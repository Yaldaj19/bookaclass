<?php
/**
 * Single video_course product template.
 *
 * Loaded via Rewrites::maybe_load_template() when the product type is 'video_course'.
 *
 * Enrolled students see the video player + download links.
 * Unauthenticated / non-enrolled users see the lesson list with free-preview
 * lessons playable and paid lessons showing a lock badge.
 *
 * Video is loaded lazily via REST (csb/v1/video/{course}/{index}/stream) so that
 * the signed URL is generated at play time, not at page render time.
 *
 * @package CookingSchoolBooking
 */

defined( 'ABSPATH' ) || exit;

use CookingSchoolBooking\Services\EnrollmentService;

get_header( 'shop' );

global $product;

if ( ! $product instanceof \CookingSchoolBooking\Products\VideoCourseProduct ) {
	$product = wc_get_product( get_the_ID() );
}

$post_id         = $product->get_id();
$title           = get_the_title( $post_id );
$image_id        = (int) get_post_thumbnail_id( $post_id );
$image           = $image_id ? wp_get_attachment_image( $image_id, 'large', false, [
	'loading' => 'eager', 'class' => 'csb-hero-img', 'alt' => esc_attr( $title ),
] ) : '';
$lessons         = $product->get_csb_lessons();
$access_duration = $product->get_csb_access_duration();
$lesson_count    = count( $lessons );
$free_count      = count( array_filter( $lessons, fn( $l ) => ! empty( $l['is_free_preview'] ) ) );
$total_min       = array_sum( array_column( $lessons, 'duration' ) );

$instructor_name = '';
$iid = $product->get_csb_instructor_id();
if ( $iid ) {
	$iu = get_user_by( 'id', $iid );
	if ( $iu ) {
		$instructor_name = $iu->display_name;
	}
}

// Enrollment check.
$is_enrolled  = false;
$progress     = [ 'completed' => [], 'watched_sec' => [] ];
$current_uid  = get_current_user_id();

if ( $current_uid ) {
	$is_enrolled = EnrollmentService::is_enrolled( $current_uid, $post_id );
	if ( $is_enrolled ) {
		$progress = EnrollmentService::get_progress( $current_uid, $post_id );
	}
}

// REST namespace for JS.
$stream_base   = esc_url( rest_url( 'csb/v1/video/' . $post_id . '/' ) );
$progress_url  = esc_url( rest_url( 'csb/v1/enrollment/' . $post_id . '/progress' ) );
$rest_nonce    = wp_create_nonce( 'wp_rest' );
?>

<?php
// ── Breadcrumb nav (accessible, semantic) ─────────────────────────────────────
$_csb_crumb_terms = wp_get_post_terms( $post_id, 'product_cat' );
$_csb_crumb_term  = ( ! is_wp_error( $_csb_crumb_terms ) && ! empty( $_csb_crumb_terms ) ) ? $_csb_crumb_terms[0] : null;
?>
<nav class="csb-breadcrumb" aria-label="<?php esc_attr_e( 'مسیر صفحه', 'csb' ); ?>" dir="rtl">
	<ol class="csb-breadcrumb__list" role="list">
		<li class="csb-breadcrumb__item">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'خانه', 'csb' ); ?></a>
		</li>
		<?php if ( $_csb_crumb_term ) : ?>
		<li class="csb-breadcrumb__item">
			<a href="<?php echo esc_url( (string) get_term_link( $_csb_crumb_term ) ); ?>">
				<?php echo esc_html( $_csb_crumb_term->name ); ?>
			</a>
		</li>
		<?php endif; ?>
		<li class="csb-breadcrumb__item csb-breadcrumb__item--current" aria-current="page">
			<?php echo esc_html( $title ); ?>
		</li>
	</ol>
</nav>

<main id="main" class="csb-main" role="main">
	<article id="post-<?php echo esc_attr( (string) $post_id ); ?>" <?php post_class( 'csb-video-single' ); ?> dir="rtl"
		itemscope itemtype="https://schema.org/VideoObject">

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
					<?php if ( $lesson_count ) : ?>
						<li class="csb-meta-list__item">
							<?php printf( esc_html( _n( '%d جلسه', '%d جلسه', $lesson_count, 'csb' ) ), $lesson_count ); ?>
						</li>
					<?php endif; ?>
					<?php if ( $total_min ) : ?>
						<li class="csb-meta-list__item"><?php echo esc_html( $total_min . ' ' . __( 'دقیقه', 'csb' ) ); ?></li>
					<?php endif; ?>
					<li class="csb-meta-list__item">
						<?php
						if ( 0 === $access_duration ) {
							esc_html_e( 'دسترسی مادام‌العمر', 'csb' );
						} else {
							printf( esc_html__( 'دسترسی %d روزه', 'csb' ), $access_duration );
						}
						?>
					</li>
				</ul>
				<div class="csb-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
				<?php if ( $is_enrolled ) : ?>
					<p class="csb-enrolled-notice" role="status">
						<?php esc_html_e( 'شما در این دوره ثبت‌نام کرده‌اید.', 'csb' ); ?>
					</p>
				<?php else : ?>
					<?php woocommerce_template_single_add_to_cart(); ?>
				<?php endif; ?>
			</div>
		</header>

		<!-- ── Short description ── -->
		<?php $short = $product->get_short_description(); ?>
		<?php if ( $short ) : ?>
			<section class="csb-excerpt" itemprop="description"><?php echo wp_kses_post( $short ); ?></section>
		<?php endif; ?>

		<!-- ── Video player (enrolled) ── -->
		<?php if ( $is_enrolled && ! empty( $lessons ) ) : ?>
		<section class="csb-player-section" aria-label="<?php esc_attr_e( 'پخش ویدیو', 'csb' ); ?>">
			<!-- Player chrome — JS populates src via signed REST URL -->
			<div class="csb-player-wrap" id="csb-player-wrap">
				<video
					id="csb-video-player"
					class="csb-video"
					controls
					playsinline
					preload="none"
					aria-label="<?php esc_attr_e( 'پخش ویدیو دوره', 'csb' ); ?>"
				>
					<p><?php esc_html_e( 'مرورگر شما از پخش ویدیو پشتیبانی نمی‌کند.', 'csb' ); ?></p>
				</video>
				<div class="csb-player-title" id="csb-player-title" aria-live="polite"></div>
			</div>

			<!-- Lesson list with progress state -->
			<nav class="csb-playlist" aria-label="<?php esc_attr_e( 'جلسات دوره', 'csb' ); ?>">
				<ol class="csb-playlist__list" role="list">
					<?php
					$completed_map = array_flip( $progress['completed'] );
					foreach ( $lessons as $i => $lesson ) :
						$is_done = isset( $completed_map[ $i ] );
						$dur_str = $lesson['duration'] ? ( (int) $lesson['duration'] . ' ' . __( 'دق', 'csb' ) ) : '';
					?>
						<li
							class="csb-playlist__item<?php echo $is_done ? ' csb-playlist__item--done' : ''; ?>"
							data-lesson-index="<?php echo esc_attr( (string) $i ); ?>"
							data-course-id="<?php echo esc_attr( (string) $post_id ); ?>"
							role="button"
							tabindex="0"
							aria-label="<?php echo esc_attr( sprintf( __( 'جلسه %d: %s', 'csb' ), $i + 1, $lesson['title'] ?? '' ) ); ?>"
						>
							<span class="csb-playlist__num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<span class="csb-playlist__title"><?php echo esc_html( $lesson['title'] ?? '' ); ?></span>
							<?php if ( $dur_str ) : ?>
								<span class="csb-playlist__dur"><?php echo esc_html( $dur_str ); ?></span>
							<?php endif; ?>
							<?php if ( $is_done ) : ?>
								<span class="csb-badge csb-badge--done" aria-label="<?php esc_attr_e( 'تمام‌شده', 'csb' ); ?>">&#10003;</span>
							<?php endif; ?>
							<?php if ( ! empty( $lesson['resource_url'] ) ) : ?>
								<button
									class="csb-resource-btn"
									data-lesson-index="<?php echo esc_attr( (string) $i ); ?>"
									data-course-id="<?php echo esc_attr( (string) $post_id ); ?>"
									aria-label="<?php esc_attr_e( 'دانلود منبع این جلسه', 'csb' ); ?>"
									type="button"
								>&#8595; <?php esc_html_e( 'منبع', 'csb' ); ?></button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
		</section>
		<?php endif; ?>

		<!-- ── Lessons (public view) ── -->
		<?php if ( ! empty( $lessons ) ) : ?>
		<section class="csb-lessons" aria-labelledby="csb-lessons-hdg">
			<h2 id="csb-lessons-hdg">
				<?php esc_html_e( 'محتوای دوره', 'csb' ); ?>
				<?php if ( $free_count ) : ?>
					<small>(<?php printf( esc_html__( '%d جلسه رایگان', 'csb' ), $free_count ); ?>)</small>
				<?php endif; ?>
			</h2>
			<ol class="csb-lessons__list" role="list">
				<?php foreach ( $lessons as $i => $lesson ) :
					$is_free    = ! empty( $lesson['is_free_preview'] );
					$accessible = $is_enrolled || $is_free;
					$dur_label  = $lesson['duration'] ? ( (int) $lesson['duration'] . ' ' . __( 'دق', 'csb' ) ) : '';
				?>
					<li class="csb-lesson <?php echo $accessible ? 'csb-lesson--open' : 'csb-lesson--locked'; ?>
						<?php echo ( $is_free && ! $is_enrolled ) ? 'csb-lesson--free-preview' : ''; ?>"
						<?php if ( $is_free && ! $is_enrolled ) : ?>
							data-lesson-index="<?php echo esc_attr( (string) $i ); ?>"
							data-course-id="<?php echo esc_attr( (string) $post_id ); ?>"
							role="button"
							tabindex="0"
						<?php endif; ?>
					>
						<span class="csb-lesson__num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
						<span class="csb-lesson__title"><?php echo esc_html( $lesson['title'] ?? '' ); ?></span>
						<?php if ( $dur_label ) : ?>
							<span class="csb-lesson__dur"><?php echo esc_html( $dur_label ); ?></span>
						<?php endif; ?>
						<?php if ( $is_free ) : ?>
							<span class="csb-badge csb-badge--free"><?php esc_html_e( 'رایگان', 'csb' ); ?></span>
						<?php elseif ( ! $is_enrolled ) : ?>
							<span class="csb-badge csb-badge--locked" aria-label="<?php esc_attr_e( 'قفل', 'csb' ); ?>">&#128274;</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php endif; ?>

		<!-- ── Inline preview player (free-preview, not enrolled) ── -->
		<?php if ( ! $is_enrolled && $free_count ) : ?>
		<div class="csb-preview-player" id="csb-preview-wrap" hidden aria-label="<?php esc_attr_e( 'پیش‌نمایش رایگان', 'csb' ); ?>">
			<video
				id="csb-preview-player"
				class="csb-video"
				controls
				playsinline
				preload="none"
				aria-label="<?php esc_attr_e( 'پیش‌نمایش جلسه', 'csb' ); ?>"
			></video>
		</div>
		<?php endif; ?>

		<!-- ── Tabs ── -->
		<div class="csb-tabs">
			<nav class="csb-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'اطلاعات دوره', 'csb' ); ?>">
				<button role="tab" id="csb-tab-desc"    aria-controls="csb-panel-desc"    aria-selected="true"  class="csb-tabs__btn csb-tabs__btn--active"><?php esc_html_e( 'توضیحات', 'csb' ); ?></button>
				<button role="tab" id="csb-tab-reviews" aria-controls="csb-panel-reviews" aria-selected="false" class="csb-tabs__btn"><?php esc_html_e( 'نظرات', 'csb' ); ?></button>
			</nav>
			<div id="csb-panel-desc" role="tabpanel" aria-labelledby="csb-tab-desc" class="csb-tabs__panel" itemprop="description">
				<?php echo wp_kses_post( $product->get_description() ); ?>
			</div>
			<div id="csb-panel-reviews" role="tabpanel" aria-labelledby="csb-tab-reviews" class="csb-tabs__panel" hidden>
				<?php comments_template(); ?>
			</div>
		</div>

	</article>
</main>

<?php
// Pass config to JS once. csb_is_enrolled drives which player section is visible.
$js_config = wp_json_encode( [
	'streamBase'   => rest_url( 'csb/v1/video/' . $post_id . '/' ),
	'progressUrl'  => rest_url( 'csb/v1/enrollment/' . $post_id . '/progress' ),
	'nonce'        => $rest_nonce,
	'isEnrolled'   => $is_enrolled,
	'courseId'     => $post_id,
	'completed'    => $progress['completed'],
] );
?>
<script>
(function() {
	var CSB_PLAYER = <?php echo $js_config; ?>;

	// ── Tab switching ────────────────────────────────────────────────────────
	document.querySelectorAll('.csb-tabs__btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var targetId = btn.getAttribute('aria-controls');
			document.querySelectorAll('.csb-tabs__btn').forEach(function(b) {
				b.setAttribute('aria-selected', 'false');
				b.classList.remove('csb-tabs__btn--active');
			});
			document.querySelectorAll('.csb-tabs__panel').forEach(function(p) {
				p.hidden = true;
			});
			btn.setAttribute('aria-selected', 'true');
			btn.classList.add('csb-tabs__btn--active');
			var panel = document.getElementById(targetId);
			if (panel) panel.hidden = false;
		});
	});

	// ── Fetch signed URL and load into player ────────────────────────────────
	function loadLesson(lessonIndex, videoEl, titleEl) {
		var url = CSB_PLAYER.streamBase + lessonIndex + '/stream';
		fetch(url, {
			method: 'GET',
			headers: { 'X-WP-Nonce': CSB_PLAYER.nonce }
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (!data.success) {
				console.warn('CSB stream error:', data.error);
				return;
			}
			videoEl.src = data.url;
			videoEl.load();
			videoEl.play().catch(function() {});
			if (titleEl) titleEl.textContent = data.title || '';
		})
		.catch(function(e) { console.error('CSB fetch error', e); });
	}

	// ── Report lesson completed ──────────────────────────────────────────────
	function reportProgress(lessonIndex, watchedSec) {
		fetch(CSB_PLAYER.progressUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CSB_PLAYER.nonce
			},
			body: JSON.stringify({ lesson_index: lessonIndex, watched_sec: watchedSec })
		}).catch(function(e) { console.error('CSB progress error', e); });
	}

	// ── Enrolled: playlist click ─────────────────────────────────────────────
	if (CSB_PLAYER.isEnrolled) {
		var videoEl    = document.getElementById('csb-video-player');
		var titleEl    = document.getElementById('csb-player-title');
		var lastIndex  = null;
		var reportedAt = {};

		document.querySelectorAll('.csb-playlist__item').forEach(function(item) {
			function activate() {
				var idx = parseInt(item.dataset.lessonIndex, 10);
				if (isNaN(idx)) return;
				loadLesson(idx, videoEl, titleEl);
				lastIndex = idx;
			}
			item.addEventListener('click', activate);
			item.addEventListener('keydown', function(e) {
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
			});
		});

		// Auto-load first lesson on page load.
		var firstItem = document.querySelector('.csb-playlist__item');
		if (firstItem && videoEl) {
			loadLesson(parseInt(firstItem.dataset.lessonIndex, 10) || 0, videoEl, titleEl);
			lastIndex = 0;
		}

		// Report progress when 80% of a lesson is watched.
		if (videoEl) {
			videoEl.addEventListener('timeupdate', function() {
				if (lastIndex === null || !videoEl.duration) return;
				var pct = videoEl.currentTime / videoEl.duration;
				if (pct >= 0.8 && !reportedAt[lastIndex]) {
					reportedAt[lastIndex] = true;
					reportProgress(lastIndex, Math.floor(videoEl.currentTime));
					// Update UI immediately.
					var item = document.querySelector('[data-lesson-index="' + lastIndex + '"]');
					if (item) item.classList.add('csb-playlist__item--done');
				}
			});
		}

		// Resource download buttons.
		document.querySelectorAll('.csb-resource-btn').forEach(function(btn) {
			btn.addEventListener('click', function(e) {
				e.stopPropagation();
				var idx   = parseInt(btn.dataset.lessonIndex, 10);
				var cid   = parseInt(btn.dataset.courseId, 10);
				var resUrl = CSB_PLAYER.streamBase.replace(/\/\d+\/$/, '/' + cid + '/') + idx + '/resource';
				fetch(resUrl, { headers: { 'X-WP-Nonce': CSB_PLAYER.nonce } })
					.then(function(r) { return r.json(); })
					.then(function(d) {
						if (d.success && d.url) {
							var a = document.createElement('a');
							a.href = d.url;
							a.download = '';
							document.body.appendChild(a);
							a.click();
							document.body.removeChild(a);
						}
					});
			});
		});
	}

	// ── Not enrolled: free-preview click ────────────────────────────────────
	if (!CSB_PLAYER.isEnrolled) {
		var previewWrap   = document.getElementById('csb-preview-wrap');
		var previewPlayer = document.getElementById('csb-preview-player');

		document.querySelectorAll('.csb-lesson--free-preview').forEach(function(item) {
			function activatePreview() {
				var idx = parseInt(item.dataset.lessonIndex, 10);
				if (isNaN(idx) || !previewWrap || !previewPlayer) return;
				loadLesson(idx, previewPlayer, null);
				previewWrap.hidden = false;
				previewWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			}
			item.addEventListener('click', activatePreview);
			item.addEventListener('keydown', function(e) {
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activatePreview(); }
			});
		});
	}
})();
</script>

<?php get_footer( 'shop' ); ?>
