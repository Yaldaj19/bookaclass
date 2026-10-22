<?php
/**
 * Registers the two custom WooCommerce product types and wires admin panels.
 *
 * Labels, URL bases, and taxonomy assignments are all read from the
 * `csb_cpt_config` option so they can be changed from the admin panel
 * without touching code (§16.5.1).
 *
 * @package CookingSchoolBooking\Products
 */

namespace CookingSchoolBooking\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProductTypes
 */
class ProductTypes {

	// ── Default config ───────────────────────────────────────────────────────

	/**
	 * Canonical defaults — merged with whatever is saved in options.
	 *
	 * @return array<string,mixed>
	 */
	public static function default_config(): array {
		return [
			'types' => [
				'class' => [
					'label_singular' => 'کلاس',
					'label_plural'   => 'کلاس‌ها',
					'classes_base'   => 'classes',
					'archive_slug'   => 'classes',
					'has_archive'    => true,
					'enabled'        => true,
					'menu_position'  => 5,
					'taxonomies'     => [ 'product_cat', 'product_tag', 'csb_level' ],
				],
				'video_course' => [
					'label_singular' => 'ویدیوکلاس',
					'label_plural'   => 'ویدیوکلاس‌ها',
					'classes_base'   => 'courses',
					'archive_slug'   => 'courses',
					'has_archive'    => true,
					'enabled'        => true,
					'menu_position'  => 6,
					'taxonomies'     => [ 'product_cat', 'product_tag', 'csb_level' ],
				],
			],
			'taxonomies' => [
				'csb_level' => [
					'label_singular' => 'سطح',
					'label_plural'   => 'سطح‌ها',
					'slug'           => 'level',
					'hierarchical'   => true,
					'enabled'        => true,
				],
			],
		];
	}

	/**
	 * Returns merged (saved option + defaults) config.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_config(): array {
		$saved    = get_option( 'csb_cpt_config', [] );
		$defaults = self::default_config();

		if ( ! is_array( $saved ) ) {
			return $defaults;
		}

		$merged = $defaults;

		foreach ( (array) ( $saved['types'] ?? [] ) as $key => $overrides ) {
			if ( isset( $merged['types'][ $key ] ) && is_array( $overrides ) ) {
				$merged['types'][ $key ] = array_merge( $merged['types'][ $key ], $overrides );
			}
		}

		foreach ( (array) ( $saved['taxonomies'] ?? [] ) as $key => $overrides ) {
			if ( is_array( $overrides ) ) {
				$merged['taxonomies'][ $key ] = array_merge(
					$merged['taxonomies'][ $key ] ?? [],
					$overrides
				);
			}
		}

		return $merged;
	}

	// ── Bootstrap ────────────────────────────────────────────────────────────

	/**
	 * Attaches all hooks.
	 */
	public function init(): void {
		// Tell WC which PHP class handles each product type.
		add_filter( 'woocommerce_product_class', [ $this, 'filter_product_class' ], 10, 2 );

		// Add our types to the «Product type» dropdown.
		add_filter( 'product_type_selector', [ $this, 'filter_type_selector' ] );

		// Register custom taxonomies on init.
		add_action( 'init', [ $this, 'register_taxonomies' ], 5 );

		// Product data tabs + panels for admin.
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_data_tabs' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_class_panel' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_sessions_stub_panel' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_video_panel' ] );

		// Save meta on product save.
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_class_meta' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_video_meta' ] );

		// Early-bird price override: return early-bird price when now < until.
		add_filter( 'woocommerce_product_get_price',         [ $this, 'maybe_earlybird_price' ], 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ $this, 'maybe_earlybird_price' ], 10, 2 );

		// Admin assets.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	// ── Early-bird pricing ────────────────────────────────────────────────────

	/**
	 * Returns the early-bird price when:
	 *   - product type is 'class'
	 *   - _csb_earlybird_price is set and > 0
	 *   - current time is before _csb_earlybird_until (UTC timestamp)
	 *
	 * @param  string|mixed     $price    Existing price from WC.
	 * @param  \WC_Product|mixed $product  WC product object.
	 * @return string|mixed
	 */
	public function maybe_earlybird_price( $price, $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return $price;
		}

		if ( 'class' !== $product->get_type() ) {
			return $price;
		}

		$eb_price = (float) $product->get_meta( '_csb_earlybird_price', true );
		if ( $eb_price <= 0 ) {
			return $price;
		}

		$eb_until_ts = (int) $product->get_meta( '_csb_earlybird_until', true );
		if ( $eb_until_ts <= 0 || time() >= $eb_until_ts ) {
			return $price;
		}

		return (string) $eb_price;
	}

	// ── WC hooks ─────────────────────────────────────────────────────────────

	/**
	 * Maps WC product type keys → our PHP class names.
	 */
	public function filter_product_class( string $classname, string $product_type ): string {
		$map = [
			'class'        => ClassProduct::class,
			'video_course' => VideoCourseProduct::class,
		];
		return $map[ $product_type ] ?? $classname;
	}

	/**
	 * Adds our types to the WC product type dropdown.
	 *
	 * @param  array<string,string> $types
	 * @return array<string,string>
	 */
	public function filter_type_selector( array $types ): array {
		$config = self::get_config();
		foreach ( $config['types'] as $key => $cfg ) {
			if ( ! empty( $cfg['enabled'] ) ) {
				$types[ $key ] = esc_html( $cfg['label_singular'] );
			}
		}
		return $types;
	}

	/**
	 * Registers custom taxonomies (csb_level, and any extra ones from config).
	 * product_cat / product_tag are WooCommerce's own — not re-registered here.
	 */
	public function register_taxonomies(): void {
		$config = self::get_config();

		foreach ( $config['taxonomies'] as $key => $cfg ) {
			if ( empty( $cfg['enabled'] ) ) {
				continue;
			}

			$s = $cfg['label_singular'];
			$p = $cfg['label_plural'];

			register_taxonomy(
				$key,
				[ 'product' ],
				[
					'labels'       => [
						'name'          => $p,
						'singular_name' => $s,
						'search_items'  => sprintf( 'جستجوی %s', $p ),
						'all_items'     => sprintf( 'همه‌ی %s', $p ),
						'edit_item'     => sprintf( 'ویرایش %s', $s ),
						'update_item'   => sprintf( 'بروزرسانی %s', $s ),
						'add_new_item'  => sprintf( 'افزودن %s جدید', $s ),
						'new_item_name' => sprintf( 'نام %s جدید', $s ),
						'menu_name'     => $p,
					],
					'hierarchical' => ! empty( $cfg['hierarchical'] ),
					'public'       => true,
					'show_ui'      => true,
					'show_in_menu' => true,
					'show_in_rest' => true,
					'rewrite'      => [
						'slug'       => sanitize_title( $cfg['slug'] ?? $key ),
						'with_front' => false,
					],
				]
			);
		}
	}

	// ── Product data tabs ─────────────────────────────────────────────────────

	/**
	 * @param  array<string,array<string,mixed>> $tabs
	 * @return array<string,array<string,mixed>>
	 */
	public function add_product_data_tabs( array $tabs ): array {
		$tabs['csb_class'] = [
			'label'    => __( 'کلاس', 'csb' ),
			'target'   => 'csb_class_data',
			'class'    => [ 'show_if_class' ],
			'priority' => 60,
		];

		$tabs['csb_sessions_stub'] = [
			'label'    => __( 'جلسات', 'csb' ),
			'target'   => 'csb_sessions_stub_data',
			'class'    => [ 'show_if_class' ],
			'priority' => 61,
		];

		$tabs['csb_video'] = [
			'label'    => __( 'ویدیوکلاس', 'csb' ),
			'target'   => 'csb_video_data',
			'class'    => [ 'show_if_video_course' ],
			'priority' => 60,
		];

		// Hide irrelevant WC tabs for our types.
		foreach ( [ 'inventory', 'shipping', 'variations', 'linked_product' ] as $tab ) {
			if ( isset( $tabs[ $tab ] ) ) {
				$tabs[ $tab ]['class'][] = 'hide_if_class';
				$tabs[ $tab ]['class'][] = 'hide_if_video_course';
			}
		}

		return $tabs;
	}

	// ── Admin panels ──────────────────────────────────────────────────────────

	/**
	 * Renders the «کلاس» product data panel.
	 */
	public function render_class_panel(): void {
		global $post;
		$product     = wc_get_product( $post->ID );
		$instructors = get_users( [ 'role__in' => [ 'csb_instructor', 'administrator', 'editor' ] ] );

		// Pull current values — works for both new products and existing ones.
		$eb_until_ts   = (int) get_post_meta( $post->ID, '_csb_earlybird_until', true );
		$eb_until_jalali = $eb_until_ts > 0 ? \CookingSchoolBooking\Support\Jalali::to_jalali_string(
			gmdate( 'Y-m-d', $eb_until_ts )
		) : '';

		$meta = [
			'class_type'         => get_post_meta( $post->ID, '_csb_class_type', true ) ?: 'in_person',
			'deposit_type'       => get_post_meta( $post->ID, '_csb_deposit_type', true ) ?: 'percent',
			'deposit_value'      => get_post_meta( $post->ID, '_csb_deposit_value', true ) ?: 30,
			'settle_days'        => get_post_meta( $post->ID, '_csb_settle_days', true ) ?: 3,
			'level'              => get_post_meta( $post->ID, '_csb_level', true ),
			'instructor_id'      => (int) get_post_meta( $post->ID, '_csb_instructor_id', true ),
			'prerequisites'      => get_post_meta( $post->ID, '_csb_prerequisites', true ),
			'materials'          => get_post_meta( $post->ID, '_csb_materials', true ),
			'syllabus'           => get_post_meta( $post->ID, '_csb_syllabus', true ),
			'cancel_policy_json' => get_post_meta( $post->ID, '_csb_cancel_policy_json', true ),
			'earlybird_price'    => get_post_meta( $post->ID, '_csb_earlybird_price', true ),
			'earlybird_until'    => $eb_until_jalali,
		];
		?>
		<div id="csb_class_data" class="panel woocommerce_options_panel" dir="rtl">
			<div class="options_group">

				<p class="form-field">
					<label for="_csb_class_type"><?php esc_html_e( 'نوع برگزاری', 'csb' ); ?></label>
					<select id="_csb_class_type" name="_csb_class_type">
						<option value="in_person" <?php selected( $meta['class_type'], 'in_person' ); ?>><?php esc_html_e( 'حضوری', 'csb' ); ?></option>
						<option value="online"    <?php selected( $meta['class_type'], 'online' ); ?>><?php esc_html_e( 'آنلاین زنده', 'csb' ); ?></option>
					</select>
				</p>

				<p class="form-field">
					<label for="_csb_deposit_type"><?php esc_html_e( 'نوع بیعانه', 'csb' ); ?></label>
					<select id="_csb_deposit_type" name="_csb_deposit_type">
						<option value="percent" <?php selected( $meta['deposit_type'], 'percent' ); ?>><?php esc_html_e( 'درصدی', 'csb' ); ?></option>
						<option value="fixed"   <?php selected( $meta['deposit_type'], 'fixed' ); ?>><?php esc_html_e( 'مبلغ ثابت', 'csb' ); ?></option>
					</select>
				</p>

				<p class="form-field">
					<label for="_csb_deposit_value"><?php esc_html_e( 'مقدار بیعانه (% یا مبلغ ثابت)', 'csb' ); ?></label>
					<input type="number" id="_csb_deposit_value" name="_csb_deposit_value"
						value="<?php echo esc_attr( (string) $meta['deposit_value'] ); ?>" step="0.01" min="0" />
				</p>

				<p class="form-field">
					<label for="_csb_settle_days"><?php esc_html_e( 'مهلت تسویه (روز قبل از کلاس)', 'csb' ); ?></label>
					<input type="number" id="_csb_settle_days" name="_csb_settle_days"
						value="<?php echo esc_attr( (string) $meta['settle_days'] ); ?>" min="0" max="30" />
				</p>

				<p class="form-field">
					<label for="_csb_level"><?php esc_html_e( 'سطح', 'csb' ); ?></label>
					<input type="text" id="_csb_level" name="_csb_level"
						value="<?php echo esc_attr( (string) $meta['level'] ); ?>" />
				</p>

				<p class="form-field">
					<label for="_csb_instructor_id"><?php esc_html_e( 'مدرس', 'csb' ); ?></label>
					<select id="_csb_instructor_id" name="_csb_instructor_id">
						<option value="0"><?php esc_html_e( '— انتخاب —', 'csb' ); ?></option>
						<?php foreach ( $instructors as $u ) : ?>
							<option value="<?php echo esc_attr( (string) $u->ID ); ?>" <?php selected( $meta['instructor_id'], $u->ID ); ?>>
								<?php echo esc_html( $u->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="form-field">
					<label for="_csb_prerequisites"><?php esc_html_e( 'پیش‌نیاز', 'csb' ); ?></label>
					<textarea id="_csb_prerequisites" name="_csb_prerequisites" rows="3"><?php echo esc_textarea( (string) $meta['prerequisites'] ); ?></textarea>
				</p>

				<p class="form-field">
					<label for="_csb_materials"><?php esc_html_e( 'مواد / ابزار موردنیاز', 'csb' ); ?></label>
					<textarea id="_csb_materials" name="_csb_materials" rows="4"><?php echo esc_textarea( (string) $meta['materials'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'هر مورد در یک خط.', 'csb' ); ?></span>
				</p>

				<p class="form-field">
					<label for="_csb_syllabus"><?php esc_html_e( 'سرفصل دوره', 'csb' ); ?></label>
					<textarea id="_csb_syllabus" name="_csb_syllabus" rows="6"><?php echo esc_textarea( (string) $meta['syllabus'] ); ?></textarea>
				</p>

				<p class="form-field">
					<label for="_csb_earlybird_price"><?php esc_html_e( 'قیمت ثبت‌نام زودهنگام', 'csb' ); ?></label>
					<input type="number" id="_csb_earlybird_price" name="_csb_earlybird_price"
						value="<?php echo esc_attr( (string) $meta['earlybird_price'] ); ?>"
						step="100" min="0" />
					<span class="description"><?php esc_html_e( 'خالی = بدون تخفیف ثبت‌نام زودهنگام', 'csb' ); ?></span>
				</p>

				<p class="form-field">
					<label for="_csb_earlybird_until"><?php esc_html_e( 'مهلت ثبت‌نام زودهنگام (تاریخ جلالی: ۱۴۰۴/۰۱/۰۱)', 'csb' ); ?></label>
					<input type="text" id="_csb_earlybird_until" name="_csb_earlybird_until"
						value="<?php echo esc_attr( (string) $meta['earlybird_until'] ); ?>"
						placeholder="۱۴۰۴/۰۱/۱۵" style="direction:ltr;max-width:160px;" />
					<span class="description"><?php esc_html_e( 'فرمت: YYYY/MM/DD جلالی', 'csb' ); ?></span>
				</p>

			</div>
		</div>
		<?php
	}

	/**
	 * Renders the «جلسات» stub panel (full session CRUD built in Phase 2).
	 */
	public function render_sessions_stub_panel(): void {
		global $post;
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}csb_sessions WHERE class_id = %d AND status != 'cancelled'",
				(int) $post->ID
			)
		);
		?>
		<div id="csb_sessions_stub_data" class="panel woocommerce_options_panel" dir="rtl">
			<div class="options_group">
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: link to sessions page */
							__( 'مدیریت جلسات از <a href="%s">پنل جلسات</a> انجام می‌شود.', 'csb' ),
							[ 'a' => [ 'href' => [] ] ]
						),
						esc_url( admin_url( 'admin.php?page=csb-sessions&product_id=' . (int) $post->ID ) )
					);
					?>
				</p>
				<p>
					<?php
					printf(
						esc_html( _n( '%d جلسه‌ی فعال', '%d جلسه‌ی فعال', $count, 'csb' ) ),
						$count
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the «ویدیوکلاس» product data panel with lessons repeater.
	 */
	public function render_video_panel(): void {
		global $post;
		$instructors      = get_users( [ 'role__in' => [ 'csb_instructor', 'administrator', 'editor' ] ] );
		$access_duration  = (int) get_post_meta( $post->ID, '_csb_access_duration', true );
		$instructor_id    = (int) get_post_meta( $post->ID, '_csb_instructor_id', true );
		$lessons_json     = get_post_meta( $post->ID, '_csb_lessons', true );
		$lessons          = [];
		if ( $lessons_json ) {
			$decoded = json_decode( $lessons_json, true );
			$lessons = is_array( $decoded ) ? $decoded : [];
		}
		?>
		<div id="csb_video_data" class="panel woocommerce_options_panel" dir="rtl">
			<div class="options_group">

				<p class="form-field">
					<label for="_csb_vc_instructor_id"><?php esc_html_e( 'مدرس', 'csb' ); ?></label>
					<select id="_csb_vc_instructor_id" name="_csb_vc_instructor_id">
						<option value="0"><?php esc_html_e( '— انتخاب —', 'csb' ); ?></option>
						<?php foreach ( $instructors as $u ) : ?>
							<option value="<?php echo esc_attr( (string) $u->ID ); ?>" <?php selected( $instructor_id, $u->ID ); ?>>
								<?php echo esc_html( $u->display_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="form-field">
					<label for="_csb_access_duration"><?php esc_html_e( 'مدت دسترسی (روز — ۰ = مادام‌العمر)', 'csb' ); ?></label>
					<input type="number" id="_csb_access_duration" name="_csb_access_duration"
						value="<?php echo esc_attr( (string) $access_duration ); ?>" min="0" />
				</p>

			</div>

			<div class="options_group csb-lessons-wrap">
				<h4 style="padding: 0 12px; margin: 8px 0;"><?php esc_html_e( 'جلسات ویدیویی', 'csb' ); ?></h4>

				<div id="csb-lessons-list">
					<?php foreach ( $lessons as $i => $lesson ) : ?>
						<?php $this->render_lesson_row( $i, $lesson ); ?>
					<?php endforeach; ?>
				</div>

				<p style="padding: 0 12px;">
					<button type="button" class="button csb-add-lesson">+ <?php esc_html_e( 'افزودن جلسه', 'csb' ); ?></button>
				</p>

				<script type="text/template" id="csb-lesson-tpl">
					<?php $this->render_lesson_row( '__IDX__', [] ); ?>
				</script>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a single lesson row.
	 *
	 * @param int|string          $idx    Row index or '__IDX__' for JS template.
	 * @param array<string,mixed> $lesson
	 */
	private function render_lesson_row( $idx, array $lesson ): void {
		$title    = esc_attr( $lesson['title'] ?? '' );
		$order    = esc_attr( (string) ( $lesson['order'] ?? 0 ) );
		$vref     = esc_attr( $lesson['video_ref'] ?? '' );
		$dur      = esc_attr( (string) ( $lesson['duration'] ?? 0 ) );
		$free     = ! empty( $lesson['is_free_preview'] );
		$res_url  = esc_attr( $lesson['resource_url'] ?? '' );
		$n        = esc_attr( (string) $idx );
		?>
		<div class="csb-lesson-row" style="border:1px solid #ddd;margin:6px 12px;padding:10px;background:#fafafa;" dir="rtl">
			<input type="hidden" name="_csb_lessons[<?php echo $n; ?>][order]" value="<?php echo $order; ?>" class="csb-lesson-order" />

			<p class="form-field"><label><?php esc_html_e( 'عنوان', 'csb' ); ?></label>
				<input type="text" name="_csb_lessons[<?php echo $n; ?>][title]" value="<?php echo $title; ?>" /></p>

			<p class="form-field"><label><?php esc_html_e( 'منبع ویدیو', 'csb' ); ?></label>
				<input type="text" name="_csb_lessons[<?php echo $n; ?>][video_ref]" value="<?php echo $vref; ?>" /></p>

			<p class="form-field"><label><?php esc_html_e( 'مدت (دقیقه)', 'csb' ); ?></label>
				<input type="number" name="_csb_lessons[<?php echo $n; ?>][duration]" value="<?php echo $dur; ?>" min="0" style="width:80px" /></p>

			<p class="form-field"><label>
				<input type="checkbox" name="_csb_lessons[<?php echo $n; ?>][is_free_preview]" value="1" <?php checked( $free ); ?> />
				<?php esc_html_e( 'پیش‌نمایش رایگان', 'csb' ); ?>
			</label></p>

			<p class="form-field"><label><?php esc_html_e( 'URL منبع دانلودی', 'csb' ); ?></label>
				<input type="url" name="_csb_lessons[<?php echo $n; ?>][resource_url]" value="<?php echo $res_url; ?>" /></p>

			<p><button type="button" class="button csb-remove-lesson" style="color:#c00;"><?php esc_html_e( 'حذف جلسه', 'csb' ); ?></button></p>
		</div>
		<?php
	}

	// ── Save meta ─────────────────────────────────────────────────────────────

	/**
	 * Saves class meta fields on product save.
	 * Nonce already verified by WC's own save flow.
	 */
	public function save_class_meta( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof ClassProduct ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$allowed_class_types   = [ 'in_person', 'online' ];
		$allowed_deposit_types = [ 'percent', 'fixed' ];

		update_post_meta( $post_id, '_csb_class_type',
			in_array( $_POST['_csb_class_type'] ?? '', $allowed_class_types, true )
				? sanitize_text_field( $_POST['_csb_class_type'] ) : 'in_person' );

		update_post_meta( $post_id, '_csb_deposit_type',
			in_array( $_POST['_csb_deposit_type'] ?? '', $allowed_deposit_types, true )
				? sanitize_text_field( $_POST['_csb_deposit_type'] ) : 'percent' );

		update_post_meta( $post_id, '_csb_deposit_value', max( 0.0, (float) ( $_POST['_csb_deposit_value'] ?? 30 ) ) );
		update_post_meta( $post_id, '_csb_settle_days',   max( 0, min( 30, (int) ( $_POST['_csb_settle_days'] ?? 3 ) ) ) );
		update_post_meta( $post_id, '_csb_level',         sanitize_text_field( $_POST['_csb_level'] ?? '' ) );
		update_post_meta( $post_id, '_csb_instructor_id', max( 0, (int) ( $_POST['_csb_instructor_id'] ?? 0 ) ) );
		update_post_meta( $post_id, '_csb_prerequisites', sanitize_textarea_field( $_POST['_csb_prerequisites'] ?? '' ) );
		update_post_meta( $post_id, '_csb_materials',     sanitize_textarea_field( $_POST['_csb_materials'] ?? '' ) );
		update_post_meta( $post_id, '_csb_syllabus',      wp_kses_post( wp_unslash( $_POST['_csb_syllabus'] ?? '' ) ) );

		$policy = wp_unslash( $_POST['_csb_cancel_policy_json'] ?? '' );
		if ( '' !== $policy && null === json_decode( $policy ) ) {
			$policy = '';
		}
		update_post_meta( $post_id, '_csb_cancel_policy_json', sanitize_text_field( $policy ) );

		// Early-bird price (float, 0 = disabled).
		$eb_price = max( 0.0, (float) ( $_POST['_csb_earlybird_price'] ?? 0 ) );
		update_post_meta( $post_id, '_csb_earlybird_price', $eb_price > 0 ? $eb_price : '' );

		// Early-bird until: input is Jalali string, store as UTC Unix timestamp.
		$eb_jalali = sanitize_text_field( wp_unslash( $_POST['_csb_earlybird_until'] ?? '' ) );
		$eb_ts     = 0;
		if ( '' !== $eb_jalali ) {
			try {
				$greg  = \CookingSchoolBooking\Support\Jalali::toGregorian( $eb_jalali );
				$eb_ts = (int) strtotime( $greg );
			} catch ( \Throwable $e ) {
				$eb_ts = 0; // invalid input — silently ignore.
			}
		}
		update_post_meta( $post_id, '_csb_earlybird_until', $eb_ts > 0 ? $eb_ts : '' );
		// phpcs:enable
	}

	/**
	 * Saves video_course meta fields on product save.
	 */
	public function save_video_meta( int $post_id ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product instanceof VideoCourseProduct ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		update_post_meta( $post_id, '_csb_vc_instructor_id', max( 0, (int) ( $_POST['_csb_vc_instructor_id'] ?? 0 ) ) );
		update_post_meta( $post_id, '_csb_access_duration',  max( 0, (int) ( $_POST['_csb_access_duration'] ?? 0 ) ) );

		$raw_lessons = is_array( $_POST['_csb_lessons'] ?? null ) ? $_POST['_csb_lessons'] : [];
		$lessons     = [];
		foreach ( $raw_lessons as $lesson ) {
			if ( ! is_array( $lesson ) ) {
				continue;
			}
			$lessons[] = [
				'title'           => sanitize_text_field( $lesson['title'] ?? '' ),
				'order'           => (int) ( $lesson['order'] ?? 0 ),
				'video_ref'       => sanitize_text_field( $lesson['video_ref'] ?? '' ),
				'duration'        => (int) ( $lesson['duration'] ?? 0 ),
				'is_free_preview' => ! empty( $lesson['is_free_preview'] ),
				'resource_url'    => esc_url_raw( $lesson['resource_url'] ?? '' ),
			];
		}
		usort( $lessons, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		update_post_meta( $post_id, '_csb_lessons', wp_json_encode( $lessons ) );
		// phpcs:enable
	}

	// ── Admin assets ──────────────────────────────────────────────────────────

	public function enqueue_admin_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			'csb-admin-lessons',
			CSB_URL . 'assets/js/admin-lessons.js',
			[ 'jquery' ],
			CSB_VERSION,
			true
		);
	}
}
