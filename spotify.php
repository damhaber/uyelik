<?php
/**
 * Spotify OAuth2 Provider
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

class AI_Community_Provider_Spotify extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Spotify';
    protected $name = 'Spotify';
    public $slug = 'spotify';
    
    // API endpoint'leri
    protected $auth_url = 'https://accounts.spotify.com/authorize';
    protected $token_url = 'https://accounts.spotify.com/api/token';
    protected $userinfo_url = 'https://api.spotify.com/v1/me';
    
    // OAuth2 ayarları
    protected $scope = 'user-read-email user-read-private';
    protected $token_method = 'POST';
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
        
        // Spotify özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
    }
    
    /**
     * Ana işleyici metodu - Parametresiz!
     */
    public function handle() {
        // GET parametrelerini al
        $params = $_GET;
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = isset($params['error_description']) ? $params['error_description'] : '';
            
            $this->core->log('spotify_oauth_error', [
                'error' => $error,
                'description' => $error_description
            ]);
            
            wp_redirect(home_url('/login?error=social_auth_failed'));
            exit;
        }
        
        // Callback işleme
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
            $this->core->log('spotify_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('spotify_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('spotify_userinfo_error', [
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
            $this->core->log('spotify_user_creation_error', [
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
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'spotify');
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
     * Yetkilendirme URL'ini al
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => $this->scope
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        }
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
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
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Token parametrelerini al
     * 
     * @param string $code Yetkilendirme kodu
     * @return array
     */
    public function get_token_params($code) {
        return [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->get_redirect_uri()
        ];
    }
    
    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return $this->token_method;
    }
    
    /**
     * Token HTTP headers'larını döndür
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    }
    
    /**
     * Access token parse et
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_access_token($response) {
        return $response['access_token'] ?? '';
    }
    
    /**
     * Refresh token parse et
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_refresh_token($response) {
        return $response['refresh_token'] ?? '';
    }
    
    /**
     * Expires in parse et
     * 
     * @param array $response API yanıtı
     * @return int
     */
    public function parse_expires_in($response) {
        return $response['expires_in'] ?? 3600;
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
            'Authorization: Bearer ' . $access_token,
            'Accept: application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametrelerini al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_params($access_token) {
        return [];
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
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        ];
        
        if (!empty($params)) {
            if ($method === 'GET') {
                $url = add_query_arg($params, $url);
            } else {
                $args['body'] = $params;
            }
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('spotify_api_error', $data['error']['message'] ?? 'Unknown error');
        }
        
        return $data;
    }
    
    /**
     * Access token al
     * 
     * @param string $code Yetkilendirme kodu
     * @return array|WP_Error
     */
    public function get_access_token($code) {
        $params = $this->get_token_params($code);
        $auth = base64_encode($this->client_id . ':' . $this->client_secret);
        
        $args = [
            'method' => $this->get_token_method(),
            'headers' => array_merge(
                $this->get_token_headers(),
                ['Authorization' => 'Basic ' . $auth]
            ),
            'body' => $params,
            'timeout' => 30
        ];
        
        $response = wp_remote_post($this->get_token_url(), $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('spotify_token_error', $data['error_description'] ?? $data['error'] ?? 'Unknown error');
        }
        
        return $data;
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
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
        
        // Avatar
        $avatar = '';
        if (!empty($userinfo['images']) && is_array($userinfo['images'])) {
            foreach ($userinfo['images'] as $image) {
                if (isset($image['url'])) {
                    $avatar = $image['url'];
                    break;
                }
            }
        }
        
        return [
            'id' => $userinfo['id'],
            'email' => $userinfo['email'] ?? '',
            'name' => $userinfo['display_name'] ?? $userinfo['id'],
            'first_name' => $userinfo['display_name'] ?? '',
            'last_name' => '',
            'username' => $userinfo['id'],
            'avatar' => $avatar,
            'product' => $userinfo['product'] ?? '',
            'country' => $userinfo['country'] ?? '',
            'followers' => $userinfo['followers']['total'] ?? 0
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
            : $profile['id'] . '@spotify.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['name'])) {
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
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
        $expires_at = !empty($token_data['expires_in']) 
            ? date('Y-m-d H:i:s', time() + $token_data['expires_in'])
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
            'refresh_token' => $token_data['refresh_token'] ?? '',
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
        } elseif (!empty($profile['id'])) {
            $base = 'spotify_' . substr($profile['id'], 0, 8);
        } else {
            $base = 'spotify_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'spotify';
        }
        
        $username = $base;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
}