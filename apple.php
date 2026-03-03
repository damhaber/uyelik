<?php
// masal-panel/modules/ai-community-engine/core/providers/apple.php
// GÜNCELLENMİŞ VERSİYON - JWT client_secret, userinfo mapping, avatar desteği

if (!defined('ABSPATH')) {
    exit;
}

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Apple extends AI_Community_OAuth2_Provider {

    protected $label = 'Apple';
    public $slug = 'apple';
    protected $scope = 'name email';
    protected $auth_url = 'https://appleid.apple.com/auth/authorize';
    protected $token_url = 'https://appleid.apple.com/auth/token';
    protected $userinfo_url = ''; // Apple'da ayrı userinfo endpoint'i yok
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config = [], $core = null) {
        $this->id = $id;
        $this->config = $config;
        $this->core = $core;
        
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        
        if (isset($config['redirect_uri'])) {
            $this->redirect_uri = $config['redirect_uri'];
        }
        
        parent::__construct($id, $config, $core);
    }
    
    /**
     * Handle - PARAMETRESİZ!
     * Ana giriş noktası
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        if (isset($request['code']) && !isset($request['oauth_provider'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * OAuth2 URL metodları - PUBLIC OLMALI!
     */
    public function get_auth_url($state = '') {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->get_scope(),
            'response_mode' => 'form_post'
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        } else {
            $params['state'] = wp_create_nonce('apple_oauth_state');
        }
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    public function get_token_url() {
        return $this->token_url;
    }
    
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    /**
     * Token parametreleri ve parse metodları
     */
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirect_uri,
            'client_id' => $this->client_id,
            'client_secret' => $this->generate_client_secret()
        ];
    }
    
    public function get_token_method() {
        return 'POST';
    }
    
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    }
    
    public function parse_access_token($response) {
        if (is_array($response) && isset($response['access_token'])) {
            return $response['access_token'];
        }
        return null;
    }
    
    public function parse_refresh_token($response) {
        if (is_array($response) && isset($response['refresh_token'])) {
            return $response['refresh_token'];
        }
        return null;
    }
    
    public function parse_expires_in($response) {
        if (is_array($response) && isset($response['expires_in'])) {
            return (int) $response['expires_in'];
        }
        return 3600;
    }
    
    /**
     * Userinfo metodları
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    public function get_userinfo_headers($token) {
        return [];
    }
    
    public function get_userinfo_params($token) {
        return [];
    }
    
    public function get_userinfo_params_with_token($token) {
        return [];
    }
    
    /**
     * Apple için JWT client_secret oluştur
     */
    private function generate_client_secret() {
        $team_id = $this->config['team_id'] ?? '';
        $client_id = $this->config['client_id'] ?? '';
        $key_id = $this->config['key_id'] ?? '';
        $private_key = $this->config['private_key'] ?? '';
        
        if (empty($team_id) || empty($client_id) || empty($key_id) || empty($private_key)) {
            error_log('Apple JWT config eksik: ' . json_encode([
                'has_team_id' => !empty($team_id),
                'has_key_id' => !empty($key_id),
                'has_private_key' => !empty($private_key),
            ]));
            return '';
        }
        
        // JWT header
        $header = json_encode([
            'alg' => 'ES256',
            'kid' => $key_id,
        ]);
        
        // JWT payload
        $now = time();
        $payload = json_encode([
            'iss' => $team_id,
            'iat' => $now,
            'exp' => $now + 3600, // 1 saat geçerli
            'aud' => 'https://appleid.apple.com',
            'sub' => $client_id,
        ]);
        
        // Base64 URL encode
        $base64_header = $this->base64url_encode($header);
        $base64_payload = $this->base64url_encode($payload);
        
        // İmza oluştur
        $signature = '';
        $key = openssl_pkey_get_private($private_key);
        if ($key) {
            openssl_sign($base64_header . '.' . $base64_payload, $signature, $key, 'sha256');
            openssl_free_key($key);
        }
        $base64_signature = $this->base64url_encode($signature);
        
        return $base64_header . '.' . $base64_payload . '.' . $base64_signature;
    }
    
    /**
     * Base64 URL encoding (URL güvenli)
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * ID Token'dan kullanıcı bilgilerini çıkar
     */
    public function map_userinfo_to_profile($token_response) {
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => '',
            'email' => '',
            'display_name' => '',
            'first_name' => '',
            'last_name' => '',
            'avatar' => '',
            'profile_url' => '',
            'verified' => false
        ];
        
        // ID token varsa parse et
        if (isset($token_response['id_token'])) {
            $parts = explode('.', $token_response['id_token']);
            if (count($parts) === 3) {
                // Payload'ı decode et
                $payload = base64_decode(strtr($parts[1], '-_', '+/'));
                if ($payload) {
                    $data = json_decode($payload, true);
                    if (is_array($data)) {
                        $profile['user_id'] = $data['sub'] ?? '';
                        $profile['email'] = $data['email'] ?? '';
                        $profile['verified'] = $data['email_verified'] ?? false;
                    }
                }
            }
        }
        
        // Apple'da isim bilgisi POST'tan gelebilir (ilk girişte)
        if (isset($_POST['user'])) {
            $user_data = json_decode(stripslashes($_POST['user']), true);
            if (is_array($user_data) && isset($user_data['name'])) {
                $profile['first_name'] = $user_data['name']['firstName'] ?? '';
                $profile['last_name'] = $user_data['name']['lastName'] ?? '';
                $profile['display_name'] = trim($profile['first_name'] . ' ' . $profile['last_name']);
            }
        }
        
        // Display name yoksa email'i kullan
        if (empty($profile['display_name']) && !empty($profile['email'])) {
            $profile['display_name'] = $profile['email'];
        }
        
        // Username oluştur (email'in @ öncesi)
        if (!empty($profile['email'])) {
            $parts = explode('@', $profile['email']);
            $profile['username'] = sanitize_user($parts[0], true);
        } else {
            $profile['username'] = '';
        }
        
        return $profile;
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     */
    public function handle_callback($request) {
        try {
            // State kontrolü
            if (!isset($request['state']) || !wp_verify_nonce($request['state'], 'apple_oauth_state')) {
                throw new Exception('Geçersiz state parametresi');
            }
            
            // Hata kontrolü
            if (isset($request['error'])) {
                $error = isset($request['error_description']) ? $request['error_description'] : $request['error'];
                throw new Exception('Apple OAuth hatası: ' . $error);
            }
            
            // Authorization code kontrolü
            if (!isset($request['code'])) {
                throw new Exception('Authorization code bulunamadı');
            }
            
            $code = $request['code'];
            
            // Token al
            $token_response = $this->get_access_token($code);
            
            if (is_wp_error($token_response)) {
                throw new Exception($token_response->get_error_message());
            }
            
            if (!isset($token_response['access_token'])) {
                throw new Exception('Access token alınamadı');
            }
            
            $access_token = $token_response['access_token'];
            $refresh_token = isset($token_response['refresh_token']) ? $token_response['refresh_token'] : null;
            $expires_in = isset($token_response['expires_in']) ? (int) $token_response['expires_in'] : 3600;
            
            // Profil oluştur (id_token'dan)
            $profile = $this->map_userinfo_to_profile($token_response);
            
            // Token bilgilerini profile ekle
            $profile['access_token'] = $access_token;
            $profile['refresh_token'] = $refresh_token;
            $profile['expires_in'] = $expires_in;
            $profile['token_type'] = 'Bearer';
            
            // Kullanıcı bul veya oluştur
            $user = $this->find_or_create_user($profile);
            
            if (is_wp_error($user)) {
                return $user;
            }
            
            // Profili döndür (user_id'yi de ekle)
            $profile['user_id'] = is_numeric($user) ? $user : $user->ID;
            
            return $profile;
            
        } catch (Exception $e) {
            error_log('Apple callback hatası: ' . $e->getMessage());
            return new WP_Error('callback_error', $e->getMessage());
        }
    }
    
    /**
     * Auth handler
     */
    public function handle_auth($request) {
        $state = wp_generate_password(12, false);
        $auth_url = $this->get_auth_url($state);
        
        if (headers_sent()) {
            echo '<script>window.location.href="' . esc_url($auth_url) . '";</script>';
            exit;
        } else {
            wp_redirect($auth_url);
            exit;
        }
    }
    
    /**
     * Kullanıcı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        if (empty($profile['user_id']) || empty($profile['provider_slug'])) {
            return new WP_Error('invalid_profile', 'Invalid profile data');
        }
        
        // Önce mevcut bağlantıyı kontrol et
        $user_id = $this->find_user_by_provider($profile['provider_slug'], $profile['user_id']);
        
        if ($user_id) {
            return get_user_by('id', $user_id);
        }
        
        // Email ile kullanıcı ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                // Bağlantıyı kaydet
                $this->save_provider_user_id($user->ID, $profile['provider_slug'], $profile['user_id']);
                return $user;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $password = wp_generate_password();
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@apple.local';
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Kullanıcı bilgilerini güncelle
        $user_data = [
            'ID' => $user_id,
            'display_name' => $profile['display_name'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name']
        ];
        
        wp_update_user($user_data);
        
        // Bağlantıyı kaydet
        $this->save_provider_user_id($user_id, $profile['provider_slug'], $profile['user_id']);
        
        // Email doğrulama durumunu kaydet
        update_user_meta($user_id, 'ai_community_apple_email_verified', $profile['verified']);
        
        // Token bilgilerini kaydet
        if (!empty($profile['access_token'])) {
            update_user_meta($user_id, 'ai_community_apple_access_token', $profile['access_token']);
        }
        
        if (!empty($profile['refresh_token'])) {
            update_user_meta($user_id, 'ai_community_apple_refresh_token', $profile['refresh_token']);
        }
        
        if (!empty($profile['expires_in'])) {
            update_user_meta($user_id, 'ai_community_apple_token_expires', time() + $profile['expires_in']);
        }
        
        return get_user_by('id', $user_id);
    }
    
    /**
     * Provider'a göre kullanıcı bul
     */
    protected function find_user_by_provider($provider_slug, $provider_user_id) {
        global $wpdb;
        
        $meta_key = 'ai_community_' . $provider_slug . '_id';
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key,
            $provider_user_id
        ));
        
        return $user_id ? (int) $user_id : null;
    }
    
    /**
     * Provider kullanıcı ID'sini kaydet
     */
    protected function save_provider_user_id($user_id, $provider_slug, $provider_user_id) {
        $meta_key = 'ai_community_' . $provider_slug . '_id';
        update_user_meta($user_id, $meta_key, $provider_user_id);
    }
    
    /**
     * Unique username oluştur
     */
    protected function generate_unique_username($profile) {
        $base = !empty($profile['username']) ? $profile['username'] : 
                (!empty($profile['display_name']) ? $profile['display_name'] : 
                $profile['provider_slug'] . '_user');
        
        $username = sanitize_user($base, true);
        $username = str_replace(' ', '_', strtolower($username));
        $username = preg_replace('/[^a-z0-9_]/', '', $username);
        
        if (empty($username)) {
            $username = $profile['provider_slug'] . '_' . $profile['user_id'];
        }
        
        $original = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $original . '_' . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Token alma metodu - PUBLIC
     */
    public function get_access_token($code) {
        $params = $this->get_token_params($code);
        $headers = $this->get_token_headers();
        
        $response = wp_remote_post($this->token_url, [
            'headers' => $headers,
            'body' => $params, // http_build_query kullanma çünkü zaten array
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Apple token alma hatası: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }
    
    /**
     * Kullanıcı bilgilerini al - Apple'da ayrı endpoint yok
     */
    public function get_userinfo($token) {
        // Apple'da ayrı userinfo endpoint'i yok, bilgiler id_token'da
        return [];
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Apple';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}