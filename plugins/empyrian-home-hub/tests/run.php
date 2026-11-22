<?php
// Unit tests for the hub's pure logic: php plugins/empyrian-home-hub/tests/run.php

const MINUTE_IN_SECONDS = 60;

require __DIR__ . '/../src/class-empyrian-hub-playable.php';
require __DIR__ . '/../src/class-empyrian-hub-history.php';
require __DIR__ . '/../src/class-empyrian-hub-page.php';
require __DIR__ . '/../src/class-empyrian-hub-rest.php';

$failures = 0;
function check( string $name, bool $passed ): void {
	global $failures;
	echo ( $passed ? 'ok   ' : 'FAIL ' ) . $name . PHP_EOL;
	$failures += $passed ? 0 : 1;
}

$track = static fn ( bool $published, string $stream ) => array( 'published' => $published, 'stream' => $stream );

check( 'an album with one published, streamable track plays', Empyrian_Hub_Playable::check( 'album', '', array( $track( false, 'a.mp3' ), $track( true, 'b.mp3' ) ) ) );
check( 'an album whose tracks are all unpublished does not', ! Empyrian_Hub_Playable::check( 'album', '', array( $track( false, 'a.mp3' ) ) ) );
check( 'a playlist whose tracks have no stream does not', ! Empyrian_Hub_Playable::check( 'playlist', '', array( $track( true, ' ' ) ) ) );
check( 'an empty album does not', ! Empyrian_Hub_Playable::check( 'album', '', array() ) );
check( 'a single plays from its own stream', Empyrian_Hub_Playable::check( 'single', 'x.mp3', array() ) );
check( 'a single without a stream does not', ! Empyrian_Hub_Playable::check( 'single', '', array() ) );
check( 'a track (no type) plays from its own stream', Empyrian_Hub_Playable::check( '', 'x.mp3', array() ) );
check( 'track ids are read from the comma list, skipping blanks', array( 5309, 5310 ) === Empyrian_Hub_Playable::track_ids( '5309, 5310,,0' ) );

$played = Empyrian_Hub_History::with_play( array(), 7, 42, 1000 );
$played = Empyrian_Hub_History::with_play( $played, 8, 0, 1001 );
$played = Empyrian_Hub_History::with_play( $played, 7, 90, 1002 );
check( 'the latest play comes first, once per track, with its position', array( 7, 8 ) === array_column( $played, 'id' ) && 90 === $played[0]['position'] && 1002 === $played[0]['at'] );
check( 'a negative position is stored as the start', 0 === Empyrian_Hub_History::with_play( array(), 9, -5, 1 )[0]['position'] );
$many = array();
for ( $id = 1; $id <= 20; $id++ ) {
	$many = Empyrian_Hub_History::with_play( $many, $id, 0, $id );
}
check( 'plays are capped, keeping the newest', Empyrian_Hub_History::MAX_PLAYED === count( $many ) && 20 === $many[0]['id'] );
check( 'junk entries are dropped', array( 3 ) === array_column( Empyrian_Hub_History::with_play( array( 'junk', null ), 3, 0, 1 ), 'id' ) );

$liked = Empyrian_Hub_History::with_like( array( 4, 5 ), 6, true );
check( 'a like goes first', array( 6, 4, 5 ) === $liked );
check( 'liking again does not repeat it', array( 5, 6, 4 ) === Empyrian_Hub_History::with_like( $liked, 5, true ) );
check( 'an unlike removes it', array( 6, 5 ) === Empyrian_Hub_History::with_like( $liked, 4, false ) );

check( 'clock shows m:ss', '0:07' === Empyrian_Hub_Page::clock( 7 ) && '12:05' === Empyrian_Hub_Page::clock( 725 ) );

$card = Empyrian_Hub_Rest::card(
	array(
		'key'   => 'charts',
		'title' => 'Charts',
		'href'  => 'https://empyrian.net/charts/',
		'items' => array(
			array(
				'id'       => 2127,
				'title'    => 'Mount Purp',
				'subtitle' => 'KLAN',
				'href'     => 'https://empyrian.net/?p=2127',
				'image'    => null,
				'count'    => 848,
			),
		),
	)
);
check(
	'a section becomes a Hub Card: string ids, app empyrian, no empty fields',
	array(
		'key'   => 'charts',
		'app'   => 'empyrian',
		'items' => array(
			array(
				'id'       => '2127',
				'title'    => 'Mount Purp',
				'subtitle' => 'KLAN',
				'href'     => 'https://empyrian.net/?p=2127',
				'count'    => 848,
			),
		),
		'href'  => 'https://empyrian.net/charts/',
	) === $card
);
check(
	'a section without a page leaves href out',
	! array_key_exists(
		'href',
		Empyrian_Hub_Rest::card(
			array(
				'key'   => 'for-you',
				'title' => 'For you',
				'href'  => null,
				'items' => array(),
			)
		)
	)
);

echo PHP_EOL . ( $failures ? "$failures failed" : 'all passed' ) . PHP_EOL;
exit( $failures ? 1 : 0 );
