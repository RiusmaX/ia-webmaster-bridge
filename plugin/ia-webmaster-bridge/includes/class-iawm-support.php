<?php
/**
 * Fonctions utilitaires partagées entre les modules de capacités.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers communs aux modules (contenu, médias, etc.).
 */
class IAWM_Support {

	/**
	 * Extrait les paramètres JSON du corps d'une requête.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return array
	 */
	public static function json_params( $request ) {
		$params = $request->get_json_params();

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Identifiant de l'utilisateur sous lequel les écritures sont effectuées.
	 *
	 * Pour l'instant : le plus ancien administrateur du site. À remplacer en
	 * Phase 5 par un utilisateur dédié à rôle restreint.
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
	 * Bascule le contexte courant sur l'utilisateur sous lequel l'agent agit.
	 *
	 * @return void
	 */
	public static function act_as_agent() {
		wp_set_current_user( self::acting_user_id() );
	}

	/**
	 * Construit une erreur REST.
	 *
	 * @param string $code    Code d'erreur.
	 * @param string $message Message lisible.
	 * @param int    $status  Code HTTP.
	 * @param array  $extra   Données additionnelles à joindre (optionnel).
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
