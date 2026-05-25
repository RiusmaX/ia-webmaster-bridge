<?php
/**
 * In-memory mock for WordPress options.
 *
 * Backs the `get_option`, `update_option`, and `delete_option` polyfills
 * declared in `wp-functions.php` with a static associative array. Reset
 * between tests via `WpOptionsMock::reset()` in the test case `setUp()`.
 *
 * Design choice: a single static store rather than an injected instance
 * keeps the polyfill functions free of state plumbing — the plugin code
 * under test calls `get_option(...)` exactly as it does in production.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

class WpOptionsMock {

	/**
	 * In-memory store keyed by option name.
	 *
	 * @var array<string, mixed>
	 */
	private static $store = array();

	/**
	 * Clears the store. Called from test `setUp()`.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$store = array();
	}

	/**
	 * Returns the option value, or the default if the option is absent.
	 *
	 * @param string $name    Option name.
	 * @param mixed  $default Default to return when the option is absent.
	 * @return mixed
	 */
	public static function get( $name, $default = false ) {
		return array_key_exists( $name, self::$store ) ? self::$store[ $name ] : $default;
	}

	/**
	 * Sets (creates or updates) an option.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool Always true (mirrors the WordPress contract on success).
	 */
	public static function set( $name, $value ) {
		self::$store[ $name ] = $value;
		return true;
	}

	/**
	 * Deletes an option.
	 *
	 * @param string $name Option name.
	 * @return bool True if it existed, false otherwise.
	 */
	public static function delete( $name ) {
		if ( ! array_key_exists( $name, self::$store ) ) {
			return false;
		}
		unset( self::$store[ $name ] );
		return true;
	}

	/**
	 * Returns true if the option exists.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function has( $name ) {
		return array_key_exists( $name, self::$store );
	}

	/**
	 * Returns the full in-memory store (debug helper).
	 *
	 * @return array<string, mixed>
	 */
	public static function dump() {
		return self::$store;
	}
}
