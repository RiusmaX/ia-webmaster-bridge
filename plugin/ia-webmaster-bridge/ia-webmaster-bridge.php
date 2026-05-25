<?php
/**
 * Plugin Name:       IA Webmaster Bridge
 * Description:       Adapter that lets an AI (Claude) act as a webmaster on this WordPress site. Exposes a controlled, signed REST API under the ia-webmaster/v1 namespace.
 * Version:           0.25.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Marius Sergent
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ia-webmaster-bridge
 *
 * @package IA_Webmaster_Bridge
 */

// Security: prevent any direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IAWM_VERSION', '0.25.0' );
define( 'IAWM_REST_NAMESPACE', 'ia-webmaster/v1' );
define( 'IAWM_PLUGIN_FILE', __FILE__ );
define( 'IAWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-settings.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-support.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-agent-user.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-confirmation.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-auth.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-rest.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-admin.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-audit.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-content.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-media.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-taxonomy.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-menu.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-diagnostics.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-config.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-plugins.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-seo.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-divi.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-divi-theme-builder.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-backup.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-themes.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-core.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-database.php';

// Database schema creation / migration on plugin activation.
register_activation_hook( IAWM_PLUGIN_FILE, array( 'IAWM_Audit', 'maybe_upgrade' ) );

// Dedicated agent role + user creation/upgrade on plugin activation.
register_activation_hook( IAWM_PLUGIN_FILE, array( 'IAWM_Agent_User', 'install' ) );

// Backup table creation/migration on plugin activation.
register_activation_hook( IAWM_PLUGIN_FILE, array( 'IAWM_Backup', 'maybe_upgrade' ) );

// Module initialisation.
IAWM_Agent_User::init();
IAWM_Audit::init();
IAWM_Backup::init();
IAWM_REST::init();
IAWM_Content::init();
IAWM_Media::init();
IAWM_Taxonomy::init();
IAWM_Menu::init();
IAWM_Diagnostics::init();
IAWM_Config::init();
IAWM_Plugins::init();
IAWM_Themes::init();
IAWM_Core::init();
IAWM_Database::init();
IAWM_Seo::init();
IAWM_Divi::init();
IAWM_Divi_Theme_Builder::init();
IAWM_Admin::init();
