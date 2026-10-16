<?php
// A broken module must never take the site or wp-admin down: load failures and hook errors are logged and skipped.

function empyrian_safe_load( $file ) {
	try {
		require_once $file;
	} catch ( Throwable $e ) {
		error_log( 'empyrian child: could not load ' . basename( $file ) . ': ' . $e->getMessage() );
	}
}

function empyrian_safe( callable $callback, $fallback = null ) {
	return function ( ...$args ) use ( $callback, $fallback ) {
		try {
			return call_user_func_array( $callback, $args );
		} catch ( Throwable $e ) {
			error_log( 'empyrian child: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			return null === $fallback && $args ? $args[0] : $fallback;
		}
	};
}
