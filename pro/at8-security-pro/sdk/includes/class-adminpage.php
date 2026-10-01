<?php
/**
 * 统一授权后台页（规划书 §34/§35）。多产品并存：按 product 隔离配置。
 *
 * 两种用法：
 *   1. config['menu'] = ['parent' => ..., 'page_title' => ..., 'menu_title' => ...]
 *      → SDK 自动注册子菜单页；
 *   2. 插件在自己的页面里调用 AT8SDK_AdminPage::render_page() 嵌入。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_AdminPage')) {

    class AT8SDK_AdminPage
    {
        /** @var array<string, AT8SDK_Config> product => config */
        private static $configs = [];

        /** @var bool */
        private static $handle_registered = false;

        public static function init($config)
        {
            self::$configs[$config->product] = $config;

            if (!self::$handle_registered) {
                self::$handle_registered = true;
                add_action('admin_init', [__CLASS__, 'handle']);
            }

            if (is_array($config->menu) && !empty($config->menu['parent'])) {
                add_action('admin_menu', function () use ($config) {
                    add_submenu_page(
                        $config->menu['parent'],
                        isset($config->menu['page_title']) ? $config->menu['page_title'] : '授权管理',
                        isset($config->menu['menu_title']) ? $config->menu['menu_title'] : '授权管理',
                        'manage_options',
                        $config->menu_slug,
                        function () use ($config) {
                            self::render($config);
                        }
                    );
                });
            }
        }

        /** 表单处理：activate / deactivate / verify（按提交的 product 路由） */
        public static function handle()
        {
            if (empty($_POST['at8sdk_action']) || empty($_POST['at8sdk_product'])) {
                return;
            }

            $product = sanitize_key(wp_unslash($_POST['at8sdk_product']));
            $config = isset(self::$configs[$product]) ? self::$configs[$product] : null;

            if (!$config || !current_user_can('manage_options')) {
                return;
            }
            check_admin_referer('at8sdk_' . $product, 'at8sdk_nonce');

            $license = AT8_License_SDK::license($product);
            if (!$license) {
                return;
            }

            $action = sanitize_key($_POST['at8sdk_action']);
            $result = true;

            if ($action === 'activate') {
                $key = isset($_POST['at8sdk_key']) ? wp_unslash($_POST['at8sdk_key']) : '';
                $result = $license->activate($key);
            } elseif ($action === 'deactivate') {
                $result = $license->deactivate();
                if (true === $result) {
                    AT8SDK_Scheduler::unschedule($config);
                }
            } elseif ($action === 'verify') {
                $license->verify();
                $result = true;
            }

            $is_error = is_string($result);
            $redirect = add_query_arg(
                ['at8sdk_msg' => $is_error ? 'error:' . rawurlencode($result) : $action],
                wp_get_referer() ? wp_get_referer() : admin_url('index.php')
            );
            wp_safe_redirect($redirect);
            exit;
        }

        /** 兼容入口：渲染最近注册产品的授权页（嵌入模式） */
        public static function render_page()
        {
            if (!self::$configs) {
                return;
            }
            self::render(end(self::$configs));
        }

        /** 渲染指定产品的授权页 */
        public static function render($config)
        {
            $license = AT8_License_SDK::license($config->product);

            $state  = $license ? $license->state() : [];
            $active = $license ? $license->is_valid() : false;
            $key    = $license ? $license->get_key() : '';
            $grace  = isset($state['status']) && $state['status'] === 'grace';
            ?>
            <div class="wrap">
                <h1>授权管理 — <?php echo esc_html($config->product); ?></h1>

                <?php if (!empty($_GET['at8sdk_msg'])) :
                    $msg = sanitize_text_field(wp_unslash($_GET['at8sdk_msg']));
                    if (strpos($msg, 'error:') === 0) : ?>
                        <div class="notice notice-error"><p><?php echo esc_html(rawurldecode(substr($msg, 6))); ?></p></div>
                    <?php else : ?>
                        <div class="notice notice-success"><p>操作成功。</p></div>
                    <?php endif;
                endif; ?>

                <div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo $active ? '#00a32a' : '#b32d2e'; ?>;padding:14px 18px;max-width:680px;margin:16px 0">
                    <p style="font-size:15px;margin:0 0 6px">
                        <strong><?php echo $active ? '✓ ' . esc_html($license->status_label()) : '✕ ' . esc_html($license->status_label()); ?></strong>
                    </p>
                    <?php if ($key) : ?>
                        <p style="margin:0 0 4px;color:#646970">
                            授权码：<code><?php echo esc_html(substr($key, 0, 3) . '-' . substr($key, 3, 5) . '-****-****-' . substr($key, -5)); ?></code>
                        </p>
                        <p style="margin:0;color:#646970">
                            <?php if ($license->is_lifetime()) : ?>
                                有效期：终身
                            <?php elseif ($license->expires_at()) : ?>
                                到期时间：<?php echo esc_html(wp_date('Y-m-d', $license->expires_at())); ?>
                            <?php endif; ?>
                            <?php if (!empty($state['max_sites'])) : ?>
                                ｜站点额度：<?php echo esc_html($state['used_sites'] . '/' . $state['max_sites']); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($license->last_error()) : ?>
                        <p style="margin:6px 0 0;color:#b32d2e"><?php echo esc_html($license->last_error()); ?></p>
                    <?php endif; ?>
                    <?php if ($grace) : ?>
                        <p style="margin:6px 0 0;color:#dba617">
                            宽限期截止：<?php echo esc_html(wp_date('Y-m-d H:i', (int) $state['grace_until'])); ?>，期间 Pro 功能正常可用。
                        </p>
                    <?php endif; ?>
                </div>

                <form method="post" style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:680px">
                    <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                    <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                    <input type="hidden" name="at8sdk_action" value="activate">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="at8sdk-key">授权码</label></th>
                            <td>
                                <input type="text" id="at8sdk-key" name="at8sdk_key" class="regular-text"
                                       value="" placeholder="AT8-XXXXX-XXXXX-XXXXX-XXXXX">
                                <p class="description">
                                    格式：AT8-XXXXX-XXXXX-XXXXX-XXXXX。
                                    <?php if ($config->buy_url) : ?>
                                        没有授权码？<a href="<?php echo esc_url($config->buy_url); ?>" target="_blank" rel="noopener">前往购买 →</a>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('激活授权', 'primary', 'submit', false); ?>
                </form>

                <?php if ($key) : ?>
                <p style="max-width:680px">
                    <form method="post" style="display:inline-block" onsubmit="return confirm('确认解绑本站？解绑后本站 Pro 功能停用。');">
                        <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                        <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                        <input type="hidden" name="at8sdk_action" value="deactivate">
                        <button class="button">解绑本站</button>
                    </form>
                    <form method="post" style="display:inline-block">
                        <?php wp_nonce_field('at8sdk_' . $config->product, 'at8sdk_nonce'); ?>
                        <input type="hidden" name="at8sdk_product" value="<?php echo esc_attr($config->product); ?>">
                        <input type="hidden" name="at8sdk_action" value="verify">
                        <button class="button">重新验证</button>
                    </form>
                </p>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}
