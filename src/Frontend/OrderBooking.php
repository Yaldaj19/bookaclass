<?php
/**
 * WC order lifecycle → booking pipeline.
 *
 * Wires WooCommerce order-status hooks to BookingService.
 * No business logic lives here — only routing between WC events and services.
 *
 * Payment paths:
 *
 *   Full payment (payment_mode = 'full'):
 *     woocommerce_order_status_processing/completed
 *       → create booking (pending)
 *       → BookingService::confirm()   (pending → fully_paid → confirmed + lock→seats_taken++)
 *
 *   Deposit payment (_csb_is_deposit = 1 on order item):
 *     woocommerce_order_status_processing/completed
 *       → create booking (pending)
 *       → BookingService::confirm_deposit()  (pending → deposit_paid + confirm_lock() → seats_taken++)
 *       → SettlementService::create_balance_order()  (WC pending order for remaining amount)
 *
 *   Balance payment (order has _csb_parent_booking_id):
 *     woocommerce_payment_complete
 *       → BookingService::confirm_balance()  (deposit_paid → fully_paid → confirmed)
 *         (NO seats_taken change — already counted at deposit step)
 *
 * Idempotency:
 *   Primary order: _csb_booking_id on order item guards double-creation.
 *   Balance order: _csb_balance_settled on balance order guards double-confirmation.
 *
 * @package BookaClass\Frontend
 */

namespace BookaClass\Frontend;

use BookaClass\Services\AuditLogger;
use BookaClass\Services\BookingService;
use BookaClass\Services\CapacityService;
use BookaClass\Services\CouponService;
use BookaClass\Services\EnrollmentService;
use BookaClass\Services\SettlementService;
use BookaClass\Services\WalletService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderBooking
 */
class OrderBooking {

	public function init(): void {
		// Primary order (deposit or full) confirmed by gateway.
		add_action( 'woocommerce_order_status_processing', [ $this, 'on_payment_confirmed' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed',  [ $this, 'on_payment_confirmed' ], 10, 1 );

		// Wallet debit: fires for every paid primary order (idempotent via order meta).
		add_action( 'woocommerce_payment_complete',        [ $this, 'debit_wallet_if_used' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'debit_wallet_if_used' ], 25, 1 );

		// Coupon usage: record once per paid order (idempotent via order meta).
		add_action( 'woocommerce_payment_complete',        [ $this, 'record_coupon_usage' ], 21, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'record_coupon_usage' ], 26, 1 );

		// Balance order paid — woocommerce_payment_complete fires before status changes.
		add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ], 10, 1 );

		// Video course enrollment granted on order completion.
		add_action( 'woocommerce_order_status_completed',  [ $this, 'grant_video_enrollment' ], 20, 1 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'grant_video_enrollment' ], 20, 1 );

		// Order voided.
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_voided' ], 10, 1 );
		add_action( 'woocommerce_order_status_failed',    [ $this, 'on_order_voided' ], 10, 1 );
	}

	// ── Primary order paid ────────────────────────────────────────────────────

	/**
	 * @param int $order_id
	 */
	public function on_payment_confirmed( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Balance orders are handled by on_payment_complete — skip here.
		if ( $order->get_meta( '_csb_parent_booking_id' ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product || 'class' !== $product->get_type() ) {
				continue;
			}

			// Idempotency guard.
			if ( (int) $item->get_meta( '_csb_booking_id', true ) > 0 ) {
				continue;
			}

			$session_id = (int) $item->get_meta( '_csb_session_id', true );
			$lock_token = (string) $item->get_meta( '_csb_lock_token', true );
			$is_deposit = (bool) $item->get_meta( '_csb_is_deposit', true );

			if ( ! $session_id ) {
				continue;
			}

			$booking_id = BookingService::create_from_order_item( $order, $item );
			if ( ! $booking_id ) {
				AuditLogger::log( 'booking_create_failed', 'order_item', $item->get_id(), [
					'order_id'   => $order_id,
					'session_id' => $session_id,
				] );
				continue;
			}

			$item->update_meta_data( '_csb_booking_id', $booking_id );
			$item->save_meta_data();

			if ( $is_deposit ) {
				$this->process_deposit_paid( $order, $item, $booking_id, $lock_token, $session_id );
			} else {
				$this->process_full_payment( $order, $item, $booking_id, $lock_token );
			}
		}
	}

	// ── Full payment ──────────────────────────────────────────────────────────

	private function process_full_payment(
		\WC_Order $order,
		\WC_Order_Item_Product $item,
		int $booking_id,
		string $lock_token
	): void {
		try {
			BookingService::confirm( $booking_id, $lock_token, (float) $item->get_total(), $order->get_id() );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			AuditLogger::log( 'booking_confirm_error', 'booking', $booking_id, [
				'error'    => $e->getMessage(),
				'order_id' => $order->get_id(),
			] );
			return;
		}

		$order->add_order_note( sprintf(
			/* translators: 1: booking ID  2: session ID */
			esc_html__( 'رزرو #%1$d جلسه #%2$d تأیید شد (پرداخت کامل).', 'csb' ),
			$booking_id,
			(int) $item->get_meta( '_csb_session_id', true )
		) );
	}

	// ── Deposit paid ──────────────────────────────────────────────────────────

	/**
	 * Seat secured here via confirm_deposit (confirm_lock inside).
	 */
	private function process_deposit_paid(
		\WC_Order $order,
		\WC_Order_Item_Product $item,
		int $booking_id,
		string $lock_token,
		int $session_id
	): void {
		try {
			BookingService::confirm_deposit(
				$booking_id,
				$lock_token,
				(float) $item->get_total(),
				$order->get_id()
			);
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			AuditLogger::log( 'booking_deposit_confirm_error', 'booking', $booking_id, [
				'error'    => $e->getMessage(),
				'order_id' => $order->get_id(),
			] );
			return;
		}

		$balance_due = (float) $item->get_meta( '_csb_balance_due', true );
		if ( $balance_due > 0 ) {
			SettlementService::create_balance_order(
				$order,
				$item,
				$booking_id,
				$balance_due,
				$session_id
			);
		}

		$order->add_order_note( sprintf(
			/* translators: 1: booking ID  2: session ID */
			esc_html__( 'بیعانه‌ی رزرو #%1$d جلسه #%2$d دریافت شد. صندلی قطعی شد. سفارش مانده ساخته شد.', 'csb' ),
			$booking_id,
			$session_id
		) );
	}

	// ── Balance payment ───────────────────────────────────────────────────────

	/**
	 * woocommerce_payment_complete — fires for every paid order.
	 * Routes only balance orders (those carrying _csb_parent_booking_id).
	 *
	 * @param int $order_id
	 */
	public function on_payment_complete( int $order_id ): void {
		$order      = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$booking_id = (int) $order->get_meta( '_csb_parent_booking_id' );
		if ( ! $booking_id ) {
			return;
		}

		// Idempotency.
		if ( $order->get_meta( '_csb_balance_settled' ) ) {
			return;
		}

		$booking = BookingService::get( $booking_id );
		if ( ! $booking || 'deposit_paid' !== $booking['status'] ) {
			return;
		}

		try {
			// No seat change — seats_taken was incremented at deposit step.
			BookingService::confirm_balance( $booking_id, (float) $order->get_total(), $order_id );
		} catch ( \InvalidArgumentException | \RuntimeException $e ) {
			AuditLogger::log( 'balance_confirm_error', 'booking', $booking_id, [
				'error'    => $e->getMessage(),
				'order_id' => $order_id,
			] );
			return;
		}

		$order->update_meta_data( '_csb_balance_settled', '1' );
		$order->save_meta_data();

		$order->add_order_note( sprintf(
			/* translators: %d booking ID */
			esc_html__( 'مانده‌ی رزرو #%d پرداخت شد. رزرو قطعی شد.', 'csb' ),
			$booking_id
		) );
	}

	// ── Video course enrollment ───────────────────────────────────────────────

	/**
	 * Grants enrollment for every video_course item in a completed/processing order.
	 *
	 * Idempotent: EnrollmentService::grant() returns the existing row on re-runs.
	 * Fires on both processing and completed so digital-only checkouts get
	 * access immediately (many payment gateways go processing → completed).
	 *
	 * @param int $order_id
	 */
	public function grant_video_enrollment( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Balance orders are class-booking orders; skip.
		if ( $order->get_meta( '_csb_parent_booking_id' ) ) {
			return;
		}

		$student_id = (int) $order->get_customer_id();
		if ( ! $student_id ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product || 'video_course' !== $product->get_type() ) {
				continue;
			}

			// Idempotency: skip if already enrolled via this order item.
			if ( $item->get_meta( '_csb_enrollment_granted', true ) ) {
				continue;
			}

			/** @var \BookaClass\Products\VideoCourseProduct $product */
			$duration_days = $product->get_csb_access_duration() ?: null;

			try {
				$enroll_id = EnrollmentService::grant(
					$student_id,
					$product->get_id(),
					$order_id,
					$duration_days
				);
			} catch ( \RuntimeException $e ) {
				AuditLogger::log( 'enrollment_grant_failed', 'order_item', $item->get_id(), [
					'student_id' => $student_id,
					'course_id'  => $product->get_id(),
					'order_id'   => $order_id,
					'error'      => $e->getMessage(),
				] );
				continue;
			}

			$item->update_meta_data( '_csb_enrollment_granted', $enroll_id );
			$item->save_meta_data();

			$order->add_order_note( sprintf(
				/* translators: 1: student user ID  2: course product ID  3: enrollment row ID */
				esc_html__( 'دسترسی به دوره #%2$d برای هنرجو #%1$d اعطا شد (ثبت‌نام #%3$d).', 'csb' ),
				$student_id,
				$product->get_id(),
				$enroll_id
			) );
		}
	}

	// ── Wallet debit ──────────────────────────────────────────────────────────

	/**
	 * Debits wallet credit that was applied at checkout.
	 *
	 * Idempotent: guarded by `_csb_wallet_debited` order meta so the debit only
	 * fires once even when both woocommerce_payment_complete and
	 * woocommerce_order_status_processing fire on the same order.
	 *
	 * The fee name «تخفیف کیف‌پول» matches what CheckoutHooks::apply_wallet_discount()
	 * registers; the absolute value of the negative fee is the debit amount.
	 *
	 * @param int $order_id
	 */
	public function debit_wallet_if_used( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip balance orders.
		if ( $order->get_meta( '_csb_parent_booking_id' ) ) {
			return;
		}

		// Idempotency guard.
		if ( $order->get_meta( '_csb_wallet_debited' ) ) {
			return;
		}

		$student_id = (int) $order->get_customer_id();
		if ( ! $student_id ) {
			return;
		}

		// Find the wallet-discount fee on this order.
		$wallet_debit = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			/** @var \WC_Order_Item_Fee $fee */
			$fee_name = (string) $fee->get_name();
			if ( false !== mb_strpos( $fee_name, 'کیف‌پول' ) ) {
				// Fee amount is negative (discount); debit the absolute value.
				$wallet_debit += abs( (float) $fee->get_total() );
			}
		}

		if ( $wallet_debit <= 0 ) {
			return;
		}

		$balance_after = WalletService::debit(
			$student_id,
			$wallet_debit,
			'checkout_debit',
			(string) $order_id
		);

		$order->update_meta_data( '_csb_wallet_debited', '1' );
		$order->save_meta_data();

		AuditLogger::log( 'wallet_checkout_debit', 'order', $order_id, [
			'student_id'    => $student_id,
			'debit_amount'  => $wallet_debit,
			'balance_after' => $balance_after,
		] );
	}

	// ── Coupon usage recording ────────────────────────────────────────────────

	/**
	 * Records CSB coupon usage when a primary order is paid.
	 *
	 * Idempotent: `_csb_coupon_recorded` order meta prevents double-counting.
	 *
	 * @param int $order_id
	 */
	public function record_coupon_usage( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Skip balance orders.
		if ( $order->get_meta( '_csb_parent_booking_id' ) ) {
			return;
		}

		// Idempotency guard.
		if ( $order->get_meta( '_csb_coupon_recorded' ) ) {
			return;
		}

		$coupon_id = (int) $order->get_meta( '_csb_coupon_id' );
		if ( ! $coupon_id ) {
			return;
		}

		CouponService::record_usage( $coupon_id );

		$order->update_meta_data( '_csb_coupon_recorded', '1' );
		$order->save_meta_data();

		AuditLogger::log( 'coupon_used', 'order', $order_id, [
			'coupon_id'   => $coupon_id,
			'coupon_code' => (string) $order->get_meta( '_csb_coupon_code' ),
		] );
	}

	// ── Order voided ──────────────────────────────────────────────────────────

	/**
	 * @param int $order_id
	 */
	public function on_order_voided( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Balance order voided while booking is still deposit_paid.
		if ( $order->get_meta( '_csb_parent_booking_id' ) ) {
			$booking_id = (int) $order->get_meta( '_csb_parent_booking_id' );
			if ( ! $booking_id ) {
				return;
			}

			$booking = BookingService::get( $booking_id );
			if ( $booking && 'deposit_paid' === $booking['status'] ) {
				// BookingService::cancel() calls CapacityService::release_seat() for
				// deposit_paid bookings — no separate inline decrement needed.
				try {
					BookingService::cancel( $booking_id, 'balance_order_voided', '', $order_id );
				} catch ( \InvalidArgumentException | \RuntimeException $e ) {
					AuditLogger::log( 'balance_void_cancel_error', 'booking', $booking_id,
						[ 'error' => $e->getMessage() ]
					);
				}

				$order->add_order_note( sprintf(
					/* translators: %d booking ID */
					esc_html__( 'رزرو #%d به دلیل لغو سفارش مانده کنسل شد.', 'csb' ),
					$booking_id
				) );
			}
			return;
		}

		// Primary order voided.
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product || 'class' !== $product->get_type() ) {
				continue;
			}

			$lock_token = (string) $item->get_meta( '_csb_lock_token', true );
			$booking_id = (int) $item->get_meta( '_csb_booking_id', true );

			if ( $lock_token ) {
				CapacityService::release_lock( $lock_token );
			}

			if ( ! $booking_id ) {
				continue;
			}

			$booking  = BookingService::get( $booking_id );
			$terminal = [ 'cancelled', 'completed', 'expired', 'no_show' ];
			if ( ! $booking || in_array( $booking['status'], $terminal, true ) ) {
				continue;
			}

			try {
				BookingService::cancel( $booking_id, 'order_voided', '', $order_id );
			} catch ( \InvalidArgumentException | \RuntimeException $e ) {
				AuditLogger::log( 'booking_cancel_error', 'booking', $booking_id,
					[ 'error' => $e->getMessage(), 'order_id' => $order_id ]
				);
			}

			$order->add_order_note( sprintf(
				/* translators: %d booking ID */
				esc_html__( 'رزرو #%d لغو شد.', 'csb' ),
				$booking_id
			) );
		}
	}
}
