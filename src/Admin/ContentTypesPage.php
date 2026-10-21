<?php
/**
 * «انواع محتوا» admin control panel — §16.5.
 *
 * Submenu under «آموزشگاه → انواع محتوا».
 *
 * Stores:
 *   - `csb_cpt_config`  — type/taxonomy labels, bases, enabled flags.
 *   - `csb_seo_config`  — per-type SEO templates, schema toggles, sitemap, robots.
 *
 * On URL-base change:
 *   1. Records old base in `csb_slug_redirects` (via RedirectManager).
 *   2. Calls Rewrites::flush() to defer flush_rewrite_rules().
 *   3. Logs to audit via AuditLogger.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

use CookingSchoolBooking\Frontend\Rewrites;
use CookingSchoolBooking\Products\ProductTypes;
use CookingSchoolBooking\Services\AuditLogger;
use CookingSchoolBooking\Support\RedirectManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ContentTypesPage
 */
class ContentTypesPage {

	private const CPT_OPTION = 'csb_cpt_config';
	private const SEO_OPTION = 'csb_seo_config';

	/**
	 * Attaches hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
		add_action( 'admin_post_csb_save_content_types', [ $this, 'handle_save' ] );
	}

	// ── Menu ─────────────────────────────────────────────────────────────────

	public function register_submenu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'انواع محتوا', 'csb' ),
			esc_html__( 'انواع محتوا', 'csb' ),
			'manage_woocommerce',
			'csb-content-types',
			[ $this, 'render' ]
		);
	}

	// ── Render ───────────────────────────────────────────────────────────────

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		$config     = ProductTypes::get_config();
		$seo_config = (array) get_option( self::SEO_OPTION, [] );
		$updated    = isset( $_GET['updated'] ) && '1' === sanitize_key( $_GET['updated'] );
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'انواع محتوا و تنظیمات سئو', 'csb' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'تنظیمات ذخیره شد.', 'csb' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'csb_content_types_save', '_csb_ct_nonce' ); ?>
				<input type="hidden" name="action" value="csb_save_content_types" />

				<h2><?php esc_html_e( 'نوع‌های محتوا', 'csb' ); ?></h2>
				<?php foreach ( $config['types'] as $type_key => $type_cfg ) : ?>
					<?php $this->render_type_section( $type_key, $type_cfg, $seo_config ); ?>
				<?php endforeach; ?>

				<h2 style="margin-top:2em;"><?php esc_html_e( 'Taxonomy‌ها', 'csb' ); ?></h2>
				<?php foreach ( $config['taxonomies'] as $tax_key => $tax_cfg ) : ?>
					<?php $this->render_taxonomy_section( $tax_key, $tax_cfg ); ?>
				<?php endforeach; ?>

				<?php $this->render_new_taxonomy_form(); ?>
				<?php $this->render_seo_switcher( $seo_config ); ?>

				<?php submit_button( esc_html__( 'ذخیره تنظیمات', 'csb' ) ); ?>
			</form>
		</div>
		<?php
	}

	// ── Section renderers ─────────────────────────────────────────────────────

	/**
	 * @param string              $key
	 * @param array<string,mixed> $cfg
	 * @param array<string,mixed> $seo
	 */
	private function render_type_section( string $key, array $cfg, array $seo ): void {
		$sk  = 'type_' . $key;
		$ts  = $seo[ $sk ] ?? [];
		?>
		<div class="postbox" style="margin-bottom:14px;">
			<div class="postbox-header">
				<h2 class="hndle" style="padding:10px 16px;">
					<?php echo esc_html( $cfg['label_singular'] ?? $key ); ?>
					<small style="color:#777;font-size:11px;">(key: <?php echo esc_html( $key ); ?>)</small>
				</h2>
			</div>
			<div class="inside">
				<table class="form-table" role="presentation">
					<tr>
						<th><label><?php esc_html_e( 'برچسب مفرد', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_types[<?php echo esc_attr( $key ); ?>][label_singular]" value="<?php echo esc_attr( $cfg['label_singular'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'برچسب جمع', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_types[<?php echo esc_attr( $key ); ?>][label_plural]" value="<?php echo esc_attr( $cfg['label_plural'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'URL Base (پایه آدرس)', 'csb' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" dir="ltr"
								name="csb_types[<?php echo esc_attr( $key ); ?>][classes_base]"
								value="<?php echo esc_attr( $cfg['classes_base'] ?? $key ); ?>" />
							<p class="description" style="color:#b00;">
								<?php esc_html_e( 'تغییر این مقدار URL را عوض می‌کند. ریدایرکت ۳۰۱ خودکار اعمال می‌شود.', 'csb' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'دارای آرشیو', 'csb' ); ?></th>
						<td><label><input type="checkbox" name="csb_types[<?php echo esc_attr( $key ); ?>][has_archive]" value="1" <?php checked( ! empty( $cfg['has_archive'] ) ); ?> /> <?php esc_html_e( 'بله', 'csb' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'فعال', 'csb' ); ?></th>
						<td><label><input type="checkbox" name="csb_types[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> /> <?php esc_html_e( 'فعال', 'csb' ); ?></label></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'جایگاه منو', 'csb' ); ?></label></th>
						<td><input type="number" min="1" max="100" style="width:70px" name="csb_types[<?php echo esc_attr( $key ); ?>][menu_position]" value="<?php echo esc_attr( (string) ( $cfg['menu_position'] ?? 5 ) ); ?>" /></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'تنظیمات سئو', 'csb' ); ?></h3>
				<table class="form-table" role="presentation">
					<tr>
						<th><label><?php esc_html_e( 'قالب Title', 'csb' ); ?></label></th>
						<td>
							<input type="text" class="large-text" dir="ltr"
								name="csb_seo[<?php echo esc_attr( $sk ); ?>][title_template]"
								value="<?php echo esc_attr( $ts['title_template'] ?? '%title% | %sitename%' ); ?>" />
							<p class="description"><?php esc_html_e( 'توکن‌ها: %title%, %instructor%, %category%, %price%, %sitename%, %sep%', 'csb' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'قالب Meta Description', 'csb' ); ?></label></th>
						<td><textarea class="large-text" dir="ltr" rows="2" name="csb_seo[<?php echo esc_attr( $sk ); ?>][desc_template]"><?php echo esc_textarea( $ts['desc_template'] ?? '%excerpt%' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'توکن‌ها: %excerpt%, %instructor%, %price%', 'csb' ); ?></p></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'تصویر OG پیش‌فرض', 'csb' ); ?></label></th>
						<td><input type="url" class="large-text" dir="ltr" name="csb_seo[<?php echo esc_attr( $sk ); ?>][og_image]" value="<?php echo esc_attr( $ts['og_image'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Schema اختصاصی', 'csb' ); ?></th>
						<td><label><input type="checkbox" name="csb_seo[<?php echo esc_attr( $sk ); ?>][enable_schema]" value="1" <?php checked( $ts['enable_schema'] ?? true ); ?> /> <?php esc_html_e( 'فعال', 'csb' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'نمایه‌سازی', 'csb' ); ?></th>
						<td>
							<select name="csb_seo[<?php echo esc_attr( $sk ); ?>][robots]">
								<option value="index" <?php selected( $ts['robots'] ?? 'index', 'index' ); ?>>index, follow</option>
								<option value="noindex" <?php selected( $ts['robots'] ?? '', 'noindex' ); ?>>noindex, nofollow</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Sitemap', 'csb' ); ?></th>
						<td>
							<label><input type="checkbox" name="csb_seo[<?php echo esc_attr( $sk ); ?>][in_sitemap]" value="1" <?php checked( $ts['in_sitemap'] ?? true ); ?> /> <?php esc_html_e( 'گنجاندن در sitemap', 'csb' ); ?></label>
							&nbsp;
							<label><?php esc_html_e( 'Priority:', 'csb' ); ?>
								<input type="text" size="4" dir="ltr" name="csb_seo[<?php echo esc_attr( $sk ); ?>][sitemap_priority]" value="<?php echo esc_attr( $ts['sitemap_priority'] ?? '0.8' ); ?>" />
							</label>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Breadcrumb Base', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" dir="ltr" name="csb_seo[<?php echo esc_attr( $sk ); ?>][breadcrumb_base]" value="<?php echo esc_attr( $ts['breadcrumb_base'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'عنوان آرشیو', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_seo[<?php echo esc_attr( $sk ); ?>][archive_title]" value="<?php echo esc_attr( $ts['archive_title'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'توضیح آرشیو', 'csb' ); ?></label></th>
						<td><textarea class="regular-text" rows="2" name="csb_seo[<?php echo esc_attr( $sk ); ?>][archive_desc]"><?php echo esc_textarea( $ts['archive_desc'] ?? '' ); ?></textarea></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * @param string              $key
	 * @param array<string,mixed> $cfg
	 */
	private function render_taxonomy_section( string $key, array $cfg ): void {
		?>
		<div class="postbox" style="margin-bottom:10px;">
			<div class="postbox-header">
				<h2 class="hndle" style="padding:10px 16px;">
					<?php echo esc_html( $cfg['label_singular'] ?? $key ); ?>
					<small style="color:#777;font-size:11px;">(<?php echo esc_html( $key ); ?>)</small>
				</h2>
			</div>
			<div class="inside">
				<table class="form-table" role="presentation">
					<tr>
						<th><label><?php esc_html_e( 'برچسب مفرد', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_taxonomies[<?php echo esc_attr( $key ); ?>][label_singular]" value="<?php echo esc_attr( $cfg['label_singular'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'برچسب جمع', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_taxonomies[<?php echo esc_attr( $key ); ?>][label_plural]" value="<?php echo esc_attr( $cfg['label_plural'] ?? '' ); ?>" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Slug', 'csb' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" dir="ltr" name="csb_taxonomies[<?php echo esc_attr( $key ); ?>][slug]" value="<?php echo esc_attr( $cfg['slug'] ?? $key ); ?>" />
							<p class="description" style="color:#b00;"><?php esc_html_e( 'ریدایرکت ۳۰۱ از slug قدیم خودکار اعمال می‌شود.', 'csb' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'سلسله‌مراتبی', 'csb' ); ?></th>
						<td><label><input type="checkbox" name="csb_taxonomies[<?php echo esc_attr( $key ); ?>][hierarchical]" value="1" <?php checked( ! empty( $cfg['hierarchical'] ) ); ?> /> <?php esc_html_e( 'بله', 'csb' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'فعال', 'csb' ); ?></th>
						<td><label><input type="checkbox" name="csb_taxonomies[<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?> /> <?php esc_html_e( 'فعال', 'csb' ); ?></label></td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	private function render_new_taxonomy_form(): void {
		?>
		<div class="postbox" style="margin-bottom:14px;">
			<div class="postbox-header">
				<h2 class="hndle" style="padding:10px 16px;"><?php esc_html_e( '+ افزودن Taxonomy سفارشی جدید', 'csb' ); ?></h2>
			</div>
			<div class="inside">
				<p class="description"><?php esc_html_e( 'مثلاً «سبک آشپزی» یا «مناسبت». کلید باید انگلیسی و بدون فاصله باشد.', 'csb' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th><label><?php esc_html_e( 'کلید (key)', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" dir="ltr" name="csb_new_tax[key]" placeholder="cuisine" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'برچسب مفرد', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_new_tax[label_singular]" placeholder="سبک" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'برچسب جمع', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" name="csb_new_tax[label_plural]" placeholder="سبک‌ها" /></td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'Slug', 'csb' ); ?></label></th>
						<td><input type="text" class="regular-text" dir="ltr" name="csb_new_tax[slug]" placeholder="cooking-style" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'سلسله‌مراتبی', 'csb' ); ?></th>
						<td><label><input type="checkbox" name="csb_new_tax[hierarchical]" value="1" /> <?php esc_html_e( 'بله', 'csb' ); ?></label></td>
					</tr>
				</table>
				<p class="description"><?php esc_html_e( 'در صورت خالی‌بودن «کلید»، taxonomy جدیدی ساخته نمی‌شود.', 'csb' ); ?></p>
			</div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $seo_config */
	private function render_seo_switcher( array $seo_config ): void {
		$manager   = $seo_config['seo_manager'] ?? 'auto';
		$rank_math = class_exists( 'RankMath' );
		$yoast     = defined( 'WPSEO_VERSION' );
		?>
		<div class="postbox" style="margin-bottom:14px;">
			<div class="postbox-header">
				<h2 class="hndle" style="padding:10px 16px;"><?php esc_html_e( 'مدیریت سئو', 'csb' ); ?></h2>
			</div>
			<div class="inside">
				<?php if ( $rank_math ) : ?>
					<p><?php esc_html_e( 'Rank Math شناسایی شد.', 'csb' ); ?></p>
				<?php elseif ( $yoast ) : ?>
					<p><?php esc_html_e( 'Yoast SEO شناسایی شد.', 'csb' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'افزونه‌ی سئوی خارجی فعال نیست.', 'csb' ); ?></p>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'مدیریت meta/title توسط', 'csb' ); ?></th>
						<td>
							<select name="csb_seo[seo_manager]">
								<option value="auto"     <?php selected( $manager, 'auto' ); ?>><?php esc_html_e( 'خودکار (Rank Math/Yoast اگر فعال بود)', 'csb' ); ?></option>
								<option value="plugin"   <?php selected( $manager, 'plugin' ); ?>><?php esc_html_e( 'این افزونه', 'csb' ); ?></option>
								<option value="external" <?php selected( $manager, 'external' ); ?>><?php esc_html_e( 'Rank Math / Yoast (فقط Schema از این افزونه)', 'csb' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<?php
	}

	// ── Save handler ──────────────────────────────────────────────────────────

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		check_admin_referer( 'csb_content_types_save', '_csb_ct_nonce' );

		$current = ProductTypes::get_config();

		// ── Types ──
		$new_types = $current['types'];
		$raw_types = is_array( $_POST['csb_types'] ?? null ) ? $_POST['csb_types'] : [];

		foreach ( $raw_types as $type_key => $values ) {
			if ( ! isset( $current['types'][ $type_key ] ) || ! is_array( $values ) ) {
				continue;
			}

			$old_base = $current['types'][ $type_key ]['classes_base'] ?? $type_key;
			$new_base = sanitize_title( $values['classes_base'] ?? $old_base );

			if ( $old_base !== $new_base ) {
				RedirectManager::record_rename( $old_base, $new_base );
			}

			$new_types[ $type_key ] = array_merge(
				$current['types'][ $type_key ],
				[
					'label_singular' => sanitize_text_field( $values['label_singular'] ?? '' ),
					'label_plural'   => sanitize_text_field( $values['label_plural'] ?? '' ),
					'classes_base'   => $new_base,
					'archive_slug'   => $new_base,
					'has_archive'    => ! empty( $values['has_archive'] ),
					'enabled'        => ! empty( $values['enabled'] ),
					'menu_position'  => max( 1, min( 100, (int) ( $values['menu_position'] ?? 5 ) ) ),
				]
			);
		}

		// ── Taxonomies ──
		$new_taxs = $current['taxonomies'];
		$raw_taxs = is_array( $_POST['csb_taxonomies'] ?? null ) ? $_POST['csb_taxonomies'] : [];

		foreach ( $raw_taxs as $tax_key => $values ) {
			if ( ! isset( $current['taxonomies'][ $tax_key ] ) || ! is_array( $values ) ) {
				continue;
			}

			$old_slug = $current['taxonomies'][ $tax_key ]['slug'] ?? $tax_key;
			$new_slug = sanitize_title( $values['slug'] ?? $old_slug );

			if ( $old_slug !== $new_slug ) {
				RedirectManager::record_rename( $old_slug, $new_slug );
			}

			$new_taxs[ $tax_key ] = array_merge(
				$current['taxonomies'][ $tax_key ],
				[
					'label_singular' => sanitize_text_field( $values['label_singular'] ?? '' ),
					'label_plural'   => sanitize_text_field( $values['label_plural'] ?? '' ),
					'slug'           => $new_slug,
					'hierarchical'   => ! empty( $values['hierarchical'] ),
					'enabled'        => ! empty( $values['enabled'] ),
				]
			);
		}

		// ── New taxonomy ──
		$new_tax = is_array( $_POST['csb_new_tax'] ?? null ) ? $_POST['csb_new_tax'] : [];
		if ( ! empty( $new_tax['key'] ) ) {
			$nk = 'csb_' . sanitize_key( $new_tax['key'] );
			if ( ! isset( $new_taxs[ $nk ] ) && ! taxonomy_exists( $nk ) ) {
				$new_taxs[ $nk ] = [
					'label_singular' => sanitize_text_field( $new_tax['label_singular'] ?? '' ),
					'label_plural'   => sanitize_text_field( $new_tax['label_plural'] ?? '' ),
					'slug'           => sanitize_title( $new_tax['slug'] ?? $nk ),
					'hierarchical'   => ! empty( $new_tax['hierarchical'] ),
					'enabled'        => true,
				];
			}
		}

		// ── Save CPT config ──
		update_option( self::CPT_OPTION, [ 'types' => $new_types, 'taxonomies' => $new_taxs ], false );

		// ── SEO config ──
		$raw_seo    = is_array( $_POST['csb_seo'] ?? null ) ? $_POST['csb_seo'] : [];
		$clean_seo  = $this->sanitize_seo( $raw_seo );
		update_option( self::SEO_OPTION, $clean_seo, false );

		// Flush rewrites deferred to shutdown.
		Rewrites::flush();

		// Audit log.
		AuditLogger::log( 'cpt_config_updated', 'cpt_config', 0, [] );

		wp_safe_redirect( add_query_arg( [ 'page' => 'csb-content-types', 'updated' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// ── Sanitizer ─────────────────────────────────────────────────────────────

	/** @param  array<string,mixed> $input */
	private function sanitize_seo( array $input ): array {
		$clean = [];

		foreach ( $input as $section_key => $section ) {
			if ( 'seo_manager' === $section_key ) {
				$clean['seo_manager'] = in_array( $section, [ 'auto', 'plugin', 'external' ], true )
					? $section : 'auto';
				continue;
			}
			if ( ! is_array( $section ) ) {
				continue;
			}
			$sk = sanitize_key( $section_key );
			$clean[ $sk ] = [
				'title_template'   => sanitize_text_field( $section['title_template'] ?? '%title% | %sitename%' ),
				'desc_template'    => sanitize_textarea_field( $section['desc_template'] ?? '%excerpt%' ),
				'og_image'         => esc_url_raw( $section['og_image'] ?? '' ),
				'enable_schema'    => ! empty( $section['enable_schema'] ),
				'robots'           => in_array( $section['robots'] ?? 'index', [ 'index', 'noindex' ], true ) ? $section['robots'] : 'index',
				'in_sitemap'       => ! empty( $section['in_sitemap'] ),
				'sitemap_priority' => (string) min( 1.0, max( 0.0, (float) ( $section['sitemap_priority'] ?? 0.8 ) ) ),
				'breadcrumb_base'  => sanitize_text_field( $section['breadcrumb_base'] ?? '' ),
				'archive_title'    => sanitize_text_field( $section['archive_title'] ?? '' ),
				'archive_desc'     => sanitize_textarea_field( $section['archive_desc'] ?? '' ),
			];
		}

		return $clean;
	}
}
