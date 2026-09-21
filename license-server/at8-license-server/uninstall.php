<?php
/**
 * Cleanup when the plugin is deleted from the site.
 *
 * Licences live in their own tables, so they are dropped here, together with
 * the options this plugin wrote and the rate-limit transients it left behind.
 * On multisite every site is visited, because a network install keeps one set
 * of tables per site.
 *
 * @package At8\License
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove everything this plugin stored for one site.
 *
 * @return void
 */
function at8ls_uninstall_site() {
	global $wpdb;

	$options = array(
		'at8ls_db_version',
		'at8ls_releases',
		'at8_license_server_settings',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients are named per caller, so a list of names will not do.
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

	foreach ( array( $wpdb->prefix . 'at8_license_sites', $wpdb->prefix . 'at8_licenses' ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}

if ( is_multisite() ) {
	$at8ls_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $at8ls_sites as $at8ls_site_id ) {
		switch_to_blog( (int) $at8ls_site_id );
		at8ls_uninstall_site();
		restore_current_blog();
	}
} else {
	at8ls_uninstall_site();
}
