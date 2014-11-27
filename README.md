DropPHP Dropbox API Class
===============================

DropPHP provides a simple interface for Dropbox's REST API to list, download and upload files.

For authentication it uses OAuthSimple, HTTPS requests are made with PHP's built in stream wrapper. It does not require any special PHP librarys like PEAR, cURL or OAUTH.

See sample.php for a basic demonstration.

Basic documentation can be found at http://fabi.me/en/php-projects/dropphp-dropbox-api-client/

Changelog
-------

= 1.7.1 =
* Check cURL availability on wakeup after object serialization

= 1.7 =
* Errors in server response after download  & upload will be thrown
* UploadFile checks if the $dropbox_path is an existing directory and puts the file there if so

= 1.6 =
* API_CONTENT_URL changed to https fixing the download size mismatch error
* Fixed cURL upload
* Fixed parameters for API GET requests (GetThumbnail, GetFiles ...)

= 1.5 =
* Added support for chunked uploads. Large files (>150MB) will automatically be uploaded in chunks.

= 1.4 =
* New API functions: GetThumbnail, GetRevisions, Restore, Search and GetCopyRef 
* Added $expires output parameter to GetLink function
* Added $copy_ref parameter to Copy function
* Added documentation to some functions
* Added new functions to sample.php

= 1.3 =
* cURL is used if installed, this fixes some issues with PHP HTTP wrapper using cURL
* Fixed minor bugs

= 1.2 =
* Fixed query string parameters issues
* Added GetLink() to sample.php
* UploadFile fixed
* Decreased buffer size

= 1.1 =
* Added parameter $get_new_token to GetRequestToken
* DownloadFile now accepts a callback to report progress during download
* 2 new parameters for UploadFile: $overwrite and $parent_rev
* New functions: GetLink, Delta, Copy, CreateFolder, Delete, Move
* Fixed some bugs

= 1.0 =
* Initial Version