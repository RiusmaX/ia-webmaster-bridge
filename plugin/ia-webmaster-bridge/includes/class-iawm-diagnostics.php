<?php
/**
 * Diagnostics and log access — read-only.
 *
 * All routes use guard_read: this module performs no writes,
 * which is its main safeguard. Every access is still logged.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Diagnostic routes (system, plugins, themes, logs).
 */
class IAWM_Diagnostics {

	/** Maximum size read from the end of the log file. */
	const LOG_TAIL_BYTES = 262144;

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers diagnostic routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/diagnostics/system'     => 'handle_system',
			'/diagnostics/plugins'    => 'handle_plugins',
			'/diagnostics/themes'     => 'handle_themes',
			'/diagnostics/logs'       => 'handle_logs',
			// Phase 7.2 — operational health probes.
			'/diagnostics/smoke'      => 'handle_smoke',
			'/diagnostics/check-self' => 'handle_check_self',
		);

		foreach ( $routes as $path => $callback ) {
			register_rest_route(
				IAWM_REST_NAMESPACE,
				$path,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $callback ),
					'permission_callback' => array( 'IAWM_Auth', 'guard_read' ),
				)
			);
		}
	}

	/**
	 * POST /diagnostics/system — information about the environment.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_system( $request ) {
		unset( $request );
		global $wpdb;

		$theme = wp_get_theme();

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'system' => array(
					'wordpress'    => get_bloginfo( 'version' ),
					'php'          => PHP_VERSION,
					'mysql'        => $wpdb->db_version(),
					'site_url'     => get_site_url(),
					'home_url'     => get_home_url(),
					'environment'  => wp_get_environment_type(),
					'is_multisite' => is_multisite(),
					'active_theme' => array(
						'name'       => $theme ? $theme->get( 'Name' ) : null,
						'version'    => $theme ? $theme->get( 'Version' ) : null,
						'stylesheet' => get_stylesheet(),
					),
					'debug'        => array(
						'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
						'wp_debug_log' => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
					),
					'php_limits'   => array(
						'memory_limit'        => ini_get( 'memory_limit' ),
						'max_execution_time'  => ini_get( 'max_execution_time' ),
						'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
						'post_max_size'       => ini_get( 'post_max_size' ),
					),
				),
			),
			200
		);
	}

	/**
	 * POST /diagnostics/plugins — list of installed plugins.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_plugins( $request ) {
		unset( $request );

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all     = get_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$pending = ( $updates && isset( $updates->response ) && is_array( $updates->response ) )
			? $updates->response
			: array();

		$items = array();
		foreach ( $all as $file => $data ) {
			$items[] = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => is_plugin_active( $file ),
				'update_available' => isset( $pending[ $file ] ),
				'new_version'      => isset( $pending[ $file ] ) ? $pending[ $file ]->new_version : null,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'total'   => count( $items ),
				'plugins' => $items,
			),
			200
		);
	}

	/**
	 * POST /diagnostics/themes — list of installed themes.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_themes( $request ) {
		unset( $request );

		$active = get_stylesheet();
		$items  = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$items[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => ( $slug === $active ),
			);
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'total'  => count( $items ),
				'themes' => $items,
			),
			200
		);
	}

	/**
	 * POST /diagnostics/logs — reads the last lines of WordPress's debug.log.
	 *
	 * JSON body: { lines? }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_logs( $request ) {
		$params = IAWM_Support::json_params( $request );
		$lines  = isset( $params['lines'] ) ? max( 1, min( 1000, (int) $params['lines'] ) ) : 100;

		$path          = self::debug_log_path();
		$php_error_log = ini_get( 'error_log' );

		if ( '' === $path || ! file_exists( $path ) ) {
			return new WP_REST_Response(
				array(
					'ok'            => true,
					'exists'        => false,
					'wp_debug_log'  => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
					'php_error_log' => $php_error_log ? $php_error_log : null,
					'hint'          => 'No debug.log file. Enabling WP_DEBUG_LOG allows logging WordPress errors.',
				),
				200
			);
		}

		$tail = self::tail( $path, $lines );

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'exists'         => true,
				'path'           => $path,
				'size_bytes'     => (int) filesize( $path ),
				'lines_returned' => count( $tail ),
				'lines'          => $tail,
			),
			200
		);
	}

	/**
	 * POST /diagnostics/smoke — operational health check after a
	 * destructive operation.
	 *
	 * Runs a series of cheap probes and reports per-probe status:
	 *  - http_home: GET the site front page (follows redirects, status code).
	 *  - fatal_errors: scan debug.log for recent fatal PHP errors.
	 *  - agent_user: dedicated agent user exists with the right role.
	 *  - kill_switch: current state.
	 *  - divi: Divi 5 is active and its version constant is exposed.
	 *  - plugin_versions: IAWM + a quick summary of active plugins.
	 *
	 * The aggregate `healthy` flag is true iff every probe is OK.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_smoke( $request ) {
		unset( $request );

		$checks = array();

		// http_home — touch the front page.
		$home   = get_home_url();
		$resp   = wp_remote_get(
			$home,
			array(
				'timeout'     => 8,
				'sslverify'   => false, // local LocalWP installs use self-signed certs.
				'redirection' => 3,
			)
		);
		if ( is_wp_error( $resp ) ) {
			$checks['http_home'] = array(
				'ok'      => false,
				'level'   => 'error',
				'message' => $resp->get_error_message(),
				'url'     => $home,
			);
		} else {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$checks['http_home'] = array(
				'ok'      => $code >= 200 && $code < 400,
				'level'   => ( $code >= 200 && $code < 400 ) ? 'ok' : 'error',
				'status'  => $code,
				'url'     => $home,
			);
		}

		// fatal_errors — last 200 lines of debug.log scanned for "Fatal error".
		$log_path = self::debug_log_path();
		$fatal_lines = array();
		if ( file_exists( $log_path ) ) {
			$tail = self::tail( $log_path, 200 );
			foreach ( $tail as $line ) {
				if ( false !== stripos( $line, 'Fatal error' ) || false !== stripos( $line, 'PHP Fatal' ) ) {
					$fatal_lines[] = $line;
				}
			}
		}
		$checks['fatal_errors'] = array(
			'ok'      => empty( $fatal_lines ),
			'level'   => empty( $fatal_lines ) ? 'ok' : 'warn',
			'count'   => count( $fatal_lines ),
			'samples' => array_slice( $fatal_lines, -5 ),
		);

		// agent_user — exists + has the right role.
		$agent_ok  = false;
		$agent_msg = 'IAWM_Agent_User class missing.';
		if ( class_exists( 'IAWM_Agent_User' ) ) {
			$id = IAWM_Agent_User::get_user_id();
			$u  = $id > 0 ? get_userdata( $id ) : null;
			if ( $u instanceof WP_User && in_array( IAWM_Agent_User::ROLE_KEY, (array) $u->roles, true ) ) {
				$agent_ok  = true;
				$agent_msg = 'OK';
			} else {
				$agent_msg = 'Agent user missing or role mismatched. Re-run the install.';
			}
		}
		$checks['agent_user'] = array(
			'ok'      => $agent_ok,
			'level'   => $agent_ok ? 'ok' : 'error',
			'message' => $agent_msg,
		);

		// kill_switch.
		$kill = IAWM_Settings::is_kill_switch_on();
		$checks['kill_switch'] = array(
			'ok'      => true, // informational only.
			'level'   => $kill ? 'warn' : 'ok',
			'message' => $kill ? 'Kill switch ON — writes blocked.' : 'Kill switch off — writes allowed.',
		);

		// Divi 5 active.
		$divi_active = defined( 'ET_BUILDER_VERSION' );
		$divi_ver    = $divi_active ? ET_BUILDER_VERSION : null;
		$checks['divi'] = array(
			'ok'      => $divi_active,
			'level'   => $divi_active ? 'ok' : 'warn',
			'version' => $divi_ver,
		);

		// Plugin versions summary.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins        = get_plugins();
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$checks['plugin_versions'] = array(
			'ok'             => true,
			'level'          => 'ok',
			'iawm_version'   => defined( 'IAWM_VERSION' ) ? IAWM_VERSION : null,
			'active_count'   => count( $active_plugins ),
			'total_installed' => count( $plugins ),
		);

		$healthy = true;
		foreach ( $checks as $c ) {
			if ( 'error' === ( $c['level'] ?? 'ok' ) || empty( $c['ok'] ) ) {
				$healthy = false;
				break;
			}
		}

		$smoke_result = array(
			'ok'      => true,
			'healthy' => $healthy,
			'checks'  => $checks,
			'time'    => current_time( 'c' ),
		);

		// Phase 9.4 — surface an unhealthy smoke run to subscribed
		// webhooks. The hook call is wrapped in class_exists so the
		// module degrades gracefully if the webhook module is absent
		// (e.g. legacy install that has not yet run maybe_upgrade).
		if ( ! $healthy && class_exists( 'IAWM_Webhook' ) ) {
			IAWM_Webhook::fire( 'smoke.failed', $smoke_result );
		}

		return new WP_REST_Response( $smoke_result, 200 );
	}

	/**
	 * POST /diagnostics/check-self — verifies the plugin's own
	 * installation invariants. Use this after a plugin upgrade to
	 * confirm everything that should be in place is in place.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_check_self( $request ) {
		unset( $request );

		global $wpdb;

		$checks = array();

		// Agent user.
		$agent_ok = false;
		if ( class_exists( 'IAWM_Agent_User' ) ) {
			$id = IAWM_Agent_User::get_user_id();
			$u  = $id > 0 ? get_userdata( $id ) : null;
			$agent_ok = $u instanceof WP_User && in_array( IAWM_Agent_User::ROLE_KEY, (array) $u->roles, true );
		}
		$checks['agent_user'] = array(
			'level'  => $agent_ok ? 'ok' : 'error',
			'detail' => $agent_ok ? 'iawm-agent present with iawm_agent role.' : 'Missing — reinstall from admin.',
		);

		// Tables.
		$audit_tbl    = $wpdb->prefix . 'iawm_audit_log';
		$backup_tbl   = $wpdb->prefix . 'iawm_backups';
		$has_audit    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_tbl ) ) === $audit_tbl;
		$has_backup   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $backup_tbl ) ) === $backup_tbl;
		$checks['audit_table']  = array(
			'level'  => $has_audit ? 'ok' : 'error',
			'detail' => $has_audit ? $audit_tbl . ' present.' : 'Table missing.',
		);
		$checks['backup_table'] = array(
			'level'  => $has_backup ? 'ok' : 'error',
			'detail' => $has_backup ? $backup_tbl . ' present.' : 'Table missing.',
		);

		// At least one key.
		$has_keys = IAWM_Settings::has_credentials();
		$checks['credentials'] = array(
			'level'  => $has_keys ? 'ok' : 'warn',
			'detail' => $has_keys ? 'At least one API key configured.' : 'No API key — the adapter cannot be reached.',
		);

		// Cron rotation jobs.
		$audit_cron  = (bool) wp_next_scheduled( IAWM_Audit::PRUNE_HOOK );
		$backup_cron = (bool) wp_next_scheduled( IAWM_Backup::PRUNE_HOOK );
		$checks['audit_rotation_cron']  = array(
			'level'  => $audit_cron ? 'ok' : 'warn',
			'detail' => $audit_cron ? 'Daily prune scheduled.' : 'Not scheduled.',
		);
		$checks['backup_rotation_cron'] = array(
			'level'  => $backup_cron ? 'ok' : 'warn',
			'detail' => $backup_cron ? 'Daily prune scheduled.' : 'Not scheduled.',
		);

		// HTTPS state — informational.
		$https_required = defined( 'IAWM_REQUIRE_HTTPS' ) && IAWM_REQUIRE_HTTPS;
		$https_active   = class_exists( 'IAWM_Network' ) && IAWM_Network::is_https();
		$checks['https'] = array(
			'level'  => ( ! $https_required || $https_active ) ? 'ok' : 'warn',
			'detail' => sprintf(
				'IAWM_REQUIRE_HTTPS=%s; current request is HTTPS=%s.',
				$https_required ? 'true' : 'false',
				$https_active ? 'true' : 'false'
			),
		);

		// Aggregate.
		$any_error = false;
		$any_warn  = false;
		foreach ( $checks as $c ) {
			if ( 'error' === $c['level'] ) {
				$any_error = true;
			} elseif ( 'warn' === $c['level'] ) {
				$any_warn = true;
			}
		}
		$verdict = $any_error ? 'error' : ( $any_warn ? 'warn' : 'ok' );

		return new WP_REST_Response(
			array(
				'ok'      => ! $any_error,
				'verdict' => $verdict,
				'checks'  => $checks,
			),
			200
		);
	}

	/**
	 * Determines the path to WordPress's debug.log.
	 *
	 * @return string
	 */
	private static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}

		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Returns the last lines of a file (reading from the end only).
	 *
	 * @param string $path  File path.
	 * @param int    $lines Desired number of lines.
	 * @return array
	 */
	private static function tail( $path, $lines ) {
		$size = (int) filesize( $path );
		$read = min( $size, self::LOG_TAIL_BYTES );

		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return array();
		}
		if ( $read > 0 ) {
			fseek( $handle, -$read, SEEK_END );
		}
		$data = (string) fread( $handle, $read );
		fclose( $handle );

		$all = explode( "\n", str_replace( "\r\n", "\n", $data ) );
		$all = array_values(
			array_filter(
				$all,
				static function ( $line ) {
					return '' !== trim( $line );
				}
			)
		);

		return array_slice( $all, -$lines );
	}
}
