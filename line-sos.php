<?php
/**
 * AI Community Provider: LINE SOS
 * 
 * LINE tabanlı SOS doğrulama provider'ı
 * Kullanıcıların telefon numaralarına SMS göndererek kimlik doğrulama yapar
 * 
 * @package AI_Community
 */

// Direkt erişimi engelle
if (!defined('ABSPATH')) exit;

class AI_Community_Provider_Line_SOS extends AI_Community_SOS_Provider {
    
    /**
     * Provider adı
     * @var string
     */
    protected $name = 'LINE (SOS)';
    
    /**
     * Provider slug
     * @var string
     */
    public $slug = 'line-sos';
    
    /**
     * Provider rengi (LINE Yeşili)
     * @var string
     */
    protected $brand_color = '#06C755';
    
    /**
     * İkincil renk
     * @var string
     */
    protected $secondary_color = '#00B83F';
    
    /**
     * Provider'a özel config
     * @var string
     */
    private $api_key = '';
    
    /**
     * Channel ID
     * @var string
     */
    private $channel_id = '';
    
    /**
     * Channel Secret
     * @var string
     */
    private $channel_secret = '';
    
    /**
     * Constructor - 3 parametreli
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
        
        if (isset($config['channel_id'])) {
            $this->channel_id = $config['channel_id'];
        }
        
        if (isset($config['channel_secret'])) {
            $this->channel_secret = $config['channel_secret'];
        }
    }
    
    /**
     * Ana işleyici - PARAMETRESİZ!
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
            // LINE temalı SMS mesajı
            $message = sprintf(
                "💚 LINE Doğrulama Kodu: %s\n\n" .
                "Bu kod 10 dakika geçerlidir. Arkadaşlarınla sohbete başla! 🗨️",
                $code
            );
            
            // Twilio SMS servisi
            $account_sid = get_option('ai_community_twilio_sid', '');
            $auth_token = get_option('ai_community_twilio_token', '');
            $from_number = get_option('ai_community_sms_from', '');
            
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
        <div class="ai-community-sos-provider ai-community-sos-line" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>; --secondary-color: <?php echo esc_attr($this->secondary_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, <?php echo esc_attr($this->secondary_color); ?>);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="24" r="20" fill="white"/>
                        <path d="M24 6C14.0589 6 6 14.0589 6 24C6 31.5 10.5 38 17 41.5C17 40 17 38 17.5 36.5C18 35 19 31 19 31C18.5 30 18 28.5 18 26.5C18 22 21 19 25 19C28.5 19 31 21.5 31 25C31 29 28.5 32.5 25 32.5C23.5 32.5 22 31.5 21.5 30C21.5 30 20.5 34 20 35.5C19.5 37 18.5 39 17.5 41C19.5 42 22 43 24.5 43C33.9411 43 42 34.9411 42 25C42 15.0589 33.9411 7 24 7V6Z" fill="<?php echo esc_attr($this->brand_color); ?>"/>
                        <circle cx="18" cy="22" r="2" fill="white"/>
                        <circle cx="30" cy="22" r="2" fill="white"/>
                        <path d="M20 28C22 30 26 30 28 28" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h2 style="color: white;">LINE ile Bağlan</h2>
                </div>
                <p class="sos-tagline" style="color: white;">Arkadaşlarınla ücretsiz mesajlaş ve ara! Telefon numaran ile giriş yap.</p>
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
                            <span class="sos-input-icon">💚</span>
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
                    
                    <button type="submit" class="sos-submit-btn" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, <?php echo esc_attr($this->secondary_color); ?>);">
                        <span>💚 Doğrulama Kodu Gönder</span>
                    </button>
                </form>
                
                <div class="sos-info-box">
                    <h4>💚 LINE'da bizi takip edin:</h4>
                    <ul>
                        <li>🗨️ Ücretsiz mesajlaşma ve aramalar</li>
                        <li>🎭 Eğlenceli sticker'lar ve emojiler</li>
                        <li>👥 Grup sohbetleri (500 kişiye kadar)</li>
                        <li>📱 LINE Timeline ve haberler</li>
                    </ul>
                </div>
            </div>
            
            <div class="sos-footer">
                <p>Doğrulama kodu SMS ile gönderilecektir. Operatör ücretleri geçerli olabilir.</p>
                <a href="<?php echo esc_url(home_url('/login')); ?>" class="sos-back-link" style="color: <?php echo esc_attr($this->brand_color); ?>;">← Diğer giriş yöntemleri</a>
            </div>
        </div>
        
        <style>
            .ai-community-sos-line {
                max-width: 480px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                overflow: hidden;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            
            .ai-community-sos-line .sos-header {
                padding: 30px 24px;
                text-align: center;
                color: white;
            }
            
            .ai-community-sos-line .sos-brand {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 15px;
            }
            
            .ai-community-sos-line .sos-logo {
                width: 64px;
                height: 64px;
                filter: drop-shadow(0 4px 8px rgba(0,0,0,0.2));
            }
            
            .ai-community-sos-line .sos-brand h2 {
                margin: 0;
                font-size: 24px;
                font-weight: 600;
            }
            
            .ai-community-sos-line .sos-tagline {
                margin: 10px 0 0;
                font-size: 14px;
                opacity: 0.9;
            }
            
            .ai-community-sos-line .sos-body {
                padding: 30px 24px;
            }
            
            .ai-community-sos-line .sos-field-group {
                margin-bottom: 25px;
            }
            
            .ai-community-sos-line .sos-field-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: #2c3e50;
                font-size: 14px;
            }
            
            .ai-community-sos-line .sos-input-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            
            .ai-community-sos-line .sos-input-icon {
                position: absolute;
                left: 12px;
                font-size: 18px;
            }
            
            .ai-community-sos-line .sos-input-wrapper input {
                width: 100%;
                padding: 14px 14px 14px 45px;
                border: 2px solid #e9ecef;
                border-radius: 12px;
                font-size: 16px;
                transition: all 0.3s ease;
            }
            
            .ai-community-sos-line .sos-input-wrapper input:focus {
                outline: none;
                border-color: var(--brand-color);
                box-shadow: 0 0 0 3px rgba(6, 199, 85, 0.1);
            }
            
            .ai-community-sos-line .sos-field-group small {
                display: block;
                margin-top: 6px;
                color: #6c757d;
                font-size: 12px;
            }
            
            .ai-community-sos-line .sos-submit-btn {
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
            
            .ai-community-sos-line .sos-submit-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(6, 199, 85, 0.3);
            }
            
            .ai-community-sos-line .sos-submit-btn:active {
                transform: translateY(0);
            }
            
            .ai-community-sos-line .sos-info-box {
                margin-top: 30px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 12px;
                border-left: 4px solid var(--brand-color);
            }
            
            .ai-community-sos-line .sos-info-box h4 {
                margin: 0 0 15px 0;
                color: #2c3e50;
                font-size: 16px;
            }
            
            .ai-community-sos-line .sos-info-box ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }
            
            .ai-community-sos-line .sos-info-box li {
                padding: 8px 0;
                color: #495057;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .ai-community-sos-line .sos-info-box li:before {
                content: "💚";
                color: var(--brand-color);
                font-weight: bold;
                margin-right: 8px;
            }
            
            .ai-community-sos-line .sos-footer {
                padding: 20px 24px;
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
                text-align: center;
            }
            
            .ai-community-sos-line .sos-footer p {
                margin: 0 0 10px 0;
                color: #6c757d;
                font-size: 12px;
            }
            
            .ai-community-sos-line .sos-back-link {
                text-decoration: none;
                font-size: 14px;
                font-weight: 500;
            }
            
            .ai-community-sos-line .sos-back-link:hover {
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
        <div class="ai-community-sos-provider ai-community-sos-line" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>; --secondary-color: <?php echo esc_attr($this->secondary_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, <?php echo esc_attr($this->secondary_color); ?>);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <!-- Aynı LINE SVG -->
                        <circle cx="24" cy="24" r="20" fill="white"/>
                        <path d="M24 6C14.0589 6 6 14.0589 6 24C6 31.5 10.5 38 17 41.5C17 40 17 38 17.5 36.5C18 35 19 31 19 31C18.5 30 18 28.5 18 26.5C18 22 21 19 25 19C28.5 19 31 21.5 31 25C31 29 28.5 32.5 25 32.5C23.5 32.5 22 31.5 21.5 30C21.5 30 20.5 34 20 35.5C19.5 37 18.5 39 17.5 41C19.5 42 22 43 24.5 43C33.9411 43 42 34.9411 42 25C42 15.0589 33.9411 7 24 7V6Z" fill="<?php echo esc_attr($this->brand_color); ?>"/>
                        <circle cx="18" cy="22" r="2" fill="white"/>
                        <circle cx="30" cy="22" r="2" fill="white"/>
                        <path d="M20 28C22 30 26 30 28 28" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h2 style="color: white;">Doğrulama Kodu</h2>
                </div>
                <p class="sos-tagline" style="color: white;"><?php echo esc_html($phone); ?> numarasına kod gönderildi</p>
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
                    
                    <button type="submit" class="sos-submit-btn" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, <?php echo esc_attr($this->secondary_color); ?>);">
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
                <a href="<?php echo esc_url(add_query_arg(['sos_provider' => $this->slug], home_url('/login'))); ?>" class="sos-back-link" style="color: <?php echo esc_attr($this->brand_color); ?>;">
                    ← Telefon numarasını değiştir
                </a>
            </div>
        </div>
        
        <style>
            .ai-community-sos-line .sos-resend {
                margin-top: 25px;
                text-align: center;
                padding-top: 20px;
                border-top: 1px solid #e9ecef;
            }
            
            .ai-community-sos-line .sos-resend p {
                margin: 0 0 10px 0;
                color: #6c757d;
                font-size: 14px;
            }
            
            .ai-community-sos-line .sos-resend-btn {
                background: none;
                border: none;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
                text-decoration: underline;
                padding: 5px 10px;
            }
            
            .ai-community-sos-line .sos-resend-btn:hover {
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
        ob_start();
        ?>
        <div class="ai-community-sos-provider ai-community-sos-line" style="--brand-color: <?php echo esc_attr($this->brand_color); ?>; --secondary-color: <?php echo esc_attr($this->secondary_color); ?>;">
            <div class="sos-header" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, <?php echo esc_attr($this->secondary_color); ?>);">
                <div class="sos-brand">
                    <svg class="sos-logo" width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <!-- Aynı LINE SVG -->
                        <circle cx="24" cy="24" r="20" fill="white"/>
                        <path d="M24 6C14.0589 6 6 14.0589 6 24C6 31.5 10.5 38 17 41.5C17 40 17 38 17.5 36.5C18 35 19 31 19 31C18.5 30 18 28.5 18 26.5C18 22 21 19 25 19C28.5 19 31 21.5 31 25C31 29 28.5 32.5 25 32.5C23.5 32.5 22 31.5 21.5 30C21.5 30 20.5 34 20 35.5C19.5 37 18.5 39 17.5 41C19.5 42 22 43 24.5 43C33.9411 43 42 34.9411 42 25C42 15.0589 33.9411 7 24 7V6Z" fill="<?php echo esc_attr($this->brand_color); ?>"/>
                        <circle cx="18" cy="22" r="2" fill="white"/>
                        <circle cx="30" cy="22" r="2" fill="white"/>
                        <path d="M20 28C22 30 26 30 28 28" stroke="white" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h2 style="color: white;">Hata Oluştu</h2>
                </div>
            </div>
            
            <div class="sos-body">
                <div class="sos-error-box">
                    <span class="sos-error-icon">⚠️</span>
                    <p><?php echo esc_html($message); ?></p>
                </div>
                
                <div class="sos-actions">
                    <a href="<?php echo esc_url(add_query_arg(['sos_provider' => $this->slug], home_url('/login'))); ?>" class="sos-retry-btn" style="background: linear-gradient(135deg, <?php echo esc_attr($this->brand_color); ?>, <?php echo esc_attr($this->secondary_color); ?>);">
                        🔄 Tekrar Dene
                    </a>
                    
                    <a href="<?php echo esc_url(home_url('/login')); ?>" class="sos-alt-link">
                        Diğer giriş yöntemlerini dene
                    </a>
                </div>
            </div>
        </div>
        
        <style>
            .ai-community-sos-line .sos-error-box {
                background: #fef2f2;
                border: 1px solid #fee2e2;
                border-radius: 12px;
                padding: 20px;
                margin-bottom: 25px;
                display: flex;
                align-items: center;
                gap: 15px;
            }
            
            .ai-community-sos-line .sos-error-icon {
                font-size: 24px;
            }
            
            .ai-community-sos-line .sos-error-box p {
                margin: 0;
                color: #991b1b;
                font-size: 15px;
            }
            
            .ai-community-sos-line .sos-actions {
                display: flex;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .ai-community-sos-line .sos-retry-btn {
                display: inline-block;
                padding: 14px 24px;
                border-radius: 12px;
                color: white;
                text-decoration: none;
                font-weight: 600;
                transition: all 0.3s ease;
            }
            
            .ai-community-sos-line .sos-retry-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(6, 199, 85, 0.3);
            }
            
            .ai-community-sos-line .sos-alt-link {
                color: #6c757d;
                text-decoration: none;
                font-size: 14px;
            }
            
            .ai-community-sos-line .sos-alt-link:hover {
                text-decoration: underline;
            }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Zorunlu Getter Metodlar
     */
    public function get_type() { return 'sos'; }
    public function get_name() { return $this->name; }
    public function get_slug() { return $this->slug; }
    public function get_label() { return $this->name; }
    
    /**
     * Provider'ın aktif olup olmadığını kontrol eder
     * 
     * @return bool
     */
    public function is_active() {
        if (empty($this->api_key)) {
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
        $status['channel_id_configured'] = !empty($this->channel_id);
        $status['channel_secret_configured'] = !empty($this->channel_secret);
        
        return $status;
    }
}