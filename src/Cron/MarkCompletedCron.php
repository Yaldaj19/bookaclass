<?php
/**
 * Cron: mark confirmed bookings as completed after the session ends.
 *
 * Hook:  csb_mark_completed  (hourly)
 *
 * For each confirmed booking where the linked session's start_at + duration_min
 * is in the past:
 *   1. Transitions the booking to 'completed'.
 *   2. Writes an audit log entry.
 *
 * @package CookingSchoolBooking\Cron
 */

namespace CookingSchoolBooking\Cron;

use CookingSchoolBooking\Services\AuditLogger;
use CookingSchoolBooking\Services\BookingService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MarkCompletedCron
 */
class MarkCompletedCron {

	public function init(): void {
		add_action( 'csb_mark_completed', [ $this, 'run' ] );
	}

	/**
	 * Main cron callback.
	 */
	public function run(): void {
		global $wpdb;

		// Fetch confirmed bookings whose session has already ended
		// (start_at + duration_min minutes <= UTC_TIMESTAMP()).
		$bookings = $wpdb->get_results(
			"SELECT b.id, b.wc_order_id, s.start_at, s.duration_min
			   FROM {$wpdb->prefix}csb_bookings b
			   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
			  WHERE b.status = 'confirmed'
			    AND DATE_ADD(s.start_at, INTERVAL s.duration_min MINUTE) <= UTC_TIMESTAMP()",
			ARRAY_A
		);

		if ( ! $bookings ) {
			return;
		}

		foreach ( $bookings as $booking ) {
			$booking_id = (int) $booking['id'];

			try {
				BookingService::transition( $booking_id, 'completed', [ 'reason' => 'session_ended' ] );
			} catch ( \InvalidArgumentException | \RuntimeException $e ) {
				AuditLogger::log(
					'mark_completed_error',
					'booking',
					$booking_id,
					[ 'error' => $e->getMessage() ]
				);
				continue;
			}

			// Update linked session status to 'completed' if all bookings are done.
			// The session itself is left to the admin/future cron for full coverage.
		}
	}
}
