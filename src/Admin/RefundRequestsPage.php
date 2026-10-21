<?php
/**
 * Admin page: pending refund requests review screen.
 *
 * آموزشگاه → درخواست‌های استرداد
 *
 * Lists rows from wp_csb_refund_requests (status = 'pending').
 * Admin can approve (cash or wallet) or reject.
 * Calls RefundService::approve_refund() / reject_refund().
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

use CookingSchoolBooking\Services\RefundService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RefundRequestsPage
 */
class RefundRequestsPage {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_post_csb_approve_refund', [ $this, 'handle_approve' ] );
		add_action( 'admin_post_csb_reject_refund',  [ $this, 'handle_reject' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'درخواست‌های استرداد', 'csb' ),
			esc_html__( 'استرداد', 'csb' ),
			'manage_woocommerce',
			'csb-refund-requests',
			[ $this, 'render' ]
		);
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public function handle_approve(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$request_id = (int) ( $_POST['request_id'] ?? 0 );
		check_admin_referer( 'csb_approve_refund_' . $request_id );

		if ( $request_id > 0 ) {
			$method = in_array( $_POST['method'] ?? 'wallet', [ 'cash', 'wallet' ], true )
				? sanitize_key( $_POST['method'] )
				: 'wallet';

			// Override method on request row before approving.
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'csb_refund_requests',
				[ 'method' => $method ],
				[ 'id'     => $request_id, 'status' => 'pending' ],
				[ '%s' ],
				[ '%d', '%s' ]
			);

			RefundService::approve_refund( $request_id, get_current_user_id() );
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'csb-refund-requests', 'csb_msg' => 'approved' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function handle_reject(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$request_id = (int) ( $_POST['request_id'] ?? 0 );
		check_admin_referer( 'csb_reject_refund_' . $request_id );

		if ( $request_id > 0 ) {
			RefundService::reject_refund( $request_id, get_current_user_id() );
		}

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'csb-refund-requests', 'csb_msg' => 'rejected' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		global $wpdb;

		$filter = sanitize_key( $_GET['csb_filter'] ?? 'pending' );
		$allowed_filters = [ 'pending', 'approved', 'rejected', 'all' ];
		if ( ! in_array( $filter, $allowed_filters, true ) ) {
			$filter = 'pending';
		}

		$where = 'all' === $filter ? '' : $wpdb->prepare( 'AND rr.status = %s', $filter );

		$requests = $wpdb->get_results(
			"SELECT rr.id, rr.booking_id, rr.refund_amount, rr.policy_tier,
			        rr.method, rr.status, rr.reason, rr.created_at,
			        u.display_name AS student_name,
			        p.post_title AS class_title,
			        s.start_at
			   FROM {$wpdb->prefix}csb_refund_requests rr
			   JOIN {$wpdb->prefix}csb_bookings b ON b.id = rr.booking_id
			   JOIN {$wpdb->prefix}csb_sessions s ON s.id = b.session_id
			   JOIN {$wpdb->posts} p ON p.ID = s.class_id
			   LEFT JOIN {$wpdb->users} u ON u.ID = b.student_id
			  WHERE 1=1 {$where}
			  ORDER BY rr.created_at DESC LIMIT 200",
			ARRAY_A
		) ?: [];

		$msg = sanitize_key( $_GET['csb_msg'] ?? '' );
		if ( 'approved' === $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'استرداد تأیید شد.', 'csb' ) . '</p></div>';
		} elseif ( 'rejected' === $msg ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'درخواست رد شد.', 'csb' ) . '</p></div>';
		}

		$tier_labels = [
			'full_refund'         => __( 'استرداد کامل', 'csb' ),
			'partial_refund'      => __( 'استرداد جزئی', 'csb' ),
			'no_refund'           => __( 'بدون استرداد', 'csb' ),
			'school_cancellation' => __( 'لغو آموزشگاه', 'csb' ),
		];
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'درخواست‌های استرداد', 'csb' ); ?></h1>

			<ul class="subsubsub" style="margin-bottom:12px;">
				<?php foreach ( [ 'pending' => __( 'در انتظار', 'csb' ), 'approved' => __( 'تأیید شده', 'csb' ), 'rejected' => __( 'رد شده', 'csb' ), 'all' => __( 'همه', 'csb' ) ] as $f => $lbl ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( [ 'page' => 'csb-refund-requests', 'csb_filter' => $f ], admin_url( 'admin.php' ) ) ); ?>"
							<?php if ( $f === $filter ) echo 'style="font-weight:bold;"'; ?>>
							<?php echo esc_html( $lbl ); ?>
						</a> |
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( empty( $requests ) ) : ?>
				<p><?php esc_html_e( 'درخواستی یافت نشد.', 'csb' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped" dir="rtl">
					<thead>
						<tr>
							<th><?php esc_html_e( '#', 'csb' ); ?></th>
							<th><?php esc_html_e( 'رزرو', 'csb' ); ?></th>
							<th><?php esc_html_e( 'هنرجو', 'csb' ); ?></th>
							<th><?php esc_html_e( 'دوره', 'csb' ); ?></th>
							<th><?php esc_html_e( 'سیاست', 'csb' ); ?></th>
							<th><?php esc_html_e( 'مبلغ', 'csb' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'csb' ); ?></th>
							<th><?php esc_html_e( 'تاریخ', 'csb' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'csb' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $requests as $req ) :
							$rid  = (int) $req['id'];
							$ap   = admin_url( 'admin-post.php' );
						?>
							<tr>
								<td><?php echo esc_html( (string) $rid ); ?></td>
								<td><?php echo esc_html( (string) $req['booking_id'] ); ?></td>
								<td><?php echo esc_html( $req['student_name'] ?? '—' ); ?></td>
								<td>
									<?php echo esc_html( $req['class_title'] ); ?><br>
									<small><?php echo esc_html( \CookingSchoolBooking\Support\Jalali::to_jalali_string( $req['start_at'] ) ); ?></small>
								</td>
								<td><?php echo esc_html( $tier_labels[ $req['policy_tier'] ] ?? $req['policy_tier'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (float) $req['refund_amount'] ) ); ?></td>
								<td><?php echo esc_html( $req['status'] ); ?></td>
								<td><?php echo esc_html( \CookingSchoolBooking\Support\Jalali::to_jalali_string( $req['created_at'] ) ); ?></td>
								<td>
									<?php if ( 'pending' === $req['status'] ) : ?>
										<!-- Approve form with method choice -->
										<form method="post" action="<?php echo esc_url( $ap ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'csb_approve_refund_' . $rid ); ?>
											<input type="hidden" name="action"     value="csb_approve_refund">
											<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $rid ); ?>">
											<select name="method">
												<option value="wallet"><?php esc_html_e( 'کیف‌پول', 'csb' ); ?></option>
												<option value="cash"><?php esc_html_e( 'بانکی', 'csb' ); ?></option>
											</select>
											<button type="submit" class="button button-primary button-small">
												<?php esc_html_e( 'تأیید', 'csb' ); ?>
											</button>
										</form>
										<!-- Reject form -->
										<form method="post" action="<?php echo esc_url( $ap ); ?>" style="display:inline;margin-right:4px;">
											<?php wp_nonce_field( 'csb_reject_refund_' . $rid ); ?>
											<input type="hidden" name="action"     value="csb_reject_refund">
											<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $rid ); ?>">
											<button type="submit" class="button button-small"
												onclick="return confirm('<?php esc_attr_e( 'رد درخواست؟', 'csb' ); ?>')">
												<?php esc_html_e( 'رد', 'csb' ); ?>
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
		</div>
		<?php
	}
}
