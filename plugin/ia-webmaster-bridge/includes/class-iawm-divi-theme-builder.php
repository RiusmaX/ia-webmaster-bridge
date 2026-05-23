<?php
/**
 * Theme Builder Divi 5 — création de templates (headers, footers, body)
 * dynamiques applicables au site.
 *
 * Modèle de données Divi 5 :
 *  - 1 post `et_theme_builder` (conteneur global) avec meta `_et_template` qui
 *    pointe vers le post `et_template`.
 *  - N posts `et_template` chacun avec :
 *      - meta `_et_default` (1 = template par défaut du site)
 *      - meta `_et_enabled`
 *      - meta `_et_header_layout_id` → post `et_header_layout`
 *      - meta `_et_body_layout_id`   → post `et_body_layout`
 *      - meta `_et_footer_layout_id` → post `et_footer_layout`
 *      - chaque zone : meta `_et_<zone>_layout_enabled`, `_et_<zone>_layout_global`,
 *        `_et_<zone>_layout_override`.
 *  - Chaque `et_header_layout` / `et_body_layout` / `et_footer_layout` est un
 *    post WP avec `post_content` = blocs Divi 5 (même format que les pages).
 *  - Assignation via `use_on` et `exclude_from` (gérée par la route REST
 *    `divi/v1/outside-vb/theme-builder/assign-template`).
 *
 * Routes exposées :
 *  - /divi/theme-builder/list                  — proxy + détails layouts
 *  - /divi/theme-builder/template/create       — proxy create-template
 *  - /divi/theme-builder/template/update       — proxy update-template
 *  - /divi/theme-builder/template/delete       — proxy delete-template
 *  - /divi/theme-builder/template/assign       — proxy assign-template
 *  - /divi/theme-builder/layout/create         — crée un et_*_layout avec
 *                                                contenu Divi 5 + retourne l'id.
 *  - /divi/theme-builder/layout/read           — lit un et_*_layout en structure
 *                                                Divi (réutilise IAWM_Divi).
 *  - /divi/theme-builder/setup-site-defaults   — wrapper haut-niveau qui crée
 *                                                en une fois le conteneur +
 *                                                template par défaut + header
 *                                                + body + footer.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes Theme Builder Divi.
 */
class IAWM_Divi_Theme_Builder {

	const ZONES = array( 'header', 'body', 'footer' );

	/** Mapping zone → post_type. */
	const ZONE_POST_TYPE = array(
		'header' => 'et_header_layout',
		'body'   => 'et_body_layout',
		'footer' => 'et_footer_layout',
	);

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/divi/theme-builder/list'                => array( 'handle_list', 'guard_read' ),
			'/divi/theme-builder/template/create'     => array( 'handle_template_create', 'guard_write' ),
			'/divi/theme-builder/template/update'     => array( 'handle_template_update', 'guard_write' ),
			'/divi/theme-builder/template/delete'     => array( 'handle_template_delete', 'guard_write' ),
			'/divi/theme-builder/template/assign'     => array( 'handle_template_assign', 'guard_write' ),
			'/divi/theme-builder/layout/create'       => array( 'handle_layout_create', 'guard_write' ),
			'/divi/theme-builder/layout/read'         => array( 'handle_layout_read', 'guard_read' ),
			'/divi/theme-builder/setup-site-defaults' => array( 'handle_setup_site_defaults', 'guard_write' ),
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
	 * Appel interne d'une route divi/v1 protégée par nonce.
	 *
	 * @param string $route  Route relative (ex. "/outside-vb/theme-builder/list-templates").
	 * @param array  $body   Corps JSON.
	 * @return array { status, data }
	 */
	protected static function call_divi( $route, $body = array() ) {
		IAWM_Support::act_as_agent();

		$full_route = '/divi/v1/' . ltrim( $route, '/' );
		$nonce      = wp_create_nonce( $full_route . '--POST' );

		$req = new WP_REST_Request( 'POST', $full_route );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_header( 'X-ET-Nonce', $nonce );
		$req->set_body( wp_json_encode( empty( $body ) ? new stdClass() : $body ) );

		$response = rest_do_request( $req );
		return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
	}

	/**
	 * POST /divi/theme-builder/list — liste les templates du site avec
	 * détails sur les layouts assignés.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );
		$live   = isset( $params['live'] ) ? (bool) $params['live'] : true;

		$res = self::call_divi( '/outside-vb/theme-builder/list-templates', array( 'live' => $live ) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'theme_builder_list_failed', 'Échec liste templates.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		// Enrichir : pour chaque template, ajouter les titres des layouts liés.
		$data = $res['data'];
		if ( isset( $data['templates'] ) && is_array( $data['templates'] ) ) {
			foreach ( $data['templates'] as &$tmpl ) {
				if ( ! isset( $tmpl['layouts'] ) || ! is_array( $tmpl['layouts'] ) ) {
					continue;
				}
				foreach ( $tmpl['layouts'] as $zone => &$layout ) {
					$id = isset( $layout['id'] ) ? (int) $layout['id'] : 0;
					if ( $id > 0 ) {
						$post = get_post( $id );
						$layout['title']   = $post ? $post->post_title : null;
						$layout['has_content'] = $post ? strlen( $post->post_content ) > 0 : false;
					}
				}
				unset( $layout );
			}
			unset( $tmpl );
		}

		return new WP_REST_Response(
			array_merge( array( 'ok' => true ), (array) $data ),
			200
		);
	}

	/**
	 * POST /divi/theme-builder/template/create — crée un template.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_template_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$title  = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : 'New Template';
		$live   = isset( $params['live'] ) ? (bool) $params['live'] : true;

		$res = self::call_divi( '/outside-vb/theme-builder/create-template', array(
			'live'  => $live,
			'title' => $title,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_create_failed', 'Échec création template.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/template/update — met à jour un template
	 * (titre, layouts assignés, état).
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_template_update( $request ) {
		$params      = IAWM_Support::json_params( $request );
		$template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$live        = isset( $params['live'] ) ? (bool) $params['live'] : true;
		$template    = isset( $params['template'] ) && is_array( $params['template'] ) ? $params['template'] : array();

		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_template_id', 'template_id requis.', 400 );
		}

		$res = self::call_divi( '/outside-vb/theme-builder/update-template', array(
			'live'        => $live,
			'template_id' => $template_id,
			'template'    => $template,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_update_failed', 'Échec update template.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/template/delete — supprime un template.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_template_delete( $request ) {
		$params      = IAWM_Support::json_params( $request );
		$template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$live        = isset( $params['live'] ) ? (bool) $params['live'] : true;

		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_template_id', 'template_id requis.', 400 );
		}

		$res = self::call_divi( '/outside-vb/theme-builder/delete-template', array(
			'live'        => $live,
			'template_id' => $template_id,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_delete_failed', 'Échec suppression.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/template/assign — assigne un template à des
	 * conditions (use_on) et exceptions (exclude_from).
	 *
	 * Exemples de conditions Divi :
	 *  - "default"          → template par défaut du site (tout)
	 *  - "singular:page"    → toutes les pages
	 *  - "singular:post"    → tous les articles
	 *  - "page:123"         → la page d'id 123
	 *  - "archive:category" → toutes les pages de catégorie
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_template_assign( $request ) {
		$params       = IAWM_Support::json_params( $request );
		$template_id  = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$live         = isset( $params['live'] ) ? (bool) $params['live'] : true;
		$use_on       = isset( $params['use_on'] ) && is_array( $params['use_on'] ) ? $params['use_on'] : array();
		$exclude_from = isset( $params['exclude_from'] ) && is_array( $params['exclude_from'] ) ? $params['exclude_from'] : array();

		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_template_id', 'template_id requis.', 400 );
		}

		$res = self::call_divi( '/outside-vb/theme-builder/assign-template', array(
			'live'         => $live,
			'template_id'  => $template_id,
			'use_on'       => $use_on,
			'exclude_from' => $exclude_from,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_assign_failed', 'Échec assignation.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/layout/create — crée un layout physique
	 * (et_header_layout / et_body_layout / et_footer_layout) avec son
	 * contenu Divi 5.
	 *
	 * Paramètres :
	 *   - zone (string, requis) : "header" | "body" | "footer".
	 *   - title (string, optionnel) : titre du layout.
	 *   - content (string, optionnel) : post_content brut (blocs Divi 5).
	 *   - blocks (array, optionnel) : alternative au content, tableau parse_blocks.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_layout_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$zone   = isset( $params['zone'] ) ? (string) $params['zone'] : '';
		$title  = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '';

		if ( ! in_array( $zone, self::ZONES, true ) ) {
			return IAWM_Support::rest_error( 'invalid_zone', 'zone doit être header, body ou footer.', 400 );
		}

		$post_type = self::ZONE_POST_TYPE[ $zone ];

		// Contenu : soit "content" (chaîne), soit "blocks" (array parse_blocks).
		$content = '';
		if ( isset( $params['content'] ) && is_string( $params['content'] ) ) {
			$content = $params['content'];
		} elseif ( isset( $params['blocks'] ) && is_array( $params['blocks'] ) ) {
			$blocks  = $params['blocks'];
			$content = serialize_blocks( $blocks );
		}

		IAWM_Support::act_as_agent();

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => '' !== $title ? $title : ucfirst( $zone ) . ' Layout',
				'post_content' => wp_slash( $content ),
				'post_status'  => 'publish',
				'post_author'  => IAWM_Support::acting_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return IAWM_Support::rest_error( 'layout_create_failed', $post_id->get_error_message(), 500 );
		}

		// Meta pour signaler que c'est un layout Divi 5.
		update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		update_post_meta( $post_id, '_et_pb_built_for_post_type', $post_type );

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'id'        => $post_id,
				'zone'      => $zone,
				'post_type' => $post_type,
				'title'     => get_the_title( $post_id ),
			),
			201
		);
	}

	/**
	 * POST /divi/theme-builder/layout/read — lit le contenu d'un layout
	 * (et_header_layout / et_body_layout / et_footer_layout) en structure
	 * Divi.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_layout_read( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$mode   = isset( $params['mode'] ) ? (string) $params['mode'] : 'tree';

		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', 'post_id requis.', 400 );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return IAWM_Support::rest_error( 'not_found', "Layout {$id} introuvable.", 404 );
		}
		if ( ! in_array( $post->post_type, self::ZONE_POST_TYPE, true ) ) {
			return IAWM_Support::rest_error(
				'not_a_layout',
				"Le post {$id} n'est pas un layout Theme Builder (type={$post->post_type}).",
				400
			);
		}

		// Réutiliser IAWM_Divi::handle_page_read en interne.
		$req = new WP_REST_Request( 'POST', '/' . IAWM_REST_NAMESPACE . '/divi/page/read' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'post_id' => $id, 'mode' => $mode ) ) );

		return IAWM_Divi::handle_page_read( $req );
	}

	/**
	 * POST /divi/theme-builder/setup-site-defaults — wrapper haut niveau :
	 * crée le conteneur Theme Builder + un template par défaut avec header,
	 * body et footer (selon ce qui est fourni), assigne le template comme
	 * default du site.
	 *
	 * Paramètres :
	 *   - title (string, optionnel) : titre du template (défaut : "Default Site Template").
	 *   - header (object, optionnel) : { title?, content?, blocks? }.
	 *   - body   (object, optionnel) : { title?, content?, blocks? }.
	 *   - footer (object, optionnel) : { title?, content?, blocks? }.
	 *   - assign_default (bool, défaut true) : si true, le template devient
	 *     le default du site (s'applique à tous les posts/pages sans override).
	 *
	 * Si un template default existe déjà, refuse l'opération (pour éviter
	 * d'écraser). L'utilisateur doit le supprimer manuellement ou passer
	 * `replace_existing=true`.
	 *
	 * @param WP_REST_Request $request Requête.
	 * @return WP_REST_Response
	 */
	public static function handle_setup_site_defaults( $request ) {
		$params        = IAWM_Support::json_params( $request );
		$title         = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : 'Default Site Template';
		$assign_default = ! isset( $params['assign_default'] ) || (bool) $params['assign_default'];
		$replace        = ! empty( $params['replace_existing'] );

		// Récupérer les payloads par zone (header/body/footer).
		$zone_inputs = array();
		foreach ( self::ZONES as $zone ) {
			if ( isset( $params[ $zone ] ) && is_array( $params[ $zone ] ) ) {
				$zone_inputs[ $zone ] = $params[ $zone ];
			}
		}

		if ( empty( $zone_inputs ) ) {
			return IAWM_Support::rest_error(
				'no_layouts',
				'Au moins un parmi header / body / footer doit être fourni.',
				400
			);
		}

		// Vérifier l'existence d'un template default.
		$existing = self::call_divi( '/outside-vb/theme-builder/list-templates', array( 'live' => true ) );
		$tmpls    = isset( $existing['data']['templates'] ) ? $existing['data']['templates'] : array();
		foreach ( $tmpls as $t ) {
			if ( ! empty( $t['default'] ) && ! $replace ) {
				return IAWM_Support::rest_error(
					'default_template_exists',
					"Un template par défaut existe déjà (id={$t['id']}). Passe replace_existing=true pour écraser.",
					409,
					array( 'existing_template' => $t )
				);
			}
		}

		IAWM_Support::act_as_agent();

		// 1. Créer les layouts physiques.
		$layouts = array();
		foreach ( $zone_inputs as $zone => $layout_input ) {
			$content      = '';
			if ( isset( $layout_input['content'] ) && is_string( $layout_input['content'] ) ) {
				$content = $layout_input['content'];
			} elseif ( isset( $layout_input['blocks'] ) && is_array( $layout_input['blocks'] ) ) {
				$content = serialize_blocks( $layout_input['blocks'] );
			}
			$layout_title = isset( $layout_input['title'] ) ? sanitize_text_field( (string) $layout_input['title'] ) : ucfirst( $zone ) . ' — ' . $title;

			$post_id = wp_insert_post(
				array(
					'post_type'    => self::ZONE_POST_TYPE[ $zone ],
					'post_title'   => $layout_title,
					'post_content' => wp_slash( $content ),
					'post_status'  => 'publish',
					'post_author'  => IAWM_Support::acting_user_id(),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return IAWM_Support::rest_error( 'layout_create_failed', "Échec création {$zone} : " . $post_id->get_error_message(), 500 );
			}
			update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
			update_post_meta( $post_id, '_et_pb_built_for_post_type', self::ZONE_POST_TYPE[ $zone ] );
			$layouts[ $zone ] = $post_id;
		}

		// 2. Créer le template via la route Divi.
		$create_res = self::call_divi( '/outside-vb/theme-builder/create-template', array(
			'live'  => true,
			'title' => $title,
		) );
		if ( $create_res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_create_failed', 'Échec création template.', $create_res['status'], array( 'divi_response' => $create_res['data'] ) );
		}
		$template_id = isset( $create_res['data']['id'] ) ? (int) $create_res['data']['id'] : 0;
		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'no_template_id', 'Pas d\'id de template retourné.', 500, array( 'divi_response' => $create_res['data'] ) );
		}

		// 2.5. Si pas de body fourni, créer un body minimal avec un module
		// post-content qui rendra le contenu de la page courante. Sinon,
		// Divi affiche un blanc entre header et footer (il attend qu'on lui
		// dise comment composer le body).
		if ( ! isset( $layouts['body'] ) ) {
			// Construit un layout body minimal à partir des blocs structurés
			// pour éviter les pièges d'échappement dans les chaînes longues.
			$body_blocks = array(
				array(
					'blockName'    => 'divi/placeholder',
					'attrs'        => array( 'builderVersion' => '5.5.2' ),
					'innerBlocks'  => array(
						array(
							'blockName'    => 'divi/section',
							'attrs'        => array( 'builderVersion' => '5.5.2' ),
							'innerBlocks'  => array(
								array(
									'blockName'    => 'divi/row',
									'attrs'        => array(
										'module' => array(
											'advanced' => array(
												'columnStructure'     => array( 'desktop' => array( 'value' => '4_4' ) ),
												'flexColumnStructure' => array( 'desktop' => array( 'value' => 'equal-columns_1' ) ),
											),
											'decoration' => array(
												'layout' => array( 'desktop' => array( 'value' => array( 'flexWrap' => 'nowrap' ) ) ),
											),
										),
										'builderVersion' => '5.5.2',
									),
									'innerBlocks'  => array(
										array(
											'blockName'    => 'divi/column',
											'attrs'        => array(
												'module' => array(
													'advanced'   => array( 'type' => array( 'desktop' => array( 'value' => '4_4' ) ) ),
													'decoration' => array( 'sizing' => array( 'desktop' => array( 'value' => array( 'flexType' => '24_24' ) ) ) ),
												),
												'builderVersion' => '5.5.2',
											),
											'innerBlocks'  => array(
												array(
													'blockName'    => 'divi/post-content',
													'attrs'        => array( 'builderVersion' => '5.5.2' ),
													'innerBlocks'  => array(),
													'innerHTML'    => '',
													'innerContent' => array( null ),
												),
											),
											'innerHTML'    => '',
											'innerContent' => array( null, null ),
										),
									),
									'innerHTML'    => '',
									'innerContent' => array( null, null ),
								),
							),
							'innerHTML'    => '',
							'innerContent' => array( null, null ),
						),
					),
					'innerHTML'    => '',
					'innerContent' => array( null, null ),
				),
			);
			$post_content_block = serialize_blocks( $body_blocks );

			$body_id = wp_insert_post(
				array(
					'post_type'    => self::ZONE_POST_TYPE['body'],
					'post_title'   => 'Body (Default) — ' . $title,
					'post_content' => wp_slash( $post_content_block ),
					'post_status'  => 'publish',
					'post_author'  => IAWM_Support::acting_user_id(),
				),
				true
			);
			if ( ! is_wp_error( $body_id ) ) {
				update_post_meta( $body_id, '_et_pb_use_builder', 'on' );
				update_post_meta( $body_id, '_et_pb_built_for_post_type', self::ZONE_POST_TYPE['body'] );
				$layouts['body'] = $body_id;
			}
		}

		// 3. Lier les layouts via update-template.
		$template_payload = array(
			'id'      => $template_id,
			'title'   => $title,
			'enabled' => true,
			'default' => $assign_default,
			'layouts' => array(),
		);
		foreach ( array( 'header', 'body', 'footer' ) as $zone ) {
			$template_payload['layouts'][ $zone ] = array(
				'id'       => isset( $layouts[ $zone ] ) ? $layouts[ $zone ] : 0,
				'enabled'  => isset( $layouts[ $zone ] ),
				'override' => false,
				'global'   => false,
			);
		}

		$update_res = self::call_divi( '/outside-vb/theme-builder/update-template', array(
			'live'        => true,
			'template_id' => $template_id,
			'template'    => $template_payload,
		) );
		if ( $update_res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_link_failed', 'Échec assignation des layouts.', $update_res['status'], array( 'divi_response' => $update_res['data'] ) );
		}

		// 4. Si demandé, le marquer comme default (assignation use_on).
		if ( $assign_default ) {
			// On met directement la meta _et_default=1 ; et on appelle assign-template
			// avec use_on vide (le default capture tout ce qui n'est pas explicitement assigné).
			update_post_meta( $template_id, '_et_default', '1' );
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'template_id'  => $template_id,
				'layouts'      => $layouts,
				'assigned_default' => $assign_default,
				'divi_response' => $update_res['data'],
			),
			201
		);
	}
}
