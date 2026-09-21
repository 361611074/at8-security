<?php
/**
 * Force HTTPS and optional HSTS.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 3 — transport security.
 */
class Https {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( Settings::on( 'force_https' ) ) {
			add_action( 'template_redirect', array( $this, 'redirect_to_https' ) );
		}

		if ( Settings::on( 'hsts' ) ) {
			add_action( 'send_headers', array( $this, 'send_hsts' ) );
		}
	}

	/**
	 * Whether the current request reached us over HTTPS.
	 *
	 * Behind a TLS-terminating proxy $_SERVER['HTTPS'] is empty, so relying
	 * on is_ssl() alone would redirect forever. The forwarded-protocol header
	 * is only consulted when the request came from a trusted proxy address —
	 * otherwise a client could pretend to be on HTTPS and skip the redirect.
	 *
	 * @return bool
	 */
	private function is_secure_request() {
		if ( is_ssl() ) {
			return true;
		}

		$remote  = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		$trusted = get_trusted_proxies();

		if ( ! $remote || ! $trusted || ! ip_in_list( $remote, $trusted ) ) {
			return false;
		}

		$proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
			: '';

		return 'https' === strtolower( trim( $proto ) );
	}

	/**
	 * Redirect front-end HTTP requests to HTTPS.
	 *
	 * @return void
	 */
	public function redirect_to_https() {
		if ( is_admin() || $this->is_secure_request() ) {
			return;
		}

		$host = isset( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: '';
		if ( '' === $host ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		wp_safe_redirect( 'https://' . $host . $uri, 301 );
		exit;
	}

	/**
	 * Send the HSTS header on secure responses.
	 *
	 * @return void
	 */
	public function send_hsts() {
		if ( headers_sent() || ! $this->is_secure_request() ) {
			return;
		}

		header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
	}
}
