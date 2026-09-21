<?php
/**
 * Automatic detection of CDN and reverse-proxy address ranges.
 *
 * Why this matters: behind a CDN every request arrives from the same handful
 * of edge addresses, so the IP the site sees is the CDN's, not the visitor's.
 * The rate limiter would then be counting every visitor in the world as one
 * person — the first few failed logins would lock out the entire internet
 * including the owner — and the blacklist would never match anybody.
 *
 * The rule this file follows, deliberately:
 *
 *   A range is only merged into the trusted list when the connection itself
 *   arrived from inside that range *and* the provider's own signature header
 *   is present. Trusting a list without checking the source would let any
 *   visitor claim to be anyone by sending a header.
 *
 * The decision is made fresh on every request rather than stored, so a site
 * that moves off a CDN stops trusting it immediately. Nothing is written to
 * the database except a note for the settings screen, and only when that note
 * changes.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CDN / proxy detection.
 */
class Cdn {

	/**
	 * Where the "what did we find" note for the settings screen is kept.
	 */
	const OPTION = 'at8_security_pro_cdn';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_filter( 'at8sec_trusted_proxies', array( $this, 'merge' ) );
		add_filter( 'at8sec_client_ip_headers', array( $this, 'header_order' ) );
		add_action( 'init', array( __CLASS__, 'record' ), 22 );
	}

	/**
	 * Is the automatic detection switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return Settings::on( 'cdn_auto' );
	}

	/**
	 * The networks this knows about.
	 *
	 * The address lists are the ones each provider publishes for its own edge
	 * network. Add a provider with the `at8sec_pro_cdn_providers` filter; the
	 * shape is documented in the README.
	 *
	 * @return array<string, array{label:string, signature:string[], authoritative:string, ranges:string[]}>
	 */
	public static function providers() {
		$providers = array(
			'cloudflare' => array(
				'label'         => 'Cloudflare',
				// Any one of these means the request passed through Cloudflare.
				'signature'     => array( 'HTTP_CF_RAY', 'HTTP_CF_CONNECTING_IP' ),
				// Cloudflare overwrites this one on every request it forwards,
				// including from Workers, so it is the address to believe.
				'authoritative' => 'HTTP_CF_CONNECTING_IP',
				'ranges'        => array(
					// IPv4.
					'173.245.48.0/20',
					'103.21.244.0/22',
					'103.22.200.0/22',
					'103.31.4.0/22',
					'141.101.64.0/18',
					'108.162.192.0/18',
					'190.93.240.0/20',
					'188.114.96.0/20',
					'197.234.240.0/22',
					'198.41.128.0/17',
					'162.158.0.0/15',
					'104.16.0.0/13',
					'104.24.0.0/14',
					'172.64.0.0/13',
					'131.0.72.0/22',
					// IPv6.
					'2400:cb00::/32',
					'2606:4700::/32',
					'2803:f800::/32',
					'2405:b500::/32',
					'2405:8100::/32',
					'2a06:98c0::/29',
					'2c0f:f248::/32',
				),
			),
		);

		/**
		 * Filter the CDN definitions used for automatic detection.
		 *
		 * @param array<string, array<string, mixed>> $providers Provider definitions.
		 */
		return (array) apply_filters( 'at8sec_pro_cdn_providers', $providers );
	}

	/**
	 * The raw address the connection came from.
	 *
	 * Deliberately not the resolved visitor address: this is the one fact a
	 * client cannot forge, and it is what decides whether a range is trusted.
	 *
	 * @return string
	 */
	private static function remote_addr() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated immediately below.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}

	/**
	 * Which provider — if any — is this request arriving through?
	 *
	 * @return string Provider key, or an empty string.
	 */
	public static function detect() {
		$remote = self::remote_addr();

		if ( '' === $remote ) {
			return '';
		}

		foreach ( self::providers() as $key => $provider ) {
			$ranges = isset( $provider['ranges'] ) ? (array) $provider['ranges'] : array();

			// Both tests have to pass: the connection came from the provider's
			// own network, and the request carries the provider's header.
			if ( ! \At8\Security\ip_in_list( $remote, $ranges ) ) {
				continue;
			}

			foreach ( (array) $provider['signature'] as $header ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the presence of the header is used.
				if ( ! empty( $_SERVER[ (string) $header ] ) ) {
					return (string) $key;
				}
			}
		}

		return '';
	}

	/**
	 * The provider's address list, or an empty array.
	 *
	 * @param string $key Provider key.
	 * @return string[]
	 */
	public static function ranges( $key ) {
		$providers = self::providers();

		if ( ! isset( $providers[ $key ]['ranges'] ) ) {
			return array();
		}

		return \At8\Security\parse_ip_list( (array) $providers[ $key ]['ranges'] );
	}

	/**
	 * The provider label, for display.
	 *
	 * @param string $key Provider key.
	 * @return string
	 */
	public static function label( $key ) {
		$providers = self::providers();

		if ( '' === $key || ! isset( $providers[ $key ]['label'] ) ) {
			return '';
		}

		return (string) $providers[ $key ]['label'];
	}

	/**
	 * Merge the detected network into the trusted proxy list.
	 *
	 * Runs on every call to the free plugin's get_trusted_proxies(), which is
	 * where the address resolution needs it. Short of a database round trip
	 * there is nothing to cache here, and a stale cached answer is exactly the
	 * failure this feature has to avoid.
	 *
	 * @param string[] $list Trusted addresses collected so far.
	 * @return string[]
	 */
	public function merge( $list ) {
		$list = (array) $list;

		if ( ! self::enabled() ) {
			return $list;
		}

		$key = self::detect();

		if ( '' === $key ) {
			return $list;
		}

		return array_values( array_unique( array_merge( $list, self::ranges( $key ) ) ) );
	}

	/**
	 * Put the detected network's own header at the front of the queue.
	 *
	 * Cloudflare replaces CF-Connecting-IP on the way through, while X-Real-IP
	 * is just a header like any other — anything able to run code on Cloudflare
	 * could set it. Reading the one the network controls first closes that gap.
	 *
	 * @param string[] $headers Server keys, in the order they will be read.
	 * @return string[]
	 */
	public function header_order( $headers ) {
		$headers = array_values( array_filter( (array) $headers, 'is_string' ) );

		if ( ! self::enabled() ) {
			return $headers;
		}

		$key       = self::detect();
		$providers = self::providers();

		if ( '' === $key || empty( $providers[ $key ]['authoritative'] ) ) {
			return $headers;
		}

		$first = (string) $providers[ $key ]['authoritative'];

		return array_values( array_unique( array_merge( array( $first ), $headers ) ) );
	}

	/**
	 * The note shown on the settings screen.
	 *
	 * @return array{provider:string, label:string, at:int}
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$state = wp_parse_args(
			$stored,
			array(
				'provider' => '',
				'label'    => '',
				'at'       => 0,
			)
		);

		return array(
			'provider' => (string) $state['provider'],
			'label'    => (string) $state['label'],
			'at'       => (int) $state['at'],
		);
	}

	/**
	 * Keep the settings-screen note in step with reality.
	 *
	 * Writes only when the answer changes, so a busy site is not rewriting an
	 * option on every request.
	 *
	 * @return void
	 */
	public static function record() {
		if ( ! self::enabled() ) {
			return;
		}

		$key = self::detect();

		/*
		 * The connection family is part of the note. A network confirmed only
		 * over IPv4 is worth knowing about, and an IPv6-only confirmation is
		 * a different fact again — both are recorded so the screen can say
		 * which one was actually seen.
		 */
		$note = '';
		if ( '' !== $key ) {
			$note = $key . ( false !== strpos( self::remote_addr(), ':' ) ? '|v6' : '|v4' );
		}

		if ( self::state()['provider'] === $note ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'provider' => $note,
				'label'    => self::label( $key ),
				'at'       => time(),
			),
			false
		);
	}

	/**
	 * Is a network sitting in front of this site that is not yet trusted?
	 *
	 * Used to warn when the site is plainly behind a CDN but the setting is
	 * off — the situation where the rate limiter quietly lumps every visitor
	 * together. Signature headers alone are enough for a warning, because a
	 * wrong warning costs nothing while a missed one locks people out.
	 *
	 * @return string Provider label, or an empty string.
	 */
	public static function untrusted_hint() {
		foreach ( self::providers() as $key => $provider ) {
			foreach ( (array) $provider['signature'] as $header ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only the presence of the header is used.
				if ( ! empty( $_SERVER[ (string) $header ] ) ) {
					return self::label( $key );
				}
			}
		}

		return '';
	}
}
