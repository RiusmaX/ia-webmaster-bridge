<?php
/**
 * Diagnostic et accès aux logs — en lecture seule.
 *
 * Toutes les routes sont en guard_read : ce module n'effectue aucune écriture,
 * ce qui constitue son garde-fou principal. Chaque accès reste journalisé.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes de diagnostic (système, extensions, thèmes, logs).
 */
class IAWM_Diagnostics {

	/** Taille maximale lue en fin de fichier de log. */
	const LOG_TAIL_BYTES = 262144;

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes de diagnostic.
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
	 * POST /diagnostics/system — informations sur l'environnement.
	 *
	 * @param WP_REST_Request $request Requête entrante.
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
	 * POST /diagnostics/plugins — liste des extensions installées.
	 *
	 * @param WP_REST_Request $request Requête entrante.
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
	 * POST /diagnostics/themes — liste des thèmes installés.
	 *
	 * @param WP_REST_Request $request Requête entrante.
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
	 * POST /diagnostics/logs — lit les dernières lignes du debug.log WordPress.
	 *
	 * Corps JSON : { lines? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
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
					'hint'          => 'Aucun fichier debug.log. Activer WP_DEBUG_LOG permet de journaliser les erreurs WordPress.',
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
	 * Détermine le chemin du debug.log de WordPress.
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
	 * Retourne les dernières lignes d'un fichier (lecture de la fin seulement).
	 *
	 * @param string $path  Chemin du fichier.
	 * @param int    $lines Nombre de lignes souhaitées.
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
