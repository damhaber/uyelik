<?php
/**
 * Netflix SOS Provider
 * 
 * Kurtarma girişi için SMS/Email doğrulama
 */

class AI_Community_Provider_Netflix_SOS extends AI_Community_SOS_Provider {
    
    public $slug = 'netflix-sos';
    protected $name = 'Netflix (SOS)';
    
    /**
     * Constructor
     * 
     * @param string $id Provider ID
     * @param array $config Provider config
     * @param object $core Core framework instance
     */
    public function __construct($id, $config, $core) {
        parent::__construct($id, $config, $core);
        
        // Netflix'e özel ayarlar
        $this->methods = ['phone', 'email']; // SMS veya email ile kurtarma
        $this->code_length = 6; // 6 haneli kod
        $this->code_expiry = 600; // 10 dakika
        $this->max_attempts = 3; // Maksimum deneme
    }
    
    /**
     * Ana handler
     * Tüm istekler buraya gelir (parametresiz)
     */
    public function handle() {
        $request = array_merge($_GET, $_POST);
        
        // Nonce kontrolü (POST ise)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($request['_wpnonce']) || !wp_verify_nonce($request['_wpnonce'], 'netflix_sos_action')) {
                return $this->show_error('Güvenlik doğrulaması başarısız.');
            }
        }
        
        // Callback mi? (kod doğrulama)
        if (isset($request['code']) && isset($request['identifier'])) {
            return $this->handle_callback($request);
        }
        
        // Auth formunu göster
        return $this->handle_auth($request);
    }
    
    /**
     * Yetkilendirme formunu göster
     * 
     * @param array $request İstek parametreleri
     * @return string HTML çıktı
     */
    public function handle_auth($request) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Netflix Hesap Kurtarma</title>
            <?php wp_head(); ?>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .netflix-sos-container {
                    max-width: 450px;
                    width: 100%;
                }
                
                .netflix-sos-card {
                    background: white;
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    overflow: hidden;
                }
                
                .netflix-sos-header {
                    background: #E50914;
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                
                .netflix-sos-header h1 {
                    font-size: 28px;
                    margin-bottom: 10px;
                    font-weight: 600;
                }
                
                .netflix-sos-header p {
                    opacity: 0.9;
                    font-size: 14px;
                }
                
                .netflix-sos-body {
                    padding: 30px;
                }
                
                .netflix-sos-form {
                    display: none;
                }
                
                .netflix-sos-form.active {
                    display: block;
                }
                
                .netflix-sos-tabs {
                    display: flex;
                    margin-bottom: 25px;
                    border-bottom: 2px solid #e0e0e0;
                }
                
                .netflix-sos-tab {
                    flex: 1;
                    padding: 12px;
                    text-align: center;
                    cursor: pointer;
                    color: #666;
                    font-weight: 500;
                    transition: all 0.3s ease;
                }
                
                .netflix-sos-tab.active {
                    color: #E50914;
                    border-bottom: 2px solid #E50914;
                    margin-bottom: -2px;
                }
                
                .form-group {
                    margin-bottom: 20px;
                }
                
                .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    color: #333;
                    font-weight: 500;
                    font-size: 14px;
                }
                
                .form-control {
                    width: 100%;
                    padding: 12px 15px;
                    border: 2px solid #e0e0e0;
                    border-radius: 10px;
                    font-size: 16px;
                    transition: border-color 0.3s ease;
                }
                
                .form-control:focus {
                    outline: none;
                    border-color: #E50914;
                }
                
                .form-control.error {
                    border-color: #E50914;
                }
                
                .btn {
                    width: 100%;
                    padding: 14px;
                    background: #E50914;
                    color: white;
                    border: none;
                    border-radius: 10px;
                    font-size: 16px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: background 0.3s ease;
                }
                
                .btn:hover {
                    background: #b80710;
                }
                
                .btn:disabled {
                    background: #ccc;
                    cursor: not-allowed;
                }
                
                .btn-secondary {
                    background: #666;
                    margin-top: 10px;
                }
                
                .btn-secondary:hover {
                    background: #555;
                }
                
                .error-message {
                    background: #ffebee;
                    color: #E50914;
                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    border-left: 4px solid #E50914;
                }
                
                .success-message {
                    background: #e8f5e8;
                    color: #2e7d32;
                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    border-left: 4px solid #2e7d32;
                }
                
                .info-message {
                    background: #e3f2fd;
                    color: #1976d2;
                    padding: 12px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                    font-size: 14px;
                    border-left: 4px solid #1976d2;
                }
                
                .verification-inputs {
                    display: flex;
                    gap: 10px;
                    justify-content: center;
                    margin: 20px 0;
                }
                
                .verification-input {
                    width: 50px;
                    height: 60px;
                    text-align: center;
                    font-size: 24px;
                    border: 2px solid #e0e0e0;
                    border-radius: 10px;
                }
                
                .verification-input:focus {
                    border-color: #E50914;
                    outline: none;
                }
                
                .countdown {
                    text-align: center;
                    margin: 15px 0;
                    color: #666;
                    font-size: 14px;
                }
                
                .resend-link {
                    text-align: center;
                    margin-top: 15px;
                }
                
                .resend-link a {
                    color: #E50914;
                    text-decoration: none;
                    font-size: 14px;
                }
                
                .resend-link a:hover {
                    text-decoration: underline;
                }
                
                .resend-link a.disabled {
                    color: #ccc;
                    pointer-events: none;
                }
                
                .masked-info {
                    background: #f5f5f5;
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    text-align: center;
                    font-size: 14px;
                    color: #666;
                }
                
                .masked-info strong {
                    color: #333;
                }
                
                @media (max-width: 480px) {
                    .netflix-sos-header {
                        padding: 20px;
                    }
                    
                    .netflix-sos-body {
                        padding: 20px;
                    }
                    
                    .verification-input {
                        width: 40px;
                        height: 50px;
                        font-size: 20px;
                    }
                }
            </style>
        </head>
        <body>
            <div class="netflix-sos-container">
                <div class="netflix-sos-card">
                    <div class="netflix-sos-header">
                        <h1>Netflix Hesap Kurtarma</h1>
                        <p>Hesabınıza erişmek için kimliğinizi doğrulayın</p>
                    </div>
                    
                    <div class="netflix-sos-body">
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
                        
                        <!-- Method Seçim Tabs -->
                        <div class="netflix-sos-tabs">
                            <div class="netflix-sos-tab active" data-method="phone">SMS ile Doğrula</div>
                            <div class="netflix-sos-tab" data-method="email">E-posta ile Doğrula</div>
                        </div>
                        
                        <!-- SMS Form -->
                        <form id="phone-form" class="netflix-sos-form active" method="post">
                            <?php wp_nonce_field('netflix_sos_action', '_wpnonce'); ?>
                            <input type="hidden" name="action" value="send_code">
                            <input type="hidden" name="method" value="phone">
                            
                            <div class="form-group">
                                <label for="phone">Telefon Numaranız</label>
                                <input type="tel" 
                                       id="phone" 
                                       name="phone" 
                                       class="form-control" 
                                       placeholder="5XX XXX XX XX"
                                       pattern="[0-9]{10,11}"
                                       maxlength="11"
                                       required>
                                <small style="color: #666; display: block; margin-top: 5px;">Başında 0 olmadan, örn: 5331234567</small>
                            </div>
                            
                            <button type="submit" class="btn">Kod Gönder</button>
                        </form>
                        
                        <!-- Email Form -->
                        <form id="email-form" class="netflix-sos-form" method="post">
                            <?php wp_nonce_field('netflix_sos_action', '_wpnonce'); ?>
                            <input type="hidden" name="action" value="send_code">
                            <input type="hidden" name="method" value="email">
                            
                            <div class="form-group">
                                <label for="email">E-posta Adresiniz</label>
                                <input type="email" 
                                       id="email" 
                                       name="email" 
                                       class="form-control" 
                                       placeholder="ornek@email.com"
                                       required>
                            </div>
                            
                            <button type="submit" class="btn">Kod Gönder</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <script>
                jQuery(document).ready(function($) {
                    // Tab geçişleri
                    $('.netflix-sos-tab').click(function() {
                        var method = $(this).data('method');
                        
                        $('.netflix-sos-tab').removeClass('active');
                        $(this).addClass('active');
                        
                        $('.netflix-sos-form').removeClass('active');
                        $('#' + method + '-form').addClass('active');
                    });
                    
                    // Form submit
                    $('.netflix-sos-form').submit(function(e) {
                        e.preventDefault();
                        
                        var form = $(this);
                        var submitBtn = form.find('button[type="submit"]');
                        var originalText = submitBtn.text();
                        
                        // Disable button
                        submitBtn.prop('disabled', true).text('Gönderiliyor...');
                        
                        $.ajax({
                            url: '<?php echo admin_url('admin-ajax.php'); ?>',
                            type: 'POST',
                            data: form.serialize(),
                            success: function(response) {
                                if (response.success) {
                                    // Verification formunu göster
                                    showVerificationForm(response.data.identifier, response.data.method);
                                } else {
                                    alert(response.data.message || 'Bir hata oluştu.');
                                    submitBtn.prop('disabled', false).text(originalText);
                                }
                            },
                            error: function() {
                                alert('Bir hata oluştu. Lütfen tekrar deneyin.');
                                submitBtn.prop('disabled', false).text(originalText);
                            }
                        });
                    });
                    
                    function showVerificationForm(identifier, method) {
                        var maskedInfo = method === 'phone' ? 
                            '<?php echo $this->mask_phone('__PHONE__'); ?>'.replace('__PHONE__', identifier) : 
                            '<?php echo $this->mask_email('__EMAIL__'); ?>'.replace('__EMAIL__', identifier);
                        
                        var html = `
                            <div class="masked-info">
                                <strong>Kod gönderildi:</strong> ${maskedInfo}
                            </div>
                            
                            <form id="verify-form" method="post">
                                <?php wp_nonce_field('netflix_sos_action', '_wpnonce'); ?>
                                <input type="hidden" name="action" value="verify_code">
                                <input type="hidden" name="identifier" value="${identifier}">
                                <input type="hidden" name="method" value="${method}">
                                
                                <div class="form-group">
                                    <label>6 Haneli Doğrulama Kodunu Girin</label>
                                    <div class="verification-inputs">
                                        <input type="text" class="verification-input" maxlength="1" pattern="[0-9]" inputmode="numeric" autofocus>
                                        <input type="text" class="verification-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                                        <input type="text" class="verification-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                                        <input type="text" class="verification-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                                        <input type="text" class="verification-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                                        <input type="text" class="verification-input" maxlength="1" pattern="[0-9]" inputmode="numeric">
                                    </div>
                                </div>
                                
                                <div class="countdown">
                                    Kod süresi: <span id="countdown">10:00</span>
                                </div>
                                
                                <button type="submit" class="btn">Doğrula ve Giriş Yap</button>
                                
                                <div class="resend-link">
                                    <a href="#" id="resend-code" class="${method === 'phone' ? 'disabled' : ''}">Kodu tekrar gönder</a>
                                </div>
                            </form>
                        `;
                        
                        $('.netflix-sos-body').html(html);
                        startCountdown(600);
                        
                        // Verification input focus
                        $('.verification-input').keyup(function(e) {
                            if (e.keyCode >= 48 && e.keyCode <= 57) {
                                $(this).val(e.key);
                                $(this).next('.verification-input').focus();
                            }
                        });
                        
                        // Resend code
                        $('#resend-code').click(function(e) {
                            e.preventDefault();
                            if ($(this).hasClass('disabled')) return;
                            
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: {
                                    action: 'netflix_sos_send_code',
                                    method: method,
                                    identifier: identifier,
                                    _wpnonce: '<?php echo wp_create_nonce('netflix_sos_action'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        alert('Yeni kod gönderildi.');
                                        startCountdown(600);
                                    }
                                }
                            });
                        });
                        
                        // Verify form submit
                        $('#verify-form').submit(function(e) {
                            e.preventDefault();
                            
                            var code = '';
                            $('.verification-input').each(function() {
                                code += $(this).val();
                            });
                            
                            if (code.length < 6) {
                                alert('Lütfen 6 haneli kodu tam girin.');
                                return;
                            }
                            
                            var formData = $(this).serialize() + '&code=' + code;
                            
                            $.ajax({
                                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                                type: 'POST',
                                data: formData,
                                success: function(response) {
                                    if (response.success) {
                                        window.location.href = response.data.redirect;
                                    } else {
                                        alert(response.data.message || 'Geçersiz kod.');
                                    }
                                }
                            });
                        });
                    }
                    
                    function startCountdown(seconds) {
                        var countdown = seconds;
                        var interval = setInterval(function() {
                            var minutes = Math.floor(countdown / 60);
                            var remainingSeconds = countdown % 60;
                            
                            $('#countdown').text(minutes.toString().padStart(2, '0') + ':' + 
                                               remainingSeconds.toString().padStart(2, '0'));
                            
                            if (countdown <= 0) {
                                clearInterval(interval);
                                $('#resend-code').removeClass('disabled');
                            }
                            
                            countdown--;
                        }, 1000);
                    }
                });
            </script>
            
            <?php wp_footer(); ?>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Callback işleme (kod doğrulama)
     * 
     * @param array $request İstek parametreleri
     * @return string HTML çıktı veya redirect
     */
    public function handle_callback($request) {
        $code = $request['code'];
        $identifier = $request['identifier'];
        $method = $request['method'] ?? 'phone';
        
        // Transient'ten kodu al
        $transient_key = 'netflix_sos_' . md5($identifier);
        $data = get_transient($transient_key);
        
        if (!$data) {
            return $this->show_error('Kod süresi dolmuş veya geçersiz.');
        }
        
        // Deneme sayısını kontrol et
        if ($data['attempts'] >= $this->max_attempts) {
            delete_transient($transient_key);
            return $this->show_error('Çok fazla hatalı deneme. Lütfen tekrar kod isteyin.');
        }
        
        // Kodu doğrula
        if ($data['code'] != $code) {
            // Hatalı deneme sayısını artır
            $data['attempts']++;
            set_transient($transient_key, $data, $this->code_expiry);
            
            return $this->show_error('Geçersiz doğrulama kodu. Kalan deneme: ' . 
                                    ($this->max_attempts - $data['attempts']));
        }
        
        // Kod doğru, transient'i temizle
        delete_transient($transient_key);
        
        // Kullanıcıyı bul veya oluştur
        $user_id = $this->find_or_create_user($data, $method);
        
        if (!$user_id) {
            return $this->show_error('Kullanıcı bulunamadı veya oluşturulamadı.');
        }
        
        // Oturum aç
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        // Aktivite logla
        $this->core->log('netflix_sos_login', [
            'user_id' => $user_id,
            'method' => $method,
            'identifier' => $this->mask_identifier($identifier, $method)
        ]);
        
        // Yönlendir
        $redirect = $this->config['redirect_url'] ?? home_url();
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Kullanıcı bul veya oluştur
     * 
     * @param array $data Kullanıcı verileri
     * @param string $method Yöntem (phone/email)
     * @return int|false Kullanıcı ID veya false
     */
    protected function find_or_create_user($data, $method) {
        $identifier = $data['identifier'];
        $user = false;
        
        // 1. Telefon numarasına göre ara (SMS ile geldiyse)
        if ($method === 'phone') {
            $users = get_users([
                'meta_key' => 'netflix_phone',
                'meta_value' => $identifier,
                'number' => 1
            ]);
            
            if (!empty($users)) {
                $user = $users[0];
            }
        }
        
        // 2. Email'e göre ara (Email ile geldiyse)
        if ($method === 'email' || !$user) {
            $user = get_user_by('email', $identifier);
        }
        
        // 3. Kullanıcı adına göre ara
        if (!$user) {
            $username = sanitize_user($identifier);
            $user = get_user_by('login', $username);
        }
        
        // Kullanıcı bulunduysa ID'sini döndür
        if ($user) {
            // Telefon meta'sını güncelle (SMS ile geldiyse)
            if ($method === 'phone') {
                update_user_meta($user->ID, 'netflix_phone', $identifier);
            }
            
            // Netflix provider ID'sini kaydet
            update_user_meta($user->ID, 'ai_comm_provider_netflix_sos_id', $identifier);
            update_user_meta($user->ID, 'ai_community_provider', 'netflix-sos');
            
            return $user->ID;
        }
        
        // 4. Yeni kullanıcı oluştur
        $username = $this->generate_unique_username($identifier);
        
        // Email oluştur (telefon ile geldiyse)
        if ($method === 'phone') {
            $email = 'netflix_' . $identifier . '@social.local';
        } else {
            $email = $identifier;
        }
        
        $user_id = wp_create_user($username, wp_generate_password(), $email);
        
        if (is_wp_error($user_id)) {
            $this->core->log('netflix_sos_user_creation_failed', [
                'error' => $user_id->get_error_message()
            ]);
            return false;
        }
        
        // Meta verilerini kaydet
        if ($method === 'phone') {
            update_user_meta($user_id, 'netflix_phone', $identifier);
        }
        
        update_user_meta($user_id, 'ai_comm_provider_netflix_sos_id', $identifier);
        update_user_meta($user_id, 'ai_community_provider', 'netflix-sos');
        
        return $user_id;
    }
    
    /**
     * Telefon maskele
     * 
     * @param string $phone Telefon numarası
     * @return string Maskelenmiş telefon
     */
    protected function mask_phone($phone) {
        $length = strlen($phone);
        if ($length < 4) return $phone;
        
        $visible = 4; // Son 4 hane görünsün
        $masked = substr($phone, 0, $length - $visible);
        $masked = preg_replace('/\d/', '*', $masked);
        
        return $masked . substr($phone, -$visible);
    }
    
    /**
     * Email maskele
     * 
     * @param string $email Email adresi
     * @return string Maskelenmiş email
     */
    protected function mask_email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
        
        list($local, $domain) = explode('@', $email);
        
        $local_length = strlen($local);
        if ($local_length <= 2) {
            $masked_local = str_repeat('*', $local_length);
        } else {
            $visible = 2; // İlk 2 karakter
            $masked_local = substr($local, 0, $visible) . 
                           str_repeat('*', $local_length - $visible);
        }
        
        return $masked_local . '@' . $domain;
    }
    
    /**
     * Benzersiz kullanıcı adı oluştur
     * 
     * @param string $identifier İdentifier
     * @return string Benzersiz kullanıcı adı
     */
    protected function generate_unique_username($identifier) {
        $base = sanitize_user($identifier);
        $base = preg_replace('/[^a-z0-9]/i', '', $base);
        $base = substr($base, 0, 20);
        
        if (empty($base)) {
            $base = 'netflix';
        }
        
        $username = $base;
        $counter = 1;
        
        while (get_user_by('login', $username)) {
            $username = $base . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Identifier maskele
     * 
     * @param string $identifier Identifier
     * @param string $method Yöntem
     * @return string Maskelenmiş identifier
     */
    protected function mask_identifier($identifier, $method) {
        if ($method === 'phone') {
            return $this->mask_phone($identifier);
        } else {
            return $this->mask_email($identifier);
        }
    }
    
    /**
     * Hata mesajı göster
     * 
     * @param string $message Hata mesajı
     * @return string HTML çıktı
     */
    protected function show_error($message) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Hata - Netflix SOS</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }
                
                .error-container {
                    max-width: 400px;
                    width: 100%;
                }
                
                .error-card {
                    background: white;
                    border-radius: 20px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    overflow: hidden;
                }
                
                .error-header {
                    background: #E50914;
                    color: white;
                    padding: 30px;
                    text-align: center;
                }
                
                .error-header h1 {
                    font-size: 28px;
                    margin-bottom: 10px;
                }
                
                .error-body {
                    padding: 30px;
                }
                
                .error-message {
                    background: #ffebee;
                    color: #E50914;
                    padding: 15px;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    border-left: 4px solid #E50914;
                }
                
                .btn {
                    display: block;
                    width: 100%;
                    padding: 14px;
                    background: #E50914;
                    color: white;
                    text-align: center;
                    text-decoration: none;
                    border-radius: 10px;
                    font-weight: 600;
                    transition: background 0.3s ease;
                }
                
                .btn:hover {
                    background: #b80710;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-card">
                    <div class="error-header">
                        <h1>Netflix SOS</h1>
                        <p>Hata Oluştu</p>
                    </div>
                    <div class="error-body">
                        <div class="error-message">
                            <?php echo esc_html($message); ?>
                        </div>
                        <a href="<?php echo esc_url(add_query_arg([])); ?>" class="btn">Tekrar Dene</a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * AJAX ile kod gönder
     */
    public function ajax_send_code() {
        // Nonce kontrolü
        if (!check_ajax_referer('netflix_sos_action', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
            return;
        }
        
        $method = $_POST['method'] ?? '';
        $identifier = '';
        
        if ($method === 'phone') {
            $identifier = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
            
            if (strlen($identifier) < 10) {
                wp_send_json_error(['message' => 'Geçerli bir telefon numarası girin.']);
                return;
            }
        } else {
            $identifier = sanitize_email($_POST['email'] ?? '');
            
            if (!is_email($identifier)) {
                wp_send_json_error(['message' => 'Geçerli bir e-posta adresi girin.']);
                return;
            }
        }
        
        // 6 haneli kod oluştur
        $code = wp_rand(100000, 999999);
        
        // Transient'e kaydet (10 dakika)
        $transient_key = 'netflix_sos_' . md5($identifier);
        set_transient($transient_key, [
            'code' => $code,
            'identifier' => $identifier,
            'method' => $method,
            'attempts' => 0,
            'time' => time()
        ], 600);
        
        // Simulate sending (gerçekte SMS/email gönderilir)
        $this->core->log('netflix_sos_code_sent', [
            'method' => $method,
            'identifier' => $this->mask_identifier($identifier, $method),
            'code' => $code // Gerçekte log'a yazılmaz, test için
        ]);
        
        wp_send_json_success([
            'identifier' => $identifier,
            'method' => $method,
            'message' => 'Kod gönderildi!'
        ]);
    }
    
    /**
     * AJAX ile kod doğrula
     */
    public function ajax_verify_code() {
        // Nonce kontrolü
        if (!check_ajax_referer('netflix_sos_action', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Güvenlik doğrulaması başarısız.']);
            return;
        }
        
        $code = $_POST['code'] ?? '';
        $identifier = $_POST['identifier'] ?? '';
        $method = $_POST['method'] ?? 'phone';
        
        // Transient'ten kodu al
        $transient_key = 'netflix_sos_' . md5($identifier);
        $data = get_transient($transient_key);
        
        if (!$data) {
            wp_send_json_error(['message' => 'Kod süresi dolmuş.']);
            return;
        }
        
        // Deneme sayısını kontrol et
        if ($data['attempts'] >= $this->max_attempts) {
            delete_transient($transient_key);
            wp_send_json_error(['message' => 'Çok fazla hatalı deneme.']);
            return;
        }
        
        // Kodu doğrula
        if ($data['code'] != $code) {
            // Hatalı deneme sayısını artır
            $data['attempts']++;
            set_transient($transient_key, $data, $this->code_expiry);
            
            wp_send_json_error([
                'message' => 'Geçersiz kod. Kalan deneme: ' . 
                            ($this->max_attempts - $data['attempts'])
            ]);
            return;
        }
        
        // Kod doğru, kullanıcıyı bul
        $user_id = $this->find_or_create_user($data, $method);
        
        if (!$user_id) {
            wp_send_json_error(['message' => 'Kullanıcı bulunamadı.']);
            return;
        }
        
        // Oturum aç
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
        
        // Transient'i temizle
        delete_transient($transient_key);
        
        // Log
        $this->core->log('netflix_sos_verified', [
            'user_id' => $user_id,
            'method' => $method
        ]);
        
        wp_send_json_success([
            'redirect' => $this->config['redirect_url'] ?? home_url()
        ]);
    }
}