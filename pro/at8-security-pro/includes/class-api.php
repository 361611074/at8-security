<?php
/**
 * Client for the licence server.
 *
 * Every call goes to the author's own site. Nothing is ever sent to a third
 * party, and no request is made from the front end — licensing runs in the
 * admin area and from a scheduled job only.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin JSON client around wp_remote_post().
 */
class Api {

	const DEFAULT_BASE = 'https://www.at8.fun';
	const PRODUCT      = 'at8-security-pro';
	const ROUTE        = '/wp-json/at8-license/v1';

	/**
	 * Base URL of the licence server.
	 *
	 * @return string
	 */
	public static function base() {
		$base = (string) apply_filters( 'at8sec_pro_api_base', self::DEFAULT_BASE );

		return untrailingslashit( $base );
	}

	/**
	 * Timeout for one request, in seconds.
	 *
	 * @return int
	 */
	private static function timeout() {
		return max( 5, (int) apply_filters( 'at8sec_pro_api_timeout', 15 ) );
	}

	/**
	 * Ask the server to activate a key on this site.
	 *
	 * @param string $key Licence key.
	 * @return array|\WP_Error Response payload, or an error.
	 */
	public static function activate( $key ) {
		return self::post(
			'/activate',
			array(
				'license' => (string) $key,
				'domain'  => site_host(),
				'product' => self::PRODUCT,
				'version' => AT8SEC_PRO_VERSION,
				'wp'      => get_bloginfo( 'version' ),
				'php'     => PHP_VERSION,
			)
		);
	}

	/**
	 * Ask the server whether a key is still good for this site.
	 *
	 * @param string $key Licence key.
	 * @return array|\WP_Error Response payload, or an error.
	 */
	public static function validate( $key ) {
		return self::post(
			'/validate',
			array(
				'license' => (string) $key,
				'domain'  => site_host(),
				'product' => self::PRODUCT,
				'version' => AT8SEC_PRO_VERSION,
			)
		);
	}

	/**
	 * Release this site from the key, so it can be used elsewhere.
	 *
	 * @param string $key Licence key.
	 * @return array|\WP_Error Response payload, or an error.
	 */
	public static function deactivate( $key ) {
		return self::post(
			'/deactivate',
			array(
				'license' => (string) $key,
				'domain'  => site_host(),
				'product' => self::PRODUCT,
			)
		);
	}

	/**
	 * Ask whether a newer build is available.
	 *
	 * Sent as a POST so the key never lands in a query string, where it would
	 * end up in access logs on the way to the server.
	 *
	 * @param string $key Licence key.
	 * @return array|\WP_Error Response payload, or an error.
	 */
	public static function update( $key ) {
		return self::post(
			'/update',
			array(
				'license' => (string) $key,
				'domain'  => site_host(),
				'product' => self::PRODUCT,
				'version' => AT8SEC_PRO_VERSION,
				'wp'      => get_bloginfo( 'version' ),
				'php'     => PHP_VERSION,
			)
		);
	}

	/**
	 * Send a POST request and decode the JSON answer.
	 *
	 * @param string               $endpoint Endpoint path, e.g. "/activate".
	 * @param array<string, mixed> $payload  Form fields.
	 * @return array|\WP_Error Decoded body, or a WP_Error whose code is
	 *                         "unreachable", "http_<code>" or "bad_response".
	 */
	private static function post( $endpoint, $payload ) {
		$response = wp_remote_post(
			self::base() . self::ROUTE . $endpoint,
			array(
				'timeout'     => self::timeout(),
				'sslverify'   => true,
				'redirection' => 2,
				'headers'     => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
					'User-Agent'   => 'At8-Security-Pro/' . AT8SEC_PRO_VERSION,
				),
				'body'        => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'unreachable', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'http_' . $code,
				sprintf(
					/* translators: %d: HTTP status code returned by the licence server. */
					__( 'The licence server answered with HTTP %d.', 'at8-security-pro' ),
					$code
				)
			);
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_response', __( 'The licence server sent a response that could not be read.', 'at8-security-pro' ) );
		}

		return $data;
	}
}
