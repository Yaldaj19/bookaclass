<?php
/**
 * Atomic seat lock / release / confirm service.
 *
 * All writes go through DB transactions so concurrent checkouts cannot
 * overbook a session.  Only this class touches `wp_csb_seat_locks`.
 *
 * Flow:
 *   1. acquire_lock()  — called at add-to-cart / during checkout validation.
 *   2. confirm_lock()  — called when the WC order reaches payment-complete.
 *   3. release_lock()  — called when cart is abandoned or order is cancelled.
 *
 * A cron (`csb_release_locks`) calls release_expired() every 5 minutes.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CapacityService
 */
class CapacityService {

	/** How many minutes a seat lock lives before expiring. */
	public const LOCK_TTL_MINUTES = 15;

	// ── Acquire ──────────────────────────────────────────────────────────────

	/**
	 * Tries to place an atomic seat lock for $qty seats in $session_id.
	 *
	 * Uses a DB transaction + SELECT … FOR UPDATE to prevent races.
	 * Returns the lock token on success, WP_Error on failure.
	 *
	 * @param  int    $session_id
	 * @param  int    $qty        Number of seats to reserve.
	 * @param  string $lock_token Opaque token that identifies this reservation
	 *                            (typically WC session token or cart hash).
	 *                            Must be unique per cart item; pass the same
	 *                            token on repeated calls to extend/replace.
	 * @return string|\WP_Error   Lock token on success; WP_Error otherwise.
	 */
	public static function acquire_lock( int $session_id, int $qty, string $lock_token ): string|\WP_Error {
		global $wpdb;

		if ( $session_id <= 0 || $qty <= 0 ) {
			return new \WP_Error( 'csb_invalid_args', __( 'پارامترهای ورودی نامعتبر است.', 'csb' ) );
		}

		$lock_token = sanitize_text_field( $lock_token );
		if ( '' === $lock_token ) {
			return new \WP_Error( 'csb_invalid_token', __( 'توکن قفل خالی است.', 'csb' ) );
		}

		$wpdb->query( 'START TRANSACTION' );

		// Lock the session row so concurrent requests queue behind this one.
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, capacity, seats_taken, status
			   FROM {$wpdb->prefix}csb_sessions
			  WHERE id = %d
			    AND status = 'scheduled'
			  FOR UPDATE",
			$session_id
		), ARRAY_A );

		if ( ! $session ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error(
				'csb_session_unavailable',
				__( 'این جلسه در دسترس نیست یا پر شده است.', 'csb' )
			);
		}

		// Count active (non-expired) locks for this session, excluding any
		// existing lock from THIS token (so a cart refresh doesn't double-count).
		$locked_qty = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(qty), 0)
			   FROM {$wpdb->prefix}csb_seat_locks
			  WHERE session_id = %d
			    AND expires_at > UTC_TIMESTAMP()
			    AND lock_token != %s",
			$session_id,
			$lock_token
		) );

		$available = (int) $session['capacity'] - (int) $session['seats_taken'] - $locked_qty;

		if ( $available < $qty ) {
			$wpdb->query( 'ROLLBACK' );
			/* translators: 1: available seats, 2: requested seats */
			return new \WP_Error(
				'csb_no_capacity',
				sprintf(
					_n(
						'تنها %1$d صندلی باقی مانده ولی %2$d درخواست شده است.',
						'تنها %1$d صندلی باقی مانده ولی %2$d درخواست شده است.',
						$available,
						'csb'
					),
					$available,
					$qty
				)
			);
		}

		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL_MINUTES * 60 );

		// Upsert: update if the token already has a lock row, otherwise insert.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}csb_seat_locks WHERE lock_token = %s",
			$lock_token
		), ARRAY_A );

		if ( $existing ) {
			$wpdb->update(
				$wpdb->prefix . 'csb_seat_locks',
				[ 'session_id' => $session_id, 'qty' => $qty, 'expires_at' => $expires_at ],
				[ 'lock_token' => $lock_token ],
				[ '%d', '%d', '%s' ],
				[ '%s' ]
			);
		} else {
			$wpdb->insert(
				$wpdb->prefix . 'csb_seat_locks',
				[
					'session_id' => $session_id,
					'lock_token' => $lock_token,
					'qty'        => $qty,
					'expires_at' => $expires_at,
				],
				[ '%d', '%s', '%d', '%s' ]
			);
		}

		if ( $wpdb->last_error ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'csb_db_error', __( 'خطای پایگاه داده هنگام ثبت قفل صندلی.', 'csb' ) );
		}

		$wpdb->query( 'COMMIT' );

		return $lock_token;
	}

	// ── Confirm ──────────────────────────────────────────────────────────────

	/**
	 * Converts a seat lock into a confirmed booking by incrementing seats_taken
	 * on the session row and removing the lock.
	 *
	 * Call this when the WC order transitions to payment_complete / processing.
	 *
	 * @param  string $lock_token
	 * @param  int    $booking_id  The wp_csb_bookings.id just created.
	 * @return true|\WP_Error
	 */
	public static function confirm_lock( string $lock_token, int $booking_id ): true|\WP_Error {
		global $wpdb;

		$lock_token = sanitize_text_field( $lock_token );

		$wpdb->query( 'START TRANSACTION' );

		$lock = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, session_id, qty
			   FROM {$wpdb->prefix}csb_seat_locks
			  WHERE lock_token = %s
			    AND expires_at > UTC_TIMESTAMP()
			  FOR UPDATE",
			$lock_token
		), ARRAY_A );

		if ( ! $lock ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'csb_lock_expired', __( 'قفل صندلی منقضی شده است.', 'csb' ) );
		}

		// Increment seats_taken on the session.
		$rows = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}csb_sessions
			    SET seats_taken = seats_taken + %d
			  WHERE id = %d AND status = 'scheduled'",
			(int) $lock['qty'],
			(int) $lock['session_id']
		) );

		if ( ! $rows ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'csb_session_gone', __( 'جلسه دیگر قابل رزرو نیست.', 'csb' ) );
		}

		// Remove the transient lock row.
		$wpdb->delete(
			$wpdb->prefix . 'csb_seat_locks',
			[ 'lock_token' => $lock_token ],
			[ '%s' ]
		);

		$wpdb->query( 'COMMIT' );

		AuditLogger::log(
			'seat_confirmed',
			'booking',
			$booking_id,
			[ 'session_id' => (int) $lock['session_id'], 'qty' => (int) $lock['qty'] ]
		);

		return true;
	}

	// ── Release ──────────────────────────────────────────────────────────────

	/**
	 * Releases a lock explicitly (cart removal, order cancellation, etc.).
	 *
	 * @param  string $lock_token
	 * @return bool   True if a row was deleted, false if nothing was found.
	 */
	public static function release_lock( string $lock_token ): bool {
		global $wpdb;

		$lock_token = sanitize_text_field( $lock_token );

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'csb_seat_locks',
			[ 'lock_token' => $lock_token ],
			[ '%s' ]
		);

		return (bool) $deleted;
	}

	// ── Release seat (post-confirm cancellation/expiry) ──────────────────────

	/**
	 * Atomically decrements seats_taken for a session (floor = 0, never negative).
	 *
	 * Call this ONLY when a booking that was previously holding a counted seat
	 * (status was deposit_paid | fully_paid | confirmed) transitions to a
	 * terminal state (cancelled | expired | no_show).
	 *
	 * Using GREATEST(..., 0) prevents underflow in the rare case of a race or
	 * data inconsistency, so the session can never show negative availability.
	 *
	 * @param int $session_id
	 * @param int $qty  Number of seats to free (default 1; matches the qty that
	 *                  was confirmed at acquire_lock time).
	 */
	public static function release_seat( int $session_id, int $qty = 1 ): void {
		global $wpdb;

		if ( $session_id <= 0 || $qty <= 0 ) {
			return;
		}

		$wpdb->query( 'START TRANSACTION' );

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}csb_sessions
			    SET seats_taken = GREATEST(seats_taken - %d, 0)
			  WHERE id = %d",
			$qty,
			$session_id
		) );

		$wpdb->query( 'COMMIT' );

		AuditLogger::log(
			'seat_released',
			'session',
			$session_id,
			[ 'qty' => $qty ]
		);
	}

	// ── Release expired ──────────────────────────────────────────────────────

	/**
	 * Deletes all expired seat locks.
	 *
	 * Called by the `csb_release_locks` cron event every 5 minutes.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function release_expired(): int {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}csb_seat_locks
			  WHERE expires_at <= UTC_TIMESTAMP()"
		);

		if ( $count > 0 ) {
			$wpdb->query(
				"DELETE FROM {$wpdb->prefix}csb_seat_locks
				  WHERE expires_at <= UTC_TIMESTAMP()"
			);
		}

		return $count;
	}

	// ── Availability helpers ─────────────────────────────────────────────────

	/**
	 * Returns the number of seats still available for a session.
	 *
	 * @param  int $session_id
	 * @return int  Available seats; 0 when full or session not found.
	 */
	public static function available_seats( int $session_id ): int {
		global $wpdb;

		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT capacity, seats_taken
			   FROM {$wpdb->prefix}csb_sessions
			  WHERE id = %d AND status = 'scheduled'",
			$session_id
		), ARRAY_A );

		if ( ! $session ) {
			return 0;
		}

		$locked = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(qty), 0)
			   FROM {$wpdb->prefix}csb_seat_locks
			  WHERE session_id = %d AND expires_at > UTC_TIMESTAMP()",
			$session_id
		) );

		$available = (int) $session['capacity'] - (int) $session['seats_taken'] - $locked;
		return max( 0, $available );
	}

	/**
	 * Returns session row or null.
	 *
	 * @param  int $session_id
	 * @return array<string,mixed>|null
	 */
	public static function get_session( int $session_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_sessions WHERE id = %d",
			$session_id
		), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Returns all non-cancelled upcoming sessions for a class product.
	 *
	 * @param  int $class_id  WC product ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_upcoming_sessions( int $class_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_sessions
			  WHERE class_id = %d
			    AND status IN ('scheduled','running')
			    AND start_at >= UTC_TIMESTAMP()
			  ORDER BY start_at ASC",
			$class_id
		), ARRAY_A ) ?: [];
	}
}
