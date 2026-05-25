<?php
/**
 * Confirmation tokens — two-step pattern for the most destructive
 * operations (spec 02, Phase 5.3).
 *
 * For an endpoint listed in REQUIRES_CONFIRMATION, the first call must
 * be made WITHOUT a `confirmation_token`: the API answers
 * `requires_confirmation: true` with a fresh token AND a summary of
 * what would happen. The caller can then re-issue the **same call**
 * with the token in the body; only then is the work actually applied.
 *
 * Properties:
 *
 *  - Tokens are 64-character hex (~256 bits), random, single-use.
 *  - TTL: 5 minutes.
 *  - Bound to (route, key_id, sha256 of the canonical body) — a token
 *    issued for one call cannot be replayed against a different one.
 *  - Stored in WordPress transients keyed by `sha256(token)` so the
 *    raw token never appears in the database.
 *  - dry_run requests are exempt: a preview never needs confirmation.
 *
 * The intent is **explicit, parameterised consent** for irreversible
 * actions. Combined with the kill switch, audit log, scope check and
 * automatic pre-op backups, this is the last gate before an operation
 * touches state that cannot easily be undone.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues, validates and consumes confirmation tokens.
 */
class IAWM_Confirmation {

	/** Lifetime of a confirmation token, in seconds. */
	const TTL = 300;

	/** Transient key prefix for tokens. */
	const TRANSIENT_PREFIX = 'iawm_confirm_';

	/**
	 * Routes (suffix after the namespace, with leading slash) whose
	 * non-dry_run write requires confirmation.
	 *
	 * Listed by intent rather than by their full namespaced path so the
	 * list remains readable. The guard normalises before comparing.
	 *
	 * @var string[]
	 */
	const REQUIRES_CONFIRMATION = array(
		'/backup/restore',
		'/content/revisions/restore',
		'/core/update',
		'/database/search-replace',
		'/diagnostics/404/clear',
	);

	/**
	 * Returns true when a route requires confirmation.
	 *
	 * @param string $route Route, with or without the namespace prefix.
	 * @return bool
	 */
	public static function requires( $route ) {
		$suffix = self::normalize_route( $route );
		return in_array( $suffix, self::REQUIRES_CONFIRMATION, true );
	}

	/**
	 * Issues a new token for the (route, key, params) tuple.
	 *
	 * @param string $route   REST route.
	 * @param string $key_id  Caller's key id.
	 * @param array  $params  JSON body of the call (`confirmation_token`
	 *                         field is ignored when hashing so the same
	 *                         body — minus the token — produces the same
	 *                         hash before and after).
	 * @return string Fresh hex token.
	 */
	public static function issue( $route, $key_id, $params ) {
		$token = bin2hex( random_bytes( 32 ) );
		$key   = self::transient_key( $token );

		set_transient(
			$key,
			array(
				'route'         => self::normalize_route( $route ),
				'key_id'        => (string) $key_id,
				'params_hash'   => self::params_hash( $params ),
				'issued_at'     => time(),
			),
			self::TTL
		);

		return $token;
	}

	/**
	 * Validates and consumes a token. Returns true on success; the
	 * token is deleted from storage in any case to prevent retry races.
	 *
	 * @param string $token  Raw token from the request body.
	 * @param string $route  REST route being invoked.
	 * @param string $key_id Caller's key id.
	 * @param array  $params JSON body of the call.
	 * @return bool
	 */
	public static function consume( $token, $route, $key_id, $params ) {
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) !== 64 ) {
			return false;
		}
		$key   = self::transient_key( $token );
		$entry = get_transient( $key );
		// Single-use: clear immediately, even if invalid (anti-bruteforce).
		delete_transient( $key );

		if ( ! is_array( $entry ) ) {
			return false;
		}
		if ( self::normalize_route( $route ) !== (string) $entry['route'] ) {
			return false;
		}
		if ( ! hash_equals( (string) $entry['key_id'], (string) $key_id ) ) {
			return false;
		}
		if ( ! hash_equals( (string) $entry['params_hash'], self::params_hash( $params ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Guard helper to call at the top of a destructive endpoint.
	 *
	 * Returns null when the call can proceed (either no confirmation is
	 * required, OR the supplied token is valid). Otherwise returns the
	 * WP_REST_Response or WP_Error to return verbatim to the caller.
	 *
	 * Convention: if the route requires confirmation and the body's
	 * `dry_run` flag is truthy, the guard short-circuits to "no
	 * confirmation needed" — a preview never mutates state.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param array           $params  Decoded JSON body.
	 * @param array           $summary Optional summary fields to surface
	 *                                 in the "requires_confirmation"
	 *                                 response so the caller knows what
	 *                                 they are about to confirm.
	 * @return WP_REST_Response|null
	 */
	public static function guard( $request, $params, $summary = array() ) {
		$route = (string) $request->get_route();

		if ( ! self::requires( $route ) ) {
			return null;
		}

		// dry_run is a preview; no confirmation needed.
		if ( ! empty( $params['dry_run'] ) ) {
			return null;
		}

		$token = isset( $params['confirmation_token'] ) ? (string) $params['confirmation_token'] : '';
		$key_id = (string) $request->get_header( 'X-IAWM-Key' );

		// Second step: token presented.
		if ( '' !== $token ) {
			if ( self::consume( $token, $route, $key_id, $params ) ) {
				return null;
			}
			return new WP_REST_Response(
				array(
					'ok'     => false,
					'code'   => 'iawm_invalid_confirmation',
					'message' => __( 'Confirmation token missing, expired, mismatched or already used. Re-issue the call without a token to obtain a fresh one.', 'ia-webmaster-bridge' ),
				),
				400
			);
		}

		// First step: issue and return.
		$new_token = self::issue( $route, $key_id, $params );

		return new WP_REST_Response(
			array(
				'ok'                  => false,
				'requires_confirmation' => true,
				'confirmation_token'  => $new_token,
				'expires_in_seconds'  => self::TTL,
				'summary'             => is_array( $summary ) ? $summary : array(),
				'next_step'           => __( 'Re-issue the exact same call with `confirmation_token` set to this value to actually apply the action.', 'ia-webmaster-bridge' ),
			),
			202 // 202 Accepted — work understood, waiting for confirmation.
		);
	}

	/**
	 * Returns the canonical transient key for a token.
	 *
	 * @param string $token Raw hex token.
	 * @return string
	 */
	private static function transient_key( $token ) {
		return self::TRANSIENT_PREFIX . hash( 'sha256', (string) $token );
	}

	/**
	 * Strips the WP REST namespace from a route, leaving the
	 * plugin-relative suffix.
	 *
	 * @param string $route Route as returned by `WP_REST_Request::get_route()`.
	 * @return string
	 */
	private static function normalize_route( $route ) {
		$prefix = '/' . IAWM_REST_NAMESPACE;
		if ( 0 === strpos( (string) $route, $prefix ) ) {
			return substr( (string) $route, strlen( $prefix ) );
		}
		return (string) $route;
	}

	/**
	 * Hashes the body of the call deterministically, ignoring the
	 * `confirmation_token` field itself (so the same body produces the
	 * same hash whether or not the token has been added).
	 *
	 * @param array $params JSON body of the call.
	 * @return string
	 */
	private static function params_hash( $params ) {
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		unset( $params['confirmation_token'] );
		ksort( $params );
		$canonical = wp_json_encode( $params );
		return hash( 'sha256', (string) $canonical );
	}
}
