<?php
/**
 * Basic hardening: version fingerprint, XML-RPC, user enumeration, file editor,
 * author archives and the Apache-side server rules.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 1 — hardening.
 */
class Hardening {

	/**
	 * Marker used inside the root .htaccess so our block can be replaced atomically.
	 */
	const MARKER = 'At8 Security';

	/**
	 * Marker used inside the uploads .htaccess.
	 */
	const UPLOADS_MARKER = 'At8 Security Uploads';

	/**
	 * Option that stores a fingerprint of the root rules we last wrote.
	 */
	const HASH_OPTION = 'at8sec_htaccess_fingerprint';

	/**
	 * Option that stores a fingerprint of the uploads rules we last wrote.
	 */
	const UPLOADS_HASH = 'at8sec_uploads_fingerprint';

	/**
	 * Set while a failed write is being backed off.
	 */
	const RETRY_TRANSIENT = 'at8sec_htaccess_retry';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		if ( Settings::on( 'disable_file_editor' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DISALLOW_FILE_EDIT is a WordPress core constant, not one of ours.
			define( 'DISALLOW_FILE_EDIT', true );
		}

		if ( Settings::on( 'hide_wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
			add_filter( 'style_loader_src', array( $this, 'strip_version_query' ), 20 );
			add_filter( 'script_loader_src', array( $this, 'strip_version_query' ), 20 );
		}

		if ( Settings::on( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( $this, 'drop_pingback_methods' ) );
			add_filter( 'wp_headers', array( $this, 'drop_x_pingback_header' ) );
		}

		if ( Settings::on( 'disable_rest_user_enum' ) ) {
			add_filter( 'rest_endpoints', array( $this, 'hide_user_endpoints' ) );
		}

		if ( Settings::on( 'uniform_login_errors' ) ) {
			add_filter( 'login_errors', array( $this, 'generic_login_error' ) );
		}

		if ( Settings::on( 'remove_rsd_wlw' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( Settings::on( 'disable_author_enum' ) ) {
			/*
			 * Priority 1, not the default 10.
			 *
			 * WordPress core hooks redirect_canonical to template_redirect at
			 * 10 as well, and registers it in default-filters.php before any
			 * plugin runs — so at equal priority core goes first and answers
			 * ?author=1 with a 301 to /author/<login>/, which is exactly the
			 * disclosure this option exists to prevent. Running before it
			 * means the visitor is sent home instead, and the login name
			 * never appears in a URL.
			 */
			add_action( 'template_redirect', array( $this, 'block_author_enumeration' ), 1 );
		}

		/*
		 * The .htaccess block is written when the relevant settings change —
		 * not on every request, which is what the previous implementation did
		 * (it rewrote the file on each page view, competing for a write lock
		 * under load).
		 */
		add_action( 'init', array( $this, 'sync_htaccess_rules' ), 20 );
	}

	/**
	 * Remove the ?ver= query argument from enqueued assets.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function strip_version_query( $src ) {
		if ( ! is_string( $src ) || false === strpos( $src, 'ver=' ) ) {
			return $src;
		}

		return remove_query_arg( 'ver', $src );
	}

	/**
	 * Drop the pingback methods from XML-RPC.
	 *
	 * @param array $methods Registered XML-RPC methods.
	 * @return array
	 */
	public function drop_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

		return is_array( $methods ) ? $methods : array();
	}

	/**
	 * Remove the X-Pingback response header.
	 *
	 * @param array $headers Response headers.
	 * @return array
	 */
	public function drop_x_pingback_header( $headers ) {
		if ( is_array( $headers ) ) {
			unset( $headers['X-Pingback'] );
		}

		return is_array( $headers ) ? $headers : array();
	}

	/**
	 * Hide the /wp/v2/users endpoints from anonymous requests.
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array
	 */
	public function hide_user_endpoints( $endpoints ) {
		if ( is_user_logged_in() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}

	/**
	 * Replace the detailed login error with a neutral one.
	 *
	 * @return string
	 */
	public function generic_login_error() {
		return __( 'The username or password you entered is incorrect.', 'at8-security' );
	}

	/**
	 * Make author archives and ?author=N probes unavailable to visitors.
	 *
	 * @return void
	 */
	public function block_author_enumeration() {
		if ( is_user_logged_in() || is_admin() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$probe = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : '';

		if ( is_author() || '' !== $probe ) {
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	/**
	 * Rules for the document root.
	 *
	 * There is deliberately no PHP-denying rule here. A `<FilesMatch>` block
	 * in the root .htaccess matches every script on the site — index.php,
	 * wp-login.php, admin-ajax.php — so putting one there would return 403 for
	 * the whole site the moment it was switched on. That rule belongs in the
	 * uploads folder, and that is where it is written.
	 *
	 * @return string[]
	 */
	private function root_rules() {
		$rules = array();

		if ( Settings::on( 'disable_dir_listing' ) ) {
			$rules[] = '<IfModule mod_autoindex.c>';
			$rules[] = 'Options -Indexes';
			$rules[] = '</IfModule>';
		}

		return $rules;
	}

	/**
	 * Rules for the uploads folder.
	 *
	 * @return string[]
	 */
	private function uploads_rules() {
		if ( ! Settings::on( 'uploads_disable_php' ) ) {
			return array();
		}

		return array(
			'# Uploaded files are data. Nothing in this folder may be executed.',
			'<FilesMatch "\.(?i:php|phtml|php[0-9]|phar|pl|py|cgi|sh)$">',
			'Require all denied',
			'</FilesMatch>',
		);
	}

	/**
	 * Path of the uploads .htaccess, or an empty string when there is none.
	 *
	 * @return string
	 */
	private function uploads_htaccess() {
		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		if ( '' === $basedir || ! is_dir( $basedir ) ) {
			// The folder is created on the first upload; until then there is
			// nothing to protect and no file to write.
			return '';
		}

		return trailingslashit( $basedir ) . '.htaccess';
	}

	/**
	 * Bring the .htaccess blocks in line with the settings.
	 *
	 * Two files are managed: the document root (directory listing) and the
	 * uploads folder (script execution). Each has its own marker, so a block
	 * written by hand or by another plugin is never touched.
	 *
	 * @return void
	 */
	public function sync_htaccess_rules() {
		$targets = array(
			array(
				'file'   => ABSPATH . '.htaccess',
				'marker' => self::MARKER,
				'option' => self::HASH_OPTION,
				'rules'  => $this->root_rules(),
			),
		);

		$uploads = $this->uploads_htaccess();

		if ( '' !== $uploads ) {
			$targets[] = array(
				'file'   => $uploads,
				'marker' => self::UPLOADS_MARKER,
				'option' => self::UPLOADS_HASH,
				'rules'  => $this->uploads_rules(),
			);
		}

		foreach ( $targets as $target ) {
			$this->sync_target( $target );
		}
	}

	/**
	 * Sync one file.
	 *
	 * @param array{file:string, marker:string, option:string, rules:string[]} $target Target description.
	 * @return void
	 */
	private function sync_target( $target ) {
		$rules = $this->normalise_rules( $target['rules'] );
		$hash  = md5( wp_json_encode( $rules ) );

		if ( get_option( $target['option'] ) === $hash ) {
			return;
		}

		/*
		 * The block is already exactly right — a first run with nothing to
		 * add, or rules put there by hand. Remember that instead of writing.
		 */
		if ( $this->block_matches( $target['file'], $target['marker'], $rules ) ) {
			update_option( $target['option'], $hash, false );

			return;
		}

		/*
		 * A read-only install fails on every request. A short back-off keeps
		 * that from turning into a file operation (and an option write) on
		 * each page view, while still picking the rules up once the
		 * permissions are fixed.
		 */
		if ( get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}

		if ( $this->write_htaccess( $target['file'], $target['marker'], $rules ) ) {
			delete_transient( self::RETRY_TRANSIENT );
			update_option( $target['option'], $hash, false );

			return;
		}

		set_transient( self::RETRY_TRANSIENT, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Trim rule lines and drop the empty ones.
	 *
	 * insert_with_markers() stores every line trimmed, so the comparison has
	 * to happen on the same footing or each request would look like a change.
	 *
	 * @param mixed $rules Raw or stored rule lines.
	 * @return string[]
	 */
	private function normalise_rules( $rules ) {
		$out = array();

		foreach ( (array) $rules as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Does the file already hold exactly these rules under our marker?
	 *
	 * @param string   $file   .htaccess path.
	 * @param string   $marker Marker name.
	 * @param string[] $rules  Normalised rules.
	 * @return bool
	 */
	private function block_matches( $file, $marker, $rules ) {
		$this->load_marker_helpers();

		if ( ! function_exists( 'extract_from_markers' ) ) {
			return false;
		}

		return $this->normalise_rules( extract_from_markers( $file, $marker ) ) === $rules;
	}

	/**
	 * Replace our block in a file.
	 *
	 * @param string   $file   .htaccess path.
	 * @param string   $marker Marker name.
	 * @param string[] $rules  Normalised rules; an empty array removes the block.
	 * @return bool
	 */
	private function write_htaccess( $file, $marker, $rules ) {
		$this->load_marker_helpers();

		if ( ! function_exists( 'insert_with_markers' ) ) {
			return false;
		}

		// insert_with_markers() checks writability itself and returns false
		// when the file cannot be written.
		return (bool) insert_with_markers( $file, $marker, $rules );
	}

	/**
	 * Load the admin file that holds the marker helpers.
	 *
	 * @return void
	 */
	private function load_marker_helpers() {
		if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'extract_from_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
	}
}
