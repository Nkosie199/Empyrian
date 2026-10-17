<?php
// Empyrian Radio: one always-on station on a shared clock, built from Play Block's public catalogue.

const EMPYRIAN_RADIO_NAME       = 'Empyrian Radio';
const EMPYRIAN_RADIO_HORIZON    = DAY_IN_SECONDS;
const EMPYRIAN_RADIO_MIN_TRACKS = 5;

require_once __DIR__ . '/radio-duration.php';
require_once __DIR__ . '/radio-catalogue.php';
require_once __DIR__ . '/radio-embed.php';

add_action( 'empyrian_radio_refresh', empyrian_safe( 'empyrian_radio_refresh' ) );
add_action( 'empyrian_radio_refresh_soon', empyrian_safe( 'empyrian_radio_refresh' ) );
add_action(
	'init',
	empyrian_safe(
		function () {
			if ( ! wp_next_scheduled( 'empyrian_radio_refresh' ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'empyrian_radio_refresh' );
			}
		}
	)
);
add_action(
	'save_post',
	empyrian_safe(
		function ( $post_id ) {
			if ( ! wp_is_post_revision( $post_id ) && ! wp_next_scheduled( 'empyrian_radio_refresh_soon' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'empyrian_radio_refresh_soon' );
			}
		}
	)
);
add_action(
	'switch_theme',
	empyrian_safe(
		function () {
			wp_clear_scheduled_hook( 'empyrian_radio_refresh' );
		}
	)
);

function empyrian_radio_refresh() {
	empyrian_radio_extend_schedule( empyrian_radio_refresh_batch(), time() );
}

function empyrian_radio_still_valid_schedule( array $tracks, $now ) {
	$stored = get_option( 'empyrian_radio_schedule', array() );
	$valid  = array();
	foreach ( is_array( $stored ) ? $stored : array() as $slot ) {
		$upcoming = $slot['start'] > $now;
		if ( $upcoming && ! isset( $tracks[ $slot['id'] ] ) ) {
			break;
		}
		if ( $slot['start'] + $slot['duration'] >= $now - 4 * HOUR_IN_SECONDS ) {
			$valid[] = $slot;
		}
	}
	return $valid;
}

function empyrian_radio_extend_schedule( array $tracks, $now ) {
	$schedule = empyrian_radio_still_valid_schedule( $tracks, $now );
	if ( count( $tracks ) >= EMPYRIAN_RADIO_MIN_TRACKS ) {
		$last = end( $schedule );
		$at   = $last ? max( $now, $last['start'] + $last['duration'] ) : $now;
		while ( $at < $now + EMPYRIAN_RADIO_HORIZON ) {
			$id         = empyrian_radio_pick( $tracks, $schedule, $at );
			$schedule[] = array(
				'id'       => $id,
				'start'    => $at,
				'duration' => $tracks[ $id ]['duration'],
			);
			$at        += $tracks[ $id ]['duration'];
		}
	}
	update_option( 'empyrian_radio_schedule', $schedule, false );
	return $schedule;
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
	$schedule = get_option( 'empyrian_radio_schedule', array() );
	$last     = is_array( $schedule ) && $schedule ? end( $schedule ) : null;
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
			unset( $track['published'] );
			$slots[] = $track;
		}
	}
	return $slots;
}

function empyrian_radio_now( $now ) {
	$slots = empyrian_radio_slots( $now, 1 );
	if ( ! $slots ) {
		return array(
			'station' => EMPYRIAN_RADIO_NAME,
			'offAir'  => true,
			'now'     => null,
			'next'    => array(),
		);
	}
	$current = array_shift( $slots );
	return array(
		'station'       => EMPYRIAN_RADIO_NAME,
		'offAir'        => false,
		'now'           => $current,
		'startedAt'     => $current['startsAt'],
		'offsetSeconds' => max( 0, $now - $current['start'] ),
		'next'          => array_slice( $slots, 0, 3 ),
	);
}

function empyrian_radio_cacheable( $data ) {
	$response = rest_ensure_response( $data );
	$response->header( 'Cache-Control', 'public, max-age=15' );
	return $response;
}

add_action(
	'rest_api_init',
	empyrian_safe(
		function () {
			$unavailable = new WP_Error( 'radio_unavailable', 'Radio is unavailable.', array( 'status' => 503 ) );
			register_rest_route(
				'empyrian/v1',
				'/radio/now',
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => empyrian_safe(
						function () {
							return empyrian_radio_cacheable( empyrian_radio_now( time() ) );
						},
						$unavailable
					),
				)
			);
			register_rest_route(
				'empyrian/v1',
				'/radio/guide',
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'args'                => array(
						'hours' => array(
							'default'           => 6,
							'sanitize_callback' => 'absint',
						),
					),
					'callback'            => empyrian_safe(
						function ( $request ) {
							$hours = min( 24, max( 1, (int) $request['hours'] ) );
							return empyrian_radio_cacheable(
								array(
									'station' => EMPYRIAN_RADIO_NAME,
									'slots'   => empyrian_radio_slots( time(), $hours ),
								)
							);
						},
						$unavailable
					),
				)
			);
		}
	)
);
