<?php
// masal-panel/modules/ai-community-engine/core/providers/linkedin.php
// LinkedIn OAuth2 Provider - wechat.php ve instagram.php mantığıyla

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_LinkedIn extends AI_Community_OAuth2_Provider {
    
    protected $label = 'LinkedIn';
    protected $name = 'LinkedIn';
    public $slug = 'linkedin';
    
    // LinkedIn API versiyonu
    private $api_version = 'v2';
    
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
        if (isset($config['api_version'])) {
            $this->api_version = $config['api_version'];
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
            
            $this->core->log('linkedin_oauth_error', [
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
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'state' => $state,
            'scope' => $this->get_scope()
        ];
        
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return 'https://www.linkedin.com/oauth/v2/accessToken';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return 'https://api.linkedin.com/' . $this->api_version . '/userinfo';
    }
    
    /**
     * Alternatif kullanıcı bilgi URL'i (email için)
     */
    public function get_email_url() {
        return 'https://api.linkedin.com/' . $this->api_version . '/emailAddress?q=members&projection=(elements*(handle~))';
    }
    
    /**
     * Token parametrelerini al
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
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametreleri
     */
    public function get_userinfo_params($access_token) {
        return []; // LinkedIn'de params URL'de değil, header'da token
    }
    
    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'openid profile email';
    }
    
    /**
     * Kullanıcı bilgilerini al (override - email ayrı endpoint)
     */
    public function get_userinfo($access_token) {
        // Önce userinfo endpoint'inden temel bilgileri al
        $url = $this->get_userinfo_url();
        
        $response = wp_remote_get($url, [
            'headers' => $this->get_userinfo_headers($access_token),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('LinkedIn userinfo hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            error_log('LinkedIn userinfo hatası: ' . print_r($data, true));
            return false;
        }
        
        // Email bilgisini ayrı endpoint'ten al
        $email_data = $this->get_email_address($access_token);
        if ($email_data && isset($email_data['email'])) {
            $data['email'] = $email_data['email'];
        }
        
        return $data;
    }
    
    /**
     * Email adresini al (LinkedIn özel)
     */
    private function get_email_address($access_token) {
        $url = $this->get_email_url();
        
        $response = wp_remote_get($url, [
            'headers' => $this->get_userinfo_headers($access_token),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            return false;
        }
        
        // LinkedIn'in complex email yapısını parse et
        if (isset($data['elements'][0]['handle~']['emailAddress'])) {
            return ['email' => $data['elements'][0]['handle~']['emailAddress']];
        }
        
        return false;
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     */
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo) || empty($userinfo['sub'])) {
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'first_name' => '',
                'last_name' => '',
                'avatar' => '',
                'headline' => ''
            ];
        }
        
        return [
            'id'         => $userinfo['sub'],
            'email'      => isset($userinfo['email']) ? $userinfo['email'] : '',
            'name'       => isset($userinfo['name']) ? $userinfo['name'] : '',
            'first_name' => isset($userinfo['given_name']) ? $userinfo['given_name'] : '',
            'last_name'  => isset($userinfo['family_name']) ? $userinfo['family_name'] : '',
            'avatar'     => isset($userinfo['picture']) ? $userinfo['picture'] : '',
            'locale'     => isset($userinfo['locale']) ? $userinfo['locale'] : '',
            'headline'   => isset($userinfo['headline']) ? $userinfo['headline'] : ''
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        // LinkedIn ID'ye göre ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_linkedin_id',
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
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = !empty($profile['email']) ? $profile['email'] : 'linkedin_' . md5($profile['id'] . time()) . '@social.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('linkedin_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_linkedin_id', $profile['id']);
        }
        
        update_user_meta($user_id, 'ai_comm_provider_linkedin_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'linkedin');
        
        // İsim bilgilerini kaydet
        if (!empty($profile['first_name'])) {
            update_user_meta($user_id, 'first_name', $profile['first_name']);
        }
        if (!empty($profile['last_name'])) {
            update_user_meta($user_id, 'last_name', $profile['last_name']);
        }
        
        // Display name oluştur
        $display_name = '';
        if (!empty($profile['first_name']) && !empty($profile['last_name'])) {
            $display_name = $profile['first_name'] . ' ' . $profile['last_name'];
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
            update_user_meta($user_id, 'linkedin_avatar', $profile['avatar']);
        }
        
        // Headline kaydet (LinkedIn profili)
        if (!empty($profile['headline'])) {
            update_user_meta($user_id, 'linkedin_headline', $profile['headline']);
        }
        
        return $user_id;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        // Önce email'den dene
        if (!empty($profile['email'])) {
            $email_parts = explode('@', $profile['email']);
            $base = sanitize_user($email_parts[0], true);
        }
        
        // Email'den olmadıysa isimden dene
        if (empty($base)) {
            if (!empty($profile['first_name']) && !empty($profile['last_name'])) {
                $base = sanitize_user($profile['first_name'] . '_' . $profile['last_name'], true);
            } elseif (!empty($profile['name'])) {
                $base = sanitize_user($profile['name'], true);
            } elseif (!empty($profile['id'])) {
                $base = 'li_' . substr(md5($profile['id']), 0, 8);
            } else {
                $base = 'linkedin_user';
            }
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'linkedin';
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
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
        ];
        
        $response = wp_remote_post($url, [
            'headers' => $this->get_token_headers(),
            'body' => $params,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('LinkedIn token refresh hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || isset($data['error'])) {
            error_log('LinkedIn token refresh hatası: ' . print_r($data, true));
            return false;
        }
        
        return [
            'access_token' => $data['access_token'] ?? '',
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 0
        ];
    }
}