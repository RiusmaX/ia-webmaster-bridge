<?php
/**
 * Infrastructure plane — WordPress core update.
 *
 * The most destructive operation in the project: replacing the running
 * WordPress code with a newer (or older) one. Every safeguard the
 * project has applies here:
 *
 *   - HMAC-signed request (spec 02).
 *   - `infra:write` scope (spec 02 / D-012).
 *   - Automatic pre-op snapshot of the plugin activation state — a
 *     core update sometimes deactivates plugins that fail the new
 *     compatibility checks, so we want to know what was active before.
 *   - PHP version pre-flight: refuse if the target core release
 *     requires a PHP newer than the one currently running, since the
 *     site would not boot after the swap.
 *   - The site is put into maintenance mode by the upgrader itself
 *     (`enable_maintenance_mode`); we surface that in the response.
 *   - Audit-logged like every other write.
 *
 * Routes (POST, JSON body):
 *  - /core/info   (read)        — current vs. available WP version.
 *  - /core/update (infra:write) — apply the update.
 *
 * Downgrades to a specific older version are NOT exposed. The upgrader
 * is invoked with no version argument, meaning "the latest WP.org
 * announces as suitable for this site". Reinstall to a specific past
 * version is a recovery scenario for the human operator.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress core update routes.
 */
class IAWM_Core {

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
			'/core/info'   => array( 'handle_info', 'guard_read' ),
			'/core/update' => array( 'handle_update', 'guard_write' ),
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
	 * POST /core/info — current WordPress version and what is available
	 * upstream.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_info( $request ) {
		unset( $request );

		global $wp_version;

		require_once ABSPATH . 'wp-admin/includes/update.php';

		// Refresh the update transient so the answer reflects WP.org's
		// current state rather than a stale cache.
		wp_version_check();

		$available = self::available_update_payload();

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'current_version' => (string) $wp_version,
				'php_version'    => PHP_VERSION,
				'available'      => $available,
				'has_update'     => null !== $available && version_compare( $available['version'], (string) $wp_version, '>' ),
			),
			200
		);
	}

	/**
	 * POST /core/update — applies the available core update.
	 *
	 * Body: { dry_run?, skip_backup? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		global $wp_version;

		$params  = IAWM_Support::json_params( $request );
		$dry_run = ! empty( $params['dry_run'] );

		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		wp_version_check();
		$available = self::available_update_payload();

		if ( null === $available || version_compare( $available['version'], (string) $wp_version, '<=' ) ) {
			return new WP_REST_Response(
				array(
					'ok'              => true,
					'updated'         => false,
					'no_update'       => true,
					'current_version' => (string) $wp_version,
				),
				200
			);
		}

		// PHP pre-flight: refuse to install a core release that would not
		// boot on the current PHP.
		if ( ! empty( $available['php_version'] ) && version_compare( PHP_VERSION, $available['php_version'], '<' ) ) {
			return IAWM_Support::rest_error(
				'php_too_old',
				sprintf(
					/* translators: 1: WordPress target version. 2: required PHP version. 3: current PHP version on the server. */
					__( 'WordPress %1$s requires PHP %2$s or higher; this server runs PHP %3$s. Update PHP first.', 'ia-webmaster-bridge' ),
					$available['version'],
					$available['php_version'],
					PHP_VERSION
				),
				400,
				array(
					'required_php' => $available['php_version'],
					'current_php'  => PHP_VERSION,
				)
			);
		}

		if ( $dry_run ) {
			return new WP_REST_Response(
				array(
					'ok'              => true,
					'dry_run'         => true,
					'current_version' => (string) $wp_version,
					'would_update_to' => $available['version'],
					'php_check'       => 'pass',
				),
				200
			);
		}

		// Phase 5.3: confirmation token required for a real core update.
		$confirm = IAWM_Confirmation::guard(
			$request,
			$params,
			array(
				'current_version' => (string) $wp_version,
				'would_update_to' => $available['version'],
				'php_required'    => isset( $available['php_version'] ) ? $available['php_version'] : null,
			)
		);
		if ( null !== $confirm ) {
			return $confirm;
		}

		// Pre-op safety net — a core update can deactivate incompatible
		// plugins, so we capture the plugin activation state for rollback.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_plugins_state(
				sprintf(
					/* translators: 1: current WP version. 2: target WP version. */
					__( 'Before WordPress core update: %1$s -> %2$s', 'ia-webmaster-bridge' ),
					$wp_version,
					$available['version']
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		// Find the right Core_Upgrader update offer object.
		$updates = get_core_updates();
		$update  = null;
		if ( is_array( $updates ) ) {
			foreach ( $updates as $candidate ) {
				if ( isset( $candidate->response ) && 'upgrade' === $candidate->response ) {
					$update = $candidate;
					break;
				}
			}
		}

		if ( ! $update ) {
			return IAWM_Support::rest_error(
				'no_upgrade_offer',
				__( 'WordPress did not return an upgrade offer; cannot apply the update.', 'ia-webmaster-bridge' ),
				502
			);
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$res      = $upgrader->upgrade( $update, array( 'allow_relaxed_file_ownership' => true ) );

		if ( is_wp_error( $res ) ) {
			return IAWM_Support::rest_error( 'core_update_failed', $res->get_error_message(), 500 );
		}
		if ( false === $res ) {
			$messages = $skin->get_error_messages();
			return IAWM_Support::rest_error( 'core_update_failed', $messages ? implode( ' ; ', $messages ) : __( 'Core update failed.', 'ia-webmaster-bridge' ), 500 );
		}

		// The new version string is in $res or via re-reading $wp_version
		// after WP reloads — but since the update may have replaced PHP
		// files we cannot reliably rely on global state. Report what we
		// asked for.
		$response = array(
			'ok'               => true,
			'updated'          => true,
			'previous_version' => (string) $wp_version,
			'new_version'      => $available['version'],
			'notice'           => __( 'A page reload may be required for WordPress to fully bootstrap on the new version.', 'ia-webmaster-bridge' ),
		);
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Reads the core update transient and returns a normalised summary,
	 * or null when no update is offered.
	 *
	 * @return array|null
	 */
	protected static function available_update_payload() {
		$updates = get_core_updates();
		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return null;
		}

		foreach ( $updates as $update ) {
			$response = isset( $update->response ) ? (string) $update->response : '';
			if ( '' === $response || 'latest' === $response ) {
				continue;
			}

			return array(
				'version'     => isset( $update->version ) ? (string) $update->version : null,
				'php_version' => isset( $update->php_version ) ? (string) $update->php_version : null,
				'mysql_version' => isset( $update->mysql_version ) ? (string) $update->mysql_version : null,
				'response'    => $response,
				'package'     => isset( $update->packages->full ) ? (string) $update->packages->full : null,
				'locale'      => isset( $update->locale ) ? (string) $update->locale : null,
				'partial'     => isset( $update->packages->partial ) && '' !== $update->packages->partial,
			);
		}

		return null;
	}
}
