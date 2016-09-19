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
class DropboxClient
{

    const API_URL = "https://api.dropboxapi.com/";
    const API_CONTENT_URL = "https://content.dropboxapi.com/";

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

    private $_redirectUri;

    function __construct($app_params, $locale = "en")
    {
        $this->appParams = $app_params;
        if (empty($app_params['app_key']))
            throw new DropboxException("App Key is empty!");

        $this->consumerToken = array('t' => $this->appParams['app_key'], 's' => $this->appParams['app_secret']);
        $this->locale = $locale;
        $this->rootPath = empty($app_params['app_full_access']) ? "sandbox" : "dropbox";

        $this->requestToken = null;
        $this->accessToken = null;

        $this->useCurl = function_exists('curl_init');
    }

    function __wakeup()
    {
        $this->useCurl = $this->useCurl && function_exists('curl_init');
    }

    /**
     * Sets whether to use cURL if its available or PHP HTTP wrappers otherwise
     *
     * @param boolean $use_it whether to use it or not
     * @return boolean Whether to actually use cURL (always false if not installed)
     */
    public function SetUseCUrl($use_it)
    {
        return ($this->useCurl = ($use_it && function_exists('curl_init')));
    }

    // ##################################################
    // Authorization

    /**
     * Step 1. Returns a URL the user must be redirected to in order to connect the app to their Dropbox account
     *
     * @param string $redirect_uri URL users are redirected after authorization
     * @param string $state Up to 500 bytes of arbirary data passed back to $redirect_uri
     * @return string URL
     */
    public function BuildAuthorizeUrl($redirect_uri, $state = '')
    {
        $this->_redirectUri = $redirect_uri;
        return "https://www.dropbox.com/oauth2/authorize?response_type=code&client_id={$this->appParams['app_key']}&redirect_uri=" . urlencode($redirect_uri) . "&state=" . urlencode($state);
    }


    /**
     * Step 2.
     *
     * @param string $code The code GET param Dropbox generates when HTTP-redirecting the client
     * @param string $redirect_uri The same reidrect_uri as passed to BuildAuthorizeUrl() before, used for validation
     * @return array
     * @throws DropboxException
     */
    public function GetBearerToken($code = '', $redirect_uri = '')
    {
        if (!empty($this->accessToken))
            return $this->accessToken;

        if (empty($code)) {
            $code = filter_input(INPUT_GET, 'code', FILTER_SANITIZE_STRING);
            if (empty($code))
                throw new DropboxException('Missing OAuth2 code parameter!');
        }

        if (!empty($redirect_uri))
            $this->_redirectUri = $redirect_uri;

        if (empty($this->_redirectUri)) {
            throw new DropboxException('Redirect URI unknown, please specify or call BuildAuthorizeUrl() before!');
        }

        $res = $this->apiCall("oauth2/token", array(
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $this->appParams['app_key'],
            'client_secret' => $this->appParams['app_secret'],
            'redirect_uri' => $this->_redirectUri
        ));

        if (empty($res) || empty($res->access_token))
            throw new DropboxException(sprintf('Could not get bearer token! (code: %s)', $code));

        return ($this->accessToken = array('t' => $res->access_token, 'account_id' => $res->account_id));
    }

    /**
     * Sets a previously retrieved (and stored) access token.
     *
     * @param string|object $token The Access Token
     * @throws DropboxException
     */
    public function SetBearerToken($token)
    {
        if (empty($token['t'])) throw new DropboxException('Passed invalid access token.');
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
        return !empty($this->accessToken);
    }

    // ##################################################
    // API Functions

    /**
     * Retrieves information about the user's account.
     *
     * @access public
     * @return object Account info object. See https://www.dropbox.com/developers/documentation/http/documentation#users-get_current_account
     */
    public function GetAccountInfo()
    {
        $info = $this->apiCall("2/users/get_current_account");
        $info->uid = $info->account_id;
        $info->name_details = $info->name;
        $info->display_name = $info->name->display_name;
        return $info;
    }

    /**
     * @param string $path
     * @param bool $recursive
     * @param bool $include_deleted
     * @return mixed
     * @throws DropboxException
     */
    public function GetFiles($path = '', $recursive = false, $include_deleted = false)
    {
        if (is_object($path) && !empty($path->path)) $path = $path->path;
        if ($path === '/') $path = '';

        $res = $this->apiCall("2/files/list_folder", compact('path', 'recursive', 'include_deleted'));
        $entries = $res->entries;

        while ($res->has_more) {
            $res = $this->apiCall("2/files/list_folder/continue", array('cursor' => $res->cursor));
            $entries = array_merge($entries, $res->entries);
        }

        $entries_assoc = array();
        foreach ($entries as $entry) {
            $entries_assoc[trim($entry->path_display, '/')] = $entry;
        }

        return array_map(array(__CLASS__, 'compatMeta'), $entries_assoc);
    }

    /**
     * See https://www.dropbox.com/developers/documentation/http/documentation#files-get_metadata
     *
     * @param $path
     * @param bool $include_deleted
     * @param null $rev
     * @return mixed
     * @throws DropboxException
     */
    public function GetMetadata($path, $include_deleted = false, $rev = null)
    {
        if (is_object($path) && !empty($path->path)) $path = $path->path;
        if (!empty($rev)) $path = "rev:$rev";
        return self::compatMeta($this->apiCall("2/files/get_metadata", compact('path', 'include_deleted')));
    }

    /**
     * Download a file from user's Dropbox to the webserver
     *
     * @param string|object $path Dropbox path or metadata object of the file to download.
     * @param string $dest_path Local path for destination
     * @param string $rev Optional. The revision of the file to retrieve. This defaults to the most recent revision.
     * @param callback $progress_changed_callback Optional. Callback that will be called during download with 2 args: 1. bytes loaded, 2. file size
     * @return object Dropbox file metadata
     * @throws DropboxException
     */
    public function DownloadFile($path, $dest_path = '', $rev = null, $progress_changed_callback = null)
    {
        if (is_object($path) && !empty($path->path))
            $path = $path->path;

        if (empty($dest_path)) $dest_path = basename($path);

        $url = $this->cleanUrl(self::API_CONTENT_URL . "2/files/download");
        if (!empty($rev)) $path = "rev:$rev";
        $context = $this->createRequestContext($url, compact('path'));

        $fh = @fopen($dest_path, 'wb'); // write binary
        if ($fh === false) {
            @fclose($rh);
            throw new DropboxException("Could not create file $dest_path !");
        }

        if ($this->useCurl) {
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
            if ($rh === false)
                throw new DropboxException("HTTP request to $url failed!");


            // get file meta from HTTP header
            $s_meta = stream_get_meta_data($rh);
            $meta = self::getMetaFromHeaders($s_meta['wrapper_data'], true);
            $bytes_loaded = 0;
            while (!feof($rh)) {
                if (($s = fwrite($fh, fread($rh, self::BUFFER_SIZE))) === false) {
                    @fclose($rh);
                    @fclose($fh);
                    throw new DropboxException("Writing to file $dest_path failed!'");
                }
                $bytes_loaded += $s;
                if (!empty($progress_changed_callback)) {
                    call_user_func($progress_changed_callback, $bytes_loaded, $meta->bytes);
                }
            }

            fclose($rh);
            fclose($fh);
        }

        if ($meta->size != $bytes_loaded)
            throw new DropboxException("Download size mismatch! (header:{$meta->size} vs actual:{$bytes_loaded}; curl:{$this->useCurl})");

        return $meta;
    }

    static function compatMeta($meta)
    {
        $meta->is_dir = !isset($meta->size) || is_null($meta->size);
        $meta->path = $meta->path_display;
        $meta->bytes = $meta->size;
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
    public function UploadFile($src_file, $path = '', $overwrite = true, $parent_rev = null)
    {
        if (empty($path)) $path = basename($src_file);
        $path = self::toPath($path);

        // make sure the dropbox_path is not a dir. if it is, append baseneme of $src_file
        $dropbox_bn = basename($path);
        if (strpos($dropbox_bn, '.') === false) { // check if ext. is missing -> could be a directory!
            try {
                $meta = $this->GetMetadata($path);
                if ($meta && $meta->is_dir)
                    $path = self::toPath($path . '/' . basename($src_file));
            } catch (Exception $e) {
            }
        }

        $file_size = filesize($src_file);

        $commit_params = array(
            'path' => $path,
            'mode' => $overwrite ? 'overwrite' : 'add',
            'autorename' => true
        );

        if ($file_size > self::UPLOAD_CHUNK_SIZE) {
            $fh = fopen($src_file, 'rb');
            if ($fh === false)
                throw new DropboxException();

            $offset = 0;

            $res = $this->apiCall("2/files/upload_session/start", array(), true);
            $session_id = $res->session_id;

            while (!feof($fh)) {
                $content = fread($fh, self::UPLOAD_CHUNK_SIZE);
                $this->apiCall("2/files/upload_session/append_v2", array('cursor' => compact('session_id', 'offset')), true, $content);
                $offset += strlen($content);
                unset($content);
                if ($offset >= $file_size)
                    break;
            }

            @fclose($fh);

            $offset = 0;
            return $this->apiCall('2/files/upload_session/finish', array(
                'cursor' => compact('session_id', 'offset'),
                'commit' => $commit_params
            ));
        } else {
            return $this->apiCall("2/files/upload", $commit_params, true, file_get_contents($src_file));
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
        $path = self::toPath($dropbox_file);

        $size_transform = array('xs' => 'w32h32', 's' => 'w64h64', 'm' => 'w128h128', 'l' => 'w640h480', 'xl' => 'w1024h768');
        if(isset($size_transform[$size])) $size = $size_transform[$size];

        $url = self::API_CONTENT_URL . '2/files/get_thumbnail';
        $context = $this->createRequestContext($url, compact("path","size", "format"));
        $thumb = $this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, false, $context);


        //$thumb = $this->apiCall('2/files/get_thumbnail', compact("path","size", "format"), true);

        if ($echo) {
            header('Content-type: image/' . $format);
            echo $thumb;
            unset($thumb);
            return '';
        }

        return $thumb;
    }


    static function toPath($file_or_path)
    {
        if (is_object($file_or_path)) $file_or_path = $file_or_path->path;
        $file_or_path = '/' . trim($file_or_path, '/');
        if($file_or_path == '/') $file_or_path = '';
        return $file_or_path;
    }

    function GetLink($path, $preview = true, $short = true, &$expires = null)
    {
        $path = self::toPath($path);

        if (!$preview) {
            $data = $this->apiCall("2/files/get_temporary_link", array(
                'path' => $path
            ));
            $expires = time() + (4 * 3600) - 60;
            return $data->link;
        } else {
            $url = $this->apiCall("2/sharing/create_shared_link_with_settings", array(
                'path' => $path,
                'settings' => array(
                    "requested_visibility" => "public",
                    //"expires" => "%Y-%m-%dT%H:%M:%SZ" TODO
                )));
            //$expires = strtotime($url->expires);
            return $url->url;
        }
    }

    function Delta($cursor, $path_prefix)
    {
        return $this->apiCall("delta", "POST", compact('cursor', 'path_prefix'));
    }

    function LatestCursor($path_prefix = null, $include_media_info = false)
    {
        $res = $this->apiCall("delta/latest_cursor", "POST", compact('path_prefix', 'include_media_info'));
        return $res->cursor;
    }

    function GetRevisions($path, $limit = 10)
    {
        $path = self::toPath($path);
        return $this->apiCall("2/files/list_revisions", compact('path', 'limit'))->entries;
    }

    function Restore($dropbox_file, $rev)
    {
        if (is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
        return $this->apiCall("restore/$this->rootPath/$dropbox_file", "POST", compact('rev'));
    }

    function Search($path, $query, $max_results = 1000, $include_deleted = false)
    {
        $path = self::toPath($path);
        $mode = $include_deleted ? 'deleted_filename' : 'filename';

        $meta = array();
        foreach($this->apiCall("2/files/search", compact('path', 'query', 'max_results', 'mode'))->matches as $match) {
            $meta[] = self::compatMeta($match->metadata);
        }
        return $meta;
    }

    function GetCopyRef($dropbox_file, &$expires = null)
    {
        if (is_object($dropbox_file) && !empty($dropbox_file->path)) $dropbox_file = $dropbox_file->path;
        $ref = $this->apiCall("copy_ref/$this->rootPath/$dropbox_file", "GET", array('locale' => null));
        $expires = strtotime($ref->expires);
        return $ref->copy_ref;
    }


    function Copy($from_path, $to_path, $copy_ref = false)
    {
        if (is_object($from_path) && !empty($from_path->path)) $from_path = $from_path->path;
        return $this->apiCall("fileops/copy", "POST", array('root' => $this->rootPath, ($copy_ref ? 'from_copy_ref' : 'from_path') => $from_path, 'to_path' => $to_path));
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
        return $this->apiCall("fileops/create_folder", "POST", array('root' => $this->rootPath, 'path' => $path));
    }

    /**
     * Delete file or folder
     *
     * @param $path mixed The path or metadata of the file/folder to be deleted.
     * @return object Dropbox metadata of deleted file or folder
     */
    function Delete($path)
    {
        if (is_object($path) && !empty($path->path)) $path = $path->path;
        return $this->apiCall("fileops/delete", "POST", array('locale' => null, 'root' => $this->rootPath, 'path' => $path));
    }

    function Move($from_path, $to_path)
    {
        if (is_object($from_path) && !empty($from_path->path)) $from_path = $from_path->path;
        return $this->apiCall("fileops/move", "POST", array('root' => $this->rootPath, 'from_path' => $from_path, 'to_path' => $to_path));
    }


    // END of API functions


    private function createCurl($url, $http_context)
    {
        $ch = curl_init($url);

        $curl_opts = array(
            CURLOPT_HEADER => false, // exclude header from output //CURLOPT_MUTE => true, // no output!
            CURLOPT_RETURNTRANSFER => true, // but return!
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_BINARYTRANSFER => true
        );

        //curl_setopt($context, , true);

        $curl_opts[CURLOPT_CUSTOMREQUEST] = $http_context['method'];

        if (!empty($http_context['content'])) {
            $curl_opts[CURLOPT_POSTFIELDS] =& $http_context['content'];
            if (defined("CURLOPT_POSTFIELDSIZE"))
                $curl_opts[CURLOPT_POSTFIELDSIZE] = strlen($http_context['content']);
        }

        $curl_opts[CURLOPT_HTTPHEADER] = array_map('trim', explode("\n", $http_context['header']));

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
        if (is_array($out_response_headers)) {
            self::$_curlHeadersRef =& $out_response_headers;
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, array(__CLASS__, '_curlHeaderCallback'));
        }
        $res = curl_exec($ch);
        $err_no = curl_errno($ch);
        $err_str = curl_error($ch);
        curl_close($ch);
        if ($err_no || $res === false) {
            throw new DropboxException("cURL-Error ($err_no): $err_str");
        }

        return $res;
    }

    /**
     * @param $url
     * @param $params
     * @param null $content
     * @param int $bearer_token
     * @return resource
     */
    private function createRequestContext($url, $params, &$content = "", $bearer_token = -1)
    {
        if ($bearer_token === -1)
            $bearer_token = $this->accessToken['t'];

        $http_context = array('method' => "POST", 'header' => '', 'content' => '');

        if (strpos($url, '/oauth2/token') !== false) {
            $http_context['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $http_context['content'] = http_build_query($params);
        } else {

            if (!empty($bearer_token))
                $http_context['header'] .= "Authorization: Bearer $bearer_token\r\n";

            if (empty($content) && strpos($url, self::API_CONTENT_URL) === false) {
                if (!empty($params)) {
                    $http_context['header'] .= "Content-Type: application/json\r\n";
                    $http_context['content'] = json_encode($params);
                }
            } else {
                $http_context['header'] .= 'Dropbox-API-Arg: ' . str_replace('"', '"', json_encode($params)) . "\r\n";
                if (!empty($content)) {
                    $http_context['header'] .= "Content-Type: application/octet-stream\r\n";

                    $http_context['content'] =& $content;
                }
            }
        }

        if (strpos($url, self::API_CONTENT_URL) === false)
            $http_context['header'] .= "Content-Length: " . strlen($http_context['content']);
        //echo $url;
        $http_context['header'] = trim($http_context['header']);
       // print_r($http_context);
        return $this->useCurl ? $this->createCurl($url, $http_context) : stream_context_create(array('http' => $http_context));
    }

    private
    static function checkForError($resp, $context = null)
    {
        if (!empty($resp->error))
            throw new DropboxException(json_encode($resp->error) . ": " . $resp->error_summary . $resp->error_description . ($context ? ", in $context" : ""));
        return $resp;
    }


    private
    function apiCall($path, $params = array(), $content_call = false, &$content = null)
    {
        $url = $this->cleanUrl(($content_call ? self::API_CONTENT_URL : self::API_URL) . $path);
        $context = $this->createRequestContext($url, $params, $content);

        $json = $this->useCurl ? self::execCurlAndClose($context) : file_get_contents($url, false, $context);
        $resp = json_decode($json);
        if (($resp === false || is_null($resp)) && !empty($json) && !$content_call) throw new DropboxException("Error apiCall($path): $json");
        return self::checkForError($resp, "apiCall($path)");
    }


    private
    static function getMetaFromHeaders(&$header_array, $throw_on_error = false)
    {
        $obj = json_decode(substr(@array_shift(array_filter($header_array, create_function('$s', 'return stripos($s, "dropbox-api-result:") === 0;'))), 20));
        if ($throw_on_error && (empty($obj) || !is_object($obj)))
            throw new DropboxException("Could not retrieve meta data from header data: " . print_r($header_array, true));
        if ($throw_on_error)
            self::checkForError($obj, __FUNCTION__);
        return self::compatMeta($obj);
    }


    function cleanUrl($url)
    {
        $p = substr($url, 0, 8);
        $url = str_replace('//', '/', str_replace('\\', '/', substr($url, 8)));
        $url = rawurlencode($url);
        $url = str_replace('%2F', '/', $url);
        return $p . $url;
    }

    /**
     * @deprecated
     * @throws DropboxException
     */
    public
    function GetRequestToken()
    {
        throw new DropboxException('GetRequestToken() has been removed with v2 API. Request tokens do not exist in OAuth2 anymore.');
    }

    /**
     * @deprecated
     * @throws DropboxException
     */
    public
    function GetAccessToken()
    {
        throw new DropboxException('GetAccessToken() has been removed with v2 API. Use GetBearerToken() instead!');
    }

}

class DropboxException extends Exception
{
    public function __construct($err = null, $isDebug = FALSE)
    {
        if (is_null($err)) {
            $el = error_get_last();
            $this->message = $el['message'];
            $this->file = $el['file'];
            $this->line = $el['line'];
        } else
            $this->message = $err;
        self::log_error($err);
        if ($isDebug) self::display_error($err, true);
    }

    public static function log_error($err)
    {
        error_log($err, 0);
    }

    public static function display_error($err, $dont_kill = false)
    {
        print_r($err);
        if (!$dont_kill) exit;
    }
}
