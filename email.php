<?php
/**
 * AI Community Provider: Email (Magic Link)
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) exit;

// Base class kontrolü
if (!class_exists('AI_Community_Provider_Base')) {
    require_once dirname(__FILE__) . '/provider-base.php';
}

class AI_Community_Email_Provider extends AI_Community_Provider_Base {
    
    protected $config = [];
    public $slug = 'email';
    
    /**
     * Constructor - 3 parametreli
     */
    public function __construct($id, $config = [], $core = null) {
        $this->id = $id;
        $this->config = $config;
        $this->core = $core;
        
        parent::__construct($id, $config, $core);
    }
    
    /**
     * Handle - PARAMETRESİZ!
     * Ana giriş noktası
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        // Magic link callback
        if (isset($request['magic_token'])) {
            return $this->handle_callback($request);
        }
        
        // Email giriş formu göster
        return $this->show_login_form($request);
    }
    
    /**
     * Email giriş formunu göster
     */
    private function show_login_form($request) {
        ob_start();
        ?>
        <div class="ai-community-email-container">
            <h2>E-posta ile Giriş</h2>
            <p class="description">E-posta adresinize göndereceğimiz sihirli bağlantı ile giriş yapın.</p>
            
            <form method="post" action="" class="ai-community-email-form">
                <?php wp_nonce_field('email_login', 'email_nonce'); ?>
                
                <div class="form-group">
                    <label for="email">E-posta Adresi:</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           placeholder="ornek@email.com" 
                           required
                           class="email-input"
                           value="<?php echo isset($request['email']) ? esc_attr($request['email']) : ''; ?>">
                </div>
                
                <button type="submit" name="email_login" class="email-submit-btn">
                    Sihirli Bağlantı Gönder
                </button>
            </form>
            
            <div class="email-info">
                <p><strong>Nasıl çalışır?</strong></p>
                <ol>
                    <li>E-posta adresinizi girin</li>
                    <li>Size özel bir giriş bağlantısı göndereceğiz</li>
                    <li>E-postanızdaki bağlantıya tıklayarak giriş yapın</li>
                </ol>
                <p class="note">* Bağlantı 15 dakika süreyle geçerlidir.</p>
            </div>
        </div>
        
        <style>
            .ai-community-email-container {
                max-width: 450px;
                margin: 0 auto;
                padding: 30px;
                background: #fff;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .ai-community-email-container h2 {
                color: #333;
                text-align: center;
                margin-bottom: 10px;
            }
            .description {
                text-align: center;
                color: #666;
                margin-bottom: 25px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: bold;
                color: #333;
            }
            .email-input {
                width: 100%;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
                transition: border-color 0.3s;
            }
            .email-input:focus {
                border-color: #4CAF50;
                outline: none;
            }
            .email-submit-btn {
                width: 100%;
                padding: 14px;
                background: #4CAF50;
                color: white;
                border: none;
                border-radius: 5px;
                font-size: 16px;
                font-weight: bold;
                cursor: pointer;
                transition: background 0.3s;
            }
            .email-submit-btn:hover {
                background: #45a049;
            }
            .email-info {
                margin-top: 30px;
                padding: 20px;
                background: #f5f5f5;
                border-radius: 5px;
                color: #333;
            }
            .email-info ol {
                margin: 10px 0;
                padding-left: 20px;
            }
            .email-info li {
                margin-bottom: 5px;
            }
            .note {
                font-size: 13px;
                color: #666;
                margin-top: 10px;
                font-style: italic;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Callback handler - PARAMETRELİ!
     * Magic link doğrulama
     */
    public function handle_callback($request) {
        try {
            // Nonce kontrolü (opsiyonel - magic link'te nonce olmayabilir)
            if (isset($request['email_nonce']) && !wp_verify_nonce($request['email_nonce'], 'email_login')) {
                throw new Exception('Güvenlik doğrulaması başarısız.');
            }
            
            // Magic link token kontrolü
            if (isset($request['magic_token'])) {
                return $this->process_magic_token($request['magic_token']);
            }
            
            // Email giriş formu gönderildi
            if (isset($request['email_login']) && isset($request['email'])) {
                return $this->send_magic_link($request);
            }
            
            throw new Exception('Geçersiz istek');
            
        } catch (Exception $e) {
            error_log('Email provider hatası: ' . $e->getMessage());
            return $this->show_error($e->getMessage());
        }
    }
    
    /**
     * Magic link gönder
     */
    private function send_magic_link($request) {
        // Nonce kontrolü
        if (!isset($request['email_nonce']) || !wp_verify_nonce($request['email_nonce'], 'email_login')) {
            return $this->show_error('Güvenlik doğrulaması başarısız.');
        }
        
        $email = sanitize_email($request['email']);
        
        if (!is_email($email)) {
            return $this->show_error('Geçerli bir e-posta adresi girin.');
        }
        
        // Magic link token oluştur
        $token = wp_generate_password(32, false);
        $expiry = time() + (15 * MINUTE_IN_SECONDS); // 15 dakika geçerli
        
        // Token'ı kaydet
        set_transient('magic_link_' . md5($email), [
            'token' => $token,
            'email' => $email,
            'expiry' => $expiry
        ], 15 * MINUTE_IN_SECONDS);
        
        // Magic link URL'i oluştur
        $magic_url = add_query_arg([
            'oauth_provider' => $this->slug,
            'magic_token' => $token
        ], home_url('/uyelik/'));
        
        // E-posta gönder
        $sent = $this->send_magic_email($email, $magic_url);
        
        if ($sent) {
            return $this->show_success($email);
        } else {
            return $this->show_error('E-posta gönderilemedi. Lütfen tekrar deneyin.');
        }
    }
    
    /**
     * Magic link e-postası gönder
     */
    private function send_magic_email($email, $magic_url) {
        $subject = 'Sihirli Bağlantı ile Giriş Yapın';
        
        $message = "Merhaba,\n\n";
        $message .= "Hesabınıza giriş yapmak için aşağıdaki bağlantıya tıklayın:\n\n";
        $message .= $magic_url . "\n\n";
        $message .= "Bu bağlantı 15 dakika süreyle geçerlidir.\n";
        $message .= "Eğer bu giriş işlemini siz yapmadıysanız, bu e-postayı dikkate almayın.\n\n";
        $message .= "İyi günler dileriz.";
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        return wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Magic token işle
     */
    private function process_magic_token($token) {
        // Token'ı doğrula
        global $wpdb;
        
        // Tüm transient'ları tara (optimize edilebilir)
        $email = null;
        $saved_data = null;
        
        // Not: Gerçek uygulamada token'ı veritabanında saklamak daha iyi olur
        // Şimdilik transient'larda arıyoruz
        $transient_key = $wpdb->get_var($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_magic_link_%' 
             AND option_value LIKE %s 
             LIMIT 1",
            '%' . $wpdb->esc_like($token) . '%'
        ));
        
        if ($transient_key) {
            $key = str_replace('_transient_', '', $transient_key);
            $saved_data = get_transient($key);
            
            if ($saved_data && $saved_data['token'] === $token && $saved_data['expiry'] > time()) {
                $email = $saved_data['email'];
            }
        }
        
        if (!$email) {
            return $this->show_error('Geçersiz veya süresi dolmuş bağlantı.');
        }
        
        // Kullanıcıyı bul veya oluştur
        $profile = $this->create_profile_from_email($email);
        $user = $this->find_or_create_user($profile);
        
        if (is_wp_error($user)) {
            return $this->show_error($user->get_error_message());
        }
        
        // Kullanıcıyı oturum açtır
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        
        // Token'ı temizle
        delete_transient('magic_link_' . md5($email));
        
        // Başarılı giriş - yönlendir
        wp_redirect(home_url('/profil-merkezim/'));
        exit;
    }
    
    /**
     * E-postadan profil oluştur
     */
    protected function create_profile_from_email($email) {
        $profile = [
            'provider' => $this->get_name(),
            'provider_slug' => $this->get_slug(),
            'user_id' => md5($email),
            'email' => $email,
            'username' => sanitize_user(current(explode('@', $email)), true),
            'display_name' => current(explode('@', $email)),
            'verified' => true
        ];
        
        return $profile;
    }
    
    /**
     * Kullanıcı bul veya oluştur
     */
    public function find_or_create_user($profile) {
        if (empty($profile['email'])) {
            return new WP_Error('invalid_profile', 'Invalid profile data');
        }
        
        // E-posta ile kullanıcıyı bul
        $user = get_user_by('email', $profile['email']);
        
        if ($user) {
            return $user;
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $profile['email']);
        
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        
        // Kullanıcı bilgilerini güncelle
        $user_data = [
            'ID' => $user_id,
            'display_name' => $profile['display_name']
        ];
        
        wp_update_user($user_data);
        
        // Provider bağlantısını kaydet
        update_user_meta($user_id, 'ai_community_' . $this->get_slug() . '_id', $profile['user_id']);
        
        return get_user_by('id', $user_id);
    }
    
    /**
     * Unique username oluştur
     */
    protected function generate_unique_username($profile) {
        $base = !empty($profile['username']) ? $profile['username'] : 
                (!empty($profile['display_name']) ? $profile['display_name'] : 'user');
        
        $username = sanitize_user($base, true);
        $username = str_replace(' ', '_', strtolower($username));
        $username = preg_replace('/[^a-z0-9_]/', '', $username);
        
        if (empty($username)) {
            $username = 'user_' . uniqid();
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
     * Başarı mesajı göster
     */
    protected function show_success($email) {
        ob_start();
        ?>
        <div class="ai-community-success">
            <h3>✅ E-posta Gönderildi!</h3>
            <p><strong><?php echo esc_html($email); ?></strong> adresine giriş bağlantısı gönderildi.</p>
            <p>E-postanızı kontrol edin ve giriş yapmak için bağlantıya tıklayın.</p>
            <p class="note">* E-posta gelmezse spam klasörünü kontrol edin.</p>
            <p><a href="javascript:history.back()">Geri dön</a></p>
        </div>
        
        <style>
            .ai-community-success {
                max-width: 450px;
                margin: 0 auto;
                padding: 30px;
                background: #d4edda;
                color: #155724;
                border-radius: 10px;
                text-align: center;
            }
            .ai-community-success h3 {
                margin-bottom: 15px;
            }
            .ai-community-success p {
                margin-bottom: 10px;
            }
            .ai-community-success .note {
                font-size: 13px;
                color: #666;
                margin-top: 15px;
            }
            .ai-community-success a {
                display: inline-block;
                margin-top: 15px;
                color: #155724;
                text-decoration: underline;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Hata mesajı göster - PROTECTED (base class ile uyumlu)
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <div class="ai-community-error">
            <h3>Hata!</h3>
            <p><?php echo esc_html($message); ?></p>
            <p><a href="javascript:history.back()">Geri dön</a></p>
        </div>
        
        <style>
            .ai-community-error {
                max-width: 450px;
                margin: 0 auto;
                padding: 30px;
                background: #f8d7da;
                color: #721c24;
                border-radius: 10px;
                text-align: center;
            }
            .ai-community-error a {
                display: inline-block;
                margin-top: 15px;
                color: #721c24;
                text-decoration: underline;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Getter metodlar - ABSTRACT METODLAR
     */
    public function get_name() {
        return 'E-posta (Magic Link)';
    }
    
    public function get_type() {
        return 'email';
    }
    
    public function get_slug() {
        return $this->slug;
    }
}