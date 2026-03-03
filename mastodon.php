<?php
// masal-panel/modules/ai-community-engine/core/providers/mastodon.php
// MASTODON OAUTH2 PROVIDER (ActivityPub / Fediverse)

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Mastodon extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Mastodon';
    protected $name = 'Mastodon';
    public $slug = 'mastodon';
    
    // Mastodon özel değişkenler
    private $instance_url = 'https://mastodon.social'; // Varsayılan instance
    private $api_version = 'v2'; // Mastodon API versiyonu
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Mastodon özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        if (isset($config['instance_url'])) {
            $this->instance_url = rtrim($config['instance_url'], '/');
        }
        if (isset($config['api_version'])) {
            $this->api_version = $config['api_version'];
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
            
            $this->core->log('mastodon_oauth_error', [
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
        
        // Instance seçimi varsa session'a kaydet
        if (isset($params['instance'])) {
            if (!session_id()) {
                session_start();
            }
            $_SESSION['mastodon_instance'] = sanitize_text_field($params['instance']);
        }
        
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
            $this->core->log('mastodon_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Session'dan instance URL'ini al
        if (!session_id()) {
            session_start();
        }
        $instance_url = isset($_SESSION['mastodon_instance']) ? $_SESSION['mastodon_instance'] : $this->instance_url;
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('mastodon_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al (verify_credentials)
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('mastodon_userinfo_error', [
                'error' => $userinfo->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=userinfo_error'));
            exit;
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($userinfo);
        
        // Instance bilgisini ekle
        $profile['instance'] = $instance_url;
        
        // Kullanıcı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id || is_wp_error($user_id)) {
            $this->core->log('mastodon_user_creation_error', [
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
            update_user_meta($user_id, 'mastodon_access_token', $token_data['access_token']);
            if (!empty($token_data['created_at']) && !empty($token_data['expires_in'])) {
                $expiry_time = intval($token_data['created_at']) + intval($token_data['expires_in']);
                update_user_meta($user_id, 'mastodon_token_expiry', $expiry_time);
            }
        }
        
        // Instance URL'ini kaydet
        update_user_meta($user_id, 'mastodon_instance', $instance_url);
        
        // Session'ı temizle
        unset($_SESSION['mastodon_instance']);
        
        // Avatar kaydet (varsa)
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        // Başarılı giriş, yönlendir
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'mastodon');
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
     * Instance URL'ini ayarla
     * 
     * @param string $url Instance URL
     */
    public function set_instance_url($url) {
        $this->instance_url = rtrim($url, '/');
    }
    
    /**
     * OAuth uygulamasını kaydet (Mastodon'da önce app registration gerekir)
     * 
     * @param string $instance_url Instance URL
     * @param string $app_name Uygulama adı
     * @param string $redirect_uri Redirect URI
     * @return array|WP_Error
     */
    public function register_app($instance_url, $app_name, $redirect_uri) {
        $url = rtrim($instance_url, '/') . '/api/v1/apps';
        
        $params = [
            'client_name' => $app_name,
            'redirect_uris' => $redirect_uri,
            'scopes' => 'read:accounts read:profile',
            'website' => home_url()
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
            'redirect_uri' => $this->get_redirect_uri(),
            'scope' => $this->get_scope(),
            'state' => $state
        ];
        
        return $this->instance_url . '/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return $this->instance_url . '/oauth/token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al (verify_credentials)
     */
    public function get_userinfo_url() {
        return $this->instance_url . '/api/v1/accounts/verify_credentials';
    }
    
    /**
     * Kullanıcının gönderilerini getir (statuses)
     */
    public function get_statuses_url($account_id) {
        return $this->instance_url . '/api/v1/accounts/' . $account_id . '/statuses';
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
            'Content-Type' => 'application/json'
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
     * Refresh token parse et - Mastodon refresh token kullanmaz
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_refresh_token($response) {
        return ''; // Mastodon refresh token kullanmaz
    }
    
    /**
     * Expires in parse et - Mastodon token'ları genelde süresiz
     * 
     * @param array $response API yanıtı
     * @return int
     */
    public function parse_expires_in($response) {
        return isset($response['expires_in']) ? intval($response['expires_in']) : 0; // 0 = süresiz
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
            'Authorization' => 'Bearer ' . $access_token
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
        return 'read:accounts read:profile';
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $response API'den gelen yanıt (verify_credentials)
     * @return array
     */
    public function map_userinfo_to_profile($response) {
        if (empty($response) || empty($response['id'])) {
            return [
                'id' => '',
                'username' => '',
                'acct' => '',
                'display_name' => '',
                'email' => '',
                'avatar' => '',
                'avatar_static' => '',
                'header' => '',
                'header_static' => '',
                'bio' => '',
                'followers_count' => 0,
                'following_count' => 0,
                'statuses_count' => 0,
                'last_status_at' => '',
                'created_at' => '',
                'locked' => false,
                'bot' => false,
                'discoverable' => false,
                'indexable' => false
            ];
        }
        
        return [
            'id'                => $response['id'],
            'username'          => isset($response['username']) ? $response['username'] : '',
            'acct'              => isset($response['acct']) ? $response['acct'] : '', // @kullanici@instance
            'display_name'      => isset($response['display_name']) ? $response['display_name'] : '',
            'email'             => isset($response['email']) ? $response['email'] : '', // Sadece verify_credentials'de gelir
            'avatar'            => isset($response['avatar']) ? $response['avatar'] : '',
            'avatar_static'     => isset($response['avatar_static']) ? $response['avatar_static'] : '',
            'header'            => isset($response['header']) ? $response['header'] : '',
            'header_static'     => isset($response['header_static']) ? $response['header_static'] : '',
            'bio'               => isset($response['note']) ? $response['note'] : '',
            'followers_count'   => isset($response['followers_count']) ? $response['followers_count'] : 0,
            'following_count'   => isset($response['following_count']) ? $response['following_count'] : 0,
            'statuses_count'    => isset($response['statuses_count']) ? $response['statuses_count'] : 0,
            'last_status_at'    => isset($response['last_status_at']) ? $response['last_status_at'] : '',
            'created_at'        => isset($response['created_at']) ? $response['created_at'] : '',
            'locked'            => isset($response['locked']) ? $response['locked'] : false,
            'bot'               => isset($response['bot']) ? $response['bot'] : false,
            'discoverable'      => isset($response['discoverable']) ? $response['discoverable'] : false,
            'indexable'         => isset($response['indexable']) ? $response['indexable'] : false,
            'fields'            => isset($response['fields']) ? $response['fields'] : [], // Profil alanları
            'source'            => isset($response['source']) ? $response['source'] : [] // Kaynak bilgileri
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|false|WP_Error
     */
    public function find_or_create_user($profile) {
        // Mastodon ID'ye göre kullanıcı ara (meta_key)
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_mastodon_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // acct (username@instance) formatına göre ara
        if (!empty($profile['acct'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'mastodon_acct',
                'meta_value' => $profile['acct'],
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
            $this->core->log('mastodon_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_mastodon_id', $profile['id']);
        }
        update_user_meta($user_id, 'ai_comm_provider_mastodon_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_mastodon_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'mastodon');
        
        // Mastodon'a özel metalar
        if (!empty($profile['username'])) {
            update_user_meta($user_id, 'mastodon_username', $profile['username']);
        }
        if (!empty($profile['acct'])) {
            update_user_meta($user_id, 'mastodon_acct', $profile['acct']);
        }
        if (!empty($profile['bio'])) {
            // HTML etiketlerini temizle
            $clean_bio = wp_strip_all_tags($profile['bio']);
            update_user_meta($user_id, 'description', $clean_bio);
            update_user_meta($user_id, 'mastodon_bio', $profile['bio']); // HTML'li hali
        }
        if (!empty($profile['followers_count'])) {
            update_user_meta($user_id, 'mastodon_followers', $profile['followers_count']);
        }
        if (!empty($profile['following_count'])) {
            update_user_meta($user_id, 'mastodon_following', $profile['following_count']);
        }
        if (!empty($profile['statuses_count'])) {
            update_user_meta($user_id, 'mastodon_posts', $profile['statuses_count']);
        }
        if (!empty($profile['last_status_at'])) {
            update_user_meta($user_id, 'mastodon_last_post', $profile['last_status_at']);
        }
        if (!empty($profile['created_at'])) {
            update_user_meta($user_id, 'mastodon_created_at', $profile['created_at']);
        }
        if (!empty($profile['locked'])) {
            update_user_meta($user_id, 'mastodon_locked', $profile['locked']);
        }
        if (!empty($profile['bot'])) {
            update_user_meta($user_id, 'mastodon_bot', $profile['bot']);
        }
        if (!empty($profile['fields'])) {
            update_user_meta($user_id, 'mastodon_fields', wp_json_encode($profile['fields']));
        }
        
        // Instance bilgisini kaydet
        if (!empty($profile['instance'])) {
            update_user_meta($user_id, 'mastodon_instance', $profile['instance']);
        }
        
        // Avatar için statik versiyonu da kaydet
        if (!empty($profile['avatar_static'])) {
            update_user_meta($user_id, 'mastodon_avatar_static', $profile['avatar_static']);
        }
        
        // Görünen ismi ayarla
        $display_name = !empty($profile['display_name']) ? $profile['display_name'] : $profile['username'];
        if (!empty($display_name)) {
            update_user_meta($user_id, 'nickname', $display_name);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $display_name
            ]);
        }
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
            update_user_meta($user_id, 'mastodon_avatar', $profile['avatar']);
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
        } elseif (!empty($profile['display_name'])) {
            $base = sanitize_user($profile['display_name'], true);
        } elseif (!empty($profile['id'])) {
            $base = 'mastodon_' . substr(md5($profile['id']), 0, 8);
        } else {
            $base = 'mastodon_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'mastodon';
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
            return 'mastodon_' . md5($profile['id']) . '@social.local';
        }
        
        return 'mastodon_' . wp_generate_password(8, false) . '@social.local';
    }
    
    /**
     * Kullanıcının gönderilerini getir
     * 
     * @param string $access_token Access token
     * @param string $account_id Account ID
     * @param int $limit Limit
     * @return array|WP_Error
     */
    public function get_account_statuses($access_token, $account_id, $limit = 20) {
        $url = $this->get_statuses_url($account_id);
        
        $params = [
            'limit' => min($limit, 40)
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
        return json_decode($body, true);
    }
    
    /**
     * Instance bilgilerini getir
     * 
     * @param string $instance_url Instance URL
     * @return array|WP_Error
     */
    public function get_instance_info($instance_url = null) {
        $url = $instance_url ? rtrim($instance_url, '/') : $this->instance_url;
        $url .= '/api/v1/instance';
        
        $response = wp_remote_get($url, [
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * Kullanıcının takip ettiklerini getir
     * 
     * @param string $access_token Access token
     * @param string $account_id Account ID
     * @return array|WP_Error
     */
    public function get_following($access_token, $account_id) {
        $url = $this->instance_url . '/api/v1/accounts/' . $account_id . '/following';
        
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
     * Kullanıcının takipçilerini getir
     * 
     * @param string $access_token Access token
     * @param string $account_id Account ID
     * @return array|WP_Error
     */
    public function get_followers($access_token, $account_id) {
        $url = $this->instance_url . '/api/v1/accounts/' . $account_id . '/followers';
        
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
}