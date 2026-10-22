<?php
/**
 * Archive template for 'video_course' product type.
 *
 * Loaded by Rewrites::maybe_load_template() when csb_archive=1 and
 * csb_product_type=video_course.
 *
 * Layout, toggles, and per-page count are driven by AppearancePage::get().
 * Card data (instructor, category) comes from CardData helpers.
 * All prices are formatted with wc_price() so WooCommerce currency settings apply.
 *
 * @package BookaClass
 */

defined( 'ABSPATH' ) || exit;

use BookaClass\Admin\AppearancePage;
use BookaClass\Frontend\CardData;

get_header( 'shop' );

$seo_config    = (array) get_option( 'csb_seo_config', [] );
$archive_title = $seo_config['type_video_course']['archive_title'] ?? __( 'ویدیوکلاس‌ها', 'csb' );

$s               = AppearancePage::get();
$archive_width   = in_array( $s['archive_width'], [ 'boxed', 'full' ], true ) ? $s['archive_width'] : 'boxed';
$archive_sidebar = in_array( $s['archive_sidebar'], [ 'none', 'right', 'left' ], true ) ? $s['archive_sidebar'] : 'none';
$archive_layout  = in_array( $s['archive_layout'], [ 'grid', 'list' ], true ) ? $s['archive_layout'] : 'grid';
$archive_columns = in_array( (int) $s['archive_columns'], [ 2, 3, 4 ], true ) ? (int) $s['archive_columns'] : 3;
$card_style      = in_array( $s['card_style'], [ 'classic', 'overlay', 'minimal', 'horizontal' ], true ) ? $s['card_style'] : 'classic';
$show_filters    = ! empty( $s['show_filters'] );
$show_count      = ! empty( $s['show_result_count'] );
$card_cta_label  = esc_html( sanitize_text_field( $s['card_cta_label'] ?: __( 'مشاهده و ثبت‌نام', 'csb' ) ) );

// Active category filter from URL.
$active_cat  = sanitize_title( (string) ( $_GET['csb_cat'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Only product_cat terms that actually have 'video_course' type products.
$archive_terms = CardData::terms_for_type( 'video_course' );

// Current archive URL base — must point to /courses/ (not the WC shop).
$_csb_cfg_early   = \BookaClass\Products\ProductTypes::get_config();
$_csb_video_base  = sanitize_title( $_csb_cfg_early['types']['video_course']['classes_base'] ?? 'courses' );
$archive_base_url = trailingslashit( home_url( '/' . $_csb_video_base ) );
?>

<main id="csb-archive-main" class="csb csb-main csb-archive csb-archive--<?php echo esc_attr( $archive_width ); ?>" dir="rtl">

	<?php
	// ── Breadcrumb nav ────────────────────────────────────────────────────────
	// $_csb_video_base already set above.
	?>
	<nav class="csb-breadcrumb" aria-label="<?php esc_attr_e( 'مسیر صفحه', 'csb' ); ?>" dir="rtl">
		<ol class="csb-breadcrumb__list" role="list">
			<li class="csb-breadcrumb__item">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'خانه', 'csb' ); ?></a>
			</li>
			<li class="csb-breadcrumb__item csb-breadcrumb__item--current" aria-current="page">
				<?php echo esc_html( $archive_title ); ?>
			</li>
		</ol>
	</nav>

	<header class="csb-archive__header">
		<h1 class="csb-archive__title"><?php echo esc_html( $archive_title ); ?></h1>
		<?php if ( $show_count && have_posts() ) : ?>
			<p class="csb-archive__count">
				<?php
				$found = $GLOBALS['wp_query']->found_posts;
				/* translators: %d: course count */
				printf( esc_html( _n( '%d دوره', '%d دوره', $found, 'csb' ) ), (int) $found );
				?>
			</p>
		<?php endif; ?>
	</header>

	<div class="csb-archive__layout" data-sidebar="<?php echo esc_attr( $archive_sidebar ); ?>">

		<?php if ( 'none' !== $archive_sidebar ) : ?>
		<aside class="csb-archive__sidebar" aria-label="<?php esc_attr_e( 'فیلتر و دسته‌بندی', 'csb' ); ?>">

			<?php if ( is_active_sidebar( 'csb-archive-sidebar' ) ) : ?>
				<div class="csb-archive__sidebar-widgets">
					<?php dynamic_sidebar( 'csb-archive-sidebar' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $archive_terms ) ) : ?>
			<div class="csb-archive__sidebar-cats">
				<h3 class="csb-archive__sidebar-heading"><?php esc_html_e( 'دسته‌بندی', 'csb' ); ?></h3>
				<ul class="csb-archive__cat-list">
					<li>
						<a href="<?php echo esc_url( $archive_base_url ); ?>"
							<?php echo '' === $active_cat ? ' aria-current="page"' : ''; ?>>
							<?php esc_html_e( 'همه', 'csb' ); ?>
						</a>
					</li>
					<?php foreach ( $archive_terms as $term ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'csb_cat', $term->slug, $archive_base_url ) ); ?>"
							<?php echo $active_cat === $term->slug ? ' aria-current="page"' : ''; ?>>
							<?php echo esc_html( $term->name ); ?>
							<span class="csb-archive__cat-count">(<?php echo esc_html( (string) $term->count ); ?>)</span>
						</a>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

		</aside>
		<?php endif; ?>

		<div class="csb-archive__main">

			<?php if ( $show_filters ) : ?>
			<nav class="csb-archive__filters" aria-label="<?php esc_attr_e( 'فیلترها', 'csb' ); ?>">

				<div class="csb-archive__filter-group csb-archive__filter-cats">
					<a class="csb-filter-chip <?php echo '' === $active_cat ? 'csb-filter-chip--active' : ''; ?>"
						href="<?php echo esc_url( $archive_base_url ); ?>"
						<?php echo '' === $active_cat ? 'aria-current="page"' : ''; ?>>
						<?php esc_html_e( 'همه', 'csb' ); ?>
					</a>
					<?php foreach ( $archive_terms as $term ) : ?>
					<a class="csb-filter-chip <?php echo $active_cat === $term->slug ? 'csb-filter-chip--active' : ''; ?>"
						href="<?php echo esc_url( add_query_arg( 'csb_cat', $term->slug, $archive_base_url ) ); ?>"
						<?php echo $active_cat === $term->slug ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $term->name ); ?>
					</a>
					<?php endforeach; ?>
				</div>

			</nav>
			<?php endif; ?>

			<?php if ( have_posts() ) : ?>

			<div class="csb-cards csb-cards--<?php echo esc_attr( $card_style ); ?>"
				data-cols="<?php echo esc_attr( (string) $archive_columns ); ?>"
				data-layout="<?php echo esc_attr( $archive_layout ); ?>">

				<?php while ( have_posts() ) : the_post();
					$product = wc_get_product( get_the_ID() );
					if ( ! $product ) {
						continue;
					}

					$post_id   = $product->get_id();
					$permalink = get_permalink( $post_id );
					$title     = get_the_title( $post_id );

					// Image / placeholder.
					$image_id  = (int) get_post_thumbnail_id( $post_id );
					$has_image = $image_id > 0;

					// Category.
					$cat_name = CardData::category_name( $post_id );
					$emoji    = CardData::emoji_for_category( $cat_name );

					// Instructor.
					$instructor = CardData::instructor_name( $post_id );

					// Level.
					$level = get_post_meta( $post_id, '_csb_level', true );
					$level = is_string( $level ) ? $level : '';

					// Lesson count.
					$lessons_count = 0;
					if ( method_exists( $product, 'get_csb_lessons' ) ) {
						$lessons_count = count( $product->get_csb_lessons() );
					}

					// Rating.
					$rating_count = (int) $product->get_rating_count();
					$avg_rating   = (float) $product->get_average_rating();

					// Excerpt.
					$excerpt = $product->get_short_description() ?: '';

					// Price / early-bird.
					$regular_price   = (float) $product->get_regular_price();
					$earlybird_price = (float) get_post_meta( $post_id, '_csb_earlybird_price', true );
					$earlybird_end   = (string) get_post_meta( $post_id, '_csb_earlybird_end', true );
					$earlybird_active = $earlybird_price > 0 && $earlybird_price < $regular_price
						&& ( '' === $earlybird_end || strtotime( $earlybird_end ) > time() );
				?>

				<article id="product-<?php the_ID(); ?>" <?php post_class( 'csb-card csb-card--video' ); ?>
					itemscope itemtype="https://schema.org/VideoObject">

					<?php if ( ! empty( $s['card_show_image'] ) ) : ?>
					<a class="csb-card__media" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true">
						<?php if ( $has_image ) : ?>
							<?php echo wp_get_attachment_image( $image_id, 'medium', false, [
								'loading' => 'lazy',
								'alt'     => esc_attr( $title ),
								'class'   => 'csb-card__img',
							] ); ?>
						<?php else : ?>
							<span class="csb-card__placeholder" data-cat="<?php echo esc_attr( $cat_name ); ?>">
								<?php echo esc_html( $emoji ); ?>
							</span>
						<?php endif; ?>
						<?php if ( ! empty( $s['card_show_type_badge'] ) ) : ?>
							<span class="csb-card__badge csb-card__badge--video">
								<?php esc_html_e( 'ویدیویی', 'csb' ); ?>
							</span>
						<?php endif; ?>
					</a>
					<?php endif; ?>

					<div class="csb-card__body">

						<?php if ( ! empty( $s['card_show_category'] ) && '' !== $cat_name ) : ?>
						<span class="csb-card__cat"><?php echo esc_html( $cat_name ); ?></span>
						<?php endif; ?>

						<h3 class="csb-card__title" itemprop="name">
							<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
						</h3>

						<?php if ( ! empty( $s['card_show_instructor'] ) && '' !== $instructor ) : ?>
						<div class="csb-card__instructor">
							<?php echo esc_html( $instructor ); ?>
						</div>
						<?php endif; ?>

						<ul class="csb-card__meta">
							<?php if ( $lessons_count > 0 ) : ?>
							<li class="csb-card__lessons">
								<?php
								printf(
									esc_html( _n( '%d درس', '%d درس', $lessons_count, 'csb' ) ),
									$lessons_count
								);
								?>
							</li>
							<?php endif; ?>

							<?php if ( ! empty( $s['card_show_level'] ) && '' !== $level ) : ?>
							<li class="csb-card__level"><?php echo esc_html( $level ); ?></li>
							<?php endif; ?>
						</ul>

						<?php if ( ! empty( $s['card_show_rating'] ) && $rating_count > 0 ) : ?>
						<div class="csb-card__rating"
							itemprop="aggregateRating" itemscope itemtype="https://schema.org/AggregateRating"
							aria-label="<?php echo esc_attr( sprintf( __( '%1$.1f از ۵ — %2$d نظر', 'csb' ), $avg_rating, $rating_count ) ); ?>">
							<?php echo wc_get_rating_html( $avg_rating, $rating_count ); ?>
							<span class="csb-card__rating-count">(<?php echo esc_html( (string) $rating_count ); ?>)</span>
							<meta itemprop="ratingValue" content="<?php echo esc_attr( number_format( $avg_rating, 1, '.', '' ) ); ?>">
							<meta itemprop="reviewCount" content="<?php echo esc_attr( (string) $rating_count ); ?>">
						</div>
						<?php endif; ?>

						<?php if ( ! empty( $s['card_show_excerpt'] ) && '' !== $excerpt ) : ?>
						<p class="csb-card__excerpt"><?php echo wp_kses_post( wp_trim_words( $excerpt, 18, '...' ) ); ?></p>
						<?php endif; ?>

					</div><!-- .csb-card__body -->

					<div class="csb-card__footer">

						<?php if ( ! empty( $s['card_show_price'] ) ) : ?>
						<div class="csb-card__price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
							<?php if ( $earlybird_active && ! empty( $s['card_show_earlybird'] ) ) : ?>
								<del class="csb-card__price-regular">
									<?php echo wp_kses_post( wc_price( $regular_price ) ); ?>
								</del>
								<ins class="csb-card__price-earlybird">
									<?php echo wp_kses_post( wc_price( $earlybird_price ) ); ?>
								</ins>
							<?php else : ?>
								<?php echo wp_kses_post( $product->get_price_html() ); ?>
							<?php endif; ?>
						</div>
						<?php endif; ?>

						<a class="csb-btn csb-btn--primary csb-card__cta"
							href="<?php echo esc_url( $permalink ); ?>">
							<?php echo $card_cta_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped above ?>
						</a>

					</div><!-- .csb-card__footer -->

				</article><!-- .csb-card -->

				<?php endwhile; ?>

			</div><!-- .csb-cards -->

			<?php the_posts_pagination( [
				'prev_text' => esc_html__( '&laquo; قبلی', 'csb' ),
				'next_text' => esc_html__( 'بعدی &raquo;', 'csb' ),
			] ); ?>

			<?php else : ?>
			<p class="csb-no-results"><?php esc_html_e( 'ویدیوکلاسی یافت نشد.', 'csb' ); ?></p>
			<?php endif; ?>

		</div><!-- .csb-archive__main -->

	</div><!-- .csb-archive__layout -->

</main><!-- .csb-archive -->

<?php get_footer( 'shop' ); ?>
