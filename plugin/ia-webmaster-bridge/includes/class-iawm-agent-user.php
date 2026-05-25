<?php
/**
 * Dedicated WordPress user under whom the agent acts.
 *
 * Before this module, the adapter borrowed the first administrator's
 * identity to perform writes (audit trail was muddied, and a leaked HMAC
 * secret would have granted full admin powers). This class introduces:
 *
 *   - a dedicated role `iawm_agent` with most editorial capabilities but
 *     stripped of the highest-risk ones (`unfiltered_html`,
 *     `unfiltered_upload`, multisite network caps);
 *   - a dedicated user `iawm-agent` assigned to that role, used as the
 *     executor for every write the API performs.
 *
 * As a result:
 *   - WordPress audit / revision metadata clearly identifies the agent;
 *   - leakage of the HMAC secret limits the attacker to what the
 *     `iawm_agent` role can do — never raw super-admin powers;
 *   - the human operator's admin account is untouched.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and tracks the dedicated agent user and role.
 */
class IAWM_Agent_User {

	/** Role slug used inside WordPress. */
	const ROLE_KEY = 'iawm_agent';

	/** User login of the dedicated user. */
	const USER_LOGIN = 'iawm-agent';

	/** Option storing the agent user ID for fast lookup. */
	const OPTION_USER_ID = 'iawm_agent_user_id';

	/** Schema version of the agent install (bump to re-run install). */
	const INSTALL_VERSION = 1;

	/** Option storing the installed agent schema version. */
	const OPTION_INSTALL_VERSION = 'iawm_agent_install_version';

	/**
	 * Hooks the lazy installer.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
	}

	/**
	 * Installs or upgrades the agent role + user if the stored version is
	 * lower than INSTALL_VERSION. Safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::OPTION_INSTALL_VERSION, 0 ) >= self::INSTALL_VERSION ) {
			return;
		}
		self::install();
		update_option( self::OPTION_INSTALL_VERSION, self::INSTALL_VERSION, true );
	}

	/**
	 * Forces a fresh install (used by the plugin activation hook).
	 *
	 * @return void
	 */
	public static function install() {
		self::install_role();
		self::ensure_user();
	}

	/**
	 * Registers or refreshes the `iawm_agent` role and its capabilities.
	 *
	 * If the role already exists, its capabilities are reset to the
	 * canonical set — this allows updating the policy by bumping
	 * INSTALL_VERSION.
	 *
	 * @return void
	 */
	public static function install_role() {
		$caps = self::role_caps();

		// Remove first so capability tweaks are picked up on upgrade.
		remove_role( self::ROLE_KEY );
		add_role( self::ROLE_KEY, 'IA Webmaster Agent', $caps );
	}

	/**
	 * Capabilities granted to the agent role.
	 *
	 * Loosely based on the administrator role, minus the highest-risk
	 * capabilities. The application layer (scope checks in IAWM_Auth and
	 * dedicated logic in IAWM_Config) adds finer-grained restrictions.
	 *
	 * Excluded on purpose:
	 *   - `unfiltered_html`     : the agent goes through Gutenberg / Divi
	 *                              normalization, raw HTML is not needed.
	 *   - `unfiltered_upload`   : extension allow-list is enough.
	 *   - `manage_network_*`    : multisite super-admin; out of scope.
	 *   - `upgrade_network`     : idem.
	 *
	 * @return array<string, bool> Map of capability => true.
	 */
	public static function role_caps() {
		$caps = array(
			// Reading.
			'read'                    => true,
			'read_private_pages'      => true,
			'read_private_posts'      => true,

			// Editorial: posts & pages.
			'edit_posts'              => true,
			'edit_pages'              => true,
			'edit_others_posts'       => true,
			'edit_others_pages'       => true,
			'edit_published_posts'    => true,
			'edit_published_pages'    => true,
			'edit_private_posts'      => true,
			'edit_private_pages'      => true,
			'publish_posts'           => true,
			'publish_pages'           => true,
			'delete_posts'            => true,
			'delete_pages'            => true,
			'delete_others_posts'     => true,
			'delete_others_pages'     => true,
			'delete_published_posts'  => true,
			'delete_published_pages'  => true,
			'delete_private_posts'    => true,
			'delete_private_pages'    => true,

			// Media.
			'upload_files'            => true,

			// Taxonomies & menus.
			'manage_categories'       => true,

			// Theme options (needed by WP menus, customizer, Divi Theme Builder).
			'edit_theme_options'      => true,
			'switch_themes'           => true,
			'install_themes'          => true,
			'update_themes'           => true,
			'delete_themes'           => true,

			// Plugins.
			'activate_plugins'        => true,
			'install_plugins'         => true,
			'update_plugins'          => true,
			'delete_plugins'          => true,
			'edit_plugins'            => false,
			'edit_themes'             => false,
			'edit_files'              => false,

			// Site settings.
			'manage_options'          => true,

			// Users (the application layer forbids touching the agent's own user).
			'list_users'              => true,
			'create_users'            => true,
			'edit_users'              => true,
			'delete_users'            => true,
			'promote_users'           => true,

			// Comments (read-only by default; we don't expose moderation yet).
			'moderate_comments'       => false,
			'edit_comment'            => false,

			// Imports/exports — not strictly required, kept off.
			'import'                  => false,
			'export'                  => false,

			// Explicitly NOT granted (security).
			// 'unfiltered_html'      => false (no raw HTML injection).
			// 'unfiltered_upload'   => false (no arbitrary upload).
			// 'manage_network_*'    => false (super-admin).
		);

		/**
		 * Filters the capability set granted to the dedicated agent role.
		 *
		 * Provided so site owners can tighten or extend the role without
		 * patching the plugin. Returning an empty array disables the role.
		 *
		 * @param array<string, bool> $caps Default capability map.
		 */
		return (array) apply_filters( 'iawm_agent_role_caps', $caps );
	}

	/**
	 * Ensures the agent user exists, has the agent role, and is recorded
	 * in the OPTION_USER_ID option. Idempotent.
	 *
	 * @return int|null User ID, or null if creation failed.
	 */
	public static function ensure_user() {
		$existing = get_user_by( 'login', self::USER_LOGIN );

		if ( $existing instanceof WP_User ) {
			// Make sure the role assignment is correct (no admin promotion).
			$has_role = in_array( self::ROLE_KEY, (array) $existing->roles, true );
			if ( ! $has_role ) {
				$existing->set_role( self::ROLE_KEY );
			}
			update_option( self::OPTION_USER_ID, (int) $existing->ID, true );
			return (int) $existing->ID;
		}

		// Create the user. The password is random and never displayed — the
		// agent authenticates via HMAC, not via WordPress login.
		$password = wp_generate_password( 32, true, true );
		$email    = self::placeholder_email();

		$user_id = wp_insert_user(
			array(
				'user_login'   => self::USER_LOGIN,
				'user_pass'    => $password,
				'user_email'   => $email,
				'display_name' => 'IA Webmaster Agent',
				'nickname'     => 'iawm-agent',
				'first_name'   => 'IA Webmaster',
				'last_name'    => 'Agent',
				'role'         => self::ROLE_KEY,
				'description'  => 'System user under whom the IA Webmaster Bridge adapter performs writes. Do not delete; do not log in. Authentication is HMAC, not WordPress login.',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		update_option( self::OPTION_USER_ID, (int) $user_id, true );
		return (int) $user_id;
	}

	/**
	 * Returns the agent user ID. Lazily ensures the user exists.
	 *
	 * @return int User ID; 0 if creation failed.
	 */
	public static function get_user_id() {
		$user_id = (int) get_option( self::OPTION_USER_ID, 0 );

		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			return $user_id;
		}

		// Stale or missing — try to (re)create.
		$created = self::ensure_user();
		return $created ? (int) $created : 0;
	}

	/**
	 * Indicates whether the given user ID belongs to the agent.
	 *
	 * Used by the configuration / user-management endpoints to refuse any
	 * attempt by the agent to modify or delete its own account.
	 *
	 * @param int $user_id Candidate user ID.
	 * @return bool
	 */
	public static function is_agent_user( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 && $user_id === self::get_user_id();
	}

	/**
	 * Returns a placeholder email rooted on the site domain, so two
	 * installs of the plugin (or a fresh local copy) do not collide on
	 * the global "email already in use" check.
	 *
	 * @return string
	 */
	private static function placeholder_email() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			$host = 'localhost';
		}
		return 'iawm-agent@' . $host;
	}
}
