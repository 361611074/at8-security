<?php
/**
 * Uninstall routine: remove everything this plugin stored.
 *
 * Only runs when the plugin is deleted from the Plugins screen, not when it is
 * merely deactivated — deactivation keeps the settings so the site behaves the
 * same if it is switched back on.
 *
 * @package At8\Security
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Options.
delete_option( 'at8_security_settings' );
delete_option( 'at8sec_version' );
delete_option( 'at8sec_htaccess_fingerprint' );
delete_option( 'at8sec_uploads_fingerprint' );

/*
 * Transients: the per-token CAPTCHA values, the login and rate-limit counters
 * and the .htaccess retry flag all live under the "at8sec_" prefix. The
 * timeout rows have to go too, or they would be left behind as orphans.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk removal during uninstall.
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_at8sec_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_at8sec_' ) . '%'
	)
);

// User meta written by the audit log and the new-sign-in notice.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk removal during uninstall.
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE meta_key LIKE %s',
		$wpdb->usermeta,
		$wpdb->esc_like( 'at8sec_' ) . '%'
	)
);

// The audit table this plugin created and owns.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table while it is being deleted.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'at8sec_audit_log' ) );

// Scheduled cleanup job.
wp_clear_scheduled_hook( 'at8sec_audit_purge' );

// The .htaccess blocks, if any were written: the document root and uploads.
if ( ! function_exists( 'insert_with_markers' ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
}

if ( function_exists( 'insert_with_markers' ) ) {
	insert_with_markers( ABSPATH . '.htaccess', 'At8 Security', array() );

	$at8sec_uploads = wp_get_upload_dir();
	$at8sec_basedir = isset( $at8sec_uploads['basedir'] ) ? (string) $at8sec_uploads['basedir'] : '';

	if ( '' !== $at8sec_basedir && is_dir( $at8sec_basedir ) ) {
		insert_with_markers( trailingslashit( $at8sec_basedir ) . '.htaccess', 'At8 Security Uploads', array() );
	}
}
