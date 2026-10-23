<?php
/**
 * Plugin Name: Mynger SSO entry
 * Description: Lets Mynger open this site already signed in: ?mynger_sso=1 starts the "Sign in with Mynger" (OpenID Connect) flow and returns to the same page.
 * Version: 1.1.0
 * Requires Plugins: daggerhart-openid-connect-generic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'mynger_sso_entry' );
add_action( 'plugins_loaded', 'mynger_sso_partition_embedded_cookies' );

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

// Inside Mynger's iframe this site is third-party: browsers drop its default (Lax) cookies there, so a login would never stick.
function mynger_sso_partition_embedded_cookies() {
	if ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) && 'iframe' === $_SERVER['HTTP_SEC_FETCH_DEST'] ) {
		header_register_callback( 'mynger_sso_rewrite_cookie_headers' );
	}
}

function mynger_sso_rewrite_cookie_headers() {
	$cookies = array_filter(
		headers_list(),
		function ( $header ) {
			return 0 === stripos( $header, 'Set-Cookie:' );
		}
	);
	if ( ! $cookies ) {
		return;
	}
	header_remove( 'Set-Cookie' );
	foreach ( $cookies as $cookie ) {
		header( mynger_sso_partitioned_cookie( $cookie ), false );
	}
}

function mynger_sso_partitioned_cookie( $header ) {
	if ( false !== stripos( $header, 'samesite=' ) ) {
		return $header;
	}
	$secure = preg_match( '/;\s*secure\b/i', $header ) ? '' : '; Secure';
	return $header . $secure . '; SameSite=None; Partitioned';
}
