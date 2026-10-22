<?php
/**
 * Settings Hub — comprehensive, WooCommerce-grade tabbed settings for the
 * Cooking School Booking plugin.
 *
 * Replaces the old flat SettingsPage render while keeping SettingsPage::get()
 * as the authoritative backward-compatible getter — so every other class
 * that calls SettingsPage::get() continues to work unchanged.
 *
 * Tabs:
 *   1. عمومی       — currency (reads/writes WC native options), terms page, country.
 *   2. رزرو         — deposit %, settle days, reminder days, min-to-run, cancellation, waitlist.
 *   3. پرداخت       — gateway allow-list + link to WC payment settings.
 *   4. حساب‌ها       — guest checkout, auto-create account, OTP login, enroll-requires-login.
 *   5. اعلان‌ها      — per-event SMS/email enable + editable templates, sender name, Telegram chat ID.
 *   6. پیشرفته      — pages, delete-on-uninstall, cron status, REST namespace, flush rewrites.
 *   7. ظاهر         — links to AppearancePage (csb-appearance).
 *
 * Storage:
 *   WC options  → woocommerce_currency / _pos / _thousand_sep / _decimal_sep / _num_decimals
 *   Plugin opts → csb_settings  (all other keys — merged with SettingsPage::defaults())
 *
 * Security: nonce + manage_woocommerce cap on every write; all output escaped;
 *           all SQL via $wpdb->prepare(); sanitized before storing.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsHub
 */
class SettingsHub {

	/** Nonce action. */
	private const NONCE_ACTION = 'csb_settings_hub_save';

	/** Nonce field name. */
	private const NONCE_FIELD = '_csb_hub_nonce';

	/** Option key shared with SettingsPage (backward-compat). */
	private const OPT = 'csb_settings';

	// ─── Bootstrap ────────────────────────────────────────────────────────────

	public function init(): void {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_post_csb_hub_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_csb_hub_flush_rewrites', [ $this, 'handle_flush_rewrites' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'تنظیمات آموزشگاه', 'csb' ),
			esc_html__( 'تنظیمات', 'csb' ),
			'manage_woocommerce',
			'csb-settings',
			[ $this, 'render' ]
		);
	}

	// ─── Save handler ─────────────────────────────────────────────────────────

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$tab = isset( $_POST['csb_active_tab'] ) ? sanitize_key( $_POST['csb_active_tab'] ) : 'general';
		$raw = isset( $_POST[ self::OPT ] ) && is_array( $_POST[ self::OPT ] )
			? $_POST[ self::OPT ]
			: [];
		// phpcs:enable

		$existing = SettingsPage::get(); // merged defaults — backward-compat base

		switch ( $tab ) {
			case 'general':
				$this->save_general( $raw );
				break;
			case 'booking':
				$existing = array_merge( $existing, $this->sanitize_booking( $raw ) );
				update_option( self::OPT, $existing );
				break;
			case 'payment':
				$existing = array_merge( $existing, $this->sanitize_payment( $raw ) );
				update_option( self::OPT, $existing );
				break;
			case 'accounts':
				$existing = array_merge( $existing, $this->sanitize_accounts( $raw ) );
				update_option( self::OPT, $existing );
				break;
			case 'notifications':
				$existing = array_merge( $existing, $this->sanitize_notifications( $raw ) );
				update_option( self::OPT, $existing );
				break;
			case 'advanced':
				$existing = array_merge( $existing, $this->sanitize_advanced( $raw ) );
				update_option( self::OPT, $existing );
				break;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'csb-settings', 'tab' => $tab, 'csb_saved' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_flush_rewrites(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}
		check_admin_referer( 'csb_flush_rewrites' );
		flush_rewrite_rules( false );
		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'csb-settings', 'tab' => 'advanced', 'csb_flushed' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// ─── Save helpers ─────────────────────────────────────────────────────────

	/**
	 * Saves عمومی tab — writes to WC native options + csb_settings.
	 *
	 * @param array<string,mixed> $raw
	 */
	private function save_general( array $raw ): void {
		// ── WooCommerce native currency options ───────────────────────────────
		$all_currencies = array_keys( get_woocommerce_currencies() );

		$currency = strtoupper( sanitize_text_field( $raw['woocommerce_currency'] ?? '' ) );
		if ( in_array( $currency, $all_currencies, true ) ) {
			update_option( 'woocommerce_currency', $currency );
		}

		$pos_opts = [ 'left', 'right', 'left_space', 'right_space' ];
		$pos      = sanitize_text_field( $raw['woocommerce_currency_pos'] ?? '' );
		if ( in_array( $pos, $pos_opts, true ) ) {
			update_option( 'woocommerce_currency_pos', $pos );
		}

		$thousand_sep = sanitize_text_field( $raw['woocommerce_price_thousand_sep'] ?? '' );
		update_option( 'woocommerce_price_thousand_sep', $thousand_sep );

		$decimal_sep = sanitize_text_field( $raw['woocommerce_price_decimal_sep'] ?? '.' );
		update_option( 'woocommerce_price_decimal_sep', $decimal_sep );

		$decimals = max( 0, min( 6, (int) ( $raw['woocommerce_price_num_decimals'] ?? 2 ) ) );
		update_option( 'woocommerce_price_num_decimals', $decimals );

		// ── Plugin-specific general fields ────────────────────────────────────
		$existing = SettingsPage::get();

		$terms_page_id = (int) ( $raw['terms_page_id'] ?? 0 );
		$existing['terms_page_id'] = max( 0, $terms_page_id );

		// Backward-compat: also keep terms_page_url populated from page permalink.
		if ( $terms_page_id > 0 ) {
			$permalink = get_permalink( $terms_page_id );
			$existing['terms_page_url'] = $permalink ? esc_url_raw( $permalink ) : '';
		} else {
			$existing['terms_page_url'] = '';
		}

		$existing['video_host'] = in_array( $raw['video_host'] ?? '', [ 'local', 'arvan', 'tamasha' ], true )
			? $raw['video_host']
			: ( $existing['video_host'] ?? 'local' );

		$existing['enable_wallet']    = ! empty( $raw['enable_wallet'] );
		$existing['enable_ecommerce'] = ! empty( $raw['enable_ecommerce'] );

		// Default country stored in WC.
		$default_country = sanitize_text_field( $raw['woocommerce_default_country'] ?? '' );
		if ( '' !== $default_country ) {
			update_option( 'woocommerce_default_country', $default_country );
		}

		update_option( self::OPT, $existing );
	}

	/**
	 * Sanitizes رزرو tab fields.
	 *
	 * @param  array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_booking( array $raw ): array {
		$clean = [];

		$clean['deposit_default_percent'] = max( 1, min( 100, (int) ( $raw['deposit_default_percent'] ?? 30 ) ) );
		$clean['settle_days_default']     = max( 0, min( 30,  (int) ( $raw['settle_days_default']     ?? 3  ) ) );
		$clean['min_to_run_hours']        = max( 1, min( 168, (int) ( $raw['min_to_run_hours']        ?? 24 ) ) );

		$raw_reminder = $raw['reminder_days_raw'] ?? '';
		if ( is_string( $raw_reminder ) && '' !== trim( $raw_reminder ) ) {
			$days = array_map( 'intval', explode( ',', $raw_reminder ) );
			$days = array_values( array_unique( array_filter( $days, fn( int $d ) => $d > 0 ) ) );
			rsort( $days );
		} else {
			$days = [ 7, 3, 1 ];
		}
		$clean['reminder_days'] = $days;

		// Cancellation tiers: JSON-encoded array of {hours_before, fee_percent}.
		$tiers_raw = sanitize_textarea_field( $raw['cancellation_tiers'] ?? '' );
		$decoded   = json_decode( $tiers_raw, true );
		if ( is_array( $decoded ) ) {
			$tiers = [];
			foreach ( $decoded as $tier ) {
				if ( isset( $tier['hours_before'], $tier['fee_percent'] ) ) {
					$tiers[] = [
						'hours_before' => max( 0, (int) $tier['hours_before'] ),
						'fee_percent'  => max( 0, min( 100, (int) $tier['fee_percent'] ) ),
					];
				}
			}
			$clean['cancellation_tiers'] = $tiers;
		} else {
			$clean['cancellation_tiers'] = [];
		}

		$clean['enable_waitlist'] = ! empty( $raw['enable_waitlist'] );

		return $clean;
	}

	/**
	 * Sanitizes پرداخت tab fields.
	 *
	 * @param  array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_payment( array $raw ): array {
		$clean = [];

		$allowed_gateways = [];
		if ( isset( $raw['allowed_gateways'] ) && is_array( $raw['allowed_gateways'] ) ) {
			$all_ids = array_keys( WC()->payment_gateways()->payment_gateways() );
			foreach ( $raw['allowed_gateways'] as $gw ) {
				$gw = sanitize_key( $gw );
				if ( in_array( $gw, $all_ids, true ) ) {
					$allowed_gateways[] = $gw;
				}
			}
		}
		$clean['allowed_gateways'] = $allowed_gateways;

		return $clean;
	}

	/**
	 * Sanitizes حساب‌ها tab fields.
	 *
	 * @param  array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_accounts( array $raw ): array {
		$clean = [];

		// Mirror WC options for guest checkout / account creation.
		$guest = ! empty( $raw['woocommerce_enable_guest_checkout'] ) ? 'yes' : 'no';
		update_option( 'woocommerce_enable_guest_checkout', $guest );

		$create = ! empty( $raw['woocommerce_enable_signup_and_login_from_checkout'] ) ? 'yes' : 'no';
		update_option( 'woocommerce_enable_signup_and_login_from_checkout', $create );

		// Plugin-specific.
		$clean['otp_login_enabled']      = ! empty( $raw['otp_login_enabled'] );
		$clean['enroll_requires_login']  = ! empty( $raw['enroll_requires_login'] );

		return $clean;
	}

	/**
	 * Sanitizes اعلان‌ها tab fields.
	 *
	 * @param  array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_notifications( array $raw ): array {
		$clean = [];

		$clean['notification_sender_name'] = sanitize_text_field( $raw['notification_sender_name'] ?? '' );
		$clean['telegram_admin_chat_id']   = sanitize_text_field( $raw['telegram_admin_chat_id']   ?? '' );
		$clean['notify_admin_on_booking']  = ! empty( $raw['notify_admin_on_booking'] );

		$events = self::notification_events();
		$templates = [];
		foreach ( array_keys( $events ) as $event_key ) {
			$templates[ $event_key ] = [
				'sms_enabled'   => ! empty( $raw['notif'][ $event_key ]['sms_enabled'] ),
				'email_enabled' => ! empty( $raw['notif'][ $event_key ]['email_enabled'] ),
				'sms_body'      => wp_kses_post( $raw['notif'][ $event_key ]['sms_body']   ?? '' ),
				'email_subject' => sanitize_text_field( $raw['notif'][ $event_key ]['email_subject'] ?? '' ),
				'email_body'    => wp_kses_post( $raw['notif'][ $event_key ]['email_body'] ?? '' ),
			];
		}
		$clean['notification_templates'] = $templates;

		return $clean;
	}

	/**
	 * Sanitizes پیشرفته tab fields.
	 *
	 * @param  array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_advanced( array $raw ): array {
		$clean = [];

		$clean['myaccount_page_id']       = max( 0, (int) ( $raw['myaccount_page_id']       ?? 0 ) );
		$clean['certificate_verify_page_id'] = max( 0, (int) ( $raw['certificate_verify_page_id'] ?? 0 ) );
		$clean['delete_data_on_uninstall'] = ! empty( $raw['delete_data_on_uninstall'] );

		return $clean;
	}

	// ─── Render ───────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$saved      = ! empty( $_GET['csb_saved'] );
		$flushed    = ! empty( $_GET['csb_flushed'] );
		// phpcs:enable

		$settings = SettingsPage::get();

		$tabs = [
			'general'       => __( 'عمومی', 'csb' ),
			'booking'       => __( 'رزرو و بیعانه', 'csb' ),
			'payment'       => __( 'پرداخت', 'csb' ),
			'accounts'      => __( 'حساب‌ها', 'csb' ),
			'notifications' => __( 'اعلان‌ها', 'csb' ),
			'advanced'      => __( 'پیشرفته', 'csb' ),
			'appearance'    => __( 'ظاهر', 'csb' ),
		];

		?>
		<div class="wrap csb-settings-hub" dir="rtl">
			<h1><?php esc_html_e( 'تنظیمات آموزشگاه', 'csb' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'csb' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $flushed ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'قوانین بازنویسی URL فلاش شدند.', 'csb' ); ?></p>
				</div>
			<?php endif; ?>

			<?php $this->render_styles(); ?>

			<!-- Tab nav -->
			<nav class="csb-hub-nav">
				<?php foreach ( $tabs as $slug => $label ) :
					$url = add_query_arg( [ 'page' => 'csb-settings', 'tab' => $slug ], admin_url( 'admin.php' ) );
					$cls = $slug === $active_tab ? ' csb-hub-nav__item--active' : '';
				?>
					<a href="<?php echo esc_url( $url ); ?>"
					   class="csb-hub-nav__item<?php echo esc_attr( $cls ); ?>"
					   <?php if ( $slug === $active_tab ) : ?>aria-current="page"<?php endif; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="csb-hub-body">
			<?php
			// Appearance tab is just a redirect link.
			if ( 'appearance' === $active_tab ) {
				$this->render_appearance_link();
				echo '</div></div>';
				return;
			}

			$action_url = esc_url( admin_url( 'admin-post.php' ) );
			?>
			<form method="post" action="<?php echo $action_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="action" value="csb_hub_save">
				<input type="hidden" name="csb_active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<?php
				match ( $active_tab ) {
					'general'       => $this->render_tab_general( $settings ),
					'booking'       => $this->render_tab_booking( $settings ),
					'payment'       => $this->render_tab_payment( $settings ),
					'accounts'      => $this->render_tab_accounts( $settings ),
					'notifications' => $this->render_tab_notifications( $settings ),
					'advanced'      => $this->render_tab_advanced( $settings ),
					default         => null,
				};
				?>

				<?php submit_button( esc_html__( 'ذخیره تنظیمات', 'csb' ) ); ?>
			</form>
			</div>
		</div>
		<?php
	}

	// ─── Tab: عمومی ──────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $s Settings array.
	 */
	private function render_tab_general( array $s ): void {
		$wc_currency      = get_option( 'woocommerce_currency', 'IRT' );
		$wc_pos           = get_option( 'woocommerce_currency_pos', 'right' );
		$wc_thousand      = get_option( 'woocommerce_price_thousand_sep', ',' );
		$wc_decimal       = get_option( 'woocommerce_price_decimal_sep', '.' );
		$wc_decimals      = (int) get_option( 'woocommerce_price_num_decimals', 2 );
		$wc_country       = get_option( 'woocommerce_default_country', 'IR' );
		$all_currencies   = get_woocommerce_currencies();

		// Put IRT / IRR at the top for convenience.
		$priority = [ 'IRT', 'IRR', 'USD', 'EUR' ];
		$sorted   = [];
		foreach ( $priority as $code ) {
			if ( isset( $all_currencies[ $code ] ) ) {
				$sorted[ $code ] = $all_currencies[ $code ];
			}
		}
		foreach ( $all_currencies as $code => $name ) {
			if ( ! isset( $sorted[ $code ] ) ) {
				$sorted[ $code ] = $name;
			}
		}

		$terms_page_id = (int) ( $s['terms_page_id'] ?? 0 );
		?>
		<h2 class="csb-tab-title"><?php esc_html_e( 'تنظیمات عمومی', 'csb' ); ?></h2>

		<!-- Currency section -->
		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'ارز و قیمت‌گذاری', 'csb' ); ?></h3>
			<p class="csb-section__desc">
				<?php esc_html_e( 'این مقادیر مستقیماً در WooCommerce ذخیره می‌شوند و بر تمام قیمت‌های سایت تأثیر می‌گذارند.', 'csb' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="csb_wc_currency"><?php esc_html_e( 'واحد پول', 'csb' ); ?></label>
					</th>
					<td>
						<select id="csb_wc_currency"
						        name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_currency]"
						        style="min-width:280px;">
							<?php foreach ( $sorted as $code => $name ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"
								        <?php selected( $wc_currency, $code ); ?>>
									<?php
									$symbol = get_woocommerce_currency_symbol( $code );
									echo esc_html( $name . ' (' . $symbol . ') — ' . $code );
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_wc_currency_pos"><?php esc_html_e( 'موقعیت نماد ارز', 'csb' ); ?></label>
					</th>
					<td>
						<select id="csb_wc_currency_pos"
						        name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_currency_pos]">
							<option value="left"        <?php selected( $wc_pos, 'left' ); ?>><?php esc_html_e( 'چپ (£99)', 'csb' ); ?></option>
							<option value="right"       <?php selected( $wc_pos, 'right' ); ?>><?php esc_html_e( 'راست (99£)', 'csb' ); ?></option>
							<option value="left_space"  <?php selected( $wc_pos, 'left_space' ); ?>><?php esc_html_e( 'چپ با فاصله (£ 99)', 'csb' ); ?></option>
							<option value="right_space" <?php selected( $wc_pos, 'right_space' ); ?>><?php esc_html_e( 'راست با فاصله (99 £)', 'csb' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_wc_thousand_sep"><?php esc_html_e( 'جداکننده هزارگان', 'csb' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="csb_wc_thousand_sep"
						       name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_price_thousand_sep]"
						       value="<?php echo esc_attr( $wc_thousand ); ?>"
						       class="small-text" maxlength="5" />
						<p class="description"><?php esc_html_e( 'مثال: , یا . یا فاصله', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_wc_decimal_sep"><?php esc_html_e( 'جداکننده اعشار', 'csb' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="csb_wc_decimal_sep"
						       name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_price_decimal_sep]"
						       value="<?php echo esc_attr( $wc_decimal ); ?>"
						       class="small-text" maxlength="5" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_wc_decimals"><?php esc_html_e( 'تعداد ارقام اعشار', 'csb' ); ?></label>
					</th>
					<td>
						<input type="number"
						       id="csb_wc_decimals"
						       name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_price_num_decimals]"
						       value="<?php echo esc_attr( (string) $wc_decimals ); ?>"
						       min="0" max="6" class="small-text" />
					</td>
				</tr>
			</table>
		</div>

		<!-- General options section -->
		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'گزینه‌های عمومی', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="csb_terms_page_id"><?php esc_html_e( 'صفحه قوانین و شرایط', 'csb' ); ?></label>
					</th>
					<td>
						<?php
						wp_dropdown_pages( [
							'name'              => esc_attr( self::OPT ) . '[terms_page_id]',
							'id'                => 'csb_terms_page_id',
							'selected'          => $terms_page_id,
							'show_option_none'  => __( '— انتخاب کنید —', 'csb' ),
							'option_none_value' => '0',
						] );
						?>
						<p class="description"><?php esc_html_e( 'در صفحه پرداخت به هنرجویان نمایش داده می‌شود.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_wc_default_country"><?php esc_html_e( 'کشور پیش‌فرض', 'csb' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="csb_wc_default_country"
						       name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_default_country]"
						       value="<?php echo esc_attr( $wc_country ); ?>"
						       class="regular-text" placeholder="IR" />
						<p class="description"><?php esc_html_e( 'کد ISO کشور (مثلاً IR برای ایران). برای نرخ مالیات WooCommerce استفاده می‌شود.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_video_host"><?php esc_html_e( 'هاست ویدیو', 'csb' ); ?></label>
					</th>
					<td>
						<select id="csb_video_host"
						        name="<?php echo esc_attr( self::OPT ); ?>[video_host]">
							<option value="local"   <?php selected( $s['video_host'] ?? 'local', 'local' ); ?>><?php esc_html_e( 'محلی (محافظت‌شده)', 'csb' ); ?></option>
							<option value="arvan"   <?php selected( $s['video_host'] ?? 'local', 'arvan' ); ?>><?php esc_html_e( 'آروان‌کلاد', 'csb' ); ?></option>
							<option value="tamasha" <?php selected( $s['video_host'] ?? 'local', 'tamasha' ); ?>><?php esc_html_e( 'تماشا', 'csb' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'کیف‌پول دیجیتال', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[enable_wallet]"
							       value="1"
							       <?php checked( $s['enable_wallet'] ?? true ); ?> />
							<?php esc_html_e( 'فعال‌سازی کیف‌پول برای بازگشت وجه به‌صورت اعتبار', 'csb' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'فروش محصول فیزیکی', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[enable_ecommerce]"
							       value="1"
							       <?php checked( $s['enable_ecommerce'] ?? false ); ?> />
							<?php esc_html_e( 'نمایش محصولات فیزیکی WooCommerce در کنار دوره‌ها', 'csb' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	// ─── Tab: رزرو و بیعانه ──────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $s
	 */
	private function render_tab_booking( array $s ): void {
		$reminder_raw = implode( ', ', (array) ( $s['reminder_days'] ?? [ 7, 3, 1 ] ) );
		$tiers_json   = wp_json_encode( $s['cancellation_tiers'] ?? [] );
		?>
		<h2 class="csb-tab-title"><?php esc_html_e( 'رزرو و بیعانه', 'csb' ); ?></h2>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'تنظیمات بیعانه', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="csb_deposit_pct"><?php esc_html_e( 'درصد بیعانه پیش‌فرض', 'csb' ); ?></label>
					</th>
					<td>
						<input type="number"
						       id="csb_deposit_pct"
						       name="<?php echo esc_attr( self::OPT ); ?>[deposit_default_percent]"
						       value="<?php echo esc_attr( (string) ( $s['deposit_default_percent'] ?? 30 ) ); ?>"
						       min="1" max="100" class="small-text" /> %
						<p class="description"><?php esc_html_e( 'هنرجو این درصد را در هنگام رزرو می‌پردازد؛ مانده تا قبل از کلاس.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_settle_days"><?php esc_html_e( 'مهلت تسویه (روز قبل از کلاس)', 'csb' ); ?></label>
					</th>
					<td>
						<input type="number"
						       id="csb_settle_days"
						       name="<?php echo esc_attr( self::OPT ); ?>[settle_days_default]"
						       value="<?php echo esc_attr( (string) ( $s['settle_days_default'] ?? 3 ) ); ?>"
						       min="0" max="30" class="small-text" />
						<p class="description"><?php esc_html_e( 'هنرجو باید حداقل این تعداد روز قبل از شروع کلاس تسویه کند.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_reminder_days"><?php esc_html_e( 'روزهای یادآوری تسویه', 'csb' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="csb_reminder_days"
						       name="<?php echo esc_attr( self::OPT ); ?>[reminder_days_raw]"
						       value="<?php echo esc_attr( $reminder_raw ); ?>"
						       class="regular-text" />
						<p class="description"><?php esc_html_e( 'اعداد را با کاما جدا کنید. مثال: 7, 3, 1', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_min_to_run"><?php esc_html_e( 'بازه بررسی حدنصاب (ساعت قبل از کلاس)', 'csb' ); ?></label>
					</th>
					<td>
						<input type="number"
						       id="csb_min_to_run"
						       name="<?php echo esc_attr( self::OPT ); ?>[min_to_run_hours]"
						       value="<?php echo esc_attr( (string) ( $s['min_to_run_hours'] ?? 24 ) ); ?>"
						       min="1" max="168" class="small-text" />
						<p class="description"><?php esc_html_e( 'اگر در این تعداد ساعت قبل از شروع جلسه حدنصاب تأمین نشود، جلسه لغو می‌شود.', 'csb' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'سیاست لغو رزرو', 'csb' ); ?></h3>
			<p class="description" style="margin-bottom:.8em;">
				<?php esc_html_e( 'تیرهای لغو را به صورت JSON وارد کنید. مثال: [{"hours_before":48,"fee_percent":0},{"hours_before":24,"fee_percent":50},{"hours_before":0,"fee_percent":100}]', 'csb' ); ?>
			</p>
			<textarea id="csb_cancel_tiers"
			          name="<?php echo esc_attr( self::OPT ); ?>[cancellation_tiers]"
			          rows="5"
			          class="large-text code"><?php echo esc_textarea( $tiers_json ); ?></textarea>
			<p class="description">
				<?php esc_html_e( 'هر tier: hours_before = ساعت قبل از شروع جلسه | fee_percent = درصد جریمه لغو (0 = رایگان، 100 = بدون استرداد).', 'csb' ); ?>
			</p>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'لیست انتظار', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'فعال‌سازی لیست انتظار', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[enable_waitlist]"
							       value="1"
							       <?php checked( ! empty( $s['enable_waitlist'] ) ); ?> />
							<?php esc_html_e( 'وقتی ظرفیت کلاس پر شد، هنرجویان می‌توانند در لیست انتظار ثبت‌نام کنند.', 'csb' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	// ─── Tab: پرداخت ─────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $s
	 */
	private function render_tab_payment( array $s ): void {
		$allowed = (array) ( $s['allowed_gateways'] ?? [] );
		$gateways = [];
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
		}
		$wc_payment_url = admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		?>
		<h2 class="csb-tab-title"><?php esc_html_e( 'درگاه‌های پرداخت', 'csb' ); ?></h2>

		<div class="csb-section">
			<p>
				<?php
				printf(
					/* translators: %s: link to WooCommerce payment settings */
					wp_kses_post( __( 'برای پیکربندی درگاه‌ها (کلیدها، رمزها، آزمون/واقعی) به <a href="%s">تنظیمات پرداخت WooCommerce</a> بروید.', 'csb' ) ),
					esc_url( $wc_payment_url )
				);
				?>
			</p>

			<?php if ( empty( $gateways ) ) : ?>
				<p class="description"><?php esc_html_e( 'هیچ درگاه پرداختی فعال نیست.', 'csb' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:700px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'درگاه', 'csb' ); ?></th>
							<th><?php esc_html_e( 'وضعیت (WC)', 'csb' ); ?></th>
							<th><?php esc_html_e( 'مجاز برای ثبت‌نام', 'csb' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $gateways as $gw ) :
						$is_enabled = 'yes' === $gw->enabled;
						$is_allowed = in_array( $gw->id, $allowed, true );
					?>
						<tr>
							<td>
								<strong><?php echo esc_html( $gw->get_title() ); ?></strong>
								<br><small><?php echo esc_html( $gw->id ); ?></small>
							</td>
							<td>
								<?php if ( $is_enabled ) : ?>
									<span style="color:#27ae60;">&#10003; <?php esc_html_e( 'فعال', 'csb' ); ?></span>
								<?php else : ?>
									<span style="color:#c0392b;">&#10007; <?php esc_html_e( 'غیرفعال', 'csb' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<label>
									<input type="checkbox"
									       name="<?php echo esc_attr( self::OPT ); ?>[allowed_gateways][]"
									       value="<?php echo esc_attr( $gw->id ); ?>"
									       <?php checked( $is_allowed ); ?> />
									<?php esc_html_e( 'مجاز', 'csb' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:.6em;">
					<?php esc_html_e( 'اگر هیچ‌کدام انتخاب نشود، همه درگاه‌های فعال مجاز خواهند بود.', 'csb' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ─── Tab: حساب‌ها ─────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $s
	 */
	private function render_tab_accounts( array $s ): void {
		$guest_checkout = get_option( 'woocommerce_enable_guest_checkout', 'yes' );
		$auto_create    = get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
		?>
		<h2 class="csb-tab-title"><?php esc_html_e( 'حساب‌ها', 'csb' ); ?></h2>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'گزینه‌های WooCommerce', 'csb' ); ?></h3>
			<p class="description" style="margin-bottom:.8em;">
				<?php esc_html_e( 'این گزینه‌ها در WooCommerce ذخیره می‌شوند و بر کل فروشگاه تأثیر دارند.', 'csb' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'پرداخت مهمان', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_enable_guest_checkout]"
							       value="1"
							       <?php checked( 'yes', $guest_checkout ); ?> />
							<?php esc_html_e( 'اجازه پرداخت بدون حساب کاربری (مهمان)', 'csb' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'ساخت خودکار حساب', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[woocommerce_enable_signup_and_login_from_checkout]"
							       value="1"
							       <?php checked( 'yes', $auto_create ); ?> />
							<?php esc_html_e( 'اگر ایمیل هنرجو وجود ندارد، هنگام پرداخت حساب بسازد', 'csb' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'گزینه‌های آموزشگاه', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'ورود با OTP (موبایل)', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[otp_login_enabled]"
							       value="1"
							       <?php checked( ! empty( $s['otp_login_enabled'] ) ); ?> />
							<?php esc_html_e( 'فعال‌سازی ورود از طریق کد یکبارمصرف SMS', 'csb' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'ثبت‌نام نیازمند حساب', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[enroll_requires_login]"
							       value="1"
							       <?php checked( ! empty( $s['enroll_requires_login'] ) ); ?> />
							<?php esc_html_e( 'هنرجو برای ثبت‌نام در دوره باید وارد شده باشد', 'csb' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	// ─── Tab: اعلان‌ها ────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $s
	 */
	private function render_tab_notifications( array $s ): void {
		$templates     = (array) ( $s['notification_templates'] ?? [] );
		$events        = self::notification_events();
		$sender_name   = esc_attr( (string) ( $s['notification_sender_name'] ?? '' ) );
		$tg_chat_id    = esc_attr( (string) ( $s['telegram_admin_chat_id']   ?? '' ) );
		$admin_notify  = ! empty( $s['notify_admin_on_booking'] );
		?>
		<h2 class="csb-tab-title"><?php esc_html_e( 'اعلان‌ها', 'csb' ); ?></h2>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'تنظیمات فرستنده', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="csb_sender_name"><?php esc_html_e( 'نام فرستنده SMS', 'csb' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="csb_sender_name"
						       name="<?php echo esc_attr( self::OPT ); ?>[notification_sender_name]"
						       value="<?php echo $sender_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
						       class="regular-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_tg_chat_id"><?php esc_html_e( 'Telegram Chat ID (مدیر)', 'csb' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="csb_tg_chat_id"
						       name="<?php echo esc_attr( self::OPT ); ?>[telegram_admin_chat_id]"
						       value="<?php echo $tg_chat_id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
						       class="regular-text" placeholder="-1001234567890" />
						<p class="description"><?php esc_html_e( 'اطلاعیه‌های جدید رزرو/لغو به این chat ارسال می‌شود.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'اطلاع‌رسانی رزرو جدید به مدیر', 'csb' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[notify_admin_on_booking]"
							       value="1"
							       <?php checked( $admin_notify ); ?> />
							<?php esc_html_e( 'هنگام ثبت رزرو جدید مدیر را مطلع کن', 'csb' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'قالب‌های پیام', 'csb' ); ?></h3>
			<p class="description" style="margin-bottom:1em;">
				<?php esc_html_e( 'متغیرهای قابل استفاده: {name} نام هنرجو، {class} نام کلاس، {date} تاریخ جلسه، {amount} مبلغ، {code} کد OTP.', 'csb' ); ?>
			</p>

			<?php foreach ( $events as $event_key => $event_label ) :
				$tpl = isset( $templates[ $event_key ] ) && is_array( $templates[ $event_key ] )
					? $templates[ $event_key ]
					: [];
				$sms_enabled   = ! empty( $tpl['sms_enabled'] );
				$email_enabled = ! empty( $tpl['email_enabled'] );
				$sms_body      = isset( $tpl['sms_body'] )      ? wp_kses_post( $tpl['sms_body'] )          : '';
				$email_subject = isset( $tpl['email_subject'] ) ? esc_attr( $tpl['email_subject'] )          : '';
				$email_body    = isset( $tpl['email_body'] )    ? wp_kses_post( $tpl['email_body'] )         : '';
				$base          = esc_attr( self::OPT ) . '[notif][' . esc_attr( $event_key ) . ']';
			?>
			<div class="csb-notif-block" style="border:1px solid #ddd;padding:1em 1.2em;margin-bottom:1em;border-radius:4px;">
				<h4 style="margin:0 0 .8em;font-size:13px;"><?php echo esc_html( $event_label ); ?></h4>
				<table class="form-table" style="margin:0;" role="presentation">
					<tr>
						<th style="width:180px;padding-top:0;"><?php esc_html_e( 'ارسال SMS', 'csb' ); ?></th>
						<td style="padding-top:0;">
							<label>
								<input type="checkbox"
								       name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[sms_enabled]"
								       value="1"
								       <?php checked( $sms_enabled ); ?> />
								<?php esc_html_e( 'فعال', 'csb' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'متن SMS', 'csb' ); ?></th>
						<td>
							<textarea name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[sms_body]"
							          rows="2" class="large-text"><?php echo esc_textarea( $sms_body ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'ارسال ایمیل', 'csb' ); ?></th>
						<td>
							<label>
								<input type="checkbox"
								       name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[email_enabled]"
								       value="1"
								       <?php checked( $email_enabled ); ?> />
								<?php esc_html_e( 'فعال', 'csb' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'موضوع ایمیل', 'csb' ); ?></th>
						<td>
							<input type="text"
							       name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[email_subject]"
							       value="<?php echo $email_subject; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
							       class="large-text" />
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'متن ایمیل', 'csb' ); ?></th>
						<td>
							<textarea name="<?php echo $base; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>[email_body]"
							          rows="3" class="large-text"><?php echo esc_textarea( $email_body ); ?></textarea>
						</td>
					</tr>
				</table>
			</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	// ─── Tab: پیشرفته ────────────────────────────────────────────────────────

	/**
	 * @param array<string,mixed> $s
	 */
	private function render_tab_advanced( array $s ): void {
		$myaccount_page_id  = (int) ( $s['myaccount_page_id']          ?? (int) get_option( 'woocommerce_myaccount_page_id', 0 ) );
		$cert_page_id       = (int) ( $s['certificate_verify_page_id'] ?? 0 );
		$del_data           = ! empty( $s['delete_data_on_uninstall'] );
		$rest_ns            = 'csb/v1';

		$cron_hooks = [
			'csb_release_locks'    => __( 'آزاد کردن قفل‌های صندلی منقضی', 'csb' ),
			'csb_check_min_to_run' => __( 'بررسی حدنصاب جلسات', 'csb' ),
			'csb_send_reminders'   => __( 'ارسال یادآوری تسویه / کلاس فردا', 'csb' ),
			'csb_expire_unsettled' => __( 'انقضای رزروهای تسویه‌نشده', 'csb' ),
			'csb_mark_completed'   => __( 'تکمیل خودکار رزروها پس از جلسه', 'csb' ),
			'csb_purge_otp'        => __( 'پاک‌سازی توکن‌های OTP منقضی', 'csb' ),
		];
		?>
		<h2 class="csb-tab-title"><?php esc_html_e( 'تنظیمات پیشرفته', 'csb' ); ?></h2>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'صفحات', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="csb_myaccount_page"><?php esc_html_e( 'صفحه «حساب من»', 'csb' ); ?></label>
					</th>
					<td>
						<?php
						wp_dropdown_pages( [
							'name'              => esc_attr( self::OPT ) . '[myaccount_page_id]',
							'id'                => 'csb_myaccount_page',
							'selected'          => $myaccount_page_id,
							'show_option_none'  => __( '— انتخاب کنید —', 'csb' ),
							'option_none_value' => '0',
						] );
						?>
						<p class="description"><?php esc_html_e( 'در صورت خالی بودن، از صفحه My Account ووکامرس استفاده می‌شود.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="csb_cert_verify_page"><?php esc_html_e( 'صفحه تأیید گواهینامه', 'csb' ); ?></label>
					</th>
					<td>
						<?php
						wp_dropdown_pages( [
							'name'              => esc_attr( self::OPT ) . '[certificate_verify_page_id]',
							'id'                => 'csb_cert_verify_page',
							'selected'          => $cert_page_id,
							'show_option_none'  => __( '— انتخاب کنید —', 'csb' ),
							'option_none_value' => '0',
						] );
						?>
					</td>
				</tr>
			</table>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'وضعیت کران‌جاب‌ها', 'csb' ); ?></h3>
			<table class="widefat striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'رویداد', 'csb' ); ?></th>
						<th><?php esc_html_e( 'توضیح', 'csb' ); ?></th>
						<th><?php esc_html_e( 'اجرای بعدی', 'csb' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $cron_hooks as $hook => $label ) :
					$next = wp_next_scheduled( $hook );
				?>
					<tr>
						<td><code><?php echo esc_html( $hook ); ?></code></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td>
							<?php if ( $next ) : ?>
								<?php
								$diff    = $next - time();
								$minutes = max( 0, (int) round( $diff / 60 ) );
								echo esc_html(
									sprintf(
										/* translators: %d: minutes from now */
										__( 'تا %d دقیقه دیگر', 'csb' ),
										$minutes
									)
								);
								?>
							<?php else : ?>
								<span style="color:#c0392b;"><?php esc_html_e( 'زمان‌بندی نشده', 'csb' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'REST API', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'فضای‌نام REST', 'csb' ); ?></th>
					<td>
						<code><?php echo esc_html( rest_url( $rest_ns ) ); ?></code>
					</td>
				</tr>
			</table>
		</div>

		<div class="csb-section">
			<h3 class="csb-section__title"><?php esc_html_e( 'ابزارها', 'csb' ); ?></h3>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'فلاش قوانین بازنویسی URL', 'csb' ); ?></th>
					<td>
						<form method="post"
						      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						      style="display:inline;">
							<?php wp_nonce_field( 'csb_flush_rewrites', '_csb_flush_nonce' ); ?>
							<input type="hidden" name="action" value="csb_hub_flush_rewrites">
							<button type="submit" class="button button-secondary">
								<?php esc_html_e( 'فلاش Rewrite Rules', 'csb' ); ?>
							</button>
						</form>
						<p class="description"><?php esc_html_e( 'اگر آدرس‌های کلاس یا دوره ویدیویی 404 می‌دهند این را اجرا کنید.', 'csb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row" style="color:#c62d2d;">
						<?php esc_html_e( 'حذف داده‌ها هنگام حذف افزونه', 'csb' ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( self::OPT ); ?>[delete_data_on_uninstall]"
							       value="1"
							       <?php checked( $del_data ); ?> />
							<?php esc_html_e( 'هنگام حذف افزونه، تمام جدول‌ها و تنظیمات پاک شوند.', 'csb' ); ?>
						</label>
						<p class="description" style="color:#c62d2d;">
							<?php esc_html_e( 'هشدار: این عمل برگشت‌پذیر نیست.', 'csb' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	// ─── Tab: ظاهر (link) ─────────────────────────────────────────────────────

	private function render_appearance_link(): void {
		$url = admin_url( 'admin.php?page=csb-appearance' );
		?>
		<div class="csb-section" style="padding:2em;text-align:center;">
			<p style="font-size:15px;margin-bottom:1.2em;">
				<?php esc_html_e( 'تنظیمات رنگ، فونت، کارت‌ها و آرشیو در صفحه ظاهر قرار دارند.', 'csb' ); ?>
			</p>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-primary button-large">
				<?php esc_html_e( 'رفتن به تنظیمات ظاهر', 'csb' ); ?>
			</a>
		</div>
		<?php
	}

	// ─── Shared: notification event registry ─────────────────────────────────

	/**
	 * Returns the canonical list of notification events.
	 *
	 * @return array<string,string> event_key => Persian label
	 */
	public static function notification_events(): array {
		return [
			'otp'                    => __( 'کد یکبارمصرف (OTP)', 'csb' ),
			'booking_confirmed'      => __( 'تأیید رزرو', 'csb' ),
			'deposit_received'       => __( 'دریافت بیعانه', 'csb' ),
			'settle_reminder'        => __( 'یادآوری تسویه مانده', 'csb' ),
			'settle_final'           => __( 'آخرین یادآوری تسویه (فوری)', 'csb' ),
			'class_tomorrow'         => __( 'یادآوری کلاس فردا', 'csb' ),
			'cancelled_refund'       => __( 'لغو رزرو با استرداد وجه', 'csb' ),
			'session_cancelled'      => __( 'لغو جلسه توسط آموزشگاه', 'csb' ),
		];
	}

	// ─── Inline styles ────────────────────────────────────────────────────────

	private function render_styles(): void {
		?>
		<style>
		.csb-settings-hub{max-width:1000px;}
		.csb-hub-nav{display:flex;flex-wrap:wrap;border-bottom:2px solid #c3c4c7;margin:1em 0 0;gap:0;}
		.csb-hub-nav__item{display:inline-block;padding:.55em 1.3em;text-decoration:none;color:#50575e;font-size:13px;border:2px solid transparent;border-bottom:none;margin-bottom:-2px;background:none;transition:color .15s;}
		.csb-hub-nav__item:hover{color:#1d2327;}
		.csb-hub-nav__item--active{background:#fff;border-color:#c3c4c7;border-bottom-color:#fff;color:#1d2327;font-weight:600;}
		.csb-hub-body{background:#fff;border:1px solid #c3c4c7;border-top:none;padding:1.5em 2em 2em;}
		.csb-tab-title{font-size:1.2em;margin-bottom:1em;padding-bottom:.4em;border-bottom:1px solid #eee;}
		.csb-section{margin-bottom:2em;}
		.csb-section__title{font-size:1em;color:#50575e;border-bottom:1px solid #f0f0f0;padding-bottom:.3em;margin-bottom:.8em;}
		.csb-section__desc{color:#646970;margin-bottom:.5em;font-size:13px;}
		</style>
		<?php
	}
}
