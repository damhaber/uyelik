<?php
/**
 * AI Community Provider: Youku (优酷)
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
 * Youku OAuth Provider sınıfı
 */
class AI_Community_Provider_Youku extends AI_Community_OAuth2_Provider {
    
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
    public $slug = 'youku';
    
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
    protected $auth_url = 'https://openapi.youku.com/v2/oauth2/authorize';
    
    /**
     * Token URL'i
     *
     * @var string
     */
    protected $token_url = 'https://openapi.youku.com/v2/oauth2/token';
    
    /**
     * Kullanıcı bilgisi URL'i
     *
     * @var string
     */
    protected $userinfo_url = 'https://openapi.youku.com/v2/users/myinfo.json';
    
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
            'redirect_uri' => $this->redirect_uri
        ];
        
        if (!empty($this->get_scope())) {
            $params['scope'] = $this->get_scope();
        }
        
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
     * Token yanıtından kullanıcı ID'sini çıkar (Youku özel)
     *
     * @param array $response Token yanıtı
     * @return string|false Kullanıcı ID veya false
     */
    public function parse_user_id($response) {
        if (isset($response['user_id'])) {
            return $response['user_id'];
        }
        
        if (isset($response['uid'])) {
            return $response['uid'];
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
        
        // Youku API yanıt yapısına göre mapping
        $data = $userinfo;
        
        // ID
        if (isset($data['user_id'])) {
            $profile['id'] = $data['user_id'];
        } elseif (isset($data['uid'])) {
            $profile['id'] = $data['uid'];
        } elseif (isset($data['id'])) {
            $profile['id'] = $data['id'];
        } else {
            $profile['id'] = '';
        }
        
        // İsim
        if (isset($data['name'])) {
            $profile['name'] = $data['name'];
        } elseif (isset($data['screen_name'])) {
            $profile['name'] = $data['screen_name'];
        } elseif (isset($data['nickname'])) {
            $profile['name'] = $data['nickname'];
        } else {
            $profile['name'] = '';
        }
        
        // Email (Youku email sağlamaz)
        $profile['email'] = isset($data['email']) ? $data['email'] : '';
        
        // Avatar
        if (isset($data['avatar'])) {
            $profile['avatar'] = $data['avatar'];
        } elseif (isset($data['avatar_large'])) {
            $profile['avatar'] = $data['avatar_large'];
        } elseif (isset($data['avatar_middle'])) {
            $profile['avatar'] = $data['avatar_middle'];
        } elseif (isset($data['avatar_small'])) {
            $profile['avatar'] = $data['avatar_small'];
        } elseif (isset($data['profile_image_url'])) {
            $profile['avatar'] = $data['profile_image_url'];
        } else {
            $profile['avatar'] = '';
        }
        
        // Avatar boyutları
        if (isset($data['avatar_small'])) {
            $profile['avatar_small'] = $data['avatar_small'];
        }
        
        if (isset($data['avatar_middle'])) {
            $profile['avatar_medium'] = $data['avatar_middle'];
        }
        
        if (isset($data['avatar_large'])) {
            $profile['avatar_large'] = $data['avatar_large'];
        }
        
        // Ek bilgiler
        if (isset($data['gender'])) {
            $profile['gender'] = $data['gender'];
        }
        
        if (isset($data['birthday'])) {
            $profile['birthday'] = $data['birthday'];
        }
        
        if (isset($data['location'])) {
            $profile['location'] = $data['location'];
        }
        
        if (isset($data['description']) || isset($data['intro'])) {
            $profile['bio'] = isset($data['description']) ? $data['description'] : $data['intro'];
        }
        
        // Youku özel alanlar
        if (isset($data['status'])) {
            $profile['status'] = $data['status']; // Hesap durumu
        }
        
        if (isset($data['vip'])) {
            $profile['vip'] = $data['vip']; // VIP üyesi mi?
        }
        
        if (isset($data['level'])) {
            $profile['level'] = $data['level']; // Kullanıcı seviyesi
        }
        
        if (isset($data['followers_count'])) {
            $profile['followers'] = $data['followers_count'];
        }
        
        if (isset($data['friends_count'])) {
            $profile['following'] = $data['friends_count'];
        }
        
        if (isset($data['statuses_count'])) {
            $profile['videos_count'] = $data['statuses_count'];
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
        
        // Token yanıtından user_id'yi çek (Youku özel)
        $user_id = $this->parse_user_id($token_result['response']);
        
        // Kullanıcı bilgilerini al
        $userinfo_result = $this->get_userinfo($access_token);
        
        if (!$userinfo_result['success']) {
            return $userinfo_result;
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($userinfo_result['data']);
        
        // Eğer profile ID boşsa token'dan gelen user_id'yi kullan
        if (empty($profile['id']) && !empty($user_id)) {
            $profile['id'] = $user_id;
        }
        
        return [
            'success' => true,
            'profile' => $profile,
            'token_data' => [
                'access_token' => $access_token,
                'refresh_token' => isset($token_result['refresh_token']) ? $token_result['refresh_token'] : '',
                'expires_in' => isset($token_result['expires_in']) ? $token_result['expires_in'] : 0,
                'user_id' => $user_id
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
        return 'Youku';
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