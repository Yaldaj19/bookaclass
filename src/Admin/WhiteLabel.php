<?php
/**
 * Hides WooCommerce admin menus and reorders the «آموزشگاه» submenu so the
 * WordPress dashboard looks like a self-contained class-booking platform.
 *
 * WooCommerce remains fully functional underneath (orders, cart, payments).
 * Nothing here touches WC's functional internals — only presentation.
 *
 * @package BookaClass\Admin
 */

namespace BookaClass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WhiteLabel
 */
class WhiteLabel {

	/**
	 * WC top-level menu slugs to remove.
	 * Keyed by slug for easy guard checks.
	 *
	 * @var string[]
	 */
	private const WC_TOP_MENUS = [
		'woocommerce',            // WC home / settings hub
		'woocommerce-marketing',  // Marketing
		'edit.php?post_type=product', // Products (standalone top-level)
	];

	/**
	 * WC Analytics menu slug — WC registers it with %2F-encoded path.
	 * We check for both the encoded and decoded variants in remove_wc_menus().
	 */
	private const WC_ANALYTICS_SLUG = 'wc-admin&path=%2Fanalytics%2Foverview';

	/**
	 * Desired submenu order under csb-dashboard.
	 * Slugs must exactly match what each admin page registers.
	 *
	 * @var string[]
	 */
	private const SUBMENU_ORDER = [
		'csb-dashboard',            // داشبورد
		// these two are URL-slugs registered directly in Plugin::add_admin_menu
		'edit.php?post_type=product&product_type=class',
		'edit.php?post_type=product&product_type=video_course',
		'csb-sessions',             // جلسات
		'csb-bookings',             // رزروها
		'csb-calendar',             // تقویم
		'csb-attendance',           // حضور و غیاب
		'csb-refund-requests',      // استردادها
		'csb-coupons',              // کوپن‌ها
		'csb-financial-report',     // گزارش مالی
		'csb-settings',             // تنظیمات
		'csb-appearance',           // ظاهر
	];

	// ─── Boot ────────────────────────────────────────────────────────────────

	/**
	 * Attaches all hooks.
	 */
	public function init(): void {
		// Remove WC standalone menus after everything is registered.
		add_action( 'admin_menu', [ $this, 'remove_wc_menus' ], 999 );

		// Reorder the «آموزشگاه» submenu (must run after all sub-pages register).
		add_action( 'admin_menu', [ $this, 'reorder_csb_submenu' ], 9999 );

		// Correct parent/submenu highlights when editing products.
		add_filter( 'parent_file',  [ $this, 'fix_parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'fix_submenu_file' ], 10, 2 );

		// Hide brand/tag taxonomy metaboxes on class/video_course product screens.
		add_action( 'admin_head', [ $this, 'suppress_brand_metabox' ] );
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'hide_brand_data_tab' ] );

		// Scrub remaining «WooCommerce» strings in admin chrome.
		add_filter( 'admin_footer_text', [ $this, 'filter_footer_text' ] );
		add_action( 'admin_head',        [ $this, 'hide_wc_dashboard_widgets' ] );
		add_filter( 'gettext',           [ $this, 'scrub_wc_label' ], 30, 3 );

		// Filter product list query so our type-filtered URLs work correctly.
		add_action( 'parse_request', [ $this, 'filter_product_list_by_type' ] );
	}

	// ─── 1. Remove WC standalone menus ───────────────────────────────────────

	/**
	 * Removes WooCommerce top-level admin menu items.
	 * Only runs when ecommerce mode is off.
	 */
	public function remove_wc_menus(): void {
		if ( $this->is_ecommerce_mode() ) {
			return;
		}

		global $menu, $submenu;

		// ── Remove registered top-level menus ─────────────────────────────────
		foreach ( self::WC_TOP_MENUS as $slug ) {
			// Guard: remove_menu_page won't fatal but double-check presence.
			if ( $this->top_menu_exists( $slug ) ) {
				remove_menu_page( $slug );
			}
		}

		// ── Analytics: WC registers with %2F-encoded or plain / path ──────────
		foreach ( [
			'wc-admin&path=%2Fanalytics%2Foverview',
			'wc-admin&path=/analytics/overview',
		] as $analytics_slug ) {
			if ( $this->top_menu_exists( $analytics_slug ) ) {
				remove_menu_page( $analytics_slug );
			}
		}

		// ── Also sweep any wc-admin path= items not caught above ──────────────
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				$s = $item[2] ?? '';
				if ( 0 === strpos( $s, 'wc-admin&path=' ) || 0 === strpos( $s, 'wc-admin&amp;path=' ) ) {
					remove_menu_page( $s );
				}
			}
		}

		// ── Sweep any remaining product sub-menu items under woocommerce ──────
		if ( isset( $submenu['woocommerce'] ) ) {
			unset( $submenu['woocommerce'] );
		}

		// ── Also remove any product/* sub-menus that leaked under the product
		//    top-level before we removed it (WC sometimes registers them late) ─
		if ( isset( $submenu['edit.php?post_type=product'] ) ) {
			foreach ( array_keys( $submenu['edit.php?post_type=product'] ) as $idx ) {
				$sub_slug = $submenu['edit.php?post_type=product'][ $idx ][2] ?? '';
				// Keep only sub-items we've intentionally placed under csb-dashboard.
				// Everything else (Attributes, Tags, Brands …) goes.
				remove_submenu_page( 'edit.php?post_type=product', $sub_slug );
			}
		}
	}

	// ─── 2 & 3. Highlight correction + submenu reorder ───────────────────────

	/**
	 * When the admin is on a product list/edit screen, highlight «آموزشگاه»
	 * in the left nav instead of the now-removed «Products».
	 *
	 * @param  string $parent_file
	 * @return string
	 */
	public function fix_parent_file( $parent_file ) {
		global $pagenow, $typenow;

		if ( $this->is_ecommerce_mode() ) {
			return $parent_file;
		}

		if ( 'product' === $typenow && in_array( $pagenow, [ 'edit.php', 'post.php', 'post-new.php' ], true ) ) {
			return 'csb-dashboard';
		}

		return $parent_file;
	}

	/**
	 * Highlights the correct sub-item (کلاس‌ها / دوره‌های ویدیویی) when the
	 * admin is on a filtered product list screen.
	 *
	 * @param  string $submenu_file
	 * @param  string $parent_file
	 * @return string
	 */
	public function fix_submenu_file( $submenu_file, $parent_file = null ) {
		if ( $this->is_ecommerce_mode() ) {
			return $submenu_file;
		}

		global $pagenow, $typenow;

		if ( 'csb-dashboard' !== $parent_file || 'product' !== $typenow ) {
			return $submenu_file;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = sanitize_key( $_GET['product_type'] ?? '' );

		if ( 'class' === $type ) {
			return admin_url( 'edit.php?post_type=product&product_type=class' );
		}

		if ( 'video_course' === $type ) {
			return admin_url( 'edit.php?post_type=product&product_type=video_course' );
		}

		// New-post screen — highlight the type-specific item when coming from it.
		if ( 'post-new.php' === $pagenow ) {
			return admin_url( 'edit.php?post_type=product&product_type=class' );
		}

		return $submenu_file;
	}

	/**
	 * Reorders $GLOBALS['submenu']['csb-dashboard'] to match SUBMENU_ORDER.
	 * Any slug not in the desired order list is appended at the end unchanged.
	 */
	public function reorder_csb_submenu(): void {
		global $submenu;

		if ( empty( $submenu['csb-dashboard'] ) || ! is_array( $submenu['csb-dashboard'] ) ) {
			return;
		}

		$ordered     = [];
		$by_slug     = [];

		foreach ( $submenu['csb-dashboard'] as $item ) {
			// $item[2] is the slug/URL.
			$slug = $item[2] ?? '';
			// Normalize admin_url-based slugs: strip the admin_url prefix if present.
			$normalized = $this->normalize_submenu_slug( $slug );
			$by_slug[ $normalized ] = $item;
		}

		foreach ( self::SUBMENU_ORDER as $desired_slug ) {
			$normalized = $this->normalize_submenu_slug( $desired_slug );
			if ( isset( $by_slug[ $normalized ] ) ) {
				$ordered[] = $by_slug[ $normalized ];
				unset( $by_slug[ $normalized ] );
			}
		}

		// Append anything not in our order list at the tail.
		foreach ( $by_slug as $item ) {
			$ordered[] = $item;
		}

		$submenu['csb-dashboard'] = $ordered;
	}

	// ─── 4. Hide brand / tag taxonomy metaboxes ──────────────────────────────

	/**
	 * Removes brand and tag metaboxes from the product edit screen for our
	 * class/video_course product types.
	 */
	public function suppress_brand_metabox(): void {
		global $typenow, $pagenow;

		if ( 'product' !== $typenow ) {
			return;
		}

		if ( ! in_array( $pagenow, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		// Only suppress when editing one of our types.
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $post_id > 0 ) {
			$type = get_post_meta( $post_id, '_product_type', true );
			if ( ! $type ) {
				// WC stores type via term; fall back to WC product object when available.
				if ( function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post_id );
					if ( $product ) {
						$type = $product->get_type();
					}
				}
			}
			if ( $type && ! in_array( $type, [ 'class', 'video_course' ], true ) ) {
				return; // Not our type — leave metaboxes intact.
			}
		}

		// Remove brand, tag and attribute taxonomies from sidebar metaboxes.
		$taxonomies_to_hide = [
			'product_brand',
			'product_tag',
			// Some plugins register pa_* terms; we only hide the UI wrapper here.
		];

		foreach ( $taxonomies_to_hide as $tax ) {
			remove_meta_box( 'tagsdiv-' . $tax, 'product', 'side' );
			remove_meta_box( $tax . 'div',       'product', 'side' );
		}
	}

	/**
	 * Remove any «Brands» tab from the WC product data panel for our types.
	 *
	 * @param  array<string,array<string,mixed>> $tabs
	 * @return array<string,array<string,mixed>>
	 */
	public function hide_brand_data_tab( array $tabs ): array {
		// Key varies by brand plugin — typical keys: 'brands', 'product_brand'.
		foreach ( [ 'brands', 'product_brand', 'wc_brands' ] as $key ) {
			unset( $tabs[ $key ] );
		}

		return $tabs;
	}

	// ─── 5. Scrub «WooCommerce» wording in admin chrome ──────────────────────

	/**
	 * Replaces the WP admin footer text on our screens.
	 *
	 * @param  string $text
	 * @return string
	 */
	public function filter_footer_text( string $text ): string {
		if ( $this->is_csb_screen() ) {
			return sprintf(
				/* translators: plugin name. */
				esc_html__( 'سپاس از استفاده از %s', 'csb' ),
				'<strong>' . esc_html__( 'آموزشگاه', 'csb' ) . '</strong>'
			);
		}

		return $text;
	}

	/**
	 * Injects CSS to hide WC setup/activity widgets on our dashboard screens.
	 * These widgets appear as WP dashboard meta-boxes (`.woocommerce_dashboard_*`).
	 */
	public function hide_wc_dashboard_widgets(): void {
		if ( ! $this->is_csb_screen() ) {
			return;
		}

		echo '<style>
			.woocommerce_dashboard_status,
			.woocommerce_dashboard_recent_reviews,
			#wc-admin-note-woocommerce_install_notice,
			.woocommerce-store-activity-ribbon,
			#woocommerce-activity-panel,
			.woocommerce-notice--welcome,
			.woocommerce-layout__header-heading .woocommerce-header__item { display:none !important; }
		</style>' . "\n";
	}

	/**
	 * Replaces «WooCommerce» menu/label strings with «آموزشگاه» on our screens.
	 *
	 * @param  string $translated
	 * @param  string $original
	 * @param  string $domain
	 * @return string
	 */
	public function scrub_wc_label( string $translated, string $original, string $domain ): string {
		if ( $this->is_ecommerce_mode() ) {
			return $translated;
		}

		// Only relabel WC's own strings and in admin context.
		if ( ! is_admin() || 'woocommerce' !== $domain ) {
			return $translated;
		}

		$map = [
			'WooCommerce'        => 'آموزشگاه',
			'Products'           => 'دوره‌ها',
			'All Products'       => 'همه دوره‌ها',
			'Brands'             => '',        // empty = hide label
			'Marketing'          => '',
			'Analytics'          => '',
		];

		return $map[ $translated ] ?? $translated;
	}

	// ─── Product list query filter ────────────────────────────────────────────

	/**
	 * When accessing the product list via our filtered URL
	 * (edit.php?post_type=product&product_type=class|video_course),
	 * this filter wires the `product_type` query var into a tax_query so
	 * WC's own list-table filter picks it up.
	 *
	 * WooCommerce already handles `product_type` as a query variable on its
	 * product list screen, so this is mainly a passthrough guard.
	 *
	 * @param \WP $wp
	 */
	public function filter_product_list_by_type( \WP $wp ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type = sanitize_key( $_GET['product_type'] ?? '' );
		if ( '' === $type ) {
			return;
		}

		// Register the query var so WC's list table picks it up.
		if ( ! in_array( 'product_type', $wp->public_query_vars, true ) ) {
			$wp->public_query_vars[] = 'product_type';
		}
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Returns true when «ecommerce mode» is enabled in CSB settings
	 * (physical-goods shop alongside classes — WC menus should remain visible).
	 */
	private function is_ecommerce_mode(): bool {
		$settings = (array) get_option( 'csb_settings', [] );

		return ! empty( $settings['enable_ecommerce'] );
	}

	/**
	 * Returns true when the current screen belongs to our plugin.
	 */
	private function is_csb_screen(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = sanitize_key( $_GET['page'] ?? '' );
		if ( str_starts_with( $page, 'csb-' ) ) {
			return true;
		}

		// product edit/list screens (our parent).
		global $typenow;
		if ( 'product' === ( $typenow ?? '' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks whether a top-level menu with the given slug exists in $GLOBALS['menu'].
	 *
	 * @param  string $slug
	 * @return bool
	 */
	private function top_menu_exists( string $slug ): bool {
		global $menu;

		if ( ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes a submenu slug for comparison:
	 *  - Strips any admin_url() prefix (absolute or with http/https).
	 *  - HTML-entity-decodes (converts &#038; → &, &amp; → &).
	 *  - URL-decodes percent-encoded chars.
	 *
	 * This lets us compare 'edit.php?post_type=product&product_type=class'
	 * with the stored 'http://localhost/wp-admin/edit.php?post_type=product&#038;product_type=class'.
	 *
	 * @param  string $slug
	 * @return string
	 */
	private function normalize_submenu_slug( string $slug ): string {
		// HTML-entity decode first so &#038; → & and &amp; → &.
		$slug = html_entity_decode( $slug, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Strip admin_url prefix (try both http and https, with and without trailing slash).
		foreach ( [ admin_url(), rtrim( admin_url(), '/' ) . '/' ] as $base ) {
			if ( '' !== $base && 0 === strpos( $slug, $base ) ) {
				$slug = substr( $slug, strlen( $base ) );
				break;
			}
		}

		// Also handle protocol-relative or plain http when admin_url uses https.
		$slug = preg_replace( '#^https?://[^/]+/wp-admin/#', '', $slug );

		// URL-decode percent-encoded sequences.
		$slug = rawurldecode( $slug );

		return $slug;
	}
}
