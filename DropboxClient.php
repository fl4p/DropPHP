<?php
/**
 * DropPHP - A simple Dropbox client that works without cURL.
 *
 * http://fabi.me/en/php-projects/dropphp-dropbox-api-client/
 *
 *
 * @author     Fabian Schlieper <fabian@fabi.me>
 * @copyright  Fabian Schlieper 2014
 * @version    1.7.1
 * @license    See LICENSE
 *
 */

require_once(dirname(__FILE__)."/OAuthSimple.php");

class DropboxClient {

	const API_URL = "https://api.dropbox.com/1/";
	const API_CONTENT_URL = "https://api-content.dropbox.com/1/";

	const BUFFER_SIZE = 4096;

	const MAX_UPLOAD_CHUNK_SIZE = 150000000; // 150MB

	const UPLOAD_CHUNK_SIZE = 4000000; // 4MB

	private $appParams;
	private $consumerToken;

	private $requestToken;
	private $accessToken;

	private $locale;
	private $rootPath;

	private $useCurl;

	function __construct ($app_params, $locale = "en"){
		$this->appParams = $app_params;
		if(empty($app_params['app_key']))
			throw new DropboxException("App Key is empty!");

		$this->consumerToken = array('t' => $this->appParams['app_key'], 's' => $this->appParams['app_secret']);
		$this->locale = $locale;
		$this->rootPath = empty($app_params['app_full_access']) ? "sandbox" : "dropbox";

		$this->requestToken = null;
		$this->accessToken = null;

		$this->useCurl = function_exists('curl_init');
	}

	function __wakeup() {
		$this->useCurl = $this->useCurl && function_exists('curl_init');
	}
    /**
	 * Sets whether to use cURL if its available or PHP HTTP wrappers otherwise
	 *
	 * @access public
	 * @return boolean Whether to actually use cURL (always false if not installed)
	 */
	public function SetUseCUrl($use_it)
	{
		return ($this->useCurl = ($use_it && function_exists('curl_init')));
	}

	// ##################################################
	// Authorization

    /**
	 * Step 1 of authentication process. Retrieves a request token or returns a previously retrieved one.
	 *
	 * @access public
	 * @param boolean $get_new_token Optional (default false). Wether to retrieve a new request token.
	 * @return array Request Token array.
	 */
	public function GetRequestToken($get_new_token=false)
	{
		if(!empty($this->requestToken) && !$get_new_token)
			return $this->requestToken;

		$rt = $this->authCall("oauth/request_token");
		if(empty($rt) || empty($rt['oauth_token']))
			throw new DropboxException('Could not get request token!');

		return ($this->requestToken = array('t'=>$rt['oauth_token'], 's'=>$rt['oauth_token_secret']));
	}

    /**
	 * Step 2. Returns a URL the user must be redirected to in order to connect the app to their Dropbox account
	 *
	 * @access public
	 * @param string $return_url URL users are redirected after authorization
	 * @return string URL
	 */
	public function BuildAuthorizeUrl($return_url)
	{
		$rt = $this->GetRequestToken();
		if(empty($rt) || empty($rt['t'])) throw new DropboxException('Request Token Invalid ('.print_r($rt,true).').');
		return "https://www.dropbox.com/1/oauth/authorize?oauth_token=".$rt['t']."&oauth_callback=".urlencode($return_url);
	}

    /**
	 * Step 3. Acquires an access token. This is the final step of authentication.
	 *
	 * @access public
	 * @param array $request_token Optional. The previously retrieved request token. This parameter can only be skipped if the DropboxClient object has been (de)serialized.
	 * @return array Access Token array.
	 */
	public function GetAccessToken($request_token = null)
	{
		if(!empty($this->accessToken)) return $this->accessToken;

		if(empty($request_token)) $request_token = $this->requestToken;
		if(empty($request_token)) throw new DropboxException('Request token required!');

		$at = $this->authCall("oauth/access_token", $request_token);
		if(empty($at))
			throw new DropboxException(sprintf('Could not get access token! (request token: %s)', $request_token['t']));

		return ($this->accessToken = array('t'=>$at['oauth_token'], 's'=>$at['oauth_token_secret']));
	}

    /**
	 * Sets a previously retrieved (and stored) access token.
	 *
	 * @access public
	 * @param string|object $token The Access Token
	 * @return none
	 */
	public function SetAccessToken($token)
	{
		if(empty($token['t']) || empty($token['s'])) throw new DropboxException('Passed invalid access token.');
			$this->accessToken = $token;
	}

    /**
	 * Checks if an access token has been set.
	 *
	 * @access public
	 * @return boolean Authorized or not
	 */
	public function IsAuthorized()
	{
		if(empty($this->accessToken)) return false;
		return true;
	}


	// ##################################################
	// API Functions


    /**
	 * Retrieves information about the user's account.
	 *
	 * @access public
	 * @return object Account info object. See https://www.dropbox.com/developers/reference/api#account-info
	 */
	public function GetAccountInfo()
	{
		return $this->apiCall("account/info", "GET");
	}


    /**
	 * Get file list of a dropbox folder.
	 *
	 * @access public
	 * @param string|object $dropbox_path Dropbox path of the folder
	 * @return array An array with metadata of files/folders keyed by paths
	 */
	public function GetFiles($dropbox_path='', $recursive=false, $include_deleted=false)
	{
		if(is_object($dropbox_path) && !empty($dropbox_path->path)) $dropbox_path = $dropbox_path->path;
		return $this->getFileTree($dropbox_path, $include_deleted, $recursive ? 1000 : 0);
	}

    /**
	 * Get file or folder metadata
	 *
	 * @access public
	 * @param $dropbox_path string Dropbox path of the file or folder
	 */
	public function GetMetadata($dropbox_path, $include_deleted=false, $rev=null)
	{
		if(is_object($dropbox_path) && !empty($dropbox_path->path)) $dropbox_path = $dropbox_path->path;
		return $this->apiCall("metadata/$this->rootPath/$dropbox_path", "GET", compact('include_deleted','rev'));
	}

    /**
	 * Download a file to the webserver
	 *
	 * @access public
	 * @param string|object $dropbox_file Dropbox path or metadata object of the file to download.
	 * @param string $dest_path Local path for destination
	 * @param string $rev Optional. The revision of the file to retrieve. This defaults to the most recent revision.
	 * @param callback $progress_changed_callback Optional. Callback that will be called during download with 2 args: 1. bytes loaded, 2. file size
	 * @return object Dropbox file metadata
	 */
	public function DownloadFile($dropbox_file, $dest_path='', $rev=null, $progress_changed_callback = null)
	{
		if(is_object($dropbox_file) && !empty($dropbox_file->path))
			$dropbox_file = $dropbox_file->path;

		if(empty($dest_path)) $dest_path = basename($dropbox_file);

		$url = $this->cleanUrl(self::API_CONTENT_URL."/files/$this->rootPath/$dropbox_file")
			. (!empty($rev) ? ('?'.http_build_query(array('rev' => $rev),'','&')) : '');
		$context = $this->createRequestContext($url, "GET");

		$fh = @fopen($dest_path, 'wb'); // write binary
		if($fh === false) {
			@fclose($rh);
			throw new DropboxException("Could not create file $dest_path !");
		}

		if($this->useCurl) {
			curl_setopt($context, CURLOPT_BINARYTRANSFER, true);
			curl_setopt($context, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($context, CURLOPT_FILE, $fh);
			$response_headers = array();
			self::execCurlAndClose($context, $response_headers);
			fclose($fh);
			$meta = self::getMetaFromHeaders($response_headers, true);
			$bytes_loaded = filesize($dest_path);
		} else {
			$rh = @fopen($url, 'rb', false, $context); // read binary
			if($rh === false)
				throw new DropboxException("HTTP request to $url failed!");


			// get file meta from HTTP header
			$s_meta = stream_get_meta_data($rh);
			$meta = self::getMetaFromHeaders($s_meta['wrapper_data'], true);
			$bytes_loaded = 0;
			while (!feof($rh)) {
			  if(($s=fwrite($fh, fread($rh, self::BUFFER_SIZE))) === false) {
				@fclose($rh);
				@fclose($fh);
				throw new DropboxException("Writing to file $dest_path failed!'");
			  }
			  $bytes_loaded += $s;
			  if(!empty($progress_changed_callback)) {
			  	call_user_func($progress_changed_callback, $bytes_loaded, $meta->bytes);
			  }
			}

			fclose($rh);
			fclose($fh);
		}

		if($meta->bytes != $bytes_loaded)
			throw new DropboxException("Download size mismatch! (header:{$meta->bytes} vs actual:{$bytes_loaded}; curl:{$this->useCurl})");

		return $meta;
	}

    /**
	 * Upload a file to dropbox
	 *
	 * @access public
	 * @param $src_file string Local file to upload
	 * @param $dropbox_path string Dropbox path for destination
	 * @return object Dropbox file metadata
	 */
	public function UploadFile($src_file, $dropbox_path='', $overwrite=true, $parent_rev=null)
	{
		if(empty($dropbox_path)) $dropbox_path = basename($src_file);
		elseif(is_object($dropbox_path) && !empty($dropbox_path->path)) $dropbox_path = $dropbox_path->path;

		// make sure the dropbox_path is not a dir. if it is, append baseneme of $src_file
		$dropbox_bn = basename($dropbox_path);
		if(strpos($dropbox_bn,'.') === false) { // check if ext. is missing -> could be a directory!
			try {
				$meta = $this->GetMetadata($dropbox_path);
				if($meta && $meta->is_dir)
					$dropbox_path = $dropbox_path . '/'. basename($src_file);
			} catch(Exception $e) {}
		}

		$file_size = filesize($src_file);

		if($file_size > self::MAX_UPLOAD_CHUNK_SIZE)
		{
			$fh = fopen($src_file,'rb');
			if($fh === false)
				throw new DropboxException();

			$upload_id = null;
			$offset = 0;


			while(!feof($fh)) {
				$url = $this->cleanUrl(self::API_CONTENT_URL."/chunked_upload").'?'.http_build_query(compact('upload_id', 'offset'),'','&');
				$content = fread($fh, self::UPLOAD_CHUNK_SIZE);
				$context = $this->createRequestContext($url, "PUT", $content);

				if($this->useCurl) {
					curl_setopt($context, CURLOPT_BINARYTRANSFER, true);
					$response = json_decode(self::execCurlAndClose($context));
				} else {
					$response = json_decode(file_get_contents($url, false, $context));
				}
				$offset += strlen($content);
				unset($content);
				unset($context);

				self::checkForError($response);

				if(empty($upload_id)) {
					$upload_id = $response->upload_id;
					if(empty($upload_id)) throw new DropboxException("Upload ID empty!");
				}
				if($offset >= $file_size)
					break;
			}

			@fclose($fh);

			return $this->apiCall("commit_chunked_upload/$this->rootPath/$dropbox_path", "POST", compact('overwrite','parent_rev','upload_id'), true);
		}

		$query = http_build_query(array_merge(compact('overwrite', 'parent_rev'), array('locale' => $this->locale)),'','&');
		$url = $this->cleanUrl(self::API_CONTENT_URL."/files_put/$this->rootPath/$dropbox_path")."?$query";

		if($this->useCurl) {
			$context = $this->createRequestContext($url, "PUT");
			curl_setopt($context, CURLOPT_BINARYTRANSFER, true);
			$fh = fopen($src_file, 'rb');
			curl_setopt($context, CURLOPT_PUT, 1);
			curl_setopt($context, CURLOPT_INFILE, $fh); // file pointer
			curl_setopt($context, CURLOPT_INFILESIZE, filesize($src_file));
			$meta = json_decode(self::execCurlAndClose($context));
			fclose($fh);
			return self::checkForError($meta);
		} else {
			$content = file_get_contents($src_file);
			if(strlen($content) == 0)
				throw new DropboxException("Could not read file $src_file or file is empty!");

			$context = $this->createRequestContext($url, "PUT", $content);

			return self::checkForError(json_decode(file_get_contents($url, false, $context)));
		}
	}

	/**
	* Get thumbnail for a specified image
	*
	* @access public
	* @param $dropbox_file string Path to the image
	* @param $format string Image format of the thumbnail (jpeg or png)
	* @param $size string Thumbnail size (xs, s, m, l, xl)
	* @return mime/* Returns the thumbnail as binary image data
	*/
	public function GetThumbnail($dropbox_file, $size = 's', $format = 'jpeg', $echo = false)
	{
		if(is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
		$url = $this->cleanUrl(self::API_CONTENT_URL."thumbnails/$this->rootPath/$dropbox_file")
			. '?' . http_build_query(array('format' => $format, 'size' => $size),'','&');
		$context = $this->createRequestContext($url, "GET");

		if($this->useCurl) {
			curl_setopt($context, CURLOPT_BINARYTRANSFER, true);
			curl_setopt($context, CURLOPT_RETURNTRANSFER, true);
		}

		$thumb = $this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, NULL, $context);

		if($echo) {
			header('Content-type: image/'.$format);
			echo $thumb;
			unset($thumb);
			return;
		}

		return $thumb;
	}


	function GetLink($dropbox_file, $preview=true, $short=true, &$expires=null)
	{
		if(is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
		$url = $this->apiCall(($preview?"shares":"media")."/$this->rootPath/$dropbox_file", "POST", array('locale' => null, 'short_url'=> $preview ? $short : null));
		$expires = strtotime($url->expires);
		return $url->url;
	}

	function Delta($cursor)
	{
		return $this->apiCall("delta", "POST", compact('cursor'));
	}

	function GetRevisions($dropbox_file, $rev_limit=10)
	{
		if(is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
		return $this->apiCall("revisions/$this->rootPath/$dropbox_file", "GET", compact('rev_limit'));
	}

	function Restore($dropbox_file, $rev)
	{
		if(is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
		return $this->apiCall("restore/$this->rootPath/$dropbox_file", "POST", compact('rev'));
	}

	function Search($path, $query, $file_limit=1000, $include_deleted=false)
	{
		return $this->apiCall("search/$this->rootPath/$path", "POST", compact('query','file_limit','include_deleted'));
	}

	function GetCopyRef($dropbox_file, &$expires=null)
	{
		if(is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
		$ref = $this->apiCall("copy_ref/$this->rootPath/$dropbox_file", "GET", array('locale' => null));
		$expires = strtotime($ref->expires);
		return $ref->copy_ref;
	}


	function Copy($from_path, $to_path, $copy_ref=false)
	{
		if(is_object($from_path) && !empty($from_path->path)) $from_path = $from_path->path;
		return $this->apiCall("fileops/copy", "POST", array('root'=> $this->rootPath, ($copy_ref ? 'from_copy_ref' : 'from_path') => $from_path, 'to_path' => $to_path));
	}

    /**
	 * Creates a new folder in the DropBox
	 *
	 * @access public
	 * @param $path string The path to the new folder to create
	 * @return object Dropbox folder metadata
	 */
	function CreateFolder($path)
	{
		return $this->apiCall("fileops/create_folder", "POST", array('root'=> $this->rootPath, 'path' => $path));
	}

    /**
	 * Delete file or folder
	 *
	 * @access public
	 * @param $path mixed The path or metadata of the file/folder to be deleted.
	 * @return object Dropbox metadata of deleted file or folder
	 */
	function Delete($path)
	{
		if(is_object($path) && !empty($path->path)) $path = $path->path;
		return $this->apiCall("fileops/delete", "POST", array('locale' =>null, 'root'=> $this->rootPath, 'path' => $path));
	}

	function Move($from_path, $to_path)
	{
		if(is_object($from_path) && !empty($from_path->path)) $from_path = $from_path->path;
		return $this->apiCall("fileops/move", "POST", array('root'=> $this->rootPath, 'from_path' => $from_path, 'to_path' => $to_path));
	}

	function getFileTree($path="", $include_deleted = false, $max_depth = 0, $depth=0)
	{
		static $files;
		if($depth == 0) $files = array();

		$dir = $this->apiCall("metadata/$this->rootPath/$path", "GET", compact('include_deleted'));

		if(empty($dir) || !is_object($dir)) return false;

		if(!empty($dir->error)) throw new DropboxException($dir->error);

		foreach($dir->contents as $item)
		{
			$files[trim($item->path,'/')] = $item;
			if($item->is_dir && $depth < $max_depth)
			{
				$this->getFileTree($item->path, $include_deleted, $max_depth, $depth+1);
			}
		}

		return $files;
	}

	function createCurl($url, $http_context)
	{
		$ch = curl_init($url);

		$curl_opts = array(
				CURLOPT_HEADER => false, // exclude header from output
				//CURLOPT_MUTE => true, // no output!
				CURLOPT_RETURNTRANSFER => true, // but return!
				CURLOPT_SSL_VERIFYPEER => false,
		);

		$curl_opts[CURLOPT_CUSTOMREQUEST] = $http_context['method'];

		if(!empty($http_context['content'])) {
			$curl_opts[CURLOPT_POSTFIELDS] =& $http_context['content'];
			if(defined("CURLOPT_POSTFIELDSIZE"))
				$curl_opts[CURLOPT_POSTFIELDSIZE] = strlen($http_context['content']);
		}

		$curl_opts[CURLOPT_HTTPHEADER] = array_map('trim',explode("\n",$http_context['header']));

		curl_setopt_array($ch, $curl_opts);
		return $ch;
	}

	static private $_curlHeadersRef;
	static function _curlHeaderCallback($ch, $header)
	{
		self::$_curlHeadersRef[] = trim($header);
		return strlen($header);
	}

	static function &execCurlAndClose($ch, &$out_response_headers = null)
	{
		if(is_array($out_response_headers)) {
			self::$_curlHeadersRef =& $out_response_headers;
			curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(__CLASS__, '_curlHeaderCallback'));
		}
		$res = curl_exec($ch);
		$err_no = curl_errno($ch);
		$err_str = curl_error($ch);
		curl_close($ch);
		if($err_no || $res === false) {
			throw new DropboxException("cURL-Error ($err_no): $err_str");
		}

		return $res;
	}

	private function createRequestContext($url, $method, &$content=null, $oauth_token=-1)
	{
		if($oauth_token === -1)
			$oauth_token = $this->accessToken;

		$method = strtoupper($method);
		$http_context = array('method'=>$method, 'header'=> '');

		$oauth = new OAuthSimple($this->consumerToken['t'],$this->consumerToken['s']);

		if(empty($oauth_token) && !empty($this->accessToken))
			$oauth_token = $this->accessToken;

		if(!empty($oauth_token)) {
			$oauth->setParameters(array('oauth_token' => $oauth_token['t']));
			$oauth->signatures(array('oauth_secret'=>$oauth_token['s']));
		}

		if(!empty($content)) {
			$post_vars = ($method != "PUT" && preg_match("/^[a-z][a-z0-9_]*=/i", substr($content, 0, 32)));
			$http_context['header'] .= "Content-Length: ".strlen($content)."\r\n";
			$http_context['header'] .= "Content-Type: application/".($post_vars?"x-www-form-urlencoded":"octet-stream")."\r\n";
			$http_context['content'] =& $content;
			if($method == "POST" && $post_vars)
				$oauth->setParameters($content);
		} elseif($method == "POST") {
			// make sure that content-length is always set when post request (otherwise some wrappers fail!)
			$http_context['content'] = "";
			$http_context['header'] .= "Content-Length: 0\r\n";
		}


		// check for query vars in url and add them to oauth parameters (and remove from path)
		$path = $url;
		$query = strrchr($url,'?');
		if(!empty($query)) {
			$oauth->setParameters(substr($query,1));
			$path = substr($url, 0, -strlen($query));
		}


		$signed = $oauth->sign(array(
			'action' => $method,
            'path'=> $path));
		//print_r($signed);

		$http_context['header'] .= "Authorization: ".$signed['header']."\r\n";

		return $this->useCurl ? $this->createCurl($url, $http_context) : stream_context_create(array('http'=>$http_context));
	}

	private function authCall($path, $request_token=null)
	{
		$url = $this->cleanUrl(self::API_URL.$path);
		$dummy = null;
		$context = $this->createRequestContext($url, "POST", $dummy, $request_token);

		$contents = $this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, false, $context);
		$data = array();
		parse_str($contents, $data);
		return $data;
	}

	private static function checkForError($resp)
	{
		if(!empty($resp->error))
			throw new DropboxException($resp->error);
		return $resp;
	}


	private function apiCall($path, $method, $params=array(), $content_call=false)
	{
		$url = $this->cleanUrl(($content_call ? self::API_CONTENT_URL : self::API_URL).$path);
		$content = http_build_query(array_merge(array('locale'=>$this->locale), $params),'','&');

		if($method == "GET") {
			$url .= "?".$content;
			$content = null;
		}

		$context = $this->createRequestContext($url, $method, $content);
		$json = $this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, false, $context);
		//if($json === false)
//			throw new DropboxException();
		$resp = json_decode($json);
		return self::checkForError($resp);
	}


	private static function getMetaFromHeaders(&$header_array, $throw_on_error=false)
	{
		$obj = json_decode(substr(@array_shift(array_filter($header_array, create_function('$s', 'return stripos($s, "x-dropbox-metadata:") === 0;'))), 20));
		if($throw_on_error && (empty($obj)||!is_object($obj)))
			throw new DropboxException("Could not retrieve meta data from header data: ".print_r($header_array,true));
		if($throw_on_error)
			self::checkForError ($obj);
		return $obj;
	}


	function cleanUrl($url) {
		$p = substr($url,0,8);
		$url = str_replace('//','/', str_replace('\\','/',substr($url,8)));
		$url = rawurlencode($url);
		$url = str_replace('%2F', '/', $url);
		return $p.$url;
	}
}

class DropboxException extends Exception {

	public function __construct($err = null, $isDebug = FALSE)
	{
		if(is_null($err)) {
			$el = error_get_last();
			$this->message = $el['message'];
			$this->file = $el['file'];
			$this->line = $el['line'];
		} else
			$this->message = $err;
		self::log_error($err);
		if ($isDebug)
		{
			self::display_error($err, TRUE);
		}
	}

	public static function log_error($err)
	{
		error_log($err, 0);
	}

	public static function display_error($err, $kill = FALSE)
	{
		print_r($err);
		if ($kill === FALSE)
		{
			die();
		}
	}
}
