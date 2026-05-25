<?php
/**
 * Authentication of IA Webmaster Bridge API requests.
 *
 * Every request (except /ping) must be signed with HMAC-SHA256 using the shared
 * secret. The signature covers the method, route, query, a timestamp
 * and a nonce: this ensures authenticity, integrity, and replay protection.
 *
 * Beyond the signature, since v0.19.0 each API key carries an optional
 * **scope** list. The scope required by a given route is derived from its
 * HTTP method and path prefix (see required_scope()). A key whose scope
 * list does not include the required scope is denied (HTTP 403), even
 * with a valid signature. Keys without an explicit scope list are
 * treated as legacy fully-scoped keys (no scope check), so an upgrade
 * does not break existing installs.
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
		// Phase 7.1 network pre-checks — run BEFORE any credentials
		// resolution so probing IPs / HTTP-only callers cannot
		// fingerprint the namespace.
		$https = IAWM_Network::check_https();
		if ( is_wp_error( $https ) ) {
			return $https;
		}
		$ip = IAWM_Network::check_ip();
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		if ( ! IAWM_Settings::has_credentials() ) {
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

		// Resolve the key by its identifier (multi-key support since v0.26.0).
		$creds = IAWM_Settings::get_by_key_id( $key_id );
		if ( null === $creds ) {
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

		// Scope check (since v0.19.0). Keys without an explicit scope list
		// keep the legacy behaviour: full access.
		$required = self::required_scope( $request, $require_write );
		if ( null !== $required && ! IAWM_Settings::key_has_scope( $key_id, $required ) ) {
			return new WP_Error(
				'iawm_scope_denied',
				sprintf( 'API key does not hold the required scope "%s".', $required ),
				array(
					'status'          => 403,
					'required_scope'  => $required,
				)
			);
		}

		// Kill switch: blocks write requests.
		if ( $require_write && IAWM_Settings::is_kill_switch_on() ) {
			return new WP_Error(
				'iawm_kill_switch',
				'Writes are disabled (kill switch on).',
				array( 'status' => 403 )
			);
		}

		// Update the last-used timestamp on the key (best-effort; failures
		// here must not block the request).
		IAWM_Settings::touch_last_used( $key_id );

		return true;
	}

	/**
	 * Derives the scope required to call a given route.
	 *
	 * The categorisation uses **the permission callback the route was
	 * registered with** (read vs write), not the HTTP method: every
	 * endpoint in this plugin happens to be POST regardless of intent,
	 * so the read/write distinction comes from the developer's explicit
	 * `guard_read` / `guard_write` choice at registration time.
	 *
	 *  - registered with `guard_read`  → `read`
	 *  - registered with `guard_write` → write scope based on path prefix:
	 *      - `/divi/*`      → `divi:write`
	 *      - `/config/*`    → `config:write`
	 *      - `/plugins/*`   → `infra:write`
	 *      - `/themes/*`    → `infra:write` (Phase 4)
	 *      - `/database/*`  → `infra:write` (Phase 4)
	 *      - `/backup/*`    → `infra:write` (Phase 5.2)
	 *      - any other      → `content:write`
	 *
	 * @param WP_REST_Request $request       Incoming request.
	 * @param bool            $require_write True if the route was registered with guard_write.
	 * @return string|null Required scope, or null when no scope check applies.
	 */
	public static function required_scope( $request, $require_write = false ) {
		$route = (string) $request->get_route();
		$ns    = '/' . IAWM_REST_NAMESPACE . '/';

		// Out-of-namespace requests do not flow through this guard, but be defensive.
		if ( 0 !== strpos( $route, $ns ) ) {
			return null;
		}

		// Read endpoints — the developer chose guard_read.
		if ( ! $require_write ) {
			return IAWM_Settings::SCOPE_READ;
		}

		$suffix = substr( $route, strlen( $ns ) );

		// Write endpoints — categorise by family.
		$prefix_map = array(
			'divi/'     => IAWM_Settings::SCOPE_DIVI_WRITE,
			'config/'   => IAWM_Settings::SCOPE_CONFIG_WRITE,
			'plugins/'  => IAWM_Settings::SCOPE_INFRA_WRITE,
			'themes/'   => IAWM_Settings::SCOPE_INFRA_WRITE,
			'database/' => IAWM_Settings::SCOPE_INFRA_WRITE,
			'backup/'   => IAWM_Settings::SCOPE_INFRA_WRITE,
		);

		foreach ( $prefix_map as $prefix => $scope ) {
			if ( 0 === strpos( $suffix, $prefix ) ) {
				return $scope;
			}
		}

		return IAWM_Settings::SCOPE_CONTENT_WRITE;
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
