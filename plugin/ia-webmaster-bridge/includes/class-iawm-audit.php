<?php
/**
 * Audit log: traces every call to the adapter's API.
 *
 * Every request to the ia-webmaster/v1 namespace (except /ping) is recorded
 * in a dedicated table — both successes and denials — in order to keep a full
 * trace of the agent's activity and access attempts.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recording and reading of the audit log.
 */
class IAWM_Audit {

	/** Database schema version; to be incremented on every change. */
	const DB_VERSION = 1;

	/** Option storing the installed schema version. */
	const OPTION_DB_VERSION = 'iawm_db_version';

	/** Option storing how many days to keep audit rows (Phase 7.2). */
	const OPTION_RETENTION_DAYS = 'iawm_audit_retention_days';

	/** Default retention if the option is unset. */
	const DEFAULT_RETENTION_DAYS = 90;

	/** WP-Cron hook fired daily to prune the audit log. */
	const PRUNE_HOOK = 'iawm_prune_audit_log';

	/**
	 * Hooks up the audit log.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'record' ), 10, 3 );

		// Daily rotation job — registered on every load (idempotent).
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_old' ) );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			// Fire daily at +3 AM site time (offset from "now" by however
			// long it takes to reach 03:00 next).
			wp_schedule_event( self::next_run_at( 3 ), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Returns the configured retention in days, clamped to [1, 3650].
	 *
	 * @return int
	 */
	public static function get_retention_days() {
		$days = (int) get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS );
		return max( 1, min( 3650, $days ) );
	}

	/**
	 * Deletes audit rows older than the configured retention window.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function prune_old() {
		global $wpdb;
		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::get_retention_days() * DAY_IN_SECONDS ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- maintenance job, no caching needed.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE created_at < %s", $cutoff ) );
		// phpcs:enable
		return (int) $deleted;
	}

	/**
	 * Returns the next site-time timestamp that matches the given hour.
	 *
	 * @param int $hour Hour 0-23.
	 * @return int Unix timestamp.
	 */
	private static function next_run_at( $hour ) {
		$tz   = wp_timezone();
		$now  = new DateTime( 'now', $tz );
		$next = clone $now;
		$next->setTime( $hour, 0, 0 );
		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Full table name for the log.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'iawm_audit_log';
	}

	/**
	 * Creates or updates the database schema if needed.
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
	 * Creates the audit log table.
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
	 * rest_post_dispatch filter: records the request if it targets the adapter.
	 *
	 * @param WP_HTTP_Response $response REST response.
	 * @param WP_REST_Server   $server   REST server.
	 * @param WP_REST_Request  $request  Incoming request.
	 * @return WP_HTTP_Response The response, unchanged.
	 */
	public static function record( $response, $server, $request ) {
		$route  = (string) $request->get_route();
		$prefix = '/' . IAWM_REST_NAMESPACE . '/';

		// Only trace our namespace, and ignore the public /ping diagnostic.
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

		// Enrich with key label + linked user (since multi-key support v0.26.0),
		// so the audit log makes sense at a glance when several humans share
		// the same site.
		$key_id = (string) $request->get_header( 'X-IAWM-Key' );
		if ( '' !== $key_id && class_exists( 'IAWM_Settings' ) ) {
			$record = IAWM_Settings::get_by_key_id( $key_id );
			if ( null !== $record ) {
				if ( ! empty( $record['label'] ) ) {
					$detail['key_label'] = $record['label'];
				}
				if ( ! empty( $record['linked_user_id'] ) ) {
					$detail['linked_user_id'] = (int) $record['linked_user_id'];
				}
			}
		}

		// In case of an error response, keep the application code.
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
				'key_id'  => substr( $key_id, 0, 64 ),
				'ip'      => self::client_ip(),
				'detail'  => $detail,
			)
		);

		return $response;
	}

	/**
	 * Inserts an entry into the log.
	 *
	 * @param array $entry Entry data.
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
	 * Returns the most recent log entries, newest first.
	 *
	 * @param int $limit Number of entries (clamped between 1 and 200).
	 * @return array
	 */
	public static function get_recent( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 200, (int) $limit ) );
		$table = self::table_name();

		// $table is built internally (wpdb prefix + literal), not from user input.
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
	 * Classifies an HTTP status code into an outcome category.
	 *
	 * @param int $status HTTP code.
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
	 * Caller's IP address (truncated to the column size).
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		return substr( preg_replace( '/[^0-9a-fA-F:.]/', '', $ip ), 0, 45 );
	}
}
