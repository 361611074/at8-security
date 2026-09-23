<?php
/**
 * Release metadata and the update packages themselves.
 *
 * A package is only ever handed out through a short-lived signed link, so the
 * file itself can sit in the uploads directory without being downloadable by
 * anyone who guesses the path.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Releases and downloads.
 */
class Package {

	const OPTION_RELEASES = 'at8ls_releases';

	/**
	 * How long a download link stays valid.
	 *
	 * The updater asks for the link and fetches it in the same request cycle,
	 * so half an hour is generous. The earlier six hours were not: revoking a
	 * licence inside that window still left a working download URL in the
	 * wild, which is the whole point of keeping the window short.
	 */
	const TOKEN_TTL = 1800;

	/**
	 * The statuses a release moves through.
	 *
	 * `withdrawn` rather than a deleted row: a build that was pulled is a fact
	 * worth keeping, and the file on disk is the only copy of it.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( 'draft', 'testing', 'published', 'deprecated', 'withdrawn' );
	}

	/**
	 * Every release, keyed by product.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		$releases = get_option( self::OPTION_RELEASES, array() );

		return is_array( $releases ) ? $releases : array();
	}

	/**
	 * The release recorded for one product.
	 *
	 * @param string $product Product slug.
	 * @return array<string, mixed>
	 */
	public static function get( $product ) {
		$all = self::all();

		return isset( $all[ $product ] ) && is_array( $all[ $product ] ) ? $all[ $product ] : array();
	}

	/**
	 * Status of a release.
	 *
	 * A record written before statuses existed has none, and was live; it is
	 * read as published so an upgrade does not silently stop offering updates.
	 *
	 * @param string $product Product slug.
	 * @return string
	 */
	public static function status( $product ) {
		$release = self::get( $product );

		return isset( $release['status'] ) && '' !== (string) $release['status']
			? (string) $release['status']
			: 'published';
	}

	/**
	 * The release a client should be offered, or an empty array.
	 *
	 * @param string $product Product slug.
	 * @return array<string, mixed>
	 */
	public static function published( $product ) {
		$release = self::get( $product );

		if ( empty( $release['version'] ) || 'published' !== self::status( $product ) ) {
			return array();
		}

		return $release;
	}

	/**
	 * The currently published version, or an empty string.
	 *
	 * @param string $product Product slug.
	 * @return string
	 */
	public static function version( $product ) {
		$release = self::published( $product );

		return isset( $release['version'] ) ? (string) $release['version'] : '';
	}

	/**
	 * Absolute path of the published package, or an empty string.
	 *
	 * A recorded release whose file has since been removed must not be offered
	 * to clients: the updater would fail halfway through an upgrade.
	 *
	 * @param string $product Product slug.
	 * @return string
	 */
	public static function path( $product ) {
		$release = self::published( $product );

		if ( empty( $release['file'] ) ) {
			return '';
		}

		$path = package_dir() . basename( (string) $release['file'] );

		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Save a release record.
	 *
	 * @param string               $product Product slug.
	 * @param array<string, mixed> $values  version, file, changelog, requires, tested, requires_php.
	 * @return void
	 */
	public static function save( $product, $values ) {
		$all      = self::all();
		$previous = isset( $all[ $product ] ) && is_array( $all[ $product ] ) ? $all[ $product ] : array();
		$status   = isset( $values['status'] ) ? (string) $values['status'] : 'published';
		$status   = in_array( $status, self::statuses(), true ) ? $status : 'published';
		$now      = time();

		$published_at = isset( $previous['published_at'] ) ? (int) $previous['published_at'] : 0;

		if ( 'published' === $status && 0 === $published_at ) {
			$published_at = $now;
		}

		$all[ $product ] = array(
			'version'      => isset( $values['version'] ) ? (string) $values['version'] : '',
			'file'         => isset( $values['file'] ) && '' !== (string) $values['file']
				? (string) $values['file']
				: ( isset( $previous['file'] ) ? (string) $previous['file'] : '' ),
			'changelog'    => isset( $values['changelog'] ) ? (string) $values['changelog'] : '',
			'requires'     => isset( $values['requires'] ) && '' !== (string) $values['requires'] ? (string) $values['requires'] : '6.4',
			'tested'       => isset( $values['tested'] ) ? (string) $values['tested'] : '',
			'requires_php' => isset( $values['requires_php'] ) && '' !== (string) $values['requires_php'] ? (string) $values['requires_php'] : '7.4',
			/*
			 * The hash and size belong to the file, not to the form: a value
			 * arriving with this call comes from the upload that just landed,
			 * and otherwise the previous one is carried over so that saving a
			 * changelog edit does not wipe the fingerprint of the package on
			 * disk.
			 */
			'sha256'       => isset( $values['sha256'] ) && '' !== (string) $values['sha256']
				? (string) $values['sha256']
				: ( isset( $previous['sha256'] ) ? (string) $previous['sha256'] : '' ),
			'package_size' => isset( $values['package_size'] ) && (int) $values['package_size'] > 0
				? (int) $values['package_size']
				: ( isset( $previous['package_size'] ) ? (int) $previous['package_size'] : 0 ),
			'status'       => $status,
			'uploaded_at'  => $now,
			'published_at' => $published_at,
		);

		update_option( self::OPTION_RELEASES, $all, false );

		Audit::record(
			'release.' . $status,
			'release',
			0,
			array(
				'product' => (string) $product,
				'version' => (string) $all[ $product ]['version'],
				'file'    => (string) $all[ $product ]['file'],
			)
		);
	}

	/**
	 * Move a release to another status.
	 *
	 * @param string $product Product slug.
	 * @param string $status  One of statuses().
	 * @return bool
	 */
	public static function set_status( $product, $status ) {
		$all = self::all();

		if ( ! isset( $all[ $product ] ) || ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}

		$all[ $product ]['status'] = $status;

		if ( 'published' === $status && empty( $all[ $product ]['published_at'] ) ) {
			$all[ $product ]['published_at'] = time();
		}

		update_option( self::OPTION_RELEASES, $all, false );

		Audit::record(
			'release.' . $status,
			'release',
			0,
			array(
				'product' => (string) $product,
				'version' => isset( $all[ $product ]['version'] ) ? (string) $all[ $product ]['version'] : '',
			)
		);

		return true;
	}

	/**
	 * Take a release out of distribution. The record and the file both stay.
	 *
	 * Pulling a build is not the same as pretending it never existed: the
	 * withdrawn row is what explains why a customer's site is on an older
	 * version, and the file on disk is the only copy of what was shipped.
	 *
	 * @param string $product Product slug.
	 * @return bool
	 */
	public static function forget( $product ) {
		return self::set_status( $product, 'withdrawn' );
	}

	/**
	 * Take an uploaded zip and put it where the download handler looks.
	 *
	 * The upload is handed to WordPress first so the usual checks (upload size,
	 * tmp dir, MIME, file name) apply, and only then moved into place. Both
	 * directories sit under uploads, so the move is a rename inside one file
	 * system rather than a copy across devices.
	 *
	 * @param array<string, mixed> $file    One entry from $_FILES.
	 * @param string               $product Product slug.
	 * @return array<string, mixed>|\WP_Error file, sha256 and package_size.
	 */
	public static function store_upload( $file, $product ) {
		unset( $product );

		if ( ! isset( $file['tmp_name'], $file['name'] ) || '' === (string) $file['tmp_name'] ) {
			return new \WP_Error( 'at8ls_no_file', __( 'Choose a plugin zip to upload.', 'at8-license-server' ) );
		}

		if ( 'zip' !== strtolower( (string) pathinfo( sanitize_file_name( (string) $file['name'] ), PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'at8ls_not_zip', __( 'The package has to be a .zip file.', 'at8-license-server' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			return new \WP_Error( 'at8ls_fs', __( 'The file system could not be reached.', 'at8-license-server' ) );
		}

		global $wp_filesystem;

		$handled = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			)
		);

		if ( ! is_array( $handled ) || isset( $handled['error'] ) ) {
			return new \WP_Error(
				'at8ls_upload',
				isset( $handled['error'] ) ? (string) $handled['error'] : __( 'The upload failed.', 'at8-license-server' )
			);
		}

		$dir = package_dir();

		if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
			return new \WP_Error( 'at8ls_mkdir', __( 'The packages directory could not be created.', 'at8-license-server' ) );
		}

		self::harden( $dir, $wp_filesystem );

		/*
		 * The stored name carries a random middle part.
		 *
		 * The directory sits under uploads, which a web server serves as
		 * plain files — on Apache the deny file written above stops that, but
		 * nginx ignores .htaccess and will happily hand the zip to anyone who
		 * asks for it by name. A name nobody can guess is what actually closes
		 * that off on every server, and the signed route is then the only way
		 * to obtain the file rather than merely the convenient one.
		 */
		$base   = sanitize_file_name( basename( (string) $handled['file'] ) );
		$stem   = pathinfo( $base, PATHINFO_FILENAME );
		$name   = sanitize_file_name( $stem . '-' . wp_generate_password( 24, false ) . '.zip' );
		$target = $dir . $name;

		if ( ! $wp_filesystem->move( (string) $handled['file'], $target, true ) ) {
			return new \WP_Error( 'at8ls_move', __( 'The uploaded package could not be stored.', 'at8-license-server' ) );
		}

		/*
		 * Fingerprint the file as it lands, while it is known to be the bytes
		 * that were just uploaded. It is checked again on every download, so a
		 * package swapped on disk later is refused rather than shipped.
		 */
		$sha = hash_file( 'sha256', $target );

		return array(
			'file'         => $name,
			'sha256'       => is_string( $sha ) ? $sha : '',
			'package_size' => (int) $wp_filesystem->size( $target ),
		);
	}

	/**
	 * Does this package still match the hash recorded when it was uploaded?
	 *
	 * A release published before hashes were recorded has nothing to compare
	 * against, and is served as it always was — the check adds certainty where
	 * it exists rather than refusing older releases.
	 *
	 * @param string $product  Product slug.
	 * @param string $contents Package bytes.
	 * @return bool
	 */
	public static function verify_hash( $product, $contents ) {
		$release  = self::published( $product );
		$expected = isset( $release['sha256'] ) ? strtolower( (string) $release['sha256'] ) : '';

		if ( '' === $expected ) {
			return true;
		}

		return hash_equals( $expected, hash( 'sha256', (string) $contents ) );
	}

	/**
	 * Stop the packages directory from being fetched over the web.
	 *
	 * The directory sits under uploads, which a web server serves as files —
	 * so without this a release could be downloaded by anyone who guesses its
	 * name, licence or no licence. The download route hands the bytes out
	 * itself after checking a signed token, and it is the only way in once
	 * these two files are in place.
	 *
	 * On nginx the .htaccess is inert; add `location ~* /at8-packages/ { deny
	 * all; }` to the server block for the same effect.
	 *
	 * @param string      $dir           Directory to guard.
	 * @param object|null $wp_filesystem Filesystem to write with; loaded when omitted.
	 * @return bool
	 */
	public static function harden( $dir = '', $wp_filesystem = null ) {
		$dir = '' === $dir ? package_dir() : trailingslashit( (string) $dir );

		if ( null === $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			if ( ! WP_Filesystem() ) {
				return false;
			}

			global $wp_filesystem;
		}

		if ( ! $wp_filesystem ) {
			return false;
		}

		if ( ! $wp_filesystem->is_dir( $dir ) && ! $wp_filesystem->mkdir( $dir, FS_CHMOD_DIR ) ) {
			return false;
		}

		$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n";

		$wp_filesystem->put_contents( $dir . '.htaccess', $rules, FS_CHMOD_FILE );
		$wp_filesystem->put_contents( $dir . 'index.html', '', FS_CHMOD_FILE );

		return true;
	}

	/**
	 * A link the updater can fetch, valid for a while and tied to one licence.
	 *
	 * @param string $product    Product slug.
	 * @param int    $license_id Licence the link is issued to.
	 * @return string Empty string when there is nothing to download.
	 */
	public static function download_url( $product, $license_id ) {
		if ( '' === self::path( $product ) ) {
			return '';
		}

		$expires = time() + self::TOKEN_TTL;
		$payload = (int) $license_id . '|' . $product . '|' . $expires;
		$token   = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ) . '.' . sign( $payload );

		return add_query_arg(
			array( 'token' => $token ),
			rest_url( AT8LS_REST_CANONICAL . '/package' )
		);
	}

	/**
	 * Check a download token.
	 *
	 * @param string $token Token from the query string.
	 * @return array<string, mixed>|null Payload on success.
	 */
	public static function read_token( $token ) {
		$token = (string) $token;

		if ( '' === $token || false === strpos( $token, '.' ) ) {
			return null;
		}

		list( $encoded, $given ) = explode( '.', $token, 2 );

		$padding = strlen( $encoded ) % 4;
		$decoded = base64_decode( strtr( $encoded, '-_', '+/' ) . ( $padding ? str_repeat( '=', 4 - $padding ) : '' ), true );

		if ( false === $decoded ) {
			return null;
		}

		if ( ! signature_ok( sign( $decoded ), $given ) ) {
			return null;
		}

		$parts = explode( '|', $decoded );

		if ( count( $parts ) !== 3 ) {
			return null;
		}

		list( $license_id, $product, $expires ) = $parts;

		if ( (int) $expires < time() ) {
			return null;
		}

		return array(
			'license_id' => (int) $license_id,
			'product'    => (string) $product,
			'expires'    => (int) $expires,
		);
	}
}
