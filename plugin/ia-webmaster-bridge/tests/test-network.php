<?php
/**
 * Critical-path tests for IAWM_Network — IP allow-list semantics.
 *
 * `ip_in_cidr` and `ip_matches` are intentionally private to the module
 * (the allow-list is one-way: callers ask "may this caller talk to us?"
 * via the public `check_ip` API). To keep tests aligned with that
 * contract, we exercise CIDR/literal matching through `check_ip`,
 * setting the caller IP via `$_SERVER['REMOTE_ADDR']` exactly as a real
 * REST request would.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

/**
 * @covers IAWM_Network
 */
final class IAWM_Test_Network extends IAWM_Test_Case {

	/**
	 * `check_ip` must accept an address inside an IPv4 CIDR.
	 *
	 * @return void
	 */
	public function testCidrIpv4Match() {
		update_option( IAWM_Network::OPTION_ALLOWLIST, array( '10.0.0.0/8' ) );

		$_SERVER['REMOTE_ADDR'] = '10.42.7.1';
		$this->assertTrue( IAWM_Network::check_ip(), 'IP inside 10.0.0.0/8 must be allowed' );

		$_SERVER['REMOTE_ADDR'] = '11.0.0.1';
		$result = IAWM_Network::check_ip();
		$this->assertInstanceOf( WP_Error::class, $result, 'IP outside 10.0.0.0/8 must be denied' );
		$this->assertSame( 'iawm_ip_not_allowed', $result->get_error_code() );
	}

	/**
	 * `check_ip` must accept an address inside an IPv6 CIDR.
	 *
	 * @return void
	 */
	public function testCidrIpv6Match() {
		update_option( IAWM_Network::OPTION_ALLOWLIST, array( '2001:db8::/32' ) );

		$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd::1';
		$this->assertTrue( IAWM_Network::check_ip(), 'IPv6 inside 2001:db8::/32 must be allowed' );

		$_SERVER['REMOTE_ADDR'] = '2001:db9::1';
		$result = IAWM_Network::check_ip();
		$this->assertInstanceOf( WP_Error::class, $result, 'IPv6 outside 2001:db8::/32 must be denied' );
	}

	/**
	 * A literal IP entry (no slash) must match exactly.
	 *
	 * @return void
	 */
	public function testSingleIpMatch() {
		update_option( IAWM_Network::OPTION_ALLOWLIST, array( '203.0.113.7' ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->assertTrue( IAWM_Network::check_ip() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$this->assertInstanceOf( WP_Error::class, IAWM_Network::check_ip() );
	}

	/**
	 * Empty / unset allow-list must allow everyone (compatibility-safe
	 * default).
	 *
	 * @return void
	 */
	public function testEmptyAllowlistAllowsAll() {
		// No allow-list configured at all.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->assertTrue( IAWM_Network::check_ip(), 'Unset allow-list must allow any caller' );

		// Empty array — same outcome.
		update_option( IAWM_Network::OPTION_ALLOWLIST, array() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.50';
		$this->assertTrue( IAWM_Network::check_ip(), 'Empty allow-list must allow any caller' );
	}
}
