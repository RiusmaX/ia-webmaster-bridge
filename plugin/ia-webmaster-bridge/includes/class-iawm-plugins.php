<?php
/**
 * Infrastructure plane — WordPress plugin management.
 *
 * Lets the AI install, activate and deactivate plugins from the
 * WordPress.org repository. Deletion is intentionally NOT exposed until
 * the backup phase (Phase 4) is in place.
 *
 * Main safeguards:
 *  - The IA Webmaster Bridge plugin can NEVER be deactivated by itself
 *    (otherwise the AI would lock itself out of the site).
 *  - The slug is validated (strict regex) to prevent any injection.
 *  - Installation goes exclusively through the official WordPress.org API
 *    (plugins_api). No arbitrary URLs for now.
 *  - Every operation is logged by IAWM_Audit.
 *
 * Routes (all POST, JSON body):
 *  - /plugins/install   — install_plugin( slug, activate=false )
 *  - /plugins/activate  — activate_plugin( file )
 *  - /plugins/deactivate— deactivate_plugin( file )
 *  - /plugins/info      — info_plugin( slug )
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress plugin management routes.
 */
class IAWM_Plugins {

	/** Plugin file of this plugin itself (never deactivatable via the API). */
	const SELF_PLUGIN_FILE = 'ia-webmaster-bridge/ia-webmaster-bridge.php';

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers plugin routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/plugins/info'       => array( 'handle_info', 'guard_read' ),
			'/plugins/install'    => array( 'handle_install', 'guard_write' ),
			'/plugins/activate'   => array( 'handle_activate', 'guard_write' ),
			'/plugins/deactivate' => array( 'handle_deactivate', 'guard_write' ),
			'/plugins/update'     => array( 'handle_update', 'guard_write' ),
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
	 * Validates a plugin slug (lowercase letters, digits, hyphens).
	 *
	 * @param string $slug Slug to validate.
	 * @return bool
	 */
	protected static function is_valid_slug( $slug ) {
		return is_string( $slug ) && preg_match( '/^[a-z0-9][a-z0-9-]{1,62}$/', $slug ) === 1;
	}

	/**
	 * Checks that a plugin file (`dir/file.php`) is plausible and present.
	 *
	 * @param string $file Path relative to the plugins directory.
	 * @return bool
	 */
	protected static function is_valid_file( $file ) {
		if ( ! is_string( $file ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]*\/[a-z0-9_-]+\.php$/i', $file ) ) {
			return false;
		}
		return file_exists( WP_PLUGIN_DIR . '/' . $file );
	}

	/**
	 * POST /plugins/info — retrieves a WP.org plugin's metadata.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_info( $request ) {
		$params = IAWM_Support::json_params( $request );
		$slug   = isset( $params['slug'] ) ? (string) $params['slug'] : '';

		if ( ! self::is_valid_slug( $slug ) ) {
			return IAWM_Support::rest_error( 'invalid_slug', __( 'Invalid slug.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'short_description' => true,
					'sections'          => false,
					'requires'          => true,
					'rating'            => true,
					'ratings'           => false,
					'downloaded'        => true,
					'last_updated'      => true,
					'homepage'          => true,
					'tags'              => true,
					'donate_link'       => false,
				),
			)
		);

		if ( is_wp_error( $info ) ) {
			return IAWM_Support::rest_error( 'plugin_info_failed', $info->get_error_message(), 404 );
		}

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'info' => array(
					'name'              => $info->name,
					'slug'              => $info->slug,
					'version'           => $info->version,
					'author'            => isset( $info->author ) ? wp_strip_all_tags( $info->author ) : null,
					'homepage'          => isset( $info->homepage ) ? $info->homepage : null,
					'requires'          => isset( $info->requires ) ? $info->requires : null,
					'requires_php'      => isset( $info->requires_php ) ? $info->requires_php : null,
					'tested'            => isset( $info->tested ) ? $info->tested : null,
					'rating'            => isset( $info->rating ) ? $info->rating : null,
					'downloaded'        => isset( $info->downloaded ) ? $info->downloaded : null,
					'last_updated'      => isset( $info->last_updated ) ? $info->last_updated : null,
					'short_description' => isset( $info->short_description ) ? $info->short_description : null,
					'download_link'     => isset( $info->download_link ) ? $info->download_link : null,
				),
			),
			200
		);
	}

	/**
	 * POST /plugins/install — installs (and optionally activates) a plugin
	 * from the WordPress.org repository.
	 *
	 * Parameters:
	 *   - slug (string, required) — WP.org slug (e.g. "rank-math-seo").
	 *   - activate (bool, default false) — activate after install.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_install( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$slug     = isset( $params['slug'] ) ? (string) $params['slug'] : '';
		$activate = ! empty( $params['activate'] );

		if ( ! self::is_valid_slug( $slug ) ) {
			return IAWM_Support::rest_error( 'invalid_slug', __( 'Invalid slug.', 'ia-webmaster-bridge' ), 400 );
		}

		// Load WP admin dependencies.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Pre-op safety net: snapshot the plugin activation state.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_plugins_state(
				sprintf(
					/* translators: 1: plugin slug. 2: optional " (+activate)" suffix. */
					__( 'Before plugin install: %1$s%2$s', 'ia-webmaster-bridge' ),
					$slug,
					$activate ? __( ' (+activate)', 'ia-webmaster-bridge' ) : ''
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		// Fetch metadata (signed download_link) via plugins_api.
		$info = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $info ) ) {
			return IAWM_Support::rest_error( 'plugin_not_found', $info->get_error_message(), 404 );
		}

		if ( empty( $info->download_link ) ) {
			return IAWM_Support::rest_error( 'no_download_link', __( 'No download link.', 'ia-webmaster-bridge' ), 502 );
		}

		// Check whether the plugin is already installed (by slug -> look for a file in WP_PLUGIN_DIR/{slug}/*).
		$existing = self::find_plugin_file_by_slug( $slug );
		$result   = array(
			'ok'         => true,
			'slug'       => $slug,
			'version'    => $info->version,
			'name'       => $info->name,
			'installed'  => false,
			'already'    => false,
			'activated'  => false,
			'file'       => $existing,
		);

		if ( $existing ) {
			$result['already'] = true;
		} else {
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$res      = $upgrader->install( $info->download_link );

			if ( is_wp_error( $res ) ) {
				return IAWM_Support::rest_error( 'install_failed', $res->get_error_message(), 500 );
			}
			if ( false === $res ) {
				$messages = $skin->get_error_messages();
				return IAWM_Support::rest_error( 'install_failed', $messages ? implode( ' ; ', $messages ) : __( 'Installation failed.', 'ia-webmaster-bridge' ), 500 );
			}

			$result['installed'] = true;
			$result['file']      = $upgrader->plugin_info();
			if ( empty( $result['file'] ) ) {
				$result['file'] = self::find_plugin_file_by_slug( $slug );
			}
		}

		// Optional activation.
		if ( $activate && ! empty( $result['file'] ) ) {
			if ( ! is_plugin_active( $result['file'] ) ) {
				$act = activate_plugin( $result['file'], '', false, true );
				if ( is_wp_error( $act ) ) {
					return IAWM_Support::rest_error( 'activation_failed', $act->get_error_message(), 500, $result );
				}
				$result['activated'] = true;
			} else {
				$result['activated'] = true;
			}
		}

		if ( null !== $pre_backup ) {
			$result['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $result, $result['installed'] ? 201 : 200 );
	}

	/**
	 * POST /plugins/activate — activates an already-installed plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_activate( $request ) {
		$params = IAWM_Support::json_params( $request );
		$file   = isset( $params['file'] ) ? (string) $params['file'] : '';

		if ( ! self::is_valid_file( $file ) ) {
			return IAWM_Support::rest_error( 'invalid_file', __( 'Invalid or missing plugin file.', 'ia-webmaster-bridge' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( is_plugin_active( $file ) ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'file' => $file, 'already_active' => true ),
				200
			);
		}

		// Pre-op safety net.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_plugins_state(
				sprintf(
					/* translators: %s: plugin file (e.g. "akismet/akismet.php"). */
					__( 'Before plugin activate: %s', 'ia-webmaster-bridge' ),
					$file
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		$res = activate_plugin( $file, '', false, true );
		if ( is_wp_error( $res ) ) {
			return IAWM_Support::rest_error( 'activation_failed', $res->get_error_message(), 500 );
		}

		$response = array( 'ok' => true, 'file' => $file, 'activated' => true );
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}
		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * POST /plugins/deactivate — deactivates a plugin.
	 *
	 * Explicitly refuses to deactivate the IA Webmaster Bridge plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_deactivate( $request ) {
		$params = IAWM_Support::json_params( $request );
		$file   = isset( $params['file'] ) ? (string) $params['file'] : '';

		if ( ! self::is_valid_file( $file ) ) {
			return IAWM_Support::rest_error( 'invalid_file', __( 'Invalid or missing plugin file.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( $file === self::SELF_PLUGIN_FILE ) {
			return IAWM_Support::rest_error(
				'cannot_disable_self',
				__( 'The IA Webmaster Bridge plugin cannot be deactivated via the API.', 'ia-webmaster-bridge' ),
				403
			);
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! is_plugin_active( $file ) ) {
			return new WP_REST_Response(
				array( 'ok' => true, 'file' => $file, 'already_inactive' => true ),
				200
			);
		}

		// Pre-op safety net.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_plugins_state(
				sprintf(
					/* translators: %s: plugin file (e.g. "akismet/akismet.php"). */
					__( 'Before plugin deactivate: %s', 'ia-webmaster-bridge' ),
					$file
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		deactivate_plugins( $file );

		$response = array( 'ok' => true, 'file' => $file, 'deactivated' => true );
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}
		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * POST /plugins/update — updates an installed plugin to its latest
	 * version from the WordPress.org repository.
	 *
	 * Body: { file, skip_backup? }
	 *
	 * Refuses to self-update the IA Webmaster Bridge plugin: replacing
	 * the running code mid-request is dangerous and would also break
	 * the active HTTP handler. Use the WordPress admin for that.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$file   = isset( $params['file'] ) ? (string) $params['file'] : '';

		if ( ! self::is_valid_file( $file ) ) {
			return IAWM_Support::rest_error( 'invalid_file', __( 'Invalid or missing plugin file.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( $file === self::SELF_PLUGIN_FILE ) {
			return IAWM_Support::rest_error(
				'cannot_self_update',
				__( 'The IA Webmaster Bridge plugin cannot be updated via its own API. Use the WordPress admin instead.', 'ia-webmaster-bridge' ),
				403
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force a fresh update check so the transient reflects WP.org's current state.
		wp_update_plugins();
		$new_version = self::available_update_for_plugin( $file );
		$current     = self::installed_version( $file );

		if ( null === $new_version ) {
			return new WP_REST_Response(
				array(
					'ok'         => true,
					'file'       => $file,
					'updated'    => false,
					'no_update'  => true,
					'version'    => $current,
				),
				200
			);
		}

		// Pre-op safety net.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_plugins_state(
				sprintf(
					/* translators: 1: plugin file. 2: previous version. 3: new version. */
					__( 'Before plugin update: %1$s (%2$s -> %3$s)', 'ia-webmaster-bridge' ),
					$file,
					$current,
					$new_version
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$res      = $upgrader->upgrade( $file );

		if ( is_wp_error( $res ) ) {
			return IAWM_Support::rest_error( 'update_failed', $res->get_error_message(), 500 );
		}
		if ( false === $res ) {
			$messages = $skin->get_error_messages();
			return IAWM_Support::rest_error( 'update_failed', $messages ? implode( ' ; ', $messages ) : __( 'Update failed.', 'ia-webmaster-bridge' ), 500 );
		}

		// Re-read to surface the actual installed version after the upgrade.
		$actual = self::installed_version( $file );

		$response = array(
			'ok'               => true,
			'file'             => $file,
			'updated'          => true,
			'previous_version' => $current,
			'new_version'      => $actual,
		);
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Returns the WP.org-advertised new version for an installed plugin,
	 * or null if no update is pending.
	 *
	 * @param string $file Plugin file (e.g. "akismet/akismet.php").
	 * @return string|null
	 */
	protected static function available_update_for_plugin( $file ) {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || empty( $transient->response[ $file ]->new_version ) ) {
			return null;
		}
		return (string) $transient->response[ $file ]->new_version;
	}

	/**
	 * Returns the currently-installed version of a plugin, or null if
	 * the plugin headers cannot be read.
	 *
	 * @param string $file Plugin file.
	 * @return string|null
	 */
	protected static function installed_version( $file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$data = get_plugin_data( $path, false, false );
		return isset( $data['Version'] ) ? (string) $data['Version'] : null;
	}

	/**
	 * Looks for a plugin file by slug (i.e. by directory name).
	 *
	 * @param string $slug Slug.
	 * @return string|null Plugin file (e.g. "rank-math-seo/rank-math.php") or null.
	 */
	protected static function find_plugin_file_by_slug( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ( $all as $file => $data ) {
			$dir = dirname( $file );
			if ( $dir === $slug ) {
				return $file;
			}
		}
		return null;
	}
}
