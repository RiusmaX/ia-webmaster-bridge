<?php
/**
 * Configuration plane: site settings and user management.
 *
 * Editable settings are restricted to an allow-list (the SETTINGS constant):
 * any other option is rejected. That is the main safeguard — the API cannot
 * touch critical options (active_plugins, keys, etc.).
 *
 * POST routes with JSON body. Reads use guard_read, writes use guard_write.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site configuration routes.
 */
class IAWM_Config {

	/**
	 * Editable settings (allow-list): option => type definition.
	 */
	const SETTINGS = array(
		'blogname'               => array( 'type' => 'string' ),
		'blogdescription'        => array( 'type' => 'string' ),
		'timezone_string'        => array( 'type' => 'string' ),
		'date_format'            => array( 'type' => 'string' ),
		'time_format'            => array( 'type' => 'string' ),
		'start_of_week'          => array( 'type' => 'int' ),
		'posts_per_page'         => array( 'type' => 'int' ),
		'show_on_front'          => array( 'type' => 'enum', 'values' => array( 'posts', 'page' ) ),
		'page_on_front'          => array( 'type' => 'int' ),
		'page_for_posts'         => array( 'type' => 'int' ),
		'default_comment_status' => array( 'type' => 'enum', 'values' => array( 'open', 'closed' ) ),
		'default_ping_status'    => array( 'type' => 'enum', 'values' => array( 'open', 'closed' ) ),
		'users_can_register'     => array( 'type' => 'bool' ),
		'default_role'           => array( 'type' => 'string' ),
		'blog_public'            => array( 'type' => 'bool' ),
		'permalink_structure'    => array( 'type' => 'string', 'risky' => true ),
	);

	/** Accepted user roles. */
	const USER_ROLES = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	/**
	 * Sensitive parameter paths to mask in the audit log, keyed by route
	 * suffix. Consumed by handlers via `audit_sensitive()` below and the
	 * `iawm_audit_pseudonymise` toggle — see D-031.
	 *
	 * Use dot notation; `*` wildcards a list index (e.g. `users.*.pass`).
	 */
	const SENSITIVE_PARAMS = array(
		'config/users/create' => array( 'password' ),
		'config/users/update' => array( 'password' ),
	);

	/**
	 * Resolves the sensitive-path list for the given route suffix.
	 *
	 * @param string $suffix Route suffix without namespace, e.g. `config/users/create`.
	 * @return array
	 */
	private static function audit_sensitive( $suffix ) {
		return isset( self::SENSITIVE_PARAMS[ $suffix ] ) ? self::SENSITIVE_PARAMS[ $suffix ] : array();
	}

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers configuration routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/config/settings/get'    => array( 'handle_settings_get', 'guard_read' ),
			'/config/settings/update' => array( 'handle_settings_update', 'guard_write' ),
			'/config/users/list'      => array( 'handle_users_list', 'guard_read' ),
			'/config/users/create'    => array( 'handle_users_create', 'guard_write' ),
			'/config/users/update'    => array( 'handle_users_update', 'guard_write' ),
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
	 * POST /config/settings/get — reads the allow-listed settings.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_settings_get( $request ) {
		unset( $request );

		$settings = array();
		foreach ( self::SETTINGS as $key => $def ) {
			$settings[ $key ] = array(
				'value' => get_option( $key ),
				'type'  => $def['type'],
				'risky' => ! empty( $def['risky'] ),
			);
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'settings' => $settings,
			),
			200
		);
	}

	/**
	 * POST /config/settings/update — modifies allow-listed settings.
	 *
	 * JSON body: { settings: { key: value, ... }, dry_run? }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_settings_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$input  = ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) ? $params['settings'] : array();

		if ( empty( $input ) ) {
			return IAWM_Support::rest_error( 'iawm_no_settings', __( "The 'settings' parameter (object) is required.", 'ia-webmaster-bridge' ), 400 );
		}

		$changes  = array();
		$rejected = array();
		foreach ( $input as $key => $value ) {
			if ( ! isset( self::SETTINGS[ $key ] ) ) {
				$rejected[] = $key;
				continue;
			}
			$clean = self::sanitize_setting( self::SETTINGS[ $key ], $value );
			if ( null === $clean ) {
				$rejected[] = $key;
				continue;
			}
			$changes[ $key ] = array(
				'from'  => get_option( $key ),
				'to'    => $clean,
				'risky' => ! empty( self::SETTINGS[ $key ]['risky'] ),
			);
		}

		if ( empty( $changes ) ) {
			return IAWM_Support::rest_error(
				'iawm_no_valid_settings',
				sprintf(
					/* translators: %s: comma-separated list of rejected setting keys. */
					__( 'No valid setting to apply. Rejected: %s.', 'ia-webmaster-bridge' ),
					implode( ', ', $rejected )
				),
				400
			);
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_change' => $changes,
					'rejected'     => $rejected,
				),
				200
			);
		}

		// Pre-op safety net: any change that touches a "risky" setting (e.g.
		// permalink_structure) gets an automatic option snapshot.
		$pre_backup = null;
		$risky_keys = array();
		foreach ( $changes as $key => $change ) {
			if ( ! empty( $change['risky'] ) ) {
				$risky_keys[] = $key;
			}
		}
		if ( ! empty( $risky_keys ) && empty( $params['skip_backup'] ) && class_exists( 'IAWM_Backup' ) ) {
			$pre_backup = IAWM_Backup::snapshot_options(
				array_keys( $changes ),
				sprintf(
					/* translators: %s: comma-separated list of risky setting keys being changed. */
					__( 'Before risky settings update: %s', 'ia-webmaster-bridge' ),
					implode( ', ', $risky_keys )
				),
				(string) $request->get_route()
			);
		}

		IAWM_Support::act_as_agent();

		$flush_permalinks = false;
		foreach ( $changes as $key => $change ) {
			update_option( $key, $change['to'] );
			if ( 'permalink_structure' === $key ) {
				$flush_permalinks = true;
			}
		}
		if ( $flush_permalinks ) {
			flush_rewrite_rules();
		}

		$applied = array();
		foreach ( array_keys( $changes ) as $key ) {
			$applied[ $key ] = get_option( $key );
		}

		$response = array(
			'ok'       => true,
			'updated'  => true,
			'applied'  => $applied,
			'rejected' => $rejected,
		);
		if ( null !== $pre_backup ) {
			$response['pre_op_backup_id'] = $pre_backup;
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * POST /config/users/list — paginated list of users.
	 *
	 * JSON body: { search?, per_page?, page? }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_users_list( $request ) {
		$params = IAWM_Support::json_params( $request );

		$per_page = isset( $params['per_page'] ) ? max( 1, min( 100, (int) $params['per_page'] ) ) : 50;
		$page     = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;
		$search   = isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '';

		$args = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);
		if ( '' !== $search ) {
			$args['search'] = '*' . $search . '*';
		}

		$query = new WP_User_Query( $args );

		$items = array();
		foreach ( $query->get_results() as $user ) {
			$items[] = self::user_data( $user );
		}

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'total'    => (int) $query->get_total(),
				'page'     => $page,
				'per_page' => $per_page,
				'items'    => $items,
			),
			200
		);
	}

	/**
	 * POST /config/users/create — creates a user.
	 *
	 * JSON body: { login, email, password?, role?, display_name?, dry_run? }
	 * Without a provided password, a strong one is generated and returned.
	 * The default role is "subscriber" (least privileged).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_users_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		IAWM_Audit::write( (string) $request->get_route(), $params, null, self::audit_sensitive( 'config/users/create' ) );

		$login = isset( $params['login'] ) ? sanitize_user( (string) $params['login'] ) : '';
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		if ( '' === $login || '' === $email ) {
			return IAWM_Support::rest_error( 'iawm_missing_fields', __( "The 'login' and 'email' parameters are required.", 'ia-webmaster-bridge' ), 400 );
		}
		if ( ! is_email( $email ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_email', __( 'Invalid email address.', 'ia-webmaster-bridge' ), 400 );
		}

		$role = isset( $params['role'] ) ? sanitize_key( (string) $params['role'] ) : 'subscriber';
		if ( ! in_array( $role, self::USER_ROLES, true ) ) {
			return IAWM_Support::rest_error(
				'iawm_invalid_role',
				/* translators: %s: WordPress role slug that was rejected. */
				sprintf( __( 'Unsupported role: %s.', 'ia-webmaster-bridge' ), $role ),
				400
			);
		}

		$display       = isset( $params['display_name'] ) ? sanitize_text_field( (string) $params['display_name'] ) : $login;
		$has_password  = isset( $params['password'] ) && '' !== (string) $params['password'];
		$password      = $has_password ? (string) $params['password'] : wp_generate_password( 20, true );

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'would_create' => array(
						'login'        => $login,
						'email'        => $email,
						'role'         => $role,
						'display_name' => $display,
					),
				),
				200
			);
		}

		if ( username_exists( $login ) || email_exists( $email ) ) {
			return IAWM_Support::rest_error( 'iawm_user_exists', __( 'A user with this login or email already exists.', 'ia-webmaster-bridge' ), 409 );
		}

		IAWM_Support::act_as_agent();

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $display,
				'role'         => $role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return IAWM_Support::rest_error( 'iawm_create_failed', $user_id->get_error_message(), 500 );
		}

		$response = array(
			'ok'      => true,
			'created' => true,
			'item'    => self::user_data( get_user_by( 'id', $user_id ) ),
		);
		if ( ! $has_password ) {
			$response['generated_password'] = $password;
			$response['notice']             = __( 'Generated password: deliver it securely, then require it to be changed.', 'ia-webmaster-bridge' );
		}

		return new WP_REST_Response( $response, 201 );
	}

	/**
	 * POST /config/users/update — modifies a user.
	 *
	 * JSON body: { id, email?, display_name?, role?, dry_run? }
	 * The user the agent operates as cannot be modified.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_users_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		IAWM_Audit::write( (string) $request->get_route(), $params, null, self::audit_sensitive( 'config/users/update' ) );

		$id = isset( $params['id'] ) ? (int) $params['id'] : 0;

		$user = $id > 0 ? get_user_by( 'id', $id ) : false;
		if ( ! $user ) {
			return IAWM_Support::rest_error(
				'iawm_not_found',
				/* translators: %d: WordPress user ID that was not found. */
				sprintf( __( 'User not found: %d.', 'ia-webmaster-bridge' ), $id ),
				404
			);
		}

		// Safeguard: the agent cannot modify the user it operates as.
		// IAWM_Agent_User::is_agent_user() is preferred when available; it
		// catches the dedicated agent user even if a different effective
		// user is currently being switched to.
		$is_agent = class_exists( 'IAWM_Agent_User' )
			? IAWM_Agent_User::is_agent_user( $id )
			: ( $id === IAWM_Support::acting_user_id() );
		if ( $is_agent ) {
			return IAWM_Support::rest_error( 'iawm_protected_user', __( 'The dedicated agent user cannot be modified via the API.', 'ia-webmaster-bridge' ), 403 );
		}

		$update = array( 'ID' => $id );
		if ( isset( $params['email'] ) ) {
			$email = sanitize_email( (string) $params['email'] );
			if ( ! is_email( $email ) ) {
				return IAWM_Support::rest_error( 'iawm_invalid_email', __( 'Invalid email address.', 'ia-webmaster-bridge' ), 400 );
			}
			$update['user_email'] = $email;
		}
		if ( isset( $params['display_name'] ) ) {
			$update['display_name'] = sanitize_text_field( (string) $params['display_name'] );
		}
		if ( isset( $params['role'] ) ) {
			$role = sanitize_key( (string) $params['role'] );
			if ( ! in_array( $role, self::USER_ROLES, true ) ) {
				return IAWM_Support::rest_error(
					'iawm_invalid_role',
					/* translators: %s: WordPress role slug that was rejected. */
					sprintf( __( 'Unsupported role: %s.', 'ia-webmaster-bridge' ), $role ),
					400
				);
			}
			$update['role'] = $role;
		}

		if ( count( $update ) <= 1 ) {
			return IAWM_Support::rest_error( 'iawm_no_change', __( 'No changes provided.', 'ia-webmaster-bridge' ), 400 );
		}

		if ( ! empty( $params['dry_run'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'           => true,
					'dry_run'      => true,
					'current'      => self::user_data( $user ),
					'would_change' => $update,
				),
				200
			);
		}

		IAWM_Support::act_as_agent();

		$result = wp_update_user( $update );
		if ( is_wp_error( $result ) ) {
			return IAWM_Support::rest_error( 'iawm_update_failed', $result->get_error_message(), 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => true,
				'item'    => self::user_data( get_user_by( 'id', $id ) ),
			),
			200
		);
	}

	/**
	 * Validates and converts a setting's value according to its type.
	 *
	 * @param array $def   Setting definition.
	 * @param mixed $value Incoming value.
	 * @return mixed|null Normalised value, or null if invalid.
	 */
	private static function sanitize_setting( $def, $value ) {
		switch ( $def['type'] ) {
			case 'int':
				return is_scalar( $value ) ? (int) $value : null;

			case 'bool':
				return ( $value && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;

			case 'enum':
				$candidate = is_string( $value ) ? sanitize_key( $value ) : '';
				return in_array( $candidate, $def['values'], true ) ? $candidate : null;

			case 'string':
			default:
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
		}
	}

	/**
	 * Representation of a user.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	private static function user_data( $user ) {
		return array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'roles'        => array_values( (array) $user->roles ),
			'registered'   => $user->user_registered,
		);
	}
}
