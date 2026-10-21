<?php
/**
 * Enrollment lifecycle for video_course products.
 *
 * Manages access grants, expiry, and per-lesson progress stored
 * in wp_csb_enrollments.progress_json.
 *
 * progress_json schema:
 *   {
 *     "completed": [0, 2, 5],   // lesson indexes (0-based)
 *     "watched_sec": { "0": 120, "2": 300 }
 *   }
 *
 * All SQL is parameterised; never string-concatenated.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class EnrollmentService
 */
class EnrollmentService {

	// ── Grant / revoke ────────────────────────────────────────────────────────

	/**
	 * Grants a student enrollment in a video course.
	 *
	 * If the student is already enrolled this is idempotent:
	 *   — lifetime enrollment with an active expiry → expiry is extended.
	 *   — enrollment already active → no change; same row ID returned.
	 *
	 * @param int      $student_id   WP user ID.
	 * @param int      $course_id    WooCommerce product ID of the video course.
	 * @param int      $wc_order_id  WC order that triggered the grant.
	 * @param int|null $duration_days NULL for lifetime; positive = days from now.
	 * @return int  Enrollment row ID.
	 * @throws \RuntimeException On DB insert failure.
	 */
	public static function grant(
		int $student_id,
		int $course_id,
		int $wc_order_id,
		?int $duration_days = null
	): int {
		global $wpdb;

		// Check for an existing row (unique key: student_id + course_id).
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, expires_at FROM {$wpdb->prefix}csb_enrollments
			  WHERE student_id = %d AND course_id = %d LIMIT 1",
			$student_id,
			$course_id
		), ARRAY_A );

		$expires_at = null === $duration_days
			? null
			: gmdate( 'Y-m-d H:i:s', time() + $duration_days * DAY_IN_SECONDS );

		if ( $existing ) {
			// Upgrade existing: lifetime > any expiry; extend if new duration is later.
			$enroll_id = (int) $existing['id'];

			$should_extend = false;
			if ( null === $duration_days ) {
				// Switching to lifetime access.
				if ( null !== $existing['expires_at'] ) {
					$should_extend = true;
				}
			} else {
				// New expiry later than current (or current is lifetime — leave alone).
				if ( null !== $existing['expires_at'] && $expires_at > $existing['expires_at'] ) {
					$should_extend = true;
				}
			}

			if ( $should_extend ) {
				$wpdb->update(
					$wpdb->prefix . 'csb_enrollments',
					[ 'expires_at' => $expires_at ],
					[ 'id'         => $enroll_id ],
					[ '%s' ],
					[ '%d' ]
				);
				AuditLogger::log( 'enrollment_extended', 'enrollment', $enroll_id, [
					'student_id'  => $student_id,
					'course_id'   => $course_id,
					'order_id'    => $wc_order_id,
					'expires_at'  => $expires_at,
				] );
			}

			return $enroll_id;
		}

		// New enrollment row.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'csb_enrollments',
			[
				'student_id'  => $student_id,
				'course_id'   => $course_id,
				'wc_order_id' => $wc_order_id,
				'granted_at'  => current_time( 'mysql', true ),
				'expires_at'  => $expires_at,
			],
			[ '%d', '%d', '%d', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			throw new \RuntimeException(
				"EnrollmentService::grant() DB insert failed (student={$student_id}, course={$course_id})"
			);
		}

		$enroll_id = (int) $wpdb->insert_id;

		AuditLogger::log( 'enrollment_granted', 'enrollment', $enroll_id, [
			'student_id'  => $student_id,
			'course_id'   => $course_id,
			'order_id'    => $wc_order_id,
			'expires_at'  => $expires_at,
		] );

		return $enroll_id;
	}

	/**
	 * Revokes an enrollment (e.g. on refund/chargeback).
	 * Sets expires_at to NOW so the student loses access immediately.
	 *
	 * @param int $student_id
	 * @param int $course_id
	 * @return bool  True if a row was updated.
	 */
	public static function revoke( int $student_id, int $course_id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'csb_enrollments',
			[ 'expires_at' => current_time( 'mysql', true ) ],
			[ 'student_id' => $student_id, 'course_id' => $course_id ],
			[ '%s' ],
			[ '%d', '%d' ]
		);

		if ( $updated ) {
			AuditLogger::log( 'enrollment_revoked', 'enrollment', 0, [
				'student_id' => $student_id,
				'course_id'  => $course_id,
			] );
		}

		return (bool) $updated;
	}

	// ── Access check ──────────────────────────────────────────────────────────

	/**
	 * Returns the enrollment row (with progress) if the student has active access;
	 * null otherwise.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_active( int $student_id, int $course_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_enrollments
			  WHERE student_id = %d
			    AND course_id  = %d
			    AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
			  LIMIT 1",
			$student_id,
			$course_id
		), ARRAY_A );

		return $row ?: null;
	}

	/**
	 * Quick boolean check.
	 */
	public static function is_enrolled( int $student_id, int $course_id ): bool {
		return null !== self::get_active( $student_id, $course_id );
	}

	// ── Progress ──────────────────────────────────────────────────────────────

	/**
	 * Records that a student has reached a lesson.
	 *
	 * @param int   $student_id
	 * @param int   $course_id
	 * @param int   $lesson_index   0-based position in the lessons array.
	 * @param int   $watched_sec    Seconds watched in this session (cumulative max stored).
	 * @return bool
	 */
	public static function mark_lesson_progress(
		int $student_id,
		int $course_id,
		int $lesson_index,
		int $watched_sec = 0
	): bool {
		global $wpdb;

		$row = self::get_active( $student_id, $course_id );
		if ( ! $row ) {
			return false;
		}

		$progress = self::decode_progress( $row['progress_json'] ?? '' );

		// Track max watched seconds per lesson.
		$prev_sec = (int) ( $progress['watched_sec'][ (string) $lesson_index ] ?? 0 );
		if ( $watched_sec > $prev_sec ) {
			$progress['watched_sec'][ (string) $lesson_index ] = $watched_sec;
		}

		// Mark lesson completed if not already in the list.
		if ( ! in_array( $lesson_index, $progress['completed'], true ) ) {
			$progress['completed'][] = $lesson_index;
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'csb_enrollments',
			[
				'last_lesson_id' => $lesson_index,
				'progress_json'  => wp_json_encode( $progress ),
			],
			[ 'id' => (int) $row['id'] ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		return (bool) $updated;
	}

	/**
	 * Returns the decoded progress structure for a student/course pair.
	 *
	 * @return array{completed: int[], watched_sec: array<string,int>}
	 */
	public static function get_progress( int $student_id, int $course_id ): array {
		global $wpdb;

		$json = $wpdb->get_var( $wpdb->prepare(
			"SELECT progress_json FROM {$wpdb->prefix}csb_enrollments
			  WHERE student_id = %d AND course_id = %d LIMIT 1",
			$student_id,
			$course_id
		) );

		return self::decode_progress( (string) $json );
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * @return array{completed: int[], watched_sec: array<string,int>}
	 */
	private static function decode_progress( string $json ): array {
		$default = [ 'completed' => [], 'watched_sec' => [] ];

		if ( '' === $json ) {
			return $default;
		}

		$data = json_decode( $json, true );

		if ( ! is_array( $data ) ) {
			return $default;
		}

		return [
			'completed'   => array_map( 'intval', (array) ( $data['completed'] ?? [] ) ),
			'watched_sec' => array_map( 'intval', (array) ( $data['watched_sec'] ?? [] ) ),
		];
	}
}
