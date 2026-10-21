<?php
/**
 * Database installer / migrator.
 *
 * Uses dbDelta to create and update all wp_csb_* tables.
 * Rule: only ever ADDS columns/indexes, never destructively removes them,
 * so existing data survives upgrades.
 *
 * @package CookingSchoolBooking\Install
 */

namespace CookingSchoolBooking\Install;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrator
 */
class Migrator {

	/**
	 * Creates or updates all plugin tables.
	 * Called on plugin activation and when CSB_DB_VERSION > csb_db_version option.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		// ── 1. Sessions ───────────────────────────────────────────────────────
		// Each physical or online class meeting with its own capacity.
		$sql_sessions = "CREATE TABLE {$prefix}csb_sessions (
  id            BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  class_id      BIGINT(20)   UNSIGNED NOT NULL COMMENT 'WooCommerce product_id of the class',
  start_at      DATETIME     NOT NULL            COMMENT 'UTC start time',
  duration_min  SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 60,
  capacity      SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 0,
  seats_taken   SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 0,
  min_to_run    SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Minimum seats to hold the session',
  location      VARCHAR(255) NOT NULL DEFAULT '',
  online_url    VARCHAR(500) NOT NULL DEFAULT '',
  status        VARCHAR(20)  NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled|running|completed|cancelled',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY class_id (class_id),
  KEY status_start (status, start_at)
) {$charset_collate};";

		// ── 2. Seat locks ─────────────────────────────────────────────────────
		// Atomic reservation during the checkout window (15 min TTL).
		$sql_seat_locks = "CREATE TABLE {$prefix}csb_seat_locks (
  id          BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id  BIGINT(20)   UNSIGNED NOT NULL,
  lock_token  VARCHAR(64)  NOT NULL COMMENT 'Cart/session token that owns this lock',
  qty         SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 1,
  expires_at  DATETIME     NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY expires_at (expires_at),
  UNIQUE KEY lock_token (lock_token)
) {$charset_collate};";

		// ── 3. Bookings ───────────────────────────────────────────────────────
		// Links a WC order item to a session; tracks deposit/balance lifecycle.
		$sql_bookings = "CREATE TABLE {$prefix}csb_bookings (
  id                BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  wc_order_id       BIGINT(20)   UNSIGNED NOT NULL,
  wc_order_item_id  BIGINT(20)   UNSIGNED NOT NULL,
  session_id        BIGINT(20)   UNSIGNED NOT NULL,
  student_id        BIGINT(20)   UNSIGNED NOT NULL COMMENT 'WP user_id',
  status            VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending|deposit_paid|fully_paid|confirmed|completed|cancelled|expired|no_show',
  deposit_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_amount       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance_due       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  balance_order_id  BIGINT(20)   UNSIGNED DEFAULT NULL COMMENT 'WC order_id of the balance payment',
  settle_due_at     DATETIME     DEFAULT NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY wc_order_id (wc_order_id),
  KEY session_id (session_id),
  KEY student_id (student_id),
  KEY status (status)
) {$charset_collate};";

		// ── 4. Enrollments ────────────────────────────────────────────────────
		// Video course access granted after successful order.
		$sql_enrollments = "CREATE TABLE {$prefix}csb_enrollments (
  id             BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id     BIGINT(20)   UNSIGNED NOT NULL,
  course_id      BIGINT(20)   UNSIGNED NOT NULL COMMENT 'WooCommerce product_id of the video course',
  wc_order_id    BIGINT(20)   UNSIGNED NOT NULL,
  granted_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at     DATETIME     DEFAULT NULL COMMENT 'NULL = lifetime access',
  last_lesson_id BIGINT(20)   UNSIGNED DEFAULT NULL,
  progress_json  LONGTEXT     DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY student_id (student_id),
  KEY course_id (course_id),
  UNIQUE KEY student_course (student_id, course_id)
) {$charset_collate};";

		// ── 5. Wallet transactions ─────────────────────────────────────────────
		// Credit/debit ledger for refunds returned as store credit.
		$sql_wallet_txns = "CREATE TABLE {$prefix}csb_wallet_txns (
  id            BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    BIGINT(20)    UNSIGNED NOT NULL,
  direction     VARCHAR(6)    NOT NULL COMMENT 'credit|debit',
  amount        DECIMAL(12,2) NOT NULL,
  reason        VARCHAR(255)  NOT NULL DEFAULT '',
  ref_id        VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'Order ID, booking ID, etc.',
  balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY student_id (student_id),
  KEY created_at (created_at)
) {$charset_collate};";

		// ── 6. Refund requests ───────────────────────────────────────────────────
		// Stores student-initiated or system-generated refund requests pending
		// admin approval.  Admin approves → RefundService issues WC refund or
		// wallet credit and marks this row approved.
		$sql_refund_requests = "CREATE TABLE {$prefix}csb_refund_requests (
  id           BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id   BIGINT(20)    UNSIGNED NOT NULL,
  requested_by BIGINT(20)    UNSIGNED NOT NULL DEFAULT 0 COMMENT 'WP user_id; 0 = system',
  reason       VARCHAR(255)  NOT NULL DEFAULT '' COMMENT 'cancellation_policy|min_to_run|admin',
  refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  policy_tier  VARCHAR(50)   NOT NULL DEFAULT '',
  method       VARCHAR(10)   NOT NULL DEFAULT 'wallet' COMMENT 'cash|wallet',
  status       VARCHAR(20)   NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
  reviewed_by  BIGINT(20)    UNSIGNED DEFAULT NULL COMMENT 'WP user_id of admin who acted',
  reviewed_at  DATETIME      DEFAULT NULL,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset_collate};";

		// ── 7. Video tokens ──────────────────────────────────────────────────
		// HMAC-backed time-limited access tokens for video stream and resource
		// download links.  Rows are purged periodically by VideoAccessService.
		$sql_video_tokens = "CREATE TABLE {$prefix}csb_video_tokens (
  id            BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = guest free-preview',
  course_id     BIGINT(20)   UNSIGNED NOT NULL,
  lesson_index  SMALLINT(5)  UNSIGNED NOT NULL DEFAULT 0,
  token         VARCHAR(64)  NOT NULL,
  expires_at    DATETIME     NOT NULL,
  single_use    TINYINT(1)   NOT NULL DEFAULT 0,
  used          TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY token (token),
  KEY student_course (student_id, course_id),
  KEY expires_at (expires_at)
) {$charset_collate};";

		// ── 8. Audit log ──────────────────────────────────────────────────────
		// Immutable trail for every sensitive action.
		$sql_audit_log = "CREATE TABLE {$prefix}csb_audit_log (
  id          BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
  action      VARCHAR(100) NOT NULL,
  object_type VARCHAR(50)  NOT NULL DEFAULT '',
  object_id   BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
  data_json   LONGTEXT     DEFAULT NULL,
  ip          VARCHAR(45)  NOT NULL DEFAULT '' COMMENT 'IPv4 or IPv6',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY user_id (user_id),
  KEY action (action),
  KEY object (object_type, object_id),
  KEY created_at (created_at)
) {$charset_collate};";

		// ── 9. Certificates ──────────────────────────────────────────────────
		// Course-completion certificates with unique human-readable serials.
		$sql_certificates = "CREATE TABLE {$prefix}csb_certificates (
  id          BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  BIGINT(20)   UNSIGNED NOT NULL,
  student_id  BIGINT(20)   UNSIGNED NOT NULL,
  course_id   BIGINT(20)   UNSIGNED NOT NULL,
  serial      VARCHAR(40)  NOT NULL COMMENT 'Format: CSB-{year}-{booking_id}-{6hex}',
  issued_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  issued_by   BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = cron/system',
  revoked     TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY serial (serial),
  UNIQUE KEY booking_id (booking_id),
  KEY student_id (student_id),
  KEY course_id (course_id)
) {$charset_collate};";

		// ── 10. Attendance ────────────────────────────────────────────────────
		// Per-booking check-in records for physical class sessions.
		$sql_attendance = "CREATE TABLE {$prefix}csb_attendance (
  id             BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id     BIGINT(20)   UNSIGNED NOT NULL,
  session_id     BIGINT(20)   UNSIGNED NOT NULL,
  student_id     BIGINT(20)   UNSIGNED NOT NULL,
  status         VARCHAR(10)  NOT NULL DEFAULT 'present' COMMENT 'present|no_show',
  checked_in_by  BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
  note           TEXT         NOT NULL DEFAULT '',
  checked_in_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY booking_id (booking_id),
  KEY session_id (session_id),
  KEY student_id (student_id)
) {$charset_collate};";

		// ── 11. OTP tokens ───────────────────────────────────────────────────
		// Short-lived one-time passwords for phone-number login (Phase 7).
		$sql_otp_tokens = "CREATE TABLE {$prefix}csb_otp_tokens (
  id          BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  phone       VARCHAR(20)  NOT NULL COMMENT 'normalised E.164 or local format',
  code        VARCHAR(8)   NOT NULL COMMENT 'hashed OTP code',
  expires_at  DATETIME     NOT NULL,
  used        TINYINT(1)   NOT NULL DEFAULT 0,
  attempts    TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0 COMMENT 'wrong-guess counter (max 5)',
  ip          VARCHAR(45)  NOT NULL DEFAULT '',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY phone (phone),
  KEY expires_at (expires_at)
) {$charset_collate};";

		// ── 12. Coupons ───────────────────────────────────────────────────────
		// CSB-scoped discount codes (applied at checkout, stored here for audit).
		$sql_coupons = "CREATE TABLE {$prefix}csb_coupons (
  id             BIGINT(20)    UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(50)   NOT NULL,
  discount_type  VARCHAR(10)   NOT NULL DEFAULT 'percent' COMMENT 'percent|fixed',
  amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  class_id       BIGINT(20)    UNSIGNED DEFAULT NULL COMMENT 'NULL = all class products',
  usage_limit    INT(10)       UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited',
  usage_count    INT(10)       UNSIGNED NOT NULL DEFAULT 0,
  expires_at     DATETIME      DEFAULT NULL COMMENT 'NULL = never expires',
  active         TINYINT(1)    NOT NULL DEFAULT 1,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY class_id (class_id),
  KEY active (active)
) {$charset_collate};";

		// ── 13. Reschedule requests ───────────────────────────────────────────
		// Student-initiated request to move a booking to a different session.
		$sql_reschedule = "CREATE TABLE {$prefix}csb_reschedule_requests (
  id              BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id      BIGINT(20)   UNSIGNED NOT NULL,
  old_session_id  BIGINT(20)   UNSIGNED NOT NULL,
  new_session_id  BIGINT(20)   UNSIGNED NOT NULL,
  requested_by    BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
  status          VARCHAR(10)  NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected',
  note            TEXT         NOT NULL DEFAULT '',
  reviewed_by     BIGINT(20)   UNSIGNED DEFAULT NULL,
  reviewed_at     DATETIME     DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY status (status)
) {$charset_collate};";

		// Run dbDelta for each table.
		dbDelta( $sql_sessions );
		dbDelta( $sql_seat_locks );
		dbDelta( $sql_bookings );
		dbDelta( $sql_enrollments );
		dbDelta( $sql_wallet_txns );
		dbDelta( $sql_refund_requests );
		dbDelta( $sql_video_tokens );
		dbDelta( $sql_audit_log );
		dbDelta( $sql_certificates );
		dbDelta( $sql_attendance );
		dbDelta( $sql_otp_tokens );
		dbDelta( $sql_coupons );
		dbDelta( $sql_reschedule );

		update_option( 'csb_db_version', CSB_DB_VERSION );
	}

	/**
	 * Runs install() only when the stored DB version is behind the current constant.
	 * Cheap to call on every page load.
	 */
	public static function maybe_upgrade(): void {
		$stored = (int) get_option( 'csb_db_version', 0 );

		if ( $stored < CSB_DB_VERSION ) {
			self::install();
		}
	}
}
