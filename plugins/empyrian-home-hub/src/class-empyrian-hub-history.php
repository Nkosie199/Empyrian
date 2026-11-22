<?php

// What a signed-in listener played (with where they stopped) and liked, kept on their WordPress user.
final class Empyrian_Hub_History {

	public const PLAYED_META = 'empyrian_hub_played';
	public const LIKED_META  = 'empyrian_hub_liked';
	public const MAX_PLAYED  = 12;
	public const MAX_LIKED   = 50;

	/**
	 * Newest first, one entry per track.
	 *
	 * @return array<int, array{id: int, position: int, at: int}>
	 */
	public static function with_play( array $played, int $id, int $position, int $now ): array {
		$others = array_filter(
			$played,
			static function ( $entry ) use ( $id ) {
				return is_array( $entry ) && (int) ( $entry['id'] ?? 0 ) !== $id;
			}
		);
		$entry  = array(
			'id'       => $id,
			'position' => max( 0, $position ),
			'at'       => $now,
		);
		return array_slice( array_merge( array( $entry ), array_values( $others ) ), 0, self::MAX_PLAYED );
	}

	public static function with_like( array $liked, int $id, bool $is_liked ): array {
		$others = array_values( array_diff( array_map( 'intval', $liked ), array( $id ) ) );
		return array_slice( $is_liked ? array_merge( array( $id ), $others ) : $others, 0, self::MAX_LIKED );
	}

	public static function record_play( int $user_id, int $id, int $position ): void {
		update_user_meta( $user_id, self::PLAYED_META, self::with_play( self::played( $user_id ), $id, $position, time() ) );
	}

	public static function record_like( int $user_id, int $id, bool $is_liked ): void {
		update_user_meta( $user_id, self::LIKED_META, self::with_like( self::liked( $user_id ), $id, $is_liked ) );
	}

	public static function played( int $user_id ): array {
		$played = get_user_meta( $user_id, self::PLAYED_META, true );
		return is_array( $played ) ? $played : array();
	}

	public static function liked( int $user_id ): array {
		$liked = get_user_meta( $user_id, self::LIKED_META, true );
		return is_array( $liked ) ? array_map( 'intval', $liked ) : array();
	}

	public static function artist_ids( int $user_id ): array {
		$ids = array_merge( array_column( self::played( $user_id ), 'id' ), self::liked( $user_id ) );
		$artists = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$terms = get_the_terms( $id, 'artist' );
			if ( is_array( $terms ) ) {
				$artists = array_merge( $artists, wp_list_pluck( $terms, 'term_id' ) );
			}
		}
		return array_values( array_unique( array_map( 'intval', $artists ) ) );
	}

	public static function forget_everyone(): void {
		delete_metadata( 'user', 0, self::PLAYED_META, '', true );
		delete_metadata( 'user', 0, self::LIKED_META, '', true );
	}
}
