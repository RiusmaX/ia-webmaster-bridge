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
 * ### Multisite behaviour
 *
 * On a WordPress multisite network, **users are global** but **roles are
 * per-site**. To keep the same security guarantees on a network:
 *
 *   - The `iawm-agent` user is created **once** for the whole network.
 *   - The `iawm_agent` role is installed on **each sub-site** that has
 *     the plugin active, and the user is granted that role on every
 *     such sub-site (never network-wide super-admin).
 *   - On `install()` we walk every sub-site (when network-activated) or
 *     just the current one (per-site activation) and call
 *     `switch_to_blog()` so each sub-site gets both the role and the
 *     role assignment.
 *   - On new sub-site creation (`wp_initialize_site`), the main plugin
 *     bootstrap re-runs `install_for_current_site()`, which calls back
 *     into this module so the new sub-site is provisioned without
 *     operator intervention.
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
	 * Only provisions the **current** site (whatever `get_option()` /
	 * `get_current_blog_id()` resolves to). On a network-activated
	 * multisite, every sub-site reaches this code on its own first
	 * request, so the cumulative effect provisions the whole network
	 * lazily. The activation hook handles the initial bulk install.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::OPTION_INSTALL_VERSION, 0 ) >= self::INSTALL_VERSION ) {
			return;
		}
		self::install_for_current_site();
		update_option( self::OPTION_INSTALL_VERSION, self::INSTALL_VERSION, true );
	}

	/**
	 * Forces a fresh install (used by the plugin activation hook).
	 *
	 * On a single-site install: registers the role on the current site
	 * and provisions the agent user.
	 *
	 * On multisite: makes sure the agent user exists globally (single
	 * shared user across the whole network), then installs the role and
	 * grants it on **every** sub-site of the network when invoked from
	 * the network-activation path, or just the current sub-site
	 * otherwise. Idempotent — safe to re-run.
	 *
	 * @param bool $network_wide When true (set by the network activation
	 *                           hook), provision every sub-site of the
	 *                           network. When false, only the current
	 *                           site. Ignored outside multisite.
	 * @return void
	 */
	public static function install( $network_wide = false ) {
		// Always ensure the (global on multisite, local otherwise) user exists.
		$user_id = self::ensure_global_user();

		if ( is_multisite() && $network_wide ) {
			// Iterate every sub-site and install role + role assignment there.
			$sites = function_exists( 'get_sites' ) ? get_sites( array( 'number' => 0 ) ) : array();
			foreach ( $sites as $site ) {
				$blog_id = (int) $site->blog_id;
				switch_to_blog( $blog_id );
				self::install_for_current_site( $user_id );
				restore_current_blog();
			}
			return;
		}

		// Single-site, or per-site activation on a multisite: just here.
		self::install_for_current_site( $user_id );
	}

	/**
	 * Installs the role on the current site and grants it to the agent user.
	 *
	 * Safe to call inside a `switch_to_blog()` block: every option / role
	 * call routes to the active blog.
	 *
	 * Used by:
	 *   - the network activation loop (one call per sub-site),
	 *   - the per-site activation path (one call total),
	 *   - the `wp_initialize_site` hook when a new sub-site is created
	 *     after network activation,
	 *   - the lazy `maybe_install()` upgrade path.
	 *
	 * @param int|null $user_id Pre-resolved agent user id; resolved here
	 *                          if null.
	 * @return void
	 */
	public static function install_for_current_site( $user_id = null ) {
		self::install_role();

		if ( null === $user_id ) {
			$user_id = self::ensure_global_user();
		}
		if ( ! $user_id ) {
			return;
		}

		// Make sure the user is a member of this site with the agent role.
		self::assign_role_on_current_site( $user_id );

		// Record the id locally so per-site reads are fast.
		update_option( self::OPTION_USER_ID, (int) $user_id, true );
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
		add_role( self::ROLE_KEY, __( 'IA Webmaster Agent', 'ia-webmaster-bridge' ), $caps );
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
	 * Ensures the agent user exists, has the agent role on the current
	 * site, and is recorded in the OPTION_USER_ID option. Idempotent.
	 *
	 * Kept as a thin wrapper around the multisite-aware
	 * `ensure_global_user()` + `assign_role_on_current_site()` pair so
	 * legacy callers keep working.
	 *
	 * @return int|null User ID, or null if creation failed.
	 */
	public static function ensure_user() {
		$user_id = self::ensure_global_user();
		if ( ! $user_id ) {
			return null;
		}
		self::assign_role_on_current_site( $user_id );
		update_option( self::OPTION_USER_ID, (int) $user_id, true );
		return (int) $user_id;
	}

	/**
	 * Ensures the agent user exists. **Single user across the whole
	 * network** on multisite — created once and reused for every
	 * sub-site.
	 *
	 * Does **not** touch role assignment on the current site; callers
	 * should pair this with `assign_role_on_current_site()`.
	 *
	 * @return int|null User ID, or null if creation failed.
	 */
	public static function ensure_global_user() {
		$existing = get_user_by( 'login', self::USER_LOGIN );

		if ( $existing instanceof WP_User ) {
			return (int) $existing->ID;
		}

		// Create the user. The password is random and never displayed — the
		// agent authenticates via HMAC, not via WordPress login.
		$password = wp_generate_password( 32, true, true );
		$email    = self::placeholder_email();

		// We pass an explicit `role` so WordPress sets it on the **creation
		// blog** (this also adds the global user to that blog on multisite).
		// `assign_role_on_current_site()` re-runs the assignment on every
		// blog that needs it.
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::USER_LOGIN,
				'user_pass'    => $password,
				'user_email'   => $email,
				'display_name' => __( 'IA Webmaster Agent', 'ia-webmaster-bridge' ),
				'nickname'     => 'iawm-agent',
				'first_name'   => __( 'IA Webmaster', 'ia-webmaster-bridge' ),
				'last_name'    => __( 'Agent', 'ia-webmaster-bridge' ),
				'role'         => self::ROLE_KEY,
				'description'  => __( 'System user under whom the IA Webmaster Bridge adapter performs writes. Do not delete; do not log in. Authentication is HMAC, not WordPress login.', 'ia-webmaster-bridge' ),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		return (int) $user_id;
	}

	/**
	 * Grants the agent role on the **current** site (respects any active
	 * `switch_to_blog()` context).
	 *
	 * On multisite, also calls `add_user_to_blog()` first so the global
	 * user becomes a member of this sub-site before the role is
	 * assigned. The function is idempotent: re-running it on a site
	 * where the assignment is already correct is a no-op.
	 *
	 * Important: we **never** grant super-admin or any network-wide
	 * capability — only the per-site `iawm_agent` role.
	 *
	 * @param int $user_id Agent user id.
	 * @return void
	 */
	public static function assign_role_on_current_site( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		// On multisite, make sure the user belongs to the current blog
		// before we touch its roles.
		if ( is_multisite() && function_exists( 'is_user_member_of_blog' ) ) {
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( $blog_id > 0 && ! is_user_member_of_blog( $user_id, $blog_id ) ) {
				if ( function_exists( 'add_user_to_blog' ) ) {
					add_user_to_blog( $blog_id, $user_id, self::ROLE_KEY );
				}
			}
		}

		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		// `set_role()` replaces any existing role mapping on the current
		// blog with just the agent role — the right policy here, since we
		// do not want the agent inheriting an editorial role someone
		// granted by accident.
		$has_role = in_array( self::ROLE_KEY, (array) $user->roles, true );
		if ( ! $has_role || count( (array) $user->roles ) > 1 ) {
			$user->set_role( self::ROLE_KEY );
		}
	}

	/**
	 * Returns the agent user ID. Lazily ensures the user exists.
	 *
	 * On multisite: returns the global agent user's id. The
	 * `OPTION_USER_ID` cache is per-site; if it is missing on this
	 * sub-site, we look the user up globally by login.
	 *
	 * @return int User ID; 0 if creation failed.
	 */
	public static function get_user_id() {
		$user_id = (int) get_option( self::OPTION_USER_ID, 0 );

		if ( $user_id > 0 && get_userdata( $user_id ) ) {
			return $user_id;
		}

		// Try the global lookup first — on multisite the user may exist
		// already (created on the main site) but the per-site option
		// hasn't been populated yet on this sub-site.
		$existing = get_user_by( 'login', self::USER_LOGIN );
		if ( $existing instanceof WP_User ) {
			update_option( self::OPTION_USER_ID, (int) $existing->ID, true );
			return (int) $existing->ID;
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
