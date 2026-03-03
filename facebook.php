<?php
if (!defined('ABSPATH')) exit;

class AI_Community_Provider_Facebook extends AI_Community_Provider_Base {

    public function name(): string { return 'facebook'; }

    /**
     * Base sınıfın zorunlu metodu
     *
     * - code yoksa  → start_auth() tetikler (Facebook’a yönlendirir)
     * - code varsa  → handle_callback() token + profil + link işlemlerini yapar
     */
    public function handle($action = '')
    {
        return $this->handle_callback();
    }

    public function handle_callback(): array {
        $code  = isset($_GET['code'])  ? (string)$_GET['code']  : '';
        $state = isset($_GET['state']) ? (string)$_GET['state'] : '';

        // Eğer geri dönüş değilse → auth başlat
        if ($code === '') {
            $this->start_auth();
            return ['ok'=>false,'user_id'=>0,'error'=>'redirecting'];
        }

        // CSRF koruması
        if (!$this->verify_state($state))
            return ['ok'=>false,'user_id'=>0,'error'=>'Invalid state'];

        $e = $this->endpoints();

        // Access token al
        $token = $this->http_post_form($e['token'] ?? '', [
            'client_id'     => (string)$this->cfg('client_id',''),
            'client_secret' => (string)$this->cfg('client_secret',''),
            'code'          => $code,
            'redirect_uri'  => $this->callback_url(),
        ]);

        if (!$token['ok'])
            return ['ok'=>false,'user_id'=>0,'error'=>'Token exchange failed'];

        $access = (string)($token['data']['access_token'] ?? '');
        if ($access === '')
            return ['ok'=>false,'user_id'=>0,'error'=>'Missing access_token'];

        // Userinfo al
        $userinfo_url = (string)($e['userinfo'] ?? '');
        $sep = (strpos($userinfo_url, '?') !== false) ? '&' : '?';

        $u = $this->http_get_json(
            $userinfo_url . $sep . 'access_token=' . rawurlencode($access)
        );

        if (!$u['ok'])
            return ['ok'=>false,'user_id'=>0,'error'=>'Userinfo failed'];

        $uid   = (string)($u['data']['id'] ?? '');
        $name  = (string)($u['data']['name'] ?? '');
        $email = (string)($u['data']['email'] ?? '');

        if ($uid === '')
            return ['ok'=>false,'user_id'=>0,'error'=>'Missing user id'];

        // Kullanıcıyı oluştur / bağla
        $user_id = $this->ensure_user_and_link_identity(
            'facebook',
            $uid,
            $email ?: null,
            ['name'=>$name]
        );

        if ($user_id <= 0)
            return ['ok'=>false,'user_id'=>0,'error'=>'User create/link failed'];

        return ['ok'=>true,'user_id'=>$user_id,'error'=>''];
    }

    private function start_auth(): void {
        $e = $this->endpoints();
        $client_id = (string)$this->cfg('client_id','');

        if ($client_id === '')
            wp_die('Facebook login is not configured (missing client_id).');

        $state = $this->new_state();

        $params = [
            'client_id'     => $client_id,
            'redirect_uri'  => $this->callback_url(),
            'response_type' => 'code',
            'scope'         => (string)$this->cfg('scopes','public_profile email'),
            'state'         => $state,
        ];

        $url = add_query_arg($params, $e['authorize'] ?? '');
        wp_safe_redirect($url);
        exit;
    }

    private function new_state(): string {
        $state = wp_generate_password(24, false, false);
        set_transient('ai_comm_state_facebook_' . $state, 600, 600);
        return $state;
    }

    private function verify_state(string $state): bool {
        if ($state === '') return false;

        $key = 'ai_comm_state_facebook_' . $state;
        $ok  = (bool) get_transient($key);

        delete_transient($key);
        return $ok;
    }
}
