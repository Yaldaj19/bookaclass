<?php
/**
 * Custom rewrite rules for class and video_course product types.
 *
 * Since both types share the `product` post type, WooCommerce's default
 * /product/{slug}/ URL resolves for all products. We add ADDITIONAL rewrite
 * rules so:
 *   /classes/{slug}     → single class product
 *   /classes/           → archive listing only class-type products
 *   /courses/{slug}     → single video_course product
 *   /courses/           → archive listing only video_course products
 *
 * Bases come from csb_cpt_config, defaulting to 'classes' and 'courses'.
 * flush_rewrite_rules() is called ONLY on:
 *   - Plugin activation
 *   - Config save (from ContentTypesPage)
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

use CookingSchoolBooking\Products\ProductTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rewrites
 */
class Rewrites {

	/**
	 * Attaches hooks.
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ], 10 );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'pre_get_posts', [ $this, 'filter_archive_query' ] );
		add_filter( 'template_include', [ $this, 'maybe_load_template' ], 20 );

		// Rewrite permalink for class / video_course products to use custom base.
		add_filter( 'post_type_link', [ $this, 'filter_product_link' ], 10, 2 );

		// 301 redirect /product/{slug} → /{base}/{slug} for our types.
		add_action( 'template_redirect', [ $this, 'maybe_redirect_default_product_url' ], 2 );
	}

	// ── Rewrite rules ─────────────────────────────────────────────────────────

	/**
	 * Registers custom rewrite rules for both product types.
	 * Called on `init` so WP loads them from the option cache (no flush needed
	 * on every request — flush is done once when config is saved).
	 *
	 * Archive rules are only added when has_archive is enabled for the type
	 * (disabled → visiting /{base}/ will 404).
	 */
	public function add_rewrite_rules(): void {
		$config     = ProductTypes::get_config();
		$class_cfg  = $config['types']['class'];
		$video_cfg  = $config['types']['video_course'];

		$class_base = sanitize_title( $class_cfg['classes_base'] ?? 'classes' );
		$video_base = sanitize_title( $video_cfg['classes_base'] ?? 'courses' );

		// ── Single product pages ─────────────────────────────────────────────
		add_rewrite_rule(
			'^' . preg_quote( $class_base, '/' ) . '/([^/]+)/?$',
			'index.php?post_type=product&name=$matches[1]&csb_product_type=class',
			'top'
		);

		add_rewrite_rule(
			'^' . preg_quote( $video_base, '/' ) . '/([^/]+)/?$',
			'index.php?post_type=product&name=$matches[1]&csb_product_type=video_course',
			'top'
		);

		// ── Archive pages — registered only when has_archive is on ───────────
		if ( ! empty( $class_cfg['has_archive'] ) ) {
			add_rewrite_rule(
				'^' . preg_quote( $class_base, '/' ) . '/?$',
				'index.php?post_type=product&csb_product_type=class&csb_archive=1',
				'top'
			);
		}

		if ( ! empty( $video_cfg['has_archive'] ) ) {
			add_rewrite_rule(
				'^' . preg_quote( $video_base, '/' ) . '/?$',
				'index.php?post_type=product&csb_product_type=video_course&csb_archive=1',
				'top'
			);
		}
	}

	/**
	 * Registers our custom query vars so WP passes them through.
	 *
	 * @param  string[] $vars
	 * @return string[]
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'csb_product_type';
		$vars[] = 'csb_archive';
		return $vars;
	}

	// ── Archive query filter ──────────────────────────────────────────────────

	/**
	 * On our custom archive pages, filter `product` post queries to only
	 * return products of the matching type.
	 */
	public function filter_archive_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$type    = (string) $query->get( 'csb_product_type' );
		$archive = (string) $query->get( 'csb_archive' );

		if ( '' === $type ) {
			return;
		}

		// Restrict to products of this type.
		if ( '1' === $archive ) {
			// Archive: no single name — list all products of this type.
			$query->set( 'post_type', 'product' );
			$query->set( 'posts_per_page', (int) get_option( 'posts_per_page', 12 ) );
			$query->set( 'csb_product_type', $type ); // kept for template detection
		}

		// Filter by product type via tax_query on product type taxonomy.
		$existing_tax = (array) ( $query->get( 'tax_query' ) ?: [] );
		$existing_tax[] = [
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => [ $type ],
		];
		$query->set( 'tax_query', $existing_tax );
	}

	// ── Template loader ───────────────────────────────────────────────────────

	/**
	 * Routes requests to our templates when the csb_product_type query var is set.
	 *
	 * Priority 20 so it runs after WooCommerce's own template_include (10).
	 *
	 * @param  string $template Currently resolved template path.
	 * @return string
	 */
	public function maybe_load_template( string $template ): string {
		$type    = (string) get_query_var( 'csb_product_type' );
		$archive = (string) get_query_var( 'csb_archive' );

		if ( '' === $type ) {
			return $template;
		}

		if ( '1' === $archive ) {
			return $this->locate( 'archive-' . str_replace( '_', '-', $type ) . '.php', $template );
		}

		// Single product page — also verify the product is the correct type.
		if ( is_singular( 'product' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && $product->get_type() === $type ) {
				return $this->locate( 'single-' . str_replace( '_', '-', $type ) . '.php', $template );
			}
		}

		return $template;
	}

	// ── Permalink rewriting ───────────────────────────────────────────────────

	/**
	 * Replaces `/product/{slug}` with `/{classes_base}/{slug}` (or `/{courses_base}/{slug}`)
	 * in every permalink generated for class / video_course products.
	 *
	 * Hooked on `post_type_link` (priority 10).
	 *
	 * @param  string   $permalink  Default permalink from WP.
	 * @param  \WP_Post $post
	 * @return string
	 */
	public function filter_product_link( string $permalink, \WP_Post $post ): string {
		if ( 'product' !== $post->post_type ) {
			return $permalink;
		}

		// wc_get_product is expensive inside a loop — use the term directly.
		$terms = get_the_terms( $post->ID, 'product_type' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return $permalink;
		}

		$type = $terms[0]->slug ?? '';

		if ( ! in_array( $type, [ 'class', 'video_course' ], true ) ) {
			return $permalink;
		}

		$config     = ProductTypes::get_config();
		$type_cfg   = $config['types'][ $type ] ?? [];
		$base       = sanitize_title( $type_cfg['classes_base'] ?? ( 'class' === $type ? 'classes' : 'courses' ) );

		// Replace the /product/ segment with our custom base.
		$home = trailingslashit( home_url() );
		$slug = $post->post_name;

		return $home . $base . '/' . $slug . '/';
	}

	// ── Canonical redirect ────────────────────────────────────────────────────

	/**
	 * Issues a 301 when a class or video_course product is accessed via the
	 * default `/product/{slug}` URL.
	 *
	 * Fires on `template_redirect` at priority 2 (before WC's redirect at 1,
	 * but after our custom base has already been resolved at the rewrite level).
	 */
	public function maybe_redirect_default_product_url(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		// Only act when the visitor reached here via the default /product/ base.
		$request = trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '', '/' );

		$shop_base = sanitize_title( (string) ( wc_get_page_permalink( 'shop' ) ?: 'shop' ) );
		$product_base_slug = sanitize_title( get_option( 'woocommerce_permalinks', [] )['product_base'] ?? 'product' );

		// If the URL doesn't start with the WC product base, it's already on our custom base — skip.
		if ( ! str_starts_with( $request, $product_base_slug . '/' ) && ! str_starts_with( $request, 'product/' ) ) {
			return;
		}

		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product ) {
			return;
		}

		$type = $product->get_type();
		if ( ! in_array( $type, [ 'class', 'video_course' ], true ) ) {
			return;
		}

		// get_permalink now returns the custom base URL (via filter_product_link).
		$canonical = get_permalink( $product->get_id() );
		if ( ! $canonical ) {
			return;
		}

		// Only redirect if the current URL differs from the canonical.
		$current = set_url_scheme( home_url( $request . '/' ) );
		if ( untrailingslashit( $current ) === untrailingslashit( $canonical ) ) {
			return;
		}

		wp_redirect( $canonical, 301 );
		exit;
	}

	/**
	 * Finds a template in (theme > plugin > fallback) order.
	 *
	 * @param  string $name     Template filename, e.g. 'single-class.php'.
	 * @param  string $fallback Path to use if nothing is found.
	 * @return string
	 */
	private function locate( string $name, string $fallback ): string {
		// 1. Active theme override.
		$theme = locate_template( [ 'csb/' . $name, $name ] );
		if ( $theme ) {
			return $theme;
		}

		// 2. Plugin templates directory.
		$plugin = CSB_PATH . 'templates/' . $name;
		if ( file_exists( $plugin ) ) {
			return $plugin;
		}

		return $fallback;
	}

	// ── Static helper called on config save + activation ──────────────────────

	/**
	 * Flush rewrite rules (deferred to shutdown to avoid doing it mid-request).
	 */
	public static function flush(): void {
		add_action( 'shutdown', 'flush_rewrite_rules' );
	}
}
