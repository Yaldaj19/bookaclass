<?php
/**
 * Cron: expire bookings whose balance settlement deadline has passed.
 *
 * Hook:  csb_expire_unsettled  (hourly)
 *
 * For every deposit_paid booking where settle_due_at <= NOW():
 *   1. Cancel the balance WC order (if still pending).
 *   2. Transition booking → expired via BookingService::transition().
 *   3. Release the counted seat (only after transition succeeds).
 *   4. Issue a refund request (auto-approved: system cancels = full refund).
 *   5. Add WC order note.
 *
 * @package BookaClass\Cron
 */

namespace BookaClass\Cron;

use BookaClass\Services\AuditLogger;
use BookaClass\Services\BookingService;
use BookaClass\Services\CapacityService;
use BookaClass\Services\RefundService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExpireUnsettledCron
 */
class ExpireUnsettledCron {

	public function init(): void {
		add_action( 'csb_expire_unsettled', [ $this, 'run' ] );
	}

	public function run(): void {
		global $wpdb;

		$bookings = $wpdb->get_results(
			"SELECT b.id, b.wc_order_id, b.balance_order_id, b.session_id,
			        b.student_id, b.paid_amount
			   FROM {$wpdb->prefix}csb_bookings b
			  WHERE b.status       = 'deposit_paid'
			    AND b.settle_due_at IS NOT NULL
			    AND b.settle_due_at <= UTC_TIMESTAMP()",
			ARRAY_A
		);

		if ( ! $bookings ) {
			return;
		}

		foreach ( $bookings as $booking ) {
			$this->expire_one( $booking );
		}
	}

	/**
	 * @param array<string,mixed> $booking
	 */
	private function expire_one( array $booking ): void {
		$booking_id       = (int) $booking['id'];
		$balance_order_id = (int) $booking['balance_order_id'];
		$session_id       = (int) $booking['session_id'];
		$wc_order_id      = (int) $booking['wc_order_id'];

		// ── 1. Cancel balance WC order ────────────────────────────────────────
		if ( $balance_order_id ) {
			$bal_order = wc_get_order( $balance_order_id );
			if ( $bal_order && ! $bal_order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
				$bal_order->update_status(
					'cancelled',
					esc_html__( 'لغو خودکار به دلیل عدم تسویه در موعد مقرر.', 'csb' )
				);
			}
		}

		// ── 2. Transition booking → expired (must succeed before we free the seat) ──
		try {
			BookingService::transition( $booking_id, 'expired', [ 'reason' => 'settle_deadline_passed' ] );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			AuditLogger::log( 'expire_transition_error', 'booking', $booking_id,
				[ 'error' => $e->getMessage() ]
			);
			return; // Don't release seat or issue refund if transition failed.
		}

		// ── 3. Release the counted seat (only after transition succeeds) ──────
		CapacityService::release_seat( $session_id );

		// ── 4. Issue auto-approved refund for the deposit already paid ────────
		// School-side expire: treat as 'system' cancellation → full refund.
		RefundService::request_refund(
			$booking_id,
			'system',  // cancelled_by
			'wallet',  // default method; admin can change if needed
			0,         // requested_by = system
			true       // auto_approve
		);

		// ── 5. Order note ─────────────────────────────────────────────────────
		$order = wc_get_order( $wc_order_id );
		if ( $order ) {
			$order->add_order_note( sprintf(
				/* translators: %d booking ID */
				esc_html__( 'رزرو #%d به دلیل عدم تسویه مانده در موعد مقرر منقضی شد. بازگشت وجه اعمال خواهد شد.', 'csb' ),
				$booking_id
			) );
		}
	}
}
