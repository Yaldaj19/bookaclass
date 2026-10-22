<?php
/**
 * Admin page: Session roster + check-in / no-show controls.
 *
 * Accessible via آموزشگاه → حضورغیاب (manage_woocommerce).
 *
 * URL pattern: ?page=csb-attendance&session_id=123
 *
 * @package BookaClass\Admin
 */

namespace BookaClass\Admin;

use BookaClass\Services\AttendanceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttendancePage
 */
class AttendancePage {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_post_csb_checkin',  [ $this, 'handle_checkin' ] );
		add_action( 'admin_post_csb_no_show',  [ $this, 'handle_no_show' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'حضورغیاب', 'csb' ),
			esc_html__( 'حضورغیاب', 'csb' ),
			'manage_woocommerce',
			'csb-attendance',
			[ $this, 'render' ]
		);
	}

	// ── Form handlers ─────────────────────────────────────────────────────────

	public function handle_checkin(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( 'csb_checkin_' . (int) ( $_POST['booking_id'] ?? 0 ) );

		$booking_id = (int) ( $_POST['booking_id'] ?? 0 );
		$session_id = (int) ( $_POST['session_id'] ?? 0 );

		if ( $booking_id > 0 ) {
			AttendanceService::check_in( $booking_id, get_current_user_id() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=csb-attendance&session_id=' . $session_id . '&csb_msg=checkin_done' ) );
		exit;
	}

	public function handle_no_show(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( 'csb_noshow_' . (int) ( $_POST['booking_id'] ?? 0 ) );

		$booking_id = (int) ( $_POST['booking_id'] ?? 0 );
		$session_id = (int) ( $_POST['session_id'] ?? 0 );

		if ( $booking_id > 0 ) {
			AttendanceService::mark_no_show( $booking_id, get_current_user_id() );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=csb-attendance&session_id=' . $session_id . '&csb_msg=noshow_done' ) );
		exit;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$session_id = (int) ( $_GET['session_id'] ?? 0 );
		$sessions   = $this->get_sessions_list();
		$roster     = $session_id > 0 ? AttendanceService::get_roster( $session_id ) : [];

		// Inline message feedback.
		$msg = sanitize_key( $_GET['csb_msg'] ?? '' );
		if ( 'checkin_done' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'حضور ثبت شد.', 'csb' ) . '</p></div>';
		} elseif ( 'noshow_done' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'غیبت ثبت شد و صندلی آزاد شد.', 'csb' ) . '</p></div>';
		}
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'حضورغیاب', 'csb' ); ?></h1>

			<!-- Session selector -->
			<form method="get" action="">
				<input type="hidden" name="page" value="csb-attendance">
				<select name="session_id" onchange="this.form.submit()">
					<option value="0"><?php esc_html_e( '— انتخاب جلسه —', 'csb' ); ?></option>
					<?php foreach ( $sessions as $s ) : ?>
						<option value="<?php echo esc_attr( (string) $s['id'] ); ?>" <?php selected( $session_id, (int) $s['id'] ); ?>>
							<?php echo esc_html(
								$s['class_title'] . ' — ' .
								\BookaClass\Support\Jalali::to_jalali_string( $s['start_at'] )
							); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</form>

			<?php if ( $session_id > 0 ) : ?>

				<h2 style="margin-top:16px;">
					<?php esc_html_e( 'لیست حضورغیاب', 'csb' ); ?>
					(<?php echo esc_html( (string) count( $roster ) ); ?> <?php esc_html_e( 'نفر', 'csb' ); ?>)
				</h2>

				<?php if ( empty( $roster ) ) : ?>
					<p><?php esc_html_e( 'هیچ رزروی برای این جلسه وجود ندارد.', 'csb' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped" dir="rtl">
						<thead>
							<tr>
								<th><?php esc_html_e( 'هنرجو', 'csb' ); ?></th>
								<th><?php esc_html_e( 'ایمیل', 'csb' ); ?></th>
								<th><?php esc_html_e( 'وضعیت رزرو', 'csb' ); ?></th>
								<th><?php esc_html_e( 'وضعیت حضور', 'csb' ); ?></th>
								<th><?php esc_html_e( 'عملیات', 'csb' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $roster as $row ) :
								$att_status = $row['status'] ?? null; // null = not yet recorded
								$booking_id = (int) $row['booking_id'];
							?>
								<tr>
									<td><?php echo esc_html( $row['student_name'] ?? '—' ); ?></td>
									<td><?php echo esc_html( $row['student_email'] ?? '—' ); ?></td>
									<td><?php echo esc_html( $this->translate_booking_status( $row['booking_status'] ?? '' ) ); ?></td>
									<td>
										<?php if ( 'present' === $att_status ) : ?>
											<span style="color:green;">&#10003; <?php esc_html_e( 'حاضر', 'csb' ); ?></span>
										<?php elseif ( 'no_show' === $att_status ) : ?>
											<span style="color:red;"><?php esc_html_e( 'غایب', 'csb' ); ?></span>
										<?php else : ?>
											<span style="color:#888;"><?php esc_html_e( 'ثبت نشده', 'csb' ); ?></span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( 'confirmed' === $row['booking_status'] && ! $att_status ) : ?>
											<!-- Check-in form -->
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'csb_checkin_' . $booking_id ); ?>
												<input type="hidden" name="action"     value="csb_checkin">
												<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $booking_id ); ?>">
												<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
												<button type="submit" class="button button-small"><?php esc_html_e( 'حضور', 'csb' ); ?></button>
											</form>
											<!-- No-show form -->
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-right:4px;">
												<?php wp_nonce_field( 'csb_noshow_' . $booking_id ); ?>
												<input type="hidden" name="action"     value="csb_no_show">
												<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $booking_id ); ?>">
												<input type="hidden" name="session_id" value="<?php echo esc_attr( (string) $session_id ); ?>">
												<button type="submit" class="button button-small"
												        onclick="return confirm('<?php esc_attr_e( 'غیبت ثبت شده و صندلی آزاد می‌شود. مطمئنید؟', 'csb' ); ?>')">
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
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	// ── Data helpers ──────────────────────────────────────────────────────────

	/**
	 * Returns a list of upcoming + recent sessions for the selector dropdown.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function get_sessions_list(): array {
		global $wpdb;

		return $wpdb->get_results(
			"SELECT s.id, s.start_at, p.post_title AS class_title
			   FROM {$wpdb->prefix}csb_sessions s
			   JOIN {$wpdb->posts} p ON p.ID = s.class_id
			  WHERE p.post_status = 'publish'
			  ORDER BY s.start_at DESC LIMIT 100",
			ARRAY_A
		) ?: [];
	}

	private function translate_booking_status( string $status ): string {
		$map = [
			'confirmed'    => 'تأیید شده',
			'fully_paid'   => 'پرداخت کامل',
			'deposit_paid' => 'بیعانه پرداخت',
			'completed'    => 'برگزار شده',
			'no_show'      => 'غایب',
		];
		return $map[ $status ] ?? $status;
	}
}
