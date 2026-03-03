<?php
/**
 * Deezer OAuth2 Provider
 * 
 * Deezer, OAuth 2.0 protokolünü kullanır.
 * 
 * @package AI Community Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Deezer extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Deezer';
    protected $name = 'Deezer';
    public $slug = 'deezer';
    
    // API endpoint'leri [citation:5][citation:6][citation:10]
    protected $auth_url = 'https://connect.deezer.com/oauth/auth.php';
    protected $token_url = 'https://connect.deezer.com/oauth/access_token.php';
    protected $userinfo_url = 'https://api.deezer.com/user/me';
    
    // OAuth2 ayarları
    protected $scope = 'basic_access,email,offline_access'; // Temel scope'lar [citation:8][citation:10]
    protected $token_method = 'GET'; // Deezer GET kullanır [citation:5][citation:6]
    protected $userinfo_method = 'GET';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Deezer özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        if (isset($config['scope'])) {
            $this->scope = $config['scope'];
        }
        
        // Deezer API'nin özel gereksinimleri
        // Not: Deezer OAuth2 desteğini geliştirmeyi planlamıyor [citation:1]
        // Mevcut implementasyon çalışmaya devam edecek
    }
    
    /**
     * Ana işleyici metodu - Parametresiz!
     */
    public function handle() {
        // GET parametrelerini al
        $params = $_GET;
        
        // Hata kontrolü - Deezer hata parametresi
        if (isset($params['error_reason'])) {
            $error = $params['error_reason'];
            $error_description = isset($params['error_description']) ? $params['error_description'] : '';
            
            $this->core->log('deezer_oauth_error', [
                'error' => $error,
                'description' => $error_description
            ]);
            
            wp_redirect(home_url('/login?error=social_auth_failed'));
            exit;
        }
        
        // Callback işleme (authorization code ile dönen)
        if (isset($params['code'])) {
            return $this->handle_callback($params);
        }
        
        // Normal akış - auth URL'ine yönlendir
        $state = wp_create_nonce('ai_community_oauth_state');
        $auth_url = $this->get_auth_url($state);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Callback işleme
     * 
     * @param array $params Callback parametreleri
     */
    protected function handle_callback($params) {
        $code = $params['code'];
        $state = isset($params['state']) ? $params['state'] : '';
        
        // State doğrulama
        if (!wp_verify_nonce($state, 'ai_community_oauth_state')) {
            $this->core->log('deezer_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('deezer_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('deezer_userinfo_error', [
                'error' => $userinfo->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=userinfo_error'));
            exit;
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($userinfo);
        
        // Kullanıcı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id || is_wp_error($user_id)) {
            $this->core->log('deezer_user_creation_error', [
                'error' => is_wp_error($user_id) ? $user_id->get_error_message() : 'Unknown error'
            ]);
            wp_redirect(home_url('/login?error=user_creation_failed'));
            exit;
        }
        
        // WordPress oturumu başlat
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Provider kullanıcısını kaydet
        $this->save_provider_user($user_id, $profile, $token_data);
        
        // Başarılı giriş
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'deezer');
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Provider tipini getir
     */
    public function get_type() {
        return 'oauth2';
    }
    
    /**
     * Provider adını getir
     */
    public function get_name() {
        return $this->name;
    }
    
    /**
     * Provider slug'ını getir
     */
    public function get_slug() {
        return $this->slug;
    }
    
    /**
     * Provider etiketini döndür
     */
    public function get_label() {
        return $this->name;
    }
    
    /**
     * Provider'ın aktif olup olmadığını kontrol et
     */
    public function is_active() {
        return !empty($this->client_id) && !empty($this->client_secret);
    }
    
    /**
     * Provider durumunu döndür
     */
    public function get_status() {
        return [
            'active' => $this->is_active(),
            'client_id' => !empty($this->client_id),
            'client_secret' => !empty($this->client_secret),
            'scope' => $this->scope,
            'note' => 'Deezer OAuth2 - Basic implementation'
        ];
    }
    
    /**
     * Yetkilendirme URL'ini al [citation:6][citation:10]
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        $params = [
            'app_id' => $this->client_id, // Deezer 'app_id' parametresini kullanır [citation:6]
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'perms' => $this->scope, // Deezer 'perms' parametresini kullanır [citation:6][citation:10]
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        }
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al [citation:5]
     */
    public function get_token_url() {
        return $this->token_url;
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    /**
     * Scope'ları döndür
     * Deezer scope'ları: basic_access, email, offline_access, manage_library, manage_community, delete_library, listening_history [citation:2]
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Token parametrelerini al [citation:6]
     * 
     * @param string $code Yetkilendirme kodu
     * @return array
     */
    public function get_token_params($code) {
        return [
            'app_id' => $this->client_id,
            'secret' => $this->client_secret, // Deezer 'secret' parametresini kullanır [citation:6]
            'code' => $code,
            'output' => 'json' // JSON yanıtı için
        ];
    }
    
    /**
     * Token istek metodunu al
     * Deezer GET kullanır [citation:5][citation:6]
     */
    public function get_token_method() {
        return $this->token_method;
    }
    
    /**
     * Token HTTP headers'larını döndür
     */
    public function get_token_headers() {
        return [
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Access token parse et
     * Deezer'dan gelen yanıt: {"access_token":"xxx","expires":86400} [citation:6]
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_access_token($response) {
        return $response['access_token'] ?? '';
    }
    
    /**
     * Refresh token parse et
     * Deezer refresh token sağlamaz, offline_access ile uzun ömürlü token verir
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_refresh_token($response) {
        return ''; // Deezer refresh token sağlamaz
    }
    
    /**
     * Expires in parse et
     * Deezer'da token süresi saniye cinsinden [citation:6]
     * 
     * @param array $response API yanıtı
     * @return int
     */
    public function parse_expires_in($response) {
        return $response['expires'] ?? 86400; // Varsayılan 24 saat
    }
    
    /**
     * Kullanıcı bilgi istek metodunu al
     */
    public function get_userinfo_method() {
        return $this->userinfo_method;
    }
    
    /**
     * Kullanıcı bilgi header'larını al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_headers($access_token) {
        return [
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametrelerini al
     * Deezer'da access_token query string ile gönderilir
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_params($access_token) {
        return [
            'access_token' => $access_token
        ];
    }
    
    /**
     * Kullanıcı bilgilerini al
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_userinfo($access_token) {
        $url = $this->get_userinfo_url();
        $method = $this->get_userinfo_method();
        $headers = $this->get_userinfo_headers($access_token);
        $params = $this->get_userinfo_params($access_token);
        
        // Deezer'da token query string ile gönderilir
        $url = add_query_arg($params, $url);
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        ];
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Deezer API hata kontrolü
        if (isset($data['error'])) {
            return new WP_Error(
                'deezer_api_error',
                $data['error']['message'] ?? 'Unknown error',
                ['type' => $data['error']['type'] ?? '']
            );
        }
        
        return $data;
    }
    
    /**
     * Access token al [citation:6]
     * Deezer'da token endpoint'i GET kullanır ve query string ile parametreler gönderilir
     * 
     * @param string $code Yetkilendirme kodu
     * @return array|WP_Error
     */
    public function get_access_token($code) {
        $params = $this->get_token_params($code);
        
        // Deezer GET kullandığı için parametreler query string'e eklenir
        $url = add_query_arg($params, $this->get_token_url());
        
        $args = [
            'method' => $this->get_token_method(),
            'headers' => $this->get_token_headers(),
            'timeout' => 30
        ];
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error(
                'deezer_token_error',
                $data['error']['message'] ?? 'Unknown error'
            );
        }
        
        return $data;
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * Deezer API'den gelen kullanıcı verileri [citation:6][citation:10]
     * 
     * @param array $userinfo API'den gelen kullanıcı bilgileri
     * @return array
     */
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo) || empty($userinfo['id'])) {
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'avatar' => ''
            ];
        }
        
        // Deezer kullanıcı objesi alanları [citation:6]
        $first_name = $userinfo['firstname'] ?? '';
        $last_name = $userinfo['lastname'] ?? '';
        $name = trim($first_name . ' ' . $last_name);
        
        if (empty($name)) {
            $name = $userinfo['name'] ?? $userinfo['nickname'] ?? 'Deezer User';
        }
        
        // Deezer profil resmi [citation:6]
        $avatar = $userinfo['picture'] ?? $userinfo['picture_small'] ?? $userinfo['picture_medium'] ?? '';
        if (empty($avatar)) {
            $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=purple&color=fff&size=200';
        }
        
        return [
            'id' => (string)($userinfo['id'] ?? ''),
            'email' => $userinfo['email'] ?? '',
            'email_verified' => true,
            'name' => $name,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'username' => $userinfo['name'] ?? $userinfo['nickname'] ?? '',
            'avatar' => $avatar,
            'birthday' => $userinfo['birthday'] ?? '',
            'inscription_date' => $userinfo['inscription_date'] ?? '',
            'gender' => $userinfo['gender'] ?? '',
            'link' => $userinfo['link'] ?? '',
            'country' => $userinfo['country'] ?? '',
            'lang' => $userinfo['lang'] ?? '',
            'status' => $userinfo['status'] ?? 0,
            'nb_followers' => $userinfo['nb_followers'] ?? 0,
            'nb_following' => $userinfo['nb_following'] ?? 0
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|WP_Error
     */
    public function find_or_create_user($profile) {
        global $wpdb;
        
        if (empty($profile['id'])) {
            return new WP_Error('missing_id', 'Provider ID missing');
        }
        
        // Provider ID'ye göre ara
        $table = $wpdb->prefix . 'ai_community_provider_users';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM $table WHERE provider = %s AND provider_id = %s",
            $this->slug,
            $profile['id']
        ));
        
        if ($user) {
            return $user->user_id;
        }
        
        // Email'e göre ara
        if (!empty($profile['email']) && is_email($profile['email'])) {
            $existing_user = get_user_by('email', $profile['email']);
            if ($existing_user) {
                return $existing_user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = !empty($profile['email']) && is_email($profile['email']) 
            ? $profile['email'] 
            : $profile['id'] . '@deezer.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Meta verileri kaydet
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $profile['name'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name']
        ]);
        
        if (!empty($profile['birthday'])) {
            update_user_meta($user_id, 'birthday', $profile['birthday']);
        }
        
        if (!empty($profile['country'])) {
            update_user_meta($user_id, 'country', $profile['country']);
        }
        
        return $user_id;
    }
    
    /**
     * Provider kullanıcısını kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Kullanıcı profili
     * @param array $token_data Token verileri
     */
    protected function save_provider_user($user_id, $profile, $token_data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ai_community_provider_users';
        $expires_at = !empty($token_data['expires']) 
            ? date('Y-m-d H:i:s', time() + $token_data['expires'])
            : null;
        
        $data = [
            'user_id' => $user_id,
            'provider' => $this->slug,
            'provider_id' => $profile['id'],
            'email' => $profile['email'] ?? '',
            'name' => $profile['name'] ?? '',
            'username' => $profile['username'] ?? '',
            'avatar' => $profile['avatar'] ?? '',
            'access_token' => $token_data['access_token'] ?? '',
            'refresh_token' => '', // Deezer refresh token sağlamaz
            'expires_at' => $expires_at,
            'raw_data' => json_encode($profile),
            'updated_at' => current_time('mysql')
        ];
        
        $wpdb->replace($table, $data);
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return string
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        if (!empty($profile['username'])) {
            $base = sanitize_user($profile['username'], true);
        } elseif (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } elseif (!empty($profile['email'])) {
            $base = sanitize_user(explode('@', $profile['email'])[0], true);
        } elseif (!empty($profile['id'])) {
            $base = 'deezer_' . substr($profile['id'], -8);
        } else {
            $base = 'deezer_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'deezer';
        }
        
        $username = $base;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Deezer API isteği gönder (opsiyonel - wrapper metod)
     * 
     * @param string $endpoint API endpoint'i
     * @param array $params İstek parametreleri
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function api_request($endpoint, $params = [], $access_token = '') {
        if (empty($access_token)) {
            return new WP_Error('deezer_api_error', 'Access token required');
        }
        
        $base_url = 'https://api.deezer.com';
        $url = $base_url . $endpoint;
        
        $params['access_token'] = $access_token;
        $url = add_query_arg($params, $url);
        
        $args = [
            'method' => 'GET',
            'headers' => [
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ];
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('deezer_api_error', $data['error']['message'] ?? 'Unknown error');
        }
        
        return $data;
    }
}