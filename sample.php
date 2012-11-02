<?php
/** 
 * DropPHP sample
 *
 * http://fabi.me/en/php-projects/dropphp-dropbox-api-client/
 *
 * @author     Fabian Schlieper <fabian@fabi.me>
 * @copyright  Fabian Schlieper 2012
 * @version    1.0
 * @license    See license.txt
 *
 */
 
error_reporting(E_ALL); // for debugging

require_once("DropboxClient.php");

// you have to create an app at https://www.dropbox.com/developers/apps and enter details below:
$dropbox = new DropboxClient(array(
	'app_key' => "tmc1teg0s3kogkn", 
	'app_secret' => "vg6eavix6a5mrp4",
	'app_full_access' => true,
),'en_GB');


// first try to load existing access token
$access_token = load_token("access");
if(!empty($access_token)) {
	$dropbox->SetAccessToken($access_token);
}
elseif(!empty($_GET['auth_callback'])) // are we coming from dropbox's auth page?
{
	// then load our previosly created request token
	$request_token = load_token($_GET['oauth_token']);
	if(empty($request_token)) die('Request token not found!');
	
	// get & store access token, the request token is not needed anymore
	$access_token = $dropbox->GetAccessToken($request_token);	
	store_token($access_token, "access");
	delete_token($_GET['oauth_token']);
}

// checks if access token is required
if(!$dropbox->IsAuthorized())
{
	// redirect user to dropbox auth page
	$return_url = "http://".$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']."?auth_callback=1";
	$auth_url = $dropbox->BuildAuthorizeUrl($return_url);
	$request_token = $dropbox->GetRequestToken();
	store_token($request_token, $request_token['t']);
	die("Authentication required. <a href='$auth_url'>Click here.</a>");
}

echo "<html><header><title>DropPHP Sample</title><body><pre>";
echo "<b>Account:</b>\r\n";
print_r($dropbox->GetAccountInfo());

//$dropbox->Copy("Unbenannt2.JPG, $to_path)
$files = $dropbox->GetFiles("",false);
$fi = reset($files);
print_r($dropbox->Copy($fi, "copyied"));

exit;

$files = $dropbox->GetFiles("",false);

echo "\r\n<b>Files:</b>\r\n";
print_r(array_keys($files));

if(!empty($files)) {
	$file = reset($files); // get first file
	$test_file = "test_download_".basename($file->path);
	
	$dropbox->Copy($file->path, $file->path.'.copy');
	
	echo "\r\n\r\n<b>Meta data of $file->path:</b>\r\n";
	print_r($dropbox->GetMetadata($file->path));
	
	$link_preview = $dropbox->GetLink($file, true, true);
	$link_direct = $dropbox->GetLink($file, false);
	echo "\r\n<b><a href='$link_preview'>Preview</a> or <a href='$link_direct'>Direct Download</a></b>\r\n";
	
	echo "\r\n\r\n<b>Downloading $file->path:</b>\r\n";
	print_r($dropbox->DownloadFile($file, $test_file));
		
	echo "\r\n\r\n<b>Uploading $test_file:</b>\r\n";
	print_r($dropbox->UploadFile($test_file));
}
echo "</body></html>";

// these are just sample functions to load/store tokens:

function store_token($token, $name)
{
	file_put_contents("tokens/$name.token", serialize($token));
}

function load_token($name)
{
	if(!file_exists("tokens/$name.token")) return null;
	return @unserialize(@file_get_contents("tokens/$name.token"));
}

function delete_token($name)
{
	@unlink("tokens/$name.token");
}