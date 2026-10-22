<?php
/**
 * SMS adapter — bridges CSB notifications to the site's SMS gateway plugin.
 *
 * Wires to NotificationService's ACTUAL contract:
 *   - filter `csb_notify_handled` ( bool $handled, string $event, array $payload )
 *       -> send an SMS and return true to short-circuit the e-mail fallback.
 *   - action `csb_bulk_sms` ( int[] $booking_ids, string $message )
 *       -> one SMS to each booking's student.
 *
 * The real sender is resolved via the `csb_sms_sender` filter (return a
 * callable($phone, $message): bool), with best-effort auto-detection of
 * common SMS plugins. When nothing is available it does NOT claim the
 * notification (so the e-mail fallback runs) and never hard-fails.
 *
 * @package BookaClass\Support
 */

namespace BookaClass\Support;

use BookaClass\Services\AuditLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SmsAdapter
 */
class SmsAdapter {

	/**
	 * Registers the notification hooks (matching NotificationService's signatures).
	 */
	public function init(): void {
		add_filter( 'csb_notify_handled', [ $this, 'maybe_handle' ], 10, 3 );
		add_action( 'csb_bulk_sms',       [ $this, 'handle_bulk' ],  10, 2 );
	}

	/**
	 * Filter callback on `csb_notify_handled`.
	 *
	 * @param  bool                $handled
	 * @param  string              $event
	 * @param  array<string,mixed> $payload  NotificationService keys: phone, body, subject, student_id.
	 * @return bool  True when the SMS was sent (skips e-mail fallback); otherwise $handled.
	 */
	public function maybe_handle( $handled, $event, $payload ) {
		if ( $handled ) {
			return $handled;
		}

		$phone = isset( $payload['phone'] ) ? sanitize_text_field( (string) $payload['phone'] ) : '';
		if ( '' === $phone ) {
			return $handled;
		}

		$message = '';
		if ( ! empty( $payload['body'] ) ) {
			$message = (string) $payload['body'];
		} elseif ( ! empty( $payload['subject'] ) ) {
			$message = (string) $payload['subject'];
		}
		if ( '' === $message ) {
			return $handled;
		}

		$sent = $this->send( $phone, $message );

		AuditLogger::log( 'sms_dispatched', 'phone', (int) ( $payload['student_id'] ?? 0 ), [
			'event' => (string) $event,
			'ok'    => $sent,
		] );

		return $sent ? true : $handled;
	}

	/**
	 * Action callback on `csb_bulk_sms` — one message to each booking's student.
	 *
	 * @param int[]  $booking_ids
	 * @param string $message
	 */
	public function handle_bulk( $booking_ids, $message ): void {
		global $wpdb;

		$message = trim( (string) $message );
		if ( '' === $message || empty( $booking_ids ) ) {
			return;
		}

		$requested = count( (array) $booking_ids );
		$sent      = 0;

		foreach ( array_map( 'intval', (array) $booking_ids ) as $booking_id ) {
			if ( $booking_id <= 0 ) {
				continue;
			}

			$student_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT student_id FROM {$wpdb->prefix}csb_bookings WHERE id = %d",
				$booking_id
			) );
			if ( $student_id <= 0 ) {
				continue;
			}

			$phone = sanitize_text_field( (string) get_user_meta( $student_id, 'billing_phone', true ) );
			if ( '' === $phone ) {
				continue;
			}

			if ( $this->send( $phone, $message ) ) {
				$sent++;
			}
		}

		AuditLogger::log( 'sms_bulk_dispatched', 'session', 0, [
			'sent'      => $sent,
			'requested' => $requested,
		] );
	}

	/**
	 * Sends a single SMS through the resolved gateway. Never throws.
	 *
	 * @param  string $phone
	 * @param  string $message
	 * @return bool
	 */
	private function send( string $phone, string $message ): bool {
		$sender = apply_filters( 'csb_sms_sender', null );
		if ( is_callable( $sender ) ) {
			try {
				return (bool) $sender( $phone, $message );
			} catch ( \Throwable $e ) {
				error_log( 'CSB SmsAdapter custom sender failed: ' . $e->getMessage() );
				return false;
			}
		}

		try {
			if ( function_exists( 'wp_sms_panel_send' ) ) {
				return (bool) wp_sms_panel_send( $phone, $message );
			}
			if ( class_exists( 'WP_SMS_Panel\Gateway' ) && method_exists( 'WP_SMS_Panel\Gateway', 'send' ) ) {
				return (bool) \WP_SMS_Panel\Gateway::send( $phone, $message );
			}
			if ( function_exists( 'wp_sms_send' ) ) {
				return (bool) wp_sms_send( $phone, $message );
			}
			if ( has_action( 'wp_sms_panel_send_sms' ) ) {
				do_action( 'wp_sms_panel_send_sms', $phone, $message );
				return true;
			}
		} catch ( \Throwable $e ) {
			error_log( 'CSB SmsAdapter gateway failed: ' . $e->getMessage() );
			return false;
		}

		return false;
	}
}
