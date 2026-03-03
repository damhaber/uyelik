<?php
/**
 * AI Community OAuth2 Provider Base Class
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Eğer sınıf zaten tanımlanmışsa tekrar tanımlama
if (!class_exists('AI_Community_OAuth2_Provider')) {

abstract class AI_Community_OAuth2_Provider extends AI_Community_Provider_Base {
    
    protected $client_id = '';
    protected $client_secret = '';
    protected $redirect_uri = '';
    protected $scope = '';
    protected $auth_url = '';
    protected $token_url = '';
    protected $userinfo_url = '';
    
    /**
     * Constructor
     */
    public function __construct($id, $config = [], $core = null) {
        parent::__construct($id, $config, $core);
        
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        
        if (isset($config['redirect_uri'])) {
            $this->redirect_uri = $config['redirect_uri'];
        }
        
        if (isset($config['scope'])) {
            $this->scope = $config['scope'];
        }
    }
    
    /**
     * Ana handler - Tüm OAuth isteklerini yönetir
     */
    public function handle() {
        // Callback işleme (code parametresi varsa)
        if (isset($_GET['code'])) {
            return $this->handle_callback($_GET);
        }
        
        // Hata varsa
        if (isset($_GET['error'])) {
            return $this->handle_error($_GET);
        }
        
        // Normal yetkilendirme başlatma
        return $this->handle_auth($_GET);
    }
    
    /**
     * Yetkilendirme başlat
     */
    protected function handle_auth($request) {
        // Benzersiz state oluştur
        $state = wp_create_nonce('ai_community_oauth_' . $this->id);
        
        // State'i session'a kaydet
        $this->save_state($state);
        
        // Yetkilendirme URL'ine yönlendir
        $auth_url = $this->get_auth_url($state);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Callback işle
     */
    protected function handle_callback($request) {
        try {
            // Hata kontrolü
            if (isset($request['error'])) {
                throw new Exception($request['error_description'] ?? $request['error']);
            }
            
            // Code kontrolü
            if (!isset($request['code'])) {
                throw new Exception('Yetkilendirme kodu bulunamadı.');
            }
            
            $code = $request['code'];
            
            // State kontrolü (varsa)
            if (isset($request['state'])) {
                if (!wp_verify_nonce($request['state'], 'ai_community_oauth_' . $this->id)) {
                    throw new Exception('Güvenlik doğrulaması başarısız.');
                }
            }
            
            // Token al
            $token_data = $this->get_access_token($code);
            
            if (!$token_data) {
                throw new Exception('Access token alınamadı.');
            }
            
            // Kullanıcı bilgilerini al
            $userinfo = $this->get_userinfo($token_data['access_token']);
            
            if (!$userinfo) {
                throw new Exception('Kullanıcı bilgileri alınamadı.');
            }
            
            // Profil oluştur
            $profile = $this->map_userinfo_to_profile($userinfo);
            
            // Provider bilgilerini ekle
            $profile['provider'] = $this->id;
            $profile['provider_name'] = $this->get_name();
            $profile['login_time'] = time();
            
            // Token bilgilerini ekle
            $profile['token_data'] = [
                'access_token' => $token_data['access_token'],
                'refresh_token' => $token_data['refresh_token'] ?? '',
                'expires_in' => $token_data['expires_in'] ?? 0
            ];
            
            // WordPress kullanıcısı oluştur veya bul
            $user_id = $this->find_or_create_user($profile);
            
            if ($user_id) {
                // WordPress oturumu başlat
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id, true);
                
                // Başarılı giriş - yönlendir
                wp_redirect(home_url('/profil-merkezim/'));
                exit;
            } else {
                throw new Exception('Kullanıcı oluşturulamadı.');
            }
            
        } catch (Exception $e) {
            error_log($this->id . ' OAuth hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Hata işleme
     */
    protected function handle_error($request) {
        $error = $request['error'] ?? 'Bilinmeyen hata';
        $error_description = $request['error_description'] ?? '';
        
        $message = $error_description ? "$error: $error_description" : $error;
        
        return $this->show_error('Yetkilendirme hatası: ' . $message);
    }
    
    /**
     * Access token al
     */
    public function get_access_token($code) {
        $url = $this->get_token_url();
        $params = $this->get_token_params($code);
        $method = $this->get_token_method();
        $headers = $this->get_token_headers();
        
        $args = [
            'headers' => $headers,
            'timeout' => 30
        ];
        
        if ($method === 'POST') {
            $args['body'] = $params;
            $response = wp_remote_post($url, $args);
        } else {
            $url = add_query_arg($params, $url);
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            error_log($this->id . ' token hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            return false;
        }
        
        // Hata kontrolü
        if (isset($data['error'])) {
            error_log($this->id . ' token hatası: ' . print_r($data, true));
            return false;
        }
        
        $token_data = [
            'access_token' => $this->parse_access_token($data),
            'refresh_token' => $this->parse_refresh_token($data),
            'expires_in' => $this->parse_expires_in($data)
        ];
        
        // Access token yoksa başarısız
        if (!$token_data['access_token']) {
            return false;
        }
        
        return $token_data;
    }
    
    /**
     * Kullanıcı bilgilerini al
     */
    public function get_userinfo($access_token) {
        $url = $this->get_userinfo_url();
        $method = $this->get_userinfo_method();
        $headers = $this->get_userinfo_headers($access_token);
        $params = $this->get_userinfo_params($access_token);
        
        $args = [
            'headers' => $headers,
            'timeout' => 30
        ];
        
        if ($method === 'POST') {
            $args['body'] = $params;
            $response = wp_remote_post($url, $args);
        } else {
            if (!empty($params)) {
                $url = add_query_arg($params, $url);
            }
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            error_log($this->id . ' userinfo hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            return false;
        }
        
        // Hata kontrolü
        if (isset($data['error'])) {
            error_log($this->id . ' userinfo hatası: ' . print_r($data, true));
            return false;
        }
        
        return $data;
    }
    
    /**
     * State'i session'a kaydet
     */
    protected function save_state($state) {
        if (!session_id()) {
            session_start();
        }
        $_SESSION['oauth_state_' . $this->id] = $state;
    }
    
    /**
     * Provider tipini getir
     */
    public function get_type() {
        return 'oauth2';
    }
    
    // === ABSTRACT METHODS ===
    
    abstract public function get_auth_url($state = '');
    abstract public function get_token_url();
    abstract public function get_userinfo_url();
    abstract public function get_token_params($code);
    abstract public function get_token_method();
    abstract public function parse_access_token($response);
    abstract public function parse_refresh_token($response);
    abstract public function parse_expires_in($response);
    abstract public function get_userinfo_method();
    abstract public function get_userinfo_headers($access_token);
    abstract public function get_userinfo_params($access_token);
    abstract public function map_userinfo_to_profile($userinfo);
    abstract public function get_scope();
    
    // === OPTIONAL METHODS ===
    
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
        ];
    }
}

} // class_exists kontrolü kapanışı
?>