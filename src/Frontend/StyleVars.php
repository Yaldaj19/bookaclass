<?php
/**
 * Injects CSS custom properties built from the csb_style option,
 * enqueues assets/css/csb.css on relevant pages, and loads Vazirmatn
 * (font-display: swap) when not already enqueued by the active theme.
 *
 * CSS variables emitted (priority 20, front-end only):
 *   --csb-primary        primary button / active colour
 *   --csb-primary-hover  primary on hover / focus
 *   --csb-accent         badges, highlights
 *   --csb-bg             page background
 *   --csb-surface        card / panel background
 *   --csb-text           body text
 *   --csb-muted          secondary / helper text
 *   --csb-border         dividers, input borders
 *   --csb-success        positive states
 *   --csb-danger         errors, warnings
 *   --csb-radius         base border-radius  (px)
 *   --csb-radius-lg      large border-radius (px)
 *   --csb-container      max-width of content container (px)
 *   --csb-font           font-family stack
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

use CookingSchoolBooking\Admin\AppearancePage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StyleVars
 */
class StyleVars {

	/** Google Fonts URL for Vazirmatn (covers wght 100–900, font-display=swap). */
	private const VAZIRMATN_URL = 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap';

	public function init(): void {
		add_action( 'wp_head',             [ $this, 'output_vars' ],    20 );
		add_action( 'wp_head',             [ $this, 'output_preconnect' ], 1 );
		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ]      );
	}

	/**
	 * Emits preconnect hints for Google Fonts origins when Vazirmatn will be loaded.
	 * Runs at priority 1 so the hints appear before any stylesheet links.
	 */
	public function output_preconnect(): void {
		if ( ! $this->is_csb_page() ) {
			return;
		}
		$s = AppearancePage::get();
		if ( 'system' === ( $s['font_family'] ?? '' ) ) {
			return;
		}
		if ( $this->theme_loads_vazirmatn() ) {
			return;
		}
		echo '<link rel="preconnect" href="https://fonts.googleapis.com" />' . "\n";
		echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />' . "\n";
	}

	// ── CSS variable block ────────────────────────────────────────────────────

	/**
	 * Prints a <style id="csb-vars"> block containing all --csb-* custom
	 * properties.  Values come from csb_style; sanitized again at output.
	 * Runs on front-end only (wp_head never fires in admin).
	 */
	public function output_vars(): void {
		$s = AppearancePage::get();

		// Resolve font stack.
		$font_stack = 'system' === ( $s['font_family'] ?? '' )
			? "system-ui, -apple-system, 'Segoe UI', Arial, sans-serif"
			: "'Vazirmatn', system-ui, -apple-system, 'Segoe UI', Arial, sans-serif";

		// Build the variable map — all values escaped at output.
		$vars = [
			'--csb-primary'       => sanitize_hex_color( (string) ( $s['primary']       ?? '' ) ) ?: '#c0392b',
			'--csb-primary-hover' => sanitize_hex_color( (string) ( $s['primary_hover'] ?? '' ) ) ?: '#a93226',
			'--csb-accent'        => sanitize_hex_color( (string) ( $s['accent']        ?? '' ) ) ?: '#e67e22',
			'--csb-bg'            => sanitize_hex_color( (string) ( $s['bg']            ?? '' ) ) ?: '#fdf8f3',
			'--csb-surface'       => sanitize_hex_color( (string) ( $s['surface']       ?? '' ) ) ?: '#ffffff',
			'--csb-text'          => sanitize_hex_color( (string) ( $s['text']          ?? '' ) ) ?: '#1a1a1a',
			'--csb-muted'         => sanitize_hex_color( (string) ( $s['muted']         ?? '' ) ) ?: '#7f8c8d',
			'--csb-border'        => sanitize_hex_color( (string) ( $s['border']        ?? '' ) ) ?: '#e0d5c8',
			'--csb-success'       => sanitize_hex_color( (string) ( $s['success']       ?? '' ) ) ?: '#27ae60',
			'--csb-danger'        => sanitize_hex_color( (string) ( $s['danger']        ?? '' ) ) ?: '#e74c3c',
			'--csb-radius'        => max( 0,   min( 24,   (int) ( $s['radius']        ?? 8 ) ) ) . 'px',
			'--csb-radius-sm'     => max( 0,   min( 16,   (int) ( $s['radius']        ?? 8 ) - 3 ) ) . 'px',
			'--csb-radius-lg'     => max( 0,   min( 40,   (int) ( $s['radius_lg']     ?? 14 ) ) ) . 'px',
			'--csb-container'     => max( 600, min( 1600, (int) ( $s['container_max'] ?? 1200 ) ) ) . 'px',
			'--csb-font'          => $font_stack,
		];

		echo '<style id="csb-vars">:root{';
		foreach ( $vars as $prop => $val ) {
			// Props are static strings; values are sanitized above.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $prop . ':' . esc_attr( $val ) . ';';
		}
		echo '}</style>' . "\n";
	}

	// ── Asset enqueue ─────────────────────────────────────────────────────────

	/**
	 * Enqueues csb.css and optionally Vazirmatn on CSB-relevant pages.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_csb_page() ) {
			return;
		}

		// Front-end stylesheet — ui-designer expands this file.
		wp_enqueue_style(
			'csb-frontend',
			CSB_URL . 'assets/css/csb.css',
			[],
			CSB_VERSION
		);

		// Vazirmatn: only load if the active theme hasn't already queued it.
		$s = AppearancePage::get();
		if ( 'Vazirmatn' === ( $s['font_family'] ?? 'Vazirmatn' )
			&& ! $this->theme_loads_vazirmatn() ) {
			wp_enqueue_style(
				'csb-vazirmatn',
				self::VAZIRMATN_URL,
				[],
				null  // external URL — no version string.
			);
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Returns true when the current request is a CSB-relevant page.
	 */
	private function is_csb_page(): bool {
		// Custom query vars set by Rewrites — archive OR single (csb_product_type is set on both).
		if ( get_query_var( 'csb_archive' ) || '' !== (string) get_query_var( 'csb_product_type' ) ) {
			return true;
		}

		// WC My Account — CSB adds tabs there.
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		// Checkout and cart — deposit/wallet fields.
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		// Single product of our types.
		if ( is_singular( 'product' ) ) {
			$product = wc_get_product( get_the_ID() );
			if ( $product && in_array( $product->get_type(), [ 'class', 'video_course' ], true ) ) {
				return true;
			}
		}

		// Post-type archive (WC or custom).
		if ( is_post_type_archive( 'product' ) ) {
			return true;
		}

		// Certificate public view page.
		if ( ! empty( $_GET['csb_cert'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns true if the active theme or any already-queued stylesheet
	 * contains "Vazirmatn" in its handle or src, so we don't double-load.
	 */
	private function theme_loads_vazirmatn(): bool {
		global $wp_styles;

		if ( ! isset( $wp_styles ) ) {
			return false;
		}

		foreach ( $wp_styles->registered as $handle => $dep ) {
			if ( false !== strpos( strtolower( $handle ), 'vazirmatn' ) ) {
				return true;
			}
			if ( ! empty( $dep->src ) && false !== strpos( strtolower( $dep->src ), 'vazirmatn' ) ) {
				return true;
			}
		}

		return false;
	}
}
