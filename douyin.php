<?php
// masal-panel/modules/ai-community-engine/core/providers/douyin.php
// Douyin (抖音) Provider - DÜZELTİLMİŞ VERSİYON (WeChat/Weibo yapısına göre)

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Douyin extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Douyin (抖音)';
    protected $name = 'Douyin';
    public $slug = 'douyin';

    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Douyin için özel scope ayarı
        if (empty($this->config['scope'])) {
            $this->config['scope'] = 'user_info';
        }
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
     * Ana işleyici metodu - Parametresiz!
     */
    public function handle() {
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = isset($params['error_description']) ? $params['error_description'] : '';
            
            $this->core->log('douyin_oauth_error', [
                'error' => $error,
                'description' => $error_description
            ]);
            
            wp_redirect(home_url('/login?error=social_auth_failed'));
            exit;
        }
        
        // Callback işleme - request parametresi olarak $params gönder
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
     * Callback işleme - Base class ile uyumlu (parametreli)
     * 
     * @param array $request İstek parametreleri
     */
    protected function handle_callback($request) {
        $code = isset($request['code']) ? $request['code'] : '';
        $state = isset($request['state']) ? $request['state'] : '';
        
        if (empty($code)) {
            $this->core->log('douyin_no_code', ['request' => $request]);
            wp_redirect(home_url('/login?error=no_code'));
            exit;
        }

        // State doğrulama
        if (!wp_verify_nonce($state, 'ai_community_oauth_state')) {
            $this->core->log('douyin_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('douyin_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Open ID'yi session'a kaydet
        if (isset($token_data['access_token']) && !session_id()) {
            session_start();
            $_SESSION['douyin_token_data'] = $token_data;
            if (isset($token_data['open_id'])) {
                $_SESSION['douyin_open_id'] = $token_data['open_id'];
            }
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('douyin_userinfo_error', [
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
            $this->core->log('douyin_user_creation_error', [
                'error' => is_wp_error($user_id) ? $user_id->get_error_message() : 'Unknown error'
            ]);
            wp_redirect(home_url('/login?error=user_creation_failed'));
            exit;
        }
        
        // Provider bağlantı kaydı
        if (!empty($profile['id'])) {
            $provider_meta_key = 'ai_comm_provider_douyin_id';
            $provider_email_key = 'ai_comm_provider_douyin_email';
            $provider_joined_key = 'ai_comm_provider_douyin_joined_at';

            if (!get_user_meta($user_id, $provider_meta_key, true)) {
                update_user_meta($user_id, $provider_meta_key, $profile['id']);
                update_user_meta($user_id, $provider_email_key, $profile['email'] ?? '');
                update_user_meta($user_id, $provider_joined_key, current_time('mysql'));
            }
        }

        update_user_meta($user_id, 'ai_comm_last_login_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_comm_last_login_device', $_SERVER['HTTP_USER_AGENT'] ?? '');
        
        // WordPress oturumu başlat
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        $this->core->log('douyin_login_success', [
            'provider' => $this->id,
            'user_id'  => $user_id,
        ]);
        
        // Başarılı giriş, yönlendir
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'douyin');
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Yetkilendirme URL'ini döndürür
     * 
     * @param string $state CSRF koruması için state parametresi
     * @return string Yetkilendirme URL'i
     */
    public function get_auth_url($state = '') {
        $url = 'https://open.douyin.com/platform/oauth/connect';
        
        $params = [
            'client_key' => $this->config['client_id'] ?? '',
            'response_type' => 'code',
            'scope' => $this->get_scope(),
            'redirect_uri' => $this->get_redirect_uri(),
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        }
        
        return add_query_arg($params, $url);
    }

    /**
     * Token URL'ini döndürür
     */
    public function get_token_url() {
        return 'https://open.douyin.com/oauth/access_token/';
    }

    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return 'POST';
    }

    /**
     * Token parametrelerini al
     */
    public function get_token_params($code) {
        return [
            'client_key' => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
            'code' => $code,
            'grant_type' => 'authorization_code'
        ];
    }

    /**
     * Token header'larını al
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Token body'sini al (POST istekleri için)
     */
    public function get_token_body($params) {
        return json_encode($params);
    }

    /**
     * Access token parse et
     */
    public function parse_access_token($response) {
        // Douyin yanıt formatı: { "data": { "access_token": "...", "open_id": "..." } }
        if (isset($response['data']['access_token'])) {
            return $response['data']['access_token'];
        }
        return $response['access_token'] ?? '';
    }

    /**
     * Refresh token parse et
     */
    public function parse_refresh_token($response) {
        if (isset($response['data']['refresh_token'])) {
            return $response['data']['refresh_token'];
        }
        return $response['refresh_token'] ?? '';
    }

    /**
     * Expires in parse et
     */
    public function parse_expires_in($response) {
        if (isset($response['data']['expires_in'])) {
            return intval($response['data']['expires_in']);
        }
        return isset($response['expires_in']) ? intval($response['expires_in']) : 0;
    }

    /**
     * Kullanıcı bilgi URL'ini döndürür
     */
    public function get_userinfo_url() {
        return 'https://open.douyin.com/oauth/userinfo/';
    }

    /**
     * Kullanıcı bilgi metodunu al
     */
    public function get_userinfo_method() {
        return 'GET';
    }

    /**
     * Kullanıcı bilgi header'larını al
     */
    public function get_userinfo_headers($access_token) {
        return [
            'Accept' => 'application/json'
        ];
    }

    /**
     * Kullanıcı bilgi parametrelerini al
     */
    public function get_userinfo_params($access_token) {
        return [
            'access_token' => $access_token,
            'open_id' => '' // Session'dan alınacak
        ];
    }

    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'user_info';
    }

    /**
     * Kullanıcı bilgilerini getir (override)
     */
    public function get_userinfo($access_token) {
        // Session'dan open_id'yi al
        if (!session_id()) {
            session_start();
        }
        
        $open_id = isset($_SESSION['douyin_open_id']) ? $_SESSION['douyin_open_id'] : '';
        
        if (empty($open_id)) {
            $this->core->log('douyin_no_open_id', []);
            return new WP_Error('no_open_id', 'Douyin Open ID bulunamadı');
        }
        
        $url = $this->get_userinfo_url();
        $params = [
            'access_token' => $access_token,
            'open_id' => $open_id
        ];
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            $this->core->log('douyin_userinfo_error', [
                'error' => $response->get_error_message()
            ]);
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Open ID'yi ekle
        if (is_array($data)) {
            $data['open_id'] = $open_id;
        }
        
        return $data;
    }

    /**
     * Kullanıcı bilgilerini profile map et
     */
    public function map_userinfo_to_profile($data) {
        if (!$data) {
            $this->core->log('douyin_invalid_response', ['data' => 'empty']);
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'gender' => 'other'
            ];
        }
        
        // Douyin yanıt formatı: { "data": { "nickname": "...", "avatar": "..." } }
        if (isset($data['data'])) {
            $user_data = $data['data'];
        } else {
            $user_data = $data;
        }
        
        // Open ID'yi al
        $provider_id = $data['open_id'] ?? $user_data['open_id'] ?? '';
        
        if (empty($provider_id)) {
            $this->core->log('douyin_no_id', ['data' => $data]);
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'gender' => 'other'
            ];
        }
        
        // Douyin email vermiyor, benzersiz bir email oluştur
        $email = 'douyin_' . md5($provider_id) . '@social.local';
        
        return [
            'id'      => $provider_id,
            'email'   => $email,
            'name'    => isset($user_data['nickname']) ? sanitize_text_field($user_data['nickname']) : 'Douyin User',
            'username' => isset($user_data['nickname']) ? sanitize_user($user_data['nickname'], true) : 'douyin_user',
            'avatar'  => isset($user_data['avatar']) ? esc_url_raw($user_data['avatar']) : '',
            'gender'  => isset($user_data['gender']) ? $this->normalize_gender($user_data['gender']) : 'other',
            'country' => isset($user_data['country']) ? $user_data['country'] : '',
            'city'    => isset($user_data['city']) ? $user_data['city'] : '',
        ];
    }

    /**
     * Cinsiyet normalize et
     */
    private function normalize_gender($gender) {
        if ($gender === 1 || $gender === '男' || $gender === 'male') return 'male';
        if ($gender === 2 || $gender === '女' || $gender === 'female') return 'female';
        return 'other';
    }

    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        if (empty($profile['id'])) {
            return new WP_Error('no_id', 'Douyin ID bulunamadı');
        }
        
        // Önce bu Douyin ID ile bağlı bir kullanıcı var mı kontrol et
        $user_query = new WP_User_Query([
            'meta_key' => 'ai_comm_provider_douyin_id',
            'meta_value' => $profile['id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        $users = $user_query->get_results();
        
        if (!empty($users)) {
            return $users[0]; // Var olan kullanıcı
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = $profile['email'] ?? 'douyin_' . md5($profile['id']) . '@social.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('douyin_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        update_user_meta($user_id, 'ai_comm_provider_douyin_id', $profile['id']);
        update_user_meta($user_id, 'ai_comm_provider_douyin_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_douyin_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'douyin');
        
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
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        if (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } elseif (!empty($profile['username'])) {
            $base = sanitize_user($profile['username'], true);
        } else {
            $base = 'douyin_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'douyin';
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
     * Avatar kaydet
     */
    public function save_avatar($user_id, $avatar_url) {
        if (empty($avatar_url)) {
            return false;
        }
        
        return parent::save_avatar($user_id, $avatar_url);
    }

    /**
     * Provider bağlantısını kes
     */
    public function disconnect($user_id, $provider_data = []) {
        return parent::disconnect($user_id, $provider_data);
    }

    /**
     * Access token al (override - Douyin formatına uygun)
     */
    public function get_access_token($code) {
        $url = $this->get_token_url();
        $params = $this->get_token_params($code);
        $headers = $this->get_token_headers();
        
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => json_encode($params),
            'timeout' => 20,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        
        $this->core->log('douyin_token_response', [
            'body' => substr($body, 0, 200)
        ]);
        
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return new WP_Error('token_parse_error', 'Token cevabı geçersiz: ' . substr($body, 0, 200));
        }
        
        // Douyin yanıt formatı: { "data": { "access_token": "...", "open_id": "..." } }
        if (isset($data['data'])) {
            $token_data = $data['data'];
        } else {
            $token_data = $data;
        }
        
        // Session'a kaydet
        if (!session_id()) {
            session_start();
        }
        $_SESSION['douyin_token_data'] = $token_data;
        if (isset($token_data['open_id'])) {
            $_SESSION['douyin_open_id'] = $token_data['open_id'];
        }
        
        return $token_data;
    }
}