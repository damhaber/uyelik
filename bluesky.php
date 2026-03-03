<?php
// masal-panel/modules/ai-community-engine/core/providers/bluesky.php
// BLUESKY OAUTH2 PROVIDER (AT Protocol)

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Bluesky extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Bluesky';
    protected $name = 'Bluesky';
    public $slug = 'bluesky';
    
    // Bluesky özel değişkenler
    private $pds_url = 'https://bsky.social'; // Varsayılan PDS (Personal Data Server)
    private $did = ''; // Decentralized Identifier
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Bluesky özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        if (isset($config['pds_url'])) {
            $this->pds_url = $config['pds_url'];
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
            
            $this->core->log('bluesky_oauth_error', [
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
            $this->core->log('bluesky_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Session ID varsa al
        $session_id = isset($params['session_id']) ? $params['session_id'] : '';
        
        // Access token al
        $token_data = $this->get_access_token($code, $session_id);
        
        if (is_wp_error($token_data)) {
            $this->core->log('bluesky_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('bluesky_userinfo_error', [
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
            $this->core->log('bluesky_user_creation_error', [
                'error' => is_wp_error($user_id) ? $user_id->get_error_message() : 'Unknown error'
            ]);
            wp_redirect(home_url('/login?error=user_creation_failed'));
            exit;
        }
        
        // WordPress oturumu başlat
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Token'ları kullanıcı meta'sına kaydet
        if (!empty($token_data['access_token'])) {
            update_user_meta($user_id, 'bluesky_access_token', $token_data['access_token']);
            update_user_meta($user_id, 'bluesky_refresh_token', isset($token_data['refresh_token']) ? $token_data['refresh_token'] : '');
            if (!empty($token_data['expires_in'])) {
                $expiry_time = time() + intval($token_data['expires_in']);
                update_user_meta($user_id, 'bluesky_token_expiry', $expiry_time);
            }
        }
        
        // DID'i kaydet
        if (!empty($profile['did'])) {
            update_user_meta($user_id, 'bluesky_did', $profile['did']);
        }
        
        // PDS URL'ini kaydet
        update_user_meta($user_id, 'bluesky_pds_url', $this->pds_url);
        
        // Avatar kaydet (varsa)
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        // Başarılı giriş, yönlendir
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'bluesky');
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
     * Bluesky OAuth2 authorization endpoint
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->get_redirect_uri(),
            'state' => $state,
            'scope' => $this->get_scope()
        ];
        
        return $this->pds_url . '/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return $this->pds_url . '/oauth/token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al (com.atproto.server.getSession)
     */
    public function get_userinfo_url() {
        return $this->pds_url . '/xrpc/com.atproto.server.getSession';
    }
    
    /**
     * Profil bilgisi URL'i (app.bsky.actor.getProfile)
     */
    public function get_profile_url($actor) {
        return $this->pds_url . '/xrpc/app.bsky.actor.getProfile?actor=' . urlencode($actor);
    }
    
    /**
     * Token parametrelerini al
     * 
     * @param string $code Yetkilendirme kodu
     * @param string $session_id Session ID (opsiyonel)
     * @return array
     */
    public function get_token_params($code, $session_id = '') {
        $params = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->get_redirect_uri()
        ];
        
        if (!empty($session_id)) {
            $params['session_id'] = $session_id;
        }
        
        return $params;
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
            'Content-Type' => 'application/json',
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
        return isset($response['expires_in']) ? intval($response['expires_in']) : 7200; // Varsayılan 2 saat
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
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametrelerini al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_params($access_token) {
        return []; // Session endpoint'i parametresiz
    }
    
    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'com.atproto.access';
    }
    
    /**
     * Detaylı profil bilgilerini al
     * 
     * @param string $access_token Access token
     * @param string $actor Actor handle veya DID
     * @return array|WP_Error
     */
    public function get_detailed_profile($access_token, $actor) {
        $url = $this->get_profile_url($actor);
        
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
        return json_decode($body, true);
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $response API'den gelen yanıt (getSession)
     * @return array
     */
    public function map_userinfo_to_profile($response) {
        if (empty($response) || empty($response['did'])) {
            return [
                'id' => '',
                'did' => '',
                'handle' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'bio' => '',
                'followers_count' => 0,
                'follows_count' => 0,
                'posts_count' => 0
            ];
        }
        
        $this->did = $response['did'];
        
        // Temel profil bilgileri
        $profile = [
            'id'            => $response['did'],
            'did'           => $response['did'],
            'handle'        => isset($response['handle']) ? $response['handle'] : '',
            'email'         => isset($response['email']) ? $response['email'] : '',
            'email_confirmed' => isset($response['emailConfirmed']) ? $response['emailConfirmed'] : false,
            'name'          => '',
            'avatar'        => '',
            'bio'           => '',
            'followers_count' => 0,
            'follows_count' => 0,
            'posts_count'   => 0,
            'created_at'    => ''
        ];
        
        // Detaylı profil bilgilerini al (opsiyonel)
        if (!empty($response['handle']) && !empty($response['access_token'])) {
            $detailed = $this->get_detailed_profile($response['access_token'], $response['handle']);
            
            if (!is_wp_error($detailed)) {
                $profile['name'] = isset($detailed['displayName']) ? $detailed['displayName'] : '';
                $profile['avatar'] = isset($detailed['avatar']) ? $detailed['avatar'] : '';
                $profile['bio'] = isset($detailed['description']) ? $detailed['description'] : '';
                $profile['followers_count'] = isset($detailed['followersCount']) ? $detailed['followersCount'] : 0;
                $profile['follows_count'] = isset($detailed['followsCount']) ? $detailed['followsCount'] : 0;
                $profile['posts_count'] = isset($detailed['postsCount']) ? $detailed['postsCount'] : 0;
                $profile['created_at'] = isset($detailed['createdAt']) ? $detailed['createdAt'] : '';
            }
        }
        
        return $profile;
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|false|WP_Error
     */
    public function find_or_create_user($profile) {
        // DID'e göre kullanıcı ara (meta_key)
        if (!empty($profile['did'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_bluesky_did',
                'meta_value' => $profile['did'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Handle'a göre ara (meta_key)
        if (!empty($profile['handle'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'bluesky_handle',
                'meta_value' => $profile['handle'],
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
        if (!empty($profile['handle'])) {
            $user = get_user_by('login', $profile['handle']);
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
            $this->core->log('bluesky_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['did'])) {
            update_user_meta($user_id, 'ai_comm_provider_bluesky_did', $profile['did']);
            update_user_meta($user_id, 'bluesky_did', $profile['did']);
        }
        update_user_meta($user_id, 'ai_comm_provider_bluesky_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_bluesky_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'bluesky');
        
        // Bluesky'a özel metalar
        if (!empty($profile['handle'])) {
            update_user_meta($user_id, 'bluesky_handle', $profile['handle']);
        }
        if (!empty($profile['bio'])) {
            update_user_meta($user_id, 'description', $profile['bio']); // WordPress bio
            update_user_meta($user_id, 'bluesky_bio', $profile['bio']);
        }
        if (!empty($profile['followers_count'])) {
            update_user_meta($user_id, 'bluesky_followers', $profile['followers_count']);
        }
        if (!empty($profile['follows_count'])) {
            update_user_meta($user_id, 'bluesky_follows', $profile['follows_count']);
        }
        if (!empty($profile['posts_count'])) {
            update_user_meta($user_id, 'bluesky_posts', $profile['posts_count']);
        }
        if (!empty($profile['email_confirmed'])) {
            update_user_meta($user_id, 'bluesky_email_confirmed', $profile['email_confirmed']);
        }
        if (!empty($profile['created_at'])) {
            update_user_meta($user_id, 'bluesky_created_at', $profile['created_at']);
        }
        
        // PDS URL'ini kaydet
        update_user_meta($user_id, 'bluesky_pds_url', $this->pds_url);
        
        // Görünen ismi ayarla
        if (!empty($profile['name'])) {
            update_user_meta($user_id, 'nickname', $profile['name']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
        } elseif (!empty($profile['handle'])) {
            update_user_meta($user_id, 'nickname', $profile['handle']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['handle']
            ]);
        }
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
            update_user_meta($user_id, 'bluesky_avatar', $profile['avatar']);
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
        
        if (!empty($profile['handle'])) {
            // Handle formatı: @kullanici.bsky.social
            $handle_parts = explode('.', $profile['handle']);
            $base = sanitize_user($handle_parts[0], true);
        } elseif (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } elseif (!empty($profile['did'])) {
            $base = 'bluesky_' . substr(md5($profile['did']), 0, 8);
        } else {
            $base = 'bluesky_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'bluesky';
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
        
        if (!empty($profile['did'])) {
            return 'bluesky_' . md5($profile['did']) . '@social.local';
        }
        
        return 'bluesky_' . wp_generate_password(8, false) . '@social.local';
    }
    
    /**
     * Kullanıcının gönderilerini getir (feed)
     * 
     * @param string $access_token Access token
     * @param string $actor Actor handle veya DID
     * @param int $limit Limit
     * @return array|WP_Error
     */
    public function get_author_feed($access_token, $actor, $limit = 30) {
        $url = $this->pds_url . '/xrpc/app.bsky.feed.getAuthorFeed';
        
        $params = [
            'actor' => $actor,
            'limit' => min($limit, 100)
        ];
        
        $url = add_query_arg($params, $url);
        
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
        
        return isset($data['feed']) ? $data['feed'] : [];
    }
    
    /**
     * Token'ı yenile (refresh token ile)
     * 
     * @param string $refresh_token Refresh token
     * @return array|WP_Error
     */
    public function refresh_access_token($refresh_token) {
        $url = $this->pds_url . '/oauth/token';
        
        $params = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($params),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
}