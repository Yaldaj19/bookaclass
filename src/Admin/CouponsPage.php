<?php
/**
 * Admin coupons management page.
 *
 * Provides a simple CRUD interface for CSB-scoped discount codes.
 * Listed under the «آموزشگاه» top-level menu.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

use CookingSchoolBooking\Services\AuditLogger;
use CookingSchoolBooking\Services\CouponService;
use CookingSchoolBooking\Support\Jalali;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CouponsPage
 */
class CouponsPage {

	public function init(): void {
		add_action( 'admin_menu',              [ $this, 'register_menu' ] );
		add_action( 'admin_post_csb_coupon_save',       [ $this, 'handle_save' ] );
		add_action( 'admin_post_csb_coupon_deactivate', [ $this, 'handle_deactivate' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'کدهای تخفیف', 'csb' ),
			esc_html__( 'کدهای تخفیف', 'csb' ),
			'manage_woocommerce',
			'csb-coupons',
			[ $this, 'render' ]
		);
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( 'csb_coupon_save' );

		$code          = sanitize_text_field( strtolower( wp_unslash( $_POST['code'] ?? '' ) ) );
		$discount_type = in_array( $_POST['discount_type'] ?? '', [ 'percent', 'fixed' ], true )
			? sanitize_key( $_POST['discount_type'] )
			: 'percent';
		$amount        = max( 0, (float) ( $_POST['amount'] ?? 0 ) );
		$class_id      = ! empty( $_POST['class_id'] ) ? (int) $_POST['class_id'] : null;
		$usage_limit   = ! empty( $_POST['usage_limit'] ) ? max( 1, (int) $_POST['usage_limit'] ) : null;
		$expires_at    = ! empty( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : null;

		if ( '' === $code ) {
			wp_redirect( add_query_arg( 'csb_msg', 'empty_code', wp_get_referer() ?: admin_url( 'admin.php?page=csb-coupons' ) ) );
			exit;
		}

		$result = CouponService::create( compact( 'code', 'discount_type', 'amount', 'class_id', 'usage_limit', 'expires_at' ) );

		AuditLogger::log( 'coupon_created', 'coupon', (int) $result, [
			'code'           => $code,
			'discount_type'  => $discount_type,
			'amount'         => $amount,
			'created_by'     => get_current_user_id(),
		] );

		wp_redirect( add_query_arg( 'csb_msg', $result ? 'saved' : 'duplicate', admin_url( 'admin.php?page=csb-coupons' ) ) );
		exit;
	}

	public function handle_deactivate(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$coupon_id = (int) ( $_GET['coupon_id'] ?? 0 );
		check_admin_referer( 'csb_coupon_deactivate_' . $coupon_id );

		CouponService::deactivate( $coupon_id );

		AuditLogger::log( 'coupon_deactivated', 'coupon', $coupon_id, [
			'deactivated_by' => get_current_user_id(),
		] );

		wp_redirect( add_query_arg( 'csb_msg', 'deactivated', admin_url( 'admin.php?page=csb-coupons' ) ) );
		exit;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$msg     = sanitize_key( $_GET['csb_msg'] ?? '' );
		$coupons = CouponService::get_all();

		// Fetch class products for the «محدود به کلاس» dropdown.
		$classes = get_posts( [
			'post_type'      => 'product',
			'posts_per_page' => 200,
			'tax_query'      => [ [ 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'class' ] ],
			'fields'         => 'ids',
		] );
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'کدهای تخفیف', 'csb' ); ?></h1>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'کوپن ذخیره شد.', 'csb' ); ?></p></div>
			<?php elseif ( 'duplicate' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'این کد تخفیف قبلاً ثبت شده است.', 'csb' ); ?></p></div>
			<?php elseif ( 'deactivated' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'کوپن غیرفعال شد.', 'csb' ); ?></p></div>
			<?php elseif ( 'empty_code' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'کد تخفیف نمی‌تواند خالی باشد.', 'csb' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'کوپن جدید', 'csb' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="csb_coupon_save">
				<?php wp_nonce_field( 'csb_coupon_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="csb_cp_code"><?php esc_html_e( 'کد تخفیف', 'csb' ); ?></label></th>
						<td><input type="text" id="csb_cp_code" name="code" class="regular-text" required /></td>
					</tr>
					<tr>
						<th><label for="csb_cp_type"><?php esc_html_e( 'نوع تخفیف', 'csb' ); ?></label></th>
						<td>
							<select id="csb_cp_type" name="discount_type">
								<option value="percent"><?php esc_html_e( 'درصدی', 'csb' ); ?></option>
								<option value="fixed"><?php esc_html_e( 'مبلغ ثابت (تومان)', 'csb' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="csb_cp_amount"><?php esc_html_e( 'مقدار', 'csb' ); ?></label></th>
						<td><input type="number" id="csb_cp_amount" name="amount" class="small-text" min="0" step="0.01" required /></td>
					</tr>
					<tr>
						<th><label for="csb_cp_class"><?php esc_html_e( 'محدود به کلاس', 'csb' ); ?></label></th>
						<td>
							<select id="csb_cp_class" name="class_id">
								<option value=""><?php esc_html_e( '— همه کلاس‌ها —', 'csb' ); ?></option>
								<?php foreach ( $classes as $cid ) : ?>
									<option value="<?php echo esc_attr( (string) $cid ); ?>">
										<?php echo esc_html( get_the_title( $cid ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="csb_cp_limit"><?php esc_html_e( 'حداکثر استفاده', 'csb' ); ?></label></th>
						<td>
							<input type="number" id="csb_cp_limit" name="usage_limit" class="small-text" min="1" placeholder="نامحدود" />
						</td>
					</tr>
					<tr>
						<th><label for="csb_cp_expires"><?php esc_html_e( 'تاریخ انقضا', 'csb' ); ?></label></th>
						<td>
							<input type="date" id="csb_cp_expires" name="expires_at" />
							<p class="description"><?php esc_html_e( 'خالی = بدون انقضا', 'csb' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( esc_html__( 'ذخیره کوپن', 'csb' ) ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'کوپن‌های موجود', 'csb' ); ?></h2>
			<?php if ( empty( $coupons ) ) : ?>
				<p><?php esc_html_e( 'هیچ کوپنی ثبت نشده است.', 'csb' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'کد', 'csb' ); ?></th>
							<th><?php esc_html_e( 'نوع', 'csb' ); ?></th>
							<th><?php esc_html_e( 'مقدار', 'csb' ); ?></th>
							<th><?php esc_html_e( 'استفاده', 'csb' ); ?></th>
							<th><?php esc_html_e( 'انقضا', 'csb' ); ?></th>
							<th><?php esc_html_e( 'وضعیت', 'csb' ); ?></th>
							<th><?php esc_html_e( 'عملیات', 'csb' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $coupons as $c ) : ?>
							<tr>
								<td><code><?php echo esc_html( $c['code'] ); ?></code></td>
								<td>
									<?php echo esc_html( 'percent' === $c['discount_type']
										? __( 'درصدی', 'csb' )
										: __( 'ثابت', 'csb' ) ); ?>
								</td>
								<td>
									<?php echo 'percent' === $c['discount_type']
										? esc_html( $c['amount'] . '%' )
										: esc_html( number_format( (float) $c['amount'] ) . ' تومان' ); ?>
								</td>
								<td>
									<?php echo esc_html( $c['usage_count'] )
										. ( null !== $c['usage_limit']
											? ' / ' . esc_html( $c['usage_limit'] )
											: '' ); ?>
								</td>
								<td>
									<?php echo $c['expires_at']
										? esc_html( Jalali::to_jalali_string( $c['expires_at'], 'Y/m/d' ) )
										: '—'; ?>
								</td>
								<td>
									<?php echo (int) $c['active']
										? '<span style="color:green">' . esc_html__( 'فعال', 'csb' ) . '</span>'
										: '<span style="color:#999">' . esc_html__( 'غیرفعال', 'csb' ) . '</span>'; ?>
								</td>
								<td>
									<?php if ( (int) $c['active'] ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url(
											admin_url( 'admin-post.php?action=csb_coupon_deactivate&coupon_id=' . (int) $c['id'] ),
											'csb_coupon_deactivate_' . (int) $c['id']
										) ); ?>"
										onclick="return confirm('<?php esc_attr_e( 'غیرفعال شود؟', 'csb' ); ?>')">
											<?php esc_html_e( 'غیرفعال', 'csb' ); ?>
										</a>
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
