<?php
/**
 * PHPUnit bootstrap — minimal WordPress stub layer for the critical-path
 * tests.
 *
 * We deliberately do NOT pull in a full WordPress install. Instead we:
 *
 *   1. Autoload Composer dev dependencies (PHPUnit, yoast/wp-test-utils).
 *   2. Define the `ABSPATH` constant so the plugin files agree to load
 *      (every `class-iawm-*.php` starts with `if ( ! defined( 'ABSPATH' ) )
 *      { exit; }`).
 *   3. Pull in the polyfills declared in `wp-stub/wp-functions.php` —
 *      these stand in for `get_option`, `set_transient`, `$wpdb`, the
 *      `WP_Error` / `WP_REST_Request` classes, etc., backed by tiny
 *      in-memory mocks (`WpOptionsMock`, `WpTransientsMock`, `WpdbMock`).
 *   4. Require the production plugin files we are exercising. We do not
 *      load the plugin entry point (`ia-webmaster-bridge.php`) because
 *      it side-effectfully wires up REST routes, cron jobs, audit
 *      tables, etc. — none of which the tests need.
 *
 * Tests reset the mock state in `setUp()` so cases never leak into each
 * other.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// 1. ABSPATH — define it as the wp-stub directory so anything in the
//    plugin that does `require ABSPATH . '...'` lands somewhere harmless.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wp-stub/' );
}

// 2. REST namespace constant used by IAWM_Auth and IAWM_Confirmation
//    when normalising routes. Production sets this in the plugin entry
//    file; we set it directly here.
if ( ! defined( 'IAWM_REST_NAMESPACE' ) ) {
	define( 'IAWM_REST_NAMESPACE', 'ia-webmaster/v1' );
}

if ( ! defined( 'IAWM_VERSION' ) ) {
	define( 'IAWM_VERSION', '0.33.0-test' );
}

// 3. WordPress function + class polyfills.
require_once __DIR__ . '/wp-stub/wp-functions.php';

// 4. Production plugin classes under test. Order matters where one
//    module depends on another (IAWM_Auth references IAWM_Network and
//    IAWM_Settings).
$plugin_includes = dirname( __DIR__ ) . '/includes/';
require_once $plugin_includes . 'class-iawm-settings.php';
require_once $plugin_includes . 'class-iawm-network.php';
require_once $plugin_includes . 'class-iawm-confirmation.php';
require_once $plugin_includes . 'class-iawm-auth.php';
require_once $plugin_includes . 'class-iawm-backup.php';

// Shared test-case base class — handles mock reset between cases.
require_once __DIR__ . '/TestCase.php';
