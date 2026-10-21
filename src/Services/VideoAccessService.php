<?php
/**
 * Signed URL generator for protected video lessons and downloadable resources.
 *
 * Architecture:
 *   — All tokens are stored in wp_csb_video_tokens (id, student_id, course_id,
 *     lesson_index, token, expires_at, used).
 *   — "local" host: redirect to a password-protected /wp-content path guarded
 *     by VideoController::serve_local_video().
 *   — "arvan" host: generate an Arvan CDN signed URL using HMAC-SHA256.
 *   — "tamasha" host: pass through the embed URL with a signed token appended.
 *   — Free-preview lessons can be requested by unauthenticated users; other
 *     lessons require an active enrollment.
 *
 * Token TTL: 4 hours by default.  Tokens are single-use for download links,
 * multi-use (re-validated per-request) for stream links.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

use CookingSchoolBooking\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class VideoAccessService
 */
class VideoAccessService {

	/** Default token TTL in seconds (4 hours). */
	private const TOKEN_TTL = 4 * HOUR_IN_SECONDS;

	/** Download token TTL (single-use, shorter window). */
	private const DOWNLOAD_TTL = 30 * MINUTE_IN_SECONDS;

	// ── Token issuance ────────────────────────────────────────────────────────

	/**
	 * Issues a signed stream URL for a lesson.
	 *
	 * For enrolled students (or free-preview lessons without a logged-in user).
	 * Returns null when access is denied.
	 *
	 * @param  int    $student_id    0 for unauthenticated free-preview access.
	 * @param  int    $course_id
	 * @param  int    $lesson_index  0-based.
	 * @param  array<string,mixed> $lesson  The lesson data row from the product.
	 * @return string|null  Signed URL or null on denial.
	 */
	public static function signed_stream_url(
		int $student_id,
		int $course_id,
		int $lesson_index,
		array $lesson
	): ?string {
		// Free preview — accessible without enrollment (unauthenticated ok).
		$is_free = ! empty( $lesson['is_free_preview'] );

		if ( ! $is_free ) {
			if ( ! $student_id || ! EnrollmentService::is_enrolled( $student_id, $course_id ) ) {
				return null;
			}
		}

		$token  = self::create_token( $student_id, $course_id, $lesson_index, self::TOKEN_TTL );
		$host   = SettingsPage::get()['video_host'] ?? 'local';

		return self::build_url( $host, $token, $course_id, $lesson_index, $lesson, 'stream' );
	}

	/**
	 * Issues a signed download URL for a lesson resource.
	 *
	 * Requires active enrollment (resources are never free-preview).
	 *
	 * @return string|null
	 */
	public static function signed_resource_url(
		int $student_id,
		int $course_id,
		int $lesson_index,
		array $lesson
	): ?string {
		if ( empty( $lesson['resource_url'] ) ) {
			return null;
		}

		if ( ! $student_id || ! EnrollmentService::is_enrolled( $student_id, $course_id ) ) {
			return null;
		}

		$token = self::create_token( $student_id, $course_id, $lesson_index, self::DOWNLOAD_TTL, true );

		return add_query_arg( [
			'csb_action'    => 'resource',
			'csb_token'     => $token,
			'csb_course'    => $course_id,
			'csb_lesson'    => $lesson_index,
		], home_url( '/' ) );
	}

	// ── Token validation ──────────────────────────────────────────────────────

	/**
	 * Validates a token string.
	 *
	 * @param  string $token_str  Raw token value from the request.
	 * @param  int    $course_id
	 * @param  int    $lesson_index
	 * @param  bool   $consume    Mark the token as used on success (for download links).
	 * @return array<string,mixed>|null  Row if valid; null otherwise.
	 */
	public static function validate_token(
		string $token_str,
		int $course_id,
		int $lesson_index,
		bool $consume = false
	): ?array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}csb_video_tokens
			  WHERE token        = %s
			    AND course_id    = %d
			    AND lesson_index = %d
			    AND expires_at   > UTC_TIMESTAMP()
			    AND used         = 0
			  LIMIT 1",
			$token_str,
			$course_id,
			$lesson_index
		), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		if ( $consume ) {
			$wpdb->update(
				$wpdb->prefix . 'csb_video_tokens',
				[ 'used' => 1 ],
				[ 'id'   => (int) $row['id'] ],
				[ '%d' ],
				[ '%d' ]
			);
		}

		return $row;
	}

	// ── Host adapters ─────────────────────────────────────────────────────────

	/**
	 * Builds the final URL for the configured video host.
	 *
	 * @param  string              $host         'local'|'arvan'|'tamasha'
	 * @param  string              $token
	 * @param  int                 $course_id
	 * @param  int                 $lesson_index
	 * @param  array<string,mixed> $lesson
	 * @param  string              $type         'stream'|'resource'
	 * @return string
	 */
	private static function build_url(
		string $host,
		string $token,
		int $course_id,
		int $lesson_index,
		array $lesson,
		string $type
	): string {
		switch ( $host ) {
			case 'arvan':
				return self::arvan_signed_url( $token, $course_id, $lesson_index, $lesson );

			case 'tamasha':
				return self::tamasha_embed_url( $token, $lesson );

			case 'local':
			default:
				return self::local_stream_url( $token, $course_id, $lesson_index );
		}
	}

	/**
	 * Local host: REST endpoint URL that validates token and proxies/redirects
	 * to the protected upload path.
	 */
	private static function local_stream_url(
		string $token,
		int $course_id,
		int $lesson_index
	): string {
		return rest_url( sprintf(
			'csb/v1/video/%d/%d/stream?csb_token=%s',
			$course_id,
			$lesson_index,
			rawurlencode( $token )
		) );
	}

	/**
	 * Arvan CDN adapter: append HMAC-SHA256 signature to the video source URL.
	 *
	 * Expects post meta `_csb_arvan_secret` on the course product,
	 * or a global setting `arvan_secret_key`.
	 *
	 * @param  array<string,mixed> $lesson
	 */
	private static function arvan_signed_url(
		string $token,
		int $course_id,
		int $lesson_index,
		array $lesson
	): string {
		$video_ref = (string) ( $lesson['video_ref'] ?? '' );

		if ( '' === $video_ref ) {
			return '';
		}

		$secret = (string) ( get_post_meta( $course_id, '_csb_arvan_secret', true ) ?: '' );

		if ( '' === $secret ) {
			$settings = SettingsPage::get();
			$secret   = (string) ( $settings['arvan_secret_key'] ?? '' );
		}

		$expires   = time() + self::TOKEN_TTL;
		$to_sign   = $video_ref . '/' . $expires;
		$signature = hash_hmac( 'sha256', $to_sign, $secret );

		return add_query_arg( [
			'md5'        => $signature,
			'expires'    => $expires,
			'csb_token'  => $token,
		], $video_ref );
	}

	/**
	 * Tamasha embed adapter: append our access token as a viewer parameter.
	 *
	 * video_ref for Tamasha is the embed URL like https://tamasha.com/v/XXX.
	 *
	 * @param  array<string,mixed> $lesson
	 */
	private static function tamasha_embed_url( string $token, array $lesson ): string {
		$video_ref = (string) ( $lesson['video_ref'] ?? '' );

		if ( '' === $video_ref ) {
			return '';
		}

		return add_query_arg( 'csb_access', rawurlencode( $token ), $video_ref );
	}

	// ── DB helpers ────────────────────────────────────────────────────────────

	/**
	 * Inserts a token row and returns the token string.
	 *
	 * @param  int  $student_id   0 for guest/free-preview.
	 * @param  bool $single_use   When true, token is consumed after first validation.
	 */
	private static function create_token(
		int $student_id,
		int $course_id,
		int $lesson_index,
		int $ttl,
		bool $single_use = false
	): string {
		global $wpdb;

		// Reuse an existing unexpired token for the same student/course/lesson
		// to avoid bloating the table on rapid page reloads.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT token FROM {$wpdb->prefix}csb_video_tokens
			  WHERE student_id   = %d
			    AND course_id    = %d
			    AND lesson_index = %d
			    AND used         = 0
			    AND expires_at   > DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
			  ORDER BY expires_at DESC LIMIT 1",
			$student_id,
			$course_id,
			$lesson_index
		) );

		if ( $existing ) {
			return (string) $existing;
		}

		$token      = bin2hex( random_bytes( 24 ) ); // 48-char hex
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + $ttl );

		$wpdb->insert(
			$wpdb->prefix . 'csb_video_tokens',
			[
				'student_id'   => $student_id,
				'course_id'    => $course_id,
				'lesson_index' => $lesson_index,
				'token'        => $token,
				'expires_at'   => $expires_at,
				'single_use'   => $single_use ? 1 : 0,
				'used'         => 0,
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
		);

		return $token;
	}

	/**
	 * Purges expired token rows.
	 * Called occasionally by VideoController on token requests.
	 */
	public static function purge_expired(): void {
		global $wpdb;

		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}csb_video_tokens
			  WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
		);
	}
}
