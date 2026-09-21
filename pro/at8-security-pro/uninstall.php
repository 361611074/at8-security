<?php
/**
 * Uninstall routine: remove everything this add-on stored.
 *
 * Only runs when the plugin is deleted from the Plugins screen, never on
 * deactivation — deactivating keeps the settings and the licence key, so
 * switching the add-on back on puts the site back exactly as it was.
 *
 * @package At8\Security\Pro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clear this add-on's data from one site.
 *
 * Written as a function because a network install runs it once per site: the
 * licence is bound to a single domain, so each site has its own record to
 * remove and clearing only the main one would leave keys behind on the rest.
 *
 * @return void
 */
function at8sec_pro_uninstall_site() {
	global $wpdb;

	// Options.
	delete_option( 'at8_security_pro_settings' );
	delete_option( 'at8_security_pro_license' );
	delete_option( 'at8_security_pro_cdn' );
	delete_option( 'at8_security_pro_integrity' );
	delete_option( 'at8_security_pro_integrity_result' );
	delete_option( 'at8sec_pro_version' );

	/*
	 * Transients, including the private ones WordPress keeps: the update
	 * answer, the per-user notices, the recovery codes held for their single
	 * showing and the activation flag. The timeout rows have to go as well, or
	 * they are left behind as orphans.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk removal during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_at8sec_pro_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_at8sec_pro_' ) . '%'
		)
	);

	// Per-user data: the two-factor secret, its state, the recovery codes and
	// the last-seen date. All of it belongs to this plugin and none of it is
	// any use without it.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk removal during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->usermeta,
			$wpdb->esc_like( 'at8sec_pro_' ) . '%'
		)
	);

	// The scheduled licence check.
	wp_clear_scheduled_hook( 'at8sec_pro_license_check' );
}

if ( is_multisite() ) {
	$at8sec_pro_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $at8sec_pro_sites as $at8sec_pro_site_id ) {
		switch_to_blog( (int) $at8sec_pro_site_id );
		at8sec_pro_uninstall_site();
		restore_current_blog();
	}
} else {
	at8sec_pro_uninstall_site();
}
