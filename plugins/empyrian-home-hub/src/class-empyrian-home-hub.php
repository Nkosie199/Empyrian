<?php

final class Empyrian_Home_Hub {

	private static string $plugin_file;

	public static function register( string $plugin_file ): void {
		self::$plugin_file = $plugin_file;
		add_filter( 'the_content', self::safely( array( self::class, 'front_page_content' ) ), 20 );
		add_action( 'wp_enqueue_scripts', self::safely( array( self::class, 'enqueue_assets' ) ) );
		add_action( 'rest_api_init', self::safely( array( Empyrian_Hub_Rest::class, 'register_routes' ) ) );
		add_action( 'save_post_post', self::safely( array( Empyrian_Hub_Catalog::class, 'forget' ) ) );
		add_action( 'deleted_post', self::safely( array( Empyrian_Hub_Catalog::class, 'forget' ) ) );
		register_deactivation_hook( $plugin_file, array( Empyrian_Hub_Catalog::class, 'forget' ) );
	}

	// The hub replaces the front page's content only; the theme's header, sidebar and player stay.
	public static function front_page_content( $content ) {
		if ( ! is_front_page() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}
		return Empyrian_Hub_Page::render( Empyrian_Hub_Sections::for_viewer( get_current_user_id() ) );
	}

	// On every page: the theme swaps pages in place (PJAX), and plays anywhere count towards Continue listening.
	public static function enqueue_assets(): void {
		$url = plugin_dir_url( self::$plugin_file );
		wp_enqueue_style( 'empyrian-home-hub', $url . 'assets/hub.css', array(), EMPYRIAN_HUB_VERSION );
		wp_enqueue_script( 'empyrian-home-hub', $url . 'assets/hub.js', array( 'jquery' ), EMPYRIAN_HUB_VERSION, true );
		if ( is_user_logged_in() ) {
			wp_localize_script(
				'empyrian-home-hub',
				'empyrianHub',
				array(
					'historyUrl' => rest_url( Empyrian_Hub_Rest::NAMESPACE . '/hub/history' ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	// A failure in the hub is logged and the page falls back to what it would have shown without it.
	private static function safely( callable $callback ): Closure {
		return static function ( ...$args ) use ( $callback ) {
			try {
				return $callback( ...$args );
			} catch ( Throwable $e ) {
				error_log( 'empyrian-home-hub: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
				return $args[0] ?? null;
			}
		};
	}
}
