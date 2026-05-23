<?php
/**
 * Test direct (sans HTTP) du module IAWM_Plugins : installation +
 * activation d'un plugin WP.org via les API WordPress.
 *
 * Utilisation :
 *   php tools/install-plugin-direct.php [slug] [activate]
 *   php tools/install-plugin-direct.php seo-by-rank-math 1
 */

$site_root = '~/wordpress-dev/Local Sites/ia-webmaster-bridge/app/public';
define( 'ABSPATH', $site_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_USE_THEMES', false );
define( 'DB_NAME', 'local' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1:10011' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wp_';
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $k ) {
    if ( ! defined( $k ) ) { define( $k, 'cli-placeholder' ); }
}

require ABSPATH . 'wp-settings.php';

$slug = isset( $argv[1] ) ? $argv[1] : 'seo-by-rank-math';
$do_activate = isset( $argv[2] ) ? (bool) $argv[2] : true;

echo "=== Test direct IAWM_Plugins::handle_install ===\n";
echo "slug = {$slug}\n";
echo "activate = " . ( $do_activate ? 'true' : 'false' ) . "\n\n";

// Construire une requête REST en mémoire (sans HTTP).
$request = new WP_REST_Request( 'POST', '/ia-webmaster/v1/plugins/install' );
$request->set_header( 'content-type', 'application/json' );
$request->set_body( json_encode( array( 'slug' => $slug, 'activate' => $do_activate ) ) );

// Bypass de l'auth : on appelle directement le handler.
echo "Appel de IAWM_Plugins::handle_install...\n";
$t0 = microtime( true );
$result = IAWM_Plugins::handle_install( $request );
$elapsed = round( ( microtime( true ) - $t0 ) * 1000 );
echo "Durée : {$elapsed}ms\n\n";

if ( is_wp_error( $result ) ) {
    echo "ERREUR : " . $result->get_error_code() . " — " . $result->get_error_message() . "\n";
    print_r( $result->get_error_data() );
    exit( 1 );
}

if ( $result instanceof WP_REST_Response ) {
    echo "HTTP " . $result->get_status() . "\n";
    print_r( $result->get_data() );
}
