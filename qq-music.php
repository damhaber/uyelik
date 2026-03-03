<?php
/**
 * QQ Music OAuth2 Provider
 * 
 * QQ Music (QQ音乐) - Çin'in en büyük müzik platformu
 * Tencent Music Entertainment Group
 */

class AI_Community_Provider_QQ_Music extends AI_Community_OAuth2_Provider {
    
    public $slug = 'qq-music';
    protected $name = 'QQ Music';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider config
     * @param object $core Core framework instance
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // QQ Music API endpoints
        $this->oauth_domain = 'https://graph.qq.com';
        $this->api_domain = 'https://openapi.tencent.com';
        
        // OAuth2 endpoints
        $this->auth_url = $this->oauth_domain . '/oauth2.0/authorize';
        $this->token_url = $this->oauth_domain . '/oauth2.0/token';
        
        // API endpoints
        $this->userinfo_url = $this->api_domain . '/v3/user/get_info';
        $this->music_info_url = $this->api_domain . '/v3/music/user_info';
        
        // Scope'lar
        $this->scope = 'get_user_info,list_playlist,get_music_info';
        
        // Response type
        $this->response_type = 'code';
        $this->grant_type = 'authorization_code';
        
        // Format
        $this->format = 'json';
    }
    
    /**
     * Ana handler
     * Tüm istekler buraya gelir (parametresiz)
     */
    public function handle() {
        $params = array_merge($_GET, $_POST);
        
        // Hata varsa
        if (isset($params['error'])) {
            $error = $params['error_description'] ?? $params['error'];
            return $this->show_error('QQ Music授权失败 / Yetkilendirme başarısız: ' . $error);
        }
        
        // Callback (code ile döndüyse)
        if (isset($params['code'])) {
            return $this->handle_callback($params);
        }
        
        // OpenID kontrolü (QQ özel)
        if (isset($params['openid'])) {
            return $this->handle_openid_callback($params);
        }
        
        // İlk giriş - OAuth2 yönlendirmesi
        $state = wp_create_nonce('ai_community_qq_music_state');
        $auth_url = $this->get_auth_url($state);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Callback işleme
     * 
     * @param array $params İstek parametreleri
     * @return string HTML çıktı veya redirect
     */
    public function handle_callback($params) {
        $code = $params['code'];
        $state = $params['state'] ?? '';
        
        // State kontrolü
        if (!wp_verify_nonce($state, 'ai_community_qq_music_state')) {
            return $this->show_error('状态验证失败 / State doğrulaması başarısız.');
        }
        
        // Access token al
        $token_response = $this->get_access_token($code);
        
        if (is_wp_error($token_response)) {
            return $this->show_error('获取访问令牌失败 / Access token alınamadı: ' . $token_response->get_error_message());
        }
        
        // QQ özel: Access token'dan sonra openid alınması gerekiyor
        $openid_response = $this->get_openid($token_response['access_token']);
        
        if (is_wp_error($openid_response)) {
            return $this->show_error('获取OpenID失败 / OpenID alınamadı: ' . $openid_response->get_error_message());
        }
        
        // Token ve openid'i birleştir
        $token_data = array_merge($token_response, $openid_response);
        
        // Kullanıcı bilgilerini al
        $user_info = $this->get_userinfo($token_data);
        
        if (is_wp_error($user_info)) {
            return $this->show_error('获取用户信息失败 / Kullanıcı bilgileri alınamadı: ' . $user_info->get_error_message());
        }
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($user_info, $token_data);
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id) {
            return $this->show_error('用户创建失败 / Kullanıcı oluşturulamadı.');
        }
        
        // Oturum aç
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        // Müzik tercihlerini kaydet
        if (!empty($profile['music_preferences'])) {
            update_user_meta($user_id, 'qq_music_preferences', $profile['music_preferences']);
        }
        
        // Log
        $this->core->log('qq_music_login', [
            'user_id' => $user_id,
            'openid' => $profile['id']
        ]);
        
        // Yönlendir
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * OpenID callback (QQ özel)
     * 
     * @param array $params İstek parametreleri
     */
    public function handle_openid_callback($params) {
        // OpenID ile kullanıcı bilgilerini al
        $openid = $params['openid'];
        $access_token = $params['access_token'] ?? '';
        
        if (empty($access_token)) {
            return $this->show_error('缺少访问令牌 / Access token eksik.');
        }
        
        $token_data = [
            'access_token' => $access_token,
            'openid' => $openid
        ];
        
        // Kullanıcı bilgilerini al
        $user_info = $this->get_userinfo($token_data);
        
        if (is_wp_error($user_info)) {
            return $this->show_error('获取用户信息失败 / Kullanıcı bilgileri alınamadı.');
        }
        
        $profile = $this->map_userinfo_to_profile($user_info, $token_data);
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id) {
            return $this->show_error('用户创建失败 / Kullanıcı oluşturulamadı.');
        }
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Yetkilendirme URL'ini oluştur
     * 
     * @param string $state State parametresi
     * @return string Yetkilendirme URL'i
     */
    public function get_auth_url($state = '') {
        $params = [
            'response_type' => $this->response_type,
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->scope,
            'state' => $state
        ];
        
        // QQ özel parametreler
        if (!empty($this->config['display'])) {
            $params['display'] = $this->config['display']; // page, popup, mobile
        }
        
        return add_query_arg($params, $this->auth_url);
    }
    
    /**
     * Token URL'ini döndür
     * 
     * @return string Token URL'i
     */
    public function get_token_url() {
        return $this->token_url;
    }
    
    /**
     * OpenID al (QQ özel)
     * 
     * @param string $access_token Access token
     * @return array|WP_Error OpenID bilgileri
     */
    public function get_openid($access_token) {
        $url = $this->oauth_domain . '/oauth2.0/me';
        
        $params = [
            'access_token' => $access_token,
            'fmt' => 'json' // JSON formatında istiyoruz
        ];
        
        $response = wp_remote_get(add_query_arg($params, $url), [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('qq_openid_error', $data['error_description'] ?? $data['error']);
        }
        
        return [
            'openid' => $data['openid'],
            'client_id' => $data['client_id'] ?? ''
        ];
    }
    
    /**
     * Access token al
     * 
     * @param string $code Yetkilendirme kodu
     * @return array|WP_Error Token bilgileri
     */
    public function get_access_token($code) {
        $params = [
            'grant_type' => $this->grant_type,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'fmt' => 'json'
        ];
        
        $response = wp_remote_post($this->token_url . '?' . http_build_query($params), [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('qq_token_error', $data['error_description'] ?? $data['error']);
        }
        
        return $this->parse_access_token($data);
    }
    
    /**
     * Token yanıtını parse et
     * 
     * @param array $response Token yanıtı
     * @return array Parse edilmiş token bilgileri
     */
    public function parse_access_token($response) {
        return [
            'access_token' => $response['access_token'] ?? '',
            'expires_in' => $response['expires_in'] ?? 3600,
            'refresh_token' => $response['refresh_token'] ?? ''
        ];
    }
    
    /**
     * Refresh token parse et
     * 
     * @param array $response Token yanıtı
     * @return string Refresh token
     */
    public function parse_refresh_token($response) {
        return $response['refresh_token'] ?? '';
    }
    
    /**
     * Expires in parse et
     * 
     * @param array $response Token yanıtı
     * @return int Expires in
     */
    public function parse_expires_in($response) {
        return $response['expires_in'] ?? 3600;
    }
    
    /**
     * Kullanıcı bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array|WP_Error Kullanıcı bilgileri
     */
    public function get_userinfo($token) {
        // Temel kullanıcı bilgileri
        $params = [
            'access_token' => $token['access_token'],
            'openid' => $token['openid'],
            'oauth_consumer_key' => $this->config['client_id'],
            'format' => $this->format
        ];
        
        $response = wp_remote_get(add_query_arg($params, $this->userinfo_url), [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => $this->get_userinfo_headers($token)
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['ret']) && $data['ret'] != 0) {
            return new WP_Error('qq_userinfo_error', $data['msg'] ?? 'Bilinmeyen hata');
        }
        
        // Müzik tercihlerini de al
        $music_params = [
            'access_token' => $token['access_token'],
            'openid' => $token['openid'],
            'appid' => $this->config['client_id'],
            'format' => $this->format
        ];
        
        $music_response = wp_remote_get(add_query_arg($music_params, $this->music_info_url), [
            'timeout' => 30,
            'sslverify' => false
        ]);
        
        $music_data = [];
        if (!is_wp_error($music_response)) {
            $music_body = wp_remote_retrieve_body($music_response);
            $music_data = json_decode($music_body, true);
        }
        
        return [
            'user' => $data,
            'music' => $music_data
        ];
    }
    
    /**
     * Kullanıcı bilgisi HTTP methodu
     * 
     * @return string HTTP method (GET/POST)
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Kullanıcı bilgisi headers
     * 
     * @param array $token Token bilgileri
     * @return array Headers
     */
    public function get_userinfo_headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token['access_token'],
            'Content-Type' => 'application/json'
        ];
    }
    
    /**
     * Kullanıcı bilgisi parametreleri
     * 
     * @param array $token Token bilgileri
     * @return array Parametreler
     */
    public function get_userinfo_params($token) {
        return [
            'access_token' => $token['access_token'],
            'openid' => $token['openid']
        ];
    }
    
    /**
     * API yanıtını profile çevir
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Profil bilgileri
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $user = $data['user'] ?? $data;
        $music = $data['music'] ?? [];
        
        // QQ profil mapping
        $profile = [
            'id' => $token['openid'] ?? ($user['openid'] ?? ''),
            'provider' => 'qq-music',
            'display_name' => $user['nickname'] ?? ($user['name'] ?? 'QQ用户'),
            'email' => $user['email'] ?? '',
            'avatar' => $user['figureurl_qq_2'] ?? ($user['figureurl_qq_1'] ?? ''),
            'gender' => $this->map_gender($user['gender'] ?? ''),
            'birthday' => $user['birthday'] ?? '',
            'province' => $user['province'] ?? '',
            'city' => $user['city'] ?? '',
            'is_vip' => $user['vip'] ?? false,
            'level' => $user['level'] ?? 0,
        ];
        
        // Müzik tercihleri
        if (!empty($music)) {
            $profile['music_preferences'] = [
                'playlist_count' => $music['playlist_count'] ?? 0,
                'favorite_songs' => $music['favorite_count'] ?? 0,
                'followers' => $music['follower_count'] ?? 0,
                'following' => $music['following_count'] ?? 0,
                'music_level' => $music['music_level'] ?? 0,
                'preferred_genres' => $music['preferred_genres'] ?? []
            ];
        }
        
        return $profile;
    }
    
    /**
     * Cinsiyet mapping
     * 
     * @param string $gender QQ cinsiyet kodu
     * @return string Cinsiyet
     */
    protected function map_gender($gender) {
        switch ($gender) {
            case '男':
            case '1':
            case 'male':
                return 'male';
            case '女':
            case '2':
            case 'female':
                return 'female';
            default:
                return 'other';
        }
    }
    
    /**
     * Scope'ları döndür
     * 
     * @return string Scope
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Token parametrelerini döndür
     * 
     * @param string $code Yetkilendirme kodu
     * @return array Token parametreleri
     */
    public function get_token_params($code) {
        return [
            'grant_type' => $this->grant_type,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri']
        ];
    }
    
    /**
     * Token HTTP methodu
     * 
     * @return string HTTP method
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Kullanıcı bul veya oluştur
     * 
     * @param array $profile Profil bilgileri
     * @return int|false Kullanıcı ID veya false
     */
    protected function find_or_create_user($profile) {
        // 1. OpenID'ye göre ara (birincil)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_qq_music_id',
            'meta_value' => $profile['id'],
            'number' => 1
        ]);
        
        if (!empty($users)) {
            $user = $users[0];
            $this->update_user_meta($user->ID, $profile);
            return $user->ID;
        }
        
        // 2. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                update_user_meta($user->ID, 'ai_comm_provider_qq_music_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 3. Kullanıcı adına göre ara
        if (!empty($profile['display_name'])) {
            $username = sanitize_user($profile['display_name']);
            $user = get_user_by('login', $username);
            if ($user) {
                update_user_meta($user->ID, 'ai_comm_provider_qq_music_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['display_name'] ?? 'qquser');
        
        // Email oluştur (yoksa)
        if (empty($profile['email'])) {
            $email = 'qq_' . $profile['id'] . '@music.qq.com';
        } else {
            $email = $profile['email'];
        }
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('qq_music_user_creation_failed', [
                'error' => $user_id->get_error_message()
            ]);
            return false;
        }
        
        // Meta verilerini kaydet
        $this->update_user_meta($user_id, $profile);
        
        return $user_id;
    }
    
    /**
     * Kullanıcı meta verilerini güncelle
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Profil bilgileri
     */
    protected function update_user_meta($user_id, $profile) {
        // Temel bilgiler
        update_user_meta($user_id, 'ai_comm_provider_qq_music_id', $profile['id']);
        update_user_meta($user_id, 'ai_community_provider', 'qq-music');
        update_user_meta($user_id, 'qq_music_display_name', $profile['display_name']);
        
        if (!empty($profile['gender'])) {
            update_user_meta($user_id, 'qq_music_gender', $profile['gender']);
        }
        
        if (!empty($profile['birthday'])) {
            update_user_meta($user_id, 'qq_music_birthday', $profile['birthday']);
        }
        
        if (!empty($profile['province'])) {
            update_user_meta($user_id, 'qq_music_province', $profile['province']);
        }
        
        if (!empty($profile['city'])) {
            update_user_meta($user_id, 'qq_music_city', $profile['city']);
        }
        
        // VIP bilgileri
        update_user_meta($user_id, 'qq_music_is_vip', $profile['is_vip'] ? 'yes' : 'no');
        update_user_meta($user_id, 'qq_music_level', $profile['level']);
        
        // Müzik tercihleri
        if (!empty($profile['music_preferences'])) {
            update_user_meta($user_id, 'qq_music_preferences', $profile['music_preferences']);
        }
        
        // Son giriş
        update_user_meta($user_id, 'qq_music_last_login', time());
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     * 
     * @param string $base Base kullanıcı adı
     * @return string Benzersiz kullanıcı adı
     */
    protected function generate_unique_username($base) {
        $base = sanitize_user($base);
        $base = preg_replace('/[^a-z0-9]/i', '', $base);
        
        if (empty($base) || strlen($base) < 3) {
            $base = 'qqmusic' . wp_rand(100, 999);
        }
        
        $base = substr($base, 0, 20);
        $username = $base;
        $counter = 1;
        
        while (get_user_by('login', $username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Email oluştur (fallback)
     * 
     * @param array $profile Profil bilgileri
     * @return string Email
     */
    protected function generate_email($profile) {
        if (!empty($profile['email'])) {
            return $profile['email'];
        }
        
        return 'qq_' . $profile['id'] . '@music.qq.com';
    }
    
    /**
     * Avatar kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param string $avatar_url Avatar URL
     * @return bool Başarılı mı
     */
    protected function save_avatar($user_id, $avatar_url) {
        if (empty($avatar_url)) {
            return false;
        }
        
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // Geçici dosyaya indir
        $tmp = download_url($avatar_url);
        
        if (is_wp_error($tmp)) {
            return false;
        }
        
        $file_array = [
            'name' => 'qq-music-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }
        
        // Kullanıcı avatarı olarak kaydet
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        
        return true;
    }
    
    /**
     * Hata mesajı göster
     * 
     * @param string $message Hata mesajı
     * @return string HTML çıktı
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>QQ音乐 授权错误 / Yetkilendirme Hatası</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #00B4D8 0%, #0077B6 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .error-container {
                    max-width: 400px;
                    width: 100%;
                }
                
                .error-card {
                    background: white;
                    border-radius: 24px;
                    box-shadow: 0 30px 70px rgba(0,0,0,0.3);
                    overflow: hidden;
                }
                
                .error-header {
                    background: #00B4D8;
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                
                .error-header h1 {
                    font-size: 28px;
                    margin-bottom: 10px;
                }
                
                .error-header p {
                    opacity: 0.9;
                    font-size: 14px;
                }
                
                .error-body {
                    padding: 30px;
                }
                
                .error-message {
                    background: #fff5f5;
                    color: #ff4757;
                    padding: 20px;
                    border-radius: 16px;
                    margin-bottom: 25px;
                    border-left: 4px solid #ff4757;
                    font-size: 14px;
                    line-height: 1.6;
                }
                
                .btn {
                    display: block;
                    width: 100%;
                    padding: 16px;
                    background: #00B4D8;
                    color: white;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 12px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                }
                
                .btn:hover {
                    background: #0077B6;
                    transform: translateY(-2px);
                    box-shadow: 0 10px 25px rgba(0, 180, 216, 0.3);
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <h1>QQ音乐</h1>
                        <p>授权错误 / Yetkilendirme Hatası</p>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <a href="<?php echo esc_url(home_url()); ?>" class="btn">
                            返回首页 / Ana Sayfaya Dön
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}