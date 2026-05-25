<?php
/**
 * Admin interface for the IA Webmaster Bridge plugin.
 *
 * "Settings -> IA Webmaster Bridge" page: management of the API secret,
 * its scopes, and the kill switch.
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

	/**
	 * Hooks up admin actions.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
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
	 * Handles form actions (generate / revoke / kill switch / update scopes).
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Access denied.' ) );
		}

		check_admin_referer( self::ACTION );

		$op     = isset( $_POST['iawm_op'] ) ? sanitize_key( wp_unslash( $_POST['iawm_op'] ) ) : '';
		$notice = 'none';

		switch ( $op ) {
			case 'generate':
				$scopes = self::scopes_from_post();
				IAWM_Settings::generate_credentials( $scopes );
				$notice = 'generated';
				break;
			case 'update_scopes':
				$scopes = self::scopes_from_post();
				IAWM_Settings::update_scopes( $scopes );
				$notice = 'scopes_updated';
				break;
			case 'revoke':
				IAWM_Settings::revoke_credentials();
				$notice = 'revoked';
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
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'iawm_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Reads the submitted list of scopes from $_POST['iawm_scopes'].
	 *
	 * A submitted form with no boxes ticked returns an empty array
	 * (read-only-but-empty key). To restore a fully-scoped key, the
	 * caller passes null instead.
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
			wp_die( esc_html( 'Access denied.' ) );
		}

		$creds       = IAWM_Settings::get_credentials();
		$kill_switch = IAWM_Settings::is_kill_switch_on();
		$api_base    = get_rest_url( null, IAWM_REST_NAMESPACE );
		$notice      = isset( $_GET['iawm_notice'] ) ? sanitize_key( wp_unslash( $_GET['iawm_notice'] ) ) : '';
		$known_scopes = IAWM_Settings::known_scopes();
		$current_scopes = IAWM_Settings::get_scopes();
		$agent_user_id = class_exists( 'IAWM_Agent_User' ) ? IAWM_Agent_User::get_user_id() : 0;
		$agent_user    = $agent_user_id > 0 ? get_userdata( $agent_user_id ) : null;
		?>
		<div class="wrap">
			<h1>IA Webmaster Bridge</h1>
			<p>Adapter version <?php echo esc_html( IAWM_VERSION ); ?>. This page manages the AI agent's authentication, scopes and the kill switch.</p>

			<?php self::render_notice( $notice ); ?>

			<h2>API credentials</h2>
			<?php if ( $creds ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">API base URL</th>
						<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $api_base ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Key identifier</th>
						<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $creds['key_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Secret</th>
						<td>
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $creds['secret'] ); ?>">
							<p class="description">To be copied into the MCP bridge configuration. Never share or commit it.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Created on</th>
						<td><?php echo esc_html( $creds['created_at'] ); ?></td>
					</tr>
				</table>

				<h3>Scopes granted to this key</h3>
				<?php if ( null === $current_scopes ) : ?>
					<p><em>Legacy key without an explicit scope list — full access. Update the scopes below to enforce least-privilege.</em></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<fieldset>
						<?php foreach ( $known_scopes as $scope => $label ) : ?>
							<?php
							$checked = null === $current_scopes
								? true
								: in_array( $scope, $current_scopes, true );
							?>
							<label style="display:block;margin:0.3em 0;">
								<input type="checkbox" name="iawm_scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( $checked ); ?>>
								<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p>
						<button type="submit" name="iawm_op" value="update_scopes" class="button button-primary">Save scopes</button>
						<button type="submit" name="iawm_op" value="generate" class="button" onclick="return confirm('Rotate the secret and apply these scopes to the new key?');">Rotate secret with these scopes</button>
						<button type="submit" name="iawm_op" value="revoke" class="button button-link-delete" onclick="return confirm('Revoke the API credentials? The MCP bridge will lose access.');">Revoke credentials</button>
					</p>
					<p class="description">Saving updates the scopes without changing the secret. Rotating regenerates the secret and applies the ticked scopes to the new key — the MCP bridge will need to be reconfigured.</p>
				</form>
			<?php else : ?>
				<p>No credentials configured. The authenticated API is unreachable until a secret has been generated.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<fieldset>
						<legend><strong>Scopes for the new key</strong></legend>
						<?php foreach ( $known_scopes as $scope => $label ) : ?>
							<label style="display:block;margin:0.3em 0;">
								<input type="checkbox" name="iawm_scopes[]" value="<?php echo esc_attr( $scope ); ?>" checked>
								<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p>
						<button type="submit" name="iawm_op" value="generate" class="button button-primary">Generate a secret</button>
					</p>
				</form>
			<?php endif; ?>

			<h2>Dedicated agent user</h2>
			<?php if ( $agent_user instanceof WP_User ) : ?>
				<p>
					The adapter performs all writes as <strong><?php echo esc_html( $agent_user->user_login ); ?></strong>
					(user ID <code><?php echo esc_html( $agent_user_id ); ?></code>, role <code><?php echo esc_html( IAWM_Agent_User::ROLE_KEY ); ?></code>).
					This user is created automatically and cannot be modified or deleted through the API.
				</p>
			<?php else : ?>
				<p>
					<strong>The dedicated agent user is missing.</strong>
					Reinstall it below; writes will otherwise fall back to the oldest administrator.
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<button type="submit" name="iawm_op" value="reinstall_agent" class="button">Reinstall agent role &amp; user</button>
				<p class="description">Idempotent: re-registers the <code>iawm_agent</code> role and re-creates the <code>iawm-agent</code> user if missing.</p>
			</form>

			<h2>Kill switch</h2>
			<p>
				Current state:
				<strong><?php echo $kill_switch ? 'ON - writes blocked' : 'off - writes allowed'; ?></strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php if ( $kill_switch ) : ?>
					<button type="submit" name="iawm_op" value="kill_off" class="button button-primary">Re-enable writes</button>
				<?php else : ?>
					<button type="submit" name="iawm_op" value="kill_on" class="button">Block writes</button>
				<?php endif; ?>
				<p class="description">The kill switch blocks all write requests from the agent. Reads remain allowed.</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the confirmation message corresponding to an action.
	 *
	 * @param string $notice Message key.
	 * @return void
	 */
	private static function render_notice( $notice ) {
		$messages = array(
			'generated'       => array( 'success', 'New secret generated. Copy it into the MCP bridge configuration.' ),
			'scopes_updated'  => array( 'success', 'Scopes updated. The secret was not rotated.' ),
			'revoked'         => array( 'warning', 'Credentials revoked: the agent can no longer authenticate.' ),
			'kill_on'         => array( 'warning', 'Kill switch enabled: all writes are blocked.' ),
			'kill_off'        => array( 'success', 'Kill switch disabled: writes are allowed again.' ),
			'agent_installed' => array( 'success', 'Agent role and user reinstalled.' ),
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
