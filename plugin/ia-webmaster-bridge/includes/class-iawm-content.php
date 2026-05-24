<?php
/**
 * Content plane: reading and writing of pages and posts.
 *
 * The content routes use the POST method with a JSON body — including for
 * reads. This keeps the HMAC signature simple (only the body is hashed, no
 * query to canonicalise). The read / write distinction is carried by the
 * permission_callback (guard_read vs guard_write), not by the HTTP method:
 * the kill switch therefore only blocks actual writes.
 *
 * Gutenberg content: when content contains block markup, it is normalised
 * (parse_blocks + serialize_blocks) to ensure canonical, valid markup.
 * The raw_content=true parameter disables this processing.
 *
 * Write safeguards:
 *  - creation as draft by default (publishing must be explicit);
 *  - dry-run mode: { "dry_run": true } validates and describes the operation
 *    without modifying anything;
 *  - kill switch: applied upstream by IAWM_Auth::guard_write.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content read and write routes.
 */
class IAWM_Content {

	/** Supported content types. */
	const ALLOWED_TYPES = array( 'post', 'page' );

	/** Statuses accepted for read filtering. */
	const ALLOWED_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

	/** Statuses accepted for writes. */
	const WRITABLE_STATUSES = array( 'draft', 'publish', 'pending', 'private', 'future' );

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers content plane routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/content/list'   => array( 'handle_list', 'guard_read' ),
			'/content/get'    => array( 'handle_get', 'guard_read' ),
			'/content/create' => array( 'handle_create', 'guard_write' ),
			'/content/update' => array( 'handle_update', 'guard_write' ),
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
	 * POST /content/list — paginated list of pages or posts.
	 *
	 * JSON body: { type?, status?, search?, per_page?, page? }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );

		$type = isset( $params['type'] ) ? sanitize_key( (string) $params['type'] ) : 'post';
		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_type', "Unsupported content type: {$type}.", 400 );
		}

		$per_page = isset( $params['per_page'] ) ? (int) $params['per_page'] : 20;
		$per_page = max( 1, min( 100, $per_page ) );
		$page     = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$search   = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$status   = isset( $params['status'] )
			? self::sanitize_status_list( $params['status'] )
			: array( 'publish', 'draft', 'pending', 'private', 'future' );

		$args = array(
			'post_type'      => $type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::summary( $post );
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'type'        => $type,
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
	 * POST /content/get — detailed content of a page or post.
	 *
	 * JSON body: { id }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;

		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'iawm_missing_id', "The 'id' parameter is required.", 400 );
		}

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::ALLOWED_TYPES, true ) ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Content not found: {$id}.", 404 );
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
	 * POST /content/create — creates a page or a post.
	 *
	 * JSON body: { type, title?, content?, status?, slug?, excerpt?,
	 *              parent?, menu_order?, template?, raw_content?, dry_run? }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create( $request ) {
		$params = IAWM_Support::json_params( $request );

		$type = isset( $params['type'] ) ? sanitize_key( (string) $params['type'] ) : '';
		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_type', 'A valid type is required (post|page).', 400 );
		}

		$title   = isset( $params['title'] ) ? (string) $params['title'] : '';
		$content = isset( $params['content'] ) ? (string) $params['content'] : '';
		if ( '' === trim( $title ) && '' === trim( $content ) ) {
			return IAWM_Support::rest_error( 'iawm_empty', 'A title or content is required.', 400 );
		}

		// Safeguard: draft by default, explicit publish.
		$status = isset( $params['status'] ) ? self::sanitize_write_status( $params['status'] ) : 'draft';
		if ( null === $status ) {
			return IAWM_Support::rest_error( 'iawm_invalid_status', 'Unsupported status for writing.', 400 );
		}

		// Content normalisation (canonical Gutenberg block markup).
		$norm = self::normalize_content( $content, ! empty( $params['raw_content'] ) );

		// wp_insert_post applies wp_unslash() internally: we slash ourselves
		// to preserve backslashes (essential for Divi 5 attributes).
		$postarr = array(
			'post_type'    => $type,
			'post_title'   => wp_slash( $title ),
			'post_content' => wp_slash( $norm['content'] ),
			'post_status'  => $status,
			'post_author'  => IAWM_Support::acting_user_id(),
		);
		if ( isset( $params['slug'] ) ) {
			$postarr['post_name'] = (string) $params['slug'];
		}
		if ( isset( $params['excerpt'] ) ) {
			$postarr['post_excerpt'] = (string) $params['excerpt'];
		}
		if ( isset( $params['parent'] ) ) {
			$postarr['post_parent'] = (int) $params['parent'];
		}
		if ( isset( $params['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $params['menu_order'];
		}

		$template = ( isset( $params['template'] ) && 'page' === $type )
			? sanitize_text_field( (string) $params['template'] )
			: null;

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_create' => $postarr,
					'template'     => $template,
					'content_info' => self::content_info( $norm ),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$result = wp_insert_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'iawm_create_failed', $result->get_error_message(), 500 );
		}

		if ( null !== $template ) {
			update_post_meta( $result, '_wp_page_template', $template );
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'created'      => true,
				'item'         => self::full( get_post( $result ) ),
				'content_info' => self::content_info( $norm ),
			),
			201
		);
	}

	/**
	 * POST /content/update — modifies an existing page or post.
	 *
	 * JSON body: { id, title?, content?, status?, slug?, excerpt?,
	 *              parent?, menu_order?, template?, raw_content?, dry_run? }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;

		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'iawm_missing_id', "The 'id' parameter is required.", 400 );
		}

		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::ALLOWED_TYPES, true ) ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Content not found: {$id}.", 404 );
		}

		$changes = array();
		$norm    = null;

		// wp_update_post applies wp_unslash() internally: we slash the
		// text fields to preserve backslashes (Divi 5 stores Unicode
		// escapes ", <, ... in its attributes).
		if ( isset( $params['title'] ) ) {
			$changes['post_title'] = wp_slash( (string) $params['title'] );
		}
		if ( isset( $params['content'] ) ) {
			$norm                    = self::normalize_content( (string) $params['content'], ! empty( $params['raw_content'] ) );
			$changes['post_content'] = wp_slash( $norm['content'] );
		}
		if ( isset( $params['excerpt'] ) ) {
			$changes['post_excerpt'] = wp_slash( (string) $params['excerpt'] );
		}
		if ( isset( $params['slug'] ) ) {
			$changes['post_name'] = (string) $params['slug'];
		}
		if ( isset( $params['parent'] ) ) {
			$changes['post_parent'] = (int) $params['parent'];
		}
		if ( isset( $params['menu_order'] ) ) {
			$changes['menu_order'] = (int) $params['menu_order'];
		}
		if ( isset( $params['status'] ) ) {
			$status = self::sanitize_write_status( $params['status'] );
			if ( null === $status ) {
				return IAWM_Support::rest_error( 'iawm_invalid_status', 'Unsupported status for writing.', 400 );
			}
			$changes['post_status'] = $status;
		}

		$template = ( isset( $params['template'] ) && 'page' === $post->post_type )
			? sanitize_text_field( (string) $params['template'] )
			: null;

		if ( empty( $changes ) && null === $template ) {
			return IAWM_Support::rest_error( 'iawm_no_change', 'No changes provided.', 400 );
		}

		$content_info = ( null !== $norm ) ? self::content_info( $norm ) : null;

		if ( ! empty( $params['dry_run'] ) ) {
			$preview = $changes;
			if ( null !== $template ) {
				$preview['_wp_page_template'] = $template;
			}
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'current'      => self::summary( $post ),
					'would_change' => $preview,
					'content_info' => $content_info,
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$changes['ID'] = $id;
		$result        = wp_update_post( $changes, true );
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'iawm_update_failed', $result->get_error_message(), 500 );
		}

		if ( null !== $template ) {
			update_post_meta( $id, '_wp_page_template', $template );
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'updated'      => true,
				'item'         => self::full( get_post( $id ) ),
				'content_info' => $content_info,
			),
			200
		);
	}

	/**
	 * Normalises a content string.
	 *
	 * If Gutenberg block markup is detected, the content is passed through
	 * parse_blocks + serialize_blocks to produce canonical, valid markup.
	 * Plain HTML and raw text are left untouched. Raw mode disables any
	 * processing.
	 *
	 * @param string $content Input content.
	 * @param bool   $raw     True to leave the content untouched.
	 * @return array { content, blocks, block_count, normalized }
	 */
	private static function normalize_content( $content, $raw ) {
		$result = array(
			'content'     => $content,
			'blocks'      => false,
			'block_count' => 0,
			'normalized'  => false,
		);

		if ( $raw || '' === trim( $content ) || false === strpos( $content, '<!-- wp:' ) ) {
			return $result;
		}

		$parsed = parse_blocks( $content );
		$count  = 0;
		foreach ( $parsed as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$count++;
			}
		}

		$result['content']     = serialize_blocks( $parsed );
		$result['blocks']      = true;
		$result['block_count'] = $count;
		$result['normalized']  = true;

		return $result;
	}

	/**
	 * Reduces the result of normalize_content to the info returned to the client.
	 *
	 * @param array $norm Result of normalize_content().
	 * @return array
	 */
	private static function content_info( $norm ) {
		return array(
			'is_blocks'   => $norm['blocks'],
			'block_count' => $norm['block_count'],
			'normalized'  => $norm['normalized'],
		);
	}

	/**
	 * Summary representation of a content item (for lists).
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function summary( $post ) {
		return array(
			'id'           => (int) $post->ID,
			'type'         => $post->post_type,
			'title'        => $post->post_title,
			'status'       => $post->post_status,
			'slug'         => $post->post_name,
			'date_gmt'     => $post->post_date_gmt,
			'modified_gmt' => $post->post_modified_gmt,
			'link'         => get_permalink( $post ),
			'parent'       => (int) $post->post_parent,
		);
	}

	/**
	 * Detailed representation of a content item (full body included).
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private static function full( $post ) {
		$data = self::summary( $post );

		$data['content']        = $post->post_content;
		$data['excerpt']        = $post->post_excerpt;
		$data['menu_order']     = (int) $post->menu_order;
		$data['author']         = (int) $post->post_author;
		$data['comment_status'] = $post->comment_status;
		$data['featured_media'] = (int) get_post_thumbnail_id( $post );
		$data['template']       = get_page_template_slug( $post );
		$data['builder']        = self::detect_builder( $post );

		return $data;
	}

	/**
	 * Detects (indicatively) with which tool the content was built.
	 *
	 * Divi detection is rudimentary at this stage and will be refined in Phase 3.
	 *
	 * @param WP_Post $post Post object.
	 * @return string 'divi' | 'gutenberg' | 'classic'
	 */
	private static function detect_builder( $post ) {
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			return 'divi';
		}
		if ( false !== strpos( (string) $post->post_content, '<!-- wp:' ) ) {
			return 'gutenberg';
		}

		return 'classic';
	}

	/**
	 * Normalises a list of statuses (string "a,b" or array) for reads.
	 *
	 * @param mixed $status Requested status(es).
	 * @return array List of valid statuses (at least "publish").
	 */
	private static function sanitize_status_list( $status ) {
		if ( is_string( $status ) ) {
			$status = explode( ',', $status );
		}
		if ( ! is_array( $status ) ) {
			return array( 'publish' );
		}

		$clean = array();
		foreach ( $status as $value ) {
			$value = sanitize_key( trim( (string) $value ) );
			if ( in_array( $value, self::ALLOWED_STATUSES, true ) ) {
				$clean[] = $value;
			}
		}

		return ! empty( $clean ) ? $clean : array( 'publish' );
	}

	/**
	 * Validates a status requested for a write.
	 *
	 * @param mixed $status Requested status.
	 * @return string|null Valid status, or null if unsupported.
	 */
	private static function sanitize_write_status( $status ) {
		$status = sanitize_key( (string) $status );

		return in_array( $status, self::WRITABLE_STATUSES, true ) ? $status : null;
	}
}
