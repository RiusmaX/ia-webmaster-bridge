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
 *
 * Scopes (since v0.19.0) gate which families of routes a key may call:
 *   - `read`           — every GET on the namespace (diagnostics included).
 *   - `content:write`  — pages, posts, media, taxonomies, menus, SEO.
 *   - `divi:write`     — Divi pages and Theme Builder.
 *   - `config:write`   — site settings and user management.
 *   - `infra:write`    — plugin install/activate/deactivate; later
 *                        themes, database, backups (Phase 4).
 *
 * For backward compatibility, a credentials record without a `scopes`
 * field is treated as fully-scoped — existing installs are not broken on
 * upgrade. New keys default to all scopes but the admin UI lets the
 * operator restrict them.
 */
class IAWM_Settings {

	/** Option storing the API credentials (key_id + secret + scopes). */
	const OPTION_CREDENTIALS = 'iawm_credentials';

	/** Option storing the kill switch state. */
	const OPTION_KILL_SWITCH = 'iawm_kill_switch';

	/** Scope: read-only access (every GET on the namespace). */
	const SCOPE_READ = 'read';

	/** Scope: content / media / taxonomy / menu / SEO writes. */
	const SCOPE_CONTENT_WRITE = 'content:write';

	/** Scope: Divi page + Theme Builder writes. */
	const SCOPE_DIVI_WRITE = 'divi:write';

	/** Scope: site settings + user-management writes. */
	const SCOPE_CONFIG_WRITE = 'config:write';

	/** Scope: infrastructure writes (plugin install, future Phase 4 ops). */
	const SCOPE_INFRA_WRITE = 'infra:write';

	/**
	 * Catalogue of all known scopes with a human-readable label.
	 *
	 * @return array<string, string> scope => label.
	 */
	public static function known_scopes() {
		return array(
			self::SCOPE_READ          => 'Read (all GET endpoints, diagnostics, audit log)',
			self::SCOPE_CONTENT_WRITE => 'Content writes (pages, posts, media, taxonomies, menus, SEO)',
			self::SCOPE_DIVI_WRITE    => 'Divi writes (pages, Theme Builder)',
			self::SCOPE_CONFIG_WRITE  => 'Configuration writes (site settings, users)',
			self::SCOPE_INFRA_WRITE   => 'Infrastructure writes (plugins, themes, backups)',
		);
	}

	/**
	 * Returns the API credentials, or null if none are configured.
	 *
	 * @return array|null Array { key_id, secret, created_at, scopes? } or null.
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
	 * Returns the scopes attached to the current key.
	 *
	 * - Returns null for legacy keys with no explicit scopes (full access,
	 *   backward compatibility).
	 * - Returns a sanitised list of scope strings otherwise.
	 *
	 * @return array|null
	 */
	public static function get_scopes() {
		$creds = self::get_credentials();
		if ( null === $creds ) {
			return null;
		}
		if ( ! array_key_exists( 'scopes', $creds ) ) {
			return null;
		}
		if ( ! is_array( $creds['scopes'] ) ) {
			return array();
		}
		return self::sanitize_scopes( $creds['scopes'] );
	}

	/**
	 * Tells whether the current key holds a given scope.
	 *
	 * @param string $scope Scope name (e.g. self::SCOPE_DIVI_WRITE).
	 * @return bool
	 */
	public static function key_has_scope( $scope ) {
		$scopes = self::get_scopes();
		if ( null === $scopes ) {
			return true; // Legacy / fully-scoped key.
		}
		return in_array( $scope, $scopes, true );
	}

	/**
	 * Generates a new identifier / secret pair and saves it.
	 *
	 * Any previous secret is permanently replaced: the MCP bridge must
	 * then be reconfigured with the new secret.
	 *
	 * @param array|null $scopes Optional list of scope names. Null means
	 *                           fully-scoped (every scope granted). An
	 *                           explicit empty array means read-only.
	 * @return array The new credentials.
	 */
	public static function generate_credentials( $scopes = null ) {
		$creds = array(
			'key_id'     => 'iawm_' . bin2hex( random_bytes( 6 ) ),
			'secret'     => bin2hex( random_bytes( 32 ) ),
			'created_at' => gmdate( 'c' ),
		);

		if ( is_array( $scopes ) ) {
			$creds['scopes'] = self::sanitize_scopes( $scopes );
		}

		// autoload=false: the secret is only read on API requests.
		update_option( self::OPTION_CREDENTIALS, $creds, false );

		return $creds;
	}

	/**
	 * Replaces the scope list of the current key without rotating the
	 * secret. Useful to broaden or restrict access without disrupting
	 * the gateway.
	 *
	 * @param array|null $scopes Scope list, or null to clear (full access).
	 * @return array|null Updated credentials, or null if none configured.
	 */
	public static function update_scopes( $scopes ) {
		$creds = self::get_credentials();
		if ( null === $creds ) {
			return null;
		}

		if ( null === $scopes ) {
			unset( $creds['scopes'] );
		} else {
			$creds['scopes'] = self::sanitize_scopes( (array) $scopes );
		}

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

	/**
	 * Filters a list of submitted scope names against the known catalogue.
	 *
	 * @param array $scopes Raw list.
	 * @return array Sanitised list, de-duplicated, only known scope names.
	 */
	public static function sanitize_scopes( $scopes ) {
		$known = array_keys( self::known_scopes() );
		$out   = array();
		foreach ( (array) $scopes as $scope ) {
			$scope = is_string( $scope ) ? trim( $scope ) : '';
			if ( '' !== $scope && in_array( $scope, $known, true ) && ! in_array( $scope, $out, true ) ) {
				$out[] = $scope;
			}
		}
		return $out;
	}
}
