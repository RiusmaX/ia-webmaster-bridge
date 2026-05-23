<?php
/**
 * Exploration du Theme Builder Divi 5 :
 *  1. Quels post types Divi utilise (et_pb_layout, et_theme_builder, …).
 *  2. Que retournent les routes outside-vb/theme-builder/*.
 *  3. Comment un template est structuré (post_content + meta).
 *  4. Quelles fonctions PHP Divi gèrent l'assignation.
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

echo "=== Exploration Theme Builder Divi 5 ===\n\n";

// 1. Post types Divi.
echo "1. Post types contenant 'et_' ou 'theme' :\n";
$post_types = get_post_types( array(), 'objects' );
foreach ( $post_types as $pt ) {
    if ( false !== stripos( $pt->name, 'et_' ) || false !== stripos( $pt->name, 'theme' ) || false !== stripos( $pt->name, 'header' ) ) {
        echo "  - {$pt->name} (label: {$pt->label}, public: " . ( $pt->public ? 'yes' : 'no' ) . ")\n";
    }
}

// 2. Compter les posts existants par type Divi.
echo "\n2. Posts existants par type :\n";
global $wpdb;
$rows = $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type LIKE 'et_%' OR post_type LIKE '%theme%' OR post_type LIKE '%layout%' OR post_type LIKE '%template%' GROUP BY post_type, post_status ORDER BY post_type, post_status" );
foreach ( $rows as $r ) {
    echo "  - {$r->post_type} [{$r->post_status}] : {$r->cnt}\n";
}

// 3. Tester les routes theme-builder.
echo "\n3. Test des routes outside-vb/theme-builder/* (avec nonce auto-généré) :\n";

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
wp_set_current_user( $admins[0]->ID );

function call_divi_route( $route, $body = array() ) {
    $full_route = '/divi/v1/' . ltrim( $route, '/' );
    $nonce_name = $full_route . '--POST';
    $nonce = wp_create_nonce( $nonce_name );

    $req = new WP_REST_Request( 'POST', $full_route );
    $req->set_header( 'Content-Type', 'application/json' );
    $req->set_header( 'X-ET-Nonce', $nonce );
    $req->set_body( wp_json_encode( $body ?: new stdClass() ) );

    $response = rest_do_request( $req );
    return array( 'status' => $response->get_status(), 'data' => $response->get_data() );
}

// 3a. list-templates (live = templates actifs, false = drafts ?)
echo "\n  3a. theme-builder/list-templates (live=true) :\n";
$res = call_divi_route( '/outside-vb/theme-builder/list-templates', array( 'live' => true ) );
echo "      HTTP " . $res['status'] . "\n";
if ( is_array( $res['data'] ) ) {
    foreach ( $res['data'] as $k => $v ) {
        if ( is_array( $v ) ) {
            echo "      {$k} : array(" . count( $v ) . ")\n";
            if ( count( $v ) > 0 && count( $v ) <= 3 ) {
                foreach ( $v as $i => $item ) {
                    if ( is_array( $item ) ) {
                        $sub_keys = array_keys( $item );
                        echo "        [{$i}] keys = " . implode( ', ', $sub_keys ) . "\n";
                    }
                }
            }
        } else {
            echo "      {$k} = " . ( is_string( $v ) ? substr( $v, 0, 100 ) : var_export( $v, true ) ) . "\n";
        }
    }
} else {
    echo "      " . var_export( $res['data'], true ) . "\n";
}

// 3b. list-templates (live=false)
echo "\n  3b. theme-builder/list-templates (live=false) :\n";
$res2 = call_divi_route( '/outside-vb/theme-builder/list-templates', array( 'live' => false ) );
echo "      HTTP " . $res2['status'] . "\n";
if ( is_array( $res2['data'] ) ) {
    foreach ( $res2['data'] as $k => $v ) {
        echo "      {$k} : " . ( is_array( $v ) ? 'array(' . count( $v ) . ')' : substr( var_export( $v, true ), 0, 100 ) ) . "\n";
    }
}

// 4. Lister directement les posts et_theme_builder s'il en existe.
echo "\n4. Templates Theme Builder existants (post_type=et_theme_builder ou autre) :\n";
foreach ( array( 'et_theme_builder', 'et_template', 'et_header_layout', 'et_footer_layout', 'et_body_layout' ) as $pt ) {
    $posts = get_posts( array( 'post_type' => $pt, 'posts_per_page' => 10, 'post_status' => 'any' ) );
    if ( ! empty( $posts ) ) {
        echo "  Post type '{$pt}' : " . count( $posts ) . " items\n";
        foreach ( $posts as $p ) {
            echo "    - id={$p->ID} title=\"{$p->post_title}\" status={$p->post_status}\n";
        }
    }
}

// 5. Métadonnées Theme Builder dans wp_options.
echo "\n5. Options Theme Builder dans wp_options :\n";
$opts = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE option_name LIKE '%theme_builder%' OR option_name LIKE '%theme%layout%' OR option_name LIKE 'et_template%' ORDER BY option_name" );
foreach ( $opts as $o ) {
    echo "  - {$o->option_name} (len={$o->len})\n";
}

// 6. Existence du sous-namespace REST des routes.
echo "\n6. Routes REST outside-vb/theme-builder :\n";
$server = rest_get_server();
do_action( 'rest_api_init', $server );
$seen = array();
foreach ( $server->get_routes() as $route => $h ) {
    if ( false !== stripos( $route, 'theme-builder' ) ) {
        if ( isset( $seen[ $route ] ) ) continue;
        $seen[ $route ] = true;
        $methods = implode( ',', array_keys( $h[0]['methods'] ?? array() ) );
        echo "  - [{$methods}] {$route}\n";
    }
}

echo "\n=== Fin exploration ===\n";
