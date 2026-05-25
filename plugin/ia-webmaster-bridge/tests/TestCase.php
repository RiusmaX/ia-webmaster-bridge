<?php
/**
 * Shared base class for IA Webmaster Bridge critical-path tests.
 *
 * Centralises the mock-reset logic so every test starts from a clean
 * in-memory store (options, transients, wpdb table). Subclasses override
 * `setUp()` only when they need extra fixturing.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

use PHPUnit\Framework\TestCase as BasePHPUnitTestCase;

/**
 * Base test case with mock reset between cases.
 */
abstract class IAWM_Test_Case extends BasePHPUnitTestCase {

	/**
	 * Reset all mock stores before each test so cases are isolated.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		WpOptionsMock::reset();
		WpTransientsMock::reset();

		global $wpdb;
		if ( $wpdb instanceof WpdbMock ) {
			$wpdb->reset();
		}

		// Clear any REMOTE_ADDR pinned by a previous network test.
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		unset( $_SERVER['HTTPS'] );
	}
}
