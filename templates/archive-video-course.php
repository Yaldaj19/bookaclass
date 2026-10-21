<?php
/**
 * Archive template for 'video_course' product type.
 *
 * Loaded by Rewrites::maybe_load_template() when csb_archive=1 and
 * csb_product_type=video_course.
 *
 * @package CookingSchoolBooking
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

$seo_config    = (array) get_option( 'csb_seo_config', [] );
$archive_title = $seo_config['type_video_course']['archive_title'] ?? __( 'ویدیوکلاس‌ها', 'csb' );
$archive_desc  = $seo_config['type_video_course']['archive_desc'] ?? '';
?>

<main id="main" class="csb-main csb-archive-videos" role="main" dir="rtl">

	<header class="csb-archive-header">
		<h1 class="csb-archive-header__title"><?php echo esc_html( $archive_title ); ?></h1>
		<?php if ( $archive_desc ) : ?>
			<p class="csb-archive-header__desc"><?php echo esc_html( $archive_desc ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( have_posts() ) : ?>
		<section class="csb-product-grid" aria-label="<?php esc_attr_e( 'لیست ویدیوکلاس‌ها', 'csb' ); ?>">
			<?php while ( have_posts() ) : the_post();
				$product = wc_get_product( get_the_ID() );
				if ( ! $product ) continue;
				$image_id = (int) get_post_thumbnail_id();
				$img      = $image_id ? wp_get_attachment_image( $image_id, 'medium', false, [
					'loading' => 'lazy', 'alt' => esc_attr( get_the_title() ),
				] ) : '';
				$lessons_count = method_exists( $product, 'get_csb_lessons' )
					? count( $product->get_csb_lessons() ) : 0;
			?>
				<article id="product-<?php the_ID(); ?>" <?php post_class( 'csb-card' ); ?> itemscope itemtype="https://schema.org/VideoObject">
					<a href="<?php echo esc_url( get_permalink() ); ?>" class="csb-card__link" tabindex="0">
						<?php if ( $img ) : ?>
							<div class="csb-card__thumb"><?php echo $img; ?></div>
						<?php endif; ?>
						<div class="csb-card__body">
							<h2 class="csb-card__title" itemprop="name"><?php the_title(); ?></h2>
							<?php
							$rating_count = (int) $product->get_rating_count();
							$avg_rating   = (float) $product->get_average_rating();
							if ( $rating_count > 0 ) :
							?>
							<div class="csb-card__rating" itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating"
								aria-label="<?php echo esc_attr( sprintf( __( '%1$.1f از ۵ — %2$d نظر', 'csb' ), $avg_rating, $rating_count ) ); ?>">
								<?php echo wc_get_rating_html( $avg_rating, $rating_count ); ?>
								<span class="csb-card__rating-count">(<?php echo esc_html( (string) $rating_count ); ?>)</span>
								<meta itemprop="ratingValue" content="<?php echo esc_attr( number_format( $avg_rating, 1, '.', '' ) ); ?>">
								<meta itemprop="reviewCount" content="<?php echo esc_attr( (string) $rating_count ); ?>">
							</div>
							<?php endif; ?>
							<div class="csb-card__excerpt"><?php echo wp_kses_post( $product->get_short_description() ?: '' ); ?></div>
							<?php if ( $lessons_count ) : ?>
								<span class="csb-card__meta">
									<?php printf( esc_html( _n( '%d جلسه', '%d جلسه', $lessons_count, 'csb' ) ), $lessons_count ); ?>
								</span>
							<?php endif; ?>
							<div class="csb-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
						</div>
					</a>
				</article>
			<?php endwhile; ?>
		</section>

		<?php the_posts_pagination( [
			'prev_text' => esc_html__( '&laquo; قبلی', 'csb' ),
			'next_text' => esc_html__( 'بعدی &raquo;', 'csb' ),
		] ); ?>

	<?php else : ?>
		<p class="csb-no-results"><?php esc_html_e( 'ویدیوکلاسی یافت نشد.', 'csb' ); ?></p>
	<?php endif; ?>

</main>

<?php get_footer( 'shop' ); ?>
