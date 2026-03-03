<?php
// masal-panel/modules/ai-community-engine/core/providers/prime-video.php
// AMAZON PRIME VIDEO OAUTH2 PROVIDER (Amazon Login)

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_Prime_Video extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Prime Video';
    protected $name = 'Prime Video';
    public $slug = 'prime-video';
    
    // Amazon Login özel değişkenler
    private $api_endpoint = 'https://api.amazon.com';
    private $userinfo_endpoint = 'https://api.amazon.com/user/profile';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Amazon özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        if (isset($config['api_endpoint'])) {
            $this->api_endpoint = rtrim($config['api_endpoint'], '/');
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
            
            $this->core->log('primevideo_oauth_error', [
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
            $this->core->log('primevideo_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Access token al
        $token_data = $this->get_access_token($code);
        
        if (is_wp_error($token_data)) {
            $this->core->log('primevideo_token_error', [
                'error' => $token_data->get_error_message()
            ]);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Kullanıcı bilgilerini al (Amazon Profile API)
        $userinfo = $this->get_userinfo($token_data['access_token']);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('primevideo_userinfo_error', [
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
            $this->core->log('primevideo_user_creation_error', [
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
            update_user_meta($user_id, 'primevideo_access_token', $token_data['access_token']);
            if (!empty($token_data['refresh_token'])) {
                update_user_meta($user_id, 'primevideo_refresh_token', $token_data['refresh_token']);
            }
            if (!empty($token_data['expires_in'])) {
                $expiry_time = time() + intval($token_data['expires_in']);
                update_user_meta($user_id, 'primevideo_token_expiry', $expiry_time);
            }
        }
        
        // Amazon müşteri ID'sini kaydet
        if (!empty($profile['user_id'])) {
            update_user_meta($user_id, 'primevideo_customer_id', $profile['user_id']);
        }
        
        // Prime üyelik bilgisini kaydet
        if (!empty($profile['prime_member'])) {
            update_user_meta($user_id, 'primevideo_member', $profile['prime_member']);
        }
        
        // Avatar kaydet (varsa)
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        // Başarılı giriş, yönlendir
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'prime-video');
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
     * Amazon OAuth2 authorization endpoint
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
        
        return $this->api_endpoint . '/ap/oa?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return $this->api_endpoint . '/auth/o2/token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al (Amazon Profile API)
     */
    public function get_userinfo_url() {
        return $this->userinfo_endpoint;
    }
    
    /**
     * Kullanıcının Prime Video izleme geçmişi URL'i
     */
    public function get_watch_history_url() {
        return 'https://www.primevideo.com/region/na/ref=atv_auth_dp';
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
            'Content-Type' => 'application/x-www-form-urlencoded'
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
        return isset($response['expires_in']) ? intval($response['expires_in']) : 3600; // Varsayılan 1 saat
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
        return 'profile';
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $response API'den gelen yanıt (user/profile)
     * @return array
     */
    public function map_userinfo_to_profile($response) {
        if (empty($response) || empty($response['user_id'])) {
            return [
                'user_id' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'postal_code' => '',
                'prime_member' => false
            ];
        }
        
        // Amazon Profile API response formatı:
        // {
        //   "user_id": "amzn1.account.XXXXX",
        //   "email": "user@example.com",
        //   "name": "John Doe",
        //   "postal_code": "12345"
        // }
        
        $profile = [
            'user_id'       => $response['user_id'],
            'email'         => isset($response['email']) ? $response['email'] : '',
            'name'          => isset($response['name']) ? $response['name'] : '',
            'avatar'        => '', // Amazon profil resmi dönmez
            'postal_code'   => isset($response['postal_code']) ? $response['postal_code'] : '',
            'prime_member'  => false // API'den gelmez, ayrı sorgu gerekir
        ];
        
        // Prime üyelik kontrolü (ayrı API çağrısı)
        // Bu kısım Prime Video özel API gerektirir, şimdilik false
        // İleride Amazon Pay veya Prime Status API entegre edilebilir
        
        return $profile;
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|false|WP_Error
     */
    public function find_or_create_user($profile) {
        // Amazon user_id'ye göre kullanıcı ara (meta_key)
        if (!empty($profile['user_id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_primevideo_id',
                'meta_value' => $profile['user_id'],
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
        
        // İsim varsa kullanıcı adı olarak dene
        if (!empty($profile['name'])) {
            $username_base = sanitize_user($profile['name'], true);
            $user = get_user_by('login', $username_base);
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
            $this->core->log('primevideo_user_creation_error', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['user_id'])) {
            update_user_meta($user_id, 'ai_comm_provider_primevideo_id', $profile['user_id']);
        }
        update_user_meta($user_id, 'ai_comm_provider_primevideo_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_primevideo_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'prime-video');
        
        // Amazon/Prime Video özel metalar
        if (!empty($profile['postal_code'])) {
            update_user_meta($user_id, 'primevideo_postal_code', $profile['postal_code']);
        }
        if (!empty($profile['prime_member'])) {
            update_user_meta($user_id, 'primevideo_member', $profile['prime_member']);
        }
        
        // Bölge bilgisi (postal code'dan çıkarılabilir)
        if (!empty($profile['postal_code'])) {
            // Basit bölge tahmini - ilk rakama göre
            $first_digit = substr($profile['postal_code'], 0, 1);
            $region = $this->guess_region_from_postal($first_digit);
            update_user_meta($user_id, 'primevideo_region', $region);
        }
        
        // Görünen ismi ayarla
        if (!empty($profile['name'])) {
            update_user_meta($user_id, 'nickname', $profile['name']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
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
        
        if (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } elseif (!empty($profile['email'])) {
            // Email'in @ öncesini al
            $email_parts = explode('@', $profile['email']);
            $base = sanitize_user($email_parts[0], true);
        } elseif (!empty($profile['user_id'])) {
            $base = 'prime_' . substr(md5($profile['user_id']), 0, 8);
        } else {
            $base = 'prime_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'primevideo';
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
        
        if (!empty($profile['user_id'])) {
            return 'prime_' . md5($profile['user_id']) . '@social.local';
        }
        
        return 'prime_' . wp_generate_password(8, false) . '@social.local';
    }
    
    /**
     * Posta kodundan bölge tahmini yap
     * 
     * @param string $first_digit Posta kodu ilk rakamı
     * @return string
     */
    private function guess_region_from_postal($first_digit) {
        $regions = [
            '0' => 'US (Northeast)',
            '1' => 'US (Mid-Atlantic)',
            '2' => 'US (Southeast)',
            '3' => 'US (South)',
            '4' => 'US (Midwest)',
            '5' => 'US (Midwest)',
            '6' => 'US (Central)',
            '7' => 'US (South Central)',
            '8' => 'US (Mountain)',
            '9' => 'US (Pacific)'
        ];
        
        return isset($regions[$first_digit]) ? $regions[$first_digit] : 'Unknown';
    }
    
    /**
     * Token'ı yenile (refresh token ile)
     * 
     * @param string $refresh_token Refresh token
     * @return array|WP_Error
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
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => http_build_query($params),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * Kullanıcının Prime üyelik durumunu kontrol et (opsiyonel)
     * Not: Bu resmi bir API değil, Prime Video özel implementasyon
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function check_prime_membership($access_token) {
        // Prime Video'nun özel API'si yok, genelde çerezlerle çalışır
        // Bu metod şimdilik pasif, ileride Amazon Pay API entegre edilebilir
        
        return [
            'is_prime_member' => false,
            'membership_type' => '',
            'expiry_date' => ''
        ];
    }
    
    /**
     * Kullanıcının izleme listesini getir (opsiyonel)
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_watchlist($access_token) {
        // Prime Video'nun public API'si yok
        // Bu metod şimdilik boş döner
        return [];
    }
    
    /**
     * Kullanıcının abone olduğu kanalları getir (Prime Video Channels)
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_subscribed_channels($access_token) {
        // Prime Video Channels API'si yok
        // Bu metod şimdilik boş döner
        return [];
    }
}