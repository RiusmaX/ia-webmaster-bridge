<?php
/**
 * Plugin Name:       IA Webmaster Bridge
 * Description:       Adapter that lets an AI (Claude) act as a webmaster on this WordPress site. Exposes a controlled, signed REST API under the ia-webmaster/v1 namespace.
 * Version:           1.2.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Marius Sergent
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ia-webmaster-bridge
 * Domain Path:       /languages
 *
 * @package IA_Webmaster_Bridge
 */

// Security: prevent any direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IAWM_VERSION', '1.2.0' );
define( 'IAWM_REST_NAMESPACE', 'ia-webmaster/v1' );
define( 'IAWM_PLUGIN_FILE', __FILE__ );
define( 'IAWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Load translations as early as WordPress allows. The `Text Domain` and
// `Domain Path` headers above tell WordPress where to look for .mo files
// (`languages/ia-webmaster-bridge-<locale>.mo`); this call wires it up.
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain(
			'ia-webmaster-bridge',
			false,
			dirname( plugin_basename( IAWM_PLUGIN_FILE ) ) . '/languages/'
		);
	}
);

require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-settings.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-support.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-agent-user.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-confirmation.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-network.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-auth.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-rest.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-admin.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-network-admin.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-audit.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-content.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-media.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-taxonomy.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-menu.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-diagnostics.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-404.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-config.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-plugins.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-seo.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-divi.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-divi-theme-builder.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-woocommerce.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-backup.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-link-checker.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-themes.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-core.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-database.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-cron.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-context.php';

/**
 * Per-site install routine.
 *
 * Bundled so:
 *   - the standard `register_activation_hook` callback runs it on the
 *     current site (single-site or per-site activation on multisite);
 *   - the network-activation loop below runs it inside each
 *     `switch_to_blog()` for every existing sub-site;
 *   - the `wp_initialize_site` hook runs it on newly-created sub-sites
 *     so a fresh network blog is provisioned without operator action.
 *
 * Every table install in this plugin uses `$wpdb->prefix`, so the
 * tables live in the sub-site's own prefix — they MUST be created
 * per-site, not just on the main site. The agent user is global on
 * multisite (see IAWM_Agent_User docblock) but its role mapping is
 * per-site, so the role install also belongs here.
 *
 * @return void
 */
function iawm_install_for_current_site() {
	IAWM_Audit::maybe_upgrade();
	IAWM_Backup::maybe_upgrade();
	IAWM_LinkChecker::maybe_upgrade();
	IAWM_FourOhFour::maybe_upgrade();
	IAWM_Agent_User::install_for_current_site();
}

/**
 * Activation handler. WordPress passes `$network_wide = true` when the
 * plugin is network-activated on a multisite. We use that to decide
 * whether to provision a single site or every sub-site of the network.
 *
 * @param bool $network_wide Network-wide activation flag.
 * @return void
 */
function iawm_on_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		// Ensure the (single) global agent user exists before we visit
		// the sub-sites — `install_for_current_site()` will then just
		// re-use it for the per-site role assignment.
		IAWM_Agent_User::ensure_global_user();

		$sites = function_exists( 'get_sites' ) ? get_sites( array( 'number' => 0 ) ) : array();
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );
			iawm_install_for_current_site();
			restore_current_blog();
		}
		return;
	}

	// Single-site, or per-site activation on multisite.
	iawm_install_for_current_site();
}
register_activation_hook( IAWM_PLUGIN_FILE, 'iawm_on_activate' );

/**
 * When a new sub-site is created on a multisite that has the plugin
 * network-activated, provision it automatically. WP 5.1+ ships
 * `wp_initialize_site`; we hook it here so the new blog gets its
 * audit/backup/404 tables and its role assignment without operator
 * action.
 *
 * On older multisite installs (pre-5.1) the deprecated `wpmu_new_blog`
 * hook does the same; we register both for safety.
 *
 * @param WP_Site|int $site WP_Site object (modern) or blog id (legacy).
 * @return void
 */
function iawm_on_new_site( $site ) {
	if ( ! is_multisite() ) {
		return;
	}
	// Only auto-provision if the plugin is network-active. On a
	// per-site-activated install, sub-sites without the plugin should
	// stay untouched.
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( IAWM_PLUGIN_FILE ) ) ) {
		return;
	}

	$blog_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;
	if ( $blog_id <= 0 ) {
		return;
	}
	switch_to_blog( $blog_id );
	iawm_install_for_current_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'iawm_on_new_site', 20 );
add_action( 'wpmu_new_blog',      'iawm_on_new_site', 20 ); // Legacy fallback for pre-WP-5.1.

// Module initialisation.
IAWM_Agent_User::init();
IAWM_Audit::init();
IAWM_Backup::init();
IAWM_LinkChecker::init();
IAWM_REST::init();
IAWM_Content::init();
IAWM_Media::init();
IAWM_Taxonomy::init();
IAWM_Menu::init();
IAWM_Diagnostics::init();
IAWM_FourOhFour::init();
IAWM_Config::init();
IAWM_Plugins::init();
IAWM_Themes::init();
IAWM_Core::init();
IAWM_Database::init();
IAWM_Cron::init();
IAWM_Context::init();
IAWM_Seo::init();
IAWM_Divi::init();
IAWM_Divi_Theme_Builder::init();
IAWM_WooCommerce::init();
IAWM_Admin::init();
IAWM_Network_Admin::init();
