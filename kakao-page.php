<?php
/**
 * AI Community Engine - Kakao Page Provider
 * 
 * Kakao Page OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * 
 * Sorumluluklar:
 * - Kakao Page OAuth2 akışını yönetme
 * - Kullanıcı profili bilgilerini alma (Kakao Account)
 * - OpenID Connect desteği (ID Token)
 * - CI (Connecting Information) yönetimi
 * - Kullanıcı oluşturma ve bağlama
 * 
 * API Dokümantasyonu:
 * - REST API: https://developers.kakao.com/docs/latest/ko/kakaologin/rest-api
 * - Scopes: profile_nickname, profile_image, account_email, gender, age_range, birthday, ci
 */

class AI_Community_Provider_Kakao_Page extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'kakao-page';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'Kakao Page';
    
    /**
     * OAuth2 endpoint'leri
     */
    protected $auth_url = 'https://kauth.kakao.com/oauth/authorize';
    protected $token_url = 'https://kauth.kakao.com/oauth/token';
    protected $userinfo_url = 'https://kapi.kakao.com/v2/user/me';
    
    /**
     * OpenID Connect JWKS endpoint (ID token doğrulama için)
     */
    protected $jwks_url = 'https://kauth.kakao.com/.well-known/jwks.json';
    
    /**
     * Varsayılan scope'lar
     * - openid: OpenID Connect (ID token)
     * - profile_nickname: Kullanıcı adı
     * - profile_image: Profil fotoğrafı
     * - account_email: Email (yetki gerektirir)
     */
    protected $scope = 'openid profile_nickname profile_image account_email';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider konfigürasyonu
     * @param object $core Ana çekirdek nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Debug log
        $this->core->log('debug', 'Kakao Page provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config)
        ]);
        
        /**
         * Kakao özel ayarları:
         * - Client ID: REST API Key
         * - Client Secret: Admin Key değil, REST API Key'in yanında verilen Secret
         * - Redirect URI: https://site.com/?auth={slug}
         */
    }
    
    /**
     * Ana giriş noktası
     * 
     * Sorumluluklar:
     * 1. GET/POST parametrelerini birleştir
     * 2. Hata varsa göster
     * 3. Code varsa callback'i işle
     * 4. Yoksa yetkilendirme URL'ine yönlendir
     * 
     * @return string|void HTML çıktı veya yönlendirme
     */
    public function handle() {
        $this->core->log('info', 'Kakao Page handle çağrıldı');
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Kakao Page OAuth hatası', [
                'error' => $error,
                'description' => $error_description
            ]);
            
            return $this->show_error(
                'Kakao bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Kakao Page callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state'])
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Kakao Page yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url
        ]);
        
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Yetkilendirme URL'ini oluştur
     * 
     * @param string $state CSRF token
     * @return string OAuth2 yetkilendirme URL'i
     */
    public function get_auth_url($state = '') {
        $params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $this->get_scope(),
            'state' => $state
        ];
        
        /**
         * Kakao özel parametreleri:
         * - prompt: login (her seferinde login ekranı göster), none (sessiz), consent (yetkilendirme)
         * - service_terms: 서비스 약관 동의 (service_terms=agree)
         */
        if (isset($this->config['prompt'])) {
            $params['prompt'] = $this->config['prompt'];
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Kakao Page auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params)
        ]);
        
        return $url;
    }
    
    /**
     * Token URL'ini döndür
     * 
     * @return string Token endpoint URL'i
     */
    public function get_token_url() {
        return $this->token_url;
    }
    
    /**
     * Kullanıcı bilgi URL'ini döndür
     * 
     * @return string Userinfo endpoint URL'i
     */
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    /**
     * Token isteği için parametreler
     * 
     * @param string $code Authorization code
     * @return array POST parametreleri
     */
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code
        ];
    }
    
    /**
     * Token isteği HTTP metodu
     * 
     * @return string 'POST' veya 'GET'
     */
    public function get_token_method() {
        return 'POST';
    }
    
    /**
     * Token yanıtından access_token'ı ayıkla
     * 
     * @param array $response API yanıtı
     * @return string|WP_Error Access token veya hata
     */
    public function parse_access_token($response) {
        if (isset($response['access_token'])) {
            return $response['access_token'];
        }
        
        $this->core->log('error', 'Kakao Page access token bulunamadı', [
            'response_keys' => array_keys($response)
        ]);
        
        return new WP_Error('no_access_token', 'Access token alınamadı');
    }
    
    /**
     * Token yanıtından refresh_token'ı ayıkla
     * 
     * @param array $response API yanıtı
     * @return string|null Refresh token veya null
     */
    public function parse_refresh_token($response) {
        return $response['refresh_token'] ?? null;
    }
    
    /**
     * Token yanıtından expires_in değerini ayıkla
     * 
     * @param array $response API yanıtı
     * @return int|null Saniye cinsinden süre veya null
     */
    public function parse_expires_in($response) {
        return $response['expires_in'] ?? null;
    }
    
    /**
     * Kullanıcı bilgi isteği HTTP metodu
     * 
     * @return string 'GET' veya 'POST'
     */
    public function get_userinfo_method() {
        return 'GET';
    }
    
    /**
     * Kullanıcı bilgi isteği HTTP header'ları
     * 
     * @param string $token Access token
     * @return array HTTP header'ları
     */
    public function get_userinfo_headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8'
        ];
    }
    
    /**
     * Kullanıcı bilgi isteği parametreleri
     * 
     * @param string $token Access token
     * @return array GET/POST parametreleri
     */
    public function get_userinfo_params($token) {
        /**
         * Kakao özel parametreleri:
         * - property_keys: İstenen özelliklerin listesi (JSON array)
         *   Örn: ["kakao_account.email", "kakao_account.profile"]
         */
        $params = [];
        
        // Sadece gerekli property'leri iste (opsiyonel)
        if (isset($this->config['property_keys'])) {
            $params['property_keys'] = json_encode($this->config['property_keys']);
        }
        
        return $params;
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Kakao yanıt yapısı:
     * {
     *   id: 1234567890,
     *   connected_at: "2023-01-01T00:00:00Z",
     *   properties: {
     *     nickname: "Kullanıcı Adı",
     *     profile_image: "http://..."
     *   },
     *   kakao_account: {
     *     email: "user@example.com",
     *     email_needs_agreement: false,
     *     profile_nickname_needs_agreement: false,
     *     profile: { ... }
     *   }
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Kakao Page profil mapping başlıyor', [
            'has_id' => isset($data['id']),
            'has_properties' => isset($data['properties'])
        ]);
        
        $profile = [];
        
        // Provider bilgileri
        $profile['provider'] = $this->slug;
        $profile['provider_id'] = $data['id'] ?? null;
        
        /**
         * Basic Profile (properties)
         * Kakao'nun temel profil bilgileri
         */
        if (isset($data['properties'])) {
            $profile['display_name'] = $data['properties']['nickname'] ?? '';
            $profile['first_name'] = $data['properties']['nickname'] ?? '';
            $profile['nickname'] = $data['properties']['nickname'] ?? '';
            $profile['avatar'] = $data['properties']['profile_image'] ?? '';
            $profile['avatar_original'] = $data['properties']['thumbnail_image'] ?? '';
        }
        
        /**
         * Kakao Account (kakao_account)
         * Email, yaş, cinsiyet, doğum günü, CI gibi ek bilgiler
         */
        if (isset($data['kakao_account'])) {
            $account = $data['kakao_account'];
            
            // Email (kullanıcı izin vermişse)
            if (!empty($account['email']) && !$account['email_needs_agreement']) {
                $profile['email'] = $account['email'];
                $profile['email_verified'] = true;
            }
            
            // Yaş aralığı
            if (!empty($account['age_range'])) {
                $profile['age_range'] = $account['age_range'];
            }
            
            // Cinsiyet (male/female)
            if (!empty($account['gender'])) {
                $profile['gender'] = $account['gender'];
            }
            
            // Doğum günü (MMDD format)
            if (!empty($account['birthday'])) {
                $profile['birthday'] = $account['birthday'];
            }
            
            // Doğum yılı
            if (!empty($account['birthyear'])) {
                $profile['birthyear'] = $account['birthyear'];
            }
            
            /**
             * CI (Connecting Information)
             * Kore'de kimlik doğrulama için kullanılan benzersiz ID
             * CI 값은 서비스가 종료되어도 동일한 이용자를 식별할 수 있는 값입니다.
             */
            if (!empty($account['ci'])) {
                $profile['ci'] = $account['ci'];
            }
        }
        
        /**
         * ID Token'dan ek bilgiler
         * OpenID Connect ile gelen JWT token
         */
        if (isset($token['id_token'])) {
            $id_token = $this->parse_id_token($token['id_token']);
            if ($id_token && !is_wp_error($id_token)) {
                $profile['id_token'] = $id_token;
            }
        }
        
        // Bağlantı zamanı
        if (isset($data['connected_at'])) {
            $profile['connected_at'] = $data['connected_at'];
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Kakao Page profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar'])
        ]);
        
        return $profile;
    }
    
    /**
     * Scope'ları döndür
     * 
     * @return string Scope string (boşlukla ayrılmış)
     */
    public function get_scope() {
        return $this->scope;
    }
    
    /**
     * Access token yenile
     * 
     * @param string $refresh_token Refresh token
     * @return array|WP_Error Yeni token bilgileri veya hata
     */
    public function refresh_access_token($refresh_token) {
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refresh_token
        ];
        
        $response = wp_remote_post($this->token_url, [
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error($body['error'], $body['error_description'] ?? 'Token yenileme hatası');
        }
        
        return $body;
    }
    
    /**
     * ID Token'ı parse et (JWT decode)
     * 
     * @param string $id_token JWT formatında ID token
     * @return array|WP_Error Decoded payload veya hata
     */
    protected function parse_id_token($id_token) {
        $parts = explode('.', $id_token);
        
        if (count($parts) !== 3) {
            return new WP_Error('invalid_id_token', 'Geçersiz ID token formatı');
        }
        
        // Payload'ı decode et (ikinci kısım)
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        
        if (!$payload) {
            return new WP_Error('invalid_payload', 'ID token payload okunamadı');
        }
        
        return $payload;
    }
    
    /**
     * Kullanıcı profilini kaydet
     * 
     * @param array $profile Kullanıcı profili
     * @return int|WP_Error Kullanıcı ID'si veya hata
     */
    protected function find_or_create_user($profile) {
        $this->core->log('info', 'Kakao Page: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id']
        ]);
        
        // 1. Provider ID'ye göre ara
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Kakao Page: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id
            ]);
            
            // Avatar güncelle (değişmiş olabilir)
            if (!empty($profile['avatar'])) {
                $this->save_avatar($user_id, $profile['avatar']);
            }
            
            return $user_id;
        }
        
        // 2. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                $user_id = $user->ID;
                $this->core->log('info', 'Kakao Page: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 3. CI'ya göre ara (Kore'de kimlik doğrulama için)
        if (!empty($profile['ci'])) {
            $users = get_users([
                'meta_key' => $this->slug . '_ci',
                'meta_value' => $profile['ci'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            if (!empty($users)) {
                $user_id = $users[0];
                $this->core->log('info', 'Kakao Page: Kullanıcı CI ile bulundu', [
                    'user_id' => $user_id
                ]);
                
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $this->core->log('info', 'Kakao Page: Yeni kullanıcı oluşturuluyor');
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Kakao Page: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message()
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Kakao Page: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id
        ]);
        
        // Provider ID'yi kaydet
        update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
        
        // Ana provider olarak işaretle
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
        // CI varsa kaydet
        if (!empty($profile['ci'])) {
            update_user_meta($user_id, $this->slug . '_ci', $profile['ci']);
        }
        
        // Email onay durumu
        if (isset($profile['email_verified'])) {
            update_user_meta($user_id, $this->slug . '_email_verified', $profile['email_verified']);
        }
        
        // Profil bilgilerini kaydet
        $this->save_profile_metadata($user_id, $profile);
        
        // Avatar kaydet
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
        }
        
        return $user_id;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     * 
     * @param array $profile Kullanıcı profili
     * @return string Benzersiz kullanıcı adı
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        // Display name varsa onu kullan
        if (!empty($profile['display_name'])) {
            $base = sanitize_user($profile['display_name'], true);
        }
        
        // Yoksa provider ID'den oluştur
        if (empty($base)) {
            $base = 'kakao_' . substr($profile['provider_id'], 0, 8);
        }
        
        // Türkçe karakterleri dönüştür
        $base = strtolower(trim($base));
        $base = preg_replace('/[^a-z0-9]/', '-', $base);
        $base = preg_replace('/-+/', '-', $base);
        $base = trim($base, '-');
        
        // Kısa ise provider ID ekle
        if (strlen($base) < 3) {
            $base = 'user_' . substr($profile['provider_id'], 0, 6);
        }
        
        $username = $base;
        $counter = 1;
        
        // Benzersiz mi kontrol et
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Profil metadata'larını kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Profil bilgileri
     */
    protected function save_profile_metadata($user_id, $profile) {
        $fields = [
            'display_name',
            'first_name',
            'last_name',
            'nickname',
            'gender',
            'age_range',
            'birthday',
            'birthyear',
            'connected_at'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
            }
        }
    }
    
    /**
     * Avatar kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param string $image_url Avatar URL'i
     * @return bool Başarılı mı
     */
    protected function save_avatar($user_id, $image_url) {
        // WordPress'in avatar fonksiyonunu kullan
        if (function_exists('ai_community_save_avatar')) {
            return ai_community_save_avatar($user_id, $image_url);
        }
        
        // Manual avatar kaydetme
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // Resmi indir
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            $this->core->log('error', 'Kakao Page: Avatar indirilemedi', [
                'error' => $tmp->get_error_message()
            ]);
            return false;
        }
        
        $file_array = [
            'name' => basename($image_url),
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Kakao Page: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message()
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
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
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Hata - <?php echo esc_html($this->name); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    overflow: hidden;
                    animation: slideIn 0.5s ease-out;
                }
                
                @keyframes slideIn {
                    from {
                        opacity: 0;
                        transform: translateY(-20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                
                .error-header {
                    background: #FEE500;
                    color: #000000;
                    padding: 30px;
                    text-align: center;
                    position: relative;
                }
                
                .error-header h1 {
                    font-size: 24px;
                    margin-bottom: 5px;
                    font-weight: 600;
                }
                
                .error-header p {
                    font-size: 14px;
                    opacity: 0.8;
                }
                
                .error-body {
                    padding: 30px;
                }
                
                .error-message {
                    background: #fff5f5;
                    color: #e53e3e;
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    border-left: 4px solid #e53e3e;
                    font-size: 14px;
                }
                
                .error-details {
                    background: #f7f7f7;
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    font-size: 13px;
                    color: #666;
                    text-align: center;
                }
                
                .btn {
                    display: block;
                    width: 100%;
                    padding: 14px;
                    background: #FEE500;
                    color: #000000;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 10px;
                    font-weight: 600;
                    border: none;
                    cursor: pointer;
                    transition: background 0.3s ease;
                }
                
                .btn:hover {
                    background: #FDD835;
                }
                
                .language-switch {
                    margin-top: 15px;
                    text-align: center;
                }
                
                .language-switch a {
                    color: #999;
                    text-decoration: none;
                    font-size: 12px;
                }
                
                .language-switch a:hover {
                    color: #666;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <h1><?php echo esc_html($this->name); ?></h1>
                        <p>Hata / 오류</p>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <div class="error-details">
                            <?php _e('Lütfen daha sonra tekrar deneyin veya farklı bir giriş yöntemi kullanın.', 'ai-community'); ?><br>
                            나중에 다시 시도하거나 다른 로그인 방법을 사용하세요.
                        </div>
                        <a href="<?php echo esc_url(home_url()); ?>" class="btn">
                            <?php _e('Ana Sayfaya Dön', 'ai-community'); ?> / 홈으로
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