<?php

// GET  /empyrian/v1/hub/cards    the hub's sections as Hub Cards (mynger-backend H-2 shape), for Mynger Home.
// POST /empyrian/v1/hub/history  the signed-in listener's plays (with position) and likes.
final class Empyrian_Hub_Rest {

	public const NAMESPACE = 'empyrian/v1';

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/hub/cards',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( self::class, 'cards' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/hub/history',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => array( self::class, 'record' ),
				'args'                => array(
					'id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
					),
					'event'    => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'play', 'like' ),
					),
					'position' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'liked'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}

	public static function cards(): WP_REST_Response {
		$user_id  = get_current_user_id();
		$response = rest_ensure_response( array_map( array( self::class, 'card' ), Empyrian_Hub_Sections::for_viewer( $user_id ) ) );
		if ( ! $user_id ) {
			$response->header( 'Cache-Control', 'public, max-age=60' );
		}
		return $response;
	}

	public static function card( array $section ): array {
		$card = array(
			'key'   => $section['key'],
			'app'   => 'empyrian',
			'items' => array_map( array( self::class, 'card_item' ), $section['items'] ),
		);
		if ( $section['href'] ) {
			$card['href'] = $section['href'];
		}
		return $card;
	}

	public static function card_item( array $item ): array {
		$card_item = array(
			'id'       => (string) $item['id'],
			'title'    => $item['title'],
			'subtitle' => $item['subtitle'],
			'href'     => $item['href'],
			'image'    => $item['image'],
			'stream'   => $item['stream'] ?? null,
			'count'    => empty( $item['count'] ) ? null : $item['count'],
			'at'       => $item['at'] ?? null,
		);
		return array_filter(
			$card_item,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	public static function record( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post || ! is_post_publicly_viewable( $post ) ) {
			return new WP_Error( 'empyrian_hub_unknown_track', __( 'That track is not available.', 'empyrian-home-hub' ), array( 'status' => 404 ) );
		}
		$user_id = get_current_user_id();
		if ( 'like' === $request['event'] ) {
			Empyrian_Hub_History::record_like( $user_id, $id, (bool) $request['liked'] );
		} else {
			Empyrian_Hub_History::record_play( $user_id, $id, (int) $request['position'] );
		}
		return new WP_REST_Response( null, 204 );
	}
}
