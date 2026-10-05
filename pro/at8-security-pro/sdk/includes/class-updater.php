<?php
/**
 * Pro 自动更新（P9）：update/check → 本地代理下载（解决签名 URL 900 秒过期问题）。
 * 多产品并存：按 product 隔离配置。
 *
 * 流程：transient 里 package 指向本站 admin-ajax 代理 → 下载时代理向服务器
 * 重新请求 update/check 拿新鲜签名 URL → 流式转发安装包。
 *
 * 注意：at8-security-pro 调用 SDK 时传的是 `'update' => false`（更新由本插件
 * 自带的 includes/class-updater.php 负责，已改走 SDK 签名调用 + sha256 校验），
 * 因此 AT8SDK_Updater::init() 不会被调用，本文件在本产品里是**未激活**的。
 * 它仍然随 SDK 一起分发，所以下面的扫描器提示按未激活代码处理。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Updater')) {

    class AT8SDK_Updater
    {
        /** @var array<string, AT8SDK_Config> product => config */
        private static $configs = [];

        /** @var bool */
        private static $hooked = false;

        public static function init($config)
        {
            self::$configs[$config->product] = $config;

            if (!self::$hooked) {
                self::$hooked = true;
                add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject_update']);
                add_action('wp_ajax_at8sdk_download', [__CLASS__, 'proxy_download']);
            }
        }

        private static function check_remote($config)
        {
            $license = AT8_License_SDK::license($config->product);
            if (!$license || $license->get_key() === '' || !$license->is_valid()) {
                return null;
            }

            // 12h 缓存，避免每次后台更新检查都打服务器
            $cache_key = 'at8sdk_upd_' . sanitize_key($config->product);
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }

            $plugin_data = get_file_data($config->plugin_file, ['Version' => 'Version']);
            $current = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';

            $res = $license->client()->post('/update/check', [
                'product'  => $config->product,
                'version'  => $current,
                'site_url' => $config->site_url(),
            ], $license->get_key());

            if ($res['http'] === null || !is_array($res['data'])) {
                return null;
            }

            set_transient($cache_key, $res['data'], 12 * HOUR_IN_SECONDS);
            return $res['data'];
        }

        public static function inject_update($transient)
        {
            if (!is_object($transient)) {
                return $transient;
            }

            foreach (self::$configs as $config) {
                $transient = self::process($transient, $config);
            }
            return $transient;
        }

        private static function process($transient, $config)
        {
            $basename = $config->plugin_basename();
            if ($basename === '' || isset($transient->response[$basename])) {
                return $transient;
            }

            $data = self::check_remote($config);
            if (!$data || empty($data['update_available'])) {
                return $transient;
            }

            $plugin_data = get_file_data($config->plugin_file, ['Version' => 'Version']);
            $current = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0.0.0';
            if (empty($data['version']) || version_compare($current, (string) $data['version'], '>=')) {
                return $transient;
            }

            $update = new stdClass();
            $update->slug        = dirname($basename);
            $update->plugin      = $basename;
            $update->new_version = (string) $data['version'];
            $update->package     = admin_url('admin-ajax.php?action=at8sdk_download&product=' . rawurlencode($config->product));
            $update->tested      = '6.7';

            $transient->response[$basename] = $update;
            return $transient;
        }

        /** 本站管理员专用下载代理 */
        public static function proxy_download()
        {
            if (!current_user_can('update_plugins')) {
                wp_die('无权限');
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- inactive in this product (update => false), and gated on update_plugins.
            $product = isset($_GET['product']) ? sanitize_key(wp_unslash($_GET['product'])) : '';
            $config  = $product !== '' && isset(self::$configs[$product]) ? self::$configs[$product] : null;

            if (!$config) {
                wp_die('参数错误');
            }

            delete_transient('at8sdk_upd_' . sanitize_key($config->product));
            $data = self::check_remote($config);
            if (!$data || empty($data['update_available']) || empty($data['download_url'])) {
                wp_die('未获取到下载地址，请稍后重试');
            }

            $res = wp_remote_get($data['download_url'], ['timeout' => 60, 'sslverify' => true]);
            if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
                wp_die('下载失败，请稍后重试');
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . sanitize_file_name($config->product . '-' . $data['version'] . '.zip') . '"');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw ZIP bytes streamed to the browser; escaping would corrupt the archive.
            echo wp_remote_retrieve_body($res);
            exit;
        }
    }
}
