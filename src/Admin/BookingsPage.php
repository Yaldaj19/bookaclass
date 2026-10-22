<?php
/**
 * Admin page: Bookings list.
 *
 * آموزشگاه → رزروها
 *
 * Features:
 *  — Filterable table: session, status, date range, free-text search.
 *  — Inline per-row status change (confirmed → no_show; active → cancelled).
 *  — Link to pending refund requests for each booking.
 *  — CSV export (all filtered rows, UTF-8 BOM for Excel).
 *  — Group SMS stub via do_action('csb_bulk_sms', $booking_ids, $message).
 *
 * All form actions guarded with nonces + manage_woocommerce capability.
 * All SQL prepared via $wpdb->prepare(). All output escaped.
 *
 * @package BookaClass\Admin
 */

namespace BookaClass\Admin;

use BookaClass\Services\AttendanceService;
use BookaClass\Services\BookingService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BookingsPage
 */
class BookingsPage {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_init', [ $this, 'maybe_export_csv' ] );
		add_action( 'admin_init', [ $this, 'maybe_send_bulk_sms' ] );
		add_action( 'admin_post_csb_admin_status_change', [ $this, 'handle_status_change' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'رزروها', 'csb' ),
			esc_html__( 'رزروها', 'csb' ),
			'manage_woocommerce',
			'csb-bookings',
			[ $this, 'render' ]
		);
	}

	// ── Status change handler ─────────────────────────────────────────────────

	public function handle_status_change(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$booking_id = (int) ( $_POST['booking_id'] ?? 0 );
		check_admin_referer( 'csb_status_change_' . $booking_id );

		$new_status = sanitize_key( $_POST['new_status'] ?? '' );
		$allowed    = [ 'no_show', 'cancelled', 'confirmed', 'completed' ];

		if ( $booking_id > 0 && in_array( $new_status, $allowed, true ) ) {
			try {
				if ( 'no_show' === $new_status ) {
					AttendanceService::mark_no_show( $booking_id, get_current_user_id() );
				} elseif ( 'cancelled' === $new_status ) {
					BookingService::cancel( $booking_id, 'admin_manual', '', 0 );
				} else {
					BookingService::transition( $booking_id, $new_status, [
						'actor' => get_current_user_id(),
					] );
				}
			} catch ( \InvalidArgumentException | \RuntimeException $e ) {
				wp_safe_redirect( add_query_arg(
					[ 'page' => 'csb-bookings', 'csb_err' => 'transition' ],
					admin_url( 'admin.php' )
				) );
				exit;
			}
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'csb-bookings', 'csb_msg' => 'status_changed' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// ── CSV export ────────────────────────────────────────────────────────────

	public function maybe_export_csv(): void {
		if ( ! isset( $_GET['csb_export_csv'] ) || 'bookings' !== $_GET['csb_export_csv'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( 'csb_export_bookings' );

		$rows = $this->get_filtered_bookings( $this->get_filter_args() );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="csb-bookings-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, [
			'شناسه رزرو', 'هنرجو', 'ایمیل', 'دوره', 'تاریخ جلسه',
			'وضعیت', 'بیعانه', 'مانده', 'مجموع پرداخت', 'تاریخ ثبت',
		] );

		foreach ( $rows as $r ) {
			fputcsv( $out, [
				$r['id'],
				$r['student_name'],
				$r['student_email'],
				$r['class_title'],
				\BookaClass\Support\Jalali::to_jalali_string( $r['start_at'] ),
				$r['status'],
				$r['deposit_amount'],
				$r['balance_due'],
				$r['paid_amount'],
				\BookaClass\Support\Jalali::to_jalali_string( $r['created_at'] ),
			] );
		}

		fclose( $out );
		exit;
	}

	// ── Bulk SMS ──────────────────────────────────────────────────────────────

	public function maybe_send_bulk_sms(): void {
		if ( empty( $_POST['csb_bulk_sms_send'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'csb_bulk_sms' );

		$booking_ids = array_map( 'absint', (array) ( $_POST['csb_selected_bookings'] ?? [] ) );
		$message     = sanitize_textarea_field( $_POST['csb_sms_message'] ?? '' );

		if ( empty( $booking_ids ) || '' === $message ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'رزروها یا متن پیامک خالی است.', 'csb' ) . '</p></div>';
			} );
			return;
		}

		/**
		 * Phase 7 SMS adapter hooks here.
		 *
		 * @param int[]  $booking_ids
		 * @param string $message
		 */
		do_action( 'csb_bulk_sms', $booking_ids, $message );

		add_action( 'admin_notices', function () use ( $booking_ids ) {
			echo '<div class="notice notice-success"><p>' . sprintf(
				/* translators: %d count */
				esc_html__( 'پیامک برای %d رزرو ارسال شد.', 'csb' ),
				count( $booking_ids )
			) . '</p></div>';
		} );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$filters    = $this->get_filter_args();
		$bookings   = $this->get_filtered_bookings( $filters );
		$sessions   = $this->get_sessions_list();
		$export_url = wp_nonce_url(
			add_query_arg( 'csb_export_csv', 'bookings', admin_url( 'admin.php?page=csb-bookings' ) ),
			'csb_export_bookings'
		);

		$msg = sanitize_key( $_GET['csb_msg'] ?? '' );
		$err = sanitize_key( $_GET['csb_err'] ?? '' );
		if ( 'status_changed' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'وضعیت تغییر کرد.', 'csb' ) . '</p></div>';
		}
		if ( 'transition' === $err ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'تغییر وضعیت مجاز نیست.', 'csb' ) . '</p></div>';
		}

		$status_labels = [
			''             => __( 'همه', 'csb' ),
			'pending'      => __( 'در انتظار', 'csb' ),
			'deposit_paid' => __( 'بیعانه', 'csb' ),
			'fully_paid'   => __( 'پرداخت کامل', 'csb' ),
			'confirmed'    => __( 'تأیید شده', 'csb' ),
			'completed'    => __( 'برگزار شده', 'csb' ),
			'cancelled'    => __( 'لغو شده', 'csb' ),
			'expired'      => __( 'منقضی', 'csb' ),
			'no_show'      => __( 'غایب', 'csb' ),
		];
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'رزروها', 'csb' ); ?></h1>

			<form method="get" action="">
				<input type="hidden" name="page" value="csb-bookings">
				<select name="csb_status">
					<?php foreach ( $status_labels as $val => $lbl ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filters['status'], $val ); ?>>
							<?php echo esc_html( $lbl ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<select name="csb_session">
					<option value="0"><?php esc_html_e( '— همه جلسات —', 'csb' ); ?></option>
					<?php foreach ( $sessions as $s ) : ?>
						<option value="<?php echo esc_attr( (string) $s['id'] ); ?>"
							<?php selected( $filters['session_id'], (int) $s['id'] ); ?>>
							<?php echo esc_html( $s['class_title'] . ' — ' . \BookaClass\Support\Jalali::to_jalali_string( $s['start_at'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<input type="date" name="csb_date_from" value="<?php echo esc_attr( (string) $filters['date_from'] ); ?>">
				<input type="date" name="csb_date_to"   value="<?php echo esc_attr( (string) $filters['date_to'] ); ?>">
				<input type="text" name="csb_search"    value="<?php echo esc_attr( (string) $filters['search'] ); ?>"
					placeholder="<?php esc_attr_e( 'جستجو', 'csb' ); ?>">
				<?php submit_button( __( 'فیلتر', 'csb' ), 'secondary', '', false ); ?>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'خروجی CSV', 'csb' ); ?></a>
			</form>

			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'csb_bulk_sms' ); ?>
				<table class="wp-list-table widefat fixed striped" dir="rtl">
					<thead>
						<tr>
							<th style="width:28px;"><input type="checkbox" id="csb-select-all"></th>
							<th><?php esc_html_e( '#', 'csb' ); ?></th>
							<th><?php esc_html_e( 'هنرجو', 'csb' ); ?></th>
							<th><?php esc_html_e( 'دوره / تاریخ', 'csb' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'csb' ); ?></th>
							<th><?php esc_html_e( 'پرداخت', 'csb' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'csb' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( empty( $bookings ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'رزروی یافت نشد.', 'csb' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $bookings as $b ) :
							$bid        = (int) $b['id'];
							$has_refund = ! empty( $b['pending_refund_id'] );
						?>
							<tr>
								<td><input type="checkbox" name="csb_selected_bookings[]" value="<?php echo esc_attr( (string) $bid ); ?>"></td>
								<td><?php echo esc_html( (string) $bid ); ?></td>
								<td>
									<?php echo esc_html( $b['student_name'] ); ?><br>
									<small><?php echo esc_html( $b['student_email'] ); ?></small>
								</td>
								<td>
									<?php echo esc_html( $b['class_title'] ); ?><br>
									<small><?php echo esc_html( \BookaClass\Support\Jalali::to_jalali_string( $b['start_at'] ) ); ?></small>
								</td>
								<td>
									<?php echo esc_html( $status_labels[ $b['status'] ] ?? $b['status'] ); ?>
									<?php if ( $has_refund ) : ?>
										<br><a href="<?php echo esc_url( admin_url( 'admin.php?page=csb-refund-requests' ) ); ?>"
											style="color:orange;font-size:11px;">
											<?php esc_html_e( 'درخواست استرداد', 'csb' ); ?>
										</a>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( number_format_i18n( (float) $b['paid_amount'] ) ); ?></td>
								<td><?php $this->render_status_actions( $bid, $b['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>

				<div style="margin-top:12px;padding:12px;background:#fff;border:1px solid #ccd0d4;">
					<strong><?php esc_html_e( 'پیامک گروهی:', 'csb' ); ?></strong><br>
					<textarea name="csb_sms_message" rows="3" style="width:100%;margin:8px 0;"
						placeholder="<?php esc_attr_e( 'متن پیامک…', 'csb' ); ?>"></textarea>
					<?php submit_button( __( 'ارسال پیامک', 'csb' ), 'primary', 'csb_bulk_sms_send', false ); ?>
					<p class="description"><?php esc_html_e( 'به اکشن csb_bulk_sms یک افزونه SMS متصل کنید.', 'csb' ); ?></p>
				</div>
			</form>
		</div>

		<script>
		document.getElementById('csb-select-all')?.addEventListener('change', function(){
			document.querySelectorAll('[name="csb_selected_bookings[]"]').forEach(cb => cb.checked = this.checked);
		});
		</script>
		<?php
	}

	// ── Per-row status action forms ───────────────────────────────────────────

	private function render_status_actions( int $booking_id, string $status ): void {
		$ap = admin_url( 'admin-post.php' );

		if ( 'confirmed' === $status ) {
			echo '<form method="post" action="' . esc_url( $ap ) . '" style="display:inline;">';
			wp_nonce_field( 'csb_status_change_' . $booking_id );
			echo '<input type="hidden" name="action"     value="csb_admin_status_change">';
			echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $booking_id ) . '">';
			echo '<input type="hidden" name="new_status" value="no_show">';
			echo '<button type="submit" class="button button-small"
				onclick="return confirm(\'' . esc_js( __( 'ثبت غیبت؟ صندلی آزاد می‌شود.', 'csb' ) ) . '\')">'
				. esc_html__( 'غیبت', 'csb' ) . '</button> ';
			echo '</form>';
		}

		$cancellable = [ 'pending', 'deposit_paid', 'fully_paid', 'confirmed' ];
		if ( in_array( $status, $cancellable, true ) ) {
			echo '<form method="post" action="' . esc_url( $ap ) . '" style="display:inline;">';
			wp_nonce_field( 'csb_status_change_' . $booking_id );
			echo '<input type="hidden" name="action"     value="csb_admin_status_change">';
			echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $booking_id ) . '">';
			echo '<input type="hidden" name="new_status" value="cancelled">';
			echo '<button type="submit" class="button button-small"
				onclick="return confirm(\'' . esc_js( __( 'لغو رزرو؟', 'csb' ) ) . '\')">'
				. esc_html__( 'لغو', 'csb' ) . '</button>';
			echo '</form>';
		}
	}

	// ── Data helpers ──────────────────────────────────────────────────────────

	/**
	 * @return array<string,mixed>
	 */
	private function get_filter_args(): array {
		return [
			'status'     => sanitize_key( $_GET['csb_status']    ?? '' ),
			'session_id' => (int) ( $_GET['csb_session']         ?? 0 ),
			'date_from'  => sanitize_text_field( $_GET['csb_date_from'] ?? '' ),
			'date_to'    => sanitize_text_field( $_GET['csb_date_to']   ?? '' ),
			'search'     => sanitize_text_field( $_GET['csb_search']    ?? '' ),
		];
	}

	/**
	 * @param  array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	private function get_filtered_bookings( array $filters ): array {
		global $wpdb;

		$where   = [ '1=1' ];
		$prepare = [];

		if ( '' !== (string) $filters['status'] ) {
			$where[]   = 'b.status = %s';
			$prepare[] = $filters['status'];
		}

		if ( (int) $filters['session_id'] > 0 ) {
			$where[]   = 'b.session_id = %d';
			$prepare[] = (int) $filters['session_id'];
		}

		if ( '' !== (string) $filters['date_from'] ) {
			$where[]   = 'b.created_at >= %s';
			$prepare[] = $filters['date_from'] . ' 00:00:00';
		}

		if ( '' !== (string) $filters['date_to'] ) {
			$where[]   = 'b.created_at <= %s';
			$prepare[] = $filters['date_to'] . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT b.id, b.status, b.paid_amount, b.deposit_amount, b.balance_due,
				        b.created_at, s.start_at, p.post_title AS class_title,
				        u.display_name AS student_name, u.user_email AS student_email,
				        ( SELECT rr.id FROM {$wpdb->prefix}csb_refund_requests rr
				            WHERE rr.booking_id = b.id AND rr.status = 'pending'
				            LIMIT 1 ) AS pending_refund_id
				   FROM {$wpdb->prefix}csb_bookings b
				   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
				   JOIN {$wpdb->posts} p ON p.ID = s.class_id
				   LEFT JOIN {$wpdb->users} u ON u.ID = b.student_id
				  WHERE {$where_sql}
				  ORDER BY b.created_at DESC LIMIT 500";

		$rows = $prepare
			? $wpdb->get_results( $wpdb->prepare( $sql, ...$prepare ), ARRAY_A )
			: $wpdb->get_results( $sql, ARRAY_A );

		$rows = $rows ?? [];

		if ( '' !== (string) $filters['search'] ) {
			$q    = mb_strtolower( (string) $filters['search'] );
			$rows = array_filter( $rows, function ( $r ) use ( $q ) {
				return false !== mb_strpos( mb_strtolower( $r['student_name'] ?? '' ), $q )
					|| false !== mb_strpos( mb_strtolower( $r['student_email'] ?? '' ), $q );
			} );
		}

		return array_values( $rows );
	}

	/**
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
}
