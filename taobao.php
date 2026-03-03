<?php
/**
 * Taobao OAuth2 Provider
 * 
 * Taobao (淘宝) - Çin'in en büyük e-ticaret platformu, Alibaba Group
 * OAuth2 ile kullanıcı profili, alışveriş tercihleri, sipariş geçmişi
 * 
 * @package AI_Community
 * @subpackage Providers
 */

class AI_Community_Provider_Taobao extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'taobao';
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'Taobao';
    
    /**
     * API Domain'leri
     */
    const OAUTH_DOMAIN = 'https://oauth.taobao.com';
    const API_DOMAIN = 'https://eco.taobao.com/router/rest';
    const AUTH_DOMAIN = 'https://auth.taobao.com';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider config
     * @param object $core Core framework instance
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Taobao API endpoints
        $this->oauth_domain = self::OAUTH_DOMAIN;
        $this->api_domain = self::API_DOMAIN;
        
        // OAuth2 endpoints
        $this->auth_url = $this->oauth_domain . '/authorize';
        $this->token_url = $this->oauth_domain . '/token';
        
        // API endpoints (Taobao REST API)
        $this->gateway_url = self::API_DOMAIN;
        
        // API methods
        $this->userinfo_method = 'taobao.user.seller.get';
        $this->buyer_method = 'taobao.user.buyer.get';
        $this->trade_method = 'taobao.trades.sold.get';
        $this->item_method = 'taobao.items.favorite.get';
        
        // Scope'lar - Taobao servisleri için
        $this->scope = 'user_info trade_get item_favorite';
        
        // Response type
        $this->response_type = 'code';
        $this->grant_type = 'authorization_code';
        
        // Format
        $this->format = 'json';
        $this->version = '2.0';
        $this->sign_method = 'hmac-sha256';
        
        // Taobao özel ayarlar
        $this->taobao_params = [
            'app_key' => $this->config['client_id'],
            'format' => $this->format,
            'v' => $this->version,
            'sign_method' => $this->sign_method,
            'timestamp' => '',
            'partner_id' => 'top-apitools'
        ];
        
        // App secret
        $this->app_secret = $this->config['client_secret'];
        
        // Session mode
        $this->session_mode = $config['session_mode'] ?? 'auto'; // auto, web, wap
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
        $this->core->log('taobao_handle_called', [
            'params' => array_keys($params),
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        
        // Hata varsa
        if (isset($params['error'])) {
            $error = $params['error_description'] ?? $params['error'];
            return $this->show_error('淘宝授权失败 / Taobao yetkilendirmesi başarısız: ' . $error);
        }
        
        // Callback (code ile döndüyse)
        if (isset($params['code'])) {
            return $this->handle_callback($params);
        }
        
        // Top_session ile döndüyse (eski versiyon)
        if (isset($params['top_session'])) {
            return $this->handle_session_callback($params);
        }
        
        // İlk giriş - OAuth2 yönlendirmesi
        $state = wp_create_nonce('ai_community_taobao_state');
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('taobao_redirect', ['url' => $auth_url]);
        
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
        if (!wp_verify_nonce($state, 'ai_community_taobao_state')) {
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
        
        // Alışveriş tercihlerini al
        $shopping_info = $this->get_shopping_info($token_response);
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($user_info, $token_response, $shopping_info);
        
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
        
        // Alışveriş tercihlerini kaydet
        if (!empty($profile['shopping_preferences'])) {
            update_user_meta($user_id, 'taobao_shopping_preferences', $profile['shopping_preferences']);
        }
        
        // Satıcı bilgilerini kaydet
        if (!empty($profile['seller_info'])) {
            update_user_meta($user_id, 'taobao_seller_info', $profile['seller_info']);
        }
        
        // Log
        $this->core->log('taobao_login_success', [
            'user_id' => $user_id,
            'taobao_user_id' => $profile['id']
        ]);
        
        // Yönlendir
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Session callback (eski versiyon)
     * 
     * @param array $params İstek parametreleri
     */
    public function handle_session_callback($params) {
        $top_session = $params['top_session'];
        $top_parameters = $params['top_parameters'] ?? '';
        $top_sign = $params['top_sign'] ?? '';
        
        // İmza doğrulama
        if (!$this->verify_top_sign($top_parameters, $top_sign)) {
            return $this->show_error('签名验证失败 / İmza doğrulaması başarısız.');
        }
        
        // Parametreleri çöz
        $parameters = base64_decode($top_parameters);
        parse_str($parameters, $session_params);
        
        $token_response = [
            'access_token' => $top_session,
            'expires_in' => 86400,
            'user_id' => $session_params['visitor_id'] ?? '',
            'nick' => $session_params['visitor_nick'] ?? ''
        ];
        
        // Kullanıcı bilgilerini al
        $user_info = $this->get_userinfo($token_response);
        
        if (is_wp_error($user_info)) {
            return $this->show_error('获取用户信息失败 / Kullanıcı bilgileri alınamadı.');
        }
        
        $profile = $this->map_userinfo_to_profile($user_info, $token_response);
        
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id) {
            return $this->show_error('用户创建失败 / Kullanıcı oluşturulamadı.');
        }
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Top sign doğrulama (eski versiyon)
     * 
     * @param string $parameters Base64 parametreler
     * @param string $sign İmza
     * @return bool İmza geçerli mi
     */
    protected function verify_top_sign($parameters, $sign) {
        $expected_sign = md5($parameters . $this->app_secret);
        return $expected_sign === $sign;
    }
    
    /**
     * İmza oluştur (Taobao API için)
     * 
     * @param array $params Parametreler
     * @return string İmza
     */
    protected function generate_signature($params) {
        ksort($params);
        
        $sign_string = $this->app_secret;
        foreach ($params as $key => $value) {
            if ($key !== 'sign' && $value !== '' && $value !== null) {
                $sign_string .= $key . $value;
            }
        }
        $sign_string .= $this->app_secret;
        
        if ($this->sign_method === 'hmac-sha256') {
            return strtoupper(hash_hmac('sha256', $sign_string, $this->app_secret));
        } else {
            return strtoupper(md5($sign_string));
        }
    }
    
    /**
     * Yetkilendirme URL'ini oluştur
     * 
     * @param string $state State parametresi
     * @return string Yetkilendirme URL'i
     */
    public function get_auth_url($state = '') {
        $params = [
            'response_type' => $this->response_type,
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->scope,
            'state' => $state,
            'view' => $this->get_view_mode()
        ];
        
        // Taobao özel parametreler
        if (!empty($this->config['auth_type'])) {
            $params['auth_type'] = $this->config['auth_type']; // user, seller
        }
        
        if (!empty($this->config['display'])) {
            $params['display'] = $this->config['display']; // web, wap, app
        }
        
        return add_query_arg(array_filter($params), $this->auth_url);
    }
    
    /**
     * Görünüm modunu belirle
     * 
     * @return string view mode
     */
    protected function get_view_mode() {
        if ($this->session_mode === 'web') {
            return 'web';
        } elseif ($this->session_mode === 'wap' || wp_is_mobile()) {
            return 'wap';
        }
        
        // Auto detect
        return wp_is_mobile() ? 'wap' : 'web';
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
            'grant_type' => $this->grant_type,
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'view' => $this->get_view_mode()
        ];
        
        $response = wp_remote_post($this->token_url, [
            'timeout' => 30,
            'sslverify' => false,
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('taobao_token_error', $data['error_description'] ?? $data['error']);
        }
        
        return $this->parse_access_token($data);
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
            'expires_in' => $response['expires_in'] ?? 86400, // 24 saat
            'refresh_token' => $response['refresh_token'] ?? '',
            're_expires_in' => $response['re_expires_in'] ?? 2592000, // 30 gün
            'token_type' => $response['token_type'] ?? 'Bearer',
            'user_id' => $response['taobao_user_id'] ?? ($response['user_id'] ?? ''),
            'nick' => $response['taobao_user_nick'] ?? ($response['nick'] ?? ''),
            'sub_user_id' => $response['sub_taobao_user_id'] ?? '',
            'sub_nick' => $response['sub_taobao_user_nick'] ?? '',
            'w1_expires_in' => $response['w1_expires_in'] ?? 0,
            'w2_expires_in' => $response['w2_expires_in'] ?? 0
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
        return $response['expires_in'] ?? 86400;
    }
    
    /**
     * Token parametrelerini döndür
     * 
     * @param string $code Yetkilendirme kodu
     * @return array Token parametreleri
     */
    public function get_token_params($code) {
        return [
            'grant_type' => $this->grant_type,
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri']
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
        // Satıcı bilgilerini al (eğer satıcıysa)
        $seller_params = $this->build_api_params($token, $this->userinfo_method, [
            'fields' => 'user_id,nick,sex,buyer_credit,seller_credit,location,avatar,type,has_shop,is_golden_seller'
        ]);
        
        $seller_response = $this->call_api($seller_params);
        
        // Alıcı bilgilerini al
        $buyer_params = $this->build_api_params($token, $this->buyer_method, [
            'fields' => 'user_id,nick,buyer_credit,level,average_score'
        ]);
        
        $buyer_response = $this->call_api($buyer_params);
        
        $user_data = [];
        
        if (!is_wp_error($seller_response) && isset($seller_response['user_seller_get_response']['user'])) {
            $user_data['seller'] = $seller_response['user_seller_get_response']['user'];
        }
        
        if (!is_wp_error($buyer_response) && isset($buyer_response['user_buyer_get_response']['user'])) {
            $user_data['buyer'] = $buyer_response['user_buyer_get_response']['user'];
        }
        
        // Token'dan gelen bilgiler
        $user_data['token'] = [
            'user_id' => $token['user_id'],
            'nick' => $token['nick']
        ];
        
        return $user_data;
    }
    
    /**
     * Alışveriş bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array Alışveriş bilgileri
     */
    protected function get_shopping_info($token) {
        $shopping_info = [];
        
        // Son 10 siparişi al
        $trade_params = $this->build_api_params($token, $this->trade_method, [
            'fields' => 'tid,title,price,num,iid,num_iid,status,created,pay_time,end_time',
            'page_size' => 10,
            'use_has_next' => true
        ]);
        
        $trade_response = $this->call_api($trade_params);
        
        if (!is_wp_error($trade_response) && isset($trade_response['trades_sold_get_response']['trades']['trade'])) {
            $shipping_info['recent_orders'] = $trade_response['trades_sold_get_response']['trades']['trade'];
            $shipping_info['total_results'] = $trade_response['trades_sold_get_response']['total_results'] ?? 0;
        }
        
        // Favori ürünleri al
        $favorite_params = $this->build_api_params($token, $this->item_method, [
            'fields' => 'item_id,title,price,pic_url,click_url',
            'page_size' => 20
        ]);
        
        $favorite_response = $this->call_api($favorite_params);
        
        if (!is_wp_error($favorite_response) && isset($favorite_response['items_favorite_get_response']['items']['item'])) {
            $shipping_info['favorites'] = $favorite_response['items_favorite_get_response']['items']['item'];
        }
        
        return $shipping_info;
    }
    
    /**
     * API parametrelerini oluştur
     * 
     * @param array $token Token bilgileri
     * @param string $method API method
     * @param array $biz_params İş parametreleri
     * @return array API parametreleri
     */
    protected function build_api_params($token, $method, $biz_params = []) {
        $params = array_merge($this->taobao_params, [
            'method' => $method,
            'timestamp' => date('Y-m-d H:i:s'),
            'session' => $token['access_token']
        ], $biz_params);
        
        // İmza oluştur
        $params['sign'] = $this->generate_signature($params);
        
        return $params;
    }
    
    /**
     * Taobao API çağrısı yap
     * 
     * @param array $params API parametreleri
     * @return array|WP_Error API yanıtı
     */
    protected function call_api($params) {
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
        
        // Hata kontrolü
        if (isset($data['error_response'])) {
            return new WP_Error(
                'taobao_api_error',
                $data['error_response']['sub_msg'] ?? $data['error_response']['msg']
            );
        }
        
        return $data;
    }
    
    /**
     * Kullanıcı bilgisi HTTP methodu
     * 
     * @return string HTTP method
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
            'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'
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
            'session' => $token['access_token'],
            'user_id' => $token['user_id']
        ];
    }
    
    /**
     * API yanıtını profile çevir
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri
     * @param array $shopping Alışveriş bilgileri
     * @return array Profil bilgileri
     */
    public function map_userinfo_to_profile($data, $token = [], $shopping = []) {
        $seller = $data['seller'] ?? [];
        $buyer = $data['buyer'] ?? [];
        $token_data = $data['token'] ?? $token;
        
        // Temel profil
        $profile = [
            'id' => $token_data['user_id'] ?? '',
            'provider' => 'taobao',
            'display_name' => $token_data['nick'] ?? ($seller['nick'] ?? '淘宝用户'),
            'nickname' => $token_data['nick'] ?? '',
            'email' => '',
            'avatar' => $seller['avatar'] ?? '',
            'gender' => $this->map_gender($seller['sex'] ?? ''),
            'type' => $seller['type'] ?? 'buyer', // buyer, seller, both
            'has_shop' => ($seller['has_shop'] ?? 'false') === 'true',
            'is_golden_seller' => ($seller['is_golden_seller'] ?? 'false') === 'true',
            'location' => $seller['location'] ?? []
        ];
        
        // Alıcı kredibilitesi
        if (!empty($buyer['buyer_credit'])) {
            $profile['buyer_credit'] = [
                'level' => $buyer['buyer_credit']['level'] ?? 0,
                'score' => $buyer['buyer_credit']['score'] ?? 0,
                'total_num' => $buyer['buyer_credit']['total_num'] ?? 0,
                'good_num' => $buyer['buyer_credit']['good_num'] ?? 0
            ];
        }
        
        // Satıcı kredibilitesi
        if (!empty($seller['seller_credit'])) {
            $profile['seller_credit'] = [
                'level' => $seller['seller_credit']['level'] ?? 0,
                'score' => $seller['seller_credit']['score'] ?? 0,
                'total_num' => $seller['seller_credit']['total_num'] ?? 0,
                'good_num' => $seller['seller_credit']['good_num'] ?? 0
            ];
        }
        
        // Alıcı seviyesi
        if (!empty($buyer['level'])) {
            $profile['buyer_level'] = $buyer['level'];
        }
        
        if (!empty($buyer['average_score'])) {
            $profile['average_score'] = $buyer['average_score'];
        }
        
        // Satıcı bilgileri
        if ($profile['type'] === 'seller' || $profile['type'] === 'both') {
            $profile['seller_info'] = [
                'has_shop' => $profile['has_shop'],
                'is_golden' => $profile['is_golden_seller'],
                'credit' => $profile['seller_credit'] ?? []
            ];
        }
        
        // Alışveriş tercihleri
        if (!empty($shopping)) {
            $profile['shopping_preferences'] = [
                'recent_orders' => $shopping['recent_orders'] ?? [],
                'favorites' => $shopping['favorites'] ?? [],
                'total_orders' => $shopping['total_results'] ?? 0
            ];
        }
        
        return $profile;
    }
    
    /**
     * Cinsiyet mapping
     * 
     * @param string $gender Taobao cinsiyet kodu
     * @return string Cinsiyet
     */
    protected function map_gender($gender) {
        switch ($gender) {
            case 'm':
            case 'M':
            case 'male':
            case '男':
                return 'male';
            case 'f':
            case 'F':
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
        // 1. Taobao User ID'ye göre ara (birincil)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_taobao_id',
            'meta_value' => $profile['id'],
            'number' => 1
        ]);
        
        if (!empty($users)) {
            $user = $users[0];
            $this->update_user_meta($user->ID, $profile);
            return $user->ID;
        }
        
        // 2. Nickname'e göre ara
        if (!empty($profile['nickname'])) {
            $users = get_users([
                'meta_key' => 'taobao_nickname',
                'meta_value' => $profile['nickname'],
                'number' => 1
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
                update_user_meta($user->ID, 'ai_comm_provider_taobao_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 3. Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['display_name'] ?? 'taobaouser');
        
        // Email oluştur
        $email = 'taobao_' . $profile['id'] . '@taobao.com';
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('taobao_user_creation_failed', [
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
        update_user_meta($user_id, 'ai_comm_provider_taobao_id', $profile['id']);
        update_user_meta($user_id, 'ai_community_provider', 'taobao');
        update_user_meta($user_id, 'taobao_display_name', $profile['display_name']);
        update_user_meta($user_id, 'taobao_nickname', $profile['nickname']);
        
        if (!empty($profile['gender'])) {
            update_user_meta($user_id, 'taobao_gender', $profile['gender']);
        }
        
        if (!empty($profile['type'])) {
            update_user_meta($user_id, 'taobao_user_type', $profile['type']);
        }
        
        // Kredi bilgileri
        if (!empty($profile['buyer_credit'])) {
            update_user_meta($user_id, 'taobao_buyer_credit', $profile['buyer_credit']);
        }
        
        if (!empty($profile['seller_credit'])) {
            update_user_meta($user_id, 'taobao_seller_credit', $profile['seller_credit']);
        }
        
        // Seviye bilgileri
        if (!empty($profile['buyer_level'])) {
            update_user_meta($user_id, 'taobao_buyer_level', $profile['buyer_level']);
        }
        
        if (!empty($profile['average_score'])) {
            update_user_meta($user_id, 'taobao_average_score', $profile['average_score']);
        }
        
        // Satıcı bilgileri
        if (!empty($profile['seller_info'])) {
            update_user_meta($user_id, 'taobao_seller_info', $profile['seller_info']);
        }
        
        update_user_meta($user_id, 'taobao_has_shop', $profile['has_shop'] ? 'yes' : 'no');
        update_user_meta($user_id, 'taobao_is_golden_seller', $profile['is_golden_seller'] ? 'yes' : 'no');
        
        // Konum bilgileri
        if (!empty($profile['location'])) {
            update_user_meta($user_id, 'taobao_location', $profile['location']);
        }
        
        // Alışveriş tercihleri
        if (!empty($profile['shopping_preferences'])) {
            update_user_meta($user_id, 'taobao_shopping_preferences', $profile['shopping_preferences']);
        }
        
        // Son giriş
        update_user_meta($user_id, 'taobao_last_login', time());
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
            $base = 'taobao' . wp_rand(100, 999);
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
        return 'taobao_' . $profile['id'] . '@taobao.com';
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
            $this->core->log('taobao_avatar_download_failed', [
                'error' => $tmp->get_error_message()
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'taobao-avatar-' . $user_id . '.jpg',
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
        update_user_meta($user_id, 'taobao_avatar_id', $attachment_id);
        
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
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'view' => $this->get_view_mode()
        ];
        
        $response = wp_remote_post($this->token_url, [
            'timeout' => 30,
            'sslverify' => false,
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('taobao_refresh_error', $data['error_description'] ?? $data['error']);
        }
        
        return $this->parse_access_token($data);
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
            <title>淘宝 授权错误 / Taobao Yetkilendirme Hatası</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #FF6A00 0%, #EE4D2D 100%);
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
                    background: #FF6A00;
                    color: white;
                    padding: 40px 30px;
                    text-align: center;
                    position: relative;
                }
                
                .error-header::before {
                    content: '淘宝';
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
                
                .error-header .subtitle {
                    font-size: 12px;
                    margin-top: 10px;
                    opacity: 0.8;
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
                    background: #FF6A00;
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
                    background: #EE4D2D;
                    transform: translateY(-2px);
                    box-shadow: 0 10px 25px rgba(255, 106, 0, 0.3);
                }
                
                .error-details {
                    font-size: 12px;
                    color: #999;
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
                
                .alibaba-logo {
                    display: flex;
                    justify-content: center;
                    margin-top: 10px;
                    font-size: 12px;
                    color: rgba(255,255,255,0.8);
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <div class="logo">🛒</div>
                        <h1>淘宝 Taobao</h1>
                        <p>授权错误 / Yetkilendirme Hatası</p>
                        <div class="alibaba-logo">
                            Alibaba Group
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