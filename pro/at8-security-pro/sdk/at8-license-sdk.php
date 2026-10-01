<?php
/**
 * AT8 License SDK — 统一授权客户端（单一来源，构建产物嵌入各 Pro 插件）。
 *
 * 插件侧接入（5 行）：
 *   require_once __DIR__ . '/sdk/at8-license-sdk.php';
 *   AT8_License_SDK::init([
 *       'server_url'  => 'https://www.at8.fun',
 *       'product'     => 'at8-security-pro',
 *       'plugin_file' => __FILE__,
 *   ]);
 *
 *   if (AT8_License_SDK::license()->is_valid()) { boot_pro(); }
 *
 * 多产品并存：同一站点可激活任意多个 AT8 Pro 产品——类文件全部带
 * class_exists 守卫（只加载第一份副本），实例按 product 隔离。
 *
 * 设计要点（规划书 §22/§23/§24）：
 *   - is_valid() 只读本地状态，永不发 HTTP；
 *   - 定期复查走 WP-Cron（每日），页面加载零请求；
 *   - 网络故障进入宽限期（grace_days 由服务器下发）；
 *   - revoked / expired 立即停 Pro，数据保留。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AT8SDK_VERSION')) {
    define('AT8SDK_VERSION', '1.0.0');
}

if (!class_exists('AT8SDK_Config')) {
    require_once __DIR__ . '/includes/class-config.php';
}
if (!class_exists('AT8SDK_Domain')) {
    require_once __DIR__ . '/includes/class-domain.php';
}
if (!class_exists('AT8SDK_Client')) {
    require_once __DIR__ . '/includes/class-client.php';
}
if (!class_exists('AT8SDK_License')) {
    require_once __DIR__ . '/includes/class-license.php';
}
if (!class_exists('AT8SDK_Scheduler')) {
    require_once __DIR__ . '/includes/class-scheduler.php';
}
if (!class_exists('AT8SDK_AdminPage')) {
    require_once __DIR__ . '/includes/class-adminpage.php';
}
if (!class_exists('AT8SDK_Updater')) {
    require_once __DIR__ . '/includes/class-updater.php';
}

if (!class_exists('AT8_License_SDK')) {

    class AT8_License_SDK
    {
        /** @var array<string, AT8SDK_License> product => instance */
        private static $licenses = [];

        /**
         * 初始化本产品的授权实例（幂等；多产品互不干扰）。
         *
         * @return AT8SDK_License|null
         */
        public static function init($config)
        {
            $config_obj = new AT8SDK_Config($config);

            if ($config_obj->product === '') {
                return null;
            }
            if (isset(self::$licenses[$config_obj->product])) {
                return self::$licenses[$config_obj->product];
            }

            $client = new AT8SDK_Client($config_obj);
            $license = new AT8SDK_License($config_obj, $client);
            self::$licenses[$config_obj->product] = $license;

            AT8SDK_Scheduler::init($config_obj);
            AT8SDK_AdminPage::init($config_obj);

            if (!empty($config['update'])) {
                AT8SDK_Updater::init($config_obj);
            }

            return $license;
        }

        /**
         * 取授权实例。
         *
         * @param string $product 产品 slug；空 = 返回本站第一个已初始化的产品。
         * @return AT8SDK_License|null
         */
        public static function license($product = '')
        {
            if ($product !== '') {
                return isset(self::$licenses[$product]) ? self::$licenses[$product] : null;
            }
            return self::$licenses ? reset(self::$licenses) : null;
        }
    }
}
