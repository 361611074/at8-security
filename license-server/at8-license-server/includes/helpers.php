<?php
/**
 * Shared helpers: key generation, tokens and small lookups.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Characters a licence key is built from.
 *
 * Crockford's base32 minus I, L, O and U, so a key read off a screen or an
 * invoice cannot be mistyped as 1/0, and no accidental words appear.
 */
const KEY_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
const KEY_GROUPS   = 4;
const KEY_GROUP_LEN = 5;

/**
 * Table names, resolved at call time so a multisite install picks the right one.
 *
 * @return array{licenses:string, sites:string, audit:string}
 */
function tables() {
	global $wpdb;

	return array(
		'licenses' => $wpdb->prefix . 'at8_licenses',
		'sites'    => $wpdb->prefix . 'at8_license_sites',
		'audit'    => $wpdb->prefix . 'at8_audit_logs',
	);
}

/**
 * The environments a site may report.
 *
 * A licence sold for one production site is often first installed on a
 * staging copy; the value is recorded so that is visible rather than guessed.
 *
 * @return string[]
 */
function environments() {
	return array( 'production', 'staging', 'development' );
}

/**
 * Fold a reported environment onto the known list.
 *
 * @param string $environment Raw value from a request.
 * @return string One of environments().
 */
function normalize_environment( $environment ) {
	$environment = sanitize_key( (string) $environment );

	return in_array( $environment, environments(), true ) ? $environment : 'production';
}

/**
 * Stable fingerprint of a site, stored alongside the domain.
 *
 * A hash rather than the raw value keeps a future "does this licence follow
 * the site or the address" decision possible without another migration.
 *
 * @param string $domain Normalised domain.
 * @return string
 */
function site_hash( $domain ) {
	$domain = normalize_domain( $domain );

	return '' === $domain ? '' : md5( $domain );
}

/**
 * Read a version string from a request body.
 *
 * Two spellings are accepted on purpose: the client distributed before the
 * field names were settled sends `wp` / `php`, the documented shape sends
 * `wp_version` / `php_version`. Supporting both means the server can be
 * updated before the sites are, which is the order updates actually happen in.
 *
 * @param array<string, mixed> $payload Request body.
 * @param string[]             $names   Candidate field names, in order.
 * @return string
 */
function payload_version( $payload, $names ) {
	foreach ( (array) $names as $name ) {
		if ( isset( $payload[ $name ] ) && '' !== (string) $payload[ $name ] ) {
			return substr( sanitize_text_field( (string) $payload[ $name ] ), 0, 20 );
		}
	}

	return '';
}

/**
 * Read a URL field from a request body, ready for storage.
 *
 * @param array<string, mixed> $payload Request body.
 * @param string               $field   Field name.
 * @return string
 */
function site_url_from( $payload, $field ) {
	if ( empty( $payload[ $field ] ) ) {
		return '';
	}

	$url = esc_url_raw( (string) $payload[ $field ] );

	return substr( (string) $url, 0, 255 );
}

/**
 * Products this server can license.
 *
 * @return array<string, array<string, string>>
 */
function products() {
	$products = array(
		'at8-security-pro' => array(
			'label' => __( 'At8 Security Pro', 'at8-license-server' ),
			'code'  => 'SEC',
		),
	);

	/**
	 * Filters the licensable products.
	 *
	 * @param array<string, array<string, string>> $products Product slugs to definitions.
	 */
	$products = (array) apply_filters( 'at8_license_server_products', $products );

	return $products;
}

/**
 * Label for one product slug.
 *
 * @param string $slug Product slug.
 * @return string
 */
function product_label( $slug ) {
	$products = products();

	if ( isset( $products[ $slug ]['label'] ) ) {
		return (string) $products[ $slug ]['label'];
	}

	return '' === $slug ? __( 'Unknown product', 'at8-license-server' ) : $slug;
}

/**
 * Short code used inside generated keys, e.g. SEC for at8-security-pro.
 *
 * @param string $slug Product slug.
 * @return string
 */
function product_code( $slug ) {
	$products = products();

	if ( isset( $products[ $slug ]['code'] ) && '' !== $products[ $slug ]['code'] ) {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $products[ $slug ]['code'] ) );
	}

	return 'KEY';
}

/**
 * Build a fresh licence key.
 *
 * The key is grouped for readability; `normalize_key()` folds it back down, so
 * dashes, case and stray spaces never decide whether a copy works.
 *
 * @param string $product Product slug.
 * @return string
 */
function generate_key( $product ) {
	$code     = product_code( $product );
	$alphabet = KEY_ALPHABET;
	$length   = strlen( $alphabet );
	$groups   = array();

	for ( $group = 0; $group < KEY_GROUPS; $group++ ) {
		$chars = '';

		for ( $i = 0; $i < KEY_GROUP_LEN; $i++ ) {
			// random_int() is not biased the way a modulo of random_bytes is.
			$chars .= $alphabet[ random_int( 0, $length - 1 ) ];
		}

		$groups[] = $chars;
	}

	return 'AT8-' . $code . '-' . implode( '-', $groups );
}

/**
 * Reduce a key to the form used for lookups.
 *
 * @param string $key Raw key from a form or a request.
 * @return string
 */
function normalize_key( $key ) {
	return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $key ) );
}

/**
 * Turn a stored key into the display form, e.g. AT8-SEC-XXXXX-XXXXX-…
 *
 * @param string $key Stored key.
 * @return string
 */
function format_key( $key ) {
	return (string) $key;
}

/**
 * Normalise a domain so www and non-www count as the same site.
 *
 * @param string $domain Hostname, possibly with a scheme, path or port.
 * @return string Lower-case hostname, without a leading www.
 */
function normalize_domain( $domain ) {
	$domain = trim( strtolower( (string) $domain ) );

	if ( '' === $domain ) {
		return '';
	}

	if ( false !== strpos( $domain, '://' ) ) {
		$host = wp_parse_url( $domain, PHP_URL_HOST );
		$domain = is_string( $host ) ? $host : $domain;
	}

	$domain = preg_replace( '/[^a-z0-9.\-:]/', '', $domain );

	if ( ! is_string( $domain ) ) {
		return '';
	}

	/**
	 * Filters whether a leading www. is ignored when matching a site.
	 *
	 * @param bool $strip Whether to strip it.
	 */
	if ( apply_filters( 'at8_license_server_strip_www', true ) ) {
		$domain = preg_replace( '/^www\./', '', $domain );
	}

	return (string) $domain;
}

/**
 * IP address of the caller, for rate limiting and the site table.
 *
 * Kept deliberately literal: this server sits behind a CDN it controls, so the
 * proxy header is only read when the direct peer is one of the ranges below.
 *
 * @return string
 */
function client_ip() {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	$header = 'HTTP_CF_CONNECTING_IP';

	$ip = $remote;

	if ( isset( $_SERVER[ $header ] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP below.
		$candidate = (string) wp_unslash( $_SERVER[ $header ] );

		if ( '' !== $candidate && is_trusted_proxy( $remote ) ) {
			$ip = $candidate;
		}
	}

	$ip = filter_var( $ip, FILTER_VALIDATE_IP );

	return $ip ? (string) $ip : '';
}

/**
 * Are we allowed to believe a proxy header coming from this peer?
 *
 * @param string $ip Peer address.
 * @return bool
 */
function is_trusted_proxy( $ip ) {
	$ranges = array(
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
		'127.0.0.1/32',
	);

	/**
	 * Filters the proxy ranges whose headers may be trusted.
	 *
	 * @param string[] $ranges CIDR list.
	 */
	$ranges = (array) apply_filters( 'at8_license_server_trusted_proxies', $ranges );

	return in_array( true, array_map( static fn( $cidr ) => ip_in_cidr( $ip, (string) $cidr ), $ranges ), true );
}

/**
 * Is an IP inside a CIDR block? Handles IPv4 and IPv6.
 *
 * @param string $ip   Address to test.
 * @param string $cidr Block, e.g. 10.0.0.0/8.
 * @return bool
 */
function ip_in_cidr( $ip, $cidr ) {
	$parts  = explode( '/', (string) $cidr, 2 );
	$subnet = $parts[0];

	$ip_bin     = @inet_pton( (string) $ip );
	$subnet_bin = @inet_pton( $subnet );

	if ( false === $ip_bin || false === $subnet_bin ) {
		return false;
	}

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

	$mask_byte = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

	return ( ord( $ip_bin[ $whole ] ) & $mask_byte ) === ( ord( $subnet_bin[ $whole ] ) & $mask_byte );
}

/**
 * Count a request against a per-IP budget.
 *
 * A wrong key is a guess, and guessing is the only way to find a key, so the
 * endpoint that accepts keys is the one that needs a ceiling.
 *
 * @param string $bucket Bucket name, e.g. "activate".
 * @param int    $limit  Allowed attempts inside the window.
 * @param int    $window Window length in seconds.
 * @return bool True when the caller is still inside the budget.
 */
function rate_limit( $bucket, $limit = 30, $window = 600 ) {
	$ip = client_ip();

	if ( '' === $ip ) {
		return true;
	}

	$option = 'at8ls_rl_' . $bucket . '_' . md5( $ip . '|' . wp_salt( 'nonce' ) );
	$hits   = get_transient( $option );

	if ( ! is_array( $hits ) ) {
		$hits = array(
			'count' => 0,
			'until' => time() + $window,
		);
	}

	if ( $hits['until'] < time() ) {
		$hits = array(
			'count' => 0,
			'until' => time() + $window,
		);
	}

	$hits['count']++;

	set_transient( $option, $hits, max( 60, (int) $hits['until'] - time() ) );

	return $hits['count'] <= $limit;
}

/**
 * Sign a value so it can travel through a URL without being tampered with.
 *
 * @param string $payload Value to sign.
 * @return string Base64url signature.
 */
function sign( $payload ) {
	return rtrim( strtr( base64_encode( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ), true ) ), '+/', '-_' ), '=' );
}

/**
 * Constant-time comparison, so a signature check cannot be timed.
 *
 * @param string $known Known-good value.
 * @param string $given Value to test.
 * @return bool
 */
function signature_ok( $known, $given ) {
	return '' !== $known && '' !== $given && hash_equals( $known, $given );
}

/**
 * Human-readable date, or a dash.
 *
 * @param int $timestamp Unix time, 0 for "never".
 * @return string
 */
function when( $timestamp ) {
	$timestamp = (int) $timestamp;

	if ( $timestamp <= 0 ) {
		return '—';
	}

	return (string) wp_date( 'Y-m-d H:i', $timestamp );
}

/**
 * Describe an expiry value.
 *
 * @param int $expires Unix time, 0 meaning no end date.
 * @return string
 */
function expiry_label( $expires ) {
	$expires = (int) $expires;

	if ( 0 === $expires ) {
		return __( 'Lifetime', 'at8-license-server' );
	}

	return when( $expires );
}

/**
 * Where update packages are kept on disk.
 *
 * @return string Absolute path, with a trailing slash.
 */
function package_dir() {
	$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'at8-packages';

	/**
	 * Filters the directory the release packages are stored in.
	 *
	 * @param string $dir Absolute path.
	 */
	$dir = (string) apply_filters( 'at8_license_server_package_dir', $dir );

	return trailingslashit( $dir );
}
