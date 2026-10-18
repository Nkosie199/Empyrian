<?php
// Play Block leaves duration empty, so it's read from the MP3 itself with two small ranged requests.

function empyrian_radio_range( $url, $from, $length ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 15,
			'headers' => array( 'Range' => 'bytes=' . $from . '-' . ( $from + $length - 1 ) ),
		)
	);
	if ( is_wp_error( $response ) || 206 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$total = (int) preg_replace( '#^.*/#', '', (string) wp_remote_retrieve_header( $response, 'content-range' ) );
	return array( wp_remote_retrieve_body( $response ), $total );
}

function empyrian_radio_mp3_duration( $url ) {
	$head = empyrian_radio_range( $url, 0, 10 );
	if ( ! $head || strlen( $head[0] ) < 10 ) {
		return 0;
	}
	list( $bytes, $total ) = $head;
	$audio_start = 0;
	if ( 'ID3' === substr( $bytes, 0, 3 ) ) {
		$size        = ( ord( $bytes[6] ) & 0x7f ) << 21 | ( ord( $bytes[7] ) & 0x7f ) << 14 | ( ord( $bytes[8] ) & 0x7f ) << 7 | ( ord( $bytes[9] ) & 0x7f );
		$audio_start = 10 + $size + ( ord( $bytes[5] ) & 0x10 ? 10 : 0 );
	}
	$chunk = empyrian_radio_range( $url, $audio_start, 16384 );
	if ( ! $chunk || ! $total ) {
		return 0;
	}
	return empyrian_radio_mpeg_duration( $chunk[0], $total - $audio_start );
}

function empyrian_radio_mpeg_duration( $data, $audio_bytes ) {
	$bitrates = array(
		1 => array( 0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320 ),
		2 => array( 0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160 ),
	);
	$rates    = array(
		3 => array( 44100, 48000, 32000 ),
		2 => array( 22050, 24000, 16000 ),
		0 => array( 11025, 12000, 8000 ),
	);
	$length   = strlen( $data );
	for ( $i = 0; $i < $length - 4; $i++ ) {
		if ( "\xFF" !== $data[ $i ] || ( ord( $data[ $i + 1 ] ) & 0xE0 ) !== 0xE0 ) {
			continue;
		}
		$b1      = ord( $data[ $i + 1 ] );
		$b2      = ord( $data[ $i + 2 ] );
		$b3      = ord( $data[ $i + 3 ] );
		$version = ( $b1 >> 3 ) & 3;
		$layer   = ( $b1 >> 1 ) & 3;
		$bitrate = ( $b2 >> 4 ) & 15;
		$rate    = ( $b2 >> 2 ) & 3;
		if ( 1 === $version || 1 !== $layer || 0 === $bitrate || 15 === $bitrate || 3 === $rate ) {
			continue;
		}
		$mpeg1       = 3 === $version;
		$sample_rate = $rates[ $version ][ $rate ];
		$samples     = $mpeg1 ? 1152 : 576;
		$mono        = 3 === ( ( $b3 >> 6 ) & 3 );
		$side_info   = $mpeg1 ? ( $mono ? 17 : 32 ) : ( $mono ? 9 : 17 );
		$xing        = $i + 4 + $side_info;
		$tag         = substr( $data, $xing, 4 );
		if ( ( 'Xing' === $tag || 'Info' === $tag ) && ( ord( $data[ $xing + 7 ] ) & 1 ) ) {
			$frames = unpack( 'N', substr( $data, $xing + 8, 4 ) )[1];
			return (int) round( $frames * $samples / $sample_rate );
		}
		if ( 'VBRI' === substr( $data, $i + 36, 4 ) ) {
			$frames = unpack( 'N', substr( $data, $i + 36 + 14, 4 ) )[1];
			return (int) round( $frames * $samples / $sample_rate );
		}
		$kbps = $bitrates[ $mpeg1 ? 1 : 2 ][ $bitrate ];
		return (int) round( $audio_bytes * 8 / ( $kbps * 1000 ) );
	}
	return 0;
}
