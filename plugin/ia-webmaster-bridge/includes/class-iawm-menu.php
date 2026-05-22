<?php
/**
 * Plan contenu : gestion des menus de navigation (menus WordPress classiques,
 * utilisés notamment par Divi).
 *
 * Routes POST avec corps JSON. Lecture (list, get) en guard_read ; créations et
 * modifications (create, add-item, remove-item, assign-location) en guard_write.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes des menus de navigation.
 */
class IAWM_Menu {

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes des menus.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/menu/list'            => array( 'handle_list', 'guard_read' ),
			'/menu/get'             => array( 'handle_get', 'guard_read' ),
			'/menu/create'          => array( 'handle_create', 'guard_write' ),
			'/menu/add-item'        => array( 'handle_add_item', 'guard_write' ),
			'/menu/remove-item'     => array( 'handle_remove_item', 'guard_write' ),
			'/menu/assign-location' => array( 'handle_assign_location', 'guard_write' ),
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
	 * POST /menu/list — liste les menus et les emplacements du thème.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		unset( $request );

		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = self::menu_summary( $menu );
		}

		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$locations  = array();
		foreach ( $registered as $slug => $description ) {
			$locations[] = array(
				'location'    => $slug,
				'description' => $description,
				'menu_id'     => isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'menus'     => $menus,
				'locations' => $locations,
			),
			200
		);
	}

	/**
	 * POST /menu/get — détail d'un menu et de ses éléments.
	 *
	 * Corps JSON : { id }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( $request ) {
		$params = IAWM_Support::json_params( $request );
		$menu   = self::resolve_menu( isset( $params['id'] ) ? (int) $params['id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$items = array();
		$raw   = wp_get_nav_menu_items( $menu->term_id );
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				$items[] = self::item_data( $item );
			}
		}

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'menu'  => self::menu_summary( $menu ),
				'items' => $items,
			),
			200
		);
	}

	/**
	 * POST /menu/create — crée un menu.
	 *
	 * Corps JSON : { name, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$name   = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';

		if ( '' === $name ) {
			return IAWM_Support::rest_error( 'iawm_missing_name', "Le paramètre 'name' est requis.", 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_create' => array( 'name' => $name ),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$result = wp_create_nav_menu( $name );
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'iawm_create_failed', $result->get_error_message(), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'created' => true,
				'menu'    => self::menu_summary( wp_get_nav_menu_object( $result ) ),
			),
			201
		);
	}

	/**
	 * POST /menu/add-item — ajoute un élément à un menu.
	 *
	 * Corps JSON : { menu_id, title?, url?, object_id?, parent_item?, dry_run? }
	 * Fournir « url » pour un lien personnalisé, ou « object_id » pour pointer
	 * vers une page ou un article.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_add_item( $request ) {
		$params = IAWM_Support::json_params( $request );
		$menu   = self::resolve_menu( isset( $params['menu_id'] ) ? (int) $params['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$title     = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '';
		$url       = isset( $params['url'] ) ? esc_url_raw( (string) $params['url'] ) : '';
		$object_id = isset( $params['object_id'] ) ? (int) $params['object_id'] : 0;
		$parent    = isset( $params['parent_item'] ) ? (int) $params['parent_item'] : 0;

		$item_args = array(
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent,
		);

		if ( $object_id > 0 ) {
			$object = get_post( $object_id );
			if ( ! $object ) {
				return IAWM_Support::rest_error( 'iawm_not_found', "Contenu introuvable : {$object_id}.", 404 );
			}
			$item_args['menu-item-type']      = 'post_type';
			$item_args['menu-item-object']    = $object->post_type;
			$item_args['menu-item-object-id'] = $object_id;
			if ( '' !== $title ) {
				$item_args['menu-item-title'] = $title;
			}
		} elseif ( '' !== $url ) {
			$item_args['menu-item-type']  = 'custom';
			$item_args['menu-item-url']   = $url;
			$item_args['menu-item-title'] = '' !== $title ? $title : $url;
		} else {
			return IAWM_Support::rest_error( 'iawm_missing_target', "Fournir 'url' (lien) ou 'object_id' (contenu).", 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'       => true,
					'dry_run'  => true,
					'menu_id'  => (int) $menu->term_id,
					'would_add' => $item_args,
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, $item_args );
		if ( is_wp_error( $item_id ) ) {
			return IAWM_Support::rest_error( 'iawm_add_item_failed', $item_id->get_error_message(), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'added'   => true,
				'menu_id' => (int) $menu->term_id,
				'item_id' => (int) $item_id,
			),
			201
		);
	}

	/**
	 * POST /menu/remove-item — retire un élément de menu.
	 *
	 * Corps JSON : { item_id, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_remove_item( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$item_id = isset( $params['item_id'] ) ? (int) $params['item_id'] : 0;

		$item = $item_id > 0 ? get_post( $item_id ) : null;
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Élément de menu introuvable : {$item_id}.", 404 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'             => true,
					'dry_run'        => true,
					'would_remove'   => $item_id,
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		if ( ! wp_delete_post( $item_id, true ) ) {
			return IAWM_Support::rest_error( 'iawm_remove_failed', "Suppression de l'élément impossible.", 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'removed' => true,
				'item_id' => $item_id,
			),
			200
		);
	}

	/**
	 * POST /menu/assign-location — assigne un menu à un emplacement du thème.
	 *
	 * Corps JSON : { menu_id, location, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_assign_location( $request ) {
		$params = IAWM_Support::json_params( $request );
		$menu   = self::resolve_menu( isset( $params['menu_id'] ) ? (int) $params['menu_id'] : 0 );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$location   = isset( $params['location'] ) ? sanitize_key( (string) $params['location'] ) : '';
		$registered = get_registered_nav_menus();
		if ( '' === $location || ! isset( $registered[ $location ] ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_location', "Emplacement de menu inconnu : {$location}.", 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_assign' => array(
						'menu_id'  => (int) $menu->term_id,
						'location' => $location,
					),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$locations              = get_nav_menu_locations();
		$locations[ $location ] = $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'assigned' => true,
				'menu_id'  => (int) $menu->term_id,
				'location' => $location,
			),
			200
		);
	}

	/**
	 * Récupère et valide un menu par son identifiant.
	 *
	 * @param int $id Identifiant du menu.
	 * @return WP_Term|WP_Error
	 */
	private static function resolve_menu( $id ) {
		$menu = $id > 0 ? wp_get_nav_menu_object( $id ) : false;

		if ( ! $menu ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Menu introuvable : {$id}.", 404 );
		}

		return $menu;
	}

	/**
	 * Représentation résumée d'un menu.
	 *
	 * @param WP_Term $menu Menu.
	 * @return array
	 */
	private static function menu_summary( $menu ) {
		return array(
			'id'    => (int) $menu->term_id,
			'name'  => $menu->name,
			'slug'  => $menu->slug,
			'count' => (int) $menu->count,
		);
	}

	/**
	 * Représentation d'un élément de menu.
	 *
	 * @param WP_Post $item Élément de menu (décoré par wp_get_nav_menu_items).
	 * @return array
	 */
	private static function item_data( $item ) {
		return array(
			'id'        => (int) $item->ID,
			'title'     => $item->title,
			'url'       => $item->url,
			'type'      => $item->type,
			'object'    => $item->object,
			'object_id' => (int) $item->object_id,
			'parent'    => (int) $item->menu_item_parent,
			'order'     => (int) $item->menu_order,
		);
	}
}
