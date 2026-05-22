<?php
/**
 * Journal d'audit : trace chaque appel de l'API de l'adaptateur.
 *
 * Chaque requête vers le namespace ia-webmaster/v1 (hors /ping) est enregistrée
 * dans une table dédiée — succès comme refus — afin de garder une trace
 * complète de l'activité de l'agent et des tentatives d'accès.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enregistrement et consultation du journal d'audit.
 */
class IAWM_Audit {

	/** Version du schéma de la base ; à incrémenter à chaque changement. */
	const DB_VERSION = 1;

	/** Option stockant la version de schéma installée. */
	const OPTION_DB_VERSION = 'iawm_db_version';

	/**
	 * Branche les hooks du journal d'audit.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'record' ), 10, 3 );
	}

	/**
	 * Nom complet de la table du journal.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'iawm_audit_log';
	}

	/**
	 * Crée ou met à jour le schéma de la base si nécessaire.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::install();
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
	}

	/**
	 * Crée la table du journal d'audit.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  method VARCHAR(10) NOT NULL DEFAULT '',
  route VARCHAR(255) NOT NULL DEFAULT '',
  status SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  outcome VARCHAR(20) NOT NULL DEFAULT '',
  key_id VARCHAR(64) NOT NULL DEFAULT '',
  ip VARCHAR(45) NOT NULL DEFAULT '',
  detail TEXT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY route (route)
) $collate;";

		dbDelta( $sql );
	}

	/**
	 * Filtre rest_post_dispatch : enregistre la requête si elle vise l'adaptateur.
	 *
	 * @param WP_HTTP_Response $response Réponse REST.
	 * @param WP_REST_Server   $server   Serveur REST.
	 * @param WP_REST_Request  $request  Requête entrante.
	 * @return WP_HTTP_Response La réponse, inchangée.
	 */
	public static function record( $response, $server, $request ) {
		$route  = (string) $request->get_route();
		$prefix = '/' . IAWM_REST_NAMESPACE . '/';

		// Ne tracer que notre namespace, et ignorer le diagnostic public /ping.
		if ( 0 !== strpos( $route, $prefix ) || ( $prefix . 'ping' ) === $route ) {
			return $response;
		}

		$status = is_object( $response ) && method_exists( $response, 'get_status' )
			? (int) $response->get_status()
			: 0;

		$detail = array(
			'query'      => $request->get_query_params(),
			'body_bytes' => strlen( (string) $request->get_body() ),
		);

		// En cas de réponse d'erreur, conserver le code applicatif.
		if ( is_object( $response ) && method_exists( $response, 'get_data' ) ) {
			$data = $response->get_data();
			if ( is_array( $data ) && isset( $data['code'] ) ) {
				$detail['error'] = $data['code'];
			}
		}

		self::log(
			array(
				'method'  => strtoupper( (string) $request->get_method() ),
				'route'   => $route,
				'status'  => $status,
				'outcome' => self::outcome_from_status( $status ),
				'key_id'  => substr( (string) $request->get_header( 'X-IAWM-Key' ), 0, 64 ),
				'ip'      => self::client_ip(),
				'detail'  => $detail,
			)
		);

		return $response;
	}

	/**
	 * Insère une entrée dans le journal.
	 *
	 * @param array $entry Données de l'entrée.
	 * @return void
	 */
	public static function log( $entry ) {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'method'     => (string) $entry['method'],
				'route'      => (string) $entry['route'],
				'status'     => (int) $entry['status'],
				'outcome'    => (string) $entry['outcome'],
				'key_id'     => (string) $entry['key_id'],
				'ip'         => (string) $entry['ip'],
				'detail'     => wp_json_encode( $entry['detail'] ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Retourne les dernières entrées du journal, de la plus récente à la plus ancienne.
	 *
	 * @param int $limit Nombre d'entrées (borné entre 1 et 200).
	 * @return array
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, (int) $limit ) );
		$table = self::table_name();

		// $table est construit en interne (préfixe wpdb + littéral), non issu d'une entrée utilisateur.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `$table` ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['id']     = (int) $row['id'];
			$row['status'] = (int) $row['status'];
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Classe un code HTTP en catégorie de résultat.
	 *
	 * @param int $status Code HTTP.
	 * @return string
	 */
	private static function outcome_from_status( $status ) {
		if ( $status >= 200 && $status < 300 ) {
			return 'success';
		}
		if ( 401 === $status || 403 === $status ) {
			return 'denied';
		}
		if ( $status >= 500 ) {
			return 'error';
		}

		return 'other';
	}

	/**
	 * Adresse IP de l'appelant (tronquée à la taille de la colonne).
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return substr( preg_replace( '/[^0-9a-fA-F:.]/', '', $ip ), 0, 45 );
	}
}
