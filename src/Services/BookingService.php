<?php
/**
 * Booking state machine.
 *
 * Owns the wp_csb_bookings table and enforces the allowed status transitions
 * defined in BUILD-SPEC §6.  No WC hooks live here — see OrderBooking.php.
 *
 * Allowed transition graph (§6):
 *   pending          → fully_paid | cancelled | expired
 *   fully_paid       → confirmed  | cancelled
 *   confirmed        → completed  | cancelled
 *   deposit_paid     → confirmed  | cancelled          (Phase 4)
 *   completed        → (terminal — no outbound edges)
 *   cancelled        → (terminal)
 *   expired          → (terminal)
 *   no_show          → (terminal)                      (Phase 6)
 *
 * Phase 3 uses only: pending → fully_paid → confirmed.
 * The deposit_paid branch is wired in Phase 4.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BookingService
 */
class BookingService {

	// ── Transition graph ─────────────────────────────────────────────────────

	/**
	 * Allowed outbound transitions for each status.
	 * Terminal statuses have an empty list.
	 *
	 * @var array<string, string[]>
	 */
	private const TRANSITIONS = [
		'pending'      => [ 'fully_paid', 'deposit_paid', 'cancelled', 'expired' ],
		'fully_paid'   => [ 'confirmed', 'cancelled' ],
		// deposit_paid → fully_paid is the settlement path (confirm_balance).
		// deposit_paid → confirmed is intentionally ABSENT: must go via fully_paid.
		'deposit_paid' => [ 'fully_paid', 'cancelled', 'expired' ],
		'confirmed'    => [ 'completed', 'cancelled', 'no_show' ],
		'completed'    => [],
		'cancelled'    => [],
		'expired'      => [],
		'no_show'      => [],
	];

	// ── Core state machine ────────────────────────────────────────────────────

	/**
	 * Transitions a booking to a new status.
	 *
	 * Validates the transition against the allowed graph.
	 * Throws \InvalidArgumentException on an illegal move.
	 * Writes an audit log entry for every transition.
	 *
	 * @param  int                 $booking_id
	 * @param  string              $to       Target status.
	 * @param  array<string,mixed> $context  Extra data to log (e.g. reason, order_id).
	 * @throws \InvalidArgumentException     If the transition is not in the graph.
	 * @throws \RuntimeException             If the booking row is not found.
	 */
	public static function transition( int $booking_id, string $to, array $context = [] ): void {
		global $wpdb;

		$booking = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$wpdb->prefix}csb_bookings WHERE id = %d FOR UPDATE",
			$booking_id
		), ARRAY_A );

		if ( ! $booking ) {
			throw new \RuntimeException(
				sprintf( 'BookingService: booking %d not found.', $booking_id )
			);
		}

		$from = $booking['status'];

		if ( ! isset( self::TRANSITIONS[ $from ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'BookingService: unknown status "%s" on booking %d.', $from, $booking_id )
			);
		}

		if ( ! in_array( $to, self::TRANSITIONS[ $from ], true ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'BookingService: illegal transition %s → %s on booking %d.',
					$from,
					$to,
					$booking_id
				)
			);
		}

		$wpdb->update(
			$wpdb->prefix . 'csb_bookings',
			[ 'status' => $to ],
			[ 'id'     => $booking_id ],
			[ '%s' ],
			[ '%d' ]
		);

		AuditLogger::log(
			'booking_transition',
			'booking',
			$booking_id,
			array_merge( [ 'from' => $from, 'to' => $to ], $context )
		);
	}

	// ── High-level helpers ────────────────────────────────────────────────────

	/**
	 * Creates a new booking row from a WC order line item.
	 *
	 * Reads `_csb_payment_mode` from the order item meta to determine whether
	 * this is a full-payment or deposit booking.
	 *
	 * For deposit bookings:
	 *   — The order line total is the deposit amount (already reduced by
	 *     CheckoutHooks::reduce_price_for_deposit()).
	 *   — full_price and balance_due are read from the additional item metas
	 *     `_csb_full_price` and `_csb_balance_due` set during checkout.
	 *   — settle_due_at is computed from `_csb_settle_due_at` order item meta.
	 *
	 * For full-payment:
	 *   — deposit_amount = line_total, balance_due = 0, settle_due_at = null.
	 *
	 * @param  \WC_Order              $order
	 * @param  \WC_Order_Item_Product $item
	 * @return int  New booking ID, or 0 on DB failure.
	 */
	public static function create_from_order_item( \WC_Order $order, \WC_Order_Item_Product $item ): int {
		global $wpdb;

		$session_id = (int) $item->get_meta( '_csb_session_id', true );

		if ( ! $session_id ) {
			return 0;
		}

		$payment_mode = (string) $item->get_meta( '_csb_payment_mode', true );
		$line_total   = (float) $item->get_total();

		if ( 'deposit' === $payment_mode ) {
			$deposit_amount = $line_total;
			$balance_due    = (float) $item->get_meta( '_csb_balance_due', true );
			$settle_raw     = (string) $item->get_meta( '_csb_settle_due_at', true );
			$settle_due_at  = $settle_raw ?: null;
		} else {
			// Full payment or fallback.
			$deposit_amount = $line_total;
			$balance_due    = 0.00;
			$settle_due_at  = null;
		}

		$wpdb->insert(
			$wpdb->prefix . 'csb_bookings',
			[
				'wc_order_id'      => $order->get_id(),
				'wc_order_item_id' => $item->get_id(),
				'session_id'       => $session_id,
				'student_id'       => $order->get_customer_id() ?: 0,
				'status'           => 'pending',
				'deposit_amount'   => $deposit_amount,
				'paid_amount'      => 0.00,
				'balance_due'      => $balance_due,
				'settle_due_at'    => $settle_due_at,
			],
			[ '%d', '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s' ]
		);

		$booking_id = (int) $wpdb->insert_id;

		if ( $booking_id ) {
			AuditLogger::log(
				'booking_created',
				'booking',
				$booking_id,
				[
					'order_id'     => $order->get_id(),
					'session_id'   => $session_id,
					'payment_mode' => $payment_mode ?: 'full',
					'deposit'      => $deposit_amount,
					'balance_due'  => $balance_due,
				]
			);
		}

		return $booking_id;
	}

	/**
	 * Deposit-payment path: pending → deposit_paid.
	 *
	 * The seat is secured here — `confirm_lock()` increments seats_taken
	 * atomically so no other buyer can take the same seat while the student
	 * settles the balance.  Balance payment (confirm_balance) must NOT touch
	 * seats_taken again.
	 *
	 * @param  int    $booking_id
	 * @param  string $lock_token    From _csb_lock_token order item meta.
	 * @param  float  $deposit_paid  Line total of the deposit order.
	 * @param  int    $order_id      Deposit WC order ID, for audit context.
	 */
	public static function confirm_deposit(
		int $booking_id,
		string $lock_token,
		float $deposit_paid,
		int $order_id = 0
	): void {
		global $wpdb;

		self::transition( $booking_id, 'deposit_paid', [ 'order_id' => $order_id ] );

		$wpdb->update(
			$wpdb->prefix . 'csb_bookings',
			[ 'paid_amount' => $deposit_paid ],
			[ 'id'          => $booking_id ],
			[ '%f' ],
			[ '%d' ]
		);

		// Secure the seat immediately upon deposit payment.
		if ( $lock_token ) {
			$result = CapacityService::confirm_lock( $lock_token, $booking_id );
			if ( is_wp_error( $result ) ) {
				AuditLogger::log(
					'deposit_confirm_lock_error',
					'booking',
					$booking_id,
					[ 'error' => $result->get_error_message(), 'order_id' => $order_id ]
				);
				// Non-fatal: booking is still deposit_paid; admin can fix seats manually.
			}
		}
	}

	/**
	 * Balance-payment path: deposit_paid → fully_paid → confirmed.
	 *
	 * Does NOT touch seats_taken — the seat was already confirmed by
	 * confirm_deposit().  Only drives the state machine and accumulates
	 * paid_amount.
	 *
	 * @param  int   $booking_id
	 * @param  float $balance_paid       Line total of the balance order.
	 * @param  int   $balance_order_id
	 */
	public static function confirm_balance(
		int $booking_id,
		float $balance_paid,
		int $balance_order_id = 0
	): void {
		global $wpdb;

		self::transition( $booking_id, 'fully_paid', [ 'balance_order_id' => $balance_order_id ] );

		// Accumulate: deposit already set; add balance on top.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}csb_bookings
			    SET paid_amount = paid_amount + %f
			  WHERE id = %d",
			$balance_paid,
			$booking_id
		) );

		// Seats are already confirmed — no lock action needed here.
		self::transition( $booking_id, 'confirmed', [ 'balance_order_id' => $balance_order_id ] );
	}

	/**
	 * Full-payment confirmation path: pending → fully_paid → confirmed.
	 *
	 * Also calls CapacityService::confirm_lock() to atomically increment
	 * seats_taken and remove the transient lock row.
	 *
	 * @param  int    $booking_id
	 * @param  string $lock_token  From _csb_lock_token order item meta.
	 * @param  float  $paid_amount Line total collected by WC.
	 * @param  int    $order_id    For context logging.
	 */
	public static function confirm( int $booking_id, string $lock_token, float $paid_amount, int $order_id = 0 ): void {
		global $wpdb;

		// Update paid_amount while transitioning through fully_paid.
		self::transition( $booking_id, 'fully_paid', [ 'order_id' => $order_id ] );

		$wpdb->update(
			$wpdb->prefix . 'csb_bookings',
			[ 'paid_amount' => $paid_amount ],
			[ 'id'          => $booking_id ],
			[ '%f' ],
			[ '%d' ]
		);

		// Confirm the seat lock — increments sessions.seats_taken atomically.
		if ( $lock_token ) {
			$result = CapacityService::confirm_lock( $lock_token, $booking_id );
			if ( is_wp_error( $result ) ) {
				AuditLogger::log(
					'booking_confirm_lock_error',
					'booking',
					$booking_id,
					[ 'error' => $result->get_error_message(), 'order_id' => $order_id ]
				);
				// Continue — seat lock failure must not roll back the booking.
			}
		}

		self::transition( $booking_id, 'confirmed', [ 'order_id' => $order_id, 'paid' => $paid_amount ] );
	}

	/**
	 * Cancels a booking.
	 *
	 * Releases the transient seat lock (pre-payment) when a lock_token is given.
	 * For bookings that already hold a counted seat (deposit_paid|fully_paid|confirmed),
	 * decrements seats_taken via CapacityService::release_seat() so the seat is
	 * immediately available to other buyers.
	 *
	 * Callers that are also doing a raw seats_taken UPDATE (e.g. for legacy or
	 * redundancy) MUST be updated to call this method instead, so the decrement
	 * never happens twice.
	 *
	 * @param  int    $booking_id
	 * @param  string $reason      Short reason string for the audit log.
	 * @param  string $lock_token  Optional — released if present (pre-confirm only).
	 * @param  int    $order_id    For context.
	 */
	public static function cancel( int $booking_id, string $reason, string $lock_token = '', int $order_id = 0 ): void {
		// Release the transient lock (only matters for still-pending bookings).
		if ( $lock_token ) {
			CapacityService::release_lock( $lock_token );
		}

		// Check if the booking held a counted seat BEFORE the transition.
		$booking = self::get( $booking_id );
		$seat_statuses = [ 'deposit_paid', 'fully_paid', 'confirmed' ];
		$held_seat = $booking && in_array( $booking['status'], $seat_statuses, true );

		self::transition( $booking_id, 'cancelled', [
			'reason'   => $reason,
			'order_id' => $order_id,
		] );

		// Free the counted seat exactly once, after the transition succeeds.
		if ( $held_seat ) {
			CapacityService::release_seat( (int) $booking['session_id'] );
		}
	}

	// ── Read helper ───────────────────────────────────────────────────────────

	/**
	 * Returns the booking row array or null.
	 *
	 * @param  int $booking_id
	 * @return array<string,mixed>|null
	 */
	public static function get( int $booking_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_bookings WHERE id = %d",
			$booking_id
		), ARRAY_A );

		return $row ?: null;
	}
}
