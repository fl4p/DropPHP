<?php

if ( ! class_exists( 'DropboxClient' ) ) {
	require_once 'DropboxClient.php';

	function dropboxClientAuthenticated() {
		/** you have to create an app at @see https://www.dropbox.com/developers/apps and enter details below: */
		/** @noinspection SpellCheckingInspection */
		$dropbox = new DropboxClient( array(
			'app_key'         => 'wbohh17zm0wn8w8',
			'app_secret'      => '6k8r6nb02z1de5e',
			'app_full_access' => false,
		) );

		$bearer = test_token_load( 'tokens/bearer' )
			?: test_token_load( 'samples/tokens/bearer' )
				?: test_token_load( 'bearer' )
					?: test_token_load( 'tests/bearer' );

		if ( ! $bearer ) {
			throw new RuntimeException( "Please run samples/simple.php!" );
		}

		$dropbox->SetBearerToken( $bearer );

		return $dropbox;
	}


	function test_token_load( $name ) {
		return @unserialize( @file_get_contents( "$name.token" ) );
	}
}