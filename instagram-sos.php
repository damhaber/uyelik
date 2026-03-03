<?php
/**
 * AI Community Provider: Instagram SOS
 * 
 * @package AI_Community
 * 
 * Instagram SOS - Telefon/Email ile kurtarma girişi
 * wechat-sos.php mantığıyla aynı
 */

if (!defined('ABSPATH')) exit;

// SOS base class'ı include et
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/sos-base.php';
}

class AI_Community_Provider_Instagram_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'instagram-sos';
    protected $name = 'Instagram (SOS)';
    
    // Instagram özel yapılandırma
    private $app_id = '';
    private $app_secret = '';
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        if (isset($config['app_id'])) {
            $this->app_id = $config['app_id'];
        }
        if (isset($config['app_secret'])) {
            $this->app_secret = $config['app_secret'];
        }
    }
    
    /**
     * Ana handler - Tüm istekleri yönet
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        // Callback handling
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
        <div class="ai-community-sos-container instagram-sos">
            <h2>🔐 Instagram Kurtarma Girişi</h2>
            <p class="description">Instagram hesabınıza erişim sağlamak için alternatif yöntemler</p>
            
            <!-- Telefon ile Giriş -->
            <div class="sos-method phone-method">
                <div class="method-header">
                    <span class="method-icon">📱</span>
                    <h3>Telefon ile Doğrulama</h3>
                </div>
                <p class="method-desc">Instagram hesabınıza kayıtlı telefon numaranızı girin</p>
                
                <form method="post" action="" class="sos-form phone-form">
                    <?php wp_nonce_field('instagram_sos_auth', 'instagram_nonce'); ?>
                    
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
                            <option value="+55">Brezilya (+55)</option>
                            <option value="+52">Meksika (+52)</option>
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
                            <input type="radio" name="phone_method" value="whatsapp"> WhatsApp ile kod al
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
            
            <div class="sos-divider">
                <span>veya</span>
            </div>
            
            <!-- Email ile Giriş -->
            <div class="sos-method email-method">
                <div class="method-header">
                    <span class="method-icon">📧</span>
                    <h3>Email ile Doğrulama</h3>
                </div>
                <p class="method-desc">Instagram hesabınıza kayıtlı email adresinizi girin</p>
                
                <form method="post" action="" class="sos-form email-form">
                    <?php wp_nonce_field('instagram_sos_auth', 'instagram_nonce'); ?>
                    
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
            
            <div class="sos-divider">
                <span>veya</span>
            </div>
            
            <!-- Instagram Kullanıcı Adı ile Kurtarma -->
            <div class="sos-method username-method">
                <div class="method-header">
                    <span class="method-icon">🔍</span>
                    <h3>Kullanıcı Adı ile Kurtarma</h3>
                </div>
                <p class="method-desc">Instagram kullanıcı adınızı girin, size bağlı hesapları bulalım</p>
                
                <form method="post" action="" class="sos-form username-form">
                    <?php wp_nonce_field('instagram_sos_auth', 'instagram_nonce'); ?>
                    
                    <div class="form-row">
                        <input type="text" 
                               name="username" 
                               placeholder="Instagram kullanıcı adınız" 
                               class="username-input"
                               pattern="[a-zA-Z0-9._]+"
                               required>
                    </div>
                    
                    <button type="submit" name="find_username" class="sos-submit-btn">
                        Hesabımı Bul
                    </button>
                </form>
            </div>
            
            <!-- Meta Verified ile Hızlı Kurtarma -->
            <div class="sos-method verified-method">
                <div class="method-header">
                    <span class="method-icon">✅</span>
                    <h3>Meta Verified Kullanıcıları</h3>
                </div>
                <p class="method-desc">Meta Verified aboneliğiniz varsa, kimliğinizi hızlıca doğrulayın</p>
                
                <button class="meta-verified-btn" onclick="startMetaVerified()">
                    <span class="verified-badge">✅</span>
                    Meta Verified ile Doğrula
                </button>
            </div>
            
            <!-- Yardım İpuçları -->
            <div class="sos-help-tips">
                <h4>📌 Hesabınıza erişemiyor musunuz?</h4>
                <ul>
                    <li>Telefon numaranızı doğru formatta girdiğinizden emin olun (+90 555 123 4567)</li>
                    <li>Eski email adreslerinizi deneyin</li>
                    <li>Instagram kullanıcı adınızı unuttuysanız, bağlı Facebook hesabınızı deneyin</li>
                    <li>2FA (İki Faktörlü Doğrulama) açıksa, yedek kodlarınızı kullanın</li>
                </ul>
            </div>
            
            <!-- Güvenlik Uyarısı -->
            <div class="sos-security-notice">
                <p>🔒 Bu sayfa sadece hesap kurtarma içindir. Instagram şifrenizi asla kimseyle paylaşmayın.</p>
            </div>
        </div>
        
        <style>
            .instagram-sos {
                max-width: 600px;
                margin: 0 auto;
                padding: 30px 20px;
                background: linear-gradient(135deg, #f09433 0%, #d62976 50%, #962fbf 100%);
                border-radius: 20px;
                color: white;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            }
            
            .instagram-sos h2 {
                text-align: center;
                font-size: 28px;
                margin-bottom: 10px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            }
            
            .instagram-sos .description {
                text-align: center;
                margin-bottom: 30px;
                opacity: 0.9;
            }
            
            .sos-method {
                background: rgba(255,255,255,0.95);
                border-radius: 15px;
                padding: 20px;
                margin-bottom: 20px;
                color: #333;
                box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
                color: #d62976;
            }
            
            .method-desc {
                color: #666;
                margin-bottom: 15px;
                font-size: 14px;
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
            
            .phone-input, .email-input, .username-input {
                flex: 1;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 16px;
            }
            
            .phone-input:focus, .email-input:focus, .username-input:focus {
                border-color: #d62976;
                outline: none;
            }
            
            .method-options {
                margin-bottom: 15px;
                padding: 10px;
                background: #f5f5f5;
                border-radius: 8px;
            }
            
            .option {
                display: block;
                margin: 5px 0;
                color: #333;
            }
            
            .option input[type="radio"] {
                margin-right: 8px;
            }
            
            .sos-submit-btn {
                width: 100%;
                padding: 14px;
                background: linear-gradient(45deg, #f09433, #d62976, #962fbf);
                color: white;
                border: none;
                border-radius: 25px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: transform 0.3s, box-shadow 0.3s;
            }
            
            .sos-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            }
            
            .sos-divider {
                text-align: center;
                margin: 20px 0;
                position: relative;
                color: white;
            }
            
            .sos-divider::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 0;
                right: 0;
                height: 1px;
                background: rgba(255,255,255,0.3);
                z-index: 1;
            }
            
            .sos-divider span {
                background: transparent;
                padding: 0 15px;
                position: relative;
                z-index: 2;
                font-weight: bold;
            }
            
            .verified-method {
                text-align: center;
            }
            
            .meta-verified-btn {
                width: 100%;
                padding: 14px;
                background: #4267B2;
                color: white;
                border: none;
                border-radius: 25px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                transition: background 0.3s;
            }
            
            .meta-verified-btn:hover {
                background: #365899;
            }
            
            .verified-badge {
                font-size: 20px;
            }
            
            .sos-help-tips {
                background: rgba(0,0,0,0.5);
                border-radius: 10px;
                padding: 15px;
                margin-top: 20px;
            }
            
            .sos-help-tips h4 {
                margin: 0 0 10px 0;
                color: white;
            }
            
            .sos-help-tips ul {
                margin: 0;
                padding-left: 20px;
                color: white;
                opacity: 0.9;
            }
            
            .sos-help-tips li {
                margin: 5px 0;
            }
            
            .sos-security-notice {
                text-align: center;
                margin-top: 20px;
                padding: 10px;
                background: rgba(255,255,255,0.1);
                border-radius: 8px;
                font-size: 13px;
                color: white;
            }
            
            @media (max-width: 600px) {
                .form-row {
                    flex-direction: column;
                }
                
                .country-code {
                    width: 100%;
                }
            }
        </style>
        
        <script>
        function startMetaVerified() {
            alert('Meta Verified ile doğrulama başlatılıyor...\n\nBu özellik yakında aktif olacak.');
        }
        
        jQuery(document).ready(function($) {
            // Teleon formu gönderildiğinde
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
                        action: 'instagram_sos_send_code',
                        phone: countryCode + phone,
                        method: method,
                        nonce: '<?php echo wp_create_nonce('instagram_sos_ajax'); ?>'
                    },
                    beforeSend: function() {
                        form.find('.sos-submit-btn').prop('disabled', true).text('Gönderiliyor...');
                    },
                    success: function(response) {
                        if (response.success) {
                            form.html('<div class="success-message">✅ Doğrulama kodu gönderildi! Lütfen telefonunuzu kontrol edin.</div>' +
                                     '<div class="code-verification">' +
                                     '<input type="text" name="code" placeholder="6 haneli kodu girin" maxlength="6" class="code-input">' +
                                     '<button class="verify-btn" onclick="verifyCode(\'' + countryCode + phone + '\')">Doğrula</button>' +
                                     '</div>');
                        } else {
                            alert('Hata: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                    },
                    complete: function() {
                        form.find('.sos-submit-btn').prop('disabled', false).text('Doğrulama Kodu Gönder');
                    }
                });
            });
        });
        
        function verifyCode(phone) {
            var code = jQuery('.code-input').val();
            
            if (code.length !== 6) {
                alert('Lütfen 6 haneli kodu girin');
                return;
            }
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'instagram_sos_verify',
                    phone: phone,
                    code: code,
                    nonce: '<?php echo wp_create_nonce('instagram_sos_ajax'); ?>'
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
     * Callback handler
     */
    public function handle_callback($request) {
        try {
            // Telefon kodu gönderme
            if (isset($request['send_phone_code']) && isset($request['phone'])) {
                return $this->send_phone_code($request);
            }
            
            // Email kodu gönderme
            if (isset($request['send_email_code']) && isset($request['email'])) {
                return $this->send_email_code($request);
            }
            
            // Kullanıcı adı ile bulma
            if (isset($request['find_username']) && isset($request['username'])) {
                return $this->find_by_username($request);
            }
            
            // Kod doğrulama (AJAX'tan gelir)
            if (isset($request['code']) && isset($request['phone'])) {
                return $this->verify_and_login($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('Instagram SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Telefon kodu gönder
     */
    private function send_phone_code($request) {
        if (!isset($request['instagram_nonce']) || !wp_verify_nonce($request['instagram_nonce'], 'instagram_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $this->format_phone_number($request['phone']);
        $method = isset($request['phone_method']) ? $request['phone_method'] : 'sms';
        
        // 6 haneli kod oluştur
        $code = wp_rand(100000, 999999);
        
        // Kodu kaydet (10 dakika geçerli)
        set_transient('instagram_sos_' . md5($phone), [
            'code' => $code,
            'phone' => $phone,
            'attempts' => 0
        ], 600);
        
        // SMS gönder (gerçek implementasyon)
        $this->send_sms($phone, "Instagram doğrulama kodunuz: $code");
        
        return $this->show_verification_form($phone, 'phone');
    }
    
    /**
     * Email kodu gönder
     */
    private function send_email_code($request) {
        if (!isset($request['instagram_nonce']) || !wp_verify_nonce($request['instagram_nonce'], 'instagram_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $email = sanitize_email($request['email']);
        
        // Email var mı kontrol et
        $user = get_user_by('email', $email);
        
        if (!$user) {
            return $this->show_error('Bu email adresiyle kayıtlı hesap bulunamadı.');
        }
        
        // 6 haneli kod oluştur
        $code = wp_rand(100000, 999999);
        
        // Kodu kaydet
        set_transient('instagram_sos_' . md5($email), [
            'code' => $code,
            'email' => $email,
            'user_id' => $user->ID,
            'attempts' => 0
        ], 600);
        
        // Email gönder
        wp_mail($email, 'Instagram Doğrulama Kodu', "Instagram doğrulama kodunuz: $code");
        
        return $this->show_verification_form($email, 'email');
    }
    
    /**
     * Kullanıcı adı ile bul
     */
    private function find_by_username($request) {
        if (!isset($request['instagram_nonce']) || !wp_verify_nonce($request['instagram_nonce'], 'instagram_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $username = sanitize_user($request['username']);
        
        // Kullanıcı adına göre ara
        $user = get_user_by('login', $username);
        
        if (!$user) {
            // Meta'da instagram_username olarak da ara
            $user_query = new WP_User_Query([
                'meta_key' => 'instagram_username',
                'meta_value' => $username,
                'number' => 1
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                $user = $users[0];
            }
        }
        
        if (!$user) {
            return $this->show_error('Bu kullanıcı adıyla kayıtlı hesap bulunamadı.');
        }
        
        // Kullanıcının kayıtlı email/telefonunu göster (maskeli)
        $email = $user->user_email;
        $phone = get_user_meta($user->ID, 'phone', true);
        
        ob_start();
        ?>
        <div class="found-account">
            <h3>✅ Hesap Bulundu!</h3>
            <p>Hesap: <strong><?php echo esc_html($user->display_name); ?></strong></p>
            
            <?php if ($email && strpos($email, '@social.local') === false): ?>
            <p>Email: <?php echo $this->mask_email($email); ?></p>
            <button onclick="sendCodeToEmail('<?php echo esc_js($email); ?>')">Bu email'e kod gönder</button>
            <?php endif; ?>
            
            <?php if ($phone): ?>
            <p>Telefon: <?php echo $this->mask_phone($phone); ?></p>
            <button onclick="sendCodeToPhone('<?php echo esc_js($phone); ?>')">Bu telefona kod gönder</button>
            <?php endif; ?>
        </div>
        <script>
        function sendCodeToEmail(email) {
            // Email'e kod gönder
        }
        function sendCodeToPhone(phone) {
            // Telefona kod gönder
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Kodu doğrula ve giriş yap
     */
    public function verify_and_login($request) {
        $phone = $request['phone'];
        $code = $request['code'];
        
        // Kayıtlı kodu kontrol et
        $data = get_transient('instagram_sos_' . md5($phone));
        
        if (!$data) {
            wp_send_json_error(['message' => 'Kod süresi dolmuş veya geçersiz.']);
            return;
        }
        
        if ($data['code'] != $code) {
            $data['attempts']++;
            set_transient('instagram_sos_' . md5($phone), $data, 600);
            
            if ($data['attempts'] >= 3) {
                delete_transient('instagram_sos_' . md5($phone));
                wp_send_json_error(['message' => 'Çok fazla hatalı deneme. Lütfen tekrar kod isteyin.']);
            } else {
                wp_send_json_error(['message' => 'Geçersiz kod. Kalan hak: ' . (3 - $data['attempts'])]);
            }
            return;
        }
        
        // Profil oluştur
        $profile = [
            'id' => md5($phone . time()),
            'phone' => $phone,
            'username' => 'instagram_' . md5($phone),
            'name' => 'Instagram Kullanıcı',
            'provider' => $this->slug,
            'provider_name' => $this->get_name(),
            'verified' => true,
            'login_method' => 'instagram_sos'
        ];
        
        // Kullanıcı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if ($user_id) {
            // Kodu temizle
            delete_transient('instagram_sos_' . md5($phone));
            
            // Oturum başlat
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
     * SMS gönder (mock)
     */
    private function send_sms($phone, $message) {
        error_log("SMS gönderiliyor: $phone - $message");
        return true;
    }
    
    /**
     * Email maskele
     */
    private function mask_email($email) {
        $parts = explode('@', $email);
        $name = substr($parts[0], 0, 3) . '***';
        $domain = $parts[1];
        return $name . '@' . $domain;
    }
    
    /**
     * Telefon maskele
     */
    private function mask_phone($phone) {
        return substr($phone, 0, 4) . '***' . substr($phone, -4);
    }
    
    /**
     * Doğrulama formunu göster
     */
    private function show_verification_form($recipient, $type) {
        ob_start();
        ?>
        <div class="verification-form">
            <h3>✅ Doğrulama Kodu Gönderildi</h3>
            <p><?php echo $type === 'phone' ? 'Telefonunuza' : 'Email adresinize'; ?> 6 haneli kod gönderildi.</p>
            
            <div class="code-input-group">
                <input type="text" 
                       id="verification_code" 
                       maxlength="6" 
                       pattern="[0-9]{6}"
                       placeholder="6 haneli kod"
                       class="code-input">
                <button onclick="verifySOSCode('<?php echo esc_js($recipient); ?>')" class="verify-btn">
                    Doğrula
                </button>
            </div>
            
            <p class="resend-link">
                <a href="#" onclick="resendCode('<?php echo esc_js($recipient); ?>')">Kodu tekrar gönder</a>
            </p>
        </div>
        
        <style>
            .verification-form {
                text-align: center;
                padding: 20px;
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
                border: 2px solid #d62976;
                border-radius: 8px;
            }
            .verify-btn {
                padding: 15px 25px;
                background: #d62976;
                color: white;
                border: none;
                border-radius: 8px;
                font-weight: bold;
                cursor: pointer;
            }
            .resend-link {
                margin-top: 15px;
            }
            .resend-link a {
                color: #d62976;
            }
        </style>
        
        <script>
        function verifySOSCode(recipient) {
            var code = document.getElementById('verification_code').value;
            
            if (code.length !== 6) {
                alert('Lütfen 6 haneli kodu girin');
                return;
            }
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'instagram_sos_verify',
                    phone: recipient,
                    code: code,
                    nonce: '<?php echo wp_create_nonce('instagram_sos_ajax'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        alert('Hata: ' + response.message);
                    }
                }
            });
        }
        
        function resendCode(recipient) {
            // Tekrar kod gönder
        }
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    protected function find_or_create_user($profile) {
        // Telefon numarasına göre ara
        if (!empty($profile['phone'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'instagram_phone',
                'meta_value' => $profile['phone'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $profile['username'] ?? 'instagram_' . uniqid();
        $email = $profile['phone'] . '@instagram.sos';
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (!is_wp_error($user_id)) {
            if (!empty($profile['phone'])) {
                update_user_meta($user_id, 'instagram_phone', $profile['phone']);
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
     * Hata mesajı göster
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error">
            <h3>❌ Hata!</h3>
            <p><?php echo esc_html($message); ?></p>
            <p><a href="javascript:history.back()" class="back-btn">◀ Geri dön</a></p>
        </div>
        <style>
            .ai-community-error {
                max-width: 500px;
                margin: 50px auto;
                padding: 30px;
                background: #fff3f3;
                border: 2px solid #ff6b6b;
                border-radius: 15px;
                text-align: center;
                color: #d63031;
            }
            .back-btn {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 20px;
                background: #d63031;
                color: white;
                text-decoration: none;
                border-radius: 8px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}