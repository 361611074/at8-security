<?php
/**
 * Audit log: who did what to which licence.
 *
 * A licence server is a commercial record. When a customer writes in saying
 * "my key stopped working", the answer has to be findable — was it revoked,
 * when, by whom, from where. That is what this table is for.
 *
 * Two rules are enforced on the way in, because a log that leaks is worse than
 * no log at all:
 *
 *   1. A licence key is never written here. Entries carry the licence id, and
 *      the metadata array drops anything that looks like a key.
 *   2. Values are scalar. A nested structure is dropped rather than serialised
 *      wholesale, so a future caller cannot accidentally dump a whole row.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads the audit trail.
 */
class Audit {

	const OPTION_RETENTION   = 'at8ls_audit_days';
	const DEFAULT_RETENTION  = 180;
	const MAX_METADATA_CHARS = 2000;

	/**
	 * Note something that happened.
	 *
	 * @param string               $action      Dotted event name, e.g. license.created.
	 * @param string               $object_type Object kind: license, site, release.
	 * @param int                  $object_id   Object id, 0 when not applicable.
	 * @param array<string, mixed> $metadata    Extra scalars; keys are never stored.
	 * @return void
	 */
	public static function record( $action, $object_type = '', $object_id = 0, $metadata = array() ) {
		global $wpdb;

		$names = tables();

		$data = array(
			'actor_id'    => get_current_user_id(),
			'actor_ip'    => client_ip(),
			'action'      => self::clean_action( $action ),
			'object_type' => self::clean_action( $object_type ),
			'object_id'   => max( 0, (int) $object_id ),
			'metadata'    => self::encode( $metadata ),
			'created_at'  => time(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$names['audit'],
			$data,
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%d' )
		);
	}

	/**
	 * The newest entries, for the admin screen.
	 *
	 * @param int $limit How many to return.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		$names = tables();
		$limit = max( 1, min( 200, (int) $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $names['audit'], $limit ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many entries are stored.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $names['audit'] ) );

		return (int) $count;
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $names['audit'] ) );
	}

	/**
	 * How long an entry is kept.
	 *
	 * @return int Days, never below 1.
	 */
	public static function retention_days() {
		$days = (int) get_option( self::OPTION_RETENTION, self::DEFAULT_RETENTION );

		return max( 1, $days );
	}

	/**
	 * Drop entries older than the retention window.
	 *
	 * Runs daily on cron. Without it a table written on every activation would
	 * grow without anyone noticing until it was the largest thing in the
	 * database — the same reasoning as the free plugin's own log.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_expired() {
		global $wpdb;

		$names = tables();
		$cutoff = time() - ( self::retention_days() * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %d', $names['audit'], $cutoff )
		);

		return (int) $removed;
	}

	/**
	 * Event name, stripped of anything that could break a query or a display.
	 *
	 * @param string $action Raw name.
	 * @return string
	 */
	private static function clean_action( $action ) {
		$action = strtolower( preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $action ) );
		$action = ltrim( (string) $action, '.' );

		return substr( (string) $action, 0, 64 );
	}

	/**
	 * Turn metadata into JSON, keeping only scalars and never a key.
	 *
	 * @param array<string, mixed> $metadata Raw metadata.
	 * @return string
	 */
	private static function encode( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return '';
		}

		$clean = array();

		foreach ( $metadata as $name => $value ) {
			$name = self::clean_action( $name );

			if ( '' === $name || ! is_scalar( $value ) || self::is_secret( $name ) ) {
				continue;
			}

			$clean[ $name ] = is_bool( $value ) ? (int) $value : (string) $value;
		}

		if ( empty( $clean ) ) {
			return '';
		}

		$json = wp_json_encode( $clean );

		if ( ! is_string( $json ) ) {
			return '';
		}

		return substr( $json, 0, self::MAX_METADATA_CHARS );
	}

	/**
	 * Does this field name look like a secret?
	 *
	 * A deny list rather than a length check: the one thing this log must never
	 * contain is a working licence key, and a caller that later adds a field
	 * named "key" or "token" should be refused automatically rather than
	 * trusted to remember.
	 *
	 * @param string $name Field name.
	 * @return bool
	 */
	private static function is_secret( $name ) {
		if ( 'license_id' === $name || 'max_sites' === $name ) {
			return false;
		}

		foreach ( array( 'key', 'secret', 'token', 'password', 'passwd', 'nonce', 'hash', 'signature' ) as $needle ) {
			if ( false !== strpos( $name, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
