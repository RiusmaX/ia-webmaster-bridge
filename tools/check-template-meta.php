<?php
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

$tid = isset( $argv[1] ) ? (int) $argv[1] : 69;
$meta = get_post_meta( $tid );
foreach ( $meta as $k => $v ) {
    if ( false !== strpos( $k, '_et_' ) || false !== strpos( $k, 'layout' ) ) {
        echo "{$k} = " . $v[0] . "\n";
    }
}
