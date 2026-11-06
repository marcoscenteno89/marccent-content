<?php
/**
 * The Opening hours field type (hours): a week's hours, one value { monday: "08:00-17:00", …, sunday: "" }. A day's
 * hours are "08:00-17:00", or two spans "08:00-12:00, 13:00-17:00"; empty, closed. Times are the site's (Settings →
 * General → Timezone). The field may name a repeating group of the days that differ ("closed_days": its rows' date,
 * hours and note; hours empty: closed all day), read by today's hours, open now and the schema.
 *
 *   {{ business.hours }}            "Mon–Fri 8 AM–5 PM · Sat 9 AM–12 PM"
 *   {{ business.hours:today }}      today's: "8 AM–5 PM", or "Closed"
 *   {{ business.hours:open_now }}   "Open now · until 5 PM", "Closed · opens Mon 8 AM"
 *   {{ business.hours:monday }}     one day's
 *
 * The Field block shows the week as a table, today in bold. In a schema mapping, an hours field is its
 * openingHoursSpecification (and its days that differ, the coming 90, specialOpeningHoursSpecification's).
 */

defined( 'ABSPATH' ) || exit;

const MCC_DAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

// "08:00-12:00, 13:00-17:00" → [ [ '08:00', '12:00' ], [ '13:00', '17:00' ] ]; what doesn't parse is left out.
function mcc_spans( string $hours ): array {
	$spans = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', $hours ) ) ) as $span ) {
		if ( preg_match( '/^(\d{1,2}):?(\d{2})\s*[-–]\s*(\d{1,2}):?(\d{2})$/', $span, $m ) && $m[1] < 25 && $m[3] < 25 && $m[2] < 60 && $m[4] < 60 ) {
			$spans[] = array( sprintf( '%02d:%s', $m[1], $m[2] ), sprintf( '%02d:%s', $m[3], $m[4] ) );
		}
	}
	return $spans;
}

function mcc_spans_text( array $spans ): string {
	return implode( ', ', array_map( fn( $s ) => "$s[0]-$s[1]", $spans ) );
}

// An hours field's value as saved: each day's spans, cleaned ("8:00 - 17:00" → "08:00-17:00").
function mcc_hours_sanitize( $value ): array {
	$value = is_string( $value ) ? (array) json_decode( $value, true ) : (array) $value;
	$out   = array();
	foreach ( MCC_DAYS as $day ) {
		$out[ $day ] = mcc_spans_text( mcc_spans( (string) ( $value[ $day ] ?? '' ) ) );
	}
	return $out;
}

// "17:00" → "5 PM", "17:30" → "5:30 PM".
function mcc_time_text( string $hm ): string {
	return wp_date( str_ends_with( $hm, ':00' ) ? 'g A' : 'g:i A', strtotime( "2000-01-01 $hm" ), new DateTimeZone( 'UTC' ) );
}

function mcc_spans_display( array $spans ): string {
	return $spans ? implode( ', ', array_map( fn( $s ) => mcc_time_text( $s[0] ) . '–' . mcc_time_text( $s[1] ), $spans ) ) : __( 'Closed', 'marccent-content' );
}

// The days that differ, from the field's closed_days group (the site's rows): [ date => spans ].
function mcc_hours_exceptions( array $field ): array {
	$group = (string) ( $field['closed_days'] ?? '' );
	$out   = array();
	foreach ( '' !== $group ? mcc_group_value( $group ) : array() as $row ) {
		$date = (string) ( $row['date'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$out[ $date ] = array( 'spans' => mcc_spans( (string) ( $row['hours'] ?? '' ) ), 'note' => (string) ( $row['note'] ?? '' ) );
		}
	}
	ksort( $out );
	return $out;
}

// A day's spans: its exception's if it has one, else its weekday's.
function mcc_day_spans( array $hours, array $exceptions, DateTimeInterface $day ): array {
	return $exceptions[ $day->format( 'Y-m-d' ) ]['spans'] ?? mcc_spans( (string) ( $hours[ strtolower( $day->format( 'l' ) ) ] ?? '' ) );
}

// Open right now: [ open, text ] ("Open now · until 5 PM", "Closed · opens Mon 8 AM"), looking a week ahead.
function mcc_open_status( array $hours, array $exceptions = array() ): array {
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	$hm  = $now->format( 'H:i' );
	foreach ( mcc_day_spans( $hours, $exceptions, $now ) as [ $open, $close ] ) {
		if ( $hm >= $open && $hm < $close ) {
			/* translators: %s: a time, 5 PM */
			return array( true, sprintf( __( 'Open now · until %s', 'marccent-content' ), mcc_time_text( $close ) ) );
		}
	}
	for ( $i = 0; $i < 8; $i++ ) {
		$day = $now->modify( "+$i day" );
		foreach ( mcc_day_spans( $hours, $exceptions, $day ) as [ $open ] ) {
			if ( $i > 0 || $open > $hm ) {
				$when = 0 === $i ? '' : ( 1 === $i ? __( 'tomorrow', 'marccent-content' ) : wp_date( 'D', $day->getTimestamp() ) ) . ' ';
				/* translators: %s: when, "tomorrow 8 AM", "Mon 8 AM" */
				return array( false, sprintf( __( 'Closed · opens %s', 'marccent-content' ), $when . mcc_time_text( $open ) ) );
			}
		}
	}
	return array( false, __( 'Closed', 'marccent-content' ) );
}

// The week in short, days with the same hours together: "Mon–Fri 8 AM–5 PM · Sat 9 AM–12 PM"; '' with no hours.
function mcc_hours_summary( array $hours ): string {
	$runs = array();
	foreach ( MCC_DAYS as $i => $day ) {
		$text = (string) ( $hours[ $day ] ?? '' );
		$last = count( $runs ) - 1;
		if ( $last >= 0 && $runs[ $last ]['text'] === $text && $runs[ $last ]['to'] === $i - 1 ) {
			$runs[ $last ]['to'] = $i;
		} else {
			$runs[] = array( 'from' => $i, 'to' => $i, 'text' => $text );
		}
	}
	$name = fn( $i ) => wp_date( 'D', strtotime( 'next ' . MCC_DAYS[ $i ] . ' 12:00' ) );
	$out  = array();
	foreach ( $runs as $r ) {
		if ( '' !== $r['text'] ) {
			$out[] = ( $r['from'] === $r['to'] ? $name( $r['from'] ) : $name( $r['from'] ) . '–' . $name( $r['to'] ) ) . ' ' . mcc_spans_display( mcc_spans( $r['text'] ) );
		}
	}
	return implode( ' · ', $out );
}

// An hours field as text (mcc_display_value): the week in short, or today, open now or one day.
function mcc_hours_display( $raw, array $field, string $arg ): string {
	$hours = mcc_hours_sanitize( $raw );
	if ( ! array_filter( $hours ) ) {
		return '';
	}
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	switch ( $arg ) {
		case 'today':
			return mcc_spans_display( mcc_day_spans( $hours, mcc_hours_exceptions( $field ), $now ) );
		case 'open_now':
			return mcc_open_status( $hours, mcc_hours_exceptions( $field ) )[1];
		case '':
			return mcc_hours_summary( $hours );
	}
	return in_array( $arg, MCC_DAYS, true ) ? mcc_spans_display( mcc_spans( $hours[ $arg ] ) ) : '';
}

// The Field block's table: each day, today in bold; the coming days that differ (the next 60) after it.
function mcc_hours_table( $raw, array $field ): string {
	$hours = mcc_hours_sanitize( $raw );
	if ( ! array_filter( $hours ) ) {
		return '';
	}
	$today = strtolower( ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'l' ) );
	$rows  = '';
	foreach ( MCC_DAYS as $day ) {
		$bold  = fn( $text ) => $day === $today ? '<strong>' . esc_html( $text ) . '</strong>' : esc_html( $text );
		$rows .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', $bold( wp_date( 'l', strtotime( "next $day 12:00" ) ) ), $bold( mcc_spans_display( mcc_spans( $hours[ $day ] ) ) ) );
	}
	$soon = array_filter( mcc_hours_exceptions( $field ), fn( $d ) => $d >= wp_date( 'Y-m-d' ) && $d <= wp_date( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ), ARRAY_FILTER_USE_KEY );
	foreach ( $soon as $date => $e ) {
		$rows .= sprintf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( wp_date( 'D, M j', strtotime( "$date 12:00" ) ) ), esc_html( mcc_spans_display( $e['spans'] ) . ( '' !== $e['note'] ? " ({$e['note']})" : '' ) ) );
	}
	return '<table><tbody>' . $rows . '</tbody></table>';
}

// An hours field in a schema mapping: the week as openingHoursSpecification, days with the same hours together.
function mcc_hours_schema( $raw ): array {
	$hours = mcc_hours_sanitize( $raw );
	$same  = array();
	foreach ( MCC_DAYS as $day ) {
		foreach ( mcc_spans( $hours[ $day ] ) as [ $open, $close ] ) {
			$same[ "$open-$close" ][] = ucfirst( $day );
		}
	}
	$out = array();
	foreach ( $same as $span => $days ) {
		[ $open, $close ] = explode( '-', $span );
		$out[]            = array( '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $open, 'closes' => $close );
	}
	return $out;
}

// Its days that differ, the coming 90, as specialOpeningHoursSpecification: a closed day opens and closes at 00:00.
function mcc_hours_special_schema( array $field ): array {
	$out = array();
	foreach ( mcc_hours_exceptions( $field ) as $date => $e ) {
		if ( $date < wp_date( 'Y-m-d' ) || $date > wp_date( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ) ) {
			continue;
		}
		foreach ( $e['spans'] ?: array( array( '00:00', '00:00' ) ) as [ $open, $close ] ) {
			$out[] = array( '@type' => 'OpeningHoursSpecification', 'validFrom' => $date, 'validThrough' => $date, 'opens' => $open, 'closes' => $close );
		}
	}
	return $out;
}
