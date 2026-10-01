<?php
/**
 * 授权状态机（SDK 核心）。
 *
 * 状态：active（有效）/ grace（服务器失联宽限）/ expired / revoked / suspended /
 *       invalid（key 无效或域名不匹配）/ server_error（从未成功激活过）
 *
 * is_valid() 只读本地状态：
 *   active 且未过期 → true；grace 且 grace_until 未到 → true；其余 → false。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_License')) {

class AT8SDK_License
{
    /** @var AT8SDK_Config */
    private $config;

    /** @var AT8SDK_Client */
    private $client;

    public function __construct($config, $client)
    {
        $this->config = $config;
        $this->client = $client;
    }

    /** @internal 供 Updater 等内部组件使用 */
    public function client()
    {
        return $this->client;
    }

    /* ---------------- 本地状态（零 HTTP） ---------------- */

    public function state()
    {
        $state = get_option($this->config->state_option(), []);
        return is_array($state) ? $state : [];
    }

    /**
     * 本地状态完整性签名：防止客户直接改数据库字段白嫖
     * （auth salt 只存在于 wp-config.php / 数据库之外，站点管理员也未必能伪造）。
     * 这是低成本防线，不替代服务器端判定（规划书 §64）。
     */
    private static function state_sig(array $state)
    {
        unset($state['sig']);
        // 独立 salt 上下文，不与授权码哈希共用；ksort 固化键序，防未来改动引发全量失效
        ksort($state);
        return hash_hmac('sha256', wp_json_encode($state), wp_salt('auth') . '|at8sdk-state');
    }

    private function save_state($state)
    {
        $state['sig'] = self::state_sig($state);
        update_option($this->config->state_option(), $state, false);
    }

    public function get_key()
    {
        $state = $this->state();
        return isset($state['key']) ? (string) $state['key'] : '';
    }

    /** Pro 是否可用（本地判定，不发请求） */
    public function is_valid()
    {
        $state = $this->state();
        if (empty($state['key'])) {
            return false;
        }

        // 完整性校验：状态被手工篡改过则一律无效
        $sig = isset($state['sig']) ? $state['sig'] : '';
        if (!hash_equals(self::state_sig($state), (string) $sig)) {
            return false;
        }

        $now = time();

        if ($state['status'] === 'active') {
            if (!empty($state['lifetime']) || (int) $state['expires_at'] === 0 || (int) $state['expires_at'] > $now) {
                return true;
            }
            // 本地到期：停 Pro（数据保留）
            return false;
        }

        if ($state['status'] === 'grace') {
            // 宽限期内本地已知的到期时间仍然生效（防"到期后断网续命"）
            $expired_locally = empty($state['lifetime'])
                && (int) ($state['expires_at'] ?? 0) !== 0
                && (int) $state['expires_at'] <= $now;

            return !$expired_locally && $now < (int) $state['grace_until'];
        }

        return false;
    }

    public function status_label()
    {
        $state = $this->state();
        if (empty($state['key'])) {
            return '未激活';
        }
        if ($this->is_valid() && $state['status'] === 'grace') {
            return '宽限期（服务器暂时无法连接）';
        }
        $map = [
            'active'       => '已激活',
            'grace'        => '宽限期已过',
            'expired'      => '授权已过期',
            'revoked'      => '授权已被吊销',
            'suspended'    => '授权已被暂停',
            'invalid'      => '授权无效',
            'server_error' => '验证失败',
        ];
        return isset($map[$state['status']]) ? $map[$state['status']] : (string) $state['status'];
    }

    public function expires_at()
    {
        $state = $this->state();
        return isset($state['expires_at']) ? (int) $state['expires_at'] : 0;
    }

    public function is_lifetime()
    {
        $state = $this->state();
        return !empty($state['lifetime']);
    }

    public function last_error()
    {
        $state = $this->state();
        return isset($state['message']) ? (string) $state['message'] : '';
    }

    /* ---------------- 远程操作（用户点击 / cron 调用） ---------------- */

    /** 激活：成功返回 true；失败返回错误消息文本 */
    public function activate($key_input)
    {
        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $key_input));
        if (strlen($key) < 10) {
            return '授权码格式不正确';
        }

        $res = $this->client->post('/license/activate', [
            'product'        => $this->config->product,
            'site_url'       => $this->config->site_url(),
            'site_name'      => get_bloginfo('name'),
            'plugin_version' => $this->plugin_version(),
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
        ], $key);

        if ($res['http'] === null) {
            return '无法连接授权服务器，请稍后重试（' . $res['error'] . '）';
        }

        if ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['data']['success'])) {
            $d = $res['data'];
            $this->save_state([
                'key'          => $key,
                'status'       => 'active',
                'license_type' => isset($d['license_type']) ? $d['license_type'] : '',
                'lifetime'     => !empty($d['lifetime']),
                'expires_at'   => isset($d['expires_at']) ? (int) $d['expires_at'] : 0,
                'grace_days'   => isset($d['grace_days']) ? (int) $d['grace_days'] : 7,
                'max_sites'    => isset($d['max_sites']) ? (int) $d['max_sites'] : 1,
                'used_sites'   => isset($d['used_sites']) ? (int) $d['used_sites'] : 1,
                'last_ok'      => time(),
                'next_check_at'=> isset($d['next_check_at']) ? (int) $d['next_check_at'] : time() + DAY_IN_SECONDS,
                'message'      => '',
            ]);
            AT8SDK_Scheduler::schedule($this->config);
            return true;
        }

        $code = isset($res['data']['code']) ? $res['data']['code'] : 'SERVER_ERROR';
        $msg  = isset($res['data']['message']) ? $res['data']['message'] : '激活失败';

        $state = $this->state();
        $state['key'] = $key;
        $state['status'] = $this->map_error_status($code);
        $state['message'] = $msg;
        $this->save_state($state);

        return $msg;
    }

    /** 解绑本站：成功后清空本地状态 */
    public function deactivate()
    {
        $key = $this->get_key();
        if ($key === '') {
            return true;
        }

        $res = $this->client->post('/license/deactivate', [
            'product'  => $this->config->product,
            'site_url' => $this->config->site_url(),
        ], $key);

        if ($res['http'] === null) {
            return '无法连接授权服务器，请稍后重试';
        }

        if ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['data']['success'])) {
            delete_option($this->config->state_option());
            AT8SDK_Scheduler::unschedule($this->config);
            return true;
        }

        return isset($res['data']['message']) ? $res['data']['message'] : '解绑失败';
    }

    /** 定期复查（cron 调用）：网络故障进宽限，明确拒绝立即停 Pro */
    public function verify()
    {
        $key = $this->get_key();
        if ($key === '') {
            return;
        }

        $res = $this->client->post('/license/verify', [
            'product'  => $this->config->product,
            'site_url' => $this->config->site_url(),
            'version'  => $this->plugin_version(),
        ], $key);

        $state = $this->state();

        if ($res['http'] === null) {
            // 网络故障：从上次成功时间起算宽限期（规划书 §22）
            $last_ok = isset($state['last_ok']) ? (int) $state['last_ok'] : time();
            $grace_days = isset($state['grace_days']) ? max(1, (int) $state['grace_days']) : 7;
            $state['status'] = 'grace';
            $state['grace_until'] = $last_ok + $grace_days * DAY_IN_SECONDS;
            $state['message'] = '授权服务器暂时无法连接，已进入宽限期';
            $this->save_state($state);
            return;
        }

        if ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['data']['success'])) {
            $d = $res['data'];
            $state['status']        = 'active';
            $state['license_type']  = isset($d['license_type']) ? $d['license_type'] : (isset($state['license_type']) ? $state['license_type'] : '');
            $state['lifetime']      = !empty($d['lifetime']);
            $state['expires_at']    = isset($d['expires_at']) ? (int) $d['expires_at'] : (int) ($state['expires_at'] ?? 0);
            $state['grace_days']    = isset($d['grace_days']) ? (int) $d['grace_days'] : (int) ($state['grace_days'] ?? 7);
            $state['max_sites']     = isset($d['max_sites']) ? (int) $d['max_sites'] : (int) ($state['max_sites'] ?? 1);
            $state['used_sites']    = isset($d['used_sites']) ? (int) $d['used_sites'] : (int) ($state['used_sites'] ?? 1);
            $state['last_ok']       = time();
            $state['next_check_at'] = isset($d['next_check_at']) ? (int) $d['next_check_at'] : time() + DAY_IN_SECONDS;
            $state['message']       = '';
            unset($state['grace_until']);
            $this->save_state($state);
            return;
        }

        $code = isset($res['data']['code']) ? $res['data']['code'] : 'SERVER_ERROR';
        $state['status']  = $this->map_error_status($code);
        $state['message'] = isset($res['data']['message']) ? $res['data']['message'] : '验证失败';
        $this->save_state($state);
    }

    private function map_error_status($code)
    {
        switch ($code) {
            case 'LICENSE_EXPIRED':    return 'expired';
            case 'LICENSE_REVOKED':    return 'revoked';
            case 'LICENSE_SUSPENDED':  return 'suspended';
            case 'LICENSE_NOT_FOUND':
            case 'LICENSE_INVALID':
            case 'PRODUCT_MISMATCH':
            case 'DOMAIN_NOT_AUTHORIZED':
            case 'SITE_LIMIT_REACHED':
            case 'INVALID_SIGNATURE':  return 'invalid';
            default:                   return 'server_error';
        }
    }

    private function plugin_version()
    {
        if (!$this->config->plugin_file) {
            return '';
        }
        $data = get_file_data($this->config->plugin_file, ['Version' => 'Version']);
        return isset($data['Version']) ? $data['Version'] : '';
    }
}

} // class_exists guard
