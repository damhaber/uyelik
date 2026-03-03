<?php
/**
 * Paramount+ Mock OAuth2 Provider
 * 
 * NOT: Paramount+ gerçek OAuth2 API'si sunmamaktadır.
 * Bu provider sadece test/development amaçlıdır.
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

class AI_Community_Provider_ParamountPlus extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Paramount+ (Mock)';
    protected $name = 'Paramount+';
    public $slug = 'paramount-plus';
    protected $is_mock = true;
    
    // Mock API endpoint'leri
    protected $auth_url = 'https://mock-auth.local/paramount';
    protected $token_url = 'https://mock-token.local/paramount';
    protected $userinfo_url = 'https://mock-api.local/paramount/user';
    
    // OAuth2 ayarları
    protected $scope = 'profile email';
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
        
        // Paramount+ özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        
        // Mock modunda olduğunu logla
        $this->core->log('paramount_mock_mode', [
            'message' => 'Paramount+ provider MOCK modunda çalışıyor - Gerçek API yok'
        ]);
    }
    
    /**
     * Ana işleyici metodu - Parametresiz!
     */
    public function handle() {
        // GET parametrelerini al
        $params = $_GET;
        
        // Mock mod - test için direkt callback simüle et
        if (defined('AI_COMMUNITY_MOCK_MODE') && AI_COMMUNITY_MOCK_MODE) {
            if (!isset($params['code'])) {
                $params['code'] = 'mock_code_' . wp_generate_password(12, false);
            }
            if (!isset($params['state'])) {
                $params['state'] = wp_create_nonce('ai_community_oauth_state');
            }
        }
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = isset($params['error_description']) ? $params['error_description'] : '';
            
            $this->core->log('paramount_oauth_error', [
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
            $this->core->log('paramount_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al (mock)
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('paramount_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al (mock)
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('paramount_userinfo_error', [
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
            $this->core->log('paramount_user_creation_error', [
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
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'paramount-plus');
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
        return $this->name . ' (Mock)';
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
        return $this->name . ' (Mock)';
    }
    
    /**
     * Provider'ın aktif olup olmadığını kontrol et
     */
    public function is_active() {
        return true;
    }
    
    /**
     * Provider durumunu döndür
     */
    public function get_status() {
        return [
            'active' => true,
            'mock' => true,
            'client_id' => !empty($this->client_id),
            'client_secret' => !empty($this->client_secret),
            'warning' => 'Paramount+ gerçek API sunmamaktadır. Bu provider sadece test amaçlıdır.'
        ];
    }
    
    /**
     * Yetkilendirme URL'ini al
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id ?: 'mock_client_id',
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => $this->scope,
            'mock' => 'true'
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
            'client_id' => $this->client_id ?: 'mock_client_id',
            'client_secret' => $this->client_secret ?: 'mock_client_secret',
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
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
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
            'Accept: application/json',
            'X-Mock-Provider' => 'paramount-plus'
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
     * Kullanıcı bilgilerini al (mock)
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_userinfo($access_token) {
        // Mock kullanıcı verileri - Paramount+ temalı
        $mock_users = [
            [
                'id' => 'paramount_usr_001',
                'email' => 'james.kirk@starfleet.com',
                'name' => 'James T. Kirk',
                'given_name' => 'James',
                'family_name' => 'Kirk',
                'picture' => 'https://ui-avatars.com/api/?name=James+Kirk&background=0058a9&color=fff&size=200',
                'subscription' => 'premium',
                'member_since' => '2021-03-04',
                'profiles' => 4,
                'locale' => 'en-US',
                'favorite_show' => 'Star Trek: Strange New Worlds',
                'watchlist' => 'Star Trek: TOS, TNG, DS9'
            ],
            [
                'id' => 'paramount_usr_002',
                'email' => 'spock@vulcan.com',
                'name' => 'Spock',
                'given_name' => 'Spock',
                'family_name' => '',
                'picture' => 'https://ui-avatars.com/api/?name=Spock&background=0058a9&color=fff&size=200',
                'subscription' => 'premium',
                'member_since' => '2021-03-04',
                'profiles' => 3,
                'locale' => 'en-US',
                'favorite_show' => 'Star Trek: The Next Generation',
                'watchlist' => 'All Star Trek'
            ],
            [
                'id' => 'paramount_usr_003',
                'email' => 'frank.reynolds@example.com',
                'name' => 'Frank Reynolds',
                'given_name' => 'Frank',
                'family_name' => 'Reynolds',
                'picture' => 'https://ui-avatars.com/api/?name=Frank+Reynolds&background=0058a9&color=fff&size=200',
                'subscription' => 'essential',
                'member_since' => '2022-06-15',
                'profiles' => 2,
                'locale' => 'en-US',
                'favorite_show' => 'It\'s Always Sunny in Philadelphia',
                'watchlist' => 'Always Sunny, Workaholics'
            ],
            [
                'id' => 'paramount_usr_004',
                'email' => 'yellowstone@dutton.com',
                'name' => 'John Dutton',
                'given_name' => 'John',
                'family_name' => 'Dutton',
                'picture' => 'https://ui-avatars.com/api/?name=John+Dutton&background=0058a9&color=fff&size=200',
                'subscription' => 'premium',
                'member_since' => '2022-11-13',
                'profiles' => 4,
                'locale' => 'en-US',
                'favorite_show' => 'Yellowstone',
                'watchlist' => 'Yellowstone, 1883, 1923'
            ],
            [
                'id' => 'paramount_usr_005',
                'email' => 'can.yilmaz@example.com.tr',
                'name' => 'Can Yılmaz',
                'given_name' => 'Can',
                'family_name' => 'Yılmaz',
                'picture' => 'https://ui-avatars.com/api/?name=Can+Yilmaz&background=0058a9&color=fff&size=200',
                'subscription' => 'essential',
                'member_since' => '2023-08-22',
                'profiles' => 3,
                'locale' => 'tr-TR',
                'favorite_show' => 'Star Trek: Discovery',
                'watchlist' => 'Star Trek, Yellowstone'
            ]
        ];
        
        // Token'dan index belirle
        $index = hexdec(substr(md5($access_token ?: rand()), 0, 1)) % count($mock_users);
        
        return $mock_users[$index];
    }
    
    /**
     * Access token al (mock)
     * 
     * @param string $code Yetkilendirme kodu
     * @return array|WP_Error
     */
    public function get_access_token($code) {
        return [
            'access_token' => 'mock_at_paramount_' . wp_generate_password(24, false),
            'refresh_token' => 'mock_rt_paramount_' . wp_generate_password(24, false),
            'expires_in' => 3600,
            'token_type' => 'Bearer',
            'scope' => $this->scope
        ];
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
                'id' => 'mock_' . uniqid(),
                'email' => 'mock_' . uniqid() . '@paramount.local',
                'name' => 'Paramount+ User',
                'avatar' => 'https://ui-avatars.com/api/?name=Paramount+User&background=0058a9&color=fff&size=200'
            ];
        }
        
        return [
            'id' => $userinfo['id'],
            'email' => $userinfo['email'] ?? '',
            'email_verified' => true,
            'name' => $userinfo['name'] ?? 'Paramount+ User',
            'first_name' => $userinfo['given_name'] ?? '',
            'last_name' => $userinfo['family_name'] ?? '',
            'username' => explode('@', $userinfo['email'])[0] ?? 'paramount_user',
            'avatar' => $userinfo['picture'] ?? '',
            'subscription' => $userinfo['subscription'] ?? 'essential',
            'member_since' => $userinfo['member_since'] ?? date('Y-m-d'),
            'profiles' => $userinfo['profiles'] ?? 3,
            'locale' => $userinfo['locale'] ?? 'en-US',
            'favorite_show' => $userinfo['favorite_show'] ?? 'Star Trek',
            'watchlist' => $userinfo['watchlist'] ?? 'Various'
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
            : $profile['id'] . '@paramount.local';
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
        
        if (!empty($profile['subscription'])) {
            update_user_meta($user_id, 'paramount_subscription', $profile['subscription']);
        }
        
        if (!empty($profile['favorite_show'])) {
            update_user_meta($user_id, 'paramount_favorite_show', $profile['favorite_show']);
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
        } elseif (!empty($profile['email'])) {
            $base = sanitize_user(explode('@', $profile['email'])[0], true);
        } elseif (!empty($profile['id'])) {
            $base = 'paramount_' . substr($profile['id'], -8);
        } else {
            $base = 'paramount_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'paramount';
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