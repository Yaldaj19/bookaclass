<?php
/**
 * REST controller: admin session operations.
 *
 * Routes:
 *   POST csb/v1/admin/session/{session_id}/sms — group SMS to all booked students.
 *
 * Requires manage_woocommerce. Fires do_action('csb_bulk_sms', $booking_ids, $message)
 * which Phase 7 SMS adapters hook into.
 *
 * @package BookaClass\REST
 */

namespace BookaClass\REST;

use BookaClass\Services\AuditLogger;
use BookaClass\Services\NotificationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdminSessionController
 */
class AdminSessionController {

	private const NAMESPACE = 'csb/v1';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/admin/session/(?P<session_id>\d+)/sms', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'send_group_sms' ],
			'permission_callback' => [ $this, 'admin_permission' ],
			'args'                => [
				'session_id' => [
					'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					'sanitize_callback' => 'absint',
				],
				'message' => [
					'required'          => true,
					'sanitize_callback' => 'sanitize_textarea_field',
				],
			],
		] );
	}

	public function admin_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_group_sms( \WP_REST_Request $request ) {
		global $wpdb;

		$session_id = (int) $request->get_param( 'session_id' );
		$message    = (string) $request->get_param( 'message' );

		if ( '' === $message ) {
			return new \WP_Error( 'csb_empty_message', __( 'متن پیامک نمی‌تواند خالی باشد.', 'csb' ), [ 'status' => 400 ] );
		}

		// Fetch all active booking IDs for this session.
		$booking_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}csb_bookings
			  WHERE session_id = %d
			    AND status IN ('deposit_paid','fully_paid','confirmed','completed')
			  ORDER BY id ASC",
			$session_id
		) );

		$booking_ids = array_map( 'absint', $booking_ids ?: [] );

		if ( empty( $booking_ids ) ) {
			return new \WP_Error( 'csb_no_recipients', __( 'هیچ رزروی برای این جلسه یافت نشد.', 'csb' ), [ 'status' => 404 ] );
		}

		/**
		 * Fires to trigger the SMS delivery. Phase 7 SMS adapters hook here.
		 *
		 * @param int[]  $booking_ids
		 * @param string $message
		 */
		do_action( 'csb_bulk_sms', $booking_ids, $message );

		AuditLogger::log( 'group_sms_sent', 'session', $session_id, [
			'actor'        => get_current_user_id(),
			'booking_count' => count( $booking_ids ),
		] );

		return rest_ensure_response( [
			'success'       => true,
			'recipients'    => count( $booking_ids ),
		] );
	}
}
