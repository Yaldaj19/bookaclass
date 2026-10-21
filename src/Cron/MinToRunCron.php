<?php
/**
 * Cron handler: check minimum enrolment threshold (min_to_run).
 *
 * Fires on the `csb_check_min_to_run` WP-Cron event, scheduled hourly.
 *
 * Logic:
 *   For each session whose start_at is between NOW and NOW + threshold_hours,
 *   if seats_taken < min_to_run and min_to_run > 0:
 *     — Set session status = 'cancelled'.
 *     — For every confirmed booking on that session, trigger a full refund
 *       (actual refund order creation is Phase 4; here we mark status and log).
 *     — Log the cancellation.
 *
 * The threshold_hours (default 24) is configurable via csb_settings option.
 *
 * @package CookingSchoolBooking\Cron
 */

namespace CookingSchoolBooking\Cron;

use CookingSchoolBooking\Services\AuditLogger;
use CookingSchoolBooking\Services\RefundService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MinToRunCron
 */
class MinToRunCron {

	public function init(): void {
		add_action( 'csb_check_min_to_run', [ $this, 'run' ] );
	}

	/**
	 * Checks all upcoming sessions and cancels those that won't reach quorum.
	 */
	public function run(): void {
		global $wpdb;

		$settings        = (array) get_option( 'csb_settings', [] );
		$threshold_hours = isset( $settings['min_to_run_hours'] )
			? max( 1, (int) $settings['min_to_run_hours'] )
			: 24;

		// Sessions starting within the threshold window that haven't been acted on.
		$sessions = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, class_id, seats_taken, min_to_run, start_at
			   FROM {$wpdb->prefix}csb_sessions
			  WHERE status = 'scheduled'
			    AND min_to_run > 0
			    AND start_at BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d HOUR)
			    AND seats_taken < min_to_run",
			$threshold_hours
		), ARRAY_A );

		if ( empty( $sessions ) ) {
			return;
		}

		foreach ( $sessions as $session ) {
			$session_id = (int) $session['id'];

			// Mark session as cancelled.
			$wpdb->update(
				$wpdb->prefix . 'csb_sessions',
				[ 'status' => 'cancelled' ],
				[ 'id'     => $session_id ],
				[ '%s' ],
				[ '%d' ]
			);

			// Find all non-cancelled bookings tied to this session.
			$bookings = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, wc_order_id, student_id, status
				   FROM {$wpdb->prefix}csb_bookings
				  WHERE session_id = %d
				    AND status NOT IN ('cancelled','expired')",
				$session_id
			), ARRAY_A );

			foreach ( $bookings as $booking ) {
				$booking_id = (int) $booking['id'];

				// BookingService::cancel() calls CapacityService::release_seat() internally
				// for deposit_paid|fully_paid|confirmed bookings — no separate decrement needed.
				try {
					\CookingSchoolBooking\Services\BookingService::cancel(
						$booking_id,
						'min_to_run_not_met',
						'',
						(int) $booking['wc_order_id']
					);
				} catch ( \InvalidArgumentException | \RuntimeException $e ) {
					AuditLogger::log( 'min_to_run_cancel_error', 'booking', $booking_id,
						[ 'error' => $e->getMessage() ]
					);
					continue;
				}

				// Auto-approved full refund (school cancellation).
				RefundService::request_refund(
					$booking_id,
					'system',
					'wallet',
					0,
					true // auto_approve
				);

				$order = wc_get_order( (int) $booking['wc_order_id'] );
				if ( $order ) {
					$order->add_order_note( sprintf(
						/* translators: %d: session ID */
						esc_html__( 'جلسه #%d به دلیل نرسیدن به حدنصاب لغو شد. بازگشت وجه خودکار اعمال می‌شود.', 'csb' ),
						$session_id
					) );
				}

				AuditLogger::log(
					'booking_cancelled_min_to_run',
					'booking',
					$booking_id,
					[
						'session_id'  => $session_id,
						'seats_taken' => (int) $session['seats_taken'],
						'min_to_run'  => (int) $session['min_to_run'],
					]
				);
			}

			AuditLogger::log(
				'session_cancelled_min_to_run',
				'session',
				$session_id,
				[
					'class_id'    => (int) $session['class_id'],
					'seats_taken' => (int) $session['seats_taken'],
					'min_to_run'  => (int) $session['min_to_run'],
					'start_at'    => $session['start_at'],
				]
			);
		}
	}
}
