<?php
/**
 * Plugin Name:       At8 Security
 * Description:       Lightweight hardening for WordPress: hide version info, disable XML-RPC and user enumeration, send security headers, throttle login attempts, block comment spam, apply a conservative firewall and keep an audit log.
 * Version:           2.0.3
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * Text Domain:       at8-security
 * Domain Path:       /languages
 *
 * @package At8\Security
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AT8SEC_VERSION', '2.0.3' );
define( 'AT8SEC_FILE', __FILE__ );
define( 'AT8SEC_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT8SEC_URL', plugin_dir_url( __FILE__ ) );
define( 'AT8SEC_BASENAME', plugin_basename( __FILE__ ) );
define( 'AT8SEC_OPTION', 'at8_security_settings' );
define( 'AT8SEC_MIN_PHP', '7.4' );

/*
 * This plugin needs PHP 7.4 (null coalescing in signatures, typed constants).
 * On older versions we bail out before touching anything, so the site keeps
 * working instead of dying with a parse error.
 */
if ( version_compare( PHP_VERSION, AT8SEC_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: minimum required PHP version, e.g. 7.4. */
						__( 'At8 Security requires PHP %s or newer. The plugin has been disabled to keep your site online.', 'at8-security' ),
						AT8SEC_MIN_PHP
					)
				)
			);
		}
	);

	return;
}

require_once AT8SEC_DIR . 'includes/helpers.php';
require_once AT8SEC_DIR . 'includes/class-settings.php';
require_once AT8SEC_DIR . 'includes/class-hardening.php';
require_once AT8SEC_DIR . 'includes/class-headers.php';
require_once AT8SEC_DIR . 'includes/class-https.php';
require_once AT8SEC_DIR . 'includes/class-login-protection.php';
require_once AT8SEC_DIR . 'includes/class-captcha.php';
require_once AT8SEC_DIR . 'includes/class-password-policy.php';
require_once AT8SEC_DIR . 'includes/class-waf.php';
require_once AT8SEC_DIR . 'includes/class-audit-log.php';
require_once AT8SEC_DIR . 'includes/class-comment-guard.php';
require_once AT8SEC_DIR . 'includes/class-upload-guard.php';
require_once AT8SEC_DIR . 'includes/class-rest-lockdown.php';
require_once AT8SEC_DIR . 'includes/class-admin.php';
require_once AT8SEC_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'At8\\Security\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'At8\\Security\\Plugin', 'deactivate' ) );

/*
 * Load the translations bundled in /languages.
 *
 * Plugin Check discourages this function, and the warning is accepted here on
 * purpose rather than suppressed. The reasoning is worth writing down, because
 * "delete the call" is the obvious-looking fix and it is the wrong one.
 *
 * WordPress searches exactly three places for a text domain's MO file:
 * wp-content/languages/plugins, wp-content/languages/themes, and whatever path
 * the domain registered for itself. WP_Textdomain_Registry::get_paths_for_domain()
 * adds the plugin's own /languages directory *only* because this call puts it
 * in custom_paths. Verified against the running core on WordPress 7.1.2.
 *
 * So removing the call does not just silence a warning — it reverts this
 * plugin's interface to English on every site that has no zh_CN language pack
 * installed, which is every site on the day the plugin is published. The
 * bundled translation exists to cover exactly that gap, and it was added
 * because users reported the English interface.
 *
 * The warning is advisory; WordPress.org does not reject for it. See
 * docs/wordpress-org-compliance-report.md for the disposition.
 *
 * Hooked to init: since WordPress 6.7, translation loading triggered before
 * after_setup_theme raises a doing_it_wrong() notice.
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'at8-security', false, dirname( AT8SEC_BASENAME ) . '/languages' );
	}
);

/*
 * Priority 5: the firewall and the login guard need to be registered before
 * most third-party code hooks into init, so a blocked request never reaches
 * another plugin's bootstrapping.
 */
add_action( 'plugins_loaded', array( 'At8\\Security\\Plugin', 'instance' ), 5 );
