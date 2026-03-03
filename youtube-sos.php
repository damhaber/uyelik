<?php
/**
 * AI Community Provider: YouTube SOS
 * 
 * @package AI_Community
 * 
 * YouTube SOS - Google hesabı kurtarma
 */

if (!defined('ABSPATH')) exit;

// SOS base class'ı include et
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/sos-base.php';
}

class AI_Community_Provider_YouTube_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'youtube-sos';
    protected $name = 'YouTube (SOS)';
    
    // Google API yapılandırması
    private $client_id = '';
    private $client_secret = '';
    private $api_key = '';
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        if (isset($config['api_key'])) {
            $this->api_key = $config['api_key'];
        }
    }
    
    /**
     * Ana handler
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        if (isset($request['code']) || isset($request['phone']) || isset($request['email'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth handler - SOS giriş ekranı
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <div class="ai-community-sos-container youtube-sos">
            <h2>▶️ YouTube Kurtarma Girişi</h2>
            <p class="description">YouTube/Google hesabınıza erişim sağlamak için alternatif yöntemler</p>
            
            <!-- Google Hesabı ile Kurtarma -->
            <div class="sos-method google-method">
                <div class="method-header">
                    <span class="method-icon">🔵</span>
                    <h3>Google Hesabı ile Kurtarma</h3>
                </div>
                <p class="method-desc">YouTube kanalınıza bağlı Google hesabınızla giriş yapın</p>
                
                <a href="<?php echo $this->get_google_auth_url(); ?>" class="google-auth-btn">
                    <img src="https://www.google.com/favicon.ico" alt="Google" style="width:20px;height:20px;">
                    Google ile Kurtarma Başlat
                </a>
            </div>
            
            <div class="sos-divider">
                <span>veya</span>
            </div>
            
            <!-- Telefon ile Giriş -->
            <div class="sos-method phone-method">
                <div class="method-header">
                    <span class="method-icon">📱</span>
                    <h3>Telefon ile Doğrulama</h3>
                </div>
                <p class="method-desc">Google hesabınıza kayıtlı telefon numaranızı girin</p>
                
                <form method="post" action="" class="sos-form phone-form">
                    <?php wp_nonce_field('youtube_sos_auth', 'youtube_nonce'); ?>
                    
                    <div class="form-row">
                        <select name="country_code" class="country-code">
                            <option value="+90">Türkiye (+90)</option>
                            <option value="+1">Amerika (+1)</option>
                            <option value="+44">İngiltere (+44)</option>
                            <option value="+49">Almanya (+49)</option>
                            <option value="+33">Fransa (+33)</option>
                            <option value="+7">Rusya (+7)</option>
                            <option value="+81">Japonya (+81)</option>
                            <option value="+82">Güney Kore (+82)</option>
                            <option value="+86">Çin (+86)</option>
                            <option value="+91">Hindistan (+91)</option>
                        </select>
                        
                        <input type="tel" 
                               name="phone" 
                               placeholder="Telefon numarası" 
                               class="phone-input"
                               pattern="[0-9]{10,15}"
                               required>
                    </div>
                    
                    <div class="method-options">
                        <label class="option">
                            <input type="radio" name="phone_method" value="sms" checked> SMS ile kod al
                        </label>
                        <label class="option">
                            <input type="radio" name="phone_method" value="voice"> Sesli arama ile kod al
                        </label>
                    </div>
                    
                    <button type="submit" name="send_phone_code" class="sos-submit-btn">
                        Doğrulama Kodu Gönder
                    </button>
                </form>
            </div>
            
            <!-- Email ile Giriş -->
            <div class="sos-method email-method">
                <div class="method-header">
                    <span class="method-icon">📧</span>
                    <h3>Email ile Doğrulama</h3>
                </div>
                <p class="method-desc">Google hesabınıza kayıtlı email adresinizi girin</p>
                
                <form method="post" action="" class="sos-form email-form">
                    <?php wp_nonce_field('youtube_sos_auth', 'youtube_nonce'); ?>
                    
                    <div class="form-row">
                        <input type="email" 
                               name="email" 
                               placeholder="Email adresiniz" 
                               class="email-input"
                               required>
                    </div>
                    
                    <button type="submit" name="send_email_code" class="sos-submit-btn">
                        Email ile Doğrulama Kodu Gönder
                    </button>
                </form>
            </div>
            
            <!-- YouTube Kanal URL ile Kurtarma -->
            <div class="sos-method channel-method">
                <div class="method-header">
                    <span class="method-icon">🎬</span>
                    <h3>YouTube Kanal URL ile Kurtarma</h3>
                </div>
                <p class="method-desc">YouTube kanalınızın URL'ini girin, size bağlı hesapları bulalım</p>
                
                <form method="post" action="" class="sos-form channel-form">
                    <?php wp_nonce_field('youtube_sos_auth', 'youtube_nonce'); ?>
                    
                    <div class="form-row">
                        <input type="url" 
                               name="channel_url" 
                               placeholder="https://www.youtube.com/c/kanalismi veya /@kanalismi" 
                               class="channel-input"
                               required>
                    </div>
                    
                    <button type="submit" name="find_channel" class="sos-submit-btn">
                        Kanalı Bul
                    </button>
                </form>
            </div>
            
            <!-- Kurtarma Email'i ile Giriş -->
            <div class="sos-method recovery-method">
                <div class="method-header">
                    <span class="method-icon">🔐</span>
                    <h3>Kurtarma Email'i ile Doğrulama</h3>
                </div>
                <p class="method-desc">Google hesabınıza eklediğiniz kurtarma email adresinizi girin</p>
                
                <form method="post" action="" class="sos-form recovery-form">
                    <?php wp_nonce_field('youtube_sos_auth', 'youtube_nonce'); ?>
                    
                    <div class="form-row">
                        <input type="email" 
                               name="recovery_email" 
                               placeholder="kurtarma@email.com" 
                               class="email-input"
                               required>
                    </div>
                    
                    <button type="submit" name="send_recovery_code" class="sos-submit-btn">
                        Kurtarma Email'ine Kod Gönder
                    </button>
                </form>
            </div>
            
            <!-- YouTube Studio ile Kurtarma -->
            <div class="sos-method studio-method">
                <div class="method-header">
                    <span class="method-icon">🎥</span>
                    <h3>YouTube Studio ile Kurtarma</h3>
                </div>
                <p class="method-desc">YouTube Studio mobil uygulaması üzerinden hesap kurtarma</p>
                
                <div class="studio-options">
                    <p><strong>Adımlar:</strong></p>
                    <ol>
                        <li>YouTube Studio uygulamasını açın</li>
                        <li>Profil fotoğrafınıza tıklayın</li>
                        <li>"Hesap Kurtarma" seçeneğini seçin</li>
                        <li>Ekrandaki talimatları izleyin</li>
                    </ol>
                    
                    <div class="qr-code-placeholder">
                        <p>📱 Mobil uygulamada açmak için QR kod:</p>
                        <div class="qr-box">
                            <!-- QR kod buraya gelecek -->
                            <span>QR KOD</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- İçerik Üreticiler için Özel Destek -->
            <div class="sos-method creator-method">
                <div class="method-header">
                    <span class="method-icon">⭐</span>
                    <h3>İçerik Üretici Özel Desteği</h3>
                </div>
                <p class="method-desc">1000+ abonesi olan içerik üreticiler için öncelikli destek</p>
                
                <button class="creator-support-btn" onclick="startCreatorSupport()">
                    <span class="creator-badge">⭐</span>
                    İçerik Üretici Desteği Başlat
                </button>
                
                <p class="note">* Kanalınızın doğrulanması için kanal ID'niz veya URL'iniz gereklidir</p>
            </div>
            
            <!-- Yardım İpuçları -->
            <div class="sos-help-tips">
                <h4>📌 YouTube hesabınıza erişemiyor musunuz?</h4>
                <ul>
                    <li>Google hesabınızın kurtarma email'ini deneyin</li>
                    <li>Telefonunuza gelen doğrulama kodlarını kontrol edin</li>
                    <li>YouTube kanal URL'inizi girin (youtube.com/c/kanalismi veya /@kanalismi)</li>
                    <li>Google Authenticator yedek kodlarınızı kullanın</li>
                    <li>Eski email adreslerinizi deneyin</li>
                </ul>
            </div>
            
            <!-- Güvenlik Uyarısı -->
            <div class="sos-security-notice">
                <p>🔒 Google/YouTube şifrenizi asla kimseyle paylaşmayın. Resmi YouTube destek ekibi asla şifre sormaz.</p>
            </div>
        </div>
        
        <style>
            .youtube-sos {
                max-width: 600px;
                margin: 0 auto;
                padding: 30px 20px;
                background: linear-gradient(135deg, #FF0000 0%, #CC0000 100%);
                border-radius: 20px;
                color: white;
                box-shadow: 0 10px 30px rgba(255,0,0,0.3);
            }
            
            .youtube-sos h2 {
                text-align: center;
                font-size: 28px;
                margin-bottom: 10px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            }
            
            .sos-method {
                background: rgba(255,255,255,0.98);
                border-radius: 15px;
                padding: 20px;
                margin-bottom: 20px;
                color: #333;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
                border-left: 5px solid #FF0000;
            }
            
            .method-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            
            .method-icon {
                font-size: 24px;
            }
            
            .method-header h3 {
                margin: 0;
                color: #FF0000;
            }
            
            .google-auth-btn {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                width: 100%;
                padding: 14px;
                background: #4285f4;
                color: white;
                border: none;
                border-radius: 25px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                text-decoration: none;
                transition: all 0.3s;
            }
            
            .google-auth-btn:hover {
                background: #3367d6;
                transform: translateY(-2px);
            }
            
            .form-row {
                display: flex;
                gap: 10px;
                margin-bottom: 15px;
            }
            
            .country-code {
                width: 100px;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 14px;
            }
            
            .phone-input, .email-input, .channel-input {
                flex: 1;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 16px;
            }
            
            .phone-input:focus, .email-input:focus, .channel-input:focus {
                border-color: #FF0000;
                outline: none;
            }
            
            .method-options {
                margin-bottom: 15px;
                padding: 10px;
                background: #f5f5f5;
                border-radius: 8px;
            }
            
            .sos-submit-btn {
                width: 100%;
                padding: 14px;
                background: #FF0000;
                color: white;
                border: none;
                border-radius: 25px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .sos-submit-btn:hover {
                background: #CC0000;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(255,0,0,0.3);
            }
            
            .sos-divider {
                text-align: center;
                margin: 20px 0;
                position: relative;
                color: white;
            }
            
            .studio-options {
                background: #f9f9f9;
                padding: 15px;
                border-radius: 8px;
            }
            
            .studio-options ol {
                margin: 10px 0;
                padding-left: 20px;
            }
            
            .qr-code-placeholder {
                text-align: center;
                margin-top: 15px;
            }
            
            .qr-box {
                width: 150px;
                height: 150px;
                background: #eee;
                margin: 10px auto;
                display: flex;
                align-items: center;
                justify-content: center;
                border: 2px dashed #FF0000;
                border-radius: 10px;
                color: #666;
            }
            
            .creator-method {
                background: linear-gradient(135deg, #fff, #fff5f5);
            }
            
            .creator-support-btn {
                width: 100%;
                padding: 14px;
                background: #FFD700;
                color: #333;
                border: none;
                border-radius: 25px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                transition: all 0.3s;
            }
            
            .creator-support-btn:hover {
                background: #FFC800;
                transform: translateY(-2px);
            }
            
            .creator-badge {
                font-size: 20px;
            }
            
            .note {
                font-size: 12px;
                color: #666;
                margin-top: 10px;
                font-style: italic;
            }
            
            .sos-help-tips {
                background: rgba(0,0,0,0.3);
                border-radius: 10px;
                padding: 15px;
                margin-top: 20px;
            }
            
            .sos-help-tips ul {
                margin: 10px 0 0;
                padding-left: 20px;
            }
            
            .sos-help-tips li {
                margin: 5px 0;
            }
            
            .sos-security-notice {
                text-align: center;
                margin-top: 20px;
                padding: 10px;
                background: rgba(0,0,0,0.2);
                border-radius: 8px;
                font-size: 13px;
            }
        </style>
        
        <script>
        function startCreatorSupport() {
            alert('YouTube İçerik Üretici Desteği başlatılıyor...\n\nLütfen kanal bilgilerinizi hazır bulundurun.');
        }
        
        jQuery(document).ready(function($) {
            // Telefon formu
            $('.phone-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var countryCode = form.find('.country-code').val();
                var phone = form.find('.phone-input').val();
                var method = form.find('input[name="phone_method"]:checked').val();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'youtube_sos_send_code',
                        phone: countryCode + phone,
                        method: method,
                        nonce: '<?php echo wp_create_nonce('youtube_sos_ajax'); ?>'
                    },
                    beforeSend: function() {
                        form.find('.sos-submit-btn').prop('disabled', true).text('Gönderiliyor...');
                    },
                    success: function(response) {
                        if (response.success) {
                            form.html('<div class="success-message">✅ Doğrulama kodu gönderildi!</div>' +
                                     '<div class="code-verification">' +
                                     '<input type="text" name="code" placeholder="6 haneli kod" maxlength="6" class="code-input">' +
                                     '<button class="verify-btn" onclick="verifyYouTubeCode(\'' + countryCode + phone + '\')">Doğrula</button>' +
                                     '</div>');
                        } else {
                            alert('Hata: ' + response.message);
                        }
                    }
                });
            });
            
            // Email formu
            $('.email-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var email = form.find('.email-input').val();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'youtube_sos_send_email_code',
                        email: email,
                        nonce: '<?php echo wp_create_nonce('youtube_sos_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            form.html('<div class="success-message">✅ Email gönderildi!</div>' +
                                     '<div class="code-verification">' +
                                     '<input type="text" name="code" placeholder="6 haneli kod" maxlength="6" class="code-input">' +
                                     '<button class="verify-btn" onclick="verifyYouTubeEmailCode(\'' + email + '\')">Doğrula</button>' +
                                     '</div>');
                        } else {
                            alert('Hata: ' + response.message);
                        }
                    }
                });
            });
            
            // Kanal formu
            $('.channel-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var channelUrl = form.find('.channel-input').val();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'youtube_sos_find_channel',
                        channel_url: channelUrl,
                        nonce: '<?php echo wp_create_nonce('youtube_sos_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            form.html(response.data.html);
                        } else {
                            alert('Hata: ' + response.message);
                        }
                    }
                });
            });
        });
        
        function verifyYouTubeCode(phone) {
            var code = jQuery('.code-input').val();
            
            if (code.length !== 6) {
                alert('Lütfen 6 haneli kodu girin');
                return;
            }
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'youtube_sos_verify',
                    phone: phone,
                    code: code,
                    nonce: '<?php echo wp_create_nonce('youtube_sos_ajax'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        alert('Geçersiz kod: ' + response.message);
                    }
                }
            });
        }
        
        function verifyYouTubeEmailCode(email) {
            var code = jQuery('.code-input').val();
            
            if (code.length !== 6) {
                alert('Lütfen 6 haneli kodu girin');
                return;
            }
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'youtube_sos_verify_email',
                    email: email,
                    code: code,
                    nonce: '<?php echo wp_create_nonce('youtube_sos_ajax'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        alert('Geçersiz kod: ' + response.message);
                    }
                }
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Google auth URL'i al
     */
    private function get_google_auth_url() {
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Callback handler
     */
    public function handle_callback($request) {
        try {
            if (isset($request['send_phone_code']) && isset($request['phone'])) {
                return $this->send_phone_code($request);
            }
            
            if (isset($request['send_email_code']) && isset($request['email'])) {
                return $this->send_email_code($request);
            }
            
            if (isset($request['send_recovery_code']) && isset($request['recovery_email'])) {
                return $this->send_recovery_code($request);
            }
            
            if (isset($request['find_channel']) && isset($request['channel_url'])) {
                return $this->find_by_channel($request);
            }
            
            if (isset($request['code'])) {
                return $this->handle_google_callback($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('YouTube SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Google OAuth callback
     */
    private function handle_google_callback($request) {
        $code = $request['code'];
        
        // Token al
        $token_data = $this->get_google_access_token($code);
        
        if (!$token_data) {
            return $this->show_error('Google hesabınıza bağlanılamadı.');
        }
        
        // Kullanıcı bilgilerini al
        $userinfo = $this->get_google_userinfo($token_data['access_token']);
        
        if (!$userinfo) {
            return $this->show_error('Kullanıcı bilgileri alınamadı.');
        }
        
        // YouTube kanal bilgilerini al
        $channel_info = $this->get_youtube_channel_info($token_data['access_token']);
        
        // Profil oluştur
        $profile = [
            'id' => $userinfo['sub'] ?? '',
            'email' => $userinfo['email'] ?? '',
            'name' => $userinfo['name'] ?? '',
            'avatar' => $userinfo['picture'] ?? '',
            'youtube_channel_id' => $channel_info['id'] ?? '',
            'youtube_channel_title' => $channel_info['snippet']['title'] ?? '',
            'provider' => $this->slug,
            'provider_name' => $this->get_name(),
            'verified' => true,
            'login_method' => 'google_oauth'
        ];
        
        // Kullanıcı oluştur veya bul
        $user_id = $this->find_or_create_user($profile);
        
        if ($user_id) {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            
            wp_redirect(home_url('/profil-merkezim/'));
            exit;
        }
        
        return $this->show_error('Kullanıcı oluşturulamadı.');
    }
    
    /**
     * Google access token al
     */
    private function get_google_access_token($code) {
        $url = 'https://oauth2.googleapis.com/token';
        
        $params = [
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'redirect_uri' => $this->get_redirect_uri(),
            'grant_type' => 'authorization_code'
        ];
        
        $response = wp_remote_post($url, [
            'body' => $params,
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['access_token'])) {
            return false;
        }
        
        return $data;
    }
    
    /**
     * Google kullanıcı bilgilerini al
     */
    private function get_google_userinfo($access_token) {
        $url = 'https://www.googleapis.com/oauth2/v3/userinfo';
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * YouTube kanal bilgilerini al
     */
    private function get_youtube_channel_info($access_token) {
        $url = 'https://www.googleapis.com/youtube/v3/channels';
        
        $params = [
            'part' => 'snippet,contentDetails,statistics',
            'mine' => 'true'
        ];
        
        $url = add_query_arg($params, $url);
        
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['items'][0])) {
            return false;
        }
        
        return $data['items'][0];
    }
    
    /**
     * Telefon kodu gönder
     */
    private function send_phone_code($request) {
        if (!isset($request['youtube_nonce']) || !wp_verify_nonce($request['youtube_nonce'], 'youtube_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $this->format_phone_number($request['phone']);
        $code = wp_rand(100000, 999999);
        
        set_transient('youtube_sos_' . md5($phone), [
            'code' => $code,
            'phone' => $phone,
            'attempts' => 0
        ], 600);
        
        // Google SMS servisi
        $this->send_google_sms($phone, "Google doğrulama kodunuz: $code");
        
        return $this->show_verification_form($phone);
    }
    
    /**
     * Email kodu gönder
     */
    private function send_email_code($request) {
        if (!isset($request['youtube_nonce']) || !wp_verify_nonce($request['youtube_nonce'], 'youtube_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $email = sanitize_email($request['email']);
        $code = wp_rand(100000, 999999);
        
        set_transient('youtube_sos_email_' . md5($email), [
            'code' => $code,
            'email' => $email,
            'attempts' => 0
        ], 600);
        
        wp_mail($email, 'YouTube/Google Doğrulama Kodu', 
                "YouTube/Google doğrulama kodunuz: $code\n\n" .
                "Bu kodu kimseyle paylaşmayın.");
        
        return $this->show_verification_form($email, 'email');
    }
    
    /**
     * Kurtarma email'i gönder
     */
    private function send_recovery_code($request) {
        if (!isset($request['youtube_nonce']) || !wp_verify_nonce($request['youtube_nonce'], 'youtube_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $recovery_email = sanitize_email($request['recovery_email']);
        $code = wp_rand(100000, 999999);
        
        set_transient('youtube_sos_recovery_' . md5($recovery_email), [
            'code' => $code,
            'email' => $recovery_email,
            'attempts' => 0
        ], 600);
        
        wp_mail($recovery_email, 'YouTube Kurtarma Email Doğrulama', 
                "YouTube hesabınız için kurtarma email doğrulama kodunuz: $code\n\n" .
                "Bu kodu kullanarak hesabınızı kurtarabilirsiniz.");
        
        return $this->show_verification_form($recovery_email, 'recovery');
    }
    
    /**
     * Kanal URL ile bul
     */
    private function find_by_channel($request) {
        if (!isset($request['youtube_nonce']) || !wp_verify_nonce($request['youtube_nonce'], 'youtube_sos_auth')) {
            wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
            return;
        }
        
        $channel_url = esc_url_raw($request['channel_url']);
        
        // URL'den channel ID veya handle çıkar
        $channel_id = $this->extract_channel_id($channel_url);
        $handle = $this->extract_handle($channel_url);
        
        if (!$channel_id && !$handle) {
            wp_send_json_error(['message' => 'Geçerli bir YouTube kanal URL\'i giriniz.']);
            return;
        }
        
        // Meta'da ara
        $user_query = new WP_User_Query([
            'meta_key' => 'youtube_channel_id',
            'meta_value' => $channel_id ?: $handle,
            'number' => 1
        ]);
        
        $users = $user_query->get_results();
        
        if (empty($users)) {
            // Handle olarak da ara
            if ($handle) {
                $user_query = new WP_User_Query([
                    'meta_key' => 'youtube_handle',
                    'meta_value' => $handle,
                    'number' => 1
                ]);
                $users = $user_query->get_results();
            }
        }
        
        if (empty($users)) {
            wp_send_json_error(['message' => 'Bu YouTube kanalıyla kayıtlı hesap bulunamadı.']);
            return;
        }
        
        $user = $users[0];
        
        ob_start();
        ?>
        <div class="found-channel">
            <h4>✅ Kanal Bulundu!</h4>
            <p><strong><?php echo esc_html($user->display_name); ?></strong></p>
            
            <?php if ($user->user_email && strpos($user->user_email, '@social.local') === false): ?>
            <p>📧 <?php echo $this->mask_email($user->user_email); ?></p>
            <button onclick="sendCodeToYouTubeEmail('<?php echo esc_js($user->user_email); ?>')" class="sos-submit-btn">
                Bu email'e kod gönder
            </button>
            <?php endif; ?>
            
            <?php 
            $phone = get_user_meta($user->ID, 'phone', true);
            if ($phone): 
            ?>
            <p>📱 <?php echo $this->mask_phone($phone); ?></p>
            <button onclick="sendCodeToYouTubePhone('<?php echo esc_js($phone); ?>')" class="sos-submit-btn">
                Bu telefona kod gönder
            </button>
            <?php endif; ?>
        </div>
        <?php
        $html = ob_get_clean();
        
        wp_send_json_success(['html' => $html]);
    }
    
    /**
     * URL'den channel ID çıkar
     */
    private function extract_channel_id($url) {
        preg_match('/channel\/(UC[\w-]+)/', $url, $matches);
        return $matches[1] ?? '';
    }
    
    /**
     * URL'den handle çıkar
     */
    private function extract_handle($url) {
        preg_match('/\/(@[\w-]+)/', $url, $matches);
        return $matches[1] ?? '';
    }
    
    /**
     * Google SMS gönder
     */
    private function send_google_sms($phone, $message) {
        error_log("Google SMS: $phone - $message");
        return true;
    }
    
    /**
     * Email maskele
     */
    private function mask_email($email) {
        $parts = explode('@', $email);
        $name = substr($parts[0], 0, 2) . '***' . substr($parts[0], -2);
        return $name . '@' . $parts[1];
    }
    
    /**
     * Telefon maskele
     */
    private function mask_phone($phone) {
        return substr($phone, 0, 4) . '***' . substr($phone, -4);
    }
    
    /**
     * Doğrulama formu
     */
    private function show_verification_form($recipient, $type = 'phone') {
        ob_start();
        ?>
        <div class="verification-form">
            <h3>✅ Doğrulama Kodu Gönderildi</h3>
            <p><?php 
                if ($type === 'phone') echo 'Telefonunuza';
                elseif ($type === 'recovery') echo 'Kurtarma email adresinize';
                else echo 'Email adresinize'; 
            ?> 6 haneli kod gönderildi.</p>
            
            <div class="code-input-group">
                <input type="text" 
                       id="verification_code" 
                       maxlength="6" 
                       pattern="[0-9]{6}"
                       placeholder="6 haneli kod"
                       class="code-input"
                       autocomplete="off">
                <button onclick="verifyYouTubeCode('<?php echo esc_js($recipient); ?>')" class="verify-btn">
                    Doğrula
                </button>
            </div>
        </div>
        
        <style>
            .verification-form {
                text-align: center;
                padding: 20px;
                background: white;
                border-radius: 10px;
                margin-top: 20px;
            }
            .code-input-group {
                display: flex;
                gap: 10px;
                margin: 20px 0;
            }
            .code-input {
                flex: 1;
                padding: 15px;
                font-size: 24px;
                text-align: center;
                letter-spacing: 8px;
                border: 2px solid #FF0000;
                border-radius: 8px;
            }
            .verify-btn {
                padding: 15px 25px;
                background: #FF0000;
                color: white;
                border: none;
                border-radius: 8px;
                font-weight: bold;
                cursor: pointer;
            }
            .verify-btn:hover {
                background: #CC0000;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Kodu doğrula ve giriş yap
     */
    public function verify_and_login($request) {
        $phone = $request['phone'];
        $code = $request['code'];
        
        $data = get_transient('youtube_sos_' . md5($phone));
        
        if (!$data || $data['code'] != $code) {
            wp_send_json_error(['message' => 'Geçersiz kod.']);
            return;
        }
        
        $profile = [
            'id' => md5($phone . time()),
            'phone' => $phone,
            'username' => 'youtube_' . md5($phone),
            'name' => 'YouTube Kullanıcı',
            'provider' => $this->slug,
            'provider_name' => $this->get_name(),
            'verified' => true,
            'login_method' => 'youtube_sos'
        ];
        
        $user_id = $this->find_or_create_user($profile);
        
        if ($user_id) {
            delete_transient('youtube_sos_' . md5($phone));
            
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            
            wp_send_json_success([
                'redirect' => home_url('/profil-merkezim/')
            ]);
        } else {
            wp_send_json_error(['message' => 'Kullanıcı oluşturulamadı.']);
        }
    }
    
    /**
     * Kullanıcı bul veya oluştur
     */
    protected function find_or_create_user($profile) {
        if (!empty($profile['phone'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'youtube_phone',
                'meta_value' => $profile['phone'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        $username = $profile['username'] ?? 'youtube_' . uniqid();
        $email = $profile['phone'] . '@youtube.sos';
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (!is_wp_error($user_id)) {
            if (!empty($profile['phone'])) {
                update_user_meta($user_id, 'youtube_phone', $profile['phone']);
            }
            if (!empty($profile['youtube_channel_id'])) {
                update_user_meta($user_id, 'youtube_channel_id', $profile['youtube_channel_id']);
            }
            if (!empty($profile['youtube_channel_title'])) {
                update_user_meta($user_id, 'youtube_channel_title', $profile['youtube_channel_title']);
            }
            if (!empty($profile['name'])) {
                update_user_meta($user_id, 'display_name', $profile['name']);
            }
            
            update_user_meta($user_id, 'ai_community_provider', $this->slug);
            update_user_meta($user_id, 'ai_community_profile', $profile);
            
            return $user_id;
        }
        
        return false;
    }
    
    /**
     * Getter metodlar
     */
    public function get_name() {
        return $this->name;
    }
    
    public function get_type() {
        return 'sos';
    }
    
    public function get_slug() {
        return $this->slug;
    }
    
    /**
     * Hata mesajı
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error">
            <h3>❌ YouTube Kurtarma Hatası</h3>
            <p><?php echo esc_html($message); ?></p>
            <p><a href="javascript:history.back()" class="back-btn">◀ Geri dön</a></p>
        </div>
        <style>
            .ai-community-error {
                max-width: 500px;
                margin: 50px auto;
                padding: 30px;
                background: #fff3f3;
                border: 2px solid #FF0000;
                border-radius: 15px;
                text-align: center;
                color: #FF0000;
            }
            .back-btn {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 20px;
                background: #FF0000;
                color: white;
                text-decoration: none;
                border-radius: 8px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}