<?php
// masal-panel/modules/ai-community-engine/core/providers/reddit.php
// REDDIT OAUTH2 PROVIDER

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Reddit extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Reddit';
    protected $name = 'Reddit';
    public $slug = 'reddit';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Reddit özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
    }
    
    /**
     * Ana işleyici metodu - Parametresiz!
     * Tüm OAuth2 callback isteklerini işler
     */
    public function handle() {
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = isset($params['error_description']) ? $params['error_description'] : '';
            
            $this->core->log('reddit_oauth_error', [
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
     */
    protected function handle_callback($params) {
        $code = $params['code'];
        $state = isset($params['state']) ? $params['state'] : '';
        
        // State doğrulama (CSRF koruması)
        if (!wp_verify_nonce($state, 'ai_community_oauth_state')) {
            $this->core->log('reddit_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('reddit_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('reddit_userinfo_error', [
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
            $this->core->log('reddit_user_creation_error', [
                'error' => is_wp_error($user_id) ? $user_id->get_error_message() : 'Unknown error'
            ]);
            wp_redirect(home_url('/login?error=user_creation_failed'));
            exit;
        }
        
        // WordPress oturumu başlat
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Avatar kaydet (varsa)
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        // Başarılı giriş, yönlendir
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'reddit');
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
     * Yetkilendirme URL'ini al
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'state' => $state,
            'redirect_uri' => $this->get_redirect_uri(),
            'duration' => 'permanent', // Reddit için: temporary veya permanent
            'scope' => $this->get_scope()
        ];
        
        return 'https://www.reddit.com/api/v1/authorize?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return 'https://www.reddit.com/api/v1/access_token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return 'https://oauth.reddit.com/api/v1/me';
    }
    
    /**
     * Token parametrelerini al
     * 
     * @param string $code Yetkilendirme kodu
     * @return array
     */
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->get_redirect_uri()
        ];
    }
    
    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return 'POST'; // Reddit POST kullanır
    }
    
    /**
     * Token isteği için header'ları al
     * Reddit Basic Auth gerektirir
     */
    public function get_token_headers() {
        $auth = base64_encode($this->client_id . ':' . $this->client_secret);
        
        return [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'User-Agent' => 'WordPress:AI-Community:v1.0 (by /u/' . get_option('reddit_username', 'your_username') . ')'
        ];
    }
    
    /**
     * Access token parse et
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_access_token($response) {
        return isset($response['access_token']) ? $response['access_token'] : '';
    }
    
    /**
     * Refresh token parse et
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_refresh_token($response) {
        return isset($response['refresh_token']) ? $response['refresh_token'] : '';
    }
    
    /**
     * Expires in parse et
     * 
     * @param array $response API yanıtı
     * @return int
     */
    public function parse_expires_in($response) {
        return isset($response['expires_in']) ? intval($response['expires_in']) : 3600; // Varsayılan 1 saat
    }
    
    /**
     * Kullanıcı bilgi istek metodunu al
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Kullanıcı bilgi header'larını al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_headers($access_token) {
        return [
            'Authorization' => 'Bearer ' . $access_token,
            'User-Agent' => 'WordPress:AI-Community:v1.0 (by /u/' . get_option('reddit_username', 'your_username') . ')'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametrelerini al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_params($access_token) {
        return []; // Reddit için ek parametre gerekmez
    }
    
    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'identity';
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $userinfo API'den gelen kullanıcı bilgileri
     * @return array
     */
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo) || empty($userinfo['name'])) {
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'username' => ''
            ];
        }
        
        // Reddit'te email genelde gelmez, izin gerektirir
        $email = isset($userinfo['email']) ? $userinfo['email'] : '';
        
        // Reddit'in icon_img alanı avatar URL'sini içerir
        $avatar = '';
        if (!empty($userinfo['icon_img'])) {
            // Reddit'te icon_img formatı: "url?reddit=..." şeklinde olabilir
            $avatar_parts = explode('?', $userinfo['icon_img']);
            $avatar = $avatar_parts[0];
        }
        
        return [
            'id'       => $userinfo['name'], // Reddit'te name benzersizdir
            'email'    => $email,
            'name'     => isset($userinfo['subreddit']['title']) ? $userinfo['subreddit']['title'] : $userinfo['name'],
            'username' => $userinfo['name'],
            'avatar'   => $avatar,
            'created_utc' => isset($userinfo['created_utc']) ? $userinfo['created_utc'] : '',
            'link_karma' => isset($userinfo['link_karma']) ? $userinfo['link_karma'] : 0,
            'comment_karma' => isset($userinfo['comment_karma']) ? $userinfo['comment_karma'] : 0,
            'is_gold' => isset($userinfo['is_gold']) ? $userinfo['is_gold'] : false,
            'is_mod' => isset($userinfo['is_mod']) ? $userinfo['is_mod'] : false,
            'has_verified_email' => isset($userinfo['has_verified_email']) ? $userinfo['has_verified_email'] : false
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|false|WP_Error
     */
    public function find_or_create_user($profile) {
        // Reddit username'e göre kullanıcı ara (meta_key)
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_reddit_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Email varsa email'e göre ara
        if (!empty($profile['email']) && is_email($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Kullanıcı adına göre ara (WordPress kullanıcı adı)
        if (!empty($profile['username'])) {
            $user = get_user_by('login', $profile['username']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = $this->generate_email($profile);
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('reddit_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_reddit_id', $profile['id']);
        }
        update_user_meta($user_id, 'ai_comm_provider_reddit_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_reddit_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'reddit');
        
        // Reddit'e özel metalar
        if (!empty($profile['link_karma'])) {
            update_user_meta($user_id, 'reddit_link_karma', $profile['link_karma']);
        }
        if (!empty($profile['comment_karma'])) {
            update_user_meta($user_id, 'reddit_comment_karma', $profile['comment_karma']);
        }
        if (!empty($profile['created_utc'])) {
            update_user_meta($user_id, 'reddit_created_utc', $profile['created_utc']);
        }
        if (!empty($profile['is_gold'])) {
            update_user_meta($user_id, 'reddit_is_gold', $profile['is_gold']);
        }
        if (!empty($profile['is_mod'])) {
            update_user_meta($user_id, 'reddit_is_mod', $profile['is_mod']);
        }
        if (!empty($profile['has_verified_email'])) {
            update_user_meta($user_id, 'reddit_has_verified_email', $profile['has_verified_email']);
        }
        
        if (!empty($profile['name'])) {
            update_user_meta($user_id, 'nickname', $profile['name']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
        }
        
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
        }
        
        return $user_id;
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
            $base = 'reddit_' . substr(md5($profile['id']), 0, 8);
        } else {
            $base = 'reddit_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'reddit';
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
     * Email oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return string
     */
    protected function generate_email($profile) {
        if (!empty($profile['email']) && is_email($profile['email'])) {
            return $profile['email'];
        }
        
        if (!empty($profile['id'])) {
            return 'reddit_' . md5($profile['id']) . '@social.local';
        }
        
        return 'reddit_' . wp_generate_password(8, false) . '@social.local';
    }
}