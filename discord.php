<?php
/**
 * AI Community Provider: Discord
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Discord extends AI_Community_OAuth2_Provider {
    
    protected $config = [];
    protected $version = 'OAuth2';
    public $slug = 'discord';
    protected $scope = 'identify email';
    protected $auth_url = 'https://discord.com/api/oauth2/authorize';
    protected $token_url = 'https://discord.com/api/oauth2/token';
    protected $userinfo_url = 'https://discord.com/api/users/@me';
    
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
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->get_scope(),
            'prompt' => 'consent'
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
            'grant_type' => 'authorization_code',
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
        return 604800; // Discord tokenları 7 gün geçerli
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
        // Discord response formatı
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => isset($userinfo['id']) ? (string) $userinfo['id'] : '',
            'username' => isset($userinfo['username']) ? $userinfo['username'] : '',
            'display_name' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => isset($userinfo['email']) ? $userinfo['email'] : '',
            'avatar' => '',
            'profile_url' => '',
            'locale' => isset($userinfo['locale']) ? $userinfo['locale'] : '',
            'verified' => isset($userinfo['verified']) ? (bool) $userinfo['verified'] : false,
            'mfa_enabled' => isset($userinfo['mfa_enabled']) ? (bool) $userinfo['mfa_enabled'] : false,
            'flags' => isset($userinfo['flags']) ? (int) $userinfo['flags'] : 0,
            'premium_type' => isset($userinfo['premium_type']) ? (int) $userinfo['premium_type'] : 0,
            'public_flags' => isset($userinfo['public_flags']) ? (int) $userinfo['public_flags'] : 0,
            'discriminator' => isset($userinfo['discriminator']) ? $userinfo['discriminator'] : '0'
        ];
        
        // Display name oluştur
        if (isset($userinfo['global_name']) && !empty($userinfo['global_name'])) {
            $profile['display_name'] = $userinfo['global_name'];
            $profile['first_name'] = $userinfo['global_name'];
        } elseif (isset($userinfo['username'])) {
            $profile['display_name'] = $userinfo['username'];
            $profile['first_name'] = $userinfo['username'];
        }
        
        // Avatar URL oluştur
        if (!empty($profile['user_id']) && isset($userinfo['avatar'])) {
            $format = strpos($userinfo['avatar'], 'a_') === 0 ? 'gif' : 'png';
            $profile['avatar'] = 'https://cdn.discordapp.com/avatars/' . $profile['user_id'] . '/' . $userinfo['avatar'] . '.' . $format;
        }
        
        // Profile URL oluştur
        if (!empty($profile['user_id'])) {
            $profile['profile_url'] = 'https://discord.com/users/' . $profile['user_id'];
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
            
            // Refresh token'ı da sakla
            if (isset($token_response['refresh_token'])) {
                $profile['refresh_token'] = $token_response['refresh_token'];
            }
            
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
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@discord.local';
        
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
        
        // Discord spesifik bilgileri kaydet
        if (!empty($profile['discriminator'])) {
            update_user_meta($user_id, 'ai_community_discord_discriminator', $profile['discriminator']);
        }
        
        if (!empty($profile['locale'])) {
            update_user_meta($user_id, 'ai_community_discord_locale', $profile['locale']);
        }
        
        if (!empty($profile['refresh_token'])) {
            update_user_meta($user_id, 'ai_community_discord_refresh_token', $profile['refresh_token']);
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
     * Discord token yenileme
     */
    public function refresh_access_token($refresh_token) {
        $response = wp_remote_post($this->token_url, [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error(
                'refresh_error', 
                isset($body['error_description']) ? $body['error_description'] : $body['error']
            );
        }
        
        return $body;
    }
    
    /**
     * Discord sunucularına katılma
     */
    public function join_guild($access_token, $guild_id, $user_id) {
        if (empty($this->config['bot_token'])) {
            return new WP_Error('no_bot_token', 'Discord bot token required');
        }
        
        $guild_url = 'https://discord.com/api/guilds/' . $guild_id . '/members/' . $user_id;
        
        $response = wp_remote_request($guild_url, [
            'method' => 'PUT',
            'headers' => [
                'Authorization' => 'Bot ' . $this->config['bot_token'],
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'access_token' => $access_token
            ])
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 201 && $code !== 204) {
            return new WP_Error(
                'guild_error', 
                isset($body['message']) ? $body['message'] : 'Failed to join guild'
            );
        }
        
        return true;
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Discord';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}