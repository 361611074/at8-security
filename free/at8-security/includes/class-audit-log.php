<?php
/**
 * Audit log: sign-ins, lockouts and settings changes, stored in a custom table.
 *
 * @package At8\Security
 */

namespace At8\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Module 8 — audit log.
 */
class Audit_Log {

	const CRON_HOOK = 'at8sec_audit_purge';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'at8sec_audit_log';
	}

	/**
	 * Create or update the log table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			event varchar(40) NOT NULL DEFAULT '',
			user_login varchar(60) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			detail varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY event (event),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// The daily cleanup follows the feature switch, so turning logging off
		// also stops the scheduled job from waking the site up.
		add_action( 'init', array( $this, 'sync_schedule' ), 20 );

		if ( ! Settings::on( 'audit_log' ) ) {
			return;
		}

		add_action( 'wp_login_failed', array( $this, 'log_failure' ), 5 );
		add_action( 'wp_login', array( $this, 'log_success' ), 5, 2 );
		add_action( 'at8sec_login_locked', array( $this, 'log_lockout' ), 10, 2 );
		add_action( 'update_option_' . AT8SEC_OPTION, array( $this, 'log_settings_change' ), 10, 2 );
		add_action( self::CRON_HOOK, array( $this, 'purge' ) );
	}

	/**
	 * Schedule — or unschedule — the daily cleanup.
	 *
	 * @return void
	 */
	public function sync_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( Settings::on( 'audit_log' ) ) {
			if ( ! $scheduled ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}

			return;
		}

		while ( $scheduled ) {
			wp_unschedule_event( $scheduled, self::CRON_HOOK );
			$scheduled = wp_next_scheduled( self::CRON_HOOK );
		}
	}

	/**
	 * Append an entry.
	 *
	 * @param string $event      Short event key.
	 * @param string $detail     Free-form detail.
	 * @param string $user_login Related user, when known.
	 * @return void
	 */
	public function add( $event, $detail = '', $user_login = '' ) {
		global $wpdb;

		/*
		 * A direct write to this plugin's own table. Object caching does not
		 * apply: this is the audit trail, and serving it from a stale cache
		 * would defeat the point of having one.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; the log must never come from a cache.
		$wpdb->insert(
			self::table(),
			array(
				// Stored in UTC so the log stays consistent if the site timezone changes.
				'created_at' => current_time( 'mysql', true ),
				'event'      => substr( (string) $event, 0, 40 ),
				'user_login' => substr( (string) $user_login, 0, 60 ),
				'ip'         => substr( get_client_ip(), 0, 45 ),
				'detail'     => substr( (string) $detail, 0, 255 ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Log a failed sign-in.
	 *
	 * @param string $username Submitted username.
	 * @return void
	 */
	public function log_failure( $username ) {
		$this->add( 'login_failed', '', (string) $username );
	}

	/**
	 * Log a successful sign-in and remember the timestamp.
	 *
	 * @param string         $user_login Username.
	 * @param \WP_User|mixed $user       User object.
	 * @return void
	 */
	public function log_success( $user_login, $user = null ) {
		$this->add( 'login_success', '', (string) $user_login );

		if ( $user instanceof \WP_User ) {
			update_user_meta( $user->ID, 'at8sec_last_login', current_time( 'mysql', true ) );
		}
	}

	/**
	 * Log an address being locked out.
	 *
	 * @param string $ip    Visitor address.
	 * @param int    $minutes Lockout length.
	 * @return void
	 */
	public function log_lockout( $ip, $minutes ) {
		$this->add(
			'login_locked',
			sprintf(
				/* translators: %d: number of minutes. */
				__( 'Locked for %d minute(s).', 'at8-security' ),
				(int) $minutes
			)
		);

		unset( $ip );
	}

	/**
	 * Log a settings change, listing which keys moved.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function log_settings_change( $old_value, $new_value ) {
		if ( ! is_array( $new_value ) ) {
			return;
		}

		$old    = is_array( $old_value ) ? $old_value : array();
		$changed = array();

		foreach ( $new_value as $key => $value ) {
			$previous = isset( $old[ $key ] ) ? $old[ $key ] : null;

			if ( $previous !== $value && ! ( is_array( $previous ) && is_array( $value ) && $previous === $value ) ) {
				$changed[] = $key;
			}
		}

		if ( empty( $changed ) ) {
			return;
		}

		$user = wp_get_current_user();

		$this->add(
			'settings_saved',
			implode( ', ', array_slice( $changed, 0, 20 ) ),
			$user instanceof \WP_User ? $user->user_login : ''
		);
	}

	/**
	 * Delete entries older than the retention window.
	 *
	 * @return void
	 */
	public function purge() {
		global $wpdb;

		$days = max( 1, (int) Settings::get( 'audit_log_days', 30 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; a cleanup has nothing to cache.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < %s',
				self::table(),
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Remove every entry.
	 *
	 * @return void
	 */
	public function clear() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Most recent entries.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return object[]
	 */
	public function recent( $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 200, (int) $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; the log must never come from a cache.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit )
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of stored entries.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
	}
}
