<?php
/**
 * Login CAPTCHA.
 *
 * The previous implementation kept the expected answer in a transient keyed by
 * "IP + answer", then looked the answer up by looping over every possible sum.
 * Two things were wrong with that: the lookup deleted the transient before
 * reading it, so the real comparison could never succeed, and the fallback
 * comparison effectively accepted any number that had ever been issued to that
 * address — which is most of them after a couple of page loads.
 *
 * This version issues a random single-use token per form render. The expected
 * answer is stored server-side under the token, the token is consumed on the
 * first check, and it is never derived from anything the visitor controls.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 5 — CAPTCHA.
 */
class Captcha {

	const TOKEN_FIELD  = 'at8sec_captcha_token';
	const ANSWER_FIELD = 'at8sec_captcha_answer';

	/**
	 * Register hooks.
	 *
	 * The hooks go on unconditionally and each one asks `in_use()` before doing
	 * anything. Deciding here instead would be a mistake, and a subtle one:
	 * this constructor runs on `plugins_loaded` at priority 5, while an add-on
	 * attaches its method filter at priority 6 — so a decision made now would
	 * always see the built-in method and conclude there is nothing to do. The
	 * add-on's challenge would then be switched on and never appear. Asking
	 * later costs one comparison per request and cannot be wrong.
	 */
	public function __construct() {
		add_action( 'login_form', array( $this, 'render' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'maybe_enqueue_style' ) );
		add_filter( 'authenticate', array( $this, 'verify' ), 28, 3 );
	}

	/**
	 * Is a challenge of any kind in play on the sign-in form?
	 *
	 * Two things can put one there: the built-in arithmetic question, or a
	 * method supplied by an add-on through the filter below. The distinction
	 * matters because this class owns the plumbing — the place in the form, the
	 * stylesheet, the hook on `authenticate` — and none of it happens if the
	 * arithmetic question is switched off while an add-on is quietly waiting to
	 * draw its own challenge.
	 *
	 * @return bool
	 */
	public static function in_use() {
		return Settings::on( 'login_captcha' ) || 'math' !== self::method();
	}

	/**
	 * Load the challenge stylesheet, but only when a challenge will be drawn.
	 *
	 * @return void
	 */
	public function maybe_enqueue_style() {
		if ( self::in_use() ) {
			enqueue_front_style();
		}
	}

	/**
	 * Which challenge should be shown?
	 *
	 * The free plugin ships the built-in arithmetic challenge. The Pro add-on
	 * swaps it for a hosted widget by filtering this value; when it is absent
	 * nothing is missing, the local challenge simply stays in place.
	 *
	 * @return string
	 */
	public static function method() {
		$method = apply_filters( 'at8sec_captcha_method', 'math' );

		return is_string( $method ) && '' !== $method ? $method : 'math';
	}

	/**
	 * Render the challenge inside the login form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! self::in_use() ) {
			return;
		}

		/**
		 * Fires just before the free challenge is rendered.
		 *
		 * The Pro add-on renders its own widget here and suppresses the local
		 * one by returning a non-empty string from the companion filter.
		 */
		do_action( 'at8sec_captcha_render' );

		if ( '' !== (string) apply_filters( 'at8sec_captcha_rendered', '' ) ) {
			return;
		}

		$a = wp_rand( 2, 9 );
		$b = wp_rand( 2, 9 );

		$token = self::issue( $a + $b );

		printf(
			'<p class="at8sec-captcha"><label for="%1$s">%2$s<br /><input type="text" name="%3$s" id="%1$s" class="input" inputmode="numeric" autocomplete="off" required /></label> <input type="hidden" name="%4$s" value="%5$s" /></p>',
			esc_attr( self::ANSWER_FIELD ),
			esc_html(
				sprintf(
					/* translators: 1: first number, 2: second number. */
					__( 'Human check: what is %1$d + %2$d?', 'at8-security' ),
					$a,
					$b
				)
			),
			esc_attr( self::ANSWER_FIELD ),
			esc_attr( self::TOKEN_FIELD ),
			esc_attr( $token )
		);
	}

	/**
	 * Create a single-use challenge and store its answer.
	 *
	 * @param int $answer Expected answer.
	 * @return string Token to embed in the form.
	 */
	public static function issue( $answer ) {
		$token = wp_generate_password( 32, false, false );
		$key   = self::key( $token );

		set_transient( $key, (int) $answer, 15 * MINUTE_IN_SECONDS );

		return $token;
	}

	/**
	 * Transient key for a token.
	 *
	 * @param string $token Token value.
	 * @return string
	 */
	private static function key( $token ) {
		return 'at8sec_cap_' . hash( 'sha256', (string) $token );
	}

	/**
	 * Validate the challenge during login.
	 *
	 * Only requests that actually posted the login form are checked. The
	 * WordPress login form always sends `log`; application passwords, the REST
	 * API and WP-CLI do not, and those flows must keep working — the previous
	 * version failed them all.
	 *
	 * @param \WP_User|\WP_Error|null $user     Current authentication result.
	 * @param string                  $username Submitted username.
	 * @param string                  $password Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function verify( $user, $username = '', $password = '' ) {
		unset( $username, $password );

		if ( ! self::in_use() ) {
			return $user;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reading a challenge, not changing state.
		if ( ! isset( $_POST['log'] ) ) {
			return $user;
		}

		$method = self::method();

		if ( 'math' !== $method ) {
			/**
			 * Let an add-on perform its own verification.
			 *
			 * @param \WP_User|\WP_Error|null $user   Authentication result so far.
			 * @param string                  $method Selected challenge method.
			 */
			return apply_filters( 'at8sec_captcha_verify', $user, $method );
		}

		return $this->verify_math( $user );
	}

	/**
	 * Check the built-in arithmetic challenge.
	 *
	 * @param \WP_User|\WP_Error|null $user Current authentication result.
	 * @return \WP_User|\WP_Error|null
	 */
	private function verify_math( $user ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- validated below against a server-side value.
		$token  = isset( $_POST[ self::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ) : '';
		$answer = isset( $_POST[ self::ANSWER_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::ANSWER_FIELD ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $token ) {
			return new \WP_Error( 'at8sec_captcha', __( 'The human check has expired. Please reload the page and try again.', 'at8-security' ) );
		}

		$key     = self::key( $token );
		$expected = get_transient( $key );

		// Single use: the challenge cannot be replayed, whether it passed or not.
		delete_transient( $key );

		if ( false === $expected ) {
			return new \WP_Error( 'at8sec_captcha', __( 'The human check has expired. Please reload the page and try again.', 'at8-security' ) );
		}

		if ( ! is_numeric( $answer ) || ! hash_equals( (string) (int) $expected, (string) (int) $answer ) ) {
			return new \WP_Error( 'at8sec_captcha', __( 'That answer was not correct. Please try again.', 'at8-security' ) );
		}

		return $user;
	}
}
