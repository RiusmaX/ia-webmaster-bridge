<?php
/**
 * Read-only network admin overview page for multisite installs.
 *
 * Adds a "IA Webmaster Bridge" entry to the **Network Admin → Settings**
 * menu (only registered when running on a multisite). The page is a
 * compact dashboard: one row per sub-site of the network, showing how
 * the plugin is provisioned there.
 *
 * Columns:
 *   - Blog id      : sub-site identifier inside the network.
 *   - URL          : `siteurl` (a link to the sub-site admin).
 *   - Keys         : number of API credentials configured on that
 *                    sub-site (count of `iawm_credentials`).
 *   - Kill switch  : whether the per-site kill switch is engaged.
 *   - Audit log    : timestamp of the most recent row in the per-site
 *                    `wp_<prefix>iawm_audit_log` (or `—` if empty).
 *   - Last cron    : next-scheduled `iawm_prune_audit_log` (a sanity
 *                    check that the per-site cron is wired up).
 *
 * The page is intentionally **read-only**: it is an at-a-glance health
 * dashboard, not a management surface. Per-site key creation, scope
 * edits and kill switch toggles still happen on each sub-site's own
 * Settings → IA Webmaster Bridge page, so the audit trail lines up
 * with the right blog id.
 *
 * The implementation walks the sub-sites with `switch_to_blog()` /
 * `restore_current_blog()`; we cap the page at 50 rows so a large
 * network does not hit a memory wall — the operator can paginate via
 * `?paged=N`.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Network admin dashboard. No-op outside multisite.
 */
class IAWM_Network_Admin {

	/** Page slug under the network admin menu. */
	const PAGE_SLUG = 'iawm-network';

	/** Rows per page on the overview. */
	const PER_PAGE = 50;

	/**
	 * Registers the menu (only on multisite).
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'network_admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Adds the page under "Network Admin → Settings".
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'settings.php',
			'IA Webmaster Bridge',
			'IA Webmaster Bridge',
			'manage_network_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the read-only overview.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ia-webmaster-bridge' ) );
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$total = (int) get_sites( array( 'count' => true ) );
		$sites = get_sites(
			array(
				'number' => self::PER_PAGE,
				'offset' => ( $paged - 1 ) * self::PER_PAGE,
			)
		);

		echo '<div class="wrap"><h1>IA Webmaster Bridge — Network overview</h1>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %d: total sub-sites in the network. */
				_n( '%d sub-site in this network.', '%d sub-sites in this network.', $total, 'ia-webmaster-bridge' ),
				$total
			)
		) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Blog', 'ia-webmaster-bridge' ) . '</th>';
		echo '<th>' . esc_html__( 'URL', 'ia-webmaster-bridge' ) . '</th>';
		echo '<th>' . esc_html__( 'Keys', 'ia-webmaster-bridge' ) . '</th>';
		echo '<th>' . esc_html__( 'Kill switch', 'ia-webmaster-bridge' ) . '</th>';
		echo '<th>' . esc_html__( 'Last audit', 'ia-webmaster-bridge' ) . '</th>';
		echo '<th>' . esc_html__( 'Next cron', 'ia-webmaster-bridge' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $sites as $site ) {
			$bid = (int) $site->blog_id;
			switch_to_blog( $bid );
			$row = self::collect_row( $bid );
			restore_current_blog();

			echo '<tr>';
			echo '<td>' . esc_html( (string) $bid ) . '</td>';
			echo '<td><a href="' . esc_url( $row['admin_url'] ) . '">' . esc_html( $row['url'] ) . '</a></td>';
			echo '<td>' . esc_html( (string) $row['keys'] ) . '</td>';
			echo '<td>' . ( $row['kill_switch'] ? '<span style="color:#d63638">ON</span>' : 'off' ) . '</td>';
			echo '<td>' . esc_html( $row['last_audit'] ) . '</td>';
			echo '<td>' . esc_html( $row['next_cron'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Compact pagination.
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<p class="iawm-pager">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg( 'paged', $i );
				if ( $i === $paged ) {
					echo '<strong>' . esc_html( (string) $i ) . '</strong> ';
				} else {
					echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
				}
			}
			echo '</p>';
		}
		echo '</div>';
	}

	/**
	 * Collects the row data for the **current** site (caller switched).
	 *
	 * @param int $blog_id Blog id (for the admin URL).
	 * @return array<string, mixed>
	 */
	private static function collect_row( $blog_id ) {
		global $wpdb;

		$creds = class_exists( 'IAWM_Settings' )
			? IAWM_Settings::all_credentials()
			: array();
		$kill = class_exists( 'IAWM_Settings' )
			? IAWM_Settings::is_kill_switch_on()
			: false;

		// Last audit row — quoting the per-site prefix.
		$last = '—';
		$table = $wpdb->prefix . 'iawm_audit_log';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time admin query, no caching needed.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
			$ts = $wpdb->get_var( "SELECT MAX(created_at) FROM `$table`" );
			if ( $ts ) {
				$last = (string) $ts;
			}
		}
		// phpcs:enable

		$next_ts = wp_next_scheduled( 'iawm_prune_audit_log' );
		$next    = $next_ts ? gmdate( 'Y-m-d H:i', (int) $next_ts ) : '—';

		return array(
			'url'         => get_site_url( $blog_id ),
			'admin_url'   => get_admin_url( $blog_id, 'options-general.php?page=iawm-settings' ),
			'keys'        => count( $creds ),
			'kill_switch' => $kill,
			'last_audit'  => $last,
			'next_cron'   => $next,
		);
	}
}
