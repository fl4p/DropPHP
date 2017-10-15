<?php

use PHPUnit\Framework\TestCase;

class AccountInfoTest extends TestCase {

	public function testAccountInfo() {
		require_once 'bootstrap.php';
		$dp = dropboxClientAuthenticated();

		$accountInfo = $dp->GetAccountInfo();

		$this->assertNotEmpty($accountInfo->account_id);
		$this->assertNotEmpty($accountInfo->name->display_name);
		$this->assertNotEmpty($accountInfo->email);
	}
}