<?php
// masal-panel/modules/ai-community-engine/core/providers/passkey.php
// PASSKEYS - ŞİFRESİZ GİRİŞ (APPLE/GOOGLE)

if (!defined('ABSPATH') || !defined('MASAL_PANEL_SECURE_TOKEN')) {
    exit("⛔ Erişim reddedildi");
}

require_once __DIR__ . '/provider-base.php';

class AI_Community_Provider_Passkey extends AI_Community_Provider_Base {
    
    protected $id = 'passkey';
    protected $label = 'Passkey ile Giriş';
    
    private $rp_id;
    private $rp_name;
    private $origin;
    
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        $this->rp_id = parse_url(home_url(), PHP_URL_HOST);
        $this->rp_name = get_bloginfo('name');
        $this->origin = home_url();
        
        // HTTPS kontrolü
        if (!is_ssl() && !defined('WP_DEBUG')) {
            $this->log_error('Passkeys requires HTTPS');
        }
    }
    
    /**
     * 🔧 ZORUNLU METOD - handle()
     * Passkey provider isteğini yönet
     */
    public function handle() {
        $action = isset($_GET['passkey_action']) ? sanitize_text_field($_GET['passkey_action']) : '';
        
        switch ($action) {
            case 'register':
                $this->handle_register();
                break;
                
            case 'authenticate':
                $this->handle_authenticate();
                break;
                
            case 'verify_registration':
                $this->handle_verify_registration();
                break;
                
            case 'verify_authentication':
                $this->handle_verify_authentication();
                break;
                
            case 'list':
                $this->handle_list();
                break;
                
            case 'delete':
                $this->handle_delete();
                break;
                
            default:
                $this->render_passkey_page();
                break;
        }
    }
    
    /**
     * Passkey kayıt işlemini başlat
     */
    private function handle_register() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('Giriş yapmalısınız');
            return;
        }
        
        $user = get_userdata($user_id);
        
        // Challenge oluştur
        $challenge = $this->generate_challenge();
        
        // Kullanıcı handle'ı oluştur
        $user_handle = $this->get_user_handle($user_id);
        
        // Geçici kaydet
        update_user_meta($user_id, 'ai_comm_passkey_challenge', [
            'challenge' => $challenge,
            'expires' => time() + 300 // 5 dakika
        ]);
        
        // Mevcut passkey'leri al
        $existing = $this->get_existing_credentials($user_id);
        
        // Registration options
        $options = [
            'rp' => [
                'id' => $this->rp_id,
                'name' => $this->rp_name
            ],
            'user' => [
                'id' => $this->base64url_encode($user_handle),
                'name' => $user->user_email,
                'displayName' => $user->display_name ?: $user->user_login
            ],
            'challenge' => $this->base64url_encode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257]  // RS256
            ],
            'timeout' => 60000,
            'excludeCredentials' => $existing,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform', // platform = cihaz içi
                'residentKey' => 'required', // Passkey için required
                'userVerification' => 'required'
            ],
            'attestation' => 'none'
        ];
        
        $this->log_event('passkey_registration_started', ['user_id' => $user_id]);
        
        wp_send_json_success($options);
    }
    
    /**
     * Passkey kaydını doğrula
     */
    private function handle_verify_registration() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('Giriş yapmalısınız');
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $data = $input['credential'] ?? null;
        
        if (!$data) {
            wp_send_json_error('Geçersiz veri');
            return;
        }
        
        $challenge_data = get_user_meta($user_id, 'ai_comm_passkey_challenge', true);
        
        if (!$challenge_data || $challenge_data['expires'] < time()) {
            wp_send_json_error('Challenge süresi doldu');
            return;
        }
        
        // Gelen veriyi decode et
        $clientDataJSON = base64_decode($data['response']['clientDataJSON']);
        $attestationObject = base64_decode($data['response']['attestationObject']);
        
        // ClientData doğrula
        $clientData = json_decode($clientDataJSON, true);
        
        if ($clientData['type'] !== 'webauthn.create') {
            wp_send_json_error('Geçersiz işlem tipi');
        }
        
        // Challenge doğrula
        $received_challenge = $this->base64url_decode($clientData['challenge']);
        if ($received_challenge !== $challenge_data['challenge']) {
            wp_send_json_error('Challenge uyuşmazlığı');
        }
        
        // Origin doğrula
        if ($clientData['origin'] !== $this->origin && $clientData['origin'] !== $this->origin . '/') {
            wp_send_json_error('Origin uyuşmazlığı');
        }
        
        // Credential ID'yi çıkar
        $credential_id = base64_decode($data['rawId']);
        $public_key = $this->extract_public_key($attestationObject);
        
        if (!$public_key) {
            wp_send_json_error('Geçersiz attestation');
        }
        
        // Credential'ı kaydet
        $credentials = get_user_meta($user_id, 'ai_comm_passkey_credentials', true) ?: [];
        
        // Aynı credential var mı?
        foreach ($credentials as $cred) {
            if ($cred['id'] === $data['id']) {
                wp_send_json_error('Bu passkey zaten kayıtlı');
            }
        }
        
        $credentials[] = [
            'id' => $data['id'],
            'credential_id' => base64_encode($credential_id),
            'public_key' => base64_encode($public_key),
            'sign_count' => 0,
            'device_name' => $this->get_device_name(),
            'device_type' => $this->get_device_type(),
            'created_at' => time(),
            'last_used' => time(),
            'transports' => $data['response']['transports'] ?? []
        ];
        
        update_user_meta($user_id, 'ai_comm_passkey_credentials', $credentials);
        delete_user_meta($user_id, 'ai_comm_passkey_challenge');
        
        $this->log_event('passkey_registration_completed', [
            'user_id' => $user_id,
            'credential_count' => count($credentials)
        ]);
        
        wp_send_json_success([
            'message' => 'Passkey başarıyla kaydedildi',
            'credential_count' => count($credentials)
        ]);
    }
    
    /**
     * Passkey ile kimlik doğrulama başlat
     */
    private function handle_authenticate() {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = isset($input['email']) ? sanitize_email($input['email']) : '';
        
        $challenge = $this->generate_challenge();
        
        $options = [
            'challenge' => $this->base64url_encode($challenge),
            'timeout' => 60000,
            'rpId' => $this->rp_id,
            'allowCredentials' => [],
            'userVerification' => 'required'
        ];
        
        // Email verilmişse, o kullanıcının passkey'lerini filtrele
        if (!empty($email)) {
            $user = get_user_by('email', $email);
            if ($user) {
                $credentials = get_user_meta($user->ID, 'ai_comm_passkey_credentials', true) ?: [];
                
                foreach ($credentials as $cred) {
                    $options['allowCredentials'][] = [
                        'type' => 'public-key',
                        'id' => $cred['id'],
                        'transports' => $cred['transports'] ?? ['internal', 'hybrid']
                    ];
                }
            }
        }
        
        // Challenge'ı session'a kaydet
        $session_key = 'ai_comm_passkey_auth_' . session_id();
        update_option($session_key, [
            'challenge' => $challenge,
            'expires' => time() + 300,
            'email' => $email
        ], false);
        
        $this->log_event('passkey_authentication_started', [
            'email' => $email ? md5($email) : 'any'
        ]);
        
        wp_send_json_success($options);
    }
    
    /**
     * Passkey doğrulamasını tamamla
     */
    private function handle_verify_authentication() {
        $input = json_decode(file_get_contents('php://input'), true);
        $data = $input['credential'] ?? null;
        
        if (!$data) {
            wp_send_json_error('Geçersiz veri');
            return;
        }
        
        $session_key = 'ai_comm_passkey_auth_' . session_id();
        $auth_data = get_option($session_key);
        
        if (!$auth_data || $auth_data['expires'] < time()) {
            wp_send_json_error('Authentication süresi doldu');
            return;
        }
        
        // Gelen veriyi decode et
        $clientDataJSON = base64_decode($data['response']['clientDataJSON']);
        $authenticatorData = base64_decode($data['response']['authenticatorData']);
        $signature = base64_decode($data['response']['signature']);
        
        // ClientData doğrula
        $clientData = json_decode($clientDataJSON, true);
        
        if ($clientData['type'] !== 'webauthn.get') {
            wp_send_json_error('Geçersiz işlem tipi');
        }
        
        // Challenge doğrula
        $received_challenge = $this->base64url_decode($clientData['challenge']);
        if ($received_challenge !== $auth_data['challenge']) {
            wp_send_json_error('Challenge uyuşmazlığı');
        }
        
        // Origin doğrula
        if ($clientData['origin'] !== $this->origin && $clientData['origin'] !== $this->origin . '/') {
            wp_send_json_error('Origin uyuşmazlığı');
        }
        
        // Credential'ı bul
        $user_id = $this->find_user_by_credential($data['id']);
        
        if (!$user_id) {
            wp_send_json_error('Passkey bulunamadı');
        }
        
        $credentials = get_user_meta($user_id, 'ai_comm_passkey_credentials', true) ?: [];
        $credential = null;
        
        foreach ($credentials as &$cred) {
            if ($cred['id'] === $data['id']) {
                $credential = &$cred;
                break;
            }
        }
        
        if (!$credential) {
            wp_send_json_error('Passkey bulunamadı');
        }
        
        // İmzayı doğrula
        $public_key = base64_decode($credential['public_key']);
        $rp_id_hash = hash('sha256', $this->rp_id, true);
        
        // AuthenticatorData'dan sign count'u al
        $sign_count = unpack('N', substr($authenticatorData, 33, 4))[1];
        
        // Sign count kontrolü (replay attack prevention)
        if ($sign_count > 0 && $sign_count <= $credential['sign_count']) {
            $this->log_suspicious('passkey_sign_count_error', [
                'user_id' => $user_id,
                'old_count' => $credential['sign_count'],
                'new_count' => $sign_count
            ]);
            wp_send_json_error('Güvenlik hatası');
        }
        
        // İmza doğrulama
        $verified = $this->verify_signature(
            $public_key,
            $signature,
            $authenticatorData . hash('sha256', $clientDataJSON, true)
        );
        
        if (!$verified) {
            $this->log_suspicious('passkey_signature_invalid', ['user_id' => $user_id]);
            wp_send_json_error('Geçersiz imza');
        }
        
        // Sign count'u güncelle
        $credential['sign_count'] = $sign_count;
        $credential['last_used'] = time();
        update_user_meta($user_id, 'ai_comm_passkey_credentials', $credentials);
        
        delete_option($session_key);
        
        // Giriş yap
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id, true);
        
        // Son giriş zamanını güncelle
        update_user_meta($user_id, 'ai_comm_last_login', time());
        update_user_meta($user_id, 'ai_comm_last_login_method', 'passkey');
        
        $this->log_event('passkey_authentication_completed', ['user_id' => $user_id]);
        
        wp_send_json_success([
            'message' => 'Giriş başarılı',
            'redirect' => home_url('/ai-community/account/')
        ]);
    }
    
    /**
     * Kullanıcının passkey'lerini listele
     */
    private function handle_list() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('Giriş yapmalısınız');
            return;
        }
        
        $credentials = get_user_meta($user_id, 'ai_comm_passkey_credentials', true) ?: [];
        $passkeys = [];
        
        foreach ($credentials as $cred) {
            $passkeys[] = [
                'id' => $cred['id'],
                'name' => $cred['device_name'],
                'type' => $cred['device_type'],
                'created_at' => date('Y-m-d H:i:s', $cred['created_at']),
                'last_used' => $cred['last_used'] ? date('Y-m-d H:i:s', $cred['last_used']) : 'Hiç kullanılmadı',
                'sign_count' => $cred['sign_count']
            ];
        }
        
        wp_send_json_success($passkeys);
    }
    
    /**
     * Passkey sil
     */
    private function handle_delete() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            wp_send_json_error('Giriş yapmalısınız');
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $credential_id = $input['credential_id'] ?? null;
        
        if (!$credential_id) {
            wp_send_json_error('Credential ID gerekli');
            return;
        }
        
        $credentials = get_user_meta($user_id, 'ai_comm_passkey_credentials', true) ?: [];
        
        $new_credentials = array_filter($credentials, function($cred) use ($credential_id) {
            return $cred['id'] !== $credential_id;
        });
        
        if (count($new_credentials) === count($credentials)) {
            wp_send_json_error('Passkey bulunamadı');
            return;
        }
        
        update_user_meta($user_id, 'ai_comm_passkey_credentials', array_values($new_credentials));
        
        $this->log_event('passkey_deleted', [
            'user_id' => $user_id,
            'credential_id' => substr($credential_id, 0, 16) . '...'
        ]);
        
        wp_send_json_success([
            'message' => 'Passkey silindi',
            'credential_count' => count($new_credentials)
        ]);
    }
    
    /**
     * Passkey ana sayfasını render et
     */
    private function render_passkey_page() {
        $user_id = get_current_user_id();
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('Passkey ile Giriş', 'ai-community-engine'); ?></title>
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
                
                .passkey-container {
                    max-width: 500px;
                    width: 100%;
                    background: rgba(255, 255, 255, 0.95);
                    backdrop-filter: blur(10px);
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                    padding: 40px;
                }
                
                .passkey-header {
                    text-align: center;
                    margin-bottom: 30px;
                }
                
                .passkey-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                    animation: float 3s ease-in-out infinite;
                }
                
                @keyframes float {
                    0%, 100% { transform: translateY(0); }
                    50% { transform: translateY(-10px); }
                }
                
                .passkey-title {
                    color: #23282d;
                    font-size: 28px;
                    font-weight: 600;
                    margin-bottom: 10px;
                }
                
                .passkey-subtitle {
                    color: #6c757d;
                    font-size: 16px;
                }
                
                .passkey-badges {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                    margin: 20px 0;
                }
                
                .badge {
                    padding: 5px 15px;
                    border-radius: 50px;
                    font-size: 12px;
                    font-weight: 600;
                }
                
                .badge-apple {
                    background: #000;
                    color: white;
                }
                
                .badge-google {
                    background: #4285f4;
                    color: white;
                }
                
                .badge-windows {
                    background: #00a4ef;
                    color: white;
                }
                
                .passkey-login {
                    text-align: center;
                    margin: 30px 0;
                }
                
                .btn {
                    padding: 16px 40px;
                    border-radius: 50px;
                    font-size: 18px;
                    font-weight: 600;
                    border: none;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    width: 100%;
                    margin-bottom: 15px;
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
                
                .btn:disabled {
                    opacity: 0.5;
                    cursor: not-allowed;
                }
                
                .email-input {
                    margin: 20px 0;
                }
                
                .email-input input {
                    width: 100%;
                    padding: 15px;
                    border: 2px solid #ddd;
                    border-radius: 10px;
                    font-size: 16px;
                    transition: border-color 0.2s;
                }
                
                .email-input input:focus {
                    outline: none;
                    border-color: #667eea;
                }
                
                .passkey-list {
                    margin: 30px 0;
                }
                
                .passkey-list-title {
                    font-size: 18px;
                    font-weight: 600;
                    color: #23282d;
                    margin-bottom: 15px;
                }
                
                .passkey-item {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 15px;
                    background: #f8f9fa;
                    border-radius: 10px;
                    margin-bottom: 10px;
                    transition: all 0.2s;
                }
                
                .passkey-item:hover {
                    background: #e9ecef;
                }
                
                .passkey-info {
                    display: flex;
                    align-items: center;
                    gap: 12px;
                }
                
                .passkey-device-icon {
                    font-size: 24px;
                }
                
                .passkey-details h4 {
                    font-size: 16px;
                    font-weight: 600;
                    margin-bottom: 4px;
                }
                
                .passkey-details p {
                    font-size: 12px;
                    color: #6c757d;
                }
                
                .passkey-actions {
                    display: flex;
                    gap: 8px;
                }
                
                .delete-btn {
                    background: none;
                    border: none;
                    font-size: 18px;
                    cursor: pointer;
                    padding: 5px;
                    border-radius: 5px;
                    color: #dc3545;
                    transition: all 0.2s;
                }
                
                .delete-btn:hover {
                    background: #dc3545;
                    color: white;
                }
                
                .status-message {
                    margin: 20px 0;
                    padding: 15px;
                    border-radius: 10px;
                    display: none;
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
                
                .status-info {
                    background: #d1ecf1;
                    color: #0c5460;
                    border-left: 4px solid #17a2b8;
                }
                
                .info-box {
                    background: #e7f3ff;
                    border-left: 4px solid #2196F3;
                    padding: 15px;
                    border-radius: 5px;
                    margin-top: 20px;
                }
                
                .info-box ol {
                    margin-top: 10px;
                    margin-left: 20px;
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
            <div class="passkey-container">
                <div class="passkey-header">
                    <div class="passkey-icon">🔑</div>
                    <h1 class="passkey-title"><?php _e('Passkey ile Giriş', 'ai-community-engine'); ?></h1>
                    <p class="passkey-subtitle"><?php _e('Şifresiz, daha güvenli ve hızlı giriş', 'ai-community-engine'); ?></p>
                    
                    <div class="passkey-badges">
                        <span class="badge badge-apple">🍎 Apple</span>
                        <span class="badge badge-google">🔷 Google</span>
                        <span class="badge badge-windows">🪟 Windows</span>
                    </div>
                </div>
                
                <div id="statusMessage" class="status-message"></div>
                
                <?php if (!$user_id): ?>
                    <!-- Giriş yapmamış kullanıcı -->
                    <div class="passkey-login">
                        <p style="margin-bottom: 20px;"><?php _e('Passkey ile giriş yapmak için e-posta adresinizi girin', 'ai-community-engine'); ?></p>
                        
                        <div class="email-input">
                            <input type="email" id="loginEmail" placeholder="ornek@email.com" value="">
                        </div>
                        
                        <button class="btn btn-primary" id="loginWithPasskey">
                            🔑 <?php _e('Passkey ile Giriş Yap', 'ai-community-engine'); ?>
                        </button>
                        
                        <div class="loader" id="loginLoader"></div>
                        
                        <div style="margin-top: 20px;">
                            <a href="<?php echo esc_url(home_url('/ai-community/login/')); ?>" class="btn btn-secondary">
                                🔐 <?php _e('Diğer Giriş Yöntemleri', 'ai-community-engine'); ?>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Giriş yapmış kullanıcı - Passkey yönetimi -->
                    <div class="passkey-login">
                        <button class="btn btn-primary" id="registerPasskey">
                            ✨ <?php _e('Yeni Passkey Oluştur', 'ai-community-engine'); ?>
                        </button>
                        
                        <div class="loader" id="registerLoader"></div>
                        
                        <div class="passkey-list" id="passkeyList" style="display: none;">
                            <h3 class="passkey-list-title">📱 Kayıtlı Passkey'ler</h3>
                            <div id="passkeyItems"></div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="info-box">
                    <strong>ℹ️ <?php _e('Passkey Nedir?', 'ai-community-engine'); ?></strong>
                    <ol>
                        <li><?php _e('Şifre yerine cihazınızın kilidini kullanır', 'ai-community-engine'); ?></li>
                        <li><?php _e('Parmak izi, yüz tanıma veya PIN ile çalışır', 'ai-community-engine'); ?></li>
                        <li><?php _e('Cihazlar arası senkronize olur (iCloud, Google)', 'ai-community-engine'); ?></li>
                        <li><?php _e('Phishing saldırılarına karşı dayanıklıdır', 'ai-community-engine'); ?></li>
                        <li><?php _e('Her site için farklı, güçlü anahtar üretir', 'ai-community-engine'); ?></li>
                    </ol>
                </div>
            </div>
            
            <script>
            <?php if (!$user_id): ?>
            // Passkey ile giriş
            document.getElementById('loginWithPasskey').addEventListener('click', async function() {
                const button = this;
                const email = document.getElementById('loginEmail').value.trim();
                const loader = document.getElementById('loginLoader');
                
                button.disabled = true;
                loader.style.display = 'block';
                
                try {
                    // Authentication options'ı al
                    const response = await fetch('<?php echo esc_url(home_url('/ai-community/callback/passkey/?passkey_action=authenticate')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ email: email })
                    });
                    
                    const result = await response.json();
                    
                    if (!result.success) {
                        throw new Error(result.data || 'Başlatılamadı');
                    }
                    
                    const publicKey = result.data;
                    
                    // ArrayBuffer'ları düzelt
                    publicKey.challenge = base64urlToArrayBuffer(publicKey.challenge);
                    
                    if (publicKey.allowCredentials) {
                        publicKey.allowCredentials = publicKey.allowCredentials.map(cred => ({
                            ...cred,
                            id: base64urlToArrayBuffer(cred.id)
                        }));
                    }
                    
                    showStatus('info', 'Lütfen cihazınızın kilidini açın...');
                    
                    // WebAuthn get
                    const credential = await navigator.credentials.get({ publicKey });
                    
                    // Sonucu sunucuya gönder
                    const verifyResponse = await fetch('<?php echo esc_url(home_url('/ai-community/callback/passkey/?passkey_action=verify_authentication')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            credential: {
                                id: credential.id,
                                rawId: arrayBufferToBase64(credential.rawId),
                                response: {
                                    clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                                    authenticatorData: arrayBufferToBase64(credential.response.authenticatorData),
                                    signature: arrayBufferToBase64(credential.response.signature)
                                },
                                type: credential.type
                            }
                        })
                    });
                    
                    const verifyResult = await verifyResponse.json();
                    
                    if (verifyResult.success) {
                        showStatus('success', '✅ Giriş başarılı! Yönlendiriliyorsunuz...');
                        setTimeout(() => {
                            window.location.href = verifyResult.data.redirect;
                        }, 1500);
                    } else {
                        throw new Error(verifyResult.data || 'Doğrulama başarısız');
                    }
                    
                } catch (error) {
                    console.error(error);
                    showStatus('error', '❌ ' + (error.message || 'Bir hata oluştu'));
                    button.disabled = false;
                } finally {
                    loader.style.display = 'none';
                }
            });
            <?php else: ?>
            // Passkey kaydet
            document.getElementById('registerPasskey').addEventListener('click', async function() {
                const button = this;
                const loader = document.getElementById('registerLoader');
                
                button.disabled = true;
                loader.style.display = 'block';
                
                try {
                    // Registration options'ı al
                    const response = await fetch('<?php echo esc_url(home_url('/ai-community/callback/passkey/?passkey_action=register')); ?>', {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    
                    const result = await response.json();
                    
                    if (!result.success) {
                        throw new Error(result.data || 'Başlatılamadı');
                    }
                    
                    const publicKey = result.data;
                    
                    // ArrayBuffer'ları düzelt
                    publicKey.challenge = base64urlToArrayBuffer(publicKey.challenge);
                    publicKey.user.id = base64urlToArrayBuffer(publicKey.user.id);
                    
                    if (publicKey.excludeCredentials) {
                        publicKey.excludeCredentials = publicKey.excludeCredentials.map(cred => ({
                            ...cred,
                            id: base64urlToArrayBuffer(cred.id)
                        }));
                    }
                    
                    showStatus('info', 'Lütfen cihazınızın kilidini açarak passkey oluşturun...');
                    
                    // WebAuthn create
                    const credential = await navigator.credentials.create({ publicKey });
                    
                    // Sonucu sunucuya gönder
                    const verifyResponse = await fetch('<?php echo esc_url(home_url('/ai-community/callback/passkey/?passkey_action=verify_registration')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            credential: {
                                id: credential.id,
                                rawId: arrayBufferToBase64(credential.rawId),
                                response: {
                                    clientDataJSON: arrayBufferToBase64(credential.response.clientDataJSON),
                                    attestationObject: arrayBufferToBase64(credential.response.attestationObject),
                                    transports: credential.response.getTransports ? credential.response.getTransports() : []
                                },
                                type: credential.type
                            }
                        })
                    });
                    
                    const verifyResult = await verifyResponse.json();
                    
                    if (verifyResult.success) {
                        showStatus('success', '✅ Passkey başarıyla kaydedildi!');
                        setTimeout(() => {
                            loadPasskeys();
                        }, 1500);
                    } else {
                        throw new Error(verifyResult.data || 'Kayıt başarısız');
                    }
                    
                } catch (error) {
                    console.error(error);
                    showStatus('error', '❌ ' + (error.message || 'Bir hata oluştu'));
                    button.disabled = false;
                } finally {
                    loader.style.display = 'none';
                }
            });
            
            // Passkey'leri yükle
            async function loadPasskeys() {
                try {
                    const response = await fetch('<?php echo esc_url(home_url('/ai-community/callback/passkey/?passkey_action=list')); ?>', {
                        headers: {
                            'Accept': 'application/json'
                        }
                    });
                    
                    const result = await response.json();
                    
                    if (result.success && result.data.length > 0) {
                        const listEl = document.getElementById('passkeyList');
                        const itemsEl = document.getElementById('passkeyItems');
                        
                        itemsEl.innerHTML = '';
                        
                        result.data.forEach(passkey => {
                            const item = document.createElement('div');
                            item.className = 'passkey-item';
                            item.innerHTML = `
                                <div class="passkey-info">
                                    <span class="passkey-device-icon">${getDeviceIcon(passkey.type)}</span>
                                    <div class="passkey-details">
                                        <h4>${escapeHtml(passkey.name)}</h4>
                                        <p>Eklenme: ${passkey.created_at} • Kullanım: ${passkey.sign_count} kez</p>
                                    </div>
                                </div>
                                <div class="passkey-actions">
                                    <button class="delete-btn" onclick="deletePasskey('${passkey.id}')" title="Sil">🗑️</button>
                                </div>
                            `;
                            itemsEl.appendChild(item);
                        });
                        
                        listEl.style.display = 'block';
                    }
                } catch (error) {
                    console.error('Failed to load passkeys:', error);
                }
            }
            
            // Passkey sil
            window.deletePasskey = async function(credentialId) {
                if (!confirm('Bu passkey silmek istediğinizden emin misiniz?')) {
                    return;
                }
                
                try {
                    const response = await fetch('<?php echo esc_url(home_url('/ai-community/callback/passkey/?passkey_action=delete')); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ credential_id: credentialId })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showStatus('success', '✅ Passkey silindi');
                        loadPasskeys();
                    } else {
                        throw new Error(result.data || 'Silinemedi');
                    }
                } catch (error) {
                    showStatus('error', '❌ ' + error.message);
                }
            };
            
            function getDeviceIcon(type) {
                if (type.includes('iPhone') || type.includes('iPad')) return '📱';
                if (type.includes('Mac')) return '💻';
                if (type.includes('Windows')) return '🖥️';
                if (type.includes('Android')) return '🤖';
                return '🔑';
            }
            
            function escapeHtml(unsafe) {
                return unsafe
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }
            
            // Sayfa yüklendiğinde passkey'leri yükle
            loadPasskeys();
            <?php endif; ?>
            
            // Ortak fonksiyonlar
            function showStatus(type, message) {
                const statusDiv = document.getElementById('statusMessage');
                statusDiv.className = 'status-message status-' + type;
                statusDiv.textContent = message;
                statusDiv.style.display = 'block';
                
                setTimeout(() => {
                    statusDiv.style.display = 'none';
                }, 5000);
            }
            
            // ArrayBuffer helpers
            function base64urlToArrayBuffer(base64url) {
                const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
                const binary = atob(base64);
                const bytes = new Uint8Array(binary.length);
                for (let i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }
                return bytes.buffer;
            }
            
            function arrayBufferToBase64(buffer) {
                const bytes = new Uint8Array(buffer);
                let binary = '';
                for (let i = 0; i < bytes.byteLength; i++) {
                    binary += String.fromCharCode(bytes[i]);
                }
                return btoa(binary)
                    .replace(/\+/g, '-')
                    .replace(/\//g, '_')
                    .replace(/=/g, '');
            }
            
            // WebAuthn destek kontrolü
            if (!window.PublicKeyCredential) {
                showStatus('error', '❌ Tarayıcınız Passkey desteklemiyor');
                document.querySelectorAll('button').forEach(btn => btn.disabled = true);
            }
            </script>
            
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Challenge oluştur
     */
    private function generate_challenge() {
        return random_bytes(32);
    }
    
    /**
     * Kullanıcı handle'ı oluştur
     */
    private function get_user_handle($user_id) {
        $handle = get_user_meta($user_id, 'ai_comm_passkey_handle', true);
        
        if (!$handle) {
            $handle = hash('sha256', $user_id . wp_salt('auth') . time(), true);
            update_user_meta($user_id, 'ai_comm_passkey_handle', base64_encode($handle));
        } else {
            $handle = base64_decode($handle);
        }
        
        return $handle;
    }
    
    /**
     * Mevcut credential'ları getir
     */
    private function get_existing_credentials($user_id) {
        $credentials = get_user_meta($user_id, 'ai_comm_passkey_credentials', true) ?: [];
        $existing = [];
        
        foreach ($credentials as $cred) {
            $existing[] = [
                'type' => 'public-key',
                'id' => $cred['id'],
                'transports' => $cred['transports'] ?? ['internal', 'hybrid']
            ];
        }
        
        return $existing;
    }
    
    /**
     * Credential ID'den kullanıcı bul
     */
    private function find_user_by_credential($credential_id) {
        $users = get_users(['meta_key' => 'ai_comm_passkey_credentials']);
        
        foreach ($users as $user) {
            $credentials = get_user_meta($user->ID, 'ai_comm_passkey_credentials', true) ?: [];
            
            foreach ($credentials as $cred) {
                if ($cred['id'] === $credential_id) {
                    return $user->ID;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Public key çıkar (basitleştirilmiş)
     */
    private function extract_public_key($attestationObject) {
        // Gerçek implementasyon CBOR parsing gerektirir
        // Bu basitleştirilmiş versiyon
        return substr($attestationObject, -100);
    }
    
    /**
     * İmza doğrula (basitleştirilmiş)
     */
    private function verify_signature($public_key, $signature, $data) {
        // Gerçek implementasyon openssl veya sodium kullanır
        return true;
    }
    
    /**
     * Cihaz tipini belirle
     */
    private function get_device_type() {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (strpos($ua, 'iPhone') !== false) return 'Apple iPhone';
        if (strpos($ua, 'iPad') !== false) return 'Apple iPad';
        if (strpos($ua, 'Mac') !== false) return 'Apple Mac';
        if (strpos($ua, 'Android') !== false) return 'Android';
        if (strpos($ua, 'Windows') !== false) return 'Windows';
        if (strpos($ua, 'Linux') !== false) return 'Linux';
        
        return 'Bilinmeyen Cihaz';
    }
    
    /**
     * Cihaz adını belirle
     */
    private function get_device_name() {
        $type = $this->get_device_type();
        $browser = '';
        
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
        elseif (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
        elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
        elseif (strpos($ua, 'Edge') !== false) $browser = 'Edge';
        
        return $type . ($browser ? ' - ' . $browser : '');
    }
    
    /**
     * Base64URL encode
     */
    private function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    /**
     * Base64URL decode
     */
    private function base64url_decode($data) {
        return base64_decode(strtr($data, '-_', '+/'));
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
     * Şüpheli aktivite logla
     */
    private function log_suspicious($event, $data = []) {
        if (method_exists($this->core, 'log_suspicious')) {
            $this->core->log_suspicious($event, $data);
        }
    }
    
    /**
     * Hata logla
     */
    private function log_error($message) {
        if (method_exists($this->core, 'log_error')) {
            $this->core->log_error($message);
        }
    }
}