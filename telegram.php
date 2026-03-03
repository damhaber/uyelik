<?php
/**
 * AI Community Provider: Telegram (OAuth2)
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Telegram extends AI_Community_OAuth2_Provider {
    
    protected $config = [];
    protected $version = 'OAuth2';
    public $slug = 'telegram';
    protected $scope = '';
    protected $auth_url = 'https://oauth.telegram.org/auth';
    protected $token_url = ''; // Telegram OAuth2 token endpoint yok, direkt callback
    protected $userinfo_url = ''; // Token ile userinfo alınmıyor
    protected $bot_token = '';
    
    /**
     * Constructor - 3 parametreli olmalı
     */
    public function __construct($id, $config = [], $core = null) {
        $this->id = $id;
        $this->config = $config;
        $this->core = $core;
        
        if (isset($config['client_id'])) { // Telegram'da bot token
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
        
        if (isset($request['hash']) || isset($request['tgAuthResult'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth URL - state parametreli
     */
    public function get_auth_url($state = '') {
        // Telegram Login Widget kullanıyor
        $params = [
            'bot_id' => $this->client_id,
            'scope' => $this->get_scope(),
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'widget_version' => '3',
            'embed' => '0'
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
        return [];
    }
    
    public function get_token_method() {
        return 'POST';
    }
    
    public function get_token_headers() {
        return [];
    }
    
    public function parse_access_token($response) {
        return null; // Telegram OAuth2 token kullanmıyor
    }
    
    public function parse_refresh_token($response) {
        return null;
    }
    
    public function parse_expires_in($response) {
        return 3600;
    }
    
    public function get_userinfo_method() {
        return 'GET';
    }
    
    public function get_userinfo_headers($token) {
        return [];
    }
    
    public function get_userinfo_params($token) {
        return [];
    }
    
    public function get_userinfo_params_with_token($token) {
        return [];
    }
    
    public function map_userinfo_to_profile($userinfo) {
        // Telegram'dan gelen user data
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => isset($userinfo['id']) ? (string) $userinfo['id'] : '',
            'username' => isset($userinfo['username']) ? $userinfo['username'] : '',
            'display_name' => '',
            'first_name' => isset($userinfo['first_name']) ? $userinfo['first_name'] : '',
            'last_name' => isset($userinfo['last_name']) ? $userinfo['last_name'] : '',
            'email' => '', // Telegram email vermiyor
            'avatar' => isset($userinfo['photo_url']) ? $userinfo['photo_url'] : '',
            'profile_url' => '',
            'locale' => isset($userinfo['language_code']) ? $userinfo['language_code'] : '',
            'phone' => isset($userinfo['phone_number']) ? $userinfo['phone_number'] : '',
            'auth_date' => isset($userinfo['auth_date']) ? $userinfo['auth_date'] : ''
        ];
        
        // Display name oluştur
        if (!empty($profile['first_name']) || !empty($profile['last_name'])) {
            $profile['display_name'] = trim($profile['first_name'] . ' ' . $profile['last_name']);
        } elseif (!empty($profile['username'])) {
            $profile['display_name'] = $profile['username'];
        }
        
        // Profile URL oluştur
        if (!empty($profile['username'])) {
            $profile['profile_url'] = 'https://t.me/' . $profile['username'];
        }
        
        return $profile;
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     */
    public function handle_callback($request) {
        // Telegram OAuth2 callback'i
        // Telegram Login Widget'dan gelen veriyi kontrol et
        
        $auth_data = [];
        
        // Telegram widget'ından gelen veri
        if (isset($request['tgAuthResult'])) {
            $auth_data = json_decode(base64_decode($request['tgAuthResult']), true);
            if (!is_array($auth_data)) {
                $auth_data = [];
            }
        } else {
            // Normal callback parametreleri
            foreach ($request as $key => $value) {
                if (in_array($key, ['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash'])) {
                    $auth_data[$key] = $value;
                }
            }
        }
        
        if (empty($auth_data)) {
            return new WP_Error('no_data', 'No authentication data received');
        }
        
        // Hash kontrolü yap
        if (!$this->check_telegram_hash($auth_data)) {
            return new WP_Error('invalid_hash', 'Invalid hash - possible data tampering');
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($auth_data);
        
        // Kullanıcı bul veya oluştur
        $user = $this->find_or_create_user($profile);
        
        if (is_wp_error($user)) {
            return $user;
        }
        
        // Profili döndür (user_id'yi de ekle)
        $profile['user_id'] = is_numeric($user) ? $user : $user->ID;
        
        return $profile;
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
        
        // Telegram email vermediği için email kontrolü yapamıyoruz
        // Sadece provider bağlantısı ile kullanıcı oluştur
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $password = wp_generate_password();
        $email = $profile['provider_slug'] . '_' . $profile['user_id'] . '@telegram.local';
        
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
        
        // Ek bilgileri kaydet
        if (!empty($profile['phone'])) {
            update_user_meta($user_id, 'ai_community_telegram_phone', $profile['phone']);
        }
        
        if (!empty($profile['auth_date'])) {
            update_user_meta($user_id, 'ai_community_telegram_auth_date', $profile['auth_date']);
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
     * Telegram'dan gelen hash'i kontrol et
     */
    private function check_telegram_hash($auth_data) {
        if (!isset($auth_data['hash'])) {
            return false;
        }
        
        $check_hash = $auth_data['hash'];
        unset($auth_data['hash']);
        
        // Veriyi sırala ve string oluştur
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        
        // Secret key oluştur
        $secret_key = hash('sha256', $this->bot_token, true);
        
        // Hash hesapla
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        
        return hash_equals($hash, $check_hash);
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Telegram';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}