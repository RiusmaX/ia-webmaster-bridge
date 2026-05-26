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
	 * Option toggling pseudonymisation of sensitive parameters in the
	 * audit log. When `1`, declared sensitive paths (e.g. user passwords,
	 * webhook signing secrets) are replaced with a SHA-256 short prefix
	 * before being serialised to the `detail` column.
	 *
	 * Default off so existing operators don't see a behaviour change on
	 * upgrade; switching to on only affects future writes (legacy rows
	 * are left untouched — see D-031).
	 */
	const OPTION_PSEUDONYMISE = 'iawm_audit_pseudonymise';

	/**
	 * Option toggling the audit-tail watcher that emits `audit.alert`
	 * webhook events when one of the small fixed rule set trips on a
	 * newly recorded row. Default on (1) — operators who do not want any
	 * alert traffic flip it off without uninstalling the webhook module.
	 *
	 * Resolves D-030's "audit alert event firing deferred to v1.4" trade-
	 * off (Phase 10.6).
	 */
	const OPTION_ALERT_ENABLED = 'iawm_audit_alert_enabled';

	/**
	 * CSV option listing which rules are active. Unknown names are
	 * ignored silently so future versions can extend the catalogue
	 * without breaking existing installs.
	 *
	 * Default value: all three v1.4 rules active.
	 */
	const OPTION_ALERT_RULES = 'iawm_audit_alert_rules';

	/**
	 * Default CSV for `OPTION_ALERT_RULES`. Kept in sync with the
	 * dispatch table in `dispatch_rules()`.
	 */
	const DEFAULT_ALERT_RULES = 'scope_denied_burst,kill_switch_toggled,auth_failure_burst';

	/**
	 * Option storing the highest audit-log `id` already evaluated by
	 * the tail watcher. Starts at 0 (no row evaluated yet); advances
	 * monotonically per watcher tick.
	 */
	const OPTION_ALERT_WATERMARK = 'iawm_audit_alert_watermark';

	/** WP-Cron hook fired every 5 minutes to drain the audit tail. */
	const ALERT_HOOK = 'iawm_audit_tail_watch';

	/** Per-tick row cap to keep the watcher cheap even after a backlog. */
	const ALERT_BATCH_SIZE = 500;

	/** Sliding-window length, in seconds, for burst-style rules. */
	const ALERT_BURST_WINDOW_S = 60;

	/** Trip threshold for the `scope_denied_burst` rule. */
	const ALERT_SCOPE_DENIED_THRESHOLD = 5;

	/** Trip threshold for the `auth_failure_burst` rule. */
	const ALERT_AUTH_FAIL_THRESHOLD = 10;

	/**
	 * Per-request stash filled by handler-side `write()` calls. Mapped
	 * by route so `record()` can merge the handler-declared params (with
	 * sensitive paths masked when the toggle is on) into the auto-logged
	 * row at the end of the request.
	 *
	 * Static lifetime is fine: each PHP request is its own process and
	 * the array is cleared once `record()` has picked it up.
	 *
	 * @var array<string, array{params: array, summary: mixed, sensitive_paths: array<int, string>}>
	 */
	private static $pending = array();

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

		// Phase 10.6 — audit-tail watcher fires `audit.alert` webhook
		// events when one of the small fixed rule set trips. Re-uses the
		// `iawm_5min` schedule registered by `IAWM_Webhook`; offset by
		// 90 s from the webhook drainer so the cron load is spread.
		//
		// We also re-register the `cron_schedules` filter ourselves: this
		// module's init() runs before IAWM_Webhook::init() (load order in
		// the plugin bootstrap), and WP's wp_schedule_event() validates
		// the slug against wp_get_schedules() at scheduling time. The
		// callback is idempotent (it checks `isset` on the slug) so
		// registering twice is safe.
		add_filter( 'cron_schedules', array( __CLASS__, 'register_5min_schedule' ) );
		add_action( self::ALERT_HOOK, array( __CLASS__, 'tail_watcher' ) );
		if ( ! wp_next_scheduled( self::ALERT_HOOK ) ) {
			wp_schedule_event( time() + 90, 'iawm_5min', self::ALERT_HOOK );
		}

		// Detect kill-switch toggles regardless of the code path that
		// flipped them (admin form, wp-cli, future REST route): the
		// listener writes a synthetic audit row tagged `iawm_kill_switch_*`
		// so the watcher picks it up like any other detail.error code.
		add_action( 'update_option_' . IAWM_Settings::OPTION_KILL_SWITCH, array( __CLASS__, 'on_kill_switch_update' ), 10, 2 );
		add_action( 'add_option_' . IAWM_Settings::OPTION_KILL_SWITCH, array( __CLASS__, 'on_kill_switch_add' ), 10, 2 );
	}

	/**
	 * Mirror of `IAWM_Webhook::register_schedule()`: registers the
	 * `iawm_5min` cron schedule used by the audit tail watcher. Safe to
	 * call twice — the callback checks the slug is not already declared.
	 *
	 * @param array $schedules WP-Cron schedules registry.
	 * @return array
	 */
	public static function register_5min_schedule( $schedules ) {
		if ( ! isset( $schedules['iawm_5min'] ) ) {
			$schedules['iawm_5min'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (IAWM)', 'ia-webmaster-bridge' ),
			);
		}
		return $schedules;
	}

	/**
	 * `update_option_iawm_kill_switch` listener. Records a synthetic
	 * audit row whenever the kill switch flips so the tail watcher can
	 * fire `audit.alert` independently of where the toggle came from
	 * (admin UI, wp-cli, programmatic).
	 *
	 * No-op when the new and old values are equivalent (e.g. WP fires
	 * the action even when nothing actually changed in some code paths).
	 *
	 * @param mixed $old_value Previous value of the option.
	 * @param mixed $new_value New value of the option.
	 * @return void
	 */
	public static function on_kill_switch_update( $old_value, $new_value ) {
		$old_on = (bool) $old_value;
		$new_on = (bool) $new_value;
		if ( $old_on === $new_on ) {
			return;
		}
		self::record_kill_switch_event( $new_on );
	}

	/**
	 * `add_option_iawm_kill_switch` listener. Fires the first time the
	 * option is ever created (no previous row) — typically the first
	 * "kill_on" before the option has been set.
	 *
	 * @param string $option    Option name (unused).
	 * @param mixed  $new_value Value just created.
	 * @return void
	 */
	public static function on_kill_switch_add( $option, $new_value ) {
		unset( $option );
		if ( ! (bool) $new_value ) {
			// add_option fires for any creation including "off" default;
			// only the on state is interesting.
			return;
		}
		self::record_kill_switch_event( true );
	}

	/**
	 * Writes a synthetic audit row reflecting a kill-switch toggle.
	 *
	 * The row uses a sentinel route (`iawm-internal/kill-switch`) that
	 * does not exist as a REST endpoint, so the rule evaluator can
	 * trigger on it without colliding with real traffic. The outcome
	 * column is set to `kill_switch` (a category outside the standard
	 * success/denied/error/other set, so it can be matched precisely)
	 * and `detail.error` carries `iawm_kill_switch_on` or
	 * `iawm_kill_switch_off` for symmetry with REST error codes.
	 *
	 * Best-effort: failures must not bubble out (the option write must
	 * complete regardless of audit-write success).
	 *
	 * @param bool $on Whether the kill switch is now on.
	 * @return void
	 */
	private static function record_kill_switch_event( $on ) {
		try {
			$user   = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$key_id = '';
			if ( $user instanceof WP_User && $user->ID > 0 ) {
				$key_id = 'wp_user:' . (int) $user->ID;
			}
			self::log(
				array(
					'method'  => 'INTERNAL',
					'route'   => '/' . IAWM_REST_NAMESPACE . '/internal/kill-switch',
					'status'  => 200,
					'outcome' => 'kill_switch',
					'key_id'  => substr( $key_id, 0, 64 ),
					'ip'      => self::client_ip(),
					'detail'  => array(
						'error'           => $on ? 'iawm_kill_switch_on' : 'iawm_kill_switch_off',
						'kill_switch_on'  => (bool) $on,
						'source'          => is_admin() ? 'admin' : 'programmatic',
					),
				)
			);
		} catch ( Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[IAWM] kill-switch audit write failed: ' . $e->getMessage() );
			}
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
	 * Handler-facing entry: enrich the audit row of the current request
	 * with the actual request parameters, optionally masking sensitive
	 * paths.
	 *
	 * The auto-recorder (`record()`, hooked on `rest_post_dispatch`)
	 * stores envelope data (method, route, status, body length). Calling
	 * `write()` from a handler attaches the JSON params to the same row
	 * so the audit trail is readable without re-correlating to a request
	 * log. When the `iawm_audit_pseudonymise` option is on, the
	 * `$sensitive_paths` list is run through `pseudonymise()` first.
	 *
	 * Signature is intentionally permissive: handlers that don't care
	 * about sensitive masking can pass an empty array for the fourth
	 * argument (the default). Existing callers that don't call this at
	 * all keep working — the audit row simply lacks the `params` block.
	 *
	 * @param string $route           REST route, e.g. `/ia-webmaster/v1/config/users/create`.
	 * @param array  $params          Request parameters (typically the JSON body).
	 * @param mixed  $result_summary  Optional handler-side summary stored alongside the params.
	 * @param array  $sensitive_paths Dot-notation paths to mask when the toggle is on.
	 * @return void
	 */
	public static function write( $route, array $params, $result_summary = null, array $sensitive_paths = array() ) {
		$stored = $params;
		if ( ! empty( $sensitive_paths ) && self::is_pseudonymise_on() ) {
			$stored = self::pseudonymise( $params, $sensitive_paths );
		}

		self::$pending[ (string) $route ] = array(
			'params'          => $stored,
			'summary'         => $result_summary,
			'sensitive_paths' => array_values( $sensitive_paths ),
		);
	}

	/**
	 * Walks `$params` along each dot-notation path in `$sensitive_paths`
	 * and replaces the leaf value with a SHA-256 short prefix so two
	 * occurrences of the same value can still be correlated across rows
	 * without leaking the value itself.
	 *
	 * Supports `*` as a wildcard array position, e.g. `users.*.password`
	 * masks `password` on every entry of the `users` list.
	 *
	 * Unresolved paths are silently ignored (the params come back
	 * unchanged for that branch).
	 *
	 * @param array $params          Parameters to mask. Returned as a new array.
	 * @param array $sensitive_paths List of dot-notation paths.
	 * @return array
	 */
	public static function pseudonymise( array $params, array $sensitive_paths ) {
		foreach ( $sensitive_paths as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}
			$segments = explode( '.', $path );
			self::mask_at( $params, $segments );
		}
		return $params;
	}

	/**
	 * Indicates whether the pseudonymisation toggle is enabled.
	 *
	 * Reads `iawm_audit_pseudonymise` defaulting to `0`. Cast through
	 * (int) so a stored `'0'` string is treated as off.
	 *
	 * @return bool
	 */
	public static function is_pseudonymise_on() {
		return 1 === (int) get_option( self::OPTION_PSEUDONYMISE, 0 );
	}

	/**
	 * Recursive helper: walks `$node` along `$segments` and replaces the
	 * leaf with the redaction sentinel. The `*` wildcard descends into
	 * every direct child of an array.
	 *
	 * @param array $node     Reference to the current node.
	 * @param array $segments Remaining path segments.
	 * @return void
	 */
	private static function mask_at( array &$node, array $segments ) {
		if ( empty( $segments ) ) {
			return;
		}

		$head = array_shift( $segments );

		if ( '*' === $head ) {
			foreach ( $node as $key => &$child ) {
				if ( empty( $segments ) ) {
					$node[ $key ] = self::redaction_sentinel( $child );
				} elseif ( is_array( $child ) ) {
					self::mask_at( $child, $segments );
				}
			}
			unset( $child );
			return;
		}

		if ( ! array_key_exists( $head, $node ) ) {
			return;
		}

		if ( empty( $segments ) ) {
			$node[ $head ] = self::redaction_sentinel( $node[ $head ] );
			return;
		}

		if ( is_array( $node[ $head ] ) ) {
			self::mask_at( $node[ $head ], $segments );
		}
	}

	/**
	 * Builds the redaction sentinel for a value.
	 *
	 * Format: `<redacted:sha256:abc123def456>` — human-readable so an
	 * operator scanning the log doesn't get confused, correlatable so two
	 * occurrences of the same value share the same 12-hex prefix, and
	 * non-reversible (the short prefix offers no path back to the value).
	 *
	 * Non-scalar values (nested arrays, objects) are first JSON-encoded
	 * before hashing so the sentinel is still deterministic.
	 *
	 * @param mixed $value Original value.
	 * @return string
	 */
	private static function redaction_sentinel( $value ) {
		if ( is_scalar( $value ) ) {
			$material = (string) $value;
		} else {
			$material = (string) wp_json_encode( $value );
		}
		return '<redacted:sha256:' . substr( hash( 'sha256', $material ), 0, 12 ) . '>';
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

		// Merge any handler-side enrichment stashed via write(). Sensitive
		// paths have already been masked there if the toggle is on.
		if ( isset( self::$pending[ $route ] ) ) {
			$entry = self::$pending[ $route ];
			unset( self::$pending[ $route ] );
			if ( is_array( $entry['params'] ) ) {
				$detail['params'] = $entry['params'];
			}
			if ( null !== $entry['summary'] ) {
				$detail['summary'] = $entry['summary'];
			}
			if ( ! empty( $entry['sensitive_paths'] ) && self::is_pseudonymise_on() ) {
				$detail['pseudonymised_paths'] = $entry['sensitive_paths'];
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

	/* ----------------------------------------------------------------- */
	/* Audit-tail watcher (Phase 10.6 — fires audit.alert webhook events) */
	/* ----------------------------------------------------------------- */

	/**
	 * WP-Cron callback: scans new audit rows since the stored watermark,
	 * evaluates the active rule set on each row, and fires `audit.alert`
	 * webhook events for every rule that trips. Watermark advances to
	 * `max(id seen)` regardless of whether rules fired so we never
	 * re-evaluate the same row twice.
	 *
	 * Wrapped in a `class_exists( 'IAWM_Webhook' )` check so the audit
	 * module degrades gracefully on installs that have not run the
	 * webhook schema upgrade yet (consistent with the diagnostics
	 * smoke-failed pattern, D-030).
	 *
	 * @return array Summary of the run.
	 */
	public static function tail_watcher() {
		if ( ! class_exists( 'IAWM_Webhook' ) ) {
			return array( 'ok' => true, 'skipped' => 'webhook_module_absent' );
		}

		if ( 1 !== (int) get_option( self::OPTION_ALERT_ENABLED, 1 ) ) {
			return array( 'ok' => true, 'skipped' => 'disabled' );
		}

		$active_rules = self::active_rules();
		if ( empty( $active_rules ) ) {
			return array( 'ok' => true, 'skipped' => 'no_active_rules' );
		}

		global $wpdb;
		$table     = self::table_name();
		$watermark = (int) get_option( self::OPTION_ALERT_WATERMARK, 0 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- watcher job, no caching needed.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, created_at, method, route, status, outcome, key_id, ip, detail
				 FROM `$table`
				 WHERE id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				$watermark,
				self::ALERT_BATCH_SIZE
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array( 'ok' => true, 'evaluated' => 0, 'fired' => 0, 'watermark' => $watermark );
		}

		$fired    = 0;
		$max_id   = $watermark;
		foreach ( $rows as $row ) {
			$row_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $row_id > $max_id ) {
				$max_id = $row_id;
			}

			// Normalise the row once so each rule sees a uniform shape
			// (decoded detail, normalised outcome, integer status). The
			// helper is defensive — legacy rows pre-Phase-9.6 may have
			// a NULL `detail` column.
			$normal = self::normalise_row( $row );

			foreach ( $active_rules as $rule_id ) {
				try {
					$fired += (int) self::dispatch_rule( $rule_id, $normal );
				} catch ( Exception $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( '[IAWM] rule ' . $rule_id . ' failed: ' . $e->getMessage() );
					}
				}
			}
		}

		if ( $max_id > $watermark ) {
			update_option( self::OPTION_ALERT_WATERMARK, $max_id, false );
		}

		return array(
			'ok'        => true,
			'evaluated' => count( $rows ),
			'fired'     => $fired,
			'watermark' => $max_id,
		);
	}

	/**
	 * Decodes the active-rules CSV into a list, filtered against the
	 * known catalogue.
	 *
	 * @return string[]
	 */
	private static function active_rules() {
		$csv     = (string) get_option( self::OPTION_ALERT_RULES, self::DEFAULT_ALERT_RULES );
		$wanted  = array_filter( array_map( 'trim', explode( ',', $csv ) ) );
		$known   = array( 'scope_denied_burst', 'kill_switch_toggled', 'auth_failure_burst' );
		$out     = array();
		foreach ( $wanted as $r ) {
			if ( in_array( $r, $known, true ) ) {
				$out[] = $r;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Normalises an audit row into a uniform shape for rule evaluators.
	 * Defensive against legacy rows that may lack any detail enrichment.
	 *
	 * @param array $row Raw row (ARRAY_A) straight from wpdb.
	 * @return array { id, created_at_ts, status, outcome, error, key_id, ip, route, detail }
	 */
	private static function normalise_row( array $row ) {
		$detail = array();
		if ( isset( $row['detail'] ) && '' !== (string) $row['detail'] ) {
			$decoded = json_decode( (string) $row['detail'], true );
			if ( is_array( $decoded ) ) {
				$detail = $decoded;
			}
		}

		$created_ts = 0;
		if ( ! empty( $row['created_at'] ) ) {
			$ts = strtotime( (string) $row['created_at'] . ' UTC' );
			if ( false !== $ts ) {
				$created_ts = (int) $ts;
			}
		}

		return array(
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'created_at_ts' => $created_ts,
			'status'       => isset( $row['status'] ) ? (int) $row['status'] : 0,
			'outcome'      => isset( $row['outcome'] ) ? (string) $row['outcome'] : '',
			'error'        => isset( $detail['error'] ) ? (string) $detail['error'] : '',
			'key_id'       => isset( $row['key_id'] ) ? (string) $row['key_id'] : '',
			'ip'           => isset( $row['ip'] ) ? (string) $row['ip'] : '',
			'route'        => isset( $row['route'] ) ? (string) $row['route'] : '',
			'method'       => isset( $row['method'] ) ? (string) $row['method'] : '',
			'detail'       => $detail,
		);
	}

	/**
	 * Routes a normalised row to the matching rule evaluator.
	 *
	 * @param string $rule_id Rule identifier (`scope_denied_burst`, ...).
	 * @param array  $row     Normalised row from `normalise_row()`.
	 * @return int  1 if the rule fired, 0 otherwise.
	 */
	private static function dispatch_rule( $rule_id, array $row ) {
		switch ( $rule_id ) {
			case 'scope_denied_burst':
				return self::evaluate_scope_denied_burst( $row );
			case 'kill_switch_toggled':
				return self::evaluate_kill_switch_toggled( $row );
			case 'auth_failure_burst':
				return self::evaluate_auth_failure_burst( $row );
			default:
				return 0;
		}
	}

	/**
	 * Internal fire helper. Adds the canonical fields shared by every
	 * alert envelope (`rule`, `summary`, `trigger_audit_id`,
	 * `window_start`, `window_end`, `details`) then delegates to
	 * `IAWM_Webhook::fire( 'audit.alert', ... )`.
	 *
	 * @param string $rule_id  Rule identifier.
	 * @param string $summary  One-line human-readable summary.
	 * @param int    $trigger_id Audit row id that tripped the rule.
	 * @param int    $window_start Epoch start of the analysed window.
	 * @param int    $window_end   Epoch end of the analysed window.
	 * @param array  $details   Rule-specific extras (merged under `details`).
	 * @return int Webhook outbox inserts (0 when no hook subscribes).
	 */
	private static function fire_alert( $rule_id, $summary, $trigger_id, $window_start, $window_end, array $details = array() ) {
		if ( ! class_exists( 'IAWM_Webhook' ) ) {
			return 0;
		}
		return (int) IAWM_Webhook::fire(
			'audit.alert',
			array(
				'rule'             => (string) $rule_id,
				'summary'          => (string) $summary,
				'trigger_audit_id' => (int) $trigger_id,
				'window_start'     => (int) $window_start,
				'window_end'       => (int) $window_end,
				'details'          => $details,
			)
		);
	}

	/* ----------------------------------------------------------------- */
	/* Rule: scope_denied_burst                                            */
	/* ----------------------------------------------------------------- */

	/**
	 * Trips when at least N rows with `outcome=denied` and the
	 * `iawm_scope_denied` error code arrive from the same `key_id`
	 * within a `ALERT_BURST_WINDOW_S` sliding window.
	 *
	 * Implemented via a per-key transient counter with TTL equal to the
	 * burst window — cheap, no extra queries, automatically
	 * self-resetting.
	 *
	 * @param array $row Normalised audit row.
	 * @return int 1 on fire, 0 otherwise.
	 */
	private static function evaluate_scope_denied_burst( array $row ) {
		if ( 'iawm_scope_denied' !== $row['error'] ) {
			return 0;
		}
		$key_id = $row['key_id'];
		if ( '' === $key_id ) {
			// Scope check only runs after key resolution; defensive guard.
			return 0;
		}

		$counter_key = 'iawm_audit_alert_sd_' . md5( $key_id );
		$routes_key  = 'iawm_audit_alert_sd_r_' . md5( $key_id );
		$start_key   = 'iawm_audit_alert_sd_t_' . md5( $key_id );

		$count   = (int) get_transient( $counter_key );
		$routes  = get_transient( $routes_key );
		$start_t = (int) get_transient( $start_key );
		if ( ! is_array( $routes ) ) {
			$routes = array();
		}
		if ( $count <= 0 || $start_t <= 0 ) {
			// First hit in a new window — start the clock.
			$start_t = $row['created_at_ts'] > 0 ? $row['created_at_ts'] : time();
		}

		++$count;
		$route_family = self::route_family( $row['route'] );
		if ( '' !== $route_family && ! in_array( $route_family, $routes, true ) ) {
			$routes[] = $route_family;
		}

		// Persist for the rest of the window.
		set_transient( $counter_key, $count, self::ALERT_BURST_WINDOW_S );
		set_transient( $routes_key, $routes, self::ALERT_BURST_WINDOW_S );
		set_transient( $start_key, $start_t, self::ALERT_BURST_WINDOW_S );

		if ( $count < self::ALERT_SCOPE_DENIED_THRESHOLD ) {
			return 0;
		}

		// Threshold hit — fire once, reset to avoid alert spam.
		delete_transient( $counter_key );
		delete_transient( $routes_key );
		delete_transient( $start_key );

		$summary = sprintf(
			/* translators: 1: count threshold, 2: window in seconds, 3: key id, 4: comma-separated route families. */
			__( '%1$d+ scope_denied responses in %2$ds from key %3$s on routes %4$s', 'ia-webmaster-bridge' ),
			self::ALERT_SCOPE_DENIED_THRESHOLD,
			self::ALERT_BURST_WINDOW_S,
			$key_id,
			implode( ', ', $routes )
		);

		self::fire_alert(
			'scope_denied_burst',
			$summary,
			$row['id'],
			$start_t,
			$row['created_at_ts'] > 0 ? $row['created_at_ts'] : time(),
			array(
				'key_id'         => $key_id,
				'route_families' => array_values( $routes ),
				'threshold'      => self::ALERT_SCOPE_DENIED_THRESHOLD,
				'window_seconds' => self::ALERT_BURST_WINDOW_S,
				'count'          => $count,
			)
		);

		return 1;
	}

	/* ----------------------------------------------------------------- */
	/* Rule: kill_switch_toggled                                            */
	/* ----------------------------------------------------------------- */

	/**
	 * Fires once per kill-switch toggle. Detection works on the
	 * synthetic audit row written by `record_kill_switch_event()` (whose
	 * `detail.error` is `iawm_kill_switch_on` or `iawm_kill_switch_off`)
	 * or on a future REST-mediated toggle that records the
	 * `iawm_kill_switch` write-denial code on a `denied` row.
	 *
	 * @param array $row Normalised audit row.
	 * @return int 1 on fire, 0 otherwise.
	 */
	private static function evaluate_kill_switch_toggled( array $row ) {
		$error = $row['error'];
		$is_toggle = (
			'iawm_kill_switch_on' === $error
			|| 'iawm_kill_switch_off' === $error
		);
		if ( ! $is_toggle ) {
			return 0;
		}

		$on = ( 'iawm_kill_switch_on' === $error );

		// Recover the "by" indicator: the synthetic row stores it under
		// detail.source; otherwise fall back to key_id from the row.
		$by = '';
		if ( ! empty( $row['detail']['source'] ) ) {
			$by = (string) $row['detail']['source'];
		}
		if ( '' === $by ) {
			$by = '' !== $row['key_id'] ? $row['key_id'] : __( 'unknown', 'ia-webmaster-bridge' );
		}

		$summary = sprintf(
			/* translators: 1: "on" or "off", 2: actor (admin / key id / source). */
			__( 'Kill switch toggled to %1$s by %2$s', 'ia-webmaster-bridge' ),
			$on ? __( 'on', 'ia-webmaster-bridge' ) : __( 'off', 'ia-webmaster-bridge' ),
			$by
		);

		self::fire_alert(
			'kill_switch_toggled',
			$summary,
			$row['id'],
			$row['created_at_ts'] > 0 ? $row['created_at_ts'] : time(),
			$row['created_at_ts'] > 0 ? $row['created_at_ts'] : time(),
			array(
				'state'  => $on ? 'on' : 'off',
				'actor'  => $by,
				'key_id' => $row['key_id'],
				'source' => isset( $row['detail']['source'] ) ? (string) $row['detail']['source'] : '',
			)
		);

		return 1;
	}

	/* ----------------------------------------------------------------- */
	/* Rule: auth_failure_burst                                             */
	/* ----------------------------------------------------------------- */

	/**
	 * Trips when at least N rows with the `iawm_unauthorized` error
	 * code arrive from the same IP within a sliding window. HMAC checks
	 * happen before key resolution, so these rows never have a key_id
	 * — we key the counter by IP instead.
	 *
	 * @param array $row Normalised audit row.
	 * @return int 1 on fire, 0 otherwise.
	 */
	private static function evaluate_auth_failure_burst( array $row ) {
		if ( 'iawm_unauthorized' !== $row['error'] ) {
			return 0;
		}
		$ip = $row['ip'];
		if ( '' === $ip ) {
			$ip = '(unknown)';
		}

		$counter_key = 'iawm_audit_alert_af_' . md5( $ip );
		$start_key   = 'iawm_audit_alert_af_t_' . md5( $ip );

		$count   = (int) get_transient( $counter_key );
		$start_t = (int) get_transient( $start_key );
		if ( $count <= 0 || $start_t <= 0 ) {
			$start_t = $row['created_at_ts'] > 0 ? $row['created_at_ts'] : time();
		}
		++$count;

		set_transient( $counter_key, $count, self::ALERT_BURST_WINDOW_S );
		set_transient( $start_key, $start_t, self::ALERT_BURST_WINDOW_S );

		if ( $count < self::ALERT_AUTH_FAIL_THRESHOLD ) {
			return 0;
		}

		delete_transient( $counter_key );
		delete_transient( $start_key );

		$summary = sprintf(
			/* translators: 1: count threshold, 2: window seconds, 3: source IP. */
			__( '%1$d+ auth failures in %2$ds from IP %3$s', 'ia-webmaster-bridge' ),
			self::ALERT_AUTH_FAIL_THRESHOLD,
			self::ALERT_BURST_WINDOW_S,
			$ip
		);

		self::fire_alert(
			'auth_failure_burst',
			$summary,
			$row['id'],
			$start_t,
			$row['created_at_ts'] > 0 ? $row['created_at_ts'] : time(),
			array(
				'ip'             => $ip,
				'threshold'      => self::ALERT_AUTH_FAIL_THRESHOLD,
				'window_seconds' => self::ALERT_BURST_WINDOW_S,
				'count'          => $count,
			)
		);

		return 1;
	}

	/**
	 * Reduces a full REST route to its top-level family for grouping in
	 * the burst-rule summary. e.g.
	 *   `/ia-webmaster/v1/content/create` → `content/*`
	 *   `/ia-webmaster/v1/divi/page/write` → `divi/*`
	 *   `/ia-webmaster/v1/backup/create` → `backup/*`
	 *
	 * Out-of-namespace routes return as-is (truncated).
	 *
	 * @param string $route Full REST route.
	 * @return string
	 */
	private static function route_family( $route ) {
		$ns = '/' . IAWM_REST_NAMESPACE . '/';
		if ( 0 !== strpos( $route, $ns ) ) {
			return substr( (string) $route, 0, 60 );
		}
		$suffix = substr( $route, strlen( $ns ) );
		$first  = strpos( $suffix, '/' );
		if ( false === $first ) {
			return $suffix;
		}
		return substr( $suffix, 0, $first ) . '/*';
	}
}
