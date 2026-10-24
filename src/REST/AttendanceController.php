<?php
/**
 * REST controller: attendance and certificate endpoints.
 *
 * Routes:
 *   POST csb/v1/attendance/{booking_id}/checkin     — check a student in
 *   POST csb/v1/attendance/{booking_id}/noshow      — mark no-show
 *   POST csb/v1/booking/{booking_id}/certificate    — issue certificate
 *   GET  csb/v1/certificate/verify/{serial}         — public serial verification
 *
 * All mutation routes require manage_woocommerce or csb_view_sessions capability.
 * The verify route is public (no authentication required).
 *
 * @package BookaClass\REST
 */

namespace BookaClass\REST;

use BookaClass\Services\AttendanceService;
use BookaClass\Services\CertificateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttendanceController
 */
class AttendanceController {

	private const NAMESPACE = 'csb/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// Check-in.
		register_rest_route( self::NAMESPACE, '/attendance/(?P<booking_id>\d+)/checkin', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'checkin' ],
			'permission_callback' => [ $this, 'instructor_permission' ],
			'args'                => [
				'booking_id' => [
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
				'note' => [
					'required'          => false,
					'sanitize_callback' => 'sanitize_textarea_field',
					'default'           => '',
				],
			],
		] );

		// No-show.
		register_rest_route( self::NAMESPACE, '/attendance/(?P<booking_id>\d+)/noshow', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'no_show' ],
			'permission_callback' => [ $this, 'instructor_permission' ],
			'args'                => [
				'booking_id' => [
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Issue certificate (admin/cron triggered).
		register_rest_route( self::NAMESPACE, '/booking/(?P<booking_id>\d+)/certificate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'issue_certificate' ],
			'permission_callback' => [ $this, 'admin_permission' ],
			'args'                => [
				'booking_id' => [
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Public serial verification.
		register_rest_route( self::NAMESPACE, '/certificate/verify/(?P<serial>[A-Z0-9\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'verify_certificate' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'serial' => [
					'validate_callback' => fn( $v ) => (bool) preg_match( '/^CSB-\d{4}-\d+-[A-F0-9]{6}$/', $v ),
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	// ── Permission callbacks ───────────────────────────────────────────────────

	/**
	 * Instructor/admin check-in permission.
	 *
	 * WC admins may act on any booking. Instructors (csb_view_sessions) may only
	 * act on bookings belonging to a session of a class they are assigned to
	 * (via the `_csb_instructor_id` postmeta on the class product). This prevents
	 * an IDOR where one instructor marks attendance on another instructor's class.
	 *
	 * @param  \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function instructor_permission( \WP_REST_Request $request ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		if ( ! current_user_can( 'csb_view_sessions' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'دسترسی غیرمجاز.', 'csb' ),
				[ 'status' => 403 ]
			);
		}

		$booking_id = (int) $request->get_param( 'booking_id' );
		if ( ! $this->instructor_owns_booking( $booking_id, get_current_user_id() ) ) {
			return new \WP_Error(
				'csb_not_owner',
				esc_html__( 'این رزرو به جلسات شما تعلق ندارد.', 'csb' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * True when the given instructor is assigned to the class product that owns
	 * the booking's session (via `_csb_instructor_id` postmeta).
	 */
	private function instructor_owns_booking( int $booking_id, int $instructor_id ): bool {
		global $wpdb;

		if ( $booking_id <= 0 || $instructor_id <= 0 ) {
			return false;
		}

		$match = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$wpdb->prefix}csb_bookings b
			   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
			   JOIN {$wpdb->postmeta} pm
			          ON pm.post_id  = s.class_id
			         AND pm.meta_key = '_csb_instructor_id'
			         AND CAST( pm.meta_value AS UNSIGNED ) = %d
			  WHERE b.id = %d",
			$instructor_id,
			$booking_id
		) );

		return $match > 0;
	}

	// ── Endpoints ─────────────────────────────────────────────────────────────

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function checkin( \WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );
		$note       = (string) $request->get_param( 'note' );

		$att_id = AttendanceService::check_in( $booking_id, get_current_user_id(), $note );

		if ( ! $att_id ) {
			return new \WP_Error(
				'csb_checkin_failed',
				__( 'ثبت حضور ممکن نیست. رزرو باید در وضعیت تأیید شده باشد.', 'csb' ),
				[ 'status' => 409 ]
			);
		}

		return rest_ensure_response( [
			'success'        => true,
			'attendance_id'  => $att_id,
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function no_show( \WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );

		$ok = AttendanceService::mark_no_show( $booking_id, get_current_user_id() );

		if ( ! $ok ) {
			return new \WP_Error(
				'csb_no_show_failed',
				__( 'ثبت غیبت ممکن نیست. رزرو باید در وضعیت تأیید شده باشد.', 'csb' ),
				[ 'status' => 409 ]
			);
		}

		return rest_ensure_response( [ 'success' => true ] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function issue_certificate( \WP_REST_Request $request ) {
		$booking_id = (int) $request->get_param( 'booking_id' );

		$cert_id = CertificateService::issue( $booking_id, get_current_user_id() );

		if ( ! $cert_id ) {
			return new \WP_Error(
				'csb_cert_failed',
				__( 'صدور گواهی ممکن نبود. رزرو پیدا نشد.', 'csb' ),
				[ 'status' => 422 ]
			);
		}

		$cert = CertificateService::get_by_booking( $booking_id );

		return rest_ensure_response( [
			'success'        => true,
			'certificate_id' => $cert_id,
			'serial'         => $cert['serial'] ?? '',
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_certificate( \WP_REST_Request $request ) {
		$serial = (string) $request->get_param( 'serial' );

		$cert = CertificateService::verify_serial( $serial );

		if ( ! $cert ) {
			return new \WP_Error(
				'csb_cert_invalid',
				__( 'گواهی با این شماره سریال پیدا نشد یا ابطال شده است.', 'csb' ),
				[ 'status' => 404 ]
			);
		}

		$student = get_userdata( (int) $cert['student_id'] );
		$course  = get_post( (int) $cert['course_id'] );

		return rest_ensure_response( [
			'valid'        => true,
			'serial'       => $cert['serial'],
			'student_name' => $student ? $student->display_name : '',
			'course_title' => $course  ? $course->post_title    : '',
			'issued_at'    => \BookaClass\Support\Jalali::to_jalali_string( $cert['issued_at'] ),
		] );
	}
}
