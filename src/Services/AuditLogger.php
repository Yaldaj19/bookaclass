<?php
/**
 * Thin audit-log helper.
 *
 * Writes one row to wp_csb_audit_log for every sensitive action.
 * All parameters are parameterised — never interpolated into SQL.
 *
 * @package BookaClass\Services
 */

namespace BookaClass\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AuditLogger
 */
class AuditLogger {

	/**
	 * Logs one event.
	 *
	 * @param string               $action      Short action identifier, e.g. 'cpt_config_updated'.
	 * @param string               $object_type Post type, 'cpt_config', 'booking', etc.
	 * @param int                  $object_id   Relevant object ID (0 if not applicable).
	 * @param array<string,mixed>  $data        Any extra data to record as JSON.
	 * @param int|null             $user_id     WP user ID; defaults to current user.
	 */
	public static function log(
		string $action,
		string $object_type = '',
		int $object_id = 0,
		array $data = [],
		?int $user_id = null
	): void {
		global $wpdb;

		$uid = $user_id ?? get_current_user_id();
		$ip  = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );

		$wpdb->insert(
			$wpdb->prefix . 'csb_audit_log',
			[
				'user_id'     => $uid,
				'action'      => substr( $action, 0, 100 ),
				'object_type' => substr( $object_type, 0, 50 ),
				'object_id'   => $object_id,
				'data_json'   => ! empty( $data ) ? wp_json_encode( $data ) : null,
				'ip'          => $ip,
				'created_at'  => current_time( 'mysql', true ), // UTC
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
	}
}
