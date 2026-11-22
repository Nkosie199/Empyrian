<?php

// Playable releases and tracks, read from Play Block's post meta (never through its REST callbacks,
// which echo and exit when called in-process).
final class Empyrian_Hub_Catalog {

	public const CACHE_KEY      = 'empyrian_hub_releases';
	public const CHARTS_SIZE    = 10;
	public const ROW_SIZE       = 8;
	private const PLAYS_META    = 'post-count-all';
	private const CACHE_SECONDS = 10 * MINUTE_IN_SECONDS;

	public static function charts(): array {
		return array_slice( self::ranked()['by_plays'], 0, self::CHARTS_SIZE );
	}

	public static function new_releases(): array {
		return array_slice( self::ranked()['by_date'], 0, self::ROW_SIZE );
	}

	// Releases just below the Charts, so a new listener's "For you" doesn't repeat them.
	public static function popular_beyond_charts(): array {
		return array_slice( self::ranked()['by_plays'], self::CHARTS_SIZE, self::ROW_SIZE );
	}

	public static function by_artists( array $artist_ids ): array {
		if ( ! $artist_ids ) {
			return array();
		}
		$ids = get_posts(
			self::release_query(
				array(
					'tax_query' => array( array( 'taxonomy' => 'artist', 'terms' => $artist_ids ) ),
					'orderby'   => 'date',
					'order'     => 'DESC',
				)
			)
		);
		return array_slice( array_values( array_filter( $ids, array( Empyrian_Hub_Playable::class, 'is_playable' ) ) ), 0, self::ROW_SIZE );
	}

	public static function forget(): void {
		delete_transient( self::CACHE_KEY );
	}

	private static function ranked(): array {
		$ranked = get_transient( self::CACHE_KEY );
		if ( is_array( $ranked ) && isset( $ranked['by_plays'], $ranked['by_date'] ) ) {
			return $ranked;
		}
		$playable = array( Empyrian_Hub_Playable::class, 'is_playable' );
		$ranked   = array(
			'by_plays' => array_values( array_filter( get_posts( self::release_query( self::by_plays() ) ), $playable ) ),
			'by_date'  => array_values( array_filter( get_posts( self::release_query( array( 'orderby' => 'date', 'order' => 'DESC' ) ) ), $playable ) ),
		);
		set_transient( self::CACHE_KEY, $ranked, self::CACHE_SECONDS );
		return $ranked;
	}

	private static function by_plays(): array {
		return array(
			'meta_key' => self::PLAYS_META,
			'orderby'  => array(
				'meta_value_num' => 'DESC',
				'date'           => 'DESC',
			),
		);
	}

	private static function release_query( array $args ): array {
		return $args + array(
			'post_type'        => 'post',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => 60,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_query'       => array(
				array(
					'key'     => 'type',
					'value'   => Empyrian_Hub_Playable::RELEASE_TYPES,
					'compare' => 'IN',
				),
			),
		);
	}
}
