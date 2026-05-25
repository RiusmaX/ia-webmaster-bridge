<?php
/**
 * Critical-path tests for IAWM_Confirmation — the two-step token gate
 * protecting destructive operations.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

/**
 * @covers IAWM_Confirmation
 */
final class IAWM_Test_Confirmation extends IAWM_Test_Case {

	/**
	 * Token issued for a (route, key, body) tuple must consume once and
	 * exactly once. A second consume attempt with the same token returns
	 * false because the transient was already deleted.
	 *
	 * @return void
	 */
	public function testIssueAndConsume() {
		$route  = '/backup/restore';
		$key_id = 'iawm_aaaa';
		$body   = array( 'id' => 12, 'dry_run' => false );

		$token = IAWM_Confirmation::issue( $route, $key_id, $body );
		$this->assertIsString( $token );
		$this->assertSame( 64, strlen( $token ), 'Token should be 64 hex chars (~256 bits)' );

		$this->assertTrue(
			IAWM_Confirmation::consume( $token, $route, $key_id, $body ),
			'First consume of a fresh token must succeed'
		);
		$this->assertFalse(
			IAWM_Confirmation::consume( $token, $route, $key_id, $body ),
			'Replaying the same token must be refused'
		);
	}

	/**
	 * The body hash is part of the binding: a token issued for body
	 * `{a: 1}` cannot be consumed against `{a: 2}`. This is what
	 * prevents an attacker from siphoning a token captured on the wire
	 * and replaying it against a tweaked call.
	 *
	 * @return void
	 */
	public function testBodyMismatchRefuses() {
		$route  = '/backup/restore';
		$key_id = 'iawm_aaaa';

		$token = IAWM_Confirmation::issue( $route, $key_id, array( 'a' => 1 ) );

		$this->assertFalse(
			IAWM_Confirmation::consume( $token, $route, $key_id, array( 'a' => 2 ) ),
			'Body mismatch must be refused'
		);
	}

	/**
	 * Tokens are bound to the TTL (300s). Once the transient has
	 * expired, `consume()` must return false. We simulate the wait by
	 * advancing the virtual clock past the TTL.
	 *
	 * @return void
	 */
	public function testTokenTTLExpires() {
		$route  = '/backup/restore';
		$key_id = 'iawm_aaaa';
		$body   = array( 'id' => 99 );

		$token = IAWM_Confirmation::issue( $route, $key_id, $body );
		$this->assertNotEmpty( $token );

		// Move past the TTL.
		WpTransientsMock::advance_time( IAWM_Confirmation::TTL + 1 );

		$this->assertFalse(
			IAWM_Confirmation::consume( $token, $route, $key_id, $body ),
			'Expired tokens must not be accepted'
		);
	}

	/**
	 * A `dry_run` request never needs confirmation: `guard()` returns
	 * null (the call may proceed) without ever issuing or consuming a
	 * token. This is what lets the operator preview the diff of a
	 * restore without triggering the two-step dance.
	 *
	 * @return void
	 */
	public function testDryRunBypassesGate() {
		$request = new WP_REST_Request( 'POST', '/ia-webmaster/v1/backup/restore' );
		$request->set_header( 'X-IAWM-Key', 'iawm_aaaa' );
		$params = array( 'id' => 7, 'dry_run' => true );

		$result = IAWM_Confirmation::guard( $request, $params );

		$this->assertNull( $result, 'dry_run must bypass the confirmation gate' );
	}
}
