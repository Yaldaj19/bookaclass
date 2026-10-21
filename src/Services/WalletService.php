<?php
/**
 * Wallet service — credit/debit ledger for store-credit refunds.
 *
 * All monetary amounts are in the site's WooCommerce currency.
 * Every mutation writes an immutable row to wp_csb_wallet_txns and
 * an audit log entry.
 *
 * Guard: silently skips all writes when enable_wallet = false.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

use CookingSchoolBooking\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WalletService
 */
class WalletService {

	// ── Balance ───────────────────────────────────────────────────────────────

	/**
	 * Returns the current wallet balance for a student.
	 *
	 * Computed from the running balance_after on the latest transaction row.
	 * Returns 0.00 when no transactions exist.
	 *
	 * @param  int $student_id  WP user_id.
	 * @return float
	 */
	public static function get_balance( int $student_id ): float {
		global $wpdb;

		if ( $student_id <= 0 ) {
			return 0.00;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT balance_after
			   FROM {$wpdb->prefix}csb_wallet_txns
			  WHERE student_id = %d
			  ORDER BY id DESC
			  LIMIT 1",
			$student_id
		), ARRAY_A );

		return $row ? (float) $row['balance_after'] : 0.00;
	}

	// ── Mutations ─────────────────────────────────────────────────────────────

	/**
	 * Credits (adds) funds to a student's wallet.
	 *
	 * @param  int    $student_id
	 * @param  float  $amount      Must be > 0.
	 * @param  string $reason      Short reason key, e.g. 'cancellation_refund'.
	 * @param  string $ref_id      External reference (booking_id, order_id …).
	 * @return float  New balance, or -1 on error / wallet disabled.
	 */
	public static function credit( int $student_id, float $amount, string $reason, string $ref_id = '' ): float {
		return self::record( $student_id, 'credit', $amount, $reason, $ref_id );
	}

	/**
	 * Debits (deducts) funds from a student's wallet.
	 *
	 * Does NOT check for sufficient balance — caller must guard with get_balance().
	 *
	 * @param  int    $student_id
	 * @param  float  $amount      Must be > 0.
	 * @param  string $reason
	 * @param  string $ref_id
	 * @return float  New balance, or -1 on error / wallet disabled.
	 */
	public static function debit( int $student_id, float $amount, string $reason, string $ref_id = '' ): float {
		return self::record( $student_id, 'debit', $amount, $reason, $ref_id );
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	/**
	 * Inserts one wallet transaction row inside a DB transaction for atomicity.
	 *
	 * @param  int    $student_id
	 * @param  string $direction   'credit' | 'debit'
	 * @param  float  $amount
	 * @param  string $reason
	 * @param  string $ref_id
	 * @return float  New balance, or -1 on failure / disabled.
	 */
	private static function record(
		int $student_id,
		string $direction,
		float $amount,
		string $reason,
		string $ref_id
	): float {
		$settings = SettingsPage::get();
		if ( empty( $settings['enable_wallet'] ) ) {
			return -1;
		}

		if ( $student_id <= 0 || $amount <= 0 ) {
			return -1;
		}

		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		// Read current balance inside the transaction to prevent races.
		$current = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(balance_after),0)
			   FROM {$wpdb->prefix}csb_wallet_txns
			  WHERE student_id = %d",
			$student_id
		) );

		$new_balance = ( 'credit' === $direction )
			? $current + $amount
			: $current - $amount;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'csb_wallet_txns',
			[
				'student_id'    => $student_id,
				'direction'     => $direction,
				'amount'        => $amount,
				'reason'        => sanitize_text_field( $reason ),
				'ref_id'        => sanitize_text_field( $ref_id ),
				'balance_after' => $new_balance,
			],
			[ '%d', '%s', '%f', '%s', '%s', '%f' ]
		);

		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			return -1;
		}

		$txn_id = (int) $wpdb->insert_id;

		$wpdb->query( 'COMMIT' );

		AuditLogger::log(
			'wallet_' . $direction,
			'student',
			$student_id,
			[
				'txn_id'      => $txn_id,
				'amount'      => $amount,
				'reason'      => $reason,
				'ref_id'      => $ref_id,
				'new_balance' => $new_balance,
			]
		);

		return $new_balance;
	}
}
