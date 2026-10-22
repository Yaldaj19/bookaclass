<?php
/**
 * Attendance service — check-in and no-show tracking per session.
 *
 * A booking that is `confirmed` can be checked-in (status → 'present') or
 * marked no-show (status → 'no_show' on both the booking and attendance row).
 *
 * No-show also transitions the booking state machine via BookingService::transition()
 * so the audit trail is consistent.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttendanceService
 */
class AttendanceService {

	// ── Check-in ──────────────────────────────────────────────────────────────

	/**
	 * Records a student check-in for a booking.
	 *
	 * Idempotent: returns existing row ID if already checked in.
	 * Only allowed when the booking status is 'confirmed'.
	 *
	 * @param int    $booking_id
	 * @param int    $checked_in_by  WP user_id of instructor/admin performing check-in.
	 * @param string $note           Optional free-text note.
	 * @return int  Attendance row ID, or 0 on failure.
	 */
	public static function check_in( int $booking_id, int $checked_in_by = 0, string $note = '' ): int {
		global $wpdb;

		$booking = BookingService::get( $booking_id );
		if ( ! $booking || 'confirmed' !== $booking['status'] ) {
			return 0;
		}

		// Idempotency.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}csb_attendance
			  WHERE booking_id = %d LIMIT 1",
			$booking_id
		) );
		if ( $existing ) {
			return $existing;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'csb_attendance',
			[
				'booking_id'     => $booking_id,
				'session_id'     => (int) $booking['session_id'],
				'student_id'     => (int) $booking['student_id'],
				'status'         => 'present',
				'checked_in_by'  => $checked_in_by,
				'note'           => sanitize_textarea_field( $note ),
				'checked_in_at'  => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		$att_id = (int) $wpdb->insert_id;

		AuditLogger::log( 'attendance_checkin', 'booking', $booking_id, [
			'att_id'        => $att_id,
			'session_id'    => (int) $booking['session_id'],
			'student_id'    => (int) $booking['student_id'],
			'checked_in_by' => $checked_in_by,
		] );

		return $att_id;
	}

	/**
	 * Marks a booking as no-show.
	 *
	 * Transitions booking → 'no_show' via BookingService and inserts/updates
	 * the attendance row.
	 *
	 * @param int $booking_id
	 * @param int $marked_by   WP user_id of instructor/admin.
	 * @return bool
	 */
	public static function mark_no_show( int $booking_id, int $marked_by = 0 ): bool {
		global $wpdb;

		$booking = BookingService::get( $booking_id );
		if ( ! $booking || 'confirmed' !== $booking['status'] ) {
			return false;
		}

		// Transition booking state.
		try {
			BookingService::transition( $booking_id, 'no_show', [
				'marked_by' => $marked_by,
			] );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			AuditLogger::log( 'attendance_no_show_error', 'booking', $booking_id,
				[ 'error' => $e->getMessage() ]
			);
			return false;
		}

		// Also release the seat — no_show vacates the booking.
		CapacityService::release_seat( (int) $booking['session_id'] );

		// Upsert attendance row.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}csb_attendance WHERE booking_id = %d LIMIT 1",
			$booking_id
		) );

		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'csb_attendance',
				[ 'status' => 'no_show' ],
				[ 'id'     => $existing ],
				[ '%s' ],
				[ '%d' ]
			);
		} else {
			$wpdb->insert(
				$wpdb->prefix . 'csb_attendance',
				[
					'booking_id'    => $booking_id,
					'session_id'    => (int) $booking['session_id'],
					'student_id'    => (int) $booking['student_id'],
					'status'        => 'no_show',
					'checked_in_by' => $marked_by,
					'note'          => '',
					'checked_in_at' => current_time( 'mysql', true ),
				],
				[ '%d', '%d', '%d', '%s', '%d', '%s', '%s' ]
			);
		}

		AuditLogger::log( 'attendance_no_show', 'booking', $booking_id, [
			'session_id' => (int) $booking['session_id'],
			'student_id' => (int) $booking['student_id'],
			'marked_by'  => $marked_by,
		] );

		return true;
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	/**
	 * Returns all attendance rows for a session, joined with student name.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_roster( int $session_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, b.status AS booking_status, u.display_name AS student_name,
			        u.user_email AS student_email
			   FROM {$wpdb->prefix}csb_bookings b
			   LEFT JOIN {$wpdb->prefix}csb_attendance a ON a.booking_id = b.id
			   LEFT JOIN {$wpdb->users} u ON u.ID = b.student_id
			  WHERE b.session_id = %d
			    AND b.status NOT IN ('pending','cancelled','expired')
			  ORDER BY u.display_name ASC",
			$session_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * Returns the attendance row for a specific booking, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_for_booking( int $booking_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_attendance WHERE booking_id = %d LIMIT 1",
			$booking_id
		), ARRAY_A );

		return $row ?: null;
	}
}
