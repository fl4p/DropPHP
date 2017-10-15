<?php

use PHPUnit\Framework\TestCase;

class CreateFolderTest extends TestCase {

	public function testCreateFolder() {
		require_once 'bootstrap.php';
		$dp = dropboxClientAuthenticated();

		try {
			$dp->Delete( '/folder01' );
		} catch (DropboxException $e) {}

		$res = $dp->CreateFolder('/folder01');
		$this->assertEquals('folder01', $res->name);
		$this->assertEquals('/folder01', $res->path_lower);
		$this->assertEquals('folder', $res->{'.tag'});

		$list = $dp->GetFiles('/');
		$this->assertEquals($res, $list['folder01']);


		$res2 = $dp->Delete('/folder01');
		$this->assertEquals($res, $res2);
	}

}