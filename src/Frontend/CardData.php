<?php
/**
 * Archive card data helpers.
 *
 * Static methods used by both archive templates (archive-class.php and
 * archive-video-course.php) to retrieve per-card data without duplicating
 * DB logic in templates.
 *
 * All DB queries use prepared statements.
 * Per-request in-memory caches (static arrays) prevent redundant queries
 * inside the loop.
 *
 * @package BookaClass\Frontend
 */

namespace BookaClass\Frontend;

use BookaClass\Services\CapacityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CardData
 */
class CardData {

	/** Per-request cache: product_id → next session row or false. */
	private static array $session_cache = [];

	/** Per-request cache: product_id → instructor name or empty string. */
	private static array $instructor_cache = [];

	/** Per-request cache: product_id → first product_cat name or empty string. */
	private static array $category_cache = [];

	// ── Next session ─────────────────────────────────────────────────────────

	/**
	 * Returns the earliest upcoming scheduled session for a class product,
	 * or null when none exists.
	 *
	 * @param  int $class_id  Product (post) ID.
	 * @return array<string,mixed>|null  Associative row from wp_csb_sessions, or null.
	 */
	public static function next_session( int $class_id ): ?array {
		if ( array_key_exists( $class_id, self::$session_cache ) ) {
			return self::$session_cache[ $class_id ] ?: null;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'csb_sessions';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}`
				  WHERE class_id = %d
				    AND status   = 'scheduled'
				    AND start_at > %s
				  ORDER BY start_at ASC
				  LIMIT 1",
				$class_id,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);

		self::$session_cache[ $class_id ] = $row ?: false;

		return $row ?: null;
	}

	// ── Available seats ───────────────────────────────────────────────────────

	/**
	 * Returns the number of available seats for a session row, using
	 * CapacityService (which accounts for confirmed bookings + live locks).
	 *
	 * @param  array<string,mixed> $session  Session row as returned by next_session().
	 * @return int
	 */
	public static function available_seats( array $session ): int {
		$session_id = (int) ( $session['id'] ?? 0 );
		if ( $session_id <= 0 ) {
			return 0;
		}
		return CapacityService::available_seats( $session_id );
	}

	// ── Instructor name ───────────────────────────────────────────────────────

	/**
	 * Returns the display name for the instructor of a product.
	 *
	 * Resolution order:
	 *   1. _csb_instructor_id  → WP user display_name.
	 *   2. _csb_instructor_name meta (plain text fallback).
	 *   3. Empty string.
	 *
	 * @param  int $product_id
	 * @return string
	 */
	public static function instructor_name( int $product_id ): string {
		if ( array_key_exists( $product_id, self::$instructor_cache ) ) {
			return self::$instructor_cache[ $product_id ];
		}

		$name = '';

		$uid = (int) get_post_meta( $product_id, '_csb_instructor_id', true );
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$name = $user->display_name;
			}
		}

		if ( '' === $name ) {
			$raw = get_post_meta( $product_id, '_csb_instructor_name', true );
			if ( is_string( $raw ) && '' !== $raw ) {
				$name = $raw;
			}
		}

		self::$instructor_cache[ $product_id ] = $name;

		return $name;
	}

	// ── Category name ─────────────────────────────────────────────────────────

	/**
	 * Returns the first product_cat term name for a product, or an empty string.
	 *
	 * @param  int $product_id
	 * @return string
	 */
	public static function category_name( int $product_id ): string {
		if ( array_key_exists( $product_id, self::$category_cache ) ) {
			return self::$category_cache[ $product_id ];
		}

		$name  = '';
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				// Skip the built-in WC «Uncategorized» placeholder.
				if ( 'uncategorized' !== $term->slug ) {
					$name = $term->name;
					break;
				}
			}
			if ( '' === $name && ! empty( $terms ) ) {
				$name = $terms[0]->name;
			}
		}

		self::$category_cache[ $product_id ] = $name;

		return $name;
	}

	// ── Filtered terms for archive sidebar/chips ─────────────────────────────

	/** Per-request cache: product_type slug → WP_Term[]. */
	private static array $archive_terms_cache = [];

	/**
	 * Returns `product_cat` terms that contain at least one product of
	 * $product_type_slug ('class' or 'video_course').
	 *
	 * One JOIN query, cached for the rest of the request.
	 * `term->count` is overwritten with the type-scoped count.
	 *
	 * @param  string $product_type_slug  'class' or 'video_course'.
	 * @return \WP_Term[]
	 */
	public static function terms_for_type( string $product_type_slug ): array {
		if ( isset( self::$archive_terms_cache[ $product_type_slug ] ) ) {
			return self::$archive_terms_cache[ $product_type_slug ];
		}

		global $wpdb;

		// Find the term_id for the product_type term (e.g. 'class').
		$type_term = get_term_by( 'slug', $product_type_slug, 'product_type' );
		if ( ! $type_term ) {
			self::$archive_terms_cache[ $product_type_slug ] = [];
			return [];
		}

		$type_term_id = (int) $type_term->term_id;

		// Uncategorized exclusion.
		$uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
		$exclude_id    = $uncategorized ? (int) $uncategorized->term_id : 0;

		// Single query: product_cat terms that share at least one product
		// with the requested product_type term.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pc_tt.term_id, COUNT(DISTINCT tr_pc.object_id) AS type_count
				 FROM {$wpdb->term_relationships} tr_pt
				 INNER JOIN {$wpdb->posts}             p      ON p.ID = tr_pt.object_id
				                                              AND p.post_type   = 'product'
				                                              AND p.post_status = 'publish'
				 INNER JOIN {$wpdb->term_relationships} tr_pc  ON tr_pc.object_id = tr_pt.object_id
				 INNER JOIN {$wpdb->term_taxonomy}      pc_tt  ON pc_tt.term_taxonomy_id = tr_pc.term_taxonomy_id
				                                              AND pc_tt.taxonomy = 'product_cat'
				                                              AND pc_tt.term_id  <> %d
				 WHERE tr_pt.term_taxonomy_id = (
				     SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy}
				     WHERE term_id = %d AND taxonomy = 'product_type'
				 )
				 GROUP BY pc_tt.term_id
				 HAVING type_count > 0",
				$exclude_id,
				$type_term_id
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			self::$archive_terms_cache[ $product_type_slug ] = [];
			return [];
		}

		// Build a term_id → count map and fetch the WP_Term objects.
		$count_map = [];
		$term_ids  = [];
		foreach ( $rows as $row ) {
			$count_map[ (int) $row['term_id'] ] = (int) $row['type_count'];
			$term_ids[] = (int) $row['term_id'];
		}

		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'include'    => $term_ids,
			'hide_empty' => false, // we already know they're non-empty
			'orderby'    => 'name',
			'order'      => 'ASC',
		] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			self::$archive_terms_cache[ $product_type_slug ] = [];
			return [];
		}

		// Overwrite count with the type-scoped value.
		foreach ( $terms as $term ) {
			/** @var \WP_Term $term */
			$term->count = $count_map[ $term->term_id ] ?? 0;
		}

		self::$archive_terms_cache[ $product_type_slug ] = $terms;

		return $terms;
	}

	// ── Category emoji ────────────────────────────────────────────────────────

	/**
	 * Returns a single emoji that best matches a Persian category name.
	 *
	 * Mapping (substring match, RTL-safe):
	 *   شیرینی  → 🍰
	 *   آشپزی   → 🍲
	 *   دسر     → 🍮
	 *   ترشی    → 🥒
	 *   نان     → 🍞
	 *   default → 🍴
	 *
	 * @param  string $name  Category name (Persian).
	 * @return string
	 */
	public static function emoji_for_category( string $name ): string {
		$map = [
			'شیرینی' => '🍰',
			'آشپزی'  => '🍲',
			'دسر'    => '🍮',
			'ترشی'   => '🥒',
			'نان'    => '🍞',
		];

		foreach ( $map as $keyword => $emoji ) {
			if ( false !== mb_strpos( $name, $keyword ) ) {
				return $emoji;
			}
		}

		return '🍴';
	}
}
