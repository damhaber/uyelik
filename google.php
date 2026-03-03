<?php
// masal-panel/modules/ai-community-engine/core/providers/google.php

if (!defined('ABSPATH')) {
    exit;
}

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Google extends AI_Community_OAuth2_Provider {

    protected $label = 'Google';
    public $slug = 'google';
    protected $scope = 'openid email profile';
    protected $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth';
    protected $token_url = 'https://oauth2.googleapis.com/token';
    protected $userinfo_url = 'https://www.googleapis.com/oauth2/v3/userinfo';
    
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
        
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
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
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        } else {
            $params['state'] = wp_create_nonce('google_oauth_state');
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
            'client_secret' => $this->client_secret
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
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
        ];
    }
    
    public function get_userinfo_params($token) {
        return [];
    }
    
    public function get_userinfo_params_with_token($token) {
        return [];
    }
    
    public function map_userinfo_to_profile($userinfo) {
        // $userinfo zaten array olarak gelmeli, string ise decode et
        if (is_string($userinfo)) {
            $userinfo = json_decode($userinfo, true);
        }
        
        if (!is_array($userinfo)) {
            $userinfo = [];
        }
        
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => isset($userinfo['sub']) ? (string) $userinfo['sub'] : '',
            'email' => isset($userinfo['email']) ? $userinfo['email'] : '',
            'display_name' => isset($userinfo['name']) ? $userinfo['name'] : '',
            'first_name' => isset($userinfo['given_name']) ? $userinfo['given_name'] : '',
            'last_name' => isset($userinfo['family_name']) ? $userinfo['family_name'] : '',
            'avatar' => isset($userinfo['picture']) ? $userinfo['picture'] : '',
            'profile_url' => '',
            'locale' => isset($userinfo['locale']) ? $userinfo['locale'] : '',
            'verified' => isset($userinfo['email_verified']) ? (bool) $userinfo['email_verified'] : false
        ];
        
        // Username oluştur (email'in @ öncesi)
        if (!empty($profile['email'])) {
            $parts = explode('@', $profile['email']);
            $profile['username'] = $parts[0];
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
            if (!isset($request['state']) || !wp_verify_nonce($request['state'], 'google_oauth_state')) {
                throw new Exception('Geçersiz state parametresi');
            }
            
            // Hata kontrolü
            if (isset($request['error'])) {
                $error = isset($request['error_description']) ? $request['error_description'] : $request['error'];
                throw new Exception('Google OAuth hatası: ' . $error);
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
            
            // Kullanıcı bilgilerini al
            $userinfo = $this->get_userinfo($access_token);
            
            if (is_wp_error($userinfo)) {
                throw new Exception($userinfo->get_error_message());
            }
            
            if (!$userinfo) {
                throw new Exception('Kullanıcı bilgileri alınamadı');
            }
            
            // Profil oluştur
            $profile = $this->map_userinfo_to_profile($userinfo);
            
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
            error_log('Google callback hatası: ' . $e->getMessage());
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
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@google.local';
        
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
        
        // Avatar'ı kaydet (opsiyonel)
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
        }
        
        // Google spesifik bilgileri kaydet
        if (!empty($profile['locale'])) {
            update_user_meta($user_id, 'ai_community_google_locale', $profile['locale']);
        }
        
        // Token bilgilerini kaydet
        if (!empty($profile['access_token'])) {
            update_user_meta($user_id, 'ai_community_google_access_token', $profile['access_token']);
        }
        
        if (!empty($profile['refresh_token'])) {
            update_user_meta($user_id, 'ai_community_google_refresh_token', $profile['refresh_token']);
        }
        
        if (!empty($profile['expires_in'])) {
            update_user_meta($user_id, 'ai_community_google_token_expires', time() + $profile['expires_in']);
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
     * Refresh token ile yeni access token al
     */
    public function refresh_access_token($refresh_token) {
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];
        
        $response = wp_remote_post($this->token_url, [
            'body' => http_build_query($params),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Google refresh token hatası: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['access_token'])) {
            return $data;
        }
        
        return new WP_Error('refresh_error', 'Failed to refresh token');
    }
    
    /**
     * Token alma metodu - PUBLIC
     */
    public function get_access_token($code) {
        $params = $this->get_token_params($code);
        
        $response = wp_remote_post($this->token_url, [
            'body' => http_build_query($params),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Google token alma hatası: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }
    
    /**
     * Kullanıcı bilgilerini al - PUBLIC
     */
    public function get_userinfo($token) {
        $headers = $this->get_userinfo_headers($token);
        
        $response = wp_remote_get($this->userinfo_url, [
            'headers' => $headers,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Google userinfo alma hatası: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return $data;
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Google';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}