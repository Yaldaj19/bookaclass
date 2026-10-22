<?php
/**
 * Appearance settings page — color tokens, radius, container, font, and presets.
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
 * Security: register_setting + nonce + manage_woocommerce cap.
 * All colors sanitized through sanitize_hex_color(); numerics clamped.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

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
	 * Default token values — warm cooking-school terracotta/orange palette.
	 *
	 * @return array<string,string|int>
	 */
	public static function defaults(): array {
		return [
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
		];
	}

	/**
	 * Returns saved tokens merged with defaults.
	 *
	 * @return array<string,string|int>
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
	 * @return array<string,string|int>
	 */
	public function sanitize( $input ): array {
		$d = self::defaults();

		if ( ! is_array( $input ) ) {
			return $d;
		}

		$color_keys = [
			'primary', 'primary_hover', 'accent',
			'bg', 'surface', 'text', 'muted', 'border',
			'success', 'danger',
		];

		$clean = [];

		foreach ( $color_keys as $k ) {
			$v = sanitize_hex_color( $input[ $k ] ?? '' );
			$clean[ $k ] = $v ?: $d[ $k ];
		}

		$clean['radius']        = max( 0,   min( 24,   (int) ( $input['radius']        ?? $d['radius'] ) ) );
		$clean['radius_lg']     = max( 0,   min( 40,   (int) ( $input['radius_lg']     ?? $d['radius_lg'] ) ) );
		$clean['container_max'] = max( 600, min( 1600, (int) ( $input['container_max'] ?? $d['container_max'] ) ) );

		$allowed_fonts = [ 'Vazirmatn', 'system' ];
		$clean['font_family'] = in_array( $input['font_family'] ?? '', $allowed_fonts, true )
			? $input['font_family']
			: $d['font_family'];

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

		// Color fields: token key => label.
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
					<?php esc_html_e( 'پریست فقط فیلدها را پر می‌کند — برای ذخیره «ذخیره ظاهر» را بزنید.', 'csb' ); ?>
				</p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'csb_appearance_save' ); ?>
				<input type="hidden" name="action" value="csb_appearance_save">

				<table class="form-table" role="presentation">

					<!-- Color pickers -->
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

					<!-- radius -->
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

					<!-- radius_lg -->
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

					<!-- container_max -->
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

					<!-- font_family -->
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
									Vazirmatn (پیش‌فرض)
								</option>
								<option value="system" <?php selected( $style['font_family'], 'system' ); ?>>
									<?php esc_html_e( 'فونت سیستم', 'csb' ); ?>
								</option>
							</select>
						</td>
					</tr>

				</table>

				<?php submit_button( esc_html__( 'ذخیره ظاهر', 'csb' ), 'primary', 'submit', true ); ?>
			</form>
		</div>

		<script>
		(function () {
			// Color token keys that map to input IDs.
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
						// Refresh wp-color-picker iris widget if active.
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
