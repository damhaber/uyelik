<?php
/**
 * Kakao Story OAuth2 Provider
 * 
 * Kakao Story (카카오스토리) - Güney Kore'nin en popüler sosyal ağı
 * Kakao Corp. tarafından geliştirilen fotoğraf ve video paylaşım platformu
 * 
 * @package AI_Community
 * @subpackage Providers
 */

class AI_Community_Provider_Kakao_Story extends AI_Community_OAuth2_Provider {
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'kakao-story';
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'Kakao Story';
    
    /**
     * API Domain'leri
     */
    const KAUTH_DOMAIN = 'https://kauth.kakao.com';
    const KAPI_DOMAIN = 'https://kapi.kakao.com';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider config
     * @param object $core Core framework instance
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Kakao API endpoints
        $this->oauth_domain = self::KAUTH_DOMAIN;
        $this->api_domain = self::KAPI_DOMAIN;
        
        // OAuth2 endpoints
        $this->auth_url = $this->oauth_domain . '/oauth/authorize';
        $this->token_url = $this->oauth_domain . '/oauth/token';
        
        // API endpoints
        $this->userinfo_url = $this->api_domain . '/v1/user/me';
        $this->story_url = $this->api_domain . '/v1/api/story';
        $this->profile_url = $this->api_domain . '/v1/api/story/profile';
        $this->posts_url = $this->api_domain . '/v1/api/story/mystories';
        $this->linkinfo_url = $this->api_domain . '/v1/api/story/linkinfo';
        
        // Scope'lar - Kakao Story için gerekli izinler
        $this->scope = 'talk_message story_profile story_read story_write';
        
        // Response type
        $this->response_type = 'code';
        $this->grant_type = 'authorization_code';
        
        // Format
        $this->format = 'json';
        
        // Kakao özel ayarlar
        $this->kakao_params = [
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => $this->response_type,
            'scope' => $this->scope
        ];
        
        // Service terms (Kakao Story için özel)
        $this->service_terms = $config['service_terms'] ?? 'story';
        
        // Admin key (story yazma için)
        $this->admin_key = $config['admin_key'] ?? '';
    }
    
    /**
     * Ana handler
     * Tüm istekler buraya gelir (parametresiz)
     * 
     * @return string|void HTML çıktı veya redirect
     */
    public function handle() {
        $params = array_merge($_GET, $_POST);
        
        // Debug log
        $this->core->log('kakao_story_handle_called', [
            'params' => array_keys($params),
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        
        // Hata varsa
        if (isset($params['error'])) {
            $error = $params['error_description'] ?? $params['error'];
            return $this->show_error('카카오스토리 인증 실패 / Kakao Story yetkilendirmesi başarısız: ' . $error);
        }
        
        // Callback (code ile döndüyse)
        if (isset($params['code'])) {
            return $this->handle_callback($params);
        }
        
        // İlk giriş - OAuth2 yönlendirmesi
        $state = wp_create_nonce('ai_community_kakao_story_state');
        $auth_url = $this->get_auth_url($state);
        
        $this->core->log('kakao_story_redirect', ['url' => $auth_url]);
        
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
        if (!wp_verify_nonce($state, 'ai_community_kakao_story_state')) {
            return $this->show_error('상태 확인 실패 / State doğrulaması başarısız.');
        }
        
        // Access token al
        $token_response = $this->get_access_token($code);
        
        if (is_wp_error($token_response)) {
            return $this->show_error('액세스 토큰 획득 실패 / Access token alınamadı: ' . $token_response->get_error_message());
        }
        
        // Kullanıcı bilgilerini al
        $user_info = $this->get_userinfo($token_response);
        
        if (is_wp_error($user_info)) {
            return $this->show_error('사용자 정보 획득 실패 / Kullanıcı bilgileri alınamadı: ' . $user_info->get_error_message());
        }
        
        // Story bilgilerini al
        $story_info = $this->get_story_info($token_response);
        
        // Profil oluştur
        $profile = $this->map_userinfo_to_profile($user_info, $token_response, $story_info);
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id) {
            return $this->show_error('사용자 생성 실패 / Kullanıcı oluşturulamadı.');
        }
        
        // Oturum aç
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        // Profil resmini kaydet
        if (!empty($profile['profile_image'])) {
            $this->save_avatar($user_id, $profile['profile_image']);
        }
        
        // Thumbnail kaydet
        if (!empty($profile['thumbnail_image'])) {
            update_user_meta($user_id, 'kakao_story_thumbnail', $profile['thumbnail_image']);
        }
        
        // Story istatistiklerini kaydet
        if (!empty($profile['story_stats'])) {
            update_user_meta($user_id, 'kakao_story_stats', $profile['story_stats']);
        }
        
        // Log
        $this->core->log('kakao_story_login_success', [
            'user_id' => $user_id,
            'kakao_id' => $profile['id']
        ]);
        
        // Yönlendir
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
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => $this->response_type,
            'scope' => $this->scope,
            'state' => $state,
            'service_terms' => $this->service_terms
        ];
        
        // Kakao özel parametreler
        if (!empty($this->config['through_talk'])) {
            $params['through_talk'] = $this->config['through_talk'] ? 'true' : 'false';
        }
        
        if (!empty($this->config['kauth_server'])) {
            $params['kauth_server'] = $this->config['kauth_server'];
        }
        
        return add_query_arg(array_filter($params), $this->auth_url);
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
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code
        ];
        
        $response = wp_remote_post($this->token_url, [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('kakao_token_error', $data['error_description'] ?? $data['error']);
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
            'token_type' => $response['token_type'] ?? 'Bearer',
            'refresh_token' => $response['refresh_token'] ?? '',
            'expires_in' => $response['expires_in'] ?? 3600, // 1 saat
            'scope' => $response['scope'] ?? '',
            'refresh_token_expires_in' => $response['refresh_token_expires_in'] ?? 2592000 // 30 gün
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
            'redirect_uri' => $this->config['redirect_uri'],
            'code' => $code
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
     * Kullanıcı bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array|WP_Error Kullanıcı bilgileri
     */
    public function get_userinfo($token) {
        $response = wp_remote_get($this->userinfo_url, [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => $this->get_userinfo_headers($token)
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['code']) && $data['code'] != 0) {
            return new WP_Error('kakao_userinfo_error', $data['msg'] ?? 'Bilinmeyen hata');
        }
        
        return $data;
    }
    
    /**
     * Story bilgilerini al
     * 
     * @param array $token Token bilgileri
     * @return array Story bilgileri
     */
    protected function get_story_info($token) {
        $story_info = [];
        
        // Story profil bilgileri
        $profile_response = wp_remote_get($this->profile_url, [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => $this->get_userinfo_headers($token)
        ]);
        
        if (!is_wp_error($profile_response)) {
            $profile_body = wp_remote_retrieve_body($profile_response);
            $profile_data = json_decode($profile_body, true);
            
            if (!isset($profile_data['code']) || $profile_data['code'] == 0) {
                $story_info['profile'] = $profile_data;
            }
        }
        
        // Son story'leri al
        $posts_response = wp_remote_get($this->posts_url . '?limit=5', [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => $this->get_userinfo_headers($token)
        ]);
        
        if (!is_wp_error($posts_response)) {
            $posts_body = wp_remote_retrieve_body($posts_response);
            $posts_data = json_decode($posts_body, true);
            
            if (!isset($posts_data['code']) || $posts_data['code'] == 0) {
                $story_info['recent_posts'] = $posts_data['stories'] ?? [];
                $story_info['post_count'] = $posts_data['total_count'] ?? 0;
            }
        }
        
        return $story_info;
    }
    
    /**
     * Kullanıcı bilgisi HTTP methodu
     * 
     * @return string HTTP method
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
            'Authorization' => $token['token_type'] . ' ' . $token['access_token'],
            'Content-Type' => 'application/x-www-form-urlencoded;charset=utf-8',
            'KAAPI-Client' => 'WordPress/' . get_bloginfo('version')
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
            'property_keys' => '["kakao_account.profile","kakao_account.name","kakao_account.email","kakao_account.age_range","kakao_account.gender","kakao_account.birthday","kakao_account.birthyear","kakao_account.phone_number","kakao_account.ci"]'
        ];
    }
    
    /**
     * API yanıtını profile çevir
     * 
     * @param array $data API yanıtı
     * @param array $token Token bilgileri
     * @param array $story_info Story bilgileri
     * @return array Profil bilgileri
     */
    public function map_userinfo_to_profile($data, $token = [], $story_info = []) {
        $profile_data = $data['kakao_account'] ?? [];
        $properties = $data['properties'] ?? [];
        
        // Temel profil
        $profile = [
            'id' => $data['id'] ?? '',
            'provider' => 'kakao-story',
            'display_name' => $properties['nickname'] ?? ($profile_data['profile']['nickname'] ?? '카카오스토리 사용자'),
            'email' => $profile_data['email'] ?? '',
            'phone_number' => $profile_data['phone_number'] ?? '',
            'name' => $profile_data['name'] ?? '',
            'profile_image' => $properties['profile_image'] ?? ($profile_data['profile']['profile_image_url'] ?? ''),
            'thumbnail_image' => $properties['thumbnail_image'] ?? ($profile_data['profile']['thumbnail_image_url'] ?? ''),
            'gender' => $this->map_gender($profile_data['gender'] ?? ''),
            'age_range' => $profile_data['age_range'] ?? '',
            'birthday' => $profile_data['birthday'] ?? '',
            'birthyear' => $profile_data['birthyear'] ?? '',
            'ci' => $profile_data['ci'] ?? '',
            'ci_authenticated_at' => $profile_data['ci_authenticated_at'] ?? '',
            'is_email_valid' => $profile_data['is_email_valid'] ?? false,
            'is_email_verified' => $profile_data['is_email_verified'] ?? false,
            'is_kakaotalk_user' => $data['has_signed_up']['kakaotalk'] ?? false,
            'is_story_user' => $data['has_signed_up']['story'] ?? true,
            'connected_at' => $data['connected_at'] ?? ''
        ];
        
        // Story profil bilgileri
        if (!empty($story_info['profile'])) {
            $story_profile = $story_info['profile'];
            $profile['story_profile'] = [
                'nickname' => $story_profile['nickName'] ?? '',
                'profile_image_url' => $story_profile['profileImageURL'] ?? '',
                'thumbnail_url' => $story_profile['thumbnailURL'] ?? '',
                'bg_image_url' => $story_profile['bgImageURL'] ?? '',
                'permalink' => $story_profile['permalink'] ?? '',
                'birthday' => $story_profile['birthday'] ?? '',
                'birthday_type' => $story_profile['birthdayType'] ?? ''
            ];
        }
        
        // Story istatistikleri
        if (!empty($story_info)) {
            $profile['story_stats'] = [
                'post_count' => $story_info['post_count'] ?? 0,
                'recent_posts' => array_map([$this, 'map_story_post'], $story_info['recent_posts'] ?? [])
            ];
        }
        
        return $profile;
    }
    
    /**
     * Story post'larını map et
     * 
     * @param array $post Story post verisi
     * @return array Map edilmiş post
     */
    protected function map_story_post($post) {
        return [
            'id' => $post['id'] ?? '',
            'url' => $post['url'] ?? '',
            'media_type' => $post['media_type'] ?? '', // photo, video, note
            'content' => $post['content'] ?? '',
            'created_at' => $post['created_at'] ?? '',
            'comment_count' => $post['comment_count'] ?? 0,
            'like_count' => $post['like_count'] ?? 0,
            'media' => $post['media'] ?? []
        ];
    }
    
    /**
     * Cinsiyet mapping
     * 
     * @param string $gender Kakao cinsiyet kodu
     * @return string Cinsiyet
     */
    protected function map_gender($gender) {
        switch ($gender) {
            case 'male':
            case 'M':
            case '남성':
                return 'male';
            case 'female':
            case 'F':
            case '여성':
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
     * Kullanıcı bul veya oluştur
     * 
     * @param array $profile Profil bilgileri
     * @return int|false Kullanıcı ID veya false
     */
    protected function find_or_create_user($profile) {
        // 1. Kakao ID'ye göre ara (birincil)
        $users = get_users([
            'meta_key' => 'ai_comm_provider_kakao_story_id',
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
                update_user_meta($user->ID, 'ai_comm_provider_kakao_story_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 3. Telefona göre ara
        if (!empty($profile['phone_number'])) {
            $users = get_users([
                'meta_key' => 'phone',
                'meta_value' => $profile['phone_number'],
                'number' => 1
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
                update_user_meta($user->ID, 'ai_comm_provider_kakao_story_id', $profile['id']);
                $this->update_user_meta($user->ID, $profile);
                return $user->ID;
            }
        }
        
        // 4. Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile['display_name'] ?? 'kakaouser');
        
        // Email oluştur (yoksa)
        if (empty($profile['email'])) {
            $email = 'kakao_' . $profile['id'] . '@kakao.com';
        } else {
            $email = $profile['email'];
        }
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('kakao_story_user_creation_failed', [
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
        update_user_meta($user_id, 'ai_comm_provider_kakao_story_id', $profile['id']);
        update_user_meta($user_id, 'ai_community_provider', 'kakao-story');
        update_user_meta($user_id, 'kakao_display_name', $profile['display_name']);
        update_user_meta($user_id, 'kakao_name', $profile['name']);
        
        if (!empty($profile['email'])) {
            update_user_meta($user_id, 'kakao_email', $profile['email']);
        }
        
        if (!empty($profile['phone_number'])) {
            update_user_meta($user_id, 'kakao_phone', $profile['phone_number']);
            update_user_meta($user_id, 'phone', $profile['phone_number']);
        }
        
        if (!empty($profile['gender'])) {
            update_user_meta($user_id, 'kakao_gender', $profile['gender']);
        }
        
        if (!empty($profile['age_range'])) {
            update_user_meta($user_id, 'kakao_age_range', $profile['age_range']);
        }
        
        if (!empty($profile['birthday'])) {
            update_user_meta($user_id, 'kakao_birthday', $profile['birthday']);
        }
        
        if (!empty($profile['birthyear'])) {
            update_user_meta($user_id, 'kakao_birthyear', $profile['birthyear']);
        }
        
        // Kimlik bilgileri (CI - Connecting Information)
        if (!empty($profile['ci'])) {
            update_user_meta($user_id, 'kakao_ci', $profile['ci']);
            update_user_meta($user_id, 'kakao_ci_authenticated_at', $profile['ci_authenticated_at']);
        }
        
        // Profil resimleri
        if (!empty($profile['profile_image'])) {
            update_user_meta($user_id, 'kakao_profile_image', $profile['profile_image']);
        }
        
        if (!empty($profile['thumbnail_image'])) {
            update_user_meta($user_id, 'kakao_thumbnail_image', $profile['thumbnail_image']);
        }
        
        // Story profili
        if (!empty($profile['story_profile'])) {
            update_user_meta($user_id, 'kakao_story_profile', $profile['story_profile']);
        }
        
        // Story istatistikleri
        if (!empty($profile['story_stats'])) {
            update_user_meta($user_id, 'kakao_story_stats', $profile['story_stats']);
        }
        
        // Hesap durumu
        update_user_meta($user_id, 'kakao_is_kakaotalk_user', $profile['is_kakaotalk_user'] ? 'yes' : 'no');
        update_user_meta($user_id, 'kakao_is_story_user', $profile['is_story_user'] ? 'yes' : 'no');
        update_user_meta($user_id, 'kakao_connected_at', $profile['connected_at']);
        
        // Son giriş
        update_user_meta($user_id, 'kakao_last_login', time());
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
            $base = 'kakaostory' . wp_rand(100, 999);
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
        
        return 'kakao_' . $profile['id'] . '@kakao.com';
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
            $this->core->log('kakao_story_avatar_download_failed', [
                'error' => $tmp->get_error_message()
            ]);
            return false;
        }
        
        $file_array = [
            'name' => 'kakao-story-avatar-' . $user_id . '.jpg',
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
        update_user_meta($user_id, 'kakao_story_avatar_id', $attachment_id);
        
        return true;
    }
    
    /**
     * Refresh token yenile
     * 
     * @param string $refresh_token Refresh token
     * @return array|WP_Error Yeni token bilgileri
     */
    public function refresh_access_token($refresh_token) {
        $params = [
            'grant_type' => 'refresh_token',
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refresh_token
        ];
        
        $response = wp_remote_post($this->token_url, [
            'timeout' => 30,
            'sslverify' => false,
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            return new WP_Error('kakao_refresh_error', $data['error_description'] ?? $data['error']);
        }
        
        return $this->parse_access_token($data);
    }
    
    /**
     * Kakao Story'ye post paylaş (opsiyonel)
     * 
     * @param int $user_id Kullanıcı ID
     * @param string $content Post içeriği
     * @param array $media Medya dosyaları
     * @return array|WP_Error Paylaşım sonucu
     */
    public function post_to_story($user_id, $content, $media = []) {
        // Kullanıcının token'ını al
        $access_token = get_user_meta($user_id, 'kakao_access_token', true);
        
        if (empty($access_token)) {
            return new WP_Error('kakao_story_post_error', 'No access token found');
        }
        
        $params = [
            'content' => $content
        ];
        
        // Varsa medya ekle
        if (!empty($media)) {
            $params['media'] = json_encode($media);
        }
        
        $response = wp_remote_post($this->story_url . '/post/note', [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => $params
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['code']) && $data['code'] != 0) {
            return new WP_Error('kakao_story_post_error', $data['msg'] ?? 'Unknown error');
        }
        
        return $data;
    }
    
    /**
     * Hata mesajı göster (Korece/İngilizce/Türkçe)
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
            <title>카카오스토리 인증 오류 / Kakao Story Yetkilendirme Hatası</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #FEE500 0%, #FFD200 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .error-container {
                    max-width: 450px;
                    width: 100%;
                }
                
                .error-card {
                    background: white;
                    border-radius: 24px;
                    box-shadow: 0 30px 70px rgba(0,0,0,0.15);
                    overflow: hidden;
                }
                
                .error-header {
                    background: #FEE500;
                    color: #3c1e1e;
                    padding: 40px 30px;
                    text-align: center;
                    position: relative;
                }
                
                .error-header::before {
                    content: '카카오스토리';
                    position: absolute;
                    bottom: 10px;
                    right: 20px;
                    font-size: 36px;
                    opacity: 0.1;
                    font-weight: bold;
                    color: #3c1e1e;
                }
                
                .error-header .logo {
                    font-size: 48px;
                    margin-bottom: 15px;
                }
                
                .error-header h1 {
                    font-size: 28px;
                    margin-bottom: 10px;
                    font-weight: 600;
                }
                
                .error-header p {
                    opacity: 0.8;
                    font-size: 14px;
                }
                
                .error-body {
                    padding: 35px 30px;
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
                    word-break: break-word;
                }
                
                .btn {
                    display: block;
                    width: 100%;
                    padding: 16px;
                    background: #FEE500;
                    color: #3c1e1e;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 12px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                    border: none;
                    cursor: pointer;
                }
                
                .btn:hover {
                    background: #FFD200;
                    transform: translateY(-2px);
                    box-shadow: 0 10px 25px rgba(254, 229, 0, 0.3);
                }
                
                .error-details {
                    font-size: 12px;
                    color: #999;
                    text-align: center;
                    margin-top: 20px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
                
                .kakao-corp {
                    display: flex;
                    justify-content: center;
                    margin-top: 10px;
                    font-size: 12px;
                    color: rgba(60, 30, 30, 0.6);
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <div class="logo">📸</div>
                        <h1>Kakao Story</h1>
                        <p>인증 오류 / Yetkilendirme Hatası</p>
                        <div class="kakao-corp">
                            Kakao Corp.
                        </div>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <a href="<?php echo esc_url(home_url()); ?>" class="btn">
                            홈으로 돌아가기 / Ana Sayfaya Dön
                        </a>
                        <div class="error-details">
                            <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
                                <p>Error Time: <?php echo date('Y-m-d H:i:s'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}