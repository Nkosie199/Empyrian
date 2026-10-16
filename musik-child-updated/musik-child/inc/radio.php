<?php
// Empyrian Radio: one always-on station on a shared clock, built from Play Block's public catalogue.

const EMPYRIAN_RADIO_NAME       = 'Empyrian Radio';
const EMPYRIAN_RADIO_HORIZON    = DAY_IN_SECONDS;
const EMPYRIAN_RADIO_MIN_TRACKS = 5;

require_once __DIR__ . '/radio-duration.php';
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

// Over HTTP, never in-process: calling Play Block's REST callbacks directly echoes and exits.
function empyrian_radio_get_json( $path ) {
	$response = wp_remote_get( rest_url( $path ), array( 'timeout' => 15 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	return json_decode( wp_remote_retrieve_body( $response ), true );
}

function empyrian_radio_full_size( $url ) {
	return $url ? preg_replace( '/-\d+x\d+(\.\w+)$/', '$1', $url ) : null;
}

function empyrian_radio_refresh() {
	$listing = empyrian_radio_get_json( 'play/play/items' );
	if ( ! is_array( $listing ) ) {
		return;
	}
	$tracks = array();
	$probes = 0;
	foreach ( $listing as $entry ) {
		$items = empyrian_radio_get_json( 'play/play/' . (int) strtok( (string) $entry, ':' ) );
		if ( ! is_array( $items ) ) {
			continue;
		}
		foreach ( isset( $items[0] ) ? $items : array( $items ) as $item ) {
			$id   = (int) ( $item['id'] ?? 0 );
			$post = $id ? get_post( $id ) : null;
			if ( ! $post || 'audio' !== ( $item['type'] ?? '' ) || empty( $item['stream_url'] ) || ! is_post_publicly_viewable( $post ) ) {
				continue;
			}
			$duration = (int) get_post_meta( $id, '_empyrian_radio_duration', true );
			$checked  = (int) get_post_meta( $id, '_empyrian_radio_duration_checked', true );
			if ( $duration <= 0 && $probes < 25 && $checked < time() - WEEK_IN_SECONDS ) {
				++$probes;
				$duration = empyrian_radio_mp3_duration( $item['stream_url'] );
				update_post_meta( $id, '_empyrian_radio_duration', $duration );
				update_post_meta( $id, '_empyrian_radio_duration_checked', time() );
			}
			if ( $duration < MINUTE_IN_SECONDS ) {
				continue;
			}
			$artist        = trim( (string) ( $item['artist'] ?? '' ) );
			$tracks[ $id ] = array(
				'id'         => $id,
				'title'      => html_entity_decode( (string) ( $item['title'] ?? get_the_title( $id ) ), ENT_QUOTES ),
				'artist'     => $artist ? $artist : (string) ( $item['user'] ?? '' ),
				'artworkUrl' => empyrian_radio_full_size( $item['artwork_url'] ?? '' ),
				'streamUrl'  => $item['stream_url'],
				'uri'        => $item['uri'] ?? get_permalink( $id ),
				'duration'   => $duration,
				'published'  => (int) get_post_time( 'U', true, $id ),
			);
		}
	}
	update_option( 'empyrian_radio_tracks', $tracks, false );
	empyrian_radio_extend_schedule( $tracks, time() );
}

function empyrian_radio_tracks() {
	$tracks = get_option( 'empyrian_radio_tracks', array() );
	return is_array( $tracks ) ? $tracks : array();
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
			'track'   => null,
			'next'    => array(),
		);
	}
	$current = array_shift( $slots );
	return array(
		'station'       => EMPYRIAN_RADIO_NAME,
		'offAir'        => false,
		'track'         => $current,
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
				'/radio/schedule',
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
