<?php
/**
 * Certificate service — issues and manages course-completion certificates.
 *
 * A certificate is issued when a student's enrollment status is `completed`
 * (i.e. the session ended and MarkCompletedCron ran) OR when an admin manually
 * triggers issuance.
 *
 * Certificate data is stored in wp_csb_certificates; the rendered HTML/PDF is
 * generated on demand from the template at templates/certificate.php.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CertificateService
 */
class CertificateService {

	// ── Issuance ─────────────────────────────────────────────────────────────

	/**
	 * Issues a certificate to a student for a completed booking.
	 *
	 * Idempotent: returns the existing certificate ID if already issued for
	 * this booking.
	 *
	 * @param int $booking_id     wp_csb_bookings.id
	 * @param int $issued_by      WP user_id of admin/system triggering issuance (0 = cron)
	 * @return int Certificate row ID, or 0 on failure.
	 */
	public static function issue( int $booking_id, int $issued_by = 0 ): int {
		global $wpdb;

		$booking = BookingService::get( $booking_id );
		if ( ! $booking ) {
			return 0;
		}

		// Idempotency guard.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}csb_certificates WHERE booking_id = %d LIMIT 1",
			$booking_id
		) );
		if ( $existing ) {
			return $existing;
		}

		// Derive course/session info.
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.class_id, s.start_at FROM {$wpdb->prefix}csb_sessions s WHERE s.id = %d",
			(int) $booking['session_id']
		), ARRAY_A );

		if ( ! $session ) {
			return 0;
		}

		$serial = self::generate_serial( $booking_id );

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'csb_certificates',
			[
				'booking_id'  => $booking_id,
				'student_id'  => (int) $booking['student_id'],
				'course_id'   => (int) $session['class_id'],
				'serial'      => $serial,
				'issued_at'   => current_time( 'mysql', true ),
				'issued_by'   => $issued_by,
				'revoked'     => 0,
			],
			[ '%d', '%d', '%d', '%s', '%s', '%d', '%d' ]
		);

		if ( ! $inserted ) {
			return 0;
		}

		$cert_id = (int) $wpdb->insert_id;

		AuditLogger::log( 'certificate_issued', 'booking', $booking_id, [
			'cert_id'    => $cert_id,
			'serial'     => $serial,
			'student_id' => (int) $booking['student_id'],
			'issued_by'  => $issued_by,
		] );

		return $cert_id;
	}

	/**
	 * Revokes a certificate.
	 */
	public static function revoke( int $cert_id, int $revoked_by ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'csb_certificates',
			[ 'revoked' => 1 ],
			[ 'id'      => $cert_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( $updated ) {
			AuditLogger::log( 'certificate_revoked', 'certificate', $cert_id, [
				'revoked_by' => $revoked_by,
			] );
		}

		return (bool) $updated;
	}

	// ── Read ─────────────────────────────────────────────────────────────────

	/**
	 * Returns the certificate row for a booking, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_by_booking( int $booking_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_certificates WHERE booking_id = %d LIMIT 1",
			$booking_id
		), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Returns all valid (non-revoked) certificates for a student.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_for_student( int $student_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.*, b.session_id
			   FROM {$wpdb->prefix}csb_certificates c
			   JOIN {$wpdb->prefix}csb_bookings b ON b.id = c.booking_id
			  WHERE c.student_id = %d AND c.revoked = 0
			  ORDER BY c.issued_at DESC",
			$student_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * Verifies a serial number; returns the certificate or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function verify_serial( string $serial ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_certificates
			  WHERE serial = %s AND revoked = 0 LIMIT 1",
			$serial
		), ARRAY_A );

		return $row ?: null;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Generates a unique, human-readable serial number.
	 * Format: CSB-{year}-{booking_id}-{6-char hex}
	 */
	private static function generate_serial( int $booking_id ): string {
		$suffix = strtoupper( bin2hex( random_bytes( 3 ) ) );
		return sprintf( 'CSB-%s-%d-%s', gmdate( 'Y' ), $booking_id, $suffix );
	}
}
