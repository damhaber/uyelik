<?php
/**
 * AI Community Provider: LINE
 * Japonya, Tayvan, Tayland'ın en popüler mesajlaşma platformu
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
 * LINE OAuth Provider sınıfı
 */
class AI_Community_Provider_Line extends AI_Community_OAuth2_Provider {
    
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
    public $slug = 'line';
    
    /**
     * Provider adı
     *
     * @var string
     */
    protected $provider_name = 'LINE';
    
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
     *
     * @var string
     */
    protected $scope = 'profile openid email';
    
    /**
     * Yetkilendirme URL'i
     *
     * @var string
     */
    protected $auth_url = 'https://access.line.me/oauth2/v2.1/authorize';
    
    /**
     * Token URL'i
     *
     * @var string
     */
    protected $token_url = 'https://api.line.me/oauth2/v2.1/token';
    
    /**
     * Kullanıcı bilgisi URL'i
     *
     * @var string
     */
    protected $userinfo_url = 'https://api.line.me/v2/profile';
    
    /**
     * Bot prompt
     *
     * @var string
     */
    protected $bot_prompt = 'normal';
    
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
        if (isset($config['channel_id'])) {
            $this->client_id = $config['channel_id'];
        } elseif (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        
        if (isset($config['channel_secret'])) {
            $this->client_secret = $config['channel_secret'];
        } elseif (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        
        if (isset($config['redirect_uri'])) {
            $this->redirect_uri = $config['redirect_uri'];
        } else {
            $this->redirect_uri = home_url('/ai-community/?provider=line');
        }
        
        if (isset($config['scope'])) {
            $this->scope = $config['scope'];
        }
        
        if (isset($config['bot_prompt'])) {
            $this->bot_prompt = $config['bot_prompt'];
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
            'scope' => $this->get_scope(),
            'bot_prompt' => $this->bot_prompt
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        } else {
            $params['state'] = wp_create_nonce('line_oauth_' . get_current_user_id());
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
     * Token yanıtından ID token'ı çıkar (LINE özel)
     *
     * @param array $response Token yanıtı
     * @return string|false ID token veya false
     */
    public function parse_id_token($response) {
        if (isset($response['id_token'])) {
            return $response['id_token'];
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
     * ID Token'dan kullanıcı bilgilerini çöz
     *
     * @param string $id_token ID Token
     * @return array|false Kullanıcı bilgileri veya false
     */
    protected function decode_id_token($id_token) {
        $parts = explode('.', $id_token);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if ($payload && is_array($payload)) {
                return $payload;
            }
        }
        
        return false;
    }
    
    /**
     * Kullanıcı bilgisini profile dönüştür
     *
     * @param array $userinfo Kullanıcı bilgisi
     * @return array Profil verisi
     */
    public function map_userinfo_to_profile($userinfo) {
        $profile = [];
        
        // ID Token'dan gelen bilgiler veya API'den gelen bilgiler
        $data = $userinfo;
        
        // ID (sub veya userId)
        if (isset($data['sub'])) {
            $profile['id'] = $data['sub'];
        } elseif (isset($data['userId'])) {
            $profile['id'] = $data['userId'];
        } else {
            $profile['id'] = '';
        }
        
        // İsim
        if (isset($data['name'])) {
            $profile['name'] = $data['name'];
        } elseif (isset($data['displayName'])) {
            $profile['name'] = $data['displayName'];
        } else {
            $profile['name'] = 'LINE User';
        }
        
        // Email
        $profile['email'] = isset($data['email']) ? $data['email'] : '';
        
        // Avatar
        if (isset($data['picture'])) {
            $profile['avatar'] = $data['picture'];
        } elseif (isset($data['pictureUrl'])) {
            $profile['avatar'] = $data['pictureUrl'];
        } else {
            $profile['avatar'] = '';
        }
        
        // Status message (LINE özel)
        if (isset($data['statusMessage'])) {
            $profile['status_message'] = $data['statusMessage'];
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
            if (!wp_verify_nonce($state, 'line_oauth_' . get_current_user_id())) {
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
        $id_token = isset($token_result['response']['id_token']) ? $token_result['response']['id_token'] : '';
        
        // Kullanıcı bilgilerini al (önce ID token'dan dene)
        $userinfo = [];
        
        if (!empty($id_token)) {
            $decoded = $this->decode_id_token($id_token);
            if ($decoded) {
                $userinfo = $decoded;
            }
        }
        
        // ID token yoksa veya çözülemediyse API'den al
        if (empty($userinfo)) {
            $userinfo_result = $this->get_userinfo($access_token);
            
            if (!$userinfo_result['success']) {
                return $userinfo_result;
            }
            
            $userinfo = $userinfo_result['data'];
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($userinfo);
        
        return [
            'success' => true,
            'profile' => $profile,
            'token_data' => [
                'access_token' => $access_token,
                'refresh_token' => isset($token_result['refresh_token']) ? $token_result['refresh_token'] : '',
                'expires_in' => isset($token_result['expires_in']) ? $token_result['expires_in'] : 0,
                'id_token' => $id_token
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
        $state = wp_create_nonce('line_oauth_' . get_current_user_id());
        
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
     * Panel ayarlarını render et
     */
    public function render_settings() {
        ?>
        <div class="line-settings">
            <h4>LINE OAuth Ayarları</h4>
            <p class="description">LINE Developers Console'dan aldığınız bilgileri girin</p>
            
            <p>
                <label>Channel ID:</label><br>
                <input type="text" 
                       name="providers[line][channel_id]" 
                       value="<?php echo esc_attr($this->client_id); ?>" 
                       class="regular-text"
                       placeholder="1234567890">
            </p>
            
            <p>
                <label>Channel Secret:</label><br>
                <input type="password" 
                       name="providers[line][channel_secret]" 
                       value="<?php echo esc_attr($this->client_secret); ?>" 
                       class="regular-text">
            </p>
            
            <p>
                <label>Redirect URI:</label><br>
                <input type="text" 
                       name="providers[line][redirect_uri]" 
                       value="<?php echo esc_attr($this->redirect_uri); ?>" 
                       class="regular-text" readonly>
                <p class="description">Bu URL'yi LINE Developers Console'da kaydedin</p>
            </p>
            
            <p>
                <label>Bot Prompt:</label><br>
                <select name="providers[line][bot_prompt]">
                    <option value="normal" <?php selected($this->bot_prompt, 'normal'); ?>>Normal</option>
                    <option value="aggressive" <?php selected($this->bot_prompt, 'aggressive'); ?>>Agresif</option>
                    <option value="none" <?php selected($this->bot_prompt, 'none'); ?>>Yok</option>
                </select>
                <p class="description">Bot ekleme prompt'u (normal/aggressive/none)</p>
            </p>
            
            <p>
                <label>Scope:</label><br>
                <input type="text" 
                       name="providers[line][scope]" 
                       value="<?php echo esc_attr($this->scope); ?>" 
                       class="regular-text"
                       placeholder="profile openid email">
                <p class="description">Boşlukla ayırarak birden fazla scope girebilirsiniz</p>
            </p>
            
            <p>
                <label>
                    <input type="checkbox" name="providers[line][enabled]" value="1" <?php checked($this->enabled); ?>>
                    LINE ile giriş aktif
                </label>
            </p>
            
            <?php if ($this->enabled && !empty($this->client_id)): ?>
            <p>
                <button type="button" class="button test-line-connection" data-nonce="<?php echo wp_create_nonce('test_line'); ?>">
                    🔌 LINE Bağlantı Testi
                </button>
                <span class="test-result" style="margin-left:10px;"></span>
            </p>
            
            <script>
            jQuery(document).ready(function($) {
                $('.test-line-connection').on('click', function() {
                    var $btn = $(this);
                    var $result = $btn.siblings('.test-result');
                    
                    $btn.prop('disabled', true);
                    $result.html('⏳ Test ediliyor...');
                    
                    $.post(ajaxurl, {
                        action: 'ai_community_test_provider',
                        provider: 'line',
                        _ajax_nonce: $btn.data('nonce')
                    }, function(response) {
                        if (response.success) {
                            $result.html('✅ LINE bağlantısı başarılı!');
                        } else {
                            $result.html('❌ Hata: ' + response.data.message);
                        }
                        $btn.prop('disabled', false);
                    });
                });
            });
            </script>
            <?php endif; ?>
        </div>
        <?php
    }
}