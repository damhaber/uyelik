<?php
// masal-panel/modules/ai-community-engine/core/providers/xiaohongshu.php
// Xiaohongshu (小红书) Provider - TÜM HATALAR DÜZELTİLDİ

if (!defined('ABSPATH')) {
    exit;
}

// Base class'ı kontrol et
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Xiaohongshu extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Xiaohongshu (小红书)';
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
    }
    
    public function get_type() {
        return 'oauth';
    }
    
    public function get_name() {
        return $this->label;
    }
    
    public function get_slug() {
        return 'xiaohongshu';
    }
    
    /**
     * Authorization URL - PUBLIC ve state parametreli
     */
    public function get_auth_url($state = '') {
        $url = 'https://oauth.xiaohongshu.com/authorize';
        $params = [
            'client_id' => $this->config['client_id'] ?? '',
            'response_type' => 'code',
            'redirect_uri' => $this->get_redirect_uri(),
            'scope' => $this->get_scope(),
            'state' => $state
        ];
        return $url . '?' . http_build_query($params);
    }
    
    /**
     * Token URL - PUBLIC
     */
    public function get_token_url() {
        return 'https://oauth.xiaohongshu.com/token';
    }
    
    /**
     * Token parametreleri - PUBLIC
     */
    public function get_token_params($code) {
        return [
            'client_id' => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
            'code' => $code,
            'redirect_uri' => $this->get_redirect_uri(),
            'grant_type' => 'authorization_code'
        ];
    }
    
    /**
     * Token istek metodu - PUBLIC
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Token headers - PUBLIC
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Userinfo URL - PUBLIC
     */
    public function get_userinfo_url() {
        return 'https://oauth.xiaohongshu.com/userinfo';
    }
    
    /**
     * Userinfo istek metodu - PUBLIC
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Userinfo headers - PUBLIC
     */
    public function get_userinfo_headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Userinfo parametreleri - PUBLIC
     */
    public function get_userinfo_params($token) {
        return [];
    }
    
    /**
     * Scope - PUBLIC
     */
    public function get_scope() {
        return 'user_info';
    }
    
    /**
     * Token parse - PUBLIC
     */
    public function parse_access_token($response) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? '';
    }
    
    /**
     * Refresh token parse - PUBLIC
     */
    public function parse_refresh_token($response) {
        $data = json_decode($response, true);
        return $data['refresh_token'] ?? '';
    }
    
    /**
     * Expires in parse - PUBLIC
     */
    public function parse_expires_in($response) {
        $data = json_decode($response, true);
        return $data['expires_in'] ?? 3600;
    }
    
    /**
     * Handle metodu - PARAMETRESİZ
     * OAuth2 akışını yönetir
     */
    public function handle() {
        if (!$this->is_active()) {
            $this->core->log('xiaohongshu_inactive', ['provider' => $this->id]);
            wp_safe_redirect(home_url('/' . $this->core->get_endpoint_slug() . '/'));
            exit;
        }

        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        if (isset($params['code'])) {
            // Callback var - request parametresiyle çağır
            $this->handle_callback($params);
        } else {
            // Auth başlat
            $this->start_auth();
        }
    }
    
    /**
     * Xiaohongshu özel callback handler - PARAMETRELİ ($request)
     */
    protected function handle_callback($request) {
        $code  = isset($request['code']) ? sanitize_text_field(wp_unslash($request['code'])) : '';
        $state = isset($request['state']) ? sanitize_text_field(wp_unslash($request['state'])) : '';

        if (!wp_verify_nonce($state, 'ai_community_oauth_' . $this->id)) {
            $this->core->log('xiaohongshu_state_invalid', ['provider' => $this->id]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        $token = $this->exchange_code_for_token($code);

        if (is_wp_error($token)) {
            $this->core->log('xiaohongshu_token_error', [
                'provider' => $this->id,
                'error'    => $token->get_error_message(),
            ]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        $user_profile = $this->fetch_userinfo($token);

        if (is_wp_error($user_profile)) {
            $this->core->log('xiaohongshu_userinfo_error', [
                'provider' => $this->id,
                'error'    => $user_profile->get_error_message(),
            ]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        $wp_user_id = $this->find_or_create_user($user_profile);

        if (is_wp_error($wp_user_id) || !$wp_user_id) {
            $this->core->log('xiaohongshu_wp_user_error', [
                'provider' => $this->id,
                'error'    => is_wp_error($wp_user_id) ? $wp_user_id->get_error_message() : 'empty_user_id',
            ]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        // Provider bağlantı kaydı
        $provider_meta_key = 'ai_comm_provider_' . $this->id . '_id';
        $provider_email_key = 'ai_comm_provider_' . $this->id . '_email';
        $provider_joined_key = 'ai_comm_provider_' . $this->id . '_joined_at';

        if (!empty($user_profile['id'])) {
            if (!get_user_meta($wp_user_id, $provider_meta_key, true)) {
                update_user_meta($wp_user_id, $provider_meta_key, $user_profile['id']);
                update_user_meta($wp_user_id, $provider_email_key, $user_profile['email'] ?? '');
                update_user_meta($wp_user_id, $provider_joined_key, current_time('mysql'));
            }
        }

        update_user_meta($wp_user_id, 'ai_comm_last_login_at', current_time('mysql'));
        update_user_meta($wp_user_id, 'ai_comm_last_login_device', $_SERVER['HTTP_USER_AGENT'] ?? '');

        // WordPress oturumunu başlat
        wp_set_auth_cookie($wp_user_id, true);
        wp_set_current_user($wp_user_id);

        if ($this->core instanceof AI_Community_Core) {
            $this->core->on_user_authenticated($this->id, $user_profile, $wp_user_id);
        }

        $this->core->log('xiaohongshu_login_success', [
            'provider' => $this->id,
            'user_id'  => $wp_user_id,
        ]);

        $profile_slug = $this->core instanceof AI_Community_Core
            ? $this->core->get_profile_slug()
            : 'profil-merkezim';

        wp_safe_redirect(home_url('/' . $profile_slug . '/'));
        exit;
    }
    
    /**
     * Auth başlat
     */
    protected function start_auth() {
        $state = wp_create_nonce('ai_community_oauth_' . $this->id);
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('xiaohongshu_auth_start', [
            'provider' => $this->id,
            'auth_url' => $auth_url
        ]);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Kullanıcı bilgilerini haritalandır - PUBLIC
     */
    public function map_userinfo_to_profile($body) {
        $data = json_decode($body, true);
        
        if (!is_array($data) || empty($data['user_id'])) {
            return new WP_Error('xiaohongshu_userinfo_invalid', 'Geçersiz Xiaohongshu kullanıcı bilgisi');
        }
        
        return [
            'id'      => isset($data['user_id']) ? (string)$data['user_id'] : '',
            'email'   => isset($data['email']) ? $data['email'] : '',
            'name'    => isset($data['nickname']) ? $data['nickname'] : 'Xiaohongshu User',
            'avatar'  => isset($data['avatar']) ? $data['avatar'] : '',
            'gender'  => isset($data['gender']) ? $this->normalize_gender($data['gender']) : 'other',
            'bio'     => isset($data['description']) ? $data['description'] : '',
            'followers' => isset($data['followers']) ? $data['followers'] : 0,
            'following' => isset($data['following']) ? $data['following'] : 0,
            'notes'     => isset($data['notes_count']) ? $data['notes_count'] : 0,
        ];
    }
    
    /**
     * Kullanıcı bul veya oluştur - PUBLIC
     */
    public function find_or_create_user($profile) {
        // Önce mevcut kullanıcıyı provider ID'ye göre bul
        $provider_meta_key = 'ai_comm_provider_' . $this->id . '_id';
        $users = get_users([
            'meta_key' => $provider_meta_key,
            'meta_value' => $profile['id'],
            'number' => 1
        ]);
        
        if (!empty($users)) {
            return $users[0]->ID;
        }
        
        // Email varsa onu dene
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['name']);
        $email = $profile['email'] ?: $username . '@xiaohongshu.user';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Avatar'ı kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_comm_avatar', $profile['avatar']);
        }
        
        return $user_id;
    }
    
    /**
     * Unique username oluştur
     */
    protected function generate_unique_username($base) {
        $username = sanitize_user($base, true);
        if (empty($username)) {
            $username = 'xiaohongshu_user';
        }
        
        $original = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $original . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Cinsiyet normalize et
     */
    private function normalize_gender($gender) {
        if ($gender === 1 || $gender === '男' || $gender === 'male') return 'male';
        if ($gender === 2 || $gender === '女' || $gender === 'female') return 'female';
        return 'other';
    }
}