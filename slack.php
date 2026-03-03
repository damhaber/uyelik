<?php
/**
 * AI Community Provider: Slack
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Slack extends AI_Community_OAuth2_Provider {
    
    protected $config = [];
    protected $version = 'OAuth2';
    public $slug = 'slack';
    protected $scope = 'identity.basic identity.email identity.avatar';
    protected $auth_url = 'https://slack.com/oauth/v2/authorize';
    protected $token_url = 'https://slack.com/api/oauth.v2.access';
    protected $userinfo_url = 'https://slack.com/api/users.identity';
    
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
        
        if (isset($config['bot_token'])) {
            $this->bot_token = $config['bot_token'];
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
            'user_scope' => $this->get_scope(),
            'redirect_uri' => $this->redirect_uri
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
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'redirect_uri' => $this->redirect_uri
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
        if (is_array($response) && isset($response['authed_user']['access_token'])) {
            return $response['authed_user']['access_token'];
        }
        return null;
    }
    
    public function parse_refresh_token($response) {
        // Slack refresh token sağlamıyor
        return null;
    }
    
    public function parse_expires_in($response) {
        // Slack tokenları expire olmuyor
        return 86400 * 365; // 1 yıl
    }
    
    public function get_userinfo_method() {
        return 'GET';
    }
    
    public function get_userinfo_headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token
        ];
    }
    
    public function get_userinfo_params($token) {
        return [];
    }
    
    public function get_userinfo_params_with_token($token) {
        return [];
    }
    
    public function map_userinfo_to_profile($userinfo) {
        // Slack response formatı
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => '',
            'username' => '',
            'display_name' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'avatar' => '',
            'profile_url' => '',
            'locale' => '',
            'timezone' => '',
            'team_id' => '',
            'team_name' => '',
            'team_domain' => '',
            'is_admin' => false,
            'is_owner' => false,
            'is_primary_owner' => false,
            'is_restricted' => false,
            'is_ultra_restricted' => false
        ];
        
        $user = isset($userinfo['user']) ? $userinfo['user'] : [];
        $team = isset($userinfo['team']) ? $userinfo['team'] : [];
        
        if (isset($user['id'])) {
            $profile['user_id'] = (string) $user['id'];
        }
        
        if (isset($user['name'])) {
            $profile['username'] = $user['name'];
        }
        
        // Display name oluştur
        if (isset($user['display_name']) && !empty($user['display_name'])) {
            $profile['display_name'] = $user['display_name'];
        } elseif (isset($user['real_name']) && !empty($user['real_name'])) {
            $profile['display_name'] = $user['real_name'];
        } elseif (isset($user['name'])) {
            $profile['display_name'] = $user['name'];
        }
        
        if (isset($user['first_name'])) {
            $profile['first_name'] = $user['first_name'];
        }
        
        if (isset($user['last_name'])) {
            $profile['last_name'] = $user['last_name'];
        }
        
        if (isset($user['email'])) {
            $profile['email'] = $user['email'];
        }
        
        // Avatar URL'leri
        if (isset($user['image_512'])) {
            $profile['avatar'] = $user['image_512'];
        } elseif (isset($user['image_192'])) {
            $profile['avatar'] = $user['image_192'];
        }
        
        if (isset($user['locale'])) {
            $profile['locale'] = $user['locale'];
        }
        
        if (isset($user['tz'])) {
            $profile['timezone'] = $user['tz'];
        }
        
        if (isset($team['id'])) {
            $profile['team_id'] = (string) $team['id'];
        }
        
        if (isset($team['name'])) {
            $profile['team_name'] = $team['name'];
        }
        
        if (isset($team['domain'])) {
            $profile['team_domain'] = $team['domain'];
        }
        
        // Kullanıcı rolleri
        if (isset($user['is_admin'])) {
            $profile['is_admin'] = (bool) $user['is_admin'];
        }
        
        if (isset($user['is_owner'])) {
            $profile['is_owner'] = (bool) $user['is_owner'];
        }
        
        if (isset($user['is_primary_owner'])) {
            $profile['is_primary_owner'] = (bool) $user['is_primary_owner'];
        }
        
        if (isset($user['is_restricted'])) {
            $profile['is_restricted'] = (bool) $user['is_restricted'];
        }
        
        if (isset($user['is_ultra_restricted'])) {
            $profile['is_ultra_restricted'] = (bool) $user['is_ultra_restricted'];
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
            
            // Slack'te access_token farklı yerde
            $token = $this->parse_access_token($token_response);
            
            if (!$token) {
                return new WP_Error('no_token', 'Access token not found');
            }
            
            // Userinfo al
            $userinfo = $this->get_userinfo($token);
            
            if (is_wp_error($userinfo)) {
                return $userinfo;
            }
            
            // Slack API başarısız yanıt kontrolü
            if (isset($userinfo['ok']) && $userinfo['ok'] === false) {
                return new WP_Error(
                    'api_error', 
                    isset($userinfo['error']) ? $userinfo['error'] : 'Unknown API error'
                );
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
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@slack.local';
        
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
        
        // Slack spesifik bilgileri kaydet
        if (!empty($profile['team_id'])) {
            update_user_meta($user_id, 'ai_community_slack_team_id', $profile['team_id']);
        }
        
        if (!empty($profile['team_name'])) {
            update_user_meta($user_id, 'ai_community_slack_team_name', $profile['team_name']);
        }
        
        if (!empty($profile['timezone'])) {
            update_user_meta($user_id, 'ai_community_slack_timezone', $profile['timezone']);
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
     * Slack workspace bilgilerini getir
     */
    public function get_team_info($access_token) {
        $response = wp_remote_get('https://slack.com/api/team.info', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['ok']) || $body['ok'] !== true) {
            return new WP_Error(
                'api_error', 
                isset($body['error']) ? $body['error'] : 'Failed to get team info'
            );
        }
        
        return $body['team'] ?? [];
    }
    
    /**
     * Slack kanallarına mesaj gönder
     */
    public function post_message($access_token, $channel, $text, $attachments = []) {
        $response = wp_remote_post('https://slack.com/api/chat.postMessage', [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'channel' => $channel,
                'text' => $text,
                'attachments' => $attachments
            ])
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['ok']) || $body['ok'] !== true) {
            return new WP_Error(
                'api_error', 
                isset($body['error']) ? $body['error'] : 'Failed to post message'
            );
        }
        
        return $body;
    }
    
    /**
     * Slack kullanıcı listesini getir
     */
    public function get_users_list($access_token, $cursor = '') {
        $url = 'https://slack.com/api/users.list?limit=100';
        if (!empty($cursor)) {
            $url .= '&cursor=' . urlencode($cursor);
        }
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['ok']) || $body['ok'] !== true) {
            return new WP_Error(
                'api_error', 
                isset($body['error']) ? $body['error'] : 'Failed to get users list'
            );
        }
        
        return [
            'users' => $body['members'] ?? [],
            'next_cursor' => $body['response_metadata']['next_cursor'] ?? ''
        ];
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Slack';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}