<?php
/**
 * AI Community Provider: Microsoft Teams
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Microsoft_Teams extends AI_Community_OAuth2_Provider {
    
    protected $config = [];
    protected $version = 'OAuth2';
    public $slug = 'microsoft-teams';
    protected $scope = 'User.Read openid profile email';
    protected $auth_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';
    protected $token_url = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';
    protected $userinfo_url = 'https://graph.microsoft.com/v1.0/me';
    protected $tenant = 'common';
    
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
        
        // Microsoft tenant ID (varsayılan: common)
        if (isset($config['tenant'])) {
            $this->tenant = $config['tenant'];
        }
        
        // Tenant'a göre URL'leri güncelle
        if ($this->tenant !== 'common') {
            $this->auth_url = 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/authorize';
            $this->token_url = 'https://login.microsoftonline.com/' . $this->tenant . '/oauth2/v2.0/token';
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
            'response_mode' => 'query'
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        } else {
            $params['state'] = wp_create_nonce('microsoft_oauth_state');
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
            'redirect_uri' => $this->redirect_uri,
            'grant_type' => 'authorization_code'
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
        // Microsoft Graph API response formatı
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => isset($userinfo['id']) ? (string) $userinfo['id'] : '',
            'username' => '',
            'display_name' => isset($userinfo['displayName']) ? $userinfo['displayName'] : '',
            'first_name' => isset($userinfo['givenName']) ? $userinfo['givenName'] : '',
            'last_name' => isset($userinfo['surname']) ? $userinfo['surname'] : '',
            'email' => '',
            'avatar' => isset($userinfo['avatar']) ? $userinfo['avatar'] : '',
            'profile_url' => '',
            'locale' => isset($userinfo['preferredLanguage']) ? $userinfo['preferredLanguage'] : '',
            'mobile_phone' => isset($userinfo['mobilePhone']) ? $userinfo['mobilePhone'] : '',
            'business_phone' => '',
            'office_location' => isset($userinfo['officeLocation']) ? $userinfo['officeLocation'] : '',
            'job_title' => isset($userinfo['jobTitle']) ? $userinfo['jobTitle'] : '',
            'department' => isset($userinfo['department']) ? $userinfo['department'] : '',
            'company_name' => isset($userinfo['companyName']) ? $userinfo['companyName'] : '',
            'tenant_id' => isset($userinfo['tenantId']) ? $userinfo['tenantId'] : ''
        ];
        
        // Username oluştur
        if (isset($userinfo['userPrincipalName'])) {
            $profile['username'] = $userinfo['userPrincipalName'];
        } elseif (isset($userinfo['mail'])) {
            $profile['username'] = $userinfo['mail'];
        }
        
        // Email oluştur
        if (isset($userinfo['mail'])) {
            $profile['email'] = $userinfo['mail'];
        } elseif (isset($userinfo['userPrincipalName'])) {
            $profile['email'] = $userinfo['userPrincipalName'];
        }
        
        // Business phone
        if (isset($userinfo['businessPhones']) && is_array($userinfo['businessPhones']) && !empty($userinfo['businessPhones'])) {
            $profile['business_phone'] = $userinfo['businessPhones'][0];
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
        
        // State kontrolü
        if (!isset($request['state']) || !wp_verify_nonce($request['state'], 'microsoft_oauth_state')) {
            return new WP_Error('invalid_state', 'Invalid state parameter');
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
            
            // Profil fotoğrafını dene
            $avatar = $this->get_user_photo($token);
            if (!is_wp_error($avatar) && !empty($avatar)) {
                $userinfo['avatar'] = $avatar;
            }
            
            // Profil oluştur
            $profile = $this->map_userinfo_to_profile($userinfo);
            
            // Token bilgilerini de sakla
            $profile['access_token'] = $token;
            $profile['refresh_token'] = $this->parse_refresh_token($token_response);
            $profile['expires_in'] = $this->parse_expires_in($token_response);
            
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
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@microsoft.local';
        
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
        
        // Microsoft Teams spesifik bilgileri kaydet
        if (!empty($profile['tenant_id'])) {
            update_user_meta($user_id, 'ai_community_microsoft_tenant_id', $profile['tenant_id']);
        }
        
        if (!empty($profile['job_title'])) {
            update_user_meta($user_id, 'ai_community_microsoft_job_title', $profile['job_title']);
        }
        
        if (!empty($profile['department'])) {
            update_user_meta($user_id, 'ai_community_microsoft_department', $profile['department']);
        }
        
        if (!empty($profile['company_name'])) {
            update_user_meta($user_id, 'ai_community_microsoft_company', $profile['company_name']);
        }
        
        // Token bilgilerini kaydet
        if (!empty($profile['access_token'])) {
            update_user_meta($user_id, 'ai_community_microsoft_access_token', $profile['access_token']);
        }
        
        if (!empty($profile['refresh_token'])) {
            update_user_meta($user_id, 'ai_community_microsoft_refresh_token', $profile['refresh_token']);
        }
        
        if (!empty($profile['expires_in'])) {
            update_user_meta($user_id, 'ai_community_microsoft_token_expires', time() + $profile['expires_in']);
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
     * Kullanıcı fotoğrafını al
     */
    public function get_user_photo($token) {
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me/photo/$value', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ],
            'timeout' => 10
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status = wp_remote_retrieve_response_code($response);
        
        if ($status !== 200) {
            return new WP_Error('no_photo', 'No profile photo available');
        }
        
        $photo_data = wp_remote_retrieve_body($response);
        
        // Fotoğrafı base64'e çevir
        if (!empty($photo_data)) {
            return 'data:image/jpeg;base64,' . base64_encode($photo_data);
        }
        
        return '';
    }
    
    /**
     * Token yenile
     */
    public function refresh_access_token($refresh_token) {
        $response = wp_remote_post($this->token_url, [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
                'redirect_uri' => $this->redirect_uri
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
     * Kullanıcının takımlarını getir
     */
    public function get_joined_teams($token) {
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/me/joinedTeams', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error(
                'api_error', 
                isset($body['error']['message']) ? $body['error']['message'] : 'Failed to get teams'
            );
        }
        
        return $body['value'] ?? [];
    }
    
    /**
     * Kullanıcının kanallarını getir
     */
    public function get_team_channels($token, $team_id) {
        $response = wp_remote_get('https://graph.microsoft.com/v1.0/teams/' . $team_id . '/channels', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token
            ]
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error(
                'api_error', 
                isset($body['error']['message']) ? $body['error']['message'] : 'Failed to get channels'
            );
        }
        
        return $body['value'] ?? [];
    }
    
    /**
     * Kanala mesaj gönder
     */
    public function send_channel_message($token, $team_id, $channel_id, $message) {
        $response = wp_remote_post(
            'https://graph.microsoft.com/v1.0/teams/' . $team_id . '/channels/' . $channel_id . '/messages',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'body' => [
                        'contentType' => 'html',
                        'content' => $message
                    ]
                ])
            ]
        );
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error(
                'api_error', 
                isset($body['error']['message']) ? $body['error']['message'] : 'Failed to send message'
            );
        }
        
        return $body;
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Microsoft Teams';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}