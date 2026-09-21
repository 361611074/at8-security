<?php
/**
 * Upload restrictions.
 *
 * WordPress already refuses PHP, ASP and similar types out of the box, so the
 * value here is in the second layer: a filename check that still applies when
 * another plugin has widened the allowed MIME list, and a guard against the
 * double-extension trick (`invoice.php.jpg`) plus Apache control files.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 10 — uploads.
 */
class Upload_Guard {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( ! Settings::on( 'upload_restrict' ) ) {
			return;
		}

		add_filter( 'upload_mimes', array( $this, 'filter_mimes' ), 9999 );
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'check_filename' ) );
	}

	/**
	 * Extensions that may never be uploaded.
	 *
	 * @return string[]
	 */
	private function denied_extensions() {
		$extensions = array(
			'php',
			'php3',
			'php4',
			'php5',
			'php7',
			'php8',
			'phps',
			'phtml',
			'pht',
			'phar',
			'cgi',
			'fcgi',
			'pl',
			'py',
			'rb',
			'jsp',
			'jspx',
			'asp',
			'aspx',
			'ashx',
			'asmx',
			'htaccess',
			'htpasswd',
			'exe',
			'dll',
			'sh',
			'bat',
			'cmd',
			'com',
			'scr',
			'msi',
			'jar',
		);

		/**
		 * Filter the list of extensions that may never be uploaded.
		 *
		 * @param string[] $extensions Lower-case extensions without a dot.
		 */
		return (array) apply_filters( 'at8sec_denied_upload_extensions', $extensions );
	}

	/**
	 * Strip dangerous types from the allowed MIME list.
	 *
	 * @param array $mimes Allowed MIME types.
	 * @return array
	 */
	public function filter_mimes( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			return $mimes;
		}

		$denied = $this->denied_extensions();
		$clean  = array();

		/*
		 * The MIME list is keyed by extension groups — "jpg|jpeg|jpe" holds
		 * three extensions — so simply unsetting a key only removes a type
		 * that was registered on its own. Splitting the key and keeping the
		 * remaining extensions removes the dangerous one whatever else shared
		 * its entry.
		 */
		foreach ( $mimes as $extensions => $mime ) {
			$kept = array();

			foreach ( explode( '|', (string) $extensions ) as $extension ) {
				$extension = strtolower( trim( $extension ) );

				if ( '' !== $extension && ! in_array( $extension, $denied, true ) ) {
					$kept[] = $extension;
				}
			}

			if ( $kept ) {
				$clean[ implode( '|', $kept ) ] = $mime;
			}
		}

		return $clean;
	}

	/**
	 * Reject an upload by filename, whatever the MIME list says.
	 *
	 * @param array $file Entry from $_FILES plus the upload context.
	 * @return array
	 */
	public function check_filename( $file ) {
		if ( ! is_array( $file ) || empty( $file['name'] ) ) {
			return $file;
		}

		$name      = strtolower( (string) $file['name'] );
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, $this->denied_extensions(), true ) ) {
			$file['error'] = __( 'This file type is not allowed for security reasons.', 'at8-security' );

			return $file;
		}

		/*
		 * Double extension: a server misconfiguration that executes anything
		 * containing ".php" would happily run `photo.php.jpg`. WordPress
		 * normally renames such files, but rejecting them is cheap and stops
		 * the payload from being stored at all.
		 */
		if ( preg_match( '/\.(php\d?|phtml|pht|phar|asp|aspx|jsp|cgi|pl|py|sh|bat|cmd|exe)\.[a-z0-9]{2,6}$/', $name ) ) {
			$file['error'] = __( 'This file name looks like an executable disguised as an image, so it was rejected.', 'at8-security' );

			return $file;
		}

		return $file;
	}
}
