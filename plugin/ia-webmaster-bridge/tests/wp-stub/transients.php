<?php
/**
 * In-memory mock for WordPress transients.
 *
 * Backs `get_transient`, `set_transient`, `delete_transient`. Tracks the
 * expiration so tests can simulate TTL expiry by advancing a virtual
 * clock via `WpTransientsMock::advance_time()`.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

class WpTransientsMock {

	/**
	 * Records keyed by transient name. Each record holds:
	 *   - value      : the stored value.
	 *   - expires_at : UNIX timestamp at which the transient is considered
	 *                  expired. 0 means "no expiry".
	 *
	 * @var array<string, array{value: mixed, expires_at: int}>
	 */
	private static $store = array();

	/**
	 * Virtual clock offset applied on top of `time()`. Tests can move
	 * time forward to age transients out.
	 *
	 * @var int
	 */
	private static $clock_offset = 0;

	/**
	 * Clears the store and resets the clock. Called from test `setUp()`.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$store        = array();
		self::$clock_offset = 0;
	}

	/**
	 * Returns the virtual "now" used to evaluate transient TTLs.
	 *
	 * @return int
	 */
	public static function now() {
		return time() + self::$clock_offset;
	}

	/**
	 * Advances the virtual clock by N seconds. Lets a test simulate
	 * waiting past the TTL.
	 *
	 * @param int $seconds Positive integer.
	 * @return void
	 */
	public static function advance_time( $seconds ) {
		self::$clock_offset += (int) $seconds;
	}

	/**
	 * Stores a transient.
	 *
	 * @param string $name       Transient name.
	 * @param mixed  $value      Value.
	 * @param int    $expiration Lifetime in seconds (0 = no expiry).
	 * @return bool
	 */
	public static function set( $name, $value, $expiration = 0 ) {
		$expiration = (int) $expiration;
		self::$store[ $name ] = array(
			'value'      => $value,
			'expires_at' => $expiration > 0 ? self::now() + $expiration : 0,
		);
		return true;
	}

	/**
	 * Fetches a transient. Returns false when missing or expired (and
	 * removes the expired record, matching WordPress's behaviour).
	 *
	 * @param string $name Transient name.
	 * @return mixed|false
	 */
	public static function get( $name ) {
		if ( ! array_key_exists( $name, self::$store ) ) {
			return false;
		}
		$record = self::$store[ $name ];
		if ( 0 !== $record['expires_at'] && self::now() >= $record['expires_at'] ) {
			unset( self::$store[ $name ] );
			return false;
		}
		return $record['value'];
	}

	/**
	 * Deletes a transient.
	 *
	 * @param string $name Transient name.
	 * @return bool
	 */
	public static function delete( $name ) {
		if ( ! array_key_exists( $name, self::$store ) ) {
			return false;
		}
		unset( self::$store[ $name ] );
		return true;
	}
}
