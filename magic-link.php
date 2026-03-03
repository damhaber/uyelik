<?php
// masal-panel/modules/ai-community-engine/core/providers/magic-link.php
// EMAIL + MAGIC LINK PROVIDER - YÖNLENDİRME DÜZELTİLDİ

if (!defined('ABSPATH') || !defined('MASAL_PANEL_SECURE_TOKEN')) {
    exit("⛔ Erişim reddedildi");
}

require_once __DIR__ . '/provider-base.php';

class AI_Community_Provider_Email extends AI_Community_Provider_Base {
    
    protected $id = 'email';
    protected $label = 'E-posta ile Giriş';
    
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
    }
    
    /**
     * 🔧 ZORUNLU METOD - handle()
     * Provider isteğini yönet - DÜZENLENDİ
     */
    public function handle() {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        
        if ($method === 'POST') {
            $this->handle_post();
        } else {
            $this->handle_get();
        }
    }
    
    /**
     * POST isteklerini yönet (form gönderimleri)
     */
    private function handle_post() {
        $mode  = isset($_POST['mode']) ? sanitize_key((string)$_POST['mode']) : '';
        $email = isset($_POST['email']) ? sanitize_email((string)$_POST['email']) : '';
        $email = strtolower(trim($email));
        
        // Rate limit kontrolü (IP bazlı)
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $rate_key = 'ai_comm_email_rate_' . md5($ip);
        $attempts = get_transient($rate_key) ?: 0;
        
        if ($attempts >= 5) {
            // Çok fazla deneme - yine de "gönderildi" göster (güvenlik)
            if ($mode === 'magic') {
                $this->render_magic_sent(__('Çok fazla deneme yaptınız. Lütfen 1 saat sonra tekrar deneyin.', 'ai-community-engine'));
            } else {
                $this->render_login_form('password', ['error' => 'rate_limit']);
            }
            exit;
        }
        
        // Deneme sayısını artır
        set_transient($rate_key, $attempts + 1, HOUR_IN_SECONDS);
        
        switch ($mode) {
            case 'magic':
                $this->handle_magic_request($email);
                break;
                
            case 'password':
                $this->handle_password_login($email);
                break;
                
            default:
                $this->render_login_form('password', ['error' => 'invalid_mode']);
                break;
        }
    }
    
    /**
     * GET isteklerini yönet (sayfa gösterimleri + magic link)
     */
    private function handle_get() {
        $action = isset($_GET['email_action']) ? sanitize_key($_GET['email_action']) : '';
        $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
        
        // Magic link doğrulama
        if (!empty($token)) {
            $this->handle_magic_confirm($token);
            return;
        }
        
        // Belirli bir aksiyon var mı?
        switch ($action) {
            case 'magic-sent':
                $this->render_magic_sent();
                break;
                
            case 'password':
                $this->render_login_form('password');
                break;
                
            case 'magic':
            default:
                $this->render_login_form('magic');
                break;
        }
    }
    
    /**
     * Magic link gönderme işlemi
     */
    private function handle_magic_request($email) {
        // Nonce kontrolü
        if (!wp_verify_nonce((string)($_POST['_ai_comm_nonce'] ?? ''), 'ai_comm_email_magic')) {
            ai_community_engine_log('magic_send_blocked', ['reason' => 'bad_nonce']);
            $this->render_magic_sent(__('Güvenlik doğrulaması başarısız.', 'ai-community-engine'));
            exit;
        }
        
        // Email validasyonu
        if (empty($email) || !is_email($email)) {
            ai_community_engine_log('magic_send_blocked', ['reason' => 'invalid_email']);
            $this->render_magic_sent(); // Güvenlik: email geçersiz olsa bile "gönderildi" göster
            exit;
        }
        
        // Email'e özel rate limit
        $email_rate_key = 'ai_comm_magic_email_' . md5($email);
        $email_attempts = get_transient($email_rate_key) ?: 0;
        
        if ($email_attempts >= 3) {
            ai_community_engine_log('magic_send_rate_limited', ['email_hash' => md5($email)]);
            $this->render_magic_sent(__('Bu email için çok fazla istek yapıldı. Lütfen 1 saat sonra tekrar deneyin.', 'ai-community-engine'));
            exit;
        }
        
        set_transient($email_rate_key, $email_attempts + 1, HOUR_IN_SECONDS);
        
        // Token oluştur
        $raw = wp_generate_password(40, false, false);
        $pepper = (string)wp_salt('auth');
        $verifier = hash('sha256', $raw . '|' . $pepper);
        
        // Token'ı kaydet
        $key = 'ai_comm_magic_' . hash('sha256', $raw);
        $payload = [
            'email'   => $email,
            'hash'    => $verifier,
            'exp'     => time() + 600, // 10 dakika
            'used'    => 0,
            'ip'      => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'ua'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            'created' => time(),
        ];
        
        set_transient($key, $payload, 600);
        
        // Magic link oluştur
        $link = add_query_arg([
            'token' => $raw,
        ], home_url('/' . $this->core->get_endpoint_slug() . '/callback/email'));
        
        // Email gönder
        $site_name = get_bloginfo('name');
        $subject = sprintf(__('[%s] Giriş Bağlantınız', 'ai-community-engine'), $site_name);
        
        $message = sprintf(
            __("Merhaba,\n\n%s hesabınıza giriş yapmak için bu bağlantıya tıklayın:\n%s\n\nBu bağlantı 10 dakika geçerlidir.\n\nEğer bu isteği siz yapmadıysanız, bu emaili dikkate almayın.", 'ai-community-engine'),
            $site_name,
            $link
        );
        
        $sent = wp_mail($email, $subject, $message);
        
        ai_community_engine_log('magic_sent', [
            'email_hash' => md5($email),
            'sent'       => (bool)$sent,
        ]);
        
        // Başarılı sayfasını göster
        $this->render_magic_sent();
    }
    
    /**
     * Magic link doğrulama
     */
    private function handle_magic_confirm($token) {
        // Token validasyonu
        if (empty($token) || strlen($token) < 16 || strlen($token) > 128) {
            ai_community_engine_log('magic_confirm_failed', ['reason' => 'bad_token_shape']);
            $this->render_error_page(__('Geçersiz veya süresi dolmuş bağlantı.', 'ai-community-engine'));
            exit;
        }
        
        // Rate limit
        if (!$this->check_rate_limit('magic_confirm', 10, 600)) {
            ai_community_engine_log('magic_confirm_rate_limited', ['token_hash' => md5($token)]);
            $this->render_error_page(__('Çok fazla deneme yaptınız. Lütfen daha sonra tekrar deneyin.', 'ai-community-engine'));
            exit;
        }
        
        // Token'ı bul
        $key = 'ai_comm_magic_' . hash('sha256', $token);
        $data = get_transient($key);
        
        if (!is_array($data) || empty($data['email']) || empty($data['hash']) || empty($data['exp'])) {
            ai_community_engine_log('magic_confirm_failed', ['reason' => 'missing_record']);
            $this->render_error_page(__('Geçersiz veya süresi dolmuş bağlantı.', 'ai-community-engine'));
            exit;
        }
        
        // Süre kontrolü
        if (time() > (int)$data['exp']) {
            delete_transient($key);
            ai_community_engine_log('magic_confirm_failed', ['reason' => 'expired', 'email_hash' => md5((string)$data['email'])]);
            $this->render_error_page(__('Bağlantının süresi dolmuş. Lütfen yeni bir giriş bağlantısı isteyin.', 'ai-community-engine'));
            exit;
        }
        
        // Tek kullanımlık kontrol
        if (!empty($data['used'])) {
            delete_transient($key);
            ai_community_engine_log('magic_confirm_failed', ['reason' => 'reused', 'email_hash' => md5((string)$data['email'])]);
            $this->render_error_page(__('Bu bağlantı daha önce kullanılmış. Lütfen yeni bir bağlantı isteyin.', 'ai-community-engine'));
            exit;
        }
        
        // Token doğrulama
        $pepper = (string)wp_salt('auth');
        $calc = hash('sha256', $token . '|' . $pepper);
        
        if (!hash_equals((string)$data['hash'], $calc)) {
            ai_community_engine_log('magic_confirm_failed', ['reason' => 'hash_mismatch', 'email_hash' => md5((string)$data['email'])]);
            $this->render_error_page(__('Geçersiz bağlantı.', 'ai-community-engine'));
            exit;
        }
        
        // Kullanıldı olarak işaretle ve sil
        $data['used'] = 1;
        set_transient($key, $data, 60);
        delete_transient($key);
        
        $email = strtolower(trim(sanitize_email((string)$data['email'])));
        
        // Kullanıcıyı bul veya oluştur
        $user = get_user_by('email', $email);
        $settings = ai_community_engine_get_settings();
        
        if (!$user) {
            // Kayıt açık mı?
            $reg_enabled = (bool)($settings['registration']['enabled'] ?? false);
            
            if (!$reg_enabled) {
                ai_community_engine_log('magic_confirm_blocked', ['reason' => 'registration_disabled', 'email_hash' => md5($email)]);
                $this->render_error_page(__('Kayıt işlemi kapalı. Lütfen site yöneticisi ile iletişime geçin.', 'ai-community-engine'));
                exit;
            }
            
            // Yeni kullanıcı oluştur
            $user_id = $this->create_user_from_email($email);
            
            if (!$user_id) {
                ai_community_engine_log('magic_confirm_failed', ['reason' => 'user_create_failed', 'email_hash' => md5($email)]);
                $this->render_error_page(__('Kullanıcı oluşturulamadı. Lütfen daha sonra tekrar deneyin.', 'ai-community-engine'));
                exit;
            }
            
            $user = get_user_by('ID', $user_id);
        }
        
        // Giriş yap
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        
        // Son giriş zamanını güncelle
        update_user_meta($user->ID, 'ai_comm_last_login', time());
        update_user_meta($user->ID, 'ai_comm_last_login_method', 'magic_link');
        
        ai_community_engine_log('magic_confirm_success', [
            'user_id' => $user->ID,
            'email_hash' => md5($email)
        ]);
        
        // Yönlendirme yap - DÜZELTİLDİ
        $redirect = $this->get_login_redirect($user);
        
        // Güvenli yönlendirme
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * Şifreli giriş işlemi
     */
    private function handle_password_login($email) {
        // Nonce kontrolü
        if (!wp_verify_nonce((string)($_POST['_ai_comm_nonce'] ?? ''), 'ai_comm_email_password')) {
            $this->render_login_form('password', ['error' => 'invalid_nonce']);
            exit;
        }
        
        $password = (string)($_POST['password'] ?? '');
        
        // Validasyon
        if (empty($email) || !is_email($email) || empty($password)) {
            $this->render_login_form('password', ['error' => 'invalid_input']);
            exit;
        }
        
        // Kullanıcıyı bul
        $user = get_user_by('email', $email);
        
        if (!$user || !wp_check_password($password, $user->user_pass, $user->ID)) {
            // Başarısız giriş log'u
            ai_community_engine_log('password_login_failed', ['email_hash' => md5($email)]);
            $this->render_login_form('password', ['error' => 'invalid_credentials']);
            exit;
        }
        
        // Giriş yap
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, isset($_POST['remember']));
        
        // Son giriş zamanını güncelle
        update_user_meta($user->ID, 'ai_comm_last_login', time());
        update_user_meta($user->ID, 'ai_comm_last_login_method', 'password');
        
        ai_community_engine_log('password_login_success', ['user_id' => $user->ID]);
        
        // Yönlendirme yap - DÜZELTİLDİ
        $redirect = $this->get_login_redirect($user);
        wp_safe_redirect($redirect);
        exit;
    }
    
    /**
     * Giriş sonrası yönlendirme URL'sini belirle
     */
    private function get_login_redirect($user) {
        // 2FA kontrolü
        if ($this->core && method_exists($this->core, 'is_2fa_required')) {
            $twofactor = $this->core->get_provider('twofactor');
            if ($twofactor) {
                $result = $twofactor->start_auth($user->ID);
                if (is_array($result) && isset($result['redirect'])) {
                    return $result['redirect'];
                }
            }
        }
        
        // Varsayılan yönlendirme
        $default_redirect = home_url('/' . $this->core->get_endpoint_slug() . '/account/');
        
        // Admin panelinde tanımlanmış yönlendirme var mı?
        $settings = get_option('ai_community_redirect_rules', []);
        
        if (!empty($settings['login_default'])) {
            return $settings['login_default'];
        }
        
        // Rol bazlı yönlendirme
        if (!empty($settings['role_based']) && is_array($settings['role_based'])) {
            foreach ($settings['role_based'] as $role => $url) {
                if (in_array($role, (array)$user->roles)) {
                    return $url;
                }
            }
        }
        
        return $default_redirect;
    }
    
    /**
     * Email'den yeni kullanıcı oluştur
     */
    private function create_user_from_email($email) {
        $username = sanitize_user(current(explode('@', $email)), true);
        
        // Username benzersiz mi kontrol et
        $base_username = $username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        // Rastgele şifre oluştur (kullanılmayacak)
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            return false;
        }
        
        // Varsayılan rolü ata
        $default_role = get_option('default_role', 'subscriber');
        $user = new WP_User($user_id);
        $user->set_role($default_role);
        
        return $user_id;
    }
    
    /**
     * Rate limit kontrolü
     */
    private function check_rate_limit($action, $limit, $window) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $key = 'ai_comm_rate_' . $action . '_' . md5($ip);
        
        $count = get_transient($key) ?: 0;
        
        if ($count >= $limit) {
            return false;
        }
        
        set_transient($key, $count + 1, $window);
        return true;
    }
    
    /**
     * Giriş formunu render et
     */
    private function render_login_form($mode = 'magic', $params = []) {
        $error = isset($params['error']) ? $params['error'] : (isset($_GET['error']) ? sanitize_key($_GET['error']) : '');
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        $error_message = $this->get_error_message($error);
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('E-posta ile Giriş', 'ai-community-engine'); ?></title>
            <?php wp_head(); ?>
            <style>
                body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .ai-email-container { max-width: 400px; width: 90%; background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); padding: 30px; }
                .ai-email-header { text-align: center; margin-bottom: 30px; }
                .ai-email-header h1 { color: #23282d; font-size: 24px; margin: 0 0 10px; }
                .ai-email-tabs { display: flex; margin-bottom: 20px; border-bottom: 2px solid #e9ecef; }
                .ai-email-tab { flex: 1; text-align: center; padding: 10px; cursor: pointer; color: #6c757d; text-decoration: none; }
                .ai-email-tab.active { color: #2271b1; border-bottom: 2px solid #2271b1; margin-bottom: -2px; }
                .ai-email-error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; }
                .ai-email-info { background: #d1ecf1; color: #0c5460; padding: 12px; border-radius: 6px; margin-bottom: 20px; text-align: center; }
                .ai-email-form input[type="email"], .ai-email-form input[type="password"] { width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 6px; margin-bottom: 15px; font-size: 16px; }
                .ai-email-form input[type="checkbox"] { margin-right: 8px; }
                .ai-email-form button { width: 100%; padding: 12px; background: #2271b1; color: white; border: none; border-radius: 6px; font-size: 16px; font-weight: 500; cursor: pointer; transition: background 0.2s; }
                .ai-email-form button:hover { background: #135e96; }
                .ai-email-form button.magic { background: #28a745; }
                .ai-email-form button.magic:hover { background: #218838; }
                .ai-email-footer { margin-top: 20px; text-align: center; }
                .ai-email-footer a { color: #666; text-decoration: none; font-size: 14px; }
                .ai-email-footer a:hover { color: #2271b1; }
                .ai-email-note { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px; font-size: 13px; color: #6c757d; }
                .remember-me { margin-bottom: 15px; color: #495057; }
            </style>
        </head>
        <body>
            <div class="ai-email-container">
                <div class="ai-email-header">
                    <h1>📧 <?php _e('E-posta ile Giriş', 'ai-community-engine'); ?></h1>
                    <p><?php _e('Hesabınıza erişmek için email adresinizi kullanın', 'ai-community-engine'); ?></p>
                </div>
                
                <div class="ai-email-tabs">
                    <a href="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/?email_action=magic')); ?>" 
                       class="ai-email-tab <?php echo $mode === 'magic' ? 'active' : ''; ?>">
                        🔗 Magic Link
                    </a>
                    <a href="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/?email_action=password')); ?>" 
                       class="ai-email-tab <?php echo $mode === 'password' ? 'active' : ''; ?>">
                        🔐 Şifre
                    </a>
                </div>
                
                <?php if ($error_message): ?>
                <div class="ai-email-error">
                    <?php echo esc_html($error_message); ?>
                </div>
                <?php endif; ?>
                
                <?php if ($mode === 'magic'): ?>
                <div class="ai-email-info">
                    ✨ Size giriş bağlantısı göndereceğiz. Telefonunuza gelen kodu girmenize gerek yok.
                </div>
                
                <form method="post" class="ai-email-form" action="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/callback/email')); ?>">
                    <?php wp_nonce_field('ai_comm_email_magic'); ?>
                    <input type="hidden" name="mode" value="magic">
                    <input type="email" 
                           name="email" 
                           placeholder="ornek@email.com" 
                           value="<?php echo esc_attr($email); ?>"
                           required 
                           autofocus>
                    <button type="submit" class="magic">📨 Magic Link Gönder</button>
                </form>
                <?php endif; ?>
                
                <?php if ($mode === 'password'): ?>
                <form method="post" class="ai-email-form" action="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/callback/email')); ?>">
                    <?php wp_nonce_field('ai_comm_email_password'); ?>
                    <input type="hidden" name="mode" value="password">
                    <input type="email" 
                           name="email" 
                           placeholder="E-posta adresiniz" 
                           value="<?php echo esc_attr($email); ?>"
                           required 
                           autofocus>
                    <input type="password" 
                           name="password" 
                           placeholder="Şifreniz" 
                           required>
                    <div class="remember-me">
                        <label>
                            <input type="checkbox" name="remember" value="1">
                            <?php _e('Beni hatırla', 'ai-community-engine'); ?>
                        </label>
                    </div>
                    <button type="submit">🔐 Giriş Yap</button>
                </form>
                
                <div class="ai-email-footer">
                    <a href="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/?email_action=magic')); ?>">
                        ← <?php _e('Magic Link ile giriş yap', 'ai-community-engine'); ?>
                    </a>
                </div>
                <?php endif; ?>
                
                <div class="ai-email-note">
                    <strong>📝 Not:</strong> Hesabınız yoksa, giriş yaparken otomatik oluşturulacaktır.
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * "Mail gönderildi" sayfasını render et
     */
    private function render_magic_sent($message = '') {
        $default_message = __('Eğer bu email kayıtlıysa, giriş bağlantısı gönderildi. Lütfen gelen kutunuzu kontrol edin.', 'ai-community-engine');
        $display_message = !empty($message) ? $message : $default_message;
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('Mail Gönderildi', 'ai-community-engine'); ?></title>
            <?php wp_head(); ?>
            <style>
                body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .ai-sent-container { max-width: 400px; width: 90%; background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); padding: 40px 30px; text-align: center; }
                .ai-sent-icon { font-size: 64px; margin-bottom: 20px; }
                .ai-sent-title { color: #23282d; font-size: 24px; margin: 0 0 15px; }
                .ai-sent-message { color: #495057; font-size: 16px; line-height: 1.6; margin-bottom: 30px; }
                .ai-sent-button { display: inline-block; padding: 12px 30px; background: #2271b1; color: white; text-decoration: none; border-radius: 6px; font-size: 16px; transition: background 0.2s; }
                .ai-sent-button:hover { background: #135e96; }
                .ai-sent-footer { margin-top: 30px; color: #6c757d; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="ai-sent-container">
                <div class="ai-sent-icon">📨</div>
                <h1 class="ai-sent-title"><?php _e('Mail Gönderildi!', 'ai-community-engine'); ?></h1>
                <div class="ai-sent-message">
                    <?php echo esc_html($display_message); ?>
                </div>
                <a href="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/login/')); ?>" class="ai-sent-button">
                    ← <?php _e('Giriş sayfasına dön', 'ai-community-engine'); ?>
                </a>
                <div class="ai-sent-footer">
                    ✉️ Spam klasörünü kontrol etmeyi unutma
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Hata sayfasını render et
     */
    private function render_error_page($message) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('Hata', 'ai-community-engine'); ?></title>
            <?php wp_head(); ?>
            <style>
                body { background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .ai-error-container { max-width: 400px; width: 90%; background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); padding: 40px 30px; text-align: center; }
                .ai-error-icon { font-size: 64px; margin-bottom: 20px; }
                .ai-error-title { color: #dc3545; font-size: 24px; margin: 0 0 15px; }
                .ai-error-message { color: #495057; font-size: 16px; line-height: 1.6; margin-bottom: 30px; }
                .ai-error-button { display: inline-block; padding: 12px 30px; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-size: 16px; transition: background 0.2s; }
                .ai-error-button:hover { background: #5a6268; }
            </style>
        </head>
        <body>
            <div class="ai-error-container">
                <div class="ai-error-icon">⚠️</div>
                <h1 class="ai-error-title"><?php _e('Bir Hata Oluştu', 'ai-community-engine'); ?></h1>
                <div class="ai-error-message">
                    <?php echo esc_html($message); ?>
                </div>
                <a href="<?php echo esc_url(home_url('/' . $this->core->get_endpoint_slug() . '/login/')); ?>" class="ai-error-button">
                    ← <?php _e('Giriş sayfasına dön', 'ai-community-engine'); ?>
                </a>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Hata mesajlarını al
     */
    private function get_error_message($error_code) {
        $messages = [
            'invalid_nonce' => '🔒 Güvenlik doğrulaması başarısız. Lütfen tekrar deneyin.',
            'invalid_input' => '⚠️ Lütfen geçerli bir e-posta ve şifre girin.',
            'invalid_credentials' => '❌ E-posta veya şifre hatalı.',
            'rate_limit' => '⏰ Çok fazla deneme yaptınız. Lütfen 1 saat sonra tekrar deneyin.',
            'invalid_mode' => 'Geçersiz giriş yöntemi.'
        ];
        
        return isset($messages[$error_code]) ? $messages[$error_code] : '';
    }
}