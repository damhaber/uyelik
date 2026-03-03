<?php
/**
 * AI Community Provider: QQ SOS
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/../sos-base.php';
}

class AI_Community_Provider_QQ_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'qq-sos';
    
    // QQ API yapılandırması
    protected $app_id = '';
    protected $app_key = '';
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config = [], $core = null) {
        $this->id = $id;
        $this->config = $config;
        $this->core = $core;
        
        // QQ Open Platform konfigürasyonu
        if (isset($config['qq_app_id'])) {
            $this->app_id = $config['qq_app_id'];
        }
        if (isset($config['qq_app_key'])) {
            $this->app_key = $config['qq_app_key'];
        }
        
        parent::__construct($id, $config, $core);
    }
    
    /**
     * Handle - PARAMETRESİZ!
     * Ana giriş noktası
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        // SOS login handling
        if (isset($request['code']) || isset($request['openid']) || isset($request['phone'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth handler - QR kod ve telefon seçenekleri
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <div class="ai-community-sos-container qq-sos">
            <h2>QQ ile Giriş</h2>
            
            <!-- QR Kod ile Giriş -->
            <div class="qq-qr-section">
                <h3>📱 QR Kod ile Giriş</h3>
                <p class="description">QQ uygulamanız ile QR kodu okutun</p>
                
                <div class="qr-container">
                    <div id="qq-qr-code" class="qq-qr">
                        <?php $this->display_qr_code(); ?>
                    </div>
                    <div class="qr-status" id="qr-status">
                        QR kod bekleniyor...
                    </div>
                </div>
                
                <div class="qr-instructions">
                    <ol>
                        <li>QQ uygulamanızı açın</li>
                        <li>Sağ üst köşedeki '+' butonuna tıklayın</li>
                        <li>"QR Kod Tara" seçeneğini seçin</li>
                        <li>Ekrandaki QR kodu okutun</li>
                        <li>Giriş işlemini onaylayın</li>
                    </ol>
                </div>
            </div>
            
            <div class="qq-divider">
                <span>veya</span>
            </div>
            
            <!-- SMS Alternatif (Telefon doğrulama) -->
            <div class="qq-phone-auth">
                <h3>📞 Telefon Numarası ile Doğrulama</h3>
                <p class="description">QQ hesabınıza kayıtlı telefon numaranızı girin, size SMS ile doğrulama kodu gönderelim.</p>
                
                <form method="post" action="" class="qq-sos-form">
                    <?php wp_nonce_field('qq_sos_auth', 'qq_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="phone">Telefon Numarası:</label>
                        <input type="tel" 
                               id="phone" 
                               name="phone" 
                               placeholder="+86 138 0013 8000" 
                               pattern="^\+?[0-9]{10,15}$"
                               required
                               class="qq-phone-input">
                        <small>Ülke koduyla birlikte girin (Çin: +86)</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group half">
                            <label for="country_code">Ülke:</label>
                            <select name="country_code" id="country_code" class="country-select">
                                <option value="+86">Çin (+86)</option>
                                <option value="+852">Hong Kong (+852)</option>
                                <option value="+853">Makao (+853)</option>
                                <option value="+886">Tayvan (+886)</option>
                                <option value="+90">Türkiye (+90)</option>
                                <option value="+65">Singapur (+65)</option>
                                <option value="+60">Malezya (+60)</option>
                                <option value="+62">Endonezya (+62)</option>
                            </select>
                        </div>
                        
                        <div class="form-group half">
                            <label for="qq_number">QQ Numarası (Opsiyonel):</label>
                            <input type="text" 
                                   id="qq_number" 
                                   name="qq_number" 
                                   placeholder="QQ numaranız varsa girin"
                                   class="qq-number-input">
                        </div>
                    </div>
                    
                    <button type="submit" name="qq_send_code" class="qq-submit-btn">
                        SMS ile Kod Gönder
                    </button>
                </form>
            </div>
            
            <!-- QQ ID ile Giriş -->
            <div class="qq-direct-login">
                <h3>🔑 QQ Numarası ile Giriş</h3>
                <p>QQ numaranız ve şifreniz ile doğrudan giriş yapın</p>
                
                <form method="post" action="" class="qq-direct-form">
                    <?php wp_nonce_field('qq_direct_login', 'direct_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="qq_uid">QQ Numarası:</label>
                        <input type="text" 
                               id="qq_uid" 
                               name="qq_uid" 
                               placeholder="QQ numaranız" 
                               required
                               class="qq-uid-input">
                    </div>
                    
                    <div class="form-group">
                        <label for="qq_password">Şifre:</label>
                        <input type="password" 
                               id="qq_password" 
                               name="qq_password" 
                               placeholder="QQ şifreniz" 
                               required
                               class="qq-password-input">
                    </div>
                    
                    <button type="submit" name="qq_direct_login" class="qq-direct-btn">
                        QQ ile Giriş Yap
                    </button>
                </form>
                
                <p class="note">* Güvenliğiniz için şifreniz kaydedilmez</p>
            </div>
            
            <div class="qq-info">
                <p><strong>QQ hesabınızla giriş yaparak:</strong></p>
                <ul>
                    <li>QQ profiliniz (avatar, isim, yaş) alınır</li>
                    <li>QQ arkadaş listenizle bağlantı kurulabilir</li>
                    <li>QQ Space içeriğinize erişilebilir</li>
                </ul>
                <p class="warning">⚠️ QQ numaranızı ve şifrenizi kimseyle paylaşmayın</p>
            </div>
        </div>
        
        <style>
            .qq-sos {
                max-width: 550px;
                margin: 0 auto;
                padding: 20px;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .qq-sos h2 {
                color: #FF8C00;
                text-align: center;
                margin-bottom: 20px;
            }
            .qq-qr-section {
                text-align: center;
                padding: 20px;
                background: #fff8f0;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .qr-container {
                margin: 20px auto;
                width: 200px;
                height: 200px;
                background: #fff;
                padding: 10px;
                border-radius: 10px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
            .qq-qr {
                width: 100%;
                height: 100%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .qr-status {
                margin-top: 10px;
                color: #FF8C00;
                font-size: 14px;
            }
            .qr-instructions {
                text-align: left;
                background: #fff;
                padding: 15px;
                border-radius: 5px;
                margin-top: 15px;
            }
            .qr-instructions ol {
                margin: 0;
                padding-left: 20px;
            }
            .qq-divider {
                text-align: center;
                margin: 20px 0;
                position: relative;
            }
            .qq-divider::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 0;
                right: 0;
                height: 1px;
                background: #ddd;
                z-index: 1;
            }
            .qq-divider span {
                background: #fff;
                padding: 0 10px;
                color: #666;
                font-size: 14px;
                position: relative;
                z-index: 2;
            }
            .qq-phone-auth {
                padding: 20px;
                background: #f9f9f9;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .qq-direct-login {
                padding: 20px;
                background: #f0f0f0;
                border-radius: 8px;
                margin-bottom: 20px;
            }
            .qq-phone-auth h3, .qq-direct-login h3 {
                color: #333;
                margin-bottom: 10px;
                font-size: 16px;
            }
            .form-row {
                display: flex;
                gap: 15px;
                margin-bottom: 15px;
            }
            .form-group {
                flex: 1;
            }
            .form-group.half {
                flex: 1;
            }
            .form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
                color: #333;
            }
            .qq-phone-input, .country-select, .qq-number-input, .qq-uid-input, .qq-password-input {
                width: 100%;
                padding: 10px;
                border: 2px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
            }
            .qq-phone-input:focus, .country-select:focus, .qq-number-input:focus,
            .qq-uid-input:focus, .qq-password-input:focus {
                border-color: #FF8C00;
                outline: none;
            }
            .qq-submit-btn, .qq-direct-btn {
                width: 100%;
                padding: 12px;
                background: #FF8C00;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: background 0.3s;
            }
            .qq-submit-btn:hover, .qq-direct-btn:hover {
                background: #E67E00;
            }
            .qq-info {
                padding: 15px;
                background: #e3f2fd;
                border-radius: 5px;
                color: #004466;
            }
            .qq-info ul {
                margin: 10px 0;
                padding-left: 20px;
            }
            .warning {
                color: #d32f2f;
                font-size: 12px;
                margin-top: 10px;
                padding: 5px;
                background: #ffebee;
                border-radius: 3px;
            }
            .note {
                font-size: 12px;
                color: #666;
                margin-top: 10px;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // QR kod durumunu kontrol et
            function checkQRStatus() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    data: {
                        action: 'qq_qr_status',
                        scene: '<?php echo $this->get_qr_scene(); ?>'
                    },
                    success: function(response) {
                        if (response.status === 'scanned') {
                            $('#qr-status').text('✅ QR kod okutuldu! Giriş yapılıyor...');
                            window.location.href = response.redirect;
                        } else if (response.status === 'confirmed') {
                            $('#qr-status').text('✅ Giriş onaylandı! Yönlendiriliyor...');
                            window.location.href = response.redirect;
                        } else {
                            $('#qr-status').text('📱 QR kodu bekleniyor...');
                            setTimeout(checkQRStatus, 2000);
                        }
                    }
                });
            }
            
            // QR kod varsa kontrolü başlat
            if ($('#qq-qr-code').length) {
                setTimeout(checkQRStatus, 2000);
            }
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * QR kod göster
     */
    private function display_qr_code() {
        // QQ QR kod için unique scene ID oluştur
        $scene_id = $this->create_qr_scene();
        
        // QQ API'den QR kod al
        $qr_code = $this->get_qq_qr_code($scene_id);
        
        if ($qr_code) {
            echo '<img src="' . esc_url($qr_code) . '" alt="QQ QR Kod" style="width:180px;height:180px;">';
        } else {
            echo '<div class="qr-error">QR kod alınamadı. Lütfen sayfayı yenileyin.</div>';
        }
    }
    
    /**
     * QR kod scene ID oluştur
     */
    private function create_qr_scene() {
        $scene_id = wp_rand(100000, 999999);
        set_transient('qq_qr_' . $scene_id, [
            'status' => 'pending',
            'created' => time()
        ], 5 * MINUTE_IN_SECONDS);
        
        return $scene_id;
    }
    
    /**
     * QR kod scene ID'sini al
     */
    private function get_qr_scene() {
        return isset($_GET['scene']) ? intval($_GET['scene']) : 0;
    }
    
    /**
     * QQ API'den QR kod al
     */
    private function get_qq_qr_code($scene_id) {
        // QQ Open Platform QR kod API
        $url = "https://graph.qq.com/oauth2.0/show";
        
        $params = [
            'which' => 'qr',
            'appid' => $this->app_id,
            'state' => $scene_id,
            'redirect_uri' => $this->get_callback_url()
        ];
        
        return $url . '?' . http_build_query($params);
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     */
    public function handle_callback($request) {
        try {
            // QQ QR kod callback
            if (isset($request['code']) && isset($request['state'])) {
                return $this->handle_oauth_callback($request);
            }
            
            // Kod gönderme işlemi
            if (isset($request['qq_send_code']) && isset($request['phone'])) {
                return $this->send_verification_code($request);
            }
            
            // Kod doğrulama işlemi
            if (isset($request['qq_code']) && isset($request['phone'])) {
                return $this->verify_code_and_login($request);
            }
            
            // Direkt QQ girişi
            if (isset($request['qq_direct_login']) && isset($request['qq_uid']) && isset($request['qq_password'])) {
                return $this->handle_direct_login($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('QQ SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * OAuth callback handler
     */
    private function handle_oauth_callback($request) {
        $code = $request['code'];
        $state = intval($request['state']);
        
        // QR kod durumunu güncelle
        $qr_data = get_transient('qq_qr_' . $state);
        
        if ($qr_data) {
            $qr_data['status'] = 'scanned';
            set_transient('qq_qr_' . $state, $qr_data, 5 * MINUTE_IN_SECONDS);
        }
        
        // Access token al
        $token_response = $this->get_access_token($code);
        
        if (!$token_response || !isset($token_response['access_token'])) {
            throw new Exception('Access token alınamadı');
        }
        
        $access_token = $token_response['access_token'];
        
        // OpenID al
        $openid_response = $this->get_openid($access_token);
        
        if (!$openid_response || !isset($openid_response['openid'])) {
            throw new Exception('OpenID alınamadı');
        }
        
        $openid = $openid_response['openid'];
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_userinfo($access_token, $openid);
        
        if ($userinfo) {
            // Profil oluştur
            $profile = $this->map_qq_userinfo($userinfo, $openid);
            
            // Kullanıcı bul veya oluştur
            $user = $this->find_or_create_user($profile);
            
            if (is_wp_error($user)) {
                throw new Exception($user->get_error_message());
            }
            
            // Profili döndür
            $profile['user_id'] = is_numeric($user) ? $user : $user->ID;
            
            // QR kod durumunu güncelle
            if ($qr_data) {
                $qr_data['status'] = 'confirmed';
                set_transient('qq_qr_' . $state, $qr_data, 5 * MINUTE_IN_SECONDS);
            }
            
            // Başarılı giriş - yönlendir
            wp_redirect(home_url('/profil-merkezim/'));
            exit;
        }
        
        throw new Exception('Kullanıcı bilgileri alınamadı');
    }
    
    /**
     * Access token al
     */
    private function get_access_token($code) {
        $url = "https://graph.qq.com/oauth2.0/token";
        
        $params = [
            'grant_type' => 'authorization_code',
            'client_id' => $this->app_id,
            'client_secret' => $this->app_key,
            'code' => $code,
            'redirect_uri' => $this->get_callback_url()
        ];
        
        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('QQ token hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // QQ format: access_token=FE04...&expires_in=7776000&refresh_token=...
        parse_str($body, $result);
        
        return $result;
    }
    
    /**
     * OpenID al
     */
    private function get_openid($access_token) {
        $url = "https://graph.qq.com/oauth2.0/me";
        
        $response = wp_remote_get($url . '?access_token=' . urlencode($access_token), [
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('QQ openid hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // QQ format: callback( {"client_id":"...","openid":"..."} );
        if (preg_match('/callback\(\s*(.*?)\s*\)/', $body, $matches)) {
            return json_decode($matches[1], true);
        }
        
        return false;
    }
    
    /**
     * Kullanıcı bilgilerini al
     */
    private function get_userinfo($access_token, $openid) {
        $url = "https://graph.qq.com/user/get_user_info";
        
        $params = [
            'access_token' => $access_token,
            'oauth_consumer_key' => $this->app_id,
            'openid' => $openid
        ];
        
        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('QQ userinfo hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * QQ kullanıcı bilgilerini profile map et
     */
    private function map_qq_userinfo($userinfo, $openid) {
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => $openid,
            'openid' => $openid,
            'qq_id' => $openid,
            'username' => $openid,
            'display_name' => $userinfo['nickname'] ?? '',
            'nickname' => $userinfo['nickname'] ?? '',
            'avatar' => isset($userinfo['figureurl_qq_2']) ? $userinfo['figureurl_qq_2'] : 
                       (isset($userinfo['figureurl_qq_1']) ? $userinfo['figureurl_qq_1'] : ''),
            'gender' => $this->map_gender($userinfo['gender'] ?? ''),
            'year' => $userinfo['year'] ?? '',
            'province' => $userinfo['province'] ?? '',
            'city' => $userinfo['city'] ?? '',
            'is_yellow_vip' => $userinfo['is_yellow_vip'] ?? '',
            'level' => $userinfo['level'] ?? '',
            'verified' => true
        ];
        
        return $profile;
    }
    
    /**
     * Cinsiyet haritalama
     */
    private function map_gender($gender) {
        $gender_map = [
            '男' => 'male',
            '女' => 'female',
            '未知' => 'unknown'
        ];
        
        return isset($gender_map[$gender]) ? $gender_map[$gender] : 'unknown';
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
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $password = wp_generate_password();
        $email = $profile['provider_slug'] . '_' . $profile['user_id'] . '@qq.local';
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Kullanıcı bilgilerini güncelle
        $user_data = [
            'ID' => $user_id,
            'display_name' => $profile['display_name']
        ];
        
        wp_update_user($user_data);
        
        // Bağlantıyı kaydet
        $this->save_provider_user_id($user_id, $profile['provider_slug'], $profile['user_id']);
        
        // Avatar'ı kaydet
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
        }
        
        // QQ spesifik bilgileri kaydet
        $qq_fields = ['nickname', 'gender', 'year', 'province', 'city', 'is_yellow_vip', 'level'];
        foreach ($qq_fields as $field) {
            if (!empty($profile[$field])) {
                update_user_meta($user_id, 'ai_community_qq_' . $field, $profile[$field]);
            }
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
     * Doğrulama kodu gönder
     */
    public function send_verification_code($request) {
        // Nonce kontrolü
        if (!isset($request['qq_nonce']) || !wp_verify_nonce($request['qq_nonce'], 'qq_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $this->format_phone_number($request['phone']);
        
        // 6 haneli random kod oluştur
        $verification_code = wp_rand(100000, 999999);
        
        // Kodu geçici olarak kaydet (10 dakika geçerli)
        $this->save_verification_code($phone, $verification_code, 600);
        
        // SMS ile kod gönder
        $sent = $this->send_sms($phone, $verification_code);
        
        if ($sent) {
            return $this->show_verification_form($phone);
        } else {
            return $this->show_error('SMS gönderilemedi. Lütfen telefon numaranızı kontrol edin.');
        }
    }
    
    /**
     * SMS gönder - PUBLIC (SOS base'den)
     */
    public function send_sms($phone, $code) {
        // QQ SMS API entegrasyonu
        // Tencent Cloud SMS API kullanılabilir
        
        $url = "https://yun.tim.qq.com/v5/tlssmssvr/sendsms";
        
        $sdkappid = $this->get_sdkappid(); // Tencent Cloud SDK AppID
        $random = wp_rand(100000, 999999);
        $curtime = time();
        
        // İmza hesapla
        $signature = hash('sha256', 
            "appkey={$this->app_key}&random={$random}&time={$curtime}&mobile={$phone}"
        );
        
        $data = [
            'ext' => '',
            'extend' => '',
            'params' => [$code, '10'], // Kod ve geçerlilik süresi
            'sign' => $signature,
            'sig' => $signature,
            'tel' => [
                'mobile' => $phone,
                'nationcode' => '86'
            ],
            'time' => $curtime,
            'tpl_id' => 123456, // SMS Template ID
        ];
        
        $response = wp_remote_post($url . "?sdkappid={$sdkappid}&random={$random}", [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($data),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('QQ SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        return isset($result['result']) && $result['result'] === 0;
    }
    
    /**
     * SDK AppID al (Tencent Cloud)
     */
    private function get_sdkappid() {
        // Tencent Cloud SDK AppID
        return $this->config['sdkappid'] ?? '';
    }
    
    /**
     * Doğrulama kodu kaydet - PROTECTED (SOS base'den)
     * İmza: save_verification_code($phone, $code, $expiry = 600)
     */
    protected function save_verification_code($phone, $code, $expiry = 600) {
        // 10 dakika geçerli (varsayılan)
        set_transient('qq_code_' . md5($phone), $code, $expiry);
    }
    
    /**
     * Kodu doğrula - PUBLIC (SOS base'den)
     */
    public function verify_code($phone, $code) {
        $saved_code = get_transient('qq_code_' . md5($phone));
        
        if ($saved_code && $saved_code == $code) {
            // Kodu temizle
            delete_transient('qq_code_' . md5($phone));
            return true;
        }
        
        return false;
    }
    
    /**
     * Doğrulama formunu göster
     */
    private function show_verification_form($phone) {
        ob_start();
        ?>
        <div class="ai-community-sos-container qq-sos">
            <h2>QQ Doğrulama</h2>
            <p class="success-message">
                ✅ <strong><?php echo esc_html($phone); ?></strong> numarasına 
                SMS ile doğrulama kodu gönderildi.
            </p>
            
            <form method="post" action="" class="qq-verify-form">
                <?php wp_nonce_field('qq_sos_verify', 'verify_nonce'); ?>
                
                <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                
                <div class="form-group">
                    <label for="qq_code">Doğrulama Kodu:</label>
                    <input type="text" 
                           id="qq_code" 
                           name="qq_code" 
                           placeholder="6 haneli kodu girin" 
                           pattern="[0-9]{6}"
                           maxlength="6"
                           required
                           class="code-input"
                           autocomplete="off">
                </div>
                
                <button type="submit" name="qq_verify" class="qq-verify-btn">
                    Doğrula ve Giriş Yap
                </button>
            </form>
            
            <div class="resend-section">
                <p>Kod gelmedi mi?</p>
                <form method="post" action="" class="resend-form">
                    <?php wp_nonce_field('qq_sos_auth', 'qq_nonce'); ?>
                    <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                    <button type="submit" name="qq_send_code" class="resend-btn">
                        Yeniden Gönder
                    </button>
                </form>
            </div>
            
            <div class="qq-alternative">
                <p>Alternatif giriş yöntemleri:</p>
                <a href="<?php echo esc_url($this->get_oauth_url()); ?>" class="qq-oauth-link">
                    🔐 QQ Hesabımla OAuth ile Giriş Yap
                </a>
            </div>
        </div>
        
        <style>
            .success-message {
                background: #d4edda;
                color: #155724;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }
            .code-input {
                width: 100%;
                padding: 15px;
                font-size: 24px;
                text-align: center;
                letter-spacing: 8px;
                border: 2px solid #FF8C00;
                border-radius: 5px;
            }
            .qq-verify-btn {
                width: 100%;
                padding: 12px;
                background: #FF8C00;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
            }
            .qq-verify-btn:hover {
                background: #E67E00;
            }
            .resend-section {
                margin-top: 20px;
                text-align: center;
            }
            .resend-btn {
                background: none;
                border: none;
                color: #FF8C00;
                text-decoration: underline;
                cursor: pointer;
                font-size: 14px;
            }
            .qq-alternative {
                margin-top: 30px;
                padding: 15px;
                background: #f5f5f5;
                border-radius: 5px;
                text-align: center;
            }
            .qq-oauth-link {
                display: inline-block;
                margin-top: 10px;
                padding: 8px 16px;
                background: #FF8C00;
                color: white;
                text-decoration: none;
                border-radius: 5px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Kodu doğrula ve giriş yap
     */
    public function verify_code_and_login($request) {
        // Nonce kontrolü
        if (!isset($request['verify_nonce']) || !wp_verify_nonce($request['verify_nonce'], 'qq_sos_verify')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $request['phone'];
        $code = $request['qq_code'];
        
        // Kodu doğrula
        if ($this->verify_code($phone, $code)) {
            // Profil oluştur
            $profile = $this->create_profile_from_phone($phone);
            
            // Kullanıcı bul veya oluştur
            $user = $this->find_or_create_user($profile);
            
            if (is_wp_error($user)) {
                return $this->show_error($user->get_error_message());
            }
            
            // Profili döndür
            $profile['user_id'] = is_numeric($user) ? $user : $user->ID;
            
            // Başarılı giriş - yönlendir
            wp_redirect(home_url('/profil-merkezim/'));
            exit;
        } else {
            return $this->show_error('Geçersiz doğrulama kodu. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Direkt QQ girişi
     */
    private function handle_direct_login($request) {
        // Nonce kontrolü
        if (!isset($request['direct_nonce']) || !wp_verify_nonce($request['direct_nonce'], 'qq_direct_login')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        // OAuth'a yönlendir
        $auth_url = "https://graph.qq.com/oauth2.0/authorize";
        $params = [
            'response_type' => 'code',
            'client_id' => $this->app_id,
            'redirect_uri' => $this->get_callback_url(),
            'state' => wp_create_nonce('qq_oauth_state')
        ];
        
        wp_redirect($auth_url . '?' . http_build_query($params));
        exit;
    }
    
    /**
     * OAuth URL'ini al
     */
    private function get_oauth_url() {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->app_id,
            'redirect_uri' => $this->get_callback_url(),
            'state' => wp_create_nonce('qq_oauth_state')
        ];
        
        return "https://graph.qq.com/oauth2.0/authorize?" . http_build_query($params);
    }
    
    /**
     * Callback URL'ini al
     */
    private function get_callback_url() {
        return add_query_arg('oauth_provider', $this->slug, home_url('/uyelik/'));
    }
    
    /**
     * Telefon numarasını formatla - PROTECTED (base class ile uyumlu)
     */
    protected function format_phone_number($phone) {
        // Boşlukları ve özel karakterleri temizle
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // + yoksa ekle
        if (substr($phone, 0, 1) !== '+') {
            if (substr($phone, 0, 2) === '86') {
                $phone = '+' . $phone;
            } else {
                $phone = '+86' . $phone;
            }
        }
        
        return $phone;
    }
    
    /**
     * Telefon numarasından profil oluştur - PROTECTED (base class ile uyumlu)
     */
    protected function create_profile_from_phone($phone) {
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => md5($phone . time()),
            'phone' => $phone,
            'username' => 'qq_' . md5($phone),
            'display_name' => 'QQ Kullanıcı',
            'verified' => true
        ];
        
        return $profile;
    }
    
    /**
     * Hata mesajı göster - PROTECTED (base class ile uyumlu)
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error">
            <h3>Hata!</h3>
            <p><?php echo esc_html($message); ?></p>
            <p><a href="javascript:history.back()">Geri dön</a></p>
            <p><a href="<?php echo esc_url($this->get_oauth_url()); ?>" class="qq-fallback-link">
                Alternatif: QQ OAuth ile Giriş Yap
            </a></p>
        </div>
        
        <style>
            .qq-fallback-link {
                display: inline-block;
                margin-top: 15px;
                padding: 8px 16px;
                background: #FF8C00;
                color: white;
                text-decoration: none;
                border-radius: 5px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Getter metodlar
     */
    public function get_name() {
        return 'QQ (SOS)';
    }
    
    public function get_type() {
        return 'sos';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}