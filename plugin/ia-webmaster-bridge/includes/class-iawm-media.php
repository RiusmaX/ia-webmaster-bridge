<?php
/**
 * Plan contenu : gestion de la médiathèque.
 *
 * Routes POST avec corps JSON. Lecture (list, get) en guard_read ; import et
 * mise à jour (sideload, update) en guard_write — donc soumis au kill switch.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes de la médiathèque.
 */
class IAWM_Media {

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes médias.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/media/list'     => array( 'handle_list', 'guard_read' ),
			'/media/get'      => array( 'handle_get', 'guard_read' ),
			'/media/sideload' => array( 'handle_sideload', 'guard_write' ),
			'/media/update'   => array( 'handle_update', 'guard_write' ),
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
	 * POST /media/list — liste paginée de la médiathèque.
	 *
	 * Corps JSON : { search?, mime_type?, per_page?, page? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );

		$per_page = isset( $params['per_page'] ) ? max( 1, min( 100, (int) $params['per_page'] ) ) : 20;
		$page     = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$search   = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$mime     = isset( $params['mime_type'] ) ? sanitize_text_field( (string) $params['mime_type'] ) : '';

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( '' !== $mime ) {
			$args['post_mime_type'] = $mime;
		}

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'items'       => $items,
			),
			200
		);
	}

	/**
	 * POST /media/get — détail d'un média.
	 *
	 * Corps JSON : { id }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;

		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'iawm_missing_id', "Le paramètre 'id' est requis.", 400 );
		}

		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Média introuvable : {$id}.", 404 );
		}

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'item' => self::full( $post ),
			),
			200
		);
	}

	/**
	 * POST /media/sideload — importe un média depuis une URL.
	 *
	 * Corps JSON : { url, title?, alt?, caption?, description?, attached_to?, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_sideload( $request ) {
		$params = IAWM_Support::json_params( $request );

		$url = isset( $params['url'] ) ? esc_url_raw( (string) $params['url'] ) : '';
		if ( '' === $url ) {
			return IAWM_Support::rest_error( 'iawm_missing_url', "Le paramètre 'url' est requis.", 400 );
		}

		$title       = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '';
		$alt         = isset( $params['alt'] ) ? sanitize_text_field( (string) $params['alt'] ) : '';
		$caption     = isset( $params['caption'] ) ? sanitize_text_field( (string) $params['caption'] ) : '';
		$description = isset( $params['description'] ) ? sanitize_textarea_field( (string) $params['description'] ) : '';
		$attached_to = isset( $params['attached_to'] ) ? (int) $params['attached_to'] : 0;

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'             => true,
					'dry_run'        => true,
					'would_sideload' => array(
						'url'         => $url,
						'title'       => $title,
						'alt'         => $alt,
						'attached_to' => $attached_to,
					),
				),
				200
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		IAWM_Support::act_as_agent();

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return IAWM_Support::rest_error( 'iawm_download_failed', 'Téléchargement impossible : ' . $tmp->get_error_message(), 502 );
		}

		$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return IAWM_Support::rest_error( 'iawm_invalid_url', "L'URL ne pointe pas vers un fichier nommé avec une extension.", 400 );
		}

		$post_data = array();
		if ( '' !== $caption ) {
			$post_data['post_excerpt'] = $caption;
		}
		if ( '' !== $description ) {
			$post_data['post_content'] = $description;
		}

		$attach_id = media_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $tmp,
			),
			$attached_to,
			'' !== $title ? $title : null,
			$post_data
		);

		if ( is_wp_error( $attach_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return IAWM_Support::rest_error( 'iawm_sideload_failed', $attach_id->get_error_message(), 500 );
		}

		if ( '' !== $alt ) {
			update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'created' => true,
				'item'    => self::full( get_post( $attach_id ) ),
			),
			201
		);
	}

	/**
	 * POST /media/update — met à jour les métadonnées d'un média.
	 *
	 * Corps JSON : { id, title?, alt?, caption?, description?, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;

		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'iawm_missing_id', "Le paramètre 'id' est requis.", 400 );
		}

		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Média introuvable : {$id}.", 404 );
		}

		$changes = array();
		if ( isset( $params['title'] ) ) {
			$changes['post_title'] = sanitize_text_field( (string) $params['title'] );
		}
		if ( isset( $params['caption'] ) ) {
			$changes['post_excerpt'] = sanitize_text_field( (string) $params['caption'] );
		}
		if ( isset( $params['description'] ) ) {
			$changes['post_content'] = sanitize_textarea_field( (string) $params['description'] );
		}

		$alt = isset( $params['alt'] ) ? sanitize_text_field( (string) $params['alt'] ) : null;

		if ( empty( $changes ) && null === $alt ) {
			return IAWM_Support::rest_error( 'iawm_no_change', 'Aucune modification fournie.', 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			$preview = $changes;
			if ( null !== $alt ) {
				$preview['_wp_attachment_image_alt'] = $alt;
			}
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'current'      => self::summary( $post ),
					'would_change' => $preview,
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		if ( ! empty( $changes ) ) {
			$changes['ID'] = $id;
			$result        = wp_update_post( $changes, true );
			if ( is_wp_error( $result ) ) {
				return IAWM_Support::rest_error( 'iawm_update_failed', $result->get_error_message(), 500 );
			}
		}
		if ( null !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => true,
				'item'    => self::full( get_post( $id ) ),
			),
			200
		);
	}

	/**
	 * Représentation résumée d'un média.
	 *
	 * @param WP_Post $post Objet attachment.
	 * @return array
	 */
	private static function summary( $post ) {
		return array(
			'id'          => (int) $post->ID,
			'title'       => $post->post_title,
			'mime_type'   => $post->post_mime_type,
			'url'         => wp_get_attachment_url( $post->ID ),
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'date_gmt'    => $post->post_date_gmt,
			'attached_to' => (int) $post->post_parent,
		);
	}

	/**
	 * Représentation détaillée d'un média.
	 *
	 * @param WP_Post $post Objet attachment.
	 * @return array
	 */
	private static function full( $post ) {
		$data = self::summary( $post );

		$data['slug']        = $post->post_name;
		$data['caption']     = $post->post_excerpt;
		$data['description'] = $post->post_content;
		$data['width']       = null;
		$data['height']      = null;

		$meta = wp_get_attachment_metadata( $post->ID );
		if ( is_array( $meta ) ) {
			if ( isset( $meta['width'] ) ) {
				$data['width'] = (int) $meta['width'];
			}
			if ( isset( $meta['height'] ) ) {
				$data['height'] = (int) $meta['height'];
			}
		}

		return $data;
	}
}
