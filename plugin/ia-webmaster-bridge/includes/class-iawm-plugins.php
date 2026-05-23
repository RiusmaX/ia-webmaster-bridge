<?php
/**
 * Plan infrastructure — gestion des extensions WordPress.
 *
 * Permet à l'IA d'installer, activer et désactiver des plugins depuis le
 * dépôt WordPress.org. La suppression n'est volontairement PAS exposée tant
 * que la phase de sauvegardes (Phase 4) n'est pas en place.
 *
 * Garde-fous principaux :
 *  - On ne peut JAMAIS désactiver le plugin IA Webmaster Bridge lui-même
 *    (sinon l'IA se couperait l'accès au site).
 *  - Le slug est validé (regex stricte) pour éviter toute injection.
 *  - L'installation passe exclusivement par l'API officielle WordPress.org
 *    (plugins_api). Pas d'URL arbitraire pour l'instant.
 *  - Toute opération est journalisée par IAWM_Audit.
 *
 * Routes (toutes en POST, corps JSON) :
 *  - /plugins/install   — install_plugin( slug, activate=false )
 *  - /plugins/activate  — activate_plugin( file )
 *  - /plugins/deactivate— deactivate_plugin( file )
 *  - /plugins/info      — info_plugin( slug )
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes de gestion des plugins WordPress.
 */
class IAWM_Plugins {

	/** Slug du plugin lui-même (jamais désactivable via l'API). */
	const SELF_PLUGIN_FILE = 'ia-webmaster-bridge/ia-webmaster-bridge.php';

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes plugins.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/plugins/info'       => array( 'handle_info', 'guard_read' ),
			'/plugins/install'    => array( 'handle_install', 'guard_write' ),
			'/plugins/activate'   => array( 'handle_activate', 'guard_write' ),
			'/plugins/deactivate' => array( 'handle_deactivate', 'guard_write' ),
		);

		foreach ( $routes as $path => $config ) {
			register_rest_route(
				IAWM_REST_NAMESPACE,
				$path,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, $config[0] ),
					'permission_callback' => array( 'IAWM_Auth', $config[1] ),
				)
			);
		}
	}

	/**
	 * Valide un slug de plugin (lettres minuscules, chiffres, tirets).
	 *
	 * @param string $slug Slug à valider.
	 * @return bool
	 */
	protected static function is_valid_slug( $slug ) {
		return is_string( $slug ) && preg_match( '/^[a-z0-9][a-z0-9-]{1,62}$/', $slug ) === 1;
	}

	/**
	 * Vérifie qu'un fichier-plugin (`dir/file.php`) est plausible et présent.
	 *
	 * @param string $file Chemin relatif au répertoire des plugins.
	 * @return bool
	 */
	protected static function is_valid_file( $file ) {
		if ( ! is_string( $file ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]*\/[a-z0-9_-]+\.php$/i', $file ) ) {
			return false;
		}
		return file_exists( WP_PLUGIN_DIR . '/' . $file );
	}

	/**
	 * POST /plugins/info — récupère les métadonnées d'un plugin WP.org.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_info( $request ) {
		$params = IAWM_Support::json_params( $request );
		$slug   = isset( $params['slug'] ) ? (string) $params['slug'] : '';

		if ( ! self::is_valid_slug( $slug ) ) {
			return IAWM_Support::rest_error( 'invalid_slug', 'Slug invalide.', 400 );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'short_description' => true,
					'sections'          => false,
					'requires'          => true,
					'rating'            => true,
					'ratings'           => false,
					'downloaded'        => true,
					'last_updated'      => true,
					'homepage'          => true,
					'tags'              => true,
					'donate_link'       => false,
				),
			)
		);

		if ( is_wp_error( $info ) ) {
			return IAWM_Support::rest_error( 'plugin_info_failed', $info->get_error_message(), 404 );
		}

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'info' => array(
					'name'              => $info->name,
					'slug'              => $info->slug,
					'version'           => $info->version,
					'author'            => isset( $info->author ) ? wp_strip_all_tags( $info->author ) : null,
					'homepage'          => isset( $info->homepage ) ? $info->homepage : null,
					'requires'          => isset( $info->requires ) ? $info->requires : null,
					'requires_php'      => isset( $info->requires_php ) ? $info->requires_php : null,
					'tested'            => isset( $info->tested ) ? $info->tested : null,
					'rating'            => isset( $info->rating ) ? $info->rating : null,
					'downloaded'        => isset( $info->downloaded ) ? $info->downloaded : null,
					'last_updated'      => isset( $info->last_updated ) ? $info->last_updated : null,
					'short_description' => isset( $info->short_description ) ? $info->short_description : null,
					'download_link'     => isset( $info->download_link ) ? $info->download_link : null,
				),
			),
			200
		);
	}

	/**
	 * POST /plugins/install — installe (et optionnellement active) un plugin
	 * depuis le dépôt WordPress.org.
	 *
	 * Paramètres :
	 *   - slug (string, requis) — slug WP.org (ex. "rank-math-seo").
	 *   - activate (bool, défaut false) — activer après install.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_install( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$slug     = isset( $params['slug'] ) ? (string) $params['slug'] : '';
		$activate = ! empty( $params['activate'] );

		if ( ! self::is_valid_slug( $slug ) ) {
			return IAWM_Support::rest_error( 'invalid_slug', 'Slug invalide.', 400 );
		}

		// Chargement des dépendances WP admin.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		IAWM_Support::act_as_agent();

		// Récupération des métadonnées (download_link signé) via plugins_api.
		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $info ) ) {
			return IAWM_Support::rest_error( 'plugin_not_found', $info->get_error_message(), 404 );
		}

		if ( empty( $info->download_link ) ) {
			return IAWM_Support::rest_error( 'no_download_link', 'Pas de lien de téléchargement.', 502 );
		}

		// Vérifier si le plugin est déjà installé (par slug → on cherche un fichier dans WP_PLUGIN_DIR/{slug}/*).
		$existing = self::find_plugin_file_by_slug( $slug );
		$result   = array(
			'ok'         => true,
			'slug'       => $slug,
			'version'    => $info->version,
			'name'       => $info->name,
			'installed'  => false,
			'already'    => false,
			'activated'  => false,
			'file'       => $existing,
		);

		if ( $existing ) {
			$result['already'] = true;
		} else {
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$res      = $upgrader->install( $info->download_link );

			if ( is_wp_error( $res ) ) {
				return IAWM_Support::rest_error( 'install_failed', $res->get_error_message(), 500 );
			}
			if ( false === $res ) {
				$messages = $skin->get_error_messages();
				return IAWM_Support::rest_error( 'install_failed', $messages ? implode( ' ; ', $messages ) : 'Installation échouée.', 500 );
			}

			$result['installed'] = true;
			$result['file']      = $upgrader->plugin_info();
			if ( empty( $result['file'] ) ) {
				$result['file'] = self::find_plugin_file_by_slug( $slug );
			}
		}

		// Activation optionnelle.
		if ( $activate && ! empty( $result['file'] ) ) {
			if ( ! is_plugin_active( $result['file'] ) ) {
				$act = activate_plugin( $result['file'], '', false, true );
				if ( is_wp_error( $act ) ) {
					return IAWM_Support::rest_error( 'activation_failed', $act->get_error_message(), 500, $result );
				}
				$result['activated'] = true;
			} else {
				$result['activated'] = true;
			}
		}

		return new WP_REST_Response( $result, $result['installed'] ? 201 : 200 );
	}

	/**
	 * POST /plugins/activate — active un plugin déjà installé.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_activate( $request ) {
		$params = IAWM_Support::json_params( $request );
		$file   = isset( $params['file'] ) ? (string) $params['file'] : '';

		if ( ! self::is_valid_file( $file ) ) {
			return IAWM_Support::rest_error( 'invalid_file', 'Fichier-plugin invalide ou introuvable.', 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		IAWM_Support::act_as_agent();

		if ( is_plugin_active( $file ) ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'file' => $file, 'already_active' => true ),
				200
			);
		}

		$res = activate_plugin( $file, '', false, true );
		if ( is_wp_error( $res ) ) {
			return IAWM_Support::rest_error( 'activation_failed', $res->get_error_message(), 500 );
		}

		return new WP_REST_Response(
			array( 'ok' => true, 'file' => $file, 'activated' => true ),
			200
		);
	}

	/**
	 * POST /plugins/deactivate — désactive un plugin.
	 *
	 * Refuse explicitement la désactivation du plugin IA Webmaster Bridge.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_deactivate( $request ) {
		$params = IAWM_Support::json_params( $request );
		$file   = isset( $params['file'] ) ? (string) $params['file'] : '';

		if ( ! self::is_valid_file( $file ) ) {
			return IAWM_Support::rest_error( 'invalid_file', 'Fichier-plugin invalide ou introuvable.', 400 );
		}

		if ( $file === self::SELF_PLUGIN_FILE ) {
			return IAWM_Support::rest_error(
				'cannot_disable_self',
				'Le plugin IA Webmaster Bridge ne peut pas être désactivé via l\'API.',
				403
			);
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		IAWM_Support::act_as_agent();

		if ( ! is_plugin_active( $file ) ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'file' => $file, 'already_inactive' => true ),
				200
			);
		}

		deactivate_plugins( $file );

		return new WP_REST_Response(
			array( 'ok' => true, 'file' => $file, 'deactivated' => true ),
			200
		);
	}

	/**
	 * Cherche un fichier-plugin par slug (i.e. par nom de répertoire).
	 *
	 * @param string $slug Slug.
	 * @return string|null Fichier-plugin (ex. "rank-math-seo/rank-math.php") ou null.
	 */
	protected static function find_plugin_file_by_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( $all as $file => $data ) {
			$dir = dirname( $file );
			if ( $dir === $slug ) {
				return $file;
			}
		}
		return null;
	}
}
