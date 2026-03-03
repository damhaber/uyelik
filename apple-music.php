<?php
/**
 * Apple Music OAuth2 Provider
 * 
 * Apple Music, standart OAuth2'den farklı bir yapı kullanır.
 * MusicKit JS üzerinden yetkilendirme yapılır.
 * 
 * @package AI Community Engine
 */

if (!defined('ABSPATH')) {
    exit;
}

// OAuth2 base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/oauth2-base.php';
}

class AI_Community_Provider_AppleMusic extends AI_Community_OAuth2_Provider {
    
    protected $label = 'Apple Music';
    protected $name = 'Apple Music';
    public $slug = 'apple-music';
    
    // API endpoint'leri - Apple Music farklı yapı kullanır
    protected $auth_url = 'https://authorize.music.apple.com/woa';
    protected $token_url = ''; // Token doğrudan callback'te gelir
    protected $userinfo_url = 'https://api.music.apple.com/v1/me';
    
    // OAuth2 ayarları
    protected $scope = 'music-user';
    protected $token_method = 'POST';
    protected $userinfo_method = 'GET';
    
    // Apple Music özel alanlar
    private $developer_token = '';
    private $app_name = '';
    private $app_icon = '';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Apple Music özel yapılandırmalar
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id']; // Team ID veya Service ID
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret']; // Developer token (JWT)
            $this->developer_token = $config['client_secret'];
        }
        if (isset($config['app_name'])) {
            $this->app_name = $config['app_name'];
        }
        if (isset($config['app_icon'])) {
            $this->app_icon = $config['app_icon'];
        }
        
        // Apple Music için gerekli uyarı
        if (empty($this->developer_token)) {
            $this->core->log('apple_music_warning', [
                'message' => 'Apple Music için developer token (JWT) gereklidir. Apple Developer hesabınızdan oluşturun.'
            ]);
        }
    }
    
    /**
     * Ana işleyici metodu - Parametresiz!
     * Apple Music özel authorization flow'u
     */
    public function handle() {
        // GET parametrelerini al
        $params = $_GET;
        
        // Callback işleme - Apple Music'ten gelen token
        if (isset($params['code']) || isset($params['user_token'])) {
            return $this->handle_callback($params);
        }
        
        // Normal akış - özel Apple Music auth URL'ine yönlendir
        $state = wp_create_nonce('ai_community_oauth_state');
        $auth_url = $this->get_auth_url($state);
        
        // Apple Music authorization window'u açmak için JavaScript
        if (wp_doing_ajax()) {
            wp_send_json([
                'auth_url' => $auth_url,
                'state' => $state
            ]);
        } else {
            // JavaScript ile popup açılmasını sağla
            $this->render_auth_script($auth_url, $state);
        }
        exit;
    }
    
    /**
     * Apple Music authorization script'ini render et
     * 
     * @param string $auth_url Yetkilendirme URL'i
     * @param string $state State parametresi
     */
    private function render_auth_script($auth_url, $state) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Apple Music ile Giriş</title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f5f7; }
                .container { text-align: center; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                h2 { color: #1d1d1f; margin-bottom: 20px; }
                p { color: #86868b; margin-bottom: 30px; }
                .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #fa233b; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px; }
                @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
                .button { background: #fa233b; color: white; border: none; padding: 12px 30px; border-radius: 8px; font-size: 16px; cursor: pointer; text-decoration: none; display: inline-block; }
                .button:hover { background: #d41b30; }
            </style>
        </head>
        <body>
            <div class="container">
                <h2>Apple Music ile Giriş</h2>
                <div class="spinner" id="spinner"></div>
                <p id="status">Apple Music yetkilendirme penceresi açılıyor...</p>
                <button class="button" id="retry" style="display:none;">Tekrar Dene</button>
            </div>
            
            <script>
                (function() {
                    const authUrl = <?php echo json_encode($auth_url); ?>;
                    const state = <?php echo json_encode($state); ?>;
                    
                    // Popup'ı aç
                    const authWindow = window.open(
                        authUrl, 
                        'apple-music-auth', 
                        'height=650,width=650,menubar=no,resizable=no,scrollbars=no,status=no,toolbar=no'
                    );
                    
                    if (!authWindow) {
                        document.getElementById('spinner').style.display = 'none';
                        document.getElementById('status').innerHTML = 'Popup engelleyici açık olabilir. Lütfen popup\'lara izin verin.';
                        document.getElementById('retry').style.display = 'inline-block';
                        document.getElementById('retry').onclick = () => window.location.reload();
                        return;
                    }
                    
                    // Message event listener - Apple Music'ten gelen mesajları dinle
                    window.addEventListener('message', function(event) {
                        if (event.source !== authWindow) return;
                        
                        console.log('Apple Music message:', event.data);
                        
                        // thirdPartyInfo isteği - MusicKit JS'nin istediği bilgiler
                        if (event.data.method === 'thirdPartyInfo') {
                            const thirdPartyInfo = {
                                thirdPartyIconURL: <?php echo json_encode($this->app_icon ?: home_url('/favicon.ico')); ?>,
                                thirdPartyName: <?php echo json_encode($this->app_name ?: get_bloginfo('name')); ?>,
                                thirdPartyToken: <?php echo json_encode($this->developer_token); ?>
                            };
                            
                            authWindow.postMessage({
                                id: event.data.id,
                                jsonrpc: event.data.jsonrpc,
                                result: JSON.stringify(thirdPartyInfo)
                            }, '*');
                        }
                        
                        // authorize event - kullanıcı yetkilendirme yaptı
                        if (event.data.method === 'authorize' && event.data.params && event.data.params[0]) {
                            const userToken = event.data.params[0];
                            
                            // Token'ı ana sayfaya yönlendir
                            window.location.href = <?php echo json_encode(add_query_arg([
                                'code' => 'apple_token',
                                'user_token' => 'TOKEN_PLACEHOLDER',
                                'state' => $state
                            ], home_url('/login'))); ?> + '&user_token=' + encodeURIComponent(userToken);
                        }
                        
                        // decline event - kullanıcı reddetti
                        if (event.data.method === 'decline') {
                            document.getElementById('spinner').style.display = 'none';
                            document.getElementById('status').innerHTML = 'Yetkilendirme reddedildi.';
                            document.getElementById('retry').style.display = 'inline-block';
                            authWindow.close();
                        }
                        
                        // close event - pencere kapatıldı
                        if (event.data.method === 'close') {
                            document.getElementById('spinner').style.display = 'none';
                            document.getElementById('status').innerHTML = 'Yetkilendirme penceresi kapatıldı.';
                            document.getElementById('retry').style.display = 'inline-block';
                        }
                    });
                    
                    // Popup kapanma kontrolü
                    const checkClosed = setInterval(function() {
                        if (authWindow.closed) {
                            clearInterval(checkClosed);
                            document.getElementById('spinner').style.display = 'none';
                            document.getElementById('status').innerHTML = 'Yetkilendirme penceresi kapatıldı.';
                            document.getElementById('retry').style.display = 'inline-block';
                        }
                    }, 500);
                    
                })();
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Callback işleme
     * 
     * @param array $params Callback parametreleri
     */
    protected function handle_callback($params) {
        $code = isset($params['code']) ? $params['code'] : '';
        $user_token = isset($params['user_token']) ? $params['user_token'] : '';
        $state = isset($params['state']) ? $params['state'] : '';
        
        // State doğrulama
        if (!wp_verify_nonce($state, 'ai_community_oauth_state')) {
            $this->core->log('apple_music_invalid_state', ['state' => $state]);
            wp_redirect(home_url('/login?error=invalid_state'));
            exit;
        }
        
        // Token kontrolü
        if (empty($user_token)) {
            $this->core->log('apple_music_no_token', []);
            wp_redirect(home_url('/login?error=token_error'));
            exit;
        }
        
        // Token verilerini oluştur
        $token_data = [
            'access_token' => $user_token,
            'refresh_token' => '', // Apple Music refresh token vermez
            'expires_in' => 3600,
            'token_type' => 'Bearer'
        ];
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($user_token);
        
        if (is_wp_error($userinfo)) {
            $this->core->log('apple_music_userinfo_error', [
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
            $this->core->log('apple_music_user_creation_error', [
                'error' => is_wp_error($user_id) ? $user_id->get_error_message() : 'Unknown error'
            ]);
            wp_redirect(home_url('/login?error=user_creation_failed'));
            exit;
        }
        
        // WordPress oturumu başlat
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Provider kullanıcısını kaydet
        $this->save_provider_user($user_id, $profile, $token_data);
        
        // Başarılı giriş
        $redirect_url = apply_filters('ai_community_login_redirect', home_url('/dashboard'), $user_id, 'apple-music');
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
     * Provider etiketini döndür
     */
    public function get_label() {
        return $this->name;
    }
    
    /**
     * Provider'ın aktif olup olmadığını kontrol et
     */
    public function is_active() {
        return !empty($this->client_id) && !empty($this->developer_token) && !empty($this->app_name);
    }
    
    /**
     * Provider durumunu döndür
     */
    public function get_status() {
        return [
            'active' => $this->is_active(),
            'client_id' => !empty($this->client_id),
            'developer_token' => !empty($this->developer_token),
            'app_name' => !empty($this->app_name),
            'app_icon' => !empty($this->app_icon),
            'warning' => 'Apple Music için Apple Developer hesabı ve JWT token gereklidir.'
        ];
    }
    
    /**
     * Yetkilendirme URL'ini al
     * Apple Music özel URL oluşturma
     * 
     * @param string $state State parametresi
     * @return string
     */
    public function get_auth_url($state = '') {
        // Third party info oluştur
        $third_party_info = [
            'thirdPartyIconURL' => $this->app_icon ?: home_url('/favicon.ico'),
            'thirdPartyName' => $this->app_name ?: get_bloginfo('name'),
            'thirdPartyToken' => $this->developer_token
        ];
        
        // Base64 encode
        $b64_info = base64_encode(json_encode($third_party_info));
        
        // URL parametreleri
        $params = [
            'a' => $b64_info,
            'referrer' => home_url('/'),
            'app' => 'music',
            'p' => 'subscribe'
        ];
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    /**
     * Token URL'ini al (Apple Music'te kullanılmaz)
     */
    public function get_token_url() {
        return '';
    }
    
    /**
     * Kullanıcı bilgi URL'ini al
     */
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    /**
     * Scope'ları döndür
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Token parametrelerini al (Apple Music'te kullanılmaz)
     * 
     * @param string $code Yetkilendirme kodu
     * @return array
     */
    public function get_token_params($code) {
        return [];
    }
    
    /**
     * Token istek metodunu al
     */
    public function get_token_method() {
        return $this->token_method;
    }
    
    /**
     * Token HTTP headers'larını döndür
     */
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];
    }
    
    /**
     * Access token parse et
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_access_token($response) {
        return $response['access_token'] ?? '';
    }
    
    /**
     * Refresh token parse et
     * 
     * @param array $response API yanıtı
     * @return string
     */
    public function parse_refresh_token($response) {
        return ''; // Apple Music refresh token sağlamaz
    }
    
    /**
     * Expires in parse et
     * 
     * @param array $response API yanıtı
     * @return int
     */
    public function parse_expires_in($response) {
        return $response['expires_in'] ?? 3600;
    }
    
    /**
     * Kullanıcı bilgi istek metodunu al
     */
    public function get_userinfo_method() {
        return $this->userinfo_method;
    }
    
    /**
     * Kullanıcı bilgi header'larını al
     * 
     * @param string $access_token Access token
     * @return array
     */
    public function get_userinfo_headers($access_token) {
        return [
            'Authorization: Bearer ' . $access_token,
            'Music-User-Token: ' . $access_token,
            'Accept: application/json'
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
     * Kullanıcı bilgilerini al
     * Apple Music API çağrısı
     * 
     * @param string $access_token Access token
     * @return array|WP_Error
     */
    public function get_userinfo($access_token) {
        $url = $this->get_userinfo_url();
        $headers = $this->get_userinfo_headers($access_token);
        
        $args = [
            'method' => 'GET',
            'headers' => $headers,
            'timeout' => 30
        ];
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Apple Music API hata kontrolü
        if (isset($data['errors']) && !empty($data['errors'])) {
            return new WP_Error('apple_music_api_error', $data['errors'][0]['title'] ?? 'Unknown error');
        }
        
        return $data['data'][0] ?? $data;
    }
    
    /**
     * Access token al (Apple Music'te token callback'ten gelir)
     * 
     * @param string $code Yetkilendirme kodu
     * @return array|WP_Error
     */
    public function get_access_token($code) {
        // Apple Music'te token doğrudan callback'ten gelir
        // Bu metod override edildi, handle_callback'te token_data oluşturuluyor
        return new WP_Error('apple_music_no_token_endpoint', 'Apple Music uses direct token from callback');
    }
    
    /**
     * Kullanıcı bilgilerini profile map et
     * 
     * @param array $userinfo API'den gelen kullanıcı bilgileri
     * @return array
     */
    public function map_userinfo_to_profile($userinfo) {
        if (!is_array($userinfo) || empty($userinfo['id'])) {
            // Mock veri döndür
            return [
                'id' => 'apple_' . uniqid(),
                'email' => '',
                'name' => 'Apple Music User',
                'first_name' => 'Apple',
                'last_name' => 'User',
                'username' => 'apple_user',
                'avatar' => ''
            ];
        }
        
        // Apple Music'ten gelen verileri map et
        $attributes = $userinfo['attributes'] ?? $userinfo;
        
        // İsim bilgilerini ayır
        $name = $attributes['name'] ?? '';
        $first_name = $attributes['firstName'] ?? '';
        $last_name = $attributes['lastName'] ?? '';
        
        if (empty($first_name) && !empty($name)) {
            $name_parts = explode(' ', $name, 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
        }
        
        return [
            'id' => (string)($userinfo['id'] ?? $attributes['playlistId'] ?? uniqid('apple_')),
            'email' => $attributes['email'] ?? $attributes['playlistId'] . '@apple.local',
            'email_verified' => true,
            'name' => $name ?: 'Apple Music User',
            'first_name' => $first_name,
            'last_name' => $last_name,
            'username' => $attributes['playlistId'] ?? 'apple_user',
            'avatar' => $attributes['artwork']['url'] ?? '',
            'apple_id' => $attributes['appleId'] ?? '',
            'developer_token' => $this->developer_token
        ];
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return int|WP_Error
     */
    public function find_or_create_user($profile) {
        global $wpdb;
        
        if (empty($profile['id'])) {
            return new WP_Error('missing_id', 'Provider ID missing');
        }
        
        // Provider ID'ye göre ara
        $table = $wpdb->prefix . 'ai_community_provider_users';
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id FROM $table WHERE provider = %s AND provider_id = %s",
            $this->slug,
            $profile['id']
        ));
        
        if ($user) {
            return $user->user_id;
        }
        
        // Email'e göre ara
        if (!empty($profile['email']) && is_email($profile['email'])) {
            $existing_user = get_user_by('email', $profile['email']);
            if ($existing_user) {
                return $existing_user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = !empty($profile['email']) && is_email($profile['email']) 
            ? $profile['email'] 
            : $profile['id'] . '@apple.local';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Meta verileri kaydet
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $profile['name'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name']
        ]);
        
        if (!empty($profile['apple_id'])) {
            update_user_meta($user_id, 'apple_id', $profile['apple_id']);
        }
        
        return $user_id;
    }
    
    /**
     * Provider kullanıcısını kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Kullanıcı profili
     * @param array $token_data Token verileri
     */
    protected function save_provider_user($user_id, $profile, $token_data) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ai_community_provider_users';
        $expires_at = !empty($token_data['expires_in']) 
            ? date('Y-m-d H:i:s', time() + $token_data['expires_in'])
            : null;
        
        $data = [
            'user_id' => $user_id,
            'provider' => $this->slug,
            'provider_id' => $profile['id'],
            'email' => $profile['email'] ?? '',
            'name' => $profile['name'] ?? '',
            'username' => $profile['username'] ?? '',
            'avatar' => $profile['avatar'] ?? '',
            'access_token' => $token_data['access_token'] ?? '',
            'refresh_token' => '',
            'expires_at' => $expires_at,
            'raw_data' => json_encode($profile),
            'updated_at' => current_time('mysql')
        ];
        
        $wpdb->replace($table, $data);
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return string
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        if (!empty($profile['username'])) {
            $base = sanitize_user($profile['username'], true);
        } elseif (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } elseif (!empty($profile['email'])) {
            $base = sanitize_user(explode('@', $profile['email'])[0], true);
        } elseif (!empty($profile['id'])) {
            $base = 'apple_' . substr($profile['id'], -8);
        } else {
            $base = 'apple_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'apple';
        }
        
        $username = $base;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
}