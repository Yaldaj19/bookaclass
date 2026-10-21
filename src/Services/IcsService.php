<?php
/**
 * ICS calendar export service.
 *
 * Generates RFC 5545-compliant iCalendar content for a single booking's session.
 * Served from My Account via a GET request with ?csb_ics=BOOKING_ID&csb_ics_nonce=NONCE.
 *
 * Timezone: Asia/Tehran (IANA).  A VTIMEZONE component is embedded for clients
 * that cannot look up the TZID themselves.
 *
 * @package CookingSchoolBooking\Services
 */

namespace CookingSchoolBooking\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IcsService
 */
class IcsService {

	private const TZID = 'Asia/Tehran';

	/**
	 * Returns the raw iCalendar text for a booking session.
	 *
	 * @param  int $booking_id
	 * @param  int $student_id  Must own the booking (ownership enforced by caller).
	 * @return string  Full VCALENDAR text ready to write to response.
	 * @throws \InvalidArgumentException  If booking/session not found.
	 */
	public static function generate( int $booking_id, int $student_id ): string {
		$booking = BookingService::get( $booking_id );
		if ( ! $booking || (int) $booking['student_id'] !== $student_id ) {
			throw new \InvalidArgumentException( "Booking {$booking_id} not found or access denied." );
		}

		$session = CapacityService::get_session( (int) $booking['session_id'] );
		if ( ! $session ) {
			throw new \InvalidArgumentException( "Session for booking {$booking_id} not found." );
		}

		$class_id    = (int) $session['class_id'];
		$class_title = (string) get_the_title( $class_id );
		$class_url   = (string) get_permalink( $class_id );

		// Build TZID-aware datetime stamps.
		$tz         = new \DateTimeZone( self::TZID );
		$start_ts   = strtotime( (string) $session['start_at'] );
		$duration   = (int) ( $session['duration_min'] ?? 60 );
		$end_ts     = $start_ts + $duration * 60;

		$dt_start = ( new \DateTime( 'now', $tz ) )->setTimestamp( (int) $start_ts );
		$dt_end   = ( new \DateTime( 'now', $tz ) )->setTimestamp( $end_ts );

		$dtstart = $dt_start->format( 'Ymd\THis' );
		$dtend   = $dt_end->format( 'Ymd\THis' );
		$dtstamp = gmdate( 'Ymd\THis\Z' ); // UTC stamp of when the ICS was generated.

		$location = ! empty( $session['online_url'] )
			? (string) $session['online_url']
			: ( (string) ( $session['location'] ?? '' ) ?: (string) get_bloginfo( 'name' ) );

		$uid = 'csb-booking-' . $booking_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

		$description = sprintf(
			/* translators: 1: booking ID  2: class URL */
			__( 'رزرو #%1$d — %2$s', 'csb' ),
			$booking_id,
			$class_url
		);

		$vtimezone = self::vtimezone_for_year( (int) $dt_start->format( 'Y' ) );

		$lines   = [];
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Cooking School Booking//CSB//FA';
		$lines[] = 'CALSCALE:GREGORIAN';
		$lines[] = 'METHOD:PUBLISH';

		// Inline VTIMEZONE component.
		foreach ( $vtimezone as $l ) {
			$lines[] = $l;
		}

		$lines[] = 'BEGIN:VEVENT';
		$lines[] = 'UID:' . $uid;
		$lines[] = 'DTSTAMP:' . $dtstamp;
		$lines[] = 'DTSTART;TZID=' . self::TZID . ':' . $dtstart;
		$lines[] = 'DTEND;TZID=' . self::TZID . ':' . $dtend;
		$lines[] = 'SUMMARY:' . self::escape_ics_text( $class_title );
		$lines[] = 'DESCRIPTION:' . self::escape_ics_text( $description );
		$lines[] = 'LOCATION:' . self::escape_ics_text( $location );
		$lines[] = 'URL:' . esc_url_raw( $class_url );
		$lines[] = 'STATUS:CONFIRMED';
		$lines[] = 'END:VEVENT';
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( [ self::class, 'fold_line' ], $lines ) ) . "\r\n";
	}

	/**
	 * Returns a Google Calendar «add event» URL for a booking's session.
	 *
	 * The returned URL can be used directly in an <a> tag.
	 *
	 * @param  int $booking_id
	 * @param  int $student_id  Must own the booking.
	 * @return string  URL or empty string on failure.
	 */
	public static function add_to_google_calendar_url( int $booking_id, int $student_id ): string {
		$booking = BookingService::get( $booking_id );
		if ( ! $booking || (int) $booking['student_id'] !== $student_id ) {
			return '';
		}

		$session = CapacityService::get_session( (int) $booking['session_id'] );
		if ( ! $session ) {
			return '';
		}

		$class_id    = (int) $session['class_id'];
		$class_title = (string) get_the_title( $class_id );
		$class_url   = (string) get_permalink( $class_id );
		$duration    = (int) ( $session['duration_min'] ?? 60 );
		$start_ts    = (int) strtotime( (string) $session['start_at'] );
		$end_ts      = $start_ts + $duration * 60;

		// Google Calendar needs UTC datetimes: YYYYMMDDTHHMMSSZ.
		$gc_start = gmdate( 'Ymd\THis\Z', $start_ts );
		$gc_end   = gmdate( 'Ymd\THis\Z', $end_ts );

		$location = ! empty( $session['online_url'] )
			? (string) $session['online_url']
			: ( (string) ( $session['location'] ?? '' ) );

		$description = sprintf(
			/* translators: %s: class URL */
			__( 'مشاهده جزئیات: %s', 'csb' ),
			$class_url
		);

		return add_query_arg(
			[
				'action'   => 'TEMPLATE',
				'text'     => rawurlencode( $class_title ),
				'dates'    => rawurlencode( $gc_start . '/' . $gc_end ),
				'details'  => rawurlencode( $description ),
				'location' => rawurlencode( $location ),
				'sf'       => 'true',
				'output'   => 'xml',
			],
			'https://calendar.google.com/calendar/render'
		);
	}

	// ── VTIMEZONE ─────────────────────────────────────────────────────────────

	/**
	 * Returns VTIMEZONE lines for Asia/Tehran.
	 *
	 * Iran uses IRST (UTC+3:30) in winter and IRDT (UTC+4:30) in summer.
	 * Iran stopped observing DST from 2005 onward. This covers both historical
	 * and current usage by embedding a single STANDARD component (UTC+3:30, no DST).
	 *
	 * For historical sessions that had DST (pre-2005), including a DAYLIGHT
	 * component would be more accurate, but for a booking system scheduling
	 * future sessions, the no-DST representation is correct.
	 *
	 * @param  int $year  Year of the session (unused; kept for future DST toggle).
	 * @return string[]
	 */
	private static function vtimezone_for_year( int $year ): array {
		return [
			'BEGIN:VTIMEZONE',
			'TZID:Asia/Tehran',
			'BEGIN:STANDARD',
			'TZOFFSETFROM:+0330',
			'TZOFFSETTO:+0330',
			'TZNAME:IRST',
			'DTSTART:19700101T000000',
			'END:STANDARD',
			'END:VTIMEZONE',
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Escapes special characters in iCal text values per RFC 5545.
	 *
	 * @param  string $text
	 * @return string
	 */
	private static function escape_ics_text( string $text ): string {
		$text = str_replace( '\\', '\\\\', $text );
		$text = str_replace( ';', '\;', $text );
		$text = str_replace( ',', '\,', $text );
		$text = str_replace( "\n", '\n', $text );
		$text = str_replace( "\r", '', $text );
		return $text;
	}

	/**
	 * Folds a long iCal property line at 75 octets (RFC 5545 §3.1).
	 *
	 * @param  string $line
	 * @return string  Folded line (CRLF + SPACE for continuation).
	 */
	private static function fold_line( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$folded = '';
		$first  = true;
		while ( '' !== $line ) {
			$take    = $first ? 75 : 74;
			$first   = false;
			$chunk   = substr( $line, 0, $take );
			$line    = substr( $line, $take );
			$folded .= ( '' !== $folded ? "\r\n " : '' ) . $chunk;
		}

		return $folded;
	}
}
