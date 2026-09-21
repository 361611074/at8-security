<?php
/**
 * Optional REST API lockdown.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 11 — REST API.
 */
class Rest_Lockdown {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( ! Settings::on( 'rest_lockdown' ) ) {
			return;
		}

		add_filter( 'rest_authentication_errors', array( $this, 'require_authentication' ) );
	}

	/**
	 * Refuse anonymous access to everything except the public content routes.
	 *
	 * @param \WP_Error|null $result Existing authentication result.
	 * @return \WP_Error|null
	 */
	public function require_authentication( $result ) {
		if ( ! empty( $result ) || is_user_logged_in() ) {
			return $result;
		}

		$route = $this->current_route();

		if ( '' === $route || $this->is_public_route( $route ) ) {
			return $result;
		}

		return new \WP_Error(
			'at8sec_rest_forbidden',
			__( 'This endpoint requires authentication.', 'at8-security' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * The REST route for the current request.
	 *
	 * @return string
	 */
	private function current_route() {
		$uri  = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$pos  = strpos( $path, '/wp-json/' );

		if ( false !== $pos ) {
			return trim( substr( $path, $pos + strlen( '/wp-json/' ) ), '/' );
		}

		// Plain permalinks pass the route as a query argument instead.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( isset( $_GET['rest_route'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return trim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' );
		}

		return '';
	}

	/**
	 * Routes that stay readable without a session.
	 *
	 * @param string $route Current route.
	 * @return bool
	 */
	private function is_public_route( $route ) {
		$allowed = array(
			'wp/v2/posts',
			'wp/v2/pages',
			'wp/v2/media',
			'wp/v2/categories',
			'wp/v2/tags',
			'wp/v2/types',
			'wp/v2/statuses',
			'wp/v2/comments',
			'wp/v2/taxonomies',
			'oembed/1.0',
		);

		/**
		 * Filter the REST routes that remain available to anonymous visitors.
		 *
		 * @param string[] $allowed Route prefixes.
		 */
		$allowed = (array) apply_filters( 'at8sec_public_rest_routes', $allowed );

		foreach ( $allowed as $prefix ) {
			if ( $route === $prefix || 0 === strpos( $route, $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}
}
