<?php
/**
 * WooCommerce cart / checkout validation for class bookings.
 *
 * Responsibilities:
 *  — Validate that a session_id is attached when adding a class product to cart.
 *  — Call CapacityService::acquire_lock() to reserve seats atomically.
 *  — Attach session_id + lock_token as cart item data so they survive the
 *    checkout flow.
 *  — Release locks when cart items are removed or the cart is cleared.
 *  — Display a human-readable session summary in the cart/checkout tables.
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

use CookingSchoolBooking\Services\CapacityService;
use CookingSchoolBooking\Support\Jalali;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CartValidation
 */
class CartValidation {

	public function init(): void {
		// Validate before the product is added to the cart.
		add_filter( 'woocommerce_add_to_cart_validation',       [ $this, 'validate_add_to_cart' ],    10, 5 );

		// Persist session data alongside the cart item.
		add_filter( 'woocommerce_add_cart_item_data',           [ $this, 'inject_cart_item_data' ],   10, 2 );

		// Make cart item data visible in cart/checkout rows.
		add_filter( 'woocommerce_get_item_data',                [ $this, 'display_cart_item_data' ],  10, 2 );

		// Re-validate capacity when cart is loaded from session (page refresh).
		add_filter( 'woocommerce_cart_item_quantity',           [ $this, 'maybe_update_lock_expiry' ], 10, 2 );

		// Release lock when an item is removed from the cart.
		add_action( 'woocommerce_cart_item_removed',            [ $this, 'on_item_removed' ],         10, 2 );

		// Release all locks when the cart is cleared.
		add_action( 'woocommerce_cart_emptied',                 [ $this, 'on_cart_cleared' ] );

		// Store session data as WC order item meta so it persists post-checkout.
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_item_meta' ], 10, 3 );

		// Refresh lock expiry at checkout validation (wc_get_checkout_fields fires here).
		add_action( 'woocommerce_checkout_process',             [ $this, 'revalidate_locks_on_checkout' ] );
	}

	// ── add-to-cart validation ────────────────────────────────────────────────

	/**
	 * Called by WC before the item lands in the cart.
	 *
	 * For class products we require:
	 *  — A `csb_session_id` in $_POST.
	 *  — The session to be scheduled and have available capacity.
	 *
	 * @param  bool   $valid
	 * @param  int    $product_id
	 * @param  int    $quantity
	 * @param  int    $variation_id   (unused)
	 * @param  array  $variations     (unused)
	 * @return bool
	 */
	public function validate_add_to_cart(
		bool $valid,
		int $product_id,
		int $quantity,
		int $variation_id = 0,
		array $variations = []
	): bool {
		if ( ! $valid ) {
			return false;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'class' !== $product->get_type() ) {
			return $valid;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = (int) ( $_POST['csb_session_id'] ?? 0 );

		if ( ! $session_id ) {
			wc_add_notice(
				esc_html__( 'لطفاً یک جلسه را برای رزرو انتخاب کنید.', 'csb' ),
				'error'
			);
			return false;
		}

		$session = CapacityService::get_session( $session_id );

		if ( ! $session || 'scheduled' !== $session['status'] ) {
			wc_add_notice(
				esc_html__( 'جلسه انتخاب‌شده در دسترس نیست.', 'csb' ),
				'error'
			);
			return false;
		}

		if ( (int) $session['class_id'] !== $product_id ) {
			wc_add_notice(
				esc_html__( 'جلسه انتخاب‌شده متعلق به این کلاس نیست.', 'csb' ),
				'error'
			);
			return false;
		}

		// Build a deterministic lock token from the WC session + product + session.
		$lock_token = self::build_lock_token( $session_id, $product_id );

		$result = CapacityService::acquire_lock( $session_id, $quantity, $lock_token );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( esc_html( $result->get_error_message() ), 'error' );
			return false;
		}

		return true;
	}

	// ── Cart item data ────────────────────────────────────────────────────────

	/**
	 * Injects our booking data into the cart item array so it survives the
	 * session serialisation round-trip.
	 *
	 * @param  array<string,mixed> $cart_item_data
	 * @param  int                 $product_id
	 * @return array<string,mixed>
	 */
	public function inject_cart_item_data( array $cart_item_data, int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'class' !== $product->get_type() ) {
			return $cart_item_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = (int) ( $_POST['csb_session_id'] ?? 0 );

		if ( $session_id ) {
			$cart_item_data['csb_session_id'] = $session_id;
			$cart_item_data['csb_lock_token'] = self::build_lock_token( $session_id, $product_id );
		}

		return $cart_item_data;
	}

	/**
	 * Renders the Jalali session date in cart/checkout item rows.
	 *
	 * @param  array<string,mixed> $item_data
	 * @param  array<string,mixed> $cart_item
	 * @return array<string,mixed>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item['csb_session_id'] ) ) {
			return $item_data;
		}

		$session = CapacityService::get_session( (int) $cart_item['csb_session_id'] );

		if ( ! $session ) {
			return $item_data;
		}

		$jalali_date = Jalali::toJalali( $session['start_at'] );

		$item_data[] = [
			'key'   => esc_html__( 'تاریخ جلسه', 'csb' ),
			'value' => esc_html( $jalali_date ),
		];

		if ( ! empty( $session['location'] ) ) {
			$item_data[] = [
				'key'   => esc_html__( 'محل برگزاری', 'csb' ),
				'value' => esc_html( $session['location'] ),
			];
		}

		// Show payment mode label when deposit is selected.
		if ( ! empty( $cart_item['csb_payment_mode'] ) && 'deposit' === $cart_item['csb_payment_mode'] ) {
			$balance_due = (float) ( $cart_item['csb_balance_due'] ?? 0 );
			$item_data[] = [
				'key'   => esc_html__( 'روش پرداخت', 'csb' ),
				'value' => sprintf(
					/* translators: %s: balance amount formatted with WC currency */
					esc_html__( 'بیعانه (مانده: %s)', 'csb' ),
					wp_kses_post( wc_price( $balance_due ) )
				),
			];
		}

		return $item_data;
	}

	// ── Lock refresh ─────────────────────────────────────────────────────────

	/**
	 * Extends the lock expiry to keep it alive while the customer is on the
	 * cart page.  Called when the quantity field is rendered.
	 *
	 * We silently re-acquire (upsert) rather than noisily error here because
	 * the actual error surface is checkout.  Returns the original $quantity
	 * unchanged — this is purely a side-effect hook.
	 *
	 * @param  string $quantity_html
	 * @param  string $cart_item_key
	 * @return string
	 */
	public function maybe_update_lock_expiry( string $quantity_html, string $cart_item_key ): string {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return $quantity_html;
		}

		$cart_item = $cart->get_cart_item( $cart_item_key );
		if ( empty( $cart_item['csb_session_id'] ) || empty( $cart_item['csb_lock_token'] ) ) {
			return $quantity_html;
		}

		CapacityService::acquire_lock(
			(int) $cart_item['csb_session_id'],
			(int) $cart_item['quantity'],
			(string) $cart_item['csb_lock_token']
		);

		return $quantity_html;
	}

	// ── Cart removed / cleared ────────────────────────────────────────────────

	/**
	 * Releases the seat lock when a cart item is removed.
	 *
	 * @param  string               $cart_item_key
	 * @param  \WC_Cart             $cart
	 */
	public function on_item_removed( string $cart_item_key, \WC_Cart $cart ): void {
		$removed = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

		if ( $removed && ! empty( $removed['csb_lock_token'] ) ) {
			CapacityService::release_lock( (string) $removed['csb_lock_token'] );
		}
	}

	/**
	 * Releases all class-type seat locks when the entire cart is emptied.
	 */
	public function on_cart_cleared(): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_removed_cart_contents() as $item ) {
			if ( ! empty( $item['csb_lock_token'] ) ) {
				CapacityService::release_lock( (string) $item['csb_lock_token'] );
			}
		}
	}

	// ── Checkout process re-validation ────────────────────────────────────────

	/**
	 * Re-checks capacity for every class item in the cart just before order
	 * creation.  Adds WC notices (which abort the order) if a session has
	 * become unavailable.
	 */
	public function revalidate_locks_on_checkout(): void {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['csb_session_id'] ) ) {
				continue;
			}

			$session_id = (int) $cart_item['csb_session_id'];
			$lock_token = (string) ( $cart_item['csb_lock_token'] ?? '' );
			$qty        = (int) $cart_item['quantity'];

			// Re-acquire to extend TTL; will fail if capacity is gone.
			$result = CapacityService::acquire_lock( $session_id, $qty, $lock_token );

			if ( is_wp_error( $result ) ) {
				wc_add_notice( esc_html( $result->get_error_message() ), 'error' );
			}
		}
	}

	// ── Order item meta ───────────────────────────────────────────────────────

	/**
	 * Saves csb_session_id and csb_lock_token as WC order item meta.
	 *
	 * These are read by the booking creation logic in Phase 3.
	 *
	 * @param  \WC_Order_Item_Product $item
	 * @param  string                 $cart_item_key
	 * @param  array<string,mixed>    $values
	 */
	public function save_order_item_meta(
		\WC_Order_Item_Product $item,
		string $cart_item_key,
		array $values
	): void {
		if ( ! empty( $values['csb_session_id'] ) ) {
			$item->add_meta_data( '_csb_session_id', (int) $values['csb_session_id'], true );
		}

		if ( ! empty( $values['csb_lock_token'] ) ) {
			$item->add_meta_data( '_csb_lock_token', sanitize_text_field( $values['csb_lock_token'] ), true );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Builds a stable, scoped lock token for a given WC session + session_id.
	 *
	 * Uses the WC session customer ID so the same browser tab always produces
	 * the same token (idempotent re-adds don't inflate lock counts).
	 *
	 * @param  int $session_id
	 * @param  int $product_id
	 * @return string  64-char hex string.
	 */
	public static function build_lock_token( int $session_id, int $product_id ): string {
		$wc_session_id = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$wc_session_id = (string) WC()->session->get_customer_id();
		}

		return hash( 'sha256', implode( '|', [ $wc_session_id, $session_id, $product_id ] ) );
	}
}
