<?php

// Renders the hub's sections. Play buttons use Play Block's own `.btn-play[data-play-id]`, so playback,
// queueing and the "now playing" highlight work exactly as on the rest of the site.
final class Empyrian_Hub_Page {

	public static function render( array $sections ): string {
		$html = '';
		foreach ( $sections as $section ) {
			$html .= match ( $section['key'] ) {
				'radio'  => self::radio( $section ),
				'upload' => self::upload( $section ),
				default  => self::row( $section ),
			};
		}
		return '<div class="empyrian-hub">' . $html . '</div>';
	}

	private static function head( array $section, string $link_text ): string {
		$link = $section['href']
			? '<a class="empyrian-hub__all" href="' . esc_url( $section['href'] ) . '">' . esc_html( $link_text ) . '</a>'
			: '';
		return '<div class="empyrian-hub__head"><h2 id="' . esc_attr( self::heading_id( $section ) ) . '">' . esc_html( $section['title'] ) . '</h2>' . $link . '</div>';
	}

	private static function row( array $section ): string {
		$list  = 'charts' === $section['key'] ? 'ol' : 'ul';
		$items = implode( '', array_map( array( self::class, 'item' ), $section['items'] ) );
		return self::open( $section )
			. self::head( $section, __( 'See all', 'empyrian-home-hub' ) )
			. "<$list class=\"empyrian-hub__grid\">$items</$list></section>";
	}

	private static function item( array $item ): string {
		$id       = (int) $item['id'];
		$position = (int) ( $item['position'] ?? 0 );
		$resume   = $position > 0 ? ' data-hub-resume="' . $position . '"' : '';
		$meta     = esc_html( $item['subtitle'] );
		if ( $position > 0 ) {
			/* translators: %s: where playback stopped, as m:ss */
			$meta .= '<span class="empyrian-hub__resume">' . esc_html( sprintf( __( 'Resume at %s', 'empyrian-home-hub' ), self::clock( $position ) ) ) . '</span>';
		}
		$art = $item['image']
			? '<img src="' . esc_url( $item['image'] ) . '" alt="" loading="lazy" decoding="async">'
			: '<span class="empyrian-hub__placeholder"></span>';
		/* translators: %s: track or release title */
		$label = sprintf( $position > 0 ? __( 'Resume %s', 'empyrian-home-hub' ) : __( 'Play %s', 'empyrian-home-hub' ), $item['title'] );

		return '<li class="empyrian-hub__item" data-play-id="' . $id . '">'
			. '<div class="empyrian-hub__cover">'
			. '<a class="empyrian-hub__art" href="' . esc_url( $item['href'] ) . '" tabindex="-1" aria-hidden="true">' . $art . '</a>'
			. '<button type="button" class="btn-play empyrian-hub__play" data-play-id="' . $id . '"' . $resume . ' aria-label="' . esc_attr( $label ) . '"><span>' . esc_html__( 'Play', 'empyrian-home-hub' ) . '</span></button>'
			. '</div>'
			. '<a class="empyrian-hub__title" href="' . esc_url( $item['href'] ) . '">' . esc_html( $item['title'] ) . '</a>'
			. '<span class="empyrian-hub__meta">' . $meta . '</span>'
			. '</li>';
	}

	private static function radio( array $section ): string {
		$player = Empyrian_Hub_Radio::player_html();
		if ( '' === $player ) {
			$track = $section['items'][0];
			/* translators: 1: track title, 2: artist */
			$player = '<p class="empyrian-hub__on-air">' . esc_html( sprintf( __( 'On air: %1$s by %2$s', 'empyrian-home-hub' ), $track['title'], $track['subtitle'] ) ) . '</p>';
		}
		return self::open( $section )
			. self::head( $section, __( 'Programme guide', 'empyrian-home-hub' ) )
			. $player
			. '</section>';
	}

	private static function upload( array $section ): string {
		return '<section class="empyrian-hub__section empyrian-hub__upload" aria-labelledby="' . esc_attr( self::heading_id( $section ) ) . '">'
			. '<div><h2 id="' . esc_attr( self::heading_id( $section ) ) . '">' . esc_html__( 'Share your music', 'empyrian-home-hub' ) . '</h2>'
			. '<p>' . esc_html__( 'Upload a track with its cover art. It plays here, in the Charts and on Empyrian Radio.', 'empyrian-home-hub' ) . '</p></div>'
			. '<a class="empyrian-hub__upload-button" href="' . esc_url( $section['href'] ) . '">' . esc_html__( 'Upload a track', 'empyrian-home-hub' ) . '</a>'
			. '</section>';
	}

	private static function open( array $section ): string {
		return '<section class="empyrian-hub__section empyrian-hub__section--' . esc_attr( $section['key'] ) . '" aria-labelledby="' . esc_attr( self::heading_id( $section ) ) . '">';
	}

	private static function heading_id( array $section ): string {
		return 'empyrian-hub-' . $section['key'];
	}

	public static function clock( int $seconds ): string {
		return sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
	}
}
