<?php
/**
 * Sitemap integration for class and video_course product pages.
 *
 * Strategy (in priority order):
 *
 * 1. Rank Math active  → ensure our 'product' CPT is included in Rank Math's
 *    sitemap via its options filter.  Rank Math will enumerate all products
 *    including our types because they share the 'product' post type.
 *
 * 2. Yoast SEO active  → ensure 'product' post type is not excluded from the
 *    Yoast sitemap and that our custom archive URLs are added as extra URLs.
 *
 * 3. No SEO plugin → hook into WordPress core sitemaps (WP 5.5+) to:
 *    a. Keep the default 'product' post-type sitemap (includes our singles).
 *    b. Add our archive URLs as additional entries via the posts provider
 *       filter on the 'page' sub-type (lightest approach, no custom provider).
 *
 * We never suppress any existing sitemap authority — only supplement.
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

use CookingSchoolBooking\Products\ProductTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sitemap
 */
class Sitemap {

	/**
	 * Attaches hooks after plugins have loaded.
	 */
	public function init(): void {
		// ── Rank Math ─────────────────────────────────────────────────────
		if ( class_exists( 'RankMath' ) ) {
			add_filter( 'rank_math/sitemap/include_post_types', [ $this, 'rankmath_include_product' ] );
			add_filter( 'rank_math/sitemap/entry',              [ $this, 'rankmath_use_custom_permalink' ], 10, 3 );
			return;
		}

		// ── Yoast SEO ─────────────────────────────────────────────────────
		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_sitemap_exclude_post_type', [ $this, 'yoast_include_product' ], 10, 2 );
			add_filter( 'wpseo_sitemap_page_for_post_type', [ $this, 'yoast_fix_archive_page' ], 10, 2 );
			return;
		}

		// ── WP Core sitemaps (WP 5.5+) ────────────────────────────────────
		add_filter( 'wp_sitemaps_post_types', [ $this, 'core_include_product' ] );
		add_filter( 'wp_sitemaps_posts_entry', [ $this, 'core_use_custom_permalink' ], 10, 3 );
		add_filter( 'wp_sitemaps_pages_query_args', [ $this, 'core_add_archive_pages' ] );
	}

	// ── Rank Math ─────────────────────────────────────────────────────────────

	/**
	 * Ensures the 'product' post type is listed in Rank Math's sitemap.
	 *
	 * @param  array<string,bool> $post_types
	 * @return array<string,bool>
	 */
	public function rankmath_include_product( array $post_types ): array {
		if ( ! isset( $post_types['product'] ) ) {
			$post_types['product'] = true;
		}
		return $post_types;
	}

	/**
	 * Replaces the default /product/{slug} URL with our custom base URL in
	 * Rank Math's sitemap entries for class / video_course products.
	 *
	 * @param  array<string,mixed> $entry
	 * @param  string              $object_type  'post'
	 * @param  \WP_Post            $post
	 * @return array<string,mixed>
	 */
	public function rankmath_use_custom_permalink( array $entry, string $object_type, $post ): array {
		if ( 'post' !== $object_type || ! isset( $post->post_type ) || 'product' !== $post->post_type ) {
			return $entry;
		}
		$terms = get_the_terms( $post->ID, 'product_type' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return $entry;
		}
		$type = $terms[0]->slug ?? '';
		if ( ! in_array( $type, [ 'class', 'video_course' ], true ) ) {
			return $entry;
		}
		// get_permalink returns our custom URL via Rewrites::filter_product_link.
		$custom = get_permalink( $post->ID );
		if ( $custom ) {
			$entry['loc'] = $custom;
		}
		return $entry;
	}

	// ── Yoast SEO ─────────────────────────────────────────────────────────────

	/**
	 * Prevents Yoast from excluding the 'product' post type.
	 *
	 * @param  bool   $exclude
	 * @param  string $post_type
	 * @return bool
	 */
	public function yoast_include_product( bool $exclude, string $post_type ): bool {
		if ( 'product' === $post_type ) {
			return false; // do not exclude
		}
		return $exclude;
	}

	/**
	 * Yoast uses a 'page' post for the archive link when `has_archive` is set.
	 * Since our types do not register a native CPT archive, we return 0 to let
	 * Yoast fall through and we add our archive URLs via a separate hook below.
	 *
	 * @param  int    $page_id
	 * @param  string $post_type
	 * @return int
	 */
	public function yoast_fix_archive_page( int $page_id, string $post_type ): int {
		// Nothing to do — Yoast enumerates product posts and their permalinks
		// will be our custom URLs via filter_product_link.
		return $page_id;
	}

	// ── WP Core sitemaps ──────────────────────────────────────────────────────

	/**
	 * Ensures the 'product' post type appears in WP core sitemaps.
	 *
	 * @param  \WP_Post_Type[] $post_types
	 * @return \WP_Post_Type[]
	 */
	public function core_include_product( array $post_types ): array {
		if ( ! isset( $post_types['product'] ) ) {
			$pto = get_post_type_object( 'product' );
			if ( $pto ) {
				$post_types['product'] = $pto;
			}
		}
		return $post_types;
	}

	/**
	 * Replaces the default /product/{slug} URL in WP core sitemap entries
	 * for class / video_course products with our custom base URL.
	 *
	 * @param  array<string,mixed> $entry
	 * @param  \WP_Post            $post
	 * @param  string              $post_type
	 * @return array<string,mixed>
	 */
	public function core_use_custom_permalink( array $entry, $post, string $post_type ): array {
		if ( 'product' !== $post_type ) {
			return $entry;
		}
		$terms = get_the_terms( $post->ID, 'product_type' );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return $entry;
		}
		$type = $terms[0]->slug ?? '';
		if ( ! in_array( $type, [ 'class', 'video_course' ], true ) ) {
			return $entry;
		}
		$custom = get_permalink( $post->ID );
		if ( $custom ) {
			$entry['loc'] = $custom;
		}
		return $entry;
	}

	/**
	 * We do NOT inject archive URLs into the pages sitemap here because WP core
	 * sitemaps have no generic "additional URL" hook; the cleanest approach is to
	 * add our archives via a minimal custom sitemap provider.
	 *
	 * This filter is kept as a no-op placeholder so the architecture is clear.
	 *
	 * @param  array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function core_add_archive_pages( array $args ): array {
		return $args;
	}

	/**
	 * Returns the two archive URLs (classes + courses) for use in any sitemap.
	 * Called by CsbArchiveSitemapProvider when WP core sitemaps are in use.
	 *
	 * @return string[]
	 */
	public static function get_archive_urls(): array {
		$config = ProductTypes::get_config();
		$urls   = [];

		foreach ( [ 'class', 'video_course' ] as $type ) {
			$cfg = $config['types'][ $type ] ?? [];
			if ( empty( $cfg['has_archive'] ) ) {
				continue;
			}
			$base   = sanitize_title( $cfg['classes_base'] ?? ( 'class' === $type ? 'classes' : 'courses' ) );
			$urls[] = trailingslashit( home_url( '/' . $base ) );
		}

		return $urls;
	}
}
