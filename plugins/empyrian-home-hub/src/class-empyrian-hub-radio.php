<?php

// Empyrian Radio lives in the child theme; the hub only shows it while that theme provides it.
final class Empyrian_Hub_Radio {

	public static function on_air(): ?array {
		if ( ! function_exists( 'empyrian_radio_now' ) ) {
			return null;
		}
		try {
			$now = empyrian_radio_now( time() );
		} catch ( Throwable $e ) {
			error_log( 'empyrian-home-hub: radio unavailable: ' . $e->getMessage() );
			return null;
		}
		return empty( $now['offAir'] ) && ! empty( $now['now'] ) ? $now['now'] : null;
	}

	public static function station_name(): string {
		return defined( 'EMPYRIAN_RADIO_NAME' ) ? EMPYRIAN_RADIO_NAME : __( 'Empyrian Radio', 'empyrian-home-hub' );
	}

	// The station's own player: one tap on its Play button and the listener joins the live broadcast.
	public static function player_html(): string {
		if ( ! function_exists( 'empyrian_radio_player_script' ) ) {
			return '';
		}
		$src = add_query_arg( 'empyrian_radio', 'embed', home_url( '/' ) );
		return '<iframe class="empyrian-radio-frame empyrian-hub__radio-player" data-src="' . esc_url( $src ) . '" title="' . esc_attr( self::station_name() ) . '" allow="autoplay"></iframe>'
			. empyrian_radio_player_script();
	}
}
