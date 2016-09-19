<?php
/** 
 * DropPHP sample
 *
 * http://fabi.me/en/php-projects/dropphp-dropbox-api-client/
 *
 * @author     Fabian Schlieper <fabian@fabi.me>
 * @copyright  Fabian Schlieper 2012
 * @version    1.1
 * @license    See license.txt
 *
 */
 


// these 2 lines are just to enable error reporting and disable output buffering (don't include this in you application!)
error_reporting(E_ALL);  
//enable_implicit_flush();
// -- end of unneeded stuff

// if there are many files in your Dropbox it can take some time, so disable the max. execution time
set_time_limit(0);

require_once("DropboxClient.php");

// you have to create an app at https://www.dropbox.com/developers/apps and enter details below:
$dropbox = new DropboxClient(array(
	'app_key' => "6gjbij0b95jqul8", 
	'app_secret' => "t1g8jps6ch5idso",
	'app_full_access' => false,
),'en');


$return_url = "https://".$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']."";

// first try to load existing access token
$access_token = load_token("access");
if(!empty($access_token)) {
	$dropbox->SetBearerToken($access_token);
	echo "loaded access token:";
	print_r($access_token);
}
elseif(!empty($_GET['code'])) // are we coming from dropbox's auth page?
{
	$access_token = $dropbox->GetBearerToken($_GET['code'], $return_url);
	store_token($access_token, "access");
	//delete_token($_GET['oauth_token']);
}

// checks if access token is required
if(!$dropbox->IsAuthorized())
{
	// redirect user to dropbox auth page
	
	$auth_url = $dropbox->BuildAuthorizeUrl($return_url);
	die("Authentication required. <a href='$auth_url'>Click here.</a>");
}


echo "<pre>";
echo "<b>Account:</b>\r\n";
print_r($dropbox->GetAccountInfo());

$files = $dropbox->GetFiles("",false);

echo "\r\n\r\n<b>Files:</b>\r\n";
print_r(array_keys($files));

if(!empty($files)) {
	$file = null;
	foreach($files as $meta) {
		if(!$meta->is_dir) {
			$file = $meta;
			break;
		}
	}
	$test_file = "test_download_".basename($file->path);
	
	echo "\r\n\r\n<b>Meta data of $file->path";
	echo " (<a href='".$dropbox->GetLink($file, true)."'>Preview</a>, <a href='".$dropbox->GetLink($file, false)."'>Download</a>) :</b>\r\n";
	print_r($dropbox->GetMetadata($file->path));
	
	echo "\r\n\r\n<b>Downloading $file->path:</b>\r\n";
	print_r($dropbox->DownloadFile($file, $test_file));
		
	echo "\r\n\r\n<b>Uploading $test_file:</b>\r\n";
	print_r($dropbox->UploadFile($test_file));
	echo "\r\n done!";	
	
	echo "\r\n\r\n<b>Revisions of $test_file:</b>\r\n";	
	print_r($dropbox->GetRevisions($test_file));
}
	
echo "\r\n\r\n<b>Searching for JPG files:</b>\r\n";	
$jpg_files = $dropbox->Search("/", ".jpg", 5);
if(empty($jpg_files))
	echo "Nothing found.";
else {
	print_r($jpg_files);
	$jpg_file = reset($jpg_files);

	echo "\r\n\r\n<b>Thumbnail of $jpg_file->path:</b>\r\n";	
	$img_data = base64_encode($dropbox->GetThumbnail($jpg_file->path));
	echo "<img src=\"data:image/jpeg;base64,$img_data\" alt=\"Generating PDF thumbnail failed!\" style=\"border: 1px solid black;\" />";
}


function store_token($token, $name)
{
	if(!file_put_contents("tokens/$name.token", serialize($token)))
		die('<br />Could not store token! <b>Make sure that the directory `tokens` exists and is writable!</b>');
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





function enable_implicit_flush()
{
	@apache_setenv('no-gzip', 1);
	@ini_set('zlib.output_compression', 0);
	@ini_set('implicit_flush', 1);
	for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
	ob_implicit_flush(1);
	echo "<!-- ".str_repeat(' ', 2000)." -->";
}