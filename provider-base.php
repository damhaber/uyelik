<?php
/**
 * AI Community Provider Base Classes
 * 
 * @package AI_Community
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Ana Provider Base Sınıfı
 */
abstract class AI_Community_Provider_Base {

    protected $id;
    protected $label;
    protected $config;
    protected $core;
    protected $slug;

    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider yapılandırması
     * @param AI_Community_Core $core Core nesnesi
     */
    public function __construct($id, $config, $core) {
        $this->id     = $id;
        $this->slug   = $id;
        $this->config = is_array($config) ? $config : [];
        $this->core   = $core;
        $this->label  = isset($config['label']) ? $config['label'] : ucfirst($id);
    }

    /**
     * Provider ID'sini getir
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Provider slug'ını getir
     */
    public function get_slug() {
        return $this->slug;
    }

    /**
     * Provider etiketini getir
     */
    public function get_label() {
        return $this->label;
    }

    /**
     * Provider adını getir (get_label ile aynı)
     */
    public function get_name() {
        return $this->label;
    }

    /**
     * Provider yapılandırmasını getir
     */
    public function get_config() {
        return $this->config;
    }

    /**
     * Provider aktif mi?
     */
    public function is_active() {
        return !empty($this->config['active']);
    }

    /**
     * Provider durumunu getir
     */
    public function get_status() {
        if (!$this->is_active()) {
            return 'inactive';
        }

        // Email / magic gibi client_id gerektirmeyen provider'lar
        if ($this->id === 'email' || $this->id === 'magic' || $this->id === 'qr' || $this->id === 'passkey') {
            return 'ready';
        }

        // OAuth2 provider'lar için client_id ve client_secret kontrolü
        if ($this instanceof AI_Community_OAuth2_Provider) {
            if (empty($this->config['client_id']) || empty($this->config['client_secret'])) {
                return 'missing_config';
            }
        }

        // SOS provider'lar için API key kontrolü
        if ($this instanceof AI_Community_SOS_Provider) {
            if (empty($this->config['api_key']) && empty($this->config['bot_token'])) {
                return 'missing_config';
            }
        }

        return 'ready';
    }

    /**
     * Yönlendirme URI'ini getir
     */
    public function get_redirect_uri() {
        $endpoint = $this->core instanceof AI_Community_Core
            ? $this->core->get_endpoint_slug()
            : 'ai-community';

        return add_query_arg(
            ['provider' => $this->id],
            home_url('/' . $endpoint . '/')
        );
    }

    /**
     * Provider tipini getir (alt sınıflar override edebilir)
     */
    public function get_type() {
        return 'base';
    }

    /**
     * Ana handler metodu - Tüm provider'lar implement etmeli
     */
    abstract public function handle();

    /**
     * Hata mesajı göster
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
                max-width: 500px;
                margin: 50px auto;
                padding: 20px;
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                border-radius: 5px;
                color: #721c24;
                text-align: center;
            }
            .ai-community-error a {
                color: #721c24;
                text-decoration: underline;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Başarı mesajı göster
     */
    protected function show_success($message) {
        ob_start();
        ?>
        <div class="ai-community-success">
            <h3>Başarılı!</h3>
            <p><?php echo esc_html($message); ?></p>
        </div>
        <style>
            .ai-community-success {
                max-width: 500px;
                margin: 50px auto;
                padding: 20px;
                background: #d4edda;
                border: 1px solid #c3e6cb;
                border-radius: 5px;
                color: #155724;
                text-align: center;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Profili session'a kaydet
     */
    protected function save_profile_to_session($profile) {
        if (!session_id()) {
            session_start();
        }
        
        $_SESSION['ai_community_profile'] = $profile;
        $_SESSION['ai_community_provider'] = $this->id;
        $_SESSION['ai_community_login_time'] = time();
    }

    /**
     * WordPress kullanıcısı bul veya oluştur
     */
    protected function find_or_create_user($profile) {
        // Provider ID'ye göre kullanıcı ara
        if (!empty($profile['id'])) {
            $user_query = new WP_User_Query([
                'meta_key' => 'ai_comm_provider_' . $this->id . '_id',
                'meta_value' => $profile['id'],
                'number' => 1,
                'fields' => 'ID'
            ]);
            
            $users = $user_query->get_results();
            
            if (!empty($users)) {
                return $users[0];
            }
        }
        
        // Email varsa email'e göre ara
        if (!empty($profile['email']) && is_email($profile['email'])) {
            $user = get_user_by('email', $profile['email']);
            if ($user) {
                // Provider ID'sini kaydet
                update_user_meta($user->ID, 'ai_comm_provider_' . $this->id . '_id', $profile['id']);
                update_user_meta($user->ID, 'ai_comm_provider_' . $this->id . '_email', $profile['email']);
                update_user_meta($user->ID, 'ai_comm_provider_' . $this->id . '_joined_at', current_time('mysql'));
                return $user->ID;
            }
        }
        
        // Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($profile);
        $email = $this->generate_email($profile);
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            error_log($this->id . ' kullanıcı oluşturma hatası: ' . $user_id->get_error_message());
            return false;
        }
        
        // Meta verileri kaydet
        if (!empty($profile['id'])) {
            update_user_meta($user_id, 'ai_comm_provider_' . $this->id . '_id', $profile['id']);
        }
        update_user_meta($user_id, 'ai_comm_provider_' . $this->id . '_email', $email);
        update_user_meta($user_id, 'ai_comm_provider_' . $this->id . '_joined_at', current_time('mysql'));
        update_user_meta($user_id, 'ai_community_provider', $this->id);
        update_user_meta($user_id, 'ai_community_profile', $profile);
        
        if (!empty($profile['name'])) {
            update_user_meta($user_id, 'nickname', $profile['name']);
            wp_update_user([
                'ID' => $user_id,
                'display_name' => $profile['name']
            ]);
        }
        
        if (!empty($profile['avatar'])) {
            update_user_meta($user_id, 'ai_community_avatar', $profile['avatar']);
        }
        
        return $user_id;
    }

    /**
     * Benzersiz kullanıcı adı oluştur
     */
    protected function generate_unique_username($profile) {
        $base = '';
        
        if (!empty($profile['username'])) {
            $base = sanitize_user($profile['username'], true);
        } elseif (!empty($profile['name'])) {
            $base = sanitize_user($profile['name'], true);
        } else {
            $base = $this->id . '_user';
        }
        
        $base = strtolower(preg_replace('/[^a-z0-9]/', '', $base));
        
        if (empty($base) || strlen($base) < 3) {
            $base = $this->id;
        }
        
        $username = $base;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }

    /**
     * Email oluştur
     */
    protected function generate_email($profile) {
        if (!empty($profile['email']) && is_email($profile['email'])) {
            return $profile['email'];
        }
        
        if (!empty($profile['id'])) {
            return $this->id . '_' . md5($profile['id']) . '@social.local';
        }
        
        return $this->id . '_' . wp_generate_password(8, false) . '@social.local';
    }
}

/**
 * OAuth2 Provider Base Sınıfı
 * NOT: Bu sınıf sadece burada TANIMLANMAMIŞTIR.
 * oauth2-base.php dosyasında tanımlanmıştır.
 * Bu dosya sadece diğer provider tiplerini içerir.
 */

/**
 * SOS Provider Base Sınıfı
 */
abstract class AI_Community_SOS_Provider extends AI_Community_Provider_Base {
    
    /**
     * SMS/WhatsApp/Telegram mesajı gönder
     */
    abstract public function send_sms($phone, $code);
    
    /**
     * Kodu doğrula
     */
    abstract public function verify_code($phone, $code);
    
    /**
     * Kodu kaydet
     */
    protected function save_verification_code($phone, $code, $expiry = 600) {
        set_transient('sos_code_' . md5($phone), $code, $expiry);
        set_transient('sos_code_expiry_' . md5($phone), time() + $expiry, $expiry);
    }
    
    /**
     * Kodu getir
     */
    protected function get_verification_code($phone) {
        return get_transient('sos_code_' . md5($phone));
    }
    
    /**
     * Kodu sil
     */
    protected function delete_verification_code($phone) {
        delete_transient('sos_code_' . md5($phone));
        delete_transient('sos_code_expiry_' . md5($phone));
    }
    
    /**
     * Rate limiting kontrolü
     */
    protected function check_rate_limit($phone) {
        $key = 'sos_rate_' . md5($phone);
        $attempts = get_transient($key) ?: 0;
        
        if ($attempts >= 3) {
            return false; // Çok fazla deneme
        }
        
        set_transient($key, $attempts + 1, 300); // 5 dakika
        return true;
    }
    
    /**
     * Telefon numarasını formatla
     */
    protected function format_phone_number($phone) {
        // Sadece rakamlar ve + işareti kalacak
        return preg_replace('/[^0-9+]/', '', $phone);
    }
    
    /**
     * Telefon numarasından profil oluştur
     */
    protected function create_profile_from_phone($phone) {
        return [
            'id' => md5($phone . time()),
            'phone' => $phone,
            'username' => $this->id . '_' . md5($phone),
            'name' => $this->get_name() . ' Kullanıcı',
            'provider' => $this->id,
            'provider_name' => $this->get_name(),
            'verified' => true,
            'login_method' => $this->id,
            'login_time' => time()
        ];
    }
    
    /**
     * Provider tipini getir
     */
    public function get_type() {
        return 'sos';
    }
}

/**
 * QR Login Provider Base Sınıfı
 */
abstract class AI_Community_QR_Provider extends AI_Community_Provider_Base {
    
    /**
     * QR token oluştur
     */
    public function generate_token() {
        return wp_generate_password(32, false);
    }
    
    /**
     * QR URL oluştur
     */
    public function generate_qr_url($token) {
        return add_query_arg([
            'qr_token' => $token,
            'action' => 'qr_login',
        ], home_url('/' . $this->core->get_endpoint_slug() . '/'));
    }
    
    /**
     * Token doğrula
     */
    public function verify_token($token, $user_id = null) {
        $qr_data = get_transient('ai_comm_qr_' . $token);
        
        if (!$qr_data || !is_array($qr_data)) {
            return false;
        }
        
        if (time() > $qr_data['expires']) {
            delete_transient('ai_comm_qr_' . $token);
            return false;
        }
        
        if ($user_id) {
            // Kullanıcı token'ı doğruluyor
            if (wp_hash($token) === $qr_data['token']) {
                $qr_data['status'] = 'verified';
                $qr_data['user_id'] = $user_id;
                set_transient('ai_comm_qr_' . $token, $qr_data, 2 * MINUTE_IN_SECONDS);
                return true;
            }
        } else {
            // QR tarama durumunu kontrol et
            return $qr_data['status'] ?? false;
        }
        
        return false;
    }
    
    /**
     * Cihaz eşle
     */
    public function pair_device($user_id, $device_name, $token) {
        $devices = get_user_meta($user_id, 'ai_comm_paired_devices', true);
        if (!is_array($devices)) {
            $devices = [];
        }
        
        $devices[] = [
            'id' => wp_generate_password(12, false),
            'name' => sanitize_text_field($device_name),
            'token_hash' => wp_hash($token),
            'paired_at' => current_time('mysql'),
            'last_used_at' => null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        
        // Son 10 cihazı tut
        if (count($devices) > 10) {
            $devices = array_slice($devices, -10);
        }
        
        update_user_meta($user_id, 'ai_comm_paired_devices', $devices);
        
        return true;
    }
    
    /**
     * Provider tipini getir
     */
    public function get_type() {
        return 'qr';
    }
}

/**
 * Passkey Provider Base Sınıfı (WebAuthn)
 */
abstract class AI_Community_Passkey_Provider extends AI_Community_Provider_Base {
    
    /**
     * Passkey kayıt başlat
     */
    public function begin_registration($user_id) {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return false;
        }
        
        // Burada WebAuthn kütüphanesi entegrasyonu olacak
        // Basit bir challenge oluştur
        $challenge = wp_generate_password(32, false);
        
        // Geçici olarak kaydet
        update_user_meta($user_id, 'ai_comm_passkey_challenge', [
            'challenge' => $challenge,
            'expires' => time() + 300,
        ]);
        
        return [
            'challenge' => $challenge,
            'rp' => [
                'name' => get_bloginfo('name'),
                'id' => parse_url(home_url(), PHP_URL_HOST)
            ],
            'user' => [
                'id' => base64_encode(hash('sha256', $user_id, true)),
                'name' => $user->user_login,
                'displayName' => $user->display_name
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257]  // RS256
            ],
            'timeout' => 60000,
            'attestation' => 'none'
        ];
    }
    
    /**
     * Passkey kayıt tamamla
     */
    public function complete_registration($user_id, $response) {
        $challenge_data = get_user_meta($user_id, 'ai_comm_passkey_challenge', true);
        if (!$challenge_data || time() > $challenge_data['expires']) {
            delete_user_meta($user_id, 'ai_comm_passkey_challenge');
            return false;
        }
        
        // Basit başarılı kabul et (gerçek WebAuthn doğrulaması burada yapılmalı)
        $passkeys = get_user_meta($user_id, 'ai_comm_passkeys', true);
        if (!is_array($passkeys)) {
            $passkeys = [];
        }
        
        $passkeys[] = [
            'id' => isset($response['id']) ? $response['id'] : wp_generate_password(16, false),
            'name' => 'Passkey ' . (count($passkeys) + 1),
            'created_at' => current_time('mysql'),
            'last_used_at' => null,
        ];
        
        update_user_meta($user_id, 'ai_comm_passkeys', $passkeys);
        delete_user_meta($user_id, 'ai_comm_passkey_challenge');
        
        return true;
    }
    
    /**
     * Provider tipini getir
     */
    public function get_type() {
        return 'passkey';
    }
}

/**
 * Two-Factor Provider Base Sınıfı
 */
abstract class AI_Community_TwoFactor_Provider extends AI_Community_Provider_Base {
    
    /**
     * 2FA secret oluştur
     */
    public function generate_secret($user_id) {
        // Basit secret oluştur (gerçek Google Authenticator için özel kütüphane gerekir)
        $secret = strtoupper(wp_generate_password(16, false));
        
        update_user_meta($user_id, 'ai_comm_2fa_secret', $secret);
        
        return $secret;
    }
    
    /**
     * QR kod URL'i oluştur
     */
    public function get_qr_code_url($user_id, $secret) {
        $user = get_user_by('id', $user_id);
        $issuer = get_bloginfo('name');
        $email = $user->user_email;
        
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . 
               urlencode('otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email) . 
                        '?secret=' . $secret . '&issuer=' . rawurlencode($issuer));
    }
    
    /**
     * 2FA kod doğrula (basit versiyon)
     */
    public function verify_code($user_id, $code) {
        $secret = get_user_meta($user_id, 'ai_comm_2fa_secret', true);
        if (!$secret) {
            return false;
        }
        
        // Basit doğrulama - gerçek TOTP için özel algoritma gerekir
        // Bu sadece örnek amaçlıdır
        return strlen($code) === 6 && ctype_digit($code);
    }
    
    /**
     * 2FA aktifleştir
     */
    public function enable($user_id, $code) {
        if ($this->verify_code($user_id, $code)) {
            update_user_meta($user_id, 'ai_comm_2fa_enabled', 1);
            
            // Kurtarma kodları oluştur
            $backup_codes = [];
            for ($i = 0; $i < 10; $i++) {
                $backup_codes[] = wp_generate_password(10, false);
            }
            update_user_meta($user_id, 'ai_comm_2fa_backup_codes', $backup_codes);
            
            return true;
        }
        return false;
    }
    
    /**
     * 2FA devre dışı bırak
     */
    public function disable($user_id) {
        delete_user_meta($user_id, 'ai_comm_2fa_secret');
        delete_user_meta($user_id, 'ai_comm_2fa_enabled');
        delete_user_meta($user_id, 'ai_comm_2fa_backup_codes');
        
        return true;
    }
    
    /**
     * Provider tipini getir
     */
    public function get_type() {
        return 'twofactor';
    }
}

/**
 * WhatsApp SOS Provider Base Sınıfı (SOS'tan türetilmiş)
 */
abstract class AI_Community_WhatsApp_SOS_Provider extends AI_Community_SOS_Provider {
    
    protected $api_url = 'https://graph.facebook.com/v17.0/';
    
    /**
     * WhatsApp mesajı gönder
     */
    public function send_whatsapp_message($to, $message) {
        $phone_number_id = $this->config['phone_number_id'] ?? '';
        $api_key = $this->config['api_key'] ?? '';
        
        if (empty($phone_number_id) || empty($api_key)) {
            return false;
        }
        
        $response = wp_remote_post($this->api_url . $phone_number_id . '/messages', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message
                ]
            ]),
            'timeout' => 10,
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300);
    }
    
    /**
     * SMS gönder (interface zorunluluğu)
     */
    public function send_sms($phone, $code) {
        $message = "AI Community doğrulama kodunuz: {$code}";
        return $this->send_whatsapp_message($phone, $message);
    }
}

/**
 * Telegram SOS Provider Base Sınıfı (SOS'tan türetilmiş)
 */
abstract class AI_Community_Telegram_SOS_Provider extends AI_Community_SOS_Provider {
    
    protected $api_url = 'https://api.telegram.org/bot';
    
    /**
     * Telegram mesajı gönder
     */
    public function send_telegram_message($chat_id, $message) {
        $bot_token = $this->config['bot_token'] ?? '';
        
        if (empty($bot_token)) {
            return false;
        }
        
        $url = $this->api_url . $bot_token . '/sendMessage';
        
        $response = wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML',
            ],
            'timeout' => 10,
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['ok']) && $body['ok'] === true;
    }
    
    /**
     * SMS gönder (interface zorunluluğu)
     */
    public function send_sms($phone, $code) {
        // Telegram için SMS gönderme mantığı farklı
        // Bu metod override edilmeli
        return false;
    }
}

// Eski kodlarla uyumluluk için alias
if (!class_exists('AI_Community_Provider')) {
    class_alias('AI_Community_Provider_Base', 'AI_Community_Provider');
}
?>