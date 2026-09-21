<?php
/**
 * Plugin Name:       At8 License Server
 * Plugin URI:        https://www.at8.fun/
 * Description:       Issues and validates licence keys for the add-ons sold from this site, and serves their update packages.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Author:            漫步白月光
 * Author URI:        https://www.at8.fun/
 * Text Domain:       at8-license-server
 * Domain Path:       /languages
 *
 * This plugin is the other half of the add-ons distributed from this site: it
 * hands out licence keys, answers the activate/validate/deactivate calls they
 * make, and streams update packages to sites that hold a valid key. It is not
 * distributed anywhere else and it is not meant to run on a customer's site.
 *
 * @package At8\License
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AT8LS_VERSION', '1.0.1' );
define( 'AT8LS_FILE', __FILE__ );
define( 'AT8LS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AT8LS_URL', plugin_dir_url( __FILE__ ) );
define( 'AT8LS_BASENAME', plugin_basename( __FILE__ ) );
define( 'AT8LS_MIN_PHP', '7.4' );
define( 'AT8LS_REST_NAMESPACE', 'at8-license/v1' );

if ( version_compare( PHP_VERSION, AT8LS_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: minimum required PHP version, e.g. 7.4. */
						__( 'At8 License Server requires PHP %s or newer. The plugin has been disabled to keep the site online.', 'at8-license-server' ),
						AT8LS_MIN_PHP
					)
				)
			);
		}
	);

	return;
}

require_once AT8LS_DIR . 'includes/helpers.php';
require_once AT8LS_DIR . 'includes/class-db.php';
require_once AT8LS_DIR . 'includes/class-keys.php';
require_once AT8LS_DIR . 'includes/class-package.php';
require_once AT8LS_DIR . 'includes/class-rest.php';
require_once AT8LS_DIR . 'includes/class-admin.php';
require_once AT8LS_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'At8\\License\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'At8\\License\\Plugin', 'deactivate' ) );

/*
 * Load translations bundled in /languages (and any language pack installed
 * under wp-content/languages/plugins/). Hooked to init, as recommended since
 * WordPress 6.7 — loading earlier triggers a doing_it_wrong notice.
 */
add_action(
	'init',
	static function () {
		load_plugin_textdomain( 'at8-license-server', false, dirname( AT8LS_BASENAME ) . '/languages' );
	}
);

add_action( 'plugins_loaded', array( 'At8\\License\\Plugin', 'instance' ), 10 );
