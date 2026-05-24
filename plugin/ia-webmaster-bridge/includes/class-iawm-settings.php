<?php
/**
 * Storage for the adapter's settings: API credentials and kill switch.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralised access to the plugin's options.
 *
 * The API secret is a symmetric shared secret (HMAC): it is therefore stored
 * in clear on the WordPress side, as it will be on the MCP bridge side. Protection
 * relies on restricted access to the database and the admin page.
 */
class IAWM_Settings {

	/** Option storing the API credentials (key_id + secret). */
	const OPTION_CREDENTIALS = 'iawm_credentials';

	/** Option storing the kill switch state. */
	const OPTION_KILL_SWITCH = 'iawm_kill_switch';

	/**
	 * Returns the API credentials, or null if none are configured.
	 *
	 * @return array|null Array { key_id, secret, created_at } or null.
	 */
	public static function get_credentials() {
		$creds = get_option( self::OPTION_CREDENTIALS );

		if ( ! is_array( $creds ) || empty( $creds['key_id'] ) || empty( $creds['secret'] ) ) {
			return null;
		}

		return $creds;
	}

	/**
	 * Indicates whether API credentials are configured.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return null !== self::get_credentials();
	}

	/**
	 * Generates a new identifier / secret pair and saves it.
	 *
	 * Any previous secret is permanently replaced: the MCP bridge must
	 * then be reconfigured with the new secret.
	 *
	 * @return array The new credentials.
	 */
	public static function generate_credentials() {
		$creds = array(
			'key_id'     => 'iawm_' . bin2hex( random_bytes( 6 ) ),
			'secret'     => bin2hex( random_bytes( 32 ) ),
			'created_at' => gmdate( 'c' ),
		);

		// autoload=false: the secret is only read on API requests.
		update_option( self::OPTION_CREDENTIALS, $creds, false );

		return $creds;
	}

	/**
	 * Deletes the API credentials: the agent can no longer authenticate.
	 *
	 * @return void
	 */
	public static function revoke_credentials() {
		delete_option( self::OPTION_CREDENTIALS );
	}

	/**
	 * Indicates whether the kill switch is on (writes blocked).
	 *
	 * @return bool
	 */
	public static function is_kill_switch_on() {
		return (bool) get_option( self::OPTION_KILL_SWITCH, false );
	}

	/**
	 * Enables or disables the kill switch.
	 *
	 * @param bool $on Desired state.
	 * @return void
	 */
	public static function set_kill_switch( $on ) {
		update_option( self::OPTION_KILL_SWITCH, (bool) $on, true );
	}
}
