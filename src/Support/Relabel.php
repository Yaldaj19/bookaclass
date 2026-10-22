<?php
/**
 * WooCommerce string relabelling for the cooking-school context.
 *
 * Hooks into WordPress gettext filters to replace a small set of WooCommerce
 * UI strings so the storefront feels like a class-booking platform, not a
 * general shop.
 *
 * SAFETY GUARD: replacements only fire when:
 *   1. The string's text domain is 'woocommerce'.
 *   2. We are NOT on a core WC admin screen (orders, products general, etc.)
 *      that would break if labels changed.
 *
 * The mapping array is intentionally small and explicit — add entries here
 * rather than writing broad regex patterns.
 *
 * @package BookaClass\Support
 */

namespace BookaClass\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Relabel
 */
class Relabel {

	/**
	 * Core mapping: WooCommerce string → cooking-school string.
	 * Keys are exact WC source strings (Persian translations).
	 * Keep the list small and purposeful.
	 *
	 * @var array<string,string>
	 */
	private const MAP = [
		// product → دوره
		'محصول'         => 'دوره',
		'محصولات'       => 'دوره‌ها',
		// shop → کلاس‌ها
		'فروشگاه'       => 'کلاس‌ها',
		// add-to-cart CTA
		'افزودن به سبد' => 'ثبت‌نام / رزرو',
		// order → ثبت‌نام (only in customer-facing context)
		'سفارش'         => 'ثبت‌نام',
	];

	/**
	 * Attaches the gettext filter.
	 */
	public function init(): void {
		add_filter( 'gettext', [ $this, 'filter_gettext' ], 20, 3 );
	}

	/**
	 * Filters translated strings for the 'woocommerce' text domain.
	 *
	 * @param  string $translated   Already-translated string.
	 * @param  string $original     Original (untranslated) string.
	 * @param  string $domain       Text domain.
	 * @return string
	 */
	public function filter_gettext( string $translated, string $original, string $domain ): string {
		if ( 'woocommerce' !== $domain ) {
			return $translated;
		}

		// Do not relabel inside WC admin screens unrelated to our types.
		if ( $this->is_protected_admin_screen() ) {
			return $translated;
		}

		return self::MAP[ $translated ] ?? $translated;
	}

	// ─── Guard ───────────────────────────────────────────────────────────────

	/**
	 * Returns true if the current screen is a WC/WP admin page where relabelling
	 * the strings would cause confusion or break UI (e.g. WC order list, WC
	 * settings, WP menus screen).
	 *
	 * We check conservatively: only suppress on pages that are definitely NOT
	 * the cooking-school product/archive/single pages.
	 */
	private function is_protected_admin_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// get_current_screen() is only available after the 'current_screen' action.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		// Protect core WC admin screens.
		$protected_ids = [
			'edit-shop_order',
			'shop_order',
			'woocommerce_page_wc-settings',
			'woocommerce_page_wc-reports',
			'woocommerce_page_wc-status',
			'nav-menus',
			'widgets',
		];

		if ( in_array( $screen->id, $protected_ids, true ) ) {
			return true;
		}

		// Protect any post-type screen that is not one of our two types.
		if ( 'post' === $screen->base || 'edit' === $screen->base ) {
			$our_types = [ 'product' ]; // WC product is our product, relabelling is fine.
			// Block only screens for OTHER post types.
			if ( '' !== $screen->post_type && ! in_array( $screen->post_type, $our_types, true ) ) {
				return true;
			}
		}

		return false;
	}
}
