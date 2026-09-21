<?php
/**
 * Shared helpers for the Pro add-on.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is the free plugin loaded and usable?
 *
 * Everything in this add-on hangs off hooks the free plugin publishes, so
 * this has to be true before any of it can be registered.
 *
 * @return bool
 */
function free_plugin_loaded() {
	return class_exists( 'At8\\Security\\Plugin' ) && function_exists( 'At8\\Security\\is_pro' );
}

/**
 * Host name this install answers on, used to bind a licence to a site.
 *
 * The leading "www." is dropped so buying a licence for example.com covers
 * www.example.com, and only the host is used — a path or a scheme change does
 * not invalidate a licence.
 *
 * @return string
 */
function site_host() {
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

	return (string) preg_replace( '/^www\./', '', $host );
}

/**
 * Development bypass, only settable from wp-config.php.
 *
 * Deliberately a constant rather than a setting: a customer cannot switch it
 * on from the dashboard, but the author can keep working on local and staging
 * installs without an internet round trip.
 *
 * Add `define( 'AT8SEC_PRO_DEV_MODE', true );` to wp-config.php to use it.
 *
 * @return bool
 */
function dev_mode() {
	return defined( 'AT8SEC_PRO_DEV_MODE' ) && AT8SEC_PRO_DEV_MODE;
}

/**
 * Shorten a licence key for display.
 *
 * The full key is never echoed back into a page: what is shown is enough for
 * the owner to recognise which key is installed, and useless to anyone who
 * reads it over a shoulder.
 *
 * @param string $key Licence key.
 * @return string
 */
function mask_key( $key ) {
	$key = (string) $key;
	$len = strlen( $key );

	if ( 0 === $len ) {
		return '';
	}

	if ( $len <= 12 ) {
		return str_repeat( '*', $len );
	}

	return substr( $key, 0, 4 ) . str_repeat( '*', 8 ) . substr( $key, -4 );
}

/**
 * Enqueue the stylesheet used outside the admin area.
 *
 * The two-factor field and the hosted challenge appear on the sign-in form,
 * which loads no admin stylesheet at all — so these styles need a file of
 * their own, or the field would arrive unstyled.
 *
 * Safe to call more than once: WordPress de-duplicates by handle.
 *
 * @return void
 */
function enqueue_front_style() {
	wp_enqueue_style( 'at8sec-pro-front', AT8SEC_PRO_URL . 'assets/front.css', array(), AT8SEC_PRO_VERSION );
}
