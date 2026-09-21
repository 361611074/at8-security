<?php
/**
 * Plugin bootstrap: loads the modules and handles activation/deactivation.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main controller.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Registered modules, keyed by short name.
	 *
	 * @var array<string, object>
	 */
	private $modules = array();

	/**
	 * Get (and lazily create) the singleton.
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
	 * Wire everything up.
	 */
	private function __construct() {
		/*
		 * Publish the instance before the modules are built. A module that
		 * needs a sibling — the settings screen reads the audit log and the
		 * hardening module — would otherwise build a second Plugin object and
		 * register every hook a second time.
		 */
		self::$instance = $this;

		$this->register_modules();

		if ( is_admin() ) {
			$this->modules['admin'] = new Admin();
		}

		/**
		 * Fires once At8 Security has loaded, after the free modules are registered.
		 *
		 * Add-ons (such as the separately distributed Pro plugin) use this to
		 * attach their own hooks. Nothing here depends on an add-on being
		 * present, which is what keeps the free download complete on its own.
		 */
		do_action( 'at8sec_loaded', $this );
	}

	/**
	 * Instantiate the free feature modules.
	 *
	 * @return void
	 */
	private function register_modules() {
		$this->modules['hardening']        = new Hardening();
		$this->modules['headers']          = new Headers();
		$this->modules['https']            = new Https();
		$this->modules['login_protection'] = new Login_Protection();
		$this->modules['captcha']          = new Captcha();
		$this->modules['password_policy']  = new Password_Policy();
		$this->modules['waf']              = new WAF();
		$this->modules['audit_log']        = new Audit_Log();
		$this->modules['comment_guard']    = new Comment_Guard();
		$this->modules['upload_guard']     = new Upload_Guard();
		$this->modules['rest_lockdown']    = new Rest_Lockdown();
	}

	/**
	 * Access a registered module.
	 *
	 * @param string $name Module name.
	 * @return object|null
	 */
	public function module( $name ) {
		return isset( $this->modules[ $name ] ) ? $this->modules[ $name ] : null;
	}

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( AT8SEC_OPTION, null );

		if ( ! is_array( $existing ) ) {
			// First install: start from the documented defaults.
			add_option( AT8SEC_OPTION, Settings::defaults() );
		} else {
			// Upgrading from an earlier version: keep the user's choices and
			// only fill in keys the new version introduced.
			update_option( AT8SEC_OPTION, wp_parse_args( $existing, Settings::defaults() ) );
		}

		Audit_Log::install();

		update_option( 'at8sec_version', AT8SEC_VERSION );

		// Make sure the very first request after activation flushes rewrite
		// rules, in case the custom login path is enabled straight away.
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation routine.
	 *
	 * Scheduled events are removed here rather than in uninstall.php, because
	 * a deactivated plugin must not keep waking the site up.
	 *
	 * @return void
	 */
	public static function deactivate() {
		foreach ( array( 'at8sec_audit_purge' ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}

		delete_transient( 'at8sec_notice_' . get_current_user_id() );
	}
}
