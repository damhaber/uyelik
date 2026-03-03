<?php
/**
 * AI Community Engine - Ameba Provider
 * 
 * Ameba OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/ameba.php
 * 
 * Sorumluluklar:
 * - Ameba OAuth2 akışını yönetme
 * - Ameba API ile kullanıcı bilgilerini alma
 * - Ameba Blog ve Pigoo (アメーバピグ) entegrasyonu
 * - Kullanıcı oluşturma ve bağlama
 * 
 * API Dokümantasyonu:
 * - Ameba Developer: https://developer.ameba.jp/
 * - Ameba Authentication: https://developer.ameba.jp/authentication
 * - Scopes: profile, blog, pigoo, payment
 */

class AI_Community_Provider_Ameba extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'ameba';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'Ameba';
    
    /**
     * OAuth2 endpoint'leri
     * Ameba API endpoints
     */
    protected $auth_url = 'https://oauth.ameba.jp/authorize';
    protected $token_url = 'https://oauth.ameba.jp/token';
    protected $userinfo_url = 'https://api.ameba.jp/v1/users/me';
    
    /**
     * Varsayılan scope'lar
     * - profile: プロフィール情報
     * - blog: ブログ情報
     * - pigoo: アメーバピグ情報
     */
    protected $scope = 'profile blog';
    
    /**
     * Ameba API response format
     */
    const API_VERSION = 'v1';
    const RESPONSE_SUCCESS = 'success';
    
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
        $this->core->log('debug', 'Ameba provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/ameba.php'
        ]);
        
        /**
         * Ameba özel ayarları:
         * - Client ID: クライアントID (Application ID)
         * - Client Secret: クライアントシークレット (Secret)
         * - Redirect URI: https://site.com/?auth={slug}
         * - Response Type: code (authorization code flow)
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
        $this->core->log('info', 'Ameba handle çağrıldı', [
            'file' => 'ameba.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Ameba OAuth hatası', [
                'error' => $error,
                'description' => $error_description,
                'file' => 'ameba.php'
            ]);
            
            return $this->show_error(
                'Ameba bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Ameba callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state']),
                'file' => 'ameba.php'
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Ameba yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url,
            'file' => 'ameba.php'
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
         * Ameba özel parametreleri:
         * - approval_prompt: auto (自動), force (毎回)
         * - display: page (デフォルト), popup, touch, wap
         * - lang: ja (日本語), en (英語)
         */
        if (isset($this->config['approval_prompt'])) {
            $params['approval_prompt'] = $this->config['approval_prompt'];
        }
        
        if (isset($this->config['display'])) {
            $params['display'] = $this->config['display'];
        }
        
        if (isset($this->config['lang'])) {
            $params['lang'] = $this->config['lang'];
        } else {
            $params['lang'] = 'ja'; // Varsayılan Japonca
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Ameba auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params),
            'file' => 'ameba.php'
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
        
        $this->core->log('error', 'Ameba access token bulunamadı', [
            'response_keys' => array_keys($response),
            'file' => 'ameba.php'
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
            'Content-Type' => 'application/json;charset=utf-8',
            'X-API-Version' => self::API_VERSION
        ];
    }
    
    /**
     * Kullanıcı bilgi isteği parametreleri
     * 
     * @param string $token Access token
     * @return array GET/POST parametreleri
     */
    public function get_userinfo_params($token) {
        // Ameba ek parametre gerektirmez
        return [];
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Ameba API yanıt yapısı:
     * {
     *   "status": "success",
     *   "data": {
     *     "id": "1234567890",
     *     "ameba_id": "taro.ameba",
     *     "nickname": "たろう",
     *     "name": "山田 太郎",
     *     "family_name": "山田",
     *     "given_name": "太郎",
     *     "profile_image": {
     *       "url": "https://..."
     *     },
     *     "cover_image": {
     *       "url": "https://..."
     *     },
     *     "email": "taro@example.com",
     *     "email_verified": true,
     *     "birthday": "1990-01-01",
     *     "gender": "male",
     *     "location": "東京都渋谷区",
     *     "introduction": "自己紹介文",
     *     "website": "https://ameblo.jp/taro",
     *     "blog": {
     *       "url": "https://ameblo.jp/taro",
     *       "title": "たろうのブログ",
     *       "theme": "日記",
     *       "created_at": "2008-01-01T00:00:00+09:00",
     *       "entries_count": 1234,
     *       "comments_count": 5678,
     *       "trackbacks_count": 90,
     *       "visitors_count": 12345
     *     },
     *     "pigoo": {
     *       "avatar_url": "https://...",
     *       "room_url": "https://pig.ameba.jp/room/12345",
     *       "level": 10,
     *       "experience": 12345,
     *       "friends_count": 123,
     *       "items_count": 456,
     *       "gold": 10000,
     *       "silver": 5000,
     *       "joined_at": "2010-01-01T00:00:00+09:00"
     *     },
     *     "followers_count": 123,
     *     "following_count": 456,
     *     "is_premium": false,
     *     "premium_type": "none",
     *     "created_at": "2008-01-01T00:00:00+09:00",
     *     "updated_at": "2024-01-01T00:00:00+09:00"
     *   }
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Ameba profil mapping başlıyor', [
            'has_status' => isset($data['status']),
            'has_data' => isset($data['data']),
            'file' => 'ameba.php'
        ]);
        
        // Ameba API hata kontrolü
        if (isset($data['status']) && $data['status'] !== self::RESPONSE_SUCCESS) {
            $error_message = $data['message'] ?? 'Bilinmeyen hata';
            $this->core->log('error', 'Ameba API hatası', [
                'status' => $data['status'],
                'message' => $error_message,
                'file' => 'ameba.php'
            ]);
            
            return new WP_Error('ameba_api_error', 'Ameba API hatası: ' . $error_message);
        }
        
        $profile = [];
        
        // Ameba response data içinde gelir
        if (isset($data['data']) && is_array($data['data'])) {
            $user_data = $data['data'];
            
            // Provider bilgileri
            $profile['provider'] = $this->slug;
            $profile['provider_id'] = $user_data['id'] ?? null;
            
            /**
             * Temel profil bilgileri
             */
            // Ameba ID (ユーザーID)
            if (!empty($user_data['ameba_id'])) {
                $profile['ameba_id'] = $user_data['ameba_id'];
            }
            
            // Nickname (ニックネーム)
            if (!empty($user_data['nickname'])) {
                $profile['nickname'] = $user_data['nickname'];
                $profile['display_name'] = $user_data['nickname'];
            }
            
            // İsim (名前)
            if (!empty($user_data['name'])) {
                $profile['formatted_name'] = $user_data['name'];
            }
            
            if (!empty($user_data['family_name'])) {
                $profile['last_name'] = $user_data['family_name'];
            }
            
            if (!empty($user_data['given_name'])) {
                $profile['first_name'] = $user_data['given_name'];
            }
            
            /**
             * Profil fotoğrafları
             */
            if (!empty($user_data['profile_image']['url'])) {
                $profile['avatar'] = $user_data['profile_image']['url'];
            }
            
            if (!empty($user_data['cover_image']['url'])) {
                $profile['cover_image'] = $user_data['cover_image']['url'];
            }
            
            /**
             * Email bilgileri
             */
            if (!empty($user_data['email'])) {
                $profile['email'] = $user_data['email'];
                $profile['email_verified'] = $user_data['email_verified'] ?? false;
            }
            
            /**
             * Kişisel bilgiler
             */
            // Doğum tarihi (生年月日)
            if (!empty($user_data['birthday'])) {
                $profile['birthdate'] = $user_data['birthday'];
                
                // Doğum yılı ayır (YYYY-MM-DD)
                $birth_parts = explode('-', $user_data['birthday']);
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
            
            // Cinsiyet (性別)
            if (!empty($user_data['gender'])) {
                $profile['gender'] = strtolower($user_data['gender']);
            }
            
            // Konum (所在地)
            if (!empty($user_data['location'])) {
                $profile['location'] = $user_data['location'];
            }
            
            // Kendini tanıtma (自己紹介)
            if (!empty($user_data['introduction'])) {
                $profile['description'] = $user_data['introduction'];
            }
            
            // Website
            if (!empty($user_data['website'])) {
                $profile['website'] = $user_data['website'];
            }
            
            /**
             * Blog bilgileri (Ameba Blog)
             */
            if (!empty($user_data['blog']) && is_array($user_data['blog'])) {
                $blog = $user_data['blog'];
                
                $profile['blog'] = [
                    'url' => $blog['url'] ?? '',
                    'title' => $blog['title'] ?? '',
                    'theme' => $blog['theme'] ?? '',
                    'created_at' => $blog['created_at'] ?? '',
                    'entries_count' => $blog['entries_count'] ?? 0,
                    'comments_count' => $blog['comments_count'] ?? 0,
                    'trackbacks_count' => $blog['trackbacks_count'] ?? 0,
                    'visitors_count' => $blog['visitors_count'] ?? 0
                ];
                
                $profile['blog_url'] = $blog['url'] ?? '';
                $profile['blog_title'] = $blog['title'] ?? '';
                $profile['blog_entries'] = $blog['entries_count'] ?? 0;
            }
            
            /**
             * Pigoo bilgileri (アメーバピグ)
             */
            if (!empty($user_data['pigoo']) && is_array($user_data['pigoo'])) {
                $pigoo = $user_data['pigoo'];
                
                $profile['pigoo'] = [
                    'avatar_url' => $pigoo['avatar_url'] ?? '',
                    'room_url' => $pigoo['room_url'] ?? '',
                    'level' => $pigoo['level'] ?? 0,
                    'experience' => $pigoo['experience'] ?? 0,
                    'friends_count' => $pigoo['friends_count'] ?? 0,
                    'items_count' => $pigoo['items_count'] ?? 0,
                    'gold' => $pigoo['gold'] ?? 0,
                    'silver' => $pigoo['silver'] ?? 0,
                    'joined_at' => $pigoo['joined_at'] ?? ''
                ];
                
                $profile['pigoo_level'] = $pigoo['level'] ?? 0;
                $profile['pigoo_friends'] = $pigoo['friends_count'] ?? 0;
            }
            
            /**
             * Sosyal istatistikler
             */
            if (isset($user_data['followers_count'])) {
                $profile['followers_count'] = (int)$user_data['followers_count'];
            }
            
            if (isset($user_data['following_count'])) {
                $profile['following_count'] = (int)$user_data['following_count'];
            }
            
            /**
             * Premium üyelik
             */
            if (isset($user_data['is_premium'])) {
                $profile['is_premium'] = (bool)$user_data['is_premium'];
            }
            
            if (!empty($user_data['premium_type'])) {
                $profile['premium_type'] = $user_data['premium_type'];
            }
            
            /**
             * Zaman damgaları
             */
            if (!empty($user_data['created_at'])) {
                $profile['registered_at'] = $user_data['created_at']; // Ameba kayıt tarihi
            }
            
            if (!empty($user_data['updated_at'])) {
                $profile['profile_updated_at'] = $user_data['updated_at'];
            }
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Ameba profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar']),
            'has_blog' => isset($profile['blog']),
            'has_pigoo' => isset($profile['pigoo']),
            'file' => 'ameba.php'
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
            $this->core->log('error', 'Ameba token yenileme hatası', [
                'error' => $response->get_error_message(),
                'file' => 'ameba.php'
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
        $this->core->log('info', 'Ameba: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id'],
            'file' => 'ameba.php'
        ]);
        
        // 1. Provider ID'ye göre ara (Ameba ID)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Ameba: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id,
                'file' => 'ameba.php'
            ]);
            
            // Avatar güncelle (değişmiş olabilir)
            if (!empty($profile['avatar'])) {
                $this->save_avatar($user_id, $profile['avatar']);
            }
            
            // Profil bilgilerini güncelle
            $this->update_profile_metadata($user_id, $profile);
            
            return $user_id;
        }
        
        // 2. Ameba ID'ye göre ara (ameba_id)
        if (!empty($profile['ameba_id'])) {
            $users = get_users([
                'meta_key' => $this->slug . '_ameba_id',
                'meta_value' => $profile['ameba_id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            if (!empty($users)) {
                $user_id = $users[0];
                $this->core->log('info', 'Ameba: Kullanıcı ameba_id ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'ameba.php'
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
                $this->core->log('info', 'Ameba: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'ameba.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $this->core->log('info', 'Ameba: Yeni kullanıcı oluşturuluyor', [
            'file' => 'ameba.php'
        ]);
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Ameba: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'ameba.php'
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Ameba: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id,
            'username' => $username,
            'file' => 'ameba.php'
        ]);
        
        // Provider ID'yi kaydet
        update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
        
        // Ana provider olarak işaretle
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
        // Ameba ID'yi kaydet
        if (!empty($profile['ameba_id'])) {
            update_user_meta($user_id, $this->slug . '_ameba_id', $profile['ameba_id']);
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
        
        // Ameba ID varsa onu kullan
        if (!empty($profile['ameba_id'])) {
            $base = sanitize_user($profile['ameba_id'], true);
        }
        
        // Yoksa nickname dene
        if (empty($base) && !empty($profile['nickname'])) {
            $base = sanitize_user($profile['nickname'], true);
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
            $base = 'ameba_' . substr($profile['provider_id'], 0, 8);
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
            'ameba_id',
            'display_name',
            'first_name',
            'last_name',
            'formatted_name',
            'nickname',
            'description',
            'gender',
            'birthdate',
            'birthyear',
            'birthmonth',
            'birthday',
            'location',
            'website',
            'blog_url',
            'blog_title',
            'blog_entries',
            'pigoo_level',
            'pigoo_friends',
            'followers_count',
            'following_count',
            'is_premium',
            'premium_type',
            'registered_at',
            'profile_updated_at'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
            }
        }
        
        // Blog array varsa JSON olarak kaydet
        if (isset($profile['blog'])) {
            update_user_meta($user_id, $this->slug . '_blog', json_encode($profile['blog']));
        }
        
        // Pigoo array varsa JSON olarak kaydet
        if (isset($profile['pigoo'])) {
            update_user_meta($user_id, $this->slug . '_pigoo', json_encode($profile['pigoo']));
        }
        
        // Cover image varsa kaydet
        if (isset($profile['cover_image'])) {
            update_user_meta($user_id, $this->slug . '_cover_image', $profile['cover_image']);
        }
        
        $this->core->log('debug', 'Ameba: Profil metadata kaydedildi', [
            'user_id' => $user_id,
            'fields' => array_keys(array_intersect_key($profile, array_flip($fields))),
            'file' => 'ameba.php'
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
            'ameba_id',
            'display_name',
            'first_name',
            'last_name',
            'formatted_name',
            'nickname',
            'description',
            'gender',
            'birthdate',
            'location',
            'website',
            'blog_title',
            'blog_entries',
            'pigoo_level',
            'pigoo_friends',
            'followers_count',
            'following_count',
            'is_premium',
            'premium_type',
            'profile_updated_at'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                // Eski değerle karşılaştır, değiştiyse güncelle
                $old_value = get_user_meta($user_id, $this->slug . '_' . $field, true);
                if ($old_value !== $profile[$field]) {
                    update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
                    
                    $this->core->log('debug', 'Ameba: Profil alanı güncellendi', [
                        'user_id' => $user_id,
                        'field' => $field,
                        'old' => $old_value,
                        'new' => $profile[$field],
                        'file' => 'ameba.php'
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
        
        // Avatar güncelle
        if (!empty($profile['avatar'])) {
            $this->save_avatar($user_id, $profile['avatar']);
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
            $this->core->log('error', 'Ameba: Avatar indirilemedi', [
                'error' => $tmp->get_error_message(),
                'url' => $image_url,
                'file' => 'ameba.php'
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'ameba-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Ameba: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message(),
                'file' => 'ameba.php'
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
        $this->core->log('info', 'Ameba: Avatar kaydedildi', [
            'user_id' => $user_id,
            'attachment_id' => $attachment_id,
            'file' => 'ameba.php'
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
                    background: linear-gradient(135deg, #FF9900 0%, #CC7A00 100%);
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
                    background: #FF9900;
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
                    background: #FF9900;
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
                    background: #CC7A00;
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