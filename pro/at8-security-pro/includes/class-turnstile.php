<?php
/**
 * Hosted anti-bot challenge, as an alternative to the arithmetic question.
 *
 * This is the one feature of the add-on that talks to a third party: the
 * widget is served by Cloudflare and the answer is verified by Cloudflare. It
 * is off by default for exactly that reason, and the free plugin's local
 * challenge stays in place until both keys are filled in.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare Turnstile integration.
 *
 * A note for anyone reading a plugin-check report: this file is where the two
 * "OffloadedContent" findings come from, and they are deliberate rather than
 * overlooked. Loading the widget and asking Cloudflare to verify the answer is
 * the entire feature — a hosted challenge with nothing hosted is not a
 * challenge. The guideline those findings point at applies to plugins
 * distributed through the WordPress.org directory, which this add-on is not;
 * and the feature stays off until an owner switches it on and supplies their
 * own keys, so a site that has not asked for it loads nothing from anywhere.
 */
class Turnstile {

	/** Served by Cloudflare. */
	const SCRIPT_HANDLE  = 'at8sec-pro-turnstile';
	const SCRIPT_URL     = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	const VERIFY_URL     = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	const RESPONSE_FIELD = 'cf-turnstile-response';

	/**
	 * Set once the widget has actually been printed for this request.
	 *
	 * @var bool
	 */
	private $rendered = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'at8sec_captcha_method', array( $this, 'method' ) );
		add_action( 'at8sec_captcha_render', array( $this, 'render' ) );
		add_filter( 'at8sec_captcha_rendered', array( $this, 'claim_render' ) );
		add_filter( 'at8sec_captcha_verify', array( $this, 'verify' ), 10, 2 );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Are both keys present?
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== trim( (string) Settings::get( 'turnstile_site_key' ) )
			&& '' !== trim( (string) Settings::get( 'turnstile_secret_key' ) );
	}

	/**
	 * Is the hosted challenge the one in use?
	 *
	 * @return bool
	 */
	public static function active() {
		return Settings::on( 'turnstile_enabled' ) && self::configured();
	}

	/**
	 * Choose the challenge.
	 *
	 * A half-configured Turnstile falls back to the built-in arithmetic
	 * question rather than removing the check altogether.
	 *
	 * @param string $method Method selected so far.
	 * @return string
	 */
	public function method( $method ) {
		return self::active() ? 'turnstile' : $method;
	}

	/**
	 * Load the widget script.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! self::active() ) {
			return;
		}

		/*
		 * The stylesheet for the wrapper is loaded here rather than left to
		 * another module. Both are part of this feature, and a widget that
		 * depends on an unrelated setting being on to be styled is a bug
		 * waiting for the day somebody switches that setting off.
		 */
		enqueue_front_style();

		wp_enqueue_script( self::SCRIPT_HANDLE, self::SCRIPT_URL, array(), AT8SEC_PRO_VERSION, true );
	}

	/**
	 * Print the widget inside the login form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! self::active() ) {
			return;
		}

		$this->rendered = true;

		printf(
			'<p class="at8sec-pro-challenge"><span class="cf-turnstile" data-sitekey="%s" data-theme="auto" data-size="flexible"></span></p>',
			esc_attr( (string) Settings::get( 'turnstile_site_key' ) )
		);
	}

	/**
	 * Tell the free plugin the widget has been printed, so it skips its own.
	 *
	 * @param string $claimed Value claimed so far.
	 * @return string
	 */
	public function claim_render( $claimed ) {
		return $this->rendered ? 'turnstile' : $claimed;
	}

	/**
	 * Check the answer with Cloudflare.
	 *
	 * Two deliberate decisions live here:
	 *
	 * 1. A failed *request* — Cloudflare unreachable, a timeout, a 5xx — lets
	 *    the sign-in through. An outage at Cloudflare must not lock every
	 *    administrator out of their own site; the free plugin's attempt
	 *    throttling still stands between an attacker and the password.
	 * 2. A completed request that says the challenge was not solved is a hard
	 *    failure, and the sign-in is refused.
	 *
	 * @param \WP_User|\WP_Error|null $user   Authentication result so far.
	 * @param string                  $method Challenge method in use.
	 * @return \WP_User|\WP_Error|null
	 */
	public function verify( $user, $method ) {
		if ( 'turnstile' !== $method ) {
			return $user;
		}

		/*
		 * The raw value is taken here and sanitised on the next line, rather
		 * than sanitised in place: this is a challenge answer, not a state
		 * change, so there is no nonce to check — and the password behind this
		 * form has already been accepted by the time we get here.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on the following line.
		$raw = isset( $_POST[ self::RESPONSE_FIELD ] ) ? wp_unslash( $_POST[ self::RESPONSE_FIELD ] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$token = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		if ( '' === $token ) {
			return new \WP_Error(
				'at8sec_pro_turnstile_missing',
				__( 'The anti-bot check did not complete. Please reload the page and try again.', 'at8-security-pro' )
			);
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout'     => 10,
				'sslverify'   => true,
				'redirection' => 0,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'secret'   => (string) Settings::get( 'turnstile_secret_key' ),
					'response' => $token,
					'remoteip' => \At8\Security\get_client_ip(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $user;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $user;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $body ) && ! empty( $body['success'] ) ) {
			return $user;
		}

		return new \WP_Error(
			'at8sec_pro_turnstile_failed',
			__( 'The anti-bot check was not passed. Please try again.', 'at8-security-pro' )
		);
	}
}
