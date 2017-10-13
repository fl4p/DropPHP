<?php

/**
 * Some helper functions for the samples
 */

function demo_init() {
	error_reporting( E_ALL );
	enable_implicit_flush();
	echo "<pre>";
}


function demo_store_token( $token, $name ) {
	is_dir( 'tokens' ) || mkdir( 'tokens' );
	if ( ! file_put_contents( "tokens/$name.token", serialize( $token ) ) ) {
		die( '<br />Could not store token! <b>Make sure that the directory `tokens` exists and is writable!</b>' );
	}
}

function demo_token_load( $name ) {
	if ( ! file_exists( "tokens/$name.token" ) ) {
		return null;
	}

	return @unserialize( @file_get_contents( "tokens/$name.token" ) );
}

function demo_token_delete( $name ) {
	@unlink( "tokens/$name.token" );
}


function enable_implicit_flush() {
	if ( function_exists( 'apache_setenv' ) ) {
		@apache_setenv( 'no-gzip', 1 );
	}
	@ini_set( 'zlib.output_compression', 0 );
	@ini_set( 'implicit_flush', 1 );
	for ( $i = 0; $i < ob_get_level(); $i ++ ) {
		ob_end_flush();
	}
	ob_implicit_flush( 1 );
	echo "<!-- " . str_repeat( ' ', 2000 ) . " -->";
}