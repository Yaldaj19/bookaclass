<?php
/**
 * Coupon service — CSB-scoped discount codes.
 *
 * Codes are stored in wp_csb_coupons (not WC native coupons) so they can be
 * scoped to a specific class product and tracked separately from general-store
 * discounts.  At checkout a negative WC fee is applied (same pattern as
 * wallet credit), and the usage_count is incremented on payment.
 *
 * Idempotency: `_csb_coupon_applied` order meta prevents double-counting.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CouponService
 */
class CouponService {

	// ── Lookup ────────────────────────────────────────────────────────────────

	/**
	 * Returns the coupon row for a code, or null if not found / inactive / expired.
	 *
	 * @param  string $code  Raw user input (case-insensitive).
	 * @return array<string,mixed>|null
	 */
	public static function get_by_code( string $code ): ?array {
		global $wpdb;

		$code = strtolower( trim( $code ) );
		if ( '' === $code ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_coupons
			  WHERE code = %s AND active = 1
			  LIMIT 1",
			$code
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		// Check expiry.
		if ( null !== $row['expires_at'] && strtotime( $row['expires_at'] ) < time() ) {
			return null;
		}

		// Check usage limit.
		if ( null !== $row['usage_limit'] && (int) $row['usage_count'] >= (int) $row['usage_limit'] ) {
			return null;
		}

		return $row;
	}

	// ── Discount calculation ──────────────────────────────────────────────────

	/**
	 * Calculates the discount amount to apply to a given cart subtotal.
	 *
	 * @param  array<string,mixed> $coupon   Row from get_by_code().
	 * @param  float               $subtotal Cart subtotal (or item price).
	 * @return float  Positive discount amount (caller negates when adding fee).
	 */
	public static function calculate_discount( array $coupon, float $subtotal ): float {
		$amount = (float) $coupon['amount'];

		if ( 'fixed' === $coupon['discount_type'] ) {
			return min( $amount, $subtotal );
		}

		// Percent.
		return min( round( $subtotal * $amount / 100, 2 ), $subtotal );
	}

	// ── Usage recording ───────────────────────────────────────────────────────

	/**
	 * Atomically increments usage_count for a coupon row.
	 * Called once per order when payment is confirmed.
	 *
	 * @param  int $coupon_id  PK from wp_csb_coupons.
	 * @return bool
	 */
	public static function record_usage( int $coupon_id ): bool {
		global $wpdb;

		$result = $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}csb_coupons
			    SET usage_count = usage_count + 1
			  WHERE id = %d",
			$coupon_id
		) );

		return false !== $result;
	}

	// ── Admin CRUD ────────────────────────────────────────────────────────────

	/**
	 * Creates a new coupon.
	 *
	 * @param  array<string,mixed> $data  Keys: code, discount_type, amount, class_id, usage_limit, expires_at.
	 * @return int|false  New coupon ID on success, false on duplicate/error.
	 */
	public static function create( array $data ): int|false {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'csb_coupons',
			[
				'code'          => strtolower( sanitize_text_field( $data['code'] ) ),
				'discount_type' => in_array( $data['discount_type'] ?? 'percent', [ 'percent', 'fixed' ], true )
					? $data['discount_type'] : 'percent',
				'amount'        => max( 0, (float) ( $data['amount'] ?? 0 ) ),
				'class_id'      => isset( $data['class_id'] ) ? (int) $data['class_id'] : null,
				'usage_limit'   => isset( $data['usage_limit'] ) ? max( 1, (int) $data['usage_limit'] ) : null,
				'expires_at'    => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
				'active'        => 1,
			],
			[ '%s', '%s', '%f', '%d', '%d', '%s', '%d' ]
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Deactivates a coupon (soft delete).
	 *
	 * @param  int $coupon_id
	 * @return bool
	 */
	public static function deactivate( int $coupon_id ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			$wpdb->prefix . 'csb_coupons',
			[ 'active' => 0 ],
			[ 'id'     => $coupon_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Returns all coupons (for admin list).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}csb_coupons ORDER BY created_at DESC LIMIT 500",
			ARRAY_A
		) ?: [];
	}
}
