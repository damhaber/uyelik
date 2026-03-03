<?php
// masal-panel/modules/ai-community-engine/core/providers/medium.php
// MEDIUM OAUTH2 PROVIDER

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Medium extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Medium';
    protected $name = 'Medium';
    public $slug = 'medium';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Medium özel yapılandırmalar
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
            
            $this->core->log('medium_oauth_error', [
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
            $this->core->log('medium_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('medium_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('medium_userinfo_error', [
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
            $this->core->log('medium_user_creation_error', [
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
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'medium');
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
            'scope' => $this->get_scope()
        ];
        
        return 'https://medium.com/m/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return 'https://api.medium.com/v1/tokens';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return 'https://api.medium.com/v1/me';
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
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->get_redirect_uri()
        ];
    }
    
    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Token isteği için header'ları al
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'Accept-Charset' => 'utf-8'
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
     * Refresh token parse et - Medium refresh token kullanır
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
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-Charset' => 'utf-8'
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
     * Scope'u al
     */
    public function get_scope() {
        return 'basicProfile';
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $response API'den gelen yanıt (userinfo)
     * @return array
     */
    public function map_userinfo_to_profile($response) {
        // Medium userinfo response: { data: { ... } } formatında
        $userinfo = isset($response['data']) ? $response['data'] : $response;
        
        if (empty($userinfo) || empty($userinfo['id'])) {
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'username' => '',
                'avatar' => '',
                'bio' => '',
                'url' => ''
            ];
        }
        
        return [
            'id'            => $userinfo['id'],
            'email'         => isset($userinfo['email']) ? $userinfo['email'] : '',
            'name'          => isset($userinfo['name']) ? $userinfo['name'] : '',
            'username'      => isset($userinfo['username']) ? $userinfo['username'] : '',
            'avatar'        => isset($userinfo['imageUrl']) ? $userinfo['imageUrl'] : '',
            'bio'           => isset($userinfo['bio']) ? $userinfo['bio'] : '',
            'url'           => isset($userinfo['url']) ? $userinfo['url'] : '',
            'twitter_username' => isset($userinfo['twitterUsername']) ? $userinfo['twitterUsername'] : '',
            'allow_notes'   => isset($userinfo['allowNotes']) ? $userinfo['allowNotes'] : false,
            'medium_member_at' => isset($userinfo['mediumMemberAt']) ? $userinfo['mediumMemberAt'] : '',
            'publication_following_count' => isset($userinfo['publicationFollowingCount']) ? $userinfo['publicationFollowingCount'] : 0,
            'follower_count' => isset($userinfo['followerCount']) ? $userinfo['followerCount'] : 0,
            'following_count' => isset($userinfo['followingCount']) ? $userinfo['followingCount'] : 0,
            'post_count'    => isset($userinfo['postCount']) ? $userinfo['postCount'] : 0,
            'top_writer_topics' => isset($userinfo['topWriterTopics']) ? $userinfo['topWriterTopics'] : [],
            'has_list'      => isset($userinfo['hasList']) ? $userinfo['hasList'] : false
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|false|WP_Error
     */
    public function find_or_create_user($profile) {
        // Medium ID'ye göre kullanıcı ara (meta_key)
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_medium_id',
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
            $this->core->log('medium_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_medium_id', $profile['id']);
        }
        update_user_meta($user_id, 'ai_comm_provider_medium_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_medium_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'medium');
        
        // Medium'a özel metalar
        if (!empty($profile['username'])) {
            update_user_meta($user_id, 'medium_username', $profile['username']);
        }
        if (!empty($profile['bio'])) {
            update_user_meta($user_id, 'description', $profile['bio']); // WordPress bio
            update_user_meta($user_id, 'medium_bio', $profile['bio']);
        }
        if (!empty($profile['url'])) {
            update_user_meta($user_id, 'medium_url', $profile['url']);
            update_user_meta($user_id, 'user_url', $profile['url']); // WordPress website
        }
        if (!empty($profile['twitter_username'])) {
            update_user_meta($user_id, 'medium_twitter', $profile['twitter_username']);
        }
        if (!empty($profile['follower_count'])) {
            update_user_meta($user_id, 'medium_follower_count', $profile['follower_count']);
        }
        if (!empty($profile['following_count'])) {
            update_user_meta($user_id, 'medium_following_count', $profile['following_count']);
        }
        if (!empty($profile['post_count'])) {
            update_user_meta($user_id, 'medium_post_count', $profile['post_count']);
        }
        if (!empty($profile['publication_following_count'])) {
            update_user_meta($user_id, 'medium_publication_following', $profile['publication_following_count']);
        }
        if (!empty($profile['medium_member_at'])) {
            update_user_meta($user_id, 'medium_member_since', $profile['medium_member_at']);
        }
        if (!empty($profile['top_writer_topics'])) {
            update_user_meta($user_id, 'medium_top_writer_topics', wp_json_encode($profile['top_writer_topics']));
        }
        if (isset($profile['allow_notes'])) {
            update_user_meta($user_id, 'medium_allow_notes', $profile['allow_notes']);
        }
        if (isset($profile['has_list'])) {
            update_user_meta($user_id, 'medium_has_list', $profile['has_list']);
        }
        
        // Görünen ismi ayarla
        if (!empty($profile['name'])) {
            update_user_meta($user_id, 'nickname', $profile['name']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
        }
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
            update_user_meta($user_id, 'medium_avatar', $profile['avatar']);
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
            $base = 'medium_' . substr(md5($profile['id']), 0, 8);
        } else {
            $base = 'medium_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'medium';
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
            return 'medium_' . md5($profile['id']) . '@social.local';
        }
        
        return 'medium_' . wp_generate_password(8, false) . '@social.local';
    }
    
    /**
     * Kullanıcının yayınlarını getir (opsiyonel)
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_user_publications($access_token) {
        $url = 'https://api.medium.com/v1/users/' . $this->get_current_user_id($access_token) . '/publications';
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['data']) ? $data['data'] : [];
    }
    
    /**
     * Kullanıcının makalelerini getir (opsiyonel)
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_user_posts($access_token) {
        $url = 'https://api.medium.com/v1/users/' . $this->get_current_user_id($access_token) . '/posts';
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['data']) ? $data['data'] : [];
    }
    
    /**
     * Mevcut kullanıcının ID'sini al
     * 
     * @param string $access_token Access token
     * @return string|null
     */
    private function get_current_user_id($access_token) {
        $userinfo = $this->get_userinfo($access_token);
        
        if (!is_wp_error($userinfo) && isset($userinfo['data']['id'])) {
            return $userinfo['data']['id'];
        }
        
        return null;
    }
}