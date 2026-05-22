<?php
/**
 * Enregistrement des routes REST de l'adaptateur.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes du namespace ia-webmaster/v1.
 */
class IAWM_REST {

	/**
	 * Branche l'enregistrement des routes sur rest_api_init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre toutes les routes du namespace.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// /ping — diagnostic public, ne renvoie aucune donnée sensible.
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_ping' ),
				'permission_callback' => '__return_true',
			)
		);

		// /status — diagnostic authentifié : valide la signature HMAC.
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( 'IAWM_Auth', 'guard_read' ),
			)
		);

		// /audit — consultation du journal d'audit (authentifié).
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_audit' ),
				'permission_callback' => array( 'IAWM_Auth', 'guard_read' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /ping — confirme que l'adaptateur est joignable.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_ping() {
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'service'   => 'IA Webmaster Bridge',
				'version'   => IAWM_VERSION,
				'namespace' => IAWM_REST_NAMESPACE,
				'site_url'  => get_site_url(),
				'env'       => self::environment(),
				'time'      => current_time( 'c' ),
			),
			200
		);
	}

	/**
	 * GET /status — diagnostic réservé aux requêtes authentifiées.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_status() {
		$creds = IAWM_Settings::get_credentials();

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'authenticated' => true,
				'service'       => 'IA Webmaster Bridge',
				'version'       => IAWM_VERSION,
				'key_id'        => $creds ? $creds['key_id'] : null,
				'kill_switch'   => IAWM_Settings::is_kill_switch_on(),
				'env'           => self::environment(),
				'time'          => current_time( 'c' ),
			),
			200
		);
	}

	/**
	 * GET /audit — retourne les dernières entrées du journal d'audit.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response
	 */
	public static function handle_audit( $request ) {
		$entries = IAWM_Audit::get_recent( (int) $request->get_param( 'limit' ) );

		// Décoder le champ detail (stocké en JSON) pour la réponse.
		foreach ( $entries as &$entry ) {
			if ( isset( $entry['detail'] ) && is_string( $entry['detail'] ) ) {
				$decoded         = json_decode( $entry['detail'], true );
				$entry['detail'] = ( null === $decoded ) ? $entry['detail'] : $decoded;
			}
		}
		unset( $entry );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'count'   => count( $entries ),
				'entries' => $entries,
			),
			200
		);
	}

	/**
	 * Versions de l'environnement (WordPress, PHP, Divi).
	 *
	 * @return array
	 */
	private static function environment() {
		return array(
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'divi'      => self::detect_divi_version(),
		);
	}

	/**
	 * Tente de détecter la version de Divi.
	 *
	 * Vérifie le thème actif et son parent éventuel, puis se rabat sur la
	 * constante de version définie par Divi.
	 *
	 * @return string|null Version de Divi, ou null si non détectée.
	 */
	private static function detect_divi_version() {
		$theme = wp_get_theme();

		if ( $theme ) {
			foreach ( array( $theme, $theme->parent() ) as $candidate ) {
				if ( $candidate && 'Divi' === $candidate->get( 'Name' ) ) {
					return $candidate->get( 'Version' );
				}
			}
		}

		if ( defined( 'ET_CORE_VERSION' ) ) {
			return ET_CORE_VERSION;
		}

		return null;
	}
}
