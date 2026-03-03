<?php
/**
 * AI Community Engine - Yahoo Japan Provider
 * 
 * Yahoo Japan OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/yahoo-japan.php
 * 
 * Sorumluluklar:
 * - Yahoo Japan OAuth2 akışını yönetme
 * - Yahoo! ID ile kullanıcı doğrulama
 * - OpenID Connect desteği (Yahoo! JAPAN OpenID)
 * - Kullanıcı profili bilgilerini alma
 * - Kullanıcı oluşturma ve bağlama
 * 
 * API Dokümantasyonu:
 * - Yahoo! JAPAN Developer: https://developer.yahoo.co.jp/
 * - YConnect API: https://developer.yahoo.co.jp/yconnect/v2/
 * - Scopes: openid, profile, email, address
 */

class AI_Community_Provider_Yahoo_Japan extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'yahoo-japan';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'Yahoo! JAPAN';
    
    /**
     * OAuth2 endpoint'leri
     * Yahoo Japan API endpoints (YConnect)
     */
    protected $auth_url = 'https://auth.login.yahoo.co.jp/yconnect/v2/authorization';
    protected $token_url = 'https://auth.login.yahoo.co.jp/yconnect/v2/token';
    protected $userinfo_url = 'https://userinfo.yahooapis.jp/yconnect/v2/attribute';
    protected $jwks_url = 'https://auth.login.yahoo.co.jp/yconnect/v2/jwks';
    
    /**
     * Varsayılan scope'lar
     * - openid: OpenID Connect (必須)
     * - profile: 名前、表示名、プロフィール画像
     * - email: メールアドレス
     * - address: 住所
     */
    protected $scope = 'openid profile email';
    
    /**
     * Yahoo Japan API response codes
     */
    const RESPONSE_SUCCESS = 'OK';
    
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
        $this->core->log('debug', 'Yahoo Japan provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/yahoo-japan.php'
        ]);
        
        /**
         * Yahoo Japan özel ayarları:
         * - Client ID: アプリケーションID (Application ID)
         * - Client Secret: シークレット (Secret)
         * - Redirect URI: https://site.com/?auth={slug}
         * - Prompt: login (毎回ログイン), consent (同意確認)
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
        $this->core->log('info', 'Yahoo Japan handle çağrıldı', [
            'file' => 'yahoo-japan.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Yahoo Japan OAuth hatası', [
                'error' => $error,
                'description' => $error_description,
                'file' => 'yahoo-japan.php'
            ]);
            
            return $this->show_error(
                'Yahoo! JAPAN bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Yahoo Japan callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state']),
                'file' => 'yahoo-japan.php'
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Yahoo Japan yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url,
            'file' => 'yahoo-japan.php'
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
            'state' => $state,
            'nonce' => wp_create_nonce('yahoo_nonce') // OpenID Connect için nonce
        ];
        
        /**
         * Yahoo Japan özel parametreleri:
         * - prompt: login (毎回ログイン), consent (同意画面表示), select_account (アカウント選択)
         * - display: page (デフォルト), popup, touch, wap
         * - bd: デバイス情報 (オプション)
         */
        if (isset($this->config['prompt'])) {
            $params['prompt'] = $this->config['prompt'];
        }
        
        if (isset($this->config['display'])) {
            $params['display'] = $this->config['display'];
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Yahoo Japan auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params),
            'file' => 'yahoo-japan.php'
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
        
        $this->core->log('error', 'Yahoo Japan access token bulunamadı', [
            'response_keys' => array_keys($response),
            'file' => 'yahoo-japan.php'
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
        // Yahoo Japan ek parametre gerektirmez
        return [];
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Yahoo Japan yanıt yapısı (OpenID Connect):
     * {
     *   sub: "1234567890",
     *   name: "山田 太郎",
     *   given_name: "太郎",
     *   family_name: "山田",
     *   nickname: "taro_y",
     *   picture: "https://...",
     *   email: "taro@example.com",
     *   email_verified: true,
     *   gender: "male",
     *   birthdate: "1990-01-01",
     *   address: {
     *     country: "JP",
     *     postal_code: "100-0001",
     *     region: "東京都",
     *     locality: "千代田区"
     *   }
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Yahoo Japan profil mapping başlıyor', [
            'has_sub' => isset($data['sub']),
            'has_name' => isset($data['name']),
            'file' => 'yahoo-japan.php'
        ]);
        
        $profile = [];
        
        // Provider bilgileri
        $profile['provider'] = $this->slug;
        $profile['provider_id'] = $data['sub'] ?? null; // OpenID Connect sub (固有ID)
        
        /**
         * Temel profil bilgileri (profile scope)
         */
        // İsim (名前)
        if (!empty($data['name'])) {
            $profile['display_name'] = $data['name'];
        }
        
        // Soyisim + İsim ayrımı
        if (!empty($data['family_name'])) {
            $profile['last_name'] = $data['family_name'];
        }
        
        if (!empty($data['given_name'])) {
            $profile['first_name'] = $data['given_name'];
        }
        
        // Takma ad (ニックネーム)
        if (!empty($data['nickname'])) {
            $profile['nickname'] = $data['nickname'];
        }
        
        // Profil fotoğrafı (プロフィール画像)
        if (!empty($data['picture'])) {
            $profile['avatar'] = $data['picture'];
        }
        
        /**
         * Email bilgileri (email scope)
         */
        if (!empty($data['email'])) {
            $profile['email'] = $data['email'];
            $profile['email_verified'] = $data['email_verified'] ?? false;
        }
        
        /**
         * Ek profil bilgileri (opsiyonel)
         */
        // Cinsiyet (性別)
        if (!empty($data['gender'])) {
            $profile['gender'] = strtolower($data['gender']);
        }
        
        // Doğum tarihi (生年月日 - YYYY-MM-DD format)
        if (!empty($data['birthdate'])) {
            $profile['birthdate'] = $data['birthdate'];
            
            // Doğum yılı ayır
            $birth_parts = explode('-', $data['birthdate']);
            if (count($birth_parts) >= 1) {
                $profile['birthyear'] = $birth_parts[0];
            }
            if (count($birth_parts) >= 2) {
                $profile['birthmonth'] = $birth_parts[1];
            }
            if (count($birth_parts) >= 3) {
                $profile['birthday'] = $birth_parts[1] . $birth_parts[2]; // MMDD format
            }
        }
        
        /**
         * Adres bilgileri (address scope)
         */
        if (!empty($data['address']) && is_array($data['address'])) {
            $address = $data['address'];
            
            $profile['address'] = [
                'country' => $address['country'] ?? 'JP',
                'postal_code' => $address['postal_code'] ?? '',
                'region' => $address['region'] ?? '', // 都道府県
                'locality' => $address['locality'] ?? '', // 市区町村
                'street' => $address['street_address'] ?? ''
            ];
            
            // Düz metin adres oluştur
            $address_parts = [];
            if (!empty($profile['address']['postal_code'])) {
                $address_parts[] = '〒' . $profile['address']['postal_code'];
            }
            if (!empty($profile['address']['region'])) {
                $address_parts[] = $profile['address']['region'];
            }
            if (!empty($profile['address']['locality'])) {
                $address_parts[] = $profile['address']['locality'];
            }
            if (!empty($profile['address']['street'])) {
                $address_parts[] = $profile['address']['street'];
            }
            
            $profile['address_formatted'] = implode(' ', $address_parts);
        }
        
        /**
         * Yahoo Japan özel alanları
         */
        // Yaş doğrulama durumu (年齢確認)
        if (isset($data['age_range'])) {
            $profile['age_range'] = $data['age_range'];
        }
        
        // Premium üyelik durumu (プレミアム会員)
        if (isset($data['is_premium'])) {
            $profile['is_premium'] = (bool)$data['is_premium'];
        }
        
        // ID token'dan ek bilgiler
        if (isset($token['id_token'])) {
            $id_token = $this->parse_id_token($token['id_token']);
            if ($id_token && !is_wp_error($id_token)) {
                $profile['id_token'] = $id_token;
                
                // Auth time (認証時刻)
                if (isset($id_token['auth_time'])) {
                    $profile['auth_time'] = $id_token['auth_time'];
                }
            }
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Yahoo Japan profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar']),
            'has_address' => isset($profile['address']),
            'file' => 'yahoo-japan.php'
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
            'body' => $params,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ]);
        
        if (is_wp_error($response)) {
            $this->core->log('error', 'Yahoo Japan token yenileme hatası', [
                'error' => $response->get_error_message(),
                'file' => 'yahoo-japan.php'
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
        $this->core->log('info', 'Yahoo Japan: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id'],
            'file' => 'yahoo-japan.php'
        ]);
        
        // 1. Provider ID'ye göre ara (sub)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Yahoo Japan: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id,
                'file' => 'yahoo-japan.php'
            ]);
            
            // Avatar güncelle (değişmiş olabilir)
            if (!empty($profile['avatar'])) {
                $this->save_avatar($user_id, $profile['avatar']);
            }
            
            // Profil bilgilerini güncelle
            $this->update_profile_metadata($user_id, $profile);
            
            return $user_id;
        }
        
        // 2. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                $user_id = $user->ID;
                $this->core->log('info', 'Yahoo Japan: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'yahoo-japan.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 3. Yeni kullanıcı oluştur
        $this->core->log('info', 'Yahoo Japan: Yeni kullanıcı oluşturuluyor', [
            'file' => 'yahoo-japan.php'
        ]);
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Yahoo Japan: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'yahoo-japan.php'
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Yahoo Japan: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id,
            'username' => $username,
            'file' => 'yahoo-japan.php'
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
        
        // Nickname varsa onu kullan
        if (!empty($profile['nickname'])) {
            $base = sanitize_user($profile['nickname'], true);
        }
        
        // Yoksa display name dene
        if (empty($base) && !empty($profile['display_name'])) {
            $base = sanitize_user($profile['display_name'], true);
        }
        
        // Yoksa given_name (isim) dene
        if (empty($base) && !empty($profile['first_name'])) {
            $base = sanitize_user($profile['first_name'], true);
        }
        
        // Yoksa family_name (soyisim) dene
        if (empty($base) && !empty($profile['last_name'])) {
            $base = sanitize_user($profile['last_name'], true);
        }
        
        // Hala yoksa provider ID'den oluştur
        if (empty($base)) {
            $base = 'yahoo_' . substr($profile['provider_id'], 0, 8);
        }
        
        // Japonca karakterleri ve özel karakterleri temizle
        $base = preg_replace('/[^a-z0-9ぁ-んァ-ヶー々〆〤]/u', '-', $base);
        $base = preg_replace('/-+/', '-', $base);
        $base = trim($base, '-');
        
        // Çok kısa ise provider ID ekle
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
            'birthdate',
            'birthyear',
            'birthmonth',
            'birthday',
            'age_range',
            'is_premium',
            'address_formatted'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
            }
        }
        
        // Address array varsa JSON olarak kaydet
        if (isset($profile['address'])) {
            update_user_meta($user_id, $this->slug . '_address', json_encode($profile['address']));
        }
        
        $this->core->log('debug', 'Yahoo Japan: Profil metadata kaydedildi', [
            'user_id' => $user_id,
            'fields' => array_keys(array_intersect_key($profile, array_flip($fields))),
            'file' => 'yahoo-japan.php'
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
            'first_name',
            'last_name',
            'nickname',
            'gender',
            'birthdate',
            'age_range',
            'is_premium'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                // Eski değerle karşılaştır, değiştiyse güncelle
                $old_value = get_user_meta($user_id, $this->slug . '_' . $field, true);
                if ($old_value !== $profile[$field]) {
                    update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
                    
                    $this->core->log('debug', 'Yahoo Japan: Profil alanı güncellendi', [
                        'user_id' => $user_id,
                        'field' => $field,
                        'old' => $old_value,
                        'new' => $profile[$field],
                        'file' => 'yahoo-japan.php'
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
            $this->core->log('error', 'Yahoo Japan: Avatar indirilemedi', [
                'error' => $tmp->get_error_message(),
                'url' => $image_url,
                'file' => 'yahoo-japan.php'
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'yahoo-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Yahoo Japan: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message(),
                'file' => 'yahoo-japan.php'
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
        $this->core->log('info', 'Yahoo Japan: Avatar kaydedildi', [
            'user_id' => $user_id,
            'attachment_id' => $attachment_id,
            'file' => 'yahoo-japan.php'
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
                    background: linear-gradient(135deg, #FF0033 0%, #CC0022 100%);
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
                    background: #FF0033;
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
                    background: #FF0033;
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
                    background: #CC0022;
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