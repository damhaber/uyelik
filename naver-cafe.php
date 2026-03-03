<?php
/**
 * AI Community Engine - Naver Cafe Provider
 * 
 * Naver Cafe OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/naver-cafe.php
 * 
 * Sorumluluklar:
 * - Naver OAuth2 akışını yönetme
 * - Naver Cafe için özel scope'lar
 * - Kullanıcı profili bilgilerini alma
 * - OpenID Connect desteği
 * - Kullanıcı oluşturma ve bağlama
 * 
 * API Dokümantasyonu:
 * - Naver Login: https://developers.naver.com/docs/login/api/
 * - Scopes: nickname, profile_image, email, gender, age, birthday
 */

class AI_Community_Provider_Naver_Cafe extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'naver-cafe';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'Naver Cafe';
    
    /**
     * OAuth2 endpoint'leri
     * Naver API endpoints
     */
    protected $auth_url = 'https://nid.naver.com/oauth2.0/authorize';
    protected $token_url = 'https://nid.naver.com/oauth2.0/token';
    protected $userinfo_url = 'https://openapi.naver.com/v1/nid/me';
    
    /**
     * Varsayılan scope'lar
     * - 기본 프로필: nickname, profile_image
     * - 이메일: email
     * - 성별: gender
     * - 연령대: age
     * - 생일: birthday
     */
    protected $scope = 'nickname profile_image email gender age birthday';
    
    /**
     * Naver API response codes
     */
    const RESPONSE_SUCCESS = '00';
    
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
        $this->core->log('debug', 'Naver Cafe provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/naver-cafe.php'
        ]);
        
        /**
         * Naver özel ayarları:
         * - Client ID: 애플리케이션 등록 시 발급받은 Client ID
         * - Client Secret: 애플리케이션 등록 시 발급받은 Client Secret
         * - Redirect URI: https://site.com/?auth={slug}
         * - Service URL: https://cafe.naver.com (Cafe için özel)
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
        $this->core->log('info', 'Naver Cafe handle çağrıldı', [
            'file' => 'naver-cafe.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Naver Cafe OAuth hatası', [
                'error' => $error,
                'description' => $error_description,
                'file' => 'naver-cafe.php'
            ]);
            
            return $this->show_error(
                'Naver bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Naver Cafe callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state']),
                'file' => 'naver-cafe.php'
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Naver Cafe yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url,
            'file' => 'naver-cafe.php'
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
            'state' => $state
        ];
        
        /**
         * Naver özel parametreleri:
         * - auth_type: reauthenticate (재인증), logout (로그아웃 후 인증)
         * - service_provider: CAFE (Cafe için özel)
         */
        if (!empty($this->scope)) {
            $params['scope'] = $this->get_scope();
        }
        
        if (isset($this->config['auth_type'])) {
            $params['auth_type'] = $this->config['auth_type'];
        }
        
        if (isset($this->config['service_provider'])) {
            $params['service_provider'] = $this->config['service_provider'];
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Naver Cafe auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params),
            'file' => 'naver-cafe.php'
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
            'code' => $code,
            'state' => $_GET['state'] ?? ''
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
        
        $this->core->log('error', 'Naver Cafe access token bulunamadı', [
            'response_keys' => array_keys($response),
            'file' => 'naver-cafe.php'
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
        // Naver ek parametre gerektirmez
        return [];
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Naver yanıt yapısı:
     * {
     *   resultcode: "00",
     *   message: "success",
     *   response: {
     *     id: "12345678",
     *     nickname: "별명",
     *     profile_image: "https://...",
     *     age: "20-29",
     *     gender: "M",
     *     email: "user@example.com",
     *     name: "실명",
     *     birthday: "12-25",
     *     birthyear: "1990"
     *   }
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Naver Cafe profil mapping başlıyor', [
            'has_resultcode' => isset($data['resultcode']),
            'has_response' => isset($data['response']),
            'file' => 'naver-cafe.php'
        ]);
        
        // Naver API hata kontrolü
        if (isset($data['resultcode']) && $data['resultcode'] !== self::RESPONSE_SUCCESS) {
            $this->core->log('error', 'Naver API hatası', [
                'resultcode' => $data['resultcode'],
                'message' => $data['message'] ?? 'Bilinmeyen hata',
                'file' => 'naver-cafe.php'
            ]);
            
            return new WP_Error(
                'naver_api_error',
                'Naver API hatası: ' . ($data['message'] ?? 'Bilinmeyen hata')
            );
        }
        
        $profile = [];
        
        // Naver response içindeki kullanıcı bilgileri
        if (isset($data['response']) && is_array($data['response'])) {
            $user_data = $data['response'];
            
            // Provider bilgileri
            $profile['provider'] = $this->slug;
            $profile['provider_id'] = $user_data['id'] ?? null;
            
            /**
             * Temel profil bilgileri
             */
            // Nickname (필수)
            if (!empty($user_data['nickname'])) {
                $profile['display_name'] = $user_data['nickname'];
                $profile['nickname'] = $user_data['nickname'];
            }
            
            // Profil fotoğrafı
            if (!empty($user_data['profile_image'])) {
                $profile['avatar'] = $user_data['profile_image'];
            }
            
            // Email (izin verilmişse)
            if (!empty($user_data['email'])) {
                $profile['email'] = $user_data['email'];
                $profile['email_verified'] = true; // Naver email onaylıdır
            }
            
            /**
             * Ek profil bilgileri (opsiyonel)
             */
            // Gerçek isim (이름 - 제공 동의 필요)
            if (!empty($user_data['name'])) {
                $profile['first_name'] = $user_data['name'];
                $profile['last_name'] = '';
                
                // Kore'de isimler genellikle soyad + ad şeklinde
                // Basit bir ayrım yapalım (ilk karakter genelde soyad)
                if (function_exists('mb_substr')) {
                    $profile['last_name'] = mb_substr($user_data['name'], 0, 1, 'UTF-8');
                    $profile['first_name'] = mb_substr($user_data['name'], 1, null, 'UTF-8');
                }
            }
            
            // Yaş aralığı (연령대)
            if (!empty($user_data['age'])) {
                $profile['age_range'] = $user_data['age'];
            }
            
            // Cinsiyet (성별)
            if (!empty($user_data['gender'])) {
                $profile['gender'] = strtolower($user_data['gender']); // M -> male, F -> female
            }
            
            // Doğum günü (생일 - MM-DD format)
            if (!empty($user_data['birthday'])) {
                $profile['birthday'] = str_replace('-', '', $user_data['birthday']); // MMDD format
            }
            
            // Doğum yılı (출생연도)
            if (!empty($user_data['birthyear'])) {
                $profile['birthyear'] = $user_data['birthyear'];
            }
            
            // Mobil telefon (휴대전화번호 - 제공 동의 필요)
            if (!empty($user_data['mobile'])) {
                $profile['phone'] = $user_data['mobile'];
            }
            
            /**
             * Naver Cafe için özel alanlar
             */
            // Cafe ID (운영하는 카페가 있을 경우)
            if (!empty($user_data['cafe_id'])) {
                $profile['cafe_id'] = $user_data['cafe_id'];
            }
            
            // Cafe URL (대표 카페 URL)
            if (!empty($user_data['cafe_url'])) {
                $profile['cafe_url'] = $user_data['cafe_url'];
            }
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Naver Cafe profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar']),
            'has_phone' => isset($profile['phone']),
            'file' => 'naver-cafe.php'
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
            $this->core->log('error', 'Naver Cafe token yenileme hatası', [
                'error' => $response->get_error_message(),
                'file' => 'naver-cafe.php'
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
        $this->core->log('info', 'Naver Cafe: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id'],
            'file' => 'naver-cafe.php'
        ]);
        
        // 1. Provider ID'ye göre ara (Naver ID)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Naver Cafe: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id,
                'file' => 'naver-cafe.php'
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
                $this->core->log('info', 'Naver Cafe: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'naver-cafe.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 3. Telefon numarasına göre ara (Naver mobil onaylı)
        if (!empty($profile['phone'])) {
            $users = get_users([
                'meta_key' => $this->slug . '_phone',
                'meta_value' => $profile['phone'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            if (!empty($users)) {
                $user_id = $users[0];
                $this->core->log('info', 'Naver Cafe: Kullanıcı telefon ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'naver-cafe.php'
                ]);
                
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $this->core->log('info', 'Naver Cafe: Yeni kullanıcı oluşturuluyor', [
            'file' => 'naver-cafe.php'
        ]);
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Naver Cafe: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'naver-cafe.php'
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Naver Cafe: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id,
            'username' => $username,
            'file' => 'naver-cafe.php'
        ]);
        
        // Provider ID'yi kaydet
        update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
        
        // Ana provider olarak işaretle
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
        // Telefon varsa kaydet
        if (!empty($profile['phone'])) {
            update_user_meta($user_id, $this->slug . '_phone', $profile['phone']);
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
        
        // Nickname varsa onu kullan
        if (!empty($profile['nickname'])) {
            $base = sanitize_user($profile['nickname'], true);
        }
        
        // Yoksa display name dene
        if (empty($base) && !empty($profile['display_name'])) {
            $base = sanitize_user($profile['display_name'], true);
        }
        
        // Yoksa isim dene
        if (empty($base) && !empty($profile['first_name'])) {
            $base = sanitize_user($profile['first_name'], true);
        }
        
        // Hala yoksa provider ID'den oluştur
        if (empty($base)) {
            $base = 'naver_' . substr($profile['provider_id'], 0, 8);
        }
        
        // Korece karakterleri ve özel karakterleri temizle
        $base = preg_replace('/[^a-z0-9가-힣]/u', '-', $base);
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
            'age_range',
            'birthday',
            'birthyear',
            'phone',
            'cafe_id',
            'cafe_url'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
            }
        }
        
        $this->core->log('debug', 'Naver Cafe: Profil metadata kaydedildi', [
            'user_id' => $user_id,
            'fields' => array_intersect_key($profile, array_flip($fields)),
            'file' => 'naver-cafe.php'
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
            'nickname',
            'display_name',
            'gender',
            'age_range',
            'birthday',
            'birthyear',
            'phone',
            'cafe_id',
            'cafe_url'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                // Eski değerle karşılaştır, değiştiyse güncelle
                $old_value = get_user_meta($user_id, $this->slug . '_' . $field, true);
                if ($old_value !== $profile[$field]) {
                    update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
                    
                    $this->core->log('debug', 'Naver Cafe: Profil alanı güncellendi', [
                        'user_id' => $user_id,
                        'field' => $field,
                        'old' => $old_value,
                        'new' => $profile[$field],
                        'file' => 'naver-cafe.php'
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
            $this->core->log('error', 'Naver Cafe: Avatar indirilemedi', [
                'error' => $tmp->get_error_message(),
                'url' => $image_url,
                'file' => 'naver-cafe.php'
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'naver-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Naver Cafe: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message(),
                'file' => 'naver-cafe.php'
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
        $this->core->log('info', 'Naver Cafe: Avatar kaydedildi', [
            'user_id' => $user_id,
            'attachment_id' => $attachment_id,
            'file' => 'naver-cafe.php'
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
                    background: linear-gradient(135deg, #03C75A 0%, #029B48 100%);
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
                    background: #03C75A;
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
                    background: #03C75A;
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
                    background: #029B48;
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