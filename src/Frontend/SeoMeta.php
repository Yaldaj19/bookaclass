<?php
/**
 * Title / meta description / OG / canonical / hreflang output.
 *
 * Active ONLY when:
 *  - Neither Rank Math nor Yoast is managing meta (auto detection), OR
 *  - csb_seo_config['seo_manager'] is forced to 'plugin'.
 *
 * When Rank Math / Yoast is present and seo_manager = 'external' (or 'auto'),
 * this class does nothing — Schema.php still runs independently.
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

use CookingSchoolBooking\Products\ClassProduct;
use CookingSchoolBooking\Products\ProductTypes;
use CookingSchoolBooking\Products\VideoCourseProduct;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SeoMeta
 */
class SeoMeta {

	/**
	 * Attaches hooks — only when we should manage meta.
	 */
	public function init(): void {
		if ( ! $this->should_manage() ) {
			return;
		}

		add_filter( 'pre_get_document_title', [ $this, 'filter_title' ], 20 );
		add_action( 'wp_head', [ $this, 'output_head_meta' ], 2 );
		add_action( 'wp_head', [ $this, 'output_archive_meta' ], 2 );
	}

	// ── Title ─────────────────────────────────────────────────────────────────

	/**
	 * Overrides the document title for our product types (singles + archives).
	 * Enforces 50–60 character budget (hard-truncates at 60 with ellipsis).
	 */
	public function filter_title( string $title ): string {
		// Archive page title.
		$type       = (string) get_query_var( 'csb_product_type' );
		$is_archive = '1' === (string) get_query_var( 'csb_archive' );

		if ( '' !== $type && $is_archive ) {
			$type_key      = 'class' === $type ? 'type_class' : 'type_video_course';
			$archive_title = $this->seo_opt( $type_key, 'archive_title', '' );
			if ( $archive_title ) {
				$full = $archive_title . ' | ' . get_bloginfo( 'name' );
				return $this->clamp_title( $full );
			}
			return $title;
		}

		// Single product title.
		if ( ! is_singular( 'product' ) ) {
			return $title;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product || ( ! $product instanceof ClassProduct && ! $product instanceof VideoCourseProduct ) ) {
			return $title;
		}

		$type_key = $product instanceof ClassProduct ? 'type_class' : 'type_video_course';
		$template = $this->seo_opt( $type_key, 'title_template', '%title% | %sitename%' );
		return $this->clamp_title( $this->tokens( $template, $product ) );
	}

	// ── Head meta ─────────────────────────────────────────────────────────────

	/**
	 * Outputs meta description, canonical, OG, Twitter Card, hreflang on singles.
	 */
	public function output_head_meta(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product || ( ! $product instanceof ClassProduct && ! $product instanceof VideoCourseProduct ) ) {
			return;
		}

		$type_key  = $product instanceof ClassProduct ? 'type_class' : 'type_video_course';
		$post_id   = $product->get_id();
		$canonical = get_permalink( $post_id );
		$title     = $this->clamp_title( $this->tokens( $this->seo_opt( $type_key, 'title_template', '%title% | %sitename%' ), $product ) );
		$desc      = $this->clamp_desc( $this->tokens( $this->seo_opt( $type_key, 'desc_template', '%excerpt%' ), $product ) );
		$robots    = $this->seo_opt( $type_key, 'robots', 'index' );

		// OG image: config override → featured image → empty.
		$og_image  = $this->seo_opt( $type_key, 'og_image', '' );
		$thumb_id  = (int) get_post_thumbnail_id( $post_id );
		if ( ! $og_image && $thumb_id ) {
			$og_image_data = wp_get_attachment_image_src( $thumb_id, 'large' );
			$og_image      = $og_image_data ? (string) $og_image_data[0] : '';
		}

		// OG dimensions for featured image.
		$og_image_w = '';
		$og_image_h = '';
		if ( $thumb_id && $og_image ) {
			$og_image_data = wp_get_attachment_image_src( $thumb_id, 'large' );
			if ( $og_image_data ) {
				$og_image_w = (string) $og_image_data[1];
				$og_image_h = (string) $og_image_data[2];
			}
		}

		// og:type — 'website' for course singles (not 'article').
		$og_type = $product instanceof VideoCourseProduct ? 'video.other' : 'website';

		// Remove WP's default canonical (we output ours).
		remove_action( 'wp_head', 'rel_canonical' );
		?>
		<!-- csb: seo meta -->
		<meta name="robots" content="<?php echo 'noindex' === $robots ? 'noindex,nofollow' : 'index,follow'; ?>" />
		<?php if ( $desc ) : ?><meta name="description" content="<?php echo esc_attr( $desc ); ?>" /><?php endif; ?>
		<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:type" content="<?php echo esc_attr( $og_type ); ?>" />
		<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
		<?php if ( $desc ) : ?><meta property="og:description" content="<?php echo esc_attr( $desc ); ?>" /><?php endif; ?>
		<meta property="og:url" content="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
		<?php if ( $og_image ) : ?>
		<meta property="og:image" content="<?php echo esc_url( $og_image ); ?>" />
		<?php if ( $og_image_w ) : ?><meta property="og:image:width" content="<?php echo esc_attr( $og_image_w ); ?>" /><?php endif; ?>
		<?php if ( $og_image_h ) : ?><meta property="og:image:height" content="<?php echo esc_attr( $og_image_h ); ?>" /><?php endif; ?>
		<?php endif; ?>
		<meta name="twitter:card" content="summary_large_image" />
		<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>" />
		<?php if ( $desc ) : ?><meta name="twitter:description" content="<?php echo esc_attr( $desc ); ?>" /><?php endif; ?>
		<?php if ( $og_image ) : ?><meta name="twitter:image" content="<?php echo esc_url( $og_image ); ?>" /><?php endif; ?>
		<link rel="alternate" hreflang="fa" href="<?php echo esc_url( $canonical ); ?>" />
		<link rel="alternate" hreflang="x-default" href="<?php echo esc_url( $canonical ); ?>" />
		<?php
	}

	// ── Archive meta ──────────────────────────────────────────────────────────

	/**
	 * Outputs full head meta for /classes/ and /courses/ archive pages.
	 * Emits: robots, description, canonical, og:type, og:title, og:description,
	 * og:image, og:url, og:site_name, twitter card, hreflang.
	 */
	public function output_archive_meta(): void {
		$type       = (string) get_query_var( 'csb_product_type' );
		$is_archive = '1' === (string) get_query_var( 'csb_archive' );

		if ( '' === $type || ! $is_archive ) {
			return;
		}

		$type_key = 'class' === $type ? 'type_class' : 'type_video_course';

		$archive_title = $this->seo_opt( $type_key, 'archive_title', '' );
		$archive_desc  = $this->seo_opt( $type_key, 'archive_desc', '' );
		$robots        = $this->seo_opt( $type_key, 'archive_robots', 'index' );
		$og_image      = $this->seo_opt( $type_key, 'archive_og_image', '' );

		// Resolve canonical from product type config (single source of truth).
		$config    = ProductTypes::get_config();
		$base      = sanitize_title( $config['types'][ $type ]['classes_base'] ?? ( 'class' === $type ? 'classes' : 'courses' ) );
		$canonical = trailingslashit( home_url( '/' . $base ) );

		$og_title = $archive_title ? $this->clamp_title( $archive_title . ' | ' . get_bloginfo( 'name' ) ) : esc_html( get_bloginfo( 'name' ) );
		$og_desc  = $archive_desc ? $this->clamp_desc( $archive_desc ) : '';

		// Remove WP's default canonical.
		remove_action( 'wp_head', 'rel_canonical' );
		?>
		<!-- csb: archive seo meta -->
		<meta name="robots" content="<?php echo 'noindex' === $robots ? 'noindex,nofollow' : 'index,follow'; ?>" />
		<?php if ( $og_desc ) : ?><meta name="description" content="<?php echo esc_attr( $og_desc ); ?>" /><?php endif; ?>
		<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:type" content="website" />
		<meta property="og:title" content="<?php echo esc_attr( $og_title ); ?>" />
		<?php if ( $og_desc ) : ?><meta property="og:description" content="<?php echo esc_attr( $og_desc ); ?>" /><?php endif; ?>
		<meta property="og:url" content="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
		<?php if ( $og_image ) : ?><meta property="og:image" content="<?php echo esc_url( $og_image ); ?>" /><?php endif; ?>
		<meta name="twitter:card" content="summary_large_image" />
		<meta name="twitter:title" content="<?php echo esc_attr( $og_title ); ?>" />
		<?php if ( $og_desc ) : ?><meta name="twitter:description" content="<?php echo esc_attr( $og_desc ); ?>" /><?php endif; ?>
		<?php if ( $og_image ) : ?><meta name="twitter:image" content="<?php echo esc_url( $og_image ); ?>" /><?php endif; ?>
		<link rel="alternate" hreflang="fa" href="<?php echo esc_url( $canonical ); ?>" />
		<link rel="alternate" hreflang="x-default" href="<?php echo esc_url( $canonical ); ?>" />
		<?php
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private function should_manage(): bool {
		$seo_config = get_option( 'csb_seo_config', [] );
		$manager    = is_array( $seo_config ) ? ( $seo_config['seo_manager'] ?? 'auto' ) : 'auto';

		if ( 'external' === $manager ) {
			return false;
		}
		if ( 'plugin' === $manager ) {
			return true;
		}
		// 'auto' — yield to Rank Math / Yoast if present.
		return ! class_exists( 'RankMath' ) && ! defined( 'WPSEO_VERSION' );
	}

	private function seo_opt( string $section, string $key, string $default ): string {
		$saved = get_option( 'csb_seo_config', [] );
		return (string) ( is_array( $saved ) ? ( $saved[ $section ][ $key ] ?? $default ) : $default );
	}

	/**
	 * Enforces 50–60 character title budget.
	 * Hard-truncates at 60 characters with an ellipsis (…).
	 */
	private function clamp_title( string $title ): string {
		$title = trim( $title );
		if ( mb_strlen( $title ) > 60 ) {
			$title = mb_substr( $title, 0, 59 ) . '…';
		}
		return $title;
	}

	/**
	 * Enforces 150–160 character description budget.
	 * Hard-truncates at 160 characters with an ellipsis.
	 */
	private function clamp_desc( string $desc ): string {
		$desc = wp_strip_all_tags( $desc );
		$desc = trim( preg_replace( '/\s+/u', ' ', $desc ) );
		if ( mb_strlen( $desc ) > 160 ) {
			$desc = mb_substr( $desc, 0, 159 ) . '…';
		}
		return $desc;
	}

	private function tokens( string $tpl, \WC_Product $product ): string {
		$instructor = '';
		if ( method_exists( $product, 'get_csb_instructor_id' ) ) {
			$uid  = (int) $product->get_csb_instructor_id();
			$user = $uid ? get_user_by( 'id', $uid ) : false;
			if ( $user ) {
				$instructor = $user->display_name;
			}
		}

		$terms   = wp_get_post_terms( $product->get_id(), 'product_cat' );
		$cat     = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0]->name : '';
		$excerpt = wp_strip_all_tags( $product->get_short_description() ?: wp_trim_words( $product->get_description(), 30 ) );

		return str_replace(
			[ '%title%', '%instructor%', '%category%', '%price%', '%sitename%', '%sep%', '%excerpt%' ],
			[
				get_the_title( $product->get_id() ),
				$instructor,
				$cat,
				$product->get_price() . ' ' . get_woocommerce_currency(),
				get_bloginfo( 'name' ),
				'|',
				$excerpt,
			],
			$tpl
		);
	}
}
