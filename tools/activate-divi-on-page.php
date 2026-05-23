<?php
/**
 * Active Divi 5 sur un post donné (par défaut le post 19 — page de référence
 * "IAWM Divi Reference") en posant la meta `_et_pb_use_builder = 'on'`.
 * Affiche ensuite l'état du post + ses meta `_et_*` pour validation.
 *
 * Utilisation :
 *   php tools/activate-divi-on-page.php [POST_ID]
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

$post_id = isset( $argv[1] ) ? (int) $argv[1] : 19;
$post = get_post( $post_id );
if ( ! $post ) {
    fwrite( STDERR, "Post {$post_id} introuvable.\n" );
    exit( 1 );
}

echo "Post {$post_id} : {$post->post_title} (status={$post->post_status}, type={$post->post_type})\n";
echo "Meta AVANT :\n";
$meta_before = get_post_meta( $post_id );
foreach ( $meta_before as $k => $v ) {
    if ( strpos( $k, '_et_' ) === 0 || strpos( $k, '_iawm_' ) === 0 ) {
        echo "  {$k} = " . print_r( maybe_unserialize( $v[0] ), true );
    }
}

update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
// Version du builder Divi 5 — épingler pour cohérence.
update_post_meta( $post_id, '_et_pb_built_for_post_type', $post->post_type );

echo "\nMeta APRES :\n";
$meta_after = get_post_meta( $post_id );
foreach ( $meta_after as $k => $v ) {
    if ( strpos( $k, '_et_' ) === 0 || strpos( $k, '_iawm_' ) === 0 ) {
        echo "  {$k} = " . print_r( maybe_unserialize( $v[0] ), true );
    }
}

// Liens utiles
$edit_url = admin_url( "post.php?post={$post_id}&action=edit" );
$vb_url   = add_query_arg( 'et_fb', '1', get_permalink( $post_id ) );
echo "\nURL admin : {$edit_url}\n";
echo "URL Visual Builder (frontend) : {$vb_url}\n";
