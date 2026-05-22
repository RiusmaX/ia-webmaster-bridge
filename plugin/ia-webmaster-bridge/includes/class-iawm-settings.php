<?php
/**
 * Stockage des réglages de l'adaptateur : identifiants d'API et kill switch.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accès centralisé aux options du plugin.
 *
 * Le secret d'API est un secret partagé symétrique (HMAC) : il est donc stocké
 * en clair côté WordPress, comme il le sera côté pont MCP. La protection repose
 * sur l'accès restreint à la base et à la page d'administration.
 */
class IAWM_Settings {

	/** Option stockant les identifiants d'API (key_id + secret). */
	const OPTION_CREDENTIALS = 'iawm_credentials';

	/** Option stockant l'état du kill switch. */
	const OPTION_KILL_SWITCH = 'iawm_kill_switch';

	/**
	 * Retourne les identifiants d'API, ou null si aucun n'est configuré.
	 *
	 * @return array|null Tableau { key_id, secret, created_at } ou null.
	 */
	public static function get_credentials() {
		$creds = get_option( self::OPTION_CREDENTIALS );

		if ( ! is_array( $creds ) || empty( $creds['key_id'] ) || empty( $creds['secret'] ) ) {
			return null;
		}

		return $creds;
	}

	/**
	 * Indique si des identifiants d'API sont configurés.
	 *
	 * @return bool
	 */
	public static function has_credentials() {
		return null !== self::get_credentials();
	}

	/**
	 * Génère un nouveau couple identifiant / secret et le sauvegarde.
	 *
	 * Tout secret précédent est définitivement remplacé : le pont MCP doit
	 * alors être reconfiguré avec le nouveau secret.
	 *
	 * @return array Les nouveaux identifiants.
	 */
	public static function generate_credentials() {
		$creds = array(
			'key_id'     => 'iawm_' . bin2hex( random_bytes( 6 ) ),
			'secret'     => bin2hex( random_bytes( 32 ) ),
			'created_at' => gmdate( 'c' ),
		);

		// autoload=false : le secret n'est lu que sur les requêtes de l'API.
		update_option( self::OPTION_CREDENTIALS, $creds, false );

		return $creds;
	}

	/**
	 * Supprime les identifiants d'API : l'agent ne peut plus s'authentifier.
	 *
	 * @return void
	 */
	public static function revoke_credentials() {
		delete_option( self::OPTION_CREDENTIALS );
	}

	/**
	 * Indique si le kill switch est actif (écritures coupées).
	 *
	 * @return bool
	 */
	public static function is_kill_switch_on() {
		return (bool) get_option( self::OPTION_KILL_SWITCH, false );
	}

	/**
	 * Active ou désactive le kill switch.
	 *
	 * @param bool $on État souhaité.
	 * @return void
	 */
	public static function set_kill_switch( $on ) {
		update_option( self::OPTION_KILL_SWITCH, (bool) $on, true );
	}
}
