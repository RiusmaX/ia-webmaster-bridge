<?php
/**
 * Broken-links scanner.
 *
 * Walks every published post / page (or a caller-supplied subset),
 * extracts every `<a href>` from `post_content`, probes the target
 * with `wp_remote_head` (falling back to `wp_remote_get` when HEAD
 * is unsupported), and stores any non-OK outcome in the
 * `wp_iawm_link_issues` table for later review.
 *
 * Outcome classification (column `outcome`):
 *
 *   - `404`     : HTTP 404 — target gone.
 *   - `410`     : HTTP 410 — target permanently gone.
 *   - `timeout` : WP_Error code `http_request_failed` whose message
 *                 mentions a timeout (`cURL error 28` or similar).
 *   - `dns`     : WP_Error code `http_request_failed` whose message
 *                 mentions a DNS resolution failure
 *                 (`cURL error 6` / "Could not resolve host").
 *   - `ssl`     : WP_Error code `http_request_failed` whose message
 *                 mentions an SSL/TLS error (`cURL error 35/60/77`).
 *   - `other`   : every other 4xx/5xx response, or a transport error
 *                 we could not classify above.
 *
 * Redirect chains: when `wp_remote_*` follows a redirect, we record
 * the final URL in `redirect_to` only if the final status is non-OK.
 * 2xx after a redirect is considered fine and skipped.
 *
 * Throttling: between successive HTTP requests we sleep 100 ms to be
 * polite to remote hosts (especially when external links dominate).
 *
 * Dedup: within a single scan we dedup target URLs across all source
 * posts so the same URL is hit at most once. We also avoid creating
 * duplicate unresolved rows for the same `(source_post_id, target_url)`
 * pair — re-scans only insert when the previous finding has been
 * resolved (or never existed).
 *
 * Phase 9 follow-ups (intentionally out of scope here):
 *   - shortcode expansion before link extraction;
 *   - redirect-loop detection (we rely on the HTTP client's hop limit);
 *   - scan of widget / option-embedded content;
 *   - async scans for huge sites (caller must drive cron themselves).
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Broken-links scanner module.
 */
class IAWM_LinkChecker {

	/** Option key storing the installed schema version. */
	const OPTION_DB_VERSION = 'iawm_link_checker_db_version';

	/** Current schema version. */
	const DB_VERSION = 1;

	/** Option key for the last-scan summary. */
	const OPTION_LAST_SCAN = 'iawm_link_checker_last_scan';

	/** Option key for the per-link HTTP timeout (seconds). */
	const OPTION_TIMEOUT = 'iawm_link_checker_timeout';

	/** Option key for the parallel-request hint (informational). */
	const OPTION_CONCURRENCY = 'iawm_link_checker_concurrency';

	/** Option key for the per-scan hard cap on URLs probed. */
	const OPTION_MAX_LINKS = 'iawm_link_checker_max_per_scan';

	/** Default per-link timeout in seconds. */
	const DEFAULT_TIMEOUT = 5;

	/** Default soft concurrency hint (we run sequentially today). */
	const DEFAULT_CONCURRENCY = 5;

	/** Default per-scan URL hard cap. */
	const DEFAULT_MAX_LINKS = 500;

	/** Throttle between successive HTTP probes (microseconds). */
	const THROTTLE_US = 100000;

	/**
	 * Hooks up the module: schema migration + REST route registration.
	 *
	 * Note: we intentionally do NOT auto-schedule a recurring scan.
	 * On big sites a full scan can hit hundreds of remote hosts and
	 * deserves an explicit operator decision — see
	 * `docs/operations.md#broken-links` for the recommended setup
	 * (via the `scheduled-routines` skill).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'iawm_link_issues';
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
	 * Installs the link-issues table.
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
  found_at DATETIME NOT NULL,
  source_post_id BIGINT(20) UNSIGNED NOT NULL,
  source_url VARCHAR(2048) NOT NULL,
  target_url VARCHAR(2048) NOT NULL,
  status_code SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  outcome VARCHAR(20) NOT NULL DEFAULT '',
  redirect_to VARCHAR(2048) NULL DEFAULT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY found_at (found_at),
  KEY source_post_id (source_post_id),
  KEY outcome (outcome)
) $collate;";

		dbDelta( $sql );
	}

	/* ----------------------------------------------------------------- */
	/* Scan                                                                */
	/* ----------------------------------------------------------------- */

	/**
	 * Runs a full scan and records any non-OK finding.
	 *
	 * @param array $options {
	 *     Optional. Scan options.
	 *
	 *     @type int[] $post_ids          Explicit post IDs to scan. Default: every published post + page.
	 *     @type bool  $dry_run           If true, returns findings without writing to the DB. Default false.
	 *     @type bool  $include_internal  Probe internal URLs (same host as site). Default true.
	 *     @type bool  $include_external  Probe external URLs. Default true.
	 * }
	 * @return array
	 */
	public static function scan( $options = array() ) {
		$started_at = microtime( true );

		$dry_run          = ! empty( $options['dry_run'] );
		$include_internal = isset( $options['include_internal'] ) ? (bool) $options['include_internal'] : true;
		$include_external = isset( $options['include_external'] ) ? (bool) $options['include_external'] : true;
		$timeout          = (int) get_option( self::OPTION_TIMEOUT, self::DEFAULT_TIMEOUT );
		if ( $timeout <= 0 ) {
			$timeout = self::DEFAULT_TIMEOUT;
		}
		$max_links = (int) get_option( self::OPTION_MAX_LINKS, self::DEFAULT_MAX_LINKS );
		if ( $max_links <= 0 ) {
			$max_links = self::DEFAULT_MAX_LINKS;
		}

		$post_ids = isset( $options['post_ids'] ) && is_array( $options['post_ids'] )
			? array_values( array_filter( array_map( 'intval', $options['post_ids'] ) ) )
			: array();

		if ( empty( $post_ids ) ) {
			$post_ids = self::all_published_ids();
		}

		$site_host = wp_parse_url( get_home_url(), PHP_URL_HOST );
		$site_host = is_string( $site_host ) ? strtolower( $site_host ) : '';

		$cache         = array(); // target_url => probe result.
		$seen_pairs    = array(); // "post_id|target_url" => true, for in-scan dedup.
		$issues        = array();
		$scanned_links = 0;
		$issues_new    = 0;

		foreach ( $post_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$source_url = (string) get_permalink( $post );
			$links      = self::extract_links( (string) $post->post_content );

			foreach ( $links as $target_url ) {
				if ( $scanned_links >= $max_links ) {
					break 2;
				}

				$pair_key = $pid . '|' . $target_url;
				if ( isset( $seen_pairs[ $pair_key ] ) ) {
					continue;
				}
				$seen_pairs[ $pair_key ] = true;

				$is_internal = self::is_internal_url( $target_url, $site_host );
				if ( $is_internal && ! $include_internal ) {
					continue;
				}
				if ( ! $is_internal && ! $include_external ) {
					continue;
				}

				if ( ! isset( $cache[ $target_url ] ) ) {
					$cache[ $target_url ] = self::probe( $target_url, $timeout );
					++$scanned_links;
					// Polite throttle between distinct remote probes.
					if ( self::THROTTLE_US > 0 ) {
						usleep( self::THROTTLE_US );
					}
				}

				$probe = $cache[ $target_url ];
				if ( true === $probe['ok'] ) {
					continue;
				}

				$issue = array(
					'source_post_id' => $pid,
					'source_url'     => $source_url,
					'target_url'     => $target_url,
					'status_code'    => (int) $probe['status_code'],
					'outcome'        => (string) $probe['outcome'],
					'redirect_to'    => isset( $probe['redirect_to'] ) ? (string) $probe['redirect_to'] : null,
					'is_internal'    => $is_internal ? 1 : 0,
				);

				if ( ! $dry_run ) {
					$inserted = self::record_issue( $issue );
					if ( $inserted ) {
						++$issues_new;
					}
				}

				$issues[] = $issue;
			}
		}

		$summary = array(
			'ok'             => true,
			'scanned_posts'  => count( $post_ids ),
			'scanned_links'  => $scanned_links,
			'issues_found'   => count( $issues ),
			'issues_new'     => $issues_new,
			'dry_run'        => $dry_run,
			'duration_ms'    => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
			'capped_at_max'  => $scanned_links >= $max_links,
			'max_links'      => $max_links,
			'issues'         => $issues,
		);

		if ( ! $dry_run ) {
			$persistable = $summary;
			// Keep the option value small — drop the per-issue detail.
			unset( $persistable['issues'] );
			$persistable['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			update_option( self::OPTION_LAST_SCAN, $persistable, false );
		}

		return $summary;
	}

	/**
	 * Inserts a finding, unless an identical unresolved row already exists.
	 *
	 * @param array $issue Pre-validated issue payload.
	 * @return bool True if a new row was inserted.
	 */
	private static function record_issue( $issue ) {
		global $wpdb;
		$table = self::table_name();

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `$table`
				 WHERE source_post_id = %d
				   AND target_url    = %s
				   AND resolved_at IS NULL
				 LIMIT 1",
				(int) $issue['source_post_id'],
				(string) $issue['target_url']
			)
		);
		if ( $existing ) {
			return false;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'found_at'       => gmdate( 'Y-m-d H:i:s' ),
				'source_post_id' => (int) $issue['source_post_id'],
				'source_url'     => substr( (string) $issue['source_url'], 0, 2048 ),
				'target_url'     => substr( (string) $issue['target_url'], 0, 2048 ),
				'status_code'    => (int) $issue['status_code'],
				'outcome'        => substr( (string) $issue['outcome'], 0, 20 ),
				'redirect_to'    => isset( $issue['redirect_to'] ) && '' !== $issue['redirect_to']
					? substr( (string) $issue['redirect_to'], 0, 2048 )
					: null,
				'is_internal'    => ! empty( $issue['is_internal'] ) ? 1 : 0,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		return (bool) $ok;
	}

	/**
	 * Returns the IDs of every published post + page.
	 *
	 * @return int[]
	 */
	private static function all_published_ids() {
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_status = 'publish'
			   AND post_type IN ('post', 'page')
			 ORDER BY ID ASC"
		);
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Extracts the unique URL list from a chunk of post content.
	 *
	 * Strategy: parse with DOMDocument when libxml is available (handles
	 * malformed HTML quirks gracefully under `LIBXML_NOERROR`); fall
	 * back to a tolerant regex otherwise.
	 *
	 * @param string $html Raw post_content.
	 * @return string[] Absolute / scheme-relative URLs.
	 */
	private static function extract_links( $html ) {
		$urls = array();

		if ( '' === trim( $html ) ) {
			return $urls;
		}

		if ( class_exists( 'DOMDocument' ) ) {
			$prev = libxml_use_internal_errors( true );
			$dom  = new DOMDocument();
			// Wrap to force UTF-8 + a body so DOMDocument behaves on snippets.
			$wrapped = '<?xml encoding="UTF-8"?><div>' . $html . '</div>';
			$dom->loadHTML( $wrapped, LIBXML_NOERROR | LIBXML_NOWARNING );
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );

			$anchors = $dom->getElementsByTagName( 'a' );
			foreach ( $anchors as $a ) {
				$href = $a->getAttribute( 'href' );
				if ( '' === $href ) {
					continue;
				}
				$urls[] = $href;
			}
		} else {
			if ( preg_match_all( '/<a\b[^>]*?\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $matches ) ) {
				$urls = $matches[1];
			}
		}

		// Normalise + filter.
		$out  = array();
		$seen = array();
		foreach ( $urls as $u ) {
			$u = trim( html_entity_decode( $u, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' === $u ) {
				continue;
			}
			// Skip pseudo-protocols and in-page anchors.
			if ( '#' === substr( $u, 0, 1 ) ) {
				continue;
			}
			$lower = strtolower( $u );
			foreach ( array( 'mailto:', 'tel:', 'javascript:', 'data:', 'sms:' ) as $p ) {
				if ( 0 === strpos( $lower, $p ) ) {
					continue 2;
				}
			}

			// Promote site-relative URLs to absolute so we can probe them.
			if ( '/' === substr( $u, 0, 1 ) && '//' !== substr( $u, 0, 2 ) ) {
				$u = rtrim( get_home_url(), '/' ) . $u;
			}

			if ( isset( $seen[ $u ] ) ) {
				continue;
			}
			$seen[ $u ] = true;
			$out[]      = $u;
		}

		return $out;
	}

	/**
	 * Tells whether a URL points at the same host as the site.
	 *
	 * @param string $url       Absolute or scheme-relative URL.
	 * @param string $site_host Lowercase host of the current site.
	 * @return bool
	 */
	private static function is_internal_url( $url, $site_host ) {
		if ( '' === $site_host ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			// No host -> site-relative -> internal.
			return true;
		}
		return strtolower( $host ) === $site_host;
	}

	/**
	 * Probes a URL and classifies the outcome.
	 *
	 * Strategy:
	 *   1. Try `wp_remote_head` first — cheap, no body transfer.
	 *   2. If HEAD returns 405 / 501 / 403 (some servers block HEAD)
	 *      or fails at the transport layer, retry with `wp_remote_get`.
	 *   3. Treat 2xx and 3xx final responses as OK. Capture the final
	 *      URL of any redirect chain to surface in `redirect_to` when
	 *      the result ends up non-OK.
	 *
	 * @param string $url     Target URL.
	 * @param int    $timeout Per-request timeout in seconds.
	 * @return array {ok, status_code, outcome, redirect_to?}
	 */
	private static function probe( $url, $timeout ) {
		$args = array(
			'timeout'     => max( 1, (int) $timeout ),
			'redirection' => 5,
			'sslverify'   => true,
			'user-agent'  => 'IAWM-LinkChecker/1.0 (+' . get_home_url() . ')',
		);

		$resp = wp_remote_head( $url, $args );

		// Some servers refuse HEAD — retry with GET for 405/501/403/400.
		if ( ! is_wp_error( $resp ) ) {
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( in_array( $code, array( 400, 403, 405, 501 ), true ) ) {
				$resp = wp_remote_get( $url, $args );
			}
		} else {
			// Transport error on HEAD — try GET once before classifying.
			$resp = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $resp ) ) {
			return array(
				'ok'          => false,
				'status_code' => 0,
				'outcome'     => self::classify_wp_error( $resp ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$redirect_to = self::extract_final_url( $resp, $url );

		if ( $code >= 200 && $code < 400 ) {
			return array(
				'ok'          => true,
				'status_code' => $code,
				'outcome'     => '',
			);
		}

		$outcome = '';
		if ( 404 === $code ) {
			$outcome = '404';
		} elseif ( 410 === $code ) {
			$outcome = '410';
		} else {
			$outcome = 'other';
		}

		return array(
			'ok'          => false,
			'status_code' => $code,
			'outcome'     => $outcome,
			'redirect_to' => $redirect_to,
		);
	}

	/**
	 * Maps a WP_Error from `wp_remote_*` to one of our outcome buckets.
	 *
	 * `wp_remote_*` returns `WP_Error` with code `http_request_failed`
	 * in most cases — the discrimination has to happen on the human
	 * message, which embeds cURL error numbers when the Curl transport
	 * is used. We match a few well-known patterns and bucket anything
	 * else as `other`.
	 *
	 * @param WP_Error $err Transport error.
	 * @return string Outcome code.
	 */
	private static function classify_wp_error( $err ) {
		$msg = strtolower( (string) $err->get_error_message() );

		if ( false !== strpos( $msg, 'timed out' )
			|| false !== strpos( $msg, 'timeout' )
			|| false !== strpos( $msg, 'curl error 28' )
		) {
			return 'timeout';
		}
		if ( false !== strpos( $msg, 'could not resolve host' )
			|| false !== strpos( $msg, 'name or service not known' )
			|| false !== strpos( $msg, 'curl error 6' )
			|| false !== strpos( $msg, 'getaddrinfo' )
		) {
			return 'dns';
		}
		if ( false !== strpos( $msg, 'ssl' )
			|| false !== strpos( $msg, 'tls' )
			|| false !== strpos( $msg, 'certificate' )
			|| false !== strpos( $msg, 'curl error 35' )
			|| false !== strpos( $msg, 'curl error 60' )
			|| false !== strpos( $msg, 'curl error 77' )
		) {
			return 'ssl';
		}

		return 'other';
	}

	/**
	 * Returns the final URL after redirects, if any, else null.
	 *
	 * `wp_remote_*` only surfaces the redirect history when the caller
	 * requests it; the simplest portable trick is to inspect the
	 * `Location` header of the last hop if the final code is still
	 * non-OK. When we cannot derive a redirect target, return null.
	 *
	 * @param array|WP_Error $resp     Response array.
	 * @param string         $original Original URL.
	 * @return string|null
	 */
	private static function extract_final_url( $resp, $original ) {
		if ( ! is_array( $resp ) ) {
			return null;
		}
		$location = wp_remote_retrieve_header( $resp, 'location' );
		if ( is_string( $location ) && '' !== $location && $location !== $original ) {
			return $location;
		}
		return null;
	}

	/* ----------------------------------------------------------------- */
	/* Reads / writes on findings                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Lists recorded issues with optional filters.
	 *
	 * @param array $args {
	 *     Optional. Filters.
	 *
	 *     @type int    $limit            Per-page (1-200, default 50).
	 *     @type int    $offset           Offset (>=0).
	 *     @type string $outcome          Filter by outcome bucket.
	 *     @type int    $source_post_id   Filter by source post id.
	 *     @type bool   $only_unresolved  Return only unresolved rows (default true).
	 * }
	 * @return array
	 */
	public static function list_issues( $args = array() ) {
		global $wpdb;
		$table = self::table_name();

		$limit  = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 50;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['outcome'] ) && is_string( $args['outcome'] ) ) {
			$where[]  = 'outcome = %s';
			$params[] = sanitize_text_field( $args['outcome'] );
		}
		if ( isset( $args['source_post_id'] ) && (int) $args['source_post_id'] > 0 ) {
			$where[]  = 'source_post_id = %d';
			$params[] = (int) $args['source_post_id'];
		}
		$only_unresolved = ! isset( $args['only_unresolved'] ) || (bool) $args['only_unresolved'];
		if ( $only_unresolved ) {
			$where[] = 'resolved_at IS NULL';
		}

		$where_sql    = implode( ' AND ', $where );
		$where_params = $params; // snapshot of filter params (no limit/offset yet).
		$params[]     = $limit;
		$params[]     = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, found_at, source_post_id, source_url, target_url,
				        status_code, outcome, redirect_to, is_internal, resolved_at
				 FROM `$table`
				 WHERE $where_sql
				 ORDER BY id DESC
				 LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		foreach ( $rows as &$row ) {
			$row['id']             = (int) $row['id'];
			$row['source_post_id'] = (int) $row['source_post_id'];
			$row['status_code']    = (int) $row['status_code'];
			$row['is_internal']    = (bool) $row['is_internal'];
		}
		unset( $row );

		$total = (int) $wpdb->get_var(
			empty( $where_params )
				? "SELECT COUNT(*) FROM `$table` WHERE $where_sql"
				: $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE $where_sql", $where_params )
		);

		return array(
			'ok'     => true,
			'total'  => $total,
			'limit'  => $limit,
			'offset' => $offset,
			'items'  => $rows,
		);
	}

	/**
	 * Marks an issue resolved.
	 *
	 * @param int $issue_id Issue id.
	 * @return bool
	 */
	public static function mark_resolved( $issue_id ) {
		global $wpdb;
		$ok = $wpdb->update(
			self::table_name(),
			array( 'resolved_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $issue_id ),
			array( '%s' ),
			array( '%d' )
		);
		return (bool) $ok;
	}

	/**
	 * Deletes an issue row.
	 *
	 * @param int $issue_id Issue id.
	 * @return bool
	 */
	public static function delete_issue( $issue_id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table_name(),
			array( 'id' => (int) $issue_id ),
			array( '%d' )
		);
	}

	/**
	 * Deletes resolved issues older than `$keep_days` days.
	 *
	 * @param int $keep_days Retention in days.
	 * @return int Number of deleted rows.
	 */
	public static function prune_old( $keep_days = 30 ) {
		global $wpdb;
		$keep_days = max( 1, (int) $keep_days );
		$table     = self::table_name();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `$table`
				 WHERE resolved_at IS NOT NULL
				   AND resolved_at < (NOW() - INTERVAL %d DAY)",
				$keep_days
			)
		);
	}

	/* ----------------------------------------------------------------- */
	/* REST routes                                                         */
	/* ----------------------------------------------------------------- */

	/**
	 * Registers the /diagnostics/links/* routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/diagnostics/links/scan'    => array( 'handle_scan', 'guard_write' ),
			'/diagnostics/links/list'    => array( 'handle_list', 'guard_read' ),
			'/diagnostics/links/resolve' => array( 'handle_resolve', 'guard_write' ),
			'/diagnostics/links/delete'  => array( 'handle_delete', 'guard_write' ),
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
	 * POST /diagnostics/links/scan — triggers a full scan.
	 *
	 * Body (all optional):
	 *   { post_ids?: int[], dry_run?: bool, include_internal?: bool, include_external?: bool }
	 *
	 * Synchronous: large sites should drive this from a cron job
	 * scheduled via the `scheduled-routines` skill — see
	 * `docs/operations.md#broken-links`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_scan( $request ) {
		$params  = IAWM_Support::json_params( $request );
		$options = array();
		if ( isset( $params['post_ids'] ) && is_array( $params['post_ids'] ) ) {
			$options['post_ids'] = $params['post_ids'];
		}
		if ( isset( $params['dry_run'] ) ) {
			$options['dry_run'] = (bool) $params['dry_run'];
		}
		if ( isset( $params['include_internal'] ) ) {
			$options['include_internal'] = (bool) $params['include_internal'];
		}
		if ( isset( $params['include_external'] ) ) {
			$options['include_external'] = (bool) $params['include_external'];
		}

		$result = self::scan( $options );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /diagnostics/links/list — lists recorded issues.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );
		return new WP_REST_Response( self::list_issues( $params ), 200 );
	}

	/**
	 * POST /diagnostics/links/resolve — { issue_id }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_resolve( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$issue_id = isset( $params['issue_id'] ) ? (int) $params['issue_id'] : 0;
		if ( $issue_id <= 0 ) {
			return IAWM_Support::rest_error(
				'iawm_invalid_id',
				__( 'Missing or invalid issue_id.', 'ia-webmaster-bridge' ),
				400
			);
		}
		$ok = self::mark_resolved( $issue_id );
		return new WP_REST_Response(
			array( 'ok' => $ok, 'resolved' => $ok, 'issue_id' => $issue_id ),
			$ok ? 200 : 404
		);
	}

	/**
	 * POST /diagnostics/links/delete — { issue_id }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete( $request ) {
		$params   = IAWM_Support::json_params( $request );
		$issue_id = isset( $params['issue_id'] ) ? (int) $params['issue_id'] : 0;
		if ( $issue_id <= 0 ) {
			return IAWM_Support::rest_error(
				'iawm_invalid_id',
				__( 'Missing or invalid issue_id.', 'ia-webmaster-bridge' ),
				400
			);
		}
		$ok = self::delete_issue( $issue_id );
		return new WP_REST_Response(
			array( 'ok' => $ok, 'deleted' => $ok, 'issue_id' => $issue_id ),
			$ok ? 200 : 404
		);
	}
}
