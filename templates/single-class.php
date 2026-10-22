<?php
/**
 * Single class product template.
 *
 * Loaded via Rewrites::maybe_load_template() when the product type is 'class'.
 * Wraps WP standard page chrome; actual product data rendered from $product.
 *
 * @package CookingSchoolBooking
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

global $product;

if ( ! $product instanceof \CookingSchoolBooking\Products\ClassProduct ) {
	$product = wc_get_product( get_the_ID() );
}

$post_id  = $product->get_id();
$title    = get_the_title( $post_id );
$image_id = (int) get_post_thumbnail_id( $post_id );
$image    = $image_id ? wp_get_attachment_image( $image_id, 'large', false, [
	'loading' => 'eager', 'class' => 'csb-hero-img', 'alt' => esc_attr( $title ),
] ) : '';

$class_type_label = 'online' === $product->get_csb_class_type()
	? __( 'آنلاین زنده', 'csb' )
	: __( 'حضوری', 'csb' );

$instructor_name = '';
$iid = $product->get_csb_instructor_id();
if ( $iid ) {
	$iu = get_user_by( 'id', $iid );
	if ( $iu ) $instructor_name = $iu->display_name;
}

$level     = $product->get_csb_level();
$materials = array_filter( array_map( 'trim', explode( "\n", $product->get_csb_materials() ) ) );
$syllabus  = $product->get_csb_syllabus();

// Upcoming sessions for this class, including live locked-seat counts.
$sessions = \CookingSchoolBooking\Services\CapacityService::get_upcoming_sessions( $post_id );
?>

<main id="main" class="csb-main" role="main">
	<article id="post-<?php echo esc_attr( (string) $post_id ); ?>" <?php post_class( 'csb-class-single' ); ?> dir="rtl"
		itemscope itemtype="https://schema.org/Course">

		<!-- ── Hero ── -->
		<header class="csb-hero">
			<?php
			// Hero image: featured thumbnail or emoji placeholder.
			use CookingSchoolBooking\Frontend\CardData;
			$_hero_cat   = CardData::category_name( $post_id );
			$_hero_emoji = CardData::emoji_for_category( $_hero_cat );
			?>
			<div class="csb-hero__thumb">
				<?php if ( $image ) : ?>
					<?php echo $image; // wp_get_attachment_image — already safe ?>
				<?php else : ?>
					<span class="csb-card__placeholder csb-hero__placeholder" data-cat="<?php echo esc_attr( $_hero_cat ); ?>">
						<?php echo esc_html( $_hero_emoji ); ?>
					</span>
				<?php endif; ?>
			</div>
			<div class="csb-hero__body">
				<h1 class="csb-hero__title" itemprop="name"><?php echo esc_html( $title ); ?></h1>
				<ul class="csb-meta-list" role="list">
					<?php if ( $instructor_name ) : ?>
						<li class="csb-meta-list__item"><?php esc_html_e( 'مدرس:', 'csb' ); ?> <strong><?php echo esc_html( $instructor_name ); ?></strong></li>
					<?php endif; ?>
					<li class="csb-meta-list__item"><?php echo esc_html( $class_type_label ); ?></li>
					<?php if ( $level ) : ?>
						<li class="csb-meta-list__item"><?php esc_html_e( 'سطح:', 'csb' ); ?> <?php echo esc_html( $level ); ?></li>
					<?php endif; ?>
				</ul>
				<div class="csb-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
					<?php echo wp_kses_post( $product->get_price_html() ); ?>
				</div>
			</div>
		</header>

		<!-- ── Short description ── -->
		<?php $short = $product->get_short_description(); ?>
		<?php if ( $short ) : ?>
			<section class="csb-excerpt" itemprop="description"><?php echo wp_kses_post( $short ); ?></section>
		<?php endif; ?>

		<!-- ── Sessions ── -->
		<section class="csb-sessions" aria-labelledby="csb-sessions-hdg">
			<h2 id="csb-sessions-hdg"><?php esc_html_e( 'جلسات دوره', 'csb' ); ?></h2>
			<?php if ( empty( $sessions ) ) : ?>
				<p class="csb-sessions__empty"><?php esc_html_e( 'جلسه‌ی فعالی وجود ندارد.', 'csb' ); ?></p>
			<?php else : ?>
				<ul class="csb-sessions__list" role="list">
					<?php foreach ( $sessions as $session ) :
						$session_id  = (int) $session['id'];
						$jalali      = \CookingSchoolBooking\Support\Jalali::toJalali( $session['start_at'] );
						$time_fmt    = \CookingSchoolBooking\Support\Jalali::toJalali( $session['start_at'], 'H:i' );
						// Available includes locked-seat deduction (live accurate count).
						$seats_avail = \CookingSchoolBooking\Services\CapacityService::available_seats( $session_id );
					?>
						<li class="csb-session-item <?php echo 0 === $seats_avail ? 'csb-session-item--full' : ''; ?>">
							<span class="csb-session-item__date">
								<strong><?php echo esc_html( $jalali ); ?></strong>
								<?php echo esc_html( $time_fmt ); ?>
							</span>
							<span class="csb-session-item__dur">
								<?php echo esc_html( $session['duration_min'] . ' ' . __( 'دقیقه', 'csb' ) ); ?>
							</span>
							<?php if ( ! empty( $session['location'] ) ) : ?>
								<span class="csb-session-item__loc"><?php echo esc_html( $session['location'] ); ?></span>
							<?php endif; ?>

							<?php if ( $seats_avail > 0 ) : ?>
								<span class="csb-session-item__seats">
									<?php printf(
										esc_html( _n( '%d صندلی خالی', '%d صندلی خالی', $seats_avail, 'csb' ) ),
										$seats_avail
									); ?>
								</span>
								<!--
								  Add-to-cart form for this session.
								  csb_session_id  → CartValidation::validate_add_to_cart()
								  csb_payment_mode → CheckoutHooks::inject_deposit_cart_data()
								-->
								<?php
								$settings         = \CookingSchoolBooking\Admin\SettingsPage::get();
								$full_price       = (float) $product->get_price();
								$deposit_percent  = (float) ( $settings['deposit_default_percent'] ?? 30 );

								// Per-product deposit override.
								$dep_type         = $product instanceof \CookingSchoolBooking\Products\ClassProduct
									? $product->get_csb_deposit_type() : '';
								$dep_value        = $product instanceof \CookingSchoolBooking\Products\ClassProduct
									? (float) $product->get_csb_deposit_value() : 0;

								if ( 'fixed' === $dep_type && $dep_value > 0 ) {
									$deposit_amount = $dep_value;
								} else {
									$pct            = $dep_value > 0 ? $dep_value : $deposit_percent;
									$deposit_amount = round( $full_price * $pct / 100, 2 );
								}

								$balance_amount = round( $full_price - $deposit_amount, 2 );

								?>
								<form class="csb-reserve-form" method="post"
									action="<?php echo esc_url( wc_get_cart_url() ); ?>">
									<?php wp_nonce_field( 'csb_add_to_cart_' . $session_id, '_csb_atc_nonce' ); ?>
									<input type="hidden" name="add-to-cart"    value="<?php echo esc_attr( (string) $post_id ); ?>" />
									<input type="hidden" name="csb_session_id" value="<?php echo esc_attr( (string) $session_id ); ?>" />

									<!-- Payment mode: deposit or full -->
									<fieldset class="csb-payment-mode" style="border:none;padding:0;margin:.5em 0;">
										<legend class="csb-payment-mode__legend" style="font-weight:bold;margin-bottom:.4em;">
											<?php esc_html_e( 'روش پرداخت', 'csb' ); ?>
										</legend>

										<label class="csb-payment-mode__option" style="display:block;margin:.25em 0;">
											<input type="radio" name="csb_payment_mode" value="full"
												<?php checked( true ); // default ?>
												aria-describedby="csb-full-desc-<?php echo esc_attr( (string) $session_id ); ?>" />
											<?php esc_html_e( 'پرداخت کامل', 'csb' ); ?>
											<span id="csb-full-desc-<?php echo esc_attr( (string) $session_id ); ?>"
												style="font-size:.85em;color:#555;display:inline-block;margin-right:.35em;">
												(<?php echo wp_kses_post( wc_price( $full_price ) ); ?>)
											</span>
										</label>

										<?php if ( $deposit_amount > 0 && $deposit_amount < $full_price ) : ?>
										<label class="csb-payment-mode__option" style="display:block;margin:.25em 0;">
											<input type="radio" name="csb_payment_mode" value="deposit"
												aria-describedby="csb-dep-desc-<?php echo esc_attr( (string) $session_id ); ?>" />
											<?php esc_html_e( 'پرداخت بیعانه', 'csb' ); ?>
											<span id="csb-dep-desc-<?php echo esc_attr( (string) $session_id ); ?>"
												style="font-size:.85em;color:#555;display:inline-block;margin-right:.35em;">
												(<?php echo wp_kses_post( wc_price( $deposit_amount ) ); ?>
												<?php esc_html_e( 'همین الان،', 'csb' ); ?>
												<?php echo wp_kses_post( wc_price( $balance_amount ) ); ?>
												<?php esc_html_e( 'مانده)', 'csb' ); ?>)
											</span>
										</label>
										<?php endif; ?>
									</fieldset>

									<label class="screen-reader-text" for="csb-qty-<?php echo esc_attr( (string) $session_id ); ?>">
										<?php esc_html_e( 'تعداد', 'csb' ); ?>
									</label>
									<input type="number"
										id="csb-qty-<?php echo esc_attr( (string) $session_id ); ?>"
										name="quantity"
										value="1" min="1"
										max="<?php echo esc_attr( (string) $seats_avail ); ?>"
										class="csb-reserve-form__qty"
										aria-label="<?php esc_attr_e( 'تعداد صندلی', 'csb' ); ?>" />
									<button type="submit" class="csb-btn-reserve button alt">
										<?php esc_html_e( 'رزرو / ثبت‌نام', 'csb' ); ?>
									</button>
								</form>
							<?php else : ?>
								<span class="csb-session-item__full"><?php esc_html_e( 'ظرفیت تکمیل', 'csb' ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<!-- ── Tabs ── -->
		<div class="csb-tabs">
			<nav class="csb-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'اطلاعات دوره', 'csb' ); ?>">
				<button role="tab" id="csb-tab-desc"      aria-controls="csb-panel-desc"      aria-selected="true"  class="csb-tabs__btn csb-tabs__btn--active"><?php esc_html_e( 'توضیحات', 'csb' ); ?></button>
				<?php if ( $syllabus ) : ?>
				<button role="tab" id="csb-tab-syllabus"  aria-controls="csb-panel-syllabus"  aria-selected="false" class="csb-tabs__btn"><?php esc_html_e( 'سرفصل', 'csb' ); ?></button>
				<?php endif; ?>
				<?php if ( ! empty( $materials ) ) : ?>
				<button role="tab" id="csb-tab-materials" aria-controls="csb-panel-materials" aria-selected="false" class="csb-tabs__btn"><?php esc_html_e( 'مواد موردنیاز', 'csb' ); ?></button>
				<?php endif; ?>
				<button role="tab" id="csb-tab-reviews"   aria-controls="csb-panel-reviews"   aria-selected="false" class="csb-tabs__btn"><?php esc_html_e( 'نظرات', 'csb' ); ?></button>
			</nav>

			<div id="csb-panel-desc" role="tabpanel" aria-labelledby="csb-tab-desc" class="csb-tabs__panel" itemprop="description">
				<?php echo wp_kses_post( $product->get_description() ); ?>
			</div>

			<?php if ( $syllabus ) : ?>
			<div id="csb-panel-syllabus" role="tabpanel" aria-labelledby="csb-tab-syllabus" class="csb-tabs__panel" hidden>
				<?php echo wp_kses_post( wpautop( $syllabus ) ); ?>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $materials ) ) : ?>
			<div id="csb-panel-materials" role="tabpanel" aria-labelledby="csb-tab-materials" class="csb-tabs__panel" hidden>
				<ul>
					<?php foreach ( $materials as $item ) : ?>
						<li><?php echo esc_html( $item ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<div id="csb-panel-reviews" role="tabpanel" aria-labelledby="csb-tab-reviews" class="csb-tabs__panel" hidden>
				<?php comments_template(); ?>
			</div>
		</div>

	</article>
</main>

<?php get_footer( 'shop' ); ?>
