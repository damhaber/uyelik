<?php
/**
 * AI Community Provider: Mail.ru
 * Rusya'nın en popüler email ve sosyal medya platformu
 * 
 * @package AI_Community
 */

// Doğrudan erişimi engelle
if (!defined('ABSPATH')) {
    exit;
}

// Base class'ları kontrol et
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

/**
 * Mail.ru OAuth Provider sınıfı
 */
class AI_Community_Provider_Mailru extends AI_Community_OAuth2_Provider {
    
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
    public $slug = 'mailru';
    
    /**
     * Provider adı
     *
     * @var string
     */
    protected $provider_name = 'Mail.ru';
    
    /**
     * Client ID
     *
     * @var string
     */
    protected $client_id = '';
    
    /**
     * Client Secret
     *
     * @var string
     */
    protected $client_secret = '';
    
    /**
     * Redirect URI
     *
     * @var string
     */
    protected $redirect_uri = '';
    
    /**
     * İzin kapsamı (scope)
     * Mail.ru scopes: https://api.mail.ru/docs/guides/oauth/sites/
     *
     * @var string
     */
    protected $scope = 'userinfo';
    
    /**
     * Yetkilendirme URL'i
     *
     * @var string
     */
    protected $auth_url = 'https://oauth.mail.ru/login';
    
    /**
     * Token URL'i
     *
     * @var string
     */
    protected $token_url = 'https://oauth.mail.ru/token';
    
    /**
     * Kullanıcı bilgisi URL'i
     *
     * @var string
     */
    protected $userinfo_url = 'https://oauth.mail.ru/userinfo';
    
    /**
     * Provider etkin mi
     *
     * @var bool
     */
    protected $enabled = false;
    
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
        } else {
            $this->redirect_uri = home_url('/ai-community/?provider=mailru&action=callback');
        }
        
        if (isset($config['scope'])) {
            $this->scope = $config['scope'];
        }
        
        if (isset($config['enabled'])) {
            $this->enabled = (bool) $config['enabled'];
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
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->get_scope()
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        } else {
            $params['state'] = wp_create_nonce('mailru_oauth_' . get_current_user_id());
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
     * İzin kapsamını getir
     *
     * @return string Scope
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Token isteği için gerekli parametreler
     *
     * @param string $code Yetkilendirme kodu
     * @return array Token parametreleri
     */
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirect_uri,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret
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
            'Authorization' => 'Bearer ' . $access_token
        ];
    }
    
    /**
     * Kullanıcı bilgisi isteği için parametreler (GET için boş)
     *
     * @param string $access_token Access token
     * @return array Parametreler
     */
    public function get_userinfo_params($access_token) {
        return [];
    }
    
    /**
     * Kullanıcı bilgisi isteği için parametreler (token ile birlikte)
     *
     * @param string $access_token Access token
     * @return array Parametreler
     */
    public function get_userinfo_params_with_token($access_token) {
        return $this->get_userinfo_params($access_token);
    }
    
    /**
     * Kullanıcı bilgisini profile dönüştür
     * Mail.ru API yanıtı: https://api.mail.ru/userinfo
     *
     * @param array $userinfo Kullanıcı bilgisi
     * @return array Profil verisi
     */
    public function map_userinfo_to_profile($userinfo) {
        $profile = [];
        
        // ID
        $profile['id'] = isset($userinfo['id']) ? (string) $userinfo['id'] : '';
        
        // Email
        $profile['email'] = isset($userinfo['email']) ? $userinfo['email'] : '';
        
        // İsim
        if (isset($userinfo['first_name']) && isset($userinfo['last_name'])) {
            $profile['name'] = $userinfo['first_name'] . ' ' . $userinfo['last_name'];
        } elseif (isset($userinfo['first_name'])) {
            $profile['name'] = $userinfo['first_name'];
        } elseif (isset($userinfo['nickname'])) {
            $profile['name'] = $userinfo['nickname'];
        } else {
            $profile['name'] = 'Mail.ru User';
        }
        
        // Avatar - Mail.ru image varsa
        if (isset($userinfo['image'])) {
            $profile['avatar'] = $userinfo['image'];
        } elseif (isset($userinfo['avatar'])) {
            $profile['avatar'] = $userinfo['avatar'];
        } else {
            $profile['avatar'] = '';
        }
        
        // Ek bilgiler
        if (isset($userinfo['first_name'])) {
            $profile['first_name'] = $userinfo['first_name'];
        }
        
        if (isset($userinfo['last_name'])) {
            $profile['last_name'] = $userinfo['last_name'];
        }
        
        if (isset($userinfo['birthday'])) {
            $profile['birthday'] = $userinfo['birthday'];
        }
        
        if (isset($userinfo['sex'])) {
            $profile['gender'] = ($userinfo['sex'] == 1) ? 'female' : 'male';
        }
        
        if (isset($userinfo['locale'])) {
            $profile['locale'] = $userinfo['locale'];
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
            if (!wp_verify_nonce($state, 'mailru_oauth_' . get_current_user_id())) {
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
        
        // Kullanıcı bilgilerini al
        $userinfo_result = $this->get_userinfo($access_token);
        
        if (!$userinfo_result['success']) {
            return $userinfo_result;
        }
        
        $userinfo = $userinfo_result['data'];
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($userinfo);
        
        return [
            'success' => true,
            'profile' => $profile,
            'token_data' => [
                'access_token' => $access_token,
                'refresh_token' => isset($token_result['refresh_token']) ? $token_result['refresh_token'] : '',
                'expires_in' => isset($token_result['expires_in']) ? $token_result['expires_in'] : 0
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
        $state = wp_create_nonce('mailru_oauth_' . get_current_user_id());
        
        $auth_url = $this->get_auth_url($state);
        
        return [
            'success' => true,
            'redirect_url' => $auth_url
        ];
    }
    
    /**
     * Provider adını getir
     *
     * @return string Provider adı
     */
    public function get_name() {
        return $this->provider_name;
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
        
        if (!empty($profile['first_name'])) {
            update_user_meta($user_id, 'first_name', $profile['first_name']);
        }
        
        if (!empty($profile['last_name'])) {
            update_user_meta($user_id, 'last_name', $profile['last_name']);
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
    
    /**
     * Provider aktif mi?
     *
     * @return bool
     */
    public function is_active() {
        return $this->enabled && !empty($this->client_id) && !empty($this->client_secret);
    }
    
    /**
     * Provider label (gösterim adı)
     *
     * @return string
     */
    public function get_label() {
        return $this->provider_name;
    }
    
    /**
     * Provider config getir
     *
     * @return array
     */
    public function get_config() {
        return [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret ? '***' : '',
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->scope,
            'enabled' => $this->enabled
        ];
    }
    
    /**
     * Provider status getir
     *
     * @return string
     */
    public function get_status() {
        if (!$this->enabled) {
            return 'inactive';
        }
        
        if (empty($this->client_id) || empty($this->client_secret)) {
            return 'missing_config';
        }
        
        return 'ready';
    }
}