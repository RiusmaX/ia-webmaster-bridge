<?php
/**
 * Admin interface for the IA Webmaster Bridge plugin.
 *
 * "Settings -> IA Webmaster Bridge" page: management of the API secret and the
 * kill switch.
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
	 * Handles form actions (generate / revoke / kill switch).
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
				IAWM_Settings::generate_credentials();
				$notice = 'generated';
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
		?>
		<div class="wrap">
			<h1>IA Webmaster Bridge</h1>
			<p>Adapter version <?php echo esc_html( IAWM_VERSION ); ?>. This page manages the AI agent's authentication secret and the kill switch.</p>

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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" name="iawm_op" value="generate" class="button">Regenerate secret</button>
					<button type="submit" name="iawm_op" value="revoke" class="button button-link-delete">Revoke credentials</button>
					<p class="description">Regenerating or revoking immediately invalidates the current secret: the MCP bridge will need to be reconfigured.</p>
				</form>
			<?php else : ?>
				<p>No credentials configured. The authenticated API is unreachable until a secret has been generated.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" name="iawm_op" value="generate" class="button button-primary">Generate a secret</button>
				</form>
			<?php endif; ?>

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
			'generated' => array( 'success', 'New secret generated. Copy it into the MCP bridge configuration.' ),
			'revoked'   => array( 'warning', 'Credentials revoked: the agent can no longer authenticate.' ),
			'kill_on'   => array( 'warning', 'Kill switch enabled: all writes are blocked.' ),
			'kill_off'  => array( 'success', 'Kill switch disabled: writes are allowed again.' ),
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
