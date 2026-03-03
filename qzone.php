<?php
// masal-panel/modules/ai-community-engine/core/providers/qzone.php
// QZone (QQ空间) Provider - DÜZELTİLMİŞ VERSİYON (WeChat/Weibo yapısına göre)

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_QZone extends AI_Community_OAuth2_Provider {
    
    protected $label = 'QZone';
    protected $name = 'QZone';
    public $slug = 'qzone';

    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // QZone için özel scope ayarı
        if (empty($this->config['scope'])) {
            $this->config['scope'] = 'get_user_info,list_album';
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
            
            $this->core->log('qzone_oauth_error', [
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
            $this->core->log('qzone_no_code', ['request' => $request]);
            wp_redirect(home_url('/login?error=no_code'));
            exit;
        }

        // State doğrulama
        if (!wp_verify_nonce($state, 'ai_community_oauth_state')) {
            $this->core->log('qzone_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('qzone_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // OpenID'yi session'a kaydet
        if (isset($token_data['access_token']) && !session_id()) {
            session_start();
            $_SESSION['qzone_token_data'] = $token_data;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('qzone_userinfo_error', [
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
            $this->core->log('qzone_user_creation_error', [
                'error' => is_wp_error($user_id) ? $user_id->get_error_message() : 'Unknown error'
            ]);
            wp_redirect(home_url('/login?error=user_creation_failed'));
            exit;
        }
        
        // Provider bağlantı kaydı
        if (!empty($profile['id'])) {
            $provider_meta_key = 'ai_comm_provider_qzone_id';
            $provider_email_key = 'ai_comm_provider_qzone_email';
            $provider_joined_key = 'ai_comm_provider_qzone_joined_at';

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
        
        $this->core->log('qzone_login_success', [
            'provider' => $this->id,
            'user_id'  => $user_id,
        ]);
        
        // Başarılı giriş, yönlendir
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'qzone');
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
        $url = 'https://graph.qq.com/oauth2.0/authorize';
        
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'] ?? '',
            'redirect_uri' => $this->get_redirect_uri(),
            'scope' => $this->get_scope(),
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
        return 'https://graph.qq.com/oauth2.0/token';
    }

    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return 'GET';
    }

    /**
     * Token parametrelerini al
     */
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->get_redirect_uri(),
            'client_id' => $this->config['client_id'] ?? '',
            'client_secret' => $this->config['client_secret'] ?? '',
            'fmt' => 'json', // JSON formatında yanıt al
        ];
    }

    /**
     * Access token parse et
     */
    public function parse_access_token($response) {
        return $response['access_token'] ?? '';
    }

    /**
     * Refresh token parse et
     */
    public function parse_refresh_token($response) {
        return $response['refresh_token'] ?? '';
    }

    /**
     * Expires in parse et
     */
    public function parse_expires_in($response) {
        return isset($response['expires_in']) ? intval($response['expires_in']) : 0;
    }

    /**
     * Kullanıcı bilgi URL'ini döndürür
     */
    public function get_userinfo_url() {
        return 'https://graph.qq.com/user/get_user_info';
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
            'oauth_consumer_key' => $this->config['client_id'] ?? '',
            'openid' => '', // Session'dan alınacak
            'format' => 'json'
        ];
    }

    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'get_user_info,list_album';
    }

    /**
     * QZone için OpenID ayrı API'den alınır
     */
    public function get_openid($access_token) {
        $url = 'https://graph.qq.com/oauth2.0/me';
        $params = [
            'access_token' => $access_token,
            'fmt' => 'json'
        ];
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            $this->core->log('qzone_openid_error', [
                'error' => $response->get_error_message()
            ]);
            return '';
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // QQ bazen callback fonksiyonu içinde dönebilir
        if (!$data && preg_match('/callback\((.+)\)/', $body, $matches)) {
            $data = json_decode($matches[1], true);
        }
        
        return isset($data['openid']) ? $data['openid'] : '';
    }

    /**
     * Kullanıcı bilgilerini getir (override)
     */
    public function get_userinfo($access_token) {
        // OpenID'yi al
        $openid = $this->get_openid($access_token);
        
        if (empty($openid)) {
            $this->core->log('qzone_no_openid', []);
            return new WP_Error('no_openid', 'QZone OpenID bulunamadı');
        }
        
        // OpenID'yi session'a kaydet (ilerde kullanmak için)
        if (!session_id()) {
            session_start();
        }
        $_SESSION['qzone_openid'] = $openid;
        
        // Kullanıcı bilgilerini al
        $url = $this->get_userinfo_url();
        $params = [
            'access_token' => $access_token,
            'oauth_consumer_key' => $this->config['client_id'] ?? '',
            'openid' => $openid,
            'format' => 'json'
        ];
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'sslverify' => false,
        ]);
        
        if (is_wp_error($response)) {
            $this->core->log('qzone_userinfo_error', [
                'error' => $response->get_error_message()
            ]);
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // OpenID'yi de ekleyelim
        if (is_array($data)) {
            $data['openid'] = $openid;
        }
        
        return $data;
    }

    /**
     * Kullanıcı bilgilerini profile map et
     */
    public function map_userinfo_to_profile($data) {
        if (!is_array($data) || !isset($data['ret']) || $data['ret'] !== 0) {
            $error_msg = isset($data['msg']) ? $data['msg'] : 'Geçersiz QZone kullanıcı bilgisi';
            $this->core->log('qzone_invalid_response', ['error' => $error_msg]);
            
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'gender' => 'other'
            ];
        }
        
        // Avatar URL'leri
        $avatar = '';
        if (!empty($data['figureurl_qq_2'])) {
            $avatar = $data['figureurl_qq_2'];
        } elseif (!empty($data['figureurl_qq_1'])) {
            $avatar = $data['figureurl_qq_1'];
        } elseif (!empty($data['figureurl'])) {
            $avatar = $data['figureurl'];
        }
        
        return [
            'id'        => isset($data['openid']) ? $data['openid'] : '',
            'email'     => '', // QZone email vermiyor
            'name'      => isset($data['nickname']) ? sanitize_text_field($data['nickname']) : 'QZone User',
            'username'  => isset($data['nickname']) ? sanitize_user($data['nickname'], true) : 'qzone_user',
            'avatar'    => esc_url_raw($avatar),
            'gender'    => $this->normalize_gender($data['gender'] ?? ''),
            'province'  => isset($data['province']) ? $data['province'] : '',
            'city'      => isset($data['city']) ? $data['city'] : '',
            'vip'       => isset($data['vip']) ? (bool)$data['vip'] : false,
            'level'     => isset($data['level']) ? $data['level'] : 0,
        ];
    }

    /**
     * Cinsiyet normalize et
     */
    private function normalize_gender($gender) {
        if ($gender === '男' || $gender === 'male') return 'male';
        if ($gender === '女' || $gender === 'female') return 'female';
        return 'other';
    }

    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        if (empty($profile['id'])) {
            return new WP_Error('no_id', 'QZone ID bulunamadı');
        }
        
        // Önce bu QZone ID ile bağlı bir kullanıcı var mı kontrol et
        $user_query = new WP_User_Query([
            'meta_key' => 'ai_comm_provider_qzone_id',
            'meta_value' => $profile['id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        $users = $user_query->get_results();
        
        if (!empty($users)) {
            return $users[0]; // Var olan kullanıcı
        }
        
        // Yeni kullanıcı oluştur (QZone email vermediği için dummy email)
        $username = $this->generate_unique_username($profile);
        $email = 'qzone_' . md5($profile['id']) . '@social.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('qzone_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        update_user_meta($user_id, 'ai_comm_provider_qzone_id', $profile['id']);
        update_user_meta($user_id, 'ai_comm_provider_qzone_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_qzone_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'qzone');
        
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
            $base = 'qzone_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'qzone';
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
     * Access token al (override - QQ formatına uygun)
     */
    public function get_access_token($code) {
        $url = $this->get_token_url();
        $params = $this->get_token_params($code);
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 20,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        
        // JSON parse et
        $data = json_decode($body, true);
        
        // QQ bazen callback fonksiyonu içinde dönebilir
        if (!$data && preg_match('/callback\((.+)\)/', $body, $matches)) {
            $data = json_decode($matches[1], true);
        }
        
        if (!is_array($data) || empty($data['access_token'])) {
            return new WP_Error('token_parse_error', 'Token cevabı geçersiz: ' . substr($body, 0, 200));
        }
        
        // Session'a kaydet
        if (!session_id()) {
            session_start();
        }
        $_SESSION['qzone_token_data'] = $data;
        
        return $data;
    }
}