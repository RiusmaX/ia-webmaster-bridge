<?php
/**
 * Outbound signed webhook notifications.
 *
 * Phase 9.4 of the operations plan: the plugin posts signed JSON
 * notifications to external receivers (Slack incoming webhooks, generic
 * JSON endpoints, internal monitoring relays, …) when interesting events
 * happen (smoke-test failure, audit alert, key-rotation reminder).
 *
 * The outbound signing scheme mirrors the inbound HMAC auth model so
 * receivers can verify authenticity:
 *
 *   - `Content-Type: application/json`
 *   - `X-IAWM-Webhook-Timestamp`: unix epoch seconds
 *   - `X-IAWM-Webhook-Nonce`     : 16 random hex bytes (32 chars)
 *   - `X-IAWM-Webhook-Signature` : `sha256=` + hex HMAC-SHA256 of
 *                                  `timestamp + "\n" + nonce + "\n" + body`
 *                                  using the webhook's `signing_secret`.
 *
 * Receivers verify by:
 *   1. checking the timestamp is within ±5 minutes of "now";
 *   2. recomputing the HMAC and comparing constant-time
 *      (`hash_equals` in PHP, `crypto.timingSafeEqual` in Node).
 *
 * Two tables — both per-site (multisite-tolerant per D-027):
 *
 *   - `wp_iawm_webhooks`        : configured webhooks (label, URL, secret,
 *                                  subscribed event list, enabled flag).
 *   - `wp_iawm_webhook_outbox`  : queue of pending / failed / dead deliveries.
 *
 * Delivery happens out-of-band: `IAWM_Webhook::fire()` is a cheap insert
 * into the outbox. A WP-Cron job (`iawm_webhook_drain`) running every
 * 5 minutes drains the outbox with retry + exponential backoff (1m, 5m,
 * 30m); after 3 failed attempts a row is dead-lettered.
 *
 * Out of scope for v1.3.0 (see D-030):
 *   - admin tab UI (a future v1.4.x can add it on top of the endpoints);
 *   - encryption-at-rest of `signing_secret`;
 *   - audit-alert event firing (planned for v1.4 with an audit-tail
 *     watcher). Today only `smoke.failed` is wired up natively.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound signed webhook delivery.
 */
class IAWM_Webhook {

	/** Option storing the installed schema version. */
	const OPTION_DB_VERSION = 'iawm_webhook_db_version';

	/** Current schema version. */
	const DB_VERSION = 1;

	/** Cron hook name fired every 5 minutes to drain the outbox. */
	const DRAIN_HOOK = 'iawm_webhook_drain';

	/** Custom cron schedule key (5-minute recurrence). */
	const SCHEDULE_5MIN = 'iawm_5min';

	/** Per-request HTTP timeout in seconds. */
	const HTTP_TIMEOUT = 10;

	/** Maximum number of delivery attempts before dead-lettering. */
	const MAX_ATTEMPTS = 3;

	/** Signing scheme prefix used in the signature header value. */
	const SIGNATURE_PREFIX = 'sha256=';

	/**
	 * Hooks up the module:
	 *   - schema migration on plugins_loaded,
	 *   - REST routes,
	 *   - custom 5-minute cron schedule + drain job.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Register the custom 5-minute schedule (idempotent under WP-Cron).
		add_filter( 'cron_schedules', array( __CLASS__, 'register_schedule' ) );

		add_action( self::DRAIN_HOOK, array( __CLASS__, 'cron_drain' ) );
		if ( ! wp_next_scheduled( self::DRAIN_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE_5MIN, self::DRAIN_HOOK );
		}
	}

	/**
	 * Registers the custom 5-minute schedule used by the drain job.
	 *
	 * Other modules can re-use `iawm_5min` once this filter has run; the
	 * key is namespaced to avoid clashing with other plugins.
	 *
	 * @param array $schedules WP-Cron schedules registry.
	 * @return array
	 */
	public static function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_5MIN ] ) ) {
			$schedules[ self::SCHEDULE_5MIN ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (IAWM)', 'ia-webmaster-bridge' ),
			);
		}
		return $schedules;
	}

	/**
	 * Full table name for the configured webhooks list.
	 *
	 * @return string
	 */
	public static function table_webhooks() {
		global $wpdb;
		return $wpdb->prefix . 'iawm_webhooks';
	}

	/**
	 * Full table name for the outbox / delivery queue.
	 *
	 * @return string
	 */
	public static function table_outbox() {
		global $wpdb;
		return $wpdb->prefix . 'iawm_webhook_outbox';
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
	 * Installs the two webhook tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$webhooks = self::table_webhooks();
		$outbox   = self::table_outbox();
		$collate  = $wpdb->get_charset_collate();

		$sql_webhooks = "CREATE TABLE $webhooks (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  label VARCHAR(191) NOT NULL DEFAULT '',
  destination_url VARCHAR(2048) NOT NULL,
  signing_secret VARCHAR(255) NOT NULL DEFAULT '',
  events VARCHAR(1024) NOT NULL DEFAULT '',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY enabled (enabled)
) $collate;";

		$sql_outbox = "CREATE TABLE $outbox (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  webhook_id BIGINT(20) UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL DEFAULT '',
  payload LONGTEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  attempts INT(11) NOT NULL DEFAULT 0,
  last_attempt_at DATETIME NULL DEFAULT NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY webhook_id (webhook_id),
  KEY status (status),
  KEY created_at (created_at)
) $collate;";

		dbDelta( $sql_webhooks );
		dbDelta( $sql_outbox );
	}

	/* ----------------------------------------------------------------- */
	/* Public API — fire an event                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Enqueues an event for delivery to every enabled webhook subscribed
	 * to `$event_type`.
	 *
	 * Cheap and synchronous-safe: only DB inserts. Network I/O happens in
	 * the cron drainer. Callers can fire-and-forget.
	 *
	 * @param string $event_type Dotted-namespace event name (e.g. "smoke.failed").
	 * @param array  $payload    Arbitrary JSON-serialisable payload.
	 * @return int Number of outbox rows inserted.
	 */
	public static function fire( $event_type, array $payload = array() ) {
		global $wpdb;

		$event_type = sanitize_text_field( (string) $event_type );
		if ( '' === $event_type ) {
			return 0;
		}

		$hooks = self::list_subscribed( $event_type );
		if ( empty( $hooks ) ) {
			return 0;
		}

		$envelope = wp_json_encode(
			array(
				'event'     => $event_type,
				'site_url'  => get_home_url(),
				'fired_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'payload'   => $payload,
			)
		);
		if ( false === $envelope ) {
			return 0;
		}

		$inserted = 0;
		$outbox   = self::table_outbox();
		foreach ( $hooks as $hook ) {
			$ok = $wpdb->insert(
				$outbox,
				array(
					'webhook_id'      => (int) $hook['id'],
					'event_type'      => substr( $event_type, 0, 64 ),
					'payload'         => (string) $envelope,
					'status'          => 'pending',
					'attempts'        => 0,
					'last_attempt_at' => null,
					'last_error'      => null,
					'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
			);
			if ( $ok ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Returns the list of enabled webhooks subscribed to `$event_type`.
	 *
	 * A subscription with the literal `*` matches every event.
	 *
	 * @param string $event_type Event name.
	 * @return array<int, array>
	 */
	private static function list_subscribed( $event_type ) {
		global $wpdb;
		$table = self::table_webhooks();

		$rows = $wpdb->get_results(
			"SELECT id, events FROM `$table` WHERE enabled = 1",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$events = self::parse_events( (string) $row['events'] );
			if ( in_array( '*', $events, true ) || in_array( $event_type, $events, true ) ) {
				$out[] = array( 'id' => (int) $row['id'] );
			}
		}
		return $out;
	}

	/**
	 * Splits the events CSV stored in DB into a normalised array.
	 *
	 * @param string $csv Stored events string.
	 * @return string[]
	 */
	private static function parse_events( $csv ) {
		$parts = array_map( 'trim', explode( ',', $csv ) );
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			$out[] = $p;
		}
		return $out;
	}

	/**
	 * Normalises an events array or CSV into a CSV ready for DB storage.
	 *
	 * @param mixed $events Array of strings or comma-separated string.
	 * @return string
	 */
	private static function normalise_events( $events ) {
		if ( is_string( $events ) ) {
			$events = explode( ',', $events );
		}
		if ( ! is_array( $events ) ) {
			return '';
		}
		$clean = array();
		foreach ( $events as $e ) {
			$e = trim( (string) $e );
			if ( '' === $e ) {
				continue;
			}
			$clean[] = $e;
		}
		$clean = array_values( array_unique( $clean ) );
		return substr( implode( ',', $clean ), 0, 1024 );
	}

	/* ----------------------------------------------------------------- */
	/* CRUD                                                                */
	/* ----------------------------------------------------------------- */

	/**
	 * Lists configured webhooks (newest first).
	 *
	 * The `signing_secret` is REDACTED in the returned rows — callers
	 * never need to read the secret back. To rotate, issue an update
	 * with a fresh secret.
	 *
	 * @return array<int, array>
	 */
	public static function list_webhooks() {
		global $wpdb;
		$table = self::table_webhooks();
		$rows  = $wpdb->get_results(
			"SELECT id, label, destination_url, events, enabled, created_at, updated_at
			 FROM `$table` ORDER BY id DESC",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		foreach ( $rows as &$row ) {
			$row['id']      = (int) $row['id'];
			$row['enabled'] = (bool) $row['enabled'];
			$row['events']  = self::parse_events( (string) $row['events'] );
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Fetches a single webhook record (including the signing secret).
	 *
	 * Internal — never surfaced through a REST handler.
	 *
	 * @param int $id Webhook id.
	 * @return array|null
	 */
	private static function get_webhook( $id ) {
		global $wpdb;
		$table = self::table_webhooks();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", (int) $id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['id']      = (int) $row['id'];
		$row['enabled'] = (bool) $row['enabled'];
		return $row;
	}

	/**
	 * Creates a new webhook record.
	 *
	 * @param array $args { label, destination_url, signing_secret, events, enabled? }
	 * @return array|WP_Error Created record (without the secret), or WP_Error.
	 */
	public static function create( array $args ) {
		global $wpdb;

		$label  = isset( $args['label'] ) ? sanitize_text_field( (string) $args['label'] ) : '';
		$url    = isset( $args['destination_url'] ) ? esc_url_raw( (string) $args['destination_url'] ) : '';
		$secret = isset( $args['signing_secret'] ) ? (string) $args['signing_secret'] : '';
		$events = isset( $args['events'] ) ? self::normalise_events( $args['events'] ) : '';
		$enabled = ! isset( $args['enabled'] ) ? 1 : ( (bool) $args['enabled'] ? 1 : 0 );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'iawm_webhook_invalid_url',
				__( 'destination_url must be an absolute http(s) URL.', 'ia-webmaster-bridge' ),
				array( 'status' => 400 )
			);
		}
		if ( strlen( $secret ) < 16 ) {
			return new WP_Error(
				'iawm_webhook_weak_secret',
				__( 'signing_secret must be at least 16 characters long.', 'ia-webmaster-bridge' ),
				array( 'status' => 400 )
			);
		}
		if ( '' === $events ) {
			return new WP_Error(
				'iawm_webhook_no_events',
				__( 'At least one event subscription is required (or "*" for all).', 'ia-webmaster-bridge' ),
				array( 'status' => 400 )
			);
		}
		if ( '' === $label ) {
			$label = wp_parse_url( $url, PHP_URL_HOST );
			$label = is_string( $label ) ? $label : __( 'Webhook', 'ia-webmaster-bridge' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			self::table_webhooks(),
			array(
				'label'           => substr( $label, 0, 191 ),
				'destination_url' => substr( $url, 0, 2048 ),
				'signing_secret'  => substr( $secret, 0, 255 ),
				'events'          => $events,
				'enabled'         => $enabled,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'iawm_webhook_insert_failed', __( 'Could not store the webhook.', 'ia-webmaster-bridge' ), array( 'status' => 500 ) );
		}

		$id  = (int) $wpdb->insert_id;
		$row = self::get_webhook( $id );
		if ( null === $row ) {
			return new WP_Error( 'iawm_webhook_not_found', __( 'Webhook just inserted is not retrievable.', 'ia-webmaster-bridge' ), array( 'status' => 500 ) );
		}
		unset( $row['signing_secret'] );
		$row['events'] = self::parse_events( (string) $row['events'] );

		return $row;
	}

	/**
	 * Updates a webhook record.
	 *
	 * @param int   $id   Webhook id.
	 * @param array $args Partial update: label, destination_url, signing_secret, events, enabled.
	 * @return array|WP_Error Updated record (without the secret), or WP_Error.
	 */
	public static function update( $id, array $args ) {
		global $wpdb;

		$id   = (int) $id;
		$row  = self::get_webhook( $id );
		if ( null === $row ) {
			return new WP_Error( 'iawm_webhook_not_found', __( 'Webhook not found.', 'ia-webmaster-bridge' ), array( 'status' => 404 ) );
		}

		$updates = array();
		$formats = array();

		if ( isset( $args['label'] ) ) {
			$updates['label'] = substr( sanitize_text_field( (string) $args['label'] ), 0, 191 );
			$formats[]        = '%s';
		}
		if ( isset( $args['destination_url'] ) ) {
			$url = esc_url_raw( (string) $args['destination_url'] );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				return new WP_Error(
					'iawm_webhook_invalid_url',
					__( 'destination_url must be an absolute http(s) URL.', 'ia-webmaster-bridge' ),
					array( 'status' => 400 )
				);
			}
			$updates['destination_url'] = substr( $url, 0, 2048 );
			$formats[]                  = '%s';
		}
		if ( isset( $args['signing_secret'] ) ) {
			$secret = (string) $args['signing_secret'];
			if ( strlen( $secret ) < 16 ) {
				return new WP_Error(
					'iawm_webhook_weak_secret',
					__( 'signing_secret must be at least 16 characters long.', 'ia-webmaster-bridge' ),
					array( 'status' => 400 )
				);
			}
			$updates['signing_secret'] = substr( $secret, 0, 255 );
			$formats[]                 = '%s';
		}
		if ( isset( $args['events'] ) ) {
			$events = self::normalise_events( $args['events'] );
			if ( '' === $events ) {
				return new WP_Error(
					'iawm_webhook_no_events',
					__( 'At least one event subscription is required (or "*" for all).', 'ia-webmaster-bridge' ),
					array( 'status' => 400 )
				);
			}
			$updates['events'] = $events;
			$formats[]         = '%s';
		}
		if ( isset( $args['enabled'] ) ) {
			$updates['enabled'] = (bool) $args['enabled'] ? 1 : 0;
			$formats[]          = '%d';
		}

		if ( empty( $updates ) ) {
			// Nothing to change — return the current snapshot unchanged.
			unset( $row['signing_secret'] );
			$row['events'] = self::parse_events( (string) $row['events'] );
			return $row;
		}

		$updates['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$formats[]             = '%s';

		$ok = $wpdb->update(
			self::table_webhooks(),
			$updates,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'iawm_webhook_update_failed', __( 'Could not update the webhook.', 'ia-webmaster-bridge' ), array( 'status' => 500 ) );
		}

		$fresh = self::get_webhook( $id );
		if ( null === $fresh ) {
			return new WP_Error( 'iawm_webhook_not_found', __( 'Webhook not found.', 'ia-webmaster-bridge' ), array( 'status' => 404 ) );
		}
		unset( $fresh['signing_secret'] );
		$fresh['events'] = self::parse_events( (string) $fresh['events'] );
		return $fresh;
	}

	/**
	 * Deletes a webhook record (and any pending outbox rows tied to it).
	 *
	 * @param int $id Webhook id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		$wpdb->delete( self::table_outbox(), array( 'webhook_id' => $id ), array( '%d' ) );
		return (bool) $wpdb->delete( self::table_webhooks(), array( 'id' => $id ), array( '%d' ) );
	}

	/* ----------------------------------------------------------------- */
	/* Drain (cron)                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Cron callback. Drains the outbox.
	 *
	 * @return array Summary of the run.
	 */
	public static function cron_drain() {
		global $wpdb;
		$outbox = self::table_outbox();

		// Pick rows ready to attempt:
		//   - status='pending' OR
		//   - status='failed' AND attempts < MAX_ATTEMPTS AND last_attempt_at < now-backoff(attempts).
		// We pull a small batch per tick to bound runtime.
		$batch_size = 20;
		$now_sql    = gmdate( 'Y-m-d H:i:s' );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, webhook_id, event_type, payload, status, attempts, last_attempt_at
				 FROM `$outbox`
				 WHERE status = 'pending'
				    OR ( status = 'failed' AND attempts < %d )
				 ORDER BY id ASC
				 LIMIT %d",
				self::MAX_ATTEMPTS,
				$batch_size
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return array( 'ok' => true, 'processed' => 0 );
		}

		$processed = 0;
		$sent      = 0;
		$failed    = 0;
		$dead      = 0;
		$skipped   = 0;

		foreach ( $rows as $row ) {
			$attempts = (int) $row['attempts'];
			$status   = (string) $row['status'];

			// Backoff gate for retries.
			if ( 'failed' === $status && '' !== (string) $row['last_attempt_at'] ) {
				$backoff_s = self::backoff_seconds( $attempts );
				$next_at   = strtotime( (string) $row['last_attempt_at'] . ' UTC' ) + $backoff_s;
				if ( time() < $next_at ) {
					++$skipped;
					continue;
				}
			}

			++$processed;
			$result = self::deliver_row( $row );

			if ( true === $result['ok'] ) {
				++$sent;
				continue;
			}

			$new_attempts = $attempts + 1;
			$next_status  = $new_attempts >= self::MAX_ATTEMPTS ? 'dead' : 'failed';
			if ( 'dead' === $next_status ) {
				++$dead;
			} else {
				++$failed;
			}
			$wpdb->update(
				$outbox,
				array(
					'status'          => $next_status,
					'attempts'        => $new_attempts,
					'last_attempt_at' => $now_sql,
					'last_error'      => substr( (string) $result['error'], 0, 1024 ),
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
		}

		return array(
			'ok'        => true,
			'processed' => $processed,
			'sent'      => $sent,
			'failed'    => $failed,
			'dead'      => $dead,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Returns the retry backoff in seconds for attempt count `$attempts`.
	 *
	 * 1m, 5m, 30m for attempts 1, 2, 3. Beyond 3 we never retry (dead).
	 *
	 * @param int $attempts Number of attempts already made.
	 * @return int
	 */
	private static function backoff_seconds( $attempts ) {
		switch ( (int) $attempts ) {
			case 0:
				return 0;
			case 1:
				return 60;
			case 2:
				return 5 * 60;
			default:
				return 30 * 60;
		}
	}

	/**
	 * Delivers a single outbox row.
	 *
	 * On HTTP 2xx the row is marked sent immediately (we do that here so
	 * the cron loop only has to handle failures uniformly).
	 *
	 * @param array $row Outbox row (ARRAY_A).
	 * @return array { ok: bool, error?: string, status?: int }
	 */
	private static function deliver_row( $row ) {
		global $wpdb;

		$webhook = self::get_webhook( (int) $row['webhook_id'] );
		if ( null === $webhook ) {
			return array( 'ok' => false, 'error' => 'webhook deleted' );
		}
		if ( empty( $webhook['enabled'] ) ) {
			return array( 'ok' => false, 'error' => 'webhook disabled' );
		}

		$body      = (string) $row['payload'];
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$signature = self::SIGNATURE_PREFIX . hash_hmac(
			'sha256',
			$timestamp . "\n" . $nonce . "\n" . $body,
			(string) $webhook['signing_secret']
		);

		$resp = wp_remote_post(
			(string) $webhook['destination_url'],
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'             => 'application/json',
					'X-IAWM-Webhook-Timestamp' => $timestamp,
					'X-IAWM-Webhook-Nonce'     => $nonce,
					'X-IAWM-Webhook-Signature' => $signature,
					'User-Agent'               => 'IAWM-Webhook/1.0 (+' . get_home_url() . ')',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'error' => $resp->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update(
				self::table_outbox(),
				array(
					'status'          => 'sent',
					'attempts'        => (int) $row['attempts'] + 1,
					'last_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
					'last_error'      => null,
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return array( 'ok' => true, 'status' => $code );
		}

		$body_excerpt = (string) wp_remote_retrieve_body( $resp );
		if ( strlen( $body_excerpt ) > 256 ) {
			$body_excerpt = substr( $body_excerpt, 0, 256 ) . '…';
		}
		return array(
			'ok'    => false,
			'error' => 'HTTP ' . $code . ' ' . $body_excerpt,
			'status' => $code,
		);
	}

	/* ----------------------------------------------------------------- */
	/* REST routes                                                         */
	/* ----------------------------------------------------------------- */

	/**
	 * Registers the /config/webhooks/* routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/config/webhooks/list'   => array( 'handle_list', 'guard_read' ),
			'/config/webhooks/create' => array( 'handle_create', 'guard_write' ),
			'/config/webhooks/update' => array( 'handle_update', 'guard_write' ),
			'/config/webhooks/delete' => array( 'handle_delete', 'guard_write' ),
			'/config/webhooks/test'   => array( 'handle_test', 'guard_write' ),
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
	 * POST /config/webhooks/list — list configured webhooks.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		unset( $request );
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'items' => self::list_webhooks(),
			),
			200
		);
	}

	/**
	 * POST /config/webhooks/create — create a new webhook.
	 *
	 * Body: { label?, destination_url, signing_secret, events: string[]|string, enabled? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_create( $request ) {
		$params = IAWM_Support::json_params( $request );
		$result = self::create( $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'created' => true,
				'item'    => $result,
			),
			201
		);
	}

	/**
	 * POST /config/webhooks/update — update an existing webhook.
	 *
	 * Body: { id, label?, destination_url?, signing_secret?, events?, enabled? }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_update( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $id <= 0 ) {
			return IAWM_Support::rest_error(
				'iawm_invalid_id',
				__( 'Missing or invalid id.', 'ia-webmaster-bridge' ),
				400
			);
		}
		unset( $params['id'] );
		$result = self::update( $id, $params );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'updated' => true,
				'item'    => $result,
			),
			200
		);
	}

	/**
	 * POST /config/webhooks/delete — delete a webhook.
	 *
	 * Body: { id }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $id <= 0 ) {
			return IAWM_Support::rest_error(
				'iawm_invalid_id',
				__( 'Missing or invalid id.', 'ia-webmaster-bridge' ),
				400
			);
		}
		$ok = self::delete( $id );
		return new WP_REST_Response(
			array(
				'ok'      => $ok,
				'deleted' => $ok,
				'id'      => $id,
			),
			$ok ? 200 : 404
		);
	}

	/**
	 * POST /config/webhooks/test — send a synthetic ping immediately.
	 *
	 * Body: { id }
	 *
	 * Bypasses the outbox: signs and POSTs in-band, returning the
	 * receiver's HTTP status + a short body excerpt. Useful for
	 * verifying a fresh configuration without waiting for the cron tick.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_test( $request ) {
		$params = IAWM_Support::json_params( $request );
		$id     = isset( $params['id'] ) ? (int) $params['id'] : 0;
		if ( $id <= 0 ) {
			return IAWM_Support::rest_error(
				'iawm_invalid_id',
				__( 'Missing or invalid id.', 'ia-webmaster-bridge' ),
				400
			);
		}

		$webhook = self::get_webhook( $id );
		if ( null === $webhook ) {
			return IAWM_Support::rest_error(
				'iawm_webhook_not_found',
				__( 'Webhook not found.', 'ia-webmaster-bridge' ),
				404
			);
		}

		$envelope = wp_json_encode(
			array(
				'event'    => 'test.ping',
				'site_url' => get_home_url(),
				'fired_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'payload'  => array(
					'message' => __( 'IA Webmaster webhook test ping.', 'ia-webmaster-bridge' ),
				),
			)
		);
		if ( false === $envelope ) {
			return IAWM_Support::rest_error(
				'iawm_webhook_encode_failed',
				__( 'Could not encode the test payload.', 'ia-webmaster-bridge' ),
				500
			);
		}

		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		$signature = self::SIGNATURE_PREFIX . hash_hmac(
			'sha256',
			$timestamp . "\n" . $nonce . "\n" . $envelope,
			(string) $webhook['signing_secret']
		);

		$resp = wp_remote_post(
			(string) $webhook['destination_url'],
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type'             => 'application/json',
					'X-IAWM-Webhook-Timestamp' => $timestamp,
					'X-IAWM-Webhook-Nonce'     => $nonce,
					'X-IAWM-Webhook-Signature' => $signature,
					'User-Agent'               => 'IAWM-Webhook/1.0 (+' . get_home_url() . ')',
				),
				'body'        => $envelope,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new WP_REST_Response(
				array(
					'ok'              => false,
					'transport_error' => $resp->get_error_message(),
				),
				200
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( strlen( $body ) > 1024 ) {
			$body = substr( $body, 0, 1024 ) . '…';
		}

		return new WP_REST_Response(
			array(
				'ok'             => $code >= 200 && $code < 300,
				'status'         => $code,
				'response_body'  => $body,
				'signed_with'    => array(
					'timestamp' => $timestamp,
					'nonce'     => $nonce,
				),
			),
			200
		);
	}
}
