<?php
/**
 * Manages 301 redirects for changed CPT/taxonomy slugs.
 *
 * When an operator changes a slug via the CptControlPage, the old slug is
 * stored so visitors arriving at old URLs are permanently redirected to the
 * new slug — preserving SEO link equity (§16.5.1).
 *
 * Storage: option `csb_slug_redirects` — array of
 *   [ 'old' => '/old-slug/', 'new' => '/new-slug/', 'added_at' => timestamp ]
 *
 * @package CookingSchoolBooking\Support
 */

namespace CookingSchoolBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RedirectManager
 */
class RedirectManager {

	private const OPTION_KEY = 'csb_slug_redirects';

	/**
	 * Hooks into `template_redirect` to serve 301s before WP renders anything.
	 */
	public function init(): void {
		add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
	}

	/**
	 * Checks the current request path against stored old slugs and 301-redirects.
	 */
	public function maybe_redirect(): void {
		$redirects = $this->get_redirects();
		if ( empty( $redirects ) ) {
			return;
		}

		// Normalise current path (strip domain, keep leading slash, no query string).
		$request_path = '/' . ltrim( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '', '/' );
		$request_path = rtrim( $request_path, '/' ) . '/';

		foreach ( $redirects as $entry ) {
			$old = trailingslashit( (string) ( $entry['old'] ?? '' ) );
			$new = trailingslashit( (string) ( $entry['new'] ?? '' ) );

			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}

			// Match exact path or path-with-trailing-segments.
			if ( str_starts_with( $request_path, $old ) ) {
				$target = $new . ltrim( substr( $request_path, strlen( $old ) ), '/' );
				wp_redirect( esc_url_raw( home_url( $target ) ), 301 );
				exit;
			}
		}
	}

	// ─── API ─────────────────────────────────────────────────────────────────

	/**
	 * Records that $old_slug was renamed to $new_slug so a 301 will be served.
	 *
	 * @param string $old_slug Old slug base (e.g. 'classes').
	 * @param string $new_slug New slug base (e.g. 'courses').
	 */
	public static function record_rename( string $old_slug, string $new_slug ): void {
		if ( $old_slug === $new_slug || '' === $old_slug || '' === $new_slug ) {
			return;
		}

		$redirects = self::static_get_redirects();

		// Avoid duplicates.
		foreach ( $redirects as $entry ) {
			if ( $entry['old'] === '/' . ltrim( $old_slug, '/' ) . '/' ) {
				return;
			}
		}

		$redirects[] = [
			'old'      => '/' . ltrim( $old_slug, '/' ) . '/',
			'new'      => '/' . ltrim( $new_slug, '/' ) . '/',
			'added_at' => time(),
		];

		update_option( self::OPTION_KEY, $redirects, false );
	}

	/**
	 * Returns all stored redirects.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_redirects(): array {
		return self::static_get_redirects();
	}

	/**
	 * Static helper (usable without instantiation).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function static_get_redirects(): array {
		$data = get_option( self::OPTION_KEY, [] );
		return is_array( $data ) ? $data : [];
	}
}
