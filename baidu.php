<?php
/**
 * Baidu OAuth2 Provider
 * 
 * Baidu (百度) - Çin'in en büyük arama motoru ve teknoloji şirketi
 * OAuth2 ile kullanıcı profili, beğeniler ve arama tercihleri
 * 
 * @package AI_Community
 * @subpackage Providers
 */

class AI_Community_Provider_Baidu extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'baidu';
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'Baidu';
    
    /**
     * API Domain'leri
     */
    const OAUTH_DOMAIN = 'https://openapi.baidu.com';
    const API_DOMAIN = 'https://openapi.baidu.com';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider config
     * @param object $core Core framework instance
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Baidu API endpoints
        $this->oauth_domain = self::OAUTH_DOMAIN;
        $this->api_domain = self::API_DOMAIN;
        
        // OAuth2 endpoints
        $this->auth_url = $this->oauth_domain . '/oauth/2.0/authorize';
        $this->token_url = $this->oauth_domain . '/oauth/2.0/token';
        
        // API endpoints
        $this->userinfo_url = $this->api_domain . '/rest/2.0/passport/users/getInfo';
        $this->pan_url = $this->api_domain . '/rest/2.0/xpan/nas';
        $this->search_url = $this->api_domain . '/rest/2.0/shuoba/user';
        
        // Scope'lar - Baidu servisleri için
        $this->scope = 'basic userinfo netdisk shuoba';
        
        // Response type
        $this->response_type = 'code';
        $this->grant_type = 'authorization_code';
        
        // Format
        $this->format = 'json';
        
        // Display type (page/popup/tv/mobile)
        $this->display = $config['display'] ?? 'page';
        
        // Force login
        $this->force_login = $config['force_login'] ?? true;
        
        // Baidu özel ayarlar
        $this->bd_params = [
            'display' => $this->display,
            'force_login' => $this->force_login ? 1 : 0,
            'confirm_login' => $config['confirm_login'] ?? 0,
            'login_type' => $config['login_type'] ?? 'default' // default, qr, sms
        ];
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
        $this->core->log('baidu_handle_called', [
            'params' => array_keys($params),
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        
        // Hata varsa
        if (isset($params['error'])) {
            $error = $params['error_description'] ?? $params['error'];
            return $this->show_error('百度授权失败 / Baidu yetkilendirmesi başarısız: ' . $error);
        }
        
        // Callback (code ile döndüyse)
        if (isset($params['code'])) {
            return $this->handle_callback($params);
        }
        
        // İlk giriş - OAuth2 yönlendirmesi
        $state = wp_create_nonce('ai_community_baidu_state');
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('baidu_redirect', ['url' => $auth_url]);
        
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
        if (!wp_verify_nonce($state, 'ai_community_baidu_state')) {
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
        
        // Baidu disk bilgilerini kaydet
        if (!empty($profile['baidu_pan'])) {
            update_user_meta($user_id, 'baidu_pan_info', $profile['baidu_pan']);
        }
        
        // Arama tercihlerini kaydet
        if (!empty($profile['search_preferences'])) {
            update_user_meta($user_id, 'baidu_search_preferences', $profile['search_preferences']);
        }
        
        // Log
        $this->core->log('baidu_login_success', [
            'user_id' => $user_id,
            'baidu_uid' => $profile['id']
        ]);
        
        // Yönlendir
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
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
            'display' => $this->bd_params['display'],
            'force_login' => $this->bd_params['force_login'],
            'confirm_login' => $this->bd_params['confirm_login']
        ];
        
        // Login type (QR kod login için)
        if ($this->bd_params['login_type'] === 'qr') {
            $params['qr'] = 1;
            $params['qrcode'] = 1;
        } elseif ($this->bd_params['login_type'] === 'sms') {
            $params['sms'] = 1;
        }
        
        // Device type
        if (wp_is_mobile()) {
            $params['display'] = 'mobile';
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
            'grant_type' => $this->grant_type,
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri']
        ];
        
        $response = wp_remote_post($this->token_url . '?' . http_build_query($params), [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('baidu_token_error', $data['error_description'] ?? $data['error']);
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
            'expires_in' => $response['expires_in'] ?? 2592000, // 30 gün
            'refresh_token' => $response['refresh_token'] ?? '',
            'session_key' => $response['session_key'] ?? '',
            'session_secret' => $response['session_secret'] ?? '',
            'scope' => $response['scope'] ?? ''
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
        return $response['expires_in'] ?? 2592000;
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
        // Temel kullanıcı bilgileri
        $params = [
            'access_token' => $token['access_token'],
            'format' => $this->format
        ];
        
        $response = wp_remote_get(add_query_arg($params, $this->userinfo_url), [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => $this->get_userinfo_headers($token)
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error_code']) && $data['error_code'] != 0) {
            return new WP_Error('baidu_userinfo_error', $data['error_msg'] ?? 'Bilinmeyen hata');
        }
        
        // Baidu Pan (Disk) bilgilerini al
        $pan_data = $this->get_pan_info($token);
        
        // Shuoba (Search) bilgilerini al
        $search_data = $this->get_search_info($token);
        
        return [
            'user' => $data,
            'pan' => $pan_data,
            'search' => $search_data
        ];
    }
    
    /**
     * Baidu Pan (Disk) bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array Disk bilgileri
     */
    protected function get_pan_info($token) {
        $params = [
            'access_token' => $token['access_token'],
            'method' => 'info',
            'format' => $this->format
        ];
        
        $response = wp_remote_get(add_query_arg($params, $this->pan_url), [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['errno']) && $data['errno'] != 0) {
            return [];
        }
        
        return [
            'total_size' => $data['total'] ?? 0,
            'used_size' => $data['used'] ?? 0,
            'free_size' => ($data['total'] ?? 0) - ($data['used'] ?? 0),
            'file_count' => $data['file_count'] ?? 0
        ];
    }
    
    /**
     * Shuoba (Search) bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array Arama tercihleri
     */
    protected function get_search_info($token) {
        $params = [
            'access_token' => $token['access_token'],
            'format' => $this->format
        ];
        
        $response = wp_remote_get(add_query_arg($params, $this->search_url), [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error_code'])) {
            return [];
        }
        
        return [
            'search_count' => $data['search_count'] ?? 0,
            'keywords' => $data['hot_keywords'] ?? [],
            'interests' => $data['interests'] ?? []
        ];
    }
    
    /**
     * Kullanıcı bilgisi HTTP methodu
     * 
     * @return string HTTP method (GET/POST)
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Kullanıcı bilgisi headers
     * 
     * @param array $token Token bilgileri
     * @return array Headers
     */
    public function get_userinfo_headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token['access_token'],
            'Content-Type' => 'application/json'
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
            'access_token' => $token['access_token'],
            'format' => $this->format
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
        $user = $data['user'] ?? $data;
        $pan = $data['pan'] ?? [];
        $search = $data['search'] ?? [];
        
        // Baidu profil mapping
        $profile = [
            'id' => $user['uid'] ?? ($user['userid'] ?? ''),
            'provider' => 'baidu',
            'display_name' => $user['username'] ?? ($user['uname'] ?? '百度用户'),
            'real_name' => $user['realname'] ?? '',
            'email' => $user['email'] ?? '',
            'avatar' => $this->get_avatar_url($user),
            'gender' => $this->map_gender($user['sex'] ?? ''),
            'birthday' => $user['birthday'] ?? '',
            'age' => $user['age'] ?? 0,
            'province' => $user['province'] ?? '',
            'city' => $user['city'] ?? '',
            'verify_type' => $user['verify_type'] ?? '',
            'verify_info' => $user['verify_info'] ?? '',
            'is_vip' => $user['vip'] ?? false,
            'level' => $user['level'] ?? 0,
            'register_time' => $user['reg_time'] ?? 0,
            'baidu_secure_level' => $user['secure_level'] ?? 'low'
        ];
        
        // Baidu Pan bilgileri
        if (!empty($pan)) {
            $profile['baidu_pan'] = [
                'total_size_gb' => round($pan['total_size'] / 1073741824, 2),
                'used_size_gb' => round($pan['used_size'] / 1073741824, 2),
                'free_size_gb' => round($pan['free_size'] / 1073741824, 2),
                'file_count' => $pan['file_count']
            ];
        }
        
        // Arama tercihleri
        if (!empty($search)) {
            $profile['search_preferences'] = [
                'search_count' => $search['search_count'],
                'keywords' => $search['keywords'],
                'interests' => $search['interests']
            ];
        }
        
        return $profile;
    }
    
    /**
     * Avatar URL'ini al
     * 
     * @param array $user Kullanıcı verisi
     * @return string Avatar URL
     */
    protected function get_avatar_url($user) {
        if (!empty($user['portrait'])) {
            return 'https://himg.bdimg.com/sys/portrait/item/' . $user['portrait'] . '.jpg';
        }
        
        if (!empty($user['avatar'])) {
            return $user['avatar'];
        }
        
        if (!empty($user['avatar_url'])) {
            return $user['avatar_url'];
        }
        
        return '';
    }
    
    /**
     * Cinsiyet mapping
     * 
     * @param string $gender Baidu cinsiyet kodu
     * @return string Cinsiyet
     */
    protected function map_gender($gender) {
        switch ($gender) {
            case '1':
            case 'male':
            case '男':
                return 'male';
            case '0':
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
        // 1. Baidu UID'ye göre ara (birincil)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_baidu_id',
            'meta_value' => $profile['id'],
            'number' => 1
        ]);
        
        if (!empty($users)) {
            $user = $users[0];
            $this->update_user_meta($user->ID, $profile);
            return $user->ID;
        }
        
        // 2. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                update_user_meta($user->ID, 'ai_comm_provider_baidu_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 3. Kullanıcı adına göre ara
        if (!empty($profile['display_name'])) {
            $username = sanitize_user($profile['display_name']);
            $user = get_user_by('login', $username);
            if ($user) {
                update_user_meta($user->ID, 'ai_comm_provider_baidu_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['display_name'] ?? 'baiduuser');
        
        // Email oluştur (yoksa)
        if (empty($profile['email'])) {
            $email = 'baidu_' . $profile['id'] . '@baidu.com';
        } else {
            $email = $profile['email'];
        }
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('baidu_user_creation_failed', [
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
        update_user_meta($user_id, 'ai_comm_provider_baidu_id', $profile['id']);
        update_user_meta($user_id, 'ai_community_provider', 'baidu');
        update_user_meta($user_id, 'baidu_display_name', $profile['display_name']);
        update_user_meta($user_id, 'baidu_real_name', $profile['real_name']);
        
        if (!empty($profile['gender'])) {
            update_user_meta($user_id, 'baidu_gender', $profile['gender']);
        }
        
        if (!empty($profile['birthday'])) {
            update_user_meta($user_id, 'baidu_birthday', $profile['birthday']);
        }
        
        if (!empty($profile['province'])) {
            update_user_meta($user_id, 'baidu_province', $profile['province']);
        }
        
        if (!empty($profile['city'])) {
            update_user_meta($user_id, 'baidu_city', $profile['city']);
        }
        
        // Baidu özel bilgiler
        update_user_meta($user_id, 'baidu_uid', $profile['id']);
        update_user_meta($user_id, 'baidu_is_vip', $profile['is_vip'] ? 'yes' : 'no');
        update_user_meta($user_id, 'baidu_level', $profile['level']);
        update_user_meta($user_id, 'baidu_register_time', $profile['register_time']);
        update_user_meta($user_id, 'baidu_secure_level', $profile['baidu_secure_level']);
        
        // Doğrulama bilgileri
        if (!empty($profile['verify_type'])) {
            update_user_meta($user_id, 'baidu_verify_type', $profile['verify_type']);
            update_user_meta($user_id, 'baidu_verify_info', $profile['verify_info']);
        }
        
        // Baidu Pan bilgileri
        if (!empty($profile['baidu_pan'])) {
            update_user_meta($user_id, 'baidu_pan_info', $profile['baidu_pan']);
        }
        
        // Arama tercihleri
        if (!empty($profile['search_preferences'])) {
            update_user_meta($user_id, 'baidu_search_preferences', $profile['search_preferences']);
        }
        
        // Son giriş
        update_user_meta($user_id, 'baidu_last_login', time());
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
            $base = 'baidu' . wp_rand(100, 999);
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
        
        return 'baidu_' . $profile['id'] . '@baidu.com';
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
            $this->core->log('baidu_avatar_download_failed', [
                'error' => $tmp->get_error_message()
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'baidu-avatar-' . $user_id . '.jpg',
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
        update_user_meta($user_id, 'baidu_avatar_id', $attachment_id);
        
        return true;
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
            <title>百度 授权错误 / Baidu Yetkilendirme Hatası</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #2932E1 0%, #1E2799 100%);
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
                    background: #2932E1;
                    color: white;
                    padding: 40px 30px;
                    text-align: center;
                    position: relative;
                }
                
                .error-header::before {
                    content: '百度';
                    position: absolute;
                    bottom: 10px;
                    right: 20px;
                    font-size: 40px;
                    opacity: 0.1;
                    font-weight: bold;
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
                    background: #2932E1;
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
                    background: #1E2799;
                    transform: translateY(-2px);
                    box-shadow: 0 10px 25px rgba(41, 50, 225, 0.3);
                }
                
                .error-details {
                    font-size: 12px;
                    color: #999;
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <h1>百度 Baidu</h1>
                        <p>授权错误 / Yetkilendirme Hatası</p>
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
                                <p>Error Code: <?php echo date('Y-m-d H:i:s'); ?></p>
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
            'client_secret' => $this->config['client_secret']
        ];
        
        $response = wp_remote_post($this->token_url . '?' . http_build_query($params), [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('baidu_refresh_error', $data['error_description'] ?? $data['error']);
        }
        
        return $this->parse_access_token($data);
    }
}