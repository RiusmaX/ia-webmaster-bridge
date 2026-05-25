<?php
/**
 * Utility functions shared between capability modules.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers common to modules (content, media, etc.).
 */
class IAWM_Support {

	/**
	 * Extracts JSON parameters from a request body.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array
	 */
	public static function json_params( $request ) {
		$params = $request->get_json_params();

		return is_array( $params ) ? $params : array();
	}

	/**
	 * ID of the user under whom writes are performed.
	 *
	 * Since v0.19.0 the adapter uses a dedicated, restricted user
	 * (`iawm-agent`, role `iawm_agent`) created on plugin activation —
	 * see IAWM_Agent_User. If for any reason that user is unavailable
	 * (manual deletion, install failure), we fall back to the oldest
	 * administrator to keep the API functional, and the operator should
	 * re-trigger installation from the admin page.
	 *
	 * @return int
	 */
	public static function acting_user_id() {
		if ( class_exists( 'IAWM_Agent_User' ) ) {
			$agent_id = IAWM_Agent_User::get_user_id();
			if ( $agent_id > 0 ) {
				return $agent_id;
			}
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 1;
	}

	/**
	 * Switches the current context to the user the agent acts as.
	 *
	 * @return void
	 */
	public static function act_as_agent() {
		wp_set_current_user( self::acting_user_id() );
	}

	/**
	 * Builds a REST error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP code.
	 * @param array  $extra   Additional data to attach (optional).
	 * @return WP_Error
	 */
	public static function rest_error( $code, $message, $status, $extra = array() ) {
		$data = array( 'status' => $status );
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$data = array_merge( $data, $extra );
		}
		return new WP_Error( $code, $message, $data );
	}
}
