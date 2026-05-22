<?php
/**
 * Plan contenu : gestion des taxonomies (catégories, étiquettes, taxonomies
 * personnalisées).
 *
 * Routes POST avec corps JSON. Lecture (list) en guard_read ; création de terme
 * et assignation (create, assign) en guard_write — donc soumis au kill switch.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes des taxonomies.
 */
class IAWM_Taxonomy {

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes des taxonomies.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/taxonomy/list'   => array( 'handle_list', 'guard_read' ),
			'/taxonomy/create' => array( 'handle_create', 'guard_write' ),
			'/taxonomy/assign' => array( 'handle_assign', 'guard_write' ),
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
	 * POST /taxonomy/list — liste les termes d'une taxonomie.
	 *
	 * Corps JSON : { taxonomy, search?, per_page?, page? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_list( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$taxonomy = self::resolve_taxonomy( $params );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$per_page = isset( $params['per_page'] ) ? max( 1, min( 200, (int) $params['per_page'] ) ) : 100;
		$page     = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$search   = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'orderby'    => 'name',
		);
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return IAWM_Support::rest_error( 'iawm_taxonomy_error', $terms->get_error_message(), 500 );
		}

		$total = (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = self::term_data( $term );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'taxonomy' => $taxonomy,
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => $total,
				'items'    => $items,
			),
			200
		);
	}

	/**
	 * POST /taxonomy/create — crée un terme.
	 *
	 * Corps JSON : { taxonomy, name, slug?, description?, parent?, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$taxonomy = self::resolve_taxonomy( $params );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$name = isset( $params['name'] ) ? sanitize_text_field( (string) $params['name'] ) : '';
		if ( '' === $name ) {
			return IAWM_Support::rest_error( 'iawm_missing_name', "Le paramètre 'name' est requis.", 400 );
		}

		$args = array();
		if ( isset( $params['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $params['slug'] );
		}
		if ( isset( $params['description'] ) ) {
			$args['description'] = sanitize_textarea_field( (string) $params['description'] );
		}
		if ( isset( $params['parent'] ) ) {
			$args['parent'] = (int) $params['parent'];
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_create' => array_merge( array( 'taxonomy' => $taxonomy, 'name' => $name ), $args ),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$result = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'iawm_create_failed', $result->get_error_message(), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'created' => true,
				'item'    => self::term_data( get_term( $result['term_id'], $taxonomy ) ),
			),
			201
		);
	}

	/**
	 * POST /taxonomy/assign — assigne des termes à un contenu.
	 *
	 * Corps JSON : { id, taxonomy, terms, append?, dry_run? }
	 * Les termes peuvent être des identifiants (recommandé) ou des noms.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_assign( $request ) {
		$params = IAWM_Support::json_params( $request );

		$post_id = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Contenu introuvable : {$post_id}.", 404 );
		}

		$taxonomy = self::resolve_taxonomy( $params );
		if ( is_wp_error( $taxonomy ) ) {
			return $taxonomy;
		}

		$terms = ( isset( $params['terms'] ) && is_array( $params['terms'] ) ) ? $params['terms'] : array();
		if ( empty( $terms ) ) {
			return IAWM_Support::rest_error( 'iawm_missing_terms', "Le paramètre 'terms' (tableau) est requis.", 400 );
		}

		// Entiers -> identifiants de termes ; chaînes -> noms.
		$clean = array();
		foreach ( $terms as $term ) {
			if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
				$clean[] = (int) $term;
			} elseif ( is_string( $term ) ) {
				$clean[] = sanitize_text_field( $term );
			}
		}

		$append = ! empty( $params['append'] );

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_assign' => array(
						'post'     => $post_id,
						'taxonomy' => $taxonomy,
						'terms'    => $clean,
						'append'   => $append,
					),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$result = wp_set_object_terms( $post_id, $clean, $taxonomy, $append );
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'iawm_assign_failed', $result->get_error_message(), 500 );
		}

		$current = wp_get_object_terms( $post_id, $taxonomy );
		$items   = array();
		if ( ! is_wp_error( $current ) ) {
			foreach ( $current as $term ) {
				$items[] = self::term_data( $term );
			}
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'assigned' => true,
				'post'     => $post_id,
				'taxonomy' => $taxonomy,
				'terms'    => $items,
			),
			200
		);
	}

	/**
	 * Valide et retourne la taxonomie demandée.
	 *
	 * @param array $params Paramètres de la requête.
	 * @return string|WP_Error Slug de taxonomie, ou erreur.
	 */
	private static function resolve_taxonomy( $params ) {
		$taxonomy = isset( $params['taxonomy'] ) ? sanitize_key( (string) $params['taxonomy'] ) : '';

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_taxonomy', "Taxonomie inconnue : {$taxonomy}.", 400 );
		}

		return $taxonomy;
	}

	/**
	 * Représentation d'un terme.
	 *
	 * @param WP_Term $term Terme.
	 * @return array
	 */
	private static function term_data( $term ) {
		return array(
			'id'          => (int) $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'taxonomy'    => $term->taxonomy,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'description' => $term->description,
		);
	}
}
