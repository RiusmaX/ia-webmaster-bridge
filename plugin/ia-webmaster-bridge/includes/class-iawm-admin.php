<?php
/**
 * Interface d'administration du plugin IA Webmaster Bridge.
 *
 * Page « Réglages → IA Webmaster Bridge » : gestion du secret d'API et du
 * kill switch.
 *
 * @package IA_Webmaster_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page de réglages et traitement des actions d'administration.
 */
class IAWM_Admin {

	/** Slug de la page de réglages. */
	const PAGE_SLUG = 'iawm-settings';

	/** Action admin-post traitant le formulaire. */
	const ACTION = 'iawm_action';

	/**
	 * Branche les hooks d'administration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Ajoute la page sous le menu « Réglages ».
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
	 * Traite les actions du formulaire (générer / révoquer / kill switch).
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Accès refusé.' ) );
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
	 * Affiche la page de réglages.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Accès refusé.' ) );
		}

		$creds       = IAWM_Settings::get_credentials();
		$kill_switch = IAWM_Settings::is_kill_switch_on();
		$api_base    = get_rest_url( null, IAWM_REST_NAMESPACE );
		$notice      = isset( $_GET['iawm_notice'] ) ? sanitize_key( wp_unslash( $_GET['iawm_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1>IA Webmaster Bridge</h1>
			<p>Adaptateur version <?php echo esc_html( IAWM_VERSION ); ?>. Cette page gère le secret d'authentification de l'agent IA et le kill switch.</p>

			<?php self::render_notice( $notice ); ?>

			<h2>Identifiants d'API</h2>
			<?php if ( $creds ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">URL de base de l'API</th>
						<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $api_base ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Identifiant de clé</th>
						<td><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $creds['key_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">Secret</th>
						<td>
							<input type="text" class="large-text code" readonly value="<?php echo esc_attr( $creds['secret'] ); ?>">
							<p class="description">À copier dans la configuration du pont MCP. Ne jamais le partager ni le versionner.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Créé le</th>
						<td><?php echo esc_html( $creds['created_at'] ); ?></td>
					</tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" name="iawm_op" value="generate" class="button">Régénérer le secret</button>
					<button type="submit" name="iawm_op" value="revoke" class="button button-link-delete">Révoquer les identifiants</button>
					<p class="description">Régénérer ou révoquer invalide immédiatement le secret actuel : le pont MCP devra être reconfiguré.</p>
				</form>
			<?php else : ?>
				<p>Aucun identifiant configuré. L'API authentifiée est inaccessible tant qu'un secret n'a pas été généré.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<?php wp_nonce_field( self::ACTION ); ?>
					<button type="submit" name="iawm_op" value="generate" class="button button-primary">Générer un secret</button>
				</form>
			<?php endif; ?>

			<h2>Kill switch</h2>
			<p>
				État actuel :
				<strong><?php echo $kill_switch ? 'ACTIF — écritures coupées' : 'inactif — écritures autorisées'; ?></strong>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php if ( $kill_switch ) : ?>
					<button type="submit" name="iawm_op" value="kill_off" class="button button-primary">Réactiver les écritures</button>
				<?php else : ?>
					<button type="submit" name="iawm_op" value="kill_on" class="button">Couper les écritures</button>
				<?php endif; ?>
				<p class="description">Le kill switch bloque toutes les requêtes en écriture de l'agent. Les lectures restent autorisées.</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Affiche le message de confirmation correspondant à une action.
	 *
	 * @param string $notice Clé du message.
	 * @return void
	 */
	private static function render_notice( $notice ) {
		$messages = array(
			'generated' => array( 'success', "Nouveau secret généré. Copiez-le dans la configuration du pont MCP." ),
			'revoked'   => array( 'warning', "Identifiants révoqués : l'agent ne peut plus s'authentifier." ),
			'kill_on'   => array( 'warning', 'Kill switch activé : toutes les écritures sont coupées.' ),
			'kill_off'  => array( 'success', 'Kill switch désactivé : les écritures sont de nouveau autorisées.' ),
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
