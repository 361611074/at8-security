<?php
/**
 * Security response headers and Content Security Policy.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 2 — response headers.
 */
class Headers {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( Settings::on( 'security_headers' ) ) {
			add_action( 'send_headers', array( $this, 'send' ) );
		}

		if ( Settings::on( 'enable_csp' ) ) {
			add_action( 'send_headers', array( $this, 'send_csp' ), 5 );
		}
	}

	/**
	 * Send the baseline hardening headers.
	 *
	 * @return void
	 */
	public function send() {
		if ( headers_sent() ) {
			return;
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );

		/*
		 * X-Frame-Options is only meaningful for documents. Sending it on
		 * every response is harmless, but skipping it for the REST and admin
		 * endpoints avoids breaking embeds and the block editor preview.
		 */
		if ( ! is_admin() ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
	}

	/**
	 * Send the CSP header.
	 *
	 * The default is Report-Only, so an incorrect policy cannot take a site
	 * down on the first page load — it only reports.
	 *
	 * @return void
	 */
	public function send_csp() {
		if ( headers_sent() || is_admin() ) {
			return;
		}

		$policy = trim( (string) Settings::get( 'csp_policy', '' ) );
		if ( '' === $policy ) {
			return;
		}

		$header = Settings::on( 'csp_report_only' )
			? 'Content-Security-Policy-Report-Only'
			: 'Content-Security-Policy';

		header( $header . ': ' . $policy );
	}
}
