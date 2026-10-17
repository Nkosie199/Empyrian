<?php
// Empyrian Radio: one always-on station on a shared clock, built from Play Block's public catalogue.

const EMPYRIAN_RADIO_NAME       = 'Empyrian Radio';
const EMPYRIAN_RADIO_HORIZON    = DAY_IN_SECONDS;
const EMPYRIAN_RADIO_MIN_TRACKS = 5;

require_once __DIR__ . '/radio-duration.php';
require_once __DIR__ . '/radio-catalogue.php';
require_once __DIR__ . '/radio-schedule.php';
require_once __DIR__ . '/radio-embed.php';
require_once __DIR__ . '/radio-page.php';
require_once __DIR__ . '/radio-admin.php';

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

// A finished pass that changed the catalogue replans what's ahead, so new tracks join and removed ones leave.
function empyrian_radio_refresh() {
	$tracks  = empyrian_radio_refresh_batch();
	$catalog = array_keys( $tracks );
	sort( $catalog );
	if ( ! get_option( 'empyrian_radio_pass' ) && get_option( 'empyrian_radio_planned_catalog' ) !== $catalog ) {
		update_option( 'empyrian_radio_planned_catalog', $catalog, false );
		empyrian_radio_replan_from( time() + 1 );
		return;
	}
	empyrian_radio_extend_schedule( $tracks, time() );
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
