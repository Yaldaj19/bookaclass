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

	// ── Title ────────────────────────────────────────────────────────────────

	/**
	 * Overrides the document title for our product types (singles + archives).
	 */
	public function filter_title( string $title ): string {
		// Archive page title.
		$type       = (string) get_query_var( 'csb_product_type' );
		$is_archive = '1' === (string) get_query_var( 'csb_archive' );

		if ( '' !== $type && $is_archive ) {
			$type_key      = 'class' === $type ? 'type_class' : 'type_video_course';
			$archive_title = $this->seo_opt( $type_key, 'archive_title', '' );
			if ( $archive_title ) {
				return $archive_title . ' | ' . get_bloginfo( 'name' );
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
		return $this->tokens( $template, $product );
	}

	// ── Head meta ─────────────────────────────────────────────────────────────

	/**
	 * Outputs meta description, canonical, OG, Twitter Card, hreflang.
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
		$title     = $this->tokens( $this->seo_opt( $type_key, 'title_template', '%title% | %sitename%' ), $product );
		$desc      = wp_trim_words( $this->tokens( $this->seo_opt( $type_key, 'desc_template', '%excerpt%' ), $product ), 30 );
		$robots    = $this->seo_opt( $type_key, 'robots', 'index' );
		$og_image  = $this->seo_opt( $type_key, 'og_image', '' );
		$thumb_id  = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id && ! $og_image ) {
			$og_image = (string) wp_get_attachment_url( $thumb_id );
		}

		// Remove WP's default canonical (we'll output ours).
		remove_action( 'wp_head', 'rel_canonical' );
		?>
		<!-- csb: seo meta -->
		<meta name="robots" content="<?php echo 'noindex' === $robots ? 'noindex,nofollow' : 'index,follow'; ?>" />
		<?php if ( $desc ) : ?><meta name="description" content="<?php echo esc_attr( $desc ); ?>" /><?php endif; ?>
		<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:type" content="<?php echo $product instanceof ClassProduct ? 'article' : 'video.other'; ?>" />
		<meta property="og:title" content="<?php echo esc_attr( $title ); ?>" />
		<?php if ( $desc ) : ?><meta property="og:description" content="<?php echo esc_attr( $desc ); ?>" /><?php endif; ?>
		<meta property="og:url" content="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
		<?php if ( $og_image ) : ?><meta property="og:image" content="<?php echo esc_url( $og_image ); ?>" /><?php endif; ?>
		<meta name="twitter:card" content="summary_large_image" />
		<meta name="twitter:title" content="<?php echo esc_attr( $title ); ?>" />
		<?php if ( $desc ) : ?><meta name="twitter:description" content="<?php echo esc_attr( $desc ); ?>" /><?php endif; ?>
		<?php if ( $og_image ) : ?><meta name="twitter:image" content="<?php echo esc_url( $og_image ); ?>" /><?php endif; ?>
		<link rel="alternate" hreflang="fa" href="<?php echo esc_url( $canonical ); ?>" />
		<link rel="alternate" hreflang="x-default" href="<?php echo esc_url( $canonical ); ?>" />
		<?php
	}

	// ── Archive meta ─────────────────────────────────────────────────────────

	/**
	 * Outputs title, description, robots and canonical for our custom archive pages
	 * (`/classes/` and `/courses/`).
	 *
	 * Values come from csb_seo_config[type_class|type_video_course][archive_*].
	 */
	public function output_archive_meta(): void {
		$type = (string) get_query_var( 'csb_product_type' );
		$is_archive = '1' === (string) get_query_var( 'csb_archive' );

		if ( '' === $type || ! $is_archive ) {
			return;
		}

		$type_key = 'class' === $type ? 'type_class' : 'type_video_course';

		$archive_title = $this->seo_opt( $type_key, 'archive_title', '' );
		$archive_desc  = $this->seo_opt( $type_key, 'archive_desc', '' );
		$robots        = $this->seo_opt( $type_key, 'archive_robots', 'index' );

		$canonical = ( 'class' === $type )
			? home_url( '/' . sanitize_title( $this->seo_opt( $type_key, 'classes_base', 'classes' ) ) . '/' )
			: home_url( '/' . sanitize_title( $this->seo_opt( $type_key, 'classes_base', 'courses' ) ) . '/' );

		// Resolve base from config rather than the SEO option, to be safe.
		$config = \CookingSchoolBooking\Products\ProductTypes::get_config();
		$base   = sanitize_title( $config['types'][ $type ]['classes_base'] ?? ( 'class' === $type ? 'classes' : 'courses' ) );
		$canonical = trailingslashit( home_url( '/' . $base ) );

		// Remove WP's default canonical.
		remove_action( 'wp_head', 'rel_canonical' );
		?>
		<!-- csb: archive seo meta -->
		<meta name="robots" content="<?php echo 'noindex' === $robots ? 'noindex,nofollow' : 'index,follow'; ?>" />
		<?php if ( $archive_desc ) : ?>
			<meta name="description" content="<?php echo esc_attr( $archive_desc ); ?>" />
			<meta property="og:description" content="<?php echo esc_attr( $archive_desc ); ?>" />
		<?php endif; ?>
		<link rel="canonical" href="<?php echo esc_url( $canonical ); ?>" />
		<?php if ( $archive_title ) : ?>
			<meta property="og:title" content="<?php echo esc_attr( $archive_title ); ?>" />
		<?php endif; ?>
		<meta property="og:url" content="<?php echo esc_url( $canonical ); ?>" />
		<meta property="og:site_name" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
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
		$excerpt = wp_strip_all_tags( $product->get_short_description() ?: wp_trim_words( $product->get_description(), 25 ) );

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
