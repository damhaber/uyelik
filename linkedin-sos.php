<?php
/**
 * AI Community Provider: LinkedIn SOS
 * 
 * @package AI_Community
 * 
 * LinkedIn SOS - Telefon/Email ile kurtarma girişi
 * instagram-sos.php mantığıyla aynı
 */

if (!defined('ABSPATH')) exit;

// SOS base class'ı include et
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/sos-base.php';
}

class AI_Community_Provider_LinkedIn_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'linkedin-sos';
    protected $name = 'LinkedIn (SOS)';
    
    // LinkedIn özel yapılandırma
    private $client_id = '';
    private $client_secret = '';
    
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
    }
    
    /**
     * Ana handler - Tüm istekleri yönet
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
        <div class="ai-community-sos-container linkedin-sos">
            <h2>💼 LinkedIn Kurtarma Girişi</h2>
            <p class="description">LinkedIn hesabınıza erişim sağlamak için alternatif yöntemler</p>
            
            <!-- Telefon ile Giriş -->
            <div class="sos-method phone-method">
                <div class="method-header">
                    <span class="method-icon">📱</span>
                    <h3>Telefon ile Doğrulama</h3>
                </div>
                <p class="method-desc">LinkedIn hesabınıza kayıtlı telefon numaranızı girin</p>
                
                <form method="post" action="" class="sos-form phone-form">
                    <?php wp_nonce_field('linkedin_sos_auth', 'linkedin_nonce'); ?>
                    
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
                            <option value="+61">Avustralya (+61)</option>
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
                <p class="method-desc">LinkedIn hesabınıza kayıtlı email adresinizi girin</p>
                
                <form method="post" action="" class="sos-form email-form">
                    <?php wp_nonce_field('linkedin_sos_auth', 'linkedin_nonce'); ?>
                    
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
            
            <!-- LinkedIn Profil URL ile Kurtarma -->
            <div class="sos-method profile-method">
                <div class="method-header">
                    <span class="method-icon">🔗</span>
                    <h3>LinkedIn Profil URL ile Kurtarma</h3>
                </div>
                <p class="method-desc">LinkedIn profilinizin URL'ini girin, size bağlı hesapları bulalım</p>
                
                <form method="post" action="" class="sos-form profile-form">
                    <?php wp_nonce_field('linkedin_sos_auth', 'linkedin_nonce'); ?>
                    
                    <div class="form-row">
                        <input type="url" 
                               name="profile_url" 
                               placeholder="https://www.linkedin.com/in/kullaniciadi/" 
                               class="profile-input"
                               pattern="https?://(www\.)?linkedin\.com/.*"
                               required>
                    </div>
                    
                    <button type="submit" name="find_profile" class="sos-submit-btn">
                        Profili Bul
                    </button>
                </form>
            </div>
            
            <!-- İş Email'i ile Kurumsal Kurtarma -->
            <div class="sos-method work-method">
                <div class="method-header">
                    <span class="method-icon">🏢</span>
                    <h3>İş Email'i ile Kurtarma</h3>
                </div>
                <p class="method-desc">Şirket email adresinizle hesabınızı doğrulayın</p>
                
                <form method="post" action="" class="sos-form work-form">
                    <?php wp_nonce_field('linkedin_sos_auth', 'linkedin_nonce'); ?>
                    
                    <div class="form-row">
                        <input type="email" 
                               name="work_email" 
                               placeholder="isim@sirketiniz.com" 
                               class="email-input"
                               required>
                    </div>
                    
                    <p class="note">* Şirket email adresinize doğrulama linki göndereceğiz</p>
                    
                    <button type="submit" name="send_work_email" class="sos-submit-btn">
                        İş Email'ine Gönder
                    </button>
                </form>
            </div>
            
            <!-- Premium Kullanıcılar için Hızlı Destek -->
            <div class="sos-method premium-method">
                <div class="method-header">
                    <span class="method-icon">⭐</span>
                    <h3>LinkedIn Premium Kullanıcıları</h3>
                </div>
                <p class="method-desc">Premium üyeler için öncelikli destek hattı</p>
                
                <button class="premium-support-btn" onclick="startPremiumSupport()">
                    <span class="premium-badge">⭐</span>
                    Premium Destek Başlat
                </button>
            </div>
            
            <!-- Yardım İpuçları -->
            <div class="sos-help-tips">
                <h4>📌 LinkedIn hesabınıza erişemiyor musunuz?</h4>
                <ul>
                    <li>İş email adresinizi deneyin (genellikle @şirketiniz.com)</li>
                    <li>LinkedIn profilinizin URL'ini girin (linkedin.com/in/kullaniciadi)</li>
                    <li>Eski iş yerinizdeki email adreslerini deneyin</li>
                    <li>LinkedIn mobil uygulamasından "Hesap Kurtarma" bölümünü kullanın</li>
                </ul>
            </div>
            
            <!-- Güvenlik Uyarısı -->
            <div class="sos-security-notice">
                <p>🔒 LinkedIn şifrenizi asla kimseyle paylaşmayın. Resmi LinkedIn destek ekibi asla şifre sormaz.</p>
            </div>
        </div>
        
        <style>
            .linkedin-sos {
                max-width: 600px;
                margin: 0 auto;
                padding: 30px 20px;
                background: linear-gradient(135deg, #0077B5 0%, #00A0DC 50%, #0077B5 100%);
                border-radius: 20px;
                color: white;
                box-shadow: 0 10px 30px rgba(0,119,181,0.3);
            }
            
            .linkedin-sos h2 {
                text-align: center;
                font-size: 28px;
                margin-bottom: 10px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
            }
            
            .linkedin-sos .description {
                text-align: center;
                margin-bottom: 30px;
                opacity: 0.9;
            }
            
            .sos-method {
                background: rgba(255,255,255,0.98);
                border-radius: 15px;
                padding: 20px;
                margin-bottom: 20px;
                color: #333;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                border-left: 5px solid #0077B5;
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
                color: #0077B5;
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
            
            .phone-input, .email-input, .profile-input {
                flex: 1;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 16px;
            }
            
            .phone-input:focus, .email-input:focus, .profile-input:focus {
                border-color: #0077B5;
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
                background: #0077B5;
                color: white;
                border: none;
                border-radius: 25px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .sos-submit-btn:hover {
                background: #005582;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,119,181,0.3);
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
            
            .note {
                font-size: 12px;
                color: #666;
                margin: 5px 0 10px;
                font-style: italic;
            }
            
            .premium-method {
                text-align: center;
                background: linear-gradient(135deg, #f5f5f5, #fff);
            }
            
            .premium-support-btn {
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
                transition: background 0.3s;
            }
            
            .premium-support-btn:hover {
                background: #FFC800;
            }
            
            .premium-badge {
                font-size: 20px;
            }
            
            .sos-help-tips {
                background: rgba(0,0,0,0.3);
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
                background: rgba(0,0,0,0.2);
                border-radius: 8px;
                font-size: 13px;
                color: white;
            }
        </style>
        
        <script>
        function startPremiumSupport() {
            alert('LinkedIn Premium Destek başlatılıyor...\n\nEn kısa sürede size ulaşacağız.');
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
                        action: 'linkedin_sos_send_code',
                        phone: countryCode + phone,
                        method: method,
                        nonce: '<?php echo wp_create_nonce('linkedin_sos_ajax'); ?>'
                    },
                    beforeSend: function() {
                        form.find('.sos-submit-btn').prop('disabled', true).text('Gönderiliyor...');
                    },
                    success: function(response) {
                        if (response.success) {
                            form.html('<div class="success-message">✅ Doğrulama kodu gönderildi!</div>' +
                                     '<div class="code-verification">' +
                                     '<input type="text" name="code" placeholder="6 haneli kod" maxlength="6" class="code-input">' +
                                     '<button class="verify-btn" onclick="verifyLinkedInCode(\'' + countryCode + phone + '\')">Doğrula</button>' +
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
            
            // Email formu
            $('.email-form').on('submit', function(e) {
                e.preventDefault();
                
                var form = $(this);
                var email = form.find('.email-input').val();
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'linkedin_sos_send_email_code',
                        email: email,
                        nonce: '<?php echo wp_create_nonce('linkedin_sos_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            form.html('<div class="success-message">✅ Email gönderildi!</div>' +
                                     '<div class="code-verification">' +
                                     '<input type="text" name="code" placeholder="6 haneli kod" maxlength="6" class="code-input">' +
                                     '<button class="verify-btn" onclick="verifyLinkedInEmailCode(\'' + email + '\')">Doğrula</button>' +
                                     '</div>');
                        } else {
                            alert('Hata: ' + response.message);
                        }
                    }
                });
            });
        });
        
        function verifyLinkedInCode(phone) {
            var code = jQuery('.code-input').val();
            
            if (code.length !== 6) {
                alert('Lütfen 6 haneli kodu girin');
                return;
            }
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'linkedin_sos_verify',
                    phone: phone,
                    code: code,
                    nonce: '<?php echo wp_create_nonce('linkedin_sos_ajax'); ?>'
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
        
        function verifyLinkedInEmailCode(email) {
            var code = jQuery('.code-input').val();
            
            if (code.length !== 6) {
                alert('Lütfen 6 haneli kodu girin');
                return;
            }
            
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'linkedin_sos_verify_email',
                    email: email,
                    code: code,
                    nonce: '<?php echo wp_create_nonce('linkedin_sos_ajax'); ?>'
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
            
            // İş email'i gönderme
            if (isset($request['send_work_email']) && isset($request['work_email'])) {
                return $this->send_work_email($request);
            }
            
            // Profil URL ile bulma
            if (isset($request['find_profile']) && isset($request['profile_url'])) {
                return $this->find_by_profile_url($request);
            }
            
            // Kod doğrulama (AJAX)
            if (isset($request['code']) && isset($request['phone'])) {
                return $this->verify_and_login($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('LinkedIn SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Telefon kodu gönder
     */
    private function send_phone_code($request) {
        if (!isset($request['linkedin_nonce']) || !wp_verify_nonce($request['linkedin_nonce'], 'linkedin_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $this->format_phone_number($request['phone']);
        $method = isset($request['phone_method']) ? $request['phone_method'] : 'sms';
        
        $code = wp_rand(100000, 999999);
        
        set_transient('linkedin_sos_' . md5($phone), [
            'code' => $code,
            'phone' => $phone,
            'attempts' => 0
        ], 600);
        
        $this->send_sms($phone, "LinkedIn doğrulama kodunuz: $code");
        
        return $this->show_verification_form($phone, 'phone');
    }
    
    /**
     * Email kodu gönder
     */
    private function send_email_code($request) {
        if (!isset($request['linkedin_nonce']) || !wp_verify_nonce($request['linkedin_nonce'], 'linkedin_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $email = sanitize_email($request['email']);
        
        $user = get_user_by('email', $email);
        
        if (!$user) {
            return $this->show_error('Bu email adresiyle kayıtlı hesap bulunamadı.');
        }
        
        $code = wp_rand(100000, 999999);
        
        set_transient('linkedin_sos_' . md5($email), [
            'code' => $code,
            'email' => $email,
            'user_id' => $user->ID,
            'attempts' => 0
        ], 600);
        
        wp_mail($email, 'LinkedIn Doğrulama Kodu', "LinkedIn doğrulama kodunuz: $code");
        
        return $this->show_verification_form($email, 'email');
    }
    
    /**
     * İş email'i gönder
     */
    private function send_work_email($request) {
        if (!isset($request['linkedin_nonce']) || !wp_verify_nonce($request['linkedin_nonce'], 'linkedin_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $work_email = sanitize_email($request['work_email']);
        
        // İş email'i formatı kontrolü (genelde @şirket.com)
        if (!$this->is_business_email($work_email)) {
            return $this->show_error('Geçerli bir iş email adresi giriniz.');
        }
        
        $code = wp_rand(100000, 999999);
        
        set_transient('linkedin_sos_work_' . md5($work_email), [
            'code' => $code,
            'email' => $work_email,
            'attempts' => 0
        ], 600);
        
        wp_mail($work_email, 'LinkedIn İş Email Doğrulama', 
                "LinkedIn iş email doğrulama kodunuz: $code\n\n" .
                "Bu kodu kullanarak hesabınızı kurtarabilirsiniz.");
        
        return $this->show_verification_form($work_email, 'work');
    }
    
    /**
     * İş email'i kontrolü
     */
    private function is_business_email($email) {
        $domain = substr(strrchr($email, "@"), 1);
        
        // Genel email sağlayıcıları değilse iş email'i say
        $public_domains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 
                           'aol.com', 'icloud.com', 'mail.com', 'protonmail.com',
                           'yandex.com', 'qq.com', '163.com', '126.com'];
        
        return !in_array($domain, $public_domains);
    }
    
    /**
     * Profil URL ile bul
     */
    private function find_by_profile_url($request) {
        if (!isset($request['linkedin_nonce']) || !wp_verify_nonce($request['linkedin_nonce'], 'linkedin_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $profile_url = esc_url_raw($request['profile_url']);
        
        // LinkedIn URL'inden kullanıcı adını çıkar
        preg_match('/linkedin\.com\/in\/([^\/]+)/', $profile_url, $matches);
        
        if (empty($matches[1])) {
            return $this->show_error('Geçerli bir LinkedIn profil URL\'i giriniz.');
        }
        
        $username = $matches[1];
        
        // Kullanıcı adına göre ara
        $user_query = new WP_User_Query([
            'meta_key' => 'linkedin_profile_url',
            'meta_value' => $profile_url,
            'number' => 1
        ]);
        
        $users = $user_query->get_results();
        
        if (empty($users)) {
            // Meta'da linkedin_username olarak da ara
            $user_query = new WP_User_Query([
                'meta_key' => 'linkedin_username',
                'meta_value' => $username,
                'number' => 1
            ]);
            
            $users = $user_query->get_results();
        }
        
        if (empty($users)) {
            return $this->show_error('Bu LinkedIn profiliyle kayıtlı hesap bulunamadı.');
        }
        
        $user = $users[0];
        
        ob_start();
        ?>
        <div class="found-account">
            <h3>✅ Profil Bulundu!</h3>
            <p>Hesap: <strong><?php echo esc_html($user->display_name); ?></strong></p>
            
            <?php if ($user->user_email && strpos($user->user_email, '@social.local') === false): ?>
            <p>Email: <?php echo $this->mask_email($user->user_email); ?></p>
            <button onclick="sendCodeToEmail('<?php echo esc_js($user->user_email); ?>')" class="sos-submit-btn">
                Bu email'e kod gönder
            </button>
            <?php endif; ?>
            
            <?php 
            $phone = get_user_meta($user->ID, 'phone', true);
            if ($phone): 
            ?>
            <p>Telefon: <?php echo $this->mask_phone($phone); ?></p>
            <button onclick="sendCodeToPhone('<?php echo esc_js($phone); ?>')" class="sos-submit-btn">
                Bu telefona kod gönder
            </button>
            <?php endif; ?>
        </div>
        <style>
            .found-account {
                background: white;
                padding: 20px;
                border-radius: 10px;
                text-align: center;
            }
            .found-account button {
                margin: 10px 0;
            }
        </style>
        <script>
        function sendCodeToEmail(email) {
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'linkedin_sos_send_email_code',
                    email: email,
                    nonce: '<?php echo wp_create_nonce('linkedin_sos_ajax'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Hata: ' + response.message);
                    }
                }
            });
        }
        
        function sendCodeToPhone(phone) {
            jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'linkedin_sos_send_code',
                    phone: phone,
                    method: 'sms',
                    nonce: '<?php echo wp_create_nonce('linkedin_sos_ajax'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Hata: ' + response.message);
                    }
                }
            });
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
        
        $data = get_transient('linkedin_sos_' . md5($phone));
        
        if (!$data) {
            wp_send_json_error(['message' => 'Kod süresi dolmuş veya geçersiz.']);
            return;
        }
        
        if ($data['code'] != $code) {
            $data['attempts']++;
            set_transient('linkedin_sos_' . md5($phone), $data, 600);
            
            if ($data['attempts'] >= 3) {
                delete_transient('linkedin_sos_' . md5($phone));
                wp_send_json_error(['message' => 'Çok fazla hatalı deneme.']);
            } else {
                wp_send_json_error(['message' => 'Geçersiz kod. Kalan hak: ' . (3 - $data['attempts'])]);
            }
            return;
        }
        
        // Profil oluştur
        $profile = [
            'id' => md5($phone . time()),
            'phone' => $phone,
            'username' => 'linkedin_' . md5($phone),
            'name' => 'LinkedIn Kullanıcı',
            'provider' => $this->slug,
            'provider_name' => $this->get_name(),
            'verified' => true,
            'login_method' => 'linkedin_sos'
        ];
        
        $user_id = $this->find_or_create_user($profile);
        
        if ($user_id) {
            delete_transient('linkedin_sos_' . md5($phone));
            
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
     * SMS gönder
     */
    private function send_sms($phone, $message) {
        error_log("LinkedIn SMS: $phone - $message");
        return true;
    }
    
    /**
     * Email maskele
     */
    private function mask_email($email) {
        $parts = explode('@', $email);
        $name = substr($parts[0], 0, 2) . '***' . substr($parts[0], -2);
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
     * Doğrulama formu
     */
    private function show_verification_form($recipient, $type) {
        ob_start();
        ?>
        <div class="verification-form">
            <h3>✅ Doğrulama Kodu Gönderildi</h3>
            <p><?php 
                if ($type === 'phone') echo 'Telefonunuza';
                elseif ($type === 'work') echo 'İş email adresinize';
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
                <button onclick="verifyLinkedInCode('<?php echo esc_js($recipient); ?>')" class="verify-btn">
                    Doğrula
                </button>
            </div>
            
            <p class="resend-link">
                <a href="#" onclick="resendLinkedInCode('<?php echo esc_js($recipient); ?>')">Kodu tekrar gönder</a>
            </p>
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
                border: 2px solid #0077B5;
                border-radius: 8px;
            }
            .verify-btn {
                padding: 15px 25px;
                background: #0077B5;
                color: white;
                border: none;
                border-radius: 8px;
                font-weight: bold;
                cursor: pointer;
            }
            .verify-btn:hover {
                background: #005582;
            }
            .resend-link {
                margin-top: 15px;
            }
            .resend-link a {
                color: #0077B5;
                text-decoration: none;
            }
        </style>
        
        <script>
        function resendLinkedInCode(recipient) {
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
        if (!empty($profile['phone'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'linkedin_phone',
                'meta_value' => $profile['phone'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        $username = $profile['username'] ?? 'linkedin_' . uniqid();
        $email = $profile['phone'] . '@linkedin.sos';
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (!is_wp_error($user_id)) {
            if (!empty($profile['phone'])) {
                update_user_meta($user_id, 'linkedin_phone', $profile['phone']);
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
            <h3>❌ LinkedIn Kurtarma Hatası</h3>
            <p><?php echo esc_html($message); ?></p>
            <p><a href="javascript:history.back()" class="back-btn">◀ Geri dön</a></p>
        </div>
        <style>
            .ai-community-error {
                max-width: 500px;
                margin: 50px auto;
                padding: 30px;
                background: #fff3f3;
                border: 2px solid #0077B5;
                border-radius: 15px;
                text-align: center;
                color: #0077B5;
            }
            .back-btn {
                display: inline-block;
                margin-top: 20px;
                padding: 10px 20px;
                background: #0077B5;
                color: white;
                text-decoration: none;
                border-radius: 8px;
            }
            .back-btn:hover {
                background: #005582;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}