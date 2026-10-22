<?php
/**
 * REST controller: booking actions exposed via the csb/v1 namespace.
 *
 * Endpoints (BUILD-SPEC §15):
 *
 *   POST  csb/v1/booking/{id}/cancel     — student self-cancels; creates refund request
 *   POST  csb/v1/admin/refund/{id}/approve  — admin approves a pending refund request
 *   POST  csb/v1/admin/refund/{id}/reject   — admin rejects a pending refund request
 *
 * All endpoints:
 *   — nonce checked automatically by WP REST (X-WP-Nonce header or _wpnonce query arg).
 *   — permission_callback enforces ownership / manage_woocommerce capability.
 *   — Responses: { success, data } or { success, error }.
 *
 * @package BookaClass\REST
 */

namespace BookaClass\REST;

use BookaClass\Services\BookingService;
use BookaClass\Services\RefundService;
use BookaClass\Services\RescheduleService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BookingController
 */
class BookingController {

	public const NAMESPACE = 'csb/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		// Student cancel → creates refund request (pending admin approval).
		register_rest_route( self::NAMESPACE, '/booking/(?P<id>\d+)/cancel', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'cancel_booking' ],
			'permission_callback' => [ $this, 'can_cancel' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
				'refund_method' => [
					'required'          => false,
					'default'           => 'wallet',
					'validate_callback' => fn( $v ) => in_array( $v, [ 'cash', 'wallet' ], true ),
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Admin approve refund request.
		register_rest_route( self::NAMESPACE, '/admin/refund/(?P<id>\d+)/approve', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'approve_refund' ],
			'permission_callback' => [ $this, 'require_wc_admin' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Student reschedule — direct atomic seat move (same-class sessions only).
		register_rest_route( self::NAMESPACE, '/booking/(?P<id>\d+)/reschedule', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'reschedule_booking' ],
			'permission_callback' => [ $this, 'can_cancel' ], // same ownership check
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
				'new_session_id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Admin reject refund request.
		register_rest_route( self::NAMESPACE, '/admin/refund/(?P<id>\d+)/reject', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'reject_refund' ],
			'permission_callback' => [ $this, 'require_wc_admin' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/**
	 * Logged-in user who owns the booking, or a WC admin.
	 */
	public function can_cancel( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden',
				esc_html__( 'برای لغو رزرو باید وارد حساب کاربری شوید.', 'csb' ),
				[ 'status' => 401 ]
			);
		}

		$booking_id = (int) $request->get_param( 'id' );
		$booking    = BookingService::get( $booking_id );

		if ( ! $booking ) {
			return new \WP_Error( 'csb_not_found',
				esc_html__( 'رزرو یافت نشد.', 'csb' ),
				[ 'status' => 404 ]
			);
		}

		if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( (int) $booking['student_id'] !== get_current_user_id() ) {
			return new \WP_Error( 'rest_forbidden',
				esc_html__( 'شما اجازه‌ی لغو این رزرو را ندارید.', 'csb' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Only WC admins.
	 */
	public function require_wc_admin( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'rest_forbidden',
				esc_html__( 'دسترسی غیرمجاز.', 'csb' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	// ── Handlers ─────────────────────────────────────────────────────────────

	/**
	 * POST csb/v1/booking/{id}/cancel
	 *
	 * Creates a pending refund request; admin must approve separately.
	 * Students are always refunded to wallet; admins may choose 'cash'.
	 */
	public function cancel_booking( \WP_REST_Request $request ): \WP_REST_Response {
		$booking_id    = (int) $request->get_param( 'id' );
		$refund_method = (string) $request->get_param( 'refund_method' );
		$user_id       = get_current_user_id();

		$is_admin = current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		if ( ! $is_admin ) {
			$refund_method = 'wallet'; // Students always get wallet.
		}

		$cancelled_by = $is_admin ? 'school' : 'student';

		// Show breakdown for transparency.
		$breakdown = RefundService::calculate( $booking_id, $cancelled_by );
		if ( ! $breakdown ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'booking_or_session_not_found' ], 404 );
		}

		$request_id = RefundService::request_refund(
			$booking_id,
			$cancelled_by,
			$refund_method,
			$user_id,
			false // requires admin approval
		);

		if ( ! $request_id ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => 'request_failed' ], 400 );
		}

		return new \WP_REST_Response( [
			'success'        => true,
			'request_id'     => $request_id,
			'booking_id'     => $booking_id,
			'policy_tier'    => $breakdown['policy_tier'],
			'refund_amount'  => $breakdown['refund_amount'],
			'refund_method'  => $refund_method,
			'awaiting_admin' => true,
		], 200 );
	}

	/**
	 * POST csb/v1/booking/{id}/reschedule
	 *
	 * Moves the booking to a different session of the same class.
	 * Seat counts are adjusted atomically; settle_due_at recalculated.
	 */
	public function reschedule_booking( \WP_REST_Request $request ): \WP_REST_Response {
		$booking_id     = (int) $request->get_param( 'id' );
		$new_session_id = (int) $request->get_param( 'new_session_id' );
		$student_id     = get_current_user_id();

		try {
			RescheduleService::reschedule( $booking_id, $new_session_id, $student_id );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => $e->getMessage() ], 422 );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( [ 'success' => false, 'error' => $e->getMessage() ], 409 );
		}

		return new \WP_REST_Response( [
			'success'        => true,
			'booking_id'     => $booking_id,
			'new_session_id' => $new_session_id,
		], 200 );
	}

	/**
	 * POST csb/v1/admin/refund/{id}/approve
	 */
	public function approve_refund( \WP_REST_Request $request ): \WP_REST_Response {
		$request_id = (int) $request->get_param( 'id' );
		$result     = RefundService::approve_refund( $request_id, get_current_user_id() );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}

	/**
	 * POST csb/v1/admin/refund/{id}/reject
	 */
	public function reject_refund( \WP_REST_Request $request ): \WP_REST_Response {
		$request_id = (int) $request->get_param( 'id' );
		$result     = RefundService::reject_refund( $request_id, get_current_user_id() );

		return new \WP_REST_Response( $result, $result['success'] ? 200 : 400 );
	}
}
