<?php
/**
 * Notification service stub.
 *
 * Phase 4 scope: thin dispatcher that logs every notification and fires
 * `do_action('csb_notify', $event, $payload)` so Phase 7 can attach
 * real SMS/Telegram adapters without touching this service.
 *
 * Built-in fallback: wp_mail() for email when no external adapter intercepts.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class NotificationService
 */
class NotificationService {

	/**
	 * Dispatches a notification event.
	 *
	 * @param  string $event    Machine-readable event key, e.g. 'settle_reminder',
	 *                          'class_tomorrow', 'booking_confirmed',
	 *                          'booking_cancelled_refund', 'session_cancelled'.
	 * @param  array<string,mixed> $payload  Context for adapters:
	 *   'student_id'   => int
	 *   'booking_id'   => int   (optional)
	 *   'session_id'   => int   (optional)
	 *   'subject'      => string (for email)
	 *   'body'         => string (for email / SMS body)
	 *   'email'        => string (to address; auto-resolved from student_id if absent)
	 *   'phone'        => string (optional; resolved from user meta if absent)
	 *   ...any event-specific keys...
	 */
	public static function dispatch( string $event, array $payload ): void {
		$student_id = (int) ( $payload['student_id'] ?? 0 );

		// Resolve email/phone lazily from WP user if not provided.
		if ( $student_id > 0 ) {
			if ( empty( $payload['email'] ) ) {
				$user = get_user_by( 'id', $student_id );
				if ( $user ) {
					$payload['email'] = $user->user_email;
					// phone stored as billing_phone in WC user meta.
					if ( empty( $payload['phone'] ) ) {
						$payload['phone'] = (string) get_user_meta( $student_id, 'billing_phone', true );
					}
				}
			}
		}

		/**
		 * Allow external adapters (SMS gateway, Telegram) to intercept the event.
		 * Returning a truthy value from a hook tells NotificationService the
		 * notification was handled — email fallback is skipped.
		 *
		 * Usage in Phase 7 SMS adapter:
		 *   add_filter('csb_notify_handled', function($handled, $event, $payload) {
		 *       if (in_array($event, ['settle_reminder','class_tomorrow'], true)) {
		 *           // send SMS …
		 *           return true;
		 *       }
		 *       return $handled;
		 *   }, 10, 3);
		 */
		$handled = (bool) apply_filters( 'csb_notify_handled', false, $event, $payload );

		/**
		 * Fires for every notification, regardless of whether it was handled.
		 * Useful for logging, analytics, or additional side-effects.
		 *
		 * @param string               $event
		 * @param array<string,mixed>  $payload
		 */
		do_action( 'csb_notify', $event, $payload );

		// Email fallback when no adapter claimed the notification.
		if ( ! $handled && ! empty( $payload['email'] ) && ! empty( $payload['subject'] ) ) {
			wp_mail(
				sanitize_email( $payload['email'] ),
				$payload['subject'],
				$payload['body'] ?? ''
			);
		}

		AuditLogger::log(
			'notification_dispatched',
			'student',
			$student_id,
			[
				'event'   => $event,
				'handled' => $handled,
			]
		);
	}
}
