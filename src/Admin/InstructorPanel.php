<?php
/**
 * Instructor role + restricted admin panel.
 *
 * Registers the `csb_instructor` WP role and provides a limited admin view so
 * instructors can see — and manage attendance for — only their own sessions.
 *
 * Ownership is determined by `_csb_instructor_id` postmeta on the class product,
 * which stores the WP user ID of the assigned instructor (set via the «مدرس»
 * user-dropdown in the product data panel — NOT post_author).
 *
 * Instructors can:
 *   — View sessions where the class product's _csb_instructor_id = their user ID.
 *   — Record check-in / no-show via the same admin-post handlers in AttendancePage.
 *   — Download a CSV roster for their own sessions.
 *
 * They cannot:
 *   — See other instructors' sessions.
 *   — Access the full bookings list.
 *   — Manage refunds, settings, or plugin config.
 *
 * @package BookaClass\Admin
 */

namespace BookaClass\Admin;

use BookaClass\Services\AttendanceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class InstructorPanel
 */
class InstructorPanel {

	public function init(): void {
		add_action( 'init',       [ $this, 'register_role' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ], 5 );
		add_action( 'admin_init', [ $this, 'maybe_export_csv' ] );
	}

	// ── Role ──────────────────────────────────────────────────────────────────

	/**
	 * Creates the csb_instructor role with minimal capabilities.
	 *
	 * Idempotent — get_role() returns non-null if already exists.
	 */
	public function register_role(): void {
		if ( get_role( 'csb_instructor' ) ) {
			return;
		}

		add_role(
			'csb_instructor',
			__( 'مدرس آموزشگاه', 'csb' ),
			[
				'read'              => true,
				'csb_view_sessions' => true, // custom cap checked throughout this class
			]
		);
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public function register_menu(): void {
		if ( ! $this->is_instructor() ) {
			return;
		}

		// Replace the whole WP admin with a single top-level menu.
		add_menu_page(
			esc_html__( 'پنل مدرس', 'csb' ),
			esc_html__( 'پنل مدرس', 'csb' ),
			'csb_view_sessions',
			'csb-instructor',
			[ $this, 'render_sessions' ],
			'dashicons-welcome-learn-more',
			2
		);

		add_submenu_page(
			'csb-instructor',
			esc_html__( 'جلسات من', 'csb' ),
			esc_html__( 'جلسات من', 'csb' ),
			'csb_view_sessions',
			'csb-instructor',
			[ $this, 'render_sessions' ]
		);

		add_submenu_page(
			'csb-instructor',
			esc_html__( 'حضورغیاب', 'csb' ),
			esc_html__( 'حضورغیاب', 'csb' ),
			'csb_view_sessions',
			'csb-instructor-attendance',
			[ $this, 'render_attendance' ]
		);
	}

	// ── CSV export ────────────────────────────────────────────────────────────

	public function maybe_export_csv(): void {
		if ( ! isset( $_GET['csb_export_csv'] ) || 'instructor' !== $_GET['csb_export_csv'] ) {
			return;
		}

		if ( ! $this->is_instructor() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$session_id = (int) ( $_GET['session_id'] ?? 0 );
		check_admin_referer( 'csb_export_instructor_' . $session_id );

		if ( ! $this->instructor_owns_session( $session_id ) ) {
			wp_die( esc_html__( 'این جلسه به شما تعلق ندارد.', 'csb' ) );
		}

		$roster = AttendanceService::get_roster( $session_id );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="roster-session-' . $session_id . '-' . gmdate( 'Ymd' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, [ 'نام هنرجو', 'ایمیل', 'وضعیت رزرو', 'حضور' ] );

		foreach ( $roster as $r ) {
			fputcsv( $out, [
				$r['student_name'] ?? '',
				$r['student_email'] ?? '',
				$r['booking_status'] ?? '',
				$r['status'] ?? 'ثبت نشده',
			] );
		}

		fclose( $out );
		exit;
	}

	// ── Render: sessions list ─────────────────────────────────────────────────

	public function render_sessions(): void {
		if ( ! $this->is_instructor() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$sessions = $this->get_own_sessions();
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'جلسات من', 'csb' ); ?></h1>

			<?php if ( empty( $sessions ) ) : ?>
				<p><?php esc_html_e( 'جلسه‌ای یافت نشد.', 'csb' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" dir="rtl">
					<thead>
						<tr>
							<th><?php esc_html_e( 'دوره', 'csb' ); ?></th>
							<th><?php esc_html_e( 'تاریخ جلسه', 'csb' ); ?></th>
							<th><?php esc_html_e( 'ظرفیت', 'csb' ); ?></th>
							<th><?php esc_html_e( 'ثبت‌نام', 'csb' ); ?></th>
							<th><?php esc_html_e( 'حضورغیاب', 'csb' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sessions as $s ) :
							$attendance_url = admin_url( 'admin.php?page=csb-instructor-attendance&session_id=' . (int) $s['id'] );
						?>
							<tr>
								<td><?php echo esc_html( $s['class_title'] ); ?></td>
								<td><?php echo esc_html( \BookaClass\Support\Jalali::to_jalali_string( $s['start_at'] ) ); ?></td>
								<td><?php echo esc_html( (string) $s['max_capacity'] ); ?></td>
								<td><?php echo esc_html( (string) $s['seats_taken'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( $attendance_url ); ?>" class="button button-small">
										<?php esc_html_e( 'مشاهده', 'csb' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Render: attendance for one session ───────────────────────────────────

	public function render_attendance(): void {
		if ( ! $this->is_instructor() ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$session_id = (int) ( $_GET['session_id'] ?? 0 );

		if ( $session_id > 0 && ! $this->instructor_owns_session( $session_id ) ) {
			wp_die( esc_html__( 'این جلسه به شما تعلق ندارد.', 'csb' ) );
		}

		$sessions = $this->get_own_sessions();
		$roster   = $session_id > 0 ? AttendanceService::get_roster( $session_id ) : [];

		$export_url = $session_id > 0
			? wp_nonce_url(
				add_query_arg( [ 'csb_export_csv' => 'instructor', 'session_id' => $session_id ], admin_url( 'admin.php' ) ),
				'csb_export_instructor_' . $session_id
			)
			: '';

		$msg = sanitize_key( $_GET['csb_msg'] ?? '' );
		if ( 'checkin_done' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'حضور ثبت شد.', 'csb' ) . '</p></div>';
		} elseif ( 'noshow_done' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'غیبت ثبت شد.', 'csb' ) . '</p></div>';
		}
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'حضورغیاب', 'csb' ); ?></h1>

			<form method="get" action="">
				<input type="hidden" name="page" value="csb-instructor-attendance">
				<select name="session_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( '— انتخاب جلسه —', 'csb' ); ?></option>
					<?php foreach ( $sessions as $s ) : ?>
						<option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( $session_id, (int) $s['id'] ); ?>>
							<?php echo esc_html( $s['class_title'] . ' — ' . \BookaClass\Support\Jalali::to_jalali_string( $s['start_at'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $export_url ) : ?>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'خروجی CSV', 'csb' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( $session_id > 0 && ! empty( $roster ) ) : ?>
				<table class="wp-list-table widefat fixed striped" style="margin-top:12px;" dir="rtl">
					<thead>
						<tr>
							<th><?php esc_html_e( 'هنرجو', 'csb' ); ?></th>
							<th><?php esc_html_e( 'ایمیل', 'csb' ); ?></th>
							<th><?php esc_html_e( 'وضعیت حضور', 'csb' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'csb' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $roster as $row ) :
							$att_status = $row['status'] ?? null;
							$booking_id = (int) $row['booking_id'];
						?>
							<tr>
								<td><?php echo esc_html( $row['student_name'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $row['student_email'] ?? '—' ); ?></td>
								<td>
									<?php if ( 'present' === $att_status ) : ?>
										<span style="color:green;">&#10003; <?php esc_html_e( 'حاضر', 'csb' ); ?></span>
									<?php elseif ( 'no_show' === $att_status ) : ?>
										<span style="color:red;"><?php esc_html_e( 'غایب', 'csb' ); ?></span>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td>
									<?php if ( 'confirmed' === $row['booking_status'] && ! $att_status ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'csb_checkin_' . $booking_id ); ?>
											<input type="hidden" name="action"     value="csb_checkin">
											<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $booking_id ); ?>">
											<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
											<button type="submit" class="button button-small"><?php esc_html_e( 'حضور', 'csb' ); ?></button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:4px;">
											<?php wp_nonce_field( 'csb_noshow_' . $booking_id ); ?>
											<input type="hidden" name="action"     value="csb_no_show">
											<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $booking_id ); ?>">
											<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
											<button type="submit" class="button button-small"
											        onclick="return confirm('<?php esc_attr_e( 'مطمئنید؟', 'csb' ); ?>')">
												<?php esc_html_e( 'غیبت', 'csb' ); ?>
											</button>
										</form>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php elseif ( $session_id > 0 ) : ?>
				<p><?php esc_html_e( 'رزروی برای این جلسه وجود ندارد.', 'csb' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * True if the current user has the instructor role or manage_woocommerce.
	 */
	private function is_instructor(): bool {
		$user = wp_get_current_user();
		if ( ! $user->ID ) {
			return false;
		}
		return in_array( 'csb_instructor', (array) $user->roles, true )
			|| current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Checks whether the current user is the assigned instructor for a session.
	 *
	 * Ownership is determined by `_csb_instructor_id` postmeta on the parent
	 * class product (not post_author).  Admins with manage_woocommerce bypass
	 * the check and are treated as owning any valid session.
	 */
	private function instructor_owns_session( int $session_id ): bool {
		global $wpdb;

		// Admins see everything — confirm the session exists then allow.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}csb_sessions WHERE id = %d",
				$session_id
			) );
			return $exists > 0;
		}

		// For instructors: join to _csb_instructor_id postmeta on the class product.
		$match = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$wpdb->prefix}csb_sessions s
			   JOIN {$wpdb->postmeta} pm
			          ON pm.post_id   = s.class_id
			         AND pm.meta_key  = '_csb_instructor_id'
			         AND CAST( pm.meta_value AS UNSIGNED ) = %d
			  WHERE s.id = %d",
			get_current_user_id(),
			$session_id
		) );

		return $match > 0;
	}

	/**
	 * Returns sessions for the current user.
	 *
	 * Admins (manage_woocommerce) see all sessions.  Instructors see only
	 * sessions whose class product has `_csb_instructor_id` = their user ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_own_sessions(): array {
		global $wpdb;

		// Admins see all sessions.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return $wpdb->get_results(
				"SELECT s.id, s.start_at, s.capacity AS max_capacity, s.seats_taken, p.post_title AS class_title
				   FROM {$wpdb->prefix}csb_sessions s
				   JOIN {$wpdb->posts} p ON p.ID = s.class_id
				  WHERE p.post_status = 'publish'
				  ORDER BY s.start_at DESC LIMIT 100",
				ARRAY_A
			) ?: [];
		}

		// Instructors: filter by _csb_instructor_id postmeta on the class product.
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.start_at, s.capacity AS max_capacity, s.seats_taken, p.post_title AS class_title
			   FROM {$wpdb->prefix}csb_sessions s
			   JOIN {$wpdb->posts}    p  ON p.ID         = s.class_id
			   JOIN {$wpdb->postmeta} pm ON pm.post_id   = s.class_id
			                            AND pm.meta_key  = '_csb_instructor_id'
			                            AND CAST( pm.meta_value AS UNSIGNED ) = %d
			  WHERE p.post_status = 'publish'
			  ORDER BY s.start_at DESC LIMIT 100",
			get_current_user_id()
		), ARRAY_A ) ?: [];
	}
}
