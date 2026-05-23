<?php
/**
 * Supprime les posts de test Theme Builder (et_theme_builder + et_template
 * + et_*_layout) créés pendant l'exploration.
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

foreach ( array( 'et_theme_builder', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout' ) as $pt ) {
    $posts = get_posts( array( 'post_type' => $pt, 'posts_per_page' => -1, 'post_status' => 'any' ) );
    foreach ( $posts as $p ) {
        echo "Suppression {$pt} id={$p->ID} ({$p->post_title})\n";
        wp_delete_post( $p->ID, true );
    }
}
echo "OK.\n";
