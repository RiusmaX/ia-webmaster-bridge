<?php
/**
 * Storage for the adapter's settings: API credentials and kill switch.
 *
 * Since v0.26.0 the plugin supports **multiple API keys** living side by
 * side, so several humans can share the same site each with their own
 * Claude Code session (and their own scopes, their own audit trail).
 *
 * The shape stored in the `iawm_credentials` option is a map keyed by
 * `key_id`:
 *
 *   [
 *     'iawm_aaaa' => [
 *        'secret'         => '<hex>',
 *        'scopes'         => ['read', 'content:write', ...] OR omitted = full,
 *        'label'          => 'Alice — content team',
 *        'linked_user_id' => 42,        // WP user the key represents (audit only)
 *        'created_at'     => ISO 8601,
 *        'last_used_at'   => ISO 8601,
 *     ],
 *     'iawm_bbbb' => [ ... ],
 *   ]
 *
 * The HMAC executor on the WordPress side is still the dedicated agent
 * user (`iawm-agent`) regardless of which key signs the request —
 * `linked_user_id` exists purely so the audit log can report which
 * human's Claude triggered the call.
 *
 * **Backward compatibility**: installs older than v0.26.0 stored a
 * single record directly: `['key_id' => ..., 'secret' => ..., 'scopes' =>
 * ..., 'created_at' => ...]`. On read, that legacy shape is migrated to
 * the multi-key map transparently. Existing keys keep working without
 * any operator action.
 *
 * Scopes (since v0.19.0) gate which families of routes a key may call.
 * A credentials record without a `scopes` field is treated as
 * fully-scoped (every scope granted).
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralised access to the plugin's options.
 */
class IAWM_Settings {

	/** Option storing the API credentials (map keyed by key_id). */
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

	/** Scope: infrastructure writes (plugin/theme install, core update, DB). */
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
			self::SCOPE_INFRA_WRITE   => 'Infrastructure writes (plugins, themes, core, database, backups)',
		);
	}

	/* ----------------------------------------------------------------- */
	/* Multi-key map (current shape)                                      */
	/* ----------------------------------------------------------------- */

	/**
	 * Returns the full credentials map keyed by key_id.
	 *
	 * @return array<string, array>
	 */
	public static function all_credentials() {
		$raw = get_option( self::OPTION_CREDENTIALS );

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		// Legacy shape: a single flat record with `key_id` and `secret`.
		if ( isset( $raw['key_id'], $raw['secret'] ) ) {
			$key_id = (string) $raw['key_id'];
			return array(
				$key_id => array(
					'secret'     => (string) $raw['secret'],
					'scopes'     => isset( $raw['scopes'] ) ? $raw['scopes'] : null,
					'label'      => isset( $raw['label'] ) ? (string) $raw['label'] : 'Legacy key',
					'created_at' => isset( $raw['created_at'] ) ? (string) $raw['created_at'] : null,
					'last_used_at' => null,
				),
			);
		}

		// Current shape: map keyed by key_id.
		return $raw;
	}

	/**
	 * Returns one credentials record by key_id, or null if not found.
	 *
	 * @param string $key_id Identifier.
	 * @return array|null
	 */
	public static function get_by_key_id( $key_id ) {
		$all = self::all_credentials();
		if ( ! is_string( $key_id ) || '' === $key_id || ! isset( $all[ $key_id ] ) ) {
			return null;
		}
		$entry = $all[ $key_id ];
		// Always surface the key_id alongside the record for callers.
		$entry['key_id'] = $key_id;
		return $entry;
	}

	/**
	 * Indicates whether at least one credentials record is configured.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return ! empty( self::all_credentials() );
	}

	/* ----------------------------------------------------------------- */
	/* Legacy single-credential helpers (read-side compat)                */
	/* ----------------------------------------------------------------- */

	/**
	 * Returns "the" credentials record when callers expect a single one.
	 *
	 * - With exactly one key: returns it.
	 * - With several keys: returns the first one (deterministic by key_id sort).
	 * - With none: null.
	 *
	 * Production callers should use `get_by_key_id()` resolved from the
	 * request's `X-IAWM-Key` header instead — IAWM_Auth does this. This
	 * helper exists only for back-compat with code paths that read the
	 * "current" key (e.g. the admin UI's "current key" panel).
	 *
	 * @return array|null
	 */
	public static function get_credentials() {
		$all = self::all_credentials();
		if ( empty( $all ) ) {
			return null;
		}
		$keys = array_keys( $all );
		sort( $keys );
		$key_id = $keys[0];
		$entry  = $all[ $key_id ];
		$entry['key_id'] = $key_id;
		return $entry;
	}

	/* ----------------------------------------------------------------- */
	/* Scope helpers                                                      */
	/* ----------------------------------------------------------------- */

	/**
	 * Returns the scopes attached to a given key.
	 *
	 * - Returns null for legacy / fully-scoped records.
	 * - Returns a sanitised list of scope strings otherwise.
	 *
	 * @param string $key_id Key id.
	 * @return array|null
	 */
	public static function get_scopes( $key_id ) {
		$entry = self::get_by_key_id( $key_id );
		if ( null === $entry ) {
			return array();
		}
		if ( ! array_key_exists( 'scopes', $entry ) || null === $entry['scopes'] ) {
			return null;
		}
		if ( ! is_array( $entry['scopes'] ) ) {
			return array();
		}
		return self::sanitize_scopes( $entry['scopes'] );
	}

	/**
	 * Tells whether a specific key holds a given scope.
	 *
	 * @param string $key_id Key id.
	 * @param string $scope  Scope name.
	 * @return bool
	 */
	public static function key_has_scope( $key_id, $scope ) {
		$scopes = self::get_scopes( $key_id );
		if ( null === $scopes ) {
			return true; // Legacy / fully-scoped key.
		}
		return in_array( $scope, $scopes, true );
	}

	/* ----------------------------------------------------------------- */
	/* CRUD                                                               */
	/* ----------------------------------------------------------------- */

	/**
	 * Generates a brand-new key (id + secret) and stores it.
	 *
	 * @param array|null  $scopes        Scope list, or null = fully-scoped.
	 * @param string      $label         Human-readable label.
	 * @param int|null    $linked_user_id Optional WP user id (audit only).
	 * @return array Created record (includes key_id and the raw secret —
	 *               this is the only chance to read the secret in cleartext
	 *               from the API).
	 */
	public static function create_credentials( $scopes = null, $label = '', $linked_user_id = null ) {
		$key_id = 'iawm_' . bin2hex( random_bytes( 6 ) );
		$record = array(
			'secret'         => bin2hex( random_bytes( 32 ) ),
			'label'          => is_string( $label ) && '' !== $label ? $label : 'Untitled key',
			'created_at'     => gmdate( 'c' ),
			'last_used_at'   => null,
			'linked_user_id' => is_numeric( $linked_user_id ) ? (int) $linked_user_id : null,
		);
		if ( is_array( $scopes ) ) {
			$record['scopes'] = self::sanitize_scopes( $scopes );
		}

		$all          = self::all_credentials();
		$all[ $key_id ] = $record;
		update_option( self::OPTION_CREDENTIALS, $all, false );

		$record['key_id'] = $key_id;
		return $record;
	}

	/**
	 * Rotates the secret of an existing key while keeping its scopes,
	 * label and linked user. Returns the new secret in cleartext (only
	 * chance to read it).
	 *
	 * @param string $key_id Key to rotate.
	 * @return array|null Updated record, or null if not found.
	 */
	public static function rotate_secret( $key_id ) {
		$all = self::all_credentials();
		if ( ! isset( $all[ $key_id ] ) ) {
			return null;
		}
		$all[ $key_id ]['secret']     = bin2hex( random_bytes( 32 ) );
		$all[ $key_id ]['created_at'] = gmdate( 'c' );
		update_option( self::OPTION_CREDENTIALS, $all, false );

		$entry           = $all[ $key_id ];
		$entry['key_id'] = $key_id;
		return $entry;
	}

	/**
	 * Replaces the scope list of a key without rotating the secret.
	 *
	 * @param string     $key_id Key id.
	 * @param array|null $scopes Scope list, or null to clear (full access).
	 * @return array|null Updated record.
	 */
	public static function update_scopes( $key_id, $scopes ) {
		$all = self::all_credentials();
		if ( ! isset( $all[ $key_id ] ) ) {
			return null;
		}
		if ( null === $scopes ) {
			unset( $all[ $key_id ]['scopes'] );
		} else {
			$all[ $key_id ]['scopes'] = self::sanitize_scopes( (array) $scopes );
		}
		update_option( self::OPTION_CREDENTIALS, $all, false );

		$entry           = $all[ $key_id ];
		$entry['key_id'] = $key_id;
		return $entry;
	}

	/**
	 * Updates the human-readable label or linked WP user of a key. Any
	 * argument can be null to leave it unchanged. Pass `0` as
	 * linked_user_id to clear the link.
	 *
	 * @param string      $key_id        Key id.
	 * @param string|null $label         New label, or null to leave unchanged.
	 * @param int|null    $linked_user_id New user id, 0 to clear, or null to
	 *                                   leave unchanged.
	 * @return array|null Updated record.
	 */
	public static function update_metadata( $key_id, $label = null, $linked_user_id = null ) {
		$all = self::all_credentials();
		if ( ! isset( $all[ $key_id ] ) ) {
			return null;
		}
		if ( null !== $label ) {
			$all[ $key_id ]['label'] = (string) $label;
		}
		if ( null !== $linked_user_id ) {
			$all[ $key_id ]['linked_user_id'] = 0 === (int) $linked_user_id ? null : (int) $linked_user_id;
		}
		update_option( self::OPTION_CREDENTIALS, $all, false );

		$entry           = $all[ $key_id ];
		$entry['key_id'] = $key_id;
		return $entry;
	}

	/**
	 * Updates the `last_used_at` timestamp of a key after a successful
	 * authentication. Called from IAWM_Auth.
	 *
	 * @param string $key_id Key id.
	 * @return void
	 */
	public static function touch_last_used( $key_id ) {
		$all = self::all_credentials();
		if ( ! isset( $all[ $key_id ] ) ) {
			return;
		}
		$all[ $key_id ]['last_used_at'] = gmdate( 'c' );
		// autoload=false: only read during signed API calls anyway.
		update_option( self::OPTION_CREDENTIALS, $all, false );
	}

	/**
	 * Revokes (deletes) a single key.
	 *
	 * @param string $key_id Key id.
	 * @return bool
	 */
	public static function revoke_key( $key_id ) {
		$all = self::all_credentials();
		if ( ! isset( $all[ $key_id ] ) ) {
			return false;
		}
		unset( $all[ $key_id ] );
		if ( empty( $all ) ) {
			delete_option( self::OPTION_CREDENTIALS );
		} else {
			update_option( self::OPTION_CREDENTIALS, $all, false );
		}
		return true;
	}

	/**
	 * Deletes ALL credentials at once (the agent loses all access).
	 *
	 * @return void
	 */
	public static function revoke_credentials() {
		delete_option( self::OPTION_CREDENTIALS );
	}

	/* ----------------------------------------------------------------- */
	/* Kill switch                                                        */
	/* ----------------------------------------------------------------- */

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

	/* ----------------------------------------------------------------- */
	/* Sanitisation                                                       */
	/* ----------------------------------------------------------------- */

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
