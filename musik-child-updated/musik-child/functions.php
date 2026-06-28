<?php
add_action( 'wp_enqueue_scripts', 'enqueue_parent_styles' );
function enqueue_parent_styles(){
	wp_enqueue_style('parent-style', get_template_directory_uri().'/style.css');
}

// Ensure Mynger SSO users can be created and linked to existing WordPress accounts.
// The openid-connect-generic plugin reads its settings from WordPress options;
// these filters override the relevant flags at runtime without touching the database.
add_filter( 'option_openid_connect_generic_settings', 'empyrian_oidc_settings' );
function empyrian_oidc_settings( $settings ) {
	if ( is_array( $settings ) ) {
		$settings['create_if_does_not_exist'] = 1;
		$settings['link_existing_users']       = 1;
	}
	return $settings;
}
