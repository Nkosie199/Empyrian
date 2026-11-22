<?php

// The hub's sections for one viewer, in page order. The front page and Hub Cards both render these.
final class Empyrian_Hub_Sections {

	public const CONTINUE_SIZE = 6;

	/**
	 * @return array<int, array{key: string, title: string, href: ?string, items: array}>
	 */
	public static function for_viewer( int $user_id ): array {
		$sections = array(
			self::continue_listening( $user_id ),
			self::radio(),
			self::section( 'charts', __( 'Charts', 'empyrian-home-hub' ), self::page_url( 'charts' ), self::releases( Empyrian_Hub_Catalog::charts() ) ),
			self::section( 'new', __( 'New releases', 'empyrian-home-hub' ), self::page_url( 'albums' ), self::releases( Empyrian_Hub_Catalog::new_releases() ) ),
			self::for_you( $user_id ),
			self::upload(),
		);
		return array_values( array_filter( $sections ) );
	}

	public static function item( int $id ): array {
		$image = get_the_post_thumbnail_url( $id, 'medium' );
		return array(
			'id'       => $id,
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'subtitle' => self::artists( $id ),
			'href'     => get_permalink( $id ),
			'image'    => $image ? $image : null,
			'count'    => (int) get_post_meta( $id, 'post-count-all', true ),
		);
	}

	private static function continue_listening( int $user_id ): ?array {
		if ( ! $user_id ) {
			return null;
		}
		$items = array();
		foreach ( Empyrian_Hub_History::played( $user_id ) as $entry ) {
			$id = (int) ( $entry['id'] ?? 0 );
			if ( $id && Empyrian_Hub_Playable::is_playable( $id ) ) {
				$items[] = self::item( $id ) + array(
					'position' => (int) ( $entry['position'] ?? 0 ),
					'at'       => gmdate( 'c', (int) ( $entry['at'] ?? 0 ) ),
				);
			}
		}
		return self::section( 'continue', __( 'Continue listening', 'empyrian-home-hub' ), null, array_slice( $items, 0, self::CONTINUE_SIZE ) );
	}

	private static function radio(): ?array {
		$track = Empyrian_Hub_Radio::on_air();
		if ( ! $track ) {
			return null;
		}
		$item = array(
			'id'       => (int) $track['id'],
			'title'    => (string) $track['title'],
			'subtitle' => (string) $track['artist'],
			'href'     => (string) $track['uri'],
			'image'    => $track['artworkUrl'] ?? null,
			'stream'   => (string) $track['streamUrl'],
		);
		return self::section( 'radio', Empyrian_Hub_Radio::station_name(), self::page_url( 'radio' ), array( $item ) );
	}

	// New listeners get the releases just below the Charts, so the two rows never repeat each other.
	private static function for_you( int $user_id ): ?array {
		if ( ! $user_id ) {
			return null;
		}
		$ids = Empyrian_Hub_Catalog::by_artists( Empyrian_Hub_History::artist_ids( $user_id ) );
		if ( ! $ids ) {
			$ids = Empyrian_Hub_Catalog::popular_beyond_charts();
		}
		if ( ! $ids ) {
			$ids = Empyrian_Hub_Catalog::charts();
		}
		return self::section( 'for-you', __( 'For you', 'empyrian-home-hub' ), null, self::releases( $ids ) );
	}

	private static function upload(): array {
		return array(
			'key'   => 'upload',
			'title' => __( 'Upload', 'empyrian-home-hub' ),
			'href'  => self::page_url( 'upload' ) ?? home_url( '/upload/' ),
			'items' => array(),
		);
	}

	private static function section( string $key, string $title, ?string $href, array $items ): ?array {
		return $items ? compact( 'key', 'title', 'href', 'items' ) : null;
	}

	private static function releases( array $ids ): array {
		return array_map( array( self::class, 'item' ), $ids );
	}

	private static function artists( int $id ): string {
		$terms = get_the_terms( $id, 'artist' );
		if ( is_array( $terms ) && $terms ) {
			return html_entity_decode( implode( ', ', wp_list_pluck( $terms, 'name' ) ), ENT_QUOTES, 'UTF-8' );
		}
		return (string) get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $id ) );
	}

	private static function page_url( string $slug ): ?string {
		$page = get_page_by_path( $slug );
		return $page && 'publish' === $page->post_status ? get_permalink( $page ) : null;
	}
}
