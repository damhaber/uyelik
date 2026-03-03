<?php
// masal-panel/modules/ai-community-engine/core/providers/qr.php
// QR KOD İLE GİRİŞ PROVIDER

if (!defined('ABSPATH') || !defined('MASAL_PANEL_SECURE_TOKEN')) {
    exit("⛔ Erişim reddedildi");
}

require_once __DIR__ . '/provider-base.php';

class AI_Community_Provider_QR extends AI_Community_Provider_Base {
    
    protected $id = 'qr';
    protected $label = 'QR Kod ile Giriş';
    
    private $session_duration = 300; // 5 dakika
    private $qr_size = 300;
    
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
    }
    
    /**
     * 🔧 ZORUNLU METOD - handle()
     * QR provider isteğini yönet
     */
    public function handle() {
        $action = isset($_GET['qr_action']) ? sanitize_text_field($_GET['qr_action']) : '';
        
        switch ($action) {
            case 'generate':
                $this->handle_generate();
                break;
                
            case 'check':
                $this->handle_check();
                break;
                
            case 'scan':
                $this->handle_scan();
                break;
                
            case 'confirm':
                $this->handle_confirm();
                break;
                
            case 'cancel':
                $this->handle_cancel();
                break;
                
            default:
                $this->render_qr_page();
                break;
        }
    }
    
    /**
     * QR kod oluştur
     */
    private function handle_generate() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('Giriş yapmalısınız');
            return;
        }
        
        // Benzersiz session ID oluştur
        $session_id = wp_generate_password(32, false);
        $expires = time() + $this->session_duration;
        
        // Session verilerini kaydet
        $session_data = [
            'session_id' => $session_id,
            'user_id' => $user_id,
            'created' => time(),
            'expires' => $expires,
            'status' => 'pending', // pending, scanned, confirmed, cancelled
            'device_info' => $this->get_device_info(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        
        update_option('ai_comm_qr_' . $session_id, $session_data, false);
        
        // QR kod içeriği
        $qr_content = json_encode([
            'type' => 'login',
            'session' => $session_id,
            'site' => home_url(),
            'expires' => $expires,
            'version' => '1.0'
        ]);
        
        // QR kod URL'si (Google Charts API - ücretsiz)
        $qr_url = 'https://chart.googleapis.com/chart?chs=' . $this->qr_size . 'x' . $this->qr_size . 
                  '&cht=qr&chl=' . urlencode($qr_content) . '&choe=UTF-8';
        
        wp_send_json_success([
            'session_id' => $session_id,
            'qr_url' => $qr_url,
            'expires' => $expires,
            'expires_in' => $this->session_duration
        ]);
    }
    
    /**
     * QR kod durumunu kontrol et
     */
    private function handle_check() {
        $session_id = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
        
        if (empty($session_id)) {
            wp_send_json_error('Geçersiz session');
            return;
        }
        
        $session = get_option('ai_comm_qr_' . $session_id);
        
        if (!$session) {
            wp_send_json_error('Session bulunamadı');
            return;
        }
        
        // Süre kontrolü
        if (time() > $session['expires']) {
            delete_option('ai_comm_qr_' . $session_id);
            wp_send_json_error('Session süresi doldu');
            return;
        }
        
        wp_send_json_success([
            'status' => $session['status'],
            'device_info' => $session['status'] === 'scanned' ? $session['device_info'] : null
        ]);
    }
    
    /**
     * QR kod okuma (mobil taraf)
     */
    private function handle_scan() {
        $session_id = isset($_POST['session']) ? sanitize_text_field($_POST['session']) : '';
        
        if (empty($session_id)) {
            wp_send_json_error('Geçersiz QR kod');
            return;
        }
        
        $session = get_option('ai_comm_qr_' . $session_id);
        
        if (!$session) {
            wp_send_json_error('Geçersiz veya süresi dolmuş QR kod');
            return;
        }
        
        // Süre kontrolü
        if (time() > $session['expires']) {
            delete_option('ai_comm_qr_' . $session_id);
            wp_send_json_error('QR kod süresi doldu');
            return;
        }
        
        // Zaten işlem görmüş mü?
        if ($session['status'] !== 'pending') {
            wp_send_json_error('Bu QR kod zaten kullanılmış');
            return;
        }
        
        // Geçici token oluştur
        $scan_token = wp_generate_password(32, false);
        
        // Session'ı güncelle
        $session['status'] = 'scanned';
        $session['scan_time'] = time();
        $session['scan_token'] = $scan_token;
        $session['scanner_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $session['scanner_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        update_option('ai_comm_qr_' . $session_id, $session, false);
        
        // Mobil için token gönder
        wp_send_json_success([
            'session_id' => $session_id,
            'token' => $scan_token,
            'user' => [
                'name' => get_userdata($session['user_id'])->display_name,
                'avatar' => get_avatar_url($session['user_id'])
            ]
        ]);
    }
    
    /**
     * QR kod onaylama (mobil taraf)
     */
    private function handle_confirm() {
        $session_id = isset($_POST['session']) ? sanitize_text_field($_POST['session']) : '';
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        if (empty($session_id) || empty($token)) {
            wp_send_json_error('Geçersiz istek');
            return;
        }
        
        $session = get_option('ai_comm_qr_' . $session_id);
        
        if (!$session || $session['status'] !== 'scanned' || $session['scan_token'] !== $token) {
            wp_send_json_error('Doğrulama başarısız');
            return;
        }
        
        // Süre kontrolü
        if (time() > $session['expires']) {
            delete_option('ai_comm_qr_' . $session_id);
            wp_send_json_error('Session süresi doldu');
            return;
        }
        
        // Onayla
        $session['status'] = 'confirmed';
        $session['confirm_time'] = time();
        update_option('ai_comm_qr_' . $session_id, $session, false);
        
        // Kullanıcı için giriş token'ı oluştur (web tarafının kullanacağı)
        $login_token = wp_generate_password(64, false);
        set_transient('ai_comm_qr_login_' . $login_token, $session['user_id'], 60);
        
        wp_send_json_success([
            'login_token' => $login_token
        ]);
    }
    
    /**
     * QR kod iptal etme
     */
    private function handle_cancel() {
        $session_id = isset($_POST['session']) ? sanitize_text_field($_POST['session']) : '';
        
        if (empty($session_id)) {
            wp_send_json_error('Geçersiz session');
            return;
        }
        
        $session = get_option('ai_comm_qr_' . $session_id);
        
        if ($session) {
            $session['status'] = 'cancelled';
            update_option('ai_comm_qr_' . $session_id, $session, false);
            
            // Kısa süre sonra sil
            wp_schedule_single_event(time() + 60, 'ai_comm_qr_cleanup', [$session_id]);
        }
        
        wp_send_json_success();
    }
    
    /**
     * QR kod ile giriş (web tarafı)
     */
    public function login_with_token($token) {
        $user_id = get_transient('ai_comm_qr_login_' . $token);
        
        if (!$user_id) {
            return new WP_Error('invalid_token', 'Geçersiz veya süresi dolmuş token');
        }
        
        // Token'ı hemen sil (tek kullanımlık)
        delete_transient('ai_comm_qr_login_' . $token);
        
        // Kullanıcıyı bul
        $user = get_user_by('ID', $user_id);
        
        if (!$user) {
            return new WP_Error('invalid_user', 'Kullanıcı bulunamadı');
        }
        
        // Giriş yap
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        
        // Son giriş zamanını güncelle
        update_user_meta($user->ID, 'ai_comm_last_login', time());
        update_user_meta($user->ID, 'ai_comm_last_login_method', 'qr');
        
        $this->log_event('qr_login_success', [
            'user_id' => $user->ID,
            'method' => 'qr_code'
        ]);
        
        return $user;
    }
    
    /**
     * QR kod sayfasını render et
     */
    private function render_qr_page() {
        $user_id = get_current_user_id();
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('QR Kod ile Giriş', 'ai-community-engine'); ?></title>
            <?php wp_head(); ?>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                
                body {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .qr-container {
                    max-width: 500px;
                    width: 100%;
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(10px);
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    padding: 40px;
                    text-align: center;
                }
                
                .qr-header {
                    margin-bottom: 30px;
                }
                
                .qr-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                    animation: pulse 2s infinite;
                }
                
                @keyframes pulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                }
                
                .qr-title {
                    color: #23282d;
                    font-size: 28px;
                    font-weight: 600;
                    margin-bottom: 10px;
                }
                
                .qr-subtitle {
                    color: #6c757d;
                    font-size: 16px;
                }
                
                .qr-display {
                    margin: 30px 0;
                    padding: 20px;
                    background: white;
                    border-radius: 10px;
                    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
                }
                
                .qr-code {
                    max-width: 300px;
                    margin: 0 auto;
                    border: 10px solid white;
                    border-radius: 10px;
                }
                
                .qr-code img {
                    width: 100%;
                    height: auto;
                }
                
                .qr-timer {
                    margin: 20px 0;
                    font-size: 18px;
                    color: #495057;
                }
                
                .timer-display {
                    font-size: 24px;
                    font-weight: 600;
                    color: #667eea;
                    font-family: monospace;
                }
                
                .qr-status {
                    margin: 20px 0;
                    padding: 15px;
                    border-radius: 10px;
                    display: none;
                }
                
                .status-pending {
                    background: #fff3cd;
                    color: #856404;
                    border-left: 4px solid #ffc107;
                }
                
                .status-scanned {
                    background: #d1ecf1;
                    color: #0c5460;
                    border-left: 4px solid #17a2b8;
                }
                
                .status-success {
                    background: #d4edda;
                    color: #155724;
                    border-left: 4px solid #28a745;
                }
                
                .status-error {
                    background: #f8d7da;
                    color: #721c24;
                    border-left: 4px solid #dc3545;
                }
                
                .device-info {
                    background: #f8f9fa;
                    border-radius: 10px;
                    padding: 15px;
                    margin: 20px 0;
                    text-align: left;
                }
                
                .device-info-item {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    margin-bottom: 10px;
                }
                
                .device-info-item:last-child {
                    margin-bottom: 0;
                }
                
                .btn {
                    padding: 12px 30px;
                    border-radius: 50px;
                    font-size: 16px;
                    font-weight: 500;
                    border: none;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    text-decoration: none;
                    display: inline-block;
                }
                
                .btn-primary {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
                }
                
                .btn-primary:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
                }
                
                .btn-secondary {
                    background: white;
                    color: #667eea;
                    border: 2px solid #667eea;
                }
                
                .btn-secondary:hover {
                    background: #f8f9fa;
                    transform: translateY(-2px);
                }
                
                .btn-danger {
                    background: #dc3545;
                    color: white;
                }
                
                .btn-danger:hover {
                    background: #c82333;
                }
                
                .btn:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
                
                .qr-steps {
                    display: flex;
                    justify-content: space-between;
                    margin: 30px 0;
                    position: relative;
                }
                
                .qr-steps::before {
                    content: '';
                    position: absolute;
                    top: 25px;
                    left: 50px;
                    right: 50px;
                    height: 2px;
                    background: #e9ecef;
                    z-index: 1;
                }
                
                .step {
                    position: relative;
                    z-index: 2;
                    background: white;
                    padding: 10px;
                    border-radius: 50%;
                    width: 50px;
                    height: 50px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-weight: 600;
                    color: #6c757d;
                    border: 2px solid #e9ecef;
                }
                
                .step.active {
                    background: #667eea;
                    color: white;
                    border-color: #667eea;
                }
                
                .step.completed {
                    background: #28a745;
                    color: white;
                    border-color: #28a745;
                }
                
                .step-label {
                    position: absolute;
                    bottom: -25px;
                    left: 50%;
                    transform: translateX(-50%);
                    font-size: 12px;
                    white-space: nowrap;
                    color: #6c757d;
                }
                
                .alternative-methods {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #e9ecef;
                }
                
                .alternative-links {
                    display: flex;
                    gap: 15px;
                    justify-content: center;
                    flex-wrap: wrap;
                    margin-top: 15px;
                }
                
                .info-box {
                    background: #e7f3ff;
                    border-left: 4px solid #2196F3;
                    padding: 15px;
                    border-radius: 5px;
                    margin-top: 20px;
                    text-align: left;
                }
                
                .loader {
                    border: 3px solid #f3f3f3;
                    border-top: 3px solid #667eea;
                    border-radius: 50%;
                    width: 40px;
                    height: 40px;
                    animation: spin 1s linear infinite;
                    margin: 20px auto;
                    display: none;
                }
                
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body>
            <div class="qr-container">
                <div class="qr-header">
                    <div class="qr-icon">📱</div>
                    <h1 class="qr-title"><?php _e('QR Kod ile Giriş', 'ai-community-engine'); ?></h1>
                    <p class="qr-subtitle"><?php _e('Telefonunuzla QR kodu okutarak güvenli giriş yapın', 'ai-community-engine'); ?></p>
                </div>
                
                <?php if (!$user_id): ?>
                    <!-- Giriş yapmamış kullanıcı -->
                    <div class="qr-display">
                        <p><?php _e('QR kod ile giriş yapmak için önce hesabınıza giriş yapmalısınız.', 'ai-community-engine'); ?></p>
                        <a href="<?php echo esc_url(home_url('/ai-community/login/')); ?>" class="btn btn-primary">
                            🔐 <?php _e('Giriş Yap', 'ai-community-engine'); ?>
                        </a>
                    </div>
                <?php else: ?>
                    <!-- QR kod gösterimi -->
                    <div class="qr-display" id="qrDisplay">
                        <div class="qr-code" id="qrCode">
                            <div class="loader" id="loader"></div>
                        </div>
                        
                        <div class="qr-timer">
                            <span><?php _e('Kalan süre:', 'ai-community-engine'); ?></span>
                            <span class="timer-display" id="timer">05:00</span>
                        </div>
                        
                        <div class="qr-steps">
                            <div class="step active" id="step1">
                                1
                                <span class="step-label"><?php _e('QR Kod', 'ai-community-engine'); ?></span>
                            </div>
                            <div class="step" id="step2">
                                2
                                <span class="step-label"><?php _e('Okutma', 'ai-community-engine'); ?></span>
                            </div>
                            <div class="step" id="step3">
                                3
                                <span class="step-label"><?php _e('Onay', 'ai-community-engine'); ?></span>
                            </div>
                        </div>
                        
                        <div class="qr-status" id="qrStatus"></div>
                        
                        <div class="device-info" id="deviceInfo" style="display: none;">
                            <h4><?php _e('Okutulan Cihaz:', 'ai-community-engine'); ?></h4>
                            <div class="device-info-item">
                                <span>📱</span>
                                <span id="deviceName"></span>
                            </div>
                            <div class="device-info-item">
                                <span>🌐</span>
                                <span id="deviceBrowser"></span>
                            </div>
                            <div class="device-info-item">
                                <span>⏰</span>
                                <span id="scanTime"></span>
                            </div>
                        </div>
                        
                        <div class="qr-actions">
                            <button class="btn btn-secondary" id="refreshButton" style="display: none;">
                                🔄 <?php _e('Yeni QR Kod', 'ai-community-engine'); ?>
                            </button>
                            <button class="btn btn-danger" id="cancelButton" style="display: none;">
                                ✕ <?php _e('İptal Et', 'ai-community-engine'); ?>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="alternative-methods">
                    <p><?php _e('Alternatif giriş yöntemleri:', 'ai-community-engine'); ?></p>
                    <div class="alternative-links">
                        <a href="<?php echo esc_url(home_url('/ai-community/login/')); ?>" class="btn btn-secondary">
                            🔐 <?php _e('Şifre', 'ai-community-engine'); ?>
                        </a>
                        <a href="<?php echo esc_url(home_url('/ai-community/login/?email_action=magic')); ?>" class="btn btn-secondary">
                            📧 <?php _e('Magic Link', 'ai-community-engine'); ?>
                        </a>
                        <a href="<?php echo esc_url(home_url('/ai-community/webauthn/')); ?>" class="btn btn-secondary">
                            👆 <?php _e('Biometrik', 'ai-community-engine'); ?>
                        </a>
                    </div>
                </div>
                
                <div class="info-box">
                    <strong>ℹ️ <?php _e('Nasıl Çalışır?', 'ai-community-engine'); ?></strong>
                    <ol style="margin-top: 10px; margin-left: 20px;">
                        <li><?php _e('Telefonunuzla QR kodu okutun', 'ai-community-engine'); ?></li>
                        <li><?php _e('Telefonda giriş yapın veya onaylayın', 'ai-community-engine'); ?></li>
                        <li><?php _e('Bilgisayarınız otomatik giriş yapacak', 'ai-community-engine'); ?></li>
                    </ol>
                </div>
            </div>
            
            <script>
            <?php if ($user_id): ?>
            let currentSession = null;
            let checkInterval = null;
            let timerInterval = null;
            let timeLeft = 300; // 5 dakika = 300 saniye
            
            // QR kod oluştur
            async function generateQR() {
                const qrCode = document.getElementById('qrCode');
                const loader = document.getElementById('loader');
                const refreshButton = document.getElementById('refreshButton');
                const cancelButton = document.getElementById('cancelButton');
                
                qrCode.innerHTML = '';
                loader.style.display = 'block';
                refreshButton.style.display = 'none';
                cancelButton.style.display = 'none';
                
                try {
                    const response = await fetch('<?php echo esc_url(home_url('/ai-community/callback/qr/?qr_action=generate')); ?>', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        currentSession = result.data.session_id;
                        timeLeft = result.data.expires_in;
                        
                        // QR kodu göster
                        qrCode.innerHTML = `<img src="${result.data.qr_url}" alt="QR Code">`;
                        
                        // Timer'ı başlat
                        startTimer(result.data.expires_in);
                        
                        // Durum kontrolünü başlat
                        startStatusCheck();
                        
                        refreshButton.style.display = 'none';
                        cancelButton.style.display = 'inline-block';
                        
                        // Step'leri sıfırla
                        updateSteps(1);
                        
                        // Status mesajını temizle
                        hideStatus();
                        
                        // Device info'yu gizle
                        document.getElementById('deviceInfo').style.display = 'none';
                    } else {
                        throw new Error(result.data || 'QR kod oluşturulamadı');
                    }
                } catch (error) {
                    console.error(error);
                    qrCode.innerHTML = '<p style="color: #dc3545;">❌ QR kod oluşturulamadı</p>';
                    refreshButton.style.display = 'inline-block';
                } finally {
                    loader.style.display = 'none';
                }
            }
            
            // Timer'ı başlat
            function startTimer(duration) {
                if (timerInterval) clearInterval(timerInterval);
                
                timeLeft = duration;
                updateTimerDisplay();
                
                timerInterval = setInterval(() => {
                    timeLeft--;
                    updateTimerDisplay();
                    
                    if (timeLeft <= 0) {
                        clearInterval(timerInterval);
                        clearInterval(checkInterval);
                        document.getElementById('qrCode').innerHTML = '<p style="color: #dc3545;">⏰ QR kod süresi doldu</p>';
                        document.getElementById('refreshButton').style.display = 'inline-block';
                        document.getElementById('cancelButton').style.display = 'none';
                        showStatus('error', 'QR kod süresi doldu. Yeni kod oluşturun.');
                    }
                }, 1000);
            }
            
            // Timer display'ini güncelle
            function updateTimerDisplay() {
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                document.getElementById('timer').textContent = 
                    String(minutes).padStart(2, '0') + ':' + 
                    String(seconds).padStart(2, '0');
            }
            
            // Durum kontrolünü başlat
            function startStatusCheck() {
                if (checkInterval) clearInterval(checkInterval);
                
                checkInterval = setInterval(async () => {
                    if (!currentSession) return;
                    
                    try {
                        const response = await fetch(`<?php echo esc_url(home_url('/ai-community/callback/qr/?qr_action=check&session=')); ?>${currentSession}`, {
                            headers: {
                                'Accept': 'application/json'
                            }
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            switch (result.data.status) {
                                case 'scanned':
                                    updateSteps(2);
                                    showStatus('scanned', '📱 QR kod okutuldu! Telefonunuzdan onaylayın...');
                                    
                                    // Cihaz bilgilerini göster
                                    if (result.data.device_info) {
                                        document.getElementById('deviceName').textContent = result.data.device_info.device;
                                        document.getElementById('deviceBrowser').textContent = result.data.device_info.browser;
                                        document.getElementById('scanTime').textContent = new Date().toLocaleTimeString();
                                        document.getElementById('deviceInfo').style.display = 'block';
                                    }
                                    break;
                                    
                                case 'confirmed':
                                    updateSteps(3);
                                    showStatus('success', '✅ Onaylandı! Yönlendiriliyorsunuz...');
                                    clearInterval(checkInterval);
                                    clearInterval(timerInterval);
                                    
                                    // Giriş yap ve yönlendir
                                    setTimeout(() => {
                                        window.location.href = '<?php echo esc_url(home_url('/ai-community/account/?qr_login=success')); ?>';
                                    }, 1500);
                                    break;
                                    
                                case 'cancelled':
                                    showStatus('error', '✕ İşlem iptal edildi');
                                    clearInterval(checkInterval);
                                    clearInterval(timerInterval);
                                    document.getElementById('refreshButton').style.display = 'inline-block';
                                    document.getElementById('cancelButton').style.display = 'none';
                                    break;
                            }
                        }
                    } catch (error) {
                        console.error('Status check error:', error);
                    }
                }, 2000);
            }
            
            // Step'leri güncelle
            function updateSteps(step) {
                for (let i = 1; i <= 3; i++) {
                    const stepEl = document.getElementById(`step${i}`);
                    if (i < step) {
                        stepEl.className = 'step completed';
                    } else if (i === step) {
                        stepEl.className = 'step active';
                    } else {
                        stepEl.className = 'step';
                    }
                }
            }
            
            // Status mesajı göster
            function showStatus(type, message) {
                const statusEl = document.getElementById('qrStatus');
                statusEl.className = `qr-status status-${type}`;
                statusEl.textContent = message;
                statusEl.style.display = 'block';
            }
            
            // Status mesajını gizle
            function hideStatus() {
                document.getElementById('qrStatus').style.display = 'none';
            }
            
            // İptal et
            document.getElementById('cancelButton')?.addEventListener('click', async function() {
                if (!currentSession) return;
                
                if (!confirm('İşlemi iptal etmek istediğinizden emin misiniz?')) {
                    return;
                }
                
                try {
                    await fetch('<?php echo esc_url(home_url('/ai-community/callback/qr/?qr_action=cancel')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            session: currentSession
                        })
                    });
                } catch (error) {
                    console.error(error);
                }
                
                clearInterval(checkInterval);
                clearInterval(timerInterval);
                document.getElementById('refreshButton').style.display = 'inline-block';
                this.style.display = 'none';
                showStatus('error', 'İşlem iptal edildi');
            });
            
            // Yenile butonu
            document.getElementById('refreshButton')?.addEventListener('click', function() {
                generateQR();
            });
            
            // Sayfa yüklendiğinde QR kod oluştur
            generateQR();
            
            // Sayfa terk edildiğinde interval'ları temizle
            window.addEventListener('beforeunload', function() {
                if (checkInterval) clearInterval(checkInterval);
                if (timerInterval) clearInterval(timerInterval);
            });
            <?php endif; ?>
            </script>
            
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Cihaz bilgilerini al
     */
    private function get_device_info() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $device = 'Bilinmeyen Cihaz';
        if (strpos($ua, 'iPhone') !== false) $device = 'iPhone';
        elseif (strpos($ua, 'iPad') !== false) $device = 'iPad';
        elseif (strpos($ua, 'Android') !== false) $device = 'Android';
        elseif (strpos($ua, 'Mac') !== false) $device = 'Mac';
        elseif (strpos($ua, 'Windows') !== false) $device = 'Windows PC';
        elseif (strpos($ua, 'Linux') !== false) $device = 'Linux PC';
        
        $browser = 'Bilinmeyen Tarayıcı';
        if (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
        elseif (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
        elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
        elseif (strpos($ua, 'Edge') !== false) $browser = 'Edge';
        elseif (strpos($ua, 'Opera') !== false) $browser = 'Opera';
        
        return [
            'device' => $device,
            'browser' => $browser,
            'ua' => substr($ua, 0, 200)
        ];
    }
    
    /**
     * Event logla
     */
    private function log_event($event, $data = []) {
        if (method_exists($this->core, 'log')) {
            $this->core->log($event, $data);
        }
    }
    
    /**
     * Eski session'ları temizle (cron için)
     */
    public function cleanup_old_sessions() {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
                'ai_comm_qr_%',
                '%"expires":' . (time() - 3600) . '%'
            )
        );
    }
}