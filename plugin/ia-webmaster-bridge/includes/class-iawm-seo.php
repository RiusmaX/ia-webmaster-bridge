<?php
/**
 * SEO capability — read/write a page's SEO metadata.
 *
 * Relies on an SEO backend active on the site. Supported backends, in
 * order of priority, are:
 *  - Rank Math (`seo-by-rank-math/rank-math.php`).
 *  - Yoast SEO (`wordpress-seo/wp-seo.php`) — backlog.
 *
 * Field names are **normalised** in this API (independent of the
 * underlying plugin): meta_title, meta_description, focus_keyword,
 * canonical_url, robots_noindex, robots_nofollow, og_title, og_description,
 * og_image_id, twitter_title, twitter_description, twitter_image_id.
 *
 * Routes:
 *  - /seo/status        — detected backend, available capabilities.
 *  - /seo/page/get      — reads a post's SEO metadata.
 *  - /seo/page/update   — updates a post's SEO metadata.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO routes.
 */
class IAWM_Seo {

	/** Rank Math backend. */
	const BACKEND_RANK_MATH = 'rank-math';

	/** Yoast backend. */
	const BACKEND_YOAST = 'yoast';

	/** No SEO backend detected. */
	const BACKEND_NONE = 'none';

	/**
	 * Mapping normalised name -> Rank Math meta_key.
	 *
	 * @var array
	 */
	const RANK_MATH_MAP = array(
		'meta_title'           => 'rank_math_title',
		'meta_description'     => 'rank_math_description',
		'focus_keyword'        => 'rank_math_focus_keyword',
		'canonical_url'        => 'rank_math_canonical_url',
		'og_title'             => 'rank_math_facebook_title',
		'og_description'       => 'rank_math_facebook_description',
		'og_image_id'          => 'rank_math_facebook_image_id',
		'twitter_card_type'    => 'rank_math_twitter_card_type',
		'twitter_title'        => 'rank_math_twitter_title',
		'twitter_description'  => 'rank_math_twitter_description',
		'twitter_image_id'     => 'rank_math_twitter_image_id',
	);

	/**
	 * Mapping normalised name -> Yoast meta_key.
	 *
	 * @var array
	 */
	const YOAST_MAP = array(
		'meta_title'           => '_yoast_wpseo_title',
		'meta_description'     => '_yoast_wpseo_metadesc',
		'focus_keyword'        => '_yoast_wpseo_focuskw',
		'canonical_url'        => '_yoast_wpseo_canonical',
		'og_title'             => '_yoast_wpseo_opengraph-title',
		'og_description'       => '_yoast_wpseo_opengraph-description',
		'og_image_id'          => '_yoast_wpseo_opengraph-image-id',
		'twitter_title'        => '_yoast_wpseo_twitter-title',
		'twitter_description'  => '_yoast_wpseo_twitter-description',
		'twitter_image_id'     => '_yoast_wpseo_twitter-image-id',
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
			'/seo/status'      => array( 'handle_status', 'guard_read' ),
			'/seo/page/get'    => array( 'handle_page_get', 'guard_read' ),
			'/seo/page/update' => array( 'handle_page_update', 'guard_write' ),
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
	 * Determines the active SEO backend.
	 *
	 * @return string One of the BACKEND_* constants.
	 */
	public static function detect_backend() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( 'seo-by-rank-math/rank-math.php' ) ) {
			return self::BACKEND_RANK_MATH;
		}
		if ( is_plugin_active( 'wordpress-seo/wp-seo.php' ) ) {
			return self::BACKEND_YOAST;
		}
		return self::BACKEND_NONE;
	}

	/**
	 * Returns the normalised name -> meta_key mapping for a given backend.
	 *
	 * @param string $backend Backend (BACKEND_* constant).
	 * @return array
	 */
	protected static function get_map( $backend ) {
		switch ( $backend ) {
			case self::BACKEND_RANK_MATH:
				return self::RANK_MATH_MAP;
			case self::BACKEND_YOAST:
				return self::YOAST_MAP;
			default:
				return array();
		}
	}

	/**
	 * POST /seo/status — detects the active backend and its scope.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		unset( $request );

		$backend = self::detect_backend();
		$map     = self::get_map( $backend );

		return new WP_REST_Response(
			array(
				'ok'              => true,
				'backend'         => $backend,
				'supported_fields' => array_keys( $map ),
			),
			200
		);
	}

	/**
	 * POST /seo/page/get — reads a post's SEO metadata.
	 *
	 * Parameter: post_id (required).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_page_get( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;

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

		$backend = self::detect_backend();
		if ( self::BACKEND_NONE === $backend ) {
			return IAWM_Support::rest_error(
				'no_seo_backend',
				__( 'No active SEO plugin detected. Install Rank Math or Yoast.', 'ia-webmaster-bridge' ),
				503
			);
		}

		if ( self::BACKEND_YOAST === $backend ) {
			return IAWM_Support::rest_error(
				'yoast_not_implemented',
				__( 'The Yoast backend is not yet implemented (backlog).', 'ia-webmaster-bridge' ),
				501
			);
		}

		$map    = self::get_map( $backend );
		$fields = array();
		foreach ( $map as $name => $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			$fields[ $name ] = '' === $value ? null : $value;
		}

		// Rank Math specific: robots is a serialised list.
		if ( self::BACKEND_RANK_MATH === $backend ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			$robots = is_array( $robots ) ? $robots : array();
			$fields['robots_noindex']  = in_array( 'noindex', $robots, true );
			$fields['robots_nofollow'] = in_array( 'nofollow', $robots, true );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'backend' => $backend,
				'post_id' => $post_id,
				'fields'  => $fields,
			),
			200
		);
	}

	/**
	 * POST /seo/page/update — updates a post's SEO metadata.
	 *
	 * Parameters:
	 *   - post_id (int, required).
	 *   - fields (object, required): { meta_title, meta_description, ... }.
	 *   - dry_run (bool, default false).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_page_update( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$post_id = isset( $params['post_id'] ) ? (int) $params['post_id'] : 0;
		$fields  = isset( $params['fields'] ) && is_array( $params['fields'] ) ? $params['fields'] : array();
		$dry_run = ! empty( $params['dry_run'] );

		if ( $post_id <= 0 ) {
			return IAWM_Support::rest_error( 'invalid_post_id', __( 'post_id required.', 'ia-webmaster-bridge' ), 400 );
		}
		if ( empty( $fields ) ) {
			return IAWM_Support::rest_error( 'no_fields', __( 'No fields to update.', 'ia-webmaster-bridge' ), 400 );
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

		$backend = self::detect_backend();
		if ( self::BACKEND_NONE === $backend ) {
			return IAWM_Support::rest_error(
				'no_seo_backend',
				__( 'No active SEO plugin detected.', 'ia-webmaster-bridge' ),
				503
			);
		}
		if ( self::BACKEND_YOAST === $backend ) {
			return IAWM_Support::rest_error(
				'yoast_not_implemented',
				__( 'The Yoast backend is not yet implemented (backlog).', 'ia-webmaster-bridge' ),
				501
			);
		}

		$map      = self::get_map( $backend );
		$applied  = array();
		$rejected = array();

		// Robots (special case for Rank Math).
		$robots_update = null;
		if ( self::BACKEND_RANK_MATH === $backend
			&& ( isset( $fields['robots_noindex'] ) || isset( $fields['robots_nofollow'] ) )
		) {
			$existing = get_post_meta( $post_id, 'rank_math_robots', true );
			$existing = is_array( $existing ) ? $existing : array();

			if ( isset( $fields['robots_noindex'] ) ) {
				$existing = array_values( array_diff( $existing, array( 'noindex', 'index' ) ) );
				$existing[] = $fields['robots_noindex'] ? 'noindex' : 'index';
			}
			if ( isset( $fields['robots_nofollow'] ) ) {
				$existing = array_values( array_diff( $existing, array( 'nofollow', 'follow' ) ) );
				$existing[] = $fields['robots_nofollow'] ? 'nofollow' : 'follow';
			}
			$robots_update = array_values( array_unique( $existing ) );
			unset( $fields['robots_noindex'], $fields['robots_nofollow'] );
		}

		// Standard fields.
		foreach ( $fields as $name => $value ) {
			if ( ! isset( $map[ $name ] ) ) {
				$rejected[] = array( 'field' => $name, 'reason' => 'unknown_field' );
				continue;
			}

			$meta_key = $map[ $name ];
			$applied[] = array( 'field' => $name, 'meta_key' => $meta_key, 'value' => $value );

			if ( ! $dry_run ) {
				if ( null === $value || '' === $value ) {
					delete_post_meta( $post_id, $meta_key );
				} else {
					update_post_meta( $post_id, $meta_key, $value );
				}
			}
		}

		if ( null !== $robots_update && ! $dry_run ) {
			update_post_meta( $post_id, 'rank_math_robots', $robots_update );
			$applied[] = array( 'field' => 'robots', 'meta_key' => 'rank_math_robots', 'value' => $robots_update );
		} elseif ( null !== $robots_update ) {
			$applied[] = array( 'field' => 'robots', 'meta_key' => 'rank_math_robots', 'value' => $robots_update );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'backend'  => $backend,
				'post_id'  => $post_id,
				'dry_run'  => $dry_run,
				'applied'  => $applied,
				'rejected' => $rejected,
			),
			200
		);
	}
}
