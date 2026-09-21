<?php
/**
 * Shared helper functions.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the Pro add-on is active and licensed.
 *
 * The free plugin ships alone, so this always returns false here — the filter
 * has no callbacks to change it. The Pro add-on (distributed separately, not
 * from the WordPress.org directory) hooks the same filter and returns true
 * once its license has been validated. Because the Pro code is never part of
 * this package, nothing in this plugin is locked behind a payment.
 *
 * @return bool
 */
function is_pro() {
	return (bool) apply_filters( 'at8sec_is_pro', false );
}

/**
 * Normalise the value of an "IP list" setting into an array.
 *
 * Accepts newlines, commas, semicolons and whitespace as separators.
 *
 * @param mixed $raw Raw setting value.
 * @return string[]
 */
function parse_list( $raw ) {
	if ( is_array( $raw ) ) {
		$raw = implode( "\n", $raw );
	}

	$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

	return is_array( $parts ) ? $parts : array();
}

/**
 * Keep only entries that are a valid IP address or a valid CIDR block.
 *
 * @param mixed $raw Raw setting value.
 * @return string[] Filtered list.
 */
function parse_ip_list( $raw ) {
	$out = array();

	foreach ( parse_list( $raw ) as $entry ) {
		$entry = trim( $entry );
		if ( '' === $entry ) {
			continue;
		}

		if ( false !== strpos( $entry, '/' ) ) {
			list( $subnet, $mask ) = array_pad( explode( '/', $entry, 2 ), 2, '' );
			$mask                  = (int) $mask;
			$bits                  = ( false !== strpos( $subnet, ':' ) ) ? 128 : 32;

			if ( filter_var( $subnet, FILTER_VALIDATE_IP ) && $mask >= 0 && $mask <= $bits ) {
				$out[] = $subnet . '/' . $mask;
			}
			continue;
		}

		if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
			$out[] = $entry;
		}
	}

	return array_values( array_unique( $out ) );
}

/**
 * Check whether an IP address appears in a list of IPs / CIDR blocks.
 *
 * @param string $ip   IP address to look for.
 * @param mixed  $list Raw list, or a value returned by parse_ip_list().
 * @return bool
 */
function ip_in_list( $ip, $list ) {
	if ( ! is_array( $list ) ) {
		$list = parse_ip_list( $list );
	}

	foreach ( $list as $entry ) {
		if ( false !== strpos( $entry, '/' ) ) {
			if ( ip_in_cidr( $ip, $entry ) ) {
				return true;
			}
		} elseif ( $ip === $entry ) {
			return true;
		}
	}

	return false;
}

/**
 * Check whether an IP address falls inside a CIDR block.
 *
 * Both address families are handled by the same routine: the two addresses
 * are unpacked to their raw bytes and compared bit by bit, so an IPv4 block
 * never has to be reasoned about separately from an IPv6 one. An address and
 * a block from different families never match, which is the correct answer
 * rather than a guess.
 *
 * @param string $ip   IP address, IPv4 or IPv6.
 * @param string $cidr CIDR block, e.g. 192.168.0.0/16 or 2606:4700::/32.
 * @return bool
 */
function ip_in_cidr( $ip, $cidr ) {
	$parts  = explode( '/', (string) $cidr, 2 );
	$subnet = $parts[0];

	// inet_pton() is stricter than a regex here: it accepts exactly the
	// textual forms that are real addresses.
	$ip_bin     = @inet_pton( (string) $ip );
	$subnet_bin = @inet_pton( $subnet );

	if ( false === $ip_bin || false === $subnet_bin ) {
		return false;
	}

	// A /16 on IPv4 says nothing about an IPv6 address, and vice versa.
	if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
		return false;
	}

	if ( ! isset( $parts[1] ) ) {
		return $ip_bin === $subnet_bin;
	}

	if ( ! is_numeric( $parts[1] ) ) {
		return false;
	}

	$bits = strlen( $ip_bin ) * 8;
	$mask = (int) $parts[1];

	if ( $mask < 0 || $mask > $bits ) {
		return false;
	}

	// 0.0.0.0/0 and ::/0 match every address in their own family.
	if ( 0 === $mask ) {
		return true;
	}

	$whole = intdiv( $mask, 8 );
	$rest  = $mask % 8;

	if ( substr( $ip_bin, 0, $whole ) !== substr( $subnet_bin, 0, $whole ) ) {
		return false;
	}

	if ( 0 === $rest ) {
		return true;
	}

	// Compare the one byte the mask cuts through, bit by bit.
	$mask_byte = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

	return ( ord( $ip_bin[ $whole ] ) & $mask_byte ) === ( ord( $subnet_bin[ $whole ] ) & $mask_byte );
}

/**
 * Trusted reverse-proxy / CDN edge addresses.
 *
 * Sources, in order: the AT8SEC_TRUSTED_PROXIES constant (wp-config.php) and
 * the `trusted_proxies` setting.
 *
 * @return string[]
 */
function get_trusted_proxies() {
	$entries = array();

	if ( defined( 'AT8SEC_TRUSTED_PROXIES' ) && is_string( AT8SEC_TRUSTED_PROXIES ) ) {
		$entries = array_merge( $entries, parse_ip_list( AT8SEC_TRUSTED_PROXIES ) );
	}

	$setting = Settings::get( 'trusted_proxies', '' );
	if ( $setting ) {
		$entries = array_merge( $entries, parse_ip_list( $setting ) );
	}

	/**
	 * Filter the trusted reverse-proxy / CDN address list.
	 *
	 * An add-on that maintains a CDN address list of its own can merge it in
	 * here instead of writing into this plugin's setting.
	 *
	 * @param string[] $entries Trusted addresses and CIDR blocks.
	 */
	return (array) apply_filters( 'at8sec_trusted_proxies', array_values( array_unique( $entries ) ) );
}

/**
 * Resolve the address of the visitor behind an optional proxy or CDN.
 *
 * Security note — this is the single most important rule in this file:
 * forwarding headers (X-Real-IP, X-Forwarded-For, CF-Connecting-IP …) are
 * attacker-controlled text. They are only honoured when the *connection*
 * itself comes from an address listed in the trusted proxy list. A request
 * arriving straight from the internet can therefore never claim to be a
 * different IP, which would otherwise defeat the blacklist, the rate limiter
 * and the audit log all at once.
 *
 * @return string IP address, or 0.0.0.0 when it cannot be determined.
 */
function get_client_ip() {
	$remote = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: '';

	if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
		return '0.0.0.0';
	}

	$trusted = get_trusted_proxies();
	if ( ! $trusted || ! ip_in_list( $remote, $trusted ) ) {
		return $remote;
	}

	/*
	 * Trusted proxy: work through the headers in order. The first two hold a
	 * single address; X-Forwarded-For is a list, and its left-most (oldest)
	 * entry is the originating client.
	 */
	$headers = apply_filters(
		'at8sec_client_ip_headers',
		array( 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' )
	);

	foreach ( (array) $headers as $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			continue;
		}

		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}

		$raw  = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		$bits = explode( ',', $raw );
		$ip   = trim( $bits[0] );

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return $remote;
}

/**
 * Build a short, stable hash of an IP address for use in transient keys.
 *
 * Hashing keeps raw addresses out of the options table, which is a small
 * privacy win on shared hosting and makes the keys a fixed length.
 *
 * @param string $ip IP address.
 * @return string
 */
function ip_hash( $ip ) {
	return substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * Enqueue the stylesheet used outside the admin area.
 *
 * The login honeypot and the arithmetic challenge are printed on the login
 * screen and on the front end, so their styles cannot live in admin.css —
 * which is only loaded on this plugin's own settings screen. Without this the
 * honeypot field would be plainly visible to visitors.
 *
 * Safe to call more than once: WordPress de-duplicates by handle.
 *
 * @return void
 */
function enqueue_front_style() {
	wp_enqueue_style( 'at8sec-front', AT8SEC_URL . 'assets/front.css', array(), AT8SEC_VERSION );
}
