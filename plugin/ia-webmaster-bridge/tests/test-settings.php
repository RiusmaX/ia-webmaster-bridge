<?php
/**
 * Critical-path tests for IAWM_Settings — multi-key storage and scope
 * helpers.
 *
 * Three angles:
 *   1. Legacy single-record shape is migrated on read into a synthetic
 *      multi-key map (no operator action required).
 *   2. Creating a new key generates a fresh id + 32-byte secret and
 *      persists the record.
 *   3. Scope checks honour both the explicit-scope path and the legacy
 *      "no scope list = full access" fallback.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

/**
 * @covers IAWM_Settings
 */
final class IAWM_Test_Settings extends IAWM_Test_Case {

	/**
	 * Pre-v0.26.0 installs stored a single flat record. On read, the
	 * settings module must transparently surface it as a one-element
	 * multi-key map with the synthetic label "Legacy key".
	 *
	 * @return void
	 */
	public function testLegacyKeyMigration() {
		// Plant the legacy shape directly into the options store.
		update_option(
			IAWM_Settings::OPTION_CREDENTIALS,
			array(
				'key_id'     => 'iawm_legacy',
				'secret'     => 'deadbeef',
				'created_at' => '2025-01-01T00:00:00+00:00',
			)
		);

		$all = IAWM_Settings::all_credentials();
		$this->assertCount( 1, $all, 'Legacy shape must surface as a single record' );
		$this->assertArrayHasKey( 'iawm_legacy', $all );
		$this->assertSame( 'deadbeef', $all['iawm_legacy']['secret'] );
		$this->assertSame( 'Legacy key', $all['iawm_legacy']['label'] );
		$this->assertNull( $all['iawm_legacy']['scopes'], 'Legacy keys have no scope list (full access)' );

		// `get_by_key_id` must work end-to-end for the legacy id.
		$entry = IAWM_Settings::get_by_key_id( 'iawm_legacy' );
		$this->assertIsArray( $entry );
		$this->assertSame( 'iawm_legacy', $entry['key_id'] );
	}

	/**
	 * `create_credentials` must mint a fresh key id and secret, persist
	 * the record, and return the cleartext secret exactly once.
	 *
	 * @return void
	 */
	public function testCreateKeyAddsRecord() {
		$record = IAWM_Settings::create_credentials(
			array( IAWM_Settings::SCOPE_READ ),
			'Alice',
			42
		);

		$this->assertIsArray( $record );
		$this->assertArrayHasKey( 'key_id', $record );
		$this->assertArrayHasKey( 'secret', $record );
		$this->assertNotEmpty( $record['key_id'] );
		$this->assertSame( 0, strpos( $record['key_id'], 'iawm_' ), 'Key id must be prefixed iawm_' );
		$this->assertSame( 64, strlen( $record['secret'] ), 'Secret must be 32 bytes hex-encoded' );
		$this->assertSame( 'Alice', $record['label'] );
		$this->assertSame( 42, $record['linked_user_id'] );

		// And the record must be readable back from storage.
		$readback = IAWM_Settings::get_by_key_id( $record['key_id'] );
		$this->assertIsArray( $readback );
		$this->assertSame( $record['secret'], $readback['secret'] );
	}

	/**
	 * A key with an explicit scope list must grant exactly the listed
	 * scopes and deny anything else.
	 *
	 * @return void
	 */
	public function testKeyHasScopeWithExplicitList() {
		$record = IAWM_Settings::create_credentials(
			array( IAWM_Settings::SCOPE_READ ),
			'Reader'
		);
		$key_id = $record['key_id'];

		$this->assertTrue(
			IAWM_Settings::key_has_scope( $key_id, IAWM_Settings::SCOPE_READ ),
			'Explicit "read" scope must grant read'
		);
		$this->assertFalse(
			IAWM_Settings::key_has_scope( $key_id, IAWM_Settings::SCOPE_CONTENT_WRITE ),
			'Explicit "read"-only key must NOT grant content:write'
		);
	}

	/**
	 * A legacy key (no scope list at all) must grant every scope —
	 * that's the backward-compat contract documented in IAWM_Settings.
	 *
	 * @return void
	 */
	public function testKeyHasScopeWithLegacy() {
		// `create_credentials(null, ...)` means "no scope list, full access".
		$record = IAWM_Settings::create_credentials( null, 'Legacy' );
		$key_id = $record['key_id'];

		$this->assertTrue( IAWM_Settings::key_has_scope( $key_id, IAWM_Settings::SCOPE_READ ) );
		$this->assertTrue( IAWM_Settings::key_has_scope( $key_id, IAWM_Settings::SCOPE_CONTENT_WRITE ) );
		$this->assertTrue( IAWM_Settings::key_has_scope( $key_id, IAWM_Settings::SCOPE_INFRA_WRITE ) );
		$this->assertTrue( IAWM_Settings::key_has_scope( $key_id, 'something_not_in_catalog' ) );
	}
}
