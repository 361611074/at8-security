<?php
/**
 * Storage: the two tables this server owns.
 *
 * Custom tables rather than a post type, because a licence is not content:
 * it is looked up by key on every activate call and never rendered, so it
 * belongs in a table with an index on the key.
 *
 * @package At8\License
 */

namespace At8\License;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema management.
 */
class Db {

	const DB_VERSION        = '3';
	const OPTION_DB_VERSION = 'at8ls_db_version';

	/**
	 * Create tables, and add the columns a newer version introduced.
	 *
	 * dbDelta() is additive on its own: a table or column that is already there
	 * is left alone, a missing one is created. That is what makes the upgrade
	 * path safe — no DROP, no rename, and a site that updates mid-flight keeps
	 * every licence it already issued.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$names    = tables();
		$collate  = $wpdb->get_charset_collate();
		$suffixes = array();

		/*
		 * Column note, because two of these names no longer mean what they say.
		 *
		 * `key_norm` holds key_hash() — the HMAC the key is looked up by — and
		 * carries the unique index, so a duplicate can never be issued.
		 * `key_hint` holds the last five characters, for telling keys apart on
		 * screen. `license_key` is retired: it held the key in the clear, and
		 * migrate_keys() blanks it. It is kept rather than dropped because
		 * dbDelta() does not remove columns, and a column that still exists but
		 * is always empty is easier to reason about than one that half exists.
		 */
		$suffixes[] = "CREATE TABLE {$names['licenses']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_key varchar(64) NOT NULL DEFAULT '',
			key_norm varchar(64) NOT NULL DEFAULT '',
			key_hint varchar(12) NOT NULL DEFAULT '',
			product varchar(64) NOT NULL DEFAULT '',
			plan varchar(32) NOT NULL DEFAULT 'lifetime',
			status varchar(20) NOT NULL DEFAULT 'active',
			expires bigint(20) unsigned NOT NULL DEFAULT 0,
			max_sites int(11) NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY key_norm (key_norm),
			KEY product (product),
			KEY status (status)
		) {$collate};";

		$suffixes[] = "CREATE TABLE {$names['sites']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_id bigint(20) unsigned NOT NULL,
			domain varchar(191) NOT NULL DEFAULT '',
			site_url varchar(255) NOT NULL DEFAULT '',
			home_url varchar(255) NOT NULL DEFAULT '',
			site_hash char(32) NOT NULL DEFAULT '',
			environment varchar(20) NOT NULL DEFAULT 'production',
			activated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
			last_ip varchar(45) NOT NULL DEFAULT '',
			wp_version varchar(20) NOT NULL DEFAULT '',
			php_version varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY license_domain (license_id,domain),
			KEY license_id (license_id),
			KEY environment (environment)
		) {$collate};";

		$suffixes[] = "CREATE TABLE {$names['audit']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_ip varchar(45) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL DEFAULT '',
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			metadata text NOT NULL,
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY action (action),
			KEY object_lookup (object_type,object_id),
			KEY created_at (created_at)
		) {$collate};";

		foreach ( $suffixes as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Are the tables present?
	 *
	 * @return bool
	 */
	public static function ready() {
		global $wpdb;

		$names = tables();

		foreach ( array( $names['licenses'], $names['sites'], $names['audit'] ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Install the tables if this install has never been through activation.
	 *
	 * The version option is the cheap check, and it is the only one that runs on
	 * an ordinary request: looking for the tables themselves means two SHOW
	 * TABLES queries every time a client calls the licence endpoint, and the
	 * activation hook already covers the normal path.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
		self::migrate_keys();
	}

	/**
	 * Move licence keys that are still stored in the clear into the hashed form.
	 *
	 * Rows written before DB_VERSION 3 hold the key twice: once readable in
	 * `license_key`, once normalised in `key_norm`. Both are the key. The
	 * migration hashes what it finds, records the tail for display, and blanks
	 * the readable column, so the table stops being a list of working keys.
	 *
	 * It reads from `license_key` rather than `key_norm` because the hash has
	 * to be taken from the key as printed — normalising first and hashing after
	 * is what key_hash() does internally, so either would work, but the printed
	 * form is the one an operator could have recovered by hand.
	 *
	 * Rows that already have a blank `license_key` are skipped, which is what
	 * makes this safe to run twice. A row whose key_norm already holds a hash
	 * cannot collide: the old values were normalised keys (26 characters, the
	 * AT8 prefix plus twenty), the new ones are 64 hex characters, and the
	 * unique index was already satisfied by the values being replaced.
	 *
	 * @return int Number of rows converted.
	 */
	public static function migrate_keys() {
		global $wpdb;

		$names = tables();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, license_key FROM %i WHERE license_key <> %s',
				$names['licenses'],
				''
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || ! $rows ) {
			return 0;
		}

		$done = 0;

		foreach ( $rows as $row ) {
			$plain = (string) $row['license_key'];

			if ( '' === $plain ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->update(
				$names['licenses'],
				array(
					'key_norm'    => key_hash( $plain ),
					'key_hint'    => key_hint( $plain ),
					'license_key' => '',
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $ok ) {
				$done++;
			}
		}

		return $done;
	}
}
