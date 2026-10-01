<?php
/**
 * SDK 配置。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AT8SDK_Config')) {

class AT8SDK_Config
{
    /** @var string 授权服务器地址，如 https://www.at8.fun */
    public $server_url;

    /** @var string 产品 slug（与授权中心产品一致，如 at8-security-pro） */
    public $product;

    /** @var string Pro 插件主文件 */
    public $plugin_file;

    /** @var string 购买链接 */
    public $buy_url;

    /** @var array 授权页挂载：parent（WP admin 页面 slug）+ page_title/menu_title；
     *             传 parent=null 则插件自渲染（用 render_page() 嵌入自己的页面） */
    public $menu;

    /** @var string 文本域 */
    public $text_domain;

    /** @var string 自动注册页面的页面 slug（menu 配置了 parent 时使用） */
    public $menu_slug;

    public function __construct($config)
    {
        $c = array_merge([
            'server_url'  => 'https://www.at8.fun',
            'product'     => '',
            'plugin_file' => '',
            'buy_url'     => '',
            'menu'        => null,
            'menu_slug'   => '',
            'text_domain' => 'at8-license-sdk',
        ], is_array($config) ? $config : []);

        // 常量覆盖（wp-config.php 可硬编码，防止被篡改指向假服务器）
        if (defined('AT8SDK_LICENSE_SERVER') && AT8SDK_LICENSE_SERVER) {
            $c['server_url'] = AT8SDK_LICENSE_SERVER;
        }

        $this->server_url  = untrailingslashit((string) $c['server_url']);
        $this->product     = (string) $c['product'];
        $this->plugin_file = (string) $c['plugin_file'];
        $this->buy_url     = (string) $c['buy_url'];
        $this->menu        = $c['menu'];
        $this->menu_slug   = $c['menu_slug'] !== '' ? $c['menu_slug'] : 'at8lic-sdk-' . sanitize_key($this->product);
        $this->text_domain = (string) $c['text_domain'];
    }

    public function plugin_basename()
    {
        return $this->plugin_file ? plugin_basename($this->plugin_file) : '';
    }

    public function site_url()
    {
        return home_url();
    }

    /** 本产品状态 option key（autoload=false） */
    public function state_option()
    {
        return 'at8lic_sdk_' . sanitize_key($this->product) . '_state';
    }

    public function api_url($path)
    {
        return $this->server_url . '/wp-json/at8-license/v1' . $path;
    }
}

} // class_exists guard
