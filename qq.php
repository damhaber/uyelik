<?php
// masal-panel/modules/ai-community-engine/core/providers/qq.php
// DÜZELTİLMİŞ VERSİYON - OAuth2 base class doğru şekilde include edildi

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_QQ extends AI_Community_OAuth2_Provider {
    
    protected $label = 'QQ';
    protected $name = 'QQ';
    public $slug = 'qq';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // QQ özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
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
     * Yetkilendirme URL'ini al
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'scope' => $this->get_scope(),
            'state' => $state
        ];
        
        return 'https://graph.qq.com/oauth2.0/authorize?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al
     */
    public function get_token_url() {
        return 'https://graph.qq.com/oauth2.0/token';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return 'https://graph.qq.com/user/get_user_info';
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
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'redirect_uri' => $this->get_redirect_uri(),
            'fmt' => 'json' // JSON formatında yanıt al
        ];
    }
    
    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return 'GET'; // QQ GET kullanır
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
        return isset($response['expires_in']) ? intval($response['expires_in']) : 0;
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
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgi parametrelerini al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_params($access_token) {
        // OpenID ayrı bir API'den alınacağı için params boş
        return [];
    }
    
    /**
     * Scope'u al
     */
    public function get_scope() {
        return 'get_user_info';
    }
    
    /**
     * Kullanıcı bilgilerini al (override - QQ özel)
     * 
     * @param string $access_token Access token
     * @return array|false
     */
    public function get_userinfo($access_token) {
        // Önce OpenID'yi al
        $openid = $this->get_openid($access_token);
        
        if (!$openid) {
            error_log('QQ: OpenID alınamadı');
            return false;
        }
        
        // Kullanıcı bilgilerini al
        $url = $this->get_userinfo_url();
        $params = [
            'access_token' => $access_token,
            'oauth_consumer_key' => $this->client_id,
            'openid' => $openid,
            'format' => 'json'
        ];
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('QQ userinfo hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data) {
            return false;
        }
        
        // OpenID'yi de ekle
        $data['openid'] = $openid;
        
        return $data;
    }
    
    /**
     * QQ OpenID al
     * 
     * @param string $access_token Access token
     * @return string|false
     */
    private function get_openid($access_token) {
        $url = 'https://graph.qq.com/oauth2.0/me';
        $params = [
            'access_token' => $access_token,
            'fmt' => 'json'
        ];
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('QQ OpenID hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['openid'])) {
            return $data['openid'];
        }
        
        // callback( {"client_id":"...","openid":"..."} ); formatında gelebilir
        if (preg_match('/callback\( (.*) \);/', $body, $matches)) {
            $data = json_decode($matches[1], true);
            return isset($data['openid']) ? $data['openid'] : false;
        }
        
        return false;
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $userinfo API'den gelen kullanıcı bilgileri
     * @return array
     */
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo) || empty($userinfo['nickname'])) {
            return [
                'id' => '',
                'email' => '',
                'name' => '',
                'avatar' => '',
                'gender' => ''
            ];
        }
        
        // Avatar URL'leri
        $avatar = '';
        if (!empty($userinfo['figureurl_qq_2'])) {
            $avatar = $userinfo['figureurl_qq_2'];
        } elseif (!empty($userinfo['figureurl_qq_1'])) {
            $avatar = $userinfo['figureurl_qq_1'];
        } elseif (!empty($userinfo['figureurl_2'])) {
            $avatar = $userinfo['figureurl_2'];
        } elseif (!empty($userinfo['figureurl_1'])) {
            $avatar = $userinfo['figureurl_1'];
        }
        
        return [
            'id'      => isset($userinfo['openid']) ? $userinfo['openid'] : '',
            'email'   => '', // QQ email vermiyor
            'name'    => $userinfo['nickname'],
            'avatar'  => $avatar,
            'gender'  => isset($userinfo['gender']) ? $userinfo['gender'] : '',
            'province' => isset($userinfo['province']) ? $userinfo['province'] : '',
            'city'    => isset($userinfo['city']) ? $userinfo['city'] : '',
            'year'    => isset($userinfo['year']) ? $userinfo['year'] : ''
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur (override)
     * 
     * @param array $profile Kullanıcı profili
     * @return int|false
     */
    protected function find_or_create_user($profile) {
        // OpenID'ye göre kullanıcı ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_qq_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = $this->generate_email($profile);
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            error_log('QQ kullanıcı oluşturma hatası: ' . $user_id->get_error_message());
            return false;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_qq_id', $profile['id']);
        }
        update_user_meta($user_id, 'ai_comm_provider_qq_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_qq_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', 'qq');
        
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
        
        if (!empty($profile['gender'])) {
            update_user_meta($user_id, 'qq_gender', $profile['gender']);
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
        } elseif (!empty($profile['id'])) {
            $base = 'qq_' . substr(md5($profile['id']), 0, 8);
        } else {
            $base = 'qq_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'qq';
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
        
        if (!empty($profile['id'])) {
            return 'qq_' . md5($profile['id']) . '@social.local';
        }
        
        return 'qq_' . wp_generate_password(8, false) . '@social.local';
    }
}