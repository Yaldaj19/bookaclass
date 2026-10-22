<?php
/**
 * Schema.org JSON-LD injection for class and video_course products.
 *
 * - Disables WooCommerce's default Product schema on our types.
 * - Emits a single @graph per page containing all applicable types.
 * - Singles: Course + hasCourseInstance + Offer + BreadcrumbList +
 *            AggregateRating + Review + Organization + WebSite.
 * - Video singles: VideoObject + Offer + BreadcrumbList +
 *                  AggregateRating + Review + Organization + WebSite.
 * - Archives:  ItemList (one ListItem per visible product card) +
 *              Organization + WebSite.
 * - Schema output is toggled via csb_seo_config per-type 'enable_schema'.
 * - When Rank Math / Yoast is present the title/meta is deferred to them,
 *   but this class STILL emits Course / VideoObject / ItemList because
 *   those plugins do not generate those types.
 *
 * @package BookaClass\Frontend
 */

namespace BookaClass\Frontend;

use BookaClass\Products\ClassProduct;
use BookaClass\Products\ProductTypes;
use BookaClass\Products\VideoCourseProduct;
use BookaClass\Support\Jalali;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema
 */
class Schema {

	/**
	 * Attaches hooks.
	 */
	public function init(): void {
		// Disable WC structured data for our types.
		add_filter( 'woocommerce_structured_data_product', [ $this, 'suppress_wc_schema' ], 10, 2 );

		// Also remove WC_Structured_Data from wp_footer on our singles.
		add_action( 'wp', [ $this, 'maybe_remove_wc_structured_data' ] );

		// Inject our schema in <head> (priority 5 = early, before title/og).
		add_action( 'wp_head', [ $this, 'output_schema' ], 5 );
	}

	// ── WC suppression ────────────────────────────────────────────────────────

	/**
	 * Empties WC's Product schema markup for our types.
	 *
	 * @param  array<string,mixed> $markup
	 * @param  \WC_Product         $product
	 * @return array<string,mixed>
	 */
	public function suppress_wc_schema( array $markup, \WC_Product $product ): array {
		if ( $product instanceof ClassProduct || $product instanceof VideoCourseProduct ) {
			return [];
		}
		return $markup;
	}

	/**
	 * Removes the WC_Structured_Data `generate_website_data` and
	 * `output_structured_data` actions on our product singles so WC doesn't
	 * output an empty <script> block.
	 */
	public function maybe_remove_wc_structured_data(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		$product = wc_get_product( get_the_ID() );
		if ( ! $product || ( ! $product instanceof ClassProduct && ! $product instanceof VideoCourseProduct ) ) {
			return;
		}
		$wc_sd = WC()->structured_data ?? null;
		if ( $wc_sd ) {
			remove_action( 'woocommerce_before_main_content', [ $wc_sd, 'generate_website_data' ], 30 );
			remove_action( 'wp_footer', [ $wc_sd, 'output_structured_data' ] );
		}
	}

	// ── Output ────────────────────────────────────────────────────────────────

	/**
	 * Outputs a single JSON-LD @graph on singles and archive pages.
	 */
	public function output_schema(): void {
		$type       = (string) get_query_var( 'csb_product_type' );
		$is_archive = '1' === (string) get_query_var( 'csb_archive' );

		// ── Archive pages ──────────────────────────────────────────────────
		if ( '' !== $type && $is_archive ) {
			$type_key   = 'class' === $type ? 'type_class' : 'type_video_course';
			$seo_config = get_option( 'csb_seo_config', [] );
			if ( is_array( $seo_config ) && isset( $seo_config[ $type_key ]['enable_schema'] ) && ! $seo_config[ $type_key ]['enable_schema'] ) {
				return;
			}
			$this->print_archive_schema( $type );
			return;
		}

		// ── Single product pages ───────────────────────────────────────────
		if ( ! is_singular( 'product' ) ) {
			return;
		}

		$product = wc_get_product( get_the_ID() );
		if ( ! $product ) {
			return;
		}

		$type_key = null;
		if ( $product instanceof ClassProduct ) {
			$type_key = 'type_class';
		} elseif ( $product instanceof VideoCourseProduct ) {
			$type_key = 'type_video_course';
		}

		if ( null === $type_key ) {
			return;
		}

		// Check config toggle.
		$seo_config = get_option( 'csb_seo_config', [] );
		if ( is_array( $seo_config ) && isset( $seo_config[ $type_key ]['enable_schema'] ) && ! $seo_config[ $type_key ]['enable_schema'] ) {
			return;
		}

		if ( $product instanceof ClassProduct ) {
			$this->print_class_schema( $product );
		} else {
			$this->print_video_schema( $product );
		}
	}

	// ── Archive schema (ItemList) ─────────────────────────────────────────────

	/**
	 * Emits an ItemList graph for archive pages using the current WP_Query posts.
	 *
	 * @param string $type  'class' or 'video_course'.
	 */
	private function print_archive_schema( string $type ): void {
		global $wp_query;

		$config     = ProductTypes::get_config();
		$type_cfg   = $config['types'][ $type ] ?? [];
		$base       = sanitize_title( $type_cfg['classes_base'] ?? ( 'class' === $type ? 'classes' : 'courses' ) );
		$archive_url = trailingslashit( home_url( '/' . $base ) );

		$seo_config    = (array) get_option( 'csb_seo_config', [] );
		$section       = 'class' === $type ? 'type_class' : 'type_video_course';
		$archive_title = $seo_config[ $section ]['archive_title'] ?? ( 'class' === $type ? 'کلاس‌های آموزشی' : 'ویدیوکلاس‌ها' );

		$list_items = [];
		$posts      = $wp_query->posts ?? [];
		$position   = 1;

		foreach ( $posts as $post ) {
			$pid       = (int) $post->ID;
			$permalink = (string) get_permalink( $pid );
			$title     = get_the_title( $pid );
			if ( ! $permalink || ! $title ) {
				continue;
			}
			$list_items[] = [
				'@type'    => 'ListItem',
				'position' => $position++,
				'url'      => esc_url( $permalink ),
				'name'     => esc_html( $title ),
			];
		}

		$graph = [];

		// ItemList.
		if ( ! empty( $list_items ) ) {
			$graph[] = [
				'@type'           => 'ItemList',
				'@id'             => esc_url( $archive_url . '#itemlist' ),
				'name'            => esc_html( $archive_title ),
				'url'             => esc_url( $archive_url ),
				'numberOfItems'   => count( $list_items ),
				'itemListElement' => $list_items,
			];
		}

		$graph[] = $this->org_node();
		$graph[] = $this->website_node();

		$this->emit_graph( $graph );
	}

	// ── Class schema ──────────────────────────────────────────────────────────

	private function print_class_schema( ClassProduct $product ): void {
		$post_id     = $product->get_id();
		$url         = get_permalink( $post_id );
		$title       = get_the_title( $post_id );
		$description = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
		$image_url   = wp_get_attachment_url( (int) get_post_thumbnail_id( $post_id ) ) ?: '';

		// priceValidUntil: use earlybird end if set, else one year from now.
		$eb_until_ts = (int) get_post_meta( $post_id, '_csb_earlybird_until', true );
		$price_valid = $eb_until_ts > time()
			? gmdate( 'Y-m-d', $eb_until_ts )
			: gmdate( 'Y-m-d', strtotime( '+1 year' ) );

		$course_instances = [];
		foreach ( $this->get_sessions( $post_id ) as $session ) {
			$start_iso = ( new \DateTime( $session->start_at, new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM );
			$end_iso   = ( new \DateTime( $session->start_at, new \DateTimeZone( 'UTC' ) ) )
				->modify( '+' . (int) $session->duration_min . ' minutes' )
				->format( \DateTime::ATOM );

			$is_online = 'online' === $product->get_csb_class_type();
			$mode      = $is_online
				? 'https://schema.org/OnlineEventAttendanceMode'
				: 'https://schema.org/OfflineEventAttendanceMode';
			$course_mode = $is_online
				? 'https://schema.org/Online'
				: 'https://schema.org/Blended';

			$location = $is_online
				? [ '@type' => 'VirtualLocation', 'url' => esc_url( $session->online_url ?: $url ) ]
				: [ '@type' => 'Place', 'name' => esc_html( $session->location ?: get_bloginfo( 'name' ) ) ];

			$instance = [
				'@type'                     => 'CourseInstance',
				'name'                      => esc_html( $title ),
				'startDate'                 => $start_iso,
				'endDate'                   => $end_iso,
				'eventAttendanceMode'       => $mode,
				'courseMode'                => $course_mode,
				'location'                  => $location,
				'maximumAttendeeCapacity'   => (int) $session->capacity,
				'remainingAttendeeCapacity' => max( 0, (int) $session->capacity - (int) $session->seats_taken ),
			];

			// Instructor as Person node if available.
			$instructor_node = $this->instructor_person_node( $product );
			if ( $instructor_node ) {
				$instance['instructor'] = $instructor_node;
			}

			$course_instances[] = $instance;
		}

		$offer = $this->offer_node( $product, $url, $price_valid );

		$course = [
			'@type'       => 'Course',
			'@id'         => esc_url( $url . '#course' ),
			'name'        => esc_html( $title ),
			'description' => esc_html( $description ),
			'url'         => esc_url( $url ),
			'provider'    => [ '@id' => esc_url( home_url( '/' ) . '#organization' ) ],
			'offers'      => $offer,
		];

		if ( ! empty( $course_instances ) ) {
			$course['hasCourseInstance'] = $course_instances;
		}

		if ( $image_url ) {
			$course['image'] = esc_url( $image_url );
		}

		$level = $product->get_csb_level();
		if ( $level ) {
			$course['educationalLevel'] = esc_html( $level );
		}

		$rating = $this->aggregate_rating( $product );
		if ( $rating ) {
			$course['aggregateRating'] = $rating;
		}

		$reviews = $this->review_schema( $post_id );
		if ( ! empty( $reviews ) ) {
			$course['review'] = $reviews;
		}

		$breadcrumb = $this->breadcrumb_node( $post_id );

		$graph = array_filter( [
			$course,
			$this->org_node(),
			$this->website_node(),
			$breadcrumb,
		] );

		$this->emit_graph( array_values( $graph ) );
	}

	// ── Video schema ──────────────────────────────────────────────────────────

	private function print_video_schema( VideoCourseProduct $product ): void {
		$post_id     = $product->get_id();
		$url         = get_permalink( $post_id );
		$title       = get_the_title( $post_id );
		$description = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
		$image_url   = wp_get_attachment_url( (int) get_post_thumbnail_id( $post_id ) ) ?: '';
		$lessons     = $product->get_csb_lessons();
		$total_min   = array_sum( array_column( $lessons, 'duration' ) );

		// priceValidUntil.
		$price_valid = gmdate( 'Y-m-d', strtotime( '+1 year' ) );

		// uploadDate = post published date (best approximation we have).
		$post_obj    = get_post( $post_id );
		$upload_date = $post_obj instanceof \WP_Post
			? gmdate( 'Y-m-d', strtotime( $post_obj->post_date_gmt ) )
			: gmdate( 'Y-m-d' );

		$video = [
			'@type'       => 'VideoObject',
			'@id'         => esc_url( $url . '#video' ),
			'name'        => esc_html( $title ),
			'description' => esc_html( $description ),
			'url'         => esc_url( $url ),
			'uploadDate'  => $upload_date,
			'offers'      => $this->offer_node( $product, $url, $price_valid ),
		];

		if ( $total_min > 0 ) {
			$video['duration'] = 'PT' . $total_min . 'M';
		}

		if ( $image_url ) {
			$video['thumbnailUrl'] = esc_url( $image_url );
			$video['image']        = esc_url( $image_url );
		}

		$level = (string) get_post_meta( $post_id, '_csb_level', true );
		if ( $level ) {
			$video['educationalLevel'] = esc_html( $level );
		}

		$instructor_node = $this->instructor_person_node( $product );
		if ( $instructor_node ) {
			$video['creator'] = $instructor_node;
		}

		$rating = $this->aggregate_rating( $product );
		if ( $rating ) {
			$video['aggregateRating'] = $rating;
		}

		$reviews = $this->review_schema( $post_id );
		if ( ! empty( $reviews ) ) {
			$video['review'] = $reviews;
		}

		$breadcrumb = $this->breadcrumb_node( $post_id );

		$graph = array_filter( [
			$video,
			$this->org_node(),
			$this->website_node(),
			$breadcrumb,
		] );

		$this->emit_graph( array_values( $graph ) );
	}

	// ── Shared node builders ──────────────────────────────────────────────────

	/**
	 * Builds a full Offer node.
	 *
	 * @param  \WC_Product $product
	 * @param  string      $url
	 * @param  string      $price_valid  ISO date string for priceValidUntil.
	 * @return array<string,mixed>
	 */
	private function offer_node( \WC_Product $product, string $url, string $price_valid ): array {
		$price = $product->get_price();

		$offer = [
			'@type'           => 'Offer',
			'priceCurrency'   => get_woocommerce_currency(),
			'availability'    => $product->is_in_stock()
				? 'https://schema.org/InStock'
				: 'https://schema.org/SoldOut',
			'url'             => esc_url( $url ),
			'priceValidUntil' => $price_valid,
		];

		// Only include price when it is a non-empty numeric string.
		if ( '' !== (string) $price ) {
			$offer['price'] = $price;
		}

		return $offer;
	}

	/**
	 * Builds the Organization node with @id for cross-reference.
	 *
	 * @return array<string,mixed>
	 */
	private function org_node(): array {
		return [
			'@type' => 'Organization',
			'@id'   => esc_url( home_url( '/' ) . '#organization' ),
			'name'  => esc_html( get_bloginfo( 'name' ) ),
			'url'   => esc_url( home_url( '/' ) ),
		];
	}

	/**
	 * Builds the WebSite node with @id for cross-reference.
	 *
	 * @return array<string,mixed>
	 */
	private function website_node(): array {
		return [
			'@type' => 'WebSite',
			'@id'   => esc_url( home_url( '/' ) . '#website' ),
			'url'   => esc_url( home_url( '/' ) ),
			'name'  => esc_html( get_bloginfo( 'name' ) ),
		];
	}

	/**
	 * Builds a BreadcrumbList node for a single product page.
	 * Returns null only when the product has no category (falls back to 2-item crumb).
	 *
	 * @return array<string,mixed>|null
	 */
	private function breadcrumb_node( int $post_id ): ?array {
		$items   = [];
		$items[] = [
			'@type'    => 'ListItem',
			'position' => 1,
			'name'     => esc_html__( 'خانه', 'csb' ),
			'item'     => esc_url( home_url( '/' ) ),
		];

		$terms = wp_get_post_terms( $post_id, 'product_cat' );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$term    = $terms[0];
			$items[] = [
				'@type'    => 'ListItem',
				'position' => 2,
				'name'     => esc_html( $term->name ),
				'item'     => esc_url( (string) get_term_link( $term ) ),
			];
			$items[] = [
				'@type'    => 'ListItem',
				'position' => 3,
				'name'     => esc_html( get_the_title( $post_id ) ),
				'item'     => esc_url( (string) get_permalink( $post_id ) ),
			];
		} else {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => 2,
				'name'     => esc_html( get_the_title( $post_id ) ),
				'item'     => esc_url( (string) get_permalink( $post_id ) ),
			];
		}

		return [
			'@type'           => 'BreadcrumbList',
			'@id'             => esc_url( (string) get_permalink( $post_id ) . '#breadcrumb' ),
			'itemListElement' => $items,
		];
	}

	/**
	 * Builds a Person node for the instructor, or null if not set.
	 *
	 * @return array<string,mixed>|null
	 */
	private function instructor_person_node( \WC_Product $product ): ?array {
		if ( ! method_exists( $product, 'get_csb_instructor_id' ) ) {
			return null;
		}
		$uid  = (int) $product->get_csb_instructor_id();
		if ( $uid <= 0 ) {
			return null;
		}
		$user = get_user_by( 'id', $uid );
		if ( ! $user ) {
			return null;
		}
		return [
			'@type' => 'Person',
			'name'  => esc_html( $user->display_name ),
		];
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function aggregate_rating( \WC_Product $product ): ?array {
		$count  = $product->get_review_count();
		$rating = (float) $product->get_average_rating();
		if ( $count < 1 || 0.0 === $rating ) {
			return null;
		}
		return [
			'@type'       => 'AggregateRating',
			'ratingValue' => number_format( $rating, 1 ),
			'reviewCount' => (int) $count,
			'bestRating'  => 5,
			'worstRating' => 1,
		];
	}

	/**
	 * Builds an array of Review schema objects from approved WC comments.
	 * Returns at most 5 most-recent 3-or-higher star reviews.
	 *
	 * @param  int $post_id  WC product ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function review_schema( int $post_id ): array {
		$reviews  = [];
		$comments = get_comments( [
			'post_id'    => $post_id,
			'status'     => 'approve',
			'type'       => 'review',
			'number'     => 5,
			'meta_query' => [
				[
					'key'     => 'rating',
					'value'   => 3,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				],
			],
		] );

		foreach ( $comments as $comment ) {
			$rating = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( $rating < 1 ) {
				continue;
			}
			$reviews[] = [
				'@type'         => 'Review',
				'author'        => [
					'@type' => 'Person',
					'name'  => esc_html( $comment->comment_author ),
				],
				'datePublished' => gmdate( 'Y-m-d', strtotime( $comment->comment_date_gmt ) ),
				'reviewBody'    => esc_html( $comment->comment_content ),
				'reviewRating'  => [
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => 5,
					'worstRating' => 1,
				],
			];
		}

		return $reviews;
	}

	/**
	 * Returns upcoming sessions for a class (5-minute transient cache).
	 *
	 * @return array<int,object>
	 */
	private function get_sessions( int $class_id ): array {
		global $wpdb;

		$cache_key = 'csb_schema_sessions_' . $class_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, start_at, duration_min, capacity, seats_taken, location, online_url
				 FROM {$wpdb->prefix}csb_sessions
				 WHERE class_id = %d AND status IN ('scheduled','running') AND start_at > UTC_TIMESTAMP()
				 ORDER BY start_at ASC LIMIT 20",
				$class_id
			)
		) ?: [];

		set_transient( $cache_key, $rows, 5 * MINUTE_IN_SECONDS );

		return $rows;
	}

	// ── Emit ──────────────────────────────────────────────────────────────────

	/**
	 * Prints a single <script type="application/ld+json"> containing one
	 * @graph with all nodes.  Null/empty nodes are stripped before output.
	 *
	 * @param array<int,array<string,mixed>> $nodes
	 */
	private function emit_graph( array $nodes ): void {
		$clean = [];
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && ! empty( $node ) ) {
				$clean[] = $this->drop_nulls( $node );
			}
		}
		if ( empty( $clean ) ) {
			return;
		}

		$graph = [
			'@context' => 'https://schema.org',
			'@graph'   => $clean,
		];

		echo '<script type="application/ld+json">'
			. wp_json_encode( $graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. '</script>' . "\n";
	}

	/**
	 * Recursively removes null values and empty strings from schema arrays.
	 *
	 * @param  array<string,mixed> $arr
	 * @return array<string,mixed>
	 */
	private function drop_nulls( array $arr ): array {
		foreach ( $arr as $k => $v ) {
			if ( null === $v || '' === $v ) {
				unset( $arr[ $k ] );
			} elseif ( is_array( $v ) ) {
				$arr[ $k ] = $this->drop_nulls( $v );
			}
		}
		return $arr;
	}
}
