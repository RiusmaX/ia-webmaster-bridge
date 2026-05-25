<?php
/**
 * Backup module — pre-operation snapshots and restore.
 *
 * Phase 5.2 of the security plan: any destructive operation (plugin
 * install/activate/deactivate, risky settings update, future Phase 4
 * theme/db ops) takes an automatic snapshot **before** running, so the
 * operator has a safety net. The snapshot id is surfaced in the operation
 * response under `pre_op_backup_id`.
 *
 * Three snapshot kinds are supported, in order of cost / blast radius:
 *
 *   - `options`        : a JSON map of WordPress options. Cheap; restoration
 *                        is a simple `update_option()` per key. Used for
 *                        settings updates and as the canonical backing for
 *                        plugin-state snapshots.
 *   - `plugins_state`  : a derived `options` snapshot capturing
 *                        `active_plugins`, `recently_activated` and the
 *                        list of installed plugin headers. Restoration
 *                        replays activation/deactivation as needed.
 *   - `tables`         : a SQL dump of one or more tables. Reserved for
 *                        Phase 4 database operations; restoration replays
 *                        the dump. Mandatorily preceded by `dry_run`.
 *
 * Snapshots are stored in the `wp_iawm_backups` table; the payload is
 * a TEXT column with up to LONGTEXT capacity (≈4 GB) — far beyond
 * anything we plan to produce here. Old snapshots can be pruned via
 * `prune_old()` to keep the table small.
 *
 * The /backup/* REST routes are scoped to `infra:write` (writes) or
 * `read` (list/get). The restore route additionally supports
 * `dry_run` so callers can preview the diff before applying.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Snapshot + restore module.
 */
class IAWM_Backup {

	/** Database schema version of the backups table. */
	const DB_VERSION = 1;

	/** Option that stores the installed schema version. */
	const OPTION_DB_VERSION = 'iawm_backup_db_version';

	/** Snapshot kind: WordPress option values. */
	const KIND_OPTIONS = 'options';

	/** Snapshot kind: WordPress plugin activation state. */
	const KIND_PLUGINS = 'plugins_state';

	/** Snapshot kind: raw SQL table dump. */
	const KIND_TABLES = 'tables';

	/** Option storing how many backup records to keep (Phase 7.2). */
	const OPTION_RETENTION_N = 'iawm_backup_keep_n';

	/** Default retention if the option is unset. */
	const DEFAULT_RETENTION_N = 50;

	/** WP-Cron hook fired daily to prune old backups. */
	const PRUNE_HOOK = 'iawm_prune_backups';

	/**
	 * Hooks up the module: schema migration + REST route registration +
	 * daily rotation cron.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( self::PRUNE_HOOK, array( __CLASS__, 'auto_prune' ) );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			// Offset from the audit-log job by 15 minutes so the two
			// don't fight over wpdb at the same time.
			$ts = self::next_run_at( 3, 15 );
			wp_schedule_event( $ts, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Returns the configured retention count, clamped to [1, 10000].
	 *
	 * @return int
	 */
	public static function get_retention_n() {
		$n = (int) get_option( self::OPTION_RETENTION_N, self::DEFAULT_RETENTION_N );
		return max( 1, min( 10000, $n ) );
	}

	/**
	 * Cron callback: prune to the configured retention count.
	 *
	 * @return int Number of deleted rows.
	 */
	public static function auto_prune() {
		return self::prune_old( self::get_retention_n() );
	}

	/**
	 * Returns the next site-time timestamp matching the given hour +
	 * minute.
	 *
	 * @param int $hour   Hour 0-23.
	 * @param int $minute Minute 0-59.
	 * @return int Unix timestamp.
	 */
	private static function next_run_at( $hour, $minute = 0 ) {
		$tz   = wp_timezone();
		$now  = new DateTime( 'now', $tz );
		$next = clone $now;
		$next->setTime( $hour, $minute, 0 );
		if ( $next <= $now ) {
			$next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'iawm_backups';
	}

	/**
	 * Creates or migrates the schema as needed. Idempotent.
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
	 * Installs the backups table.
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
  kind VARCHAR(30) NOT NULL DEFAULT '',
  label VARCHAR(255) NOT NULL DEFAULT '',
  trigger_route VARCHAR(255) NOT NULL DEFAULT '',
  size_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  payload LONGTEXT NOT NULL,
  restored_at DATETIME NULL DEFAULT NULL,
  restored_by_key VARCHAR(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY kind (kind)
) $collate;";

		dbDelta( $sql );
	}

	/* ----------------------------------------------------------------- */
	/* Snapshot creation                                                  */
	/* ----------------------------------------------------------------- */

	/**
	 * Captures the current value of a set of WordPress options.
	 *
	 * @param array  $option_names   List of option names to snapshot.
	 * @param string $label          Human-readable label.
	 * @param string $trigger_route  Route that triggered the snapshot, or '' for manual.
	 * @return int|null New backup id, or null on failure.
	 */
	public static function snapshot_options( $option_names, $label, $trigger_route = '' ) {
		$option_names = array_values( array_unique( array_filter( (array) $option_names, 'is_string' ) ) );

		$values = array();
		foreach ( $option_names as $name ) {
			// Use a sentinel for missing options so restore can distinguish "absent" from "false".
			$values[ $name ] = get_option( $name, '__iawm_absent__' );
		}

		$payload = wp_json_encode(
			array(
				'kind'    => self::KIND_OPTIONS,
				'options' => $values,
			)
		);

		return self::insert(
			array(
				'kind'          => self::KIND_OPTIONS,
				'label'         => $label,
				'trigger_route' => $trigger_route,
				'payload'       => $payload,
			)
		);
	}

	/**
	 * Captures the current plugin activation state.
	 *
	 * The snapshot includes:
	 *   - `active_plugins`         (the active list)
	 *   - `recently_activated`     (deactivation timestamps)
	 *   - `installed`              (slug -> {version, name, file} for every plugin
	 *                               on disk, so the operator can audit what was
	 *                               installed at backup time)
	 *
	 * @param string $label         Human-readable label.
	 * @param string $trigger_route Route that triggered the snapshot.
	 * @return int|null New backup id, or null on failure.
	 */
	public static function snapshot_plugins_state( $label, $trigger_route = '' ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array();
		foreach ( get_plugins() as $file => $data ) {
			$installed[ $file ] = array(
				'name'    => isset( $data['Name'] ) ? (string) $data['Name'] : '',
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
			);
		}

		$payload = wp_json_encode(
			array(
				'kind'               => self::KIND_PLUGINS,
				'active_plugins'     => (array) get_option( 'active_plugins', array() ),
				'recently_activated' => (array) get_option( 'recently_activated', array() ),
				'installed'          => $installed,
			)
		);

		return self::insert(
			array(
				'kind'          => self::KIND_PLUGINS,
				'label'         => $label,
				'trigger_route' => $trigger_route,
				'payload'       => $payload,
			)
		);
	}

	/**
	 * Captures one or more database tables as a SQL dump.
	 *
	 * Implementation note: we use straight `SHOW CREATE TABLE` plus a row
	 * iteration with `wpdb->prepare()` to build INSERT statements. This is
	 * safe for the typical tables we'll back up (`wp_options`, plugin
	 * tables) but expensive for very large tables; callers should be
	 * intentional about which tables they pass in.
	 *
	 * @param array  $tables         Fully-qualified table names (e.g. "wp_options").
	 * @param string $label          Human-readable label.
	 * @param string $trigger_route  Route that triggered the snapshot.
	 * @return int|null New backup id, or null on failure.
	 */
	public static function snapshot_tables( $tables, $label, $trigger_route = '' ) {
		global $wpdb;
		$tables = array_values( array_unique( array_filter( (array) $tables, 'is_string' ) ) );
		if ( empty( $tables ) ) {
			return null;
		}

		$dump = array();
		foreach ( $tables as $table ) {
			// Allow only known plugin / core tables to limit foot-gun risk.
			if ( ! preg_match( '/^[a-z0-9_]+$/i', $table ) ) {
				continue;
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				continue;
			}

			$create_row = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
			if ( ! is_array( $create_row ) || empty( $create_row[1] ) ) {
				continue;
			}

			$dump[] = "-- Table: $table";
			$dump[] = "DROP TABLE IF EXISTS `$table`;";
			$dump[] = $create_row[1] . ';';

			$rows = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
			if ( ! empty( $rows ) ) {
				$columns = array_keys( $rows[0] );
				$col_sql = '`' . implode( '`,`', $columns ) . '`';

				foreach ( $rows as $row ) {
					$values = array();
					foreach ( $columns as $col ) {
						$v = $row[ $col ];
						if ( null === $v ) {
							$values[] = 'NULL';
						} else {
							$values[] = "'" . $wpdb->_real_escape( (string) $v ) . "'";
						}
					}
					$dump[] = "INSERT INTO `$table` ($col_sql) VALUES (" . implode( ',', $values ) . ');';
				}
			}
		}

		if ( empty( $dump ) ) {
			return null;
		}

		$payload = wp_json_encode(
			array(
				'kind'   => self::KIND_TABLES,
				'tables' => $tables,
				'sql'    => implode( "\n", $dump ),
			)
		);

		return self::insert(
			array(
				'kind'          => self::KIND_TABLES,
				'label'         => $label,
				'trigger_route' => $trigger_route,
				'payload'       => $payload,
			)
		);
	}

	/**
	 * Inserts a record. Returns the new id, or null on failure.
	 *
	 * @param array $row Pre-validated entry.
	 * @return int|null
	 */
	private static function insert( $row ) {
		global $wpdb;

		$payload = (string) $row['payload'];

		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'created_at'    => gmdate( 'Y-m-d H:i:s' ),
				'kind'          => (string) $row['kind'],
				'label'         => substr( (string) $row['label'], 0, 255 ),
				'trigger_route' => substr( (string) $row['trigger_route'], 0, 255 ),
				'size_bytes'    => strlen( $payload ),
				'payload'       => $payload,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : null;
	}

	/* ----------------------------------------------------------------- */
	/* Reads                                                              */
	/* ----------------------------------------------------------------- */

	/**
	 * Lists backups, newest first.
	 *
	 * @param int $limit  Per-page (clamped 1-100).
	 * @param int $offset Offset (>=0).
	 * @return array<int, array> Rows without the payload column.
	 */
	public static function list_backups( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$limit  = max( 1, min( 100, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$table  = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, kind, label, trigger_route, size_bytes, restored_at, restored_by_key
				 FROM `$table` ORDER BY id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['id']         = (int) $row['id'];
			$row['size_bytes'] = (int) $row['size_bytes'];
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Fetches a backup including its decoded payload.
	 *
	 * @param int $id Backup id.
	 * @return array|null
	 */
	public static function get_backup( $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", (int) $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']         = (int) $row['id'];
		$row['size_bytes'] = (int) $row['size_bytes'];
		$row['payload']    = json_decode( (string) $row['payload'], true );
		return $row;
	}

	/**
	 * Deletes a backup record.
	 *
	 * @param int $id Backup id.
	 * @return bool
	 */
	public static function delete_backup( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table_name(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Keeps only the last $keep backups; deletes the rest.
	 *
	 * @param int $keep Number of records to keep.
	 * @return int Number of deleted rows.
	 */
	public static function prune_old( $keep = 50 ) {
		global $wpdb;
		$keep  = max( 1, (int) $keep );
		$table = self::table_name();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM `$table` ORDER BY id DESC LIMIT 1000 OFFSET %d", $keep )
		);
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `$table` WHERE id IN ($placeholders)", $ids ) );
	}

	/* ----------------------------------------------------------------- */
	/* Restore                                                            */
	/* ----------------------------------------------------------------- */

	/**
	 * Restores a backup. Dispatches by kind.
	 *
	 * @param int  $id      Backup id.
	 * @param bool $dry_run If true, return the diff without applying.
	 * @param string $key_id Key id that requested the restore (for audit).
	 * @return array Result array with `ok`, `dry_run`, `kind`, kind-specific fields.
	 */
	public static function restore( $id, $dry_run = false, $key_id = '' ) {
		$backup = self::get_backup( $id );
		if ( ! $backup ) {
			return array( 'ok' => false, 'error' => 'not_found' );
		}

		switch ( (string) $backup['kind'] ) {
			case self::KIND_OPTIONS:
				return self::restore_options( $backup, $dry_run, $key_id );
			case self::KIND_PLUGINS:
				return self::restore_plugins( $backup, $dry_run, $key_id );
			case self::KIND_TABLES:
				return self::restore_tables( $backup, $dry_run, $key_id );
		}
		return array( 'ok' => false, 'error' => 'unknown_kind' );
	}

	/**
	 * Restores option values from a snapshot.
	 *
	 * @param array  $backup  Backup row with decoded payload.
	 * @param bool   $dry_run If true, only return the diff.
	 * @param string $key_id  Calling key id, for the audit trail.
	 * @return array
	 */
	private static function restore_options( $backup, $dry_run, $key_id ) {
		$payload  = is_array( $backup['payload'] ) ? $backup['payload'] : array();
		$snapshot = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();

		$diff = array();
		foreach ( $snapshot as $name => $value ) {
			$current = get_option( $name, '__iawm_absent__' );
			if ( $current !== $value ) {
				$diff[ $name ] = array(
					'current' => '__iawm_absent__' === $current ? null : $current,
					'restore' => '__iawm_absent__' === $value ? null : $value,
				);
			}
		}

		if ( $dry_run ) {
			return array(
				'ok'      => true,
				'dry_run' => true,
				'kind'    => self::KIND_OPTIONS,
				'diff'    => $diff,
			);
		}

		foreach ( $snapshot as $name => $value ) {
			if ( '__iawm_absent__' === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value );
			}
		}

		self::mark_restored( (int) $backup['id'], $key_id );

		return array(
			'ok'       => true,
			'dry_run'  => false,
			'kind'     => self::KIND_OPTIONS,
			'restored' => count( $snapshot ),
			'diff'     => $diff,
		);
	}

	/**
	 * Restores the plugin activation state.
	 *
	 * @param array  $backup  Backup row.
	 * @param bool   $dry_run If true, only return the plan.
	 * @param string $key_id  Calling key id.
	 * @return array
	 */
	private static function restore_plugins( $backup, $dry_run, $key_id ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$payload   = is_array( $backup['payload'] ) ? $backup['payload'] : array();
		$snap_active = isset( $payload['active_plugins'] ) ? (array) $payload['active_plugins'] : array();
		$now_active  = (array) get_option( 'active_plugins', array() );

		$to_activate   = array_values( array_diff( $snap_active, $now_active ) );
		$to_deactivate = array_values( array_diff( $now_active, $snap_active ) );

		// Never deactivate the bridge itself during restore.
		$to_deactivate = array_values( array_diff( $to_deactivate, array( IAWM_Plugins::SELF_PLUGIN_FILE ) ) );

		if ( $dry_run ) {
			return array(
				'ok'             => true,
				'dry_run'        => true,
				'kind'           => self::KIND_PLUGINS,
				'to_activate'    => $to_activate,
				'to_deactivate'  => $to_deactivate,
			);
		}

		$results = array(
			'activated'   => array(),
			'deactivated' => array(),
			'errors'      => array(),
		);

		IAWM_Support::act_as_agent();

		foreach ( $to_deactivate as $file ) {
			deactivate_plugins( $file );
			$results['deactivated'][] = $file;
		}
		foreach ( $to_activate as $file ) {
			// Only re-activate plugins that are still installed on disk.
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				$results['errors'][] = "Plugin file no longer present: $file";
				continue;
			}
			$res = activate_plugin( $file, '', false, true );
			if ( is_wp_error( $res ) ) {
				$results['errors'][] = $file . ': ' . $res->get_error_message();
			} else {
				$results['activated'][] = $file;
			}
		}

		self::mark_restored( (int) $backup['id'], $key_id );

		return array(
			'ok'      => true,
			'dry_run' => false,
			'kind'    => self::KIND_PLUGINS,
			'result'  => $results,
		);
	}

	/**
	 * Restores raw tables from a SQL dump.
	 *
	 * Note: every statement is run through `wpdb->query()` and stops at
	 * the first error. This is intentionally a thin restore — heavy
	 * recovery scenarios should go through a proper backup tool.
	 *
	 * @param array  $backup  Backup row.
	 * @param bool   $dry_run If true, only return the table list.
	 * @param string $key_id  Calling key id.
	 * @return array
	 */
	private static function restore_tables( $backup, $dry_run, $key_id ) {
		global $wpdb;

		$payload = is_array( $backup['payload'] ) ? $backup['payload'] : array();
		$sql     = isset( $payload['sql'] ) ? (string) $payload['sql'] : '';
		$tables  = isset( $payload['tables'] ) ? (array) $payload['tables'] : array();

		if ( $dry_run ) {
			return array(
				'ok'      => true,
				'dry_run' => true,
				'kind'    => self::KIND_TABLES,
				'tables'  => $tables,
				'sql_len' => strlen( $sql ),
			);
		}

		$statements = array_filter(
			array_map( 'trim', explode( ";\n", $sql ) ),
			static function ( $s ) {
				return '' !== $s && '--' !== substr( $s, 0, 2 );
			}
		);

		$executed = 0;
		foreach ( $statements as $stmt ) {
			// Strip an optional trailing semicolon left over after split.
			$stmt = rtrim( $stmt, ";\n " );
			if ( '' === $stmt || '--' === substr( $stmt, 0, 2 ) ) {
				continue;
			}
			$res = $wpdb->query( $stmt );
			if ( false === $res ) {
				return array(
					'ok'       => false,
					'error'    => 'sql_error',
					'message'  => $wpdb->last_error,
					'executed' => $executed,
				);
			}
			++$executed;
		}

		self::mark_restored( (int) $backup['id'], $key_id );

		return array(
			'ok'       => true,
			'dry_run'  => false,
			'kind'     => self::KIND_TABLES,
			'tables'   => $tables,
			'executed' => $executed,
		);
	}

	/**
	 * Stamps a backup record as restored.
	 *
	 * @param int    $id     Backup id.
	 * @param string $key_id Key id of the caller.
	 * @return void
	 */
	private static function mark_restored( $id, $key_id ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array(
				'restored_at'     => gmdate( 'Y-m-d H:i:s' ),
				'restored_by_key' => substr( (string) $key_id, 0, 64 ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/* ----------------------------------------------------------------- */
	/* REST routes                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Registers the /backup/* routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/backup/list'    => array( 'handle_list', 'guard_read' ),
			'/backup/get'     => array( 'handle_get', 'guard_read' ),
			'/backup/create'  => array( 'handle_create', 'guard_write' ),
			'/backup/restore' => array( 'handle_restore', 'guard_write' ),
			'/backup/delete'  => array( 'handle_delete', 'guard_write' ),
			'/backup/prune'   => array( 'handle_prune', 'guard_write' ),
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
	 * POST /backup/list — { limit?, offset? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );
		$limit  = isset( $params['limit'] ) ? (int) $params['limit'] : 50;
		$offset = isset( $params['offset'] ) ? (int) $params['offset'] : 0;

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'items'   => self::list_backups( $limit, $offset ),
				'limit'   => $limit,
				'offset'  => $offset,
			),
			200
		);
	}

	/**
	 * POST /backup/get — { id, include_payload? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_get( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$id      = isset( $params['id'] ) ? (int) $params['id'] : 0;
		$include = ! empty( $params['include_payload'] );

		$backup = self::get_backup( $id );
		if ( ! $backup ) {
			return IAWM_Support::rest_error( 'iawm_backup_not_found', "Backup not found: {$id}.", 404 );
		}
		if ( ! $include ) {
			unset( $backup['payload'] );
		}

		return new WP_REST_Response( array( 'ok' => true, 'item' => $backup ), 200 );
	}

	/**
	 * POST /backup/create — manual snapshot.
	 *
	 * { kind: 'options' | 'plugins_state' | 'tables', label?, option_names?, tables? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$kind   = isset( $params['kind'] ) ? sanitize_key( (string) $params['kind'] ) : '';
		$label  = isset( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : 'Manual snapshot';

		switch ( $kind ) {
			case self::KIND_OPTIONS:
				$names = isset( $params['option_names'] ) ? (array) $params['option_names'] : array();
				$id    = self::snapshot_options( $names, $label );
				break;
			case self::KIND_PLUGINS:
				$id = self::snapshot_plugins_state( $label );
				break;
			case self::KIND_TABLES:
				$tables = isset( $params['tables'] ) ? (array) $params['tables'] : array();
				$id     = self::snapshot_tables( $tables, $label );
				break;
			default:
				return IAWM_Support::rest_error( 'iawm_invalid_kind', "Invalid backup kind: {$kind}.", 400 );
		}

		if ( null === $id ) {
			return IAWM_Support::rest_error( 'iawm_snapshot_failed', 'Snapshot produced no payload.', 400 );
		}

		return new WP_REST_Response(
			array( 'ok' => true, 'created' => true, 'backup_id' => $id ),
			201
		);
	}

	/**
	 * POST /backup/restore — { id, dry_run? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_restore( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$id      = isset( $params['id'] ) ? (int) $params['id'] : 0;
		$dry_run = ! empty( $params['dry_run'] );
		$key_id  = (string) $request->get_header( 'X-IAWM-Key' );

		// Phase 5.3: a real restore (not dry_run) requires an explicit
		// confirmation token. The first call returns the token + a
		// summary of what the restore would do; the second call applies.
		$summary = array();
		if ( ! $dry_run ) {
			$preview = self::restore( $id, true, $key_id );
			if ( empty( $preview['ok'] ) ) {
				return new WP_REST_Response( $preview, 400 );
			}
			$summary = array(
				'kind'    => isset( $preview['kind'] ) ? $preview['kind'] : null,
				'preview' => $preview,
			);
		}
		$confirm = IAWM_Confirmation::guard( $request, $params, $summary );
		if ( null !== $confirm ) {
			return $confirm;
		}

		$result = self::restore( $id, $dry_run, $key_id );
		$code   = ! empty( $result['ok'] ) ? 200 : 400;

		return new WP_REST_Response( $result, $code );
	}

	/**
	 * POST /backup/delete — { id }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'iawm_invalid_id', 'Missing or invalid id.', 400 );
		}
		$ok = self::delete_backup( $id );
		return new WP_REST_Response( array( 'ok' => $ok, 'deleted' => $ok, 'id' => $id ), $ok ? 200 : 404 );
	}

	/**
	 * POST /backup/prune — { keep? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_prune( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$keep    = isset( $params['keep'] ) ? (int) $params['keep'] : 50;
		$deleted = self::prune_old( $keep );
		return new WP_REST_Response( array( 'ok' => true, 'kept' => $keep, 'deleted' => $deleted ), 200 );
	}
}
