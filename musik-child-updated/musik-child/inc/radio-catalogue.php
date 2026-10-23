<?php
// The station's tracks, read from Play Block a batch at a time so no single cron run outlasts the host's time limit.

const EMPYRIAN_RADIO_BATCH      = 5;
const EMPYRIAN_RADIO_MAX_PROBES = 8;

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

// Reads the MP3's own length once, then again only if a week-old probe found nothing.
function empyrian_radio_duration( $id, $stream_url ) {
	static $probes = 0;
	$duration = (int) get_post_meta( $id, '_empyrian_radio_duration', true );
	$checked  = (int) get_post_meta( $id, '_empyrian_radio_duration_checked', true );
	if ( $duration <= 0 && $probes < EMPYRIAN_RADIO_MAX_PROBES && $checked < time() - WEEK_IN_SECONDS ) {
		++$probes;
		$duration = empyrian_radio_mp3_duration( $stream_url );
		update_post_meta( $id, '_empyrian_radio_duration', $duration );
		update_post_meta( $id, '_empyrian_radio_duration_checked', time() );
	}
	return $duration;
}

function empyrian_radio_track( array $item ) {
	$id   = (int) ( $item['id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'audio' !== ( $item['type'] ?? '' ) || empty( $item['stream_url'] ) || ! is_post_publicly_viewable( $post ) ) {
		return null;
	}
	$duration = empyrian_radio_duration( $id, $item['stream_url'] );
	if ( $duration < MINUTE_IN_SECONDS ) {
		return null;
	}
	$artist = trim( (string) ( $item['artist'] ?? '' ) );
	return array(
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

// A listed item is a single track or an album of them.
function empyrian_radio_items_listed_as( $listed_id ) {
	$items = empyrian_radio_get_json( 'play/play/' . (int) $listed_id );
	if ( ! is_array( $items ) ) {
		return array();
	}
	return isset( $items[0] ) ? $items : array( $items );
}

function empyrian_radio_tracks() {
	$tracks = get_option( 'empyrian_radio_tracks', array() );
	return is_array( $tracks ) ? $tracks : array();
}

function empyrian_radio_start_pass() {
	$listing = empyrian_radio_get_json( 'play/play/items' );
	if ( ! is_array( $listing ) ) {
		return null;
	}
	$pending = array_map(
		function ( $entry ) {
			return (int) strtok( (string) $entry, ':' );
		},
		$listing
	);
	return array(
		'pending' => $pending,
		'seen'    => array(),
		'streams' => array(),
	);
}

// Each run reads the next batch; the pass ends by dropping tracks that are no longer listed or public,
// and by publishing every playable item's current stream for the player's saved queues.
function empyrian_radio_refresh_batch() {
	$pass = get_option( 'empyrian_radio_pass' );
	if ( ! is_array( $pass ) || ! $pass['pending'] ) {
		$pass = empyrian_radio_start_pass();
		if ( ! $pass ) {
			return empyrian_radio_tracks();
		}
	}
	$tracks = empyrian_radio_tracks();
	foreach ( array_splice( $pass['pending'], 0, EMPYRIAN_RADIO_BATCH ) as $listed_id ) {
		foreach ( empyrian_radio_items_listed_as( $listed_id ) as $item ) {
			if ( ! empty( $item['id'] ) && ! empty( $item['stream_url'] ) ) {
				$pass['streams'][ (int) $item['id'] ] = $item['stream_url'];
			}
			$track = empyrian_radio_track( $item );
			if ( $track ) {
				$tracks[ $track['id'] ] = $track;
				$pass['seen'][]         = $track['id'];
			}
		}
	}
	if ( $pass['pending'] ) {
		if ( ! wp_next_scheduled( 'empyrian_radio_refresh_soon' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'empyrian_radio_refresh_soon' );
		}
		update_option( 'empyrian_radio_pass', $pass, false );
	} else {
		$tracks = array_intersect_key( $tracks, array_flip( $pass['seen'] ) );
		update_option( 'empyrian_playable_streams', $pass['streams'], false );
		delete_option( 'empyrian_radio_pass' );
	}
	update_option( 'empyrian_radio_tracks', $tracks, false );
	return $tracks;
}
