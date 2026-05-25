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

		// /status/network — multisite topology (authenticated).
		register_rest_route(
			IAWM_REST_NAMESPACE,
			'/status/network',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_status_network' ),
				'permission_callback' => array( 'IAWM_Auth', 'guard_read' ),
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
	 * POST /status/network — multisite topology overview.
	 *
	 * Returns whether this WordPress install is a multisite, and if so,
	 * which sub-site the request currently lives on. On the main site of
	 * a network the response additionally includes the total sub-site
	 * count and a compact list of sites (id, URL, name).
	 *
	 * This is read-only and authenticated under the `read` scope. It is
	 * deliberately small so Claude can call it cheaply at the start of a
	 * session to know whether multisite-specific reasoning applies.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_status_network() {
		$is_multisite     = is_multisite();
		$current_blog_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$is_main_site     = $is_multisite ? (bool) is_main_site() : true;
		$network_id       = $is_multisite && function_exists( 'get_current_network_id' )
			? (int) get_current_network_id()
			: 0;

		$payload = array(
			'ok'              => true,
			'is_multisite'    => $is_multisite,
			'is_main_site'    => $is_main_site,
			'current_blog_id' => $current_blog_id,
			'network_id'      => $network_id,
			'sites_in_network' => null,
			'sites'            => null,
		);

		if ( $is_multisite && function_exists( 'get_sites' ) ) {
			// Compute the total count regardless of which sub-site we're on
			// — `get_sites` is allowed across the whole network from any
			// blog context, and this lets the caller see the topology
			// even from a secondary sub-site.
			$payload['sites_in_network'] = (int) get_sites( array( 'count' => true ) );

			// Only echo the per-site list from the main site to keep the
			// payload small and the surface predictable.
			if ( $is_main_site ) {
				$sites = get_sites( array( 'number' => 100 ) );
				$list  = array();
				foreach ( $sites as $site ) {
					$bid  = (int) $site->blog_id;
					$info = get_blog_details( $bid, false );
					$list[] = array(
						'blog_id'   => $bid,
						'url'       => $info ? (string) $info->siteurl : '',
						'name'      => $info ? (string) $info->blogname : '',
						'is_active' => $info ? ! (int) $info->deleted && ! (int) $info->archived : true,
					);
				}
				$payload['sites'] = $list;
			}
		}

		// Plugin network-activation flag is useful for the operator.
		$payload['plugin_network_active'] = false;
		if ( $is_multisite ) {
			if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$payload['plugin_network_active'] = (bool) is_plugin_active_for_network(
				plugin_basename( IAWM_PLUGIN_FILE )
			);
		}

		return new WP_REST_Response( $payload, 200 );
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
