<?php
/**
 * Plugin Name: At8-安全防护
 * Description: 与「At8-加速优化」相辅相成的轻量安全插件：品牌/视觉一致，但代码零耦合、可独立开启。覆盖 WordPress 基础安全、登录保护、高级 WAF、文件完整性、强密码策略、登录验证码与内容安全策略 (CSP)。
 * Version: 1.0.4
 * Author: 漫步白月光
 * Author URI: https://www.at8.fun/
 * Plugin URI: https://www.at8.fun/
 * Text Domain: at8-security
 * Domain Path: /languages
 *
 * 设计铁律：
 *  - 所有高风险功能默认关闭并带恢复机制，避免误锁站点。
 *  - 与「At8-加速优化」插件无任何 require / 函数依赖，互不干扰。
 *  - 紧急恢复常量（写在 wp-config.php）：
 *      define('SS_DISABLE_LOGIN_URL', true); // 关闭"隐藏登录入口"并恢复 wp-login.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SS_VERSION', '1.0.4' );
define( 'SS_OPTION', 'at8_security_settings' );

/**
 * 配置项默认值。每个键都带类型暗示（bool / int / string），sanitize 据此处理。
 */
function ss_defaults() {
	return array(
		// ① 基础加固
		'hide_wp_version'        => 1,
		'disable_xmlrpc'         => 1,
		'disable_rest_user_enum' => 1,
		'uniform_login_errors'   => 1,
		'disable_file_editor'    => 1,
		'security_headers'       => 1,
		'remove_rsd_wlw'         => 1,
		'disable_author_enum'    => 1,
		'disable_dir_listing'    => 0,
		'uploads_disable_php'    => 0,

		// ② 登录保护
		'login_throttle'         => 1,
		'login_max_retries'      => 5,
		'login_lockout_minutes'  => 15,
		'change_login_url'       => 0,
		'login_slug'             => '',
		'login_email_alert'      => 1,
		'login_alert_email'      => '',
		'honeypot'               => 1,

		// ②-新增：登录验证码 / 强密码（用户明确要求，默认开启；CSP 默认 Report-Only 不阻断）
		'login_captcha'          => 1,
		'pwd_policy'             => 1,
		'pwd_min_length'         => 12,
		'weak_pwd_scan'          => 1,

		// ②-新增：登录验证码模式（math 数学题 / turnstile Cloudflare 滑动验证）
		'captcha_type'           => 'math',
		'turnstile_site_key'     => '',
		'turnstile_secret_key'   => '',

		// ③ 高级防护（WAF / 限流）
		'waf_basic'              => 1,
		'waf_sql'                => 1,
		'waf_xss'                => 1,
		'waf_traversal'          => 1,
		'waf_rce'                => 1,
		'ip_blacklist'           => '',
		'ip_whitelist'           => '',
		'rate_limit_global'      => 1,
		'rate_limit_max'         => 60,
		'rate_limit_window'      => 60,
		'block_bad_ua'           => 1,

		// ③ 高级防护 — CDN / 上游 WAF 兼容
		'waf_cdn_compat'       => 0,
		'waf_trusted_proxies'  => '',
		'error_404_throttle'     => 1,
		'error_404_threshold'    => 20,

		// ④ 文件完整性
		'integrity_enabled'      => 1,

		// ⑤ 内容安全策略 CSP（用户明确要求，默认开启；Report-Only 模式不阻断前端）
		'enable_csp'             => 1,
		'csp_report_only'        => 1,
		'csp_policy'             => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",

		// ⑥ 源码混淆（隐藏主题/插件路径，默认关闭、需开启；低物理风险）
		'obfuscate_content'      => 0,
		'content_alias'          => 'assets',

		// ⑦ 评论/表单垃圾防护（蜜罐 + 未登录评论限速）
		'comment_spam'           => 1,
		'disable_comments'       => 0,

		// ⑧ 强制 HTTPS / HSTS
		'force_https'            => 1,
		'hsts'                   => 0,

		// ⑨ 安全审计日志（DB 表）
		'audit_log'              => 1,
		'audit_log_days'         => 30,

		// ⑩ 文件上传类型限制（拦截可执行/危险类型）
		'upload_restrict'        => 1,

		// ⑪ REST API 锁定（未登录禁止非核心端点；默认关，可能误伤合法 API）
		'rest_lockdown'          => 0,

		// ⑫ 闲置账户禁用（cron，默认关；仅非管理员）
		'idle_disable'           => 0,
		'idle_days'              => 90,
	);
}

function ss_get_settings() {
	$saved = get_option( SS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, ss_defaults() );
}

function ss_setting( $key, $default = null ) {
	$all = ss_get_settings();
	return isset( $all[ $key ] ) ? $all[ $key ] : $default;
}

/**
 * 兼容代理 / CDN 的客户端 IP 获取（修复 P1：不可信代理头可被伪造，导致限流/白名单全部被绕过）。
 *
 * 信任顺序：
 *  1. HTTP_X_REAL_IP —— 由上游代理（ESA / 反代）单值设置，客户端无法注入，最可靠；
 *  2. HTTP_X_FORWARDED_FOR 首段（最原始访客 IP）—— 仅当直连来源 REMOTE_ADDR 属于受信代理（WAF 节点）时才信任
 *     （受信列表 = wp-config 常量 SS_TRUSTED_PROXIES 合并设置项 waf_trusted_proxies；支持 CIDR）；
 *  3. 否则回退连接层 REMOTE_ADDR（代理自身地址）。
 */
function ss_get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
	if ( is_array( $remote ) ) {
		$remote = $remote[0] ?? '0.0.0.0';
	}

	// 兼容 CDN / 上游 WAF：受信代理列表 = wp-config 常量 SS_TRUSTED_PROXIES 合并设置项 waf_trusted_proxies
	$compat     = (bool) ss_setting( 'waf_cdn_compat' );
	$trusted    = ss_trusted_proxies();
	$from_proxy = $trusted && ss_ip_in_list( $remote, $trusted );

	// 上游单值头：仅当「兼容模式」且直连来源属于受信代理时才采用，避免伪造（兼容模式关闭时保持原行为）
	$real = isset( $_SERVER['HTTP_X_REAL_IP'] ) ? trim( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) ) : ''; // phpcs:ignore
	if ( $real && filter_var( $real, FILTER_VALIDATE_IP ) ) {
		if ( ! $compat || $from_proxy ) {
			return $real;
		}
	}

	// X-Forwarded-For 首段（最原始访客）：格式为 client, proxy1, proxy2，最左即发起请求的访客 IP；
	// 仅当直连来源属于受信代理（WAF 节点）时才信任，避免客户端伪造。
	if ( $from_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$parts = array_map( 'trim', explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) ); // phpcs:ignore
		$ip    = $parts[0] ?? '';
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return $remote;
}

/**
 * 判断某 IP 是否出现在以逗号/换行分隔的列表中。
 */
function ss_ip_in_list( $ip, $list ) {
	$list = preg_split( '/[\s,]+/', (string) $list, -1, PREG_SPLIT_NO_EMPTY );
	if ( empty( $list ) ) {
		return false;
	}
	foreach ( $list as $entry ) {
		$entry = trim( $entry );
		if ( '' === $entry ) {
			continue;
		}
		if ( strpos( $entry, '/' ) !== false ) {
			if ( ss_ip_in_cidr( $ip, $entry ) ) {
				return true;
			}
		} elseif ( $ip === $entry ) {
			return true;
		}
	}
	return false;
}

function ss_ip_in_cidr( $ip, $cidr ) {
	list( $subnet, $mask ) = array_pad( explode( '/', $cidr ), 2, '32' );
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$ip_long    = ip2long( $ip );
		$subnet_long = ip2long( $subnet );
		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}
		$mask_long = ~ ( ( 1 << ( 32 - (int) $mask ) ) - 1 );
		return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
	}
	return false;
}

/**
 * 受信代理 / WAF 节点 IP 列表（合并 wp-config 常量 SS_TRUSTED_PROXIES 与设置项 waf_trusted_proxies）。
 *
 * 来自这些 IP 的连接被视为 CDN / 上游 WAF 边缘节点：其转发的真实访客 IP（X-Real-IP / X-Forwarded-For）
 * 会被信任；且这些节点 IP 永不会被本插件封禁或限速（避免整站被打死）。
 *
 * @return array
 */
function ss_trusted_proxies() {
	$list = array();
	if ( defined( 'SS_TRUSTED_PROXIES' ) ) {
		$c = preg_split( '/[\s,]+/', (string) SS_TRUSTED_PROXIES, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $c as $e ) {
			$e = trim( $e );
			if ( '' !== $e ) {
				$list[] = $e;
			}
		}
	}
	$setting = ss_setting( 'waf_trusted_proxies' );
	if ( $setting ) {
		$c = preg_split( '/[\s,]+/', (string) $setting, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $c as $e ) {
			$e = trim( $e );
			if ( '' !== $e ) {
				$list[] = $e;
			}
		}
	}
	return implode( ' ', $list );
}

/* ============================================================
 * ① 基础加固
 * ============================================================ */
class SS_Hardening {

	public function __construct() {
		if ( ss_setting( 'disable_file_editor' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}

		if ( ss_setting( 'hide_wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'style_loader_src', array( $this, 'remove_ver' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'remove_ver' ), 9999 );
		}

		if ( ss_setting( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'kill_xmlrpc_pingback' ) );
		}

		if ( ss_setting( 'disable_rest_user_enum' ) ) {
			add_filter( 'rest_endpoints', array( $this, 'disable_users_endpoint' ) );
		}

		if ( ss_setting( 'uniform_login_errors' ) ) {
			add_filter( 'login_errors', array( $this, 'generic_login_error' ) );
		}

		if ( ss_setting( 'remove_rsd_wlw' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( ss_setting( 'security_headers' ) ) {
			add_action( 'send_headers', array( $this, 'send_security_headers' ) );
		}

		if ( ss_setting( 'disable_author_enum' ) ) {
			add_action( 'template_redirect', array( $this, 'block_author_enum' ) );
		}

		if ( ss_setting( 'disable_dir_listing' ) || ss_setting( 'uploads_disable_php' ) ) {
			add_action( 'init', array( $this, 'apply_htaccess' ) );
		}
	}

	public function remove_ver( $src ) {
		if ( strpos( $src, 'ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	public function kill_xmlrpc_pingback( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	public function disable_users_endpoint( $endpoints ) {
		if ( ! is_user_logged_in() && isset( $endpoints['/wp/v2/users'] ) ) {
			unset( $endpoints['/wp/v2/users'] );
		}
		if ( ! is_user_logged_in() && isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
			unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}

	public function generic_login_error( $msg ) {
		return esc_html__( '用户名或密码错误。', 'at8-security' );
	}

	public function send_security_headers() {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( "Permissions-Policy: geolocation=(), microphone=(), camera=()" );
	}

	public function block_author_enum() {
		if ( ! is_admin() && is_author() && ! is_user_logged_in() ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
		if ( isset( $_GET['author'] ) && ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
	}

	/**
	 * 通过 .htaccess 加固（仅 Apache 生效；Nginx 需手动在服务器配置加规则）。
	 */
	public function apply_htaccess() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) || ! is_writable( ABSPATH . '.htaccess' ) ) {
			return;
		}
		$lines = array();
		if ( ss_setting( 'disable_dir_listing' ) ) {
			$lines[] = 'Options -Indexes';
		}
		if ( ss_setting( 'uploads_disable_php' ) ) {
			$uploads = wp_upload_dir();
			$rel     = ltrim( str_replace( ABSPATH, '', $uploads['basedir'] ), '/' );
			if ( $rel ) {
				$lines[] = '<FilesMatch "\.php$">';
				$lines[] = '    # At8-安全防护：禁止 uploads 目录执行 PHP';
				$lines[] = '    Require all denied';
				$lines[] = '</FilesMatch>';
			}
		}
		if ( empty( $lines ) ) {
			return;
		}
		insert_with_markers( ABSPATH . '.htaccess', 'At8 Security', $lines );
	}
}

/* ============================================================
 * ② 登录保护（限流 / 隐藏入口 / 邮件告警 / 蜜罐）
 * ============================================================ */
class SS_Login_Protection {

	public function __construct() {
		if ( ss_setting( 'login_throttle' ) ) {
			add_action( 'wp_login_failed', array( $this, 'login_failed' ) );
			add_action( 'wp_login', array( $this, 'login_success' ), 10, 2 );
			add_filter( 'authenticate', array( $this, 'check_lockout' ), 30, 3 );
		}

		// 自定义登录地址：用自定义 slug 替换默认的 wp-login.php
		if ( ss_setting( 'change_login_url' ) && ! defined( 'SS_DISABLE_LOGIN_URL' ) ) {
			add_filter( 'site_url', array( $this, 'filter_site_url' ), 10, 4 );
			add_filter( 'network_site_url', array( $this, 'filter_site_url' ), 10, 3 );
			add_filter( 'wp_redirect', array( $this, 'filter_redirect' ), 10, 2 );
			add_action( 'init', array( $this, 'serve_custom_login' ), 1 );
			add_action( 'init', array( $this, 'block_default_login' ), 2 );
		}

		if ( ss_setting( 'login_email_alert' ) ) {
			add_action( 'wp_login', array( $this, 'email_alert' ), 20, 2 );
		}

		if ( ss_setting( 'honeypot' ) ) {
			add_action( 'login_form', array( $this, 'honeypot_field' ) );
			add_filter( 'authenticate', array( $this, 'honeypot_check' ), 25, 3 );
		}
	}

	private function ip_lock_key() {
		$ip = ss_get_client_ip();
		// 兼容 CDN/WAF：来自受信代理但取不到真实访客 IP 时，不按“代理节点 IP”加锁，避免锁死全站登录
		if ( (bool) ss_setting( 'waf_cdn_compat' ) ) {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
			if ( is_array( $remote ) ) {
				$remote = $remote[0] ?? '0.0.0.0';
			}
			$trusted = ss_trusted_proxies();
			if ( $trusted && ss_ip_in_list( $remote, $trusted ) && $ip === $remote ) {
				return 'ss_lock_proxy_unknown';
			}
		}
		return 'ss_lock_' . md5( $ip );
	}

	public function login_failed( $username ) {
		$max = (int) ss_setting( 'login_max_retries' );
		$key = $this->ip_lock_key();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array( 'count' => 0, 'until' => 0 );
		}
		if ( $data['until'] > time() ) {
			return; // 已锁定期内，不再累计
		}
		$data['count'] ++;
		if ( $data['count'] >= $max ) {
			$data['until'] = time() + (int) ss_setting( 'login_lockout_minutes' ) * 60;
			$data['count'] = 0;
		}
		set_transient( $key, $data, (int) ss_setting( 'login_lockout_minutes' ) * 60 + 600 );
	}

	public function login_success( $user_login, $user ) {
		delete_transient( $this->ip_lock_key() );
	}

	public function check_lockout( $user, $username, $password ) {
		$data = get_transient( $this->ip_lock_key() );
		if ( is_array( $data ) && ! empty( $data['until'] ) && $data['until'] > time() ) {
			$left = ceil( ( $data['until'] - time() ) / 60 );
			return new WP_Error( 'ss_locked', sprintf( esc_html__( '登录尝试过于频繁，请 %d 分钟后再试。', 'at8-security' ), $left ) );
		}
		return $user;
	}

	/**
	 * 命中自定义登录地址时，加载真正的登录页（wp-login.php）。
	 * 通过 require 复用 wp-login.php，其内部 wp-load.php 使用 require_once，不会重复加载 WordPress。
	 */
	public function serve_custom_login() {
		$slug = trim( (string) ss_setting( 'login_slug' ), '/' );
		if ( ! $slug ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$path = untrailingslashit( strtok( $uri, '?' ) );
		$path = ltrim( $path, '/' );
		if ( $path === $slug ) {
			// wp-login.php 的 login_footer() 会读取 $user_login / $error 两个核心未初始化变量
			// （WordPress 已知瑕疵：官方核心 login_footer 不声明 global，且全文件从不为 $error 赋值）。
			// 仅在生产 display_errors 开启时可见，且会泄露服务器绝对路径。此处仅在该次登录页渲染期间
			// 临时关闭 E_WARNING / E_NOTICE，渲染后立即恢复原 error_reporting，不影响其他页面。
			// 根治做法：生产环境 display_errors = Off（见 wp-config 的 WP_DEBUG_DISPLAY）。
			$prev = error_reporting( error_reporting() & ~E_WARNING & ~E_NOTICE );
			require ABSPATH . 'wp-login.php';
			error_reporting( $prev );
			exit;
		}
	}

	/**
	 * 拦截默认登录入口，强制走自定义地址。
	 * 关键：不重定向到自定义地址（否则等于把秘密地址甩给任何扫 wp-admin 的访客，
	 * “自定义登录”形同虚设），也不输出任何暴露框架的文案——统一返回中性 404，
	 * 让默认入口看起来“不存在”。已登录用户放行（后台可正常进入）。
	 */
	public function block_default_login() {
		$slug = trim( (string) ss_setting( 'login_slug' ), '/' );
		if ( ! $slug ) {
			return;
		}
		// 已登录：放行（后台、wp-admin 子资源正常可用）
		if ( is_user_logged_in() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$path = untrailingslashit( strtok( $uri, '?' ) );
		$path = ltrim( $path, '/' );

		// 1) 默认登录文件：永远隐藏（已登录用户也不应走 wp-login.php）
		if ( strpos( $uri, 'wp-login.php' ) !== false ) {
			nocache_headers();
			status_header( 404 );
			wp_die( '', '', array( 'response' => 404 ) );
		}

		// 2) 裸 wp-admin（后台入口，非子资源）：返回中性 404，不跳转到自定义地址。
		//    放行 admin-ajax / async-upload / load-scripts / load-styles / admin-post 等
		//    前端依赖的后台子文件，避免影响站内 AJAX / 上传等正常功能。
		if ( 'wp-admin' === $path || 'wp-admin/' === $path ) {
			nocache_headers();
			status_header( 404 );
			wp_die( '', '', array( 'response' => 404 ) );
		}
	}

	/**
	 * 把站内所有 wp-login.php 链接改写为自定义登录地址（含登录表单 POST、登出、找回密码、注册等）。
	 */
	public function filter_site_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		$slug = trim( (string) ss_setting( 'login_slug' ), '/' );
		if ( $slug && strpos( (string) $url, 'wp-login.php' ) !== false ) {
			$url = str_replace( 'wp-login.php', $slug, (string) $url );
		}
		return $url;
	}

	/**
	 * 重定向目标若指向 wp-login.php，一并改写，避免登录后跳回被拦截的旧地址。
	 */
	public function filter_redirect( $location, $status = 0 ) {
		$slug = trim( (string) ss_setting( 'login_slug' ), '/' );
		if ( $slug && strpos( (string) $location, 'wp-login.php' ) !== false ) {
			$location = str_replace( 'wp-login.php', $slug, (string) $location );
		}
		return $location;
	}

	public function email_alert( $user_login, $user ) {
		$ip   = ss_get_client_ip();
		$last = get_transient( 'ss_last_ip_' . $user->ID );
		if ( $last === $ip ) {
			return;
		}
		set_transient( 'ss_last_ip_' . $user->ID, $ip, MONTH_IN_SECONDS );
		$to = ss_setting( 'login_alert_email' );
		if ( ! $to || ! is_email( $to ) ) {
			$to = get_option( 'admin_email' );
		}
		$subj = sprintf( '[%s] ' . esc_html__( '检测到新 IP 登录', 'at8-security' ), wp_parse_url( home_url(), PHP_URL_HOST ) );
		$body = sprintf( esc_html__( "用户 %s 于 %s 从新 IP %s 登录。若非本人操作请立即修改密码。", 'at8-security' ), $user_login, current_time( 'mysql' ), $ip );
		wp_mail( $to, $subj, $body );
	}

	public function honeypot_field() {
		echo '<p style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
			. '<label>邮箱（请勿填写）<input type="text" name="ss_hp_email" value="" tabindex="-1" autocomplete="off"></label></p>';
	}

	public function honeypot_check( $user, $username, $password ) {
		if ( ! empty( $_POST['ss_hp_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return new WP_Error( 'ss_honeypot', esc_html__( '登录失败。', 'at8-security' ) );
		}
		return $user;
	}
}

/* ============================================================
 * ③ 高级防护（WAF / IP 名单 / 限流 / 404 限流）
 * ============================================================ */
class SS_WAF {

	private $patterns = array();

	public function __construct() {
		// 说明：WAF 仅为纵深防御的补充，绝不能替代参数化查询与输出转义（P3）。
		// 这里收紧常见绕过：UNION ALL、/**/ 间隔、更多事件处理器、%5c 遍历等。
		$this->patterns = array(
			'sql'      => '/(\bunion\b(\s+all\s+)?\bselect\b|\bunion\b.{0,40}\bselect\b|\bselect\b.+\bfrom\b|\binsert\b\s+\binto\b|\bdrop\b\s+\btable\b|\bor\b\s+1\s*=\s*1)/i',
			'xss'      => '/(<script\b|%3cscript|on(load|error|mouseover|mouseout|focus|blur|click|change|submit|input|keydown|keyup|contextmenu|pageshow)\s*=|javascript\s*:)/i',
			'traversal' => '/(\.\.\/|\.\.\\|%2e%2e%2f|%2e%2e\/|\.\.%2f|%2e%2e%5c)/i',
			'rce'      => '/(\bexec\b\s*\(|\bsystem\b\s*\(|\bpassthru\b|\bproc_open\b|\$\{.*\}|`[^`]+`)/i',
		);

		add_action( 'init', array( $this, 'run' ), 2 );
		add_action( 'template_redirect', array( $this, 'throttle_404' ) );
	}

	public function run() {
		// 后台不拦截，避免误伤管理员操作
		if ( is_admin() ) {
			return;
		}

		// REST 与 AJAX 由 WordPress 自身校验；且 php://input 不可重复读，
		// WAF 若读取会破坏 REST / JSON 接口的请求体，故整体跳过（修复 P2）。
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		if ( is_array( $remote ) ) {
			$remote = $remote[0] ?? '0.0.0.0';
		}
		$ip = ss_get_client_ip();

		// 兼容 CDN / 上游 WAF：直连来源为受信代理节点时，真实访客 IP 由 ss_get_client_ip() 从代理头取得；
		// 若无法取得真实 IP（代理未转发），则不对“代理节点 IP”做限速/封禁，避免整站被打死。
		$compat      = (bool) ss_setting( 'waf_cdn_compat' );
		$trusted     = ss_trusted_proxies();
		$from_proxy  = $trusted && ss_ip_in_list( $remote, $trusted );
		$has_real_ip = ! ( $compat && $from_proxy && $ip === $remote );

		// 白名单：完全绕过所有检查（仅针对真实访客 IP；即便 WAF 节点 IP 被误列，也绝不因此绕过整站防护）
		if ( $has_real_ip && ss_setting( 'ip_whitelist' ) && ss_ip_in_list( $ip, ss_setting( 'ip_whitelist' ) ) ) {
			return;
		}

		// 管理员已登录：免除速率 / UA 限制（仍受黑名单与 WAF 保护）
		$is_admin_user = is_user_logged_in() && current_user_can( 'manage_options' );

		// IP 黑名单（仅作用于真实访客 IP；受信代理节点 IP 永不被封）
		if ( $has_real_ip && ss_setting( 'ip_blacklist' ) && ss_ip_in_list( $ip, ss_setting( 'ip_blacklist' ) ) ) {
			$this->deny( 'ip-blacklist' );
		}

		// 恶意 User-Agent（请求内容级，非 IP 级，对受信代理转发来的恶意请求照常拦截）
		if ( ! $is_admin_user && ss_setting( 'block_bad_ua' ) ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
			if ( $this->bad_ua( $ua ) ) {
				$this->deny( 'bad-ua' );
			}
		}

		// 全局速率限制（仅作用于真实访客 IP，避免把整个 WAF / 代理节点限速掉）
		if ( $has_real_ip && ! $is_admin_user && ss_setting( 'rate_limit_global' ) ) {
			$this->rate_limit( $ip );
		}

		// 基础 WAF（请求内容级扫描，始终执行）
		if ( ss_setting( 'waf_basic' ) ) {
			$this->waf_scan();
		}
	}

	private function bad_ua( $ua ) {
		if ( '' === trim( $ua ) ) {
			return true; // 空 UA 多为扫描器
		}
		$bad = array( 'sqlmap', 'nikto', 'nmap', 'masscan', 'nessus', 'wpscan', 'hydra', 'dirbuster', 'acunetix', 'curl', 'wget', 'python-requests', 'go-http-client', 'semrushbot', 'ahrefsbot', 'mj12bot' );
		foreach ( $bad as $b ) {
			if ( stripos( $ua, $b ) !== false ) {
				return true;
			}
		}
		return false;
	}

	private function rate_limit( $ip ) {
		$max    = (int) ss_setting( 'rate_limit_max' );
		$window = (int) ss_setting( 'rate_limit_window' );
		$key    = 'ss_rate_' . md5( $ip );
		$count  = (int) get_transient( $key );
		$count ++;
		if ( $count > $max ) {
			$this->deny( 'rate-limit' );
		}
		set_transient( $key, $count, $window );
	}

	private function waf_scan() {
		// 仅扫描已解析的超全局与服务器变量，绝不读取 php://input（该流不可重复读，
		// 读取后会破坏 REST / JSON 接口的请求体）。修复 P2。
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? urldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET'; // phpcs:ignore
		$get  = 'GET' === $method ? urldecode( http_build_query( (array) $_GET ) ) : '';
		$post = ! in_array( $method, array( 'GET', 'HEAD' ), true ) ? urldecode( http_build_query( (array) $_POST ) ) : '';
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore
		$cookie = isset( $_SERVER['HTTP_COOKIE'] ) ? wp_unslash( $_SERVER['HTTP_COOKIE'] ) : ''; // phpcs:ignore
		$hay  = $uri . ' ' . $get . ' ' . $post . ' ' . $ua . ' ' . $cookie;

		$checks = array(
			'sql'      => ss_setting( 'waf_sql' ),
			'xss'      => ss_setting( 'waf_xss' ),
			'traversal' => ss_setting( 'waf_traversal' ),
			'rce'      => ss_setting( 'waf_rce' ),
		);
		foreach ( $checks as $type => $on ) {
			if ( $on && isset( $this->patterns[ $type ] ) && preg_match( $this->patterns[ $type ], $hay ) ) {
				$this->deny( 'waf-' . $type );
			}
		}
	}

	public function throttle_404() {
		if ( is_admin() || ! ss_setting( 'error_404_throttle' ) ) {
			return;
		}
		if ( ! is_404() ) {
			return;
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		if ( is_array( $remote ) ) {
			$remote = $remote[0] ?? '0.0.0.0';
		}
		$ip = ss_get_client_ip();

		// 兼容 CDN/WAF：来自受信代理但取不到真实访客 IP 时，不按代理节点 IP 限流，避免整站被打死
		$compat      = (bool) ss_setting( 'waf_cdn_compat' );
		$trusted     = ss_trusted_proxies();
		$from_proxy  = $trusted && ss_ip_in_list( $remote, $trusted );
		$has_real_ip = ! ( $compat && $from_proxy && $ip === $remote );
		if ( ! $has_real_ip ) {
			return;
		}
		if ( ss_setting( 'ip_whitelist' ) && ss_ip_in_list( $ip, ss_setting( 'ip_whitelist' ) ) ) {
			return;
		}
		$threshold = (int) ss_setting( 'error_404_threshold' );
		$key       = 'ss_404_' . md5( $ip );
		$count     = (int) get_transient( $key );
		$count ++;
		if ( $count > $threshold ) {
			$this->deny( '404-flood' );
		}
		set_transient( $key, $count, 300 );
	}

	private function deny( $reason ) {
		status_header( 403 );
		header( 'X-At8-Security: ' . $reason );
		wp_die( esc_html__( '请求被安全策略拦截。', 'at8-security' ), esc_html__( '已拦截', 'at8-security' ), array( 'response' => 403 ) );
	}
}

/* ============================================================
 * ④ 文件完整性（md5 基线 + 扫描）
 * ============================================================ */
class SS_Integrity {

	private $baseline_option = 'ss_integrity_baseline';

	public function __construct() {
		// 扫描由设置页工具按钮触发，这里仅注册辅助逻辑
	}

	private function core_paths() {
		$paths = array( ABSPATH . 'wp-admin', ABSPATH . 'wp-includes' );
		// 根目录核心 PHP 文件
		$root_files = array( 'index.php', 'wp-login.php', 'wp-config-sample.php', 'xmlrpc.php', 'wp-load.php', 'wp-settings.php', 'wp-cron.php', 'wp-blog-header.php', 'wp-comments-post.php', 'wp-links-opml.php', 'wp-mail.php', 'wp-signup.php', 'wp-trackback.php' );
		$files      = array();
		foreach ( $root_files as $f ) {
			if ( file_exists( ABSPATH . $f ) ) {
				$files[] = ABSPATH . $f;
			}
		}
		return array( 'dirs' => $paths, 'files' => $files );
	}

	public function build_baseline() {
		$baseline = $this->hash_all();
		update_option( $this->baseline_option, $baseline );
		return count( $baseline );
	}

	public function scan() {
		$baseline = get_option( $this->baseline_option );
		if ( ! is_array( $baseline ) ) {
			return array( 'error' => esc_html__( '尚未建立基线，请先生成基线。', 'at8-security' ) );
		}
		$current  = $this->hash_all();
		$changed  = array();
		$added    = array();
		$missing  = array();
		foreach ( $baseline as $file => $hash ) {
			if ( ! isset( $current[ $file ] ) ) {
				$missing[] = $file;
			} elseif ( $current[ $file ] !== $hash ) {
				$changed[] = $file;
			}
		}
		foreach ( $current as $file => $hash ) {
			if ( ! isset( $baseline[ $file ] ) ) {
				$added[] = $file;
			}
		}
		return array(
			'changed' => $changed,
			'added'   => $added,
			'missing' => $missing,
			'count'   => count( $baseline ),
		);
	}

	private function hash_all() {
		$map  = array();
		$info = $this->core_paths();
		foreach ( $info['files'] as $f ) {
			$map[ $f ] = md5_file( $f );
		}
		foreach ( $info['dirs'] as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iter as $file ) {
				if ( $file->isFile() && substr( $file->getFilename(), -4 ) === '.php' ) {
					$map[ $file->getPathname() ] = md5_file( $file->getPathname() );
				}
			}
		}
		return $map;
	}
}

/* ============================================================
 * ②-新增 强密码策略 / 弱密码扫描
 * ============================================================ */
class SS_Password_Policy {

	private $weak_dict = array(
		'123456', 'password', '12345678', 'qwerty', 'abc123', '111111', '123123',
		'admin', 'admin123', 'letmein', 'welcome', 'monkey', 'dragon', 'passw0rd',
		'123456789', 'password1', 'iloveyou', '1q2w3e4r', 'qwerty123', 'root',
		'test', 'test123', 'changeme', 'P@ssw0rd', 'p@ssword',
	);

	public function __construct() {
		if ( ss_setting( 'pwd_policy' ) ) {
			add_filter( 'user_profile_update_errors', array( $this, 'validate_profile' ), 10, 3 );
			add_filter( 'registration_errors', array( $this, 'validate_registration' ), 10, 2 );
		}
	}

	private function meets_policy( $password ) {
		$len = (int) ss_setting( 'pwd_min_length' );
		if ( strlen( $password ) < $len ) {
			return sprintf( esc_html__( '密码长度至少 %d 位。', 'at8-security' ), $len );
		}
		if ( ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/[A-Z]/', $password ) ) {
			return esc_html__( '密码需同时包含大写与小写字母。', 'at8-security' );
		}
		if ( ! preg_match( '/\d/', $password ) ) {
			return esc_html__( '密码需至少包含一位数字。', 'at8-security' );
		}
		if ( ! preg_match( '/[^a-zA-Z0-9]/', $password ) ) {
			return esc_html__( '密码需至少包含一个特殊符号。', 'at8-security' );
		}
		return true;
	}

	public function validate_profile( $errors, $update, $user ) {
		if ( $update && empty( $user->user_pass ) ) {
			return $errors; // 未修改密码
		}
		$ok = $this->meets_policy( $user->user_pass );
		if ( true !== $ok ) {
			$errors->add( 'ss_pwd', $ok );
		}
		return $errors;
	}

	public function validate_registration( $errors, $sanitized_user_login ) {
		if ( empty( $_POST['user_pass'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $errors;
		}
		$ok = $this->meets_policy( wp_unslash( $_POST['user_pass'] ) ); // phpcs:ignore
		if ( true !== $ok ) {
			$errors->add( 'ss_pwd', $ok );
		}
		return $errors;
	}

	/**
	 * 弱密码扫描：用常见弱密码字典比对现有用户。
	 */
	public function scan_weak() {
		$results = array();
		$users   = get_users( array( 'fields' => array( 'ID', 'user_login' ) ) );
		foreach ( $users as $u ) {
			$hash = get_user_meta( $u->ID, 'wp_user_pass', true );
			if ( ! $hash ) {
				$user_obj = get_user_by( 'id', $u->ID );
				$hash     = $user_obj ? $user_obj->user_pass : '';
			}
			if ( ! $hash ) {
				continue;
			}
			foreach ( $this->weak_dict as $candidate ) {
				if ( wp_check_password( $candidate, $hash ) ) {
					$results[] = $u->user_login;
					break;
				}
			}
		}
		return $results;
	}
}

/* ============================================================
 * ②-新增 登录验证码（数学题，无 GD 依赖）
 * ============================================================ */
class SS_Captcha {

	/**
	 * 是否使用 Cloudflare Turnstile（滑动验证）。
	 * 仅当模式为 turnstile 且站点/密钥均已配置时启用，否则回退数学题。
	 */
	public static function use_turnstile() {
		return ss_setting( 'captcha_type' ) === 'turnstile'
			&& trim( (string) ss_setting( 'turnstile_site_key' ) )
			&& trim( (string) ss_setting( 'turnstile_secret_key' ) );
	}

	public function __construct() {
		if ( ! ss_setting( 'login_captcha' ) ) {
			return;
		}
		add_action( 'login_form', array( $this, 'field' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_turnstile' ) );
		add_filter( 'authenticate', array( $this, 'verify' ), 28, 3 );
	}

	public function enqueue_turnstile() {
		if ( self::use_turnstile() ) {
			wp_enqueue_script( 'ss-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		}
	}

	public function field() {
		if ( self::use_turnstile() ) {
			$site = trim( (string) ss_setting( 'turnstile_site_key' ) );
			echo '<div class="ss-captcha"><div class="cf-turnstile" data-sitekey="' . esc_attr( $site ) . '"></div>'
				. '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></div>';
			return;
		}
		$a   = wp_rand( 1, 9 );
		$b   = wp_rand( 1, 9 );
		$sum = $a + $b;
		$key = 'ss_cap_' . md5( ss_get_client_ip() . $sum );
		set_transient( $key, $sum, 600 );
		echo '<p class="ss-captcha"><label>' . sprintf( esc_html__( '人机验证：%d + %d = ?', 'at8-security' ), $a, $b )
			. '<br><input type="text" name="ss_captcha" class="input" autocomplete="off"></label></p>';
	}

	public function verify( $user, $username, $password ) {
		if ( self::use_turnstile() ) {
			return $this->verify_turnstile( $user );
		}
		return $this->verify_math( $user );
	}

	/**
	 * 校验 Turnstile 令牌，返回 bool。登录与评论提交共用，避免重复实现。
	 */
	public static function verify_turnstile_token( $token ) {
		if ( empty( $token ) ) {
			return false;
		}
		$secret = trim( (string) ss_setting( 'turnstile_secret_key' ) );
		$resp   = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => ss_get_client_ip(),
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		return ! empty( $body['success'] );
	}

	private function verify_turnstile( $user ) {
		$token = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : ''; // phpcs:ignore
		if ( empty( $token ) ) {
			return new WP_Error( 'ss_captcha', esc_html__( '请完成人机验证。', 'at8-security' ) );
		}
		if ( ! self::verify_turnstile_token( $token ) ) {
			return new WP_Error( 'ss_captcha', esc_html__( '人机验证未通过，请重试。', 'at8-security' ) );
		}
		return $user;
	}

	private function verify_math( $user ) {
		if ( empty( $_POST['ss_captcha'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return new WP_Error( 'ss_captcha', esc_html__( '请完成人机验证。', 'at8-security' ) );
		}
		$answer = trim( wp_unslash( $_POST['ss_captcha'] ) ); // phpcs:ignore
		foreach ( array( 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18 ) as $s ) {
			$key = 'ss_cap_' . md5( ss_get_client_ip() . $s );
			if ( get_transient( $key ) !== false ) {
				delete_transient( $key );
				if ( (string) $answer === (string) get_transient( $key ) || (int) $answer === $s ) {
					return $user;
				}
				return new WP_Error( 'ss_captcha', esc_html__( '人机验证答案错误。', 'at8-security' ) );
			}
		}
		return new WP_Error( 'ss_captcha', esc_html__( '人机验证已失效，请重试。', 'at8-security' ) );
	}
}

/* ============================================================
 * ⑤ 内容安全策略 CSP
 * ============================================================ */
class SS_CSP {

	public function __construct() {
		if ( ss_setting( 'enable_csp' ) ) {
			add_action( 'send_headers', array( $this, 'send' ), 5 );
		}
	}

	public function send() {
		if ( is_admin() ) {
			return; // 后台不施加 CSP，避免破坏编辑器
		}
		$policy = trim( ss_setting( 'csp_policy' ) );
		if ( ! $policy ) {
			return;
		}
		$header = ss_setting( 'csp_report_only' ) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
		header( $header . ': ' . $policy );
	}
}

class SS_2FA {

    public function run() {
        if ( ! ss_setting( 'enable_2fa' ) ) {
            return;
        }
        // 紧急恢复：在 wp-config.php 加 define('SS_DISABLE_2FA', true); 即可全局关闭
        if ( defined( 'SS_DISABLE_2FA' ) && SS_DISABLE_2FA ) {
            return;
        }
        add_action( 'show_user_profile', array( $this, 'profile' ) );
        add_action( 'edit_user_profile', array( $this, 'profile' ) );
        add_action( 'personal_options_update', array( $this, 'save_profile' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_profile' ) );
        add_filter( 'authenticate', array( $this, 'verify' ), 35, 3 );
    }

    public function profile( $user ) {
        if ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) {
            return;
        }
        $enabled = (bool) get_user_meta( $user->ID, 'ss_2fa_enabled', true );
        $secret  = (string) get_user_meta( $user->ID, 'ss_2fa_secret', true );
        if ( ! $secret ) {
            $secret = $this->generate_secret();
            update_user_meta( $user->ID, 'ss_2fa_secret', $secret );
        }
        $label   = wp_parse_url( home_url(), PHP_URL_HOST );
        $otpauth = 'otpauth://totp/' . rawurlencode( $label . ':' . $user->user_login ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $label );
        ?>
        <h2><?php esc_html_e( '双因素认证（2FA）', 'at8-security' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="ss_2fa_enabled"><?php esc_html_e( '启用 2FA', 'at8-security' ); ?></label></th>
                <td>
                    <input type="checkbox" name="ss_2fa_enabled" id="ss_2fa_enabled" value="1" <?php checked( $enabled ); ?> />
                    <p class="description"><?php esc_html_e( '启用后，登录需在密码之外输入身份验证器（如 Google Authenticator）生成的 6 位动态码。', 'at8-security' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( '密钥 / 绑定', 'at8-security' ); ?></th>
                <td>
                    <code style="display:inline-block;padding:6px 8px;background:#f1f5f9;border-radius:4px;"><?php echo esc_html( $secret ); ?></code>
                    <p class="description"><?php esc_html_e( '在身份验证器 App 中手动添加下方地址（或手动输入上方密钥）：', 'at8-security' ); ?><br>
                    <textarea readonly style="width:100%;height:60px;font-family:monospace;font-size:11px;"><?php echo esc_textarea( $otpauth ); ?></textarea></p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_profile( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }
        $enabled = isset( $_POST['ss_2fa_enabled'] ) ? 1 : 0;
        update_user_meta( $user_id, 'ss_2fa_enabled', $enabled );
    }

    public function verify( $user, $username, $password ) {
        if ( is_wp_error( $user ) || is_user_logged_in() ) {
            return $user;
        }
        $u = get_user_by( 'login', $username );
        if ( ! $u || ! (bool) get_user_meta( $u->ID, 'ss_2fa_enabled', true ) ) {
            return $user;
        }
        $code = isset( $_POST['ss_2fa_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ss_2fa_code'] ) ) ) : '';
        if ( ! $this->verify_code( $u->ID, $code ) ) {
            return new WP_Error( 'ss_2fa', __( '动态验证码错误，请使用身份验证器重新生成。', 'at8-security' ) );
        }
        return $user;
    }

    private function generate_secret( $length = 16 ) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // RFC4648 base32（无填充）
        $out   = '';
        for ( $i = 0; $i < $length; $i ++ ) {
            $out .= $chars[ random_int( 0, 31 ) ];
        }
        return $out;
    }

    private function verify_code( $user_id, $code ) {
        $secret = (string) get_user_meta( $user_id, 'ss_2fa_secret', true );
        if ( ! $secret || ! preg_match( '/^\d{6}$/', $code ) ) {
            return false;
        }
        $slice = (int) ( time() / 30 );
        for ( $i = - 1; $i <= 1; $i ++ ) {
            if ( $this->totp( $secret, $slice + $i ) === $code ) {
                return true;
            }
        }
        return false;
    }

    private function base32_decode( $secret ) {
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper( str_replace( ' ', '', $secret ) );
        $buf    = '';
        $bits   = 0;
        $val    = 0;
        for ( $i = 0; $i < strlen( $secret ); $i ++ ) {
            $idx = strpos( $chars, $secret[ $i ] );
            if ( false === $idx ) {
                continue;
            }
            $val  = ( $val << 5 ) | $idx;
            $bits += 5;
            if ( $bits >= 8 ) {
                $bits -= 8;
                $buf  .= chr( ( $val >> $bits ) & 0xFF );
            }
        }
        return $buf;
    }

    private function totp( $secret, $time_slice ) {
        $key = $this->base32_decode( $secret );
        $msg = pack( 'N', 0 ) . pack( 'N', $time_slice );
        $hash = hash_hmac( 'sha1', $msg, $key, true );
        $off  = ord( $hash[19] ) & 0x0F;
        $bin  = ( ( ord( $hash[ $off ] ) & 0x7F ) << 24 )
              | ( ( ord( $hash[ $off + 1 ] ) & 0xFF ) << 16 )
              | ( ( ord( $hash[ $off + 2 ] ) & 0xFF ) << 8 )
              | ( ord( $hash[ $off + 3 ] ) & 0xFF );
        return str_pad( (string) ( $bin % 1000000 ), 6, '0', STR_PAD_LEFT );
    }
}

/* ============================================================
 * ⑥ 源码混淆：隐藏主题/插件路径（将 /wp-content/ 改写为自定义别名）
 *    原理：仅改写前端输出的 URL + 服务器层重写别名回 wp-content，
 *    物理目录与 wp-config 均不动，插件自身不受影响，风险低。
 *    仅 Apache 自动写 .htaccess；Nginx 需在服务器配置手动加规则。
 * ============================================================ */
class SS_Content_Obfuscator {

	private $alias = 'assets';

	public function __construct() {
		if ( ! ss_setting( 'obfuscate_content' ) ) {
			// 关闭时确保 .htaccess 别名段被移除
			add_action( 'init', array( $this, 'maybe_remove_htaccess' ), 1 );
			return;
		}
		$alias       = trim( ss_setting( 'content_alias' ) );
		$this->alias = $alias ? $alias : 'assets';
		if ( ! is_admin() ) {
			add_filter( 'content_url', array( $this, 'rewrite' ), 99 );
			add_filter( 'plugins_url', array( $this, 'rewrite' ), 99 );
			add_filter( 'stylesheet_directory_uri', array( $this, 'rewrite' ), 99 );
			add_filter( 'template_directory_uri', array( $this, 'rewrite' ), 99 );
			add_filter( 'upload_dir', array( $this, 'rewrite_upload_dir' ), 99 );
		}
		// 整页输出缓冲：覆盖过滤器无法触达的路径（文章正文图 / 内联 CSS / JS 字符串）。
		// 必须在站点加速（Redis 页面缓存）的 ob_start（init 优先级 5）之后启动，
		// 使其成为内层缓冲，确保缓存存储的是已改写后的 HTML。
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 9999 );
		add_action( 'init', array( $this, 'maybe_write_htaccess' ), 1 );
	}

	public function rewrite( $url ) {
		return $this->replace( $url );
	}

	public function rewrite_upload_dir( $info ) {
		if ( isset( $info['baseurl'] ) ) {
			$info['baseurl'] = $this->replace( $info['baseurl'] );
		}
		if ( isset( $info['url'] ) ) {
			$info['url'] = $this->replace( $info['url'] );
		}
		return $info;
	}

	private function replace( $url ) {
		return str_replace( '/wp-content/', '/' . $this->alias . '/', (string) $url );
	}

	/**
	 * 启动整页输出缓冲（仅前端 HTML 响应）。
	 * 不在 admin / AJAX / REST / XML-RPC 下启用，避免破坏接口与后台。
	 */
	public function start_buffer() {
		if ( ! ss_setting( 'obfuscate_content' ) ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}
		ob_start( array( $this, 'flush_buffer' ) );
	}

	/**
	 * 输出缓冲回调：对最终 HTML 做全量替换，
	 * 覆盖文章正文图片、内联 CSS background、JS 字符串等过滤器无法触达的路径。
	 */
	public function flush_buffer( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		return str_replace( '/wp-content/', '/' . $this->alias . '/', $html );
	}

	private function htaccess_lines() {
		$alias = $this->alias;
		return array(
			'RewriteEngine On',
			"RewriteRule ^{$alias}/(.*)$ wp-content/$1 [L]",
		);
	}

	public function maybe_write_htaccess() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) || ! is_writable( ABSPATH . '.htaccess' ) ) {
			return;
		}
		insert_with_markers( ABSPATH . '.htaccess', 'At8 Security Content Alias', $this->htaccess_lines() );
	}

	public function maybe_remove_htaccess() {
		$file = ABSPATH . '.htaccess';
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) || ! is_writable( $file ) ) {
			return;
		}
		$current = file_get_contents( $file );
		if ( false !== $current && strpos( $current, '# BEGIN At8 Security Content Alias' ) !== false ) {
			insert_with_markers( $file, 'At8 Security Content Alias', array() );
		}
	}
}

/* ============================================================
 * ⑦ 数据库表前缀修改（高风险工具）
 *    防 SQL 注入猜表名；需重命名表 + 改写 wp-config + 更新 usermeta/options 键。
 *    操作前必须先手动备份数据库；失败可能无法自动恢复。红字警告见设置页。
 * ============================================================ */
class SS_Db_Prefix {

	/**
	 * 执行表前缀修改。
	 *
	 * @param string $new_prefix 新前缀（须以 _ 结尾）
	 * @param bool   $backup_ok  是否已确认备份
	 * @return string|WP_Error
	 */
	public function change_prefix( $new_prefix, $backup_ok ) {
		global $wpdb;
		if ( ! $backup_ok ) {
			return new WP_Error( 'no-backup', esc_html__( '请先确认已备份数据库（勾选"我已备份"）。', 'at8-security' ) );
		}
		$new_prefix = (string) $new_prefix;
		if ( substr( $new_prefix, -1 ) !== '_' || ! preg_match( '/^[a-zA-Z0-9_]+$/', $new_prefix ) ) {
			return new WP_Error( 'bad-prefix', esc_html__( '新前缀只能含字母数字下划线，且必须以 _ 结尾。', 'at8-security' ) );
		}
		$old = $wpdb->prefix;
		if ( $new_prefix === $old ) {
			return new WP_Error( 'same-prefix', esc_html__( '新前缀与当前相同。', 'at8-security' ) );
		}
		// 收集需重命名的表（仅匹配旧前缀）
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old . '%' ) ); // phpcs:ignore
		if ( empty( $tables ) ) {
			return new WP_Error( 'no-tables', esc_html__( '未找到以当前前缀开头的表。', 'at8-security' ) );
		}
		$renamed = 0;
		foreach ( $tables as $t ) {
			$new_name = $new_prefix . substr( $t, strlen( $old ) );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_name ) ) ) { // phpcs:ignore
				return new WP_Error( 'exists', sprintf( esc_html__( '目标表 %s 已存在，中止以免覆盖。', 'at8-security' ), $new_name ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- 表名不可参数化，已做白名单校验
			$wpdb->query( "RENAME TABLE `{$t}` TO `{$new_name}`" );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_name ) ) ) { // phpcs:ignore
				$renamed ++;
			}
		}
		// 更新 usermeta 关键键（权限相关，必须同步）
		$wpdb->query( $wpdb->prepare( "UPDATE `{$new_prefix}usermeta` SET meta_key = %s WHERE meta_key = %s", $new_prefix . 'capabilities', $old . 'capabilities' ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "UPDATE `{$new_prefix}usermeta` SET meta_key = %s WHERE meta_key = %s", $new_prefix . 'user_level', $old . 'user_level' ) ); // phpcs:ignore
		// 更新 options 关键键（角色定义）
		$wpdb->query( $wpdb->prepare( "UPDATE `{$new_prefix}options` SET option_name = %s WHERE option_name = %s", $new_prefix . 'user_roles', $old . 'user_roles' ) ); // phpcs:ignore

		$config_ok = $this->update_wp_config( $new_prefix );
		return sprintf(
			esc_html__( '已重命名 %1$d 张表。若站点异常，请用备份恢复。wp-config.php 的 $table_prefix 更新：%2$s', 'at8-security' ),
			$renamed,
			$config_ok ? esc_html__( '成功', 'at8-security' ) : esc_html__( '失败（请手动修改 $table_prefix）', 'at8-security' )
		);
	}

	private function update_wp_config( $new_prefix ) {
		$file = ABSPATH . 'wp-config.php';
		if ( ! is_writable( $file ) ) {
			return false;
		}
		$content = file_get_contents( $file );
		if ( false === $content ) {
			return false;
		}
		$replaced = preg_replace( '/(\$table_prefix\s*=\s*[\'"]).*?([\'"];)/', '$1' . $new_prefix . '$2', $content );
		if ( null === $replaced || $replaced === $content ) {
			return false;
		}
		return (bool) file_put_contents( $file, $replaced );
	}
}

/* ============================================================
 * ⑧ 评论 / 表单垃圾防护（蜜罐 + 未登录评论限速）
 * ============================================================ */
class SS_Comment_Spam {

	public function __construct() {
		if ( ! ss_setting( 'comment_spam' ) ) {
			return;
		}
		add_action( 'comment_form', array( $this, 'form_fields' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'preprocess_comment', array( $this, 'check' ), 1 );
	}

	/**
	 * 评论表单渲染：蜜罐（始终）+ Turnstile（启用时）。
	 */
	public function form_fields( $post_id ) {
		// 蜜罐：隐藏字段，真人不会填，机器人填了即丢弃
		echo '<p style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
			. '<label>网站（请勿填写）<input type="text" name="ss_url_hp" value="" tabindex="-1" autocomplete="off"></label></p>';
		if ( SS_Captcha::use_turnstile() ) {
			$site = trim( (string) ss_setting( 'turnstile_site_key' ) );
			echo '<div class="ss-captcha" style="margin:12px 0;"><div class="cf-turnstile" data-sitekey="' . esc_attr( $site ) . '"></div></div>';
		}
	}

	public function enqueue() {
		if ( is_singular() && comments_open() && SS_Captcha::use_turnstile() ) {
			wp_enqueue_script( 'ss-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, true );
		}
	}

	public function check( $commentdata ) {
		// 蜜罐命中：伪装成功，静默丢弃机器人提交
		if ( ! empty( $_POST['ss_url_hp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_die( '', '', array( 'response' => 200 ) );
		}
		// Turnstile 验证：启用且未登录用户提交评论时强制校验（防垃圾评论）
		if ( SS_Captcha::use_turnstile() && ! is_user_logged_in() ) {
			$token = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : ''; // phpcs:ignore
			if ( ! SS_Captcha::verify_turnstile_token( $token ) ) {
				wp_die( esc_html__( '请完成人机验证后再提交评论。', 'at8-security' ), '', array( 'response' => 400 ) );
			}
		}
		// 未登录用户评论限速：同一 IP 60 秒内最多 1 条
		if ( ! is_user_logged_in() ) {
			$ip  = ss_get_client_ip();
			$key = 'ss_comment_' . md5( $ip );
			if ( get_transient( $key ) ) {
				wp_die( esc_html__( '评论过于频繁，请稍后再试。', 'at8-security' ), '', array( 'response' => 429 ) );
			}
			set_transient( $key, time(), 60 );
		}
		return $commentdata;
	}
}

/* ============================================================
 * ⑧-新增 一键关闭全站评论
 * 关闭评论/引用表单、移除文章评论支持、隐藏后台评论菜单与顶栏节点，
 * 并用空白模板替换评论区，确保前台不再接受或显示评论。开启即可、关闭即恢复。
 * ============================================================ */
class SS_Disable_Comments {

	public function __construct() {
		if ( ! ss_setting( 'disable_comments' ) ) {
			return;
		}
		// 前端：关闭所有文章/页面的评论与 pingback
		add_filter( 'comments_open', '__return_false', 20, 2 );
		add_filter( 'pings_open', '__return_false', 20, 2 );
		// 移除文章类型的评论/引用支持（讨论元数据框一并消失）
		add_action( 'init', array( $this, 'remove_support' ) );
		// 后台：隐藏"评论"菜单与顶栏评论节点
		add_action( 'admin_menu', array( $this, 'hide_menu' ) );
		add_action( 'wp_before_admin_bar_render', array( $this, 'hide_admin_bar' ) );
		// 用空白模板替换评论区，连已存在的评论也不渲染
		add_filter( 'comments_template', array( $this, 'blank_template' ), 20 );
	}

	public function remove_support() {
		foreach ( get_post_types() as $pt ) {
			if ( post_type_supports( $pt, 'comments' ) ) {
				remove_post_type_support( $pt, 'comments' );
			}
			if ( post_type_supports( $pt, 'trackbacks' ) ) {
				remove_post_type_support( $pt, 'trackbacks' );
			}
		}
	}

	public function hide_menu() {
		remove_menu_page( 'edit-comments.php' );
	}

	public function hide_admin_bar() {
		global $wp_admin_bar;
		if ( is_object( $wp_admin_bar ) ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	public function blank_template( $template ) {
		$file = __DIR__ . '/blank-comments.php';
		return file_exists( $file ) ? $file : $template;
	}
}

/* ============================================================
 * ⑨ 强制 HTTPS / HSTS
 * ============================================================ */
class SS_Https {

	public function __construct() {
		if ( ss_setting( 'force_https' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect' ) );
		}
		if ( ss_setting( 'hsts' ) ) {
			add_action( 'send_headers', array( $this, 'hsts' ) );
		}
	}

	public function redirect() {
		// 代理终止 TLS 时 is_ssl() 恒 false 会导致反复 301 死循环，信任上游协议头（修复 P9）
		$https = is_ssl();
		if ( ! $https && isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$https = strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) === 'https'; // phpcs:ignore
		}
		if ( $https || is_admin() ) {
			return;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
		if ( '' === $host ) {
			return;
		}
		wp_safe_redirect( 'https://' . $host . $uri, 301 );
		exit;
	}

	public function hsts() {
		if ( ! is_ssl() ) {
			return;
		}
		header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
	}
}

/* ============================================================
 * ⑩ 安全审计日志（DB 表）
 * ============================================================ */
class SS_Audit_Log {

	private static $table = 'ss_audit_log';

	public static function install() {
		global $wpdb;
		$table   = $wpdb->prefix . self::$table;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS `$table` (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ts DATETIME NOT NULL,
			event VARCHAR(40) NOT NULL,
			user_login VARCHAR(60) DEFAULT '',
			ip VARCHAR(45) DEFAULT '',
			detail VARCHAR(255) DEFAULT '',
			PRIMARY KEY (id),
			KEY event (event),
			KEY ts (ts)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function __construct() {
		if ( ! ss_setting( 'audit_log' ) ) {
			return;
		}
		add_action( 'wp_login_failed', array( $this, 'log_failed' ), 5 );
		add_action( 'wp_login', array( $this, 'log_success' ), 5, 2 );
		add_action( 'ss_after_settings_save', array( $this, 'log_settings' ) );
		add_action( 'ss_audit_purge', array( $this, 'purge' ) );
		if ( ! wp_next_scheduled( 'ss_audit_purge' ) ) {
			wp_schedule_event( time(), 'daily', 'ss_audit_purge' );
		}
	}

	public function add( $event, $user_login = '', $detail = '' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . self::$table,
			array(
				'ts'         => current_time( 'mysql' ),
				'event'      => $event,
				'user_login' => $user_login,
				'ip'         => ss_get_client_ip(),
				'detail'     => substr( (string) $detail, 0, 255 ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	public function log_failed( $user_login ) {
		$this->add( 'login_failed', $user_login );
	}

	public function log_success( $user_login, $user ) {
		$this->add( 'login_success', $user_login );
		if ( $user instanceof WP_User ) {
			update_user_meta( $user->ID, 'ss_last_login', current_time( 'mysql' ) );
		}
	}

	public function log_settings() {
		$this->add( 'settings_saved', wp_get_current_user()->user_login );
	}

	public function purge() {
		global $wpdb;
		$days = (int) ss_setting( 'audit_log_days' );
		if ( $days <= 0 ) {
			$days = 30;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->prefix}" . self::$table . "` WHERE ts < %s", date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	public function clear_all() {
		global $wpdb;
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}" . self::$table . "`" ); // phpcs:ignore
	}

	public function recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}" . self::$table . "` ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}
}

/* ============================================================
 * ⑪ 文件上传类型限制（拦截可执行 / 危险类型）
 * ============================================================ */
class SS_Upload_Restrict {

	private $deny_ext = array( 'php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx', 'exe', 'sh', 'bat', 'cmd', 'com', 'phps', 'htaccess' );

	public function __construct() {
		if ( ss_setting( 'upload_restrict' ) ) {
			add_filter( 'upload_mimes', array( $this, 'strip_dangerous' ), 9999 );
		}
	}

	public function strip_dangerous( $mimes ) {
		foreach ( $this->deny_ext as $ext ) {
			unset( $mimes[ $ext ] );
		}
		return $mimes;
	}
}

/* ============================================================
 * ⑫ REST API 锁定（未登录禁止非核心端点）
 * ============================================================ */
class SS_Rest_Lockdown {

	public function __construct() {
		if ( ! ss_setting( 'rest_lockdown' ) ) {
			return;
		}
		add_filter( 'rest_authentication_errors', array( $this, 'require_auth' ) );
	}

	public function require_auth( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}
		if ( is_user_logged_in() ) {
			return $result;
		}
		$route = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		// 允许核心公开端点（文章/页面/媒体/分类/标签/类型/状态/评论），其余要求登录
		if ( preg_match( '#/wp-json/wp/v2/(posts|pages|media|categories|tags|types|statuses|comments)#', $route ) ) {
			return $result;
		}
		return new WP_Error( 'rest_forbidden', esc_html__( '未授权访问。', 'at8-security' ), array( 'status' => 401 ) );
	}
}

/* ============================================================
 * ⑬ 闲置账户禁用（cron，默认关；仅非管理员，需先有登录记录）
 * ============================================================ */
class SS_Idle_Disable {

	public function __construct() {
		if ( ! ss_setting( 'idle_disable' ) ) {
			return;
		}
		add_action( 'ss_idle_check', array( $this, 'run' ) );
		if ( ! wp_next_scheduled( 'ss_idle_check' ) ) {
			wp_schedule_event( time(), 'daily', 'ss_idle_check' );
		}
	}

	public function run() {
		$days = (int) ss_setting( 'idle_days' );
		if ( $days <= 0 ) {
			$days = 90;
		}
		$users = get_users( array(
			'role__not_in' => array( 'administrator' ),
			'meta_key'     => 'ss_last_login',
			'meta_value'   => date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ),
			'meta_compare' => '<',
			'fields'       => 'ID',
		) );
		foreach ( $users as $uid ) {
			if ( user_can( $uid, 'manage_options' ) ) {
				continue;
			}
			$u = get_user_by( 'id', $uid );
			if ( $u ) {
				$u->set_role( '' ); // 移除角色即禁用登录
			}
		}
	}
}

/* ============================================================
 * ⑭ 数据库手动备份工具（生成 SQL 下载，不自动调度）
 * ============================================================ */
class SS_Db_Backup {

	public function dump() {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore
		$out    = "-- At8 Security DB Backup " . current_time( 'mysql' ) . "\n";
		$out   .= "SET NAMES utf8mb4;\n";
		// 敏感列/项脱敏：避免密码哈希与 SMTP 明文口令落入备份文件（修复 P8）
		$skip_cols = array( $wpdb->users => array( 'user_pass' ) );
		$skip_opts = array( 'moonlight_smtp_pass' );
		foreach ( $tables as $t ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `$t`", ARRAY_N ); // phpcs:ignore
			if ( $create ) {
				$out .= "\nDROP TABLE IF EXISTS `$t`;\n" . $create[1] . ";\n";
			}
			$rows = $wpdb->get_results( "SELECT * FROM `$t`", ARRAY_A ); // phpcs:ignore
			if ( empty( $rows ) ) {
				continue;
			}
			$cols = array_keys( $rows[0] );
			$skip = isset( $skip_cols[ $t ] ) ? $skip_cols[ $t ] : array();
			foreach ( $rows as $row ) {
				// options 表中明文口令项整行跳过
				if ( $t === $wpdb->options && isset( $row['option_name'] ) && in_array( $row['option_name'], $skip_opts, true ) ) {
					continue;
				}
				$vals = array();
				foreach ( $cols as $c ) {
					if ( in_array( $c, $skip, true ) ) {
						$vals[] = "'**REDACTED**'";
						continue;
					}
					$v = isset( $row[ $c ] ) ? $row[ $c ] : null;
					$vals[] = is_null( $v ) ? 'NULL' : "'" . esc_sql( $v ) . "'";
				}
				$out .= "INSERT INTO `$t` VALUES (" . implode( ',', $vals ) . ");\n";
			}
		}
		return $out;
	}
}

/* ============================================================
 * 设置页（基础加固 / 登录保护 / 高级防护 / 文件完整性 / CSP / 源码混淆 / 数据库前缀 / 使用提示）
 * ============================================================ */
class SS_Admin {

	private $integrity;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		register_setting( 'ss_settings', SS_OPTION, array( 'sanitize_callback' => 'ss_sanitize' ) );
		add_action( 'update_option_' . SS_OPTION, array( $this, 'after_settings_save' ) );
		$this->integrity = new SS_Integrity();
	}

	public function after_settings_save() {
		do_action( 'ss_after_settings_save' );
	}

	public function assets( $hook ) {
		if ( 'settings_page_ss-settings' !== $hook ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ ) . 'assets/admin.css';
		wp_enqueue_style( 'at8-sec-admin', $url, array(), SS_VERSION );
	}

	public function menu() {
		add_options_page(
			esc_html__( 'At8-安全防护', 'at8-security' ),
			esc_html__( 'At8-安全防护', 'at8-security' ),
			'manage_options',
			'ss-settings',
			array( $this, 'page' )
		);
	}

	public function admin_init() {
		// 工具按钮（与设置主表单共用 ss_settings-options nonce，避免保存冲突）
		if ( isset( $_POST['ss_run_tool'] ) && check_admin_referer( 'ss_settings-options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$this->run_tool( sanitize_key( $_POST['ss_run_tool'] ) ); // phpcs:ignore
		}
	}

	private function run_tool( $tool ) {
		$msg = '';
		if ( 'integrity_build' === $tool ) {
			$n   = $this->integrity->build_baseline();
			$msg = sprintf( esc_html__( '已生成文件完整性基线，共 %d 个文件。', 'at8-security' ), $n );
		} elseif ( 'integrity_scan' === $tool ) {
			$r = $this->integrity->scan();
			if ( isset( $r['error'] ) ) {
				$msg = $r['error'];
			} else {
				$msg = sprintf(
					esc_html__( '扫描完成：变更 %d 个，新增 %d 个，缺失 %d 个。', 'at8-security' ),
					count( $r['changed'] ), count( $r['added'] ), count( $r['missing'] )
				);
				if ( ! empty( $r['changed'] ) ) {
					$msg .= ' ' . esc_html__( '变更文件：', 'at8-security' ) . implode( ', ', array_map( 'basename', $r['changed'] ) );
				}
			}
		} elseif ( 'weak_pwd_scan' === $tool ) {
			$pp  = new SS_Password_Policy();
			$res = $pp->scan_weak();
			if ( empty( $res ) ) {
				$msg = esc_html__( '弱密码扫描完成：未发现使用常见弱密码的账户。', 'at8-security' );
			} else {
				$msg = esc_html__( '发现以下账户可能使用弱密码，请尽快修改：', 'at8-security' ) . ' ' . implode( ', ', $res );
			}
		} elseif ( 'db_prefix_change' === $tool ) {
			global $wpdb;
			$new        = isset( $_POST['ss_new_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['ss_new_prefix'] ) ) : ''; // phpcs:ignore
			$backup_ok  = ! empty( $_POST['ss_backup_ok'] ); // phpcs:ignore
			$dbp        = new SS_Db_Prefix();
			$res        = $dbp->change_prefix( $new, $backup_ok );
			$msg        = is_wp_error( $res ) ? $res->get_error_message() : $res;
			// 表已被重命名，set_transient 可能落到旧表；改用 GET 参数传递结果
			wp_safe_redirect( admin_url( 'options-general.php?page=ss-settings&ss_msg=' . urlencode( $msg ) ) );
			exit;
		} elseif ( 'db_backup' === $tool ) {
			$backup = new SS_Db_Backup();
			$sql    = $backup->dump();
			$fname  = 'db-backup-' . date( 'Ymd-His' ) . '.sql';
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="' . $fname . '"' );
			header( 'Content-Length: ' . strlen( $sql ) );
			echo $sql; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} elseif ( 'audit_clear' === $tool ) {
			$al   = new SS_Audit_Log();
			$al->clear_all();
			$msg = esc_html__( '审计日志已清空。', 'at8-security' );
		}
		set_transient( 'ss_admin_notice', $msg, 60 );
		wp_safe_redirect( admin_url( 'options-general.php?page=ss-settings' ) );
		exit;
	}

	public function notices() {
		$msg = get_transient( 'ss_admin_notice' );
		if ( $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			delete_transient( 'ss_admin_notice' );
		}
		if ( isset( $_GET['ss_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$text = sanitize_text_field( wp_unslash( $_GET['ss_msg'] ) ); // phpcs:ignore
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '无权限。', 'at8-security' ) );
		}
		global $wpdb;
		$s = ss_get_settings();
		?>
		<div class="wrap at8-sec-wrap">
			<h1><?php esc_html_e( 'At8-安全防护', 'at8-security' ); ?></h1>
			<p class="at8-sec-sub"><?php esc_html_e( '与「At8-加速优化」相辅相成的轻量安全插件。所有高风险功能默认关闭，请按需开启。', 'at8-security' ); ?></p>

			<nav class="at8-sec-tabs nav-tab-wrapper">
				<a href="#tab-hardening" class="nav-tab nav-tab-active" data-tab="hardening"><?php esc_html_e( '基础加固', 'at8-security' ); ?></a>
				<a href="#tab-login" class="nav-tab" data-tab="login"><?php esc_html_e( '登录保护', 'at8-security' ); ?></a>
				<a href="#tab-advanced" class="nav-tab" data-tab="advanced"><?php esc_html_e( '高级防护', 'at8-security' ); ?></a>
				<a href="#tab-integrity" class="nav-tab" data-tab="integrity"><?php esc_html_e( '文件完整性', 'at8-security' ); ?></a>
				<a href="#tab-csp" class="nav-tab" data-tab="csp">CSP</a>
				<a href="#tab-obfuscate" class="nav-tab" data-tab="obfuscate"><?php esc_html_e( '源码混淆', 'at8-security' ); ?></a>
				<a href="#tab-dbprefix" class="nav-tab" data-tab="dbprefix"><?php esc_html_e( '数据库前缀', 'at8-security' ); ?></a>
				<a href="#tab-extra" class="nav-tab" data-tab="extra"><?php esc_html_e( '附加防护', 'at8-security' ); ?></a>
				<a href="#tab-audit" class="nav-tab" data-tab="audit"><?php esc_html_e( '审计日志', 'at8-security' ); ?></a>
				<a href="#tab-backup" class="nav-tab" data-tab="backup"><?php esc_html_e( '数据备份', 'at8-security' ); ?></a>
				<a href="#tab-tips" class="nav-tab" data-tab="tips"><?php esc_html_e( '使用提示', 'at8-security' ); ?></a>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( 'ss_settings' ); ?>

				<div class="at8-sec-panel" id="tab-hardening">
					<h2><?php esc_html_e( '基础加固（建议全开）', 'at8-security' ); ?></h2>
					<?php $this->check( 'hide_wp_version', __( '隐藏 WordPress 版本号', 'at8-security' ), __( '移除 generator / 静态资源 ?ver=，降低被针对性攻击的风险。', 'at8-security' ) ); ?>
					<?php $this->check( 'disable_xmlrpc', __( '禁用 XML-RPC', 'at8-security' ), __( '防暴力登录与 pingback 反射攻击。', 'at8-security' ) ); ?>
					<?php $this->check( 'disable_rest_user_enum', __( '禁止匿名 REST 用户枚举', 'at8-security' ), __( '未登录无法访问 /wp/v2/users。', 'at8-security' ) ); ?>
					<?php $this->check( 'uniform_login_errors', __( '统一登录错误提示', 'at8-security' ), __( '不泄露用户名是否存在。', 'at8-security' ) ); ?>
					<?php $this->check( 'disable_file_editor', __( '禁用后台文件在线编辑', 'at8-security' ), __( '防入侵者直接改 PHP。', 'at8-security' ) ); ?>
					<?php $this->check( 'security_headers', __( '发送安全响应头', 'at8-security' ), __( 'X-Content-Type-Options / X-Frame-Options / Referrer-Policy / Permissions-Policy。', 'at8-security' ) ); ?>
					<?php $this->check( 'remove_rsd_wlw', __( '移除 RSD / WLW 链接', 'at8-security' ), __( '去 head 冗余标签。', 'at8-security' ) ); ?>
					<?php $this->check( 'disable_author_enum', __( '禁止 ?author=N 枚举作者', 'at8-security' ), __( '访问即跳首页。', 'at8-security' ) ); ?>
					<?php $this->check( 'disable_dir_listing', __( '禁止目录浏览', 'at8-security' ), __( '仅 Apache 生效（写入 .htaccess）；Nginx 需手动配置。', 'at8-security' ) ); ?>
					<?php $this->check( 'uploads_disable_php', __( '禁止 uploads 目录执行 PHP', 'at8-security' ), __( '仅 Apache 生效（写入 .htaccess）；Nginx 需手动配置。', 'at8-security' ) ); ?>
				</div>

				<div class="at8-sec-panel" id="tab-login" style="display:none">
					<h2><?php esc_html_e( '登录保护', 'at8-security' ); ?></h2>
					<?php $this->check( 'login_throttle', __( '登录失败次数限制', 'at8-security' ), __( '超过重试次数后临时锁定 IP。', 'at8-security' ) ); ?>
					<?php $this->number( 'login_max_retries', __( '最大重试次数', 'at8-security' ), $s['login_max_retries'] ); ?>
					<?php $this->number( 'login_lockout_minutes', __( '锁定时长（分钟）', 'at8-security' ), $s['login_lockout_minutes'] ); ?>
					<?php $this->check( 'change_login_url', __( '启用自定义登录地址', 'at8-security' ), __( '用自定义路径替换默认的 wp-login.php，旧地址将被 403 拦截。紧急恢复：wp-config.php 加 define(\'SS_DISABLE_LOGIN_URL\', true);', 'at8-security' ) ); ?>
				<?php $this->text( 'login_slug', __( '自定义登录路径（如 my-login，不要含斜杠，勿与已有页面重名）', 'at8-security' ), ss_setting( 'login_slug' ) ); ?>
					<?php $this->check( 'login_email_alert', __( '异常登录邮件告警', 'at8-security' ), __( '从新 IP 登录时通知管理员。', 'at8-security' ) ); ?>
					<?php $this->text( 'login_alert_email', __( '告警接收邮箱（留空用管理员邮箱）', 'at8-security' ), $s['login_alert_email'] ); ?>
					<?php $this->check( 'honeypot', __( '蜜罐隐藏字段', 'at8-security' ), __( '诱捕机器人。', 'at8-security' ) ); ?>

					<h3><?php esc_html_e( '登录验证码 / 强密码', 'at8-security' ); ?></h3>
					<?php $this->check( 'login_captcha', __( '登录验证码（人机验证）', 'at8-security' ), __( '启用后登录页增加人机验证；可选择数学题或 Cloudflare 滑动验证。', 'at8-security' ) ); ?>
					<?php $this->select( 'captcha_type', __( '验证方式', 'at8-security' ), array( 'math' => __( '数学题（无依赖）', 'at8-security' ), 'turnstile' => __( 'Cloudflare 滑动验证 (Turnstile)', 'at8-security' ) ), $s['captcha_type'] ); ?>
					<?php $this->text( 'turnstile_site_key', __( 'Turnstile 站点密钥 (Site Key)', 'at8-security' ), $s['turnstile_site_key'] ); ?>
					<?php $this->text( 'turnstile_secret_key', __( 'Turnstile 密钥 (Secret Key)', 'at8-security' ), $s['turnstile_secret_key'] ); ?>
					<?php $this->check( 'pwd_policy', __( '强密码策略', 'at8-security' ), __( '强制长度与复杂度。', 'at8-security' ) ); ?>
					<?php $this->number( 'pwd_min_length', __( '密码最小长度', 'at8-security' ), $s['pwd_min_length'] ); ?>
					<?php $this->check( 'weak_pwd_scan', __( '启用弱密码扫描工具', 'at8-security' ), __( '在下方"文件完整性"区使用扫描按钮。', 'at8-security' ) ); ?>
				</div>

				<div class="at8-sec-panel" id="tab-advanced" style="display:none">
					<h2><?php esc_html_e( '高级防护（建议谨慎开启）', 'at8-security' ); ?></h2>
					<?php $this->check( 'waf_basic', __( '基础 WAF', 'at8-security' ), __( 'SQL 注入 / XSS / 路径遍历 / 命令执行 保守正则。', 'at8-security' ) ); ?>
					<?php $this->check( 'waf_sql', __( '　└ SQL 注入拦截', 'at8-security' ), '' ); ?>
					<?php $this->check( 'waf_xss', __( '　└ XSS 拦截', 'at8-security' ), '' ); ?>
					<?php $this->check( 'waf_traversal', __( '　└ 路径遍历拦截', 'at8-security' ), '' ); ?>
					<?php $this->check( 'waf_rce', __( '　└ 命令执行拦截', 'at8-security' ), '' ); ?>

					<hr class="at8-sec-hr">
					<?php $this->check( 'waf_cdn_compat', __( '兼容 CDN / 上游 WAF', 'at8-security' ), __( '站点位于 CDN 或独立 WAF 之后时开启：从受信代理头（X-Real-IP / X-Forwarded-For）提取真实访客 IP 用于限流与封禁；受信代理节点 IP 永不被本防火墙封禁或限速，避免整站被打死。', 'at8-security' ) ); ?>
					<?php $this->textarea( 'waf_trusted_proxies', __( '受信代理 / WAF 节点 IP 白名单（每行一个，支持 CIDR）', 'at8-security' ), $s['waf_trusted_proxies'] ); ?>
					<p class="at8-sec-note"><?php esc_html_e( '把你的上游 WAF / CDN 边缘节点 IP 填在此处。来自这些 IP 的请求会信任其转发的真实访客 IP，且这些节点 IP 绝不会被本防火墙封禁或限速。若上游已通过 X-Real-IP 传递真实 IP，也可在 wp-config.php 定义 SS_TRUSTED_PROXIES 常量。', 'at8-security' ); ?></p>
					<?php $this->textarea( 'ip_blacklist', __( 'IP 黑名单（每行/逗号分隔，支持 CIDR）', 'at8-security' ), $s['ip_blacklist'] ); ?>
					<?php $this->textarea( 'ip_whitelist', __( 'IP 白名单（真实访客 IP，完全绕过检查；勿填 WAF 节点 IP）', 'at8-security' ), $s['ip_whitelist'] ); ?>
					<?php $this->check( 'rate_limit_global', __( '全局访问速率限制', 'at8-security' ), __( '防 CC / 恶意爬虫。', 'at8-security' ) ); ?>
					<?php $this->number( 'rate_limit_max', __( '速率上限（次/窗口）', 'at8-security' ), $s['rate_limit_max'] ); ?>
					<?php $this->number( 'rate_limit_window', __( '速率窗口（秒）', 'at8-security' ), $s['rate_limit_window'] ); ?>
					<?php $this->check( 'block_bad_ua', __( '恶意 User-Agent 拦截', 'at8-security' ), __( '拦截空 UA 与常见扫描器指纹。', 'at8-security' ) ); ?>
					<?php $this->check( 'error_404_throttle', __( '404 频繁访问限流', 'at8-security' ), __( '防扫描器。', 'at8-security' ) ); ?>
					<?php $this->number( 'error_404_threshold', __( '404 阈值（次/5分钟）', 'at8-security' ), $s['error_404_threshold'] ); ?>
				</div>

				<div class="at8-sec-panel" id="tab-integrity" style="display:none">
					<h2><?php esc_html_e( '文件完整性', 'at8-security' ); ?></h2>
					<?php $this->check( 'integrity_enabled', __( '启用文件完整性监控', 'at8-security' ), __( '生成核心文件 md5 基线后，可发现被篡改的核心文件。', 'at8-security' ) ); ?>
					<p>
							<?php wp_nonce_field( 'ss_settings-options' ); ?>
							<button type="submit" name="ss_run_tool" value="integrity_build" class="button"><?php esc_html_e( '生成基线', 'at8-security' ); ?></button>
							<button type="submit" name="ss_run_tool" value="integrity_scan" class="button"><?php esc_html_e( '立即扫描', 'at8-security' ); ?></button>
					</p>
					<?php if ( ss_setting( 'weak_pwd_scan' ) ) : ?>
					<h3><?php esc_html_e( '弱密码扫描', 'at8-security' ); ?></h3>
					<p>
							<?php wp_nonce_field( 'ss_settings-options' ); ?>
							<button type="submit" name="ss_run_tool" value="weak_pwd_scan" class="button"><?php esc_html_e( '扫描弱密码账户', 'at8-security' ); ?></button>
					</p>
					<?php endif; ?>
				</div>

				<div class="at8-sec-panel" id="tab-csp" style="display:none">
					<h2>CSP — <?php esc_html_e( '内容安全策略', 'at8-security' ); ?></h2>
					<?php $this->check( 'enable_csp', __( '启用 CSP', 'at8-security' ), __( '默认 Report-Only 模式（仅上报不阻断），避免误伤前端；确认无误后再切为强制。', 'at8-security' ) ); ?>
					<?php $this->check( 'csp_report_only', __( 'Report-Only 模式（不阻断）', 'at8-security' ), __( '开启时仅发送 Content-Security-Policy-Report-Only。', 'at8-security' ) ); ?>
					<?php $this->textarea( 'csp_policy', __( 'CSP 策略', 'at8-security' ), $s['csp_policy'] ); ?>
				</div>

				<div class="at8-sec-panel" id="tab-obfuscate" style="display:none">
					<h2><?php esc_html_e( '源码混淆（隐藏主题/插件路径）', 'at8-security' ); ?></h2>
					<p class="at8-sec-desc"><?php esc_html_e( '将前端输出的 /wp-content/ 路径改写为自定义别名（如 /assets/），配合服务器重写规则，使入侵者右键查看源码时无法直接识别主题与插件目录。物理目录与 wp-config 均不动，插件自身不受影响，风险较低。', 'at8-security' ); ?></p>
					<?php $this->check( 'obfuscate_content', __( '启用路径混淆', 'at8-security' ), __( '改写前端资源 URL，并在 Apache 自动写入 .htaccess 重写规则（Nginx 需手动配置，见下方）。', 'at8-security' ) ); ?>
					<?php $this->text( 'content_alias', __( '自定义别名（仅小写字母数字及 -_，不含斜杠）', 'at8-security' ), $s['content_alias'] ); ?>
					<?php if ( ss_setting( 'obfuscate_content' ) ) : ?>
						<p class="at8-sec-note"><?php esc_html_e( '已启用：请访问前台查看源码，确认 /wp-content/ 已被替换。若使用 Nginx，请在站点配置（server 段）手动加入以下规则（Apache 已自动写入 .htaccess）：', 'at8-security' ); ?></p>
						<pre class="at8-sec-code">location /<?php echo esc_html( $s['content_alias'] ); ?>/ {
    rewrite ^/<?php echo esc_html( $s['content_alias'] ); ?>/?(.*)$ /wp-content/$1 break;
}</pre>
					<?php endif; ?>
				</div>

				<div class="at8-sec-panel" id="tab-dbprefix" style="display:none">
					<h2><?php esc_html_e( '修改数据库表前缀（高风险）', 'at8-security' ); ?></h2>
					<div class="at8-sec-danger">
						<strong><?php esc_html_e( '⚠ 高风险操作', 'at8-security' ); ?></strong>
						<p><?php esc_html_e( '修改表前缀会重命名所有数据表、改写 wp-config.php，并更新用户元数据与配置键。一旦中途失败，站点可能白屏且无法自动恢复。', 'at8-security' ); ?></p>
						<p><?php esc_html_e( '执行前必须手动备份整个数据库（如 phpMyAdmin 导出 / 主机快照 / 命令行 mysqldump）。本插件不会自动备份。', 'at8-security' ); ?></p>
						<p><?php esc_html_e( '多站点（Multisite）环境不建议使用本工具。', 'at8-security' ); ?></p>
					</div>
					<p><?php printf( esc_html__( '当前表前缀：%s', 'at8-security' ), '<code>' . esc_html( $wpdb->prefix ) . '</code>' ); ?></p>
						<?php wp_nonce_field( 'ss_settings-options' ); ?>
						<p>
							<label><?php esc_html_e( '新表前缀（仅字母数字下划线，须以 _ 结尾）：', 'at8-security' ); ?><br>
							<input type="text" name="ss_new_prefix" class="regular-text" value="" placeholder="newprefix_">
							</label>
						</p>
						<p>
							<label><input type="checkbox" name="ss_backup_ok" value="1"> <?php esc_html_e( '我已备份数据库，确认继续', 'at8-security' ); ?></label>
						</p>
						<p>
							<button type="submit" name="ss_run_tool" value="db_prefix_change" class="button button-primary" onclick="return confirm('<?php esc_attr_e( '确认已备份？此操作不可自动回滚！', 'at8-security' ); ?>');"><?php esc_html_e( '执行修改表前缀', 'at8-security' ); ?></button>
						</p>
				</div>

				<div class="at8-sec-panel" id="tab-extra" style="display:none">
					<h2><?php esc_html_e( '附加防护', 'at8-security' ); ?></h2>
					<?php $this->check( 'comment_spam', __( '评论/表单垃圾防护', 'at8-security' ), __( '蜜罐隐藏字段 + 未登录用户评论限速，拦截机器人刷评论。', 'at8-security' ) ); ?>
					<?php $this->check( 'disable_comments', __( '一键关闭全站评论', 'at8-security' ), __( '关闭所有文章/页面的评论与引用，移除评论支持并隐藏后台"评论"菜单；开启后前台不再接受或显示评论，关闭即恢复。', 'at8-security' ) ); ?>
					<?php $this->check( 'force_https', __( '强制 HTTPS', 'at8-security' ), __( '前台 HTTP 自动 301 跳转到 HTTPS。', 'at8-security' ) ); ?>
					<?php $this->check( 'hsts', __( '启用 HSTS', 'at8-security' ), __( '高风险：一旦启用且后续无法提供 HTTPS，浏览器将拒绝访问；确认证书长期稳定后再开。', 'at8-security' ) ); ?>
					<?php $this->check( 'upload_restrict', __( '限制危险上传类型', 'at8-security' ), __( '禁止上传 php / asp / exe 等可执行文件，防上传木马。', 'at8-security' ) ); ?>
					<?php $this->check( 'rest_lockdown', __( 'REST API 锁定', 'at8-security' ), __( '未登录禁止访问非核心 REST 端点（保留文章/媒体/评论等公开接口）；可能误伤合法 API/前端，谨慎开启。', 'at8-security' ) ); ?>
					<?php $this->check( 'idle_disable', __( '闲置账户禁用', 'at8-security' ), __( '超过阈值天数未登录的非管理员账户将被禁用（移除角色）。', 'at8-security' ) ); ?>
					<?php $this->number( 'idle_days', __( '闲置天数阈值', 'at8-security' ), $s['idle_days'] ); ?>
				</div>

				<div class="at8-sec-panel" id="tab-audit" style="display:none">
					<h2><?php esc_html_e( '安全审计日志', 'at8-security' ); ?></h2>
					<p class="at8-sec-desc"><?php esc_html_e( '记录登录成功/失败与设置变更，保留天数到达后每日自动清理。', 'at8-security' ); ?></p>
					<?php $this->check( 'audit_log', __( '启用审计日志', 'at8-security' ), __( '写入数据库表 ss_audit_log。', 'at8-security' ) ); ?>
					<?php $this->number( 'audit_log_days', __( '日志保留天数', 'at8-security' ), $s['audit_log_days'] ); ?>
					<?php
					$al   = new SS_Audit_Log();
					$logs = method_exists( $al, 'recent' ) ? $al->recent( 50 ) : array();
					if ( empty( $logs ) ) {
						echo '<p class="at8-sec-note">' . esc_html__( '暂无记录（或数据表尚未创建，请先保存一次设置或重新激活插件）。', 'at8-security' ) . '</p>';
					} else {
						echo '<table class="at8-sec-log"><thead><tr>'
							. '<th>' . esc_html__( '时间', 'at8-security' ) . '</th>'
							. '<th>' . esc_html__( '事件', 'at8-security' ) . '</th>'
							. '<th>' . esc_html__( '用户', 'at8-security' ) . '</th>'
							. '<th>IP</th><th>' . esc_html__( '详情', 'at8-security' ) . '</th></tr></thead><tbody>';
						foreach ( $logs as $lg ) {
							printf(
								'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
								esc_html( $lg->ts ), esc_html( $lg->event ), esc_html( $lg->user_login ),
								esc_html( $lg->ip ), esc_html( $lg->detail )
							);
						}
						echo '</tbody></table>';
					}
					?>
					<p>
							<?php wp_nonce_field( 'ss_settings-options' ); ?>
							<button type="submit" name="ss_run_tool" value="audit_clear" class="button"><?php esc_html_e( '清空日志', 'at8-security' ); ?></button>
					</p>
				</div>

				<div class="at8-sec-panel" id="tab-backup" style="display:none">
					<h2><?php esc_html_e( '数据库手动备份', 'at8-security' ); ?></h2>
					<div class="at8-sec-danger">
						<strong><?php esc_html_e( '说明', 'at8-security' ); ?></strong>
						<p><?php esc_html_e( '点击按钮将生成当前整个数据库的 SQL 备份并下载到本地。本工具为手动操作、不自动调度（避免磁盘写满或权限问题）；建议配合主机快照 / 自动备份策略长期使用。', 'at8-security' ); ?></p>
					</div>
					<p>
							<?php wp_nonce_field( 'ss_settings-options' ); ?>
							<button type="submit" name="ss_run_tool" value="db_backup" class="button button-primary"><?php esc_html_e( '生成并下载数据库备份 (.sql)', 'at8-security' ); ?></button>
					</p>
				</div>

				<div class="at8-sec-panel" id="tab-tips" style="display:none">
					<h2><?php esc_html_e( '使用提示', 'at8-security' ); ?></h2>
					<ul class="at8-sec-tips">
						<li><?php esc_html_e( '高风险功能默认关闭，请按需开启并先在小流量验证。', 'at8-security' ); ?></li>
						<li><?php esc_html_e( '线上为 Nginx 时，"禁止目录浏览""uploads 禁 PHP"需手动在服务器配置加规则（.htaccess 仅 Apache 生效）。', 'at8-security' ); ?></li>
						<li><?php esc_html_e( '自定义登录地址紧急恢复：wp-config.php 加 define(\'SS_DISABLE_LOGIN_URL\', true); 可立即恢复默认 wp-login.php 访问。', 'at8-security' ); ?></li>
						<li><?php esc_html_e( 'HSTS 与 REST 锁定为高风险项，确认环境稳定后再开启，避免误伤。', 'at8-security' ); ?></li>
						<li><?php esc_html_e( '审计日志与数据库备份可分别在"审计日志""数据备份"标签页使用。', 'at8-security' ); ?></li>
						<li><?php esc_html_e( '本插件与「At8-加速优化」零代码耦合，可独立开关。', 'at8-security' ); ?></li>
					</ul>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<script>
			(function(){
				var tabs = document.querySelectorAll('.at8-sec-tabs .nav-tab');
				tabs.forEach(function(t){
					t.addEventListener('click', function(e){
						e.preventDefault();
						tabs.forEach(function(x){ x.classList.remove('nav-tab-active'); });
						t.classList.add('nav-tab-active');
						document.querySelectorAll('.at8-sec-panel').forEach(function(p){ p.style.display = 'none'; });
						var el = document.getElementById(t.getAttribute('data-tab') ? 'tab-' + t.getAttribute('data-tab') : 'tab-hardening');
						if (el) el.style.display = 'block';
					});
				});
			})();
		</script>
		<?php
	}

	private function check( $key, $label, $desc ) {
		$v = ss_setting( $key );
		?>
		<label class="at8-sec-row">
			<input type="checkbox" name="<?php echo esc_attr( SS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $v, 1 ); ?>>
			<strong><?php echo esc_html( $label ); ?></strong>
			<?php if ( $desc ) : ?><span class="at8-sec-desc"><?php echo esc_html( $desc ); ?></span><?php endif; ?>
		</label>
		<?php
	}

	private function number( $key, $label, $value ) {
		?>
		<label class="at8-sec-row">
			<strong><?php echo esc_html( $label ); ?></strong>
			<input type="number" name="<?php echo esc_attr( SS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="small-text">
		</label>
		<?php
	}

	private function text( $key, $label, $value ) {
		?>
		<label class="at8-sec-row">
			<strong><?php echo esc_html( $label ); ?></strong>
			<input type="text" name="<?php echo esc_attr( SS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text">
		</label>
		<?php
	}

	private function textarea( $key, $label, $value ) {
		?>
		<label class="at8-sec-row at8-sec-row-block">
			<strong><?php echo esc_html( $label ); ?></strong>
			<textarea name="<?php echo esc_attr( SS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" rows="3" class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
		</label>
		<?php
	}

	private function select( $key, $label, $options, $value ) {
		?>
		<label class="at8-sec-row">
			<strong><?php echo esc_html( $label ); ?></strong>
			<select name="<?php echo esc_attr( SS_OPTION ); ?>[<?php echo esc_attr( $key ); ?>]">
				<?php foreach ( $options as $ov => $ol ) : ?>
					<option value="<?php echo esc_attr( $ov ); ?>" <?php selected( $value, $ov ); ?>><?php echo esc_html( $ol ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
	}
}

/**
 * Sanitize：以默认值合并，确保 option 始终含全部键。
 */
function ss_sanitize( $input ) {
	$defaults = ss_defaults();
	$out      = $defaults;
	if ( ! is_array( $input ) ) {
		return $out;
	}
	foreach ( $defaults as $k => $dv ) {
		// 修复(1.0.4)：取消勾选复选框时 $_POST 无该键，必须显式归 0；旧版 continue 会导致对号不回退
		if ( ! isset( $input[ $k ] ) ) {
			$out[ $k ] = 0;
			continue;
		}
		$val = $input[ $k ];
		if ( is_bool( $dv ) || 0 === $dv || 1 === $dv ) {
			$out[ $k ] = empty( $val ) ? 0 : 1;
		} elseif ( is_int( $dv ) ) {
			$out[ $k ] = (int) $val;
		} else {
			$out[ $k ] = sanitize_textarea_field( $val );
		}
	}
	// 特定字段特殊处理
	if ( isset( $input['login_alert_email'] ) ) {
		$out['login_alert_email'] = sanitize_email( $input['login_alert_email'] );
	}
		if ( isset( $input['content_alias'] ) ) {
			$alias = strtolower( trim( sanitize_text_field( $input['content_alias'] ) ) );
			$alias = preg_replace( '/[^a-z0-9_-]/', '', $alias );
			$out['content_alias'] = $alias ? $alias : 'assets';
		}
		if ( isset( $input['turnstile_site_key'] ) ) {
			$out['turnstile_site_key'] = sanitize_text_field( $input['turnstile_site_key'] );
		}
		if ( isset( $input['turnstile_secret_key'] ) ) {
			$out['turnstile_secret_key'] = sanitize_text_field( $input['turnstile_secret_key'] );
		}
		if ( isset( $input['captcha_type'] ) ) {
			$out['captcha_type'] = ( 'turnstile' === $input['captcha_type'] ) ? 'turnstile' : 'math';
		}

		// 自定义登录地址：校验 slug 合法性，避免与保留词/已有页面冲突导致无法访问或被覆盖（修复 P4）
		if ( ! empty( $out['change_login_url'] ) ) {
			$slug     = strtolower( trim( (string) ( $out['login_slug'] ?? '' ) ) );
			$reserved = array( 'wp-login', 'wp-admin', 'admin', 'login', 'wp-content', 'wp-includes', 'xmlrpc', 'wp-login-php' );
			if ( ! $slug || in_array( $slug, $reserved, true ) ) {
				$out['change_login_url'] = 0;
				set_transient( 'ss_admin_notice', esc_html__( '自定义登录地址未生效：slug 为空或与保留词/系统路径冲突，请更换后重试。', 'at8-security' ), 60 );
			} else {
				$conflict = get_posts( array(
					'name'        => $slug,
					'post_type'   => array( 'page', 'post' ),
					'post_status' => 'publish',
					'fields'      => 'ids',
					'numberposts' => 1,
				) );
				if ( ! empty( $conflict ) ) {
					$out['change_login_url'] = 0;
					set_transient( 'ss_admin_notice', sprintf( esc_html__( '自定义登录地址未生效：%s 与已有页面/文章别名冲突，请更换后重试。', 'at8-security' ), $slug ), 60 );
				}
			}
		}

		return $out;
}

/* ============================================================
 * 引导
 * ============================================================ */
class Site_Security {

	public static function run() {
		new SS_Hardening();
		new SS_Login_Protection();
		new SS_WAF();
		new SS_Integrity();
		new SS_Password_Policy();
		new SS_Captcha();
		new SS_CSP();
		new SS_2FA();
		new SS_Content_Obfuscator();
		new SS_Comment_Spam();
		new SS_Disable_Comments();
		new SS_Https();
		new SS_Audit_Log();
		new SS_Upload_Restrict();
		new SS_Rest_Lockdown();
		new SS_Idle_Disable();
		if ( is_admin() ) {
			new SS_Admin();
		}
	}
}

register_activation_hook( __FILE__, array( 'SS_Audit_Log', 'install' ) );

add_action( 'plugins_loaded', array( 'Site_Security', 'run' ) );
