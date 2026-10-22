<?php
/**
 * Minimal WP_Sitemaps_Provider implementation that adds our two archive
 * pages (/classes/ and /courses/) to the WordPress core sitemap.
 *
 * Only registered when neither Rank Math nor Yoast is active.
 *
 * @package CookingSchoolBooking\Frontend
 */

namespace CookingSchoolBooking\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
	return;
}

/**
 * Class CsbArchiveSitemapProvider
 */
class CsbArchiveSitemapProvider extends \WP_Sitemaps_Provider {

	/** @var string */
	public $name = 'csb-archives';

	/** @var string */
	public $object_type = 'csb_archive';

	/**
	 * Returns the list of URLs for page 1.
	 *
	 * @param  string $object_subtype  Unused.
	 * @param  int    $page_num        Page number (we only ever have page 1).
	 * @return array<int,array<string,string>>
	 */
	public function get_url_list( $page_num, $object_subtype = '' ): array {
		if ( (int) $page_num !== 1 ) {
			return [];
		}

		$urls    = Sitemap::get_archive_urls();
		$entries = [];

		foreach ( $urls as $url ) {
			$entries[] = [ 'loc' => $url ];
		}

		return $entries;
	}

	/**
	 * Returns total page count — always 1.
	 *
	 * @param  string $object_subtype  Unused.
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ): int {
		return empty( Sitemap::get_archive_urls() ) ? 0 : 1;
	}
}
