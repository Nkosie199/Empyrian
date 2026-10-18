<?php
// One stored schedule everyone shares, so every listener hears the same track at the same moment.
// Admins can pin a track to a time; it airs when the track playing at that time ends.

const EMPYRIAN_RADIO_KEEP_PAST = 4 * HOUR_IN_SECONDS;

function empyrian_radio_schedule() {
	$schedule = get_option( 'empyrian_radio_schedule', array() );
	return is_array( $schedule ) ? $schedule : array();
}

function empyrian_radio_pins() {
	$pins = get_option( 'empyrian_radio_pins', array() );
	return is_array( $pins ) ? $pins : array();
}

function empyrian_radio_save_pins( array $pins ) {
	usort(
		$pins,
		function ( $a, $b ) {
			return $a['at'] <=> $b['at'];
		}
	);
	update_option( 'empyrian_radio_pins', array_values( $pins ), false );
}

// Keeps what has already aired, and the plan up to the first track that is no longer available.
function empyrian_radio_still_valid_schedule( array $tracks, $now ) {
	$valid = array();
	foreach ( empyrian_radio_schedule() as $slot ) {
		if ( $slot['start'] > $now && ! isset( $tracks[ $slot['id'] ] ) ) {
			break;
		}
		if ( $slot['start'] + $slot['duration'] >= $now - EMPYRIAN_RADIO_KEEP_PAST ) {
			$valid[] = $slot;
		}
	}
	return $valid;
}

function empyrian_radio_due_pin( array $tracks, array $schedule, $at ) {
	$placed = array_filter( array_column( $schedule, 'pin' ) );
	foreach ( empyrian_radio_pins() as $pin ) {
		if ( $pin['at'] <= $at && ! in_array( $pin['key'], $placed, true ) && isset( $tracks[ $pin['id'] ] ) ) {
			return $pin;
		}
	}
	return null;
}

function empyrian_radio_extend_schedule( array $tracks, $now, array $avoid = array() ) {
	$schedule = empyrian_radio_still_valid_schedule( $tracks, $now );
	if ( count( $tracks ) >= EMPYRIAN_RADIO_MIN_TRACKS ) {
		$last = end( $schedule );
		$at   = $last ? max( $now, $last['start'] + $last['duration'] ) : $now;
		while ( $at < $now + EMPYRIAN_RADIO_HORIZON ) {
			$pin  = empyrian_radio_due_pin( $tracks, $schedule, $at );
			$id   = $pin ? $pin['id'] : empyrian_radio_pick( array_diff_key( $tracks, array_flip( $avoid ) ), $schedule, $at );
			$slot = array(
				'id'       => $id,
				'start'    => $at,
				'duration' => $tracks[ $id ]['duration'],
			);
			if ( $pin ) {
				$slot['pin'] = $pin['key'];
			}
			$schedule[] = $slot;
			$at        += $tracks[ $id ]['duration'];
			$avoid      = array();
		}
	}
	update_option( 'empyrian_radio_schedule', $schedule, false );
	return $schedule;
}

// Drops everything planned from $from on and plans it again, optionally keeping one track out of the next slot.
function empyrian_radio_replan_from( $from, array $avoid = array() ) {
	$kept = array_filter(
		empyrian_radio_schedule(),
		function ( $slot ) use ( $from ) {
			return $slot['start'] < $from;
		}
	);
	update_option( 'empyrian_radio_schedule', array_values( $kept ), false );
	return empyrian_radio_extend_schedule( empyrian_radio_tracks(), time(), $avoid );
}

// No track twice within 4 hours, no artist twice within 45 minutes; new uploads get double airtime.
function empyrian_radio_pick( array $tracks, array $schedule, $at ) {
	$recent_tracks  = array();
	$recent_artists = array();
	$last_played    = array_fill_keys( array_keys( $tracks ), 0 );
	foreach ( $schedule as $slot ) {
		if ( isset( $last_played[ $slot['id'] ] ) ) {
			$last_played[ $slot['id'] ] = max( $last_played[ $slot['id'] ], $slot['start'] );
		}
		if ( $slot['start'] > $at - 4 * HOUR_IN_SECONDS ) {
			$recent_tracks[ $slot['id'] ] = true;
		}
		if ( $slot['start'] > $at - 45 * MINUTE_IN_SECONDS && isset( $tracks[ $slot['id'] ] ) ) {
			$recent_artists[ strtolower( $tracks[ $slot['id'] ]['artist'] ) ] = true;
		}
	}
	$unplayed = array_diff_key( $tracks, $recent_tracks );
	$fresh    = array_filter(
		$unplayed,
		function ( $track ) use ( $recent_artists ) {
			return ! isset( $recent_artists[ strtolower( $track['artist'] ) ] );
		}
	);
	if ( ! $fresh ) {
		asort( $last_played );
		$fresh = $unplayed ? $unplayed : array_intersect_key( $tracks, array_slice( $last_played, 0, (int) ceil( count( $tracks ) / 2 ), true ) );
	}
	$weighted = array();
	foreach ( $fresh as $track ) {
		$weight = $track['published'] > $at - 14 * DAY_IN_SECONDS ? 2 : 1;
		for ( $i = 0; $i < $weight; $i++ ) {
			$weighted[] = $track['id'];
		}
	}
	return $weighted[ wp_rand( 0, count( $weighted ) - 1 ) ];
}

function empyrian_radio_slots( $now, $hours ) {
	$tracks   = empyrian_radio_tracks();
	$schedule = empyrian_radio_schedule();
	$last     = $schedule ? end( $schedule ) : null;
	if ( ! $last || $last['start'] + $last['duration'] < $now + $hours * HOUR_IN_SECONDS ) {
		$schedule = empyrian_radio_extend_schedule( $tracks, $now );
	}
	$slots = array();
	foreach ( $schedule as $slot ) {
		$end = $slot['start'] + $slot['duration'];
		if ( $end > $now && $slot['start'] < $now + $hours * HOUR_IN_SECONDS && isset( $tracks[ $slot['id'] ] ) ) {
			$track             = $tracks[ $slot['id'] ];
			$track['startsAt'] = gmdate( 'c', $slot['start'] );
			$track['start']    = $slot['start'];
			$track['pinned']   = isset( $slot['pin'] );
			unset( $track['published'] );
			$slots[] = $track;
		}
	}
	return $slots;
}
