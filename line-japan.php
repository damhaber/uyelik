<?php
/**
 * AI Community Engine - Line Japan Provider
 * 
 * Line Japan OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/line-japan.php
 * 
 * Sorumluluklar:
 * - Line OAuth2 akışını yönetme
 * - Line Login v2.1 ile kullanıcı doğrulama
 * - OpenID Connect desteği
 * - Kullanıcı profili bilgilerini alma (email, profile, openid)
 * - Line özel scope'ları yönetme
 * 
 * API Dokümantasyonu:
 * - Line Developers: https://developers.line.biz/en/docs/line-login/
 * - Line Login v2.1: https://developers.line.biz/en/reference/line-login/
 * - Scopes: profile, openid, email
 */

class AI_Community_Provider_Line_Japan extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'line-japan';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'LINE';
    
    /**
     * OAuth2 endpoint'leri
     * Line API endpoints
     */
    protected $auth_url = 'https://access.line.me/oauth2/v2.1/authorize';
    protected $token_url = 'https://api.line.me/oauth2/v2.1/token';
    protected $userinfo_url = 'https://api.line.me/v2/profile';
    protected $verify_url = 'https://api.line.me/oauth2/v2.1/verify';
    
    /**
     * OpenID Connect endpoint'leri
     */
    protected $jwks_url = 'https://api.line.me/oauth2/v2.1/certs';
    
    /**
     * Varsayılan scope'lar
     * - profile: プロフィール情報 (ユーザーID、表示名、プロフィール画像)
     * - openid: OpenID Connect (IDトークン)
     * - email: メールアドレス (要申請)
     */
    protected $scope = 'profile openid';
    
    /**
     * Line API response codes
     */
    const BOT_TYPE_NORMAL = 'normal';
    const BOT_TYPE_STICKER = 'sticker';
    
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
        $this->core->log('debug', 'Line Japan provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/line-japan.php'
        ]);
        
        /**
         * Line özel ayarları:
         * - Client ID: チャネルID (Channel ID)
         * - Client Secret: チャネルシークレット (Channel Secret)
         * - Redirect URI: https://site.com/?auth={slug}
         * - Bot type: normal (通常), sticker (スタンプ)
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
        $this->core->log('info', 'Line Japan handle çağrıldı', [
            'file' => 'line-japan.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Line Japan OAuth hatası', [
                'error' => $error,
                'description' => $error_description,
                'file' => 'line-japan.php'
            ]);
            
            return $this->show_error(
                'LINE bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Line Japan callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state']),
                'file' => 'line-japan.php'
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Line Japan yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url,
            'file' => 'line-japan.php'
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
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'state' => $state,
            'scope' => $this->get_scope()
        ];
        
        /**
         * Line özel parametreleri:
         * - prompt: consent (同意画面表示), login (毎回ログイン), select_account (アカウント選択)
         * - max_age: 認証最大経過時間（秒）
         * - ui_locales: UI言語 (ja, en, ko, etc)
         * - bot_prompt: normal (通常), sticker (スタンプ表示)
         */
        if (isset($this->config['prompt'])) {
            $params['prompt'] = $this->config['prompt'];
        }
        
        if (isset($this->config['max_age'])) {
            $params['max_age'] = $this->config['max_age'];
        }
        
        if (isset($this->config['ui_locales'])) {
            $params['ui_locales'] = $this->config['ui_locales'];
        } else {
            $params['ui_locales'] = 'ja'; // Varsayılan Japonca
        }
        
        if (isset($this->config['bot_prompt'])) {
            $params['bot_prompt'] = $this->config['bot_prompt'];
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Line Japan auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params),
            'file' => 'line-japan.php'
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
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret']
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
        
        $this->core->log('error', 'Line Japan access token bulunamadı', [
            'response_keys' => array_keys($response),
            'file' => 'line-japan.php'
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
            'Content-Type' => 'application/json;charset=utf-8'
        ];
    }
    
    /**
     * Kullanıcı bilgi isteği parametreleri
     * 
     * @param string $token Access token
     * @return array GET/POST parametreleri
     */
    public function get_userinfo_params($token) {
        // Line ek parametre gerektirmez
        return [];
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Line Profile API yanıtı (v2/profile):
     * {
     *   "userId": "U1234567890abcdef1234567890abcdef",
     *   "displayName": "山田 太郎",
     *   "pictureUrl": "https://profile.line-scdn.net/1234567890",
     *   "statusMessage": "こんにちは！",
     *   "language": "ja"
     * }
     * 
     * Line ID Token payload (OpenID Connect):
     * {
     *   "iss": "https://access.line.me",
     *   "sub": "U1234567890abcdef1234567890abcdef",
     *   "aud": "1234567890",
     *   "exp": 1612345678,
     *   "iat": 1612342078,
     *   "auth_time": 1612342078,
     *   "nonce": "xxxxxxxxxx",
     *   "name": "山田 太郎",
     *   "picture": "https://profile.line-scdn.net/1234567890",
     *   "email": "taro@example.com"
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Line Japan profil mapping başlıyor', [
            'has_userId' => isset($data['userId']),
            'has_displayName' => isset($data['displayName']),
            'file' => 'line-japan.php'
        ]);
        
        $profile = [];
        
        /**
         * Profile API'den gelen bilgiler
         */
        // Provider bilgileri
        $profile['provider'] = $this->slug;
        $profile['provider_id'] = $data['userId'] ?? null;
        
        // Temel profil bilgileri
        if (!empty($data['displayName'])) {
            $profile['display_name'] = $data['displayName'];
            $profile['nickname'] = $data['displayName'];
        }
        
        if (!empty($data['pictureUrl'])) {
            $profile['avatar'] = $data['pictureUrl'];
        }
        
        if (!empty($data['statusMessage'])) {
            $profile['status_message'] = $data['statusMessage'];
        }
        
        if (!empty($data['language'])) {
            $profile['language'] = $data['language'];
        }
        
        /**
         * ID Token'dan email ve ek bilgiler
         */
        if (isset($token['id_token'])) {
            $id_token = $this->parse_id_token($token['id_token']);
            if ($id_token && !is_wp_error($id_token)) {
                $profile['id_token'] = $id_token;
                
                // Email (email scope ile gelir)
                if (!empty($id_token['email'])) {
                    $profile['email'] = $id_token['email'];
                    
                    // Line'da email onayı her zaman true
                    $profile['email_verified'] = true;
                }
                
                // İsim (varsa ID token'da daha detaylı olabilir)
                if (empty($profile['display_name']) && !empty($id_token['name'])) {
                    $profile['display_name'] = $id_token['name'];
                }
                
                // Profil fotoğrafı (varsa ID token'da)
                if (empty($profile['avatar']) && !empty($id_token['picture'])) {
                    $profile['avatar'] = $id_token['picture'];
                }
                
                // Kimlik doğrulama zamanı
                if (isset($id_token['auth_time'])) {
                    $profile['auth_time'] = $id_token['auth_time'];
                }
            }
        }
        
        /**
         * Token bilgilerinden scope'ları çıkar
         */
        if (isset($token['scope'])) {
            $profile['granted_scopes'] = explode(' ', $token['scope']);
        }
        
        /**
         * Line özel alanları
         */
        // Kullanıcı tipi (normal, premium, etc)
        if (isset($data['type'])) {
            $profile['user_type'] = $data['type'];
        }
        
        // Bot ile ilgili bilgiler
        if (isset($data['bot'])) {
            $profile['bot'] = $data['bot'];
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Line Japan profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar']),
            'has_id_token' => isset($profile['id_token']),
            'file' => 'line-japan.php'
        ]);
        
        return $profile;
    }
    
    /**
     * Scope'ları döndür
     * 
     * @return string Scope string (boşlukla ayrılmış)
     */
    public function get_scope() {
        // Email scope'u config'de varsa ekle
        if (!empty($this->config['email_scope']) && $this->config['email_scope'] === true) {
            return $this->scope . ' email';
        }
        
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
            'refresh_token' => $refresh_token,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret']
        ];
        
        $response = wp_remote_post($this->token_url, [
            'body' => $params,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->core->log('error', 'Line Japan token yenileme hatası', [
                'error' => $response->get_error_message(),
                'file' => 'line-japan.php'
            ]);
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error($body['error'], $body['error_description'] ?? 'Token yenileme hatası');
        }
        
        return $body;
    }
    
    /**
     * Access token doğrula
     * 
     * @param string $access_token Access token
     * @return array|WP_Error Token bilgileri veya hata
     */
    public function verify_access_token($access_token) {
        $params = [
            'access_token' => $access_token
        ];
        
        $response = wp_remote_get($this->verify_url . '?' . http_build_query($params));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error($body['error'], $body['error_description'] ?? 'Token doğrulama hatası');
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
        $this->core->log('info', 'Line Japan: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id'],
            'file' => 'line-japan.php'
        ]);
        
        // 1. Provider ID'ye göre ara (Line User ID)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Line Japan: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id,
                'file' => 'line-japan.php'
            ]);
            
            // Avatar güncelle (değişmiş olabilir)
            if (!empty($profile['avatar'])) {
                $this->save_avatar($user_id, $profile['avatar']);
            }
            
            // Profil bilgilerini güncelle
            $this->update_profile_metadata($user_id, $profile);
            
            return $user_id;
        }
        
        // 2. Email'e göre ara (eğer varsa)
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                $user_id = $user->ID;
                $this->core->log('info', 'Line Japan: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'line-japan.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 3. Yeni kullanıcı oluştur
        $this->core->log('info', 'Line Japan: Yeni kullanıcı oluşturuluyor', [
            'file' => 'line-japan.php'
        ]);
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Line Japan: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'line-japan.php'
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Line Japan: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id,
            'username' => $username,
            'file' => 'line-japan.php'
        ]);
        
        // Provider ID'yi kaydet
        update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
        
        // Ana provider olarak işaretle
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
        // Email onay durumu
        if (isset($profile['email_verified'])) {
            update_user_meta($user_id, $this->slug . '_email_verified', $profile['email_verified']);
        }
        
        // Profil bilgilerini kaydet
        $this->save_profile_metadata($user_id, $profile);
        
        // Display name güncelle (wp_users tablosu)
        if (!empty($profile['display_name'])) {
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['display_name']
            ]);
        }
        
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
        
        // Yoksa nickname dene
        if (empty($base) && !empty($profile['nickname'])) {
            $base = sanitize_user($profile['nickname'], true);
        }
        
        // Hala yoksa provider ID'den oluştur
        if (empty($base)) {
            // Line user ID "U" ile başlar, onu temizle
            $clean_id = preg_replace('/^U/', '', $profile['provider_id']);
            $base = 'line_' . substr($clean_id, 0, 8);
        }
        
        // Japonca karakterleri ve özel karakterleri temizle
        $base = preg_replace('/[^a-z0-9ぁ-んァ-ヶー々〆〤]/u', '-', $base);
        $base = preg_replace('/-+/', '-', $base);
        $base = trim($base, '-');
        
        // Çok kısa ise provider ID ekle
        if (strlen($base) < 3) {
            $clean_id = preg_replace('/^U/', '', $profile['provider_id']);
            $base = 'user_' . substr($clean_id, 0, 6);
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
            'nickname',
            'status_message',
            'language',
            'user_type',
            'auth_time'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
            }
        }
        
        // Granted scopes varsa JSON olarak kaydet
        if (isset($profile['granted_scopes'])) {
            update_user_meta($user_id, $this->slug . '_granted_scopes', json_encode($profile['granted_scopes']));
        }
        
        // Bot bilgileri varsa JSON olarak kaydet
        if (isset($profile['bot'])) {
            update_user_meta($user_id, $this->slug . '_bot', json_encode($profile['bot']));
        }
        
        // ID token varsa (hata ayıklama için) kaydetme, sadece logla
        if (isset($profile['id_token'])) {
            $this->core->log('debug', 'Line Japan: ID token alındı', [
                'user_id' => $user_id,
                'claims' => array_keys($profile['id_token']),
                'file' => 'line-japan.php'
            ]);
        }
        
        $this->core->log('debug', 'Line Japan: Profil metadata kaydedildi', [
            'user_id' => $user_id,
            'fields' => array_keys(array_intersect_key($profile, array_flip($fields))),
            'file' => 'line-japan.php'
        ]);
    }
    
    /**
     * Profil metadata'larını güncelle (mevcut kullanıcı için)
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Profil bilgileri
     */
    protected function update_profile_metadata($user_id, $profile) {
        $fields = [
            'display_name',
            'nickname',
            'status_message',
            'language',
            'user_type'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                // Eski değerle karşılaştır, değiştiyse güncelle
                $old_value = get_user_meta($user_id, $this->slug . '_' . $field, true);
                if ($old_value !== $profile[$field]) {
                    update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
                    
                    $this->core->log('debug', 'Line Japan: Profil alanı güncellendi', [
                        'user_id' => $user_id,
                        'field' => $field,
                        'old' => $old_value,
                        'new' => $profile[$field],
                        'file' => 'line-japan.php'
                    ]);
                }
            }
        }
        
        // Display name güncelle (wp_users tablosu)
        if (!empty($profile['display_name'])) {
            $user = get_userdata($user_id);
            if ($user && $user->display_name !== $profile['display_name']) {
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $profile['display_name']
                ]);
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
            $this->core->log('error', 'Line Japan: Avatar indirilemedi', [
                'error' => $tmp->get_error_message(),
                'url' => $image_url,
                'file' => 'line-japan.php'
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'line-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Line Japan: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message(),
                'file' => 'line-japan.php'
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
        $this->core->log('info', 'Line Japan: Avatar kaydedildi', [
            'user_id' => $user_id,
            'attachment_id' => $attachment_id,
            'file' => 'line-japan.php'
        ]);
        
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
                    background: linear-gradient(135deg, #00B900 0%, #009900 100%);
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
                    background: #00B900;
                    color: white;
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
                    opacity: 0.9;
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
                    background: #00B900;
                    color: white;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 10px;
                    font-weight: 600;
                    border: none;
                    cursor: pointer;
                    transition: background 0.3s ease;
                }
                
                .btn:hover {
                    background: #009900;
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
                        <p>Hata / エラー</p>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <div class="error-details">
                            <?php _e('Lütfen daha sonra tekrar deneyin veya farklı bir giriş yöntemi kullanın.', 'ai-community'); ?><br>
                            時間をおいて再度お試しいただくか、別のログイン方法をご利用ください。
                        </div>
                        <a href="<?php echo esc_url(home_url()); ?>" class="btn">
                            <?php _e('Ana Sayfaya Dön', 'ai-community'); ?> / ホームへ
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