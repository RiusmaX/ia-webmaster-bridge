<?php
/**
 * Divi 5 Theme Builder — creates templates (headers, footers, body)
 * dynamically applied to the site.
 *
 * Divi 5 data model:
 *  - 1 `et_theme_builder` post (global container) with `_et_template` meta
 *    pointing to the `et_template` post.
 *  - N `et_template` posts each with:
 *      - meta `_et_default` (1 = site's default template)
 *      - meta `_et_enabled`
 *      - meta `_et_header_layout_id` -> `et_header_layout` post
 *      - meta `_et_body_layout_id`   -> `et_body_layout` post
 *      - meta `_et_footer_layout_id` -> `et_footer_layout` post
 *      - per zone: meta `_et_<zone>_layout_enabled`, `_et_<zone>_layout_global`,
 *        `_et_<zone>_layout_override`.
 *  - Each `et_header_layout` / `et_body_layout` / `et_footer_layout` is a
 *    WP post with `post_content` = Divi 5 blocks (same format as pages).
 *  - Assignment via `use_on` and `exclude_from` (handled by the REST route
 *    `divi/v1/outside-vb/theme-builder/assign-template`).
 *
 * Exposed routes:
 *  - /divi/theme-builder/list                  — proxy + layout details
 *  - /divi/theme-builder/template/create       — proxy create-template
 *  - /divi/theme-builder/template/update       — proxy update-template
 *  - /divi/theme-builder/template/delete       — proxy delete-template
 *  - /divi/theme-builder/template/assign       — proxy assign-template
 *  - /divi/theme-builder/layout/create         — creates an et_*_layout with
 *                                                Divi 5 content + returns the id.
 *  - /divi/theme-builder/layout/read           — reads an et_*_layout as a Divi
 *                                                structure (reuses IAWM_Divi).
 *  - /divi/theme-builder/setup-site-defaults   — high-level wrapper that creates
 *                                                in one call the container +
 *                                                default template + header
 *                                                + body + footer.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Divi Theme Builder routes.
 */
class IAWM_Divi_Theme_Builder {

	const ZONES = array( 'header', 'body', 'footer' );

	/** Mapping zone -> post_type. */
	const ZONE_POST_TYPE = array(
		'header' => 'et_header_layout',
		'body'   => 'et_body_layout',
		'footer' => 'et_footer_layout',
	);

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
	 * Internal call to a nonce-protected divi/v1 route.
	 *
	 * @param string $route  Relative route (e.g. "/outside-vb/theme-builder/list-templates").
	 * @param array  $body   JSON body.
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
	 * POST /divi/theme-builder/list — lists the site's templates with
	 * details about the assigned layouts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );
		$live   = isset( $params['live'] ) ? (bool) $params['live'] : true;

		$res = self::call_divi( '/outside-vb/theme-builder/list-templates', array( 'live' => $live ) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'theme_builder_list_failed', __( 'Failed to list templates.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		// Enrich: for each template, add the titles of related layouts.
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
	 * POST /divi/theme-builder/template/create — creates a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_template_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$title  = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : __( 'New Template', 'ia-webmaster-bridge' );
		$live   = isset( $params['live'] ) ? (bool) $params['live'] : true;

		$res = self::call_divi( '/outside-vb/theme-builder/create-template', array(
			'live'  => $live,
			'title' => $title,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_create_failed', __( 'Failed to create template.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/template/update — updates a template
	 * (title, assigned layouts, state).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_template_update( $request ) {
		$params      = IAWM_Support::json_params( $request );
		$template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$live        = isset( $params['live'] ) ? (bool) $params['live'] : true;
		$template    = isset( $params['template'] ) && is_array( $params['template'] ) ? $params['template'] : array();

		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_template_id', __( 'template_id required.', 'ia-webmaster-bridge' ), 400 );
		}

		$res = self::call_divi( '/outside-vb/theme-builder/update-template', array(
			'live'        => $live,
			'template_id' => $template_id,
			'template'    => $template,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_update_failed', __( 'Failed to update template.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/template/delete — deletes a template.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_template_delete( $request ) {
		$params      = IAWM_Support::json_params( $request );
		$template_id = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$live        = isset( $params['live'] ) ? (bool) $params['live'] : true;

		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_template_id', __( 'template_id required.', 'ia-webmaster-bridge' ), 400 );
		}

		$res = self::call_divi( '/outside-vb/theme-builder/delete-template', array(
			'live'        => $live,
			'template_id' => $template_id,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_delete_failed', __( 'Deletion failed.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/template/assign — assigns a template to
	 * conditions (use_on) and exceptions (exclude_from).
	 *
	 * Examples of Divi conditions:
	 *  - "default"          -> site default template (everything)
	 *  - "singular:page"    -> all pages
	 *  - "singular:post"    -> all posts
	 *  - "page:123"         -> the page with id 123
	 *  - "archive:category" -> all category archive pages
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_template_assign( $request ) {
		$params       = IAWM_Support::json_params( $request );
		$template_id  = isset( $params['template_id'] ) ? (int) $params['template_id'] : 0;
		$live         = isset( $params['live'] ) ? (bool) $params['live'] : true;
		$use_on       = isset( $params['use_on'] ) && is_array( $params['use_on'] ) ? $params['use_on'] : array();
		$exclude_from = isset( $params['exclude_from'] ) && is_array( $params['exclude_from'] ) ? $params['exclude_from'] : array();

		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_template_id', __( 'template_id required.', 'ia-webmaster-bridge' ), 400 );
		}

		$res = self::call_divi( '/outside-vb/theme-builder/assign-template', array(
			'live'         => $live,
			'template_id'  => $template_id,
			'use_on'       => $use_on,
			'exclude_from' => $exclude_from,
		) );
		if ( $res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_assign_failed', __( 'Assignment failed.', 'ia-webmaster-bridge' ), $res['status'], array( 'divi_response' => $res['data'] ) );
		}

		return new WP_REST_Response( array_merge( array( 'ok' => true ), (array) $res['data'] ), 200 );
	}

	/**
	 * POST /divi/theme-builder/layout/create — creates a physical layout
	 * (et_header_layout / et_body_layout / et_footer_layout) with its
	 * Divi 5 content.
	 *
	 * Parameters:
	 *   - zone (string, required): "header" | "body" | "footer".
	 *   - title (string, optional): layout title.
	 *   - content (string, optional): raw post_content (Divi 5 blocks).
	 *   - blocks (array, optional): alternative to content, parse_blocks array.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_layout_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$zone   = isset( $params['zone'] ) ? (string) $params['zone'] : '';
		$title  = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : '';

		if ( ! in_array( $zone, self::ZONES, true ) ) {
			return IAWM_Support::rest_error( 'invalid_zone', __( 'zone must be header, body or footer.', 'ia-webmaster-bridge' ), 400 );
		}

		$post_type = self::ZONE_POST_TYPE[ $zone ];

		// Content: either "content" (string) or "blocks" (parse_blocks array).
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

		// Meta to signal this is a Divi 5 layout.
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
	 * POST /divi/theme-builder/layout/read — reads a layout's content
	 * (et_header_layout / et_body_layout / et_footer_layout) as a Divi
	 * structure.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_layout_read( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$mode   = isset( $params['mode'] ) ? (string) $params['mode'] : 'tree';

		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', __( 'post_id required.', 'ia-webmaster-bridge' ), 400 );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return IAWM_Support::rest_error( 'not_found', "Layout {$id} not found.", 404 );
		}
		if ( ! in_array( $post->post_type, self::ZONE_POST_TYPE, true ) ) {
			return IAWM_Support::rest_error(
				'not_a_layout',
				"Post {$id} is not a Theme Builder layout (type={$post->post_type}).",
				400
			);
		}

		// Reuse IAWM_Divi::handle_page_read internally.
		$req = new WP_REST_Request( 'POST', '/' . IAWM_REST_NAMESPACE . '/divi/page/read' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'post_id' => $id, 'mode' => $mode ) ) );

		return IAWM_Divi::handle_page_read( $req );
	}

	/**
	 * POST /divi/theme-builder/setup-site-defaults — high-level wrapper:
	 * creates the Theme Builder container + a default template with header,
	 * body and footer (depending on what is provided), and assigns the
	 * template as the site's default.
	 *
	 * Parameters:
	 *   - title (string, optional): template title (default: "Default Site Template").
	 *   - header (object, optional): { title?, content?, blocks? }.
	 *   - body   (object, optional): { title?, content?, blocks? }.
	 *   - footer (object, optional): { title?, content?, blocks? }.
	 *   - assign_default (bool, default true): if true, the template becomes
	 *     the site default (applied to all posts/pages without override).
	 *
	 * If a default template already exists, refuses the operation (to avoid
	 * overwriting). The user must delete it manually or pass
	 * `replace_existing=true`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_setup_site_defaults( $request ) {
		$params        = IAWM_Support::json_params( $request );
		$title         = isset( $params['title'] ) ? sanitize_text_field( (string) $params['title'] ) : 'Default Site Template';
		$assign_default = ! isset( $params['assign_default'] ) || (bool) $params['assign_default'];
		$replace        = ! empty( $params['replace_existing'] );

		// Get the payloads per zone (header/body/footer).
		$zone_inputs = array();
		foreach ( self::ZONES as $zone ) {
			if ( isset( $params[ $zone ] ) && is_array( $params[ $zone ] ) ) {
				$zone_inputs[ $zone ] = $params[ $zone ];
			}
		}

		if ( empty( $zone_inputs ) ) {
			return IAWM_Support::rest_error(
				'no_layouts',
				'At least one of header / body / footer must be provided.',
				400
			);
		}

		// Check for the existence of a default template.
		$existing = self::call_divi( '/outside-vb/theme-builder/list-templates', array( 'live' => true ) );
		$tmpls    = isset( $existing['data']['templates'] ) ? $existing['data']['templates'] : array();
		foreach ( $tmpls as $t ) {
			if ( ! empty( $t['default'] ) && ! $replace ) {
				return IAWM_Support::rest_error(
					'default_template_exists',
					"A default template already exists (id={$t['id']}). Pass replace_existing=true to overwrite.",
					409,
					array( 'existing_template' => $t )
				);
			}
		}

		IAWM_Support::act_as_agent();

		// 1. Create the physical layouts.
		$layouts = array();
		foreach ( $zone_inputs as $zone => $layout_input ) {
			$content      = '';
			if ( isset( $layout_input['content'] ) && is_string( $layout_input['content'] ) ) {
				$content = $layout_input['content'];
			} elseif ( isset( $layout_input['blocks'] ) && is_array( $layout_input['blocks'] ) ) {
				$content = serialize_blocks( $layout_input['blocks'] );
			}
			$layout_title = isset( $layout_input['title'] ) ? sanitize_text_field( (string) $layout_input['title'] ) : ucfirst( $zone ) . ' - ' . $title;

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
				return IAWM_Support::rest_error( 'layout_create_failed', "Failed to create {$zone}: " . $post_id->get_error_message(), 500 );
			}
			update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
			update_post_meta( $post_id, '_et_pb_built_for_post_type', self::ZONE_POST_TYPE[ $zone ] );
			$layouts[ $zone ] = $post_id;
		}

		// 2. Create the template via the Divi route.
		$create_res = self::call_divi( '/outside-vb/theme-builder/create-template', array(
			'live'  => true,
			'title' => $title,
		) );
		if ( $create_res['status'] >= 400 ) {
			return IAWM_Support::rest_error( 'template_create_failed', 'Failed to create template.', $create_res['status'], array( 'divi_response' => $create_res['data'] ) );
		}
		$template_id = isset( $create_res['data']['id'] ) ? (int) $create_res['data']['id'] : 0;
		if ( $template_id <= 0 ) {
			return IAWM_Support::rest_error( 'no_template_id', 'No template id returned.', 500, array( 'divi_response' => $create_res['data'] ) );
		}

		// 3. Link the layouts via update-template.
		// Divi logic (theme-builder.php):
		//   override = (id != 0) OR (enabled === false)
		// So for a zone to REMAIN in native WordPress rendering (e.g. body
		// displaying the current page's post_content), it must be
		// enabled = TRUE and id = 0. If we set enabled = false, Divi takes
		// control of the zone and renders nothing without a custom layout.
		$template_payload = array(
			'id'      => $template_id,
			'title'   => $title,
			'enabled' => true,
			'default' => $assign_default,
			'layouts' => array(),
		);
		foreach ( array( 'header', 'body', 'footer' ) as $zone ) {
			$has = isset( $layouts[ $zone ] );
			$template_payload['layouts'][ $zone ] = array(
				'id'       => $has ? $layouts[ $zone ] : 0,
				// IMPORTANT: enabled=true even without a layout — otherwise Divi
				// overrides the zone and removes it from the rendering.
				'enabled'  => true,
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
			return IAWM_Support::rest_error( 'template_link_failed', 'Failed to assign layouts.', $update_res['status'], array( 'divi_response' => $update_res['data'] ) );
		}

		// 4. If requested, mark it as default (use_on assignment).
		if ( $assign_default ) {
			// We set the _et_default=1 meta directly; and call assign-template
			// with empty use_on (default catches everything not explicitly assigned).
			update_post_meta( $template_id, '_et_default', '1' );
		}

		// 5. Force in post_meta for zones without a provided layout:
		// id=0 + enabled=1 -> the zone stays in native WordPress rendering.
		// (See the override logic in Divi: id!=0 OR !enabled.)
		foreach ( self::ZONES as $zone ) {
			if ( ! isset( $layouts[ $zone ] ) ) {
				update_post_meta( $template_id, "_et_{$zone}_layout_enabled", '1' );
				update_post_meta( $template_id, "_et_{$zone}_layout_id", '0' );
			}
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
