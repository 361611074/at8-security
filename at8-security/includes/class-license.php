<?php
/**
 * AT8 License - 授权验证模块
 * 
 * @package AT8_License
 * @version 1.0.0
 * @author 漫步白月光
 * @link https://www.at8.fun/
 */

if (!defined('ABSPATH')) {
    exit;
}

// 从 wp-config.php 读取密钥，避免硬编码
if (!defined('AT8_LICENSE_SECRET_KEY')) {
    define('AT8_LICENSE_SECRET_KEY', 'at8-license-secret-key-2025-at8fun-请替换为随机字符串');
}

class AT8_License {
    private static $plugin_slug = 'at8';
    private static $plugin_version = '1.0.0';
    private static $license_option = 'at8_license_data';
    
    /**
     * 初始化
     */
    public static function init($plugin_slug, $version) {
        self::$plugin_slug = $plugin_slug;
        self::$plugin_version = $version;
        
        add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'check_update']);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('wp_ajax_at8_activate_license', [__CLASS__, 'ajax_activate']);
    }
    
    /**
     * 检查授权状态
     */
    public static function is_valid() {
        $license_data = get_option(self::$license_option);
        
        if (!$license_data) {
            return false;
        }
        
        // 检查本地过期
        if (strtotime($license_data['expires']) < time()) {
            self::deactivate();
            return false;
        }
        
        // 检查缓存（6小时）
        $cache = get_transient('at8_license_valid_' . self::$plugin_slug);
        if ($cache === true) {
            return true;
        }
        
        // 尝试远程验证
        $response = self::remote_validate($license_data['token']);
        
        if ($response === true) {
            set_transient('at8_license_valid_' . self::$plugin_slug, true, HOUR_IN_SECONDS * 6);
            return true;
        }
        
        // 远程失败，检查离线备份
        if (self::has_offline_backup()) {
            set_transient('at8_license_valid_' . self::$plugin_slug, true, HOUR_IN_SECONDS * 6);
            return true;
        }
        
        return false;
    }
    
    /**
     * 远程验证
     */
    private static function remote_validate($token) {
        // 本地模式：查询授权管理插件的数据库
        if (function_exists('AT8_License_Manager')) {
            global $wpdb;
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "at8_licenses WHERE license_key = %s AND status = 'active'",
                $token
            ));
            
            if (!$license) {
                return new WP_Error('not_found', 'License not found');
            }
            
            if (strtotime($license->expires) < time()) {
                return new WP_Error('expired', 'License expired');
            }
            
            $current_host = self::get_normalized_host();
            if ($current_host !== $license->normalized_host) {
                return new WP_Error('domain_mismatch', 'Domain mismatch');
            }
            
            return true;
        }
        
        return new WP_Error('no_server', 'No license server');
    }
    
    /**
     * 获取规范化域名
     */
    private static function get_normalized_host() {
        $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $host = preg_replace('/^www\./', '', $host);
        return $host;
    }
    
    /**
     * 检查离线备份
     */
    private static function has_offline_backup() {
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/at8-license-backup-' . self::$plugin_slug . '.json';
        
        if (!file_exists($backup_path)) {
            return false;
        }
        
        $content = json_decode(file_get_contents($backup_path), true);
        
        if (!$content || !isset($content['data']) || !isset($content['signature'])) {
            return false;
        }
        
        // 检查备份是否在 7 天内
        if (time() - ($content['backup_at'] ?? 0) > 7 * DAY_IN_SECONDS) {
            return false;
        }
        
        // 验证签名
        $expected = hash_hmac('sha256', json_encode($content['data'], JSON_UNESCAPED_SLASHES), AT8_LICENSE_SECRET_KEY);
        return hash_equals($expected, $content['signature']);
    }
    
    /**
     * 从授权包文件激活
     */
    public static function activate_from_file($file_content) {
        $data = json_decode($file_content, true);
        
        if (!$data || !isset($data['license_key'])) {
            return new WP_Error('invalid_file', '无效的授权包文件');
        }
        
        // 验证签名
        $expected_sig = hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_SLASHES), AT8_LICENSE_SECRET_KEY);
        
        if (!hash_equals($expected_sig, $data['signature'] ?? '')) {
            return new WP_Error('invalid_signature', '授权包签名验证失败');
        }
        
        // 验证域名
        $current_host = self::get_normalized_host();
        if ($current_host !== $data['normalized_host']) {
            return new WP_Error('domain_mismatch', '域名不匹配');
        }
        
        // 验证是否过期
        if (strtotime($data['expires']) < time()) {
            return new WP_Error('expired', '授权已过期');
        }
        
        // 保存激活信息
        update_option(self::$license_option, [
            'license_key' => $data['license_key'],
            'token' => $data['license_key'],
            'expires' => $data['expires'],
            'normalized_host' => $data['normalized_host'],
            'activated_at' => time()
        ]);
        
        // 写离线备份
        self::save_offline_backup($data);
        
        return true;
    }
    
    /**
     * 保存离线备份
     */
    private static function save_offline_backup($data) {
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/at8-license-backup-' . self::$plugin_slug . '.json';
        
        $content = json_encode([
            'data' => $data,
            'backup_at' => time(),
            'signature' => hash_hmac('sha256', json_encode($data, JSON_UNESCAPED_SLASHES), AT8_LICENSE_SECRET_KEY)
        ], JSON_PRETTY_PRINT);
        
        $dir = dirname($backup_path);
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
        
        file_put_contents($backup_path, $content);
    }
    
    /**
     * 取消激活
     */
    public static function deactivate() {
        delete_option(self::$license_option);
        delete_transient('at8_license_valid_' . self::$plugin_slug);
        
        $upload_dir = wp_upload_dir();
        $backup_path = $upload_dir['basedir'] . '/at8-license-backup-' . self::$plugin_slug . '.json';
        
        if (file_exists($backup_path)) {
            unlink($backup_path);
        }
    }
    
    /**
     * 检查更新（注入 WordPress 更新机制）
     */
    public static function check_update($transient) {
        if (!self::is_valid()) {
            return $transient;
        }
        
        if (function_exists('AT8_License_Manager')) {
            global $wpdb;
            $license_data = get_option(self::$license_option);
            
            if (!$license_data) {
                return $transient;
            }
            
            $license = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . $wpdb->prefix . "at8_licenses WHERE license_key = %s",
                $license_data['license_key']
            ));
            
            if ($license && version_compare($license->latest_version ?? '0', self::$plugin_version, '>')) {
                $transient->responses[plugin_basename(__FILE__)] = (object)[
                    'new_version' => $license->latest_version,
                    'package' => add_query_arg([
                        'key' => $license->license_key,
                        'v' => $license->latest_version
                    ], admin_url('admin-ajax.php?action=at8_download_plugin')),
                    'slug' => self::$plugin_slug,
                    'url' => 'https://www.at8.fun/'
                ];
            }
        }
        
        return $transient;
    }
    
    /**
     * 显示管理员提示
     */
    public static function admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (self::is_valid()) {
            $license_data = get_option(self::$license_option);
            if ($license_data) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p><strong>AT8 ' . self::$plugin_slug . '</strong> 已授权，有效期至 ' . date('Y-m-d', strtotime($license_data['expires'])) . '</p>';
                echo '</div>';
            }
            return;
        }
        
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>AT8 ' . self::$plugin_slug . '</strong> 未激活授权。请 <a href="' . admin_url('options-general.php?page=at8-' . self::$plugin_slug . '-license') . '">点击此处</a> 激活。</p>';
        echo '</div>';
    }
    
    /**
     * 添加后台菜单
     */
    public static function add_menu() {
        add_options_page(
            'AT8 ' . ucwords(self::$plugin_slug) . ' 授权',
            'AT8 ' . ucwords(self::$plugin_slug),
            'manage_options',
            'at8-' . self::$plugin_slug . '-license',
            [__CLASS__, 'render_license_page']
        );
    }
    
    /**
     * 渲染授权页面
     */
    public static function render_license_page() {
        $license_data = get_option(self::$license_option);
        $is_valid = self::is_valid();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('at8_activate_license');
            
            $file = $_FILES['license_file'] ?? null;
            
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $error = '请选择有效的授权包文件';
            } else {
                $content = file_get_contents($file['tmp_name']);
                $result = self::activate_from_file($content);
                
                if (is_wp_error($result)) {
                    $error = $result->get_error_message();
                } else {
                    $is_valid = true;
                    $license_data = get_option(self::$license_option);
                }
            }
        }
        ?>
        <div class="wrap">
            <h1>AT8 <?php echo ucwords(self::$plugin_slug); ?> 授权</h1>
            
            <?php if (isset($error)): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>
            
            <?php if ($is_valid && $license_data): ?>
                <div class="notice notice-success">
                    <p><strong>授权状态：</strong>已激活</p>
                    <p><strong>授权码：</strong><code><?php echo esc_html($license_data['license_key']); ?></code></p>
                    <p><strong>到期时间：</strong><?php echo esc_html(date('Y-m-d H:i', strtotime($license_data['expires']))); ?></p>
                    <p><strong>绑定域名：</strong><?php echo esc_html($license_data['normalized_host']); ?></p>
                </div>
            <?php else: ?>
                <div class="card">
                    <h2>激活授权</h2>
                    <p>请上传从 AT8 授权管理系统下载的 <code>.license</code> 文件。</p>
                    
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('at8_activate_license'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="license_file">授权包文件</label></th>
                                <td>
                                    <input type="file" name="license_file" id="license_file" accept=".license,.json" required>
                                    <p class="description">文件扩展名：.license 或 .json</p>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">激活授权</button>
                        </p>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * AJAX 处理激活
     */
    public static function ajax_activate() {
        check_ajax_referer('at8_activate', 'nonce');
        
        $file_content = $_POST['license_content'] ?? '';
        
        if (empty($file_content)) {
            wp_send_json_error(['message' => '授权包内容为空']);
        }
        
        $result = self::activate_from_file($file_content);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
        
        wp_send_json_success(['message' => '授权激活成功']);
    }
    
    /**
     * AJAX 下载插件更新
     */
    public static function ajax_download() {
        $key = sanitize_text_field($_GET['key'] ?? '');
        $version = sanitize_text_field($_GET['v'] ?? '');
        
        global $wpdb;
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "at8_licenses WHERE license_key = %s AND status = 'active'",
            $key
        ));
        
        if (!$license) {
            wp_die('Invalid license');
        }
        
        $download_url = add_query_arg([
            'key' => $key,
            'v' => $version,
            'sig' => hash_hmac('sha256', $key . $version, AT8_LICENSE_SECRET_KEY)
        ], home_url('/wp-content/uploads/at8-plugins/'));
        
        wp_redirect($download_url);
        exit;
    }
}
