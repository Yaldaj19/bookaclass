<?php
/**
 * WooCommerce Product Type: video_course (ویدیوکلاس).
 *
 * Inherently virtual. Purchase grants an enrollment (Phase 5).
 * Lessons stored as JSON in `_csb_lessons`.
 *
 * @package BookaClass\Products
 */

namespace BookaClass\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class VideoCourseProduct
 */
class VideoCourseProduct extends \WC_Product {

	protected string $product_type = 'video_course';

	public function get_type(): string {
		return 'video_course';
	}

	public function is_virtual(): bool {
		return true;
	}

	public function is_downloadable(): bool {
		return (bool) $this->get_prop( 'csb_is_downloadable', 'view' );
	}

	// ── Extra data ───────────────────────────────────────────────────────────

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function get_extra_data(): array {
		return [
			'csb_access_duration'  => [ 'default' => 0 ],
			'csb_instructor_id'    => [ 'default' => 0 ],
			'csb_lessons'          => [ 'default' => '' ], // JSON string
			'csb_is_downloadable'  => [ 'default' => false ],
		];
	}

	// ── Accessors ────────────────────────────────────────────────────────────

	public function get_csb_access_duration( string $context = 'view' ): int {
		return (int) $this->get_prop( 'csb_access_duration', $context );
	}

	public function get_csb_instructor_id( string $context = 'view' ): int {
		return (int) $this->get_prop( 'csb_instructor_id', $context );
	}

	public function get_csb_lessons_json( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_lessons', $context );
	}

	/**
	 * Returns decoded lessons array.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_csb_lessons(): array {
		$json = $this->get_csb_lessons_json();
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	// ── Setters ──────────────────────────────────────────────────────────────

	public function set_csb_access_duration( int $value ): void {
		$this->set_prop( 'csb_access_duration', max( 0, $value ) );
	}

	public function set_csb_instructor_id( int $value ): void {
		$this->set_prop( 'csb_instructor_id', max( 0, $value ) );
	}

	/**
	 * Sanitises and stores the lessons array as JSON.
	 *
	 * @param array<int,array<string,mixed>> $lessons
	 */
	public function set_csb_lessons( array $lessons ): void {
		$clean = [];
		foreach ( $lessons as $lesson ) {
			if ( ! is_array( $lesson ) ) {
				continue;
			}
			$clean[] = [
				'title'           => sanitize_text_field( $lesson['title'] ?? '' ),
				'order'           => (int) ( $lesson['order'] ?? 0 ),
				'video_ref'       => sanitize_text_field( $lesson['video_ref'] ?? '' ),
				'duration'        => (int) ( $lesson['duration'] ?? 0 ),
				'is_free_preview' => ! empty( $lesson['is_free_preview'] ),
				'resource_url'    => esc_url_raw( $lesson['resource_url'] ?? '' ),
			];
		}
		usort( $clean, fn( $a, $b ) => $a['order'] <=> $b['order'] );
		$this->set_prop( 'csb_lessons', wp_json_encode( $clean ) );
	}
}
