<?php
/**
 * Schema.org JSON-LD injection for class and video_course products.
 *
 * - Disables WooCommerce's default Product schema on our types.
 * - Emits Course + hasCourseInstance + Offer for 'class'.
 * - Emits VideoObject for 'video_course'.
 * - Emits BreadcrumbList, Organization, AggregateRating.
 * - Schema output is toggled via csb_seo_config per-type 'enable_schema'.
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

use CookingSchoolBooking\Products\ClassProduct;
use CookingSchoolBooking\Products\VideoCourseProduct;
use CookingSchoolBooking\Support\Jalali;

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
	 * Outputs JSON-LD on single product pages for our types.
	 */
	public function output_schema(): void {
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

	// ── Class schema ──────────────────────────────────────────────────────────

	private function print_class_schema( ClassProduct $product ): void {
		$post_id     = $product->get_id();
		$url         = get_permalink( $post_id );
		$title       = get_the_title( $post_id );
		$description = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
		$image_url   = wp_get_attachment_url( (int) get_post_thumbnail_id( $post_id ) ) ?: '';

		$course_instances = [];
		foreach ( $this->get_sessions( $post_id ) as $session ) {
			$start_iso = ( new \DateTime( $session->start_at, new \DateTimeZone( 'UTC' ) ) )->format( \DateTime::ATOM );
			$end_iso   = ( new \DateTime( $session->start_at, new \DateTimeZone( 'UTC' ) ) )
				->modify( '+' . (int) $session->duration_min . ' minutes' )
				->format( \DateTime::ATOM );

			$mode     = 'online' === $product->get_csb_class_type()
				? 'https://schema.org/OnlineEventAttendanceMode'
				: 'https://schema.org/OfflineEventAttendanceMode';

			$location = 'online' === $product->get_csb_class_type()
				? [ '@type' => 'VirtualLocation', 'url' => esc_url( $session->online_url ?: $url ) ]
				: [ '@type' => 'Place', 'name' => esc_html( $session->location ?: get_bloginfo( 'name' ) ) ];

			$course_instances[] = [
				'@type'                     => 'CourseInstance',
				'name'                      => esc_html( $title ),
				'startDate'                 => $start_iso,
				'endDate'                   => $end_iso,
				'eventAttendanceMode'       => $mode,
				'location'                  => $location,
				'maximumAttendeeCapacity'   => (int) $session->capacity,
				'remainingAttendeeCapacity' => max( 0, (int) $session->capacity - (int) $session->seats_taken ),
			];
		}

		$schema = [
			'@context'          => 'https://schema.org',
			'@type'             => 'Course',
			'@id'               => esc_url( $url . '#course' ),
			'name'              => esc_html( $title ),
			'description'       => esc_html( $description ),
			'url'               => esc_url( $url ),
			'provider'          => $this->org_schema(),
			'offers'            => [
				'@type'         => 'Offer',
				'price'         => $product->get_price(),
				'priceCurrency' => get_woocommerce_currency(),
				'availability'  => $product->is_in_stock()
					? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
				'url'           => esc_url( $url ),
			],
		];

		if ( ! empty( $course_instances ) ) {
			$schema['hasCourseInstance'] = $course_instances;
		}

		if ( $image_url ) {
			$schema['image'] = esc_url( $image_url );
		}

		$rating = $this->aggregate_rating( $product );
		if ( $rating ) {
			$schema['aggregateRating'] = $rating;
		}

		$reviews = $this->review_schema( $post_id );
		if ( ! empty( $reviews ) ) {
			$schema['review'] = $reviews;
		}

		$this->emit( [ $schema, $this->breadcrumb_schema( $post_id ), $this->org_schema_top() ] );
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

		$schema = [
			'@context'    => 'https://schema.org',
			'@type'       => 'VideoObject',
			'@id'         => esc_url( $url . '#video' ),
			'name'        => esc_html( $title ),
			'description' => esc_html( $description ),
			'url'         => esc_url( $url ),
			'provider'    => $this->org_schema(),
			'offers'      => [
				'@type'         => 'Offer',
				'price'         => $product->get_price(),
				'priceCurrency' => get_woocommerce_currency(),
				'availability'  => 'https://schema.org/InStock',
				'url'           => esc_url( $url ),
			],
		];

		if ( $total_min > 0 ) {
			$schema['duration'] = 'PT' . $total_min . 'M';
		}

		if ( $image_url ) {
			$schema['thumbnailUrl'] = esc_url( $image_url );
			$schema['image']        = esc_url( $image_url );
		}

		$rating = $this->aggregate_rating( $product );
		if ( $rating ) {
			$schema['aggregateRating'] = $rating;
		}

		$reviews = $this->review_schema( $post_id );
		if ( ! empty( $reviews ) ) {
			$schema['review'] = $reviews;
		}

		$this->emit( [ $schema, $this->breadcrumb_schema( $post_id ), $this->org_schema_top() ] );
	}

	// ── Shared builders ───────────────────────────────────────────────────────

	/** @return array<string,string> */
	private function org_schema(): array {
		return [ '@type' => 'Organization', 'name' => esc_html( get_bloginfo( 'name' ) ), 'url' => esc_url( home_url( '/' ) ) ];
	}

	/** @return array<string,mixed> */
	private function org_schema_top(): array {
		return array_merge( [ '@context' => 'https://schema.org' ], $this->org_schema() );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function breadcrumb_schema( int $post_id ): ?array {
		$terms = wp_get_post_terms( $post_id, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}
		$term  = $terms[0];
		return [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => [
				[ '@type' => 'ListItem', 'position' => 1, 'name' => esc_html__( 'خانه', 'csb' ), 'item' => esc_url( home_url( '/' ) ) ],
				[ '@type' => 'ListItem', 'position' => 2, 'name' => esc_html( $term->name ), 'item' => esc_url( (string) get_term_link( $term ) ) ],
				[ '@type' => 'ListItem', 'position' => 3, 'name' => esc_html( get_the_title( $post_id ) ), 'item' => esc_url( (string) get_permalink( $post_id ) ) ],
			],
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
	 * Returns at most 5 most-recent 4-or-5 star reviews (prevents bloat).
	 *
	 * @param  int $post_id  WC product ID.
	 * @return array<int,array<string,mixed>>
	 */
	private function review_schema( int $post_id ): array {
		$reviews = [];

		$comments = get_comments( [
			'post_id'      => $post_id,
			'status'       => 'approve',
			'type'         => 'review',
			'number'       => 5,
			'meta_query'   => [
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
				'@type'        => 'Review',
				'author'       => [
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
	 * Returns upcoming sessions for a class.
	 *
	 * Cached in a 5-minute transient (keyed by class_id) to avoid a DB hit on
	 * every page load.  The transient is intentionally short so capacity numbers
	 * stay reasonably fresh.
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

	/**
	 * Prints each schema object as its own <script type="application/ld+json">.
	 *
	 * @param array<int,array<string,mixed>|null> $schemas
	 */
	private function emit( array $schemas ): void {
		foreach ( $schemas as $schema ) {
			if ( ! is_array( $schema ) || empty( $schema ) ) {
				continue;
			}
			$clean = $this->drop_nulls( $schema );
			echo '<script type="application/ld+json">'
				. wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
				. '</script>' . "\n";
		}
	}

	/**
	 * Recursively removes null values.
	 *
	 * @param  array<string,mixed> $arr
	 * @return array<string,mixed>
	 */
	private function drop_nulls( array $arr ): array {
		foreach ( $arr as $k => $v ) {
			if ( null === $v ) {
				unset( $arr[ $k ] );
			} elseif ( is_array( $v ) ) {
				$arr[ $k ] = $this->drop_nulls( $v );
			}
		}
		return $arr;
	}
}
