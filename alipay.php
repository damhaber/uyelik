<?php
/**
 * Alipay OAuth2 Provider
 * 
 * Alipay (支付宝) - Çin'in en büyük ödeme platformu, Ant Group
 * OAuth2 ile kullanıcı profili, ödeme tercihleri ve finansal bilgiler
 * 
 * @package AI_Community
 * @subpackage Providers
 */

class AI_Community_Provider_Alipay extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'alipay';
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'Alipay';
    
    /**
     * API Domain'leri
     */
    const OAUTH_DOMAIN = 'https://openauth.alipay.com';
    const API_DOMAIN = 'https://openapi.alipay.com';
    const PROD_DOMAIN = 'https://openapi.alipay.com/gateway.do';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider config
     * @param object $core Core framework instance
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Alipay API endpoints
        $this->oauth_domain = self::OAUTH_DOMAIN;
        $this->api_domain = self::API_DOMAIN;
        
        // OAuth2 endpoints
        $this->auth_url = $this->oauth_domain . '/oauth2/publicAppAuthorize.htm';
        $this->token_url = $this->oauth_domain . '/oauth2/token';
        
        // API endpoints (Alipay gateway)
        $this->gateway_url = self::PROD_DOMAIN;
        
        // API methods
        $this->userinfo_method = 'alipay.user.info.share';
        $this->auth_method = 'alipay.system.oauth.token';
        
        // Scope'lar - Alipay servisleri için
        $this->scope = 'auth_user auth_base';
        
        // Response type
        $this->response_type = 'code';
        $this->grant_type = 'authorization_code';
        
        // Format
        $this->format = 'json';
        $this->charset = 'UTF-8';
        $this->version = '1.0';
        $this->sign_type = 'RSA2'; // RSA veya RSA2
        
        // Alipay özel ayarlar
        $this->alipay_params = [
            'app_id' => $this->config['client_id'],
            'method' => '',
            'format' => $this->format,
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'timestamp' => '',
            'version' => $this->version,
            'biz_content' => ''
        ];
        
        // RSA private key (signature için)
        $this->private_key = $config['private_key'] ?? '';
        $this->public_key = $config['public_key'] ?? '';
    }
    
    /**
     * Ana handler
     * Tüm istekler buraya gelir (parametresiz)
     * 
     * @return string|void HTML çıktı veya redirect
     */
    public function handle() {
        $params = array_merge($_GET, $_POST);
        
        // Debug log
        $this->core->log('alipay_handle_called', [
            'params' => array_keys($params),
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        
        // Hata varsa
        if (isset($params['error'])) {
            $error = $params['error_description'] ?? $params['error'];
            return $this->show_error('支付宝授权失败 / Alipay yetkilendirmesi başarısız: ' . $error);
        }
        
        // Callback (code ile döndüyse)
        if (isset($params['code']) || isset($params['auth_code'])) {
            $code = $params['code'] ?? $params['auth_code'];
            return $this->handle_callback(['code' => $code] + $params);
        }
        
        // App_id kontrolü
        if (isset($params['app_id']) && isset($params['sign'])) {
            return $this->handle_notify($params);
        }
        
        // İlk giriş - OAuth2 yönlendirmesi
        $state = wp_create_nonce('ai_community_alipay_state');
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('alipay_redirect', ['url' => $auth_url]);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Callback işleme
     * 
     * @param array $params İstek parametreleri
     * @return string HTML çıktı veya redirect
     */
    public function handle_callback($params) {
        $code = $params['code'];
        $state = $params['state'] ?? '';
        
        // State kontrolü
        if (!wp_verify_nonce($state, 'ai_community_alipay_state')) {
            return $this->show_error('状态验证失败 / State doğrulaması başarısız.');
        }
        
        // Access token al
        $token_response = $this->get_access_token($code);
        
        if (is_wp_error($token_response)) {
            return $this->show_error('获取访问令牌失败 / Access token alınamadı: ' . $token_response->get_error_message());
        }
        
        // Kullanıcı bilgilerini al
        $user_info = $this->get_userinfo($token_response);
        
        if (is_wp_error($user_info)) {
            return $this->show_error('获取用户信息失败 / Kullanıcı bilgileri alınamadı: ' . $user_info->get_error_message());
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($user_info, $token_response);
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id) {
            return $this->show_error('用户创建失败 / Kullanıcı oluşturulamadı.');
        }
        
        // Oturum aç
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        // Finansal bilgileri kaydet
        if (!empty($profile['alipay_account'])) {
            update_user_meta($user_id, 'alipay_account', $profile['alipay_account']);
        }
        
        if (!empty($profile['certified_info'])) {
            update_user_meta($user_id, 'alipay_certified', $profile['certified_info']);
        }
        
        // Log
        $this->core->log('alipay_login_success', [
            'user_id' => $user_id,
            'alipay_user_id' => $profile['id']
        ]);
        
        // Yönlendir
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Asenkron bildirim işleme (Alipay özel)
     * 
     * @param array $params Bildirim parametreleri
     */
    public function handle_notify($params) {
        // İmza doğrulama
        if (!$this->verify_signature($params)) {
            wp_die('signature verification failed');
        }
        
        // Bildirim tipine göre işlem
        $notify_type = $params['notify_type'] ?? '';
        
        switch ($notify_type) {
            case 'user_authorization':
                $this->handle_auth_notify($params);
                break;
            case 'trade_status_sync':
                $this->handle_trade_notify($params);
                break;
            default:
                // Bilinmeyen bildirim
                break;
        }
        
        // Alipay'e başarılı bildirimi ilet
        echo 'success';
        exit;
    }
    
    /**
     * Yetkilendirme bildirimi işleme
     * 
     * @param array $params Bildirim parametreleri
     */
    protected function handle_auth_notify($params) {
        $user_id = $params['user_id'] ?? '';
        $auth_code = $params['auth_code'] ?? '';
        
        if (empty($user_id) || empty($auth_code)) {
            return;
        }
        
        // Kullanıcıyı bul
        $users = get_users([
            'meta_key' => 'alipay_user_id',
            'meta_value' => $user_id,
            'number' => 1
        ]);
        
        if (!empty($users)) {
            $user = $users[0];
            update_user_meta($user->ID, 'alipay_auth_code', $auth_code);
            update_user_meta($user->ID, 'alipay_auth_time', time());
        }
    }
    
    /**
     * Ödeme bildirimi işleme
     * 
     * @param array $params Bildirim parametreleri
     */
    protected function handle_trade_notify($params) {
        $out_trade_no = $params['out_trade_no'] ?? '';
        $trade_no = $params['trade_no'] ?? '';
        $trade_status = $params['trade_status'] ?? '';
        $buyer_id = $params['buyer_id'] ?? '';
        
        if (empty($out_trade_no) || empty($trade_status)) {
            return;
        }
        
        // İşlem logu
        $this->core->log('alipay_trade_notify', [
            'out_trade_no' => $out_trade_no,
            'trade_no' => $trade_no,
            'trade_status' => $trade_status,
            'buyer_id' => $buyer_id
        ]);
        
        // Sipariş durumunu güncelle (opsiyonel)
        do_action('alipay_trade_status_updated', $out_trade_no, $trade_status, $params);
    }
    
    /**
     * İmza doğrulama (Alipay güvenliği)
     * 
     * @param array $params İstek parametreleri
     * @return bool İmza geçerli mi
     */
    protected function verify_signature($params) {
        $sign = $params['sign'] ?? '';
        $sign_type = $params['sign_type'] ?? 'RSA2';
        
        if (empty($sign)) {
            return false;
        }
        
        // İmzalanacak veriyi hazırla
        $sign_data = $this->build_sign_string($params);
        
        // RSA doğrulama
        if ($sign_type === 'RSA2') {
            return openssl_verify($sign_data, base64_decode($sign), $this->public_key, OPENSSL_ALGO_SHA256) === 1;
        } else {
            return openssl_verify($sign_data, base64_decode($sign), $this->public_key) === 1;
        }
    }
    
    /**
     * İmza string'i oluştur
     * 
     * @param array $params Parametreler
     * @return string İmza string'i
     */
    protected function build_sign_string($params) {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        
        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $pairs[] = $key . '=' . $value;
            }
        }
        
        return implode('&', $pairs);
    }
    
    /**
     * İmza oluştur (API istekleri için)
     * 
     * @param array $params Parametreler
     * @return string İmza
     */
    protected function generate_signature($params) {
        ksort($params);
        
        $sign_string = $this->build_sign_string($params);
        
        $private_key = "-----BEGIN RSA PRIVATE KEY-----\n" .
                       wordwrap($this->private_key, 64, "\n", true) .
                       "\n-----END RSA PRIVATE KEY-----";
        
        if ($this->sign_type === 'RSA2') {
            openssl_sign($sign_string, $signature, $private_key, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($sign_string, $signature, $private_key);
        }
        
        return base64_encode($signature);
    }
    
    /**
     * Yetkilendirme URL'ini oluştur
     * 
     * @param string $state State parametresi
     * @return string Yetkilendirme URL'i
     */
    public function get_auth_url($state = '') {
        $params = [
            'app_id' => $this->config['client_id'],
            'scope' => $this->scope,
            'redirect_uri' => $this->config['redirect_uri'],
            'state' => $state
        ];
        
        // Alipay özel parametreler
        if (!empty($this->config['auth_type'])) {
            $params['auth_type'] = $this->config['auth_type']; // AUTH_ONLY, LOGIN_ONLY
        }
        
        if (!empty($this->config['biz_type'])) {
            $params['biz_type'] = $this->config['biz_type'];
        }
        
        // Mobile uyumlu
        if (wp_is_mobile()) {
            $params['view'] = 'wap';
        }
        
        return add_query_arg(array_filter($params), $this->auth_url);
    }
    
    /**
     * Token URL'ini döndür
     * 
     * @return string Token URL'i
     */
    public function get_token_url() {
        return $this->token_url;
    }
    
    /**
     * Access token al
     * 
     * @param string $code Yetkilendirme kodu
     * @return array|WP_Error Token bilgileri
     */
    public function get_access_token($code) {
        $params = [
            'app_id' => $this->config['client_id'],
            'method' => $this->auth_method,
            'format' => $this->format,
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => $this->version,
            'grant_type' => 'authorization_code',
            'code' => $code
        ];
        
        // İmza oluştur
        $params['sign'] = $this->generate_signature($params);
        
        $response = wp_remote_post($this->gateway_url, [
            'timeout' => 30,
            'sslverify' => false,
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Alipay response format: { "alipay_system_oauth_token_response": { ... } }
        $response_key = str_replace('.', '_', $this->auth_method) . '_response';
        
        if (isset($data[$response_key])) {
            $token_data = $data[$response_key];
            
            if (isset($token_data['code']) && $token_data['code'] != 10000) {
                return new WP_Error('alipay_token_error', $token_data['sub_msg'] ?? $token_data['msg']);
            }
            
            return $this->parse_access_token($token_data);
        }
        
        if (isset($data['error_response'])) {
            return new WP_Error('alipay_token_error', $data['error_response']['sub_msg'] ?? $data['error_response']['msg']);
        }
        
        return new WP_Error('alipay_token_error', 'Unknown error');
    }
    
    /**
     * Token yanıtını parse et
     * 
     * @param array $response Token yanıtı
     * @return array Parse edilmiş token bilgileri
     */
    public function parse_access_token($response) {
        return [
            'access_token' => $response['access_token'] ?? '',
            'expires_in' => $response['expires_in'] ?? 7200, // 2 saat
            'refresh_token' => $response['refresh_token'] ?? '',
            're_expires_in' => $response['re_expires_in'] ?? 2592000, // 30 gün
            'user_id' => $response['user_id'] ?? '',
            'auth_start' => $response['auth_start'] ?? time()
        ];
    }
    
    /**
     * Refresh token parse et
     * 
     * @param array $response Token yanıtı
     * @return string Refresh token
     */
    public function parse_refresh_token($response) {
        return $response['refresh_token'] ?? '';
    }
    
    /**
     * Expires in parse et
     * 
     * @param array $response Token yanıtı
     * @return int Expires in
     */
    public function parse_expires_in($response) {
        return $response['expires_in'] ?? 7200;
    }
    
    /**
     * Token parametrelerini döndür
     * 
     * @param string $code Yetkilendirme kodu
     * @return array Token parametreleri
     */
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'app_id' => $this->config['client_id'],
            'method' => $this->auth_method,
            'format' => $this->format,
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => $this->version
        ];
    }
    
    /**
     * Token HTTP methodu
     * 
     * @return string HTTP method
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Kullanıcı bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array|WP_Error Kullanıcı bilgileri
     */
    public function get_userinfo($token) {
        $params = [
            'app_id' => $this->config['client_id'],
            'method' => $this->userinfo_method,
            'format' => $this->format,
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => $this->version,
            'auth_token' => $token['access_token'],
            'biz_content' => json_encode([
                'user_id' => $token['user_id'] ?? ''
            ])
        ];
        
        // İmza oluştur
        $params['sign'] = $this->generate_signature($params);
        
        $response = wp_remote_post($this->gateway_url, [
            'timeout' => 30,
            'sslverify' => false,
            'body' => $params,
            'headers' => $this->get_userinfo_headers($token)
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Alipay response format: { "alipay_user_info_share_response": { ... } }
        $response_key = str_replace('.', '_', $this->userinfo_method) . '_response';
        
        if (isset($data[$response_key])) {
            $user_data = $data[$response_key];
            
            if (isset($user_data['code']) && $user_data['code'] != 10000) {
                return new WP_Error('alipay_userinfo_error', $user_data['sub_msg'] ?? $user_data['msg']);
            }
            
            return $user_data;
        }
        
        if (isset($data['error_response'])) {
            return new WP_Error('alipay_userinfo_error', $data['error_response']['sub_msg'] ?? $data['error_response']['msg']);
        }
        
        return new WP_Error('alipay_userinfo_error', 'Unknown error');
    }
    
    /**
     * Kullanıcı bilgisi HTTP methodu
     * 
     * @return string HTTP method (GET/POST)
     */
    public function get_userinfo_method() {
        return 'POST';
    }
    
    /**
     * Kullanıcı bilgisi headers
     * 
     * @param array $token Token bilgileri
     * @return array Headers
     */
    public function get_userinfo_headers($token) {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded;charset=' . $this->charset
        ];
    }
    
    /**
     * Kullanıcı bilgisi parametreleri
     * 
     * @param array $token Token bilgileri
     * @return array Parametreler
     */
    public function get_userinfo_params($token) {
        return [
            'auth_token' => $token['access_token'],
            'user_id' => $token['user_id'] ?? ''
        ];
    }
    
    /**
     * API yanıtını profile çevir
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Profil bilgileri
     */
    public function map_userinfo_to_profile($data, $token = []) {
        // Alipay profil mapping
        $profile = [
            'id' => $data['user_id'] ?? ($token['user_id'] ?? ''),
            'provider' => 'alipay',
            'display_name' => $data['user_name'] ?? ($data['nick_name'] ?? '支付宝用户'),
            'real_name' => $data['real_name'] ?? '',
            'email' => $data['email'] ?? '',
            'avatar' => $data['avatar'] ?? $this->get_avatar_url($data),
            'gender' => $this->map_gender($data['gender'] ?? ''),
            'birthday' => $data['birthday'] ?? '',
            'province' => $data['province'] ?? '',
            'city' => $data['city'] ?? '',
            'address' => $data['address'] ?? '',
            'phone' => $data['phone'] ?? '',
            'certified' => $data['is_certified'] ?? 'F',
            'certified_type' => $data['cert_type'] ?? '',
            'student' => $data['is_student'] ?? 'F',
            'balance' => $data['balance'] ?? 0,
            'points' => $data['points'] ?? 0,
            'level' => $data['level'] ?? 0,
            'user_status' => $data['user_status'] ?? 'normal',
            'alipay_account' => $data['alipay_account'] ?? '',
            'alipay_user_id' => $data['user_id'] ?? ''
        ];
        
        // Doğrulama bilgileri
        if ($profile['certified'] === 'T') {
            $profile['certified_info'] = [
                'type' => $profile['certified_type'],
                'time' => $data['cert_time'] ?? '',
                'authority' => $data['cert_authority'] ?? ''
            ];
        }
        
        // Adres bilgileri (JSON parse)
        if (!empty($data['address_info']) && is_string($data['address_info'])) {
            $profile['address_info'] = json_decode($data['address_info'], true);
        }
        
        return $profile;
    }
    
    /**
     * Avatar URL'ini al
     * 
     * @param array $data Kullanıcı verisi
     * @return string Avatar URL
     */
    protected function get_avatar_url($data) {
        if (!empty($data['avatar'])) {
            return $data['avatar'];
        }
        
        if (!empty($data['head_img'])) {
            return $data['head_img'];
        }
        
        return '';
    }
    
    /**
     * Cinsiyet mapping
     * 
     * @param string $gender Alipay cinsiyet kodu
     * @return string Cinsiyet
     */
    protected function map_gender($gender) {
        switch ($gender) {
            case 'M':
            case 'm':
            case 'male':
            case '男':
                return 'male';
            case 'F':
            case 'f':
            case 'female':
            case '女':
                return 'female';
            default:
                return 'other';
        }
    }
    
    /**
     * Scope'ları döndür
     * 
     * @return string Scope
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Kullanıcı bul veya oluştur
     * 
     * @param array $profile Profil bilgileri
     * @return int|false Kullanıcı ID veya false
     */
    protected function find_or_create_user($profile) {
        // 1. Alipay User ID'ye göre ara (birincil)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_alipay_id',
            'meta_value' => $profile['id'],
            'number' => 1
        ]);
        
        if (!empty($users)) {
            $user = $users[0];
            $this->update_user_meta($user->ID, $profile);
            return $user->ID;
        }
        
        // 2. Alipay Account'a göre ara
        if (!empty($profile['alipay_account'])) {
            $users = get_users([
                'meta_key' => 'alipay_account',
                'meta_value' => $profile['alipay_account'],
                'number' => 1
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
                update_user_meta($user->ID, 'ai_comm_provider_alipay_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 3. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                update_user_meta($user->ID, 'ai_comm_provider_alipay_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 4. Telefona göre ara
        if (!empty($profile['phone'])) {
            $users = get_users([
                'meta_key' => 'phone',
                'meta_value' => $profile['phone'],
                'number' => 1
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
                update_user_meta($user->ID, 'ai_comm_provider_alipay_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 5. Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['display_name'] ?? 'alipayuser');
        
        // Email oluştur (yoksa)
        if (empty($profile['email'])) {
            $email = 'alipay_' . $profile['id'] . '@alipay.com';
        } else {
            $email = $profile['email'];
        }
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('alipay_user_creation_failed', [
                'error' => $user_id->get_error_message()
            ]);
            return false;
        }
        
        // Meta verilerini kaydet
        $this->update_user_meta($user_id, $profile);
        
        return $user_id;
    }
    
    /**
     * Kullanıcı meta verilerini güncelle
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Profil bilgileri
     */
    protected function update_user_meta($user_id, $profile) {
        // Temel bilgiler
        update_user_meta($user_id, 'ai_comm_provider_alipay_id', $profile['id']);
        update_user_meta($user_id, 'ai_community_provider', 'alipay');
        update_user_meta($user_id, 'alipay_display_name', $profile['display_name']);
        update_user_meta($user_id, 'alipay_real_name', $profile['real_name']);
        update_user_meta($user_id, 'alipay_user_id', $profile['id']);
        
        if (!empty($profile['gender'])) {
            update_user_meta($user_id, 'alipay_gender', $profile['gender']);
        }
        
        if (!empty($profile['birthday'])) {
            update_user_meta($user_id, 'alipay_birthday', $profile['birthday']);
        }
        
        if (!empty($profile['phone'])) {
            update_user_meta($user_id, 'alipay_phone', $profile['phone']);
            update_user_meta($user_id, 'phone', $profile['phone']); // Ana telefon
        }
        
        if (!empty($profile['province'])) {
            update_user_meta($user_id, 'alipay_province', $profile['province']);
        }
        
        if (!empty($profile['city'])) {
            update_user_meta($user_id, 'alipay_city', $profile['city']);
        }
        
        if (!empty($profile['address'])) {
            update_user_meta($user_id, 'alipay_address', $profile['address']);
        }
        
        // Alipay özel bilgiler
        update_user_meta($user_id, 'alipay_account', $profile['alipay_account']);
        update_user_meta($user_id, 'alipay_certified', $profile['certified']);
        update_user_meta($user_id, 'alipay_student', $profile['student']);
        update_user_meta($user_id, 'alipay_balance', $profile['balance']);
        update_user_meta($user_id, 'alipay_points', $profile['points']);
        update_user_meta($user_id, 'alipay_level', $profile['level']);
        update_user_meta($user_id, 'alipay_user_status', $profile['user_status']);
        
        // Doğrulama bilgileri
        if (!empty($profile['certified_info'])) {
            update_user_meta($user_id, 'alipay_certified_info', $profile['certified_info']);
        }
        
        // Adres bilgileri
        if (!empty($profile['address_info'])) {
            update_user_meta($user_id, 'alipay_address_info', $profile['address_info']);
        }
        
        // Son giriş
        update_user_meta($user_id, 'alipay_last_login', time());
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     * 
     * @param string $base Base kullanıcı adı
     * @return string Benzersiz kullanıcı adı
     */
    protected function generate_unique_username($base) {
        $base = sanitize_user($base);
        $base = preg_replace('/[^a-z0-9]/i', '', $base);
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'alipay' . wp_rand(100, 999);
        }
        
        $base = substr($base, 0, 20);
        $username = $base;
        $counter = 1;
        
        while (get_user_by('login', $username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Email oluştur (fallback)
     * 
     * @param array $profile Profil bilgileri
     * @return string Email
     */
    protected function generate_email($profile) {
        if (!empty($profile['email'])) {
            return $profile['email'];
        }
        
        return 'alipay_' . $profile['id'] . '@alipay.com';
    }
    
    /**
     * Avatar kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param string $avatar_url Avatar URL
     * @return bool Başarılı mı
     */
    protected function save_avatar($user_id, $avatar_url) {
        if (empty($avatar_url)) {
            return false;
        }
        
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // Geçici dosyaya indir
        $tmp = download_url($avatar_url);
        
        if (is_wp_error($tmp)) {
            $this->core->log('alipay_avatar_download_failed', [
                'error' => $tmp->get_error_message()
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'alipay-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }
        
        // Kullanıcı avatarı olarak kaydet
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, 'alipay_avatar_id', $attachment_id);
        
        return true;
    }
    
    /**
     * Refresh token yenile
     * 
     * @param string $refresh_token Refresh token
     * @return array|WP_Error Yeni token bilgileri
     */
    public function refresh_access_token($refresh_token) {
        $params = [
            'app_id' => $this->config['client_id'],
            'method' => $this->auth_method,
            'format' => $this->format,
            'charset' => $this->charset,
            'sign_type' => $this->sign_type,
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => $this->version,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token
        ];
        
        // İmza oluştur
        $params['sign'] = $this->generate_signature($params);
        
        $response = wp_remote_post($this->gateway_url, [
            'timeout' => 30,
            'sslverify' => false,
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        $response_key = str_replace('.', '_', $this->auth_method) . '_response';
        
        if (isset($data[$response_key])) {
            $token_data = $data[$response_key];
            
            if (isset($token_data['code']) && $token_data['code'] != 10000) {
                return new WP_Error('alipay_refresh_error', $token_data['sub_msg'] ?? $token_data['msg']);
            }
            
            return $this->parse_access_token($token_data);
        }
        
        return new WP_Error('alipay_refresh_error', 'Refresh failed');
    }
    
    /**
     * Hata mesajı göster (Çince/İngilizce/Türkçe)
     * 
     * @param string $message Hata mesajı
     * @return string HTML çıktı
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>支付宝 授权错误 / Alipay Yetkilendirme Hatası</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #1677FF 0%, #0052CC 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .error-container {
                    max-width: 450px;
                    width: 100%;
                }
                
                .error-card {
                    background: white;
                    border-radius: 24px;
                    box-shadow: 0 30px 70px rgba(0,0,0,0.3);
                    overflow: hidden;
                }
                
                .error-header {
                    background: #1677FF;
                    color: white;
                    padding: 40px 30px;
                    text-align: center;
                    position: relative;
                }
                
                .error-header::before {
                    content: '支付宝';
                    position: absolute;
                    bottom: 10px;
                    right: 20px;
                    font-size: 40px;
                    opacity: 0.1;
                    font-weight: bold;
                }
                
                .error-header .logo {
                    font-size: 48px;
                    margin-bottom: 15px;
                }
                
                .error-header h1 {
                    font-size: 32px;
                    margin-bottom: 10px;
                    font-weight: 600;
                }
                
                .error-header p {
                    opacity: 0.9;
                    font-size: 14px;
                }
                
                .error-body {
                    padding: 35px 30px;
                }
                
                .error-message {
                    background: #fff5f5;
                    color: #ff4757;
                    padding: 20px;
                    border-radius: 16px;
                    margin-bottom: 25px;
                    border-left: 4px solid #ff4757;
                    font-size: 14px;
                    line-height: 1.6;
                    word-break: break-word;
                }
                
                .btn {
                    display: block;
                    width: 100%;
                    padding: 16px;
                    background: #1677FF;
                    color: white;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 12px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    border: none;
                    cursor: pointer;
                }
                
                .btn:hover {
                    background: #0052CC;
                    transform: translateY(-2px);
                    box-shadow: 0 10px 25px rgba(22, 119, 255, 0.3);
                }
                
                .error-details {
                    font-size: 12px;
                    color: #999;
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
                
                .ant-logo {
                    display: flex;
                    justify-content: center;
                    margin-bottom: 20px;
                }
                
                .ant-logo span {
                    font-size: 14px;
                    color: rgba(255,255,255,0.8);
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <div class="logo">💰</div>
                        <h1>支付宝 Alipay</h1>
                        <p>授权错误 / Yetkilendirme Hatası</p>
                        <div class="ant-logo">
                            <span>Ant Group</span>
                        </div>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <a href="<?php echo esc_url(home_url()); ?>" class="btn">
                            返回首页 / Ana Sayfaya Dön
                        </a>
                        <div class="error-details">
                            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                                <p>Error Time: <?php echo date('Y-m-d H:i:s'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}