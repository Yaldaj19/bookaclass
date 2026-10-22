<?php
/**
 * WooCommerce Product Type: class (کلاس قابل‌رزرو).
 *
 * The post type remains `product` — this only registers a new WC product type.
 * All meta stored with `_csb_` prefix; logic (capacity, deposit, booking) is
 * wired in later phases.
 *
 * @package BookaClass\Products
 */

namespace BookaClass\Products;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ClassProduct
 */
class ClassProduct extends \WC_Product {

	/** WC product type key — never changes (§16.5.1). */
	protected string $product_type = 'class';

	public function get_type(): string {
		return 'class';
	}

	// ── Capability flags ─────────────────────────────────────────────────────

	/** Classes are virtual — no shipping. */
	public function is_virtual(): bool {
		return true;
	}

	public function is_downloadable(): bool {
		return false;
	}

	// ── Extra data declaration ────────────────────────────────────────────────

	/**
	 * Tells the WC generic data store which extra postmeta keys to load/save.
	 * Keys match the `_csb_*` postmeta names used in the admin panel.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_extra_data(): array {
		return [
			'csb_class_type'         => [ 'default' => 'in_person' ],
			'csb_deposit_type'       => [ 'default' => 'percent' ],
			'csb_deposit_value'      => [ 'default' => 30 ],
			'csb_settle_days'        => [ 'default' => 3 ],
			'csb_cancel_policy_json' => [ 'default' => '' ],
			'csb_level'              => [ 'default' => '' ],
			'csb_prerequisites'      => [ 'default' => '' ],
			'csb_materials'          => [ 'default' => '' ],
			'csb_instructor_id'      => [ 'default' => 0 ],
			'csb_syllabus'           => [ 'default' => '' ],
		];
	}

	// ── Accessors ────────────────────────────────────────────────────────────

	public function get_csb_class_type( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_class_type', $context );
	}

	public function get_csb_deposit_type( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_deposit_type', $context );
	}

	public function get_csb_deposit_value( string $context = 'view' ): float {
		return (float) $this->get_prop( 'csb_deposit_value', $context );
	}

	public function get_csb_settle_days( string $context = 'view' ): int {
		$v = $this->get_prop( 'csb_settle_days', $context );
		return '' !== (string) $v ? (int) $v : 3;
	}

	public function get_csb_cancel_policy_json( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_cancel_policy_json', $context );
	}

	public function get_csb_level( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_level', $context );
	}

	public function get_csb_prerequisites( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_prerequisites', $context );
	}

	public function get_csb_materials( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_materials', $context );
	}

	public function get_csb_instructor_id( string $context = 'view' ): int {
		return (int) $this->get_prop( 'csb_instructor_id', $context );
	}

	public function get_csb_syllabus( string $context = 'view' ): string {
		return (string) $this->get_prop( 'csb_syllabus', $context );
	}

	// ── Setters ──────────────────────────────────────────────────────────────

	public function set_csb_class_type( string $value ): void {
		$this->set_prop( 'csb_class_type', in_array( $value, [ 'in_person', 'online' ], true ) ? $value : 'in_person' );
	}

	public function set_csb_deposit_type( string $value ): void {
		$this->set_prop( 'csb_deposit_type', in_array( $value, [ 'percent', 'fixed' ], true ) ? $value : 'percent' );
	}

	public function set_csb_deposit_value( float $value ): void {
		$this->set_prop( 'csb_deposit_value', max( 0.0, $value ) );
	}

	public function set_csb_settle_days( int $value ): void {
		$this->set_prop( 'csb_settle_days', max( 0, $value ) );
	}

	public function set_csb_cancel_policy_json( string $value ): void {
		if ( '' !== $value && null === json_decode( $value ) ) {
			$value = '';
		}
		$this->set_prop( 'csb_cancel_policy_json', $value );
	}

	public function set_csb_level( string $value ): void {
		$this->set_prop( 'csb_level', sanitize_text_field( $value ) );
	}

	public function set_csb_prerequisites( string $value ): void {
		$this->set_prop( 'csb_prerequisites', sanitize_textarea_field( $value ) );
	}

	public function set_csb_materials( string $value ): void {
		$this->set_prop( 'csb_materials', sanitize_textarea_field( $value ) );
	}

	public function set_csb_instructor_id( int $value ): void {
		$this->set_prop( 'csb_instructor_id', max( 0, $value ) );
	}

	public function set_csb_syllabus( string $value ): void {
		$this->set_prop( 'csb_syllabus', wp_kses_post( $value ) );
	}
}
