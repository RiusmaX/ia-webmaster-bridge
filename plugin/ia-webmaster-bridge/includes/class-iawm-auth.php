<?php
/**
 * Authentication of IA Webmaster Bridge API requests.
 *
 * Every request (except /ping) must be signed with HMAC-SHA256 using the shared
 * secret. The signature covers the method, route, query, a timestamp
 * and a nonce: this ensures authenticity, integrity, and replay protection.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signature verification and REST route guards.
 */
class IAWM_Auth {

	/** Tolerance, in seconds, on the request timestamp (anti-replay). */
	const TIMESTAMP_TOLERANCE = 300;

	/** Signature scheme prefix (domain separator). */
	const SIGNATURE_SCHEME = 'IAWM-HMAC-SHA256';

	/**
	 * Permission callback for read routes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function guard_read( $request ) {
		return self::guard( $request, false );
	}

	/**
	 * Permission callback for write routes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function guard_write( $request ) {
		return self::guard( $request, true );
	}

	/**
	 * Verifies the authentication of a request.
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param bool            $require_write True if the request modifies the site.
	 * @return true|WP_Error
	 */
	public static function guard( $request, $require_write ) {
		$creds = IAWM_Settings::get_credentials();

		if ( null === $creds ) {
			return new WP_Error(
				'iawm_not_configured',
				'The adapter is not configured: no API credentials.',
				array( 'status' => 503 )
			);
		}

		$key_id    = (string) $request->get_header( 'X-IAWM-Key' );
		$timestamp = (string) $request->get_header( 'X-IAWM-Timestamp' );
		$nonce     = (string) $request->get_header( 'X-IAWM-Nonce' );
		$signature = (string) $request->get_header( 'X-IAWM-Signature' );

		if ( '' === $key_id || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return self::deny( 'Missing authentication headers.' );
		}

		// Key identifier (constant-time comparison).
		if ( ! hash_equals( (string) $creds['key_id'], $key_id ) ) {
			return self::deny( 'Unknown key identifier.' );
		}

		// Timestamp: the request must fall within the tolerance window.
		if ( ! ctype_digit( $timestamp ) ) {
			return self::deny( 'Invalid timestamp.' );
		}
		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			return self::deny( 'Request expired or timestamp out of tolerance.' );
		}

		// Nonce: single-use, to prevent replay of a signed request.
		$nonce_key = 'iawm_nonce_' . hash( 'sha256', $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return self::deny( 'Nonce already used (replay detected).' );
		}

		// HMAC signature.
		$message  = self::build_message( $request, $timestamp, $nonce );
		$expected = hash_hmac( 'sha256', $message, (string) $creds['secret'] );

		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return self::deny( 'Invalid signature.' );
		}

		// Valid signature: consume the nonce (lifetime > tolerance).
		set_transient( $nonce_key, time(), self::TIMESTAMP_TOLERANCE * 2 );

		// Kill switch: blocks write requests.
		if ( $require_write && IAWM_Settings::is_kill_switch_on() ) {
			return new WP_Error(
				'iawm_kill_switch',
				'Writes are disabled (kill switch on).',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Builds the canonical signed message.
	 *
	 * Format — seven elements separated by "\n" newlines:
	 *   1. IAWM-HMAC-SHA256        (scheme prefix)
	 *   2. HTTP method             (uppercase)
	 *   3. REST route              (e.g. /ia-webmaster/v1/status)
	 *   4. canonical query         (sorted parameters, or empty string)
	 *   5. Unix timestamp
	 *   6. nonce
	 *   7. hexadecimal SHA-256 of the raw body (hash of "" if body is empty)
	 *
	 * The MCP bridge must reproduce this message identically to sign.
	 *
	 * @param WP_REST_Request $request   Incoming request.
	 * @param string          $timestamp Transmitted timestamp.
	 * @param string          $nonce     Transmitted nonce.
	 * @return string
	 */
	public static function build_message( $request, $timestamp, $nonce ) {
		$method = strtoupper( $request->get_method() );
		$route  = $request->get_route();
		$query  = self::canonical_query( $request->get_query_params() );
		$body   = (string) $request->get_body();

		return implode(
			"\n",
			array(
				self::SIGNATURE_SCHEME,
				$method,
				$route,
				$query,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Serialises query parameters deterministically (sorted by key).
	 *
	 * @param array $params Query parameters.
	 * @return string
	 */
	private static function canonical_query( $params ) {
		if ( empty( $params ) || ! is_array( $params ) ) {
			return '';
		}

		ksort( $params );

		$pairs = array();
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}

		return implode( '&', $pairs );
	}

	/**
	 * Builds an authentication error (HTTP 401).
	 *
	 * @param string $message Human-readable message.
	 * @return WP_Error
	 */
	private static function deny( $message ) {
		return new WP_Error( 'iawm_unauthorized', $message, array( 'status' => 401 ) );
	}
}
