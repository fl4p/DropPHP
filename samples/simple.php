<?php
/**
 * DropPHP Demo
 *
 * http://fabi.me/en/php-projects/dropphp-dropbox-api-client/
 *
 * @author     Fabian Schlieper <fabian@fabi.me>
 * @copyright  Fabian Schlieper 2012
 * @version    1.1
 * @license    See license.txt
 *
 */


require_once 'demo-lib.php';
demo_init(); // this just enables nicer output

// if there are many files in your Dropbox it can take some time, so disable the max. execution time
set_time_limit( 0 );

require_once '../DropboxClient.php';

/** you have to create an app at @see https://www.dropbox.com/developers/apps and enter details below: */
/** @noinspection SpellCheckingInspection */
$dropbox = new DropboxClient( array(
	'app_key'         => 'wbohh17zm0wn8w8',
	'app_secret'      => '6k8r6nb02z1de5e',
	'app_full_access' => false,
) );


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


echo "<pre>";
echo "<b>Account:</b>\n";
echo json_encode( $dropbox->GetAccountInfo(), JSON_PRETTY_PRINT );

$files = $dropbox->GetFiles( "", false );

echo "\n\n<b>Files:</b>\n";
print_r( array_keys( $files ) );

if ( ! empty( $files ) ) {
	$file      = reset( $files );
	$test_file = "test_download_" . basename( $file->path );

	echo "\n\n<b>Meta data of <a href='" . $dropbox->GetLink( $file ) . "'>$file->path</a>:</b>\n";
	print_r( $dropbox->GetMetadata( $file->path ) );

	echo "\n\n<b>Downloading $file->path:</b>\n";
	print_r( $dropbox->DownloadFile( $file, $test_file ) );

	echo "\n\n<b>Uploading $test_file:</b>\n";
	print_r( $dropbox->UploadFile( $test_file ) );
	echo "\n done!";

	echo "\n\n<b>Revisions of $test_file:</b>\n";
	print_r( $dropbox->GetRevisions( $test_file ) );
}

echo "\n\n<b>Searching for JPG files:</b>\n";
$jpg_files = $dropbox->Search( "/", ".jpg", 5 );
if ( empty( $jpg_files ) ) {
	echo "Nothing found.";
} else {
	print_r( $jpg_files );
	$jpg_file = reset( $jpg_files );

	echo "\n\n<b>Thumbnail of $jpg_file->path:</b>\n";
	$img_data = base64_encode( $dropbox->GetThumbnail( $jpg_file->path ) );
	echo "<img src=\"data:image/jpeg;base64,$img_data\" alt=\"Generating PDF thumbnail failed!\" style=\"border: 1px solid black;\" />";
}


