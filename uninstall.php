<?php
/**
 * Uninstall routine for BookaClass.
 *
 * Fires when the user deletes the plugin from the WordPress admin.
 * Data is dropped ONLY when the operator has explicitly opted in via
 * csb_settings['delete_data_on_uninstall'] — safe by default.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ─── Options / Transients ─────────────────────────────────────────────────────
delete_option( 'csb_version' );
delete_option( 'csb_db_version' );
delete_transient( 'csb_activation_redirect' );

// ─── Cron events ──────────────────────────────────────────────────────────────
$cron_hooks = [
	'csb_release_locks',
	'csb_send_reminders',
	'csb_expire_unsettled',
	'csb_check_min_to_run',
	'csb_mark_completed',
];

foreach ( $cron_hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}

// ─── Tables — only when explicitly enabled ────────────────────────────────────
$settings = get_option( 'csb_settings', [] );
$delete   = isset( $settings['delete_data_on_uninstall'] )
	? (bool) $settings['delete_data_on_uninstall']
	: false; // default: safe — do NOT drop tables.

if ( $delete ) {
	global $wpdb;

	// Drop in reverse dependency order.
	$tables = [
			$wpdb->prefix . 'csb_audit_log',
			$wpdb->prefix . 'csb_reschedule_requests',
			$wpdb->prefix . 'csb_attendance',
			$wpdb->prefix . 'csb_certificates',
			$wpdb->prefix . 'csb_otp_tokens',
			$wpdb->prefix . 'csb_coupons',
			$wpdb->prefix . 'csb_video_tokens',
			$wpdb->prefix . 'csb_refund_requests',
			$wpdb->prefix . 'csb_wallet_txns',
			$wpdb->prefix . 'csb_enrollments',
			$wpdb->prefix . 'csb_bookings',
			$wpdb->prefix . 'csb_seat_locks',
			$wpdb->prefix . 'csb_sessions',
		];

	foreach ( $tables as $table ) {
		// Table names are hardcoded above — no user input, no prepare needed.
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	delete_option( 'csb_settings' );
	delete_option( 'csb_cpt_config' );
	delete_option( 'csb_seo_config' );
		delete_option( 'csb_style' );
		delete_option( 'csb_slug_redirects' );
}
