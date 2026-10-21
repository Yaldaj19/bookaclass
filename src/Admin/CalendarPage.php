<?php
/**
 * Admin page: Jalali month-view calendar of sessions.
 *
 * آموزشگاه → تقویم
 *
 * Renders an HTML grid of the selected Jalali month.
 * Each day cell lists sessions (title + time + capacity).
 * Navigation links move month ±1.
 * Sessions are loaded via a single prepared SQL query;
 * the Jalali helper converts UTC start_at to a (y, m, d) triple for placement.
 *
 * @package CookingSchoolBooking\Admin
 */

namespace CookingSchoolBooking\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CalendarPage
 */
class CalendarPage {

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_submenu' ] );
	}

	public function register_submenu(): void {
		add_submenu_page(
			'csb-dashboard',
			esc_html__( 'تقویم جلسات', 'csb' ),
			esc_html__( 'تقویم', 'csb' ),
			'manage_woocommerce',
			'csb-calendar',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی غیرمجاز.', 'csb' ) );
		}

		// Determine current Jalali year/month from query-string or default to today.
		[ $today_jy, $today_jm, ] = \CookingSchoolBooking\Support\Jalali::to_jalali(
			(int) gmdate( 'Y' ),
			(int) gmdate( 'm' ),
			(int) gmdate( 'd' )
		);

		$jy = (int) ( $_GET['jy'] ?? $today_jy );
		$jm = (int) ( $_GET['jm'] ?? $today_jm );

		// Clamp.
		if ( $jm < 1 )  { $jm = 12; $jy--; }
		if ( $jm > 12 ) { $jm = 1;  $jy++; }

		// Prev / next month links.
		$prev_jm = $jm - 1;
		$prev_jy = $jy;
		if ( $prev_jm < 1 )  { $prev_jm = 12; $prev_jy--; }
		$next_jm = $jm + 1;
		$next_jy = $jy;
		if ( $next_jm > 12 ) { $next_jm = 1; $next_jy++; }

		$prev_url = add_query_arg( [ 'page' => 'csb-calendar', 'jy' => $prev_jy, 'jm' => $prev_jm ], admin_url( 'admin.php' ) );
		$next_url = add_query_arg( [ 'page' => 'csb-calendar', 'jy' => $next_jy, 'jm' => $next_jm ], admin_url( 'admin.php' ) );

		// Gregorian range for this Jalali month (first + last day).
		[ $g_start_y, $g_start_m, $g_start_d ] = \CookingSchoolBooking\Support\Jalali::to_gregorian( $jy, $jm, 1 );
		$month_days = $jm <= 6 ? 31 : ( $jm <= 11 ? 30 : ( \CookingSchoolBooking\Support\Jalali::is_leap_year( $jy ) ? 30 : 29 ) );
		[ $g_end_y, $g_end_m, $g_end_d ] = \CookingSchoolBooking\Support\Jalali::to_gregorian( $jy, $jm, $month_days );

		$range_start = sprintf( '%04d-%02d-%02d 00:00:00', $g_start_y, $g_start_m, $g_start_d );
		$range_end   = sprintf( '%04d-%02d-%02d 23:59:59', $g_end_y, $g_end_m, $g_end_d );

		// Load sessions for this Jalali month.
		$sessions_by_day = $this->get_sessions_by_day( $range_start, $range_end, $jy, $jm );

		// Day-of-week of the first day (0=Saturday in Jalali calendar; PHP uses 0=Sunday).
		$g_first   = mktime( 0, 0, 0, $g_start_m, $g_start_d, $g_start_y );
		$dow_first = ( (int) gmdate( 'w', $g_first ) + 1 ) % 7; // Saturday=0

		$month_names = [
			1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
			4 => 'تیر',     5 => 'مرداد',    6 => 'شهریور',
			7 => 'مهر',     8 => 'آبان',     9 => 'آذر',
			10 => 'دی',     11 => 'بهمن',    12 => 'اسفند',
		];
		$day_names = [ 'شنبه', 'یک‌شنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه' ];
		?>
		<div class="wrap" dir="rtl">
			<h1><?php esc_html_e( 'تقویم جلسات', 'csb' ); ?></h1>

			<div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
				<a href="<?php echo esc_url( $prev_url ); ?>" class="button">&raquo; <?php esc_html_e( 'ماه قبل', 'csb' ); ?></a>
				<strong style="font-size:18px;">
					<?php echo esc_html( $month_names[ $jm ] . ' ' . $jy ); ?>
				</strong>
				<a href="<?php echo esc_url( $next_url ); ?>" class="button"><?php esc_html_e( 'ماه بعد', 'csb' ); ?> &laquo;</a>
			</div>

			<table style="width:100%;border-collapse:collapse;table-layout:fixed;" dir="rtl">
				<thead>
					<tr>
						<?php foreach ( $day_names as $dn ) : ?>
							<th style="border:1px solid #ccc;padding:6px;text-align:center;background:#f1f1f1;">
								<?php echo esc_html( $dn ); ?>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php
					$cell    = 0;
					$day_num = 1;
					echo '<tr>';

					// Empty cells before first day.
					for ( $i = 0; $i < $dow_first; $i++ ) {
						echo '<td style="border:1px solid #eee;height:80px;vertical-align:top;padding:4px;background:#fafafa;"></td>';
						$cell++;
					}

					while ( $day_num <= $month_days ) {
						if ( $cell > 0 && $cell % 7 === 0 ) {
							echo '</tr><tr>';
						}

						$sessions_today = $sessions_by_day[ $day_num ] ?? [];
						$is_today       = ( $jy === $today_jy && $jm === $today_jm && $day_num === (int) gmdate( 'd' ) );
						$bg             = $is_today ? '#fff3cd' : '#fff';

						echo '<td style="border:1px solid #ccc;height:80px;vertical-align:top;padding:4px;background:' . esc_attr( $bg ) . ';">';
						echo '<div style="font-weight:bold;margin-bottom:4px;">' . esc_html( (string) $day_num ) . '</div>';

						foreach ( $sessions_today as $s ) {
							echo '<div style="font-size:11px;background:#e7f3ff;border-radius:3px;padding:2px 4px;margin-bottom:2px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;" title="' . esc_attr( $s['class_title'] . ' — ' . $s['time_label'] . ' (' . $s['seats_taken'] . '/' . $s['max_capacity'] . ')' ) . '">';
							echo esc_html( $s['class_title'] ) . ' ' . esc_html( $s['time_label'] );
							echo '</div>';
						}

						echo '</td>';
						$cell++;
						$day_num++;
					}

					// Trailing empty cells.
					while ( $cell % 7 !== 0 ) {
						echo '<td style="border:1px solid #eee;height:80px;background:#fafafa;"></td>';
						$cell++;
					}

					echo '</tr>';
					?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Data helper ───────────────────────────────────────────────────────────

	/**
	 * Returns sessions indexed by Jalali day-of-month for fast O(1) cell lookup.
	 *
	 * @param  string $range_start  Gregorian UTC datetime e.g. '2026-03-21 00:00:00'
	 * @param  string $range_end
	 * @param  int    $jy           Target Jalali year (for boundary check)
	 * @param  int    $jm           Target Jalali month
	 * @return array<int,array<int,array<string,string>>>  day_of_month => [ session_rows ]
	 */
	private function get_sessions_by_day( string $range_start, string $range_end, int $jy, int $jm ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.start_at, s.capacity AS max_capacity, s.seats_taken, p.post_title AS class_title
			   FROM {$wpdb->prefix}csb_sessions s
			   JOIN {$wpdb->posts} p ON p.ID = s.class_id
			  WHERE s.start_at BETWEEN %s AND %s
			    AND p.post_status = 'publish'
			  ORDER BY s.start_at ASC",
			$range_start,
			$range_end
		), ARRAY_A ) ?: [];

		$by_day = [];

		foreach ( $rows as $r ) {
			// Parse UTC datetime to Gregorian components.
			$dt   = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $r['start_at'], new \DateTimeZone( 'UTC' ) );
			if ( ! $dt ) {
				continue;
			}

			// Convert to site timezone for display.
			$tz   = wp_timezone();
			$dt   = $dt->setTimezone( $tz );
			$gymd = explode( '-', $dt->format( 'Y-m-d' ) );
			[ $jy2, $jm2, $jd ] = \CookingSchoolBooking\Support\Jalali::to_jalali(
				(int) $gymd[0], (int) $gymd[1], (int) $gymd[2]
			);

			// Only include days in the requested month.
			if ( $jy2 !== $jy || $jm2 !== $jm ) {
				continue;
			}

			$by_day[ $jd ][] = [
				'class_title'  => $r['class_title'],
				'time_label'   => $dt->format( 'H:i' ),
				'max_capacity' => $r['max_capacity'],
				'seats_taken'  => $r['seats_taken'],
			];
		}

		return $by_day;
	}
}
