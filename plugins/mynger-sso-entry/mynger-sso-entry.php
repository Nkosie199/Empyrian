<?php
/**
 * Plugin Name: Mynger SSO entry
 * Description: Lets Mynger open this site already signed in: ?mynger_sso=1 starts the "Sign in with Mynger" (OpenID Connect) flow and returns to the same page.
 * Version: 1.0.0
 * Requires Plugins: daggerhart-openid-connect-generic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'mynger_sso_entry' );

function mynger_sso_entry() {
	if ( ! isset( $_GET['mynger_sso'] ) ) {
		return;
	}

	$target = wp_validate_redirect( remove_query_arg( 'mynger_sso' ), home_url( '/' ) );

	if ( is_user_logged_in() || ! class_exists( 'OpenID_Connect_Generic' ) ) {
		wp_safe_redirect( $target );
		exit;
	}

	$oidc = OpenID_Connect_Generic::instance();
	if ( empty( $oidc->client_wrapper ) ) {
		wp_safe_redirect( $target );
		exit;
	}

	// The login endpoint is the Mynger API, so wp_safe_redirect would refuse it.
	wp_redirect( $oidc->client_wrapper->get_authentication_url( array( 'redirect_to' => $target ) ) );
	exit;
}
