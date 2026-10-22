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
