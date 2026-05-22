<?php
/**
 * Authentification des requêtes de l'API IA Webmaster Bridge.
 *
 * Chaque requête (hors /ping) doit être signée en HMAC-SHA256 avec le secret
 * partagé. La signature couvre la méthode, la route, la query, un horodatage
 * et un nonce : cela garantit l'authenticité, l'intégrité, et protège du rejeu.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vérification de signature et garde des routes REST.
 */
class IAWM_Auth {

	/** Tolérance, en secondes, sur l'horodatage des requêtes (anti-rejeu). */
	const TIMESTAMP_TOLERANCE = 300;

	/** Préfixe du schéma de signature (séparateur de domaine). */
	const SIGNATURE_SCHEME = 'IAWM-HMAC-SHA256';

	/**
	 * Permission callback pour les routes en lecture.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return true|WP_Error
	 */
	public static function guard_read( $request ) {
		return self::guard( $request, false );
	}

	/**
	 * Permission callback pour les routes en écriture.
	 *
	 * @param WP_REST_Request $request Requête entrante.
	 * @return true|WP_Error
	 */
	public static function guard_write( $request ) {
		return self::guard( $request, true );
	}

	/**
	 * Vérifie l'authentification d'une requête.
	 *
	 * @param WP_REST_Request $request       Requête entrante.
	 * @param bool            $require_write True si la requête modifie le site.
	 * @return true|WP_Error
	 */
	public static function guard( $request, $require_write ) {
		$creds = IAWM_Settings::get_credentials();

		if ( null === $creds ) {
			return new WP_Error(
				'iawm_not_configured',
				"L'adaptateur n'est pas configuré : aucun identifiant d'API.",
				array( 'status' => 503 )
			);
		}

		$key_id    = (string) $request->get_header( 'X-IAWM-Key' );
		$timestamp = (string) $request->get_header( 'X-IAWM-Timestamp' );
		$nonce     = (string) $request->get_header( 'X-IAWM-Nonce' );
		$signature = (string) $request->get_header( 'X-IAWM-Signature' );

		if ( '' === $key_id || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return self::deny( "En-têtes d'authentification manquants." );
		}

		// Identifiant de clé (comparaison en temps constant).
		if ( ! hash_equals( (string) $creds['key_id'], $key_id ) ) {
			return self::deny( 'Identifiant de clé inconnu.' );
		}

		// Horodatage : la requête doit tomber dans la fenêtre de tolérance.
		if ( ! ctype_digit( $timestamp ) ) {
			return self::deny( 'Horodatage invalide.' );
		}
		if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
			return self::deny( 'Requête expirée ou horodatage hors tolérance.' );
		}

		// Nonce : usage unique, pour empêcher le rejeu d'une requête signée.
		$nonce_key = 'iawm_nonce_' . hash( 'sha256', $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return self::deny( 'Nonce déjà utilisé (rejeu détecté).' );
		}

		// Signature HMAC.
		$message  = self::build_message( $request, $timestamp, $nonce );
		$expected = hash_hmac( 'sha256', $message, (string) $creds['secret'] );

		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return self::deny( 'Signature invalide.' );
		}

		// Signature valide : on consomme le nonce (durée de vie > tolérance).
		set_transient( $nonce_key, time(), self::TIMESTAMP_TOLERANCE * 2 );

		// Kill switch : bloque les requêtes en écriture.
		if ( $require_write && IAWM_Settings::is_kill_switch_on() ) {
			return new WP_Error(
				'iawm_kill_switch',
				'Les écritures sont désactivées (kill switch actif).',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Construit le message canonique signé.
	 *
	 * Format — sept éléments séparés par des sauts de ligne « \n » :
	 *   1. IAWM-HMAC-SHA256        (préfixe de schéma)
	 *   2. méthode HTTP            (majuscules)
	 *   3. route REST              (ex. /ia-webmaster/v1/status)
	 *   4. query canonique         (paramètres triés, ou chaîne vide)
	 *   5. horodatage Unix
	 *   6. nonce
	 *   7. SHA-256 hexadécimal du corps brut (hash de "" si corps vide)
	 *
	 * Le pont MCP doit reproduire ce message à l'identique pour signer.
	 *
	 * @param WP_REST_Request $request   Requête entrante.
	 * @param string          $timestamp Horodatage transmis.
	 * @param string          $nonce     Nonce transmis.
	 * @return string
	 */
	public static function build_message( $request, $timestamp, $nonce ) {
		$method = strtoupper( $request->get_method() );
		$route  = $request->get_route();
		$query  = self::canonical_query( $request->get_query_params() );
		$body   = (string) $request->get_body();

		return implode(
			"\n",
			array(
				self::SIGNATURE_SCHEME,
				$method,
				$route,
				$query,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Sérialise les paramètres de query de façon déterministe (triés par clé).
	 *
	 * @param array $params Paramètres de query.
	 * @return string
	 */
	private static function canonical_query( $params ) {
		if ( empty( $params ) || ! is_array( $params ) ) {
			return '';
		}

		ksort( $params );

		$pairs = array();
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}

		return implode( '&', $pairs );
	}

	/**
	 * Construit une erreur d'authentification (HTTP 401).
	 *
	 * @param string $message Message lisible.
	 * @return WP_Error
	 */
	private static function deny( $message ) {
		return new WP_Error( 'iawm_unauthorized', $message, array( 'status' => 401 ) );
	}
}
