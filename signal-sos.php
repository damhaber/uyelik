<?php
/**
 * AI Community Provider: Signal SOS
 * 
 * Signal üzerinden telefon doğrulama ile giriş
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// SOS base class'ı include et - class_exists kontrolü ile
if (!class_exists('AI_Community_SOS_Provider')) {
    require_once dirname(__FILE__) . '/sos-base.php';
}

class AI_Community_Provider_Signal_SOS extends AI_Community_SOS_Provider {
    
    protected $config = [];
    protected $version = 'SOS';
    public $slug = 'signal-sos';
    protected $name = 'Signal (SOS)';
    
    // Signal özel yapılandırma
    private $api_key = '';
    private $sender_number = '';
    private $use_api = false;
    
    /**
     * Constructor - 3 parametreli
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Signal özel konfigürasyon
        if (isset($config['api_key'])) {
            $this->api_key = $config['api_key'];
            $this->use_api = true;
        }
        if (isset($config['sender_number'])) {
            $this->sender_number = $config['sender_number'];
        }
    }
    
    /**
     * Ana handler - Tüm istekleri yönet
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        // SOS login handling
        if (isset($request['code']) || isset($request['phone']) || isset($request['verify_code'])) {
            return $this->handle_callback($request);
        } else {
            return $this->handle_auth($request);
        }
    }
    
    /**
     * Auth handler - Giriş formunu göster
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <div class="ai-community-sos-container signal-sos">
            <div class="signal-header">
                <div class="signal-logo">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="#3A76F0">
                        <path d="M12 2C6.48 2 2 6.48 2 12c0 2.54.94 4.86 2.5 6.66L2.5 21.5l3.84-1.5c1.8.96 3.84 1.5 6.16 1.5 5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.82 0-3.53-.5-5-1.35l-3.3 1.3 1.3-3.3C5.5 15.53 5 13.82 5 12c0-3.86 3.14-7 7-7s7 3.14 7 7-3.14 7-7 7z"/>
                    </svg>
                </div>
                <h2>Signal ile Giriş</h2>
                <p class="signal-subtitle">Gizlilik odaklı, güvenli mesajlaşma</p>
            </div>
            
            <!-- Signal Özel Bilgi -->
            <div class="signal-info-box">
                <div class="signal-feature">
                    <span class="feature-icon">🔒</span>
                    <span>Uçtan uca şifreli</span>
                </div>
                <div class="signal-feature">
                    <span class="feature-icon">📱</span>
                    <span>Telefon numarası tabanlı</span>
                </div>
                <div class="signal-feature">
                    <span class="feature-icon">⚡</span>
                    <span>Hızlı doğrulama</span>
                </div>
            </div>
            
            <!-- SMS/Telefon ile Giriş -->
            <div class="sms-auth-section">
                <h3>📱 Telefon Numarası ile Giriş</h3>
                <p class="description">Signal hesabınıza kayıtlı telefon numaranızı girin. Size SMS ile doğrulama kodu göndereceğiz.</p>
                
                <form method="post" action="" class="sos-form">
                    <?php wp_nonce_field('signal_sos_auth', 'signal_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="phone">Telefon Numarası:</label>
                        <input type="tel" 
                               id="phone" 
                               name="phone" 
                               placeholder="+90 555 123 4567" 
                               pattern="^\+?[0-9]{10,15}$"
                               required
                               class="phone-input"
                               autocomplete="off">
                        <small class="phone-hint">Ülke koduyla birlikte girin (Türkiye: +90, ABD: +1...)</small>
                    </div>
                    
                    <?php if ($this->use_api): ?>
                    <div class="form-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="use_signal_api" value="1" checked>
                            Signal API üzerinden mesaj gönder (varsa)
                        </label>
                        <small class="hint">Signal uygulaması yüklü değilse SMS ile devam edin</small>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" name="send_code" class="submit-btn signal-btn">
                        <span>📨</span> Doğrulama Kodu Gönder
                    </button>
                </form>
            </div>
            
            <!-- Signal Alternatif Yöntem -->
            <div class="signal-alternative">
                <div class="signal-divider">
                    <span>veya</span>
                </div>
                
                <div class="signal-qr-section">
                    <h3>📱 Signal Uygulaması ile</h3>
                    <p>Signal uygulamanız varsa, doğrudan bağlantı ile giriş yapabilirsiniz.</p>
                    
                    <a href="signal://" class="signal-app-link">
                        <button class="signal-app-btn">
                            <span>📲</span> Signal Uygulamasını Aç
                        </button>
                    </a>
                    
                    <div class="signal-instructions">
                        <p><strong>Nasıl çalışır?</strong></p>
                        <ol>
                            <li>Signal uygulamasını açın</li>
                            <li>Ayarlar > Bağlı Cihazlar'a gidin</li>
                            <li>QR kod tarayıcıyı açın</li>
                            <li>Aşağıdaki QR kodu okutun</li>
                        </ol>
                        
                        <div class="qr-placeholder">
                            <div class="mock-qr">
                                <div class="qr-pattern">
                                    <div class="qr-corner tl"></div>
                                    <div class="qr-corner tr"></div>
                                    <div class="qr-corner bl"></div>
                                    <div class="qr-corner br"></div>
                                    <div class="qr-center"></div>
                                </div>
                            </div>
                            <p class="qr-note">Signal Desktop bağlantısı için QR kod</p>
                            <p class="qr-dev-note">⚡ Signal API entegrasyonu ile gerçek QR kod gösterilecek</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Bilgi notu -->
            <div class="info-box signal-privacy">
                <div class="privacy-header">
                    <span class="privacy-icon">🛡️</span>
                    <strong>Signal ile giriş yaparak:</strong>
                </div>
                <ul>
                    <li>Telefon numaranız doğrulanır</li>
                    <li>Profil bilgileriniz (varsa) alınır</li>
                    <li>Hesabınız Signal ile ilişkilendirilir</li>
                    <li>Hiçbir mesajınız okunmaz veya kaydedilmez</li>
                </ul>
                <p class="privacy-note">Signal'in uçtan uca şifrelemesi sayesinde bilgileriniz güvendedir.</p>
            </div>
        </div>
        
        <style>
            .signal-sos {
                max-width: 550px;
                margin: 0 auto;
                padding: 30px;
                background: #fff;
                border-radius: 20px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .signal-header {
                text-align: center;
                margin-bottom: 30px;
            }
            
            .signal-logo {
                background: #e8f0fe;
                width: 100px;
                height: 100px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 15px;
            }
            
            .signal-logo svg {
                width: 60px;
                height: 60px;
            }
            
            .signal-sos h2 {
                color: #1a1a1a;
                font-size: 28px;
                margin: 0 0 5px;
            }
            
            .signal-subtitle {
                color: #666;
                font-size: 14px;
                margin: 0;
            }
            
            .signal-info-box {
                display: flex;
                justify-content: space-around;
                background: #f8f9fa;
                padding: 15px;
                border-radius: 12px;
                margin-bottom: 25px;
            }
            
            .signal-feature {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 5px;
                font-size: 13px;
                color: #3A76F0;
            }
            
            .feature-icon {
                font-size: 20px;
            }
            
            .sms-auth-section {
                background: #f8f9fa;
                padding: 25px;
                border-radius: 15px;
                margin-bottom: 20px;
                border: 1px solid #e9ecef;
            }
            
            .sms-auth-section h3 {
                color: #3A76F0;
                margin: 0 0 10px;
                font-size: 18px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #2c3e50;
                font-size: 14px;
            }
            
            .checkbox-label {
                display: flex;
                align-items: center;
                gap: 8px;
                font-weight: normal;
                cursor: pointer;
            }
            
            .checkbox-label input[type="checkbox"] {
                width: auto;
                margin: 0;
            }
            
            .phone-input {
                width: 100%;
                padding: 14px;
                border: 2px solid #e9ecef;
                border-radius: 10px;
                font-size: 16px;
                transition: all 0.3s;
                background: white;
            }
            
            .phone-input:focus {
                border-color: #3A76F0;
                outline: none;
                box-shadow: 0 0 0 3px rgba(58,118,240,0.1);
            }
            
            .phone-hint, .hint {
                display: block;
                margin-top: 5px;
                color: #868e96;
                font-size: 12px;
            }
            
            .signal-btn {
                background: #3A76F0 !important;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            
            .signal-btn:hover {
                background: #2a5fc1 !important;
            }
            
            .submit-btn {
                width: 100%;
                padding: 14px;
                background: #3A76F0;
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .signal-alternative {
                margin: 30px 0;
            }
            
            .signal-divider {
                text-align: center;
                margin: 20px 0;
                position: relative;
            }
            
            .signal-divider::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 0;
                right: 0;
                height: 1px;
                background: #e9ecef;
                z-index: 1;
            }
            
            .signal-divider span {
                background: white;
                padding: 0 15px;
                color: #868e96;
                font-size: 14px;
                position: relative;
                z-index: 2;
            }
            
            .signal-qr-section {
                text-align: center;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 15px;
            }
            
            .signal-app-btn {
                background: #2c3e50;
                color: white;
                border: none;
                padding: 12px 25px;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin: 15px 0;
                transition: background 0.3s;
            }
            
            .signal-app-btn:hover {
                background: #1a252f;
            }
            
            .signal-instructions {
                text-align: left;
                margin-top: 20px;
            }
            
            .signal-instructions ol {
                padding-left: 20px;
                color: #495057;
                line-height: 1.8;
            }
            
            .qr-placeholder {
                text-align: center;
                margin-top: 20px;
            }
            
            .mock-qr {
                width: 200px;
                height: 200px;
                margin: 0 auto;
                background: white;
                border: 2px solid #e9ecef;
                border-radius: 15px;
                padding: 15px;
                position: relative;
            }
            
            .qr-pattern {
                width: 100%;
                height: 100%;
                background: linear-gradient(45deg, #000 25%, transparent 25%),
                            linear-gradient(-45deg, #000 25%, transparent 25%);
                background-size: 30px 30px;
                position: relative;
            }
            
            .qr-corner {
                position: absolute;
                width: 40px;
                height: 40px;
                border: 3px solid #3A76F0;
            }
            
            .qr-corner.tl { top: 0; left: 0; border-right: none; border-bottom: none; }
            .qr-corner.tr { top: 0; right: 0; border-left: none; border-bottom: none; }
            .qr-corner.bl { bottom: 0; left: 0; border-right: none; border-top: none; }
            .qr-corner.br { bottom: 0; right: 0; border-left: none; border-top: none; }
            
            .qr-center {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 30px;
                height: 30px;
                background: #3A76F0;
                border-radius: 5px;
            }
            
            .qr-note {
                color: #495057;
                font-size: 14px;
                margin: 10px 0 5px;
            }
            
            .qr-dev-note {
                color: #3A76F0;
                font-size: 12px;
                font-style: italic;
            }
            
            .info-box {
                background: #e8f0fe;
                border-radius: 15px;
                padding: 20px;
                margin-top: 25px;
            }
            
            .privacy-header {
                display: flex;
                align-items: center;
                gap: 8px;
                color: #3A76F0;
                font-size: 16px;
                margin-bottom: 15px;
            }
            
            .privacy-icon {
                font-size: 24px;
            }
            
            .info-box ul {
                margin: 0 0 15px;
                padding-left: 25px;
                color: #2c3e50;
                line-height: 1.8;
            }
            
            .privacy-note {
                color: #495057;
                font-size: 13px;
                font-style: italic;
                margin: 0;
                padding-top: 15px;
                border-top: 1px solid rgba(58,118,240,0.2);
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Callback handler
     */
    public function handle_callback($request) {
        try {
            // Kod gönderme işlemi
            if (isset($request['send_code']) && isset($request['phone'])) {
                return $this->send_verification_code($request);
            }
            
            // Kod doğrulama işlemi
            if (isset($request['verify_code']) && isset($request['phone']) && isset($request['code'])) {
                return $this->verify_code_and_login($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('Signal SOS hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Doğrulama kodu gönder
     */
    public function send_verification_code($request) {
        // Nonce kontrolü
        if (!isset($request['signal_nonce']) || !wp_verify_nonce($request['signal_nonce'], 'signal_sos_auth')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        // Rate limit kontrolü
        if (!$this->check_rate_limit($request['phone'])) {
            return $this->show_error('Çok fazla deneme yaptınız. Lütfen daha sonra tekrar deneyin.');
        }
        
        $phone = $this->format_phone_number($request['phone']);
        
        // 6 haneli random kod oluştur
        $verification_code = wp_rand(100000, 999999);
        
        // Kodu kaydet (10 dakika)
        $this->save_verification_code($phone, $verification_code, 600);
        
        // SMS gönder
        $sent = $this->send_sms($phone, $verification_code);
        
        if ($sent) {
            return $this->show_verification_form($phone);
        } else {
            return $this->show_error('Doğrulama kodu gönderilemedi. Lütfen telefon numaranızı kontrol edin.');
        }
    }
    
    /**
     * SMS gönder - Signal API veya normal SMS
     * 
     * @param string $phone Telefon numarası
     * @param string $code Doğrulama kodu
     * @return bool
     */
    public function send_sms($phone, $code) {
        // Signal API varsa onu kullan
        if ($this->use_api && !empty($this->api_key) && !empty($this->sender_number)) {
            return $this->send_via_signal_api($phone, $code);
        }
        
        // Mock SMS - gerçek implementasyon
        $this->core->log('signal_sms_sent', [
            'phone' => $phone,
            'code' => $code,
            'message' => 'Signal SMS gönderildi (mock)'
        ]);
        
        // Gerçek SMS servisi entegrasyonu buraya
        // Twilio, Nexmo, vb.
        
        return true;
    }
    
    /**
     * Signal API üzerinden mesaj gönder
     * 
     * @param string $phone Telefon numarası
     * @param string $code Doğrulama kodu
     * @return bool
     */
    private function send_via_signal_api($phone, $code) {
        // Signal API entegrasyonu
        // signal-cli veya Signal Messenger API
        
        $message = "Signal doğrulama kodunuz: $code\nBu kodu kimseyle paylaşmayın.";
        
        $response = wp_remote_post('https://api.signal.org/v1/messages', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'recipient' => $phone,
                'sender' => $this->sender_number,
                'message' => $message
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Signal API hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        return isset($result['success']) && $result['success'] === true;
    }
    
    /**
     * Doğrulama formunu göster
     */
    protected function show_verification_form($phone) {
        ob_start();
        ?>
        <div class="ai-community-sos-container signal-sos">
            <div class="signal-header">
                <div class="signal-logo">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="#3A76F0">
                        <path d="M12 2C6.48 2 2 6.48 2 12c0 2.54.94 4.86 2.5 6.66L2.5 21.5l3.84-1.5c1.8.96 3.84 1.5 6.16 1.5 5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.82 0-3.53-.5-5-1.35l-3.3 1.3 1.3-3.3C5.5 15.53 5 13.82 5 12c0-3.86 3.14-7 7-7s7 3.14 7 7-3.14 7-7 7z"/>
                    </svg>
                </div>
                <h2>Doğrulama Kodu</h2>
            </div>
            
            <div class="success-message">
                <div class="success-icon">✅</div>
                <div class="success-text">
                    <strong><?php echo esc_html($phone); ?></strong> numarasına<br>
                    6 haneli doğrulama kodu gönderildi.
                </div>
            </div>
            
            <form method="post" action="" class="verify-form">
                <?php wp_nonce_field('signal_sos_verify', 'verify_nonce'); ?>
                
                <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                
                <div class="form-group">
                    <label for="code">Doğrulama Kodu:</label>
                    <div class="code-input-container">
                        <input type="text" 
                               id="code" 
                               name="code" 
                               placeholder="••••••" 
                               pattern="[0-9]{6}"
                               maxlength="6"
                               required
                               class="code-input"
                               autocomplete="off"
                               inputmode="numeric">
                        <div class="code-mask"></div>
                    </div>
                </div>
                
                <button type="submit" name="verify_code" class="submit-btn signal-btn">
                    <span>🔓</span> Doğrula ve Giriş Yap
                </button>
            </form>
            
            <div class="resend-section">
                <p>Kod gelmedi mi?</p>
                <form method="post" action="" class="resend-form">
                    <?php wp_nonce_field('signal_sos_auth', 'signal_nonce'); ?>
                    <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                    <button type="submit" name="send_code" class="resend-btn">
                        <span>🔄</span> Yeniden Gönder
                    </button>
                </form>
                
                <div class="timer-section">
                    <span class="timer-icon">⏱️</span>
                    <span class="timer-text" id="countdown">10:00</span>
                </div>
            </div>
            
            <div class="signal-warning">
                <p>⚠️ Doğrulama kodunu kimseyle paylaşmayın. Signal çalışanları asla kod sormaz.</p>
            </div>
        </div>
        
        <style>
            .success-message {
                background: #d4edda;
                color: #155724;
                padding: 20px;
                border-radius: 12px;
                margin-bottom: 25px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .success-icon {
                font-size: 32px;
            }
            
            .success-text {
                font-size: 16px;
                line-height: 1.5;
            }
            
            .code-input-container {
                position: relative;
            }
            
            .code-input {
                width: 100%;
                padding: 18px;
                font-size: 32px;
                text-align: center;
                letter-spacing: 10px;
                border: 2px solid #3A76F0;
                border-radius: 12px;
                background: white;
                font-family: monospace;
            }
            
            .code-input:focus {
                outline: none;
                box-shadow: 0 0 0 3px rgba(58,118,240,0.2);
            }
            
            .code-mask {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                pointer-events: none;
                background: linear-gradient(90deg, transparent 15%, rgba(58,118,240,0.05) 50%, transparent 85%);
                border-radius: 12px;
            }
            
            .resend-section {
                margin-top: 30px;
                text-align: center;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 12px;
            }
            
            .resend-btn {
                background: none;
                border: 2px solid #3A76F0;
                color: #3A76F0;
                padding: 10px 25px;
                border-radius: 8px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                display: inline-flex;
                align-items: center;
                gap: 5px;
                transition: all 0.3s;
                margin: 10px 0;
            }
            
            .resend-btn:hover {
                background: #3A76F0;
                color: white;
            }
            
            .timer-section {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
                color: #495057;
                font-size: 14px;
                margin-top: 10px;
            }
            
            .timer-text {
                font-family: monospace;
                font-size: 18px;
                font-weight: 600;
                color: #3A76F0;
            }
            
            .signal-warning {
                margin-top: 20px;
                padding: 12px;
                background: #fff3cd;
                border: 1px solid #ffeeba;
                border-radius: 8px;
                color: #856404;
                font-size: 13px;
                text-align: center;
            }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Geri sayım
            var minutes = 10;
            var seconds = 0;
            
            function updateCountdown() {
                if (minutes === 0 && seconds === 0) {
                    $('#countdown').text('Süre doldu');
                    return;
                }
                
                if (seconds === 0) {
                    minutes--;
                    seconds = 59;
                } else {
                    seconds--;
                }
                
                var timeStr = (minutes < 10 ? '0' + minutes : minutes) + ':' + (seconds < 10 ? '0' + seconds : seconds);
                $('#countdown').text(timeStr);
                
                setTimeout(updateCountdown, 1000);
            }
            
            updateCountdown();
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
        if (!isset($request['verify_nonce']) || !wp_verify_nonce($request['verify_nonce'], 'signal_sos_verify')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $phone = $request['phone'];
        $code = $request['code'];
        
        // Kodu doğrula
        if ($this->verify_code($phone, $code)) {
            // Profil oluştur
            $profile = $this->create_profile_from_phone($phone);
            
            // WordPress kullanıcısı oluştur veya bul
            $user_id = $this->find_or_create_user($profile);
            
            if ($user_id && !is_wp_error($user_id)) {
                // WordPress oturumu başlat
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id, true);
                
                // Başarılı giriş - yönlendir
                wp_redirect(home_url('/profil-merkezim/'));
                exit;
            } else {
                return $this->show_error('Kullanıcı oluşturulamadı.');
            }
        } else {
            return $this->show_error('Geçersiz doğrulama kodu. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Kodu doğrula
     */
    public function verify_code($phone, $code) {
        $saved_code = $this->get_verification_code($phone);
        
        if ($saved_code && $saved_code == $code) {
            // Kodu temizle
            $this->delete_verification_code($phone);
            return true;
        }
        
        return false;
    }
    
    /**
     * Telefon numarasından profil oluştur
     */
    protected function create_profile_from_phone($phone) {
        $profile = [
            'id' => 'signal_' . md5($phone . time()),
            'phone' => $phone,
            'username' => 'signal_' . substr(md5($phone), 0, 8),
            'name' => 'Signal Kullanıcı',
            'provider' => $this->slug,
            'provider_name' => $this->get_name(),
            'verified' => true,
            'login_method' => 'sos',
            'signal_verified' => true,
            'verified_at' => current_time('mysql')
        ];
        
        return $profile;
    }
    
    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    protected function find_or_create_user($profile) {
        // Telefon numarasına göre kullanıcı ara
        if (!empty($profile['phone'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'signal_phone',
                'meta_value' => $profile['phone'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Signal ID'ye göre ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'signal_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $profile['username'] ?? 'signal_' . uniqid();
        $email = $profile['phone'] . '@signal.user';
        
        $user_id = wp_create_user(
            $username,
            wp_generate_password(),
            $email
        );
        
        if (!is_wp_error($user_id)) {
            // Meta verileri kaydet
            update_user_meta($user_id, 'signal_phone', $profile['phone']);
            update_user_meta($user_id, 'signal_id', $profile['id']);
            update_user_meta($user_id, 'signal_verified', $profile['signal_verified']);
            update_user_meta($user_id, 'signal_verified_at', $profile['verified_at']);
            update_user_meta($user_id, 'display_name', $profile['name']);
            update_user_meta($user_id, 'ai_community_provider', $this->slug);
            
            return $user_id;
        }
        
        return false;
    }
    
    /**
     * Rate limit kontrolü
     */
    protected function check_rate_limit($phone) {
        $key = 'rate_limit_signal_' . md5($phone);
        $attempts = get_transient($key);
        
        if ($attempts && $attempts >= 3) {
            return false;
        }
        
        if ($attempts) {
            set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
        } else {
            set_transient($key, 1, HOUR_IN_SECONDS);
        }
        
        return true;
    }
    
    /**
     * Doğrulama kodunu kaydet
     */
    protected function save_verification_code($phone, $code, $expiry = 600) {
        set_transient('signal_code_' . md5($phone), $code, $expiry);
    }
    
    /**
     * Doğrulama kodunu getir
     */
    protected function get_verification_code($phone) {
        return get_transient('signal_code_' . md5($phone));
    }
    
    /**
     * Doğrulama kodunu sil
     */
    protected function delete_verification_code($phone) {
        delete_transient('signal_code_' . md5($phone));
    }
    
    /**
     * Telefon numarasını formatla
     */
    protected function format_phone_number($phone) {
        // Boşlukları ve özel karakterleri temizle
        $phone = preg_replace('/[^0-9\+]/', '', $phone);
        
        // + yoksa ekle (varsayılan ülke kodu +90)
        if (substr($phone, 0, 1) !== '+') {
            // Başındaki 0'ı temizle
            $phone = ltrim($phone, '0');
            $phone = '+90' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Hata mesajı göster
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error signal-error">
            <div class="error-icon">❌</div>
            <h3>Hata Oluştu!</h3>
            <p><?php echo esc_html($message); ?></p>
            <div class="error-actions">
                <a href="javascript:history.back()" class="error-back-btn">
                    <span>◀</span> Geri dön
                </a>
                <a href="<?php echo esc_url(home_url('/giris')); ?>" class="error-home-btn">
                    <span>🏠</span> Giriş sayfası
                </a>
            </div>
        </div>
        <style>
            .signal-error {
                max-width: 450px;
                margin: 50px auto;
                padding: 30px;
                background: #fff5f5;
                border: 2px solid #feb2b2;
                border-radius: 20px;
                color: #c53030;
                text-align: center;
                box-shadow: 0 10px 30px rgba(197,48,48,0.1);
            }
            .error-icon {
                font-size: 48px;
                margin-bottom: 20px;
            }
            .signal-error h3 {
                color: #c53030;
                font-size: 24px;
                margin: 0 0 15px;
            }
            .signal-error p {
                margin: 0 0 25px;
                line-height: 1.6;
            }
            .error-actions {
                display: flex;
                gap: 10px;
                justify-content: center;
            }
            .error-back-btn, .error-home-btn {
                padding: 10px 20px;
                border-radius: 8px;
                text-decoration: none;
                font-size: 14px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 5px;
                transition: all 0.3s;
            }
            .error-back-btn {
                background: #c53030;
                color: white;
            }
            .error-back-btn:hover {
                background: #9b2c2c;
            }
            .error-home-btn {
                background: #e2e8f0;
                color: #2d3748;
            }
            .error-home-btn:hover {
                background: #cbd5e0;
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