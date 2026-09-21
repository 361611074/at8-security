<?php
/**
 * Add-on bootstrap: dependency check, module registration, activation.
 *
 * The shape of this plugin is dictated by one rule: the free download has to
 * be complete on its own, so nothing here may be load-bearing for it. This
 * file therefore tries hard to be invisible when things are not in place —
 * without the free plugin it says so once in the admin and registers nothing;
 * without a valid licence it registers the non-paid parts and leaves the paid
 * features unhooked, which returns the site to exactly how the free plugin
 * behaves by itself.
 *
 * The paid modules are gated on the licence, not the paid *code*: the code is
 * all present, because this plugin is not distributed through the WordPress.org
 * directory. What the licence decides is whether these features run.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

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
		self::$instance = $this;

		if ( ! free_plugin_loaded() ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_free' ) );

			return;
		}

		/*
		 * Tell the free plugin its add-on is here and licensed. This is the
		 * single point of contact between the two: the free plugin asks, and
		 * nothing else in it knows this plugin exists.
		 */
		add_filter( 'at8sec_is_pro', array( $this, 'flag_pro' ) );

		$this->register_modules();

		/**
		 * Fires once the Pro add-on has loaded and registered its modules.
		 *
		 * @param Plugin $plugin The add-on controller.
		 */
		do_action( 'at8sec_pro_loaded', $this );
	}

	/**
	 * Answer the free plugin's "is the add-on active?" question.
	 *
	 * Returns the licence state rather than a plain true: a key that has been
	 * rejected and whose grace has run out stops the paid features, and the
	 * free plugin goes back to showing its own upgrade information.
	 *
	 * @param bool $value Value from earlier filters.
	 * @return bool
	 */
	public function flag_pro( $value ) {
		return (bool) $value || License::is_valid();
	}

	/**
	 * Instantiate the modules.
	 *
	 * @return void
	 */
	private function register_modules() {
		// Always: the licence has to be readable and enterable in order for
		// anything else to be switched on.
		$this->modules['license'] = new License();

		if ( is_admin() ) {
			$this->modules['admin']   = new Admin();
			$this->modules['updater'] = new Updater();
		}

		if ( ! License::is_valid() ) {
			return;
		}

		$this->modules['turnstile']  = new Turnstile();
		$this->modules['two_factor'] = new Two_Factor();
		$this->modules['cdn']        = new Cdn();
		$this->modules['accounts']   = new Accounts();
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
	 * Say, once, that the free plugin is missing.
	 *
	 * @return void
	 */
	public function notice_missing_free() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$free = 'at8-security/at8-security.php';

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins   = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$installed = isset( $plugins[ $free ] );

		$url = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $free ) ),
			'activate-plugin_' . $free
		);
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'At8 Security Pro needs the free At8 Security plugin.', 'at8-security-pro' ); ?></strong>
				<?php if ( $installed ) : ?>
					<?php esc_html_e( 'It is installed but not active, so none of these features are running.', 'at8-security-pro' ); ?>
					<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Activate it', 'at8-security-pro' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'It is not installed, so none of these features are running.', 'at8-security-pro' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Activation routine.
	 *
	 * @return void
	 */
	public static function activate() {
		$existing = get_option( AT8SEC_PRO_OPTION, null );

		if ( ! is_array( $existing ) ) {
			add_option( AT8SEC_PRO_OPTION, Settings::defaults() );
		} else {
			// Keep the owner's choices and only fill in what is new.
			update_option( AT8SEC_PRO_OPTION, wp_parse_args( $existing, Settings::defaults() ) );
		}

		if ( free_plugin_loaded() ) {
			License::sync_schedule();
		}

		update_option( 'at8sec_pro_version', AT8SEC_PRO_VERSION );

		/*
		 * Nothing is switched on by activating this plugin. Every feature here
		 * can lock somebody out or change how a site answers requests, so each
		 * one is turned on deliberately, from its own setting.
		 */
		set_transient( 'at8sec_pro_activated_' . get_current_user_id(), 1, 60 );
	}

	/**
	 * Deactivation routine.
	 *
	 * Scheduled work is removed here rather than in uninstall.php: a
	 * deactivated plugin must not keep waking the site up.
	 *
	 * @return void
	 */
	public static function deactivate() {
		License::unschedule();
		Updater::clear_cache();

		delete_transient( 'at8sec_pro_activated_' . get_current_user_id() );
	}
}
