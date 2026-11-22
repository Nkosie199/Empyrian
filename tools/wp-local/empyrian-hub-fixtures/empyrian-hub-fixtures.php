<?php
/**
 * Plugin Name: Empyrian Hub local fixtures
 * Description: LOCAL PLAYGROUND ONLY, never install on a real site. Stands in for Play Block's taxonomies and catalogue, seeds releases shaped like empyrian.net's, and signs in local test users with a fresh listening history.
 */

defined( 'ABSPATH' ) || exit;

const EMPYRIAN_HUB_FIXTURES_OPTION = 'empyrian_hub_fixtures';

add_action(
	'init',
	static function () {
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post', array( 'public' => true, 'show_in_rest' => true, 'rewrite' => array( 'slug' => 'artist' ) ) );
		}
		if ( isset( $_GET['empyrian_hub_test_login'] ) ) {
			$user = get_user_by( 'login', sanitize_user( wp_unslash( $_GET['empyrian_hub_test_login'] ) ) );
			if ( $user && in_array( $user->user_login, array( 'hubtester', 'hubadmin' ), true ) ) {
				delete_user_meta( $user->ID, 'empyrian_hub_played' );
				delete_user_meta( $user->ID, 'empyrian_hub_liked' );
				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID );
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'empyrian-hub-fixtures/v1',
			'/expected',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$log = WP_CONTENT_DIR . '/debug.log';
					return (array) get_option( EMPYRIAN_HUB_FIXTURES_OPTION, array() ) + array( 'debugLog' => file_exists( $log ) ? (string) file_get_contents( $log ) : '' );
				},
			)
		);
	}
);

function empyrian_hub_fixtures_seed(): void {
	$artists = array();
	foreach ( array( 'KLAN', 'Deelow', 'Goofy', 'Phil-Air', 'LaPartyX', 'Vanity Of Man' ) as $name ) {
		$artists[ $name ] = (int) wp_insert_term( $name, 'artist' )['term_id'];
	}
	$day     = DAY_IN_SECONDS;
	$created = array(
		'releases'   => array(),
		'unplayable' => array(),
		'tracksBy'   => array(),
	);

	$track = static function ( string $title, string $artist, int $age, string $status = 'publish' ) use ( $artists, $day, &$created ): int {
		$id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => $status,
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - $age * $day ),
			)
		);
		update_post_meta( $id, 'type', '' );
		update_post_meta( $id, 'stream', 'https://example.test/' . sanitize_title( $title ) . '.mp3' );
		wp_set_object_terms( $id, array( $artists[ $artist ] ), 'artist' );
		if ( 'publish' === $status ) {
			$created['tracksBy'][ $artist ][] = $id;
		}
		return $id;
	};

	$release = static function ( string $title, string $type, string $artist, int $plays, int $age, array $tracks, string $stream = '' ) use ( $artists, $day ): int {
		$id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() - $age * $day ),
			)
		);
		update_post_meta( $id, 'type', $type );
		update_post_meta( $id, 'post', implode( ',', $tracks ) );
		update_post_meta( $id, 'stream', $stream );
		update_post_meta( $id, 'post-count-all', $plays );
		wp_set_object_terms( $id, array( $artists[ $artist ] ), 'artist' );
		return $id;
	};

	$names = array_keys( $artists );
	for ( $i = 0; $i < 14; $i++ ) {
		$artist = $names[ $i % count( $names ) ];
		$tracks = array( $track( "Track $i A", $artist, 40 + $i ), $track( "Track $i B", $artist, 40 + $i ) );
		$id     = $release( "Release $i", 0 === $i % 2 ? 'album' : 'playlist', $artist, 1000 - $i * 37, $i * 3, $tracks );
		$created['releases'][] = array(
			'id'     => $id,
			'plays'  => 1000 - $i * 37,
			'age'    => $i * 3,
			'artist' => $artist,
		);
	}
	$single = $release( 'Fresh Single', 'single', 'Goofy', 3, 1, array(), 'https://example.test/fresh-single.mp3' );
	$created['releases'][] = array(
		'id'     => $single,
		'plays'  => 3,
		'age'    => 1,
		'artist' => 'Goofy',
	);

	$created['unplayable'][] = $release( 'Hidden Album (draft tracks)', 'album', 'KLAN', 5000, 0, array( $track( 'Draft track', 'KLAN', 0, 'draft' ) ) );
	$created['unplayable'][] = $release( 'Hidden Single (no stream)', 'single', 'Deelow', 4000, 0, array() );
	$created['unplayable'][] = $release( 'Hidden Empty Playlist', 'playlist', 'Goofy', 3000, 0, array() );

	$page = static function ( string $slug, string $title, string $content = '' ): int {
		return wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	};
	$front = $page( 'discover', 'Discover', '<p>THEME SHOWCASE CONTENT</p>' );
	$page( 'charts', 'Charts', '<p>Charts page</p>' );
	$page( 'albums', 'Albums', '<p>Albums page</p>' );
	$page( 'radio', 'Radio', '[empyrian_radio]' );
	$page( 'upload', 'Upload', '<p>Upload page</p>' );
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $front );
	update_option( 'permalink_structure', '/%postname%/' );

	$radio = array();
	foreach ( array_slice( $created['tracksBy'], 0, 6, true ) as $artist => $ids ) {
		$id           = $ids[0];
		$radio[ $id ] = array(
			'id'         => $id,
			'title'      => get_the_title( $id ),
			'artist'     => $artist,
			'artworkUrl' => null,
			'streamUrl'  => 'https://example.test/' . $id . '.mp3',
			'uri'        => get_permalink( $id ),
			'duration'   => 600,
			'published'  => time(),
		);
	}
	update_option( 'empyrian_radio_tracks', $radio, false );

	wp_insert_user( array( 'user_login' => 'hubtester', 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ) );
	wp_insert_user( array( 'user_login' => 'hubadmin', 'user_pass' => wp_generate_password( 32 ), 'role' => 'administrator' ) );

	update_option( EMPYRIAN_HUB_FIXTURES_OPTION, $created, false );
}
