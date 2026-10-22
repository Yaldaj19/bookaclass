<?php
/**
 * OTP (One-Time Password) service — phone-number login.
 *
 * Flow:
 *   1. OtpService::send($phone) — generates a 6-digit code, stores a
 *      bcrypt hash in wp_csb_otp_tokens, dispatches via NotificationService.
 *   2. OtpService::verify($phone, $code) — validates code, marks used,
 *      returns WP user_id (creates account if phone not registered).
 *
 * Rate limit: at most 3 un-expired tokens per phone in a 10-minute window.
 * Brute-force guard: 5 wrong attempts invalidates the token.
 * Tokens expire after 10 minutes.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OtpService
 */
class OtpService {

	/** OTP validity window in seconds. */
	private const TTL_SECONDS = 600;

	/** Maximum wrong-guess attempts before the token is invalidated. */
	private const MAX_ATTEMPTS = 5;

	/** Maximum un-expired tokens per phone per TTL window (rate limit). */
	private const SEND_RATE_LIMIT = 3;

	// ── Send ──────────────────────────────────────────────────────────────────

	/**
	 * Generates and dispatches an OTP for the given phone number.
	 *
	 * @param  string $phone  Local or E.164 format; normalised internally.
	 * @param  string $ip     Requester IP for audit trail.
	 * @return bool   True on success; false if rate-limited.
	 * @throws \InvalidArgumentException  If phone is empty.
	 */
	public static function send( string $phone, string $ip = '' ): bool {
		global $wpdb;

		$phone = self::normalise_phone( $phone );
		if ( '' === $phone ) {
			throw new \InvalidArgumentException( 'Phone number is required.' );
		}

		// Rate limit: count un-expired tokens sent in the last TTL window.
		$recent = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}csb_otp_tokens
			  WHERE phone = %s AND expires_at > UTC_TIMESTAMP() AND used = 0",
			$phone
		) );

		if ( $recent >= self::SEND_RATE_LIMIT ) {
			AuditLogger::log( 'otp_rate_limited', 'phone', 0, [ 'phone' => $phone, 'ip' => $ip ] );
			return false;
		}

		// Generate a 6-digit code.
		$plain_code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$hashed     = wp_hash_password( $plain_code );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );

		$wpdb->insert(
			$wpdb->prefix . 'csb_otp_tokens',
			[
				'phone'      => $phone,
				'code'       => $hashed,
				'expires_at' => $expires_at,
				'used'       => 0,
				'attempts'   => 0,
				'ip'         => sanitize_text_field( $ip ),
			],
			[ '%s', '%s', '%s', '%d', '%d', '%s' ]
		);

		// Dispatch via NotificationService — adapter (SMS gateway) hooks in Phase 7+.
		NotificationService::dispatch( 'otp_login', [
			'phone'      => $phone,
			'otp_code'   => $plain_code,
			'subject'    => sprintf(
				/* translators: %s: OTP code */
				__( 'کد ورود شما: %s', 'csb' ),
				$plain_code
			),
			'body'       => sprintf(
				/* translators: 1: OTP code 2: expiry minutes */
				__( 'کد ورود به آموزشگاه: %1$s (اعتبار %2$d دقیقه)', 'csb' ),
				$plain_code,
				self::TTL_SECONDS / 60
			),
		] );

		AuditLogger::log( 'otp_sent', 'phone', 0, [ 'phone' => $phone, 'ip' => $ip ] );

		return true;
	}

	// ── Verify ────────────────────────────────────────────────────────────────

	/**
	 * Verifies an OTP code for a phone number.
	 *
	 * On success: marks the token used and returns the WP user_id (creating
	 * a minimal account if the phone is not yet registered).
	 *
	 * @param  string $phone  Same format as send().
	 * @param  string $code   6-digit string entered by the user.
	 * @return int    WP user_id on success.
	 * @throws \RuntimeException  On invalid / expired / exhausted token.
	 */
	public static function verify( string $phone, string $code ): int {
		global $wpdb;

		$phone = self::normalise_phone( $phone );
		if ( '' === $phone || '' === $code ) {
			throw new \RuntimeException( 'شماره موبایل و کد الزامی است.' );
		}

		// Fetch the most recent un-used, un-expired token for this phone.
		$token = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_otp_tokens
			  WHERE phone = %s AND used = 0 AND expires_at > UTC_TIMESTAMP()
			  ORDER BY created_at DESC LIMIT 1",
			$phone
		), ARRAY_A );

		if ( ! $token ) {
			throw new \RuntimeException( 'کد معتبر نیست یا منقضی شده است.' );
		}

		// Brute-force guard.
		if ( (int) $token['attempts'] >= self::MAX_ATTEMPTS ) {
			throw new \RuntimeException( 'کد بیش از حد مجاز اشتباه وارد شده است.' );
		}

		if ( ! wp_check_password( $code, $token['code'] ) ) {
			// Increment attempt counter.
			$wpdb->update(
				$wpdb->prefix . 'csb_otp_tokens',
				[ 'attempts' => (int) $token['attempts'] + 1 ],
				[ 'id'       => (int) $token['id'] ],
				[ '%d' ],
				[ '%d' ]
			);
			throw new \RuntimeException( 'کد وارد شده اشتباه است.' );
		}

		// Mark token used.
		$wpdb->update(
			$wpdb->prefix . 'csb_otp_tokens',
			[ 'used' => 1 ],
			[ 'id'   => (int) $token['id'] ],
			[ '%d' ],
			[ '%d' ]
		);

		// Resolve or create the WP user.
		$user_id = self::resolve_or_create_user( $phone );

		AuditLogger::log( 'otp_login_success', 'user', $user_id, [ 'phone' => $phone ] );

		return $user_id;
	}

	// ── Maintenance ───────────────────────────────────────────────────────────

	/**
	 * Purges expired OTP tokens.  Called from a cron hook registered in Phase 7.
	 */
	public static function purge_expired(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}csb_otp_tokens WHERE expires_at < UTC_TIMESTAMP()"
		);
	}

	// ── Internal helpers ──────────────────────────────────────────────────────

	/**
	 * Normalises a phone number (strips spaces/dashes, ensures leading +98 or 09).
	 */
	private static function normalise_phone( string $phone ): string {
		$phone = preg_replace( '/[^\d+]/', '', trim( $phone ) ) ?? '';

		// Convert +98 prefix to 09.
		if ( str_starts_with( $phone, '+98' ) ) {
			$phone = '0' . substr( $phone, 3 );
		}

		// Accept 09xxxxxxxxx (11 digits) or +98xxxxxxxxx.
		if ( preg_match( '/^09\d{9}$/', $phone ) ) {
			return $phone;
		}

		return '';
	}

	/**
	 * Looks up a WP user by billing_phone meta; creates one if not found.
	 *
	 * @param  string $phone  Normalised phone.
	 * @return int    WP user_id.
	 * @throws \RuntimeException  If account creation fails.
	 */
	private static function resolve_or_create_user( string $phone ): int {
		global $wpdb;

		// 1. Check billing_phone meta.
		$user_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta}
			  WHERE meta_key = 'billing_phone' AND meta_value = %s LIMIT 1",
			$phone
		) );

		if ( $user_id > 0 ) {
			return $user_id;
		}

		// 2. Create a minimal account (username = phone, random password).
		$username = 'student_' . ltrim( $phone, '0' );
		$email    = $phone . '@csb.local';  // placeholder; user should update later.

		$new_id = wp_insert_user( [
			'user_login'    => $username,
			'user_email'    => $email,
			'user_pass'     => wp_generate_password( 16 ),
			'display_name'  => $phone,
			'role'          => 'subscriber',
		] );

		if ( is_wp_error( $new_id ) ) {
			throw new \RuntimeException( 'ایجاد حساب کاربری با خطا مواجه شد.' );
		}

		update_user_meta( (int) $new_id, 'billing_phone', $phone );

		AuditLogger::log( 'otp_user_created', 'user', (int) $new_id, [ 'phone' => $phone ] );

		return (int) $new_id;
	}
}
