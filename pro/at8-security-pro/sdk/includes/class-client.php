<?php
/**
 * 签名 HTTP 客户端（与服务器 AT8LIC_Security 的签名规则严格对应）。
 *
 * raw = timestamp \n nonce \n METHOD \n path \n sha256(body)
 * sig = base64(HMAC-SHA256(raw, license_key_canonical))
 * path = URL 路径部分（含 WP 子目录）
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Client')) {

class AT8SDK_Client
{
    const TIMEOUT = 8;

    /** @var AT8SDK_Config */
    private $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * POST 请求。
     * @param string $path  API 路径，如 /activate
     * @param array  $body  JSON body
     * @param string $key   授权码（canonical）
     * @return array ['http'=>int|null, 'data'=>array|null, 'error'=>string|null]
     */
    public function post($path, $body, $key = '')
    {
        /*
         * The server reads the key out of the body, not out of the signature
         * headers: /activate, /validate and /update all declare `license` as a
         * required param (at8-license-server/includes/class-rest.php, and
         * docs/api.md). Sending the key only in X-AT8-Key answered
         * 400 rest_missing_callback_param.
         */
        if ($key !== '' && !isset($body['license'])) {
            $body['license'] = $key;
        }

        $url  = $this->config->api_url($path);
        $json = wp_json_encode($body);
        return $this->request('POST', $url, $json, $key);
    }

    /** GET 请求（签名 body 为空串） */
    public function get($path, $key = '')
    {
        $url = $this->config->api_url($path);
        return $this->request('GET', $url, '', $key);
    }

    private function request($method, $url, $body_json, $key)
    {
        $ts    = (string) time();
        $nonce = wp_generate_uuid4();
        $path  = (string) wp_parse_url($url, PHP_URL_PATH);

        $raw = implode("\n", [$ts, $nonce, $method, $path, hash('sha256', $body_json)]);
        $sig = base64_encode(hash_hmac('sha256', $raw, (string) $key, true));

        $headers = [
            'Content-Type'      => 'application/json',
            'X-AT8-Key'         => (string) $key,
            'X-AT8-Timestamp'   => $ts,
            'X-AT8-Nonce'       => $nonce,
            'X-AT8-Signature'   => $sig,
            'X-AT8-Product'     => $this->config->product,
        ];

        $args = [
            'timeout'    => self::TIMEOUT,
            'headers'    => $headers,
            'sslverify'  => true,
            // 伪装成 WordPress 核心的出站 UA：部分主机的 WAF 规则会拦截
            // 非浏览器 UA（curl/PHP/空 UA），WP 核心格式在所有主流 WAF 白名单里
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url() . ' (AT8-License-SDK/' . AT8SDK_VERSION . ')',
        ];

        if ($method === 'POST') {
            $args['body']        = $body_json;
            $args['data_format'] = 'body';
            $res = wp_remote_post($url, $args);
        } else {
            $res = wp_remote_get($url, $args);
        }

        if (is_wp_error($res)) {
            return ['http' => null, 'data' => null, 'error' => $res->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);

        return ['http' => $code, 'data' => is_array($data) ? $data : null, 'error' => null];
    }
}

} // class_exists guard
