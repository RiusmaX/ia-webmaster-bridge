<?php
/**
 * 404 tracker — records 404 hits actually received by visitors so the
 * operator (and the agent) can investigate broken inbound URLs.
 *
 * This is REACTIVE monitoring: it observes the URLs the outside world
 * tries to fetch and that WordPress could not resolve. Typical sources:
 *  - removed pages that search engines still have indexed,
 *  - mistyped sitemap entries shipped to crawlers,
 *  - broken backlinks from other sites pointing at us,
 *  - bots probing the install for known vulnerable paths.
 *
 * A separate broken-links scanner (planned) is the PROACTIVE counterpart:
 * it walks the site's OWN content looking for stale outbound or internal
 * links before a visitor (or a crawler) hits one. The two are complementary.
 *
 * Storage model — one row per (url, ip) burst, with hit_count incremented
 * on duplicates inside a short dedup window so a single bot retry storm
 * does not blow up the table. The class name reads "IAWM_FourOhFour"
 * because PHP class identifiers cannot start with a digit; the REST route
 * prefix is the more conventional "/diagnostics/404".
 *
 * Schema is auto-migrated through `OPTION_DB_VERSION` / `DB_VERSION` the
 * same way IAWM_Audit handles its own table.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records 404 hits and exposes them through `/diagnostics/404/*`.
 */
class IAWM_FourOhFour {

	/** Option storing the installed schema version. */
	const OPTION_DB_VERSION = 'iawm_404_db_version';

	/** Database schema version; to be incremented on every change. */
	const DB_VERSION = 1;

	/** Option storing how many days of 404 history to keep. */
	const OPTION_RETENTION_DAYS = 'iawm_404_retention_days';

	/** Default retention if the option is unset. */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Option storing the sampling rate.
	 *
	 * Convention: stored as the DENOMINATOR of a 1/N probability.
	 *   - 1   = record every 404 (the default — most sites get few enough
	 *           that full capture is cheap and the resulting data is most
	 *           actionable).
	 *   - 10  = record 1 in 10 (10 % sample).
	 *   - 100 = record 1 in 100 (1 % sample, for very high-traffic sites
	 *           where a misbehaving crawler could otherwise dwarf the
	 *           table).
	 *
	 * `1` is the intuitive default: an operator dropping the plugin onto a
	 * fresh site sees every 404 without any tuning. Knobs are only useful
	 * once a site is hot enough to need them.
	 */
	const OPTION_SAMPLING_RATE = 'iawm_404_sampling_rate';

	/** Default sampling rate (record everything). */
	const DEFAULT_SAMPLING_RATE = 1;

	/** WP-Cron hook fired daily to prune old 404 rows. */
	const PRUNE_HOOK = 'iawm_prune_404_log';

	/**
	 * Transient prefix used for the dedup window.
	 *
	 * Key shape: PREFIX . sha1( url . '|' . ip ). TTL is one minute, so a
	 * retrying crawler that hammers the same missing URL for a few seconds
	 * folds into a single row whose `hit_count` ticks up — instead of
	 * thousands of rows.
	 */
	const TRANSIENT_DEDUP_PREFIX = 'iawm_404_dedup_';

	/** Dedup window in seconds. */
	const DEDUP_TTL = 60;

	/** Path prefixes we never want to log (admin / login / cron / feeds). */
	const SKIP_PREFIXES = array(
		'/wp-admin',
		'/wp-login.php',
		'/wp-cron.php',
		'/xmlrpc.php',
	);

	/**
	 * Hooks up schema migration, the recording listener, REST routes and
	 * the daily prune.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );

		// template_redirect fires AFTER WP has resolved the request, so
		// `is_404()` is accurate. Priority 999 lets any other plugin that
		// might rescue a 404 (a redirect plugin, a custom rewriter…) run
		// first; we only log what truly fell through.
		add_action( 'template_redirect', array( __CLASS__, 'record_if_404' ), 999 );

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune_old' ) );
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			// Offset from the audit (3:00) and backup (3:15) jobs so the
			// three rotation crons don't all hit wpdb at the same minute.
			wp_schedule_event( self::next_run_at( 3, 30 ), 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Returns the configured retention in days, clamped to [1, 365].
	 *
	 * @return int
	 */
	public static function get_retention_days() {
		$days = (int) get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS );
		return max( 1, min( 365, $days ) );
	}

	/**
	 * Returns the configured sampling rate (1/N), clamped to [1, 10000].
	 *
	 * @return int
	 */
	public static function get_sampling_rate() {
		$rate = (int) get_option( self::OPTION_SAMPLING_RATE, self::DEFAULT_SAMPLING_RATE );
		return max( 1, min( 10000, $rate ) );
	}

	/**
	 * Daily cron callback: deletes entries older than the retention window.
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
	 * Returns the next site-time timestamp that matches the given hour +
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
	 * Full table name for the 404 log.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'iawm_404_log';
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
	 * Creates the 404 log table.
	 *
	 * The index on `requested_url` uses a 191-character prefix because the
	 * MySQL InnoDB index entry has a hard byte ceiling (3072 bytes total in
	 * recent MySQL, 767 historically) and `utf8mb4` columns reserve 4 bytes
	 * per character — 191 * 4 = 764 bytes is the historically safe upper
	 * bound and is still the WordPress core convention.
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
  requested_url VARCHAR(2048) NOT NULL,
  referer VARCHAR(2048) NULL,
  user_agent VARCHAR(512) NULL,
  ip VARCHAR(45) NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 1,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY requested_url (requested_url(191))
) $collate;";

		dbDelta( $sql );
	}

	/* ------------------------------------------------------------------ */
	/* Recording                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * template_redirect callback: records the current request if it is a
	 * 404 the operator would care about.
	 *
	 * Skip rules (in order — earliest match wins):
	 *  1. Not a 404 — nothing to log.
	 *  2. Request method other than GET/HEAD — POSTs / PUTs that 404 are
	 *     almost always bots probing endpoints; the URL alone is enough
	 *     signal so we still log them, but if you want to tighten this
	 *     later this is the spot.
	 *  3. URL targets wp-admin / wp-login.php / wp-cron.php / xmlrpc.php /
	 *     /feed — these are infrastructure paths and their 404s are noise
	 *     when a setting changes.
	 *  4. Sampling roll fails.
	 *
	 * Dedup model:
	 *  - Key = sha1( requested_url . '|' . ip ), prefixed by
	 *    TRANSIENT_DEDUP_PREFIX. TTL 60 s.
	 *  - On a hit (same URL + IP within 60 s): we find the most recent row
	 *    for that URL and bump `hit_count` + `last_seen`, no INSERT.
	 *  - On a miss: we set the transient, then INSERT a fresh row with
	 *    hit_count = 1.
	 *
	 * The transient is keyed on (url, ip) — not on url alone — so that
	 * three different visitors hitting the same broken URL each produce
	 * their own row (useful for distinguishing one crawler from a real
	 * traffic pattern). A single retrying crawler is what we collapse.
	 *
	 * @return void
	 */
	public static function record_if_404() {
		if ( ! is_404() ) {
			return;
		}

		$url = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $url ) {
			return;
		}

		// Path-only comparison for the skip list.
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = '';
		}
		foreach ( self::SKIP_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return;
			}
		}
		// /feed and /*/feed.
		if ( '/feed' === $path || preg_match( '#/feed/?$#', $path ) ) {
			return;
		}

		// Sampling: roll 1..N, log on 1.
		$rate = self::get_sampling_rate();
		if ( $rate > 1 && wp_rand( 1, $rate ) !== 1 ) {
			return;
		}

		$ip      = self::client_ip();
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

		// Trim to column widths defensively (long UAs and pathological URLs
		// happen — better truncate than fail the INSERT).
		$url     = substr( $url, 0, 2048 );
		$referer = substr( $referer, 0, 2048 );
		$ua      = substr( $ua, 0, 512 );

		// Dedup probe.
		$dedup_key = self::TRANSIENT_DEDUP_PREFIX . sha1( $url . '|' . $ip );
		$now_gmt   = gmdate( 'Y-m-d H:i:s' );

		if ( false !== get_transient( $dedup_key ) ) {
			self::bump_existing( $url, $now_gmt );
			return;
		}

		set_transient( $dedup_key, 1, self::DEDUP_TTL );

		global $wpdb;
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at'    => $now_gmt,
				'requested_url' => $url,
				'referer'       => '' !== $referer ? $referer : null,
				'user_agent'    => '' !== $ua ? $ua : null,
				'ip'            => '' !== $ip ? $ip : null,
				'hit_count'     => 1,
				'last_seen'     => $now_gmt,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Increments the hit counter on the most recent row for a given URL.
	 *
	 * We don't filter by IP here on purpose: once dedup has fired, the
	 * `hit_count` value represents "burst size for this URL", which is the
	 * useful signal even if a couple of different bots are converging on
	 * the same path.
	 *
	 * @param string $url     The requested URL.
	 * @param string $now_gmt GMT timestamp to write into `last_seen`.
	 * @return void
	 */
	private static function bump_existing( $url, $now_gmt ) {
		global $wpdb;
		$table = self::table_name();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM `$table` WHERE requested_url = %s ORDER BY id DESC LIMIT 1",
				$url
			)
		);

		if ( ! $id ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- counter bump, no cache.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `$table` SET hit_count = hit_count + 1, last_seen = %s WHERE id = %d",
				$now_gmt,
				$id
			)
		);
		// phpcs:enable
	}

	/**
	 * Best-effort client IP, truncated to the column width.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return substr( preg_replace( '/[^0-9a-fA-F:.]/', '', $ip ), 0, 45 );
	}

	/* ------------------------------------------------------------------ */
	/* REST routes                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Registers `/diagnostics/404/*`.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/diagnostics/404/list'   => array( 'handle_list', 'guard_read' ),
			'/diagnostics/404/stats'  => array( 'handle_stats', 'guard_read' ),
			'/diagnostics/404/delete' => array( 'handle_delete', 'guard_write' ),
			'/diagnostics/404/clear'  => array( 'handle_clear', 'guard_write' ),
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
	 * POST /diagnostics/404/list — { limit?, offset?, since? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );
		$limit  = isset( $params['limit'] ) ? max( 1, min( 100, (int) $params['limit'] ) ) : 50;
		$offset = isset( $params['offset'] ) ? max( 0, (int) $params['offset'] ) : 0;
		$since  = self::parse_since( $params['since'] ?? null );

		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `$table` WHERE created_at >= %s ORDER BY last_seen DESC LIMIT %d OFFSET %d",
				$since,
				$limit,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		foreach ( $rows as &$row ) {
			$row['id']        = (int) $row['id'];
			$row['hit_count'] = (int) $row['hit_count'];
		}
		unset( $row );

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table` WHERE created_at >= %s",
				$since
			)
		);

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'items'  => $rows,
				'limit'  => $limit,
				'offset' => $offset,
				'since'  => $since,
				'total'  => $total,
			),
			200
		);
	}

	/**
	 * POST /diagnostics/404/stats — { since? }
	 *
	 * Returns aggregate metrics: total hit count (sum of hit_count, not
	 * row count — a single noisy URL with 10000 hits in one row should
	 * outweigh ten URLs with one hit each), top URLs and top referers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_stats( $request ) {
		$params = IAWM_Support::json_params( $request );
		$since  = self::parse_since( $params['since'] ?? null );

		global $wpdb;
		$table = self::table_name();

		$total_rows = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `$table` WHERE created_at >= %s",
				$since
			)
		);
		$total_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(hit_count), 0) FROM `$table` WHERE created_at >= %s",
				$since
			)
		);

		$top_urls = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT requested_url, SUM(hit_count) AS hits, MAX(last_seen) AS last_seen
                 FROM `$table`
                 WHERE created_at >= %s
                 GROUP BY requested_url
                 ORDER BY hits DESC
                 LIMIT 10",
				$since
			),
			ARRAY_A
		);
		if ( ! is_array( $top_urls ) ) {
			$top_urls = array();
		}
		foreach ( $top_urls as &$row ) {
			$row['hits'] = (int) $row['hits'];
		}
		unset( $row );

		$top_referers = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referer, SUM(hit_count) AS hits
                 FROM `$table`
                 WHERE created_at >= %s AND referer IS NOT NULL AND referer <> ''
                 GROUP BY referer
                 ORDER BY hits DESC
                 LIMIT 5",
				$since
			),
			ARRAY_A
		);
		if ( ! is_array( $top_referers ) ) {
			$top_referers = array();
		}
		foreach ( $top_referers as &$row ) {
			$row['hits'] = (int) $row['hits'];
		}
		unset( $row );

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'since'        => $since,
				'unique_urls'  => $total_rows,
				'total_hits'   => $total_hits,
				'top_urls'     => $top_urls,
				'top_referers' => $top_referers,
			),
			200
		);
	}

	/**
	 * POST /diagnostics/404/delete — { id }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $id <= 0 ) {
			return IAWM_Support::rest_error( 'iawm_invalid_id', __( 'Missing or invalid id.', 'ia-webmaster-bridge' ), 400 );
		}

		global $wpdb;
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );

		return new WP_REST_Response(
			array(
				'ok'      => false !== $deleted && $deleted > 0,
				'deleted' => (int) $deleted,
				'id'      => $id,
			),
			false !== $deleted && $deleted > 0 ? 200 : 404
		);
	}

	/**
	 * POST /diagnostics/404/clear — { confirmation_token? }
	 *
	 * Two-step destructive operation: empties the whole table. Listed in
	 * IAWM_Confirmation::REQUIRES_CONFIRMATION so the first call returns a
	 * token + summary, and the second (with the token) actually wipes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_clear( $request ) {
		$params = IAWM_Support::json_params( $request );

		global $wpdb;
		$table = self::table_name();

		// Show the caller what they are about to lose.
		$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );

		$confirm = IAWM_Confirmation::guard(
			$request,
			$params,
			array(
				'rows_to_delete' => $row_count,
				'effect'         => 'Truncates the iawm_404_log table. Historical 404 data is lost.',
			)
		);
		if ( null !== $confirm ) {
			return $confirm;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- explicit destructive op.
		$wpdb->query( "DELETE FROM `$table`" );
		// phpcs:enable

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'cleared' => true,
				'deleted' => $row_count,
			),
			200
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Normalises a caller-supplied `since` value into a GMT SQL timestamp.
	 *
	 * Accepts:
	 *   - null / empty / unparseable → defaults to now - 7 days.
	 *   - any string strtotime() understands (ISO 8601 recommended).
	 *
	 * @param mixed $value Raw value from the request body.
	 * @return string GMT timestamp suitable for a `created_at` comparison.
	 */
	private static function parse_since( $value ) {
		if ( is_string( $value ) && '' !== $value ) {
			$ts = strtotime( $value );
			if ( false !== $ts ) {
				return gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		return gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
	}
}
