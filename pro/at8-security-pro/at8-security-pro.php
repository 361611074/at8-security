<?php
/**
 * Plugin Name:       At8 Security Pro
 * Plugin URI:        https://www.at8.fun/
 * Description:       Adds two-factor authentication, a hosted anti-bot challenge and the maintenance tools to At8 Security. Requires the free At8 Security plugin.
 * Version:           2.0.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * Update URI:        https://www.at8.fun/
 * Text Domain:       at8-security-pro
 * Domain Path:       /languages
 *
 * This add-on is distributed from the author's own site, not from the
 * WordPress.org plugin directory, and it needs the free At8 Security plugin to
 * be installed and active. Everything here is an addition; with this plugin
 * absent the free download keeps working exactly as it does on its own.
 *
 * @package At8\Security\Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AT8SEC_PRO_VERSION', '2.0.1' );
define( 'AT8SEC_PRO_FILE', __FILE__ );
define( 'AT8SEC_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT8SEC_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'AT8SEC_PRO_BASENAME', plugin_basename( __FILE__ ) );
define( 'AT8SEC_PRO_OPTION', 'at8_security_pro_settings' );
define( 'AT8SEC_PRO_LICENSE_OPTION', 'at8_security_pro_license' );
define( 'AT8SEC_PRO_MIN_PHP', '7.4' );

/*
 * How long the protections keep running after the licence server stops
 * confirming the key. A security feature that switches itself off because a
 * licence server is unreachable would leave the site exposed, so a failed
 * *check* is treated differently from a rejected key.
 */
define( 'AT8SEC_PRO_GRACE_DAYS', 14 );
define( 'AT8SEC_PRO_GRACE_DAYS_REJECTED', 3 );

if ( version_compare( PHP_VERSION, AT8SEC_PRO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: minimum required PHP version, e.g. 7.4. */
						__( 'At8 Security Pro requires PHP %s or newer. The add-on has been disabled to keep your site online.', 'at8-security-pro' ),
						AT8SEC_PRO_MIN_PHP
					)
				)
			);
		}
	);

	return;
}

require_once AT8SEC_PRO_DIR . 'includes/helpers.php';
require_once AT8SEC_PRO_DIR . 'includes/class-settings.php';
require_once AT8SEC_PRO_DIR . 'includes/class-api.php';
require_once AT8SEC_PRO_DIR . 'includes/class-license.php';
require_once AT8SEC_PRO_DIR . 'includes/class-updater.php';
require_once AT8SEC_PRO_DIR . 'includes/class-turnstile.php';
require_once AT8SEC_PRO_DIR . 'includes/class-cdn.php';
require_once AT8SEC_PRO_DIR . 'includes/class-two-factor.php';
require_once AT8SEC_PRO_DIR . 'includes/class-integrity.php';
require_once AT8SEC_PRO_DIR . 'includes/class-accounts.php';
require_once AT8SEC_PRO_DIR . 'includes/class-admin.php';
require_once AT8SEC_PRO_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'At8\\Security\\Pro\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'At8\\Security\\Pro\\Plugin', 'deactivate' ) );

/*
 * Priority 6: the free plugin boots at 5, so by the time this runs its
 * functions and filter names all exist and the dependency check is meaningful.
 */
add_action( 'plugins_loaded', array( 'At8\\Security\\Pro\\Plugin', 'instance' ), 6 );
