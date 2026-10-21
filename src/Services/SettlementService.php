<?php
/**
 * Settlement service — creates and manages the WC balance order for deposit bookings.
 *
 * When a deposit order is paid, this service:
 *   1. Computes settle_due_at (session.start_at − settle_days).
 *   2. Creates a WC 'pending' order with a fee line for balance_due.
 *   3. Writes _csb_parent_booking_id to the balance order.
 *   4. Writes balance_order_id + settle_due_at back to the booking row.
 *
 * Balance order payment is handled by woocommerce_payment_complete in
 * OrderBooking, which calls BookingService::confirm_balance().
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

use CookingSchoolBooking\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettlementService
 */
class SettlementService {

	/**
	 * Creates the WC balance order for a deposit booking.
	 *
	 * @param  \WC_Order              $source_order   The deposit order.
	 * @param  \WC_Order_Item_Product $source_item    The class line item.
	 * @param  int                    $booking_id
	 * @param  float                  $balance_due    Remaining amount owed.
	 * @param  int                    $session_id     Used to compute settle_due_at.
	 * @return int  New WC order ID, or 0 on failure.
	 */
	public static function create_balance_order(
		\WC_Order $source_order,
		\WC_Order_Item_Product $source_item,
		int $booking_id,
		float $balance_due,
		int $session_id
	): int {
		if ( $balance_due <= 0 ) {
			return 0;
		}

		$settle_due_at = self::compute_settle_due_at( $session_id, $source_item->get_product() );

		// ── Build the WC order ────────────────────────────────────────────────
		$balance_order = wc_create_order( [
			'customer_id'   => $source_order->get_customer_id(),
			'customer_note' => sprintf(
				/* translators: %d booking ID */
				__( 'سفارش مانده‌ی رزرو #%d', 'csb' ),
				$booking_id
			),
		] );

		if ( is_wp_error( $balance_order ) ) {
			AuditLogger::log(
				'balance_order_create_failed',
				'booking',
				$booking_id,
				[ 'error' => $balance_order->get_error_message() ]
			);
			return 0;
		}

		$balance_order->set_address( $source_order->get_address( 'billing' ), 'billing' );

		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( sprintf(
			/* translators: 1: product name  2: booking ID */
			__( 'مانده‌ی «%1$s» — رزرو #%2$d', 'csb' ),
			$source_item->get_name(),
			$booking_id
		) );
		$fee->set_amount( $balance_due );
		$fee->set_total( $balance_due );
		$fee->set_tax_class( '' );
		$balance_order->add_item( $fee );

		$balance_order->set_payment_method( $source_order->get_payment_method() );
		$balance_order->set_payment_method_title( $source_order->get_payment_method_title() );

		// Link markers: the key _csb_parent_booking_id is the routing signal
		// used by OrderBooking::on_payment_complete to identify balance orders.
		$balance_order->update_meta_data( '_csb_parent_booking_id', $booking_id );
		$balance_order->update_meta_data( '_csb_settle_due_at',     $settle_due_at );
		// Keep the lock_token so ExpireUnsettledCron can release the seat if needed.
		$balance_order->update_meta_data( '_csb_lock_token',
			(string) $source_item->get_meta( '_csb_lock_token', true )
		);

		$balance_order->calculate_totals();

		// Set status to pending — do NOT trigger the normal WC new-order email.
		// The student pays via My Account → Orders.
		remove_action( 'woocommerce_checkout_order_processed', 'WC_Emails::send_transactional_email' );
		$balance_order->update_status(
			'pending',
			esc_html__( 'سفارش مانده در انتظار پرداخت هنرجو.', 'csb' ),
			false  // suppress email
		);
		$balance_order->save();

		$balance_order_id = $balance_order->get_id();

		// ── Update booking row ────────────────────────────────────────────────
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'csb_bookings',
			[
				'balance_order_id' => $balance_order_id,
				'settle_due_at'    => $settle_due_at ?: null,
			],
			[ 'id' => $booking_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		AuditLogger::log(
			'balance_order_created',
			'booking',
			$booking_id,
			[
				'balance_order_id' => $balance_order_id,
				'balance_due'      => $balance_due,
				'settle_due_at'    => $settle_due_at,
			]
		);

		return $balance_order_id;
	}

	/**
	 * Computes the settle_due_at UTC string for a session.
	 *
	 * = session.start_at − settle_days (per-product meta, or global default).
	 * Returns '' when the session cannot be found.
	 *
	 * @param  int         $session_id
	 * @param  \WC_Product|null $product  For per-product settle_days.
	 * @return string  UTC datetime, e.g. '2026-05-10 14:00:00', or ''.
	 */
	public static function compute_settle_due_at( int $session_id, ?\WC_Product $product ): string {
		$session = CapacityService::get_session( $session_id );
		if ( ! $session ) {
			return '';
		}

		$settings    = SettingsPage::get();
		$settle_days = (int) ( $settings['settle_days_default'] ?? 3 );

		if ( $product && method_exists( $product, 'get_csb_settle_days' ) ) {
			$per = (int) $product->get_csb_settle_days();
			if ( $per > 0 ) {
				$settle_days = $per;
			}
		}

		try {
			$start = new \DateTimeImmutable( $session['start_at'], new \DateTimeZone( 'UTC' ) );
			return $start->modify( '-' . $settle_days . ' days' )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
