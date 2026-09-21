<?php
/**
 * Bootstrap.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the pieces together.
 */
class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Registered modules.
	 *
	 * @var array<string, object>
	 */
	private $modules = array();

	/**
	 * Instance accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register everything.
	 */
	private function __construct() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );

		$modules = array( 'rest', 'admin' );

		foreach ( $modules as $key ) {
			$class = __NAMESPACE__ . '\\' . ucfirst( $key );

			if ( class_exists( $class ) ) {
				$this->modules[ $key ] = new $class();
			}
		}
	}

	/**
	 * Create the tables if this install has never had them.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		Db::maybe_install();

		/*
		 * An install that already holds packages was activated before the
		 * directory was guarded, so it is guarded here the first time it is
		 * noticed to be missing. Two stat calls, and only until it is there.
		 */
		$dir = package_dir();

		if ( is_dir( $dir ) && ! file_exists( $dir . '.htaccess' ) ) {
			Package::harden( $dir );
		}
	}

	/**
	 * Called when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate() {
		Db::install();

		/*
		 * Guard the packages directory straight away rather than waiting for
		 * the first release to be uploaded: the directory is inside uploads,
		 * so until the deny file exists anything dropped there is served as a
		 * plain file to whoever asks for it.
		 */
		Package::harden();
	}

	/**
	 * Called when the plugin is deactivated.
	 *
	 * Keys are deliberately kept: deactivating a plugin is not the same as
	 * deciding to stop honouring licences that were already sold.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Nothing to clean up here; uninstall.php does that.
	}
}
