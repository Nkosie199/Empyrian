// Empyrian: keep releases with no playable tracks out of Charts, Discover and search.
function empyrian_is_playable( $post_id ) {
	$response = rest_do_request( new WP_REST_Request( 'GET', '/play/play/' . $post_id ) );
	return $response->is_error() || ! empty( $response->get_data() );
}

function empyrian_refresh_playable( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || 'post' !== get_post_type( $post_id ) ) {
		return;
	}
	if ( empyrian_is_playable( $post_id ) ) {
		delete_post_meta( $post_id, '_empyrian_unplayable' );
	} else {
		update_post_meta( $post_id, '_empyrian_unplayable', 1 );
	}
}
add_action( 'save_post', 'empyrian_refresh_playable', 20 );

function empyrian_refresh_all_playable() {
	$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		empyrian_refresh_playable( $id );
	}
	update_option( 'empyrian_playable_checked_at', time() );
}
add_action( 'empyrian_refresh_all_playable', 'empyrian_refresh_all_playable' );

add_action( 'admin_init', function () {
	if ( ! get_option( 'empyrian_playable_checked_at' ) ) {
		empyrian_refresh_all_playable();
	}
	if ( ! wp_next_scheduled( 'empyrian_refresh_all_playable' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'empyrian_refresh_all_playable' );
	}
} );

add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || $query->is_singular() ) {
		return;
	}
	$type = $query->get( 'post_type' );
	if ( $type && 'post' !== $type && ! ( is_array( $type ) && in_array( 'post', $type, true ) ) ) {
		return;
	}
	$meta   = (array) $query->get( 'meta_query' );
	$meta[] = array( 'key' => '_empyrian_unplayable', 'compare' => 'NOT EXISTS' );
	$query->set( 'meta_query', $meta );
} );

add_filter( 'the_content', function ( $content ) {
	if ( is_singular( 'post' ) && in_the_loop() && get_post_meta( get_the_ID(), '_empyrian_unplayable', true ) ) {
		$content = '<p class="empyrian-notice" role="status">This release has no playable tracks yet.</p>' . $content;
	}
	return $content;
} );
