<?php
/**
 * Gregorian ↔ Jalali (Solar Hijri) calendar helpers.
 *
 * All conversions are done without external libraries.
 * Storage: UTC. Display: Asia/Tehran.
 *
 * @package CookingSchoolBooking\Support
 */

namespace CookingSchoolBooking\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Jalali
 *
 * Static helpers for converting dates between Gregorian and Jalali calendars.
 *
 * Algorithm: Borkowski's algorithm as commonly used in open-source Persian
 * date libraries — integer-only arithmetic, no floating-point.
 */
class Jalali {

	private const TIMEZONE = 'Asia/Tehran';

	// ─── Public API ──────────────────────────────────────────────────────────

	/**
	 * Converts a Gregorian datetime string (or DateTime) to a Jalali date string.
	 *
	 * @param  string|\DateTimeInterface $gregorian_datetime UTC datetime.
	 * @param  string                   $format             Output format (default Y/m/d H:i).
	 * @return string
	 */
	public static function toJalali( $gregorian_datetime, string $format = 'Y/m/d H:i' ): string {
		$dt = self::to_datetime( $gregorian_datetime, 'UTC' );
		$dt->setTimezone( new \DateTimeZone( self::TIMEZONE ) );

		[ $jy, $jm, $jd ] = self::gregorian_to_jalali(
			(int) $dt->format( 'Y' ),
			(int) $dt->format( 'n' ),
			(int) $dt->format( 'j' )
		);

		return self::format_jalali( $jy, $jm, $jd, $dt, $format );
	}

	/**
	 * Converts a Jalali date/datetime string to a UTC Gregorian datetime string.
	 *
	 * Accepted input formats: «Y/m/d» or «Y/m/d H:i» or «Y/m/d H:i:s»
	 *
	 * @param  string $jalali_string e.g. «1403/07/22» or «1403/07/22 10:30».
	 * @return string  UTC ISO-8601 datetime: «2024-10-13 07:00:00»
	 */
	public static function toGregorian( string $jalali_string ): string {
		// Parse Jalali string.
		$parts = preg_split( '/[\/ \-:]/', $jalali_string );

		$jy = (int) ( $parts[0] ?? 0 );
		$jm = (int) ( $parts[1] ?? 1 );
		$jd = (int) ( $parts[2] ?? 1 );
		$h  = (int) ( $parts[3] ?? 0 );
		$i  = (int) ( $parts[4] ?? 0 );
		$s  = (int) ( $parts[5] ?? 0 );

		[ $gy, $gm, $gd ] = self::jalali_to_gregorian( $jy, $jm, $jd );

		$dt = new \DateTime(
			sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $gy, $gm, $gd, $h, $i, $s ),
			new \DateTimeZone( self::TIMEZONE )
		);
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );

		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Formats a Gregorian datetime into a Jalali string using a pattern.
	 *
	 * Tokens: Y=year, m=month(01-12), n=month(1-12), d=day(01-31), j=day(1-31),
	 *         H=hour(00-23), i=minute(00-59), s=second(00-59).
	 *
	 * @param  string|\DateTimeInterface $datetime UTC datetime.
	 * @param  string                   $pattern  Format pattern.
	 * @return string
	 */
	public static function format( $datetime, string $pattern = 'Y/m/d' ): string {
		return self::toJalali( $datetime, $pattern );
	}

	// ─── Additional public helpers ───────────────────────────────────────────

	/**
	 * Public wrapper: Gregorian → Jalali.
	 *
	 * @return array{int,int,int} [year, month, day]
	 */
	public static function to_jalali( int $gy, int $gm, int $gd ): array {
		return self::gregorian_to_jalali( $gy, $gm, $gd );
	}

	/**
	 * Public wrapper: Jalali → Gregorian.
	 *
	 * @return array{int,int,int} [year, month, day]
	 */
	public static function to_gregorian( int $jy, int $jm, int $jd ): array {
		return self::jalali_to_gregorian( $jy, $jm, $jd );
	}

	/**
	 * Returns true when $jy is a Jalali leap year.
	 */
	public static function is_leap_year( int $jy ): bool {
		// Leap years in the 33-year cycle: 1,5,9,13,17,22,26,30.
		$cycle = $jy % 33;
		return in_array( $cycle, [ 1, 5, 9, 13, 17, 22, 26, 30 ], true );
	}

	/**
	 * Converts a UTC datetime string to a human-readable Jalali string.
	 *
	 * Used throughout admin and frontend to format session/booking dates.
	 *
	 * @param  string $utc_datetime  e.g. '2026-05-10 14:00:00'
	 * @param  string $format        Jalali format pattern (default Y/m/d H:i)
	 * @return string
	 */
	public static function to_jalali_string( string $utc_datetime, string $format = 'Y/m/d H:i' ): string {
		if ( '' === $utc_datetime || '0000-00-00 00:00:00' === $utc_datetime ) {
			return '—';
		}

		return self::toJalali( $utc_datetime, $format );
	}

	// ─── Core conversion: Gregorian → Jalali ─────────────────────────────────

	/**
	 * @return array{int,int,int} [year, month, day]
	 */
	private static function gregorian_to_jalali( int $gy, int $gm, int $gd ): array {
		$g_days_in_month = [ 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];
		$j_days_in_month = [ 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 ];

		$gy_offset = $gy - 1600;
		$gm_offset = $gm - 1;
		$gd_offset = $gd - 1;

		$g_day_no = 365 * $gy_offset
			+ (int) floor( ( $gy_offset + 3 ) / 4 )
			- (int) floor( ( $gy_offset + 99 ) / 100 )
			+ (int) floor( ( $gy_offset + 399 ) / 400 );

		for ( $i = 0; $i < $gm_offset; $i++ ) {
			$g_day_no += $g_days_in_month[ $i ];
		}

		if ( $gm_offset > 1 && (
			( $gy % 4 === 0 && $gy % 100 !== 0 ) || ( $gy % 400 === 0 )
		) ) {
			++$g_day_no;
		}

		$g_day_no += $gd_offset;

		$j_day_no = $g_day_no - 79;

		$j_np   = (int) floor( $j_day_no / 12053 );
		$j_day_no %= 12053;

		$jy = 979 + 33 * $j_np + 4 * (int) floor( $j_day_no / 1461 );
		$j_day_no %= 1461;

		if ( $j_day_no >= 366 ) {
			$jy       += (int) floor( ( $j_day_no - 1 ) / 365 );
			$j_day_no  = ( $j_day_no - 1 ) % 365;
		}

		$jm = 0;
		for ( $i = 0; $i < 11 && $j_day_no >= $j_days_in_month[ $i ]; $i++ ) {
			$j_day_no -= $j_days_in_month[ $i ];
			$jm++;
		}

		$jd = $j_day_no + 1;
		$jm++;

		return [ $jy, $jm, $jd ];
	}

	// ─── Core conversion: Jalali → Gregorian ─────────────────────────────────

	/**
	 * @return array{int,int,int} [year, month, day]
	 */
	private static function jalali_to_gregorian( int $jy, int $jm, int $jd ): array {
		$j_days_in_month = [ 31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29 ];

		$jy_offset = $jy - 979;
		$jm_offset = $jm - 1;
		$jd_offset = $jd - 1;

		$j_day_no = 365 * $jy_offset + (int) floor( $jy_offset / 33 ) * 8
			+ (int) floor( ( $jy_offset % 33 + 3 ) / 4 );

		for ( $i = 0; $i < $jm_offset; $i++ ) {
			$j_day_no += $j_days_in_month[ $i ];
		}

		$j_day_no += $jd_offset;

		$g_day_no = $j_day_no + 79;

		$gy_offset = 1600 + 400 * (int) floor( $g_day_no / 146097 );
		$g_day_no %= 146097;

		$leap = true;
		if ( $g_day_no >= 36525 ) {
			$g_day_no--;
			$gy_offset += 100 * (int) floor( $g_day_no / 36524 );
			$g_day_no  %= 36524;

			if ( $g_day_no >= 365 ) {
				$g_day_no++;
			} else {
				$leap = false;
			}
		}

		$gy_offset += 4 * (int) floor( $g_day_no / 1461 );
		$g_day_no  %= 1461;

		if ( $g_day_no >= 366 ) {
			$leap       = false;
			$g_day_no--;
			$gy_offset += (int) floor( $g_day_no / 365 );
			$g_day_no  %= 365;
		}

		$g_days_in_month = [ 31, ( $leap ? 29 : 28 ), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ];

		$gm = 0;
		for ( $i = 0; $i < 12 && $g_day_no >= $g_days_in_month[ $i ]; $i++ ) {
			$g_day_no -= $g_days_in_month[ $i ];
			$gm++;
		}

		$gd = $g_day_no + 1;
		$gm++;
		$gy = $gy_offset;

		return [ $gy, $gm, $gd ];
	}

	// ─── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Converts a string or DateTimeInterface to a DateTime object.
	 */
	private static function to_datetime( $value, string $tz = 'UTC' ): \DateTime {
		if ( $value instanceof \DateTimeImmutable ) {
			return \DateTime::createFromImmutable( $value );
		}

		if ( $value instanceof \DateTime ) {
			return clone $value;
		}

		return new \DateTime( (string) $value, new \DateTimeZone( $tz ) );
	}

	/**
	 * Formats Jalali year/month/day + time from DateTime into a pattern string.
	 */
	private static function format_jalali(
		int $jy,
		int $jm,
		int $jd,
		\DateTime $dt,
		string $pattern
	): string {
		$replacements = [
			'Y' => sprintf( '%04d', $jy ),
			'm' => sprintf( '%02d', $jm ),
			'n' => (string) $jm,
			'd' => sprintf( '%02d', $jd ),
			'j' => (string) $jd,
			'H' => $dt->format( 'H' ),
			'i' => $dt->format( 'i' ),
			's' => $dt->format( 's' ),
		];

		// Escape backslash-prefixed characters, then replace tokens.
		$result  = '';
		$escaped = false;

		for ( $pos = 0, $len = strlen( $pattern ); $pos < $len; $pos++ ) {
			$char = $pattern[ $pos ];

			if ( $escaped ) {
				$result  .= $char;
				$escaped  = false;
				continue;
			}

			if ( '\\' === $char ) {
				$escaped = true;
				continue;
			}

			// Multi-char tokens first.
			$matched = false;
			foreach ( $replacements as $token => $value ) {
				if ( str_starts_with( substr( $pattern, $pos ), $token ) ) {
					$result  .= $value;
					$pos     += strlen( $token ) - 1;
					$matched  = true;
					break;
				}
			}

			if ( ! $matched ) {
				$result .= $char;
			}
		}

		return $result;
	}
}
