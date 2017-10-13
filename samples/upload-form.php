<?php

require_once 'demo-lib.php';
demo_init(); // this just enables nicer output

require_once  '../DropboxClient.php';

// you have to create an app at https://www.dropbox.com/developers/apps and enter details below:
/** @noinspection SpellCheckingInspection */
$dropbox = new DropboxClient( array(
	'app_key'         => 'wbohh17zm0wn8w8',
	'app_secret'      => '6k8r6nb02z1de5e',
	'app_full_access' => false,
) );

handle_dropbox_auth( $dropbox ); // see below

// if there is no upload, show the form
if ( empty( $_FILES['the_upload'] ) ) {
	?>
    <form enctype="multipart/form-data" method="POST" action="">
        <p>
            <label for="file">Upload File</label>
            <input type="file" name="the_upload"/>
        </p>
        <p><input type="submit" name="submit-btn" value="Upload!"></p>
    </form>
<?php } else {

	$upload_name = $_FILES["the_upload"]["name"];
	echo "<pre>";
	echo "\n\n<b>Uploading $upload_name:</b>\r\n";
	$meta = $dropbox->UploadFile( $_FILES["the_upload"]["tmp_name"], $upload_name );
	print_r( $meta );
	echo "\r\n done!";
	echo "</pre>";
}



function handle_dropbox_auth( DropboxClient $dropbox ) {
	/**
	 * Dropbox will redirect the user here
	 * @var string $return_url
	 */
	$return_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'] . "?auth_redirect=1";

	// first, try to load existing access token
	$bearer_token = demo_token_load( "bearer" );

	if ( $bearer_token ) {
		$dropbox->SetBearerToken( $bearer_token );
		echo "loaded bearer token: " . json_encode( $bearer_token, JSON_PRETTY_PRINT ) . "\n";
	} elseif ( ! empty( $_GET['auth_redirect'] ) ) // are we coming from dropbox's auth page?
	{
		// get & store bearer token
		$bearer_token = $dropbox->GetBearerToken( null, $return_url );
		demo_store_token( $bearer_token, "bearer" );
	} elseif ( ! $dropbox->IsAuthorized() ) {
		// redirect user to Dropbox auth page
		$auth_url = $dropbox->BuildAuthorizeUrl( $return_url );
		die( "Authentication required. <a href='$auth_url'>Continue.</a>" );
	}
}