<?php
/**
 * Plugin Name:       IA Webmaster Bridge
 * Description:       Adaptateur permettant à une IA (Claude) d'agir comme webmaster sur ce site WordPress. Expose une API REST contrôlée et signée sous le namespace ia-webmaster/v1.
 * Version:           0.16.0
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Author:            Marius Sergent
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ia-webmaster-bridge
 *
 * @package IA_Webmaster_Bridge
 */

// Sécurité : empêcher tout accès direct au fichier.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IAWM_VERSION', '0.16.0' );
define( 'IAWM_REST_NAMESPACE', 'ia-webmaster/v1' );
define( 'IAWM_PLUGIN_FILE', __FILE__ );
define( 'IAWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-settings.php';
require_once IAWM_PLUGIN_DIR . 'includes/class-iawm-support.php';
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

// Création / migration du schéma de la base lors de l'activation du plugin.
register_activation_hook( IAWM_PLUGIN_FILE, array( 'IAWM_Audit', 'maybe_upgrade' ) );

// Initialisation des modules.
IAWM_Audit::init();
IAWM_REST::init();
IAWM_Content::init();
IAWM_Media::init();
IAWM_Taxonomy::init();
IAWM_Menu::init();
IAWM_Diagnostics::init();
IAWM_Config::init();
IAWM_Plugins::init();
IAWM_Seo::init();
IAWM_Divi::init();
IAWM_Admin::init();
