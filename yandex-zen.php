<?php
/**
 * AI Community Engine - Yandex Zen Provider
 * 
 * Yandex Zen OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/yandex-zen.php
 * 
 * Sorumluluklar:
 * - Yandex OAuth2 akışını yönetme
 * - Yandex Passport API ile kullanıcı doğrulama
 * - Yandex Zen için özel kullanıcı bilgileri
 * - Kullanıcı oluşturma ve bağlama
 * 
 * API Dokümantasyonu:
 * - Yandex OAuth: https://yandex.com/dev/oauth/
 * - Yandex Passport: https://yandex.com/dev/passport/
 * - Scopes: login:email, login:avatar, login:birthday, login:info
 */

class AI_Community_Provider_Yandex_Zen extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'yandex-zen';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'Yandex Zen';
    
    /**
     * OAuth2 endpoint'leri
     * Yandex API endpoints
     */
    protected $auth_url = 'https://oauth.yandex.com/authorize';
    protected $token_url = 'https://oauth.yandex.com/token';
    protected $userinfo_url = 'https://login.yandex.ru/info';
    
    /**
     * Varsayılan scope'lar
     * - login:email: email адрес
     * - login:avatar: аватар пользователя
     * - login:birthday: дата рождения
     * - login:info: основная информация (имя, пол)
     */
    protected $scope = 'login:email login:avatar login:info';
    
    /**
     * Yandex API formatı
     */
    const RESPONSE_FORMAT = 'json';
    
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
        $this->core->log('debug', 'Yandex Zen provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/yandex-zen.php'
        ]);
        
        /**
         * Yandex özel ayarları:
         * - Client ID: Идентификатор приложения (Application ID)
         * - Client Secret: Пароль приложения (Application Password)
         * - Redirect URI: https://site.com/?auth={slug}
         * - Display: popup (всплывающее окно), page (страница)
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
        $this->core->log('info', 'Yandex Zen handle çağrıldı', [
            'file' => 'yandex-zen.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Yandex Zen OAuth hatası', [
                'error' => $error,
                'description' => $error_description,
                'file' => 'yandex-zen.php'
            ]);
            
            return $this->show_error(
                'Yandex Zen bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Yandex Zen callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state']),
                'file' => 'yandex-zen.php'
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Yandex Zen yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url,
            'file' => 'yandex-zen.php'
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
         * Yandex özel parametreleri:
         * - display: popup (всплывающее окно), page (полная страница)
         * - force_confirm: true (всегда показывать окно подтверждения)
         * - optional_scope: дополнительные опциональные права
         */
        if (isset($this->config['display'])) {
            $params['display'] = $this->config['display'];
        }
        
        if (isset($this->config['force_confirm'])) {
            $params['force_confirm'] = $this->config['force_confirm'] ? 'yes' : 'no';
        }
        
        if (isset($this->config['optional_scope'])) {
            $params['optional_scope'] = $this->config['optional_scope'];
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Yandex Zen auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params),
            'file' => 'yandex-zen.php'
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
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->config['redirect_uri']
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
        
        $this->core->log('error', 'Yandex Zen access token bulunamadı', [
            'response_keys' => array_keys($response),
            'file' => 'yandex-zen.php'
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
            'Authorization' => 'OAuth ' . $token,
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
        // Yandex ek parametre olarak format isteyebilir
        return [
            'format' => self::RESPONSE_FORMAT
        ];
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Yandex Passport API yanıt yapısı:
     * {
     *   "id": "1234567890",
     *   "login": "my-yandex-login",
     *   "client_id": "1234567890",
     *   "display_name": "Иван Петров",
     *   "real_name": "Иван Петров",
     *   "first_name": "Иван",
     *   "last_name": "Петров",
     *   "sex": "male",
     *   "default_email": "user@yandex.ru",
     *   "emails": ["user@yandex.ru", "user@gmail.com"],
     *   "birthday": "1990-01-01",
     *   "default_avatar_id": "12345/abcdef",
     *   "is_avatar_empty": false,
     *   "psuid": "1234567890",
     *   "zen": {
     *     "channel_id": "1234567890",
     *     "channel_url": "https://zen.yandex.ru/id/1234567890",
     *     "subscribers": 12345,
     *     "publications": 678,
     *     "likes": 9876,
     *     "comments": 543,
     *     "joined_at": "2020-01-01T00:00:00+03:00"
     *   }
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Yandex Zen profil mapping başlıyor', [
            'has_id' => isset($data['id']),
            'has_login' => isset($data['login']),
            'file' => 'yandex-zen.php'
        ]);
        
        $profile = [];
        
        // Provider bilgileri
        $profile['provider'] = $this->slug;
        $profile['provider_id'] = $data['id'] ?? null;
        
        /**
         * Temel profil bilgileri
         */
        // Kullanıcı adı (login)
        if (!empty($data['login'])) {
            $profile['login'] = $data['login'];
        }
        
        // Görünen ad
        if (!empty($data['display_name'])) {
            $profile['display_name'] = $data['display_name'];
        } elseif (!empty($data['real_name'])) {
            $profile['display_name'] = $data['real_name'];
        }
        
        // İsim bilgileri
        if (!empty($data['first_name'])) {
            $profile['first_name'] = $data['first_name'];
        }
        
        if (!empty($data['last_name'])) {
            $profile['last_name'] = $data['last_name'];
        }
        
        // Cinsiyet
        if (!empty($data['sex'])) {
            $profile['gender'] = $data['sex']; // male/female
        }
        
        /**
         * Email bilgileri
         */
        // Default email
        if (!empty($data['default_email'])) {
            $profile['email'] = $data['default_email'];
            $profile['email_verified'] = true; // Yandep email onaylıdır
        }
        
        // Tüm email adresleri
        if (!empty($data['emails']) && is_array($data['emails'])) {
            $profile['emails'] = $data['emails'];
        }
        
        /**
         * Profil fotoğrafı
         * Yandex avatar URL formatı:
         * https://avatars.yandex.net/get-yapic/{avatar_id}/islands-200
         */
        if (!empty($data['default_avatar_id']) && empty($data['is_avatar_empty'])) {
            $avatar_id = $data['default_avatar_id'];
            $profile['avatar'] = 'https://avatars.yandex.net/get-yapic/' . $avatar_id . '/islands-200';
            
            // Büyük boy avatar
            if (!empty($data['is_avatar_empty']) === false) {
                $profile['avatar_large'] = 'https://avatars.yandex.net/get-yapic/' . $avatar_id . '/islands-300';
            }
        }
        
        /**
         * Doğum tarihi
         */
        if (!empty($data['birthday'])) {
            $profile['birthdate'] = $data['birthday'];
            
            // Doğum yılı ayır (YYYY-MM-DD)
            $birth_parts = explode('-', $data['birthday']);
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
         * Yandex Zen özel alanları
         */
        if (!empty($data['zen']) && is_array($data['zen'])) {
            $zen = $data['zen'];
            
            $profile['zen'] = [
                'channel_id' => $zen['channel_id'] ?? '',
                'channel_url' => $zen['channel_url'] ?? '',
                'subscribers' => $zen['subscribers'] ?? 0,
                'publications' => $zen['publications'] ?? 0,
                'likes' => $zen['likes'] ?? 0,
                'comments' => $zen['comments'] ?? 0,
                'joined_at' => $zen['joined_at'] ?? ''
            ];
            
            $profile['zen_channel_id'] = $zen['channel_id'] ?? '';
            $profile['zen_channel_url'] = $zen['channel_url'] ?? '';
            $profile['zen_subscribers'] = $zen['subscribers'] ?? 0;
            $profile['zen_publications'] = $zen['publications'] ?? 0;
        }
        
        /**
         * Ek bilgiler
         */
        // PSUID (Permanent Subscriber User ID)
        if (!empty($data['psuid'])) {
            $profile['psuid'] = $data['psuid'];
        }
        
        // Client ID (hangi uygulama üzerinden bağlanıldı)
        if (!empty($data['client_id'])) {
            $profile['connected_app'] = $data['client_id'];
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Yandex Zen profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar']),
            'has_zen' => isset($profile['zen']),
            'file' => 'yandex-zen.php'
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
            $this->core->log('error', 'Yandex Zen token yenileme hatası', [
                'error' => $response->get_error_message(),
                'file' => 'yandex-zen.php'
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
     * Kullanıcı profilini kaydet
     * 
     * @param array $profile Kullanıcı profili
     * @return int|WP_Error Kullanıcı ID'si veya hata
     */
    protected function find_or_create_user($profile) {
        $this->core->log('info', 'Yandex Zen: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id'],
            'file' => 'yandex-zen.php'
        ]);
        
        // 1. Provider ID'ye göre ara (Yandex ID)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Yandex Zen: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id,
                'file' => 'yandex-zen.php'
            ]);
            
            // Avatar güncelle (değişmiş olabilir)
            if (!empty($profile['avatar'])) {
                $this->save_avatar($user_id, $profile['avatar']);
            }
            
            // Profil bilgilerini güncelle
            $this->update_profile_metadata($user_id, $profile);
            
            return $user_id;
        }
        
        // 2. Login'e göre ara (Yandex login)
        if (!empty($profile['login'])) {
            $users = get_users([
                'meta_key' => $this->slug . '_login',
                'meta_value' => $profile['login'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            if (!empty($users)) {
                $user_id = $users[0];
                $this->core->log('info', 'Yandex Zen: Kullanıcı login ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'yandex-zen.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 3. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                $user_id = $user->ID;
                $this->core->log('info', 'Yandex Zen: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'yandex-zen.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $this->core->log('info', 'Yandex Zen: Yeni kullanıcı oluşturuluyor', [
            'file' => 'yandex-zen.php'
        ]);
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Yandex Zen: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'yandex-zen.php'
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Yandex Zen: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id,
            'username' => $username,
            'file' => 'yandex-zen.php'
        ]);
        
        // Provider ID'yi kaydet
        update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
        
        // Ana provider olarak işaretle
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
        // Login bilgisini kaydet
        if (!empty($profile['login'])) {
            update_user_meta($user_id, $this->slug . '_login', $profile['login']);
        }
        
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
        
        // Login varsa onu kullan (Yandex login)
        if (!empty($profile['login'])) {
            $base = sanitize_user($profile['login'], true);
        }
        
        // Yoksa display name dene
        if (empty($base) && !empty($profile['display_name'])) {
            $base = sanitize_user($profile['display_name'], true);
        }
        
        // Yoksa first_name dene
        if (empty($base) && !empty($profile['first_name'])) {
            $base = sanitize_user($profile['first_name'], true);
        }
        
        // Hala yoksa provider ID'den oluştur
        if (empty($base)) {
            $base = 'yandex_' . substr($profile['provider_id'], 0, 8);
        }
        
        // Kiril karakterlerini Latin karakterlere dönüştür (transliteration)
        $base = $this->transliterate_cyrillic($base);
        
        // Geri kalan özel karakterleri temizle
        $base = preg_replace('/[^a-z0-9]/', '-', $base);
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
     * Kiril karakterlerini Latin karakterlere dönüştür
     * 
     * @param string $string Dönüştürülecek metin
     * @return string Dönüştürülmüş metin
     */
    protected function transliterate_cyrillic($string) {
        $cyrillic = [
            'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
            'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
            'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
            'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
        ];
        
        $latin = [
            'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
            'r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya',
            'A','B','V','G','D','E','Yo','Zh','Z','I','Y','K','L','M','N','O','P',
            'R','S','T','U','F','Kh','Ts','Ch','Sh','Shch','','Y','','E','Yu','Ya'
        ];
        
        return str_replace($cyrillic, $latin, $string);
    }
    
    /**
     * Profil metadata'larını kaydet
     * 
     * @param int $user_id Kullanıcı ID
     * @param array $profile Profil bilgileri
     */
    protected function save_profile_metadata($user_id, $profile) {
        $fields = [
            'login',
            'display_name',
            'first_name',
            'last_name',
            'gender',
            'birthdate',
            'birthyear',
            'birthmonth',
            'birthday',
            'psuid',
            'connected_app',
            'zen_channel_id',
            'zen_channel_url',
            'zen_subscribers',
            'zen_publications'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
            }
        }
        
        // Email listesi varsa JSON olarak kaydet
        if (isset($profile['emails'])) {
            update_user_meta($user_id, $this->slug . '_emails', json_encode($profile['emails']));
        }
        
        // Zen detayları varsa JSON olarak kaydet
        if (isset($profile['zen'])) {
            update_user_meta($user_id, $this->slug . '_zen', json_encode($profile['zen']));
        }
        
        $this->core->log('debug', 'Yandex Zen: Profil metadata kaydedildi', [
            'user_id' => $user_id,
            'fields' => array_keys(array_intersect_key($profile, array_flip($fields))),
            'file' => 'yandex-zen.php'
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
            'login',
            'display_name',
            'first_name',
            'last_name',
            'gender',
            'birthdate',
            'zen_channel_url',
            'zen_subscribers',
            'zen_publications'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                // Eski değerle karşılaştır, değiştiyse güncelle
                $old_value = get_user_meta($user_id, $this->slug . '_' . $field, true);
                if ($old_value !== $profile[$field]) {
                    update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
                    
                    $this->core->log('debug', 'Yandex Zen: Profil alanı güncellendi', [
                        'user_id' => $user_id,
                        'field' => $field,
                        'old' => $old_value,
                        'new' => $profile[$field],
                        'file' => 'yandex-zen.php'
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
            $this->core->log('error', 'Yandex Zen: Avatar indirilemedi', [
                'error' => $tmp->get_error_message(),
                'url' => $image_url,
                'file' => 'yandex-zen.php'
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'yandex-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Yandex Zen: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message(),
                'file' => 'yandex-zen.php'
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
        $this->core->log('info', 'Yandex Zen: Avatar kaydedildi', [
            'user_id' => $user_id,
            'attachment_id' => $attachment_id,
            'file' => 'yandex-zen.php'
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
                    background: linear-gradient(135deg, #FFCC00 0%, #FFAA00 100%);
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
                    background: #FFCC00;
                    color: #333;
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
                    background: #FFCC00;
                    color: #333;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 10px;
                    font-weight: 600;
                    border: none;
                    cursor: pointer;
                    transition: background 0.3s ease;
                }
                
                .btn:hover {
                    background: #FFAA00;
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
                        <p>Hata / Ошибка</p>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <div class="error-details">
                            <?php _e('Lütfen daha sonra tekrar deneyin veya farklı bir giriş yöntemi kullanın.', 'ai-community'); ?><br>
                            Пожалуйста, повторите попытку позже или используйте другой способ входа.
                        </div>
                        <a href="<?php echo esc_url(home_url()); ?>" class="btn">
                            <?php _e('Ana Sayfaya Dön', 'ai-community'); ?> / Вернуться на главную
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