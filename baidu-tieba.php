<?php
/**
 * AI Community Provider: Baidu Tieba (百度贴吧)
 * 
 * @package AI_Community
 */

// Doğrudan erişimi engelle
if (!defined('ABSPATH')) {
    exit;
}

// Base class'ı kontrol et
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

/**
 * Baidu Tieba OAuth Provider sınıfı
 */
class AI_Community_Provider_Baidu_Tieba extends AI_Community_OAuth2_Provider {
    
    /**
     * Yapılandırma
     *
     * @var array
     */
    protected $config = [];
    
    /**
     * OAuth sürümü
     *
     * @var string
     */
    protected $version = 'OAuth2';
    
    /**
     * Provider slug
     *
     * @var string
     */
    public $slug = 'baidu-tieba';
    
    /**
     * İzin kapsamı (scope)
     *
     * @var string
     */
    protected $scope = 'basic';
    
    /**
     * Yetkilendirme URL'i
     *
     * @var string
     */
    protected $auth_url = 'https://openapi.baidu.com/oauth/2.0/authorize';
    
    /**
     * Token URL'i
     *
     * @var string
     */
    protected $token_url = 'https://openapi.baidu.com/oauth/2.0/token';
    
    /**
     * Kullanıcı bilgisi URL'i
     *
     * @var string
     */
    protected $userinfo_url = 'https://openapi.baidu.com/rest/2.0/passport/users/getInfo';
    
    /**
     * Kullanıcı ID
     *
     * @var string
     */
    protected $uid = '';
    
    /**
     * Constructor
     * AI_Community_Provider_Base'in beklediği imza: __construct($id, $config, $core)
     *
     * @param string $id Provider ID
     * @param array $config Yapılandırma
     * @param object $core Ana sınıf
     */
    public function __construct($id, $config = [], $core = null) {
        // Parent constructor'ı çağır
        parent::__construct($id, $config, $core);
        
        $this->config = $config;
        
        // Varsayılan ayarları yapılandırma ile birleştir
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
     * Handle metodu - Gelen isteği işler (PARAMETRESİZ!)
     * AI_Community_Provider_Base'den gelen abstract metod
     *
     * @return array İşlem sonucu
     */
    public function handle() {
        // $_GET ve $_POST'u birleştir
        $request = array_merge($_GET, $_POST);
        
        $action = isset($request['action']) ? $request['action'] : '';
        
        switch ($action) {
            case 'auth':
                return $this->handle_auth($request);
            case 'callback':
                return $this->handle_callback($request);
            default:
                return [
                    'success' => false,
                    'message' => 'Geçersiz action: ' . $action
                ];
        }
    }
    
    /**
     * Yetkilendirme URL'ini oluştur
     *
     * @param string $state State parametresi
     * @return string Yetkilendirme URL'i
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->get_scope(),
            'display' => 'popup' // Baidu özel parametre
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        }
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    /**
     * Token almak için URL
     *
     * @return string Token URL'i
     */
    public function get_token_url() {
        return $this->token_url;
    }
    
    /**
     * Kullanıcı bilgisi almak için URL
     *
     * @return string Kullanıcı bilgisi URL'i
     */
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    /**
     * Token isteği için gerekli parametreler
     *
     * @param string $code Yetkilendirme kodu
     * @return array Token parametreleri
     */
    public function get_token_params($code) {
        return [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirect_uri
        ];
    }
    
    /**
     * Token isteği için HTTP methodu
     *
     * @return string HTTP method (GET, POST)
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Token isteği için headers
     *
     * @return array Headers
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    }
    
    /**
     * Token yanıtından access token'ı çıkar
     *
     * @param array $response Token yanıtı
     * @return string|false Access token veya false
     */
    public function parse_access_token($response) {
        if (isset($response['access_token'])) {
            return $response['access_token'];
        }
        
        return false;
    }
    
    /**
     * Token yanıtından refresh token'ı çıkar
     *
     * @param array $response Token yanıtı
     * @return string|false Refresh token veya false
     */
    public function parse_refresh_token($response) {
        if (isset($response['refresh_token'])) {
            return $response['refresh_token'];
        }
        
        return false;
    }
    
    /**
     * Token yanıtından expire süresini çıkar (saniye)
     *
     * @param array $response Token yanıtı
     * @return int|false Expire süresi veya false
     */
    public function parse_expires_in($response) {
        if (isset($response['expires_in'])) {
            return (int) $response['expires_in'];
        }
        
        return false;
    }
    
    /**
     * Token yanıtından kullanıcı ID'sini çıkar (Baidu özel)
     *
     * @param array $response Token yanıtı
     * @return string|false Kullanıcı ID veya false
     */
    public function parse_user_id($response) {
        if (isset($response['uid'])) {
            return $response['uid'];
        }
        
        if (isset($response['user_id'])) {
            return $response['user_id'];
        }
        
        return false;
    }
    
    /**
     * Kullanıcı bilgisi isteği için HTTP methodu
     *
     * @return string HTTP method (GET, POST)
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Kullanıcı bilgisi isteği için headers
     *
     * @param string $access_token Access token
     * @return array Headers
     */
    public function get_userinfo_headers($access_token) {
        return [
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgisi isteği için parametreler
     *
     * @param string $access_token Access token
     * @return array Parametreler
     */
    public function get_userinfo_params($access_token) {
        return [
            'access_token' => $access_token
        ];
    }
    
    /**
     * Kullanıcı bilgisini profile dönüştür
     *
     * @param array $userinfo Kullanıcı bilgisi
     * @return array Profil verisi
     */
    public function map_userinfo_to_profile($userinfo) {
        $profile = [];
        
        // Baidu API yanıt yapısına göre mapping
        $data = $userinfo;
        
        // ID
        if (isset($data['uid'])) {
            $profile['id'] = $data['uid'];
            $this->uid = $data['uid'];
        } elseif (isset($data['user_id'])) {
            $profile['id'] = $data['user_id'];
        } elseif (isset($data['openid'])) {
            $profile['id'] = $data['openid'];
        } else {
            $profile['id'] = '';
        }
        
        // İsim
        if (isset($data['username'])) {
            $profile['name'] = $data['username'];
        } elseif (isset($data['uname'])) {
            $profile['name'] = $data['uname'];
        } elseif (isset($data['name'])) {
            $profile['name'] = $data['name'];
        } elseif (isset($data['nickname'])) {
            $profile['name'] = $data['nickname'];
        } else {
            $profile['name'] = '';
        }
        
        // Email (Baidu email verebilir ama izin gerektirir)
        $profile['email'] = isset($data['email']) ? $data['email'] : '';
        
        // Avatar
        if (isset($data['portrait'])) {
            // Baidu portrait format: portrait_url = "http://tb.himg.baidu.com/sys/portraitn/item/" + portrait
            $profile['avatar'] = 'http://tb.himg.baidu.com/sys/portraitn/item/' . $data['portrait'];
        } elseif (isset($data['avatar'])) {
            $profile['avatar'] = $data['avatar'];
        } elseif (isset($data['headimgurl'])) {
            $profile['avatar'] = $data['headimgurl'];
        } elseif (isset($data['figureurl'])) {
            $profile['avatar'] = $data['figureurl'];
        } else {
            $profile['avatar'] = '';
        }
        
        // Avatar URL'i oluştur (portrait varsa)
        if (isset($data['portrait']) && empty($profile['avatar'])) {
            $profile['avatar'] = 'https://himg.bdimg.com/sys/portraitn/item/' . $data['portrait'];
        }
        
        // Ek bilgiler
        if (isset($data['sex'])) {
            $profile['gender'] = $data['sex'] == 1 ? 'male' : 'female';
        }
        
        if (isset($data['gender'])) {
            $profile['gender'] = $data['gender'];
        }
        
        if (isset($data['birthday'])) {
            $profile['birthday'] = $data['birthday'];
        }
        
        if (isset($data['birth_year']) && isset($data['birth_month']) && isset($data['birth_day'])) {
            $profile['birthday'] = $data['birth_year'] . '-' . 
                                  str_pad($data['birth_month'], 2, '0', STR_PAD_LEFT) . '-' . 
                                  str_pad($data['birth_day'], 2, '0', STR_PAD_LEFT);
        }
        
        if (isset($data['province'])) {
            $profile['province'] = $data['province'];
        }
        
        if (isset($data['city'])) {
            $profile['city'] = $data['city'];
        }
        
        if (isset($data['location'])) {
            $profile['location'] = $data['location'];
        }
        
        if (isset($data['description']) || isset($data['intro']) || isset($data['bio'])) {
            if (isset($data['description'])) {
                $profile['bio'] = $data['description'];
            } elseif (isset($data['intro'])) {
                $profile['bio'] = $data['intro'];
            } else {
                $profile['bio'] = $data['bio'];
            }
        }
        
        // Baidu Tieba özel alanlar
        if (isset($data['level'])) {
            $profile['level'] = $data['level']; // Kullanıcı seviyesi
        }
        
        if (isset($data['exp'])) {
            $profile['experience'] = $data['exp']; // Deneyim puanı
        }
        
        if (isset($data['follow_forum_count'])) {
            $profile['follow_forums'] = $data['follow_forum_count']; // Takip edilen forum sayısı
        }
        
        if (isset($data['post_count'])) {
            $profile['posts'] = $data['post_count']; // Gönderi sayısı
        }
        
        if (isset($data['thread_count'])) {
            $profile['threads'] = $data['thread_count']; // Konu sayısı
        }
        
        if (isset($data['follower_count'])) {
            $profile['followers'] = $data['follower_count'];
        }
        
        if (isset($data['follow_count'])) {
            $profile['following'] = $data['follow_count'];
        }
        
        if (isset($data['like_count'])) {
            $profile['likes'] = $data['like_count']; // Beğeni sayısı
        }
        
        if (isset($data['is_vip'])) {
            $profile['is_vip'] = (bool) $data['is_vip'];
        }
        
        if (isset($data['vip_level'])) {
            $profile['vip_level'] = $data['vip_level'];
        }
        
        return $profile;
    }
    
    /**
     * Callback işleme
     * AI_Community_OAuth2_Provider'da protected olarak tanımlı
     *
     * @param array $request Callback isteği
     * @return array İşlem sonucu
     */
    protected function handle_callback($request) {
        $code = isset($request['code']) ? $request['code'] : '';
        
        if (empty($code)) {
            return [
                'success' => false,
                'message' => 'Yetkilendirme kodu bulunamadı'
            ];
        }
        
        // Hata kontrolü
        if (isset($request['error'])) {
            return [
                'success' => false,
                'message' => isset($request['error_description']) ? $request['error_description'] : $request['error']
            ];
        }
        
        // State kontrolü
        $state = isset($request['state']) ? $request['state'] : '';
        if (!empty($state)) {
            if (!session_id()) {
                session_start();
            }
            if (isset($_SESSION['oauth_state']) && $_SESSION['oauth_state'] !== $state) {
                return [
                    'success' => false,
                    'message' => 'State parametresi geçersiz'
                ];
            }
        }
        
        // Token al
        $token_result = $this->get_access_token($code);
        
        if (!$token_result['success']) {
            return $token_result;
        }
        
        $access_token = $token_result['access_token'];
        
        // Token yanıtından uid'yi çek (Baidu özel)
        $uid = $this->parse_user_id($token_result['response']);
        if (!empty($uid)) {
            $this->uid = $uid;
        }
        
        // Kullanıcı bilgilerini al
        $userinfo_result = $this->get_userinfo($access_token);
        
        if (!$userinfo_result['success']) {
            return $userinfo_result;
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($userinfo_result['data']);
        
        return [
            'success' => true,
            'profile' => $profile,
            'token_data' => [
                'access_token' => $access_token,
                'refresh_token' => isset($token_result['refresh_token']) ? $token_result['refresh_token'] : '',
                'expires_in' => isset($token_result['expires_in']) ? $token_result['expires_in'] : 0,
                'uid' => $uid
            ]
        ];
    }
    
    /**
     * Yetkilendirme işlemini başlat
     *
     * @param array $request İstek verisi
     * @return array İşlem sonucu
     */
    protected function handle_auth($request) {
        $state = wp_generate_password(32, false);
        
        // State'i session'a kaydet
        if (!session_id()) {
            session_start();
        }
        $_SESSION['oauth_state'] = $state;
        
        $auth_url = $this->get_auth_url($state);
        
        return [
            'success' => true,
            'redirect_url' => $auth_url
        ];
    }
    
    /**
     * İzin kapsamını getir
     *
     * @return string Scope
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Provider adını getir
     *
     * @return string Provider adı
     */
    public function get_name() {
        return 'Baidu Tieba';
    }
    
    /**
     * Provider tipini getir
     *
     * @return string Provider tipi
     */
    public function get_type() {
        return $this->version;
    }
    
    /**
     * Provider slug'ını getir
     *
     * @return string Provider slug
     */
    public function get_slug() {
        return $this->slug;
    }
    
    /**
     * Kullanıcı bul veya oluştur
     *
     * @param array $profile Kullanıcı profili
     * @return int|false Kullanıcı ID'si veya false
     */
    public function find_or_create_user($profile) {
        if (empty($profile['id'])) {
            return false;
        }
        
        // Kullanıcıyı meta tablosunda ara
        $users = get_users([
            'meta_key' => 'ai_community_' . $this->get_slug() . '_id',
            'meta_value' => $profile['id'],
            'number' => 1,
            'fields' => ['ID']
        ]);
        
        if (!empty($users)) {
            return $users[0]->ID;
        }
        
        // Email varsa email ile ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                // Bu provider ID'sini kaydet
                update_user_meta($user->ID, 'ai_community_' . $this->get_slug() . '_id', $profile['id']);
                return $user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['name']);
        $email = !empty($profile['email']) ? $profile['email'] : $username . '@' . $this->get_slug() . '.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return false;
        }
        
        // Provider ID'sini kaydet
        update_user_meta($user_id, 'ai_community_' . $this->get_slug() . '_id', $profile['id']);
        
        // Diğer meta bilgilerini kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
        }
        
        return $user_id;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     *
     * @param string $base_name Temel isim
     * @return string Benzersiz kullanıcı adı
     */
    protected function generate_unique_username($base_name) {
        $username = sanitize_user($base_name, true);
        
        if (empty($username)) {
            $username = 'user_' . $this->get_slug();
        }
        
        $username = strtolower($username);
        $username = preg_replace('/[^a-z0-9]/', '', $username);
        
        $original_username = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $original_username . $counter;
            $counter++;
        }
        
        return $username;
    }
}