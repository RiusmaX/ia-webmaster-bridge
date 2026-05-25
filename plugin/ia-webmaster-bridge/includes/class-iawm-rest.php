<?php
/**
 * Registration of the adapter's REST routes.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes in the ia-webmaster/v1 namespace.
 */
class IAWM_REST {

	/**
	 * Hooks route registration on rest_api_init.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers all routes in the namespace.
	 *
	 * @return void
	 */
	public static function register_routes() {
		// /ping — public diagnostic, returns no sensitive data.
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_ping' ),
				'permission_callback' => '__return_true',
			)
		);

		// /status — authenticated diagnostic: validates the HMAC signature.
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( 'IAWM_Auth', 'guard_read' ),
			)
		);

		// /audit — read the audit log (authenticated).
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_audit' ),
				'permission_callback' => array( 'IAWM_Auth', 'guard_read' ),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * GET /ping — confirms that the adapter is reachable.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_ping() {
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'service'   => 'IA Webmaster Bridge',
				'version'   => IAWM_VERSION,
				'namespace' => IAWM_REST_NAMESPACE,
				'site_url'  => get_site_url(),
				'env'       => self::environment(),
				'time'      => current_time( 'c' ),
			),
			200
		);
	}

	/**
	 * GET /status — diagnostic restricted to authenticated requests.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		// The key id that signed this request — resolved by IAWM_Auth.
		$caller_key = (string) $request->get_header( 'X-IAWM-Key' );
		$caller     = IAWM_Settings::get_by_key_id( $caller_key );
		$scopes     = IAWM_Settings::get_scopes( $caller_key );

		// Linked WP user (audit-level link, NOT the executor).
		$linked = null;
		if ( $caller && ! empty( $caller['linked_user_id'] ) ) {
			$linked_user = get_userdata( (int) $caller['linked_user_id'] );
			if ( $linked_user instanceof WP_User ) {
				$linked = array(
					'id'           => (int) $linked_user->ID,
					'login'        => $linked_user->user_login,
					'display_name' => $linked_user->display_name,
				);
			}
		}

		$agent_id   = class_exists( 'IAWM_Agent_User' ) ? IAWM_Agent_User::get_user_id() : 0;
		$agent_user = $agent_id > 0 ? get_userdata( $agent_id ) : null;

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'authenticated' => true,
				'service'       => 'IA Webmaster Bridge',
				'version'       => IAWM_VERSION,
				'key_id'        => $caller_key,
				'key_label'     => $caller && isset( $caller['label'] ) ? $caller['label'] : null,
				'linked_user'   => $linked,
				'scopes'        => null === $scopes ? '*' : $scopes,
				'kill_switch'   => IAWM_Settings::is_kill_switch_on(),
				'agent_user'    => $agent_user instanceof WP_User
					? array(
						'id'    => (int) $agent_user->ID,
						'login' => $agent_user->user_login,
						'role'  => IAWM_Agent_User::ROLE_KEY,
					)
					: null,
				'total_keys'    => count( IAWM_Settings::all_credentials() ),
				'env'           => self::environment(),
				'time'          => current_time( 'c' ),
			),
			200
		);
	}

	/**
	 * GET /audit — returns the most recent audit log entries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_audit( $request ) {
		$entries = IAWM_Audit::get_recent( (int) $request->get_param( 'limit' ) );

		// Decode the detail field (stored as JSON) for the response.
		foreach ( $entries as &$entry ) {
			if ( isset( $entry['detail'] ) && is_string( $entry['detail'] ) ) {
				$decoded         = json_decode( $entry['detail'], true );
				$entry['detail'] = ( null === $decoded ) ? $entry['detail'] : $decoded;
			}
		}
		unset( $entry );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'count'   => count( $entries ),
				'entries' => $entries,
			),
			200
		);
	}

	/**
	 * Environment versions (WordPress, PHP, Divi).
	 *
	 * @return array
	 */
	private static function environment() {
		return array(
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'divi'      => self::detect_divi_version(),
		);
	}

	/**
	 * Tries to detect the Divi version.
	 *
	 * Checks the active theme and its parent if any, then falls back to the
	 * version constant defined by Divi.
	 *
	 * @return string|null Divi version, or null if not detected.
	 */
	private static function detect_divi_version() {
		$theme = wp_get_theme();

		if ( $theme ) {
			foreach ( array( $theme, $theme->parent() ) as $candidate ) {
				if ( $candidate && 'Divi' === $candidate->get( 'Name' ) ) {
					return $candidate->get( 'Version' );
				}
			}
		}

		if ( defined( 'ET_CORE_VERSION' ) ) {
			return ET_CORE_VERSION;
		}

		return null;
	}
}
