<?php
/**
 * Refund service — tiered refund policy + admin-approval workflow.
 *
 * Refund tiers (per BUILD-SPEC §8, config-driven):
 *   > 7 days before session start  → 100 % refund
 *   7 – 3 days before              → deposit forfeited, balance refunded
 *   < 3 days before / no_show      → no refund
 *   School cancellation            → 100 % refund + optional priority wallet credit
 *
 * Workflow:
 *   1. Student or system calls RefundService::request_refund() → creates a
 *      pending row in wp_csb_refund_requests.
 *   2. Admin reviews and calls RefundService::approve_refund() (via REST or
 *      admin page — Phase 6 adds the UI; the approval logic lives here).
 *   3. approve_refund() issues WC refund (wc_create_refund) or wallet credit,
 *      decrements seats_taken if the booking was confirmed, cancels the
 *      booking, and writes the audit trail.
 *
 * Auto-approval path: session_cancelled by MinToRunCron passes
 * `auto_approve = true` so the cron can issue refunds immediately
 * without waiting for admin action.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RefundService
 */
class RefundService {

	// Tier thresholds in full calendar days.
	private const DEFAULT_FULL_REFUND_DAYS    = 7;
	private const DEFAULT_PARTIAL_REFUND_DAYS = 3;

	// ── Policy calculation ────────────────────────────────────────────────────

	/**
	 * Calculates the refund breakdown for a booking without executing anything.
	 *
	 * @param  int    $booking_id
	 * @param  string $cancelled_by  'student' | 'school' | 'system'
	 * @return array{
	 *   refund_amount: float,
	 *   forfeited_amount: float,
	 *   policy_tier: string,
	 *   days_to_session: int
	 * }|null  null if booking or session not found.
	 */
	public static function calculate( int $booking_id, string $cancelled_by = 'student' ): ?array {
		$booking = BookingService::get( $booking_id );
		if ( ! $booking ) {
			return null;
		}

		$session = CapacityService::get_session( (int) $booking['session_id'] );
		if ( ! $session ) {
			return null;
		}

		$paid = (float) $booking['paid_amount'];

		// School / system cancellations are always full refunds.
		if ( in_array( $cancelled_by, [ 'school', 'system' ], true ) ) {
			return [
				'refund_amount'    => $paid,
				'forfeited_amount' => 0.00,
				'policy_tier'      => 'school_cancellation',
				'days_to_session'  => self::days_until( $session['start_at'] ),
			];
		}

		// Read configurable thresholds.
		$settings           = (array) get_option( 'csb_settings', [] );
		$full_refund_days   = (int) ( $settings['cancel_full_refund_days']    ?? self::DEFAULT_FULL_REFUND_DAYS );
		$partial_refund_days = (int) ( $settings['cancel_partial_refund_days'] ?? self::DEFAULT_PARTIAL_REFUND_DAYS );

		$days = self::days_until( $session['start_at'] );

		if ( $days > $full_refund_days ) {
			return [
				'refund_amount'    => $paid,
				'forfeited_amount' => 0.00,
				'policy_tier'      => 'full_refund',
				'days_to_session'  => $days,
			];
		}

		if ( $days >= $partial_refund_days ) {
			$deposit    = (float) $booking['deposit_amount'];
			$refundable = max( 0.00, $paid - $deposit );

			return [
				'refund_amount'    => $refundable,
				'forfeited_amount' => min( $paid, $deposit ),
				'policy_tier'      => 'partial_refund',
				'days_to_session'  => $days,
			];
		}

		return [
			'refund_amount'    => 0.00,
			'forfeited_amount' => $paid,
			'policy_tier'      => 'no_refund',
			'days_to_session'  => $days,
		];
	}

	// ── Request creation ──────────────────────────────────────────────────────

	/**
	 * Creates a refund request (pending admin approval) for a booking.
	 *
	 * Returns the new request ID, or 0 on failure / already exists.
	 * Pass $auto_approve = true to skip the approval step (system/cron paths).
	 *
	 * @param  int    $booking_id
	 * @param  string $cancelled_by   'student' | 'school' | 'system'
	 * @param  string $method         'cash' | 'wallet'
	 * @param  int    $requested_by   WP user_id (0 = system).
	 * @param  bool   $auto_approve
	 * @return int  Request ID, or 0 on failure.
	 */
	public static function request_refund(
		int $booking_id,
		string $cancelled_by = 'student',
		string $method = 'wallet',
		int $requested_by = 0,
		bool $auto_approve = false
	): int {
		global $wpdb;

		// Guard: block if already terminal.
		$booking = BookingService::get( $booking_id );
		if ( ! $booking ) {
			return 0;
		}

		$terminal = [ 'cancelled', 'completed', 'expired', 'no_show' ];
		if ( in_array( $booking['status'], $terminal, true ) ) {
			return 0;
		}

		// Guard: prevent duplicate pending requests.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}csb_refund_requests
			  WHERE booking_id = %d AND status = 'pending'
			  LIMIT 1",
			$booking_id
		) );
		if ( $existing ) {
			return (int) $existing; // Return existing request ID.
		}

		$breakdown = self::calculate( $booking_id, $cancelled_by );
		if ( ! $breakdown ) {
			return 0;
		}

		$status = $auto_approve ? 'approved' : 'pending';

		$wpdb->insert(
			$wpdb->prefix . 'csb_refund_requests',
			[
				'booking_id'    => $booking_id,
				'requested_by'  => $requested_by,
				'reason'        => sanitize_text_field( $cancelled_by ),
				'refund_amount' => $breakdown['refund_amount'],
				'policy_tier'   => $breakdown['policy_tier'],
				'method'        => in_array( $method, [ 'cash', 'wallet' ], true ) ? $method : 'wallet',
				'status'        => $status,
			],
			[ '%d', '%d', '%s', '%f', '%s', '%s', '%s' ]
		);

		$request_id = (int) $wpdb->insert_id;

		AuditLogger::log(
			'refund_requested',
			'booking',
			$booking_id,
			[
				'request_id'    => $request_id,
				'cancelled_by'  => $cancelled_by,
				'policy_tier'   => $breakdown['policy_tier'],
				'refund_amount' => $breakdown['refund_amount'],
				'method'        => $method,
				'auto_approve'  => $auto_approve,
			]
		);

		if ( $auto_approve && $request_id ) {
			self::approve_refund( $request_id, 0 ); // actor 0 = system.
		}

		return $request_id;
	}

	// ── Admin approval ────────────────────────────────────────────────────────

	/**
	 * Approves a pending refund request and executes it.
	 *
	 * Capability check: caller must verify manage_woocommerce before calling
	 * (enforced at REST layer; this method trusts the caller).
	 *
	 * @param  int $request_id
	 * @param  int $actor_user_id  WP user_id of the approving admin (0 = system).
	 * @return array{success: bool, error?: string}
	 */
	public static function approve_refund( int $request_id, int $actor_user_id ): array {
		global $wpdb;

		$request = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_refund_requests WHERE id = %d FOR UPDATE",
			$request_id
		), ARRAY_A );

		if ( ! $request ) {
			return [ 'success' => false, 'error' => 'request_not_found' ];
		}

		if ( 'pending' !== $request['status'] ) {
			return [ 'success' => false, 'error' => 'already_processed' ];
		}

		$booking_id    = (int) $request['booking_id'];
		$refund_amount = (float) $request['refund_amount'];
		$method        = (string) $request['method'];
		$booking       = BookingService::get( $booking_id );

		if ( ! $booking ) {
			return [ 'success' => false, 'error' => 'booking_not_found' ];
		}

		// ── 1. Cancel any balance WC order ────────────────────────────────────
		// NOTE: seat release is handled inside BookingService::cancel() (step 3);
		// do NOT call decrement_seats_taken() here or the seat would be freed twice.
		$balance_order_id = (int) $booking['balance_order_id'];
		if ( $balance_order_id ) {
			$bal_order = wc_get_order( $balance_order_id );
			if ( $bal_order && ! $bal_order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
				$bal_order->update_status(
					'cancelled',
					esc_html__( 'لغو به دلیل تأیید درخواست استرداد.', 'csb' )
				);
			}
		}

		// ── 2. Transition booking to cancelled (also releases seat via BookingService) ──
		try {
			BookingService::cancel( $booking_id, 'refund_approved', '', (int) $booking['wc_order_id'] );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			// Log but continue — refund still issued.
			AuditLogger::log( 'refund_cancel_error', 'booking', $booking_id, [ 'error' => $e->getMessage() ] );
		}

		// ── 3. Issue the refund ───────────────────────────────────────────────
		$refund_issued = false;
		$refund_error  = '';

		if ( $refund_amount > 0 ) {
			if ( 'cash' === $method ) {
				$result = self::issue_wc_refund( (int) $booking['wc_order_id'], $refund_amount, $booking_id );
				if ( is_wp_error( $result ) ) {
					$refund_error = $result->get_error_message();
					// Fallback: wallet credit.
					WalletService::credit(
						(int) $booking['student_id'],
						$refund_amount,
						'refund_cash_fallback',
						(string) $booking_id
					);
					$refund_issued = true;
				} else {
					$refund_issued = true;
				}
			} else {
				$new_bal = WalletService::credit(
					(int) $booking['student_id'],
					$refund_amount,
					'refund_wallet',
					(string) $booking_id
				);
				$refund_issued = $new_bal >= 0;
			}
		} else {
			$refund_issued = true; // No refund to issue; still a valid outcome.
		}

		// ── 4. Mark request approved ──────────────────────────────────────────
		$wpdb->update(
			$wpdb->prefix . 'csb_refund_requests',
			[
				'status'      => 'approved',
				'reviewed_by' => $actor_user_id,
				'reviewed_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $request_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		// ── 5. Notify student ─────────────────────────────────────────────────
		NotificationService::dispatch( 'booking_cancelled_refund', [
			'student_id'    => (int) $booking['student_id'],
			'booking_id'    => $booking_id,
			'refund_amount' => $refund_amount,
			'method'        => $method,
			'subject'       => __( 'بازگشت وجه رزرو شما تأیید شد.', 'csb' ),
			'body'          => sprintf(
				/* translators: 1: booking_id  2: refund_amount */
				/* translators: 1: booking_id  2: refund_amount with currency  3: method */
				__( "رزرو #%1\$d لغو و مبلغ %2\$s به %3\$s شما بازگشت داده شد.", 'csb' ),
				$booking_id,
				strip_tags( wc_price( $refund_amount ) ),
				'wallet' === $method ? __( 'کیف‌پول', 'csb' ) : __( 'حساب بانکی', 'csb' )
			),
		] );

		// ── 6. Audit ──────────────────────────────────────────────────────────
		AuditLogger::log(
			'refund_approved',
			'booking',
			$booking_id,
			[
				'request_id'    => $request_id,
				'refund_amount' => $refund_amount,
				'method'        => $method,
				'refund_issued' => $refund_issued,
				'refund_error'  => $refund_error,
				'actor'         => $actor_user_id,
			]
		);

		return [ 'success' => true ];
	}

	/**
	 * Rejects a pending refund request.
	 *
	 * @param  int $request_id
	 * @param  int $actor_user_id
	 * @return array{success: bool, error?: string}
	 */
	public static function reject_refund( int $request_id, int $actor_user_id ): array {
		global $wpdb;

		$request = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_refund_requests WHERE id = %d",
			$request_id
		), ARRAY_A );

		if ( ! $request ) {
			return [ 'success' => false, 'error' => 'request_not_found' ];
		}

		if ( 'pending' !== $request['status'] ) {
			return [ 'success' => false, 'error' => 'already_processed' ];
		}

		$wpdb->update(
			$wpdb->prefix . 'csb_refund_requests',
			[
				'status'      => 'rejected',
				'reviewed_by' => $actor_user_id,
				'reviewed_at' => current_time( 'mysql', true ),
			],
			[ 'id' => $request_id ],
			[ '%s', '%d', '%s' ],
			[ '%d' ]
		);

		AuditLogger::log(
			'refund_rejected',
			'booking',
			(int) $request['booking_id'],
			[ 'request_id' => $request_id, 'actor' => $actor_user_id ]
		);

		return [ 'success' => true ];
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Issues a WC refund via wc_create_refund().
	 *
	 * @return \WC_Order_Refund|\WP_Error
	 */
	private static function issue_wc_refund( int $order_id, float $amount, int $booking_id ) {
		if ( ! function_exists( 'wc_create_refund' ) ) {
			return new \WP_Error( 'wc_missing', 'wc_create_refund not available.' );
		}

		return wc_create_refund( [
			'order_id'       => $order_id,
			'amount'         => $amount,
			'reason'         => sprintf(
				/* translators: %d booking ID */
				__( 'بازگشت وجه رزرو #%d طبق سیاست لغو', 'csb' ),
				$booking_id
			),
			'refund_payment' => true,
		] );
	}

	/**
	 * Returns the number of full calendar days until a UTC datetime.
	 * Negative = already in the past.
	 */
	private static function days_until( string $utc_datetime ): int {
		$now   = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$start = new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) );
		$diff  = $now->diff( $start );

		return $diff->invert ? -$diff->days : $diff->days;
	}
}
