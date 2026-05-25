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
			'/divi/page/read'                  => array( 'handle_page_read', 'guard_read' ),
			'/divi/page/write'                 => array( 'handle_page_write', 'guard_write' ),
			'/divi/status'                     => array( 'handle_status', 'guard_read' ),
			'/divi/library/list'               => array( 'handle_library_list', 'guard_read' ),
			'/divi/library/item'               => array( 'handle_library_item', 'guard_read' ),
			'/divi/library/local'              => array( 'handle_library_local', 'guard_read' ),
			'/divi/cloud/status'               => array( 'handle_cloud_status', 'guard_read' ),
			'/divi/global-data'                => array( 'handle_global_data', 'guard_read' ),
			// Design system writes (Phase 6 — design system).
			'/divi/global-data/colors/update'  => array( 'handle_global_colors_update', 'guard_write' ),
			'/divi/global-data/fonts/update'   => array( 'handle_global_fonts_update', 'guard_write' ),
			'/divi/global-data/variables/update' => array( 'handle_global_variables_update', 'guard_write' ),
			'/divi/theme-options/get'          => array( 'handle_theme_options_get', 'guard_read' ),
			'/divi/theme-options/update'       => array( 'handle_theme_options_update', 'guard_write' ),
			'/divi/branding/get'               => array( 'handle_branding_get', 'guard_read' ),
			'/divi/branding/update'            => array( 'handle_branding_update', 'guard_write' ),
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
			return IAWM_Support::rest_error(
				'invalid_mode',
				/* translators: %s: unsupported "mode" value. */
				sprintf( __( 'Unknown mode: %s.', 'ia-webmaster-bridge' ), $mode ),
				400
			);
		}
		if ( $post_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', __( 'post_id required.', 'ia-webmaster-bridge' ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return IAWM_Support::rest_error(
				'post_not_found',
				/* translators: %d: post ID. */
				sprintf( __( 'Post %d not found.', 'ia-webmaster-bridge' ), $post_id ),
				404
			);
		}

		$uses_builder = 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true );
		$has_d5_marker = false !== strpos( $post->post_content, '<!-- wp:divi/' );

		if ( ! $uses_builder && ! $has_d5_marker ) {
			return IAWM_Support::rest_error(
				'not_a_divi_page',
				sprintf(
					/* translators: %d: post ID. */
					__( 'Post %d is not a Divi 5 page (neither meta _et_pb_use_builder=on, nor wp:divi/ blocks detected).', 'ia-webmaster-bridge' ),
					$post_id
				),
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
			return IAWM_Support::rest_error(
				'invalid_type',
				sprintf(
					/* translators: %s: comma-separated list of valid type values. */
					__( 'Invalid type. Expected: %s', 'ia-webmaster-bridge' ),
					implode( ', ', $valid_types )
				),
				400
			);
		}

		$res = self::call_divi_route( '/divi-library', 'POST', array(
			'type'    => $type,
			'exclude' => $exclude,
		) );

		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'divi_library_failed', __( 'Call to divi-library failed.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
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
			return IAWM_Support::rest_error( 'invalid_id', __( 'id required.', 'ia-webmaster-bridge' ), 400 );
		}

		$res = self::call_divi_route( '/divi-library/item', 'POST', array(
			'id'           => $params['id'],
			'libraryType'  => isset( $params['library_type'] ) ? $params['library_type'] : 'layout',
			'builtFor'     => isset( $params['built_for'] ) ? $params['built_for'] : 'page',
			'contentType'  => isset( $params['content_type'] ) ? $params['content_type'] : 'layout',
		) );

		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'divi_library_item_failed', __( 'Call to divi-library/item failed.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
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
	 * Returns the three pieces of the design system the caller will need
	 * before authoring pages: global colors (the `gcid-*` palette),
	 * global fonts (heading / body), and global variables (the typed
	 * design tokens — numbers, strings, images, links, colors, fonts).
	 * The matching write endpoints are exposed under
	 * `/divi/global-data/{colors,fonts,variables}/update`.
	 *
	 * Implementation: piggy-backs on a Divi library item call (the
	 * Divi REST response always carries globalColors + globalVariables
	 * inline) plus reads the saved `heading_font` / `body_font` ePanel
	 * options for the fonts piece.
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
			return IAWM_Support::rest_error( 'global_data_unavailable', __( 'Could not retrieve the Divi design system.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		$data = (array) $res['data'];

		// Read the saved fonts from ePanel options (the heading/body fonts
		// are persisted there alongside the global_variables.fonts entries).
		$heading_font = function_exists( 'et_get_option' ) ? et_get_option( 'heading_font', 'Open Sans' ) : 'Open Sans';
		$body_font    = function_exists( 'et_get_option' ) ? et_get_option( 'body_font', 'Open Sans' ) : 'Open Sans';

		return new WP_REST_Response(
			array(
				'ok'               => true,
				'global_colors'    => isset( $data['globalColors'] ) ? $data['globalColors'] : null,
				'global_variables' => isset( $data['globalVariables'] ) ? $data['globalVariables'] : null,
				'global_fonts'     => array(
					'heading_font' => $heading_font,
					'body_font'    => $body_font,
				),
			),
			200
		);
	}

	/* ---------------------------------------------------------------- */
	/* Design system writes                                              */
	/* ---------------------------------------------------------------- */

	/**
	 * POST /divi/global-data/colors/update — replaces the global-colors palette.
	 *
	 * Body: { global_colors: { "gcid-...": { color, lastUpdated?, status?, usedInPosts? }, ... }, dry_run? }
	 *
	 * The full palette is sent (this matches Divi's own contract — the
	 * upstream endpoint replaces the palette wholesale). Use
	 * `/divi/global-data` to read the current palette first, then add /
	 * change entries and send back. Missing entries are removed.
	 *
	 * A common pattern: call `/divi/global-data`, mutate the response's
	 * `global_colors`, post it back here. The agent gets to choose
	 * stable `gcid-*` ids (e.g. `gcid-primary-color` for an explicit
	 * brand colour, or a `gcid-<uuid>` for a custom one).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_global_colors_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$colors = isset( $params['global_colors'] ) && is_array( $params['global_colors'] ) ? $params['global_colors'] : null;
		if ( null === $colors || empty( $colors ) ) {
			return IAWM_Support::rest_error( 'iawm_missing_global_colors', __( 'Provide a non-empty `global_colors` object.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_set'    => array_keys( $colors ),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		// Normalise: ensure each entry has the canonical shape Divi expects.
		$now = gmdate( 'Y-m-d\TH:i:s.000\Z' );
		$normalised = array();
		foreach ( $colors as $id => $entry ) {
			$entry = is_array( $entry ) ? $entry : array();
			$normalised[ (string) $id ] = array(
				'color'       => isset( $entry['color'] ) ? (string) $entry['color'] : '#000000',
				'lastUpdated' => isset( $entry['lastUpdated'] ) ? (string) $entry['lastUpdated'] : $now,
				'status'      => isset( $entry['status'] ) ? (string) $entry['status'] : 'active',
				'usedInPosts' => isset( $entry['usedInPosts'] ) && is_array( $entry['usedInPosts'] ) ? $entry['usedInPosts'] : array(),
			);
		}

		$res = self::call_divi_route( '/global-data/global-colors', 'POST', array(
			'global_colors' => $normalised,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'global_colors_update_failed', __( 'Divi rejected the update.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => true,
				'palette' => $res['data'],
			),
			200
		);
	}

	/**
	 * POST /divi/global-data/fonts/update — sets the global heading + body fonts.
	 *
	 * Body: { heading_font?, body_font?, dry_run? }
	 *
	 * Either or both fields can be omitted; the omitted one is left
	 * unchanged. Values are Google Fonts (or 'system fonts') family
	 * names exactly as Divi expects ('Open Sans', 'Roboto', 'Inter',
	 * 'Arial', 'Georgia', …).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_global_fonts_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$heading = isset( $params['heading_font'] ) ? (string) $params['heading_font'] : '';
		$body    = isset( $params['body_font'] ) ? (string) $params['body_font'] : '';
		if ( '' === $heading && '' === $body ) {
			return IAWM_Support::rest_error( 'iawm_missing_fonts', __( 'Provide at least one of `heading_font` or `body_font`.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'dry_run' => true,
					'would_set' => array(
						'heading_font' => '' !== $heading ? $heading : null,
						'body_font'    => '' !== $body ? $body : null,
					),
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		// Divi's endpoint expects BOTH params. Fill the missing one with
		// the currently-saved value to avoid clobbering it with empty.
		if ( '' === $heading ) {
			$heading = function_exists( 'et_get_option' ) ? (string) et_get_option( 'heading_font', 'Open Sans' ) : 'Open Sans';
		}
		if ( '' === $body ) {
			$body = function_exists( 'et_get_option' ) ? (string) et_get_option( 'body_font', 'Open Sans' ) : 'Open Sans';
		}

		$res = self::call_divi_route( '/global-data/global-fonts', 'POST', array(
			'heading_font' => $heading,
			'body_font'    => $body,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'global_fonts_update_failed', __( 'Divi rejected the update.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'updated'      => true,
				'heading_font' => $heading,
				'body_font'    => $body,
			),
			200
		);
	}

	/**
	 * POST /divi/global-data/variables/update — replaces the global variables.
	 *
	 * Body: { global_variables: { numbers: {...}, strings: {...},
	 *         images: {...}, links: {...}, colors: {...}, fonts: {...} },
	 *         dry_run? }
	 *
	 * Each bucket is a map keyed by `gvid-<id>` -> { label, value, order,
	 * status }. Use `/divi/global-data` to read first, mutate, and post
	 * back. Like colors, this is a full-replace operation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_global_variables_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$vars = isset( $params['global_variables'] ) && is_array( $params['global_variables'] ) ? $params['global_variables'] : null;
		if ( null === $vars ) {
			return IAWM_Support::rest_error( 'iawm_missing_global_variables', __( 'Provide `global_variables` as an object with keys among numbers, strings, images, links, colors, fonts.', 'ia-webmaster-bridge' ), 400 );
		}

		// Bucket-level validation.
		$known_buckets = array( 'numbers', 'strings', 'images', 'links', 'colors', 'fonts' );
		foreach ( $vars as $bucket => $_unused ) {
			if ( ! in_array( $bucket, $known_buckets, true ) ) {
				return IAWM_Support::rest_error(
					'iawm_unknown_variable_bucket',
					sprintf(
						/* translators: 1: unknown bucket name. 2: comma-separated list of valid buckets. */
						__( 'Unknown bucket: %1$s. Use one of: %2$s', 'ia-webmaster-bridge' ),
						$bucket,
						implode( ', ', $known_buckets )
					),
					400
				);
			}
		}

		if ( ! empty( $params['dry_run'] ) ) {
			$summary = array();
			foreach ( $vars as $bucket => $entries ) {
				$summary[ $bucket ] = is_array( $entries ) ? array_keys( $entries ) : array();
			}
			return new WP_REST_Response(
				array(
					'ok'        => true,
					'dry_run'   => true,
					'would_set' => $summary,
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$res = self::call_divi_route( '/global-data/global-variables', 'POST', array(
			'global_variables' => $vars,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'global_variables_update_failed', __( 'Divi rejected the update.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'updated'   => true,
				'variables' => $res['data'],
			),
			200
		);
	}

	/* ---------------------------------------------------------------- */
	/* Branding (logo, favicon, brand-level et_divi keys)                */
	/* ---------------------------------------------------------------- */

	/**
	 * Keys we let the agent read/write inside the `et_divi` option.
	 * Divi's narrow `theme-options/update` allow-list excludes these
	 * (it only covers customizer keys); branding settings live in the
	 * `et_divi` WP option directly.
	 *
	 * Curated rather than wildcard so we cannot accidentally clobber
	 * unrelated et_divi sub-keys (color schemes, social IDs, etc.) the
	 * site owner may have configured manually.
	 *
	 * @return string[]
	 */
	protected static function branding_allowlist() {
		return array(
			'divi_logo',          // Header / general logo URL.
			'divi_favicon',       // Favicon URL.
			'divi_logo_dark',     // Dark-mode logo, if the theme uses one.
			'divi_logo_mobile',   // Mobile-specific logo, if used.
			'divi_logo_phone',    // Phone-specific logo (Divi variant).
			'divi_logo_tablet',   // Tablet-specific logo.
		);
	}

	/**
	 * POST /divi/branding/get — reads the branding-related slice of
	 * the `et_divi` WP option.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_branding_get( $request ) {
		unset( $request );

		$et_divi = get_option( 'et_divi', array() );
		if ( ! is_array( $et_divi ) ) {
			$et_divi = array();
		}

		$branding = array();
		foreach ( self::branding_allowlist() as $key ) {
			$branding[ $key ] = array_key_exists( $key, $et_divi ) ? $et_divi[ $key ] : null;
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'branding' => $branding,
				'allowed'  => self::branding_allowlist(),
			),
			200
		);
	}

	/**
	 * POST /divi/branding/update — writes the branding-related slice of
	 * the `et_divi` WP option.
	 *
	 * Body: { branding: { key: value, ... }, dry_run? }
	 *
	 * Only keys on the allow-list are accepted; everything else is
	 * reported under `rejected`. Auto-backup of the `et_divi` option
	 * is taken before writing (pre_op_backup_id returned).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_branding_update( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$branding = isset( $params['branding'] ) && is_array( $params['branding'] ) ? $params['branding'] : null;
		if ( null === $branding || empty( $branding ) ) {
			return IAWM_Support::rest_error( 'iawm_missing_branding', __( 'Provide a non-empty `branding` object.', 'ia-webmaster-bridge' ), 400 );
		}

		$allow    = self::branding_allowlist();
		$current  = get_option( 'et_divi', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$applied  = array();
		$rejected = array();
		foreach ( $branding as $key => $value ) {
			if ( ! in_array( $key, $allow, true ) ) {
				$rejected[ $key ] = __( 'Not in the branding allow-list.', 'ia-webmaster-bridge' );
				continue;
			}
			// URL-like keys should be a URL string (or empty to clear).
			if ( '' === $value || null === $value ) {
				$applied[ $key ] = '';
				continue;
			}
			$applied[ $key ] = esc_url_raw( (string) $value );
		}

		if ( empty( $applied ) ) {
			return IAWM_Support::rest_error(
				'iawm_no_valid_branding',
				sprintf(
					/* translators: %s: comma-separated list of rejected branding keys. */
					__( 'No valid branding key to apply. Rejected: %s.', 'ia-webmaster-bridge' ),
					implode( ', ', array_keys( $rejected ) )
				),
				400,
				array( 'rejected' => $rejected )
			);
		}

		if ( ! empty( $params['dry_run'] ) ) {
			$diff = array();
			foreach ( $applied as $key => $value ) {
				$diff[ $key ] = array(
					'from' => array_key_exists( $key, $current ) ? $current[ $key ] : null,
					'to'   => $value,
				);
			}
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_change' => $diff,
					'rejected'     => $rejected,
				),
				200
			);
		}

		// Pre-op safety net: snapshot the entire et_divi option.
		$pre_backup = empty( $params['skip_backup'] ) && class_exists( 'IAWM_Backup' )
			? IAWM_Backup::snapshot_options(
				array( 'et_divi' ),
				sprintf(
					/* translators: %s: comma-separated list of branding keys being updated. */
					__( 'Before branding update: %s', 'ia-webmaster-bridge' ),
					implode( ', ', array_keys( $applied ) )
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		$merged = array_merge( $current, $applied );
		update_option( 'et_divi', $merged );

		$response = array(
			'ok'           => true,
			'updated'      => true,
			'applied'      => $applied,
			'rejected'     => $rejected,
			'changed_keys' => array_keys( $applied ),
		);
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/* ---------------------------------------------------------------- */
	/* Theme options                                                     */
	/* ---------------------------------------------------------------- */

	/**
	 * POST /divi/theme-options/get — read Divi's theme-options panel.
	 *
	 * Wraps Divi's `outside-vb/theme-options/get` route which returns
	 * the full set of options stored under the `et_divi` option key —
	 * site logo, favicon, layout settings, performance switches,
	 * integration headers/footers, etc. Treat the response as
	 * opaque-ish: keys are documented in Divi's ePanel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_theme_options_get( $request ) {
		unset( $request );
		$res = self::call_divi_route( '/outside-vb/theme-options/get', 'POST', array() );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'theme_options_get_failed', __( 'Could not retrieve theme options.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}
		return new WP_REST_Response( array( 'ok' => true, 'theme_options' => $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-options/update — write Divi's theme-options panel.
	 *
	 * Body: { options: { key: value, ... }, dry_run? }
	 *
	 * Divi's upstream `outside-vb/theme-options/update` route is a
	 * **single-key/value contract** with a strict allow-list of 17
	 * customizer options (`divi_blog_style`, `heading_font`,
	 * `body_font`, `heading_font_weight`, `body_font_weight`,
	 * `body_font_height`, `body_font_size`, `body_header_size`,
	 * `content_width`, `accent_color`, `et_pb_static_css_file`,
	 * `et_pb_css_in_footer`, `gutter_width`, `vertical_nav`,
	 * `header_style`, `color_schemes`, `divi_disable_translations`).
	 * Our wrapper accepts the more ergonomic bag-of-keys shape and
	 * **loops** over Divi's endpoint, one call per key. Values are
	 * coerced to string (Divi rejects non-string values).
	 *
	 * Logo, favicon and other ePanel settings outside this allow-list
	 * live in the `et_divi` WordPress option and are NOT writable
	 * through this route — Divi guards them tightly through its
	 * Customizer. They will be addressed by a dedicated endpoint in a
	 * later iteration if needed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_theme_options_update( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$updates = isset( $params['options'] ) && is_array( $params['options'] ) ? $params['options'] : null;
		if ( null === $updates || empty( $updates ) ) {
			return IAWM_Support::rest_error( 'iawm_missing_options', __( 'Provide a non-empty `options` object.', 'ia-webmaster-bridge' ), 400 );
		}

		// Fetch current options to populate the dry-run diff.
		$current      = self::call_divi_route( '/outside-vb/theme-options/get', 'POST', array() );
		$current_data = ( $current['status'] < 400 && is_array( $current['data'] ) && isset( $current['data']['options'] ) && is_array( $current['data']['options'] ) )
			? $current['data']['options']
			: array();

		if ( ! empty( $params['dry_run'] ) ) {
			$diff = array();
			foreach ( $updates as $k => $v ) {
				$diff[ $k ] = array(
					'from' => array_key_exists( $k, $current_data ) ? $current_data[ $k ] : null,
					'to'   => $v,
				);
			}
			return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'would_change' => $diff ), 200 );
		}

		IAWM_Support::act_as_agent();

		// Divi's update endpoint takes ONE { key, value } pair per call,
		// validates the key against its own allow-list, and coerces value
		// to string. Loop, collecting per-key results.
		$applied  = array();
		$rejected = array();
		foreach ( $updates as $key => $value ) {
			$res = self::call_divi_route(
				'/outside-vb/theme-options/update',
				'POST',
				array(
					'key'   => (string) $key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				)
			);
			if ( $res['status'] >= 400 ) {
				$rejected[ $key ] = array(
					'status'        => $res['status'],
					'divi_response' => $res['data'],
				);
			} else {
				$applied[ $key ] = isset( $res['data']['value'] ) ? $res['data']['value'] : true;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'           => empty( $rejected ),
				'updated'      => ! empty( $applied ),
				'applied'      => $applied,
				'rejected'     => $rejected,
				'changed_keys' => array_keys( $applied ),
			),
			empty( $rejected ) ? 200 : 207 // 207 Multi-Status when some keys failed.
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
			return IAWM_Support::rest_error( 'invalid_post_id', __( 'post_id required.', 'ia-webmaster-bridge' ), 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return IAWM_Support::rest_error(
				'post_not_found',
				/* translators: %d: post ID. */
				sprintf( __( 'Post %d not found.', 'ia-webmaster-bridge' ), $post_id ),
				404
			);
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
				__( 'Provide "content" (serialised string) OR "blocks" (array of blocks).', 'ia-webmaster-bridge' ),
				400
			);
		}

		// Filter out empty blocks from parsing (whitespace).
		$real_blocks = array_values( array_filter( $blocks, function( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		if ( empty( $real_blocks ) ) {
			return IAWM_Support::rest_error( 'empty_layout', __( 'No Divi block detected in the payload.', 'ia-webmaster-bridge' ), 400 );
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
				__( 'The layout contains blocks outside the divi/ namespace.', 'ia-webmaster-bridge' ),
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
