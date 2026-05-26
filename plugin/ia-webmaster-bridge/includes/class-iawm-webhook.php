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
 * Encryption-at-rest (v1.4+, see D-032): `signing_secret` is written
 * through `IAWM_Crypto::encrypt()` on INSERT/UPDATE and read through
 * `IAWM_Crypto::decrypt()` at signing time. Legacy plaintext rows
 * from v1.3.x decrypt transparently via envelope sniff; a one-time
 * `maybe_migrate_secrets_to_encrypted()` migration rewrites them at
 * the first `init()` after upgrade.
 *
 * Resolved in v1.4.0 (was D-030's "no admin UI" trade-off):
 *   - admin "Webhooks" tab in the plugin settings page now exposes
 *     create / edit / toggle / test / delete / rotate-secret on top of
 *     the existing endpoints. The REST + MCP surface is unchanged —
 *     both paths call the same `create() / update() / delete() /
 *     rotate_secret() / test()` helpers.
 *
 * Still out of scope:
 *   - audit-alert event firing (planned alongside an audit-tail
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

	/**
	 * Option flag recording that the v1.4 one-time migration of
	 * `signing_secret` from plaintext to AES-256-CBC (D-032) has run
	 * against the current `IAWM_VERSION`. The value is the version
	 * string so a future schema bump can re-trigger the scan without
	 * a fresh option name.
	 */
	const OPTION_SECRETS_MIGRATED = 'iawm_webhook_secrets_migrated';

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
		// One-time encryption-at-rest migration. Runs once per version
		// bump (idempotent on repeated boots) and lazily on plugins_loaded
		// so the crypto helper is loaded by the time we touch a row.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_migrate_secrets_to_encrypted' ), 20 );
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

	/**
	 * One-time encryption-at-rest migration (D-032).
	 *
	 * Walks every row in `wp_iawm_webhooks` whose `signing_secret` does
	 * not already carry the `iawm-enc:v1:` envelope and rewrites it
	 * with `IAWM_Crypto::encrypt()`. Idempotent on its own (the
	 * `IAWM_Crypto::is_encrypted()` sniff short-circuits encrypted
	 * rows) and gated by an option keyed on `IAWM_VERSION` so we only
	 * scan once per version bump rather than every boot.
	 *
	 * Wrapped in `try/catch` so a corrupted row or a misconfigured
	 * environment cannot crash the page load that triggered
	 * `plugins_loaded` — the migration simply skips the bad row and
	 * the next attempt picks it up on the next version bump.
	 *
	 * Note: this is per-site (multisite-tolerant per D-027). Each
	 * sub-site carries its own `wp_iawm_webhooks` table and its own
	 * migration flag, so a single network-activated install sees the
	 * migration fire once per sub-site as each one is touched.
	 *
	 * @return void
	 */
	public static function maybe_migrate_secrets_to_encrypted() {
		// Already migrated for this version — fast path.
		if ( get_option( self::OPTION_SECRETS_MIGRATED ) === IAWM_VERSION ) {
			return;
		}

		// Crypto helper must be available. The require_once in
		// `ia-webmaster-bridge.php` happens before this hook fires, but
		// guard defensively for code reuse (tests, partial loads).
		if ( ! class_exists( 'IAWM_Crypto' ) ) {
			return;
		}

		global $wpdb;
		$table = self::table_webhooks();

		// `dbDelta` runs in `install()` on activation; the migration
		// option lives in the standard options table. If the webhooks
		// table does not exist (e.g. install hook never ran on a
		// freshly-cloned sub-site that has yet to be visited), bail
		// silently — the next call after `maybe_upgrade` will pick it
		// up.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		try {
			$rows = $wpdb->get_results(
				"SELECT id, signing_secret FROM `$table`",
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$current = (string) $row['signing_secret'];
					if ( '' === $current ) {
						continue;
					}
					if ( IAWM_Crypto::is_encrypted( $current ) ) {
						continue;
					}
					$encrypted = IAWM_Crypto::encrypt( $current );
					if ( '' === $encrypted ) {
						// Encryption refused (no OpenSSL? bad key?). Leave
						// the plaintext alone so the decryptor's fallback
						// keeps the receiver working until the operator
						// fixes the environment.
						continue;
					}
					$wpdb->update(
						$table,
						array( 'signing_secret' => $encrypted ),
						array( 'id' => (int) $row['id'] ),
						array( '%s' ),
						array( '%d' )
					);
				}
			}
		} catch ( \Throwable $e ) {
			// Swallow: a migration crash must not block the page.
			// Re-running on the next version bump is fine — encrypted
			// rows are skipped, only the failing rows are retried.
			return;
		}

		update_option( self::OPTION_SECRETS_MIGRATED, IAWM_VERSION, true );
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

		// Encryption-at-rest (D-032): the operator-supplied secret is
		// wrapped in the versioned envelope before it touches the DB.
		// The 16-character minimum already enforced above guarantees
		// the ciphertext + envelope fits in the 255-char column with
		// room to spare (the envelope adds ~40 bytes of overhead and
		// AES-CBC ciphertext is padded to a 16-byte boundary).
		$secret_to_store = IAWM_Crypto::encrypt( substr( $secret, 0, 255 ) );

		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			self::table_webhooks(),
			array(
				'label'           => substr( $label, 0, 191 ),
				'destination_url' => substr( $url, 0, 2048 ),
				'signing_secret'  => $secret_to_store,
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
			// Encryption-at-rest (D-032): rotated secrets are encrypted
			// on the way in just like fresh ones. The column is
			// untouched when `signing_secret` is absent from the update
			// payload, so existing rows keep their stored envelope.
			$updates['signing_secret'] = IAWM_Crypto::encrypt( substr( $secret, 0, 255 ) );
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

	/**
	 * Generates a fresh signing secret — 32 random bytes hex-encoded (64
	 * chars). Used by the admin tab when the operator clicks "rotate
	 * secret" (no need for them to invent a strong value by hand).
	 *
	 * @return string
	 */
	public static function generate_secret() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			// Extremely unlikely fallback. `wp_generate_password` is seeded
			// by the same CSPRNG when available, so this stays strong.
			return wp_generate_password( 64, false, false );
		}
	}

	/**
	 * Rotates the signing secret of a webhook in place and returns the
	 * freshly-generated plaintext value so the caller can display it ONCE
	 * to the operator (it is never retrievable again from the API).
	 *
	 * @param int $id Webhook id.
	 * @return array|WP_Error { id, signing_secret } on success.
	 */
	public static function rotate_secret( $id ) {
		$id     = (int) $id;
		$secret = self::generate_secret();
		$result = self::update( $id, array( 'signing_secret' => $secret ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'id'             => $id,
			'signing_secret' => $secret,
		);
	}

	/**
	 * Sends a synthetic `test.ping` to a webhook in-band (bypasses the
	 * outbox). Returns a plain array so admin and REST callers share the
	 * same code path. Mirrors `handle_test()` but does not allocate a
	 * `WP_REST_Response` — the admin tab just needs the shape.
	 *
	 * @param int $id Webhook id.
	 * @return array|WP_Error { ok, status?, response_body?, transport_error?, signed_with? }
	 */
	public static function test( $id ) {
		$id      = (int) $id;
		$webhook = self::get_webhook( $id );
		if ( null === $webhook ) {
			return new WP_Error(
				'iawm_webhook_not_found',
				__( 'Webhook not found.', 'ia-webmaster-bridge' ),
				array( 'status' => 404 )
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
			return new WP_Error(
				'iawm_webhook_encode_failed',
				__( 'Could not encode the test payload.', 'ia-webmaster-bridge' ),
				array( 'status' => 500 )
			);
		}

		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 16 ) );
		// Encryption-at-rest (D-032): decrypt the stored envelope before
		// HMAC. Legacy plaintext rows pass through `IAWM_Crypto::decrypt`
		// unchanged.
		$secret    = IAWM_Crypto::decrypt( (string) $webhook['signing_secret'] );
		$signature = self::SIGNATURE_PREFIX . hash_hmac(
			'sha256',
			$timestamp . "\n" . $nonce . "\n" . $envelope,
			$secret
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
			return array(
				'ok'              => false,
				'transport_error' => $resp->get_error_message(),
				'signed_with'     => array(
					'timestamp' => $timestamp,
					'nonce'     => $nonce,
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( strlen( $body ) > 1024 ) {
			$body = substr( $body, 0, 1024 ) . '…';
		}

		return array(
			'ok'            => $code >= 200 && $code < 300,
			'status'        => $code,
			'response_body' => $body,
			'signed_with'   => array(
				'timestamp' => $timestamp,
				'nonce'     => $nonce,
			),
		);
	}

	/**
	 * Returns the most recent outbox row per webhook, keyed by webhook_id.
	 *
	 * Used by the admin "Webhooks" tab to render the "last drain" column
	 * (status + timestamp). One query, one row per webhook — no window
	 * functions so it stays portable across older MySQL versions.
	 *
	 * @return array<int, array{status:string,last_attempt_at:?string,event_type:string,last_error:?string,created_at:string}>
	 */
	public static function latest_outbox_by_webhook() {
		global $wpdb;
		$outbox = self::table_outbox();

		$rows = $wpdb->get_results(
			"SELECT o.webhook_id, o.status, o.last_attempt_at, o.event_type, o.last_error, o.created_at
			 FROM `$outbox` o
			 INNER JOIN (
				 SELECT webhook_id, MAX(id) AS max_id
				 FROM `$outbox`
				 GROUP BY webhook_id
			 ) latest ON latest.webhook_id = o.webhook_id AND latest.max_id = o.id",
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['webhook_id'] ] = array(
				'status'          => (string) $row['status'],
				'last_attempt_at' => $row['last_attempt_at'] ? (string) $row['last_attempt_at'] : null,
				'event_type'      => (string) $row['event_type'],
				'last_error'      => $row['last_error'] ? (string) $row['last_error'] : null,
				'created_at'      => (string) $row['created_at'],
			);
		}
		return $out;
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
		// Encryption-at-rest (D-032): the column may carry an
		// `iawm-enc:v1:` envelope; `IAWM_Crypto::decrypt` peels it.
		// Legacy plaintext rows pass through untouched.
		$secret    = IAWM_Crypto::decrypt( (string) $webhook['signing_secret'] );
		$signature = self::SIGNATURE_PREFIX . hash_hmac(
			'sha256',
			$timestamp . "\n" . $nonce . "\n" . $body,
			$secret
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
		// Encryption-at-rest (D-032): decrypt the column before HMAC.
		$secret    = IAWM_Crypto::decrypt( (string) $webhook['signing_secret'] );
		$signature = self::SIGNATURE_PREFIX . hash_hmac(
			'sha256',
			$timestamp . "\n" . $nonce . "\n" . $envelope,
			$secret
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
