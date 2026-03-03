<?php
/**
 * AI Community Provider: Meituan (美团)
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
 * Meituan OAuth Provider sınıfı
 */
class AI_Community_Provider_Meituan extends AI_Community_OAuth2_Provider {
    
    /**
     * Yapılandırma
     *
     * @var array
     */
    protected $config = [];
    
    /**
     * Provider slug
     *
     * @var string
     */
    protected $slug = 'meituan';
    
    /**
     * Provider adı
     *
     * @var string
     */
    protected $name = 'Meituan (美团)';
    
    protected $label = 'Meituan (美团)';
    
    /**
     * İzin kapsamı (scope)
     *
     * @var string
     */
    protected $scope = 'user:profile';
    
    /**
     * Yetkilendirme URL'i
     *
     * @var string
     */
    protected $auth_url = 'https://open.meituan.com/oauth/authorize';
    
    /**
     * Token URL'i
     *
     * @var string
     */
    protected $token_url = 'https://open.meituan.com/oauth/token';
    
    /**
     * Kullanıcı bilgisi URL'i
     *
     * @var string
     */
    protected $userinfo_url = 'https://open.meituan.com/api/user/info';
    
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
     * Yönlendirme URI'i
     *
     * @var string
     */
    protected $redirect_uri = '';
    
    /**
     * Constructor
     *
     * @param string $id Provider ID
     * @param array $config Yapılandırma
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        // Parent constructor'ı çağır (3 parametre ile)
        parent::__construct($id, $config, $core);
        
        $this->config = $config;
        $this->slug = $id;
        
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
    
    public function get_type() {
        return 'oauth';
    }
    
    public function get_name() {
        return $this->label;
    }
    
    public function get_slug() {
        return $this->slug;
    }
    
    /**
     * Yetkilendirme URL'ini oluştur - state parametreli
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
            'state' => $state
        ];
        
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
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json'
        ];
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
        return [];
    }
    
    /**
     * Token yanıtından access token'ı çıkar
     *
     * @param string|array $response Token yanıtı
     * @return string|false Access token veya false
     */
    public function parse_access_token($response) {
        $data = is_string($response) ? json_decode($response, true) : $response;
        
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }
        
        if (isset($data['data']['access_token'])) {
            return $data['data']['access_token'];
        }
        
        return '';
    }
    
    /**
     * Token yanıtından refresh token'ı çıkar
     *
     * @param string|array $response Token yanıtı
     * @return string|false Refresh token veya false
     */
    public function parse_refresh_token($response) {
        $data = is_string($response) ? json_decode($response, true) : $response;
        
        if (isset($data['refresh_token'])) {
            return $data['refresh_token'];
        }
        
        if (isset($data['data']['refresh_token'])) {
            return $data['data']['refresh_token'];
        }
        
        return '';
    }
    
    /**
     * Token yanıtından expire süresini çıkar (saniye)
     *
     * @param string|array $response Token yanıtı
     * @return int|false Expire süresi veya false
     */
    public function parse_expires_in($response) {
        $data = is_string($response) ? json_decode($response, true) : $response;
        
        if (isset($data['expires_in'])) {
            return (int) $data['expires_in'];
        }
        
        if (isset($data['data']['expires_in'])) {
            return (int) $data['data']['expires_in'];
        }
        
        return 3600;
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
     * Handle metodu - PARAMETRESİZ
     * OAuth2 akışını yönetir
     */
    public function handle() {
        if (!$this->is_active()) {
            $this->core->log('meituan_inactive', ['provider' => $this->id]);
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
     * Meituan özel callback handler - PARAMETRELİ ($request)
     */
    protected function handle_callback($request) {
        $code  = isset($request['code']) ? sanitize_text_field(wp_unslash($request['code'])) : '';
        $state = isset($request['state']) ? sanitize_text_field(wp_unslash($request['state'])) : '';

        if (!wp_verify_nonce($state, 'ai_community_oauth_' . $this->id)) {
            $this->core->log('meituan_state_invalid', ['provider' => $this->id]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        $token = $this->exchange_code_for_token($code);

        if (is_wp_error($token)) {
            $this->core->log('meituan_token_error', [
                'provider' => $this->id,
                'error'    => $token->get_error_message(),
            ]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        $user_profile = $this->fetch_userinfo($token);

        if (is_wp_error($user_profile)) {
            $this->core->log('meituan_userinfo_error', [
                'provider' => $this->id,
                'error'    => $user_profile->get_error_message(),
            ]);
            wp_safe_redirect($this->get_endpoint_url());
            exit;
        }

        $wp_user_id = $this->find_or_create_user($user_profile);

        if (is_wp_error($wp_user_id) || !$wp_user_id) {
            $this->core->log('meituan_wp_user_error', [
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

        $this->core->log('meituan_login_success', [
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
        
        $this->core->log('meituan_auth_start', [
            'provider' => $this->id,
            'auth_url' => $auth_url
        ]);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Kullanıcı bilgisini profile dönüştür - PUBLIC
     *
     * @param string|array $userinfo Kullanıcı bilgisi
     * @return array Profil verisi
     */
    public function map_userinfo_to_profile($userinfo) {
        $data = is_string($userinfo) ? json_decode($userinfo, true) : $userinfo;
        
        // Meituan API yanıt yapısına göre mapping
        $user_data = isset($data['data']) ? $data['data'] : $data;
        
        $profile = [
            'id'      => '',
            'email'   => '',
            'name'    => '',
            'avatar'  => '',
            'gender'  => 'other',
            'phone'   => '',
            'birthday' => ''
        ];
        
        // ID
        if (isset($user_data['user_id'])) {
            $profile['id'] = (string) $user_data['user_id'];
        } elseif (isset($user_data['open_id'])) {
            $profile['id'] = (string) $user_data['open_id'];
        } elseif (isset($user_data['id'])) {
            $profile['id'] = (string) $user_data['id'];
        }
        
        // İsim
        if (isset($user_data['nickname'])) {
            $profile['name'] = $user_data['nickname'];
        } elseif (isset($user_data['name'])) {
            $profile['name'] = $user_data['name'];
        } else {
            $profile['name'] = 'Meituan User';
        }
        
        // Email (Meituan genellikle email sağlamaz)
        if (isset($user_data['email'])) {
            $profile['email'] = $user_data['email'];
        }
        
        // Avatar
        if (isset($user_data['avatar'])) {
            $profile['avatar'] = $user_data['avatar'];
        } elseif (isset($user_data['avatar_url'])) {
            $profile['avatar'] = $user_data['avatar_url'];
        } elseif (isset($user_data['profile_image'])) {
            $profile['avatar'] = $user_data['profile_image'];
        }
        
        // Ek bilgiler
        if (isset($user_data['gender'])) {
            $profile['gender'] = $this->normalize_gender($user_data['gender']);
        }
        
        if (isset($user_data['mobile'])) {
            $profile['phone'] = $user_data['mobile'];
        }
        
        if (isset($user_data['birthday'])) {
            $profile['birthday'] = $user_data['birthday'];
        }
        
        return $profile;
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
        $email = $profile['email'] ?: $username . '@meituan.user';
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
            $username = 'meituan_user';
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
        if ($gender === 1 || $gender === '男' || $gender === 'male' || $gender === 'M') return 'male';
        if ($gender === 2 || $gender === '女' || $gender === 'female' || $gender === 'F') return 'female';
        return 'other';
    }
}