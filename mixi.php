<?php
/**
 * AI Community Engine - Mixi Provider
 * 
 * Mixi OAuth2 Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/mixi.php
 * 
 * Sorumluluklar:
 * - Mixi OAuth2 akışını yönetme
 * - Mixi Graph API ile kullanıcı bilgilerini alma
 * - Kullanıcı profili, arkadaşlar ve yazılar için yetkilendirme
 * - Kullanıcı oluşturma ve bağlama
 * 
 * API Dokümantasyonu:
 * - Mixi Developer: https://developer.mixi.co.jp/
 * - Graph API: https://developer.mixi.co.jp/connect/mixi_graph_api/
 * - Scopes: r_profile, r_profile_name, r_profile_profile, r_voice, w_voice, r_friend, r_update
 */

class AI_Community_Provider_Mixi extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'mixi';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'mixi';
    
    /**
     * OAuth2 endpoint'leri
     * Mixi API endpoints
     */
    protected $auth_url = 'https://mixi.jp/connect_authorize.pl';
    protected $token_url = 'https://api.mixi-platform.com/v2/token';
    protected $userinfo_url = 'https://api.mixi-platform.com/v2/people/@me/@self';
    
    /**
     * Varsayılan scope'lar
     * - r_profile: プロフィール読み取り（基本）
     * - r_profile_name: 名前の読み取り
     * - r_profile_profile: プロフィール詳細
     * - r_voice: ボイス（つぶやき）の読み取り
     */
    protected $scope = 'r_profile r_profile_name';
    
    /**
     * Mixi API response format
     */
    const API_VERSION = 'v2';
    const DATA_FORMAT = 'json';
    
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
        $this->core->log('debug', 'Mixi provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/mixi.php'
        ]);
        
        /**
         * Mixi özel ayarları:
         * - Client ID: アプリケーションID (Application ID)
         * - Client Secret: シークレットキー (Secret Key)
         * - Redirect URI: https://site.com/?auth={slug}
         * - Display: pc (PC), mobile (モバイル), tablet (タブレット)
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
        $this->core->log('info', 'Mixi handle çağrıldı', [
            'file' => 'mixi.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $params = array_merge($_GET, $_POST);
        
        // Hata kontrolü
        if (isset($params['error'])) {
            $error = $params['error'];
            $error_description = $params['error_description'] ?? 'Bilinmeyen hata';
            
            $this->core->log('error', 'Mixi OAuth hatası', [
                'error' => $error,
                'description' => $error_description,
                'file' => 'mixi.php'
            ]);
            
            return $this->show_error(
                'Mixi bağlantısı sırasında hata: ' . $error . ' - ' . $error_description
            );
        }
        
        // Callback kontrolü (authorization code)
        if (isset($params['code'])) {
            $this->core->log('info', 'Mixi callback alındı', [
                'code_length' => strlen($params['code']),
                'has_state' => isset($params['state']),
                'file' => 'mixi.php'
            ]);
            
            return $this->handle_callback($params);
        }
        
        /**
         * Yeni yetkilendirme isteği
         * State: CSRF koruması için nonce
         */
        $state = wp_create_nonce('ai_community_' . $this->slug . '_state');
        
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('info', 'Mixi yetkilendirme URL\'ine yönlendiriliyor', [
            'auth_url' => $auth_url,
            'file' => 'mixi.php'
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
         * Mixi özel parametreleri:
         * - display: pc (PCサイト), mobile (モバイルサイト), tablet (タブレット)
         * - view: profile (プロフィールのみ), friend (友人のみ), all (すべて)
         */
        if (isset($this->config['display'])) {
            $params['display'] = $this->config['display'];
        } else {
            $params['display'] = 'pc'; // Varsayılan PC görünümü
        }
        
        if (isset($this->config['view'])) {
            $params['view'] = $this->config['view'];
        }
        
        $url = add_query_arg($params, $this->auth_url);
        
        $this->core->log('debug', 'Mixi auth URL oluşturuldu', [
            'url' => $url,
            'params' => array_keys($params),
            'file' => 'mixi.php'
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
        
        $this->core->log('error', 'Mixi access token bulunamadı', [
            'response_keys' => array_keys($response),
            'file' => 'mixi.php'
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
        // Mixi ek parametre gerektirmez
        return [];
    }
    
    /**
     * API yanıtını profil array'ine dönüştür
     * 
     * Mixi Graph API yanıt yapısı:
     * {
     *   "entry": {
     *     "id": "1234567890",
     *     "displayName": "山田 太郎",
     *     "name": {
     *       "familyName": "山田",
     *       "givenName": "太郎",
     *       "formatted": "山田 太郎"
     *     },
     *     "thumbnailUrl": "https://...",
     *     "profileUrl": "https://mixi.jp/show_profile.pl?id=1234567890",
     *     "birthday": "1990-01-01",
     *     "gender": "male",
     *     "age": 33,
     *     "address": {
     *       "country": "JP",
     *       "region": "東京都",
     *       "locality": "渋谷区"
     *     },
     *     "emails": [
     *       {
     *         "value": "taro@example.com",
     *         "type": "primary",
     *         "verified": true
     *       }
     *     ],
     *     "aboutMe": "自己紹介文",
     *     "relationshipStatus": "single",
     *     "languages": ["ja"],
     *     "timezone": "+09:00",
     *     "utcOffset": "+09:00",
     *     "published": "2008-01-01T00:00:00+09:00",
     *     "updated": "2024-01-01T00:00:00+09:00"
     *   }
     * }
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri (opsiyonel)
     * @return array Standart profil array'i
     */
    public function map_userinfo_to_profile($data, $token = []) {
        $this->core->log('debug', 'Mixi profil mapping başlıyor', [
            'has_entry' => isset($data['entry']),
            'file' => 'mixi.php'
        ]);
        
        $profile = [];
        
        // Mixi response entry içinde gelir
        if (isset($data['entry']) && is_array($data['entry'])) {
            $user_data = $data['entry'];
            
            // Provider bilgileri
            $profile['provider'] = $this->slug;
            $profile['provider_id'] = $user_data['id'] ?? null;
            
            /**
             * Temel profil bilgileri
             */
            // Display name (表示名)
            if (!empty($user_data['displayName'])) {
                $profile['display_name'] = $user_data['displayName'];
            }
            
            // İsim bilgileri (名前)
            if (!empty($user_data['name'])) {
                $name = $user_data['name'];
                
                if (!empty($name['familyName'])) {
                    $profile['last_name'] = $name['familyName'];
                }
                
                if (!empty($name['givenName'])) {
                    $profile['first_name'] = $name['givenName'];
                }
                
                if (!empty($name['formatted'])) {
                    $profile['formatted_name'] = $name['formatted'];
                }
            }
            
            // Profil fotoğrafı (サムネイル)
            if (!empty($user_data['thumbnailUrl'])) {
                $profile['avatar'] = $user_data['thumbnailUrl'];
            }
            
            // Profil URL'i (プロフィールURL)
            if (!empty($user_data['profileUrl'])) {
                $profile['profile_url'] = $user_data['profileUrl'];
            }
            
            /**
             * Email bilgileri
             */
            if (!empty($user_data['emails']) && is_array($user_data['emails'])) {
                foreach ($user_data['emails'] as $email) {
                    if (isset($email['value']) && ($email['type'] ?? '') === 'primary') {
                        $profile['email'] = $email['value'];
                        $profile['email_verified'] = $email['verified'] ?? false;
                        break;
                    }
                }
                
                // Primary email yoksa ilk email'i al
                if (!isset($profile['email']) && isset($user_data['emails'][0]['value'])) {
                    $profile['email'] = $user_data['emails'][0]['value'];
                    $profile['email_verified'] = $user_data['emails'][0]['verified'] ?? false;
                }
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
            
            // Yaş (年齢)
            if (!empty($user_data['age'])) {
                $profile['age'] = (int)$user_data['age'];
            }
            
            // Cinsiyet (性別)
            if (!empty($user_data['gender'])) {
                $profile['gender'] = strtolower($user_data['gender']);
            }
            
            // Kendini tanıtma (自己紹介)
            if (!empty($user_data['aboutMe'])) {
                $profile['description'] = $user_data['aboutMe'];
            }
            
            // İlişki durumu (交際状況)
            if (!empty($user_data['relationshipStatus'])) {
                $profile['relationship_status'] = $user_data['relationshipStatus'];
            }
            
            /**
             * Adres bilgileri
             */
            if (!empty($user_data['address']) && is_array($user_data['address'])) {
                $address = $user_data['address'];
                
                $profile['address'] = [
                    'country' => $address['country'] ?? 'JP',
                    'region' => $address['region'] ?? '', // 都道府県
                    'locality' => $address['locality'] ?? '' // 市区町村
                ];
                
                // Düz metin adres oluştur
                $address_parts = [];
                if (!empty($profile['address']['region'])) {
                    $address_parts[] = $profile['address']['region'];
                }
                if (!empty($profile['address']['locality'])) {
                    $address_parts[] = $profile['address']['locality'];
                }
                
                $profile['address_formatted'] = implode(' ', $address_parts);
            }
            
            /**
             * Dil ve zaman bilgileri
             */
            if (!empty($user_data['languages'])) {
                $profile['languages'] = $user_data['languages'];
            }
            
            if (!empty($user_data['timezone'])) {
                $profile['timezone'] = $user_data['timezone'];
            }
            
            /**
             * Zaman damgaları
             */
            if (!empty($user_data['published'])) {
                $profile['registered_at'] = $user_data['published']; // mixi kayıt tarihi
            }
            
            if (!empty($user_data['updated'])) {
                $profile['profile_updated_at'] = $user_data['updated'];
            }
            
            /**
             * mixi özel alanları
             */
            // 친구 수 (arkadaş sayısı)
            if (isset($user_data['friendsCount'])) {
                $profile['friends_count'] = (int)$user_data['friendsCount'];
            }
            
            // 日記の数 (günlük sayısı)
            if (isset($user_data['diariesCount'])) {
                $profile['diaries_count'] = (int)$user_data['diariesCount'];
            }
            
            // 写真の数 (fotoğraf sayısı)
            if (isset($user_data['photosCount'])) {
                $profile['photos_count'] = (int)$user_data['photosCount'];
            }
            
            // ボイスの数 (sesli mesaj sayısı)
            if (isset($user_data['voicesCount'])) {
                $profile['voices_count'] = (int)$user_data['voicesCount'];
            }
            
            // コミュニティ数 (topluluk sayısı)
            if (isset($user_data['communitiesCount'])) {
                $profile['communities_count'] = (int)$user_data['communitiesCount'];
            }
            
            // 足あと (ziyaretçi sayısı)
            if (isset($user_data['visitorsCount'])) {
                $profile['visitors_count'] = (int)$user_data['visitorsCount'];
            }
            
            // アクセスランク (erişim sıralaması)
            if (isset($user_data['accessRank'])) {
                $profile['access_rank'] = $user_data['accessRank'];
            }
        }
        
        // Log mapping sonucu
        $this->core->log('info', 'Mixi profil mapping tamamlandı', [
            'has_email' => isset($profile['email']),
            'has_display_name' => isset($profile['display_name']),
            'has_avatar' => isset($profile['avatar']),
            'has_birthdate' => isset($profile['birthdate']),
            'file' => 'mixi.php'
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
            $this->core->log('error', 'Mixi token yenileme hatası', [
                'error' => $response->get_error_message(),
                'file' => 'mixi.php'
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
        $this->core->log('info', 'Mixi: Kullanıcı aranıyor', [
            'provider_id' => $profile['provider_id'],
            'file' => 'mixi.php'
        ]);
        
        // 1. Provider ID'ye göre ara (mixi ID)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_' . $this->slug . '_id',
            'meta_value' => $profile['provider_id'],
            'number' => 1,
            'fields' => 'ID'
        ]);
        
        if (!empty($users)) {
            $user_id = $users[0];
            $this->core->log('info', 'Mixi: Kullanıcı provider ID ile bulundu', [
                'user_id' => $user_id,
                'file' => 'mixi.php'
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
                $this->core->log('info', 'Mixi: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'mixi.php'
                ]);
                
                // Provider ID'yi bağla
                update_user_meta($user_id, 'ai_comm_provider_' . $this->slug . '_id', $profile['provider_id']);
                
                return $user_id;
            }
        }
        
        // 3. Yeni kullanıcı oluştur
        $this->core->log('info', 'Mixi: Yeni kullanıcı oluşturuluyor', [
            'file' => 'mixi.php'
        ]);
        
        // Kullanıcı adı oluştur
        $username = $this->generate_unique_username($profile);
        
        // Email (yoksa dummy email)
        $email = $profile['email'] ?? $this->slug . '_' . md5($profile['provider_id']) . '@social.local';
        
        // Kullanıcı oluştur
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Mixi: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'mixi.php'
            ]);
            return $user_id;
        }
        
        $this->core->log('info', 'Mixi: Yeni kullanıcı oluşturuldu', [
            'user_id' => $user_id,
            'username' => $username,
            'file' => 'mixi.php'
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
        
        // Yoksa formatted name dene
        if (empty($base) && !empty($profile['formatted_name'])) {
            $base = sanitize_user($profile['formatted_name'], true);
        }
        
        // Yoksa first_name + last_name dene
        if (empty($base)) {
            $name_parts = [];
            if (!empty($profile['first_name'])) {
                $name_parts[] = $profile['first_name'];
            }
            if (!empty($profile['last_name'])) {
                $name_parts[] = $profile['last_name'];
            }
            if (!empty($name_parts)) {
                $base = sanitize_user(implode('_', $name_parts), true);
            }
        }
        
        // Hala yoksa provider ID'den oluştur
        if (empty($base)) {
            $base = 'mixi_' . substr($profile['provider_id'], 0, 8);
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
            'formatted_name',
            'description',
            'gender',
            'birthdate',
            'birthyear',
            'birthmonth',
            'birthday',
            'age',
            'relationship_status',
            'profile_url',
            'timezone',
            'address_formatted',
            'friends_count',
            'diaries_count',
            'photos_count',
            'voices_count',
            'communities_count',
            'visitors_count',
            'access_rank',
            'registered_at',
            'profile_updated_at'
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
        
        // Languages array varsa JSON olarak kaydet
        if (isset($profile['languages'])) {
            update_user_meta($user_id, $this->slug . '_languages', json_encode($profile['languages']));
        }
        
        $this->core->log('debug', 'Mixi: Profil metadata kaydedildi', [
            'user_id' => $user_id,
            'fields' => array_keys(array_intersect_key($profile, array_flip($fields))),
            'file' => 'mixi.php'
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
            'formatted_name',
            'description',
            'gender',
            'birthdate',
            'age',
            'relationship_status',
            'profile_url',
            'friends_count',
            'visitors_count',
            'profile_updated_at'
        ];
        
        foreach ($fields as $field) {
            if (isset($profile[$field])) {
                // Eski değerle karşılaştır, değiştiyse güncelle
                $old_value = get_user_meta($user_id, $this->slug . '_' . $field, true);
                if ($old_value !== $profile[$field]) {
                    update_user_meta($user_id, $this->slug . '_' . $field, $profile[$field]);
                    
                    $this->core->log('debug', 'Mixi: Profil alanı güncellendi', [
                        'user_id' => $user_id,
                        'field' => $field,
                        'old' => $old_value,
                        'new' => $profile[$field],
                        'file' => 'mixi.php'
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
            $this->core->log('error', 'Mixi: Avatar indirilemedi', [
                'error' => $tmp->get_error_message(),
                'url' => $image_url,
                'file' => 'mixi.php'
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'mixi-avatar-' . $user_id . '.jpg',
            'tmp_name' => $tmp
        ];
        
        // Medya kütüphanesine ekle
        $attachment_id = media_handle_sideload($file_array, 0);
        
        if (is_wp_error($attachment_id)) {
            @unlink($file_array['tmp_name']);
            $this->core->log('error', 'Mixi: Avatar medya kütüphanesine eklenemedi', [
                'error' => $attachment_id->get_error_message(),
                'file' => 'mixi.php'
            ]);
            return false;
        }
        
        // Kullanıcı avatar'ı olarak ayarla
        update_user_meta($user_id, 'ai_community_avatar', $attachment_id);
        update_user_meta($user_id, $this->slug . '_avatar', $image_url);
        
        $this->core->log('info', 'Mixi: Avatar kaydedildi', [
            'user_id' => $user_id,
            'attachment_id' => $attachment_id,
            'file' => 'mixi.php'
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
                    background: linear-gradient(135deg, #D83C7A 0%, #B13263 100%);
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
                    background: #D83C7A;
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
                    background: #D83C7A;
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
                    background: #B13263;
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