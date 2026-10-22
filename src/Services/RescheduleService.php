<?php
/**
 * Reschedule service — direct student-initiated session change.
 *
 * Moves a booking from one session to another of the same class in one
 * atomic DB transaction: releases the old seat and acquires the new one.
 * No admin-approval step — the student acts directly from My Account.
 *
 * Policy cutoff: reschedule is blocked when fewer than `settle_days`
 * calendar days remain before the current session's start time.
 *
 * Seat management:
 *   - New session: seats_taken++ (direct UPDATE, no lock-token needed for moves).
 *   - Old session: CapacityService::release_seat() inside the same transaction.
 *   Net change = 0 seats across the system — always correct.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RescheduleService
 */
class RescheduleService {

	/** Statuses eligible for a reschedule. */
	private const ELIGIBLE = [ 'deposit_paid', 'confirmed' ];

	// ── Direct reschedule ─────────────────────────────────────────────────────

	/**
	 * Moves a booking to a new session of the same class atomically.
	 *
	 * Validates:
	 *   - Booking is owned by $student_id.
	 *   - Booking status is eligible (deposit_paid or confirmed).
	 *   - Target session belongs to the same class.
	 *   - Target session has capacity.
	 *   - Policy cutoff: not closer than $settle_days days to the current session start.
	 *
	 * On success: booking.session_id + settle_due_at updated, seats moved, audited.
	 *
	 * @param  int    $booking_id
	 * @param  int    $new_session_id  Target session PK.
	 * @param  int    $student_id      WP user_id performing the action.
	 * @return void
	 * @throws \InvalidArgumentException  On ownership, eligibility, or policy violation.
	 * @throws \RuntimeException          On capacity or DB failure.
	 */
	public static function reschedule(
		int $booking_id,
		int $new_session_id,
		int $student_id
	): void {
		global $wpdb;

		// ── Load booking ──────────────────────────────────────────────────────
		$booking = BookingService::get( $booking_id );
		if ( ! $booking ) {
			throw new \InvalidArgumentException( "Booking {$booking_id} not found." );
		}

		if ( (int) $booking['student_id'] !== $student_id ) {
			throw new \InvalidArgumentException( 'شما مجاز به تغییر این رزرو نیستید.' );
		}

		if ( ! in_array( $booking['status'], self::ELIGIBLE, true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s booking status */
					__( 'رزرو با وضعیت «%s» قابل تغییر زمان نیست.', 'csb' ),
					$booking['status']
				)
			);
		}

		$old_session_id = (int) $booking['session_id'];
		if ( $old_session_id === $new_session_id ) {
			throw new \InvalidArgumentException( 'جلسه جدید باید با جلسه فعلی متفاوت باشد.' );
		}

		// ── Load sessions ─────────────────────────────────────────────────────
		$old_session = CapacityService::get_session( $old_session_id );
		$new_session = CapacityService::get_session( $new_session_id );

		if ( ! $old_session ) {
			throw new \RuntimeException( "Old session {$old_session_id} not found." );
		}
		if ( ! $new_session ) {
			throw new \RuntimeException( "Target session {$new_session_id} not found." );
		}

		// ── Same class check ──────────────────────────────────────────────────
		if ( (int) $old_session['class_id'] !== (int) $new_session['class_id'] ) {
			throw new \InvalidArgumentException( 'جلسه جدید باید متعلق به همان کلاس باشد.' );
		}

		// ── Target session must be open ───────────────────────────────────────
		if ( ! in_array( $new_session['status'], [ 'scheduled', 'running' ], true ) ) {
			throw new \RuntimeException( 'جلسه انتخاب‌شده برای رزرو در دسترس نیست.' );
		}

		// ── Policy cutoff: not allowed < settle_days before current session start ──
		$settle_days = (int) get_post_meta( (int) $old_session['class_id'], '_csb_settle_days', true );
		if ( $settle_days <= 0 ) {
			$settings    = (array) get_option( 'csb_settings', [] );
			$settle_days = (int) ( $settings['settle_days_default'] ?? 3 );
		}

		$now         = time();
		$session_ts  = strtotime( $old_session['start_at'] );
		$days_left   = (int) floor( ( $session_ts - $now ) / DAY_IN_SECONDS );

		if ( $days_left < $settle_days ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %d days */
					__( 'تغییر زمان کمتر از %d روز قبل از شروع جلسه مجاز نیست.', 'csb' ),
					$settle_days
				)
			);
		}

		// ── Compute new settle_due_at ─────────────────────────────────────────
		$new_start_ts    = strtotime( $new_session['start_at'] );
		$new_settle_at   = null;
		if ( $new_start_ts && 'deposit_paid' === $booking['status'] ) {
			$new_settle_ts = $new_start_ts - $settle_days * DAY_IN_SECONDS;
			$new_settle_at = gmdate( 'Y-m-d H:i:s', $new_settle_ts );
		}

		// ── Atomic seat move ──────────────────────────────────────────────────
		$wpdb->query( 'START TRANSACTION' );

		try {
			// Acquire seat on new session (FOR UPDATE locks the row).
			$can_acquire = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, capacity, seats_taken
				   FROM {$wpdb->prefix}csb_sessions
				  WHERE id = %d
				    AND status IN ('scheduled','running')
				  FOR UPDATE",
				$new_session_id
			), ARRAY_A );

			if ( ! $can_acquire ) {
				throw new \RuntimeException( 'جلسه مقصد دیگر در دسترس نیست.' );
			}

			$available = (int) $can_acquire['capacity'] - (int) $can_acquire['seats_taken'];
			if ( $available < 1 ) {
				throw new \RuntimeException( 'ظرفیت جلسه مقصد تکمیل است.' );
			}

			// Increment seats_taken on new session.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}csb_sessions
				    SET seats_taken = seats_taken + 1
				  WHERE id = %d",
				$new_session_id
			) );

			// Decrement seats_taken on old session (floor 0).
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}csb_sessions
				    SET seats_taken = GREATEST(seats_taken - 1, 0)
				  WHERE id = %d",
				$old_session_id
			) );

			// Update booking row.
			$update_data   = [ 'session_id' => $new_session_id, 'updated_at' => current_time( 'mysql', true ) ];
			$update_format = [ '%d', '%s' ];

			if ( null !== $new_settle_at ) {
				$update_data['settle_due_at']   = $new_settle_at;
				$update_format[]                = '%s';
			}

			$updated = $wpdb->update(
				$wpdb->prefix . 'csb_bookings',
				$update_data,
				[ 'id' => $booking_id ],
				$update_format,
				[ '%d' ]
			);

			if ( false === $updated ) {
				throw new \RuntimeException( 'خطا در به‌روزرسانی رزرو.' );
			}

			$wpdb->query( 'COMMIT' );

		} catch ( \Exception $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		AuditLogger::log( 'booking_rescheduled', 'booking', $booking_id, [
			'old_session_id' => $old_session_id,
			'new_session_id' => $new_session_id,
			'new_settle_at'  => $new_settle_at,
			'student_id'     => $student_id,
		] );
	}
}
