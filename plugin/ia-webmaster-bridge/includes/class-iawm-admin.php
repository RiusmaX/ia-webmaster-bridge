<?php
/**
 * Admin interface for the IA Webmaster Bridge plugin.
 *
 * "Settings -> IA Webmaster Bridge" page: management of the API keys
 * (multi-key since v0.26.0), their scopes, optional linked WP user and
 * the kill switch.
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
	 * Handles form actions.
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
		$args   = array();

		switch ( $op ) {
			case 'create_key':
				$scopes = self::scopes_from_post();
				$label  = isset( $_POST['iawm_label'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_label'] ) ) : '';
				$linked = isset( $_POST['iawm_linked_user'] ) ? (int) $_POST['iawm_linked_user'] : 0;
				$created = IAWM_Settings::create_credentials( $scopes, $label, $linked > 0 ? $linked : null );
				$notice = 'key_created';
				// Surface the new key id once in the URL so the secret line
				// can be highlighted on return.
				$args['new_key'] = $created['key_id'];
				break;

			case 'rotate_secret':
				$key = isset( $_POST['iawm_key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['iawm_key_id'] ) ) : '';
				if ( '' !== $key ) {
					IAWM_Settings::rotate_secret( $key );
					$notice         = 'secret_rotated';
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
		}

		$args['page']        = self::PAGE_SLUG;
		$args['iawm_notice'] = $notice;
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
			wp_die( esc_html( 'Access denied.' ) );
		}

		$kill_switch    = IAWM_Settings::is_kill_switch_on();
		$api_base       = get_rest_url( null, IAWM_REST_NAMESPACE );
		$notice         = isset( $_GET['iawm_notice'] ) ? sanitize_key( wp_unslash( $_GET['iawm_notice'] ) ) : '';
		$highlight_key  = isset( $_GET['new_key'] ) ? sanitize_text_field( wp_unslash( $_GET['new_key'] ) ) : '';
		$known_scopes   = IAWM_Settings::known_scopes();
		$all_keys       = IAWM_Settings::all_credentials();
		$agent_user_id  = class_exists( 'IAWM_Agent_User' ) ? IAWM_Agent_User::get_user_id() : 0;
		$agent_user     = $agent_user_id > 0 ? get_userdata( $agent_user_id ) : null;
		$candidate_users = get_users(
			array(
				'fields'  => array( 'ID', 'user_login', 'display_name' ),
				'orderby' => 'display_name',
				'order'   => 'ASC',
			)
		);
		?>
		<div class="wrap">
			<h1>IA Webmaster Bridge</h1>
			<p>Adapter version <?php echo esc_html( IAWM_VERSION ); ?>. This page manages API keys (multiple keys supported), their scopes, optional linked WP user, and the kill switch.</p>

			<?php self::render_notice( $notice ); ?>

			<h2>API keys</h2>
			<?php if ( empty( $all_keys ) ) : ?>
				<p>No keys configured. The authenticated API is unreachable until at least one key is created.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th>Label</th>
							<th>Key id</th>
							<th>Scopes</th>
							<th>Linked WP user</th>
							<th>Created</th>
							<th>Last used</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $all_keys as $key_id => $entry ) :
						$scopes_field = array_key_exists( 'scopes', $entry ) ? $entry['scopes'] : null;
						$scopes_label = null === $scopes_field ? '* (full access)' : ( empty( $scopes_field ) ? '(none)' : implode( ', ', $scopes_field ) );
						$linked_id    = isset( $entry['linked_user_id'] ) ? (int) $entry['linked_user_id'] : 0;
						$linked_user  = $linked_id > 0 ? get_userdata( $linked_id ) : null;
						$is_highlight = ( $highlight_key === $key_id );
						?>
						<tr<?php echo $is_highlight ? ' style="background:#fff8c5;"' : ''; ?>>
							<td><strong><?php echo esc_html( isset( $entry['label'] ) ? $entry['label'] : 'Unnamed' ); ?></strong></td>
							<td><code><?php echo esc_html( $key_id ); ?></code></td>
							<td><?php echo esc_html( $scopes_label ); ?></td>
							<td><?php echo $linked_user instanceof WP_User ? esc_html( $linked_user->display_name . ' (#' . $linked_user->ID . ')' ) : '—'; ?></td>
							<td><?php echo esc_html( isset( $entry['created_at'] ) ? $entry['created_at'] : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $entry['last_used_at'] ) ? $entry['last_used_at'] : '—' ); ?></td>
							<td>
								<details>
									<summary>Manage</summary>
									<div style="padding:0.5em;border-left:3px solid #ccd0d4;margin-top:0.5em;">
										<?php if ( $is_highlight && isset( $entry['secret'] ) ) : ?>
											<p><strong>Secret (copy now — it won't be shown again):</strong></p>
											<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $entry['secret'] ); ?>" onclick="this.select();">
										<?php endif; ?>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.5em;">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
											<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
											<?php wp_nonce_field( self::ACTION ); ?>
											<p><strong>Label &amp; linked WP user</strong></p>
											<p>
												<label>Label
													<input type="text" name="iawm_label" value="<?php echo esc_attr( isset( $entry['label'] ) ? $entry['label'] : '' ); ?>">
												</label>
												<label style="margin-left:1em;">Linked WP user
													<select name="iawm_linked_user">
														<option value="0">— none —</option>
														<?php foreach ( $candidate_users as $cu ) : ?>
															<option value="<?php echo (int) $cu->ID; ?>" <?php selected( $linked_id, (int) $cu->ID ); ?>>
																<?php echo esc_html( $cu->display_name . ' (' . $cu->user_login . ')' ); ?>
															</option>
														<?php endforeach; ?>
													</select>
												</label>
											</p>
											<button type="submit" name="iawm_op" value="update_metadata" class="button">Save label / user</button>
										</form>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.7em;">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
											<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
											<?php wp_nonce_field( self::ACTION ); ?>
											<p><strong>Scopes</strong></p>
											<?php foreach ( $known_scopes as $scope => $label ) :
												$checked = null === $scopes_field ? true : in_array( $scope, (array) $scopes_field, true );
												?>
												<label style="display:block;margin:0.2em 0;">
													<input type="checkbox" name="iawm_scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( $checked ); ?>>
													<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?>
												</label>
											<?php endforeach; ?>
											<button type="submit" name="iawm_op" value="update_scopes" class="button">Save scopes</button>
										</form>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:0.7em;">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
											<input type="hidden" name="iawm_key_id" value="<?php echo esc_attr( $key_id ); ?>">
											<?php wp_nonce_field( self::ACTION ); ?>
											<button type="submit" name="iawm_op" value="rotate_secret" class="button" onclick="return confirm('Rotate the secret of this key? The MCP gateway will need to be reconfigured.');">Rotate secret</button>
											<button type="submit" name="iawm_op" value="revoke_key" class="button button-link-delete" onclick="return confirm('Revoke this key permanently?');">Revoke this key</button>
										</form>
									</div>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h3 style="margin-top:1.5em;">Create a new key</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Label</th>
						<td><input type="text" name="iawm_label" class="regular-text" placeholder="e.g. Alice — content team"></td>
					</tr>
					<tr>
						<th scope="row">Linked WP user (audit only)</th>
						<td>
							<select name="iawm_linked_user">
								<option value="0">— none —</option>
								<?php foreach ( $candidate_users as $cu ) : ?>
									<option value="<?php echo (int) $cu->ID; ?>">
										<?php echo esc_html( $cu->display_name . ' (' . $cu->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">The agent always executes as <code><?php echo esc_html( IAWM_Agent_User::USER_LOGIN ); ?></code>. The linked user only appears in the audit log so you can tell whose Claude triggered each call.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Scopes</th>
						<td>
							<fieldset>
								<?php foreach ( $known_scopes as $scope => $label ) : ?>
									<label style="display:block;margin:0.2em 0;">
										<input type="checkbox" name="iawm_scopes[]" value="<?php echo esc_attr( $scope ); ?>" checked>
										<code><?php echo esc_html( $scope ); ?></code> — <?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" name="iawm_op" value="create_key" class="button button-primary">Generate key</button>
					<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $api_base ); ?>">
				</p>
				<p class="description">After clicking <strong>Generate key</strong>, the secret will appear once on the page — copy it into <code>~/.iawm/config.json</code> on the operator's machine. It cannot be retrieved later.</p>
			</form>

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

			<?php if ( ! empty( $all_keys ) ) : ?>
				<h2>Danger zone</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" name="iawm_op" value="revoke_all" class="button button-link-delete" onclick="return confirm('Revoke ALL API keys? Every Claude session connected to this site will lose access immediately.');">Revoke ALL keys</button>
					<p class="description">Use this if a secret leak is suspected. You will need to re-create each key.</p>
				</form>
			<?php endif; ?>
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
			'key_created'      => array( 'success', 'New key created. The secret is shown below in its row — copy it now, it cannot be displayed again.' ),
			'secret_rotated'   => array( 'success', 'Secret rotated. The new value is shown below in the key row — copy it into the gateway config.' ),
			'scopes_updated'   => array( 'success', 'Scopes updated for this key.' ),
			'metadata_updated' => array( 'success', 'Label / linked user updated.' ),
			'key_revoked'      => array( 'warning', 'Key revoked. The corresponding Claude session can no longer authenticate.' ),
			'all_revoked'      => array( 'warning', 'All keys revoked. No Claude session can authenticate until a new key is created.' ),
			'kill_on'          => array( 'warning', 'Kill switch enabled: all writes are blocked.' ),
			'kill_off'         => array( 'success', 'Kill switch disabled: writes are allowed again.' ),
			'agent_installed'  => array( 'success', 'Agent role and user reinstalled.' ),
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
