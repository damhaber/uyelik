<?php
/**
 * AI Community Engine - Mail.ru SOS Provider
 * 
 * Mail.ru SOS (Phone/Email) Authentication Provider
 * 
 * @package AI_Community
 * @subpackage Providers
 * @file masal-panel/modules/ai-community-engine/core/providers/mailru-sos.php
 * 
 * Sorumluluklar:
 * - Mail.ru kullanıcıları için SMS/Email ile giriş
 * - Telefon numarası veya email ile doğrulama kodu gönderme
 * - Mail.ru özel session yapısı
 * - Kiril karakter desteği
 * - Rate limiting ve güvenlik kontrolleri
 */

class AI_Community_Provider_Mailru_SOS extends AI_Community_SOS_Provider {
    
    /**
     * Provider slug (benzersiz kimlik)
     * @var string
     */
    public $slug = 'mailru-sos';
    
    /**
     * Provider görünen adı
     * @var string
     */
    protected $name = 'Mail.ru (SOS)';
    
    /**
     * Desteklenen metodlar
     * @var array
     */
    protected $methods = ['phone', 'email'];
    
    /**
     * Doğrulama kodu uzunluğu
     * @var int
     */
    protected $code_length = 6;
    
    /**
     * Kod geçerlilik süresi (saniye)
     * @var int
     */
    protected $code_expiry = 600; // 10 dakika
    
    /**
     * Maksimum deneme sayısı
     * @var int
     */
    protected $max_attempts = 3;
    
    /**
     * Rate limiting süresi (saniye)
     * @var int
     */
    protected $rate_limit = 60; // 1 dakika
    
    /**
     * Mail.ru domain listesi
     * @var array
     */
    protected $mailru_domains = [
        '@mail.ru',
        '@inbox.ru',
        '@list.ru',
        '@bk.ru',
        '@mail.ua'
    ];
    
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
        $this->core->log('debug', 'Mail.ru SOS provider başlatılıyor', [
            'slug' => $this->slug,
            'has_config' => !empty($config),
            'file' => 'masal-panel/modules/ai-community-engine/core/providers/mailru-sos.php'
        ]);
        
        /**
         * Mail.ru özel ayarları:
         * - SMS gönderim servisi (varsa)
         * - Email gönderim ayarları
         * - Test modu (kodları logla)
         */
    }
    
    /**
     * Ana giriş noktası
     * 
     * Sorumluluklar:
     * 1. GET/POST parametrelerini birleştir
     * 2. Nonce kontrolü
     * 3. Kod varsa callback'i işle
     * 4. Yoksa giriş formunu göster
     * 
     * @return string HTML çıktı
     */
    public function handle() {
        $this->core->log('info', 'Mail.ru SOS handle çağrıldı', [
            'file' => 'mailru-sos.php',
            'method' => 'handle'
        ]);
        
        // GET ve POST parametrelerini birleştir
        $request = array_merge($_GET, $_POST);
        
        // POST isteği ise nonce kontrolü yap
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($request['_wpnonce']) || !wp_verify_nonce($request['_wpnonce'], $this->slug . '_action')) {
                $this->core->log('error', 'Mail.ru SOS: Nonce doğrulaması başarısız', [
                    'file' => 'mailru-sos.php'
                ]);
                
                return $this->show_error('Güvenlik doğrulaması başarısız.');
            }
        }
        
        // Callback kontrolü (doğrulama kodu)
        if (isset($request['code']) && isset($request['identifier'])) {
            $this->core->log('info', 'Mail.ru SOS: Doğrulama kodu alındı', [
                'identifier_type' => $this->detect_identifier_type($request['identifier']),
                'file' => 'mailru-sos.php'
            ]);
            
            return $this->handle_callback($request);
        }
        
        // Giriş formunu göster
        return $this->handle_auth($request);
    }
    
    /**
     * Giriş formunu göster
     * 
     * @param array $request İstek parametreleri
     * @return string HTML çıktı
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html($this->name); ?> ile Giriş</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #005FF9 0%, #0040C1 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .auth-container {
                    max-width: 400px;
                    width: 100%;
                }
                
                .auth-card {
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
                
                .auth-header {
                    background: #005FF9;
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                
                .auth-header h1 {
                    font-size: 24px;
                    margin-bottom: 5px;
                    font-weight: 600;
                }
                
                .auth-header p {
                    font-size: 14px;
                    opacity: 0.9;
                }
                
                .auth-body {
                    padding: 30px;
                }
                
                .method-selector {
                    display: flex;
                    gap: 10px;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #e0e0e0;
                    padding-bottom: 10px;
                }
                
                .method-btn {
                    flex: 1;
                    padding: 10px;
                    background: none;
                    border: none;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 500;
                    color: #666;
                    cursor: pointer;
                    transition: all 0.3s ease;
                }
                
                .method-btn.active {
                    background: #005FF9;
                    color: white;
                }
                
                .method-btn.phone-btn.active {
                    background: #005FF9;
                }
                
                .method-btn.email-btn.active {
                    background: #005FF9;
                }
                
                .input-group {
                    margin-bottom: 20px;
                }
                
                .input-group label {
                    display: block;
                    margin-bottom: 8px;
                    font-size: 14px;
                    font-weight: 500;
                    color: #333;
                }
                
                .input-group input {
                    width: 100%;
                    padding: 12px 16px;
                    border: 2px solid #e0e0e0;
                    border-radius: 10px;
                    font-size: 16px;
                    transition: all 0.3s ease;
                }
                
                .input-group input:focus {
                    outline: none;
                    border-color: #005FF9;
                    box-shadow: 0 0 0 3px rgba(0, 95, 249, 0.1);
                }
                
                .input-group input.error {
                    border-color: #e53e3e;
                }
                
                .phone-input {
                    display: flex;
                    gap: 10px;
                }
                
                .phone-input .country-code {
                    width: 100px;
                    background: #f5f5f5;
                    border: 2px solid #e0e0e0;
                    border-radius: 10px;
                    padding: 12px;
                    font-size: 16px;
                    color: #333;
                    text-align: center;
                }
                
                .phone-input .phone-number {
                    flex: 1;
                }
                
                .hint {
                    margin-top: 5px;
                    font-size: 12px;
                    color: #999;
                }
                
                .btn-primary {
                    width: 100%;
                    padding: 14px;
                    background: #005FF9;
                    color: white;
                    border: none;
                    border-radius: 10px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.3s ease;
                }
                
                .btn-primary:hover {
                    background: #0040C1;
                }
                
                .btn-primary:disabled {
                    background: #ccc;
                    cursor: not-allowed;
                }
                
                .error-message {
                    background: #fff5f5;
                    color: #e53e3e;
                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    border-left: 4px solid #e53e3e;
                }
                
                .success-message {
                    background: #f0fff4;
                    color: #38a169;
                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    border-left: 4px solid #38a169;
                }
                
                .footer {
                    margin-top: 20px;
                    text-align: center;
                }
                
                .footer a {
                    color: #999;
                    text-decoration: none;
                    font-size: 12px;
                }
                
                .footer a:hover {
                    color: #666;
                }
                
                .language-switch {
                    margin-top: 15px;
                    text-align: center;
                }
                
                .language-switch a {
                    color: rgba(255,255,255,0.8);
                    text-decoration: none;
                    font-size: 12px;
                }
                
                .language-switch a:hover {
                    color: white;
                }
            </style>
        </head>
        <body>
            <div class="auth-container">
                <div class="auth-card">
                    <div class="auth-header">
                        <h1><?php echo esc_html($this->name); ?></h1>
                        <p>Telefon veya email ile giriş</p>
                    </div>
                    <div class="auth-body">
                        
                        <?php if (isset($request['error'])): ?>
                            <div class="error-message">
                                <?php echo esc_html($request['error']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($request['success'])): ?>
                            <div class="success-message">
                                <?php echo esc_html($request['success']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="method-selector">
                            <button class="method-btn phone-btn active" onclick="switchMethod('phone')">📱 Telefon</button>
                            <button class="method-btn email-btn" onclick="switchMethod('email')">✉️ Email</button>
                        </div>
                        
                        <form id="auth-form" method="post" action="">
                            <?php wp_nonce_field($this->slug . '_action'); ?>
                            <input type="hidden" name="method" id="method" value="phone">
                            
                            <div id="phone-input-group" class="input-group">
                                <label for="phone">Telefon Numarası / Номер телефона</label>
                                <div class="phone-input">
                                    <select class="country-code" id="country_code" name="country_code">
                                        <option value="7">+7 (Rusya)</option>
                                        <option value="380">+380 (Ukrayna)</option>
                                        <option value="375">+375 (Belarus)</option>
                                        <option value="77">+77 (Kazakistan)</option>
                                    </select>
                                    <input type="tel" class="phone-number" id="phone" name="phone" 
                                           placeholder="XXX XXX-XX-XX" 
                                           pattern="[0-9]{10}" 
                                           maxlength="10"
                                           required>
                                </div>
                                <div class="hint">Örnek: 916 123-45-67</div>
                            </div>
                            
                            <div id="email-input-group" class="input-group" style="display: none;">
                                <label for="email">Email Adresi / Адрес электронной почты</label>
                                <input type="email" id="email" name="email" 
                                       placeholder="username@mail.ru" 
                                       pattern="[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$"
                                       required>
                                <div class="hint">@mail.ru, @inbox.ru, @list.ru, @bk.ru</div>
                            </div>
                            
                            <button type="submit" class="btn-primary" id="submit-btn">
                                Kod Gönder / Отправить код
                            </button>
                        </form>
                        
                        <div class="footer">
                            <a href="#" onclick="window.close()">İptal / Отмена</a>
                        </div>
                    </div>
                </div>
                
                <div class="language-switch">
                    <a href="#" onclick="switchLanguage('tr')">Türkçe</a> | 
                    <a href="#" onclick="switchLanguage('ru')">Русский</a>
                </div>
            </div>
            
            <script>
                function switchMethod(method) {
                    const phoneBtn = document.querySelector('.phone-btn');
                    const emailBtn = document.querySelector('.email-btn');
                    const phoneGroup = document.getElementById('phone-input-group');
                    const emailGroup = document.getElementById('email-input-group');
                    const methodInput = document.getElementById('method');
                    
                    if (method === 'phone') {
                        phoneBtn.classList.add('active');
                        emailBtn.classList.remove('active');
                        phoneGroup.style.display = 'block';
                        emailGroup.style.display = 'none';
                        methodInput.value = 'phone';
                        
                        // Phone alanını zorunlu yap
                        document.getElementById('phone').required = true;
                        document.getElementById('email').required = false;
                    } else {
                        emailBtn.classList.add('active');
                        phoneBtn.classList.remove('active');
                        emailGroup.style.display = 'block';
                        phoneGroup.style.display = 'none';
                        methodInput.value = 'email';
                        
                        // Email alanını zorunlu yap
                        document.getElementById('email').required = true;
                        document.getElementById('phone').required = false;
                    }
                }
                
                function switchLanguage(lang) {
                    if (lang === 'tr') {
                        // Türkçe metinler
                        document.querySelector('.auth-header p').textContent = 'Telefon veya email ile giriş';
                        document.querySelector('.phone-btn').innerHTML = '📱 Telefon';
                        document.querySelector('.email-btn').innerHTML = '✉️ Email';
                        document.querySelector('label[for="phone"]').textContent = 'Telefon Numarası / Номер телефона';
                        document.querySelector('#phone').placeholder = 'XXX XXX-XX-XX';
                        document.querySelector('.hint').textContent = 'Örnek: 916 123-45-67';
                        document.querySelector('label[for="email"]').textContent = 'Email Adresi / Адрес электронной почты';
                        document.querySelector('#email').placeholder = 'username@mail.ru';
                        document.querySelector('.email-input-group .hint').textContent = '@mail.ru, @inbox.ru, @list.ru, @bk.ru';
                        document.querySelector('#submit-btn').textContent = 'Kod Gönder / Отправить код';
                        document.querySelector('.footer a').textContent = 'İptal / Отмена';
                    } else {
                        // Rusça metinler
                        document.querySelector('.auth-header p').textContent = 'Вход по телефону или email';
                        document.querySelector('.phone-btn').innerHTML = '📱 Телефон';
                        document.querySelector('.email-btn').innerHTML = '✉️ Email';
                        document.querySelector('label[for="phone"]').textContent = 'Номер телефона';
                        document.querySelector('#phone').placeholder = 'XXX XXX-XX-XX';
                        document.querySelector('.hint').textContent = 'Пример: 916 123-45-67';
                        document.querySelector('label[for="email"]').textContent = 'Адрес электронной почты';
                        document.querySelector('#email').placeholder = 'username@mail.ru';
                        document.querySelector('.email-input-group .hint').textContent = '@mail.ru, @inbox.ru, @list.ru, @bk.ru';
                        document.querySelector('#submit-btn').textContent = 'Отправить код';
                        document.querySelector('.footer a').textContent = 'Отмена';
                    }
                }
                
                // Form doğrulama
                document.getElementById('auth-form').addEventListener('submit', function(e) {
                    const method = document.getElementById('method').value;
                    
                    if (method === 'phone') {
                        const phone = document.getElementById('phone').value;
                        if (!/^\d{10}$/.test(phone)) {
                            e.preventDefault();
                            alert('Lütfen geçerli bir telefon numarası girin (10 haneli)');
                        }
                    } else {
                        const email = document.getElementById('email').value;
                        const validDomains = ['mail.ru', 'inbox.ru', 'list.ru', 'bk.ru', 'mail.ua'];
                        const domain = email.split('@')[1];
                        
                        if (!validDomains.includes(domain)) {
                            e.preventDefault();
                            alert('Lütfen geçerli bir Mail.ru email adresi girin');
                        }
                    }
                });
            </script>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Callback işleme (kod doğrulama)
     * 
     * @param array $request İstek parametreleri
     * @return string HTML çıktı
     */
    public function handle_callback($request) {
        $code = $request['code'];
        $identifier = $request['identifier'];
        $identifier_type = $this->detect_identifier_type($identifier);
        
        $this->core->log('info', 'Mail.ru SOS: Kod doğrulanıyor', [
            'identifier_type' => $identifier_type,
            'code_length' => strlen($code),
            'file' => 'mailru-sos.php'
        ]);
        
        // Transient'ten kullanıcı verilerini al
        $transient_key = 'mailru_sos_' . md5($identifier);
        $data = get_transient($transient_key);
        
        if (!$data) {
            $this->core->log('error', 'Mail.ru SOS: Kod bulunamadı veya süresi dolmuş', [
                'identifier' => $this->mask_identifier($identifier),
                'file' => 'mailru-sos.php'
            ]);
            
            return $this->show_verification_form($identifier, 'Doğrulama kodunun süresi dolmuş veya geçersiz.');
        }
        
        // Deneme sayısını kontrol et
        if ($data['attempts'] >= $this->max_attempts) {
            $this->core->log('error', 'Mail.ru SOS: Maksimum deneme sayısı aşıldı', [
                'identifier' => $this->mask_identifier($identifier),
                'attempts' => $data['attempts'],
                'file' => 'mailru-sos.php'
            ]);
            
            delete_transient($transient_key);
            return $this->show_verification_form($identifier, 'Çok fazla hatalı deneme. Lütfen tekrar kod isteyin.');
        }
        
        // Kodu kontrol et
        if ($data['code'] != $code) {
            $data['attempts']++;
            set_transient($transient_key, $data, $this->code_expiry);
            
            $this->core->log('error', 'Mail.ru SOS: Hatalı kod', [
                'identifier' => $this->mask_identifier($identifier),
                'attempts' => $data['attempts'],
                'file' => 'mailru-sos.php'
            ]);
            
            $remaining = $this->max_attempts - $data['attempts'];
            return $this->show_verification_form(
                $identifier, 
                'Hatalı kod. Kalan deneme: ' . $remaining
            );
        }
        
        // Kod doğru, transient'i temizle
        delete_transient($transient_key);
        
        $this->core->log('info', 'Mail.ru SOS: Kod doğrulandı, kullanıcı giriş yapıyor', [
            'identifier' => $this->mask_identifier($identifier),
            'file' => 'mailru-sos.php'
        ]);
        
        // Kullanıcı profili oluştur
        $profile = [
            'provider' => $this->slug,
            'identifier' => $identifier,
            'identifier_type' => $identifier_type,
            'email' => $identifier_type === 'email' ? $identifier : null,
            'phone' => $identifier_type === 'phone' ? $identifier : null,
            'display_name' => $identifier_type === 'email' ? explode('@', $identifier)[0] : 'user_' . substr($identifier, -4)
        ];
        
        // Mail.ru özel alanları
        if ($identifier_type === 'email') {
            $profile['mailru_domain'] = explode('@', $identifier)[1];
        }
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (is_wp_error($user_id)) {
            $this->core->log('error', 'Mail.ru SOS: Kullanıcı oluşturulamadı', [
                'error' => $user_id->get_error_message(),
                'file' => 'mailru-sos.php'
            ]);
            
            return $this->show_error('Kullanıcı oluşturulamadı: ' . $user_id->get_error_message());
        }
        
        // WordPress giriş yap
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        $this->core->log('info', 'Mail.ru SOS: Başarılı giriş', [
            'user_id' => $user_id,
            'identifier_type' => $identifier_type,
            'file' => 'mailru-sos.php'
        ]);
        
        // Yönlendir
        $redirect_url = home_url();
        if (isset($data['data']['redirect'])) {
            $redirect_url = $data['data']['redirect'];
        }
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * AJAX ile kod gönder
     */
    public function ajax_send_code() {
        $this->core->log('info', 'Mail.ru SOS: AJAX kod gönderme isteği', [
            'file' => 'mailru-sos.php',
            'method' => 'ajax_send_code'
        ]);
        
        // Nonce kontrolü
        if (!check_ajax_referer($this->slug . '_ajax', 'nonce', false)) {
            $this->core->log('error', 'Mail.ru SOS: AJAX nonce hatası', [
                'file' => 'mailru-sos.php'
            ]);
            wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
            return;
        }
        
        $method = $_POST['method'] ?? '';
        $identifier = '';
        
        if ($method === 'phone') {
            $country_code = $_POST['country_code'] ?? '7';
            $phone = $_POST['phone'] ?? '';
            $identifier = '+' . $country_code . $phone;
        } else {
            $identifier = $_POST['email'] ?? '';
        }
        
        if (empty($identifier)) {
            wp_send_json_error(['message' => 'Lütfen geçerli bir telefon veya email girin.']);
            return;
        }
        
        // Rate limiting kontrolü
        $rate_key = 'rate_' . md5($identifier);
        $last_sent = get_transient($rate_key);
        
        if ($last_sent) {
            $wait = $this->rate_limit - (time() - $last_sent);
            if ($wait > 0) {
                wp_send_json_error([
                    'message' => sprintf('Çok fazla istek. Lütfen %d saniye bekleyin.', $wait)
                ]);
                return;
            }
        }
        
        // Doğrulama kodu oluştur
        $code = $this->generate_code();
        
        $this->core->log('info', 'Mail.ru SOS: Doğrulama kodu oluşturuldu', [
            'identifier_type' => $this->detect_identifier_type($identifier),
            'code_length' => strlen($code),
            'file' => 'mailru-sos.php'
        ]);
        
        // Kodu transient'e kaydet
        $transient_key = 'mailru_sos_' . md5($identifier);
        set_transient($transient_key, [
            'code' => $code,
            'attempts' => 0,
            'data' => [
                'method' => $method,
                'identifier' => $identifier,
                'redirect' => $_POST['redirect'] ?? home_url()
            ]
        ], $this->code_expiry);
        
        // Rate limiting kaydı
        set_transient($rate_key, time(), $this->rate_limit);
        
        // Kodu gönder
        $sent = false;
        if ($method === 'phone') {
            $sent = $this->send_phone_code($identifier, $code);
        } else {
            $sent = $this->send_email_code($identifier, $code);
        }
        
        if ($sent) {
            $this->core->log('info', 'Mail.ru SOS: Kod gönderildi', [
                'identifier' => $this->mask_identifier($identifier),
                'file' => 'mailru-sos.php'
            ]);
            
            wp_send_json_success([
                'message' => 'Doğrulama kodu gönderildi.',
                'identifier' => $identifier,
                'identifier_masked' => $this->mask_identifier($identifier)
            ]);
        } else {
            $this->core->log('error', 'Mail.ru SOS: Kod gönderilemedi', [
                'identifier' => $this->mask_identifier($identifier),
                'method' => $method,
                'file' => 'mailru-sos.php'
            ]);
            
            wp_send_json_error(['message' => 'Kod gönderilemedi. Lütfen tekrar deneyin.']);
        }
    }
    
    /**
     * AJAX ile kod doğrula
     */
    public function ajax_verify_code() {
        $this->core->log('info', 'Mail.ru SOS: AJAX kod doğrulama isteği', [
            'file' => 'mailru-sos.php',
            'method' => 'ajax_verify_code'
        ]);
        
        // Nonce kontrolü
        if (!check_ajax_referer($this->slug . '_ajax', 'nonce', false)) {
            wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
            return;
        }
        
        $identifier = $_POST['identifier'] ?? '';
        $code = $_POST['code'] ?? '';
        
        if (empty($identifier) || empty($code)) {
            wp_send_json_error(['message' => 'Eksik bilgi.']);
            return;
        }
        
        // Transient'ten veriyi al
        $transient_key = 'mailru_sos_' . md5($identifier);
        $data = get_transient($transient_key);
        
        if (!$data) {
            wp_send_json_error(['message' => 'Kodun süresi dolmuş. Lütfen tekrar kod isteyin.']);
            return;
        }
        
        // Deneme sayısını kontrol et
        if ($data['attempts'] >= $this->max_attempts) {
            delete_transient($transient_key);
            wp_send_json_error(['message' => 'Çok fazla hatalı deneme. Lütfen tekrar kod isteyin.']);
            return;
        }
        
        // Kodu kontrol et
        if ($data['code'] != $code) {
            $data['attempts']++;
            set_transient($transient_key, $data, $this->code_expiry);
            
            wp_send_json_error([
                'message' => 'Hatalı kod.',
                'attempts' => $data['attempts'],
                'remaining' => $this->max_attempts - $data['attempts']
            ]);
            return;
        }
        
        // Kod doğru
        wp_send_json_success([
            'message' => 'Kod doğrulandı. Yönlendiriliyorsunuz...',
            'redirect' => $data['data']['redirect'] ?? home_url()
        ]);
    }
    
    /**
     * Doğrulama formunu göster
     * 
     * @param string $identifier Telefon/Email
     * @param string $error Hata mesajı (opsiyonel)
     * @return string HTML çıktı
     */
    protected function show_verification_form($identifier, $error = '') {
        $identifier_type = $this->detect_identifier_type($identifier);
        $masked = $this->mask_identifier($identifier);
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="tr">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Kod Doğrulama - <?php echo esc_html($this->name); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    background: linear-gradient(135deg, #005FF9 0%, #0040C1 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .verify-container {
                    max-width: 400px;
                    width: 100%;
                }
                
                .verify-card {
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
                
                .verify-header {
                    background: #005FF9;
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                
                .verify-header h1 {
                    font-size: 24px;
                    margin-bottom: 5px;
                    font-weight: 600;
                }
                
                .verify-header p {
                    font-size: 14px;
                    opacity: 0.9;
                }
                
                .verify-body {
                    padding: 30px;
                }
                
                .info {
                    background: #f0f9ff;
                    color: #005FF9;
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    text-align: center;
                }
                
                .code-input {
                    text-align: center;
                    margin-bottom: 20px;
                }
                
                .code-input input {
                    width: 100%;
                    padding: 15px;
                    font-size: 24px;
                    text-align: center;
                    letter-spacing: 8px;
                    border: 2px solid #e0e0e0;
                    border-radius: 10px;
                    transition: all 0.3s ease;
                }
                
                .code-input input:focus {
                    outline: none;
                    border-color: #005FF9;
                    box-shadow: 0 0 0 3px rgba(0, 95, 249, 0.1);
                }
                
                .error-message {
                    background: #fff5f5;
                    color: #e53e3e;
                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    border-left: 4px solid #e53e3e;
                }
                
                .btn-primary {
                    width: 100%;
                    padding: 14px;
                    background: #005FF9;
                    color: white;
                    border: none;
                    border-radius: 10px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.3s ease;
                }
                
                .btn-primary:hover {
                    background: #0040C1;
                }
                
                .btn-link {
                    display: block;
                    text-align: center;
                    margin-top: 15px;
                    color: #999;
                    text-decoration: none;
                    font-size: 14px;
                }
                
                .btn-link:hover {
                    color: #666;
                }
            </style>
        </head>
        <body>
            <div class="verify-container">
                <div class="verify-card">
                    <div class="verify-header">
                        <h1><?php echo esc_html($this->name); ?></h1>
                        <p>Kod Doğrulama</p>
                    </div>
                    <div class="verify-body">
                        
                        <div class="info">
                            <?php echo esc_html($masked); ?> adresine<br>
                            6 haneli doğrulama kodu gönderildi.
                        </div>
                        
                        <?php if (!empty($error)): ?>
                            <div class="error-message">
                                <?php echo esc_html($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="post" action="">
                            <?php wp_nonce_field($this->slug . '_action'); ?>
                            <input type="hidden" name="identifier" value="<?php echo esc_attr($identifier); ?>">
                            
                            <div class="code-input">
                                <input type="text" name="code" 
                                       maxlength="6" 
                                       pattern="[0-9]{6}" 
                                       placeholder="000000"
                                       autocomplete="off"
                                       required>
                            </div>
                            
                            <button type="submit" class="btn-primary">
                                Doğrula / Подтвердить
                            </button>
                        </form>
                        
                        <a href="<?php echo esc_url(add_query_arg('auth', $this->slug)); ?>" class="btn-link">
                            ← Kodu tekrar gönder
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * SMS ile kod gönder
     * 
     * @param string $phone Telefon numarası
     * @param string $code Doğrulama kodu
     * @return bool Başarılı mı
     */
    protected function send_phone_code($phone, $code) {
        // Test modunda logla
        if (isset($this->config['test_mode']) && $this->config['test_mode']) {
            $this->core->log('info', 'Mail.ru SOS: [TEST] SMS kodu', [
                'phone' => $this->mask_identifier($phone),
                'code' => $code,
                'file' => 'mailru-sos.php'
            ]);
            return true;
        }
        
        /**
         * Burada gerçek SMS servisi entegrasyonu yapılacak
         * Örnek: Twilio, Nexmo, Clickatell veya yerel SMS servisi
         */
        
        $this->core->log('warning', 'Mail.ru SOS: SMS gönderim servisi yapılandırılmamış', [
            'phone' => $this->mask_identifier($phone),
            'code' => $code,
            'file' => 'mailru-sos.php'
        ]);
        
        // Varsayılan olarak false döndür (gerçek entegrasyon yoksa)
        return false;
    }
    
    /**
     * Email ile kod gönder
     * 
     * @param string $email Email adresi
     * @param string $code Doğrulama kodu
     * @return bool Başarılı mı
     */
    protected function send_email_code($email, $code) {
        // Test modunda logla
        if (isset($this->config['test_mode']) && $this->config['test_mode']) {
            $this->core->log('info', 'Mail.ru SOS: [TEST] Email kodu', [
                'email' => $this->mask_identifier($email),
                'code' => $code,
                'file' => 'mailru-sos.php'
            ]);
            return true;
        }
        
        $subject = 'Doğrulama Kodu / Код подтверждения';
        $message = $this->get_email_template($code);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
        ];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if ($sent) {
            $this->core->log('info', 'Mail.ru SOS: Email gönderildi', [
                'email' => $this->mask_identifier($email),
                'file' => 'mailru-sos.php'
            ]);
        } else {
            $this->core->log('error', 'Mail.ru SOS: Email gönderilemedi', [
                'email' => $this->mask_identifier($email),
                'file' => 'mailru-sos.php'
            ]);
        }
        
        return $sent;
    }
    
    /**
     * Email şablonu oluştur
     * 
     * @param string $code Doğrulama kodu
     * @return string HTML email
     */
    protected function get_email_template($code) {
        $site_name = get_bloginfo('name');
        $site_url = home_url();
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Doğrulama Kodu</title>
        </head>
        <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
            <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background: #005FF9; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                    <h1 style="margin: 0;"><?php echo esc_html($site_name); ?></h1>
                </div>
                
                <div style="background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px;">
                    <h2 style="margin-top: 0;">Doğrulama Kodu / Код подтверждения</h2>
                    
                    <p>Merhaba,</p>
                    <p>Giriş yapmak için doğrulama kodunuz:</p>
                    
                    <div style="background: white; padding: 20px; text-align: center; border-radius: 10px; margin: 20px 0;">
                        <span style="font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #005FF9;">
                            <?php echo esc_html($code); ?>
                        </span>
                    </div>
                    
                    <p style="color: #999; font-size: 14px;">
                        Bu kod 10 dakika süreyle geçerlidir.<br>
                        Eğer bu isteği siz yapmadıysanız, bu emaili dikkate almayın.
                    </p>
                    
                    <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;">
                    
                    <p style="color: #999; font-size: 12px; text-align: center;">
                        &copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?><br>
                        <a href="<?php echo esc_url($site_url); ?>" style="color: #005FF9;"><?php echo esc_html($site_url); ?></a>
                    </p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Kullanıcı profilini kaydet
     * 
     * @param array $profile Kullanıcı profili
     * @return int|WP_Error Kullanıcı ID'si veya hata
     */
    protected function find_or_create_user($profile) {
        $this->core->log('info', 'Mail.ru SOS: Kullanıcı aranıyor', [
            'identifier_type' => $profile['identifier_type'],
            'file' => 'mailru-sos.php'
        ]);
        
        $user_id = null;
        
        // 1. Email'e göre ara
        if (!empty($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                $user_id = $user->ID;
                $this->core->log('info', 'Mail.ru SOS: Kullanıcı email ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'mailru-sos.php'
                ]);
            }
        }
        
        // 2. Telefona göre ara
        if (!$user_id && !empty($profile['phone'])) {
            $users = get_users([
                'meta_key' => $this->slug . '_phone',
                'meta_value' => $profile['phone'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            if (!empty($users)) {
                $user_id = $users[0];
                $this->core->log('info', 'Mail.ru SOS: Kullanıcı telefon ile bulundu', [
                    'user_id' => $user_id,
                    'file' => 'mailru-sos.php'
                ]);
            }
        }
        
        // 3. Yeni kullanıcı oluştur
        if (!$user_id) {
            $this->core->log('info', 'Mail.ru SOS: Yeni kullanıcı oluşturuluyor', [
                'file' => 'mailru-sos.php'
            ]);
            
            // Kullanıcı adı oluştur
            $username = $this->generate_unique_username($profile);
            
            // Email (yoksa dummy email)
            $email = $profile['email'] ?? $this->slug . '_' . md5($profile['identifier']) . '@local.mail';
            
            // Kullanıcı oluştur
            $user_id = wp_create_user($username, wp_generate_password(), $email);
            
            if (is_wp_error($user_id)) {
                $this->core->log('error', 'Mail.ru SOS: Kullanıcı oluşturulamadı', [
                    'error' => $user_id->get_error_message(),
                    'file' => 'mailru-sos.php'
                ]);
                return $user_id;
            }
            
            $this->core->log('info', 'Mail.ru SOS: Yeni kullanıcı oluşturuldu', [
                'user_id' => $user_id,
                'username' => $username,
                'file' => 'mailru-sos.php'
            ]);
            
            // Telefon varsa kaydet
            if (!empty($profile['phone'])) {
                update_user_meta($user_id, $this->slug . '_phone', $profile['phone']);
            }
            
            // Email varsa kaydet
            if (!empty($profile['email'])) {
                update_user_meta($user_id, $this->slug . '_email', $profile['email']);
            }
            
            // Mail.ru domain varsa kaydet
            if (!empty($profile['mailru_domain'])) {
                update_user_meta($user_id, $this->slug . '_domain', $profile['mailru_domain']);
            }
            
            // Display name güncelle
            if (!empty($profile['display_name'])) {
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $profile['display_name']
                ]);
            }
        }
        
        // Ana provider olarak işaretle
        update_user_meta($user_id, 'ai_community_provider', $this->slug);
        
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
        
        // Email varsa @ öncesini kullan
        if (!empty($profile['email'])) {
            $base = explode('@', $profile['email'])[0];
        }
        
        // Telefon varsa son 4 haneyi kullan
        if (empty($base) && !empty($profile['phone'])) {
            $base = 'user_' . substr(preg_replace('/[^0-9]/', '', $profile['phone']), -4);
        }
        
        // Hala yoksa identifier'dan oluştur
        if (empty($base)) {
            $base = 'mailru_' . substr(md5($profile['identifier']), 0, 8);
        }
        
        // Kiril karakterlerini Latin'e çevir
        $base = $this->transliterate_cyrillic($base);
        
        // Özel karakterleri temizle
        $base = preg_replace('/[^a-z0-9]/', '-', $base);
        $base = preg_replace('/-+/', '-', $base);
        $base = trim($base, '-');
        
        // Çok kısa ise random ekle
        if (strlen($base) < 3) {
            $base = 'user_' . substr(md5($profile['identifier']), 0, 6);
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
     * Kiril karakterlerini Latin karakterlere dönüştür
     * 
     * @param string $string Dönüştürülecek metin
     * @return string Dönüştürülmüş metin
     */
    protected function transliterate_cyrillic($string) {
        $cyrillic = [
            'а','б','в','г','д','е','ё','ж','з','и','й','к','л','м','н','о','п',
            'р','с','т','у','ф','х','ц','ч','ш','щ','ъ','ы','ь','э','ю','я',
            'А','Б','В','Г','Д','Е','Ё','Ж','З','И','Й','К','Л','М','Н','О','П',
            'Р','С','Т','У','Ф','Х','Ц','Ч','Ш','Щ','Ъ','Ы','Ь','Э','Ю','Я'
        ];
        
        $latin = [
            'a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p',
            'r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya',
            'A','B','V','G','D','E','Yo','Zh','Z','I','Y','K','L','M','N','O','P',
            'R','S','T','U','F','Kh','Ts','Ch','Sh','Shch','','Y','','E','Yu','Ya'
        ];
        
        return str_replace($cyrillic, $latin, $string);
    }
    
    /**
     * Identifier tipini tespit et (phone/email)
     * 
     * @param string $identifier Telefon veya email
     * @return string 'phone' veya 'email'
     */
    protected function detect_identifier_type($identifier) {
        if (strpos($identifier, '@') !== false) {
            return 'email';
        }
        return 'phone';
    }
    
    /**
     * Identifier'ı maskele (güvenlik için)
     * 
     * @param string $identifier Telefon veya email
     * @return string Maskelenmiş
     */
    protected function mask_identifier($identifier) {
        if ($this->detect_identifier_type($identifier) === 'email') {
            return $this->mask_email($identifier);
        } else {
            return $this->mask_phone($identifier);
        }
    }
    
    /**
     * Email maskele
     * 
     * @param string $email Email adresi
     * @return string Maskelenmiş email
     */
    protected function mask_email($email) {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];
        
        $masked_name = substr($name, 0, 2) . str_repeat('*', max(0, strlen($name) - 2));
        
        return $masked_name . '@' . $domain;
    }
    
    /**
     * Telefon maskele
     * 
     * @param string $phone Telefon numarası
     * @return string Maskelenmiş telefon
     */
    protected function mask_phone($phone) {
        // +7 916 123-45-67 formatını temizle
        $clean = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($clean) > 4) {
            $last4 = substr($clean, -4);
            $masked = str_repeat('*', strlen($clean) - 4) . $last4;
            
            // Formatlı gösterim
            if (strlen($clean) === 11) { // Rusya formatı
                return '+7 ' . substr($masked, 1, 3) . ' ' . substr($masked, 4, 3) . '-' . substr($masked, 7, 2) . '-' . substr($masked, 9, 2);
            }
        }
        
        return $phone;
    }
}