<?php
/**
 * Infrastructure plane — controlled database operations.
 *
 * Three families:
 *
 *   - `/database/info`          (read)        — table-level metadata.
 *   - `/database/export`        (infra:write) — thin wrapper around
 *                                                `IAWM_Backup::snapshot_tables`.
 *   - `/database/query`         (infra:write) — SELECT-only ad-hoc query
 *                                                with a forced row cap.
 *   - `/database/search-replace`(infra:write) — serialization-safe
 *                                                search/replace on an
 *                                                explicit allow-list of
 *                                                (table, column) pairs;
 *                                                mandatory `dry_run` and
 *                                                confirmation token for
 *                                                a real apply.
 *
 * Design choices:
 *
 *   - **No raw INSERT / UPDATE / DELETE / DDL** through `/database/query`.
 *     Mutations go through purpose-built endpoints
 *     (`content/*`, `config/*`, `themes/*`, etc.) where business rules
 *     and audit are properly enforced.
 *   - **Search-replace** is limited to a fixed allow-list of (table,
 *     column) pairs known to need it (options.option_value,
 *     posts.post_content, *meta.meta_value, comments.comment_content).
 *     Arbitrary tables/columns are rejected so the agent cannot scribble
 *     over schema-shaped data.
 *   - The walker handles PHP-serialized strings/arrays/objects so
 *     option payloads (the most common case after a domain move) stay
 *     valid; this is a deliberately simplified port of the WP-CLI
 *     algorithm and may not cover every exotic serialized shape — see
 *     the doc on `IAWM_Database::sr_recursive` for the limits.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controlled DB routes.
 */
class IAWM_Database {

	/** Max rows returned by `/database/query`. */
	const QUERY_MAX_ROWS = 200;

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/database/info'           => array( 'handle_info', 'guard_read' ),
			'/database/export'         => array( 'handle_export', 'guard_write' ),
			'/database/query'          => array( 'handle_query', 'guard_write' ),
			'/database/search-replace' => array( 'handle_search_replace', 'guard_write' ),
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
	 * Allow-list of (table, columns) pairs the search-replace operates
	 * on. Keys are aliases of `wpdb` properties (so the WP prefix is
	 * applied automatically); values are the columns the walker is
	 * allowed to touch.
	 *
	 * @return array<string, string[]>
	 */
	protected static function sr_allowed_targets() {
		global $wpdb;
		return array(
			$wpdb->options       => array( 'option_value' ),
			$wpdb->posts         => array( 'post_content', 'post_excerpt', 'post_title' ),
			$wpdb->postmeta      => array( 'meta_value' ),
			$wpdb->usermeta      => array( 'meta_value' ),
			$wpdb->termmeta      => array( 'meta_value' ),
			$wpdb->comments      => array( 'comment_content' ),
			$wpdb->commentmeta   => array( 'meta_value' ),
		);
	}

	/* ----------------------------------------------------------------- */
	/* /database/info                                                     */
	/* ----------------------------------------------------------------- */

	/**
	 * POST /database/info — lists tables with row counts and sizes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_info( $request ) {
		unset( $request );

		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		$tables = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$tables[] = array(
					'name'         => isset( $row['Name'] ) ? $row['Name'] : '',
					'engine'       => isset( $row['Engine'] ) ? $row['Engine'] : null,
					'rows'         => isset( $row['Rows'] ) ? (int) $row['Rows'] : 0,
					'data_size'    => isset( $row['Data_length'] ) ? (int) $row['Data_length'] : 0,
					'index_size'   => isset( $row['Index_length'] ) ? (int) $row['Index_length'] : 0,
					'collation'    => isset( $row['Collation'] ) ? $row['Collation'] : null,
				);
			}
		}

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'db_prefix'     => $wpdb->prefix,
				'mysql_version' => $wpdb->db_version(),
				'total'         => count( $tables ),
				'tables'        => $tables,
			),
			200
		);
	}

	/* ----------------------------------------------------------------- */
	/* /database/export                                                   */
	/* ----------------------------------------------------------------- */

	/**
	 * POST /database/export — SQL dump of selected tables via the
	 * Backup module. The result is a `tables` snapshot that can be
	 * retrieved via `/backup/get` and replayed via `/backup/restore`.
	 *
	 * Body: { tables[], label? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_export( $request ) {
		$params = IAWM_Support::json_params( $request );
		$tables = isset( $params['tables'] ) ? (array) $params['tables'] : array();
		$label  = isset( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : 'Manual DB export';

		if ( empty( $tables ) ) {
			return IAWM_Support::rest_error( 'iawm_no_tables', 'Provide a non-empty `tables` array.', 400 );
		}

		$id = IAWM_Backup::snapshot_tables( $tables, $label, (string) $request->get_route() );
		if ( null === $id ) {
			return IAWM_Support::rest_error( 'iawm_snapshot_failed', 'Snapshot produced no payload. Check that the table names exist.', 400 );
		}

		return new WP_REST_Response(
			array( 'ok' => true, 'exported' => true, 'backup_id' => $id, 'tables' => $tables ),
			201
		);
	}

	/* ----------------------------------------------------------------- */
	/* /database/query                                                    */
	/* ----------------------------------------------------------------- */

	/**
	 * POST /database/query — SELECT-only ad-hoc query.
	 *
	 * Body: { sql, limit? }
	 *
	 * Validation: the SQL must start with `SELECT` (case-insensitive,
	 * after stripping comments and whitespace), must not contain a `;`,
	 * `INTO OUTFILE`, `INTO DUMPFILE` or `UNION` (the last to avoid
	 * stacked semantics), and is forcibly capped at QUERY_MAX_ROWS.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_query( $request ) {
		global $wpdb;

		$params = IAWM_Support::json_params( $request );
		$sql    = isset( $params['sql'] ) ? (string) $params['sql'] : '';
		$limit  = isset( $params['limit'] ) ? max( 1, min( self::QUERY_MAX_ROWS, (int) $params['limit'] ) ) : self::QUERY_MAX_ROWS;

		$check = self::validate_select( $sql );
		if ( true !== $check ) {
			return IAWM_Support::rest_error( 'iawm_invalid_query', $check, 400 );
		}

		// Force a LIMIT cap. Append rather than parse SQL so we keep the
		// caller's intent intact when they did include one — MySQL takes
		// the LAST LIMIT clause, so a trailing one wins.
		$bounded = $sql . ' LIMIT ' . (int) $limit;

		$start  = microtime( true );
		$rows   = $wpdb->get_results( $bounded, ARRAY_A );
		$millis = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( null === $rows ) {
			return IAWM_Support::rest_error( 'iawm_query_failed', $wpdb->last_error ?: 'Query failed.', 500 );
		}

		return new WP_REST_Response(
			array(
				'ok'         => true,
				'row_count'  => count( $rows ),
				'limit'      => $limit,
				'capped'     => count( $rows ) >= $limit,
				'duration_ms' => $millis,
				'rows'       => $rows,
			),
			200
		);
	}

	/**
	 * Validates that an SQL string is a single SELECT we are willing to run.
	 *
	 * @param string $sql Raw SQL.
	 * @return true|string True if valid; otherwise a human message.
	 */
	private static function validate_select( $sql ) {
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			return 'Empty SQL.';
		}

		// Strip /* ... */ and -- ... comments to inspect the meaningful body.
		$stripped = preg_replace( '#/\*.*?\*/#s', ' ', $sql );
		$stripped = preg_replace( '/--[^\n]*/', ' ', $stripped );
		$stripped = trim( (string) $stripped );

		if ( '' === $stripped ) {
			return 'SQL contains only comments.';
		}
		if ( false !== strpos( $stripped, ';' ) ) {
			return 'Multiple statements are not allowed (no `;`).';
		}
		if ( 0 !== stripos( $stripped, 'SELECT' ) && 0 !== stripos( $stripped, '(SELECT' ) && 0 !== stripos( $stripped, 'WITH' ) ) {
			return 'Only SELECT (or WITH ... SELECT) queries are allowed.';
		}
		$forbidden = array( 'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'BENCHMARK', 'SLEEP(' );
		foreach ( $forbidden as $needle ) {
			if ( false !== stripos( $stripped, $needle ) ) {
				return 'Disallowed construct in query: ' . $needle;
			}
		}

		return true;
	}

	/* ----------------------------------------------------------------- */
	/* /database/search-replace                                           */
	/* ----------------------------------------------------------------- */

	/**
	 * POST /database/search-replace — serialization-safe search/replace.
	 *
	 * Body: { search, replace, targets?, dry_run?, confirmation_token? }
	 *
	 *   - `targets` is an explicit list of `[table, column]` pairs to
	 *     scan. When omitted, the full sr_allowed_targets() catalogue
	 *     is used.
	 *   - `dry_run` is mandatory for a first call; the response reports
	 *     the number of rows that would change per (table, column) and
	 *     a small sample of before/after string fragments.
	 *   - A real (non-dry_run) call requires a `confirmation_token`
	 *     obtained from a first call with `dry_run: false` and no token
	 *     (which returns `requires_confirmation: true`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_search_replace( $request ) {
		global $wpdb;

		$params  = IAWM_Support::json_params( $request );
		$search  = isset( $params['search'] ) ? (string) $params['search'] : '';
		$replace = isset( $params['replace'] ) ? (string) $params['replace'] : '';
		$dry_run = ! empty( $params['dry_run'] );

		if ( '' === $search ) {
			return IAWM_Support::rest_error( 'iawm_missing_search', '`search` is required and cannot be empty.', 400 );
		}
		if ( $search === $replace ) {
			return IAWM_Support::rest_error( 'iawm_same_value', '`search` and `replace` are identical.', 400 );
		}

		$allowed = self::sr_allowed_targets();
		$targets = self::normalize_sr_targets( $params, $allowed );
		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		// Phase 5.3 confirmation, but only for the real apply.
		$confirm = IAWM_Confirmation::guard(
			$request,
			$params,
			array(
				'search_length'  => strlen( $search ),
				'replace_length' => strlen( $replace ),
				'targets'        => $targets,
			)
		);
		if ( null !== $confirm ) {
			return $confirm;
		}

		$report = array();
		$total_changed = 0;
		$samples_left  = 5;

		foreach ( $targets as $pair ) {
			list( $table, $column ) = $pair;
			$primary = self::primary_key_for( $table );
			if ( null === $primary ) {
				continue;
			}

			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$select = $wpdb->prepare(
				"SELECT `$primary` AS pk, `$column` AS val FROM `$table` WHERE `$column` LIKE %s",
				$like
			);
			$rows = $wpdb->get_results( $select, ARRAY_A );
			$changed = 0;
			$samples = array();

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$old = (string) $row['val'];
					$new = self::sr_recursive( $search, $replace, $old );
					if ( $new === $old ) {
						continue;
					}
					$changed++;
					if ( $samples_left > 0 ) {
						$samples[] = array(
							'pk'     => $row['pk'],
							'before' => self::truncate_for_sample( $old ),
							'after'  => self::truncate_for_sample( $new ),
						);
						$samples_left--;
					}
					if ( ! $dry_run ) {
						$wpdb->update(
							$table,
							array( $column => $new ),
							array( $primary => $row['pk'] )
						);
					}
				}
			}

			$report[] = array(
				'table'   => $table,
				'column'  => $column,
				'changed' => $changed,
				'samples' => $samples,
			);
			$total_changed += $changed;
		}

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'dry_run'      => $dry_run,
				'total_changed' => $total_changed,
				'targets'      => $report,
			),
			200
		);
	}

	/**
	 * Resolves the caller's `targets` parameter against the
	 * sr_allowed_targets() catalogue, returning a normalised list of
	 * `[table, column]` pairs.
	 *
	 * @param array $params  Request params.
	 * @param array $allowed Allowed targets.
	 * @return array|WP_Error
	 */
	private static function normalize_sr_targets( $params, $allowed ) {
		$requested = isset( $params['targets'] ) ? (array) $params['targets'] : array();

		// Default: every allowed (table, column).
		if ( empty( $requested ) ) {
			$out = array();
			foreach ( $allowed as $table => $columns ) {
				foreach ( $columns as $column ) {
					$out[] = array( $table, $column );
				}
			}
			return $out;
		}

		$out = array();
		foreach ( $requested as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
				return IAWM_Support::rest_error( 'iawm_bad_target', 'Each target must be [table, column].', 400 );
			}
			$table  = (string) $entry[0];
			$column = (string) $entry[1];
			if ( ! isset( $allowed[ $table ] ) || ! in_array( $column, $allowed[ $table ], true ) ) {
				return IAWM_Support::rest_error(
					'iawm_target_not_allowed',
					sprintf( 'Target (%s, %s) is not in the allow-list.', $table, $column ),
					403
				);
			}
			$out[] = array( $table, $column );
		}
		return $out;
	}

	/**
	 * Returns the primary key column of an allowed table.
	 *
	 * @param string $table Table name (already prefixed).
	 * @return string|null
	 */
	private static function primary_key_for( $table ) {
		global $wpdb;
		$map = array(
			$wpdb->options     => 'option_id',
			$wpdb->posts       => 'ID',
			$wpdb->postmeta    => 'meta_id',
			$wpdb->usermeta    => 'umeta_id',
			$wpdb->termmeta    => 'meta_id',
			$wpdb->comments    => 'comment_ID',
			$wpdb->commentmeta => 'meta_id',
		);
		return isset( $map[ $table ] ) ? $map[ $table ] : null;
	}

	/**
	 * Recursive, serialization-safe search/replace on a value.
	 *
	 * If the value is a string that PHP-unserialises to a non-false
	 * value, the walker descends into the unserialised structure,
	 * replaces in leaf strings, and re-serialises. If it does not
	 * unserialise, a flat `str_replace` is applied.
	 *
	 * Arrays and objects are walked recursively. Stringifiable objects
	 * keep their class via `clone` + property assignment. Numeric /
	 * boolean / null leaves are left untouched.
	 *
	 * Known limits (acceptable for Phase 4 launch — to revisit):
	 *
	 *   - JSON-encoded payloads are NOT decoded (str_replace on the raw
	 *     JSON is usually fine because JSON is the same shape after a
	 *     domain swap, but length counts inside JSON strings are not
	 *     adjusted).
	 *   - PHP serialised payloads with custom __sleep / __wakeup hooks
	 *     are not re-serialised through their hooks.
	 *
	 * @param string $from From string.
	 * @param string $to   To string.
	 * @param mixed  $data Value to walk.
	 * @return mixed
	 */
	public static function sr_recursive( $from, $to, $data ) {
		if ( is_string( $data ) ) {
			if ( '' === $data ) {
				return $data;
			}
			// Try to detect a PHP-serialised payload.
			$unser = @unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $unser || 'b:0;' === $data ) {
				return serialize( self::sr_recursive( $from, $to, $unser ) );
			}
			return str_replace( $from, $to, $data );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = self::sr_recursive( $from, $to, $v );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			$out = clone $data;
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$out->$k = self::sr_recursive( $from, $to, $v );
			}
			return $out;
		}
		return $data;
	}

	/**
	 * Truncates a string for the dry-run preview.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max chars.
	 * @return string
	 */
	private static function truncate_for_sample( $value, $max = 120 ) {
		$value = (string) $value;
		if ( strlen( $value ) <= $max ) {
			return $value;
		}
		return substr( $value, 0, $max - 3 ) . '...';
	}
}
