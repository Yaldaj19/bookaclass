<?php
/**
 * REST controller: video-course stream and resource access.
 *
 * Endpoints:
 *
 *   GET  csb/v1/video/{course_id}/{lesson_index}/stream
 *        — Returns a signed stream URL (or serves/redirects the file for local host).
 *        — Requires enrollment unless lesson is free-preview.
 *
 *   GET  csb/v1/video/{course_id}/{lesson_index}/resource
 *        — Returns a signed one-time resource download URL.
 *        — Requires enrollment; single-use token consumed immediately.
 *
 *   POST csb/v1/enrollment/{course_id}/progress
 *        — Records lesson progress (completed + watched_sec).
 *        — Requires enrollment.
 *
 * All state-altering requests check the WP REST nonce automatically (X-WP-Nonce).
 * GET endpoints are nonce-free so the signed URL can be embedded in <video src>.
 *
 * @package BookaClass\REST
 */

namespace BookaClass\REST;

use BookaClass\Services\AuditLogger;
use BookaClass\Services\EnrollmentService;
use BookaClass\Services\VideoAccessService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class VideoController
 */
class VideoController {

	public const NAMESPACE = 'csb/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Local-host video file serving (bypasses REST bootstrap for performance).
		add_action( 'init', [ $this, 'maybe_serve_local_video' ] );
	}

	public function register_routes(): void {
		$id_schema = [
			'required'          => true,
			'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
			'sanitize_callback' => 'absint',
		];
		$index_schema = [
			'required'          => true,
			'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= 0,
			'sanitize_callback' => 'absint',
		];

		// GET stream URL (or redirect for local host).
		register_rest_route( self::NAMESPACE, '/video/(?P<course_id>\d+)/(?P<lesson_index>\d+)/stream', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_stream' ],
			'permission_callback' => '__return_true', // enrollment checked inside
			'args'                => [
				'course_id'    => $id_schema,
				'lesson_index' => $index_schema,
			],
		] );

		// GET resource download URL.
		register_rest_route( self::NAMESPACE, '/video/(?P<course_id>\d+)/(?P<lesson_index>\d+)/resource', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_resource' ],
			'permission_callback' => [ $this, 'require_enrolled' ],
			'args'                => [
				'course_id'    => $id_schema,
				'lesson_index' => $index_schema,
			],
		] );

		// POST progress.
		register_rest_route( self::NAMESPACE, '/enrollment/(?P<course_id>\d+)/progress', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'record_progress' ],
			'permission_callback' => [ $this, 'require_enrolled' ],
			'args'                => [
				'course_id' => $id_schema,
				'lesson_index' => array_merge( $index_schema, [ 'required' => true ] ),
				'watched_sec'  => [
					'required'          => false,
					'default'           => 0,
					'validate_callback' => fn( $v ) => is_numeric( $v ),
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/**
	 * Returns WP_Error unless the current user is enrolled in the course.
	 */
	public function require_enrolled( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden',
				esc_html__( 'برای دسترسی به این محتوا باید وارد حساب کاربری شوید.', 'csb' ),
				[ 'status' => 401 ]
			);
		}

		$course_id = (int) $request->get_param( 'course_id' );

		if ( ! EnrollmentService::is_enrolled( get_current_user_id(), $course_id ) ) {
			return new \WP_Error( 'csb_not_enrolled',
				esc_html__( 'شما در این دوره ثبت‌نام نکرده‌اید.', 'csb' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * GET csb/v1/video/{course_id}/{lesson_index}/stream
	 *
	 * Returns { url, host } for the client-side video player.
	 * For local host: issues a 302 redirect directly to the protected file.
	 */
	public function get_stream( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id    = (int) $request->get_param( 'course_id' );
		$lesson_index = (int) $request->get_param( 'lesson_index' );
		$student_id   = is_user_logged_in() ? get_current_user_id() : 0;

		$product = wc_get_product( $course_id );
		if ( ! $product || 'video_course' !== $product->get_type() ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'course_not_found' ], 404 );
		}

		/** @var \BookaClass\Products\VideoCourseProduct $product */
		$lessons = $product->get_csb_lessons();

		if ( ! isset( $lessons[ $lesson_index ] ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'lesson_not_found' ], 404 );
		}

		$lesson = $lessons[ $lesson_index ];

		// Periodically clean up expired tokens.
		if ( 0 === wp_rand( 0, 49 ) ) {
			VideoAccessService::purge_expired();
		}

		$url = VideoAccessService::signed_stream_url( $student_id, $course_id, $lesson_index, $lesson );

		if ( null === $url ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'access_denied' ], 403 );
		}

		$settings = \BookaClass\Admin\SettingsPage::get();
		$host     = $settings['video_host'] ?? 'local';

		AuditLogger::log( 'video_stream_requested', 'enrollment', 0, [
			'student_id'   => $student_id,
			'course_id'    => $course_id,
			'lesson_index' => $lesson_index,
			'host'         => $host,
		], $student_id ?: null );

		return new \WP_REST_Response( [
			'success'  => true,
			'url'      => $url,
			'host'     => $host,
			'title'    => esc_html( $lesson['title'] ?? '' ),
			'duration' => (int) ( $lesson['duration'] ?? 0 ),
		], 200 );
	}

	/**
	 * GET csb/v1/video/{course_id}/{lesson_index}/resource
	 *
	 * Returns a single-use signed URL for the lesson's downloadable resource.
	 */
	public function get_resource( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id    = (int) $request->get_param( 'course_id' );
		$lesson_index = (int) $request->get_param( 'lesson_index' );
		$student_id   = get_current_user_id();

		$product = wc_get_product( $course_id );
		if ( ! $product || 'video_course' !== $product->get_type() ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'course_not_found' ], 404 );
		}

		/** @var \BookaClass\Products\VideoCourseProduct $product */
		$lessons = $product->get_csb_lessons();

		if ( ! isset( $lessons[ $lesson_index ] ) ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'lesson_not_found' ], 404 );
		}

		$lesson = $lessons[ $lesson_index ];
		$url    = VideoAccessService::signed_resource_url( $student_id, $course_id, $lesson_index, $lesson );

		if ( null === $url ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'no_resource' ], 404 );
		}

		AuditLogger::log( 'resource_download_requested', 'enrollment', 0, [
			'student_id'   => $student_id,
			'course_id'    => $course_id,
			'lesson_index' => $lesson_index,
		] );

		return new \WP_REST_Response( [ 'success' => true, 'url' => $url ], 200 );
	}

	/**
	 * POST csb/v1/enrollment/{course_id}/progress
	 *
	 * Records lesson completion + watched seconds.
	 */
	public function record_progress( \WP_REST_Request $request ): \WP_REST_Response {
		$course_id    = (int) $request->get_param( 'course_id' );
		$lesson_index = (int) $request->get_param( 'lesson_index' );
		$watched_sec  = (int) $request->get_param( 'watched_sec' );
		$student_id   = get_current_user_id();

		$ok = EnrollmentService::mark_lesson_progress( $student_id, $course_id, $lesson_index, $watched_sec );

		if ( ! $ok ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'update_failed' ], 400 );
		}

		$progress = EnrollmentService::get_progress( $student_id, $course_id );

		return new \WP_REST_Response( [
			'success'   => true,
			'completed' => $progress['completed'],
		], 200 );
	}

	// ── Local-host video serving ──────────────────────────────────────────────

	/**
	 * Intercepts requests to `/?csb_action=resource&csb_token=...`
	 * to serve or redirect to the protected file.
	 *
	 * Must run on `init` (before headers are sent).
	 */
	public function maybe_serve_local_video(): void {
		$action = (string) ( $_GET['csb_action'] ?? '' );

		if ( 'resource' !== $action && 'stream' !== $action ) {
			return;
		}

		$token_str    = sanitize_text_field( $_GET['csb_token']  ?? '' );
		$course_id    = absint( $_GET['csb_course'] ?? 0 );
		$lesson_index = absint( $_GET['csb_lesson'] ?? 0 );

		if ( ! $token_str || ! $course_id ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ), '', [ 'response' => 403 ] );
		}

		$is_download = ( 'resource' === $action );
		$row = VideoAccessService::validate_token( $token_str, $course_id, $lesson_index, $is_download );

		if ( ! $row ) {
			wp_die( esc_html__( 'لینک منقضی یا نامعتبر است.', 'csb' ), '', [ 'response' => 403 ] );
		}

		$product = wc_get_product( $course_id );
		if ( ! $product || 'video_course' !== $product->get_type() ) {
			wp_die( esc_html__( 'دوره یافت نشد.', 'csb' ), '', [ 'response' => 404 ] );
		}

		/** @var \BookaClass\Products\VideoCourseProduct $product */
		$lessons = $product->get_csb_lessons();

		if ( ! isset( $lessons[ $lesson_index ] ) ) {
			wp_die( esc_html__( 'جلسه یافت نشد.', 'csb' ), '', [ 'response' => 404 ] );
		}

		$lesson  = $lessons[ $lesson_index ];
		$file_ref = (string) ( $lesson[ $is_download ? 'resource_url' : 'video_ref' ] ?? '' );

		if ( '' === $file_ref ) {
			wp_die( esc_html__( 'فایلی یافت نشد.', 'csb' ), '', [ 'response' => 404 ] );
		}

		AuditLogger::log(
			$is_download ? 'resource_served' : 'video_served',
			'enrollment',
			0,
			[
				'student_id'   => (int) $row['student_id'],
				'course_id'    => $course_id,
				'lesson_index' => $lesson_index,
			],
			(int) $row['student_id'] ?: null
		);

		// Redirect to the actual file (host handles auth via WP).
		wp_redirect( esc_url_raw( $file_ref ), 302 );
		exit;
	}
}
