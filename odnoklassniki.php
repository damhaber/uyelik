<?php
/**
 * AI Community Provider: Odnoklassniki (OK.ru)
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Odnoklassniki extends AI_Community_OAuth2_Provider {
    
    protected $config = [];
    protected $version = 'OAuth2';
    public $slug = 'odnoklassniki';
    protected $scope = 'VALUABLE_ACCESS;GET_EMAIL';
    protected $auth_url = 'https://connect.ok.ru/oauth/authorize';
    protected $token_url = 'https://api.ok.ru/oauth/token.do';
    protected $userinfo_url = 'https://api.ok.ru/api/users/getCurrentUser';
    
    /**
     * Constructor - 3 parametreli olmalı
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
        
        if (isset($config['public_key'])) {
            $this->public_key = $config['public_key'];
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
     * Auth URL - state parametreli
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->get_scope()
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        }
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    public function get_token_url() {
        return $this->token_url;
    }
    
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    public function get_token_params($code) {
        return [
            'code' => $code,
            'redirect_uri' => $this->redirect_uri,
            'grant_type' => 'authorization_code',
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
        return 3600; // Varsayılan 1 saat
    }
    
    public function get_userinfo_method() {
        return 'POST'; // Odnoklassniki POST kullanıyor
    }
    
    public function get_userinfo_headers($token) {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    }
    
    public function get_userinfo_params($token) {
        // Odnoklassniki için özel parametreler
        $secret_key = md5($token . $this->client_secret);
        
        $params = [
            'method' => 'users.getCurrentUser',
            'application_key' => $this->public_key,
            'format' => 'json'
        ];
        
        // Sig oluştur
        $sig_string = 'application_key=' . $this->public_key .
                      'format=json' .
                      'method=users.getCurrentUser' .
                      $secret_key;
        $params['sig'] = md5($sig_string);
        
        return $params;
    }
    
    public function get_userinfo_params_with_token($token) {
        $params = $this->get_userinfo_params($token);
        $params['access_token'] = $token;
        return $params;
    }
    
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo)) {
            $userinfo = [];
        }
        
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => isset($userinfo['uid']) ? (string) $userinfo['uid'] : '',
            'username' => isset($userinfo['username']) ? $userinfo['username'] : '',
            'display_name' => '',
            'first_name' => isset($userinfo['first_name']) ? $userinfo['first_name'] : '',
            'last_name' => isset($userinfo['last_name']) ? $userinfo['last_name'] : '',
            'email' => isset($userinfo['email']) ? $userinfo['email'] : '',
            'avatar' => isset($userinfo['pic_avatar']) ? $userinfo['pic_avatar'] : (isset($userinfo['pic_1']) ? $userinfo['pic_1'] : ''),
            'profile_url' => isset($userinfo['uid']) ? 'https://ok.ru/profile/' . $userinfo['uid'] : '',
            'locale' => isset($userinfo['locale']) ? $userinfo['locale'] : '',
            'gender' => '',
            'birthday' => isset($userinfo['birthday']) ? $userinfo['birthday'] : ''
        ];
        
        // Display name oluştur
        if (!empty($profile['first_name']) || !empty($profile['last_name'])) {
            $profile['display_name'] = trim($profile['first_name'] . ' ' . $profile['last_name']);
        } elseif (!empty($profile['username'])) {
            $profile['display_name'] = $profile['username'];
        }
        
        // Gender çevir
        if (isset($userinfo['gender'])) {
            if ($userinfo['gender'] == 'male' || $userinfo['gender'] == 'M') {
                $profile['gender'] = 'male';
            } elseif ($userinfo['gender'] == 'female' || $userinfo['gender'] == 'F') {
                $profile['gender'] = 'female';
            }
        }
        
        return $profile;
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     */
    public function handle_callback($request) {
        if (isset($request['error'])) {
            return new WP_Error(
                'oauth_error', 
                isset($request['error_description']) ? $request['error_description'] : $request['error']
            );
        }
        
        if (!isset($request['code'])) {
            return new WP_Error('no_code', 'Authorization code not received');
        }
        
        try {
            // Token al
            $token_response = $this->get_access_token($request['code']);
            
            if (is_wp_error($token_response)) {
                return $token_response;
            }
            
            $token = $this->parse_access_token($token_response);
            
            if (!$token) {
                return new WP_Error('no_token', 'Access token not found');
            }
            
            // Userinfo al
            $userinfo = $this->get_userinfo($token);
            
            if (is_wp_error($userinfo)) {
                return $userinfo;
            }
            
            // Profil oluştur
            $profile = $this->map_userinfo_to_profile($userinfo);
            
            // Kullanıcı bul veya oluştur
            $user = $this->find_or_create_user($profile);
            
            if (is_wp_error($user)) {
                return $user;
            }
            
            // Profili döndür (user_id'yi de ekle)
            $profile['user_id'] = is_numeric($user) ? $user : $user->ID;
            
            return $profile;
            
        } catch (Exception $e) {
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
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@example.com';
        
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
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Odnoklassniki';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}