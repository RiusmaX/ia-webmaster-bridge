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
			'/diagnostics/system'  => 'handle_system',
			'/diagnostics/plugins' => 'handle_plugins',
			'/diagnostics/themes'  => 'handle_themes',
			'/diagnostics/logs'    => 'handle_logs',
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
