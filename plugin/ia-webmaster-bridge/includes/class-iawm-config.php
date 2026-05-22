<?php
/**
 * Plan configuration : réglages du site et gestion des utilisateurs.
 *
 * Les réglages modifiables sont limités à une liste blanche (constante
 * SETTINGS) : toute autre option est rejetée. C'est le garde-fou principal —
 * l'API ne peut pas toucher à des options critiques (active_plugins, clés, etc.).
 *
 * Routes POST avec corps JSON. Lecture en guard_read, écriture en guard_write.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes de configuration du site.
 */
class IAWM_Config {

	/**
	 * Réglages modifiables (liste blanche) : option => définition de type.
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

	/** Rôles d'utilisateur acceptés. */
	const USER_ROLES = array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' );

	/**
	 * Branche l'enregistrement des routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Enregistre les routes de configuration.
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
	 * POST /config/settings/get — lit les réglages de la liste blanche.
	 *
	 * @param WP_REST_Request $request Requête entrante.
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
	 * POST /config/settings/update — modifie des réglages de la liste blanche.
	 *
	 * Corps JSON : { settings: { clé: valeur, ... }, dry_run? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_settings_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$input  = ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) ? $params['settings'] : array();

		if ( empty( $input ) ) {
			return IAWM_Support::rest_error( 'iawm_no_settings', "Le paramètre 'settings' (objet) est requis.", 400 );
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
				'Aucun réglage valide à appliquer. Rejetés : ' . implode( ', ', $rejected ) . '.',
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

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'updated'  => true,
				'applied'  => $applied,
				'rejected' => $rejected,
			),
			200
		);
	}

	/**
	 * POST /config/users/list — liste paginée des utilisateurs.
	 *
	 * Corps JSON : { search?, per_page?, page? }
	 *
	 * @param WP_REST_Request $request Requête entrante.
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
	 * POST /config/users/create — crée un utilisateur.
	 *
	 * Corps JSON : { login, email, password?, role?, display_name?, dry_run? }
	 * Sans mot de passe fourni, un mot de passe fort est généré et renvoyé.
	 * Le rôle par défaut est « subscriber » (le moins privilégié).
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_users_create( $request ) {
		$params = IAWM_Support::json_params( $request );

		$login = isset( $params['login'] ) ? sanitize_user( (string) $params['login'] ) : '';
		$email = isset( $params['email'] ) ? sanitize_email( (string) $params['email'] ) : '';
		if ( '' === $login || '' === $email ) {
			return IAWM_Support::rest_error( 'iawm_missing_fields', "Les paramètres 'login' et 'email' sont requis.", 400 );
		}
		if ( ! is_email( $email ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_email', 'Adresse e-mail invalide.', 400 );
		}

		$role = isset( $params['role'] ) ? sanitize_key( (string) $params['role'] ) : 'subscriber';
		if ( ! in_array( $role, self::USER_ROLES, true ) ) {
			return IAWM_Support::rest_error( 'iawm_invalid_role', "Rôle non pris en charge : {$role}.", 400 );
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
			return IAWM_Support::rest_error( 'iawm_user_exists', 'Un utilisateur avec ce login ou cet e-mail existe déjà.', 409 );
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
			$response['notice']             = 'Mot de passe généré : à transmettre de façon sécurisée, puis à faire changer.';
		}

		return new WP_REST_Response( $response, 201 );
	}

	/**
	 * POST /config/users/update — modifie un utilisateur.
	 *
	 * Corps JSON : { id, email?, display_name?, role?, dry_run? }
	 * L'utilisateur sous lequel l'agent opère ne peut pas être modifié.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_users_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;

		$user = $id > 0 ? get_user_by( 'id', $id ) : false;
		if ( ! $user ) {
			return IAWM_Support::rest_error( 'iawm_not_found', "Utilisateur introuvable : {$id}.", 404 );
		}

		// Garde-fou : l'agent ne peut pas modifier l'utilisateur sous lequel il opère.
		if ( $id === IAWM_Support::acting_user_id() ) {
			return IAWM_Support::rest_error( 'iawm_protected_user', "L'utilisateur sous lequel l'agent opère ne peut pas être modifié via l'API.", 403 );
		}

		$update = array( 'ID' => $id );
		if ( isset( $params['email'] ) ) {
			$email = sanitize_email( (string) $params['email'] );
			if ( ! is_email( $email ) ) {
				return IAWM_Support::rest_error( 'iawm_invalid_email', 'Adresse e-mail invalide.', 400 );
			}
			$update['user_email'] = $email;
		}
		if ( isset( $params['display_name'] ) ) {
			$update['display_name'] = sanitize_text_field( (string) $params['display_name'] );
		}
		if ( isset( $params['role'] ) ) {
			$role = sanitize_key( (string) $params['role'] );
			if ( ! in_array( $role, self::USER_ROLES, true ) ) {
				return IAWM_Support::rest_error( 'iawm_invalid_role', "Rôle non pris en charge : {$role}.", 400 );
			}
			$update['role'] = $role;
		}

		if ( count( $update ) <= 1 ) {
			return IAWM_Support::rest_error( 'iawm_no_change', 'Aucune modification fournie.', 400 );
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
	 * Valide et convertit la valeur d'un réglage selon son type.
	 *
	 * @param array $def   Définition du réglage.
	 * @param mixed $value Valeur entrante.
	 * @return mixed|null Valeur normalisée, ou null si invalide.
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
	 * Représentation d'un utilisateur.
	 *
	 * @param WP_User $user Utilisateur.
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
