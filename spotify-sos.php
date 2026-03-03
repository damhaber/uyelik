<?php
/**
 * AI Community Provider: Spotify SOS (Kurtarma)
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// SOS base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/sos-base.php';
}

class AI_Community_Provider_Spotify_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'spotify-sos';
    protected $name = 'Spotify (SOS)';
    
    // Spotify API yapılandırması
    private $client_id = '';
    private $client_secret = '';
    private $api_endpoint = 'https://api.spotify.com/v1';
    private $accounts_endpoint = 'https://accounts.spotify.com';
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Spotify SOS konfigürasyonu
        if (isset($config['client_id'])) {
            $this->client_id = $config['client_id'];
        }
        if (isset($config['client_secret'])) {
            $this->client_secret = $config['client_secret'];
        }
        if (isset($config['api_endpoint'])) {
            $this->api_endpoint = rtrim($config['api_endpoint'], '/');
        }
        if (isset($config['accounts_endpoint'])) {
            $this->accounts_endpoint = rtrim($config['accounts_endpoint'], '/');
        }
    }
    
    /**
     * Ana handler - Tüm istekleri yönet
     * Base class'taki abstract handle() metodunu implement eder
     */
    public function handle() {
        // Global request değişkenlerini kullan
        $request = array_merge($_GET, $_POST);
        
        // SOS login handling
        if (isset($request['code']) || isset($request['email']) || isset($request['username']) || isset($request['phone'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth handler - Kurtarma seçenekleri
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <div class="ai-community-sos-container spotify-sos">
            <div class="sos-header">
                <img src="https://storage.googleapis.com/pr-newsroom-wp/1/2023/05/Spotify_Primary_Logo_RGB_Green.png" alt="Spotify" class="spotify-logo">
                <h2>Spotify Hesabına Erişim</h2>
                <p class="sos-description">Spotify hesabına giriş yapamıyor musun? Alternatif yöntemlerle hesabını kurtar.</p>
            </div>
            
            <!-- Email ile Kurtarma -->
            <div class="sos-method email-method">
                <div class="method-icon">📧</div>
                <h3>E-posta ile Kurtarma</h3>
                <p class="method-description">Spotify hesabına kayıtlı e-posta adresine doğrulama kodu gönder</p>
                
                <form method="post" action="" class="sos-form" id="email-form">
                    <?php wp_nonce_field('spotify_sos_auth', 'spotify_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="email">E-posta Adresi:</label>
                        <input type="email" 
                               id="email" 
                               name="email" 
                               placeholder="ornek@email.com" 
                               required
                               class="sos-input">
                    </div>
                    
                    <button type="submit" name="spotify_send_email_code" class="sos-submit-btn">
                        <span class="btn-icon">📨</span> Doğrulama Kodu Gönder
                    </button>
                </form>
                
                <div class="method-note">
                    <small>📌 Spotify hesabında kayıtlı e-posta adresini gir.</small>
                </div>
            </div>
            
            <div class="sos-divider">
                <span>veya</span>
            </div>
            
            <!-- Kullanıcı Adı ile Kurtarma -->
            <div class="sos-method username-method">
                <div class="method-icon">👤</div>
                <h3>Kullanıcı Adı ile Kurtarma</h3>
                <p class="method-description">Spotify kullanıcı adını biliyorsan, SMS ile doğrulama kodu gönderelim</p>
                
                <form method="post" action="" class="sos-form" id="username-form">
                    <?php wp_nonce_field('spotify_sos_auth', 'spotify_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="username">Spotify Kullanıcı Adı:</label>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder="kullaniciadi" 
                               required
                               class="sos-input">
                    </div>
                    
                    <div class="form-group">
                        <label for="phone_username">Telefon Numarası:</label>
                        <div class="phone-input-group">
                            <select name="country_code_username" class="country-code">
                                <option value="+90">Türkiye (+90)</option>
                                <option value="+1">Amerika (+1)</option>
                                <option value="+44">İngiltere (+44)</option>
                                <option value="+49">Almanya (+49)</option>
                                <option value="+33">Fransa (+33)</option>
                                <option value="+46">İsveç (+46)</option>
                                <option value="+47">Norveç (+47)</option>
                                <option value="+45">Danimarka (+45)</option>
                                <option value="+358">Finlandiya (+358)</option>
                                <option value="+31">Hollanda (+31)</option>
                                <option value="+32">Belçika (+32)</option>
                                <option value="+41">İsviçre (+41)</option>
                                <option value="+43">Avusturya (+43)</option>
                                <option value="+61">Avustralya (+61)</option>
                                <option value="+64">Yeni Zelanda (+64)</option>
                            </select>
                            <input type="tel" 
                                   name="phone" 
                                   placeholder="532 123 4567" 
                                   required
                                   class="sos-phone-input">
                        </div>
                    </div>
                    
                    <button type="submit" name="spotify_send_username_code" class="sos-submit-btn">
                        <span class="btn-icon">📱</span> SMS Gönder
                    </button>
                </form>
                
                <div class="method-note">
                    <small>📌 Telefon numaran Spotify hesabında kayıtlı olmalı.</small>
                </div>
            </div>
            
            <div class="sos-divider">
                <span>veya</span>
            </div>
            
            <!-- Spotify Connect ile Kurtarma -->
            <div class="sos-method connect-method">
                <div class="method-icon">🔗</div>
                <h3>Spotify Connect ile Kurtarma</h3>
                <p class="method-description">Spotify uygulamasına bağlı bir cihazın varsa, cihaz üzerinden doğrula</p>
                
                <div class="connect-devices">
                    <div class="device-option">
                        <span class="device-icon">📱</span>
                        <span class="device-name">Telefon</span>
                        <button class="device-btn" onclick="alert('Cihazda Spotify uygulamasını açın ve bildirimi onaylayın.')">Doğrula</button>
                    </div>
                    <div class="device-option">
                        <span class="device-icon">💻</span>
                        <span class="device-name">Bilgisayar</span>
                        <button class="device-btn" onclick="alert('Bilgisayardaki Spotify uygulamasında bildirimi onaylayın.')">Doğrula</button>
                    </div>
                    <div class="device-option">
                        <span class="device-icon">📺</span>
                        <span class="device-name">TV</span>
                        <button class="device-btn" onclick="alert('TV\'deki Spotify uygulamasında bildirimi onaylayın.')">Doğrula</button>
                    </div>
                </div>
                
                <div class="method-note">
                    <small>📌 Spotify hesabın bu cihazlarda oturum açık olmalı.</small>
                </div>
            </div>
            
            <div class="sos-footer">
                <p>Spotify hesabına hala erişemiyor musun?</p>
                <a href="https://support.spotify.com/tr/account-payment/" target="_blank" class="support-link">
                    Spotify Destek Sayfası
                </a>
            </div>
        </div>
        
        <style>
            .spotify-sos {
                max-width: 600px;
                margin: 30px auto;
                padding: 30px;
                background: linear-gradient(145deg, #1DB954 0%, #191414 100%);
                border-radius: 20px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                color: white;
            }
            
            .sos-header {
                text-align: center;
                margin-bottom: 30px;
            }
            
            .spotify-logo {
                width: 80px;
                height: 80px;
                margin-bottom: 15px;
                filter: drop-shadow(0 4px 10px rgba(0,0,0,0.3));
            }
            
            .sos-header h2 {
                color: white;
                font-size: 28px;
                margin-bottom: 10px;
                font-weight: 700;
            }
            
            .sos-description {
                color: #b3b3b3;
                font-size: 16px;
                line-height: 1.5;
            }
            
            .sos-method {
                background: rgba(255,255,255,0.1);
                backdrop-filter: blur(10px);
                border-radius: 15px;
                padding: 25px;
                margin-bottom: 20px;
                border: 1px solid rgba(255,255,255,0.1);
                transition: all 0.3s ease;
            }
            
            .sos-method:hover {
                background: rgba(255,255,255,0.15);
                transform: translateY(-2px);
            }
            
            .method-icon {
                font-size: 48px;
                text-align: center;
                margin-bottom: 15px;
            }
            
            .sos-method h3 {
                color: #1DB954;
                font-size: 20px;
                margin-bottom: 10px;
                text-align: center;
            }
            
            .method-description {
                color: #b3b3b3;
                text-align: center;
                margin-bottom: 20px;
                font-size: 14px;
            }
            
            .sos-form {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }
            
            .form-group {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            
            .form-group label {
                color: white;
                font-size: 14px;
                font-weight: 500;
            }
            
            .sos-input {
                padding: 12px 15px;
                border: none;
                border-radius: 25px;
                background: rgba(255,255,255,0.9);
                font-size: 16px;
                transition: all 0.3s;
            }
            
            .sos-input:focus {
                outline: none;
                box-shadow: 0 0 0 3px rgba(29, 185, 84, 0.5);
                background: white;
            }
            
            .phone-input-group {
                display: flex;
                gap: 10px;
            }
            
            .country-code {
                width: 120px;
                padding: 12px;
                border: none;
                border-radius: 25px;
                background: rgba(255,255,255,0.9);
                font-size: 14px;
            }
            
            .sos-phone-input {
                flex: 1;
                padding: 12px 15px;
                border: none;
                border-radius: 25px;
                background: rgba(255,255,255,0.9);
                font-size: 16px;
            }
            
            .sos-submit-btn {
                background: #1DB954;
                color: white;
                border: none;
                padding: 14px 25px;
                border-radius: 25px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
            }
            
            .sos-submit-btn:hover {
                background: #169c46;
                transform: scale(1.02);
            }
            
            .btn-icon {
                font-size: 20px;
            }
            
            .method-note {
                margin-top: 15px;
                color: #b3b3b3;
                font-size: 13px;
                text-align: center;
            }
            
            .sos-divider {
                text-align: center;
                margin: 25px 0;
                position: relative;
            }
            
            .sos-divider::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 0;
                right: 0;
                height: 1px;
                background: rgba(255,255,255,0.2);
                z-index: 1;
            }
            
            .sos-divider span {
                background: #191414;
                padding: 0 15px;
                color: #b3b3b3;
                font-size: 14px;
                position: relative;
                z-index: 2;
            }
            
            .connect-devices {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin: 15px 0;
            }
            
            .device-option {
                display: flex;
                align-items: center;
                padding: 10px 15px;
                background: rgba(255,255,255,0.05);
                border-radius: 10px;
                gap: 10px;
            }
            
            .device-icon {
                font-size: 24px;
                width: 40px;
            }
            
            .device-name {
                flex: 1;
                color: white;
            }
            
            .device-btn {
                background: rgba(255,255,255,0.2);
                color: white;
                border: 1px solid rgba(255,255,255,0.3);
                padding: 8px 15px;
                border-radius: 20px;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .device-btn:hover {
                background: #1DB954;
                border-color: #1DB954;
            }
            
            .sos-footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid rgba(255,255,255,0.2);
            }
            
            .sos-footer p {
                color: #b3b3b3;
                margin-bottom: 10px;
            }
            
            .support-link {
                display: inline-block;
                color: #1DB954;
                text-decoration: none;
                font-weight: 500;
                padding: 8px 20px;
                border: 2px solid #1DB954;
                border-radius: 25px;
                transition: all 0.3s;
            }
            
            .support-link:hover {
                background: #1DB954;
                color: white;
            }
            
            @media (max-width: 480px) {
                .spotify-sos {
                    padding: 20px;
                    margin: 15px;
                }
                
                .phone-input-group {
                    flex-direction: column;
                }
                
                .country-code {
                    width: 100%;
                }
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Form validasyonu
            $('.sos-form').on('submit', function(e) {
                const emailForm = $(this).find('input[type="email"]');
                if (emailForm.length && !isValidEmail(emailForm.val())) {
                    e.preventDefault();
                    alert('Lütfen geçerli bir e-posta adresi girin.');
                }
            });
            
            function isValidEmail(email) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            }
            
            // Telefon input masking
            $('.sos-phone-input').on('input', function() {
                let value = $(this).val().replace(/\D/g, '');
                if (value.length > 0) {
                    if (value.length <= 3) {
                        value = value;
                    } else if (value.length <= 6) {
                        value = value.slice(0,3) + ' ' + value.slice(3);
                    } else {
                        value = value.slice(0,3) + ' ' + value.slice(3,6) + ' ' + value.slice(6,10);
                    }
                    $(this).val(value);
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Callback handler
     */
    public function handle_callback($request) {
        try {
            // Email ile kod gönderme
            if (isset($request['spotify_send_email_code']) && isset($request['email'])) {
                return $this->send_email_code($request);
            }
            
            // Kullanıcı adı ile SMS gönderme
            if (isset($request['spotify_send_username_code']) && isset($request['username']) && isset($request['phone'])) {
                return $this->send_username_code($request);
            }
            
            // Kod doğrulama
            if (isset($request['spotify_code']) && (isset($request['email']) || isset($request['phone']))) {
                return $this->verify_code_and_login($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('Spotify SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Email ile doğrulama kodu gönder
     */
    public function send_email_code($request) {
        // Nonce kontrolü
        if (!isset($request['spotify_nonce']) || !wp_verify_nonce($request['spotify_nonce'], 'spotify_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $email = sanitize_email($request['email']);
        
        if (!is_email($email)) {
            return $this->show_error('Geçersiz e-posta adresi.');
        }
        
        // Email'in Spotify'da kayıtlı olup olmadığını kontrol et
        $spotify_user = $this->check_spotify_email($email);
        
        if (!$spotify_user) {
            return $this->show_error('Bu e-posta adresi ile kayıtlı bir Spotify hesabı bulunamadı.');
        }
        
        // 6 haneli random kod oluştur
        $verification_code = wp_rand(100000, 999999);
        
        // Kodu transient'a kaydet (10 dakika)
        $this->save_verification_code($email, $verification_code, $spotify_user);
        
        // Email gönder
        $sent = $this->send_verification_email($email, $verification_code, $spotify_user);
        
        if ($sent) {
            return $this->show_verification_form('email', $email, $this->mask_email($email));
        } else {
            return $this->show_error('Doğrulama kodu gönderilemedi. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Kullanıcı adı ile SMS gönder
     */
    public function send_username_code($request) {
        // Nonce kontrolü
        if (!isset($request['spotify_nonce']) || !wp_verify_nonce($request['spotify_nonce'], 'spotify_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $username = sanitize_user($request['username']);
        $country_code = sanitize_text_field($request['country_code_username']);
        $phone = $this->format_phone_number($country_code . $request['phone']);
        
        if (empty($username)) {
            return $this->show_error('Geçersiz kullanıcı adı.');
        }
        
        // Kullanıcı adı ile Spotify'da ara
        $spotify_user = $this->check_spotify_username($username);
        
        if (!$spotify_user) {
            return $this->show_error('Bu kullanıcı adı ile kayıtlı bir Spotify hesabı bulunamadı.');
        }
        
        // Telefon numarasını doğrula
        if (!$this->verify_spotify_phone($spotify_user, $phone)) {
            return $this->show_error('Bu telefon numarası Spotify hesabınızda kayıtlı görünmüyor.');
        }
        
        // 6 haneli random kod oluştur
        $verification_code = wp_rand(100000, 999999);
        
        // Kodu transient'a kaydet
        $this->save_verification_code($username, $verification_code, $spotify_user);
        
        // SMS gönder
        $sent = $this->send_verification_sms($phone, $verification_code);
        
        if ($sent) {
            return $this->show_verification_form('sms', $username, $this->mask_phone($phone));
        } else {
            return $this->show_error('SMS gönderilemedi. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Doğrulama formunu göster
     */
    private function show_verification_form($method, $identifier, $masked_value) {
        $method_text = ($method === 'email') ? 'e-posta' : 'SMS';
        
        ob_start();
        ?>
        <div class="ai-community-sos-container spotify-sos verification-form">
            <div class="sos-header">
                <img src="https://storage.googleapis.com/pr-newsroom-wp/1/2023/05/Spotify_Primary_Logo_RGB_Green.png" alt="Spotify" class="spotify-logo">
                <h2>Doğrulama Kodu</h2>
            </div>
            
            <div class="sos-method">
                <div class="method-icon">🔐</div>
                <p class="success-message">
                    <strong><?php echo esc_html($masked_value); ?></strong> adresine/numarasına 
                    6 haneli doğrulama kodu gönderildi.
                </p>
                
                <form method="post" action="" class="sos-form">
                    <?php wp_nonce_field('spotify_sos_verify', 'verify_nonce'); ?>
                    
                    <input type="hidden" name="identifier" value="<?php echo esc_attr($identifier); ?>">
                    <input type="hidden" name="method" value="<?php echo esc_attr($method); ?>">
                    
                    <div class="form-group">
                        <label for="spotify_code">Doğrulama Kodu:</label>
                        <input type="text" 
                               id="spotify_code" 
                               name="spotify_code" 
                               placeholder="6 haneli kod" 
                               pattern="[0-9]{6}"
                               maxlength="6"
                               required
                               class="sos-input code-input"
                               autocomplete="off"
                               inputmode="numeric">
                    </div>
                    
                    <button type="submit" name="spotify_verify" class="sos-submit-btn">
                        <span class="btn-icon">✓</span> Doğrula ve Giriş Yap
                    </button>
                </form>
                
                <div class="resend-section">
                    <p>Kod gelmedi mi?</p>
                    <form method="post" action="" class="resend-form">
                        <?php wp_nonce_field('spotify_sos_auth', 'spotify_nonce'); ?>
                        <input type="hidden" name="<?php echo $method === 'email' ? 'email' : 'username'; ?>" 
                               value="<?php echo esc_attr($identifier); ?>">
                        <?php if ($method !== 'email'): ?>
                        <input type="hidden" name="phone" value="<?php echo esc_attr($identifier); ?>">
                        <?php endif; ?>
                        <button type="submit" name="<?php echo $method === 'email' ? 'spotify_send_email_code' : 'spotify_send_username_code'; ?>" 
                                class="resend-btn">
                            Yeniden Gönder
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <style>
            .verification-form .sos-method {
                text-align: center;
            }
            
            .success-message {
                background: rgba(29, 185, 84, 0.2);
                color: white;
                padding: 15px;
                border-radius: 10px;
                margin-bottom: 20px;
                font-size: 16px;
                border: 1px solid #1DB954;
            }
            
            .code-input {
                text-align: center;
                font-size: 28px;
                letter-spacing: 8px;
                font-weight: 600;
            }
            
            .resend-section {
                margin-top: 20px;
                text-align: center;
            }
            
            .resend-section p {
                color: #b3b3b3;
                margin-bottom: 10px;
            }
            
            .resend-btn {
                background: none;
                border: 2px solid #1DB954;
                color: #1DB954;
                padding: 8px 25px;
                border-radius: 25px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                transition: all 0.3s;
            }
            
            .resend-btn:hover {
                background: #1DB954;
                color: white;
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
        if (!isset($request['verify_nonce']) || !wp_verify_nonce($request['verify_nonce'], 'spotify_sos_verify')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $identifier = $request['identifier'];
        $method = $request['method'];
        $code = $request['spotify_code'];
        
        // Kodu doğrula
        $verification_data = $this->verify_code($identifier, $code);
        
        if (!$verification_data) {
            return $this->show_error('Geçersiz doğrulama kodu. Lütfen tekrar deneyin.');
        }
        
        // Spotify kullanıcı bilgilerini al
        $spotify_user = $verification_data['user_data'];
        
        // Profil oluştur
        $profile = $this->create_profile_from_spotify($spotify_user, $method);
        
        // WordPress kullanıcısı oluştur veya bul
        $user_id = $this->find_or_create_user($profile);
        
        if ($user_id) {
            // WordPress oturumu başlat
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);
            
            // Başarılı giriş - yönlendir
            wp_redirect(home_url('/dashboard?welcome=spotify'));
            exit;
        } else {
            return $this->show_error('Kullanıcı hesabı oluşturulamadı.');
        }
    }
    
    /**
     * Spotify'da email ile kullanıcı ara
     */
    private function check_spotify_email($email) {
        // Burada gerçek Spotify API entegrasyonu olmalı
        // Şimdilik mock data dönüyoruz
        
        // Access token al
        $access_token = $this->get_spotify_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        // Spotify API'de email ile kullanıcı arama (gerçek API'de bu direkt mümkün değil)
        // Şimdilik mock data
        
        return [
            'id' => 'spotify_user_' . md5($email),
            'email' => $email,
            'display_name' => 'Spotify Kullanıcı',
            'country' => 'TR',
            'product' => 'premium', // premium, free, etc.
            'uri' => 'spotify:user:' . md5($email)
        ];
    }
    
    /**
     * Spotify'da kullanıcı adı ile ara
     */
    private function check_spotify_username($username) {
        // Gerçek Spotify API'de kullanıcı adı ile arama
        $access_token = $this->get_spotify_access_token();
        
        if (!$access_token) {
            return false;
        }
        
        // Mock data
        return [
            'id' => $username,
            'display_name' => ucfirst($username),
            'email' => $username . '@example.com',
            'country' => 'TR',
            'product' => 'premium'
        ];
    }
    
    /**
     * Telefon numarasını doğrula
     */
    private function verify_spotify_phone($spotify_user, $phone) {
        // Spotify'da telefon doğrulama API'si yok
        // Şimdilik true dön
        return true;
    }
    
    /**
     * Spotify access token al
     */
    private function get_spotify_access_token() {
        $cached_token = get_transient('spotify_sos_access_token');
        
        if ($cached_token) {
            return $cached_token;
        }
        
        $url = $this->accounts_endpoint . '/api/token';
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded'
            ],
            'body' => [
                'grant_type' => 'client_credentials'
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Spotify token hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if (isset($result['access_token'])) {
            set_transient('spotify_sos_access_token', $result['access_token'], $result['expires_in'] - 60);
            return $result['access_token'];
        }
        
        return false;
    }
    
    /**
     * Doğrulama e-postası gönder
     */
    private function send_verification_email($email, $code, $user_data) {
        $subject = 'Spotify Doğrulama Kodu';
        $message = "Merhaba {$user_data['display_name']},\n\n";
        $message .= "Spotify hesabınıza giriş yapmak için doğrulama kodunuz: {$code}\n\n";
        $message .= "Bu kod 10 dakika süreyle geçerlidir.\n\n";
        $message .= "Eğer bu işlemi siz yapmadıysanız, bu e-postayı dikkate almayın.\n\n";
        $message .= "Saygılarımızla,\nAI Community Spotify SOS";
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        return wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Doğrulama SMS'i gönder
     */
    private function send_verification_sms($phone, $code) {
        // SMS servisi entegrasyonu
        // Twilio, Nexmo, vb. kullanılabilir
        error_log("Spotify SMS kodu {$phone} için: {$code}");
        return true; // Mock
    }
    
    /**
     * Kodu kaydet
     */
    protected function save_verification_code($identifier, $code, $user_data, $expiry = 600) {
        $data = [
            'code' => $code,
            'user_data' => $user_data,
            'created_at' => time(),
            'attempts' => 0
        ];
        
        set_transient('spotify_sos_' . md5($identifier), $data, $expiry);
    }
    
    /**
     * Kodu doğrula
     */
    public function verify_code($identifier, $code) {
        $data = get_transient('spotify_sos_' . md5($identifier));
        
        if ($data && $data['code'] == $code) {
            // Başarılı - transient'ı sil
            delete_transient('spotify_sos_' . md5($identifier));
            return $data;
        }
        
        return false;
    }
    
    /**
     * Spotify verilerinden profil oluştur
     */
    private function create_profile_from_spotify($spotify_user, $method) {
        $profile = [
            'id' => $spotify_user['id'],
            'provider' => $this->slug,
            'provider_name' => $this->get_name(),
            'username' => $spotify_user['id'],
            'display_name' => isset($spotify_user['display_name']) ? $spotify_user['display_name'] : $spotify_user['id'],
            'email' => isset($spotify_user['email']) ? $spotify_user['email'] : '',
            'country' => isset($spotify_user['country']) ? $spotify_user['country'] : '',
            'product' => isset($spotify_user['product']) ? $spotify_user['product'] : 'free',
            'uri' => isset($spotify_user['uri']) ? $spotify_user['uri'] : '',
            'verified' => true,
            'login_method' => 'spotify_sos_' . $method
        ];
        
        return $profile;
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    protected function find_or_create_user($profile) {
        // Spotify ID'ye göre kullanıcı ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'spotify_sos_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Email varsa email'e göre ara
        if (!empty($profile['email']) && is_email($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                return $user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = !empty($profile['email']) ? $profile['email'] : $profile['id'] . '@spotify.sos';
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return false;
        }
        
        // Meta verileri kaydet
        update_user_meta($user_id, 'spotify_sos_id', $profile['id']);
        update_user_meta($user_id, 'spotify_sos_display_name', $profile['display_name']);
        update_user_meta($user_id, 'spotify_sos_country', $profile['country']);
        update_user_meta($user_id, 'spotify_sos_product', $profile['product']);
        update_user_meta($user_id, 'spotify_sos_uri', $profile['uri']);
        update_user_meta($user_id, 'spotify_sos_login_method', $profile['login_method']);
        update_user_meta($user_id, 'spotify_sos_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
        // Görünen ismi ayarla
        wp_update_user([
            'ID' => $user_id,
            'display_name' => $profile['display_name']
        ]);
        
        return $user_id;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     */
    protected function generate_unique_username($profile) {
        $base = sanitize_user($profile['display_name'], true);
        
        if (empty($base)) {
            $base = 'spotify_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        $username = $base;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * E-posta adresini maskele
     */
    private function mask_email($email) {
        if (empty($email)) return '';
        
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];
        
        $masked_name = substr($name, 0, 3) . str_repeat('*', max(0, strlen($name) - 3));
        
        return $masked_name . '@' . $domain;
    }
    
    /**
     * Telefon numarasını maskele
     */
    private function mask_phone($phone) {
        if (empty($phone)) return '';
        
        // +905321234567 -> +90***4567
        $country_code = '';
        $number = $phone;
        
        if (substr($phone, 0, 1) === '+') {
            $parts = explode(' ', $phone);
            $country_code = $parts[0];
            $number = isset($parts[1]) ? $parts[1] : substr($phone, strlen($country_code));
        }
        
        $number = preg_replace('/\D/', '', $number);
        $masked = substr($number, 0, 3) . '***' . substr($number, -4);
        
        return $country_code ? $country_code . ' ' . $masked : $masked;
    }
    
    /**
     * Hata mesajı göster
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error spotify-error">
            <h3>😕 Hata!</h3>
            <p><?php echo esc_html($message); ?></p>
            <p><a href="javascript:history.back()" class="back-link">← Geri dön</a></p>
        </div>
        
        <style>
            .spotify-error {
                max-width: 500px;
                margin: 50px auto;
                padding: 30px;
                background: #191414;
                border: 2px solid #1DB954;
                border-radius: 15px;
                color: white;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            }
            
            .spotify-error h3 {
                color: #1DB954;
                font-size: 24px;
                margin-bottom: 15px;
            }
            
            .spotify-error p {
                color: #b3b3b3;
                line-height: 1.6;
            }
            
            .back-link {
                display: inline-block;
                margin-top: 20px;
                color: #1DB954;
                text-decoration: none;
                padding: 10px 25px;
                border: 2px solid #1DB954;
                border-radius: 25px;
                transition: all 0.3s;
            }
            
            .back-link:hover {
                background: #1DB954;
                color: white;
            }
        </style>
        <?php
        return ob_get_clean();
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
}