<?php
/**
 * Admin page: full CRUD for class sessions.
 *
 * URL: wp-admin/admin.php?page=csb-sessions[&product_id=X][&action=edit&id=Y]
 *
 * Actions:
 *   (none)   → list all sessions, optionally filtered by product_id.
 *   add      → create new session form (product_id required).
 *   edit     → edit existing session form.
 *   delete   → soft-delete (status→cancelled) with nonce.
 *   save     → POST handler for add/edit.
 *
 * Security: every write is guarded by a nonce + manage_woocommerce cap check.
 * All DB reads use $wpdb->prepare(); outputs use esc_html/esc_attr/esc_url.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

use CookingSchoolBooking\Services\AuditLogger;
use CookingSchoolBooking\Support\Jalali;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SessionsPage
 */
class SessionsPage {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		// Handle POST saves before output.
		add_action( 'admin_init', [ $this, 'handle_save' ] );
		add_action( 'admin_init', [ $this, 'handle_delete' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────

	public function register_submenu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'جلسات کلاس', 'csb' ),
			esc_html__( 'جلسات', 'csb' ),
			'manage_woocommerce',
			'csb-sessions',
			[ $this, 'render' ]
		);
	}

	// ── Router ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'csb' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action     = sanitize_key( $_GET['action'] ?? '' );
		$session_id = (int) ( $_GET['id'] ?? 0 );
		$product_id = (int) ( $_GET['product_id'] ?? 0 );
		// phpcs:enable

		switch ( $action ) {
			case 'add':
				$this->render_form( null, $product_id );
				break;
			case 'edit':
				$this->render_form( $session_id, $product_id );
				break;
			default:
				$this->render_list( $product_id );
				break;
		}
	}

	// ── List ──────────────────────────────────────────────────────────────────

	private function render_list( int $product_id ): void {
		global $wpdb;

		// Updated / error notices from redirect.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$updated = sanitize_key( $_GET['csb_notice'] ?? '' );
		// phpcs:enable

		// Build query.
		if ( $product_id ) {
			$sessions = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.*, p.post_title AS class_title
				   FROM {$wpdb->prefix}csb_sessions s
				   JOIN {$wpdb->posts} p ON p.ID = s.class_id
				  WHERE s.class_id = %d
				  ORDER BY s.start_at DESC
				  LIMIT 200",
				$product_id
			), ARRAY_A );
		} else {
			$sessions = $wpdb->get_results(
				"SELECT s.*, p.post_title AS class_title
				   FROM {$wpdb->prefix}csb_sessions s
				   JOIN {$wpdb->posts} p ON p.ID = s.class_id
				  ORDER BY s.start_at DESC
				  LIMIT 500",
				ARRAY_A
			) ?: [];
		}

		$add_url = admin_url( 'admin.php?page=csb-sessions&action=add' . ( $product_id ? '&product_id=' . $product_id : '' ) );
		?>
		<div class="wrap" dir="rtl">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'جلسات کلاس', 'csb' ); ?>
			</h1>
			<a href="<?php echo esc_url( $add_url ); ?>" class="page-title-action">
				<?php esc_html_e( '+ افزودن جلسه', 'csb' ); ?>
			</a>
			<hr class="wp-header-end">

			<?php if ( 'saved' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'جلسه ذخیره شد.', 'csb' ); ?></p></div>
			<?php elseif ( 'deleted' === $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'جلسه لغو شد.', 'csb' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $product_id ) :
				$class_name = get_the_title( $product_id );
				?>
				<p>
					<?php printf(
						wp_kses(
							/* translators: %s: class name */
							__( 'جلسات کلاس: <strong>%s</strong>', 'csb' ),
							[ 'strong' => [] ]
						),
						esc_html( (string) $class_name )
					); ?>
					&nbsp;
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=csb-sessions' ) ); ?>">
						<?php esc_html_e( 'مشاهده همه', 'csb' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped" style="direction:rtl">
				<thead>
					<tr>
						<th><?php esc_html_e( 'شناسه', 'csb' ); ?></th>
						<th><?php esc_html_e( 'کلاس', 'csb' ); ?></th>
						<th><?php esc_html_e( 'تاریخ شروع (شمسی)', 'csb' ); ?></th>
						<th><?php esc_html_e( 'مدت (دقیقه)', 'csb' ); ?></th>
						<th><?php esc_html_e( 'ظرفیت', 'csb' ); ?></th>
						<th><?php esc_html_e( 'رزرو‌شده', 'csb' ); ?></th>
						<th><?php esc_html_e( 'حدنصاب', 'csb' ); ?></th>
						<th><?php esc_html_e( 'محل', 'csb' ); ?></th>
						<th><?php esc_html_e( 'وضعیت', 'csb' ); ?></th>
						<th><?php esc_html_e( 'عملیات', 'csb' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $sessions ) ) : ?>
						<tr>
							<td colspan="10"><?php esc_html_e( 'جلسه‌ای یافت نشد.', 'csb' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $sessions as $s ) :
							$edit_url   = admin_url( 'admin.php?page=csb-sessions&action=edit&id=' . (int) $s['id'] );
							$delete_url = wp_nonce_url(
								admin_url( 'admin.php?page=csb-sessions&action=delete&id=' . (int) $s['id'] . '&product_id=' . (int) $s['class_id'] ),
								'csb_delete_session_' . (int) $s['id']
							);
							$jalali = Jalali::toJalali( $s['start_at'] );
							$status_map = [
								'scheduled' => 'برنامه‌ریزی‌شده',
								'running'   => 'در حال برگزاری',
								'completed' => 'برگزار شده',
								'cancelled' => 'لغو شده',
							];
							$status_label = $status_map[ $s['status'] ] ?? esc_html( $s['status'] );
						?>
							<tr>
								<td><?php echo esc_html( (string) $s['id'] ); ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=csb-sessions&product_id=' . (int) $s['class_id'] ) ); ?>">
										<?php echo esc_html( (string) $s['class_title'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $jalali ); ?></td>
								<td><?php echo esc_html( (string) $s['duration_min'] ); ?></td>
								<td><?php echo esc_html( (string) $s['capacity'] ); ?></td>
								<td><?php echo esc_html( (string) $s['seats_taken'] ); ?></td>
								<td><?php echo esc_html( (string) $s['min_to_run'] ); ?></td>
								<td><?php echo esc_html( (string) $s['location'] ); ?></td>
								<td>
									<span class="csb-status csb-status--<?php echo esc_attr( $s['status'] ); ?>">
										<?php echo esc_html( $status_label ); ?>
									</span>
								</td>
								<td>
									<?php if ( 'cancelled' !== $s['status'] ) : ?>
										<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'ویرایش', 'csb' ); ?></a>
										|
										<a href="<?php echo esc_url( $delete_url ); ?>"
											onclick="return confirm('<?php esc_attr_e( 'آیا مطمئنید؟', 'csb' ); ?>');"
											style="color:#c00;">
											<?php esc_html_e( 'لغو', 'csb' ); ?>
										</a>
									<?php else : ?>
										<span style="color:#999;"><?php esc_html_e( 'لغو شده', 'csb' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Form (add / edit) ─────────────────────────────────────────────────────

	private function render_form( ?int $session_id, int $product_id ): void {
		global $wpdb;

		$is_edit = ( null !== $session_id && $session_id > 0 );
		$session = null;

		if ( $is_edit ) {
			$session = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}csb_sessions WHERE id = %d",
				$session_id
			), ARRAY_A );

			if ( ! $session ) {
				wp_die( esc_html__( 'جلسه یافت نشد.', 'csb' ) );
			}

			$product_id = (int) $session['class_id'];
		}

		// Need a class product to add a session.
		if ( ! $product_id ) {
			wp_die( esc_html__( 'شناسه کلاس الزامی است.', 'csb' ) );
		}

		$class_product = wc_get_product( $product_id );
		if ( ! $class_product || 'class' !== $class_product->get_type() ) {
			wp_die( esc_html__( 'محصول مشخص‌شده یک کلاس معتبر نیست.', 'csb' ) );
		}

		// Defaults for a new session.
		$defaults = [
			'class_id'     => $product_id,
			'start_at'     => '',
			'duration_min' => 90,
			'capacity'     => 10,
			'seats_taken'  => 0,
			'min_to_run'   => 3,
			'location'     => '',
			'online_url'   => '',
			'status'       => 'scheduled',
		];

		$data = $session ? array_merge( $defaults, $session ) : $defaults;

		// Convert UTC start_at to Jalali for the form fields.
		$jalali_start = '';
		$time_start   = '';
		if ( ! empty( $data['start_at'] ) ) {
			$jalali_start = Jalali::toJalali( $data['start_at'], 'Y/m/d' );
			$time_start   = Jalali::toJalali( $data['start_at'], 'H:i' );
		}

		$form_action = admin_url( 'admin.php?page=csb-sessions' );
		$page_title  = $is_edit
			? esc_html__( 'ویرایش جلسه', 'csb' )
			: esc_html__( 'افزودن جلسه جدید', 'csb' );
		$list_url    = admin_url( 'admin.php?page=csb-sessions&product_id=' . $product_id );
		?>
		<div class="wrap" dir="rtl">
			<h1>
				<?php echo $page_title; ?>
				<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action">
					<?php esc_html_e( '← بازگشت به لیست', 'csb' ); ?>
				</a>
			</h1>
			<hr class="wp-header-end">

			<p>
				<?php printf(
					wp_kses(
						/* translators: %s: class name */
						__( 'کلاس: <strong>%s</strong>', 'csb' ),
						[ 'strong' => [] ]
					),
					esc_html( $class_product->get_name() )
				); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $form_action ); ?>">
				<?php wp_nonce_field( 'csb_save_session', '_csb_session_nonce' ); ?>
				<input type="hidden" name="csb_action"     value="save_session" />
				<input type="hidden" name="csb_product_id" value="<?php echo esc_attr( (string) $product_id ); ?>" />
				<?php if ( $is_edit ) : ?>
					<input type="hidden" name="csb_session_id" value="<?php echo esc_attr( (string) $session_id ); ?>" />
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="csb_start_jalali"><?php esc_html_e( 'تاریخ شروع (شمسی)', 'csb' ); ?></label></th>
						<td>
							<input type="text" id="csb_start_jalali" name="csb_start_jalali"
								value="<?php echo esc_attr( $jalali_start ); ?>"
								placeholder="مثلاً ۱۴۰۳/۰۸/۱۵" required style="width:130px" />
							&nbsp;
							<input type="time" id="csb_start_time" name="csb_start_time"
								value="<?php echo esc_attr( $time_start ?: '09:00' ); ?>"
								required style="width:90px" />
							<span class="description"><?php esc_html_e( 'فرمت تاریخ: YYYY/MM/DD', 'csb' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="csb_duration_min"><?php esc_html_e( 'مدت (دقیقه)', 'csb' ); ?></label></th>
						<td>
							<input type="number" id="csb_duration_min" name="csb_duration_min"
								value="<?php echo esc_attr( (string) $data['duration_min'] ); ?>"
								min="15" max="600" required />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="csb_capacity"><?php esc_html_e( 'ظرفیت کل', 'csb' ); ?></label></th>
						<td>
							<input type="number" id="csb_capacity" name="csb_capacity"
								value="<?php echo esc_attr( (string) $data['capacity'] ); ?>"
								min="1" max="9999" required />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="csb_min_to_run"><?php esc_html_e( 'حدنصاب تشکیل', 'csb' ); ?></label></th>
						<td>
							<input type="number" id="csb_min_to_run" name="csb_min_to_run"
								value="<?php echo esc_attr( (string) $data['min_to_run'] ); ?>"
								min="0" max="9999" />
							<span class="description"><?php esc_html_e( '۰ = بدون حدنصاب', 'csb' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="csb_location"><?php esc_html_e( 'محل برگزاری', 'csb' ); ?></label></th>
						<td>
							<input type="text" id="csb_location" name="csb_location"
								value="<?php echo esc_attr( (string) $data['location'] ); ?>"
								style="width:350px" />
							<span class="description"><?php esc_html_e( 'خالی بگذارید اگر آنلاین است.', 'csb' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="csb_online_url"><?php esc_html_e( 'لینک جلسه آنلاین', 'csb' ); ?></label></th>
						<td>
							<input type="url" id="csb_online_url" name="csb_online_url"
								value="<?php echo esc_attr( (string) $data['online_url'] ); ?>"
								style="width:350px" />
						</td>
					</tr>
					<?php if ( $is_edit ) : ?>
					<tr>
						<th scope="row"><label for="csb_status"><?php esc_html_e( 'وضعیت', 'csb' ); ?></label></th>
						<td>
							<select id="csb_status" name="csb_status">
								<?php
								$statuses = [
									'scheduled' => 'برنامه‌ریزی‌شده',
									'running'   => 'در حال برگزاری',
									'completed' => 'برگزار شده',
									'cancelled' => 'لغو شده',
								];
								foreach ( $statuses as $val => $label ) :
								?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $data['status'], $val ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php echo $is_edit ? esc_html__( 'ذخیره تغییرات', 'csb' ) : esc_html__( 'ذخیره جلسه', 'csb' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	// ── POST handlers ─────────────────────────────────────────────────────────

	/**
	 * Handles session create/update form submission.
	 */
	public function handle_save(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['csb_action'] ) || 'save_session' !== $_POST['csb_action'] ) {
			return;
		}
		// phpcs:enable

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'csb' ) );
		}

		check_admin_referer( 'csb_save_session', '_csb_session_nonce' );

		global $wpdb;

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$product_id = (int) ( $_POST['csb_product_id'] ?? 0 );
		$session_id = (int) ( $_POST['csb_session_id'] ?? 0 );

		$jalali_date = sanitize_text_field( $_POST['csb_start_jalali'] ?? '' );
		$time_str    = sanitize_text_field( $_POST['csb_start_time']   ?? '09:00' );
		$duration    = max( 15, min( 600, (int) ( $_POST['csb_duration_min'] ?? 90 ) ) );
		$capacity    = max( 1, (int) ( $_POST['csb_capacity'] ?? 1 ) );
		$min_to_run  = max( 0, (int) ( $_POST['csb_min_to_run'] ?? 0 ) );
		$location    = sanitize_text_field( $_POST['csb_location']    ?? '' );
		$online_url  = esc_url_raw( $_POST['csb_online_url']    ?? '' );
		$status      = sanitize_key( $_POST['csb_status'] ?? 'scheduled' );
		// phpcs:enable

		$allowed_statuses = [ 'scheduled', 'running', 'completed', 'cancelled' ];
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'scheduled';
		}

		// Convert Jalali date + time → UTC datetime.
		if ( ! preg_match( '/^\d{4}\/\d{1,2}\/\d{1,2}$/', $jalali_date ) ) {
			wp_die( esc_html__( 'فرمت تاریخ شمسی نامعتبر است.', 'csb' ) );
		}

		$start_at_utc = Jalali::toGregorian( $jalali_date . ' ' . $time_str );

		$data = [
			'class_id'     => $product_id,
			'start_at'     => $start_at_utc,
			'duration_min' => $duration,
			'capacity'     => $capacity,
			'min_to_run'   => $min_to_run,
			'location'     => $location,
			'online_url'   => $online_url,
			'status'       => $status,
		];

		$format = [ '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ];

		if ( $session_id > 0 ) {
			// Update — seats_taken is NOT reset; admin can't change it from this form.
			$wpdb->update(
				$wpdb->prefix . 'csb_sessions',
				$data,
				[ 'id' => $session_id ],
				$format,
				[ '%d' ]
			);
			AuditLogger::log( 'session_updated', 'session', $session_id, $data );
		} else {
			// Insert new session.
			$data['seats_taken'] = 0;
			$format[]            = '%d';

			$wpdb->insert( $wpdb->prefix . 'csb_sessions', $data, $format );
			$session_id = (int) $wpdb->insert_id;
			AuditLogger::log( 'session_created', 'session', $session_id, $data );
		}

		// Bust the Schema sessions transient for this class so Schema.php serves fresh data.
		delete_transient( 'csb_schema_sessions_' . $product_id );

		$redirect = admin_url( 'admin.php?page=csb-sessions&product_id=' . $product_id . '&csb_notice=saved' );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handles session soft-delete (status → cancelled).
	 */
	public function handle_delete(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] ) {
			return;
		}

		$session_id = (int) ( $_GET['id'] ?? 0 );
		$product_id = (int) ( $_GET['product_id'] ?? 0 );
		// phpcs:enable

		if ( ! $session_id ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی کافی ندارید.', 'csb' ) );
		}

		check_admin_referer( 'csb_delete_session_' . $session_id );

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'csb_sessions',
			[ 'status' => 'cancelled' ],
			[ 'id'     => $session_id ],
			[ '%s' ],
			[ '%d' ]
		);

		AuditLogger::log( 'session_cancelled', 'session', $session_id, [ 'by_admin' => true ] );

		if ( $product_id ) {
			delete_transient( 'csb_schema_sessions_' . $product_id );
		}

		$redirect = admin_url( 'admin.php?page=csb-sessions' . ( $product_id ? '&product_id=' . $product_id : '' ) . '&csb_notice=deleted' );
		wp_safe_redirect( $redirect );
		exit;
	}
}
