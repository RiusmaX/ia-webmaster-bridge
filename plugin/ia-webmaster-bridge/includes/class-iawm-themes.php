<?php
/**
 * Infrastructure plane — WordPress theme management.
 *
 * Mirrors IAWM_Plugins for themes: install, activate and update an
 * already-installed theme. The active theme is the **most visible**
 * piece of state a site has — switching it changes the entire frontend
 * — so every write here:
 *
 *  - validates the slug strictly (WP.org-style: lowercase letters,
 *    digits and hyphens);
 *  - only accepts installations from the official WP.org theme repository
 *    (themes_api), never an arbitrary URL;
 *  - automatically takes a pre-op `options` snapshot of the
 *    theme-related options (template, stylesheet, current_theme and
 *    theme_mods_{slug} for the involved themes), so the operator can
 *    roll back with `/backup/restore`;
 *  - is logged by IAWM_Audit.
 *
 * Deletion is intentionally NOT exposed (matches the IAWM_Plugins
 * policy). Deleting a theme that has child themes or is currently
 * active is destructive and ambiguous; we leave it to the human
 * operator for now.
 *
 * Routes (all POST, JSON body):
 *  - /themes/info     (read) — themes_api lookup
 *  - /themes/list     (read) — installed themes
 *  - /themes/install  (write, infra:write)
 *  - /themes/activate (write, infra:write)
 *  - /themes/update   (write, infra:write)
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress theme management routes.
 */
class IAWM_Themes {

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers theme routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/themes/info'     => array( 'handle_info', 'guard_read' ),
			'/themes/list'     => array( 'handle_list', 'guard_read' ),
			'/themes/install'  => array( 'handle_install', 'guard_write' ),
			'/themes/activate' => array( 'handle_activate', 'guard_write' ),
			'/themes/update'   => array( 'handle_update', 'guard_write' ),
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
	 * Validates a theme slug (lowercase letters, digits, hyphens).
	 *
	 * @param string $slug Slug to validate.
	 * @return bool
	 */
	protected static function is_valid_slug( $slug ) {
		return is_string( $slug ) && preg_match( '/^[a-z0-9][a-z0-9-]{1,62}$/', $slug ) === 1;
	}

	/**
	 * POST /themes/info — fetches a WP.org theme's metadata.
	 *
	 * Body: { slug }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_info( $request ) {
		$params = IAWM_Support::json_params( $request );
		$slug   = isset( $params['slug'] ) ? (string) $params['slug'] : '';

		if ( ! self::is_valid_slug( $slug ) ) {
			return IAWM_Support::rest_error( 'invalid_slug', __( 'Invalid slug.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$info = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'description'  => true,
					'sections'     => false,
					'rating'       => true,
					'ratings'      => false,
					'downloaded'   => true,
					'downloadlink' => true,
					'last_updated' => true,
					'homepage'     => true,
					'tags'         => false,
					'screenshot_url' => true,
					'requires'     => true,
					'requires_php' => true,
				),
			)
		);

		if ( is_wp_error( $info ) ) {
			return IAWM_Support::rest_error( 'theme_info_failed', $info->get_error_message(), 404 );
		}

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'info' => array(
					'name'           => isset( $info->name ) ? $info->name : null,
					'slug'           => isset( $info->slug ) ? $info->slug : null,
					'version'        => isset( $info->version ) ? $info->version : null,
					'author'         => isset( $info->author ) ? wp_strip_all_tags( (string) $info->author ) : null,
					'homepage'       => isset( $info->homepage ) ? $info->homepage : null,
					'requires'       => isset( $info->requires ) ? $info->requires : null,
					'requires_php'   => isset( $info->requires_php ) ? $info->requires_php : null,
					'rating'         => isset( $info->rating ) ? $info->rating : null,
					'downloaded'     => isset( $info->downloaded ) ? $info->downloaded : null,
					'last_updated'   => isset( $info->last_updated ) ? $info->last_updated : null,
					'screenshot_url' => isset( $info->screenshot_url ) ? $info->screenshot_url : null,
					'description'    => isset( $info->description ) ? wp_strip_all_tags( (string) $info->description ) : null,
					'download_link'  => isset( $info->download_link ) ? $info->download_link : null,
				),
			),
			200
		);
	}

	/**
	 * POST /themes/list — lists installed themes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		unset( $request );

		$active_stylesheet = get_stylesheet();
		$active_template   = get_template();

		$themes = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			/** @var WP_Theme $theme */
			$update = self::available_update_for( $stylesheet );

			$themes[] = array(
				'stylesheet'       => $stylesheet,
				'name'             => $theme->display( 'Name' ),
				'version'          => $theme->display( 'Version' ),
				'template'         => $theme->get_template(),
				'is_active'        => ( $stylesheet === $active_stylesheet ),
				'is_parent_of_active' => ( $stylesheet === $active_template && $active_stylesheet !== $active_template ),
				'is_child_theme'   => $theme->parent() instanceof WP_Theme,
				'author'           => $theme->display( 'Author' ),
				'description'      => $theme->display( 'Description', false, true ),
				'screenshot'       => $theme->get_screenshot(),
				'update_available' => null !== $update,
				'new_version'      => $update ? $update : null,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'                => true,
				'active_stylesheet' => $active_stylesheet,
				'active_template'   => $active_template,
				'total'             => count( $themes ),
				'themes'            => $themes,
			),
			200
		);
	}

	/**
	 * POST /themes/install — installs a theme from WP.org.
	 *
	 * Body: { slug, activate? (default false) }
	 *
	 * Takes an automatic pre-op snapshot of the theme-related options
	 * before doing anything so the operator can roll back. The snapshot
	 * id is returned as `pre_op_backup_id`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_install( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$slug     = isset( $params['slug'] ) ? (string) $params['slug'] : '';
		$activate = ! empty( $params['activate'] );

		if ( ! self::is_valid_slug( $slug ) ) {
			return IAWM_Support::rest_error( 'invalid_slug', __( 'Invalid slug.', 'ia-webmaster-bridge' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Pre-op safety net.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_options(
				self::theme_option_keys_to_snapshot( $slug ),
				sprintf(
					/* translators: 1: theme slug. 2: optional " (+activate)" suffix. */
					__( 'Before theme install: %1$s%2$s', 'ia-webmaster-bridge' ),
					$slug,
					$activate ? __( ' (+activate)', 'ia-webmaster-bridge' ) : ''
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		$info = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);

		if ( is_wp_error( $info ) ) {
			return IAWM_Support::rest_error( 'theme_not_found', $info->get_error_message(), 404 );
		}

		if ( empty( $info->download_link ) ) {
			return IAWM_Support::rest_error( 'no_download_link', __( 'No download link.', 'ia-webmaster-bridge' ), 502 );
		}

		// Already installed?
		$existing = wp_get_theme( $slug );
		$result   = array(
			'ok'         => true,
			'slug'       => $slug,
			'version'    => isset( $info->version ) ? $info->version : null,
			'name'       => isset( $info->name ) ? $info->name : null,
			'installed'  => false,
			'already'    => false,
			'activated'  => false,
			'stylesheet' => $slug,
		);

		if ( $existing->exists() ) {
			$result['already'] = true;
		} else {
			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Theme_Upgrader( $skin );
			$res      = $upgrader->install( $info->download_link );

			if ( is_wp_error( $res ) ) {
				return IAWM_Support::rest_error( 'install_failed', $res->get_error_message(), 500, $result );
			}
			if ( false === $res ) {
				$messages = $skin->get_error_messages();
				return IAWM_Support::rest_error( 'install_failed', $messages ? implode( ' ; ', $messages ) : __( 'Installation failed.', 'ia-webmaster-bridge' ), 500, $result );
			}

			$result['installed'] = true;
		}

		// Optional activation.
		if ( $activate ) {
			$candidate = wp_get_theme( $slug );
			if ( ! $candidate->exists() ) {
				return IAWM_Support::rest_error( 'theme_missing_after_install', __( 'Theme not found after install.', 'ia-webmaster-bridge' ), 500, $result );
			}
			if ( ! $candidate->is_allowed() ) {
				return IAWM_Support::rest_error( 'theme_not_allowed', __( 'Theme is not allowed on this site.', 'ia-webmaster-bridge' ), 403, $result );
			}
			switch_theme( $slug );
			$result['activated'] = ( get_stylesheet() === $slug );
		}

		if ( null !== $pre_backup ) {
			$result['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $result, $result['installed'] ? 201 : 200 );
	}

	/**
	 * POST /themes/activate — switches the active theme.
	 *
	 * Body: { stylesheet }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_activate( $request ) {
		$params     = IAWM_Support::json_params( $request );
		$stylesheet = isset( $params['stylesheet'] ) ? (string) $params['stylesheet'] : '';

		if ( '' === $stylesheet || ! self::is_valid_slug( $stylesheet ) ) {
			return IAWM_Support::rest_error( 'invalid_stylesheet', __( 'Invalid or missing stylesheet.', 'ia-webmaster-bridge' ), 400 );
		}

		$candidate = wp_get_theme( $stylesheet );
		if ( ! $candidate->exists() ) {
			return IAWM_Support::rest_error(
				'theme_not_installed',
				/* translators: %s: theme stylesheet slug. */
				sprintf( __( "Theme '%s' is not installed.", 'ia-webmaster-bridge' ), $stylesheet ),
				404
			);
		}
		if ( ! $candidate->is_allowed() ) {
			return IAWM_Support::rest_error( 'theme_not_allowed', __( 'Theme is not allowed on this site.', 'ia-webmaster-bridge' ), 403 );
		}

		// No-op if already active.
		if ( get_stylesheet() === $stylesheet ) {
			return new WP_REST_Response(
				array(
					'ok'             => true,
					'stylesheet'     => $stylesheet,
					'already_active' => true,
				),
				200
			);
		}

		// Pre-op safety net.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_options(
				self::theme_option_keys_to_snapshot( $stylesheet ),
				sprintf(
					/* translators: %s: theme stylesheet slug. */
					__( 'Before theme activate: %s', 'ia-webmaster-bridge' ),
					$stylesheet
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		switch_theme( $stylesheet );
		$activated = ( get_stylesheet() === $stylesheet );

		$response = array(
			'ok'         => $activated,
			'stylesheet' => $stylesheet,
			'activated'  => $activated,
		);
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $response, $activated ? 200 : 500 );
	}

	/**
	 * POST /themes/update — updates an installed theme to its latest
	 * version from WP.org.
	 *
	 * Body: { stylesheet }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		$params     = IAWM_Support::json_params( $request );
		$stylesheet = isset( $params['stylesheet'] ) ? (string) $params['stylesheet'] : '';

		if ( '' === $stylesheet || ! self::is_valid_slug( $stylesheet ) ) {
			return IAWM_Support::rest_error( 'invalid_stylesheet', __( 'Invalid or missing stylesheet.', 'ia-webmaster-bridge' ), 400 );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return IAWM_Support::rest_error(
				'theme_not_installed',
				/* translators: %s: theme stylesheet slug. */
				sprintf( __( "Theme '%s' is not installed.", 'ia-webmaster-bridge' ), $stylesheet ),
				404
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force a refresh of the update transient so we have the latest data.
		wp_update_themes();
		$updates = self::available_update_for( $stylesheet );
		if ( null === $updates ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'stylesheet'   => $stylesheet,
					'updated'      => false,
					'no_update'    => true,
					'version'      => $theme->display( 'Version' ),
				),
				200
			);
		}

		// Pre-op safety net.
		$pre_backup = empty( $params['skip_backup'] )
			? IAWM_Backup::snapshot_options(
				self::theme_option_keys_to_snapshot( $stylesheet ),
				sprintf(
					/* translators: 1: theme stylesheet slug. 2: target version string. */
					__( 'Before theme update: %1$s -> %2$s', 'ia-webmaster-bridge' ),
					$stylesheet,
					$updates
				),
				(string) $request->get_route()
			)
			: null;

		IAWM_Support::act_as_agent();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$res      = $upgrader->upgrade( $stylesheet );

		if ( is_wp_error( $res ) ) {
			return IAWM_Support::rest_error( 'update_failed', $res->get_error_message(), 500 );
		}
		if ( false === $res ) {
			$messages = $skin->get_error_messages();
			return IAWM_Support::rest_error( 'update_failed', $messages ? implode( ' ; ', $messages ) : __( 'Update failed.', 'ia-webmaster-bridge' ), 500 );
		}

		// Re-read the theme to get the new version.
		$fresh = wp_get_theme( $stylesheet );

		$response = array(
			'ok'              => true,
			'stylesheet'      => $stylesheet,
			'updated'         => true,
			'previous_version' => $theme->display( 'Version' ),
			'new_version'     => $fresh->display( 'Version' ),
		);
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Returns the version available in the update transient for a
	 * given stylesheet, or null if none.
	 *
	 * @param string $stylesheet Stylesheet slug.
	 * @return string|null
	 */
	protected static function available_update_for( $stylesheet ) {
		$transient = get_site_transient( 'update_themes' );
		if ( ! is_object( $transient ) || empty( $transient->response[ $stylesheet ]['new_version'] ) ) {
			return null;
		}
		return (string) $transient->response[ $stylesheet ]['new_version'];
	}

	/**
	 * Returns the WordPress option keys worth snapshotting for a theme
	 * operation. These are the options that describe "which theme is
	 * active" and the customizer state for the involved themes — small
	 * enough to snapshot cheaply, comprehensive enough to roll back.
	 *
	 * @param string $stylesheet Stylesheet of the theme being installed,
	 *                           activated or updated.
	 * @return array
	 */
	protected static function theme_option_keys_to_snapshot( $stylesheet ) {
		$keys = array(
			'template',
			'stylesheet',
			'current_theme',
			'theme_switched',
			'theme_switched_via_customizer',
		);

		// Add theme_mods for the currently-active theme.
		$active = get_stylesheet();
		if ( $active ) {
			$keys[] = 'theme_mods_' . $active;
		}
		if ( $stylesheet && $stylesheet !== $active ) {
			$keys[] = 'theme_mods_' . $stylesheet;
		}

		return array_values( array_unique( $keys ) );
	}
}
