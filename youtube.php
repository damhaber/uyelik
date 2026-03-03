<?php
// masal-panel/modules/ai-community-engine/core/providers/youtube.php
// YouTube OAuth2 Provider - Google API üzerinden

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_YouTube extends AI_Community_OAuth2_Provider {
    
    protected $label = 'YouTube';
    protected $name = 'YouTube';
    public $slug = 'youtube';
    
    // Google API yapılandırması
    private $api_key = '';
    private $application_name = '';
    
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
        if (isset($config['api_key'])) {
            $this->api_key = $config['api_key'];
        }
        if (isset($config['application_name'])) {
            $this->application_name = $config['application_name'];
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
            
            $this->core->log('youtube_oauth_error', [
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
     * Yetkilendirme URL'ini al (Google OAuth2)
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => $this->get_scope(),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true'
        ];
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return 'https://oauth2.googleapis.com/token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al (Google People API)
     */
    public function get_userinfo_url() {
        return 'https://people.googleapis.com/v1/people/me';
    }
    
    /**
     * YouTube kanal bilgisi URL'i
     */
    public function get_channel_url() {
        return 'https://www.googleapis.com/youtube/v3/channels';
    }
    
    /**
     * Token parametrelerini al
     */
    public function get_token_params($code) {
        return [
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->get_redirect_uri(),
            'grant_type' => 'authorization_code'
        ];
    }
    
    /**
     * Token istek metodu
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Token headers
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
        ];
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
            'personFields' => 'names,emailAddresses,photos,metadata'
        ];
    }
    
    /**
     * YouTube kanal bilgisi parametreleri
     */
    public function get_channel_params($access_token) {
        return [
            'part' => 'snippet,contentDetails,statistics',
            'mine' => 'true'
        ];
    }
    
    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email';
    }
    
    /**
     * Kullanıcı bilgilerini al (override - YouTube kanal bilgisi ekle)
     */
    public function get_userinfo($access_token) {
        // Google People API'den temel bilgileri al
        $url = $this->get_userinfo_url();
        $params = $this->get_userinfo_params($access_token);
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'headers' => $this->get_userinfo_headers($access_token),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('YouTube/People API hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            error_log('YouTube/People API hatası: ' . print_r($data, true));
            return false;
        }
        
        // YouTube kanal bilgilerini al
        $channel_data = $this->get_channel_info($access_token);
        if ($channel_data) {
            $data['youtube'] = $channel_data;
        }
        
        return $data;
    }
    
    /**
     * YouTube kanal bilgilerini al
     */
    private function get_channel_info($access_token) {
        $url = $this->get_channel_url();
        $params = $this->get_channel_params($access_token);
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'headers' => $this->get_userinfo_headers($access_token),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error']) || empty($data['items'])) {
            return false;
        }
        
        return $data['items'][0];
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     */
    public function map_userinfo_to_profile($userinfo) {
        $profile = [
            'id' => '',
            'email' => '',
            'name' => '',
            'first_name' => '',
            'last_name' => '',
            'avatar' => '',
            'youtube_channel_id' => '',
            'youtube_channel_title' => '',
            'youtube_subscribers' => 0,
            'youtube_videos' => 0
        ];
        
        // Google People API'den ID
        if (isset($userinfo['resourceName'])) {
            $profile['id'] = str_replace('people/', '', $userinfo['resourceName']);
        }
        
        // Email
        if (isset($userinfo['emailAddresses'][0]['value'])) {
            $profile['email'] = $userinfo['emailAddresses'][0]['value'];
        }
        
        // İsim
        if (isset($userinfo['names'][0])) {
            $name_data = $userinfo['names'][0];
            $profile['name'] = $name_data['displayName'] ?? '';
            $profile['first_name'] = $name_data['givenName'] ?? '';
            $profile['last_name'] = $name_data['familyName'] ?? '';
        }
        
        // Avatar
        if (isset($userinfo['photos'][0]['url'])) {
            $profile['avatar'] = $userinfo['photos'][0]['url'];
        }
        
        // YouTube kanal bilgileri
        if (isset($userinfo['youtube'])) {
            $channel = $userinfo['youtube'];
            $profile['youtube_channel_id'] = $channel['id'] ?? '';
            $profile['youtube_channel_title'] = $channel['snippet']['title'] ?? '';
            $profile['youtube_subscribers'] = $channel['statistics']['subscriberCount'] ?? 0;
            $profile['youtube_videos'] = $channel['statistics']['videoCount'] ?? 0;
            $profile['youtube_views'] = $channel['statistics']['viewCount'] ?? 0;
            
            // Kanal avatarı varsa onu kullan
            if (empty($profile['avatar']) && isset($channel['snippet']['thumbnails']['default']['url'])) {
                $profile['avatar'] = $channel['snippet']['thumbnails']['default']['url'];
            }
        }
        
        return $profile;
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        // Google/YouTube ID'ye göre ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_youtube_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // YouTube kanal ID'sine göre ara
        if (!empty($profile['youtube_channel_id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'youtube_channel_id',
                'meta_value' => $profile['youtube_channel_id'],
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
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = !empty($profile['email']) ? $profile['email'] : 'youtube_' . md5($profile['id'] . time()) . '@social.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('youtube_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_youtube_id', $profile['id']);
        }
        
        update_user_meta($user_id, 'ai_comm_provider_youtube_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'youtube');
        
        // YouTube kanal bilgilerini kaydet
        if (!empty($profile['youtube_channel_id'])) {
            update_user_meta($user_id, 'youtube_channel_id', $profile['youtube_channel_id']);
        }
        if (!empty($profile['youtube_channel_title'])) {
            update_user_meta($user_id, 'youtube_channel_title', $profile['youtube_channel_title']);
        }
        if (!empty($profile['youtube_subscribers'])) {
            update_user_meta($user_id, 'youtube_subscribers', $profile['youtube_subscribers']);
        }
        if (!empty($profile['youtube_videos'])) {
            update_user_meta($user_id, 'youtube_videos', $profile['youtube_videos']);
        }
        if (!empty($profile['youtube_views'])) {
            update_user_meta($user_id, 'youtube_views', $profile['youtube_views']);
        }
        
        // İsim bilgilerini kaydet
        if (!empty($profile['first_name'])) {
            update_user_meta($user_id, 'first_name', $profile['first_name']);
        }
        if (!empty($profile['last_name'])) {
            update_user_meta($user_id, 'last_name', $profile['last_name']);
        }
        
        // Display name oluştur
        $display_name = '';
        if (!empty($profile['youtube_channel_title'])) {
            $display_name = $profile['youtube_channel_title'];
        } elseif (!empty($profile['name'])) {
            $display_name = $profile['name'];
        } else {
            $display_name = $username;
        }
        
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $display_name,
            'first_name' => $profile['first_name'] ?? '',
            'last_name' => $profile['last_name'] ?? ''
        ]);
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
            update_user_meta($user_id, 'youtube_avatar', $profile['avatar']);
        }
        
        return $user_id;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        // Önce YouTube kanal adından dene
        if (!empty($profile['youtube_channel_title'])) {
            $base = sanitize_user($profile['youtube_channel_title'], true);
        }
        
        // Email'den dene
        if (empty($base) && !empty($profile['email'])) {
            $email_parts = explode('@', $profile['email']);
            $base = sanitize_user($email_parts[0], true);
        }
        
        // İsimden dene
        if (empty($base) && !empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        }
        
        // ID'den oluştur
        if (empty($base) && !empty($profile['id'])) {
            $base = 'yt_' . substr(md5($profile['id']), 0, 8);
        }
        
        if (empty($base)) {
            $base = 'youtube_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'youtube';
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
     * Refresh token kullanarak token yenile
     */
    public function refresh_access_token($refresh_token) {
        $url = $this->get_token_url();
        
        $params = [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token'
        ];
        
        $response = wp_remote_post($url, [
            'headers' => $this->get_token_headers(),
            'body' => $params,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('YouTube token refresh hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            error_log('YouTube token refresh hatası: ' . print_r($data, true));
            return false;
        }
        
        return [
            'access_token' => $data['access_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 0
        ];
    }
    
    /**
     * Abone sayısını formatla
     */
    public function format_subscriber_count($count) {
        if ($count >= 1000000) {
            return round($count / 1000000, 1) . 'M';
        } elseif ($count >= 1000) {
            return round($count / 1000, 1) . 'K';
        }
        return $count;
    }
}