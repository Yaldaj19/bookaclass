<?php
/**
 * Settings page for Cooking School Booking.
 *
 * Registers the csb_settings option group and renders the admin form.
 * All defaults are defined here; individual classes can override specific values.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsPage
 */
class SettingsPage {

	/** Option key that holds all plugin settings as a single array. */
	public const OPTION_KEY = 'csb_settings';

	/**
	 * Sane defaults — see spec §7/§8.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'deposit_default_percent'    => 30,
			'settle_days_default'        => 3,
			'reminder_days'              => [ 7, 3, 1 ],
			'currency'                   => 'IRT',   // تومان
			'video_host'                 => 'local',
			'enable_wallet'              => true,
			'enable_ecommerce'           => false,
			'delete_data_on_uninstall'   => false,   // safe default: keep data on remove
			// Checkout: URL of the terms & conditions page shown to students.
			'terms_page_url'             => '',
			// Min-to-run: hours before session start to check quorum.
			'min_to_run_hours'           => 24,
		];
	}

	/**
	 * Returns the saved settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Hooks into WordPress to register the settings.
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Registers the settings group and sanitization callback.
	 */
	public function register_settings(): void {
		register_setting(
			'csb_settings_group',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
			]
		);
	}

	/**
	 * Sanitizes and validates settings before saving.
	 *
	 * @param  mixed $input Raw POST data.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$clean = [];

		// deposit_default_percent: 1–100.
		$clean['deposit_default_percent'] = max( 1, min( 100, (int) ( $input['deposit_default_percent'] ?? $defaults['deposit_default_percent'] ) ) );

		// settle_days_default: 0–30.
		$clean['settle_days_default'] = max( 0, min( 30, (int) ( $input['settle_days_default'] ?? $defaults['settle_days_default'] ) ) );

		// reminder_days: comma-separated integers.
		$raw_reminder = $input['reminder_days_raw'] ?? '';
		if ( is_string( $raw_reminder ) && '' !== trim( $raw_reminder ) ) {
			$reminder_days = array_map( 'intval', explode( ',', $raw_reminder ) );
			$reminder_days = array_filter( $reminder_days, fn( int $d ) => $d > 0 );
			$reminder_days = array_values( array_unique( $reminder_days ) );
			rsort( $reminder_days );
		} else {
			$reminder_days = $defaults['reminder_days'];
		}
		$clean['reminder_days'] = $reminder_days;

		// currency: IRT or IRR.
		$clean['currency'] = in_array( $input['currency'] ?? '', [ 'IRT', 'IRR' ], true )
			? $input['currency']
			: $defaults['currency'];

		// video_host: local | arvan | tamasha.
		$clean['video_host'] = in_array( $input['video_host'] ?? '', [ 'local', 'arvan', 'tamasha' ], true )
			? $input['video_host']
			: $defaults['video_host'];

		// Checkbox booleans.
		$clean['enable_wallet']            = ! empty( $input['enable_wallet'] );
		$clean['enable_ecommerce']         = ! empty( $input['enable_ecommerce'] );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

		// terms_page_url — URL or empty.
		$terms_url = esc_url_raw( $input['terms_page_url'] ?? '' );
		$clean['terms_page_url'] = $terms_url;

		// min_to_run_hours: 1–168.
		$clean['min_to_run_hours'] = max( 1, min( 168, (int) ( $input['min_to_run_hours'] ?? 24 ) ) );

		return $clean;
	}

	/**
	 * Renders the settings page HTML.
	 * Called from Plugin::add_admin_menu() as a static callback.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$settings      = self::get();
		$reminder_raw  = implode( ', ', $settings['reminder_days'] );
		?>
		<div class="wrap" dir="rtl" style="font-family:inherit;">
			<h1><?php esc_html_e( 'تنظیمات آموزشگاه', 'csb' ); ?></h1>

			<?php settings_errors( self::OPTION_KEY ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'csb_settings_group' ); ?>

				<table class="form-table" role="presentation">

					<!-- deposit_default_percent -->
					<tr>
						<th scope="row">
							<label for="csb_deposit_default_percent">
								<?php esc_html_e( 'درصد بیعانه پیش‌فرض', 'csb' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="csb_deposit_default_percent"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deposit_default_percent]"
								value="<?php echo esc_attr( (string) $settings['deposit_default_percent'] ); ?>"
								min="1"
								max="100"
								class="small-text"
							/>
							<p class="description">
								<?php esc_html_e( 'مثلاً ۳۰ یعنی هنرجو ۳۰٪ را هنگام رزرو پرداخت می‌کند.', 'csb' ); ?>
							</p>
						</td>
					</tr>

					<!-- settle_days_default -->
					<tr>
						<th scope="row">
							<label for="csb_settle_days_default">
								<?php esc_html_e( 'مهلت تسویه (روز قبل از کلاس)', 'csb' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="csb_settle_days_default"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[settle_days_default]"
								value="<?php echo esc_attr( (string) $settings['settle_days_default'] ); ?>"
								min="0"
								max="30"
								class="small-text"
							/>
							<p class="description">
								<?php esc_html_e( 'هنرجو باید حداقل این تعداد روز قبل از شروع کلاس مانده را تسویه کند.', 'csb' ); ?>
							</p>
						</td>
					</tr>

					<!-- reminder_days -->
					<tr>
						<th scope="row">
							<label for="csb_reminder_days">
								<?php esc_html_e( 'روزهای یادآوری تسویه', 'csb' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="csb_reminder_days"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[reminder_days_raw]"
								value="<?php echo esc_attr( $reminder_raw ); ?>"
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'اعداد را با کاما جدا کنید. مثال: 7, 3, 1', 'csb' ); ?>
							</p>
						</td>
					</tr>

					<!-- currency -->
					<tr>
						<th scope="row">
							<label for="csb_currency">
								<?php esc_html_e( 'واحد پول نمایشی', 'csb' ); ?>
							</label>
						</th>
						<td>
							<select
								id="csb_currency"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[currency]"
							>
								<option value="IRT" <?php selected( $settings['currency'], 'IRT' ); ?>>
									<?php esc_html_e( 'تومان (IRT)', 'csb' ); ?>
								</option>
								<option value="IRR" <?php selected( $settings['currency'], 'IRR' ); ?>>
									<?php esc_html_e( 'ریال (IRR)', 'csb' ); ?>
								</option>
							</select>
						</td>
					</tr>

					<!-- video_host -->
					<tr>
						<th scope="row">
							<label for="csb_video_host">
								<?php esc_html_e( 'هاست ویدیو', 'csb' ); ?>
							</label>
						</th>
						<td>
							<select
								id="csb_video_host"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[video_host]"
							>
								<option value="local" <?php selected( $settings['video_host'], 'local' ); ?>>
									<?php esc_html_e( 'محلی (محافظت‌شده)', 'csb' ); ?>
								</option>
								<option value="arvan" <?php selected( $settings['video_host'], 'arvan' ); ?>>
									<?php esc_html_e( 'آروان‌کلاد', 'csb' ); ?>
								</option>
								<option value="tamasha" <?php selected( $settings['video_host'], 'tamasha' ); ?>>
									<?php esc_html_e( 'تماشا', 'csb' ); ?>
								</option>
							</select>
							<!-- TODO: add arvan/tamasha credential fields when video_host != local -->
						</td>
					</tr>

					<!-- enable_wallet -->
					<tr>
						<th scope="row">
							<?php esc_html_e( 'کیف‌پول دیجیتال', 'csb' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_wallet]"
									value="1"
									<?php checked( $settings['enable_wallet'] ); ?>
								/>
								<?php esc_html_e( 'فعال‌سازی کیف‌پول برای بازگشت وجه به‌صورت اعتبار', 'csb' ); ?>
							</label>
						</td>
					</tr>

					<!-- enable_ecommerce -->
					<tr>
						<th scope="row">
							<?php esc_html_e( 'فروش محصول فیزیکی', 'csb' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enable_ecommerce]"
									value="1"
									<?php checked( $settings['enable_ecommerce'] ); ?>
								/>
								<?php esc_html_e( 'نمایش محصولات فیزیکی ووکامرس در کنار دوره‌ها', 'csb' ); ?>
							</label>
						</td>
					</tr>

					<!-- delete_data_on_uninstall -->
					<tr>
						<th scope="row">
							<?php esc_html_e( 'حذف داده‌ها هنگام حذف افزونه', 'csb' ); ?>
						</th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[delete_data_on_uninstall]"
									value="1"
									<?php checked( $settings['delete_data_on_uninstall'] ); ?>
								/>
								<?php esc_html_e( 'هنگام حذف افزونه، تمام جدول‌ها و تنظیمات پاک شوند.', 'csb' ); ?>
							</label>
							<p class="description" style="color:#c62d2d;">
								<?php esc_html_e( 'هشدار: این عمل برگشت‌پذیر نیست.', 'csb' ); ?>
							</p>
						</td>
					</tr>

				</table>

					<!-- terms_page_url -->
					<tr>
						<th scope="row">
							<label for="csb_terms_page_url">
								<?php esc_html_e( 'آدرس صفحه قوانین', 'csb' ); ?>
							</label>
						</th>
						<td>
							<input
								type="url"
								id="csb_terms_page_url"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[terms_page_url]"
								value="<?php echo esc_attr( (string) $settings['terms_page_url'] ); ?>"
								class="regular-text"
								placeholder="https://example.com/terms"
							/>
							<p class="description">
								<?php esc_html_e( 'لینک «قوانین و شرایط» که در صفحه پرداخت نمایش داده می‌شود.', 'csb' ); ?>
							</p>
						</td>
					</tr>

					<!-- min_to_run_hours -->
					<tr>
						<th scope="row">
							<label for="csb_min_to_run_hours">
								<?php esc_html_e( 'بازه بررسی حدنصاب (ساعت قبل از کلاس)', 'csb' ); ?>
							</label>
						</th>
						<td>
							<input
								type="number"
								id="csb_min_to_run_hours"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_to_run_hours]"
								value="<?php echo esc_attr( (string) ( $settings['min_to_run_hours'] ?? 24 ) ); ?>"
								min="1"
								max="168"
								class="small-text"
							/>
							<p class="description">
								<?php esc_html_e( 'اگر در این تعداد ساعت قبل از شروع جلسه حدنصاب تأمین نشود، جلسه لغو می‌شود.', 'csb' ); ?>
							</p>
						</td>
					</tr>

				<?php submit_button( esc_html__( 'ذخیره تنظیمات', 'csb' ) ); ?>
			</form>
		</div>
		<?php
	}
}
