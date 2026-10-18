<?php
// Muzik loads its theme/theme.css and theme.js from the active theme's folder, which under a child theme doesn't hold them.

const EMPYRIAN_PARENT_ASSET_HANDLES = array( 'ffl-custom-style', 'ffl-theme-script' );

add_filter( 'style_loader_src', empyrian_safe( 'empyrian_parent_asset_src' ), 10, 2 );
add_filter( 'script_loader_src', empyrian_safe( 'empyrian_parent_asset_src' ), 10, 2 );

function empyrian_parent_asset_src( $src, $handle ) {
	if ( ! in_array( $handle, EMPYRIAN_PARENT_ASSET_HANDLES, true ) ) {
		return $src;
	}
	return str_replace( get_stylesheet_directory_uri(), get_template_directory_uri(), $src );
}
