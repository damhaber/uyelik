<?php
/**
 * AI Community Provider: SMS SOS
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/../sos-base.php';
}

class AI_Community_Provider_Sms_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'sms-sos';
    
    // SMS Servis sağlayıcıları
    private $sms_provider = 'twilio'; // twilio, nexmo, clickatell, infobip, custom
    private $api_key = '';
    private $api_secret = '';
    private $from_number = '';
    
    // Desteklenen SMS servisleri
    private $supported_providers = [
        'twilio' => 'Twilio',
        'nexmo' => 'Vonage (Nexmo)',
        'clickatell' => 'Clickatell',
        'infobip' => 'Infobip',
        'custom' => 'Özel SMTP/HTTP'
    ];
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config = [], $core = null) {
        $this->id = $id;
        $this->config = $config;
        $this->core = $core;
        
        // SMS servis konfigürasyonu
        if (isset($config['sms_provider'])) {
            $this->sms_provider = $config['sms_provider'];
        }
        if (isset($config['sms_api_key'])) {
            $this->api_key = $config['sms_api_key'];
        }
        if (isset($config['sms_api_secret'])) {
            $this->api_secret = $config['sms_api_secret'];
        }
        if (isset($config['sms_from_number'])) {
            $this->from_number = $config['sms_from_number'];
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
        if (isset($request['phone']) || isset($request['code'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth handler - Telefon numarası formu
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <div class="ai-community-sos-container sms-sos">
            <h2>SMS ile Giriş</h2>
            <p class="description">Telefon numaranızı girin, size SMS ile doğrulama kodu gönderelim.</p>
            
            <!-- SMS Giriş Formu -->
            <form method="post" action="" class="sms-sos-form" id="sms-login-form">
                <?php wp_nonce_field('sms_sos_auth', 'sms_nonce'); ?>
                
                <div class="form-group">
                    <label for="phone">Telefon Numarası:</label>
                    <div class="phone-input-group">
                        <select name="country_code" id="country_code" class="country-code-select">
                            <option value="+90" data-pattern="(5__) ___ __ __">Türkiye (+90)</option>
                            <option value="+1" data-pattern="(___) ___ - ____">USA (+1)</option>
                            <option value="+44" data-pattern="____ ______">UK (+44)</option>
                            <option value="+49" data-pattern="(____) ______">Germany (+49)</option>
                            <option value="+33" data-pattern="__ __ __ __ __">France (+33)</option>
                            <option value="+34" data-pattern="___ ___ ___">Spain (+34)</option>
                            <option value="+39" data-pattern="___ ______">Italy (+39)</option>
                            <option value="+7" data-pattern="(___) ___ __ __">Russia (+7)</option>
                            <option value="+86" data-pattern="___ ____ ____">China (+86)</option>
                            <option value="+81" data-pattern="__ ____ ____">Japan (+81)</option>
                            <option value="+82" data-pattern="___ ____ ____">Korea (+82)</option>
                            <option value="+55" data-pattern="(__) _____ ____">Brazil (+55)</option>
                            <option value="+52" data-pattern="___ ___ ____">Mexico (+52)</option>
                            <option value="+61" data-pattern="___ ___ ___">Australia (+61)</option>
                            <option value="+91" data-pattern="_____ _____">India (+91)</option>
                        </select>
                        <input type="tel" 
                               id="phone" 
                               name="phone" 
                               placeholder="555 123 4567" 
                               required
                               class="sms-phone-input"
                               autocomplete="off">
                    </div>
                    <small class="phone-hint">Ülke kodunu seçin ve telefon numaranızı girin</small>
                </div>
                
                <div class="form-group options-group">
                    <label>Doğrulama Seçenekleri:</label>
                    <div class="radio-group">
                        <label class="radio-label">
                            <input type="radio" name="verification_type" value="standard" checked>
                            <span>Standart SMS (6 haneli kod)</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="verification_type" value="voice">
                            <span>Sesli arama (kod sesli okunur)</span>
                        </label>
                        <label class="radio-label">
                            <input type="radio" name="verification_type" value="flash">
                            <span>Flash SMS (ekranda görünür)</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group captcha-group">
                    <label for="captcha">Güvenlik Kodu:</label>
                    <div class="captcha-container">
                        <img src="<?php echo $this->generate_captcha(); ?>" alt="CAPTCHA" class="captcha-image">
                        <input type="text" 
                               id="captcha" 
                               name="captcha" 
                               placeholder="Yukarıdaki kodu girin" 
                               required
                               class="captcha-input">
                        <button type="button" class="refresh-captcha" onclick="this.blur();">🔄</button>
                    </div>
                </div>
                
                <button type="submit" name="sms_send_code" class="sms-submit-btn" id="send-code-btn">
                    <span class="btn-text">SMS Gönder</span>
                    <span class="btn-loader" style="display:none;">⏳</span>
                </button>
            </form>
            
            <!-- Hızlı Giriş Seçenekleri -->
            <div class="quick-login-options">
                <h3>⚡ Hızlı Giriş</h3>
                <div class="quick-login-grid">
                    <div class="quick-login-item" data-phone="+905551234567">
                        <span class="quick-label">Son kullanılan</span>
                        <span class="quick-phone">+90 555 123 4567</span>
                    </div>
                    <div class="quick-login-item" data-phone="+905557654321">
                        <span class="quick-label">Kayıtlı</span>
                        <span class="quick-phone">+90 555 765 4321</span>
                    </div>
                </div>
            </div>
            
            <!-- SMS Bilgilendirme -->
            <div class="sms-info">
                <div class="info-row">
                    <span class="info-icon">📱</span>
                    <span class="info-text">SMS ücreti yoktur</span>
                </div>
                <div class="info-row">
                    <span class="info-icon">⏱️</span>
                    <span class="info-text">Kod 10 dakika geçerlidir</span>
                </div>
                <div class="info-row">
                    <span class="info-icon">🔐</span>
                    <span class="info-text">Kodunuzu kimseyle paylaşmayın</span>
                </div>
            </div>
            
            <!-- Dil Seçeneği -->
            <div class="language-selector">
                <select name="sms_language" id="sms-language">
                    <option value="tr">🇹🇷 Türkçe</option>
                    <option value="en">🇬🇧 English</option>
                    <option value="ar">🇸🇦 العربية</option>
                    <option value="ru">🇷🇺 Русский</option>
                    <option value="zh">🇨🇳 中文</option>
                    <option value="de">🇩🇪 Deutsch</option>
                    <option value="fr">🇫🇷 Français</option>
                    <option value="es">🇪🇸 Español</option>
                </select>
            </div>
        </div>
        
        <style>
            .sms-sos {
                max-width: 500px;
                margin: 0 auto;
                padding: 25px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                color: white;
            }
            .sms-sos h2 {
                color: white;
                text-align: center;
                margin-bottom: 10px;
                font-size: 28px;
                text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            .sms-sos .description {
                text-align: center;
                margin-bottom: 25px;
                opacity: 0.9;
                font-size: 14px;
            }
            .sms-sos-form {
                background: rgba(255,255,255,0.1);
                backdrop-filter: blur(10px);
                padding: 20px;
                border-radius: 10px;
                margin-bottom: 20px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                font-size: 14px;
                opacity: 0.9;
            }
            .phone-input-group {
                display: flex;
                gap: 10px;
            }
            .country-code-select {
                width: 120px;
                padding: 12px;
                border: 2px solid rgba(255,255,255,0.2);
                border-radius: 8px;
                background: rgba(255,255,255,0.15);
                color: white;
                font-size: 14px;
                cursor: pointer;
            }
            .country-code-select option {
                background: #667eea;
                color: white;
            }
            .sms-phone-input {
                flex: 1;
                padding: 12px;
                border: 2px solid rgba(255,255,255,0.2);
                border-radius: 8px;
                background: rgba(255,255,255,0.15);
                color: white;
                font-size: 16px;
                transition: all 0.3s;
            }
            .sms-phone-input:focus, .country-code-select:focus {
                border-color: white;
                outline: none;
                background: rgba(255,255,255,0.25);
            }
            .sms-phone-input::placeholder {
                color: rgba(255,255,255,0.6);
            }
            .phone-hint {
                display: block;
                margin-top: 5px;
                font-size: 11px;
                opacity: 0.7;
            }
            .options-group {
                background: rgba(255,255,255,0.05);
                padding: 15px;
                border-radius: 8px;
            }
            .radio-group {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-top: 10px;
            }
            .radio-label {
                display: flex;
                align-items: center;
                gap: 10px;
                cursor: pointer;
                padding: 8px;
                border-radius: 5px;
                transition: background 0.3s;
            }
            .radio-label:hover {
                background: rgba(255,255,255,0.1);
            }
            .radio-label input[type="radio"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
            }
            .captcha-container {
                display: flex;
                gap: 10px;
                align-items: center;
            }
            .captcha-image {
                width: 100px;
                height: 40px;
                border-radius: 5px;
                background: #fff;
            }
            .captcha-input {
                flex: 1;
                padding: 10px;
                border: 2px solid rgba(255,255,255,0.2);
                border-radius: 5px;
                background: rgba(255,255,255,0.15);
                color: white;
            }
            .refresh-captcha {
                width: 40px;
                height: 40px;
                border: none;
                border-radius: 5px;
                background: rgba(255,255,255,0.2);
                color: white;
                cursor: pointer;
                font-size: 20px;
                transition: transform 0.3s;
            }
            .refresh-captcha:hover {
                transform: rotate(180deg);
            }
            .sms-submit-btn {
                width: 100%;
                padding: 14px;
                background: white;
                color: #667eea;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s;
                position: relative;
            }
            .sms-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            }
            .quick-login-options {
                margin-top: 20px;
            }
            .quick-login-options h3 {
                font-size: 14px;
                margin-bottom: 10px;
                opacity: 0.9;
            }
            .quick-login-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            .quick-login-item {
                background: rgba(255,255,255,0.1);
                padding: 10px;
                border-radius: 5px;
                cursor: pointer;
                transition: background 0.3s;
                display: flex;
                flex-direction: column;
            }
            .quick-login-item:hover {
                background: rgba(255,255,255,0.2);
            }
            .quick-label {
                font-size: 10px;
                opacity: 0.7;
            }
            .quick-phone {
                font-size: 12px;
                font-weight: bold;
            }
            .sms-info {
                margin-top: 20px;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
            }
            .info-row {
                background: rgba(255,255,255,0.1);
                padding: 10px;
                border-radius: 5px;
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 12px;
            }
            .language-selector {
                margin-top: 15px;
                text-align: right;
            }
            .language-selector select {
                padding: 5px 10px;
                border: 1px solid rgba(255,255,255,0.2);
                border-radius: 5px;
                background: rgba(255,255,255,0.1);
                color: white;
                cursor: pointer;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Telefon numarası formatlama
            $('#country_code').change(function() {
                var pattern = $(this).find(':selected').data('pattern');
                // Format helper gösterebiliriz
            });
            
            // Hızlı giriş tıklama
            $('.quick-login-item').click(function() {
                var phone = $(this).data('phone');
                $('#phone').val(phone);
            });
            
            // Form submit
            $('#sms-login-form').submit(function(e) {
                var phone = $('#phone').val().replace(/\D/g, '');
                if (phone.length < 10) {
                    e.preventDefault();
                    alert('Lütfen geçerli bir telefon numarası girin');
                    return false;
                }
                
                // Buton durumunu güncelle
                $('#send-code-btn').prop('disabled', true);
                $('.btn-text').hide();
                $('.btn-loader').show();
            });
            
            // CAPTCHA yenileme
            $('.refresh-captcha').click(function() {
                $('.captcha-image').attr('src', '<?php echo $this->generate_captcha(true); ?>');
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     */
    public function handle_callback($request) {
        try {
            // Kod gönderme işlemi
            if (isset($request['sms_send_code']) && isset($request['phone'])) {
                return $this->send_verification_code($request);
            }
            
            // Kod doğrulama işlemi
            if (isset($request['sms_code']) && isset($request['phone'])) {
                return $this->verify_code_and_login($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('SMS SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Doğrulama kodu gönder
     */
    public function send_verification_code($request) {
        // Nonce kontrolü
        if (!isset($request['sms_nonce']) || !wp_verify_nonce($request['sms_nonce'], 'sms_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        // CAPTCHA kontrolü
        if (!$this->verify_captcha($request['captcha'])) {
            return $this->show_error('Güvenlik kodu hatalı.');
        }
        
        $country_code = $request['country_code'];
        $phone_number = preg_replace('/[^0-9]/', '', $request['phone']);
        $full_phone = $country_code . $phone_number;
        
        // Telefon numarası validasyonu
        if (!$this->validate_phone($full_phone)) {
            return $this->show_error('Geçersiz telefon numarası formatı.');
        }
        
        // Rate limiting kontrolü
        if (!$this->check_rate_limit($full_phone)) {
            return $this->show_error('Çok fazla deneme yaptınız. Lütfen 5 dakika bekleyin.');
        }
        
        // Doğrulama tipi
        $verification_type = isset($request['verification_type']) ? $request['verification_type'] : 'standard';
        
        // 6 haneli random kod oluştur
        $verification_code = $this->generate_verification_code();
        
        // Kodu session'a kaydet (10 dakika geçerli)
        $this->save_verification_code($full_phone, $verification_code, 600);
        
        // SMS gönder
        $sent = false;
        
        switch ($verification_type) {
            case 'standard':
                $sent = $this->send_sms($full_phone, $verification_code);
                break;
            case 'voice':
                $sent = $this->send_voice_call($full_phone, $verification_code);
                break;
            case 'flash':
                $sent = $this->send_flash_sms($full_phone, $verification_code);
                break;
        }
        
        if ($sent) {
            // Başarılı gönderim logu
            $this->log_sms_attempt($full_phone, true);
            
            return $this->show_verification_form($full_phone);
        } else {
            // Başarısız gönderim logu
            $this->log_sms_attempt($full_phone, false);
            
            return $this->show_error('SMS gönderilemedi. Lütfen daha sonra tekrar deneyin.');
        }
    }
    
    /**
     * SMS gönder (çoklu servis desteği)
     */
    public function send_sms($phone, $code) {
        // Dil seçeneğine göre mesaj içeriği
        $language = isset($_POST['sms_language']) ? $_POST['sms_language'] : 'tr';
        $message = $this->get_sms_message($code, $language);
        
        // Servis sağlayıcıya göre SMS gönder
        switch ($this->sms_provider) {
            case 'twilio':
                return $this->send_via_twilio($phone, $message);
            case 'nexmo':
                return $this->send_via_nexmo($phone, $message);
            case 'clickatell':
                return $this->send_via_clickatell($phone, $message);
            case 'infobip':
                return $this->send_via_infobip($phone, $message);
            case 'custom':
                return $this->send_via_custom($phone, $message);
            default:
                return $this->send_via_wp_mail($phone, $message); // Fallback
        }
    }
    
    /**
     * Twilio ile SMS gönder
     */
    private function send_via_twilio($phone, $message) {
        $account_sid = $this->api_key;
        $auth_token = $this->api_secret;
        $from = $this->from_number;
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$account_sid}:{$auth_token}"),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query([
                'From' => $from,
                'To' => $phone,
                'Body' => $message,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('Twilio SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['sid']);
    }
    
    /**
     * Nexmo/Vonage ile SMS gönder
     */
    private function send_via_nexmo($phone, $message) {
        $url = "https://rest.nexmo.com/sms/json";
        
        $response = wp_remote_post($url, [
            'body' => [
                'api_key' => $this->api_key,
                'api_secret' => $this->api_secret,
                'from' => $this->from_number,
                'to' => $phone,
                'text' => $message,
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('Nexmo SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['messages'][0]['status']) && $body['messages'][0]['status'] == '0';
    }
    
    /**
     * Clickatell ile SMS gönder
     */
    private function send_via_clickatell($phone, $message) {
        $url = "https://platform.clickatell.com/messages";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'to' => [$phone],
                'content' => $message,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('Clickatell SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['messages'][0]['accepted']) && $body['messages'][0]['accepted'];
    }
    
    /**
     * Infobip ile SMS gönder
     */
    private function send_via_infobip($phone, $message) {
        $url = "https://api.infobip.com/sms/1/text/single";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'App ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'from' => $this->from_number,
                'to' => $phone,
                'text' => $message,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('Infobip SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['messages'][0]['status']['id']) && $body['messages'][0]['status']['id'] == 0;
    }
    
    /**
     * Özel SMTP/HTTP ile SMS gönder
     */
    private function send_via_custom($phone, $message) {
        // Custom SMS provider entegrasyonu
        $custom_url = $this->config['custom_sms_url'] ?? '';
        $custom_params = $this->config['custom_sms_params'] ?? [];
        
        if (empty($custom_url)) {
            return false;
        }
        
        $params = [];
        foreach ($custom_params as $key => $value) {
            $params[$key] = str_replace(
                ['{phone}', '{message}', '{code}'],
                [$phone, $message, $this->extract_code($message)],
                $value
            );
        }
        
        $response = wp_remote_post($custom_url, [
            'body' => $params,
            'timeout' => 30,
        ]);
        
        return !is_wp_error($response);
    }
    
    /**
     * WordPress mail ile SMS (fallback)
     */
    private function send_via_wp_mail($phone, $message) {
        // Bu gerçek SMS göndermez, email olarak gönderir (test için)
        $to = $this->config['test_email'] ?? get_option('admin_email');
        $subject = 'SMS Test: ' . $phone;
        
        return wp_mail($to, $subject, $message);
    }
    
    /**
     * Sesli arama ile kod gönder
     */
    public function send_voice_call($phone, $code) {
        // Twilio Voice API veya benzeri
        if ($this->sms_provider == 'twilio') {
            $account_sid = $this->api_key;
            $auth_token = $this->api_secret;
            
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Calls.json";
            
            $message = "Doğrulama kodunuz: " . implode(' ', str_split($code)) . ". Tekrar ediyorum: " . implode(' ', str_split($code));
            
            $response = wp_remote_post($url, [
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode("{$account_sid}:{$auth_token}"),
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'From' => $this->from_number,
                    'To' => $phone,
                    'Url' => $this->get_twiml_url($message),
                ]),
                'timeout' => 30,
            ]);
            
            return !is_wp_error($response);
        }
        
        return false;
    }
    
    /**
     * Flash SMS gönder
     */
    public function send_flash_sms($phone, $code) {
        // Flash SMS (ekranda görünen, kaydedilmeyen SMS)
        // Bazı servisler destekler
        return $this->send_sms($phone, $code); // Standart SMS'e düş
    }
    
    /**
     * SMS mesaj içeriğini hazırla
     */
    private function get_sms_message($code, $language = 'tr') {
        $messages = [
            'tr' => "AI Community doğrulama kodunuz: {$code}\nBu kodu kimseyle paylaşmayın. 10 dakika geçerlidir.",
            'en' => "Your AI Community verification code is: {$code}\nDo not share this code with anyone. Valid for 10 minutes.",
            'ar' => "رمز التحقق الخاص بك في AI Community: {$code}\nلا تشارك هذا الرمز مع أي شخص. صالح لمدة 10 دقائق.",
            'ru' => "Ваш код подтверждения AI Community: {$code}\nНе сообщайте этот код никому. Действителен в течение 10 минут.",
            'zh' => "您的AI Community验证码是：{$code}\n请勿与任何人分享此代码。有效期为10分钟。",
            'de' => "Ihr AI Community Bestätigungscode lautet: {$code}\nTeilen Sie diesen Code mit niemandem. 10 Minuten gültig.",
            'fr' => "Votre code de vérification AI Community est : {$code}\nNe partagez ce code avec personne. Valable 10 minutes.",
            'es' => "Su código de verificación de AI Community es: {$code}\nNo comparta este código con nadie. Válido por 10 minutos.",
        ];
        
        return isset($messages[$language]) ? $messages[$language] : $messages['en'];
    }
    
    /**
     * Koddan sayıları çıkar
     */
    private function extract_code($message) {
        preg_match('/\b(\d{6})\b/', $message, $matches);
        return isset($matches[1]) ? $matches[1] : '';
    }
    
    /**
     * TwiML URL oluştur
     */
    private function get_twiml_url($message) {
        // Twilio için TwiML oluştur
        return add_query_arg([
            'action' => 'twiml',
            'message' => urlencode($message),
        ], home_url('/'));
    }
    
    /**
     * Doğrulama formunu göster
     */
    private function show_verification_form($phone) {
        $masked_phone = $this->mask_phone_number($phone);
        
        ob_start();
        ?>
        <div class="ai-community-sos-container sms-sos">
            <h2>SMS Doğrulama</h2>
            
            <div class="verification-card">
                <div class="phone-display">
                    <span class="phone-icon">📱</span>
                    <span class="phone-number"><?php echo esc_html($masked_phone); ?></span>
                </div>
                
                <p class="verification-message">
                    <strong><?php echo esc_html($masked_phone); ?></strong> numarasına 6 haneli doğrulama kodu gönderildi.
                </p>
                
                <form method="post" action="" class="verification-form" id="verification-form">
                    <?php wp_nonce_field('sms_sos_verify', 'verify_nonce'); ?>
                    
                    <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                    
                    <div class="code-input-container">
                        <label for="sms_code">Doğrulama Kodu:</label>
                        <div class="code-digit-group">
                            <input type="text" 
                                   id="code-1" 
                                   maxlength="1" 
                                   pattern="[0-9]"
                                   class="code-digit"
                                   inputmode="numeric"
                                   autofocus>
                            <input type="text" 
                                   id="code-2" 
                                   maxlength="1" 
                                   pattern="[0-9]"
                                   class="code-digit"
                                   inputmode="numeric">
                            <input type="text" 
                                   id="code-3" 
                                   maxlength="1" 
                                   pattern="[0-9]"
                                   class="code-digit"
                                   inputmode="numeric">
                            <input type="text" 
                                   id="code-4" 
                                   maxlength="1" 
                                   pattern="[0-9]"
                                   class="code-digit"
                                   inputmode="numeric">
                            <input type="text" 
                                   id="code-5" 
                                   maxlength="1" 
                                   pattern="[0-9]"
                                   class="code-digit"
                                   inputmode="numeric">
                            <input type="text" 
                                   id="code-6" 
                                   maxlength="1" 
                                   pattern="[0-9]"
                                   class="code-digit"
                                   inputmode="numeric">
                        </div>
                        <input type="hidden" name="sms_code" id="sms_code">
                    </div>
                    
                    <div class="timer-container">
                        <div class="timer" id="timer">10:00</div>
                        <div class="timer-text">Kalan süre</div>
                    </div>
                    
                    <button type="submit" name="sms_verify" class="verify-btn" id="verify-btn">
                        Doğrula ve Giriş Yap
                    </button>
                </form>
                
                <div class="verification-footer">
                    <p>Kod gelmedi mi?</p>
                    <div class="action-buttons">
                        <form method="post" action="" class="resend-form">
                            <?php wp_nonce_field('sms_sos_auth', 'sms_nonce'); ?>
                            <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                            <input type="hidden" name="country_code" value="<?php echo esc_attr($this->get_country_code($phone)); ?>">
                            <button type="submit" name="sms_send_code" class="resend-btn" id="resend-btn" disabled>
                                <span class="resend-text">Yeniden Gönder</span>
                                <span class="resend-timer" id="resend-timer">(60)</span>
                            </button>
                        </form>
                        <a href="<?php echo esc_url($this->get_change_phone_url()); ?>" class="change-phone-link">
                            Farklı numara kullan
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
            .verification-card {
                background: rgba(255,255,255,0.1);
                backdrop-filter: blur(10px);
                padding: 30px 20px;
                border-radius: 15px;
                text-align: center;
            }
            .phone-display {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                margin-bottom: 20px;
                font-size: 18px;
                font-weight: bold;
            }
            .phone-icon {
                font-size: 24px;
            }
            .verification-message {
                margin-bottom: 25px;
                font-size: 14px;
                opacity: 0.9;
            }
            .code-input-container {
                margin-bottom: 25px;
            }
            .code-input-container label {
                display: block;
                margin-bottom: 10px;
                font-size: 14px;
            }
            .code-digit-group {
                display: flex;
                gap: 8px;
                justify-content: center;
                margin-bottom: 20px;
            }
            .code-digit {
                width: 45px;
                height: 55px;
                text-align: center;
                font-size: 24px;
                font-weight: bold;
                border: 2px solid rgba(255,255,255,0.3);
                border-radius: 8px;
                background: rgba(255,255,255,0.15);
                color: white;
            }
            .code-digit:focus {
                border-color: white;
                outline: none;
                background: rgba(255,255,255,0.25);
            }
            .timer-container {
                margin-bottom: 25px;
            }
            .timer {
                font-size: 36px;
                font-weight: bold;
                font-family: monospace;
                color: #fff;
                text-shadow: 0 0 10px rgba(255,255,255,0.5);
            }
            .timer-text {
                font-size: 12px;
                opacity: 0.7;
            }
            .verify-btn {
                width: 100%;
                padding: 14px;
                background: white;
                color: #667eea;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: all 0.3s;
                margin-bottom: 20px;
            }
            .verify-btn:hover:not(:disabled) {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            }
            .verify-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .verification-footer {
                border-top: 1px solid rgba(255,255,255,0.2);
                padding-top: 20px;
            }
            .action-buttons {
                display: flex;
                justify-content: center;
                gap: 15px;
                margin-top: 10px;
            }
            .resend-btn {
                background: none;
                border: 1px solid rgba(255,255,255,0.3);
                padding: 8px 15px;
                border-radius: 5px;
                color: white;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 5px;
            }
            .resend-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .resend-timer {
                font-family: monospace;
            }
            .change-phone-link {
                color: white;
                text-decoration: none;
                padding: 8px 15px;
                border: 1px solid rgba(255,255,255,0.3);
                border-radius: 5px;
                font-size: 14px;
            }
            .change-phone-link:hover {
                background: rgba(255,255,255,0.1);
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // 6 haneli kod input yönetimi
            $('.code-digit').on('input', function() {
                var index = $(this).index();
                if ($(this).val().length === 1) {
                    if (index < 5) {
                        $('.code-digit').eq(index + 1).focus();
                    }
                }
                updateCodeField();
            }).on('keydown', function(e) {
                var index = $(this).index();
                if (e.key === 'Backspace' && !$(this).val() && index > 0) {
                    $('.code-digit').eq(index - 1).focus();
                }
            }).on('paste', function(e) {
                e.preventDefault();
                var paste = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
                if (/^\d{6}$/.test(paste)) {
                    for (var i = 0; i < 6; i++) {
                        $('.code-digit').eq(i).val(paste.charAt(i));
                    }
                    updateCodeField();
                    $('.code-digit').eq(5).focus();
                }
            });
            
            function updateCodeField() {
                var code = '';
                $('.code-digit').each(function() {
                    code += $(this).val();
                });
                $('#sms_code').val(code);
                
                if (code.length === 6) {
                    $('#verify-btn').prop('disabled', false);
                } else {
                    $('#verify-btn').prop('disabled', true);
                }
            }
            
            // Timer
            var timeLeft = 600; // 10 dakika
            var timer = setInterval(function() {
                timeLeft--;
                var minutes = Math.floor(timeLeft / 60);
                var seconds = timeLeft % 60;
                $('#timer').text(minutes.toString().padStart(2, '0') + ':' + seconds.toString().padStart(2, '0'));
                
                if (timeLeft <= 0) {
                    clearInterval(timer);
                    $('#timer').text('00:00');
                    $('#verify-btn').prop('disabled', true);
                }
            }, 1000);
            
            // Resend timer
            var resendTimeLeft = 60;
            var resendTimer = setInterval(function() {
                resendTimeLeft--;
                $('#resend-timer').text('(' + resendTimeLeft + ')');
                
                if (resendTimeLeft <= 0) {
                    clearInterval(resendTimer);
                    $('#resend-btn').prop('disabled', false);
                    $('#resend-timer').hide();
                }
            }, 1000);
        });
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Kodu doğrula ve giriş yap
     */
    public function verify_code_and_login($request) {
        // Nonce kontrolü
        if (!isset($request['verify_nonce']) || !wp_verify_nonce($request['verify_nonce'], 'sms_sos_verify')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $request['phone'];
        $code = $request['sms_code'];
        
        // Kodu doğrula
        if ($this->verify_code($phone, $code)) {
            // Profil oluştur
            $profile = $this->create_profile_from_phone($phone);
            
            // Kullanıcı bul veya oluştur
            $user = $this->find_or_create_user($profile);
            
            if (is_wp_error($user)) {
                return $this->show_error($user->get_error_message());
            }
            
            // Kullanıcıyı oturum açtır
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            
            // Başarılı giriş logu
            $this->log_successful_login($phone);
            
            // Yönlendir
            wp_redirect(home_url('/profil-merkezim/'));
            exit;
        } else {
            // Başarısız deneme logu
            $this->log_failed_attempt($phone);
            
            return $this->show_error('Geçersiz doğrulama kodu. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Kodu doğrula - PUBLIC (SOS base'den)
     */
    public function verify_code($phone, $code) {
        $saved_data = get_transient('sms_code_' . md5($phone));
        
        if ($saved_data && $saved_data['code'] == $code && $saved_data['attempts'] < 3) {
            // Başarılı doğrulama
            delete_transient('sms_code_' . md5($phone));
            return true;
        } else {
            // Başarısız deneme sayısını artır
            if ($saved_data) {
                $saved_data['attempts']++;
                set_transient('sms_code_' . md5($phone), $saved_data, 10 * MINUTE_IN_SECONDS);
            }
            return false;
        }
    }
    
    /**
     * Kodu session'a kaydet - PROTECTED (SOS base'den)
     * İmza: save_verification_code($phone, $code, $expiry = 600)
     */
    protected function save_verification_code($phone, $code, $expiry = 600) {
        $data = [
            'code' => $code,
            'attempts' => 0,
            'created' => time()
        ];
        
        // 10 dakika geçerli (varsayılan)
        set_transient('sms_code_' . md5($phone), $data, $expiry);
    }
    
    /**
     * Doğrulama kodu oluştur
     */
    private function generate_verification_code() {
        return wp_rand(100000, 999999);
    }
    
    /**
     * Telefon numarası validasyonu
     */
    private function validate_phone($phone) {
        // En az 10, en fazla 15 karakter
        return preg_match('/^\+[1-9][0-9]{9,14}$/', $phone);
    }
    
    /**
     * Telefon numarasını maskele
     */
    private function mask_phone_number($phone) {
        $length = strlen($phone);
        if ($length > 8) {
            $start = substr($phone, 0, 4);
            $end = substr($phone, -4);
            $masked = $start . '****' . $end;
        } else {
            $masked = substr($phone, 0, 2) . '****' . substr($phone, -2);
        }
        return $masked;
    }
    
    /**
     * Ülke kodunu çıkar
     */
    private function get_country_code($phone) {
        preg_match('/^(\+\d+)/', $phone, $matches);
        return isset($matches[1]) ? $matches[1] : '+90';
    }
    
    /**
     * Rate limiting kontrolü - PROTECTED (base class ile uyumlu)
     */
    protected function check_rate_limit($phone) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $key = 'sms_rate_' . md5($ip . '_' . $phone);
        
        $attempts = get_transient($key);
        
        if ($attempts && $attempts >= 3) {
            return false; // 5 dakikada 3'ten fazla deneme
        }
        
        if ($attempts) {
            set_transient($key, $attempts + 1, 5 * MINUTE_IN_SECONDS);
        } else {
            set_transient($key, 1, 5 * MINUTE_IN_SECONDS);
        }
        
        return true;
    }
    
    /**
     * CAPTCHA oluştur
     */
    private function generate_captcha($refresh = false) {
        if ($refresh) {
            $code = wp_rand(1000, 9999);
            set_transient('sms_captcha_' . session_id(), $code, 5 * MINUTE_IN_SECONDS);
        } else {
            $code = get_transient('sms_captcha_' . session_id());
            if (!$code) {
                $code = wp_rand(1000, 9999);
                set_transient('sms_captcha_' . session_id(), $code, 5 * MINUTE_IN_SECONDS);
            }
        }
        
        // Basit bir CAPTCHA görseli oluştur (gerçek uygulamada GD kullanılır)
        return 'data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><rect width="100" height="40" fill="#f0f0f0"/><text x="20" y="25" font-size="20" fill="#333">' . $code . '</text></svg>');
    }
    
    /**
     * CAPTCHA doğrula
     */
    private function verify_captcha($input) {
        $code = get_transient('sms_captcha_' . session_id());
        return $code && $code == $input;
    }
    
    /**
     * SMS denemesi logla
     */
    private function log_sms_attempt($phone, $success) {
        $log = get_option('sms_sos_log', []);
        $log[] = [
            'phone' => $this->mask_phone_number($phone),
            'success' => $success,
            'time' => current_time('mysql'),
            'ip' => $_SERVER['REMOTE_ADDR']
        ];
        
        // Son 100 log'u tut
        if (count($log) > 100) {
            array_shift($log);
        }
        
        update_option('sms_sos_log', $log);
    }
    
    /**
     * Başarılı giriş logla
     */
    private function log_successful_login($phone) {
        // İsteğe bağlı: Veritabanına kaydet
    }
    
    /**
     * Başarısız deneme logla
     */
    private function log_failed_attempt($phone) {
        // İsteğe bağlı: Güvenlik için logla
    }
    
    /**
     * Telefon numarasından profil oluştur
     */
    protected function create_profile_from_phone($phone) {
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => md5($phone . time()),
            'phone' => $phone,
            'phone_verified' => true,
            'username' => 'sms_' . substr(md5($phone), 0, 8),
            'display_name' => 'SMS Kullanıcı',
            'first_name' => 'SMS',
            'last_name' => 'Kullanıcı',
            'verified' => true,
            'login_method' => 'sms_sos'
        ];
        
        return $profile;
    }
    
    /**
     * Kullanıcı bul veya oluştur - PUBLIC
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
        
        // Telefon numarası ile kullanıcı ara
        if (!empty($profile['phone'])) {
            $user = $this->find_user_by_phone($profile['phone']);
            if ($user) {
                // Bağlantıyı kaydet
                $this->save_provider_user_id($user->ID, $profile['provider_slug'], $profile['user_id']);
                return $user;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $password = wp_generate_password();
        $email = $profile['provider_slug'] . '_' . $profile['user_id'] . '@sms.local';
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Kullanıcı bilgilerini güncelle
        $user_data = [
            'ID' => $user_id,
            'display_name' => $profile['display_name'],
            'first_name' => $profile['first_name'] ?? '',
            'last_name' => $profile['last_name'] ?? ''
        ];
        
        wp_update_user($user_data);
        
        // Bağlantıyı kaydet
        $this->save_provider_user_id($user_id, $profile['provider_slug'], $profile['user_id']);
        
        // Telefon numarasını kaydet
        if (!empty($profile['phone'])) {
            update_user_meta($user_id, 'ai_community_phone', $profile['phone']);
            update_user_meta($user_id, 'ai_community_phone_verified', true);
        }
        
        return get_user_by('id', $user_id);
    }
    
    /**
     * Telefon numarası ile kullanıcı bul
     */
    protected function find_user_by_phone($phone) {
        $users = get_users([
            'meta_key' => 'ai_community_phone',
            'meta_value' => $phone,
            'number' => 1
        ]);
        
        return !empty($users) ? $users[0] : null;
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
                (!empty($profile['display_name']) ? $profile['display_name'] : 'sms_user');
        
        $username = sanitize_user($base, true);
        $username = str_replace(' ', '_', strtolower($username));
        $username = preg_replace('/[^a-z0-9_]/', '', $username);
        
        if (empty($username)) {
            $username = 'sms_' . substr($profile['user_id'], 0, 8);
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
     * Telefon değiştirme URL'i
     */
    private function get_change_phone_url() {
        return add_query_arg('change_phone', '1', remove_query_arg(['code', 'sms_code']));
    }
    
    /**
     * Hata mesajı göster - PROTECTED (base class ile uyumlu)
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error">
            <div class="error-icon">❌</div>
            <h3>Hata!</h3>
            <p><?php echo esc_html($message); ?></p>
            <div class="error-actions">
                <a href="javascript:history.back()" class="error-btn">◀ Geri dön</a>
                <a href="<?php echo esc_url($this->get_change_phone_url()); ?>" class="error-btn">
                    Farklı numara dene
                </a>
            </div>
        </div>
        
        <style>
            .ai-community-error {
                max-width: 400px;
                margin: 50px auto;
                padding: 30px;
                background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
                border-radius: 15px;
                color: white;
                text-align: center;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            }
            .error-icon {
                font-size: 48px;
                margin-bottom: 20px;
            }
            .error-actions {
                display: flex;
                gap: 10px;
                justify-content: center;
                margin-top: 25px;
            }
            .error-btn {
                padding: 10px 20px;
                background: rgba(255,255,255,0.2);
                color: white;
                text-decoration: none;
                border-radius: 5px;
                border: 1px solid rgba(255,255,255,0.3);
                transition: all 0.3s;
            }
            .error-btn:hover {
                background: rgba(255,255,255,0.3);
                transform: translateY(-2px);
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Profili session'a kaydet - PROTECTED (base class ile uyumlu)
     */
    protected function save_profile_to_session($profile) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['ai_community_profile'] = $profile;
        $_SESSION['ai_community_provider'] = $this->slug;
        $_SESSION['ai_community_login_time'] = time();
    }
    
    /**
     * Getter metodlar
     */
    public function get_name() {
        return 'SMS (SOS)';
    }
    
    public function get_type() {
        return 'sos';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}