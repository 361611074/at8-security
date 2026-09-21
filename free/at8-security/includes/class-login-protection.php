<?php
/**
 * Login protection: throttling, honeypot, custom login path, new-IP alerts.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 4 — login protection.
 */
class Login_Protection {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( Settings::on( 'login_throttle' ) ) {
			add_action( 'wp_login_failed', array( $this, 'register_failure' ) );
			add_action( 'wp_login', array( $this, 'clear_failures' ), 10, 2 );
			add_filter( 'authenticate', array( $this, 'refuse_when_locked' ), 30, 3 );
		}

		if ( Settings::on( 'honeypot' ) ) {
			add_action( 'login_enqueue_scripts', __NAMESPACE__ . '\\enqueue_front_style' );
			add_action( 'login_form', array( $this, 'render_honeypot' ) );
			add_filter( 'authenticate', array( $this, 'reject_honeypot' ), 25, 3 );
		}

		if ( Settings::on( 'login_email_alert' ) ) {
			add_action( 'wp_login', array( $this, 'alert_new_ip' ), 20, 2 );
		}

		$slug = self::login_slug();

		if ( '' !== $slug && ! defined( 'AT8SEC_DISABLE_CUSTOM_LOGIN' ) ) {
			add_filter( 'site_url', array( $this, 'rewrite_login_url' ), 10, 4 );
			add_filter( 'network_site_url', array( $this, 'rewrite_login_url' ), 10, 3 );
			add_filter( 'wp_redirect', array( $this, 'rewrite_login_redirect' ), 10, 2 );
			add_action( 'init', array( $this, 'serve_custom_login' ), 1 );
			add_action( 'init', array( $this, 'hide_default_login' ), 2 );
		}
	}

	/**
	 * Configured custom login path, or an empty string when disabled.
	 *
	 * @return string
	 */
	public static function login_slug() {
		if ( ! Settings::on( 'change_login_url' ) ) {
			return '';
		}

		return trim( (string) Settings::get( 'login_slug', '' ), '/' );
	}

	/**
	 * Transient key used for the per-address failure counter.
	 *
	 * @return string
	 */
	private static function lock_key() {
		return 'at8sec_lock_' . ip_hash( get_client_ip() );
	}

	/**
	 * Count a failed attempt and start a lockout when the limit is reached.
	 *
	 * @return void
	 */
	public function register_failure() {
		$limit     = max( 1, (int) Settings::get( 'login_max_retries', 5 ) );
		$minutes   = max( 1, (int) Settings::get( 'login_lockout_minutes', 15 ) );
		$lock_for  = $minutes * MINUTE_IN_SECONDS;
		$key       = self::lock_key();
		$data      = get_transient( $key );

		if ( ! is_array( $data ) ) {
			$data = array(
				'count' => 0,
				'until' => 0,
			);
		}

		// Already locked: leave the existing window untouched so repeated
		// attempts cannot extend a lockout indefinitely.
		if ( (int) $data['until'] > time() ) {
			return;
		}

		$data['count'] = (int) $data['count'] + 1;

		if ( $data['count'] >= $limit ) {
			$data['until'] = time() + $lock_for;
			$data['count'] = 0;

			/**
			 * Fires when an address is locked out after repeated failures.
			 *
			 * @param string $ip   Visitor address.
			 * @param int    $mins Lockout length in minutes.
			 */
			do_action( 'at8sec_login_locked', get_client_ip(), $minutes );
		}

		set_transient( $key, $data, $lock_for + 600 );
	}

	/**
	 * Clear the counter after a successful login.
	 *
	 * @param string         $user_login Username.
	 * @param \WP_User|mixed $user       User object.
	 * @return void
	 */
	public function clear_failures( $user_login, $user = null ) {
		unset( $user_login, $user );
		delete_transient( self::lock_key() );
	}

	/**
	 * Reject authentication while the address is locked out.
	 *
	 * @param \WP_User|\WP_Error|null $user     Current authentication result.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function refuse_when_locked( $user, $username = '', $password = '' ) {
		unset( $username, $password );

		$data = get_transient( self::lock_key() );

		if ( is_array( $data ) && ! empty( $data['until'] ) && (int) $data['until'] > time() ) {
			$minutes = (int) ceil( ( (int) $data['until'] - time() ) / MINUTE_IN_SECONDS );

			return new \WP_Error(
				'at8sec_locked',
				sprintf(
					/* translators: %d: number of minutes the visitor has to wait. */
					_n(
						'Too many failed login attempts. Please try again in %d minute.',
						'Too many failed login attempts. Please try again in %d minutes.',
						$minutes,
						'at8-security'
					),
					$minutes
				)
			);
		}

		return $user;
	}

	/**
	 * Render the honeypot field inside the login form.
	 *
	 * @return void
	 */
	public function render_honeypot() {
		printf(
			'<p class="at8sec-hp" aria-hidden="true"><label for="at8sec_hp_url">%1$s</label> <input type="text" name="at8sec_hp_url" id="at8sec_hp_url" value="" tabindex="-1" autocomplete="off" /></p>',
			esc_html__( 'Website', 'at8-security' )
		);
	}

	/**
	 * Fail authentication when the honeypot was filled in.
	 *
	 * @param \WP_User|\WP_Error|null $user Current authentication result.
	 * @return \WP_User|\WP_Error|null
	 */
	public function reject_honeypot( $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- honeypot check, no state change.
		$value = isset( $_POST['at8sec_hp_url'] ) ? sanitize_text_field( wp_unslash( $_POST['at8sec_hp_url'] ) ) : '';

		if ( '' !== $value ) {
			return new \WP_Error( 'at8sec_honeypot', __( 'Login failed.', 'at8-security' ) );
		}

		return $user;
	}

	/**
	 * Notify the administrator when an account is used from a new address.
	 *
	 * @param string         $user_login Username.
	 * @param \WP_User|mixed $user       User object.
	 * @return void
	 */
	public function alert_new_ip( $user_login, $user = null ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$fingerprint = ip_hash( get_client_ip() );

		if ( get_user_meta( $user->ID, 'at8sec_last_ip', true ) === $fingerprint ) {
			return;
		}

		update_user_meta( $user->ID, 'at8sec_last_ip', $fingerprint );

		$recipient = (string) Settings::get( 'login_alert_email', '' );
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			$recipient = get_option( 'admin_email' );
		}

		if ( ! is_email( $recipient ) ) {
			return;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$subject = sprintf(
			/* translators: %s: site host name. */
			__( '[%s] New sign-in from an unrecognised address', 'at8-security' ),
			$host
		);

		$body = sprintf(
			/* translators: 1: username, 2: date and time, 3: IP address. */
			__( "The account \"%1\$s\" signed in on %2\$s from a new IP address (%3\$s).\n\nIf this was not you, change the password immediately.", 'at8-security' ),
			$user_login,
			current_time( 'mysql' ),
			get_client_ip()
		);

		wp_mail( $recipient, $subject, $body );
	}

	/**
	 * Rewrite wp-login.php links to the custom path.
	 *
	 * @param string $url     Generated URL.
	 * @param string $path    Requested path.
	 * @param string $scheme  URL scheme.
	 * @param int    $blog_id Site ID (multisite).
	 * @return string
	 */
	public function rewrite_login_url( $url, $path = '', $scheme = null, $blog_id = null ) {
		unset( $path, $scheme, $blog_id );

		$slug = self::login_slug();
		if ( '' === $slug || ! is_string( $url ) ) {
			return $url;
		}

		return str_replace( 'wp-login.php', $slug, $url );
	}

	/**
	 * Keep redirects pointed at the custom path as well.
	 *
	 * @param string $location Redirect target.
	 * @param int    $status   HTTP status.
	 * @return string
	 */
	public function rewrite_login_redirect( $location, $status = 302 ) {
		unset( $status );

		$slug = self::login_slug();
		if ( '' === $slug || ! is_string( $location ) ) {
			return $location;
		}

		return str_replace( 'wp-login.php', $slug, $location );
	}

	/**
	 * The request path, relative to the WordPress home path.
	 *
	 * Handles installs that live in a subdirectory, which the previous
	 * implementation did not (the custom login path silently never matched).
	 *
	 * @return string
	 */
	private function relative_request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$request = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base    = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$base    = trim( $base, '/' );

		if ( '' !== $base && 0 === strpos( ltrim( $request, '/' ), $base ) ) {
			$request = substr( ltrim( $request, '/' ), strlen( $base ) );
		}

		return trim( (string) $request, '/' );
	}

	/**
	 * Serve wp-login.php when the custom path is requested.
	 *
	 * @return void
	 */
	public function serve_custom_login() {
		$slug = self::login_slug();

		if ( '' === $slug || strtolower( $slug ) !== $this->normalized_request_path() ) {
			return;
		}

		/*
		 * wp-login.php expects these to exist in the global scope. Defining
		 * them up front is what keeps a hand-rolled entry point quiet when
		 * display_errors happens to be on — no error_reporting juggling.
		 */
		$error      = '';
		$user_login = '';

		require ABSPATH . 'wp-login.php';
		exit;
	}

	/**
	 * The request path normalised for comparison with the configured slug.
	 *
	 * Visitors rarely retype the stored form exactly: the browser keeps the
	 * casing they typed and non-ASCII paths arrive percent-encoded. Saving
	 * normalises the slug (lowercase, stripped), so the comparison must use
	 * the same normalisation — otherwise a valid entry point answers 404.
	 *
	 * @return string
	 */
	private function normalized_request_path() {
		$path = rawurldecode( $this->relative_request_path() );

		return strtolower( untrailingslashit( $path ) );
	}

	/**
	 * Make the default login entry point look like it does not exist.
	 *
	 * No redirect to the custom path is issued on purpose: sending visitors
	 * there would hand the secret path to anything that probes /wp-admin.
	 * A plain 404 leaks nothing at all.
	 *
	 * @return void
	 */
	public function hide_default_login() {
		if ( is_user_logged_in() ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( false !== strpos( $uri, 'wp-login.php' ) ) {
			$this->not_found();
		}

		$path = $this->relative_request_path();

		if ( 'wp-admin' === $path || 'wp-admin/' === rtrim( $path, '/' ) ) {
			$this->not_found();
		}
	}

	/**
	 * Emit a neutral 404 response.
	 *
	 * @return void
	 */
	private function not_found() {
		nocache_headers();
		status_header( 404 );
		wp_die( '', '', array( 'response' => 404 ) );
	}
}
