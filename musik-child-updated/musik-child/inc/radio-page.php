<?php
// [empyrian_radio]: the station's player and a programme guide laid out on a timeline, like a TV guide.

const EMPYRIAN_RADIO_GUIDE_HOURS = 3;
const EMPYRIAN_RADIO_GUIDE_TICK  = 30 * MINUTE_IN_SECONDS;

add_shortcode( 'empyrian_radio', empyrian_safe( 'empyrian_radio_render_page', '' ) );

function empyrian_radio_render_page() {
	$resize = "var f=this;f.contentDocument.documentElement.classList.add('sized-by-parent');new ResizeObserver(function(){f.style.height=f.contentDocument.body.scrollHeight+'px'}).observe(f.contentDocument.body)";
	return '<div class="empyrian-radio-page">'
		. '<iframe class="empyrian-radio-frame" src="' . esc_url( home_url( '/?empyrian_radio=embed' ) ) . '" title="' . esc_attr( EMPYRIAN_RADIO_NAME ) . '" allow="autoplay" style="width:100%;border:0;height:16rem" onload="' . esc_attr( $resize ) . '"></iframe>'
		. empyrian_radio_render_guide( empyrian_radio_slots( time(), EMPYRIAN_RADIO_GUIDE_HOURS ), time() )
		. '</div>';
}

function empyrian_radio_render_guide( array $slots, $now ) {
	if ( ! $slots ) {
		return '';
	}
	$start   = (int) floor( $now / EMPYRIAN_RADIO_GUIDE_TICK ) * EMPYRIAN_RADIO_GUIDE_TICK;
	$end     = $start + EMPYRIAN_RADIO_GUIDE_HOURS * HOUR_IN_SECONDS;
	$percent = function ( $at ) use ( $start, $end ) {
		return round( ( max( $start, min( $end, $at ) ) - $start ) / ( $end - $start ) * 100, 3 );
	};
	$clock   = function ( $at ) {
		return wp_date( get_option( 'time_format' ), $at );
	};

	$ticks = '';
	for ( $at = $start; $at < $end; $at += EMPYRIAN_RADIO_GUIDE_TICK ) {
		$ticks .= sprintf( '<span class="station-guide__tick" style="left:%s%%">%s</span>', $percent( $at ), esc_html( $clock( $at ) ) );
	}
	$blocks = '';
	foreach ( $slots as $slot ) {
		$ends    = $slot['start'] + $slot['duration'];
		$on_now  = $slot['start'] <= $now && $now < $ends;
		$blocks .= sprintf(
			'<a class="station-guide__programme%1$s" href="%2$s" style="left:%3$s%%;width:%4$s%%" title="%5$s"><span class="station-guide__times">%6$s</span><strong>%7$s</strong><span class="station-guide__artist">%8$s</span></a>',
			$on_now ? ' station-guide__programme--live' : '',
			esc_url( $slot['uri'] ),
			$percent( $slot['start'] ),
			$percent( $ends ) - $percent( $slot['start'] ),
			esc_attr( $slot['title'] . ' — ' . $slot['artist'] ),
			esc_html( $clock( $slot['start'] ) . ' – ' . $clock( $ends ) ),
			esc_html( $slot['title'] ),
			esc_html( $slot['artist'] )
		);
	}
	$edit = current_user_can( 'manage_options' )
		? '<a class="station-guide__edit" href="' . esc_url( admin_url( 'tools.php?page=' . EMPYRIAN_RADIO_ADMIN_PAGE ) ) . '">' . esc_html__( 'Edit guide', 'musik-child' ) . '</a>'
		: '';

	return empyrian_radio_guide_styles()
		. '<section class="station-guide" aria-label="' . esc_attr__( 'Programme guide', 'musik-child' ) . '">'
		. '<div class="station-guide__head"><h3>' . esc_html__( 'Guide', 'musik-child' ) . '</h3>' . $edit . '</div>'
		. '<div class="station-guide__scroll"><div class="station-guide__grid" style="--station-guide-minutes:' . (int) ( ( $end - $start ) / MINUTE_IN_SECONDS ) . '">'
		. '<div class="station-guide__ruler">' . $ticks . '</div>'
		. '<div class="station-guide__track">' . $blocks . '</div>'
		. '<span class="station-guide__now" style="left:' . $percent( $now ) . '%" aria-hidden="true"></span>'
		. '</div></div></section>'
		. '<script>(function (scroller) { var live = scroller && scroller.querySelector(".station-guide__programme--live"); if (live) scroller.scrollLeft = live.offsetLeft - 16; })(document.currentScript.previousElementSibling.querySelector(".station-guide__scroll"));</script>';
}

function empyrian_radio_guide_styles() {
	return '<style>
.station-guide { display: flex; flex-direction: column; gap: 0.75rem; margin-top: 1.5rem; }
.station-guide__head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }
.station-guide__head h3 { margin: 0; }
.station-guide__scroll { overflow-x: auto; padding-bottom: 0.5rem; }
.station-guide__grid { position: relative; width: calc(var(--station-guide-minutes) * 2rem); }
.station-guide__ruler { position: relative; height: 2rem; }
.station-guide__tick { position: absolute; top: 0; padding-left: 0.4rem; border-left: 1px solid currentColor; opacity: 0.6; font-size: 0.85rem; }
.station-guide__track { position: relative; height: 5rem; }
.station-guide__programme { position: absolute; top: 0; bottom: 0; display: flex; flex-direction: column; justify-content: center; gap: 0.15rem; padding: 0.4rem 0.6rem; overflow: hidden; box-sizing: border-box; border-right: 3px solid transparent; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.15); background-clip: padding-box; color: inherit; text-decoration: none; }
.station-guide__programme:hover, .station-guide__programme:focus-visible { background-color: rgba(96, 94, 215, 0.25); }
.station-guide__programme--live { background-color: rgba(96, 94, 215, 0.35); }
.station-guide__programme strong, .station-guide__artist, .station-guide__times { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.station-guide__times, .station-guide__artist { font-size: 0.8rem; opacity: 0.75; }
.station-guide__now { position: absolute; top: 0; bottom: 0; border-left: 2px solid #e11d48; pointer-events: none; }
</style>';
}
