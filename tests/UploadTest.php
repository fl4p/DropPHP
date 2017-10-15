<?php

use PHPUnit\Framework\TestCase;

class UploadTest extends TestCase {

	public function testUpload() {
		require_once 'bootstrap.php';
		$dp = dropboxClientAuthenticated();

		$res = file_put_contents('small', str_repeat(md5(rand()), DropboxClient::UPLOAD_CHUNK_SIZE/32/100));
		$this->assertNotFalse($res);

		$res = $dp->UploadFile('small' );

		$this->assertEquals('small', $res->name);
		$this->assertEquals('/small', $res->path_lower);
		$this->assertEquals('/small', $res->path_display);
		$this->assertEquals(filesize('small'), $res->size);
		$this->assertEquals(DropboxClient::contentHashFile('small'), $res->content_hash);

		unlink('small');
	}


	public function testChunkedUpload() {
		require_once 'bootstrap.php';
		$dp = dropboxClientAuthenticated();

		$res = file_put_contents('big', str_repeat(md5(rand()), DropboxClient::UPLOAD_CHUNK_SIZE/32*2));
		$this->assertNotFalse($res);

		$res = $dp->UploadFile('big' );

		$this->assertEquals('big', $res->name);
		$this->assertEquals('/big', $res->path_lower);
		$this->assertEquals('/big', $res->path_display);
		$this->assertEquals(filesize('big'), $res->size);
		$this->assertEquals(DropboxClient::contentHashFile('big'), $res->content_hash);

		unlink('big');
	}
}