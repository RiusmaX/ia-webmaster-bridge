<?php
/**
 * Infrastructure plane — WordPress cron management.
 *
 * Lets the agent inspect, trigger and program WordPress scheduled
 * events ("WP-Cron"). Useful for:
 *
 *   - auditing which background tasks a site runs;
 *   - manually firing a hook (cache flushes, maintenance routines,
 *     plugin background jobs) without waiting for the natural tick;
 *   - registering periodic agent-driven routines (weekly SEO audits,
 *     etc.) — spec 07's "scheduled routines".
 *
 * Routes (POST, JSON body):
 *  - /cron/list       (read)        — every queued event, with hook,
 *                                     args, next run, recurrence.
 *  - /cron/schedules  (read)        — available recurrence slugs
 *                                     (`hourly`, `daily`, plus custom
 *                                     ones registered by themes/plugins).
 *  - /cron/run        (infra:write) — run a queued event now.
 *  - /cron/schedule   (infra:write) — schedule a single or recurring event.
 *  - /cron/unschedule (infra:write) — remove a scheduled event.
 *
 * Each write is logged by IAWM_Audit and respects the kill switch.
 * Scope: `infra:write` for the three writes.
 *
 * Safety note: cron events run inside the WP process during the next
 * request matching the WP-Cron trigger; nothing in this module is a
 * shell escape, but malicious or buggy hooks can still hurt the site
 * — list and verify before scheduling.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-Cron routes.
 */
class IAWM_Cron {

	/**
	 * Hooks up route registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers cron routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$routes = array(
			'/cron/list'       => array( 'handle_list', 'guard_read' ),
			'/cron/schedules'  => array( 'handle_schedules', 'guard_read' ),
			'/cron/run'        => array( 'handle_run', 'guard_write' ),
			'/cron/schedule'   => array( 'handle_schedule', 'guard_write' ),
			'/cron/unschedule' => array( 'handle_unschedule', 'guard_write' ),
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
	 * POST /cron/list — flat list of queued WP-Cron events.
	 *
	 * Body: { hook? } — optional hook filter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_list( $request ) {
		$params = IAWM_Support::json_params( $request );
		$filter = isset( $params['hook'] ) ? (string) $params['hook'] : '';

		$crons = _get_cron_array();
		if ( ! is_array( $crons ) ) {
			$crons = array();
		}

		$now    = time();
		$events = array();
		foreach ( $crons as $timestamp => $hooks ) {
			foreach ( $hooks as $hook => $by_key ) {
				if ( '' !== $filter && $filter !== $hook ) {
					continue;
				}
				foreach ( $by_key as $key => $entry ) {
					$events[] = array(
						'hook'      => $hook,
						'args'      => isset( $entry['args'] ) ? $entry['args'] : array(),
						'timestamp' => (int) $timestamp,
						'next_run_in' => (int) $timestamp - $now,
						'schedule'  => isset( $entry['schedule'] ) && $entry['schedule'] ? (string) $entry['schedule'] : null,
						'interval'  => isset( $entry['interval'] ) ? (int) $entry['interval'] : null,
						'key'       => (string) $key,
					);
				}
			}
		}

		// Stable sort: by next run.
		usort(
			$events,
			static function ( $a, $b ) {
				return $a['timestamp'] - $b['timestamp'];
			}
		);

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'total' => count( $events ),
				'now'   => $now,
				'events' => $events,
			),
			200
		);
	}

	/**
	 * POST /cron/schedules — available recurrence slugs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle_schedules( $request ) {
		unset( $request );

		$schedules = wp_get_schedules();
		$out = array();
		foreach ( $schedules as $slug => $def ) {
			$out[] = array(
				'slug'     => $slug,
				'interval' => isset( $def['interval'] ) ? (int) $def['interval'] : null,
				'display'  => isset( $def['display'] ) ? (string) $def['display'] : '',
			);
		}

		return new WP_REST_Response( array( 'ok' => true, 'schedules' => $out ), 200 );
	}

	/**
	 * POST /cron/run — runs the next occurrence of a hook **now**.
	 *
	 * Body: { hook, args? } — args must match the queued event exactly
	 * for `do_action_ref_array` to find it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_run( $request ) {
		$params = IAWM_Support::json_params( $request );
		$hook   = isset( $params['hook'] ) ? (string) $params['hook'] : '';
		$args   = isset( $params['args'] ) && is_array( $params['args'] ) ? $params['args'] : array();

		if ( '' === $hook ) {
			return IAWM_Support::rest_error( 'iawm_missing_hook', '`hook` is required.', 400 );
		}

		$next = wp_next_scheduled( $hook, $args );
		if ( false === $next ) {
			return IAWM_Support::rest_error( 'iawm_not_scheduled', "Hook '{$hook}' is not currently scheduled with the given args.", 404 );
		}

		IAWM_Support::act_as_agent();

		// Fire the action with the recorded args.
		do_action_ref_array( $hook, $args );

		// And reschedule if it was recurring (mimic wp-cron.php behaviour).
		$crons = _get_cron_array();
		if ( isset( $crons[ $next ][ $hook ] ) ) {
			foreach ( $crons[ $next ][ $hook ] as $key => $entry ) {
				if ( ! empty( $entry['schedule'] ) ) {
					wp_reschedule_event( $next, $entry['schedule'], $hook, $entry['args'] );
				}
				wp_unschedule_event( $next, $hook, $entry['args'] );
			}
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'hook'   => $hook,
				'args'   => $args,
				'fired_at_ts' => $next,
			),
			200
		);
	}

	/**
	 * POST /cron/schedule — adds a single or recurring scheduled event.
	 *
	 * Body: { hook, schedule?, timestamp?, args? }
	 *   - `schedule`: slug from `wp_get_schedules()` for a recurring event.
	 *   - `timestamp`: Unix timestamp for a one-off event (or for the
	 *                  first occurrence of a recurring one). Defaults
	 *                  to `time() + 60` (1 minute from now).
	 *   - `args`: optional positional arguments passed to the hook.
	 *
	 * Note: the hook is **not** registered by this endpoint — the
	 * caller must trust that some PHP code on the site is listening
	 * with `add_action( $hook, ... )`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_schedule( $request ) {
		$params    = IAWM_Support::json_params( $request );
		$hook      = isset( $params['hook'] ) ? (string) $params['hook'] : '';
		$schedule  = isset( $params['schedule'] ) ? (string) $params['schedule'] : '';
		$timestamp = isset( $params['timestamp'] ) ? (int) $params['timestamp'] : ( time() + 60 );
		$args      = isset( $params['args'] ) && is_array( $params['args'] ) ? $params['args'] : array();

		if ( '' === $hook ) {
			return IAWM_Support::rest_error( 'iawm_missing_hook', '`hook` is required.', 400 );
		}
		if ( '' !== $schedule ) {
			$schedules = wp_get_schedules();
			if ( ! isset( $schedules[ $schedule ] ) ) {
				return IAWM_Support::rest_error(
					'iawm_unknown_schedule',
					"Unknown schedule slug '{$schedule}'. Use /cron/schedules to list valid ones.",
					400
				);
			}
		}

		IAWM_Support::act_as_agent();

		$res = '' !== $schedule
			? wp_schedule_event( $timestamp, $schedule, $hook, $args )
			: wp_schedule_single_event( $timestamp, $hook, $args );

		if ( false === $res ) {
			return IAWM_Support::rest_error( 'iawm_schedule_failed', 'Scheduling failed (event probably already queued).', 409 );
		}

		return new WP_REST_Response(
			array(
				'ok'        => true,
				'scheduled' => true,
				'hook'      => $hook,
				'args'      => $args,
				'timestamp' => $timestamp,
				'schedule'  => '' !== $schedule ? $schedule : null,
			),
			201
		);
	}

	/**
	 * POST /cron/unschedule — removes a scheduled event.
	 *
	 * Body: { hook, args?, timestamp? }
	 *   - With `timestamp`: removes the specific occurrence at that
	 *     timestamp.
	 *   - Without `timestamp`: removes every occurrence of the hook
	 *     (calls `wp_unschedule_hook`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_unschedule( $request ) {
		$params    = IAWM_Support::json_params( $request );
		$hook      = isset( $params['hook'] ) ? (string) $params['hook'] : '';
		$args      = isset( $params['args'] ) && is_array( $params['args'] ) ? $params['args'] : array();
		$timestamp = isset( $params['timestamp'] ) ? (int) $params['timestamp'] : 0;

		if ( '' === $hook ) {
			return IAWM_Support::rest_error( 'iawm_missing_hook', '`hook` is required.', 400 );
		}

		IAWM_Support::act_as_agent();

		if ( $timestamp > 0 ) {
			$res = wp_unschedule_event( $timestamp, $hook, $args );
			return new WP_REST_Response(
				array(
					'ok'           => false !== $res,
					'unscheduled'  => false !== $res,
					'hook'         => $hook,
					'args'         => $args,
					'timestamp'    => $timestamp,
				),
				false !== $res ? 200 : 404
			);
		}

		$removed = wp_unschedule_hook( $hook );

		return new WP_REST_Response(
			array(
				'ok'             => true,
				'unscheduled_all' => true,
				'hook'           => $hook,
				'removed'        => is_numeric( $removed ) ? (int) $removed : null,
			),
			200
		);
	}
}
