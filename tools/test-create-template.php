<?php
/**
 * Crée un template Theme Builder de test via l'API Divi et inspecte
 * ce qui est posé en base. Permet de comprendre la structure.
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

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
wp_set_current_user( $admins[0]->ID );

function call_divi_route( $route, $body ) {
    $full_route = '/divi/v1/' . ltrim( $route, '/' );
    $nonce = wp_create_nonce( $full_route . '--POST' );
    $req = new WP_REST_Request( 'POST', $full_route );
    $req->set_header( 'Content-Type', 'application/json' );
    $req->set_header( 'X-ET-Nonce', $nonce );
    $req->set_body( wp_json_encode( $body ) );
    $r = rest_do_request( $req );
    return array( 'status' => $r->get_status(), 'data' => $r->get_data() );
}

echo "=== Création d'un template Theme Builder test ===\n\n";

// 1. Créer le template.
$res = call_divi_route( '/outside-vb/theme-builder/create-template', array(
    'live'  => false,  // brouillon
    'title' => 'IAWM Test Template',
) );
echo "1. create-template :\n";
echo "   HTTP " . $res['status'] . "\n";
echo "   data = " . substr( var_export( $res['data'], true ), 0, 500 ) . "\n\n";

// 2. Lister à nouveau pour voir ce qui a été créé.
$res2 = call_divi_route( '/outside-vb/theme-builder/list-templates', array( 'live' => false ) );
echo "2. list-templates après création :\n";
echo "   HTTP " . $res2['status'] . "\n";
echo "   data = " . substr( var_export( $res2['data'], true ), 0, 2000 ) . "\n\n";

// 3. Inspecter ce qui est en base.
echo "3. Posts Theme Builder en base :\n";
global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_type, post_status, post_title, post_parent, post_date_gmt FROM {$wpdb->posts} WHERE post_type IN ('et_theme_builder','et_template','et_header_layout','et_body_layout','et_footer_layout') ORDER BY ID DESC LIMIT 20" );
foreach ( $rows as $r ) {
    echo "   - id={$r->ID} type={$r->post_type} parent={$r->post_parent} status={$r->post_status} title='{$r->post_title}'\n";
    // Meta du post.
    $meta = get_post_meta( $r->ID );
    foreach ( $meta as $k => $v ) {
        if ( false !== strpos( $k, '_et_' ) || false !== strpos( $k, 'layout' ) || false !== strpos( $k, 'template' ) ) {
            $val = is_array( $v ) ? maybe_unserialize( $v[0] ) : $v;
            $val_str = is_array( $val ) ? json_encode( $val ) : (string) $val;
            if ( strlen( $val_str ) > 80 ) {
                $val_str = substr( $val_str, 0, 80 ) . '...';
            }
            echo "     meta {$k} = {$val_str}\n";
        }
    }
}

echo "\n=== Fin test ===\n";
