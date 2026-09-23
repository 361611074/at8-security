<?php
/**
 * What happens when the plugin is deleted from the site.
 *
 * Licences, site bindings and the audit trail are commercial records: they are
 * the proof of what was sold, to whom, and when it stopped. Deleting the
 * WordPress plugin is a routine act — a staging site being torn down, a plugin
 * being reinstalled to clear a bad upgrade — and it should not destroy that.
 *
 * So the records are kept by default, and removed only when the site owner has
 * said so explicitly, either with the switch on the Audit log screen or by
 * defining AT8LS_PURGE_ON_UNINSTALL in wp-config.php. The transients and the
 * scheduled job are cleaned up either way, because those are cache and
 * therefore disposable.
 *
 * @package At8\License
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove everything this plugin stored for one site.
 *
 * @param bool $purge Whether the commercial records should go too.
 * @return void
 */
function at8ls_uninstall_site( $purge ) {
	global $wpdb;

	$options = array( 'at8ls_db_version', 'at8ls_releases' );

	if ( $purge ) {
		$options[] = 'at8_license_server_settings';
		$options[] = 'at8ls_audit_days';
		$options[] = 'at8ls_purge_on_uninstall';
	}

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	/*
	 * Transients are cache, and are always cleared. They are named per caller,
	 * so a list of names will not do.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$names = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_at8ls_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_at8ls_' ) . '%'
		)
	);

	if ( is_array( $names ) ) {
		foreach ( $names as $name ) {
			$option = (string) $name;

			if ( 0 === strpos( $option, '_transient_timeout_' ) ) {
				continue;
			}

			$key = preg_replace( '/^_transient_/', '', $option );

			if ( is_string( $key ) ) {
				delete_transient( $key );
			}
		}
	}

	if ( ! $purge ) {
		return;
	}

	foreach ( array( $wpdb->prefix . 'at8_license_sites', $wpdb->prefix . 'at8_licenses', $wpdb->prefix . 'at8_audit_logs' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}

/**
 * Should the records be removed?
 *
 * A constant wins over the stored switch, so the decision can be made for a
 * specific site in wp-config.php without touching the admin.
 *
 * @return bool
 */
function at8ls_should_purge() {
	if ( defined( 'AT8LS_PURGE_ON_UNINSTALL' ) ) {
		return (bool) AT8LS_PURGE_ON_UNINSTALL;
	}

	return (bool) get_option( 'at8ls_purge_on_uninstall', 0 );
}

$at8ls_purge = at8ls_should_purge();

if ( is_multisite() ) {
	$at8ls_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $at8ls_sites as $at8ls_site_id ) {
		switch_to_blog( (int) $at8ls_site_id );
		at8ls_uninstall_site( $at8ls_purge );
		restore_current_blog();
	}
} else {
	at8ls_uninstall_site( $at8ls_purge );
}
