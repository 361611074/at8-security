<?php
/**
 * File integrity monitoring against a stored baseline.
 *
 * The idea is the oldest one in the book, and still the most useful: record a
 * fingerprint of every file the site is made of while you know it is clean,
 * then compare. Anything that changed without an update having been installed
 * is worth a look — and a file that appeared where the site does not write is
 * worth a very close look.
 *
 * Two rules hold throughout:
 *
 * 1. This is read-only. Nothing is ever deleted, quarantined or modified. It
 *    reports; the owner decides.
 * 2. It says what it cannot know. A plugin update legitimately rewrites plugin
 *    files, so "changed" under wp-content/plugins usually means an update, not
 *    an intrusion. The report says so rather than crying wolf.
 *
 * @package At8\Security\Pro
 */

namespace At8\Security\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Baseline builder and comparator.
 */
class Integrity {

	/**
	 * Where the baseline lives.
	 */
	const OPTION = 'at8_security_pro_integrity';

	/**
	 * Where the result of the last comparison lives.
	 */
	const RESULT_OPTION = 'at8_security_pro_integrity_result';

	/**
	 * Files hashed in one run before the scan asks to be continued.
	 *
	 * A ceiling rather than no limit: hashing every file on a very large
	 * install in one request is how a security tool ends up causing a timeout.
	 * What happens when the ceiling is reached matters as much as the ceiling
	 * itself — see scan(), which resumes where the last run stopped rather
	 * than starting again, because a scan that restarts every time never
	 * finishes on a site big enough to hit the limit.
	 */
	const BATCH = 5000;

	/**
	 * Paths that are never part of the comparison.
	 *
	 * Uploads are meant to change, caches are meant to change, and version
	 * control directories are none of this plugin's business.
	 *
	 * @var string[]
	 */
	private static $ignored = array(
		'wp-content/uploads/',
		'wp-content/cache/',
		'wp-content/upgrade/',
		'wp-content/upgrade-temp-backup/',
		'wp-content/ai1wm-backups/',
		'wp-content/backups-dup-pro/',
		'node_modules/',
		'.git/',
		'.svn/',
		'.backups/',
	);

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Nothing runs on a page load: a scan is started from the settings
		// screen and nowhere else.
	}

	/* ---------------------------------------------------------------------
	 * What gets scanned
	 * ------------------------------------------------------------------ */

	/**
	 * The directories and files the comparison covers.
	 *
	 * @return string[] Absolute paths, each ending in a slash for directories.
	 */
	public static function targets() {
		$targets = array(
			'core'    => array(
				'label' => __( 'WordPress core', 'at8-security-pro' ),
				'paths' => array( ABSPATH, trailingslashit( ABSPATH . WPINC ), trailingslashit( ABSPATH . 'wp-admin' ) ),
			),
			'plugins' => array(
				'label' => __( 'Plugins', 'at8-security-pro' ),
				'paths' => array( trailingslashit( WP_PLUGIN_DIR ), trailingslashit( WPMU_PLUGIN_DIR ) ),
			),
			'themes'  => array(
				'label' => __( 'Themes', 'at8-security-pro' ),
				'paths' => array( trailingslashit( get_theme_root() ), trailingslashit( WP_CONTENT_DIR . '/themes' ) ),
			),
		);

		/*
		 * The multi-site plugin directory lives inside the ordinary one, and
		 * the theme root is usually wp-content/themes as well. Hashing the same
		 * file twice costs time and changes nothing, so the duplicates go.
		 */
		foreach ( $targets as $key => $target ) {
			$paths = array();

			foreach ( (array) $target['paths'] as $path ) {
				if ( ! is_dir( $path ) && ! is_file( $path ) ) {
					continue;
				}

				$paths[ str_replace( '\\', '/', $path ) ] = $path;
			}

			$targets[ $key ]['paths'] = array_values( $paths );
		}

		/**
		 * Filter the paths included in the file integrity comparison.
		 *
		 * @param array<string, array{label:string, paths:string[]}> $targets Areas to scan.
		 */
		return (array) apply_filters( 'at8sec_pro_integrity_targets', $targets );
	}

	/**
	 * Is this relative path one to leave alone?
	 *
	 * @param string $relative Path relative to the WordPress root.
	 * @return bool
	 */
	private static function ignored( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );

		foreach ( self::$ignored as $skip ) {
			if ( 0 === strpos( $relative, $skip ) || false !== strpos( '/' . $relative, '/' . $skip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Turn an absolute path into a stable key relative to the site root.
	 *
	 * The root is stripped so the baseline survives the site being moved, and
	 * the result is not tied to whoever the web server runs as.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function key( $path ) {
		$root = str_replace( '\\', '/', ABSPATH );
		$path = str_replace( '\\', '/', (string) $path );

		if ( 0 === strpos( $path, $root ) ) {
			$path = substr( $path, strlen( $root ) );
		}

		return ltrim( $path, '/' );
	}

	/**
	 * Which area a relative path belongs to.
	 *
	 * Used to group the report, because "a core file changed" and "a plugin
	 * file changed" mean quite different things.
	 *
	 * @param string $relative Relative path.
	 * @return string Area key.
	 */
	public static function area( $relative ) {
		$relative = str_replace( '\\', '/', (string) $relative );

		foreach ( array( self::key( WP_PLUGIN_DIR ), self::key( WPMU_PLUGIN_DIR ) ) as $root ) {
			if ( '' !== $root && 0 === strpos( $relative, $root . '/' ) ) {
				return 'plugins';
			}
		}

		foreach ( array( self::key( get_theme_root() ), self::key( WP_CONTENT_DIR . '/themes' ) ) as $root ) {
			if ( '' !== $root && 0 === strpos( $relative, $root . '/' ) ) {
				return 'themes';
			}
		}

		return 'core';
	}

	/**
	 * Collect the relative path of every file in a target.
	 *
	 * Listing and hashing are separate steps on purpose. The listing is cheap
	 * and its order is stable — the directories are walked in a fixed order and
	 * scandir() returns names sorted — which is what lets a capped scan resume
	 * exactly where the previous one stopped instead of starting again.
	 *
	 * @param string   $path Absolute directory or file.
	 * @param string[] $into Relative paths, by reference.
	 * @return void
	 */
	private static function collect( $path, &$into ) {
		if ( is_file( $path ) ) {
			$relative = self::key( $path );

			if ( ! self::ignored( $relative ) ) {
				$into[] = $relative;
			}

			return;
		}

		if ( ! is_dir( $path ) || ! is_readable( $path ) ) {
			return;
		}

		// A RecursiveDirectoryIterator would be tidier and slower; on shared
		// hosting the plain listing is quick and needs no extension.
		$entries = @scandir( $path );

		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full     = trailingslashit( $path ) . $entry;
			$relative = self::key( $full );

			if ( self::ignored( $relative ) ) {
				continue;
			}

			if ( is_link( $full ) ) {
				// A symlink points somewhere the scan does not own.
				continue;
			}

			if ( is_dir( $full ) ) {
				self::collect( $full, $into );
				continue;
			}

			if ( is_file( $full ) && is_readable( $full ) ) {
				$into[] = $relative;
			}
		}
	}

	/**
	 * Every file the comparison covers, in a stable order.
	 *
	 * @return string[] Relative paths.
	 */
	public static function list_files() {
		$paths = array();

		foreach ( self::targets() as $target ) {
			foreach ( (array) $target['paths'] as $path ) {
				self::collect( $path, $paths );
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Hash a slice of the site's files.
	 *
	 * The slice is taken from the stable listing, so a run with an offset of
	 * 5,000 covers files 5,001 onwards. Repeated runs therefore cover the whole
	 * site between them and the baseline converges, which is the property a
	 * capped scan needs and the one an earlier version of this file lacked: it
	 * restarted at the beginning every time, so a site larger than the cap
	 * could never record a complete baseline no matter how often it was run.
	 *
	 * @param int      $offset Start after this many files, in listing order.
	 * @param int|null $limit  Most files to hash, or null for the configured batch.
	 * @return array{files:array<string,string>, complete:bool, total:int, next:int}
	 */
	public static function scan( $offset = 0, $limit = null ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a scan is a deliberate long request; the host may refuse, which is fine.
			@set_time_limit( 0 );
		}

		$offset = max( 0, (int) $offset );
		$limit  = null === $limit ? (int) apply_filters( 'at8sec_pro_integrity_batch', self::BATCH ) : (int) $limit;
		$limit  = max( 200, $limit );

		$all    = self::list_files();
		$total  = count( $all );
		$slice  = array_slice( $all, $offset, $limit );
		$files  = array();

		foreach ( $slice as $relative ) {
			$hash = sha1_file( ABSPATH . $relative );

			if ( false !== $hash ) {
				$files[ $relative ] = $hash;
			}
		}

		$next = $offset + count( $slice );

		return array(
			'files'    => $files,
			'complete' => $next >= $total,
			'total'    => $total,
			'next'     => $next,
		);
	}

	/* ---------------------------------------------------------------------
	 * Baseline
	 * ------------------------------------------------------------------ */

	/**
	 * How far the baseline has got.
	 *
	 * @return array{next:int, total:int, complete:bool}
	 */
	public static function progress() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'next'     => isset( $stored['next'] ) ? (int) $stored['next'] : 0,
			'total'    => isset( $stored['total'] ) ? (int) $stored['total'] : 0,
			'complete' => ! empty( $stored['complete'] ),
		);
	}

	/**
	 * Store the files just hashed as the baseline.
	 *
	 * Compressed before storing: a mid-sized site produces a few hundred
	 * kilobytes of hex, which compresses to a small fraction of that and keeps
	 * one very large row out of the options table. The hash map is highly
	 * repetitive, so this is close to free.
	 *
	 * @param array<string, string> $files    Relative path => sha1.
	 * @param bool                  $complete Whether every file has been hashed.
	 * @param int                   $next     Offset the next run should continue from.
	 * @param int                   $total    Files the listing contains.
	 * @return int Number of files stored.
	 */
	public static function store( $files, $complete = true, $next = 0, $total = 0 ) {
		$record = array(
			'built'    => time(),
			'complete' => (bool) $complete,
			'count'    => count( $files ),
			'next'     => max( 0, (int) $next ),
			'total'    => max( 0, (int) $total ),
			'encoding' => 'plain',
			'data'     => $files,
		);

		if ( function_exists( 'gzcompress' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- internal storage, decoded only by this class.
			$compressed = gzcompress( serialize( $files ), 6 );

			if ( is_string( $compressed ) ) {
				$record['encoding'] = 'gz';
				$record['data']     = base64_encode( $compressed ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- see above.
			}
		}

		update_option( self::OPTION, $record, false );

		return (int) $record['count'];
	}

	/**
	 * Hash the next slice of the site into the baseline.
	 *
	 * One call, one bounded amount of work, and it picks up where the previous
	 * call left off. Calling this until it reports complete always finishes,
	 * whatever the size of the site — which is the property that makes a capped
	 * scan safe to offer at all.
	 *
	 * A baseline that is already complete is rebuilt from the beginning, since
	 * re-checking a fraction of the files against their own recorded hashes
	 * would tell the owner nothing.
	 *
	 * @return array{hashed:int, stored:int, total:int, next:int, complete:bool}
	 */
	public static function step() {
		$progress = self::progress();
		$resuming = ! $progress['complete'];

		$offset = $resuming ? $progress['next'] : 0;
		$files  = $resuming ? self::baseline()['hashes'] : array();

		$scan   = self::scan( $offset );
		$merged = array_merge( $files, $scan['files'] );

		self::store( $merged, $scan['complete'], $scan['next'], $scan['total'] );

		return array(
			'hashed'   => count( $scan['files'] ),
			'stored'   => count( $merged ),
			'total'    => $scan['total'],
			'next'     => $scan['next'],
			'complete' => $scan['complete'],
		);
	}

	/**
	 * The stored baseline, decoded.
	 *
	 * @return array{built:int, complete:bool, hashes:array<string,string>}
	 */
	public static function baseline() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || empty( $stored['data'] ) ) {
			return array(
				'built'    => 0,
				'complete' => false,
				'hashes'   => array(),
			);
		}

		$hashes = array();

		if ( isset( $stored['encoding'] ) && 'gz' === $stored['encoding'] && is_string( $stored['data'] ) ) {
			$raw = base64_decode( $stored['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

			if ( false !== $raw && function_exists( 'gzuncompress' ) ) {
				$uncompressed = @gzuncompress( $raw );
				$decoded      = is_string( $uncompressed ) ? @unserialize( $uncompressed, array( 'allowed_classes' => false ) ) : false;

				if ( is_array( $decoded ) ) {
					$hashes = $decoded;
				}
			}
		} elseif ( is_array( $stored['data'] ) ) {
			$hashes = $stored['data'];
		}

		return array(
			'built'    => isset( $stored['built'] ) ? (int) $stored['built'] : 0,
			'complete' => ! empty( $stored['complete'] ),
			'hashes'   => $hashes,
		);
	}

	/**
	 * Does a baseline exist?
	 *
	 * @return bool
	 */
	public static function has_baseline() {
		return (bool) self::baseline()['hashes'];
	}

	/**
	 * Throw the baseline away.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
		delete_option( self::RESULT_OPTION );
	}

	/* ---------------------------------------------------------------------
	 * Comparison
	 * ------------------------------------------------------------------ */

	/**
	 * Compare the stored baseline with the current files.
	 *
	 * Three lists come out, and each one means something different:
	 * added — a file that was not there when the baseline was taken;
	 * changed — a file whose contents differ;
	 * removed — a file that has since gone.
	 *
	 * Every file is read here, with no batch ceiling. A capped comparison would
	 * be worse than no comparison at all: files the scan never reached are
	 * indistinguishable from files that have been deleted, so the report would
	 * accuse the site of having lost hundreds of files it never looked at.
	 * Taking longer, or failing outright on a host that kills the request, is
	 * the honest outcome.
	 *
	 * @return array{added:string[], changed:string[], removed:string[], complete:bool, at:int}
	 */
	public static function compare() {
		$baseline = self::baseline();

		if ( ! $baseline['hashes'] ) {
			return array(
				'added'    => array(),
				'changed'  => array(),
				'removed'  => array(),
				'complete' => false,
				'at'       => 0,
			);
		}

		$scan = self::scan( 0, PHP_INT_MAX );
		$now  = $scan['files'];

		$added   = array();
		$changed = array();

		foreach ( $now as $relative => $hash ) {
			if ( ! isset( $baseline['hashes'][ $relative ] ) ) {
				$added[] = $relative;
				continue;
			}

			if ( $baseline['hashes'][ $relative ] !== $hash ) {
				$changed[] = $relative;
			}
		}

		$removed = array();

		foreach ( array_keys( $baseline['hashes'] ) as $relative ) {
			if ( ! isset( $now[ $relative ] ) ) {
				$removed[] = $relative;
			}
		}

		sort( $added );
		sort( $changed );
		sort( $removed );

		$result = array(
			'added'    => $added,
			'changed'  => $changed,
			'removed'  => $removed,
			// Both halves have to have been complete for the answer to be
			// trusted: a capped scan compared against a capped baseline would
			// report every file it never reached as "removed".
			'complete' => $scan['complete'] && $baseline['complete'],
			'at'       => time(),
		);

		update_option( self::RESULT_OPTION, $result, false );

		return $result;
	}

	/**
	 * The result of the last comparison.
	 *
	 * @return array{added:string[], changed:string[], removed:string[], complete:bool, at:int}
	 */
	public static function last_result() {
		$stored = get_option( self::RESULT_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$result = wp_parse_args(
			$stored,
			array(
				'added'    => array(),
				'changed'  => array(),
				'removed'  => array(),
				'complete' => false,
				'at'       => 0,
			)
		);

		foreach ( array( 'added', 'changed', 'removed' ) as $key ) {
			$result[ $key ] = array_values( array_filter( array_map( 'strval', (array) $result[ $key ] ) ) );
		}

		$result['complete'] = (bool) $result['complete'];
		$result['at']       = (int) $result['at'];

		return $result;
	}
}
