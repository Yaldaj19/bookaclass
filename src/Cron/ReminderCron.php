<?php
/**
 * Cron: reminder notifications for deposit settlement and upcoming classes.
 *
 * Hook:  csb_send_reminders  (hourly)
 *
 * Two sub-tasks:
 *   1. Settle reminders — for each deposit_paid booking, send at each configured
 *      threshold (reminder_days: 7, 3, 1) once per stage.
 *      Deduplication: WC order post meta `_csb_reminder_sent_{N}d` prevents re-sends.
 *
 *   2. Class-tomorrow — for each confirmed/deposit_paid booking whose session
 *      starts in the next ~24 h, send once.
 *      Deduplication: WC order post meta `_csb_class_tomorrow_sent`.
 *
 * All notifications go through NotificationService::dispatch() — Phase 7
 * attaches the SMS adapter without touching this cron.
 *
 * @package CookingSchoolBooking\Cron
 */

namespace CookingSchoolBooking\Cron;

use CookingSchoolBooking\Admin\SettingsPage;
use CookingSchoolBooking\Services\NotificationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReminderCron
 */
class ReminderCron {

	public function init(): void {
		add_action( 'csb_send_reminders', [ $this, 'run' ] );
	}

	public function run(): void {
		$this->send_settle_reminders();
		$this->send_class_tomorrow();
	}

	// ── Settle reminders ──────────────────────────────────────────────────────

	private function send_settle_reminders(): void {
		global $wpdb;

		$settings      = SettingsPage::get();
		$reminder_days = (array) ( $settings['reminder_days'] ?? [ 7, 3, 1 ] );

		if ( empty( $reminder_days ) ) {
			return;
		}

		// Fetch all deposit_paid bookings with a future settle_due_at.
		$bookings = $wpdb->get_results(
			"SELECT b.id, b.student_id, b.balance_due, b.settle_due_at,
			        b.wc_order_id, b.balance_order_id, b.session_id,
			        s.start_at, s.class_id
			   FROM {$wpdb->prefix}csb_bookings b
			   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
			  WHERE b.status       = 'deposit_paid'
			    AND b.settle_due_at IS NOT NULL
			    AND b.settle_due_at > UTC_TIMESTAMP()",
			ARRAY_A
		);

		if ( ! $bookings ) {
			return;
		}

		$now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

		foreach ( $bookings as $booking ) {
			$booking_id  = (int) $booking['id'];
			$settle_date = new \DateTimeImmutable( $booking['settle_due_at'], new \DateTimeZone( 'UTC' ) );
			$days_left   = (int) $now->diff( $settle_date )->days;

			foreach ( $reminder_days as $threshold ) {
				$threshold = (int) $threshold;
				if ( $threshold <= 0 || $days_left > $threshold ) {
					continue;
				}

				// Deduplication via WC order post meta.
				$meta_key = "_csb_reminder_sent_{$threshold}d";
				$wc_order_id = (int) $booking['wc_order_id'];

				if ( get_post_meta( $wc_order_id, $meta_key, true ) ) {
					continue;
				}

				$this->dispatch_settle_reminder( $booking, $threshold );
				update_post_meta( $wc_order_id, $meta_key, '1' );
			}
		}
	}

	/**
	 * @param array<string,mixed> $booking
	 * @param int                 $days_remaining
	 */
	private function dispatch_settle_reminder( array $booking, int $days_remaining ): void {
		$booking_id       = (int) $booking['id'];
		$balance_due      = (float) $booking['balance_due'];
		$balance_order_id = (int) $booking['balance_order_id'];

		$pay_url = '';
		if ( $balance_order_id ) {
			$bal_order = wc_get_order( $balance_order_id );
			if ( $bal_order ) {
				$pay_url = $bal_order->get_checkout_payment_url();
			}
		}

		$class_title = get_the_title( (int) $booking['class_id'] );

		NotificationService::dispatch( 'settle_reminder', [
			'student_id' => (int) $booking['student_id'],
			'booking_id' => $booking_id,
			'session_id' => (int) $booking['session_id'],
			'days'       => $days_remaining,
			'pay_url'    => $pay_url,
			'subject'    => sprintf(
				/* translators: %d days remaining */
				__( 'یادآوری: %d روز تا سررسید تسویه رزرو شما', 'csb' ),
				$days_remaining
			),
			'body'       => sprintf(
				/* translators: 1: class title  2: booking ID  3: balance  4: days  5: pay URL */
				__(
					"هنرجوی عزیز،\n\nمانده‌ی رزرو دوره «%1\$s» (#%2\$d) به مبلغ %3\$s تومان باید ظرف %4\$d روز آینده تسویه شود.\n\nلینک پرداخت: %5\$s\n\nآموزشگاه",
					'csb'
				),
				$class_title,
				$booking_id,
				number_format_i18n( $balance_due ),
				$days_remaining,
				$pay_url
			),
		] );
	}

	// ── Class-tomorrow reminder ───────────────────────────────────────────────

	private function send_class_tomorrow(): void {
		global $wpdb;

		$window_start = gmdate( 'Y-m-d H:i:s', time() + 23 * 3600 + 1800 );
		$window_end   = gmdate( 'Y-m-d H:i:s', time() + 25 * 3600 );

		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id, b.student_id, b.wc_order_id, b.session_id,
			        s.start_at, s.location, s.online_url, s.class_id
			   FROM {$wpdb->prefix}csb_bookings b
			   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
			  WHERE b.status IN ('confirmed','deposit_paid')
			    AND s.start_at BETWEEN %s AND %s",
			$window_start,
			$window_end
		), ARRAY_A );

		if ( ! $bookings ) {
			return;
		}

		foreach ( $bookings as $booking ) {
			$wc_order_id = (int) $booking['wc_order_id'];
			$flag_key    = '_csb_class_tomorrow_sent';

			if ( get_post_meta( $wc_order_id, $flag_key, true ) ) {
				continue;
			}

			$class_title = get_the_title( (int) $booking['class_id'] );
			$location    = $booking['location'] ?: $booking['online_url'];

			NotificationService::dispatch( 'class_tomorrow', [
				'student_id' => (int) $booking['student_id'],
				'booking_id' => (int) $booking['id'],
				'session_id' => (int) $booking['session_id'],
				'subject'    => sprintf(
					/* translators: %s: class title */
					__( 'یادآوری کلاس فردا: %s', 'csb' ),
					$class_title
				),
				'body'       => sprintf(
					/* translators: 1: class title  2: time  3: location */
					__(
						"هنرجوی عزیز،\n\nکلاس «%1\$s» فردا ساعت %2\$s برگزار می‌شود.\nمکان/لینک: %3\$s\n\nمنتظرتان هستیم.\nآموزشگاه",
						'csb'
					),
					$class_title,
					gmdate( 'H:i', strtotime( $booking['start_at'] ) ),
					$location
				),
			] );

			update_post_meta( $wc_order_id, $flag_key, '1' );
		}
	}
}
