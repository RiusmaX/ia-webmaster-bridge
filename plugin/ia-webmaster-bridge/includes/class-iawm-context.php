<?php
/**
 * Per-site context — Phase 6 (spec 07 "Webmaster layer").
 *
 * A structured place to record the **site-specific knowledge** an
 * operator wants Claude to use when acting on this site: brand voice,
 * audience, do/don't lists, editorial defaults, design notes,
 * infrastructure preferences, free-form notes. Stored as a single WP
 * option so every operator + Claude session sees the same context —
 * the "memory" sticks with the site, not the workstation.
 *
 * Claude reads this BEFORE acting (the `webmaster-wordpress` skill and
 * its descendants pull this first in their preflight). Writes happen
 * rarely — usually once at install time via the discovery skill, then
 * occasional updates from the admin UI.
 *
 * Schema is intentionally lightweight: a versioned associative array
 * with top-level sections {brand, content, design, infrastructure,
 * notes}. Missing keys are tolerated; updates use merge semantics.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site context read/write.
 */
class IAWM_Context {

	/** Option storing the per-site context. */
	const OPTION_KEY = 'iawm_site_context';

	/** Current schema version of the stored structure. */
	const SCHEMA_VERSION = 1;

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the /site-context/* routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/site-context/get'    => array( 'handle_get', 'guard_read' ),
			'/site-context/update' => array( 'handle_update', 'guard_write' ),
			'/site-context/clear'  => array( 'handle_clear', 'guard_write' ),
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
	 * Returns the default, empty-but-shaped context. Used as a fallback
	 * when the option is unset and as a reference for the admin form.
	 *
	 * @return array
	 */
	public static function default_context() {
		return array(
			'version'        => self::SCHEMA_VERSION,
			'brand'          => array(
				'name'      => '',
				'tagline'   => '',
				'voice'     => '',          // editorial tone (concise, formal, playful…).
				'audience'  => '',          // primary persona.
				'do_list'   => array(),     // editorial dos.
				'dont_list' => array(),     // editorial don'ts.
			),
			'content'        => array(
				'default_status'         => 'draft',  // never publish by default.
				'default_language'       => '',       // BCP-47 if set (e.g. fr-FR).
				'page_naming_convention' => '',
				'homepage_pattern'       => '',       // free-form layout summary.
				'main_cta'               => array(
					'label' => '',
					'url'   => '',
				),
			),
			'design'         => array(
				'palette_summary' => '',  // human description — actual values live in Divi global_data.
				'fonts_summary'   => '',
				'patterns_used'   => array(),  // names of Divi patterns the site relies on.
			),
			'infrastructure' => array(
				'plugins_required'  => array(),  // slugs the operator wants kept active.
				'plugins_forbidden' => array(),  // slugs that must NEVER be installed.
				'environment_note'  => '',       // e.g. "shared host, no shell access".
			),
			'notes'          => '',
			'updated_at'     => null,
			'updated_by'     => null,
		);
	}

	/**
	 * Returns the stored context merged with defaults so callers always
	 * see the full shape.
	 *
	 * @return array
	 */
	public static function get_context() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::deep_merge( self::default_context(), $stored );
	}

	/**
	 * Indicates whether the operator has filled anything beyond defaults.
	 *
	 * @return bool
	 */
	public static function is_populated() {
		$stored = get_option( self::OPTION_KEY, null );
		return is_array( $stored ) && ! empty( $stored );
	}

	/* ----------------------------------------------------------------- */
	/* Endpoints                                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * POST /site-context/get — returns the per-site context, defaults
	 * merged in.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_get( $request ) {
		unset( $request );
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'populated' => self::is_populated(),
				'context'   => self::get_context(),
			),
			200
		);
	}

	/**
	 * POST /site-context/update — merges the provided patch into the
	 * stored context. Sections are merged top-level; arrays inside
	 * sections are REPLACED wholesale (so the caller controls list
	 * membership). Operator-only fields (`updated_at`, `updated_by`,
	 * `version`) are always set server-side.
	 *
	 * Body: { context: { ... partial patch ... }, dry_run? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$patch  = isset( $params['context'] ) && is_array( $params['context'] ) ? $params['context'] : null;
		if ( null === $patch ) {
			return IAWM_Support::rest_error(
				'iawm_missing_context',
				__( 'Provide a `context` object.', 'ia-webmaster-bridge' ),
				400
			);
		}

		$current = self::get_context();
		$merged  = self::deep_merge( $current, $patch );

		// Server-set metadata.
		$merged['version']    = self::SCHEMA_VERSION;
		$merged['updated_at'] = gmdate( 'c' );
		$merged['updated_by'] = (string) $request->get_header( 'X-IAWM-Key' );

		if ( ! empty( $params['dry_run'] ) ) {
			$diff = array();
			foreach ( $merged as $k => $v ) {
				$before = $current[ $k ] ?? null;
				if ( $v !== $before ) {
					$diff[ $k ] = array( 'from' => $before, 'to' => $v );
				}
			}
			return new WP_REST_Response(
				array( 'ok' => true, 'dry_run' => true, 'would_change' => $diff ),
				200
			);
		}

		update_option( self::OPTION_KEY, $merged, false );

		return new WP_REST_Response(
			array( 'ok' => true, 'updated' => true, 'context' => $merged ),
			200
		);
	}

	/**
	 * POST /site-context/clear — wipes the context (back to defaults).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_clear( $request ) {
		unset( $request );
		delete_option( self::OPTION_KEY );
		return new WP_REST_Response( array( 'ok' => true, 'cleared' => true ), 200 );
	}

	/* ----------------------------------------------------------------- */
	/* Helpers                                                            */
	/* ----------------------------------------------------------------- */

	/**
	 * Recursively merges $b into $a. Associative arrays are merged
	 * key-by-key; numeric arrays are REPLACED (so the caller can shrink
	 * a do_list / patterns_used array).
	 *
	 * @param array $a Base.
	 * @param array $b Patch.
	 * @return array
	 */
	private static function deep_merge( $a, $b ) {
		if ( ! is_array( $a ) ) {
			return $b;
		}
		if ( ! is_array( $b ) ) {
			return $a;
		}
		$is_list = static function ( $arr ) {
			if ( ! is_array( $arr ) ) {
				return false;
			}
			$i = 0;
			foreach ( $arr as $k => $_v ) {
				if ( $k !== $i ) {
					return false;
				}
				++$i;
			}
			return true;
		};

		$out = $a;
		foreach ( $b as $k => $v ) {
			if ( is_array( $v ) && isset( $out[ $k ] ) && is_array( $out[ $k ] ) && ! $is_list( $v ) && ! $is_list( $out[ $k ] ) ) {
				$out[ $k ] = self::deep_merge( $out[ $k ], $v );
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}
}
