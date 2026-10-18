<?php
// Muzik keeps its logo, menu locations and Additional CSS as theme mods, which WordPress stores per theme.
add_action(
	'after_switch_theme',
	empyrian_safe(
		function () {
			if ( get_option( 'empyrian_child_inherited_mods' ) ) {
				return;
			}
			$parent = get_option( 'theme_mods_' . get_template() );
			if ( is_array( $parent ) ) {
				update_option( 'theme_mods_' . get_stylesheet(), array_merge( $parent, (array) get_option( 'theme_mods_' . get_stylesheet(), array() ) ) );
			}
			update_option( 'empyrian_child_inherited_mods', 1 );
		}
	)
);
