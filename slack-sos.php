<?php
/**
 * AI Community Provider: Slack SOS
 * 
 * Slack tabanlı SOS doğrulama provider'ı
 * Kullanıcıların telefon numaralarına SMS göndererek kimlik doğrulama yapar
 * 
 * @package AI_Community
 * @since 1.0.0
 */

// Direkt erişimi engelle
if (!defined('ABSPATH')) exit;

class AI_Community_Provider_Slack_SOS extends AI_Community_SOS_Provider {
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'Slack (SOS)';
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'slack-sos';
    
    /**
     * Provider rengi (Slack Moru)
     * @var string
     */
    protected $brand_color = '#4A154B';
    
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
    private $api_endpoint = 'https://slack.com/api';
    
    /**
     * SMS servisi tipi
     * @var string
     */
    private $sms_service = 'twilio';
    
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
        if (empty($this->api_key)) {
            return false;
        }
        
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
        
        if (isset($request['code']) || isset($request['phone']) || isset($request['verify_code'])) {
            return $this->handle_callback($request);
        }
        
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
            if (isset($request['_wpnonce']) && !wp_verify_nonce($request['_wpnonce'], 'ai_community_sos_' . $this->slug)) {
                throw new Exception('Güvenlik doğrulaması başarısız oldu.');
            }
            
            if (isset($request['send_code']) && isset($request['phone'])) {
                return $this->send_verification_code($request);
            }
            
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
        
        if (!$phone) {
            return $this->show_error('Geçerli bir telefon numarası giriniz.');
        }
        
        if (!$this->check_rate_limit($phone)) {
            return $this->show_error('Çok fazla deneme yaptınız. Lütfen 1 saat sonra tekrar deneyiniz.');
        }
        
        $code = wp_rand(100000, 999999);
        $this->save_verification_code($phone, $code);
        
        $sent = $this->send_sms($phone, $code);
        
        if (!$sent) {
            return $this->show_error('SMS gönderilemedi. Lütfen daha sonra tekrar deneyiniz.');
        }
        
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
        
        if (!$this->verify_code($phone, $code)) {
            return $this->show_error('Doğrulama kodu geçersiz veya süresi dolmuş.');
        }
        
        $profile = $this->create_profile_from_phone($phone);
        
        if (!$profile) {
            return $this->show_error('Profil oluşturulamadı.');
        }
        
        $user_id = $this->find_or_create_user($profile);
        
        if (!$user_id || is_wp_error($user_id)) {
            return $this->show_error('Kullanıcı oluşturulamadı.');
        }
        
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        $this->delete_verification_code($phone);
        
        $this->core->log('sos_login_success', [
            'provider' => $this->slug,
            'user_id' => $user_id
        ]);
        
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
            // Slack temalı SMS mesajı
            $message = sprintf(
                "📧 Slack Doğrulama Kodu: %s\n\n" .
                "Bu kodu kimseyle paylaşmayın! Kod 10 dakika geçerlidir.\n" .
                "Slack çalışma alanımıza hoş geldiniz! 👋",
                $code
            );
            
            switch ($this->sms_service) {
                case 'twilio':
                    return $this->send_sms_twilio($phone, $message);
                case 'vonage':
                    return $this->send_sms_vonage($phone, $message);
                case 'plivo':
                    return $this->send_sms_plivo($phone, $message);
                default:
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
     */
    private function send_sms_twilio($phone, $message) {
        $account_sid = $this->sms_config['account_sid'] ?? '';
        $auth_token = $this->sms_config['auth_token'] ?? '';
        $from_number = $this->sms_config['from_number'] ?? '';
        
        if (empty($account_sid) || empty($auth_token) || empty($from_number)) {
            error_log('[' . $this->slug . '] Twilio yapılandırması eksik');
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
            error_log('[' . $this->slug . '] Twilio hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['sid']);
    }
    
    /**
     * Vonage SMS gönderimi
     */
    private function send_sms_vonage($phone, $message) {
        $api_key = $this->sms_config['api_key'] ?? '';
        $api_secret = $this->sms_config['api_secret'] ?? '';
        $from_number = $this->sms_config['from_number'] ?? 'Slack';
        
        if (empty($api_key) || empty($api_secret)) {
            error_log('[' . $this->slug . '] Vonage yapılandırması eksik');
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
            error_log('[' . $this->slug . '] Vonage hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['messages'][0]['status']) && $data['messages'][0]['status'] == '0';
    }
    
    /**
     * Plivo SMS gönderimi
     */
    private function send_sms_plivo($phone, $message) {
        $auth_id = $this->sms_config['auth_id'] ?? '';
        $auth_token = $this->sms_config['auth_token'] ?? '';
        $from_number = $this->sms_config['from_number'] ?? '';
        
        if (empty($auth_id) || empty($auth_token) || empty($from_number)) {
            error_log('[' . $this->slug . '] Plivo yapılandırması eksik');
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
            error_log('[' . $this->slug . '] Plivo hatası: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['message_uuid']);
    }
    
    /**
     * Custom SMS gönderimi
     */
    private function send_sms_custom($phone, $message) {
        $endpoint = $this->sms_config['endpoint'] ?? '';
        $api_key = $this->sms_config['api_key'] ?? '';
        
        if (empty($endpoint) || empty($api_key)) {
            error_log('[' . $this->slug . '] Custom SMS yapılandırması eksik');
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
            error_log('[' . $this->slug . '] Custom SMS hatası: ' . $response->get_error_message());
            return false;
        }
        
        return wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Giriş formunu gösterir
     */
    public function handle_auth($request) {
        $redirect_to = isset($request['redirect_to']) ? esc_url($request['redirect_to']) : '';
        $nonce = wp_create_nonce('ai_community_sos_' . $this->slug);
        
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-slack" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, #611f69);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 28C14.2091 28 16 26.2091 16 24C16 21.7909 14.2091 20 12 20C9.79086 20 8 21.7909 8 24C8 26.2091 9.79086 28 12 28Z" fill="#36C5F0"/>
                        <path d="M20 28H36C38.2091 28 40 26.2091 40 24C40 21.7909 38.2091 20 36 20H20C17.7909 20 16 21.7909 16 24C16 26.2091 17.7909 28 20 28Z" fill="#2EB67D"/>
                        <path d="M36 40C38.2091 40 40 38.2091 40 36C40 33.7909 38.2091 32 36 32C33.7909 32 32 33.7909 32 36C32 38.2091 33.7909 40 36 40Z" fill="#ECB22E"/>
                        <path d="M36 16V0C36 0 36 0 36 0C36 0 36 0 36 0C36 2.20914 34.2091 4 32 4C29.7909 4 28 5.79086 28 8C28 10.2091 29.7909 12 32 12H36V16Z" fill="#E01E5A"/>
                        <path d="M12 16C14.2091 16 16 14.2091 16 12C16 9.79086 14.2091 8 12 8C9.79086 8 8 9.79086 8 12C8 14.2091 9.79086 16 12 16Z" fill="#E01E5A"/>
                        <path d="M20 8H36C38.2091 8 40 6.20914 40 4C40 1.79086 38.2091 0 36 0H20C17.7909 0 16 1.79086 16 4C16 6.20914 17.7909 8 20 8Z" fill="#36C5F0"/>
                        <path d="M12 32C14.2091 32 16 33.7909 16 36C16 38.2091 14.2091 40 12 40C9.79086 40 8 38.2091 8 36C8 33.7909 9.79086 32 12 32Z" fill="#2EB67D"/>
                        <path d="M12 48H28C30.2091 48 32 46.2091 32 44C32 41.7909 30.2091 40 28 40H12C9.79086 40 8 41.7909 8 44C8 46.2091 9.79086 48 12 48Z" fill="#ECB22E"/>
                    </svg>
                    <h2>Slack ile Bağlan</h2>
                </div>
                <p class="sos-tagline">Çalışma alanımıza hoş geldiniz! Telefon numaranız ile giriş yapın.</p>
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
                    <h4>👥 Slack Topluluğumuza Katılın</h4>
                    <ul>
                        <li>💬 Takım içi iletişim kanalları</li>
                        <li>📊 Proje yönetimi ve entegrasyonlar</li>
                        <li>🎯 Özel interest grupları</li>
                        <li>🤝 5.000+ aktif üye</li>
                    </ul>
                </div>
            </div>
            
            <div class="sos-footer">
                <p>Doğrulama kodu SMS ile gönderilecektir. Operatör ücretleri geçerli olabilir.</p>
                <a href="<?php echo esc_url(home_url('/login')); ?>" class="sos-back-link">← Diğer giriş yöntemleri</a>
            </div>
        </div>
        
        <style>
            .ai-community-sos-slack {
                max-width: 480px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .ai-community-sos-slack .sos-header {
                padding: 30px 24px;
                text-align: center;
                color: white;
            }
            
            .ai-community-sos-slack .sos-brand {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 15px;
            }
            
            .ai-community-sos-slack .sos-logo {
                width: 64px;
                height: 64px;
                filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
            }
            
            .ai-community-sos-slack .sos-brand h2 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            
            .ai-community-sos-slack .sos-tagline {
                margin: 10px 0 0;
                font-size: 14px;
                opacity: 0.9;
            }
            
            .ai-community-sos-slack .sos-body {
                padding: 30px 24px;
            }
            
            .ai-community-sos-slack .sos-field-group {
                margin-bottom: 25px;
            }
            
            .ai-community-sos-slack .sos-field-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #2c3e50;
                font-size: 14px;
            }
            
            .ai-community-sos-slack .sos-input-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            
            .ai-community-sos-slack .sos-input-icon {
                position: absolute;
                left: 12px;
                font-size: 18px;
            }
            
            .ai-community-sos-slack .sos-input-wrapper input {
                width: 100%;
                padding: 14px 14px 14px 45px;
                border: 2px solid #e9ecef;
                border-radius: 12px;
                font-size: 16px;
                transition: all 0.3s ease;
            }
            
            .ai-community-sos-slack .sos-input-wrapper input:focus {
                outline: none;
                border-color: var(--brand-color);
                box-shadow: 0 0 0 3px rgba(74, 21, 75, 0.1);
            }
            
            .ai-community-sos-slack .sos-field-group small {
                display: block;
                margin-top: 6px;
                color: #6c757d;
                font-size: 12px;
            }
            
            .ai-community-sos-slack .sos-submit-btn {
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
            
            .ai-community-sos-slack .sos-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(74, 21, 75, 0.3);
            }
            
            .ai-community-sos-slack .sos-submit-btn:active {
                transform: translateY(0);
            }
            
            .ai-community-sos-slack .sos-info-box {
                margin-top: 30px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 12px;
                border-left: 4px solid var(--brand-color);
            }
            
            .ai-community-sos-slack .sos-info-box h4 {
                margin: 0 0 15px 0;
                color: #2c3e50;
                font-size: 16px;
            }
            
            .ai-community-sos-slack .sos-info-box ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }
            
            .ai-community-sos-slack .sos-info-box li {
                padding: 8px 0;
                color: #495057;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .ai-community-sos-slack .sos-info-box li:before {
                content: "✓";
                color: var(--brand-color);
                font-weight: bold;
            }
            
            .ai-community-sos-slack .sos-footer {
                padding: 20px 24px;
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
                text-align: center;
            }
            
            .ai-community-sos-slack .sos-footer p {
                margin: 0 0 10px 0;
                color: #6c757d;
                font-size: 12px;
            }
            
            .ai-community-sos-slack .sos-back-link {
                color: var(--brand-color);
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
            }
            
            .ai-community-sos-slack .sos-back-link:hover {
                text-decoration: underline;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Doğrulama formunu gösterir
     */
    protected function show_verification_form($phone) {
        $redirect_to = isset($_GET['redirect_to']) ? esc_url($_GET['redirect_to']) : '';
        $nonce = wp_create_nonce('ai_community_sos_' . $this->slug);
        
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-slack" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, #611f69);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <!-- Aynı Slack SVG -->
                        <path d="M12 28C14.2091 28 16 26.2091 16 24C16 21.7909 14.2091 20 12 20C9.79086 20 8 21.7909 8 24C8 26.2091 9.79086 28 12 28Z" fill="#36C5F0"/>
                        <path d="M20 28H36C38.2091 28 40 26.2091 40 24C40 21.7909 38.2091 20 36 20H20C17.7909 20 16 21.7909 16 24C16 26.2091 17.7909 28 20 28Z" fill="#2EB67D"/>
                        <path d="M36 40C38.2091 40 40 38.2091 40 36C40 33.7909 38.2091 32 36 32C33.7909 32 32 33.7909 32 36C32 38.2091 33.7909 40 36 40Z" fill="#ECB22E"/>
                        <path d="M36 16V0C36 0 36 0 36 0C36 0 36 0 36 0C36 2.20914 34.2091 4 32 4C29.7909 4 28 5.79086 28 8C28 10.2091 29.7909 12 32 12H36V16Z" fill="#E01E5A"/>
                        <path d="M12 16C14.2091 16 16 14.2091 16 12C16 9.79086 14.2091 8 12 8C9.79086 8 8 9.79086 8 12C8 14.2091 9.79086 16 12 16Z" fill="#E01E5A"/>
                        <path d="M20 8H36C38.2091 8 40 6.20914 40 4C40 1.79086 38.2091 0 36 0H20C17.7909 0 16 1.79086 16 4C16 6.20914 17.7909 8 20 8Z" fill="#36C5F0"/>
                        <path d="M12 32C14.2091 32 16 33.7909 16 36C16 38.2091 14.2091 40 12 40C9.79086 40 8 38.2091 8 36C8 33.7909 9.79086 32 12 32Z" fill="#2EB67D"/>
                        <path d="M12 48H28C30.2091 48 32 46.2091 32 44C32 41.7909 30.2091 40 28 40H12C9.79086 40 8 41.7909 8 44C8 46.2091 9.79086 48 12 48Z" fill="#ECB22E"/>
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
            .ai-community-sos-slack .sos-resend {
                margin-top: 25px;
                text-align: center;
                padding-top: 20px;
                border-top: 1px solid #e9ecef;
            }
            
            .ai-community-sos-slack .sos-resend p {
                margin: 0 0 10px 0;
                color: #6c757d;
                font-size: 14px;
            }
            
            .ai-community-sos-slack .sos-resend-btn {
                background: none;
                border: none;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                text-decoration: underline;
                padding: 5px 10px;
            }
            
            .ai-community-sos-slack .sos-resend-btn:hover {
                text-decoration: none;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Hata mesajı gösterir
     */
    protected function show_error($message) {
        $request = array_merge($_GET, $_POST);
        
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-slack" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, #611f69);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <!-- Aynı Slack SVG -->
                        <path d="M12 28C14.2091 28 16 26.2091 16 24C16 21.7909 14.2091 20 12 20C9.79086 20 8 21.7909 8 24C8 26.2091 9.79086 28 12 28Z" fill="#36C5F0"/>
                        <path d="M20 28H36C38.2091 28 40 26.2091 40 24C40 21.7909 38.2091 20 36 20H20C17.7909 20 16 21.7909 16 24C16 26.2091 17.7909 28 20 28Z" fill="#2EB67D"/>
                        <path d="M36 40C38.2091 40 40 38.2091 40 36C40 33.7909 38.2091 32 36 32C33.7909 32 32 33.7909 32 36C32 38.2091 33.7909 40 36 40Z" fill="#ECB22E"/>
                        <path d="M36 16V0C36 0 36 0 36 0C36 0 36 0 36 0C36 2.20914 34.2091 4 32 4C29.7909 4 28 5.79086 28 8C28 10.2091 29.7909 12 32 12H36V16Z" fill="#E01E5A"/>
                        <path d="M12 16C14.2091 16 16 14.2091 16 12C16 9.79086 14.2091 8 12 8C9.79086 8 8 9.79086 8 12C8 14.2091 9.79086 16 12 16Z" fill="#E01E5A"/>
                        <path d="M20 8H36C38.2091 8 40 6.20914 40 4C40 1.79086 38.2091 0 36 0H20C17.7909 0 16 1.79086 16 4C16 6.20914 17.7909 8 20 8Z" fill="#36C5F0"/>
                        <path d="M12 32C14.2091 32 16 33.7909 16 36C16 38.2091 14.2091 40 12 40C9.79086 40 8 38.2091 8 36C8 33.7909 9.79086 32 12 32Z" fill="#2EB67D"/>
                        <path d="M12 48H28C30.2091 48 32 46.2091 32 44C32 41.7909 30.2091 40 28 40H12C9.79086 40 8 41.7909 8 44C8 46.2091 9.79086 48 12 48Z" fill="#ECB22E"/>
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
            .ai-community-sos-slack .sos-error-box {
                background: #fef2f2;
                border: 1px solid #fee2e2;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 25px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .ai-community-sos-slack .sos-error-icon {
                font-size: 24px;
            }
            
            .ai-community-sos-slack .sos-error-box p {
                margin: 0;
                color: #991b1b;
                font-size: 15px;
            }
            
            .ai-community-sos-slack .sos-actions {
                display: flex;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .ai-community-sos-slack .sos-retry-btn {
                display: inline-block;
                padding: 14px 24px;
                border-radius: 12px;
                color: white;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
            }
            
            .ai-community-sos-slack .sos-retry-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(74, 21, 75, 0.3);
            }
            
            .ai-community-sos-slack .sos-alt-link {
                color: #6c757d;
                text-decoration: none;
                font-size: 14px;
            }
            
            .ai-community-sos-slack .sos-alt-link:hover {
                text-decoration: underline;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
}