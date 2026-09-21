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

	const DB_VERSION        = '1';
	const OPTION_DB_VERSION = 'at8ls_db_version';

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$names    = tables();
		$collate  = $wpdb->get_charset_collate();
		$suffixes = array();

		$suffixes[] = "CREATE TABLE {$names['licenses']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_key varchar(64) NOT NULL,
			key_norm varchar(64) NOT NULL,
			product varchar(64) NOT NULL DEFAULT '',
			plan varchar(32) NOT NULL DEFAULT 'lifetime',
			status varchar(20) NOT NULL DEFAULT 'active',
			expires bigint(20) unsigned NOT NULL DEFAULT 0,
			max_sites int(11) NOT NULL DEFAULT 1,
			note varchar(255) NOT NULL DEFAULT '',
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY key_norm (key_norm),
			KEY product (product),
			KEY status (status)
		) {$collate};";

		$suffixes[] = "CREATE TABLE {$names['sites']} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			license_id bigint(20) unsigned NOT NULL,
			domain varchar(191) NOT NULL DEFAULT '',
			activated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
			last_ip varchar(45) NOT NULL DEFAULT '',
			wp_version varchar(20) NOT NULL DEFAULT '',
			php_version varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY license_domain (license_id,domain),
			KEY license_id (license_id)
		) {$collate};";

		foreach ( $suffixes as $sql ) {
			dbDelta( $sql );
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Are both tables present?
	 *
	 * @return bool
	 */
	public static function ready() {
		global $wpdb;

		$names = tables();

		foreach ( array( $names['licenses'], $names['sites'] ) as $table ) {
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
	}
}
