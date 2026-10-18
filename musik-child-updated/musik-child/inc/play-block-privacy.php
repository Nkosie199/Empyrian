<?php
// Play Block's REST API serves private stations to anyone; only their author and editors may see them.
add_filter( 'rest_post_dispatch', empyrian_safe( 'play_block_privacy_filter' ), 10, 3 );

function play_block_privacy_can_read( $id ) {
	$post = get_post( (int) $id );
	return $post && ( is_post_publicly_viewable( $post ) || current_user_can( 'read_post', $post->ID ) );
}

function play_block_privacy_filter( $response, $server, $request ) {
	$route = $request->get_route();
	$data  = $response->get_data();

	if ( '/play/play/items' === $route && is_array( $data ) ) {
		$readable = array_filter(
			$data,
			function ( $item ) {
				return play_block_privacy_can_read( strtok( (string) $item, ':' ) );
			}
		);
		$response->set_data( array_values( $readable ) );
	} elseif ( preg_match( '#^/play/play/(\d+)$#', $route, $match ) ) {
		if ( ! play_block_privacy_can_read( $match[1] ) ) {
			return new WP_REST_Response( array( 'code' => 'rest_post_invalid_id', 'message' => 'Not found.' ), 404 );
		}
		if ( is_array( $data ) && isset( $data[0] ) ) {
			$readable = array_filter(
				$data,
				function ( $item ) {
					return ! isset( $item['id'] ) || play_block_privacy_can_read( $item['id'] );
				}
			);
			$response->set_data( array_values( $readable ) );
		}
	}

	return $response;
}
