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
	 * For now: the oldest administrator on the site. To be replaced in
	 * Phase 5 by a dedicated user with a restricted role.
	 *
	 * @return int
	 */
	public static function acting_user_id() {
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
