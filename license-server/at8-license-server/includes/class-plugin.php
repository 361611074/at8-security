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
	 * Cron hook that trims the audit log.
	 */
	const CRON_PURGE = 'at8ls_audit_purge';

	/**
	 * Register everything.
	 */
	private function __construct() {
		add_action( 'init', array( __CLASS__, 'maybe_upgrade' ), 1 );
		add_action( self::CRON_PURGE, array( __NAMESPACE__ . '\\Audit', 'purge_expired' ) );

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

		if ( ! wp_next_scheduled( self::CRON_PURGE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE );
		}
	}

	/**
	 * Called when the plugin is deactivated.
	 *
	 * Keys are deliberately kept: deactivating a plugin is not the same as
	 * deciding to stop honouring licences that were already sold. The audit
	 * log is kept for the same reason — it is a commercial record, and it
	 * outlives both the plugin being switched off and the licence it describes.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$scheduled = wp_next_scheduled( self::CRON_PURGE );

		if ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_PURGE );
		}
	}
}
