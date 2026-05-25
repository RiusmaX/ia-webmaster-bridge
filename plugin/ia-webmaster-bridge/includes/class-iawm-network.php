<?php
/**
 * Network-layer pre-checks: HTTPS enforcement and IP allow-list.
 *
 * Phase 7.1 of production hardening. Run BEFORE the HMAC verification
 * so:
 *   - we do not waste signature work on requests that would be denied
 *     anyway (defence in depth + small DoS protection);
 *   - an attacker probing the namespace from an unauthorised IP cannot
 *     learn anything about which key ids exist or what scopes they
 *     hold;
 *   - an HTTP request on a site that requires HTTPS is rejected
 *     before any credentials material is parsed.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network pre-checks invoked by IAWM_Auth::guard().
 */
class IAWM_Network {

	/** Option storing the IP allow-list (array of strings — IPs or CIDRs). */
	const OPTION_ALLOWLIST = 'iawm_ip_allowlist';

	/**
	 * Verifies the request comes over HTTPS, when the operator has
	 * pinned the site to HTTPS-only via the wp-config.php constant
	 * `IAWM_REQUIRE_HTTPS`.
	 *
	 * The constant lives in wp-config.php (NOT an admin-toggleable
	 * option) so a compromised admin cannot disable it through the UI.
	 *
	 * @return true|WP_Error True if the constant is unset OR the call is
	 *                       HTTPS; WP_Error otherwise.
	 */
	public static function check_https() {
		if ( ! defined( 'IAWM_REQUIRE_HTTPS' ) || ! IAWM_REQUIRE_HTTPS ) {
			return true;
		}
		if ( self::is_https() ) {
			return true;
		}
		return new WP_Error(
			'iawm_https_required',
			'This site requires HTTPS for the API. Re-issue your request over https://.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Checks the caller IP against the operator-configured allow-list.
	 *
	 * Empty / unset allow-list = allow all (today's default and a
	 * compatibility-safe one). Anything else means "only the listed
	 * IPs or CIDRs may call the API". Loopback (127.0.0.1, ::1) is
	 * always allowed so the operator does not lock themselves out
	 * from a WP-CLI eval-file probe.
	 *
	 * @return true|WP_Error
	 */
	public static function check_ip() {
		$list = self::get_allowlist();
		if ( empty( $list ) ) {
			return true;
		}

		$caller = self::caller_ip();
		if ( '' === $caller ) {
			return new WP_Error(
				'iawm_ip_not_allowed',
				'Caller IP not recognised.',
				array( 'status' => 403 )
			);
		}

		// Loopback escape hatch — never lock the operator out of their own box.
		if ( '127.0.0.1' === $caller || '::1' === $caller ) {
			return true;
		}

		foreach ( $list as $entry ) {
			if ( self::ip_matches( $caller, $entry ) ) {
				return true;
			}
		}

		// Intentionally vague — do not echo the caller IP, do not reveal
		// the size of the allow-list.
		return new WP_Error(
			'iawm_ip_not_allowed',
			'Caller IP is not on the allow-list for this site.',
			array( 'status' => 403 )
		);
	}

	/**
	 * Returns the configured allow-list, sanitised.
	 *
	 * @return array<int, string>
	 */
	public static function get_allowlist() {
		$raw = get_option( self::OPTION_ALLOWLIST, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $entry ) {
			$entry = is_string( $entry ) ? trim( $entry ) : '';
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Sets the allow-list. Caller is responsible for validating entries —
	 * see `validate_allowlist()` for the helper used by the admin UI.
	 *
	 * @param array $list List of IPs or CIDRs.
	 * @return void
	 */
	public static function set_allowlist( $list ) {
		$clean = array();
		foreach ( (array) $list as $entry ) {
			$entry = is_string( $entry ) ? trim( $entry ) : '';
			if ( '' !== $entry ) {
				$clean[] = $entry;
			}
		}
		update_option( self::OPTION_ALLOWLIST, $clean, true );
	}

	/**
	 * Parses + validates a list of allow-list entries. Returns an
	 * associative array with `valid` (kept entries) and `invalid`
	 * (rejected entries with a reason).
	 *
	 * @param array $list Candidate list.
	 * @return array { valid: string[], invalid: array<string, string> }
	 */
	public static function validate_allowlist( $list ) {
		$valid   = array();
		$invalid = array();
		foreach ( (array) $list as $entry ) {
			$entry = is_string( $entry ) ? trim( $entry ) : '';
			if ( '' === $entry || 0 === strpos( $entry, '#' ) ) {
				continue;
			}
			if ( false !== strpos( $entry, '/' ) ) {
				if ( self::is_valid_cidr( $entry ) ) {
					$valid[] = $entry;
				} else {
					$invalid[ $entry ] = 'Invalid CIDR notation.';
				}
				continue;
			}
			if ( filter_var( $entry, FILTER_VALIDATE_IP ) ) {
				$valid[] = $entry;
			} else {
				$invalid[ $entry ] = 'Not a valid IPv4 or IPv6 address.';
			}
		}
		return array( 'valid' => $valid, 'invalid' => $invalid );
	}

	/* ----------------------------------------------------------------- */
	/* Internals                                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Best-effort detection of the caller IP. Trusts `REMOTE_ADDR` by
	 * default; if a reverse-proxy header is configured and the constant
	 * `IAWM_TRUST_PROXY_HEADER` is set in wp-config.php, falls back to
	 * the leftmost value of `X-Forwarded-For`.
	 *
	 * @return string IP, or '' if unknown.
	 */
	public static function caller_ip() {
		// Honor proxy header only when the operator opted in via wp-config.
		if ( defined( 'IAWM_TRUST_PROXY_HEADER' ) && IAWM_TRUST_PROXY_HEADER ) {
			$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
			if ( '' !== $xff ) {
				$parts = array_map( 'trim', explode( ',', $xff ) );
				$first = $parts[0];
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					return $first;
				}
			}
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Is the current request HTTPS? Mirrors `is_ssl()` from WordPress
	 * but is tolerant to a reverse proxy when the operator opted in.
	 *
	 * @return bool
	 */
	public static function is_https() {
		if ( function_exists( 'is_ssl' ) && is_ssl() ) {
			return true;
		}
		if ( defined( 'IAWM_TRUST_PROXY_HEADER' ) && IAWM_TRUST_PROXY_HEADER ) {
			$proto = isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) : '';
			if ( 'https' === $proto ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tests whether an IP matches a single allow-list entry.
	 *
	 * @param string $ip    Caller IP (already validated).
	 * @param string $entry IP literal or CIDR.
	 * @return bool
	 */
	private static function ip_matches( $ip, $entry ) {
		if ( false === strpos( $entry, '/' ) ) {
			return hash_equals( $entry, $ip );
		}
		return self::ip_in_cidr( $ip, $entry );
	}

	/**
	 * IP-in-CIDR check supporting both IPv4 and IPv6.
	 *
	 * @param string $ip   Candidate IP.
	 * @param string $cidr CIDR notation (e.g. 10.0.0.0/8 or 2001:db8::/32).
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $mask ) = explode( '/', $cidr, 2 );
		$mask = (int) $mask;
		if ( $mask < 0 ) {
			return false;
		}
		$ip_bin     = @inet_pton( $ip );      // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$subnet_bin = @inet_pton( $subnet );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}
		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			// IPv4 vs IPv6 mismatch.
			return false;
		}
		$bytes = (int) ceil( $mask / 8 );
		if ( $bytes > strlen( $ip_bin ) ) {
			return false;
		}
		if ( 0 !== strncmp( $ip_bin, $subnet_bin, $bytes ) ) {
			return false;
		}
		$remainder = $mask % 8;
		if ( 0 === $remainder || $bytes >= strlen( $ip_bin ) ) {
			return true;
		}
		$last_mask = chr( 0xFF & ( 0xFF00 >> $remainder ) );
		return ( $ip_bin[ $bytes ] & $last_mask ) === ( $subnet_bin[ $bytes ] & $last_mask );
	}

	/**
	 * Validates that a string is a well-formed CIDR. Lightweight — does
	 * not check whether the prefix length is sensible for the family.
	 *
	 * @param string $cidr Candidate.
	 * @return bool
	 */
	private static function is_valid_cidr( $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $mask ) = explode( '/', $cidr, 2 );
		if ( ! ctype_digit( $mask ) ) {
			return false;
		}
		$mask = (int) $mask;
		if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $mask >= 0 && $mask <= 32;
		}
		if ( filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $mask >= 0 && $mask <= 128;
		}
		return false;
	}
}
