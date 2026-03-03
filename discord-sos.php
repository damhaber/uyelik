<?php
/**
 * AI Community Provider: Discord SOS
 * 
 * Discord tabanlı SOS doğrulama provider'ı
 * Kullanıcıların telefon numaralarına SMS göndererek kimlik doğrulama yapar
 * 
 * @package AI_Community
 * @since 1.0.0
 */

// Direkt erişimi engelle
if (!defined('ABSPATH')) exit;

class AI_Community_Provider_Discord_SOS extends AI_Community_SOS_Provider {
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'Discord (SOS)';
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'discord-sos';
    
    /**
     * Provider rengi (Discord Blurple)
     * @var string
     */
    protected $brand_color = '#5865F2';
    
    /**
     * API anahtarı
     * @var string
     */
    private $api_key = '';
    
    /**
     * API secret
     * @var string
     */
    private $api_secret = '';
    
    /**
     * API endpoint
     * @var string
     */
    private $api_endpoint = 'https://discord.com/api/v9';
    
    /**
     * SMS servisi tipi
     * @var string
     */
    private $sms_service = 'twilio'; // twilio, vonage, plivo, custom
    
    /**
     * SMS servisi yapılandırması
     * @var array
     */
    private $sms_config = [];
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Discord'a özel yapılandırmalar
        if (isset($config['api_key'])) {
            $this->api_key = $config['api_key'];
        }
        
        if (isset($config['api_secret'])) {
            $this->api_secret = $config['api_secret'];
        }
        
        if (isset($config['sms_service'])) {
            $this->sms_service = $config['sms_service'];
        }
        
        if (isset($config['sms_config']) && is_array($config['sms_config'])) {
            $this->sms_config = $config['sms_config'];
        }
        
        // SMS servisi yoksa varsayılan ayarlar
        if (empty($this->sms_config)) {
            $this->sms_config = [
                'from_number' => get_option('ai_community_sms_from', ''),
                'account_sid' => get_option('ai_community_twilio_sid', ''),
                'auth_token' => get_option('ai_community_twilio_token', '')
            ];
        }
    }
    
    /**
     * Provider tipini döndürür
     * 
     * @return string
     */
    public function get_type() {
        return 'sos';
    }
    
    /**
     * Provider adını döndürür
     * 
     * @return string
     */
    public function get_name() {
        return $this->name;
    }
    
    /**
     * Provider slug'ını döndürür
     * 
     * @return string
     */
    public function get_slug() {
        return $this->slug;
    }
    
    /**
     * Provider etiketini döndürür
     * 
     * @return string
     */
    public function get_label() {
        return $this->name;
    }
    
    /**
     * Provider'ın aktif olup olmadığını kontrol eder
     * 
     * @return bool
     */
    public function is_active() {
        // API anahtarı kontrolü
        if (empty($this->api_key)) {
            return false;
        }
        
        // SMS servisi kontrolü
        if ($this->sms_service === 'twilio' && empty($this->sms_config['account_sid'])) {
            return false;
        }
        
        return parent::is_active();
    }
    
    /**
     * Provider durumunu döndürür
     * 
     * @return array
     */
    public function get_status() {
        $status = parent::get_status();
        
        $status['api_key_configured'] = !empty($this->api_key);
        $status['sms_service'] = $this->sms_service;
        $status['sms_configured'] = !empty($this->sms_config['from_number']);
        
        return $status;
    }
    
    /**
     * Ana işleyici
     * 
     * @return string|void
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        // Callback işleme
        if (isset($request['code']) || isset($request['phone']) || isset($request['verify_code'])) {
            return $this->handle_callback($request);
        }
        
        // Auth formu göster
        return $this->handle_auth($request);
    }
    
    /**
     * Callback işleyici
     * 
     * @param array $request İstek parametreleri
     * @return string|void
     */
    public function handle_callback($request) {
        try {
            // Nonce kontrolü
            if (isset($request['_wpnonce']) && !wp_verify_nonce($request['_wpnonce'], 'ai_community_sos_' . $this->slug)) {
                throw new Exception('Güvenlik doğrulaması başarısız oldu.');
            }
            
            // Kod gönderme işlemi
            if (isset($request['send_code']) && isset($request['phone'])) {
                return $this->send_verification_code($request);
            }
            
            // Kod doğrulama işlemi
            if (isset($request['verify_code']) && isset($request['phone']) && isset($request['code'])) {
                return $this->verify_code_and_login($request);
            }
            
            throw new Exception('Geçersiz istek parametreleri.');
            
        } catch (Exception $e) {
            $this->core->log('sos_callback_error', [
                'provider' => $this->slug,
                'error' => $e->getMessage()
            ]);
            
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Doğrulama kodu gönderir
     * 
     * @param array $request İstek parametreleri
     * @return string
     */
    protected function send_verification_code($request) {
        $phone = $this->format_phone_number($request['phone']);
        
        // Telefon numarası format kontrolü
        if (!$phone) {
            return $this->show_error('Geçerli bir telefon numarası giriniz.');
        }
        
        // Rate limit kontrolü
        if (!$this->check_rate_limit($phone)) {
            return $this->show_error('Çok fazla deneme yaptınız. Lütfen 1 saat sonra tekrar deneyiniz.');
        }
        
        // 6 haneli doğrulama kodu oluştur
        $code = wp_rand(100000, 999999);
        
        // Kodu kaydet
        $this->save_verification_code($phone, $code);
        
        // SMS gönder
        $sent = $this->send_sms($phone, $code);
        
        if (!$sent) {
            return $this->show_error('SMS gönderilemedi. Lütfen daha sonra tekrar deneyiniz.');
        }
        
        // Başarılı - doğrulama formunu göster
        return $this->show_verification_form($phone);
    }
    
    /**
     * Kodu doğrular ve giriş yapar
     * 
     * @param array $request İstek parametreleri
     * @return string|void
     */
    protected function verify_code_and_login($request) {
        $phone = $this->format_phone_number($request['phone']);
        $code = sanitize_text_field($request['code']);
        
        if (!$phone || !$code) {
            return $this->show_error('Geçersiz telefon numarası veya doğrulama kodu.');
        }
        
        // Kodu doğrula
        if (!$this->verify_code($phone, $code)) {
            return $this->show_error('Doğrulama kodu geçersiz veya süresi dolmuş.');
        }
        
        // Telefon numarasına göre profil oluştur
        $profile = $this->create_profile_from_phone($phone);
        
        if (!$profile) {
            return $this->show_error('Profil oluşturulamadı.');
        }
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id || is_wp_error($user_id)) {
            return $this->show_error('Kullanıcı oluşturulamadı.');
        }
        
        // Oturum başlat
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Doğrulama kodunu sil
        $this->delete_verification_code($phone);
        
        // Başarılı giriş logu
        $this->core->log('sos_login_success', [
            'provider' => $this->slug,
            'user_id' => $user_id
        ]);
        
        // Yönlendir
        $redirect_url = isset($request['redirect_to']) 
            ? esc_url_raw($request['redirect_to']) 
            : home_url('/');
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * SMS gönderir
     * 
     * @param string $phone Telefon numarası
     * @param string $code Doğrulama kodu
     * @return bool
     */
    public function send_sms($phone, $code) {
        try {
            // Discord temalı SMS mesajı
            $message = sprintf(
                "🏰 Discord Doğrulama Kodu: %s\n\n" .
                "Bu kodu kimseyle paylaşmayın! Kod 10 dakika geçerlidir.\n" .
                "Discord sunucumuza hoş geldiniz! 🎮",
                $code
            );
            
            // SMS servisine göre gönderim
            switch ($this->sms_service) {
                case 'twilio':
                    return $this->send_sms_twilio($phone, $message);
                    
                case 'vonage':
                    return $this->send_sms_vonage($phone, $message);
                    
                case 'plivo':
                    return $this->send_sms_plivo($phone, $message);
                    
                default:
                    // Custom SMS servisi
                    return $this->send_sms_custom($phone, $message);
            }
            
        } catch (Exception $e) {
            $this->core->log('sms_send_error', [
                'provider' => $this->slug,
                'phone' => substr($phone, 0, 6) . '***',
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Twilio SMS gönderimi
     * 
     * @param string $phone Telefon numarası
     * @param string $message SMS mesajı
     * @return bool
     */
    private function send_sms_twilio($phone, $message) {
        $account_sid = $this->sms_config['account_sid'] ?? '';
        $auth_token = $this->sms_config['auth_token'] ?? '';
        $from_number = $this->sms_config['from_number'] ?? '';
        
        if (empty($account_sid) || empty($auth_token) || empty($from_number)) {
            error_log('[Discord SOS] Twilio yapılandırması eksik');
            return false;
        }
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$account_sid}:{$auth_token}"),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'From' => $from_number,
                'To' => $phone,
                'Body' => $message,
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Discord SOS] Twilio hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['sid']);
    }
    
    /**
     * Vonage (Nexmo) SMS gönderimi
     * 
     * @param string $phone Telefon numarası
     * @param string $message SMS mesajı
     * @return bool
     */
    private function send_sms_vonage($phone, $message) {
        $api_key = $this->sms_config['api_key'] ?? '';
        $api_secret = $this->sms_config['api_secret'] ?? '';
        $from_number = $this->sms_config['from_number'] ?? 'Discord';
        
        if (empty($api_key) || empty($api_secret)) {
            error_log('[Discord SOS] Vonage yapılandırması eksik');
            return false;
        }
        
        $url = 'https://rest.nexmo.com/sms/json';
        
        $response = wp_remote_post($url, [
            'body' => [
                'api_key' => $api_key,
                'api_secret' => $api_secret,
                'from' => $from_number,
                'to' => $phone,
                'text' => $message,
            ],
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Discord SOS] Vonage hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['messages'][0]['status']) && $data['messages'][0]['status'] == '0';
    }
    
    /**
     * Plivo SMS gönderimi
     * 
     * @param string $phone Telefon numarası
     * @param string $message SMS mesajı
     * @return bool
     */
    private function send_sms_plivo($phone, $message) {
        $auth_id = $this->sms_config['auth_id'] ?? '';
        $auth_token = $this->sms_config['auth_token'] ?? '';
        $from_number = $this->sms_config['from_number'] ?? '';
        
        if (empty($auth_id) || empty($auth_token) || empty($from_number)) {
            error_log('[Discord SOS] Plivo yapılandırması eksik');
            return false;
        }
        
        $url = "https://api.plivo.com/v1/Account/{$auth_id}/Message/";
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$auth_id}:{$auth_token}"),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'src' => $from_number,
                'dst' => $phone,
                'text' => $message,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Discord SOS] Plivo hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['message_uuid']);
    }
    
    /**
     * Custom SMS gönderimi
     * 
     * @param string $phone Telefon numarası
     * @param string $message SMS mesajı
     * @return bool
     */
    private function send_sms_custom($phone, $message) {
        $endpoint = $this->sms_config['endpoint'] ?? '';
        $api_key = $this->sms_config['api_key'] ?? '';
        
        if (empty($endpoint) || empty($api_key)) {
            error_log('[Discord SOS] Custom SMS yapılandırması eksik');
            return false;
        }
        
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'phone' => $phone,
                'message' => $message,
                'provider' => $this->slug,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Discord SOS] Custom SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        return wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Giriş formunu gösterir
     * 
     * @param array $request İstek parametreleri
     * @return string
     */
    public function handle_auth($request) {
        $redirect_to = isset($request['redirect_to']) ? esc_url($request['redirect_to']) : '';
        $nonce = wp_create_nonce('ai_community_sos_' . $this->slug);
        
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-discord" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, #3c4b9a);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M40 12C37.5 10.9 34.8 10.1 32 9.7C31.9 9.9 31.5 10.7 31.3 11.2C28.4 10.8 25.5 10.8 22.7 11.2C22.5 10.7 22.1 9.9 22 9.7C19.2 10.1 16.5 10.9 14 12C8.6 20.5 7.2 28.7 7.9 36.8C11.3 39.4 15.6 41 20.1 41.4C21.4 39.7 22.5 37.8 23.3 35.8C21.5 35.2 19.8 34.3 18.2 33.2C18.6 32.9 19 32.6 19.4 32.3C25.4 35.2 32.6 35.2 38.5 32.3C38.9 32.6 39.3 32.9 39.8 33.2C38.2 34.3 36.5 35.2 34.7 35.8C35.5 37.8 36.6 39.7 37.9 41.4C42.4 41 46.7 39.4 50.1 36.8C50.9 27.5 48.6 19.4 40 12Z" fill="white"/>
                        <path d="M20 26C20 28.2 18.2 30 16 30C13.8 30 12 28.2 12 26C12 23.8 13.8 22 16 22C18.2 22 20 23.8 20 26ZM36 26C36 23.8 34.2 22 32 22C29.8 22 28 23.8 28 26C28 28.2 29.8 30 32 30C34.2 30 36 28.2 36 26Z" fill="<?php echo esc_attr($this->brand_color); ?>"/>
                    </svg>
                    <h2>Discord ile Bağlan</h2>
                </div>
                <p class="sos-tagline">Discord sunucumuza hoş geldiniz! Telefon numaranız ile giriş yapın.</p>
            </div>
            
            <div class="sos-body">
                <form method="post" action="" class="sos-form">
                    <?php wp_nonce_field('ai_community_sos_' . $this->slug); ?>
                    <input type="hidden" name="send_code" value="1">
                    <?php if ($redirect_to): ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
                    <?php endif; ?>
                    
                    <div class="sos-field-group">
                        <label for="sos-phone">Telefon Numarası</label>
                        <div class="sos-input-wrapper">
                            <span class="sos-input-icon">📱</span>
                            <input type="tel" 
                                   id="sos-phone" 
                                   name="phone" 
                                   placeholder="+905551234567"
                                   value="<?php echo isset($_POST['phone']) ? esc_attr($_POST['phone']) : ''; ?>"
                                   required
                                   pattern="^\+?[1-9]\d{1,14}$"
                                   title="Lütfen geçerli bir telefon numarası giriniz (+905551234567)">
                        </div>
                        <small>Ülke kodu ile birlikte giriniz (Örnek: +905551234567)</small>
                    </div>
                    
                    <button type="submit" class="sos-submit-btn" style="background-color: <?php echo esc_attr($this->brand_color); ?>;">
                        <span>📨 Doğrulama Kodu Gönder</span>
                    </button>
                </form>
                
                <div class="sos-info-box">
                    <h4>🎮 Discord Topluluğumuza Katılın</h4>
                    <ul>
                        <li>🔊 Sesli sohbet odaları</li>
                        <li>💬 Oyun ve sohbet kanalları</li>
                        <li>🎁 Özel etkinlikler ve çekilişler</li>
                        <li>👥 10.000+ aktif üye</li>
                    </ul>
                </div>
            </div>
            
            <div class="sos-footer">
                <p>Doğrulama kodu SMS ile gönderilecektir. Operatör ücretleri geçerli olabilir.</p>
                <a href="<?php echo esc_url(home_url('/login')); ?>" class="sos-back-link">← Diğer giriş yöntemleri</a>
            </div>
        </div>
        
        <style>
            .ai-community-sos-discord {
                max-width: 480px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .ai-community-sos-discord .sos-header {
                padding: 30px 24px;
                text-align: center;
                color: white;
            }
            
            .ai-community-sos-discord .sos-brand {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 15px;
            }
            
            .ai-community-sos-discord .sos-logo {
                width: 64px;
                height: 64px;
                filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
            }
            
            .ai-community-sos-discord .sos-brand h2 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            
            .ai-community-sos-discord .sos-tagline {
                margin: 10px 0 0;
                font-size: 14px;
                opacity: 0.9;
            }
            
            .ai-community-sos-discord .sos-body {
                padding: 30px 24px;
            }
            
            .ai-community-sos-discord .sos-field-group {
                margin-bottom: 25px;
            }
            
            .ai-community-sos-discord .sos-field-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #2c3e50;
                font-size: 14px;
            }
            
            .ai-community-sos-discord .sos-input-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            
            .ai-community-sos-discord .sos-input-icon {
                position: absolute;
                left: 12px;
                font-size: 18px;
            }
            
            .ai-community-sos-discord .sos-input-wrapper input {
                width: 100%;
                padding: 14px 14px 14px 45px;
                border: 2px solid #e9ecef;
                border-radius: 12px;
                font-size: 16px;
                transition: all 0.3s ease;
            }
            
            .ai-community-sos-discord .sos-input-wrapper input:focus {
                outline: none;
                border-color: var(--brand-color);
                box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.1);
            }
            
            .ai-community-sos-discord .sos-field-group small {
                display: block;
                margin-top: 6px;
                color: #6c757d;
                font-size: 12px;
            }
            
            .ai-community-sos-discord .sos-submit-btn {
                width: 100%;
                padding: 16px;
                border: none;
                border-radius: 12px;
                color: white;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }
            
            .ai-community-sos-discord .sos-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(88, 101, 242, 0.3);
            }
            
            .ai-community-sos-discord .sos-submit-btn:active {
                transform: translateY(0);
            }
            
            .ai-community-sos-discord .sos-info-box {
                margin-top: 30px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 12px;
                border-left: 4px solid var(--brand-color);
            }
            
            .ai-community-sos-discord .sos-info-box h4 {
                margin: 0 0 15px 0;
                color: #2c3e50;
                font-size: 16px;
            }
            
            .ai-community-sos-discord .sos-info-box ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }
            
            .ai-community-sos-discord .sos-info-box li {
                padding: 8px 0;
                color: #495057;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .ai-community-sos-discord .sos-info-box li:before {
                content: "✓";
                color: var(--brand-color);
                font-weight: bold;
            }
            
            .ai-community-sos-discord .sos-footer {
                padding: 20px 24px;
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
                text-align: center;
            }
            
            .ai-community-sos-discord .sos-footer p {
                margin: 0 0 10px 0;
                color: #6c757d;
                font-size: 12px;
            }
            
            .ai-community-sos-discord .sos-back-link {
                color: var(--brand-color);
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
            }
            
            .ai-community-sos-discord .sos-back-link:hover {
                text-decoration: underline;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Doğrulama formunu gösterir
     * 
     * @param string $phone Telefon numarası
     * @return string
     */
    protected function show_verification_form($phone) {
        $redirect_to = isset($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : '';
        $nonce = wp_create_nonce('ai_community_sos_' . $this->slug);
        
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-discord" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, #3c4b9a);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <!-- Aynı Discord SVG -->
                        <path d="M40 12C37.5 10.9 34.8 10.1 32 9.7C31.9 9.9 31.5 10.7 31.3 11.2C28.4 10.8 25.5 10.8 22.7 11.2C22.5 10.7 22.1 9.9 22 9.7C19.2 10.1 16.5 10.9 14 12C8.6 20.5 7.2 28.7 7.9 36.8C11.3 39.4 15.6 41 20.1 41.4C21.4 39.7 22.5 37.8 23.3 35.8C21.5 35.2 19.8 34.3 18.2 33.2C18.6 32.9 19 32.6 19.4 32.3C25.4 35.2 32.6 35.2 38.5 32.3C38.9 32.6 39.3 32.9 39.8 33.2C38.2 34.3 36.5 35.2 34.7 35.8C35.5 37.8 36.6 39.7 37.9 41.4C42.4 41 46.7 39.4 50.1 36.8C50.9 27.5 48.6 19.4 40 12Z" fill="white"/>
                        <path d="M20 26C20 28.2 18.2 30 16 30C13.8 30 12 28.2 12 26C12 23.8 13.8 22 16 22C18.2 22 20 23.8 20 26ZM36 26C36 23.8 34.2 22 32 22C29.8 22 28 23.8 28 26C28 28.2 29.8 30 32 30C34.2 30 36 28.2 36 26Z" fill="<?php echo esc_attr($this->brand_color); ?>"/>
                    </svg>
                    <h2>Doğrulama Kodu</h2>
                </div>
                <p class="sos-tagline"><?php echo esc_html($phone); ?> numarasına kod gönderildi</p>
            </div>
            
            <div class="sos-body">
                <form method="post" action="" class="sos-form">
                    <?php wp_nonce_field('ai_community_sos_' . $this->slug); ?>
                    <input type="hidden" name="verify_code" value="1">
                    <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                    <?php if ($redirect_to): ?>
                        <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
                    <?php endif; ?>
                    
                    <div class="sos-field-group">
                        <label for="sos-code">6 Haneli Doğrulama Kodu</label>
                        <div class="sos-input-wrapper">
                            <span class="sos-input-icon">🔐</span>
                            <input type="text" 
                                   id="sos-code" 
                                   name="code" 
                                   placeholder="123456"
                                   maxlength="6"
                                   pattern="[0-9]{6}"
                                   inputmode="numeric"
                                   autocomplete="off"
                                   required>
                        </div>
                        <small>Telefonunuza gönderilen 6 haneli kodu giriniz</small>
                    </div>
                    
                    <button type="submit" class="sos-submit-btn" style="background-color: <?php echo esc_attr($this->brand_color); ?>;">
                        <span>✅ Doğrula ve Giriş Yap</span>
                    </button>
                </form>
                
                <div class="sos-resend">
                    <p>Kod gelmedi mi?</p>
                    <form method="post" action="" class="sos-resend-form">
                        <?php wp_nonce_field('ai_community_sos_' . $this->slug); ?>
                        <input type="hidden" name="send_code" value="1">
                        <input type="hidden" name="phone" value="<?php echo esc_attr($phone); ?>">
                        <?php if ($redirect_to): ?>
                            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
                        <?php endif; ?>
                        <button type="submit" class="sos-resend-btn" style="color: <?php echo esc_attr($this->brand_color); ?>;">
                            🔄 Tekrar gönder
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="sos-footer">
                <a href="<?php echo esc_url(add_query_arg(['sos_provider' => $this->slug], home_url('/login'))); ?>" class="sos-back-link">
                    ← Telefon numarasını değiştir
                </a>
            </div>
        </div>
        
        <style>
            .ai-community-sos-discord .sos-resend {
                margin-top: 25px;
                text-align: center;
                padding-top: 20px;
                border-top: 1px solid #e9ecef;
            }
            
            .ai-community-sos-discord .sos-resend p {
                margin: 0 0 10px 0;
                color: #6c757d;
                font-size: 14px;
            }
            
            .ai-community-sos-discord .sos-resend-btn {
                background: none;
                border: none;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                text-decoration: underline;
                padding: 5px 10px;
            }
            
            .ai-community-sos-discord .sos-resend-btn:hover {
                text-decoration: none;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Hata mesajı gösterir
     * 
     * @param string $message Hata mesajı
     * @return string
     */
    protected function show_error($message) {
        $request = array_merge($_GET, $_POST);
        
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-discord" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, #3c4b9a);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <!-- Aynı Discord SVG -->
                        <path d="M40 12C37.5 10.9 34.8 10.1 32 9.7C31.9 9.9 31.5 10.7 31.3 11.2C28.4 10.8 25.5 10.8 22.7 11.2C22.5 10.7 22.1 9.9 22 9.7C19.2 10.1 16.5 10.9 14 12C8.6 20.5 7.2 28.7 7.9 36.8C11.3 39.4 15.6 41 20.1 41.4C21.4 39.7 22.5 37.8 23.3 35.8C21.5 35.2 19.8 34.3 18.2 33.2C18.6 32.9 19 32.6 19.4 32.3C25.4 35.2 32.6 35.2 38.5 32.3C38.9 32.6 39.3 32.9 39.8 33.2C38.2 34.3 36.5 35.2 34.7 35.8C35.5 37.8 36.6 39.7 37.9 41.4C42.4 41 46.7 39.4 50.1 36.8C50.9 27.5 48.6 19.4 40 12Z" fill="white"/>
                        <path d="M20 26C20 28.2 18.2 30 16 30C13.8 30 12 28.2 12 26C12 23.8 13.8 22 16 22C18.2 22 20 23.8 20 26ZM36 26C36 23.8 34.2 22 32 22C29.8 22 28 23.8 28 26C28 28.2 29.8 30 32 30C34.2 30 36 28.2 36 26Z" fill="<?php echo esc_attr($this->brand_color); ?>"/>
                    </svg>
                    <h2>Hata Oluştu</h2>
                </div>
            </div>
            
            <div class="sos-body">
                <div class="sos-error-box">
                    <span class="sos-error-icon">⚠️</span>
                    <p><?php echo esc_html($message); ?></p>
                </div>
                
                <div class="sos-actions">
                    <a href="<?php echo esc_url(add_query_arg(['sos_provider' => $this->slug], home_url('/login'))); ?>" class="sos-retry-btn" style="background-color: <?php echo esc_attr($this->brand_color); ?>;">
                        🔄 Tekrar Dene
                    </a>
                    
                    <a href="<?php echo esc_url(home_url('/login')); ?>" class="sos-alt-link">
                        Diğer giriş yöntemlerini dene
                    </a>
                </div>
            </div>
        </div>
        
        <style>
            .ai-community-sos-discord .sos-error-box {
                background: #fef2f2;
                border: 1px solid #fee2e2;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 25px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .ai-community-sos-discord .sos-error-icon {
                font-size: 24px;
            }
            
            .ai-community-sos-discord .sos-error-box p {
                margin: 0;
                color: #991b1b;
                font-size: 15px;
            }
            
            .ai-community-sos-discord .sos-actions {
                display: flex;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .ai-community-sos-discord .sos-retry-btn {
                display: inline-block;
                padding: 14px 24px;
                border-radius: 12px;
                color: white;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
            }
            
            .ai-community-sos-discord .sos-retry-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(88, 101, 242, 0.3);
            }
            
            .ai-community-sos-discord .sos-alt-link {
                color: #6c757d;
                text-decoration: none;
                font-size: 14px;
            }
            
            .ai-community-sos-discord .sos-alt-link:hover {
                text-decoration: underline;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
}