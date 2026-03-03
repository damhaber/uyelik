<?php
// masal-panel/modules/ai-community-engine/core/providers/instagram.php
// Instagram OAuth2 Provider - wechat.php mantığıyla

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Instagram extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Instagram';
    protected $name = 'Instagram';
    public $slug = 'instagram';
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
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
        $params = array_merge($_GET, $_POST);
        
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = isset($params['error_description']) ? $params['error_description'] : '';
            
            $this->core->log('instagram_oauth_error', [
                'error' => $error,
                'description' => $error_description
            ]);
            
            wp_redirect(home_url('/login?error=social_auth_failed'));
            exit;
        }
        
        if (isset($params['code'])) {
            return $this->handle_callback($params);
        }
        
        $state = wp_create_nonce('ai_community_oauth_state');
        $auth_url = $this->get_auth_url($state);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Yetkilendirme URL'ini al
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => $this->get_scope(),
            'state' => $state
        ];
        
        return 'https://api.instagram.com/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return 'https://api.instagram.com/oauth/access_token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return 'https://graph.instagram.com/me';
    }
    
    /**
     * Token parametrelerini al
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
     * Token istek metodu
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Access token parse et
     */
    public function parse_access_token($response) {
        return isset($response['access_token']) ? $response['access_token'] : '';
    }
    
    /**
     * Refresh token parse et
     */
    public function parse_refresh_token($response) {
        return isset($response['refresh_token']) ? $response['refresh_token'] : '';
    }
    
    /**
     * Expires in parse et
     */
    public function parse_expires_in($response) {
        return isset($response['expires_in']) ? intval($response['expires_in']) : 0;
    }
    
    /**
     * Kullanıcı bilgi istek metodu
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Kullanıcı bilgi header'ları
     */
    public function get_userinfo_headers($access_token) {
        return [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametreleri
     */
    public function get_userinfo_params($access_token) {
        return [
            'fields' => 'id,username,account_type,media_count,name'
        ];
    }
    
    /**
     * Scope
     */
    public function get_scope() {
        return 'user_profile';
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     */
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo) || empty($userinfo['id'])) {
            return [
                'id' => '',
                'username' => '',
                'name' => ''
            ];
        }
        
        return [
            'id'       => $userinfo['id'],
            'username' => isset($userinfo['username']) ? $userinfo['username'] : '',
            'name'     => isset($userinfo['name']) ? $userinfo['name'] : $userinfo['username'] ?? '',
            'avatar'   => '', // Instagram Graph API avatar vermiyor
            'email'    => ''  // Instagram email vermiyor
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        // Instagram ID'ye göre ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_instagram_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Username'e göre ara
        if (!empty($profile['username'])) {
            $user = get_user_by('login', $profile['username']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = 'instagram_' . md5($profile['id'] . time()) . '@social.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('instagram_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_instagram_id', $profile['id']);
        }
        if (!empty($profile['username'])) {
            update_user_meta($user_id, 'instagram_username', $profile['username']);
        }
        update_user_meta($user_id, 'ai_comm_provider_instagram_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'instagram');
        
        if (!empty($profile['name'])) {
            update_user_meta($user_id, 'nickname', $profile['name']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
        }
        
        return $user_id;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        if (!empty($profile['username'])) {
            $base = sanitize_user($profile['username'], true);
        } elseif (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } elseif (!empty($profile['id'])) {
            $base = 'ig_' . substr(md5($profile['id']), 0, 8);
        } else {
            $base = 'instagram_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'instagram';
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