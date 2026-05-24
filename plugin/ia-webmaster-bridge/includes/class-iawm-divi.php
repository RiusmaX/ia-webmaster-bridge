<?php
/**
 * Divi 5 capability — reading and writing layouts.
 *
 * Phase 3.2 (reads):
 *  - /divi/page/read: projects a Divi page's post_content into a simplified
 *    JSON tree (sections > rows > columns > modules), with normalised
 *    attributes and a content summary.
 *
 * Phase 3.2 (writes) — coming:
 *  - /divi/page/write: takes a simplified tree, serialises it to valid
 *    Divi 5 blocks and writes it into post_content.
 *
 * Phase 3.3 (Divi Cloud) — coming.
 *
 * The format is documented in docs/divi5-format.md (reverse-engineered
 * from local reference page #19).
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi 5 routes.
 */
class IAWM_Divi {

	/** Prefix of Divi 5 blocks in Gutenberg markup. */
	const BLOCK_PREFIX = 'divi/';

	/** Root wrapper block name. */
	const ROOT_BLOCK = 'divi/placeholder';

	/** Structural (non-leaf) modules. */
	const STRUCTURAL_BLOCKS = array( 'divi/placeholder', 'divi/section', 'divi/row', 'divi/column' );

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/divi/page/read'      => array( 'handle_page_read', 'guard_read' ),
			'/divi/page/write'     => array( 'handle_page_write', 'guard_write' ),
			'/divi/status'         => array( 'handle_status', 'guard_read' ),
			'/divi/library/list'   => array( 'handle_library_list', 'guard_read' ),
			'/divi/library/item'   => array( 'handle_library_item', 'guard_read' ),
			'/divi/library/local'  => array( 'handle_library_local', 'guard_read' ),
			'/divi/cloud/status'   => array( 'handle_cloud_status', 'guard_read' ),
			'/divi/global-data'    => array( 'handle_global_data', 'guard_read' ),
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
	 * POST /divi/status — state of Divi 5 on the site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		unset( $request );

		$divi_active = defined( 'ET_BUILDER_VERSION' );
		$version     = $divi_active && defined( 'ET_BUILDER_VERSION' ) ? ET_BUILDER_VERSION : null;
		// Also covers the case where Divi exposes its version via another constant.
		if ( null === $version && defined( 'ET_CORE_VERSION' ) ) {
			$version = ET_CORE_VERSION;
		}

		return new WP_REST_Response(
			array(
				'ok'              => true,
				'divi_active'     => $divi_active,
				'divi_version'    => $version,
				'theme_stylesheet' => get_stylesheet(),
				'has_d5_format'   => function_exists( 'parse_blocks' ),
			),
			200
		);
	}

	/**
	 * POST /divi/page/read — reads a Divi 5 page and projects it into a tree.
	 *
	 * Parameters:
	 *   - post_id (int, required).
	 *   - mode    (string, optional): "tree" (default) | "raw" | "flat".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_page_read( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$mode    = isset( $params['mode'] ) ? (string) $params['mode'] : 'tree';

		if ( ! in_array( $mode, array( 'tree', 'raw', 'flat' ), true ) ) {
			return IAWM_Support::rest_error( 'invalid_mode', "Unknown mode: {$mode}.", 400 );
		}
		if ( $post_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', 'post_id required.', 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return IAWM_Support::rest_error( 'post_not_found', "Post {$post_id} not found.", 404 );
		}

		$uses_builder = 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true );
		$has_d5_marker = false !== strpos( $post->post_content, '<!-- wp:divi/' );

		if ( ! $uses_builder && ! $has_d5_marker ) {
			return IAWM_Support::rest_error(
				'not_a_divi_page',
				"Post {$post_id} is not a Divi 5 page (neither meta _et_pb_use_builder=on, nor wp:divi/ blocks detected).",
				400,
				array( 'meta_use_builder' => $uses_builder, 'has_d5_marker' => $has_d5_marker )
			);
		}

		$blocks = parse_blocks( $post->post_content );

		$page = array(
			'id'                   => (int) $post->ID,
			'title'                => $post->post_title,
			'status'               => $post->post_status,
			'slug'                 => $post->post_name,
			'link'                 => get_permalink( $post ),
			'meta_use_builder'     => $uses_builder,
			'built_for_post_type'  => get_post_meta( $post_id, '_et_pb_built_for_post_type', true ),
		);

		// Look for the root placeholder wrapper — if present, descend into it
		// to expose sections directly.
		$layout_blocks = self::unwrap_placeholder( $blocks );

		switch ( $mode ) {
			case 'raw':
				$output = $blocks;
				break;
			case 'flat':
				$output = self::flatten( $layout_blocks );
				break;
			case 'tree':
			default:
				$output = self::project_tree( $layout_blocks );
				break;
		}

		// Useful stats (always provided).
		$counts = self::count_block_types( $blocks );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'mode'    => $mode,
				'page'    => $page,
				'stats'   => array(
					'total_blocks'   => array_sum( $counts ),
					'block_counts'   => $counts,
					'section_count'  => isset( $counts['divi/section'] ) ? $counts['divi/section'] : 0,
				),
				'layout'  => $output,
			),
			200
		);
	}

	/**
	 * Internal proxy to a nonce-protected divi/v1 route.
	 *
	 * Divi 5 protects its REST routes with a nonce named after the pattern:
	 *   "{full_route}--{METHOD}"  (e.g. "/divi/v1/divi-library--POST")
	 *
	 * We sign in as admin, generate the expected nonce, inject it into the
	 * X-ET-Nonce header and call rest_do_request().
	 *
	 * @param string $route    Relative route (e.g. "/divi-library").
	 * @param string $method   HTTP method (POST by default).
	 * @param array  $body     JSON body.
	 * @return array { status, data, headers }
	 */
	protected static function call_divi_route( $route, $method = 'POST', $body = array() ) {
		IAWM_Support::act_as_agent();

		$namespace  = 'divi/v1';
		$full_route = '/' . $namespace . '/' . ltrim( $route, '/' );
		$nonce_name = $full_route . '--' . strtoupper( $method );
		$nonce      = wp_create_nonce( $nonce_name );

		$req = new WP_REST_Request( $method, $full_route );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_header( 'X-ET-Nonce', $nonce );
		if ( ! empty( $body ) ) {
			$req->set_body( wp_json_encode( $body ) );
		} else {
			// Divi sometimes expects a JSON body even when empty.
			$req->set_body( '{}' );
		}

		$response = rest_do_request( $req );

		return array(
			'status' => $response->get_status(),
			'data'   => $response->get_data(),
		);
	}

	/**
	 * POST /divi/library/list — lists the local Divi library (and Cloud if connected).
	 *
	 * Parameters:
	 *   - type (string, optional): "layout" (default) | "section" | "row" | "module".
	 *
	 * Returns: { categories, packs, tags, items } from Divi.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_library_list( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$type    = isset( $params['type'] ) ? (string) $params['type'] : 'layout';
		$exclude = isset( $params['exclude'] ) && is_array( $params['exclude'] ) ? $params['exclude'] : array();

		$valid_types = array( 'layout', 'section', 'row', 'module' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			return IAWM_Support::rest_error( 'invalid_type', "Invalid type. Expected: " . implode( ', ', $valid_types ), 400 );
		}

		$res = self::call_divi_route( '/divi-library', 'POST', array(
			'type'    => $type,
			'exclude' => $exclude,
		) );

		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'divi_library_failed', 'Call to divi-library failed.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		// Flatten: Divi returns { layout: { categories, packs, tags, items } }
		// or directly the structure depending on the type.
		$payload = $res['data'];
		$root_key = $type; // e.g. "layout" / "section"
		if ( isset( $payload[ $root_key ] ) ) {
			$payload = $payload[ $root_key ];
		}

		$summary = array(
			'category_count' => isset( $payload['categories'] ) ? count( (array) $payload['categories'] ) : 0,
			'pack_count'     => isset( $payload['packs'] ) ? count( (array) $payload['packs'] ) : 0,
			'tag_count'      => isset( $payload['tags'] ) ? count( (array) $payload['tags'] ) : 0,
			'item_count'     => isset( $payload['items'] ) ? count( (array) $payload['items'] ) : 0,
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'type'    => $type,
				'summary' => $summary,
				'library' => $payload,
			),
			200
		);
	}

	/**
	 * POST /divi/library/item — fetches an item from the Divi library.
	 *
	 * Parameters:
	 *   - id (int|string, required): item identifier.
	 *   - library_type (string, optional): "layout" (default).
	 *   - built_for (string, optional): "page" (default).
	 *   - content_type (string, optional): "layout" (default).
	 *
	 * Returns: { content, globalColors, globalVariables }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_library_item( $request ) {
		$params = IAWM_Support::json_params( $request );
		if ( ! isset( $params['id'] ) || '' === $params['id'] ) {
			return IAWM_Support::rest_error( 'invalid_id', 'id required.', 400 );
		}

		$res = self::call_divi_route( '/divi-library/item', 'POST', array(
			'id'           => $params['id'],
			'libraryType'  => isset( $params['library_type'] ) ? $params['library_type'] : 'layout',
			'builtFor'     => isset( $params['built_for'] ) ? $params['built_for'] : 'page',
			'contentType'  => isset( $params['content_type'] ) ? $params['content_type'] : 'layout',
		) );

		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'divi_library_item_failed', 'Call to divi-library/item failed.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/library/local — lists the layouts saved in the local Divi
	 * library (et_pb_layout post type).
	 *
	 * Hybrid workflow: when the user finds an interesting layout in Divi
	 * Cloud (from the Visual Builder in their browser), they click
	 * "Save to Library" — this creates an et_pb_layout post that this route
	 * exposes to our API. We can then read its content via
	 * iawm_content_get (post_content contains the Divi 5 blocks) or via
	 * iawm_divi_page_read if the safeguard is adapted.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_library_local( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$per_page = isset( $params['per_page'] ) ? max( 1, min( 100, (int) $params['per_page'] ) ) : 50;
		$page     = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$search   = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';
		$category = isset( $params['category'] ) ? sanitize_text_field( (string) $params['category'] ) : '';

		$args = array(
			'post_type'      => 'et_pb_layout',
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( '' !== $category ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'layout_category',
					'field'    => 'slug',
					'terms'    => $category,
				),
			);
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$layout_type = get_post_meta( $post->ID, '_et_pb_built_for_post_type', true );
			$cats        = wp_get_post_terms( $post->ID, 'layout_category', array( 'fields' => 'names' ) );
			$tags        = wp_get_post_terms( $post->ID, 'layout_tag', array( 'fields' => 'names' ) );
			$pack        = wp_get_post_terms( $post->ID, 'layout_pack', array( 'fields' => 'names' ) );

			// Detect whether it's Divi 5 (blocks) or Divi 4 (shortcodes).
			$is_d5 = false !== strpos( $post->post_content, '<!-- wp:divi/' );

			$items[] = array(
				'id'           => (int) $post->ID,
				'title'        => $post->post_title,
				'slug'         => $post->post_name,
				'status'       => $post->post_status,
				'date_gmt'     => $post->post_date_gmt,
				'modified_gmt' => $post->post_modified_gmt,
				'is_divi_5'    => $is_d5,
				'layout_type'  => is_string( $layout_type ) && '' !== $layout_type ? $layout_type : null,
				'categories'   => is_array( $cats ) ? $cats : array(),
				'tags'         => is_array( $tags ) ? $tags : array(),
				'pack'         => is_array( $pack ) && ! empty( $pack ) ? $pack[0] : null,
				'content_length' => strlen( $post->post_content ),
			);
		}

		// Also list categories/tags to help with filtering.
		$all_categories = get_terms( array( 'taxonomy' => 'layout_category', 'hide_empty' => false, 'fields' => 'names' ) );
		$all_tags       = get_terms( array( 'taxonomy' => 'layout_tag', 'hide_empty' => false, 'fields' => 'names' ) );

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'total'          => (int) $query->found_posts,
				'page'           => $page,
				'per_page'       => $per_page,
				'total_pages'    => (int) $query->max_num_pages,
				'items'          => $items,
				'all_categories' => is_array( $all_categories ) ? $all_categories : array(),
				'all_tags'       => is_array( $all_tags ) ? $all_tags : array(),
			),
			200
		);
	}

	/**
	 * POST /divi/cloud/status — state of the Divi Cloud connection.
	 *
	 * Retrieves the cloudToken and the Elegant Marketplace API identity if
	 * available (without exposing the API key in clear).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_cloud_status( $request ) {
		unset( $request );

		$token_res = self::call_divi_route( '/divi-library/cloud-token', 'POST', array() );

		$marketplace = get_option( 'et_automatic_updates_options', array() );
		$has_license = is_array( $marketplace ) && ! empty( $marketplace['username'] ) && ! empty( $marketplace['api_key'] );

		$cloud_token = '';
		if ( $token_res['status'] < 400 && is_array( $token_res['data'] ) ) {
			$cloud_token = isset( $token_res['data']['cloudToken'] ) ? (string) $token_res['data']['cloudToken'] : '';
		}

		return new WP_REST_Response(
			array(
				'ok'                       => true,
				'has_elegant_license'      => $has_license,
				'elegant_username'         => $has_license ? (string) $marketplace['username'] : null,
				'cloud_token_present'      => '' !== $cloud_token,
				// We do NOT return the raw token (sensitive).
				'cloud_token_length'       => strlen( $cloud_token ),
			),
			200
		);
	}

	/**
	 * POST /divi/global-data — fetches the site's Divi design system.
	 *
	 * Tip: we fetch any item from the Divi library; its response always
	 * includes global colors and global variables. We could also go further
	 * with direct divi/v1/global-data/* routes.
	 *
	 * This route is useful for driving generated pages by referencing the
	 * design system variables (gcid-*).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_global_data( $request ) {
		unset( $request );

		// Try via an item call with id=1 to piggyback globalColors + globalVariables.
		$res = self::call_divi_route( '/divi-library/item', 'POST', array(
			'id'          => 1,
			'libraryType' => 'layout',
			'builtFor'    => 'page',
			'contentType' => 'layout',
		) );

		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'global_data_unavailable', 'Could not retrieve the Divi design system.', $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		$data = (array) $res['data'];
		return new WP_REST_Response(
			array(
				'ok'              => true,
				'global_colors'   => isset( $data['globalColors'] ) ? $data['globalColors'] : null,
				'global_variables' => isset( $data['globalVariables'] ) ? $data['globalVariables'] : null,
			),
			200
		);
	}

	/**
	 * POST /divi/page/write — writes a Divi 5 layout into a post.
	 *
	 * Two accepted input formats:
	 *  - "content" (string): post_content already serialised (string with
	 *    `<!-- wp:divi/... -->`). Goes through parse_blocks + serialize_blocks
	 *    for normalisation and validation.
	 *  - "blocks" (array): array of blocks in the parse_blocks format
	 *    (`{ blockName, attrs, innerBlocks, innerHTML, innerContent }`).
	 *    Serialised via serialize_blocks().
	 *
	 * Guarantees:
	 *  - the root wp:divi/placeholder wrapper (added automatically if missing);
	 *  - the _et_pb_use_builder=on meta (set if absent);
	 *  - the _et_pb_built_for_post_type meta aligned with the post's type.
	 *
	 * dry_run=true: validates and describes what would be written without
	 * touching the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_page_write( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$dry_run = ! empty( $params['dry_run'] );

		if ( $post_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', 'post_id required.', 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return IAWM_Support::rest_error( 'post_not_found', "Post {$post_id} not found.", 404 );
		}

		// Retrieve the content to write according to the format provided.
		$blocks = null;
		if ( isset( $params['blocks'] ) && is_array( $params['blocks'] ) ) {
			$blocks = $params['blocks'];
		} elseif ( isset( $params['content'] ) && is_string( $params['content'] ) ) {
			$blocks = parse_blocks( $params['content'] );
		} else {
			return IAWM_Support::rest_error(
				'missing_payload',
				'Provide "content" (serialised string) OR "blocks" (array of blocks).',
				400
			);
		}

		// Filter out empty blocks from parsing (whitespace).
		$real_blocks = array_values( array_filter( $blocks, function( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		if ( empty( $real_blocks ) ) {
			return IAWM_Support::rest_error( 'empty_layout', 'No Divi block detected in the payload.', 400 );
		}

		// Check we either have a root placeholder or only divi/ blocks.
		// If not, refuse.
		$has_placeholder = count( $real_blocks ) === 1 && $real_blocks[0]['blockName'] === self::ROOT_BLOCK;
		$all_divi        = true;
		foreach ( $real_blocks as $b ) {
			if ( 0 !== strpos( $b['blockName'], self::BLOCK_PREFIX ) ) {
				$all_divi = false;
				break;
			}
		}
		if ( ! $all_divi ) {
			return IAWM_Support::rest_error(
				'non_divi_blocks',
				'The layout contains blocks outside the divi/ namespace.',
				400,
				array( 'detected' => array_map( function( $b ) { return $b['blockName']; }, $real_blocks ) )
			);
		}

		// Auto-wrap: if there is no root placeholder, add one.
		if ( ! $has_placeholder ) {
			$real_blocks = array(
				array(
					'blockName'    => self::ROOT_BLOCK,
					'attrs'        => new stdClass(),
					'innerBlocks'  => $real_blocks,
					'innerHTML'    => '',
					'innerContent' => array( null ),
				),
			);
			$wrapped = true;
		} else {
			$wrapped = false;
		}

		$content      = serialize_blocks( $real_blocks );
		$block_count  = self::count_block_types( $real_blocks );
		$total        = array_sum( $block_count );

		$preview = array(
			'wrapped_with_placeholder' => $wrapped,
			'block_count'              => $block_count,
			'total_blocks'             => $total,
			'content_length'           => strlen( $content ),
		);

		if ( $dry_run ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'dry_run' => true, 'preview' => $preview ),
				200
			);
		}

		IAWM_Support::act_as_agent();

		// wp_update_post applies wp_unslash() internally (assumes the data
		// comes from an already-slashed $_POST). We must therefore slash
		// ourselves to preserve the backslashes in Divi's JSON
		// (", <, etc.) — without this, block attributes are corrupted
		// on write.
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'write_failed', $result->get_error_message(), 500 );
		}

		// Ensure the Divi metas.
		if ( 'on' !== get_post_meta( $post_id, '_et_pb_use_builder', true ) ) {
			update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		}
		if ( get_post_meta( $post_id, '_et_pb_built_for_post_type', true ) !== $post->post_type ) {
			update_post_meta( $post_id, '_et_pb_built_for_post_type', $post->post_type );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'written' => true,
				'post_id' => $post_id,
				'preview' => $preview,
			),
			200
		);
	}

	/**
	 * If the root contains a single wp:divi/placeholder, descend into it
	 * to expose sections directly.
	 *
	 * @param array $blocks Root blocks.
	 * @return array
	 */
	protected static function unwrap_placeholder( $blocks ) {
		// Filter out empty blocks (whitespace between comments).
		$real = array_values( array_filter( $blocks, function( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		if ( 1 === count( $real ) && self::ROOT_BLOCK === $real[0]['blockName'] ) {
			return $real[0]['innerBlocks'];
		}
		return $real;
	}

	/**
	 * Projects a list of blocks into a simplified tree.
	 *
	 * Preserves the hierarchy but flattens intermediate breakpoint wrappers
	 * to expose JSON usable directly.
	 *
	 * @param array $blocks Blocks to project.
	 * @return array
	 */
	protected static function project_tree( $blocks ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$out[] = self::project_block( $block );
		}
		return $out;
	}

	/**
	 * Projects a single block into a simplified structure.
	 *
	 * @param array $block Gutenberg block.
	 * @return array
	 */
	protected static function project_block( $block ) {
		$name       = $block['blockName'];
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$short_name = self::short_name( $name );

		$node = array(
			'type'           => $short_name,
			'block_name'     => $name,
			'is_structural'  => in_array( $name, self::STRUCTURAL_BLOCKS, true ),
			'builder_version'=> isset( $attrs['builderVersion'] ) ? $attrs['builderVersion'] : null,
		);

		// Module-specific fields: we extract the textual content
		// to make it easier for Claude to read.
		$summary = self::summarize_module( $name, $attrs );
		if ( ! empty( $summary ) ) {
			$node['summary'] = $summary;
		}

		// Normalised style: lift up what's generally interesting.
		$style = self::summarize_style( $attrs );
		if ( ! empty( $style ) ) {
			$node['style'] = $style;
		}

		// For structural blocks, recurse into innerBlocks.
		if ( in_array( $name, self::STRUCTURAL_BLOCKS, true ) && ! empty( $block['innerBlocks'] ) ) {
			$children = self::project_tree( $block['innerBlocks'] );
			$child_key = self::children_key( $name );
			$node[ $child_key ] = $children;
		} else {
			// For leaf modules, keep the raw attrs for
			// detailed inspection (useful in debug).
			$node['attrs'] = $attrs;
		}

		return $node;
	}

	/**
	 * Short name of a Divi block (without the "divi/" prefix).
	 *
	 * @param string $block_name Full name.
	 * @return string
	 */
	protected static function short_name( $block_name ) {
		if ( 0 === strpos( $block_name, self::BLOCK_PREFIX ) ) {
			return substr( $block_name, strlen( self::BLOCK_PREFIX ) );
		}
		return $block_name;
	}

	/**
	 * Returns the children key expected for a given structural block.
	 *
	 * @param string $block_name Parent block name.
	 * @return string
	 */
	protected static function children_key( $block_name ) {
		switch ( $block_name ) {
			case 'divi/placeholder':
			case 'divi/section':
				return 'rows';
			case 'divi/row':
				return 'columns';
			case 'divi/column':
				return 'modules';
			default:
				return 'children';
		}
	}

	/**
	 * Extracts a readable summary of a module's content (text, title, etc.).
	 *
	 * @param string $name  Block name.
	 * @param array  $attrs Attributes.
	 * @return array
	 */
	protected static function summarize_module( $name, $attrs ) {
		$summary = array();

		// Helper: gets innerContent.desktop.value (default HTML/text).
		$pick_desktop = function( $field ) use ( $attrs ) {
			if ( ! isset( $attrs[ $field ]['innerContent']['desktop']['value'] ) ) {
				return null;
			}
			return $attrs[ $field ]['innerContent']['desktop']['value'];
		};

		switch ( $name ) {
			case 'divi/text':
				$summary['content_html'] = $pick_desktop( 'content' );
				break;

			case 'divi/blurb':
				$title = $pick_desktop( 'title' );
				if ( is_array( $title ) && isset( $title['text'] ) ) {
					$summary['title'] = $title['text'];
				} elseif ( is_string( $title ) ) {
					$summary['title'] = $title;
				}
				$summary['content_html'] = $pick_desktop( 'content' );
				if ( isset( $attrs['imageIcon']['innerContent']['desktop']['value']['icon']['unicode'] ) ) {
					$summary['icon_unicode'] = $attrs['imageIcon']['innerContent']['desktop']['value']['icon']['unicode'];
				}
				break;

			case 'divi/cta':
				$summary['title']        = $pick_desktop( 'title' );
				$summary['content_html'] = $pick_desktop( 'content' );
				$button = $pick_desktop( 'button' );
				if ( is_array( $button ) ) {
					$summary['button_text'] = isset( $button['text'] ) ? $button['text'] : null;
					$summary['button_url']  = isset( $button['linkUrl'] ) ? $button['linkUrl'] : null;
				}
				break;

			case 'divi/image':
				if ( isset( $attrs['image']['innerContent']['desktop']['value']['src'] ) ) {
					$src = $attrs['image']['innerContent']['desktop']['value']['src'];
					// Truncate data: URLs so they don't pollute the output.
					$summary['src'] = self::truncate_src( $src );
				}
				break;

			case 'divi/button':
				$button = $pick_desktop( 'button' );
				if ( is_array( $button ) ) {
					$summary['text'] = isset( $button['text'] ) ? $button['text'] : null;
					$summary['url']  = isset( $button['linkUrl'] ) ? $button['linkUrl'] : null;
				}
				break;

			case 'divi/row':
				// Column structure.
				if ( isset( $attrs['module']['advanced']['columnStructure']['desktop']['value'] ) ) {
					$summary['column_structure'] = $attrs['module']['advanced']['columnStructure']['desktop']['value'];
				}
				break;

			case 'divi/column':
				if ( isset( $attrs['module']['advanced']['type']['desktop']['value'] ) ) {
					$summary['type'] = $attrs['module']['advanced']['type']['desktop']['value'];
				}
				break;
		}

		// Strip nulls.
		return array_filter( $summary, function( $v ) { return null !== $v && '' !== $v; } );
	}

	/**
	 * Truncates data: URLs or very long URLs for display.
	 *
	 * @param string $src URL or data: URI.
	 * @return string
	 */
	protected static function truncate_src( $src ) {
		if ( ! is_string( $src ) ) {
			return '';
		}
		if ( strlen( $src ) > 200 && 0 === strpos( $src, 'data:' ) ) {
			$mime = substr( $src, 5, strpos( $src, ';' ) - 5 );
			return 'data:' . $mime . ';base64,...(' . strlen( $src ) . ' bytes)';
		}
		return $src;
	}

	/**
	 * Extracts a summary of a block's style (colours, spacing, etc.).
	 *
	 * @param array $attrs Block attributes.
	 * @return array
	 */
	protected static function summarize_style( $attrs ) {
		$style = array();

		if ( ! isset( $attrs['module']['decoration'] ) ) {
			return $style;
		}
		$deco = $attrs['module']['decoration'];

		// Background.
		if ( isset( $deco['background']['desktop']['value'] ) ) {
			$bg = $deco['background']['desktop']['value'];
			if ( isset( $bg['color'] ) ) {
				$style['background_color'] = self::shorten_variable( $bg['color'] );
			}
			if ( isset( $bg['image']['url'] ) ) {
				$style['background_image'] = $bg['image']['url'];
			}
			if ( isset( $bg['gradient']['enabled'] ) && 'on' === $bg['gradient']['enabled'] ) {
				$style['background_gradient'] = isset( $bg['gradient']['stops'] ) ? $bg['gradient']['stops'] : true;
			}
		}

		// Spacing.
		if ( isset( $deco['spacing']['desktop']['value'] ) ) {
			$spacing = $deco['spacing']['desktop']['value'];
			if ( isset( $spacing['padding'] ) ) {
				$style['padding'] = array_filter(
					array(
						'top'    => isset( $spacing['padding']['top'] )    ? $spacing['padding']['top']    : null,
						'right'  => isset( $spacing['padding']['right'] )  ? $spacing['padding']['right']  : null,
						'bottom' => isset( $spacing['padding']['bottom'] ) ? $spacing['padding']['bottom'] : null,
						'left'   => isset( $spacing['padding']['left'] )   ? $spacing['padding']['left']   : null,
					),
					function( $v ) { return null !== $v; }
				);
			}
			if ( isset( $spacing['margin'] ) ) {
				$style['margin'] = array_filter(
					array(
						'top'    => isset( $spacing['margin']['top'] )    ? $spacing['margin']['top']    : null,
						'right'  => isset( $spacing['margin']['right'] )  ? $spacing['margin']['right']  : null,
						'bottom' => isset( $spacing['margin']['bottom'] ) ? $spacing['margin']['bottom'] : null,
						'left'   => isset( $spacing['margin']['left'] )   ? $spacing['margin']['left']   : null,
					),
					function( $v ) { return null !== $v; }
				);
			}
		}

		return $style;
	}

	/**
	 * Simplifies a global variable reference to its name.
	 * E.g. `$variable({"type":"color","value":{"name":"gcid-foo","settings":{}}})$` -> `var:gcid-foo`.
	 *
	 * @param string $color Colour value (may contain a variable or a hex code).
	 * @return string
	 */
	protected static function shorten_variable( $color ) {
		if ( ! is_string( $color ) ) {
			return '';
		}
		if ( preg_match( '/"name":"([a-zA-Z0-9_\\-]+)"/', $color, $m ) ) {
			return 'var:' . $m[1];
		}
		return $color;
	}

	/**
	 * Produces a linear list (flat mode) of blocks with paths.
	 *
	 * @param array  $blocks List of root blocks.
	 * @param string $path   Current path.
	 * @return array
	 */
	protected static function flatten( $blocks, $path = '' ) {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$here = $path === '' ? (string) $i : "{$path}.{$i}";
			$node = array(
				'path'       => $here,
				'type'       => self::short_name( $block['blockName'] ),
				'block_name' => $block['blockName'],
			);
			$summary = self::summarize_module( $block['blockName'], isset( $block['attrs'] ) ? $block['attrs'] : array() );
			if ( ! empty( $summary ) ) {
				$node['summary'] = $summary;
			}
			$out[] = $node;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::flatten( $block['innerBlocks'], $here ) );
			}
		}
		return $out;
	}

	/**
	 * Counts blocks recursively by type.
	 *
	 * @param array $blocks Blocks.
	 * @param array $acc    Accumulator.
	 * @return array
	 */
	protected static function count_block_types( $blocks, $acc = array() ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$name = $block['blockName'];
			$acc[ $name ] = isset( $acc[ $name ] ) ? $acc[ $name ] + 1 : 1;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$acc = self::count_block_types( $block['innerBlocks'], $acc );
			}
		}
		return $acc;
	}
}
