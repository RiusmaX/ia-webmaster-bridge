<?php
/**
 * Admin interface for the IA Webmaster Bridge plugin.
 *
 * "Settings -> IA Webmaster Bridge" page. Multi-tab UI redesigned in
 * Phase 7.4 — status bar at the top, six tabs (Keys / Agent /
 * Security / Cleanup / Audit / Tools), card layout, danger zone
 * visually separated.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings page and admin action handling.
 */
class IAWM_Admin {

	/** Slug of the settings page. */
	const PAGE_SLUG = 'iawm-settings';

	/** admin-post action that processes the form. */
	const ACTION = 'iawm_action';

	/** Default tab when no `tab` query param is present. */
	const DEFAULT_TAB = 'keys';

	/**
	 * Tabs definition: slug => { label, icon }.
	 *
	 * @return array
	 */
	private static function tabs() {
		return array(
			'keys'     => array( 'label' => __( 'API Keys', 'ia-webmaster-bridge' ),  'icon' => '\f160' ), // dashicons-admin-network
			'agent'    => array( 'label' => __( 'Agent', 'ia-webmaster-bridge' ),     'icon' => '\f110' ), // dashicons-businessman
			'security' => array( 'label' => __( 'Security', 'ia-webmaster-bridge' ),  'icon' => '\f332' ), // dashicons-shield
			'cleanup'  => array( 'label' => __( 'Cleanup', 'ia-webmaster-bridge' ),   'icon' => '\f182' ), // dashicons-trash
			'audit'    => array( 'label' => __( 'Audit log', 'ia-webmaster-bridge' ), 'icon' => '\f105' ), // dashicons-list-view
			'tools'    => array( 'label' => __( 'Tools', 'ia-webmaster-bridge' ),     'icon' => '\f308' ), // dashicons-admin-tools
		);
	}

	/**
	 * Hooks up admin actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	/**
	 * Adds the page under the "Settings" menu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_options_page(
			'IA Webmaster Bridge',
			'IA Webmaster Bridge',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Adds page-specific styles (inline, no extra HTTP request).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_styles( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		// Inline so we don't add a CSS file to maintain.
		wp_add_inline_style( 'common', self::page_css() );
	}

	/**
	 * Page CSS — kept compact, leans on WP admin variables.
	 *
	 * @return string
	 */
	private static function page_css() {
		return '
.iawm-wrap { max-width: 1200px; }
.iawm-status-bar { display: flex; gap: 12px; flex-wrap: wrap; padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; border-left-width: 4px; border-left-color: #2271b1; margin: 16px 0 24px; border-radius: 4px; }
.iawm-status-bar.is-danger { border-left-color: #d63638; }
.iawm-status-item { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.iawm-status-item strong { font-weight: 600; }
.iawm-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; line-height: 1.4; }
.iawm-pill-ok { background: #d4edda; color: #155724; }
.iawm-pill-warn { background: #fff3cd; color: #856404; }
.iawm-pill-danger { background: #f8d7da; color: #721c24; }
.iawm-pill-neutral { background: #e2e3e5; color: #383d41; }

.iawm-tabs { margin-bottom: 0; }
.iawm-tabs .nav-tab { display: inline-block; }
.iawm-tab-panel { background: #fff; border: 1px solid #c3c4c7; border-top: none; padding: 24px; border-radius: 0 0 4px 4px; }

.iawm-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
.iawm-card-title { margin-top: 0; padding-bottom: 8px; border-bottom: 1px solid #f0f0f1; font-size: 16px; }
.iawm-card-help { color: #646970; font-size: 13px; margin-top: -4px; margin-bottom: 16px; }

.iawm-key-row { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; margin-bottom: 12px; }
.iawm-key-row.is-highlight { border-color: #ffba00; background: #fff8c5; }
.iawm-key-summary { display: grid; grid-template-columns: auto 1fr 2fr 1fr auto; gap: 16px; padding: 14px 16px; align-items: center; }
.iawm-key-status { width: 10px; height: 10px; border-radius: 50%; }
.iawm-key-status.is-active { background: #00a32a; }
.iawm-key-status.is-idle { background: #dba617; }
.iawm-key-status.is-unused { background: #c3c4c7; }
.iawm-key-label { font-weight: 600; }
.iawm-key-id { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; color: #50575e; }
.iawm-key-scopes { display: flex; gap: 4px; flex-wrap: wrap; }
.iawm-scope-badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 11px; line-height: 1.5; font-weight: 600; }
.iawm-scope-read         { background: #e7f5ff; color: #0c5460; }
.iawm-scope-content-write{ background: #fff3cd; color: #856404; }
.iawm-scope-divi-write   { background: #f4d3f6; color: #6f42c1; }
.iawm-scope-config-write { background: #d4edda; color: #155724; }
.iawm-scope-infra-write  { background: #f8d7da; color: #721c24; }
.iawm-scope-all          { background: #2271b1; color: #fff; }

.iawm-key-details { border-top: 1px solid #f0f0f1; padding: 16px; background: #fafafa; }
.iawm-key-details fieldset { margin: 8px 0; }
.iawm-key-details label { display: block; margin: 4px 0; }
.iawm-key-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e0e0e0; }

.iawm-secret-display { background: #fff8c5; border: 1px solid #ffba00; padding: 10px 12px; border-radius: 4px; margin-bottom: 12px; }
.iawm-secret-display p { margin: 0 0 6px; font-weight: 600; }

.iawm-danger-zone { border-left: 4px solid #d63638; background: #fcf0f1; padding: 16px; border-radius: 4px; }
.iawm-danger-zone h3 { margin-top: 0; color: #d63638; }

.iawm-help-table th { width: 200px; text-align: left; vertical-align: top; padding-right: 16px; font-weight: 600; color: #50575e; }
.iawm-help-table td { padding-bottom: 8px; }

.iawm-kill-switch { font-size: 24px; padding: 14px 22px; }

.iawm-audit-table { width: 100%; }
.iawm-audit-table .outcome-success { color: #00a32a; }
.iawm-audit-table .outcome-denied  { color: #d63638; }
.iawm-audit-table .outcome-error   { color: #d63638; font-weight: 600; }

.iawm-tools-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
.iawm-tool-card { background: #fff; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px; }
.iawm-tool-card h4 { margin-top: 0; }

.iawm-empty-state { text-align: center; padding: 40px 20px; color: #646970; }
.iawm-empty-state h3 { margin-top: 0; }

@media (max-width: 782px) {
  .iawm-key-summary { grid-template-columns: auto 1fr; }
  .iawm-key-summary > * { grid-column: span 1; }
}
';
	}

	/**
	 * Handles form actions.
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ia-webmaster-bridge' ) );
		}

		check_admin_referer( self::ACTION );

		$op     = isset( $_POST['iawm_op'] ) ? sanitize_key( wp_unslash( $_POST['iawm_op'] ) ) : '';
		$tab    = isset( $_POST['iawm_tab'] ) ? sanitize_key( wp_unslash( $_POST['iawm_tab'] ) ) : self::DEFAULT_TAB;
		$notice = 'none';
		$args   = array();

		switch ( $op ) {
			case 'create_key':
				$scopes  = self::scopes_from_post();
				$label   = isset( $_POST['iawm_label'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_label'] ) ) : '';
				$linked  = isset( $_POST['iawm_linked_user'] ) ? (int) $_POST['iawm_linked_user'] : 0;
				$created = IAWM_Settings::create_credentials( $scopes, $label, $linked > 0 ? $linked : null );
				$notice  = 'key_created';
				$args['new_key'] = $created['key_id'];
				break;

			case 'rotate_secret':
				$key = isset( $_POST['iawm_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_key_id'] ) ) : '';
				if ( '' !== $key ) {
					IAWM_Settings::rotate_secret( $key );
					$notice          = 'secret_rotated';
					$args['new_key'] = $key;
				}
				break;

			case 'update_scopes':
				$key    = isset( $_POST['iawm_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_key_id'] ) ) : '';
				$scopes = self::scopes_from_post();
				if ( '' !== $key ) {
					IAWM_Settings::update_scopes( $key, $scopes );
					$notice = 'scopes_updated';
				}
				break;

			case 'update_metadata':
				$key    = isset( $_POST['iawm_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_key_id'] ) ) : '';
				$label  = isset( $_POST['iawm_label'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_label'] ) ) : null;
				$linked = isset( $_POST['iawm_linked_user'] ) ? (int) $_POST['iawm_linked_user'] : null;
				if ( '' !== $key ) {
					IAWM_Settings::update_metadata( $key, $label, $linked );
					$notice = 'metadata_updated';
				}
				break;

			case 'revoke_key':
				$key = isset( $_POST['iawm_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_key_id'] ) ) : '';
				if ( '' !== $key ) {
					IAWM_Settings::revoke_key( $key );
					$notice = 'key_revoked';
				}
				break;

			case 'revoke_all':
				IAWM_Settings::revoke_credentials();
				$notice = 'all_revoked';
				break;

			case 'kill_on':
				IAWM_Settings::set_kill_switch( true );
				$notice = 'kill_on';
				break;
			case 'kill_off':
				IAWM_Settings::set_kill_switch( false );
				$notice = 'kill_off';
				break;

			case 'reinstall_agent':
				if ( class_exists( 'IAWM_Agent_User' ) ) {
					IAWM_Agent_User::install();
				}
				$notice = 'agent_installed';
				break;

			case 'save_allowlist':
				$raw = isset( $_POST['iawm_allowlist_raw'] ) ? (string) wp_unslash( $_POST['iawm_allowlist_raw'] ) : '';
				$entries = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
				$check   = IAWM_Network::validate_allowlist( $entries );
				IAWM_Network::set_allowlist( $check['valid'] );
				if ( ! empty( $check['invalid'] ) ) {
					$notice = 'allowlist_partial';
					$args['rejected_count'] = count( $check['invalid'] );
				} else {
					$notice = 'allowlist_saved';
				}
				break;

			case 'save_retention':
				$days = isset( $_POST['iawm_audit_days'] ) ? (int) $_POST['iawm_audit_days'] : 90;
				$keep = isset( $_POST['iawm_backup_keep'] ) ? (int) $_POST['iawm_backup_keep'] : 50;
				update_option( IAWM_Audit::OPTION_RETENTION_DAYS, max( 1, min( 3650, $days ) ), true );
				update_option( IAWM_Backup::OPTION_RETENTION_N, max( 1, min( 10000, $keep ) ), true );
				$notice = 'retention_saved';
				break;

			case 'prune_audit_now':
				$deleted = IAWM_Audit::prune_old();
				$notice  = 'audit_pruned';
				$args['pruned'] = (int) $deleted;
				break;

			case 'prune_backups_now':
				$deleted = IAWM_Backup::auto_prune();
				$notice  = 'backups_pruned';
				$args['pruned'] = (int) $deleted;
				break;
		}

		$args['page']        = self::PAGE_SLUG;
		$args['iawm_notice'] = $notice;
		$args['tab']         = $tab;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Reads the submitted list of scopes from $_POST['iawm_scopes'].
	 *
	 * @return array
	 */
	private static function scopes_from_post() {
		$raw = isset( $_POST['iawm_scopes'] ) ? (array) wp_unslash( $_POST['iawm_scopes'] ) : array();
		return IAWM_Settings::sanitize_scopes( $raw );
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'ia-webmaster-bridge' ) );
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : self::DEFAULT_TAB;
		if ( ! array_key_exists( $current_tab, self::tabs() ) ) {
			$current_tab = self::DEFAULT_TAB;
		}

		$notice = isset( $_GET['iawm_notice'] ) ? sanitize_key( wp_unslash( $_GET['iawm_notice'] ) ) : '';
		?>
		<div class="wrap iawm-wrap">
			<h1>IA Webmaster Bridge <span style="color:#646970;font-weight:400;font-size:14px;">v<?php echo esc_html( IAWM_VERSION ); ?></span></h1>

			<?php self::render_status_bar(); ?>
			<?php self::render_notice( $notice ); ?>
			<?php self::render_tabs( $current_tab ); ?>

			<div class="iawm-tab-panel">
				<?php
				switch ( $current_tab ) {
					case 'agent':    self::render_tab_agent();    break;
					case 'security': self::render_tab_security(); break;
					case 'cleanup':  self::render_tab_cleanup();  break;
					case 'audit':    self::render_tab_audit();    break;
					case 'tools':    self::render_tab_tools();    break;
					case 'keys':
					default:
						self::render_tab_keys();
				}
				?>
			</div>

			<p style="color:#646970;font-size:12px;margin-top:24px;">
				<?php esc_html_e( 'API base:', 'ia-webmaster-bridge' ); ?> <code><?php echo esc_html( get_rest_url( null, IAWM_REST_NAMESPACE ) ); ?></code>
				· <a href="https://github.com/RiusmaX/ia-webmaster-bridge" target="_blank" rel="noopener">GitHub</a>
				·
				<?php
				printf(
					/* translators: %s: file name of the operations runbook (kept verbatim). */
					esc_html__( 'See %s for the runbook.', 'ia-webmaster-bridge' ),
					'<code>docs/operations.md</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Status bar                                                         */
	/* ----------------------------------------------------------------- */

	private static function render_status_bar() {
		global $wpdb;
		$keys_count    = count( IAWM_Settings::all_credentials() );
		$kill          = IAWM_Settings::is_kill_switch_on();
		$agent_id      = class_exists( 'IAWM_Agent_User' ) ? IAWM_Agent_User::get_user_id() : 0;
		$agent_ok      = $agent_id > 0;
		$audit_count   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IAWM_Audit::table_name() );
		$backups_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IAWM_Backup::table_name() );
		$class         = $kill ? 'iawm-status-bar is-danger' : 'iawm-status-bar';
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<div class="iawm-status-item">
				<?php if ( $kill ) : ?>
					<span class="iawm-pill iawm-pill-danger"><?php esc_html_e( 'KILL SWITCH ON', 'ia-webmaster-bridge' ); ?></span>
				<?php else : ?>
					<span class="iawm-pill iawm-pill-ok"><?php esc_html_e( 'Operational', 'ia-webmaster-bridge' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="iawm-status-item">
				<strong><?php esc_html_e( 'Keys:', 'ia-webmaster-bridge' ); ?></strong> <?php echo (int) $keys_count; ?>
			</div>
			<div class="iawm-status-item">
				<strong><?php esc_html_e( 'Agent user:', 'ia-webmaster-bridge' ); ?></strong>
				<?php if ( $agent_ok ) : ?>
					<span class="iawm-pill iawm-pill-ok"><?php esc_html_e( 'healthy', 'ia-webmaster-bridge' ); ?></span>
				<?php else : ?>
					<span class="iawm-pill iawm-pill-danger"><?php esc_html_e( 'missing', 'ia-webmaster-bridge' ); ?></span>
				<?php endif; ?>
			</div>
			<div class="iawm-status-item">
				<?php
				printf(
					/* translators: %d: number of audit log entries. */
					'<strong>' . esc_html__( 'Audit:', 'ia-webmaster-bridge' ) . '</strong> ' . esc_html( _n( '%d entry', '%d entries', (int) $audit_count, 'ia-webmaster-bridge' ) ),
					(int) $audit_count
				);
				?>
			</div>
			<div class="iawm-status-item">
				<strong><?php esc_html_e( 'Backups:', 'ia-webmaster-bridge' ); ?></strong> <?php echo (int) $backups_count; ?>
			</div>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tab navigation                                                     */
	/* ----------------------------------------------------------------- */

	private static function render_tabs( $current ) {
		echo '<nav class="nav-tab-wrapper iawm-tabs">';
		foreach ( self::tabs() as $slug => $def ) {
			$url   = add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'tab' => $slug ),
				admin_url( 'options-general.php' )
			);
			$class = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
			printf(
				'<a href="%1$s" class="%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $def['label'] )
			);
		}
		echo '</nav>';
	}

	/* ----------------------------------------------------------------- */
	/* Tab: API keys                                                      */
	/* ----------------------------------------------------------------- */

	private static function render_tab_keys() {
		$all            = IAWM_Settings::all_credentials();
		$highlight_key  = isset( $_GET['new_key'] ) ? sanitize_text_field( wp_unslash( $_GET['new_key'] ) ) : '';
		$known_scopes   = IAWM_Settings::known_scopes();
		$candidate_users = get_users( array( 'fields' => array( 'ID', 'user_login', 'display_name' ), 'orderby' => 'display_name', 'order' => 'ASC' ) );

		if ( empty( $all ) ) {
			?>
			<div class="iawm-empty-state">
				<h3><?php esc_html_e( 'No API key yet', 'ia-webmaster-bridge' ); ?></h3>
				<p><?php esc_html_e( 'Create one to let Claude Code reach this site.', 'ia-webmaster-bridge' ); ?></p>
			</div>
			<?php
		}
		?>

		<?php foreach ( $all as $key_id => $entry ) :
			$scopes_field = array_key_exists( 'scopes', $entry ) ? $entry['scopes'] : null;
			$linked_id    = isset( $entry['linked_user_id'] ) ? (int) $entry['linked_user_id'] : 0;
			$linked_user  = $linked_id > 0 ? get_userdata( $linked_id ) : null;
			$is_highlight = ( $highlight_key === $key_id );
			$status_class = self::key_status_class( $entry );
			?>
			<details class="iawm-key-row<?php echo $is_highlight ? ' is-highlight' : ''; ?>"<?php echo $is_highlight ? ' open' : ''; ?>>
				<summary class="iawm-key-summary" style="list-style:none;cursor:pointer;">
					<div class="iawm-key-status <?php echo esc_attr( $status_class ); ?>" title="<?php echo esc_attr( self::key_status_title( $entry ) ); ?>"></div>
					<div>
						<div class="iawm-key-label"><?php echo esc_html( $entry['label'] ?? __( 'Unnamed', 'ia-webmaster-bridge' ) ); ?></div>
						<div class="iawm-key-id"><?php echo esc_html( $key_id ); ?></div>
					</div>
					<div class="iawm-key-scopes">
						<?php echo self::render_scope_badges( $scopes_field ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<div style="font-size:12px;color:#646970;">
						<?php if ( $linked_user instanceof WP_User ) : ?>
							👤 <?php echo esc_html( $linked_user->display_name ); ?>
						<?php else : ?>
							<span style="color:#a7aaad;"><?php esc_html_e( 'no linked user', 'ia-webmaster-bridge' ); ?></span>
						<?php endif; ?>
					</div>
					<div style="font-size:12px;color:#646970;text-align:right;">
						<?php echo esc_html( self::format_last_used( $entry ) ); ?>
					</div>
				</summary>

				<div class="iawm-key-details">
					<?php if ( $is_highlight && isset( $entry['secret'] ) ) : ?>
						<div class="iawm-secret-display">
							<p><?php esc_html_e( '⚠️ Secret (copy now — it will not be shown again):', 'ia-webmaster-bridge' ); ?></p>
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $entry['secret'] ); ?>" onclick="this.select();">
						</div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
						<input type="hidden" name="iawm_tab" value="keys">
						<?php wp_nonce_field( self::ACTION ); ?>

						<fieldset>
							<legend><strong><?php esc_html_e( 'Label & linked user', 'ia-webmaster-bridge' ); ?></strong></legend>
							<p>
								<label><?php esc_html_e( 'Label', 'ia-webmaster-bridge' ); ?>
									<input type="text" name="iawm_label" value="<?php echo esc_attr( $entry['label'] ?? '' ); ?>" class="regular-text">
								</label>
								<label style="margin-left:1em;"><?php esc_html_e( 'Linked WP user (audit only)', 'ia-webmaster-bridge' ); ?>
									<select name="iawm_linked_user">
										<option value="0"><?php esc_html_e( '— none —', 'ia-webmaster-bridge' ); ?></option>
										<?php foreach ( $candidate_users as $cu ) : ?>
											<option value="<?php echo (int) $cu->ID; ?>" <?php selected( $linked_id, (int) $cu->ID ); ?>>
												<?php echo esc_html( $cu->display_name . ' (' . $cu->user_login . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</label>
							</p>
							<button type="submit" name="iawm_op" value="update_metadata" class="button"><?php esc_html_e( 'Save label / user', 'ia-webmaster-bridge' ); ?></button>
						</fieldset>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px;">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
						<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
						<input type="hidden" name="iawm_tab" value="keys">
						<?php wp_nonce_field( self::ACTION ); ?>
						<fieldset>
							<legend><strong><?php esc_html_e( 'Scopes', 'ia-webmaster-bridge' ); ?></strong></legend>
							<?php foreach ( $known_scopes as $scope => $label ) :
								$checked = null === $scopes_field ? true : in_array( $scope, (array) $scopes_field, true );
								?>
								<label>
									<input type="checkbox" name="iawm_scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( $checked ); ?>>
									<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<?php if ( null === $scopes_field ) : ?>
								<p style="color:#856404;font-size:12px;margin-top:8px;">
									<?php esc_html_e( '⚠️ Legacy key without an explicit scope list (full access). Tick / untick + save to lock down.', 'ia-webmaster-bridge' ); ?>
								</p>
							<?php endif; ?>
						</fieldset>
						<button type="submit" name="iawm_op" value="update_scopes" class="button"><?php esc_html_e( 'Save scopes', 'ia-webmaster-bridge' ); ?></button>
					</form>

					<div class="iawm-key-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
							<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
							<input type="hidden" name="iawm_tab" value="keys">
							<?php wp_nonce_field( self::ACTION ); ?>
							<button type="submit" name="iawm_op" value="rotate_secret" class="button" onclick="return confirm('<?php echo esc_js( __( 'Rotate the secret of this key? The gateway will need to be reconfigured.', 'ia-webmaster-bridge' ) ); ?>');"><?php esc_html_e( 'Rotate secret', 'ia-webmaster-bridge' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
							<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
							<input type="hidden" name="iawm_tab" value="keys">
							<?php wp_nonce_field( self::ACTION ); ?>
							<button type="submit" name="iawm_op" value="revoke_key" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke this key permanently?', 'ia-webmaster-bridge' ) ); ?>');"><?php esc_html_e( 'Revoke this key', 'ia-webmaster-bridge' ); ?></button>
						</form>
					</div>
				</div>
			</details>
		<?php endforeach; ?>

		<div class="iawm-card" style="margin-top:24px;">
			<h2 class="iawm-card-title"><?php esc_html_e( 'Create a new key', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help">
				<?php
				printf(
					/* translators: %s: path to the gateway configuration file. */
					wp_kses(
						__( 'After clicking Generate, the secret will be shown <strong>once</strong> in the key&#8217;s row above. Copy it into %s on the operator&#8217;s machine.', 'ia-webmaster-bridge' ),
						array( 'strong' => array() )
					),
					'<code>~/.iawm/config.json</code>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="keys">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="iawm_label"><?php esc_html_e( 'Label', 'ia-webmaster-bridge' ); ?></label></th>
						<td><input id="iawm_label" type="text" name="iawm_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Alice — content team', 'ia-webmaster-bridge' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="iawm_linked_user"><?php esc_html_e( 'Linked WP user', 'ia-webmaster-bridge' ); ?></label></th>
						<td>
							<select id="iawm_linked_user" name="iawm_linked_user">
								<option value="0"><?php esc_html_e( '— none —', 'ia-webmaster-bridge' ); ?></option>
								<?php foreach ( $candidate_users as $cu ) : ?>
									<option value="<?php echo (int) $cu->ID; ?>"><?php echo esc_html( $cu->display_name . ' (' . $cu->user_login . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: login name of the dedicated agent WordPress user (rendered as <code>iawm-agent</code>). */
										__( 'Audit-only — every write still executes as %s.', 'ia-webmaster-bridge' ),
										'<code>' . esc_html( IAWM_Agent_User::USER_LOGIN ) . '</code>'
									),
									array( 'code' => array() )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scopes', 'ia-webmaster-bridge' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $known_scopes as $scope => $label ) : ?>
									<label>
										<input type="checkbox" name="iawm_scopes[]" value="<?php echo esc_attr( $scope ); ?>" checked>
										<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" name="iawm_op" value="create_key" class="button button-primary"><?php esc_html_e( 'Generate key', 'ia-webmaster-bridge' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tab: agent                                                         */
	/* ----------------------------------------------------------------- */

	private static function render_tab_agent() {
		$agent_id   = class_exists( 'IAWM_Agent_User' ) ? IAWM_Agent_User::get_user_id() : 0;
		$agent_user = $agent_id > 0 ? get_userdata( $agent_id ) : null;
		?>
		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'Dedicated agent user', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help"><?php esc_html_e( 'The adapter performs every write under this single WordPress user, regardless of which API key signed the request. The user has a restricted role with the highest-risk capabilities stripped out.', 'ia-webmaster-bridge' ); ?></p>

			<?php if ( $agent_user instanceof WP_User ) : ?>
				<table class="iawm-help-table">
					<tr><th><?php esc_html_e( 'Login', 'ia-webmaster-bridge' ); ?></th><td><strong><?php echo esc_html( $agent_user->user_login ); ?></strong></td></tr>
					<tr><th><?php esc_html_e( 'User ID', 'ia-webmaster-bridge' ); ?></th><td><code><?php echo (int) $agent_id; ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Role', 'ia-webmaster-bridge' ); ?></th><td><code><?php echo esc_html( IAWM_Agent_User::ROLE_KEY ); ?></code></td></tr>
					<tr><th><?php esc_html_e( 'Status', 'ia-webmaster-bridge' ); ?></th><td><span class="iawm-pill iawm-pill-ok"><?php esc_html_e( 'healthy', 'ia-webmaster-bridge' ); ?></span></td></tr>
				</table>
				<p style="font-size:12px;color:#646970;">
					<?php
					echo wp_kses(
						/* translators: HTTP 403 error code (iawm_protected_user) shown verbatim. */
						__( 'This user cannot be modified or deleted through the API (HTTP 403 <code>iawm_protected_user</code>).', 'ia-webmaster-bridge' ),
						array( 'code' => array() )
					);
					?>
				</p>
			<?php else : ?>
				<p><span class="iawm-pill iawm-pill-danger"><?php esc_html_e( 'missing', 'ia-webmaster-bridge' ); ?></span> <?php esc_html_e( 'The dedicated agent user could not be located. Click below to (re)install.', 'ia-webmaster-bridge' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="agent">
				<?php wp_nonce_field( self::ACTION ); ?>
				<button type="submit" name="iawm_op" value="reinstall_agent" class="button"><?php esc_html_e( 'Reinstall agent role & user', 'ia-webmaster-bridge' ); ?></button>
				<p class="description"><?php esc_html_e( 'Idempotent: re-registers the role with the current capability set and re-creates the user if it was removed.', 'ia-webmaster-bridge' ); ?></p>
			</form>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tab: security                                                      */
	/* ----------------------------------------------------------------- */

	private static function render_tab_security() {
		$kill          = IAWM_Settings::is_kill_switch_on();
		$require_https = defined( 'IAWM_REQUIRE_HTTPS' ) && IAWM_REQUIRE_HTTPS;
		$is_https      = class_exists( 'IAWM_Network' ) && IAWM_Network::is_https();
		$allowlist     = class_exists( 'IAWM_Network' ) ? IAWM_Network::get_allowlist() : array();
		?>
		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'Kill switch', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help"><?php esc_html_e( 'Blocks ALL write requests. Reads remain allowed. Use it during an incident or when you want to pause the agent.', 'ia-webmaster-bridge' ); ?></p>
			<p>
				<?php esc_html_e( 'State:', 'ia-webmaster-bridge' ); ?>
				<strong>
					<?php if ( $kill ) : ?>
						<span class="iawm-pill iawm-pill-danger"><?php esc_html_e( 'ON — writes blocked', 'ia-webmaster-bridge' ); ?></span>
					<?php else : ?>
						<span class="iawm-pill iawm-pill-ok"><?php esc_html_e( 'off — writes allowed', 'ia-webmaster-bridge' ); ?></span>
					<?php endif; ?>
				</strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="security">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php if ( $kill ) : ?>
					<button type="submit" name="iawm_op" value="kill_off" class="button button-primary iawm-kill-switch"><?php esc_html_e( 'Re-enable writes', 'ia-webmaster-bridge' ); ?></button>
				<?php else : ?>
					<button type="submit" name="iawm_op" value="kill_on" class="button iawm-kill-switch"><?php esc_html_e( 'Block all writes', 'ia-webmaster-bridge' ); ?></button>
				<?php endif; ?>
			</form>
		</div>

		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'HTTPS enforcement', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help">
				<?php
				echo wp_kses(
					/* translators: HTML <code> tags wrap PHP constant + file names; left verbatim. */
					__( 'When the <code>IAWM_REQUIRE_HTTPS</code> constant is set to <code>true</code> in <code>wp-config.php</code>, non-HTTPS API calls are refused before any signature work. Configuration via a constant (not an admin toggle) prevents a compromised admin from disabling the protection.', 'ia-webmaster-bridge' ),
					array( 'code' => array() )
				);
				?>
			</p>
			<table class="iawm-help-table">
				<tr><th>IAWM_REQUIRE_HTTPS</th><td>
					<?php if ( $require_https ) : ?>
						<span class="iawm-pill iawm-pill-ok"><?php esc_html_e( 'true (enforced)', 'ia-webmaster-bridge' ); ?></span>
					<?php else : ?>
						<span class="iawm-pill iawm-pill-neutral"><?php esc_html_e( 'not set (any scheme accepted)', 'ia-webmaster-bridge' ); ?></span>
					<?php endif; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Current page over HTTPS', 'ia-webmaster-bridge' ); ?></th><td>
					<?php if ( $is_https ) : ?>
						<span class="iawm-pill iawm-pill-ok"><?php esc_html_e( 'yes', 'ia-webmaster-bridge' ); ?></span>
					<?php else : ?>
						<span class="iawm-pill iawm-pill-warn"><?php esc_html_e( 'no', 'ia-webmaster-bridge' ); ?></span>
					<?php endif; ?>
				</td></tr>
			</table>
			<?php if ( ! $require_https ) : ?>
				<p style="font-size:12px;">
					<?php
					echo wp_kses(
						/* translators: Instructional sentence pointing to wp-config.php. */
						__( '<strong>To enforce HTTPS</strong>, add this line to <code>wp-config.php</code>:', 'ia-webmaster-bridge' ),
						array(
							'strong' => array(),
							'code'   => array(),
						)
					);
					?>
				</p>
				<pre style="background:#f0f0f1;padding:8px;font-size:12px;">define( 'IAWM_REQUIRE_HTTPS', true );</pre>
			<?php endif; ?>
		</div>

		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'IP allow-list', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help">
				<?php
				echo wp_kses(
					/* translators: <code> tags wrap example IP addresses (kept verbatim). */
					__( 'One entry per line. Each entry can be an IP literal (<code>198.51.100.42</code>, <code>2001:db8::1</code>) or a CIDR block (<code>192.168.1.0/24</code>, <code>2001:db8::/32</code>). Empty list = allow all. Loopback (<code>127.0.0.1</code>, <code>::1</code>) is always allowed.', 'ia-webmaster-bridge' ),
					array( 'code' => array() )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="security">
				<?php wp_nonce_field( self::ACTION ); ?>
				<textarea name="iawm_allowlist_raw" rows="8" cols="60" class="large-text code" placeholder="<?php echo esc_attr( __( "# One IP or CIDR per line.\n# Empty list = allow all.\n192.168.1.0/24", 'ia-webmaster-bridge' ) ); ?>"><?php echo esc_textarea( implode( "\n", $allowlist ) ); ?></textarea>
				<p><button type="submit" name="iawm_op" value="save_allowlist" class="button button-primary"><?php esc_html_e( 'Save allow-list', 'ia-webmaster-bridge' ); ?></button></p>
			</form>
			<?php if ( ! empty( $allowlist ) ) : ?>
				<p style="font-size:12px;color:#646970;">
					<?php
					printf(
						/* translators: %d: number of allow-list entries. */
						esc_html( _n( 'Currently %d entry active.', 'Currently %d entries active.', (int) count( $allowlist ), 'ia-webmaster-bridge' ) ),
						(int) count( $allowlist )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tab: cleanup                                                       */
	/* ----------------------------------------------------------------- */

	private static function render_tab_cleanup() {
		global $wpdb;
		$audit_days = IAWM_Audit::get_retention_days();
		$backup_n   = IAWM_Backup::get_retention_n();
		$next_audit = wp_next_scheduled( IAWM_Audit::PRUNE_HOOK );
		$next_backup= wp_next_scheduled( IAWM_Backup::PRUNE_HOOK );
		$audit_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IAWM_Audit::table_name() );
		$backup_rows= (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IAWM_Backup::table_name() );
		?>
		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'Retention policy', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help"><?php esc_html_e( 'Two daily cron jobs trim the audit log and the backup table to the limits below. Defaults: 90 days of audit, 50 backups.', 'ia-webmaster-bridge' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="cleanup">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="iawm_audit_days"><?php esc_html_e( 'Keep audit entries for (days)', 'ia-webmaster-bridge' ); ?></label></th>
						<td>
							<input id="iawm_audit_days" type="number" name="iawm_audit_days" value="<?php echo (int) $audit_days; ?>" min="1" max="3650" class="small-text">
							<p class="description">
								<?php
								$next_audit_str = $next_audit
									? '<code>' . esc_html( wp_date( 'Y-m-d H:i', $next_audit ) ) . '</code>'
									: '<em>' . esc_html__( 'not scheduled', 'ia-webmaster-bridge' ) . '</em>';
								echo wp_kses(
									sprintf(
										/* translators: 1: current number of audit entries, 2: timestamp of the next prune or "not scheduled". */
										__( 'Currently <strong>%1$d</strong> entries. Next prune: %2$s.', 'ia-webmaster-bridge' ),
										(int) $audit_rows,
										$next_audit_str
									),
									array(
										'strong' => array(),
										'em'     => array(),
										'code'   => array(),
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="iawm_backup_keep"><?php esc_html_e( 'Keep newest N backups', 'ia-webmaster-bridge' ); ?></label></th>
						<td>
							<input id="iawm_backup_keep" type="number" name="iawm_backup_keep" value="<?php echo (int) $backup_n; ?>" min="1" max="10000" class="small-text">
							<p class="description">
								<?php
								$next_backup_str = $next_backup
									? '<code>' . esc_html( wp_date( 'Y-m-d H:i', $next_backup ) ) . '</code>'
									: '<em>' . esc_html__( 'not scheduled', 'ia-webmaster-bridge' ) . '</em>';
								echo wp_kses(
									sprintf(
										/* translators: 1: current number of backup records, 2: timestamp of the next prune or "not scheduled". */
										__( 'Currently <strong>%1$d</strong> backup records. Next prune: %2$s.', 'ia-webmaster-bridge' ),
										(int) $backup_rows,
										$next_backup_str
									),
									array(
										'strong' => array(),
										'em'     => array(),
										'code'   => array(),
									)
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<p><button type="submit" name="iawm_op" value="save_retention" class="button button-primary"><?php esc_html_e( 'Save retention', 'ia-webmaster-bridge' ); ?></button></p>
			</form>
		</div>

		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'Prune now', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help"><?php esc_html_e( 'Manually run the retention jobs without waiting for the next cron tick.', 'ia-webmaster-bridge' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="cleanup">
				<?php wp_nonce_field( self::ACTION ); ?>
				<button type="submit" name="iawm_op" value="prune_audit_now" class="button"><?php esc_html_e( 'Prune audit log now', 'ia-webmaster-bridge' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="cleanup">
				<?php wp_nonce_field( self::ACTION ); ?>
				<button type="submit" name="iawm_op" value="prune_backups_now" class="button"><?php esc_html_e( 'Prune backups now', 'ia-webmaster-bridge' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tab: audit                                                         */
	/* ----------------------------------------------------------------- */

	private static function render_tab_audit() {
		$entries = IAWM_Audit::get_recent( 30 );
		?>
		<div class="iawm-card">
			<h2 class="iawm-card-title"><?php esc_html_e( 'Recent activity (last 30)', 'ia-webmaster-bridge' ); ?></h2>
			<p class="iawm-card-help">
				<?php
				echo wp_kses(
					/* translators: <code> tags wrap the MCP tool name and the SQL table name (kept verbatim). */
					__( 'Full audit available via <code>iawm_audit</code> MCP tool or the <code>wp_iawm_audit_log</code> table.', 'ia-webmaster-bridge' ),
					array( 'code' => array() )
				);
				?>
			</p>
			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No entries yet.', 'ia-webmaster-bridge' ); ?></p>
			<?php else : ?>
				<table class="widefat striped iawm-audit-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'ia-webmaster-bridge' ); ?></th>
							<th><?php esc_html_e( 'Method', 'ia-webmaster-bridge' ); ?></th>
							<th><?php esc_html_e( 'Route', 'ia-webmaster-bridge' ); ?></th>
							<th><?php esc_html_e( 'Status', 'ia-webmaster-bridge' ); ?></th>
							<th><?php esc_html_e( 'Key', 'ia-webmaster-bridge' ); ?></th>
							<th><?php esc_html_e( 'IP', 'ia-webmaster-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $row ) :
							$detail = is_string( $row['detail'] ) ? json_decode( $row['detail'], true ) : $row['detail'];
							$label  = is_array( $detail ) && ! empty( $detail['key_label'] ) ? $detail['key_label'] : '';
							?>
							<tr>
								<td style="white-space:nowrap;font-size:12px;"><?php echo esc_html( $row['created_at'] ); ?></td>
								<td><code><?php echo esc_html( $row['method'] ); ?></code></td>
								<td style="font-size:12px;"><?php echo esc_html( $row['route'] ); ?></td>
								<td><span class="outcome-<?php echo esc_attr( $row['outcome'] ); ?>"><?php echo (int) $row['status']; ?> <?php echo esc_html( $row['outcome'] ); ?></span></td>
								<td style="font-size:12px;"><?php echo esc_html( $label ?: $row['key_id'] ); ?></td>
								<td style="font-size:12px;font-family:monospace;"><?php echo esc_html( $row['ip'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Tab: tools                                                         */
	/* ----------------------------------------------------------------- */

	private static function render_tab_tools() {
		?>
		<div class="iawm-tools-grid">
			<div class="iawm-tool-card">
				<h4><?php esc_html_e( '📋 Documentation', 'ia-webmaster-bridge' ); ?></h4>
				<p style="font-size:13px;color:#646970;"><?php esc_html_e( 'Operations runbook, security model, design system primer.', 'ia-webmaster-bridge' ); ?></p>
				<p>
					<a class="button" target="_blank" rel="noopener" href="https://github.com/RiusmaX/ia-webmaster-bridge/blob/main/docs/operations.md"><?php esc_html_e( 'Operations', 'ia-webmaster-bridge' ); ?></a>
					<a class="button" target="_blank" rel="noopener" href="https://github.com/RiusmaX/ia-webmaster-bridge/blob/main/docs/design-system.md"><?php esc_html_e( 'Design system', 'ia-webmaster-bridge' ); ?></a>
				</p>
			</div>
			<div class="iawm-tool-card">
				<h4><?php esc_html_e( '🔍 Diagnostics', 'ia-webmaster-bridge' ); ?></h4>
				<p style="font-size:13px;color:#646970;">
					<?php
					echo wp_kses(
						/* translators: <code> tags wrap MCP tool names (kept verbatim). */
						__( 'Run from Claude Code: <code>iawm_diagnostics_smoke</code> after any destructive op, <code>iawm_diagnostics_check_self</code> after upgrades.', 'ia-webmaster-bridge' ),
						array( 'code' => array() )
					);
					?>
				</p>
			</div>
			<div class="iawm-tool-card">
				<h4><?php esc_html_e( '♻ Reinstall agent', 'ia-webmaster-bridge' ); ?></h4>
				<p style="font-size:13px;color:#646970;"><?php esc_html_e( 'Recreates the dedicated user + role.', 'ia-webmaster-bridge' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="iawm_tab" value="tools">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" name="iawm_op" value="reinstall_agent" class="button"><?php esc_html_e( 'Reinstall', 'ia-webmaster-bridge' ); ?></button>
				</form>
			</div>
		</div>

		<div class="iawm-danger-zone" style="margin-top:32px;">
			<h3><?php esc_html_e( '⚠️ Danger zone', 'ia-webmaster-bridge' ); ?></h3>
			<p><?php esc_html_e( 'Revokes every API key on this site at once. The corresponding Claude sessions lose access immediately.', 'ia-webmaster-bridge' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="iawm_tab" value="tools">
				<?php wp_nonce_field( self::ACTION ); ?>
				<button type="submit" name="iawm_op" value="revoke_all" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke ALL keys? This cannot be undone.', 'ia-webmaster-bridge' ) ); ?>');"><?php esc_html_e( 'Revoke ALL keys', 'ia-webmaster-bridge' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ----------------------------------------------------------------- */
	/* Helpers                                                            */
	/* ----------------------------------------------------------------- */

	/**
	 * Maps a key entry to a status CSS class.
	 *
	 * @param array $entry Key record.
	 * @return string
	 */
	private static function key_status_class( $entry ) {
		$last = $entry['last_used_at'] ?? null;
		if ( empty( $last ) ) {
			return 'is-unused';
		}
		$last_ts = strtotime( (string) $last );
		if ( $last_ts && ( time() - $last_ts ) > 30 * DAY_IN_SECONDS ) {
			return 'is-idle';
		}
		return 'is-active';
	}

	/**
	 * Human-readable tooltip for the status dot.
	 *
	 * @param array $entry Key record.
	 * @return string
	 */
	private static function key_status_title( $entry ) {
		$last = $entry['last_used_at'] ?? null;
		if ( empty( $last ) ) {
			return __( 'Never used yet', 'ia-webmaster-bridge' );
		}
		/* translators: %s: timestamp at which the API key was last used. */
		return sprintf( __( 'Last used at %s', 'ia-webmaster-bridge' ), (string) $last );
	}

	/**
	 * Formats the "last used" cell for the key summary row.
	 *
	 * @param array $entry Key record.
	 * @return string
	 */
	private static function format_last_used( $entry ) {
		if ( empty( $entry['last_used_at'] ) ) {
			return __( 'never used', 'ia-webmaster-bridge' );
		}
		$ts = strtotime( (string) $entry['last_used_at'] );
		if ( ! $ts ) {
			return (string) $entry['last_used_at'];
		}
		$delta = time() - $ts;
		if ( $delta < 60 ) {
			return __( 'just now', 'ia-webmaster-bridge' );
		}
		if ( $delta < HOUR_IN_SECONDS ) {
			$minutes = (int) floor( $delta / 60 );
			/* translators: %d: number of minutes elapsed since the key was last used. */
			return sprintf( _n( '%d min ago', '%d min ago', $minutes, 'ia-webmaster-bridge' ), $minutes );
		}
		if ( $delta < DAY_IN_SECONDS ) {
			$hours = (int) floor( $delta / HOUR_IN_SECONDS );
			/* translators: %d: number of hours elapsed since the key was last used. */
			return sprintf( _n( '%d h ago', '%d h ago', $hours, 'ia-webmaster-bridge' ), $hours );
		}
		$days = (int) floor( $delta / DAY_IN_SECONDS );
		/* translators: %d: number of days elapsed since the key was last used. */
		return sprintf( _n( '%d d ago', '%d d ago', $days, 'ia-webmaster-bridge' ), $days );
	}

	/**
	 * Renders the scope badges row for a key.
	 *
	 * @param array|null $scopes Scope list, or null for full access.
	 * @return string HTML.
	 */
	private static function render_scope_badges( $scopes ) {
		if ( null === $scopes ) {
			return '<span class="iawm-scope-badge iawm-scope-all">' . esc_html__( 'all (legacy)', 'ia-webmaster-bridge' ) . '</span>';
		}
		if ( empty( $scopes ) ) {
			return '<span class="iawm-scope-badge iawm-scope-read">' . esc_html__( '(no scope)', 'ia-webmaster-bridge' ) . '</span>';
		}
		$out = '';
		foreach ( $scopes as $scope ) {
			$cls = 'iawm-scope-' . str_replace( ':', '-', $scope );
			$out .= sprintf(
				'<span class="iawm-scope-badge %s">%s</span>',
				esc_attr( $cls ),
				esc_html( $scope )
			);
		}
		return $out;
	}

	/**
	 * Renders the confirmation message corresponding to an action.
	 *
	 * @param string $notice Message key.
	 * @return void
	 */
	private static function render_notice( $notice ) {
		$rejected = isset( $_GET['rejected_count'] ) ? (int) $_GET['rejected_count'] : 0;
		$pruned   = isset( $_GET['pruned'] ) ? (int) $_GET['pruned'] : 0;
		$messages = array(
			'key_created'       => array( 'success', __( 'New key created. The secret is shown once in its row below — copy it now, it cannot be displayed again.', 'ia-webmaster-bridge' ) ),
			'secret_rotated'    => array( 'success', __( 'Secret rotated. Copy the new value into the gateway config.', 'ia-webmaster-bridge' ) ),
			'scopes_updated'    => array( 'success', __( 'Scopes updated for this key.', 'ia-webmaster-bridge' ) ),
			'metadata_updated'  => array( 'success', __( 'Label / linked user updated.', 'ia-webmaster-bridge' ) ),
			'key_revoked'       => array( 'warning', __( 'Key revoked.', 'ia-webmaster-bridge' ) ),
			'all_revoked'       => array( 'warning', __( 'All keys revoked. No Claude session can authenticate until a new key is created.', 'ia-webmaster-bridge' ) ),
			'kill_on'           => array( 'warning', __( 'Kill switch enabled: all writes are blocked.', 'ia-webmaster-bridge' ) ),
			'kill_off'          => array( 'success', __( 'Kill switch disabled: writes are allowed again.', 'ia-webmaster-bridge' ) ),
			'agent_installed'   => array( 'success', __( 'Agent role and user reinstalled.', 'ia-webmaster-bridge' ) ),
			'allowlist_saved'   => array( 'success', __( 'IP allow-list saved.', 'ia-webmaster-bridge' ) ),
			'allowlist_partial' => array(
				'warning',
				sprintf(
					/* translators: %d: number of invalid allow-list entries that were dropped. */
					_n( 'IP allow-list saved with %d invalid entry dropped.', 'IP allow-list saved with %d invalid entries dropped.', $rejected, 'ia-webmaster-bridge' ),
					$rejected
				),
			),
			'retention_saved'   => array( 'success', __( 'Retention policy saved.', 'ia-webmaster-bridge' ) ),
			'audit_pruned'      => array(
				'success',
				sprintf(
					/* translators: %d: number of audit-log rows deleted. */
					_n( 'Audit log pruned: %d row deleted.', 'Audit log pruned: %d rows deleted.', $pruned, 'ia-webmaster-bridge' ),
					$pruned
				),
			),
			'backups_pruned'    => array(
				'success',
				sprintf(
					/* translators: %d: number of backup records deleted. */
					_n( 'Backups pruned: %d record deleted.', 'Backups pruned: %d records deleted.', $pruned, 'ia-webmaster-bridge' ),
					$pruned
				),
			),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
