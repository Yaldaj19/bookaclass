<?php
/**
 * Plugin Name:       Cooking School Booking
 * Plugin URI:        https://github.com/
 * Description:       یک افزونه‌ی WooCommerce برای رزرو کلاس‌ها و فروش ویدیوهای آموزشگاه آشپزی.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Developer
 * Text Domain:       csb
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'CSB_VERSION',    '0.2.0' );
define( 'CSB_DB_VERSION', 5 );
define( 'CSB_FILE',       __FILE__ );
define( 'CSB_PATH',       plugin_dir_path( __FILE__ ) );
define( 'CSB_URL',        plugin_dir_url( __FILE__ ) );

// ─── Autoloader ───────────────────────────────────────────────────────────────
// Try Composer first; fall back to a simple PSR-4 resolver so the plugin works
// without running `composer install`.
$composer_autoload = CSB_PATH . 'vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
	require_once $composer_autoload;
} else {
	spl_autoload_register( function ( string $class ): void {
		$prefix = 'CookingSchoolBooking\\';
		$len    = strlen( $prefix );

		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative = substr( $class, $len );
		$file     = CSB_PATH . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	} );
}

// ─── Custom cron schedules ────────────────────────────────────────────────────
// Adds a 5-minute interval used by the seat-lock expiry cron.
add_filter( 'cron_schedules', function ( array $schedules ): array {
	if ( ! isset( $schedules['csb_every_5min'] ) ) {
		$schedules['csb_every_5min'] = [
			'interval' => 300,
			'display'  => esc_html__( 'هر ۵ دقیقه (CSB)', 'csb' ),
		];
	}
	return $schedules;
} );

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook( CSB_FILE, function (): void {
	CookingSchoolBooking\Install\Migrator::install();

	// Schedule recurring crons if not already scheduled.
	if ( ! wp_next_scheduled( 'csb_release_locks' ) ) {
		wp_schedule_event( time(), 'csb_every_5min', 'csb_release_locks' );
	}
	if ( ! wp_next_scheduled( 'csb_check_min_to_run' ) ) {
		wp_schedule_event( time(), 'hourly', 'csb_check_min_to_run' );
	}
	if ( ! wp_next_scheduled( 'csb_send_reminders' ) ) {
		wp_schedule_event( time(), 'hourly', 'csb_send_reminders' );
	}
	if ( ! wp_next_scheduled( 'csb_expire_unsettled' ) ) {
		wp_schedule_event( time(), 'hourly', 'csb_expire_unsettled' );
	}
	if ( ! wp_next_scheduled( 'csb_mark_completed' ) ) {
		wp_schedule_event( time(), 'hourly', 'csb_mark_completed' );
	}
	if ( ! wp_next_scheduled( 'csb_purge_otp' ) ) {
		wp_schedule_event( time(), 'daily', 'csb_purge_otp' );
	}

	// Flush rewrite rules so custom URL bases take effect immediately.
	flush_rewrite_rules( false );
} );

register_deactivation_hook( CSB_FILE, function (): void {
	// Clear all plugin-registered cron events.
	$hooks = [
		'csb_release_locks',
		'csb_check_min_to_run',
		'csb_send_reminders',
		'csb_expire_unsettled',
		'csb_mark_completed',
		'csb_purge_otp',
	];

	foreach ( $hooks as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
} );

// ─── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {
	CookingSchoolBooking\Plugin::instance();
} );
