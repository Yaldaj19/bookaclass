<?php
/**
 * WooCommerce checkout customisations for the cooking-school context.
 *
 * Phase 4 additions on top of Phase 3:
 *  1. Deposit/full radio buttons on the single-class product page.
 *  2. woocommerce_before_calculate_totals: reduce cart price to deposit amount
 *     when payment_mode = 'deposit'.
 *  3. woocommerce_add_cart_item_data: persist payment_mode + full_price +
 *     balance_due + settle_due_at in cart item data.
 *  4. woocommerce_checkout_create_order_line_item: persist all deposit meta to
 *     the order item so OrderBooking can read them.
 *
 * Phase 3 behaviour retained:
 *  — Terms checkbox.
 *  — Checkout / order string relabelling.
 *  — Hide shipping fields for virtual-only carts.
 *
 * @package BookaClass\Frontend
 */

namespace BookaClass\Frontend;

use BookaClass\Admin\SettingsPage;
use BookaClass\Services\AuditLogger;
use BookaClass\Services\CapacityService;
use BookaClass\Services\CouponService;
use BookaClass\Services\WalletService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CheckoutHooks
 */
class CheckoutHooks {

	public function init(): void {
		// ── Phase 4: deposit cart price reduction ─────────────────────────────
		add_filter( 'woocommerce_add_cart_item_data',           [ $this, 'inject_deposit_cart_data' ],   20, 2 );
		add_action( 'woocommerce_before_calculate_totals',      [ $this, 'reduce_price_for_deposit' ],   10, 1 );

		// ── Phase 3 + 4: persist all CSB meta to order item ──────────────────
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_payment_mode_to_order_item' ], 10, 3 );

		// ── Phase 6: wallet credit at checkout ───────────────────────────────────
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_wallet_checkbox' ], 5 );
		add_action( 'woocommerce_cart_calculate_fees',        [ $this, 'apply_wallet_discount' ] );
		add_action( 'woocommerce_checkout_process',           [ $this, 'validate_wallet_amount' ] );

		// ── Phase 7: CSB coupon at checkout ──────────────────────────────────
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_coupon_field' ], 6 );
		add_action( 'woocommerce_cart_calculate_fees',        [ $this, 'apply_coupon_discount' ] );
		add_action( 'woocommerce_checkout_process',           [ $this, 'validate_coupon' ] );
		add_action( 'woocommerce_checkout_create_order',      [ $this, 'save_coupon_to_order' ], 10, 2 );

		// ── Phase 3: terms checkbox ───────────────────────────────────────────
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_terms_checkbox' ] );
		add_action( 'woocommerce_checkout_process',           [ $this, 'validate_terms_checkbox' ] );

		// ── Phase 3: checkout string relabelling ──────────────────────────────
		add_filter( 'gettext', [ $this, 'relabel_checkout_strings' ], 25, 3 );

		// ── Phase 3: hide shipping address block for virtual-only carts ──────
		add_filter( 'woocommerce_checkout_fields', [ $this, 'maybe_hide_shipping_fields' ] );
	}

	// ── Deposit cart item data ────────────────────────────────────────────────

	/**
	 * Injects payment_mode, full_price, balance_due, and settle_due_at
	 * into the cart item data array for class products.
	 *
	 * Called after CartValidation::inject_cart_item_data() (priority 20 vs 10).
	 *
	 * @param  array<string,mixed> $cart_item_data
	 * @param  int                 $product_id
	 * @return array<string,mixed>
	 */
	public function inject_deposit_cart_data( array $cart_item_data, int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'class' !== $product->get_type() ) {
			return $cart_item_data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_mode = sanitize_key( $_POST['csb_payment_mode'] ?? 'full' );
		$mode     = in_array( $raw_mode, [ 'deposit', 'full' ], true ) ? $raw_mode : 'full';

		$cart_item_data['csb_payment_mode'] = $mode;

		if ( 'deposit' === $mode ) {
			$settings        = SettingsPage::get();
			$full_price      = (float) $product->get_price();
			$deposit_percent = (float) ( $settings['deposit_default_percent'] ?? 30 );

			// Per-product override if the class has its own deposit_type meta.
			/** @var \BookaClass\Products\ClassProduct $product */
			if ( method_exists( $product, 'get_csb_deposit_type' ) ) {
				$dep_type  = $product->get_csb_deposit_type();
				$dep_value = (float) $product->get_csb_deposit_value();

				if ( 'fixed' === $dep_type && $dep_value > 0 ) {
					$deposit_amount = $dep_value;
				} else {
					$pct            = $dep_value > 0 ? $dep_value : $deposit_percent;
					$deposit_amount = round( $full_price * $pct / 100, 2 );
				}
			} else {
				$deposit_amount = round( $full_price * $deposit_percent / 100, 2 );
			}

			$balance_due = round( $full_price - $deposit_amount, 2 );

			// Settle deadline: session_start − settle_days (from class meta or global default).
			$settle_due_at = $this->compute_settle_due_at( $cart_item_data, $product, $settings );

			$cart_item_data['csb_deposit_amount'] = $deposit_amount;
			$cart_item_data['csb_full_price']     = $full_price;
			$cart_item_data['csb_balance_due']    = $balance_due;
			$cart_item_data['csb_settle_due_at']  = $settle_due_at;
		}

		return $cart_item_data;
	}

	/**
	 * Reduces the cart line price to the deposit amount before WC recalculates
	 * totals (woocommerce_before_calculate_totals).
	 *
	 * @param \WC_Cart $cart
	 */
	public function reduce_price_for_deposit( \WC_Cart $cart ): void {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['csb_payment_mode'] ) ) {
				continue;
			}

			if ( 'deposit' !== $cart_item['csb_payment_mode'] ) {
				continue;
			}

			$deposit = (float) ( $cart_item['csb_deposit_amount'] ?? 0 );
			if ( $deposit <= 0 ) {
				continue;
			}

			// set_price() overrides WC's price for this calculation pass only.
			$cart_item['data']->set_price( $deposit );
		}
	}

	// ── Order item meta ───────────────────────────────────────────────────────

	/**
	 * Persists all CSB booking-related data as WC order item meta.
	 *
	 * Called for every cart item during checkout order creation.
	 * Replaces the Phase 3 version (which only saved payment_mode='full').
	 *
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array<string,mixed>    $values
	 */
	public function save_payment_mode_to_order_item(
		\WC_Order_Item_Product $item,
		string $cart_item_key,
		array $values
	): void {
		$product = $item->get_product();
		if ( ! $product || 'class' !== $product->get_type() ) {
			return;
		}

		$mode = $values['csb_payment_mode'] ?? 'full';
		// Keep _csb_payment_mode for display / compat.
		$item->add_meta_data( '_csb_payment_mode', $mode, true );

		if ( 'deposit' === $mode ) {
			// _csb_is_deposit is the routing flag read by OrderBooking.
			$item->add_meta_data( '_csb_is_deposit',  '1',                                           true );
			$item->add_meta_data( '_csb_full_price',  (float)  ( $values['csb_full_price']  ?? 0 ),  true );
			$item->add_meta_data( '_csb_balance_due', (float)  ( $values['csb_balance_due'] ?? 0 ),  true );
		}
	}

	// ── Wallet at checkout ───────────────────────────────────────────────────

	/**
	 * Renders a «استفاده از اعتبار کیف‌پول» checkbox when the student has balance.
	 * Only shown when the cart has a booking item and wallet is enabled.
	 */
	public function render_wallet_checkbox(): void {
		$settings = SettingsPage::get();
		if ( empty( $settings['enable_wallet'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! $this->cart_has_booking_items() ) {
			return;
		}

		$balance = WalletService::get_balance( get_current_user_id() );
		if ( $balance <= 0 ) {
			return;
		}

		?>
		<div class="csb-wallet-checkout" dir="rtl" style="margin:.75em 0 1em;">
			<label style="display:flex;gap:.5em;align-items:flex-start;cursor:pointer;">
				<input type="checkbox" name="csb_use_wallet" value="1"
					style="margin-top:3px;flex-shrink:0;"
					<?php checked( ! empty( WC()->session->get( 'csb_use_wallet' ) ) ); ?>>
				<span>
					<?php
					printf(
						/* translators: %s: wallet balance formatted with WC currency */
						esc_html__( 'از اعتبار کیف‌پولم (موجودی: %s) استفاده کن.', 'csb' ),
						wp_kses_post( wc_price( $balance ) )
					);
					?>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Applies the wallet balance as a negative fee (capped at cart total, never < 0).
	 *
	 * Fires on woocommerce_cart_calculate_fees so the discount is visible in
	 * the order review table.
	 *
	 * @param \WC_Cart $cart
	 */
	public function apply_wallet_discount( \WC_Cart $cart ): void {
		$settings = SettingsPage::get();
		if ( empty( $settings['enable_wallet'] ) ) {
			return;
		}

		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$use = ! empty( $_POST['csb_use_wallet'] ) || ! empty( WC()->session->get( 'csb_use_wallet' ) );

		// Store selection in session so the checkbox state survives AJAX refresh.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['csb_use_wallet'] ) ) {
			WC()->session->set( 'csb_use_wallet', ! empty( $_POST['csb_use_wallet'] ) ? '1' : '' );
		}

		if ( ! $use ) {
			return;
		}

		$balance  = WalletService::get_balance( get_current_user_id() );
		if ( $balance <= 0 ) {
			return;
		}

		$subtotal = (float) $cart->get_subtotal();
		$discount = min( $balance, $subtotal );
		if ( $discount <= 0 ) {
			return;
		}

		$cart->add_fee(
			__( 'تخفیف کیف‌پول', 'csb' ),
			-$discount,  // negative = discount
			false         // not taxable
		);
	}

	/**
	 * Validates that the wallet amount to debit does not exceed available balance.
	 * Runs on woocommerce_checkout_process; adds a WC notice on error.
	 */
	public function validate_wallet_amount(): void {
		$settings = SettingsPage::get();
		if ( empty( $settings['enable_wallet'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['csb_use_wallet'] ) ) {
			return;
		}

		$balance = WalletService::get_balance( get_current_user_id() );
		if ( $balance < 0 ) {
			wc_add_notice(
				esc_html__( 'موجودی کیف‌پول کافی نیست.', 'csb' ),
				'error'
			);
		}
	}

	// ── CSB coupon at checkout ───────────────────────────────────────────────

	/**
	 * Renders a coupon-code input field above the submit button.
	 * Only shown when the cart has a booking item.
	 */
	public function render_coupon_field(): void {
		if ( ! $this->cart_has_booking_items() ) {
			return;
		}

		$current = (string) ( WC()->session->get( 'csb_coupon_code' ) ?? '' );
		?>
		<div class="csb-coupon-field" dir="rtl" style="margin:.75em 0 1em;">
			<label for="csb_coupon_code" style="display:block;margin-bottom:.3em;font-weight:600;">
				<?php esc_html_e( 'کد تخفیف', 'csb' ); ?>
			</label>
			<div style="display:flex;gap:.5em;align-items:center;">
				<input type="text"
					id="csb_coupon_code"
					name="csb_coupon_code"
					value="<?php echo esc_attr( $current ); ?>"
					placeholder="<?php esc_attr_e( 'کد تخفیف دارید؟', 'csb' ); ?>"
					style="max-width:200px;"
					class="input-text"
				/>
			</div>
		</div>
		<?php
	}

	/**
	 * Applies a CSB coupon as a negative fee when valid.
	 *
	 * @param \WC_Cart $cart
	 */
	public function apply_coupon_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}

		if ( ! is_user_logged_in() && ! WC()->session->get( 'csb_coupon_code' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['csb_coupon_code'] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['csb_coupon_code'] ) );
			WC()->session->set( 'csb_coupon_code', $raw );
		}

		$code = (string) ( WC()->session->get( 'csb_coupon_code' ) ?? '' );
		if ( '' === $code ) {
			return;
		}

		$coupon = CouponService::get_by_code( $code );
		if ( ! $coupon ) {
			return;
		}

		// Scope check: if coupon is limited to a class, verify cart contains that class.
		if ( null !== $coupon['class_id'] ) {
			$has_class = false;
			foreach ( $cart->get_cart() as $item ) {
				if ( ( $item['data'] ?? null ) && (int) $item['product_id'] === (int) $coupon['class_id'] ) {
					$has_class = true;
					break;
				}
			}
			if ( ! $has_class ) {
				return;
			}
		}

		$subtotal = (float) $cart->get_subtotal();
		$discount = CouponService::calculate_discount( $coupon, $subtotal );
		if ( $discount <= 0 ) {
			return;
		}

		$cart->add_fee(
			sprintf(
				/* translators: %s: coupon code */
				__( 'تخفیف کد %s', 'csb' ),
				strtoupper( $code )
			),
			-$discount,
			false
		);
	}

	/**
	 * Validates coupon code at checkout process time.
	 */
	public function validate_coupon(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$code = sanitize_text_field( wp_unslash( $_POST['csb_coupon_code'] ?? '' ) );
		if ( '' === $code ) {
			WC()->session->set( 'csb_coupon_code', '' );
			return;
		}

		$coupon = CouponService::get_by_code( $code );
		if ( ! $coupon ) {
			wc_add_notice(
				esc_html__( 'کد تخفیف وارد شده معتبر نیست یا منقضی شده است.', 'csb' ),
				'error'
			);
		}
	}

	/**
	 * Saves coupon ID on the order for later usage-recording by OrderBooking.
	 *
	 * @param \WC_Order            $order
	 * @param array<string,mixed>  $data   Checkout POST data (unused here).
	 */
	public function save_coupon_to_order( \WC_Order $order, array $data ): void {
		$code = (string) ( WC()->session->get( 'csb_coupon_code' ) ?? '' );
		if ( '' === $code ) {
			return;
		}

		$coupon = CouponService::get_by_code( $code );
		if ( ! $coupon ) {
			return;
		}

		$order->update_meta_data( '_csb_coupon_id', (int) $coupon['id'] );
		$order->update_meta_data( '_csb_coupon_code', $code );
	}

	// ── Terms checkbox ────────────────────────────────────────────────────────

	/**
	 * Renders a «پذیرش قوانین لغو و استرداد» checkbox above the submit button.
	 * Only rendered when the cart contains at least one booking item.
	 */
	public function render_terms_checkbox(): void {
		if ( ! $this->cart_has_booking_items() ) {
			return;
		}

		$settings  = SettingsPage::get();
		$terms_url = (string) ( $settings['terms_page_url'] ?? '' );
		?>
		<div class="csb-terms-wrap" dir="rtl" style="margin:.75em 0 1em;">
			<label class="csb-terms-label" style="display:flex;gap:.5em;align-items:flex-start;cursor:pointer;">
				<input type="checkbox" name="csb_accept_terms" value="1"
					style="margin-top:3px;flex-shrink:0;" />
				<span>
					<?php
					if ( $terms_url ) {
						printf(
							wp_kses(
								/* translators: %s: URL of cancellation-policy page */
								__( 'قوانین لغو و استرداد آموزشگاه را خوانده‌ام و <a href="%s" target="_blank" rel="noopener noreferrer">می‌پذیرم</a>.', 'csb' ),
								[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
							),
							esc_url( $terms_url )
						);
					} else {
						esc_html_e( 'قوانین لغو و استرداد آموزشگاه را خوانده‌ام و می‌پذیرم.', 'csb' );
					}
					?>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Blocks order creation with a WC error notice if the terms box is unticked.
	 */
	public function validate_terms_checkbox(): void {
		if ( ! $this->cart_has_booking_items() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['csb_accept_terms'] ) ) {
			wc_add_notice(
				esc_html__( 'برای تکمیل رزرو، باید قوانین لغو و استرداد آموزشگاه را بپذیرید.', 'csb' ),
				'error'
			);
		}
	}

	// ── Checkout relabelling ──────────────────────────────────────────────────

	/**
	 * Replaces WC checkout strings with booking-specific wording.
	 *
	 * Guard: only fires on is_checkout() + booking cart, never in admin.
	 *
	 * @param  string $translated
	 * @param  string $original   (unused — we match translated, not source)
	 * @param  string $domain
	 * @return string
	 */
	public function relabel_checkout_strings( string $translated, string $original, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translated;
		}

		if ( is_admin() ) {
			return $translated;
		}

		if ( ! is_checkout() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $translated;
		}

		if ( ! $this->cart_has_booking_items() ) {
			return $translated;
		}

		static $map = null;
		if ( null === $map ) {
			$map = [
				'Place order'                              => 'تأیید رزرو',
				'ثبت سفارش'                                => 'تأیید رزرو',
				'Your order'                               => 'خلاصه رزرو شما',
				'سفارش شما'                                => 'خلاصه رزرو شما',
				'Order total'                              => 'مبلغ قابل پرداخت',
				'جمع سفارش'                                => 'مبلغ قابل پرداخت',
				'Thank you. Your order has been received.' => 'رزرو شما با موفقیت ثبت شد.',
				'متشکریم. سفارش شما دریافت شد.'           => 'رزرو شما با موفقیت ثبت شد.',
				'Order number:'                            => 'شماره رزرو:',
				'شماره سفارش:'                             => 'شماره رزرو:',
			];
		}

		return $map[ $translated ] ?? $translated;
	}

	// ── Shipping fields ───────────────────────────────────────────────────────

	/**
	 * Removes the shipping address section when every cart item is virtual.
	 *
	 * @param  array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public function maybe_hide_shipping_fields( array $fields ): array {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return $fields;
		}

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product && ! $product->is_virtual() ) {
				return $fields;
			}
		}

		unset( $fields['shipping'] );
		return $fields;
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Computes the settle_due_at UTC datetime string for a deposit booking.
	 *
	 * Uses the session start time minus settle_days (per-product or global).
	 *
	 * @param  array<string,mixed>             $cart_item_data  (must have csb_session_id)
	 * @param  \WC_Product                     $product
	 * @param  array<string,mixed>             $settings
	 * @return string  UTC datetime, e.g. '2026-05-10 14:00:00', or '' if unknown.
	 */
	private function compute_settle_due_at(
		array $cart_item_data,
		\WC_Product $product,
		array $settings
	): string {
		$session_id = (int) ( $cart_item_data['csb_session_id'] ?? 0 );
		if ( ! $session_id ) {
			return '';
		}

		$session = CapacityService::get_session( $session_id );
		if ( ! $session ) {
			return '';
		}

		// Per-product settle_days or global default.
		$settle_days = (int) ( $settings['settle_days_default'] ?? 3 );
		if ( method_exists( $product, 'get_csb_settle_days' ) ) {
			$per_product = (int) $product->get_csb_settle_days();
			if ( $per_product > 0 ) {
				$settle_days = $per_product;
			}
		}

		try {
			$start        = new \DateTimeImmutable( $session['start_at'], new \DateTimeZone( 'UTC' ) );
			$settle_at    = $start->modify( '-' . $settle_days . ' days' );
			return $settle_at->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Returns true when the active cart contains at least one class or video_course item.
	 */
	private function cart_has_booking_items(): bool {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return false;
		}

		foreach ( $cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product && in_array( $product->get_type(), [ 'class', 'video_course' ], true ) ) {
				return true;
			}
		}

		return false;
	}
}
