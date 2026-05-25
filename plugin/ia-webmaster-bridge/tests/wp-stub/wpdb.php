<?php
/**
 * Minimal `$wpdb` stub backing the backup module's table operations.
 *
 * Implements just enough of the WordPress `wpdb` surface for the backup
 * test cases: a single in-memory table (`wp_iawm_backups`) with
 * `insert`, `update`, `delete`, `get_row`, `get_col`, `get_results`,
 * `prepare`, `query`. Other tables / queries (SHOW CREATE, SHOW TABLES
 * LIKE, the wider DB ops) are not needed by the critical-path tests.
 *
 * The `prepare()` implementation is intentionally simplistic — it
 * substitutes `%d` and `%s` placeholders for testing only and should
 * never be confused with a real SQL parameter binder. Production code
 * still runs against the real `wpdb`.
 *
 * @package IA_Webmaster_Bridge\Tests
 */

class WpdbMock {

	/**
	 * Table prefix, mirrors `$wpdb->prefix`.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Last AUTO_INCREMENT id assigned by `insert()`.
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Last MySQL error string set by query helpers.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Single in-memory table for the backups module.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $backups = array();

	/**
	 * Next id to hand out on insert.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Resets the in-memory table between tests.
	 *
	 * @return void
	 */
	public function reset() {
		$this->backups    = array();
		$this->insert_id  = 0;
		$this->next_id    = 1;
		$this->last_error = '';
	}

	/**
	 * Mirrors `$wpdb->get_charset_collate()`.
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return '';
	}

	/**
	 * Escapes a string for SQL — pass-through addslashes, sufficient for
	 * the table-dump round trip we do not actually re-execute in tests.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public function _real_escape( $value ) {
		return addslashes( (string) $value );
	}

	/**
	 * Simplistic `prepare()` substituting `%d` / `%s` placeholders.
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values to substitute, in order.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		// Allow callers to pass either a positional list of args or a
		// single array (some WP code does both — match the looser
		// behaviour for parity).
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$out = '';
		$arg_index = 0;
		$len = strlen( $query );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '%' === $query[ $i ] && $i + 1 < $len ) {
				$type = $query[ $i + 1 ];
				if ( 'd' === $type ) {
					$out .= isset( $args[ $arg_index ] ) ? (string) (int) $args[ $arg_index ] : '0';
					++$arg_index;
					++$i;
					continue;
				}
				if ( 's' === $type ) {
					$value = isset( $args[ $arg_index ] ) ? (string) $args[ $arg_index ] : '';
					$out .= "'" . addslashes( $value ) . "'";
					++$arg_index;
					++$i;
					continue;
				}
			}
			$out .= $query[ $i ];
		}
		return $out;
	}

	/**
	 * Inserts a row.
	 *
	 * @param string $table   Table name (must be `<prefix>iawm_backups`).
	 * @param array  $data    Column => value pairs.
	 * @param array  $formats Format specifiers (unused — purely informational).
	 * @return int|false Number of affected rows, or false on failure.
	 */
	public function insert( $table, $data, $formats = array() ) {
		if ( $table !== $this->prefix . 'iawm_backups' ) {
			return false;
		}
		$id           = $this->next_id++;
		$data['id']   = $id;
		$this->backups[ $id ] = $data;
		$this->insert_id      = $id;
		return 1;
	}

	/**
	 * Updates rows matching `$where` (only `id` keys are supported here).
	 *
	 * @param string $table        Table name.
	 * @param array  $data         Column => value.
	 * @param array  $where        Column => value (id only in tests).
	 * @param array  $data_formats Unused.
	 * @param array  $where_formats Unused.
	 * @return int|false Affected rows, or false on failure.
	 */
	public function update( $table, $data, $where, $data_formats = array(), $where_formats = array() ) {
		if ( $table !== $this->prefix . 'iawm_backups' ) {
			return false;
		}
		if ( ! isset( $where['id'] ) ) {
			return false;
		}
		$id = (int) $where['id'];
		if ( ! isset( $this->backups[ $id ] ) ) {
			return 0;
		}
		$this->backups[ $id ] = array_merge( $this->backups[ $id ], $data );
		return 1;
	}

	/**
	 * Deletes rows matching `$where` (id only in tests).
	 *
	 * @param string $table         Table name.
	 * @param array  $where         Column => value.
	 * @param array  $where_formats Unused.
	 * @return int|false Affected rows, or false on failure.
	 */
	public function delete( $table, $where, $where_formats = array() ) {
		if ( $table !== $this->prefix . 'iawm_backups' ) {
			return false;
		}
		if ( ! isset( $where['id'] ) ) {
			return 0;
		}
		$id = (int) $where['id'];
		if ( ! isset( $this->backups[ $id ] ) ) {
			return 0;
		}
		unset( $this->backups[ $id ] );
		return 1;
	}

	/**
	 * Returns a single row. The query is recognised by substring match.
	 *
	 * @param string $query  Prepared SQL (already substituted).
	 * @param string $output Output format (ARRAY_A, ARRAY_N, OBJECT).
	 * @return array|object|null
	 */
	public function get_row( $query, $output = ARRAY_A ) {
		if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			if ( ! isset( $this->backups[ $id ] ) ) {
				return null;
			}
			$row = $this->backups[ $id ];
			if ( ARRAY_N === $output ) {
				return array_values( $row );
			}
			return $row;
		}
		return null;
	}

	/**
	 * Returns a single column across rows. Used by `prune_old()` to
	 * fetch the ids beyond `keep`.
	 *
	 * @param string $query Prepared SQL.
	 * @return array<int, int>
	 */
	public function get_col( $query ) {
		if ( strpos( $query, 'SELECT id FROM' ) !== false ) {
			$ids = array_keys( $this->backups );
			rsort( $ids );
			$offset = 0;
			if ( preg_match( '/OFFSET (\d+)/', $query, $m ) ) {
				$offset = (int) $m[1];
			}
			$slice = array_slice( $ids, $offset );
			return array_map( 'intval', $slice );
		}
		return array();
	}

	/**
	 * Returns rows, newest first. Honours LIMIT / OFFSET when present.
	 *
	 * @param string $query  Prepared SQL.
	 * @param string $output Output format (ARRAY_A only in tests).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( $query, $output = ARRAY_A ) {
		if ( strpos( $query, 'iawm_backups' ) !== false ) {
			$rows = array_values( $this->backups );
			usort(
				$rows,
				static function ( $a, $b ) {
					return ( (int) $b['id'] ) - ( (int) $a['id'] );
				}
			);
			$limit  = PHP_INT_MAX;
			$offset = 0;
			if ( preg_match( '/LIMIT (\d+)/', $query, $m ) ) {
				$limit = (int) $m[1];
			}
			if ( preg_match( '/OFFSET (\d+)/', $query, $m ) ) {
				$offset = (int) $m[1];
			}
			return array_slice( $rows, $offset, $limit );
		}
		return array();
	}

	/**
	 * Returns a single scalar (used by SHOW TABLES LIKE — not exercised).
	 *
	 * @param string $query Query.
	 * @return string|null
	 */
	public function get_var( $query ) {
		return null;
	}

	/**
	 * Runs a generic query. The backup module uses this for DELETE IN (...)
	 * during `prune_old()` and for arbitrary SQL during table restore.
	 *
	 * @param string $query SQL.
	 * @return int|false Affected rows, or false on failure.
	 */
	public function query( $query ) {
		if ( preg_match( '/DELETE FROM `[^`]*iawm_backups` WHERE id IN \(([^)]+)\)/', $query, $m ) ) {
			$ids     = array_map( 'intval', array_map( 'trim', explode( ',', $m[1] ) ) );
			$deleted = 0;
			foreach ( $ids as $id ) {
				if ( isset( $this->backups[ $id ] ) ) {
					unset( $this->backups[ $id ] );
					++$deleted;
				}
			}
			return $deleted;
		}
		return 0;
	}

	/**
	 * Returns the full in-memory backups table (debug helper).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function dump() {
		return $this->backups;
	}
}
