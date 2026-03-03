<?php
/**
 * AI Community Provider: Signal
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_OAuth2_Provider')) {
    require_once dirname(__FILE__) . '/../oauth2-base.php';
}

class AI_Community_Provider_Signal extends AI_Community_OAuth2_Provider {
    
    protected $config = [];
    protected $version = 'OAuth2';
    public $slug = 'signal';
    protected $scope = '';
    protected $auth_url = 'https://signal.org/oauth/authorize'; // Signal'in resmi OAuth2 desteği yok
    protected $token_url = 'https://signal.org/oauth/token';
    protected $userinfo_url = 'https://signal.org/api/v1/users/me';
    protected $signal_server = 'https://chat.signal.org';
    protected $bot_token = '';
    
    /**
     * Constructor - 3 parametreli olmalı
     */
    public function __construct($id, $config = [], $core = null) {
        $this->id = $id;
        $this->config = $config;
        $this->core = $core;
        
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        
        if (isset($config['redirect_uri'])) {
            $this->redirect_uri = $config['redirect_uri'];
        }
        
        // Signal spesifik ayarlar
        if (isset($config['signal_server'])) {
            $this->signal_server = $config['signal_server'];
        }
        
        if (isset($config['bot_token'])) {
            $this->bot_token = $config['bot_token'];
        }
        
        parent::__construct($id, $config, $core);
    }
    
    /**
     * Handle - PARAMETRESİZ!
     * Ana giriş noktası
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        if (isset($request['code']) && !isset($request['oauth_provider'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth URL - state parametreli
     */
    public function get_auth_url($state = '') {
        // Signal aslında QR kod ile login yapıyor
        // Bu bir OAuth2 benzeri akış değil, webhook tabanlı bir sistem
        $params = [
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri,
            'scope' => $this->get_scope(),
            'signal_flow' => 'qr_code' // QR kod ile bağlantı
        ];
        
        if (!empty($state)) {
            $params['state'] = $state;
        } else {
            $params['state'] = wp_create_nonce('signal_oauth_state');
        }
        
        return $this->auth_url . '?' . http_build_query($params);
    }
    
    public function get_token_url() {
        return $this->token_url;
    }
    
    public function get_userinfo_url() {
        return $this->userinfo_url;
    }
    
    public function get_token_params($code) {
        return [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'redirect_uri' => $this->redirect_uri
        ];
    }
    
    public function get_token_method() {
        return 'POST';
    }
    
    public function get_token_headers() {
        return [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];
    }
    
    public function parse_access_token($response) {
        if (is_array($response) && isset($response['access_token'])) {
            return $response['access_token'];
        }
        return null;
    }
    
    public function parse_refresh_token($response) {
        if (is_array($response) && isset($response['refresh_token'])) {
            return $response['refresh_token'];
        }
        return null;
    }
    
    public function parse_expires_in($response) {
        if (is_array($response) && isset($response['expires_in'])) {
            return (int) $response['expires_in'];
        }
        return 3600; // Varsayılan 1 saat
    }
    
    public function get_userinfo_method() {
        return 'GET';
    }
    
    public function get_userinfo_headers($token) {
        return [
            'Authorization' => 'Bearer ' . $token,
            'X-Signal-Agent' => 'AI-Community/1.0'
        ];
    }
    
    public function get_userinfo_params($token) {
        return [
            'timestamp' => time(),
            'include_profile' => 'true'
        ];
    }
    
    public function get_userinfo_params_with_token($token) {
        return [
            'timestamp' => time(),
            'include_profile' => 'true'
        ];
    }
    
    public function map_userinfo_to_profile($userinfo) {
        // Signal API response formatı
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => '',
            'username' => '',
            'display_name' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'avatar' => '',
            'profile_url' => '',
            'phone' => '',
            'locale' => '',
            'is_verified' => false,
            'registration_id' => '',
            'device_id' => ''
        ];
        
        if (isset($userinfo['uuid'])) {
            $profile['user_id'] = (string) $userinfo['uuid'];
        } elseif (isset($userinfo['id'])) {
            $profile['user_id'] = (string) $userinfo['id'];
        }
        
        if (isset($userinfo['username'])) {
            $profile['username'] = $userinfo['username'];
        } elseif (isset($userinfo['number'])) {
            $profile['username'] = $userinfo['number'];
        }
        
        // Profil bilgileri
        if (isset($userinfo['profile'])) {
            if (isset($userinfo['profile']['displayName'])) {
                $profile['display_name'] = $userinfo['profile']['displayName'];
            }
            if (isset($userinfo['profile']['givenName'])) {
                $profile['first_name'] = $userinfo['profile']['givenName'];
            }
            if (isset($userinfo['profile']['familyName'])) {
                $profile['last_name'] = $userinfo['profile']['familyName'];
            }
            if (isset($userinfo['profile']['avatar'])) {
                $profile['avatar'] = $userinfo['profile']['avatar'];
            }
        } elseif (isset($userinfo['name'])) {
            $profile['display_name'] = $userinfo['name'];
        }
        
        if (isset($userinfo['email'])) {
            $profile['email'] = $userinfo['email'];
        }
        
        if (isset($userinfo['number'])) {
            $profile['phone'] = $userinfo['number'];
        }
        
        if (isset($userinfo['locale'])) {
            $profile['locale'] = $userinfo['locale'];
        }
        
        if (isset($userinfo['verified'])) {
            $profile['is_verified'] = (bool) $userinfo['verified'];
        }
        
        if (isset($userinfo['registrationId'])) {
            $profile['registration_id'] = $userinfo['registrationId'];
        }
        
        if (isset($userinfo['deviceId'])) {
            $profile['device_id'] = $userinfo['deviceId'];
        }
        
        // Display name yoksa username kullan
        if (empty($profile['display_name']) && !empty($profile['username'])) {
            $profile['display_name'] = $profile['username'];
        }
        
        return $profile;
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     */
    public function handle_callback($request) {
        if (isset($request['error'])) {
            return new WP_Error(
                'oauth_error', 
                isset($request['error_description']) ? $request['error_description'] : $request['error']
            );
        }
        
        if (!isset($request['code'])) {
            return new WP_Error('no_code', 'Authorization code not received');
        }
        
        // State kontrolü
        if (!isset($request['state']) || !wp_verify_nonce($request['state'], 'signal_oauth_state')) {
            return new WP_Error('invalid_state', 'Invalid state parameter');
        }
        
        try {
            // Signal spesifik: QR kod tarama sonucunu bekle
            $device_link = $this->wait_for_device_link($request['code']);
            
            if (is_wp_error($device_link)) {
                return $device_link;
            }
            
            // Token al
            $token_response = $this->get_access_token($request['code']);
            
            if (is_wp_error($token_response)) {
                return $token_response;
            }
            
            $token = $this->parse_access_token($token_response);
            
            if (!$token) {
                return new WP_Error('no_token', 'Access token not found');
            }
            
            // Userinfo al
            $userinfo = $this->get_userinfo($token);
            
            if (is_wp_error($userinfo)) {
                return $userinfo;
            }
            
            // Profil oluştur
            $profile = $this->map_userinfo_to_profile($userinfo);
            
            // Kullanıcı bul veya oluştur
            $user = $this->find_or_create_user($profile);
            
            if (is_wp_error($user)) {
                return $user;
            }
            
            // Profili döndür (user_id'yi de ekle)
            $profile['user_id'] = is_numeric($user) ? $user : $user->ID;
            
            return $profile;
            
        } catch (Exception $e) {
            return new WP_Error('callback_error', $e->getMessage());
        }
    }
    
    /**
     * Auth handler - QR kod göster
     */
    public function handle_auth($request) {
        $state = wp_generate_password(12, false);
        $auth_url = $this->get_auth_url($state);
        
        // QR kod sayfasına yönlendir
        if (headers_sent()) {
            echo '<!DOCTYPE html>
            <html>
            <head>
                <title>Signal ile Bağlan</title>
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f5f5f5; }
                    .container { text-align: center; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; }
                    .qr-code { margin: 20px 0; }
                    .qr-code img { max-width: 300px; height: auto; border: 1px solid #ddd; border-radius: 8px; }
                    .instructions { color: #666; font-size: 14px; line-height: 1.6; text-align: left; margin: 20px 0; }
                    .button { background: #3a76f0; color: white; border: none; padding: 12px 24px; border-radius: 6px; font-size: 16px; cursor: pointer; text-decoration: none; display: inline-block; }
                    .button:hover { background: #2a5fd0; }
                    .note { color: #999; font-size: 12px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Signal ile Bağlan</h2>
                    <div class="qr-code">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($auth_url) . '" alt="QR Kod">
                    </div>
                    <div class="instructions">
                        <p><strong>Bağlantı Adımları:</strong></p>
                        <p>1. Signal uygulamanızı açın<br>
                        2. Ayarlar > Bağlı cihazlar > Yeni cihaz bağla<br>
                        3. QR kodu tarayın</p>
                    </div>
                    <p><a href="' . esc_url($auth_url) . '" class="button">Manuel Bağlantı</a></p>
                    <p class="note">QR kod taranana kadar bekleyin. Bu sayfa otomatik olarak yönlendirilecektir.</p>
                </div>
            </body>
            </html>';
            exit;
        } else {
            wp_redirect($auth_url);
            exit;
        }
    }
    
    /**
     * Kullanıcı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        if (empty($profile['user_id']) || empty($profile['provider_slug'])) {
            return new WP_Error('invalid_profile', 'Invalid profile data');
        }
        
        // Önce mevcut bağlantıyı kontrol et
        $user_id = $this->find_user_by_provider($profile['provider_slug'], $profile['user_id']);
        
        if ($user_id) {
            return get_user_by('id', $user_id);
        }
        
        // Telefon numarası ile kullanıcı ara (Signal için önemli)
        if (!empty($profile['phone'])) {
            $users = get_users([
                'meta_key' => 'ai_community_signal_phone',
                'meta_value' => $profile['phone'],
                'number' => 1
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
                // Bağlantıyı kaydet
                $this->save_provider_user_id($user->ID, $profile['provider_slug'], $profile['user_id']);
                return $user;
            }
        }
        
        // Email ile kullanıcı ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                // Bağlantıyı kaydet
                $this->save_provider_user_id($user->ID, $profile['provider_slug'], $profile['user_id']);
                return $user;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $password = wp_generate_password();
        $email = !empty($profile['email']) ? $profile['email'] : $profile['provider_slug'] . '_' . $profile['user_id'] . '@signal.local';
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Kullanıcı bilgilerini güncelle
        $user_data = [
            'ID' => $user_id,
            'display_name' => $profile['display_name'],
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name']
        ];
        
        wp_update_user($user_data);
        
        // Bağlantıyı kaydet
        $this->save_provider_user_id($user_id, $profile['provider_slug'], $profile['user_id']);
        
        // Signal spesifik bilgileri kaydet
        if (!empty($profile['phone'])) {
            update_user_meta($user_id, 'ai_community_signal_phone', $profile['phone']);
        }
        
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
        }
        
        if (!empty($profile['registration_id'])) {
            update_user_meta($user_id, 'ai_community_signal_registration_id', $profile['registration_id']);
        }
        
        if (!empty($profile['device_id'])) {
            update_user_meta($user_id, 'ai_community_signal_device_id', $profile['device_id']);
        }
        
        return get_user_by('id', $user_id);
    }
    
    /**
     * Provider'a göre kullanıcı bul
     */
    protected function find_user_by_provider($provider_slug, $provider_user_id) {
        global $wpdb;
        
        $meta_key = 'ai_community_' . $provider_slug . '_id';
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
            $meta_key,
            $provider_user_id
        ));
        
        return $user_id ? (int) $user_id : null;
    }
    
    /**
     * Provider kullanıcı ID'sini kaydet
     */
    protected function save_provider_user_id($user_id, $provider_slug, $provider_user_id) {
        $meta_key = 'ai_community_' . $provider_slug . '_id';
        update_user_meta($user_id, $meta_key, $provider_user_id);
    }
    
    /**
     * Unique username oluştur
     */
    protected function generate_unique_username($profile) {
        $base = !empty($profile['username']) ? $profile['username'] : 
                (!empty($profile['display_name']) ? $profile['display_name'] : 
                $profile['provider_slug'] . '_user');
        
        $username = sanitize_user($base, true);
        $username = str_replace(' ', '_', strtolower($username));
        $username = preg_replace('/[^a-z0-9_]/', '', $username);
        
        if (empty($username)) {
            $username = $profile['provider_slug'] . '_' . $profile['user_id'];
        }
        
        $original = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $original . '_' . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Signal cihaz bağlantısını bekle
     */
    private function wait_for_device_link($session_id) {
        // Signal API'ye device link isteği gönder
        $device_link_url = $this->signal_server . '/v1/devices/link';
        
        $response = wp_remote_post($device_link_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Signal-Agent' => 'AI-Community/1.0'
            ],
            'body' => json_encode([
                'sessionId' => $session_id,
                'deviceName' => 'AI Community Integration'
            ]),
            'timeout' => 60 // 60 saniye bekle
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('device_link_error', $body['error']);
        }
        
        return $body;
    }
    
    /**
     * Signal mesaj gönderme (isteğe bağlı)
     */
    public function send_message($phone_number, $message) {
        if (empty($this->bot_token)) {
            return new WP_Error('no_bot_token', 'Signal bot token required');
        }
        
        $message_url = $this->signal_server . '/v1/messages';
        
        $response = wp_remote_post($message_url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->bot_token
            ],
            'body' => json_encode([
                'recipient' => $phone_number,
                'message' => $message
            ])
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return json_decode(wp_remote_retrieve_body($response), true);
    }
    
    public function get_scope() {
        return $this->scope;
    }
    
    public function get_name() {
        return 'Signal';
    }
    
    public function get_type() {
        return 'oauth2';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}