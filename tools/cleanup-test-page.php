<?php
/**
 * Supprime définitivement la page de test du round-trip (id 24).
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

$id = isset( $argv[1] ) ? (int) $argv[1] : 24;
$post = get_post( $id );
if ( ! $post ) {
    echo "Post {$id} introuvable.\n";
    exit( 0 );
}
echo "Suppression du post {$id} ({$post->post_title}, status={$post->post_status})...\n";
$res = wp_delete_post( $id, true );
echo ( false !== $res ) ? "Supprimé.\n" : "Échec.\n";
