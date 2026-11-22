<?php

// The E-1 rule: a release only shows if Play has something to play.
final class Empyrian_Hub_Playable {

	public const RELEASE_TYPES = array( 'album', 'playlist', 'single' );

	/**
	 * @param string $type      Play Block's `type` meta: album, playlist, single or '' for a track.
	 * @param string $stream    The release's own stream (singles and tracks).
	 * @param array  $tracks    For albums and playlists: each listed track as array( 'published' => bool, 'stream' => string ).
	 */
	public static function check( string $type, string $stream, array $tracks ): bool {
		if ( 'album' === $type || 'playlist' === $type ) {
			foreach ( $tracks as $track ) {
				if ( ! empty( $track['published'] ) && '' !== trim( (string) ( $track['stream'] ?? '' ) ) ) {
					return true;
				}
			}
			return false;
		}
		return '' !== trim( $stream );
	}

	public static function track_ids( string $listed ): array {
		return array_values( array_filter( array_map( 'intval', explode( ',', $listed ) ) ) );
	}

	public static function is_playable( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || ! is_post_publicly_viewable( $post ) ) {
			return false;
		}
		$tracks = array_map(
			static function ( int $track_id ): array {
				$track = get_post( $track_id );
				return array(
					'published' => $track && is_post_publicly_viewable( $track ),
					'stream'    => $track ? self::stream( $track_id ) : '',
				);
			},
			self::track_ids( (string) get_post_meta( $post_id, 'post', true ) )
		);
		return self::check( (string) get_post_meta( $post_id, 'type', true ), self::stream( $post_id ), $tracks );
	}

	private static function stream( int $post_id ): string {
		$stream = (string) get_post_meta( $post_id, 'stream', true );
		return '' !== $stream ? $stream : (string) get_post_meta( $post_id, 'stream_url', true );
	}
}
