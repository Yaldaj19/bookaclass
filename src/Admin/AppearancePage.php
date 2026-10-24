<?php
/**
 * Appearance settings page — color tokens, radius, container, font,
 * card layout, and archive layout options.
 *
 * Submenu: «آموزشگاه → ظاهر»
 * Option:  csb_style  (single flat array)
 *
 * Token keys (match the CSS variable names emitted by StyleVars):
 *   primary       --csb-primary
 *   primary_hover --csb-primary-hover
 *   accent        --csb-accent
 *   bg            --csb-bg
 *   surface       --csb-surface
 *   text          --csb-text
 *   muted         --csb-muted
 *   border        --csb-border
 *   success       --csb-success
 *   danger        --csb-danger
 *   radius        --csb-radius        (px, 0–24)
 *   radius_lg     --csb-radius-lg     (px, 0–40)
 *   container_max --csb-container     (px, 600–1600)
 *   font_family   --csb-font          (Vazirmatn | system)
 *
 * Card / archive layout keys:
 *   card_style          classic | overlay | minimal | horizontal
 *   archive_columns     2 | 3 | 4
 *   archive_layout      grid | list
 *   archive_width       boxed | full
 *   archive_per_page    int
 *   archive_sidebar     none | right | left
 *   show_filters        bool
 *   show_result_count   bool
 *   card_show_image, card_show_category, card_show_instructor,
 *   card_show_level, card_show_type_badge, card_show_next_session,
 *   card_show_seats, card_show_rating, card_show_excerpt,
 *   card_show_price, card_show_earlybird  (bool each)
 *   card_cta_label      text
 *
 * Security: register_setting + nonce + manage_woocommerce cap.
 * All colors sanitized through sanitize_hex_color(); numerics clamped.
 *
 * @package BookaClass\Admin
 */

namespace BookaClass\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AppearancePage
 */
class AppearancePage {

	/** Option key that holds all style tokens. */
	public const OPTION_KEY = 'csb_style';

	// ── Defaults ─────────────────────────────────────────────────────────────

	/**
	 * Default token values — warm warm terracotta/orange palette.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			// Colors & font.
			'primary'       => '#c0392b',
			'primary_hover' => '#a93226',
			'accent'        => '#e67e22',
			'bg'            => '#fdf8f3',
			'surface'       => '#ffffff',
			'text'          => '#1a1a1a',
			'muted'         => '#7f8c8d',
			'border'        => '#e0d5c8',
			'success'       => '#27ae60',
			'danger'        => '#e74c3c',
			'radius'        => 8,
			'radius_lg'     => 14,
			'container_max' => 1200,
			'font_family'   => 'Vazirmatn',

			// Card style.
			'card_style'    => 'classic',

			// Archive layout.
			'archive_columns'   => 3,
			'archive_layout'    => 'grid',
			'archive_width'     => 'boxed',
			'archive_per_page'  => 12,
			'archive_sidebar'   => 'none',
			'show_filters'      => true,
			'show_result_count' => true,

			// Card toggles (all true by default).
			'card_show_image'        => true,
			'card_show_category'     => true,
			'card_show_instructor'   => true,
			'card_show_level'        => true,
			'card_show_type_badge'   => true,
			'card_show_next_session' => true,
			'card_show_seats'        => true,
			'card_show_rating'       => true,
			'card_show_excerpt'      => true,
			'card_show_price'        => true,
			'card_show_earlybird'    => true,

			// Card CTA label.
			'card_cta_label' => 'مشاهده و ثبت‌نام',
		];
	}

	/**
	 * Returns saved tokens merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	// ── Presets ───────────────────────────────────────────────────────────────

	/**
	 * Three named presets filled by the JS preset buttons.
	 * Label is only used in the UI — not stored.
	 *
	 * @return array<string,array<string,string|int>>
	 */
	public static function presets(): array {
		return [
			'warm' => [
				'label'         => 'گرم (نارنجی آشپزخانه)',
				'primary'       => '#c0392b',
				'primary_hover' => '#a93226',
				'accent'        => '#e67e22',
				'bg'            => '#fdf8f3',
				'surface'       => '#ffffff',
				'text'          => '#1a1a1a',
				'muted'         => '#7f8c8d',
				'border'        => '#e0d5c8',
				'success'       => '#27ae60',
				'danger'        => '#e74c3c',
				'radius'        => 8,
				'radius_lg'     => 14,
				'container_max' => 1200,
				'font_family'   => 'Vazirmatn',
			],
			'green' => [
				'label'         => 'سبز (طبیعی)',
				'primary'       => '#27ae60',
				'primary_hover' => '#219a52',
				'accent'        => '#16a085',
				'bg'            => '#f4fbf7',
				'surface'       => '#ffffff',
				'text'          => '#1a2a1e',
				'muted'         => '#6c8c77',
				'border'        => '#c3e6d2',
				'success'       => '#2ecc71',
				'danger'        => '#e74c3c',
				'radius'        => 10,
				'radius_lg'     => 18,
				'container_max' => 1200,
				'font_family'   => 'Vazirmatn',
			],
			'dark' => [
				'label'         => 'تیره (حرفه‌ای)',
				'primary'       => '#3498db',
				'primary_hover' => '#2980b9',
				'accent'        => '#9b59b6',
				'bg'            => '#1a1d23',
				'surface'       => '#23272f',
				'text'          => '#ecf0f1',
				'muted'         => '#95a5a6',
				'border'        => '#2c3040',
				'success'       => '#2ecc71',
				'danger'        => '#e74c3c',
				'radius'        => 6,
				'radius_lg'     => 12,
				'container_max' => 1200,
				'font_family'   => 'Vazirmatn',
			],
		];
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public function init(): void {
		add_action( 'admin_menu',                          [ $this, 'register_menu' ] );
		add_action( 'admin_init',                          [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts',               [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_csb_appearance_save',      [ $this, 'handle_save' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'ظاهر آموزشگاه', 'csb' ),
			esc_html__( 'ظاهر', 'csb' ),
			'manage_woocommerce',
			'csb-appearance',
			[ $this, 'render' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'csb_style_group',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	// ── Assets (color picker — this page only) ────────────────────────────────

	public function enqueue_assets( string $hook ): void {
		global $plugin_page;
		if ( 'csb-appearance' !== ( $plugin_page ?? '' ) ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		// Init all .csb-color-picker inputs.
		wp_add_inline_script(
			'wp-color-picker',
			'document.addEventListener("DOMContentLoaded",function(){
				if(typeof jQuery==="undefined"||typeof jQuery.fn.wpColorPicker==="undefined"){return;}
				jQuery(".csb-color-picker").wpColorPicker();
			});'
		);
	}

	// ── Save handler ──────────────────────────────────────────────────────────

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( 'csb_appearance_save' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$raw = is_array( $_POST[ self::OPTION_KEY ] ?? null )
			? $_POST[ self::OPTION_KEY ]
			: [];
		// phpcs:enable

		update_option( self::OPTION_KEY, $this->sanitize( $raw ), false );

		wp_safe_redirect( add_query_arg(
			[ 'page' => 'csb-appearance', 'csb_saved' => '1' ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Sanitizes raw POST input for the csb_style option.
	 *
	 * @param  mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$d = self::defaults();

		if ( ! is_array( $input ) ) {
			return $d;
		}

		$clean = [];

		// ── Colors ────────────────────────────────────────────────────────────
		$color_keys = [
			'primary', 'primary_hover', 'accent',
			'bg', 'surface', 'text', 'muted', 'border',
			'success', 'danger',
		];
		foreach ( $color_keys as $k ) {
			$v = sanitize_hex_color( $input[ $k ] ?? '' );
			$clean[ $k ] = $v ?: $d[ $k ];
		}

		// ── Numerics ──────────────────────────────────────────────────────────
		$clean['radius']        = max( 0,   min( 24,   (int) ( $input['radius']        ?? $d['radius'] ) ) );
		$clean['radius_lg']     = max( 0,   min( 40,   (int) ( $input['radius_lg']     ?? $d['radius_lg'] ) ) );
		$clean['container_max'] = max( 600, min( 1600, (int) ( $input['container_max'] ?? $d['container_max'] ) ) );
		$clean['archive_per_page'] = max( 1, min( 100, (int) ( $input['archive_per_page'] ?? $d['archive_per_page'] ) ) );

		// ── Select: font_family ───────────────────────────────────────────────
		$allowed_fonts = [ 'Vazirmatn', 'system' ];
		$clean['font_family'] = in_array( $input['font_family'] ?? '', $allowed_fonts, true )
			? $input['font_family']
			: $d['font_family'];

		// ── Select: card_style ────────────────────────────────────────────────
		$allowed_card_styles = [ 'classic', 'overlay', 'minimal', 'horizontal' ];
		$clean['card_style'] = in_array( $input['card_style'] ?? '', $allowed_card_styles, true )
			? $input['card_style']
			: $d['card_style'];

		// ── Select: archive_columns ───────────────────────────────────────────
		$allowed_cols = [ 2, 3, 4 ];
		$clean['archive_columns'] = in_array( (int) ( $input['archive_columns'] ?? 0 ), $allowed_cols, true )
			? (int) $input['archive_columns']
			: $d['archive_columns'];

		// ── Select: archive_layout ────────────────────────────────────────────
		$clean['archive_layout'] = in_array( $input['archive_layout'] ?? '', [ 'grid', 'list' ], true )
			? $input['archive_layout']
			: $d['archive_layout'];

		// ── Select: archive_width ─────────────────────────────────────────────
		$clean['archive_width'] = in_array( $input['archive_width'] ?? '', [ 'boxed', 'full' ], true )
			? $input['archive_width']
			: $d['archive_width'];

		// ── Select: archive_sidebar ───────────────────────────────────────────
		$clean['archive_sidebar'] = in_array( $input['archive_sidebar'] ?? '', [ 'none', 'right', 'left' ], true )
			? $input['archive_sidebar']
			: $d['archive_sidebar'];

		// ── Booleans ──────────────────────────────────────────────────────────
		$bool_keys = [
			'show_filters', 'show_result_count',
			'card_show_image', 'card_show_category', 'card_show_instructor',
			'card_show_level', 'card_show_type_badge', 'card_show_next_session',
			'card_show_seats', 'card_show_rating', 'card_show_excerpt',
			'card_show_price', 'card_show_earlybird',
		];
		foreach ( $bool_keys as $k ) {
			// Checkbox: present = true, absent = false.
			$clean[ $k ] = ! empty( $input[ $k ] );
		}

		// ── Text: card_cta_label ──────────────────────────────────────────────
		$cta = sanitize_text_field( $input['card_cta_label'] ?? '' );
		$clean['card_cta_label'] = '' !== $cta ? $cta : $d['card_cta_label'];

		return $clean;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$style   = self::get();
		$presets = self::presets();
		$saved   = ! empty( $_GET['csb_saved'] );

		$color_fields = [
			'primary'       => __( 'رنگ اصلی (دکمه‌ها)', 'csb' ),
			'primary_hover' => __( 'رنگ اصلی — حالت hover', 'csb' ),
			'accent'        => __( 'رنگ تأکید (نشان‌ها، هایلایت)', 'csb' ),
			'bg'            => __( 'پس‌زمینه صفحه', 'csb' ),
			'surface'       => __( 'پس‌زمینه کارت / پنل', 'csb' ),
			'text'          => __( 'رنگ متن اصلی', 'csb' ),
			'muted'         => __( 'رنگ متن ثانوی (muted)', 'csb' ),
			'border'        => __( 'رنگ خطوط / حاشیه', 'csb' ),
			'success'       => __( 'رنگ موفقیت (success)', 'csb' ),
			'danger'        => __( 'رنگ خطر / هشدار (danger)', 'csb' ),
		];

		$bool_to_checked = static function ( $key ) use ( $style ): string {
			return ! empty( $style[ $key ] ) ? ' checked' : '';
		};
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'ظاهر آموزشگاه', 'csb' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'تنظیمات ظاهری ذخیره شد.', 'csb' ); ?></p>
				</div>
			<?php endif; ?>

			<!-- Preset buttons -->
			<div style="margin-bottom:1.5em;">
				<strong style="display:block;margin-bottom:.5em;">
					<?php esc_html_e( 'پریست‌های آماده:', 'csb' ); ?>
				</strong>
				<div style="display:flex;gap:.5em;flex-wrap:wrap;">
				<?php foreach ( $presets as $preset_key => $preset ) : ?>
					<button
						type="button"
						class="button csb-ap-preset"
						data-preset="<?php echo esc_attr( (string) wp_json_encode( $preset ) ); ?>"
						style="background:<?php echo esc_attr( (string) $preset['primary'] ); ?>;color:#fff;border-color:transparent;">
						<?php echo esc_html( $preset['label'] ); ?>
					</button>
				<?php endforeach; ?>
				</div>
				<p class="description" style="margin-top:.4em;">
					<?php esc_html_e( 'پریست فقط فیلدهای رنگ/فونت را پر می‌کند — برای ذخیره «ذخیره ظاهر» را بزنید.', 'csb' ); ?>
				</p>
			</div>

			<!-- Tab nav -->
			<div id="csb-ap-tabs" style="margin-bottom:1em;">
				<style>
				.csb-ap-tab-nav{display:flex;gap:0;border-bottom:2px solid #c3c4c7;margin-bottom:1.5em;list-style:none;padding:0;}
				.csb-ap-tab-nav li{margin:0;}
				.csb-ap-tab-nav a{display:inline-block;padding:.5em 1.2em;text-decoration:none;color:#1d2327;border:2px solid transparent;border-bottom:none;margin-bottom:-2px;font-size:13px;}
				.csb-ap-tab-nav a[aria-selected="true"]{background:#fff;border-color:#c3c4c7;border-bottom-color:#fff;font-weight:600;}
				.csb-ap-tab-panel{display:none;}
				.csb-ap-tab-panel.csb-ap-active{display:block;}
				</style>
				<ul class="csb-ap-tab-nav" role="tablist" aria-label="<?php esc_attr_e( 'بخش‌های ظاهر', 'csb' ); ?>">
					<li role="presentation">
						<a href="#csb-tab-colors" role="tab" aria-selected="true" id="csb-tab-btn-colors">
							<?php esc_html_e( 'رنگ‌ها و فونت', 'csb' ); ?>
						</a>
					</li>
					<li role="presentation">
						<a href="#csb-tab-cards" role="tab" aria-selected="false" id="csb-tab-btn-cards">
							<?php esc_html_e( 'کارت‌ها', 'csb' ); ?>
						</a>
					</li>
					<li role="presentation">
						<a href="#csb-tab-archive" role="tab" aria-selected="false" id="csb-tab-btn-archive">
							<?php esc_html_e( 'آرشیو', 'csb' ); ?>
						</a>
					</li>
					<li role="presentation">
						<a href="#csb-tab-general" role="tab" aria-selected="false" id="csb-tab-btn-general">
							<?php esc_html_e( 'عمومی', 'csb' ); ?>
						</a>
					</li>
				</ul>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'csb_appearance_save' ); ?>
				<input type="hidden" name="action" value="csb_appearance_save">

				<!-- ══════════════════════════════════════════════════════════════
				     TAB 1: رنگ‌ها و فونت
				     ══════════════════════════════════════════════════════════ -->
				<div id="csb-tab-colors" class="csb-ap-tab-panel csb-ap-active" role="tabpanel" aria-labelledby="csb-tab-btn-colors">
					<table class="form-table" role="presentation">

						<?php foreach ( $color_fields as $key => $label ) : ?>
						<tr>
							<th scope="row">
								<label for="csb_ap_<?php echo esc_attr( $key ); ?>">
									<?php echo esc_html( $label ); ?>
								</label>
							</th>
							<td>
								<input
									type="text"
									id="csb_ap_<?php echo esc_attr( $key ); ?>"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
									value="<?php echo esc_attr( (string) $style[ $key ] ); ?>"
									class="csb-color-picker"
									data-default-color="<?php echo esc_attr( (string) self::defaults()[ $key ] ); ?>"
								/>
							</td>
						</tr>
						<?php endforeach; ?>

						<tr>
							<th scope="row">
								<label for="csb_ap_radius">
									<?php esc_html_e( 'گردی گوشه (radius — پیکسل)', 'csb' ); ?>
								</label>
							</th>
							<td>
								<input
									type="number"
									id="csb_ap_radius"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[radius]"
									value="<?php echo esc_attr( (string) $style['radius'] ); ?>"
									min="0" max="24" class="small-text"
								/>
								<span class="description">0–24 px</span>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_radius_lg">
									<?php esc_html_e( 'گردی گوشه بزرگ (radius-lg — پیکسل)', 'csb' ); ?>
								</label>
							</th>
							<td>
								<input
									type="number"
									id="csb_ap_radius_lg"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[radius_lg]"
									value="<?php echo esc_attr( (string) $style['radius_lg'] ); ?>"
									min="0" max="40" class="small-text"
								/>
								<span class="description">0–40 px</span>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_container_max">
									<?php esc_html_e( 'عرض حداکثر محتوا (container — پیکسل)', 'csb' ); ?>
								</label>
							</th>
							<td>
								<input
									type="number"
									id="csb_ap_container_max"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[container_max]"
									value="<?php echo esc_attr( (string) $style['container_max'] ); ?>"
									min="600" max="1600" step="20" class="small-text"
								/>
								<span class="description">600–1600 px</span>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_font_family">
									<?php esc_html_e( 'فونت', 'csb' ); ?>
								</label>
							</th>
							<td>
								<select
									id="csb_ap_font_family"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[font_family]"
								>
									<option value="Vazirmatn" <?php selected( $style['font_family'], 'Vazirmatn' ); ?>>
										Vazirmatn (<?php esc_html_e( 'پیش‌فرض', 'csb' ); ?>)
									</option>
									<option value="system" <?php selected( $style['font_family'], 'system' ); ?>>
										<?php esc_html_e( 'فونت سیستم', 'csb' ); ?>
									</option>
								</select>
							</td>
						</tr>

					</table>
				</div><!-- /tab-colors -->

				<!-- ══════════════════════════════════════════════════════════════
				     TAB 2: کارت‌ها
				     ══════════════════════════════════════════════════════════ -->
				<div id="csb-tab-cards" class="csb-ap-tab-panel" role="tabpanel" aria-labelledby="csb-tab-btn-cards">
					<table class="form-table" role="presentation">

						<tr>
							<th scope="row">
								<label for="csb_ap_card_style">
									<?php esc_html_e( 'سبک کارت', 'csb' ); ?>
								</label>
							</th>
							<td>
								<select
									id="csb_ap_card_style"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[card_style]"
								>
									<option value="classic"    <?php selected( $style['card_style'], 'classic' ); ?>><?php esc_html_e( 'کلاسیک', 'csb' ); ?></option>
									<option value="overlay"    <?php selected( $style['card_style'], 'overlay' ); ?>><?php esc_html_e( 'Overlay (متن روی تصویر)', 'csb' ); ?></option>
									<option value="minimal"    <?php selected( $style['card_style'], 'minimal' ); ?>><?php esc_html_e( 'مینیمال', 'csb' ); ?></option>
									<option value="horizontal" <?php selected( $style['card_style'], 'horizontal' ); ?>><?php esc_html_e( 'افقی', 'csb' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_card_cta_label">
									<?php esc_html_e( 'متن دکمه کارت', 'csb' ); ?>
								</label>
							</th>
							<td>
								<input
									type="text"
									id="csb_ap_card_cta_label"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[card_cta_label]"
									value="<?php echo esc_attr( (string) $style['card_cta_label'] ); ?>"
									class="regular-text"
								/>
							</td>
						</tr>

						<?php
						$card_toggles = [
							'card_show_image'        => __( 'نمایش تصویر', 'csb' ),
							'card_show_category'     => __( 'نمایش دسته‌بندی', 'csb' ),
							'card_show_instructor'   => __( 'نمایش نام مدرس', 'csb' ),
							'card_show_level'        => __( 'نمایش سطح دوره', 'csb' ),
							'card_show_type_badge'   => __( 'نمایش نشان نوع (حضوری/آنلاین/ویدیویی)', 'csb' ),
							'card_show_next_session' => __( 'نمایش تاریخ جلسه بعدی (کلاس)', 'csb' ),
							'card_show_seats'        => __( 'نمایش ظرفیت خالی', 'csb' ),
							'card_show_rating'       => __( 'نمایش امتیاز', 'csb' ),
							'card_show_excerpt'      => __( 'نمایش توضیح کوتاه', 'csb' ),
							'card_show_price'        => __( 'نمایش قیمت', 'csb' ),
							'card_show_earlybird'    => __( 'نمایش قیمت ثبت‌نام زودهنگام (در صورت فعال بودن)', 'csb' ),
						];
						?>

						<?php foreach ( $card_toggles as $key => $label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php echo $bool_to_checked( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									/>
									<?php esc_html_e( 'فعال', 'csb' ); ?>
								</label>
							</td>
						</tr>
						<?php endforeach; ?>

					</table>
				</div><!-- /tab-cards -->

				<!-- ══════════════════════════════════════════════════════════════
				     TAB 3: آرشیو
				     ══════════════════════════════════════════════════════════ -->
				<div id="csb-tab-archive" class="csb-ap-tab-panel" role="tabpanel" aria-labelledby="csb-tab-btn-archive">
					<table class="form-table" role="presentation">

						<tr>
							<th scope="row">
								<label for="csb_ap_archive_columns">
									<?php esc_html_e( 'تعداد ستون‌ها', 'csb' ); ?>
								</label>
							</th>
							<td>
								<select
									id="csb_ap_archive_columns"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_columns]"
								>
									<option value="2" <?php selected( (int) $style['archive_columns'], 2 ); ?>>2</option>
									<option value="3" <?php selected( (int) $style['archive_columns'], 3 ); ?>>3</option>
									<option value="4" <?php selected( (int) $style['archive_columns'], 4 ); ?>>4</option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_archive_layout">
									<?php esc_html_e( 'چیدمان', 'csb' ); ?>
								</label>
							</th>
							<td>
								<select
									id="csb_ap_archive_layout"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_layout]"
								>
									<option value="grid" <?php selected( $style['archive_layout'], 'grid' ); ?>><?php esc_html_e( 'شبکه (grid)', 'csb' ); ?></option>
									<option value="list" <?php selected( $style['archive_layout'], 'list' ); ?>><?php esc_html_e( 'لیست', 'csb' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_archive_width">
									<?php esc_html_e( 'عرض صفحه آرشیو', 'csb' ); ?>
								</label>
							</th>
							<td>
								<select
									id="csb_ap_archive_width"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_width]"
								>
									<option value="boxed" <?php selected( $style['archive_width'], 'boxed' ); ?>><?php esc_html_e( 'محدود (boxed)', 'csb' ); ?></option>
									<option value="full"  <?php selected( $style['archive_width'], 'full' ); ?>><?php esc_html_e( 'تمام‌عرض (full)', 'csb' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_archive_per_page">
									<?php esc_html_e( 'تعداد دوره در هر صفحه', 'csb' ); ?>
								</label>
							</th>
							<td>
								<input
									type="number"
									id="csb_ap_archive_per_page"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_per_page]"
									value="<?php echo esc_attr( (string) $style['archive_per_page'] ); ?>"
									min="1" max="100" class="small-text"
								/>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="csb_ap_archive_sidebar">
									<?php esc_html_e( 'سایدبار', 'csb' ); ?>
								</label>
							</th>
							<td>
								<select
									id="csb_ap_archive_sidebar"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[archive_sidebar]"
								>
									<option value="none"  <?php selected( $style['archive_sidebar'], 'none' ); ?>><?php esc_html_e( 'بدون سایدبار', 'csb' ); ?></option>
									<option value="right" <?php selected( $style['archive_sidebar'], 'right' ); ?>><?php esc_html_e( 'راست', 'csb' ); ?></option>
									<option value="left"  <?php selected( $style['archive_sidebar'], 'left' ); ?>><?php esc_html_e( 'چپ', 'csb' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'نمایش فیلترها', 'csb' ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_filters]"
										value="1"
										<?php echo $bool_to_checked( 'show_filters' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									/>
									<?php esc_html_e( 'فعال', 'csb' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'نمایش تعداد نتایج', 'csb' ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_KEY ); ?>[show_result_count]"
										value="1"
										<?php echo $bool_to_checked( 'show_result_count' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									/>
									<?php esc_html_e( 'فعال', 'csb' ); ?>
								</label>
							</td>
						</tr>

					</table>
				</div><!-- /tab-archive -->

				<!-- ══════════════════════════════════════════════════════════════
				     TAB 4: عمومی
				     ══════════════════════════════════════════════════════════ -->
				<div id="csb-tab-general" class="csb-ap-tab-panel" role="tabpanel" aria-labelledby="csb-tab-btn-general">
					<p class="description" style="margin-top:1em;">
						<?php esc_html_e( 'تنظیمات عمومی در نسخه‌های بعدی اضافه می‌شوند.', 'csb' ); ?>
					</p>
				</div><!-- /tab-general -->

				<?php submit_button( esc_html__( 'ذخیره ظاهر', 'csb' ), 'primary', 'submit', true ); ?>
			</form>
		</div>

		<script>
		(function () {
			// ── Tab switching ──────────────────────────────────────────────────
			var tabs = document.querySelectorAll('.csb-ap-tab-nav a');
			var panels = document.querySelectorAll('.csb-ap-tab-panel');

			function activateTab(href) {
				tabs.forEach(function(a) {
					a.setAttribute('aria-selected', a.getAttribute('href') === href ? 'true' : 'false');
				});
				panels.forEach(function(p) {
					if ('#' + p.id === href) {
						p.classList.add('csb-ap-active');
					} else {
						p.classList.remove('csb-ap-active');
					}
				});
			}

			tabs.forEach(function(a) {
				a.addEventListener('click', function(e) {
					e.preventDefault();
					activateTab(a.getAttribute('href'));
				});
			});

			// ── Preset buttons ─────────────────────────────────────────────────
			var colorKeys = [
				'primary','primary_hover','accent',
				'bg','surface','text','muted','border','success','danger'
			];

			document.querySelectorAll('.csb-ap-preset').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var preset = {};
					try { preset = JSON.parse(this.dataset.preset || '{}'); } catch(e) {}

					colorKeys.forEach(function (k) {
						if (!preset[k]) { return; }
						var el = document.getElementById('csb_ap_' + k);
						if (!el) { return; }
						el.value = preset[k];
						if (typeof jQuery !== 'undefined' && jQuery(el).hasClass('wp-color-picker')) {
							jQuery(el).wpColorPicker('color', preset[k]);
						}
					});

					['radius','radius_lg','container_max'].forEach(function (k) {
						if (preset[k] === undefined) { return; }
						var el = document.getElementById('csb_ap_' + k);
						if (el) { el.value = preset[k]; }
					});

					var ff = document.getElementById('csb_ap_font_family');
					if (ff && preset.font_family) { ff.value = preset.font_family; }
				});
			});
		}());
		</script>
		<?php
	}
}
