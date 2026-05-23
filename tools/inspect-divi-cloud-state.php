<?php
/**
 * Inspecte l'état exact de la connexion Divi Cloud côté server :
 *  - Options et transients liés.
 *  - Tokens stockés.
 *  - Tente de récupérer le refresh_token et l'access_token.
 *  - Cherche si une route alternative pour les premade layouts existe.
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

echo "=== Inspection Divi Cloud state ===\n\n";

// 1. Options Divi Cloud.
echo "1. Options Divi Cloud :\n";
global $wpdb;
$rows = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE option_name LIKE '%cloud%' OR option_name LIKE 'et\\_%' ORDER BY option_name" );
foreach ( $rows as $r ) {
    echo "  - {$r->option_name} (len={$r->len})\n";
}

// 2. Transients.
echo "\n2. Transients Divi Cloud :\n";
$rows = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_%cloud%' OR option_name LIKE '_transient_et_%' ORDER BY option_name" );
foreach ( $rows as $r ) {
    echo "  - {$r->option_name}\n";
}

// 3. Détail du refresh_token (si présent).
echo "\n3. Contenu de et_cloud_refresh_token :\n";
$refresh = get_option( 'et_cloud_refresh_token' );
if ( false === $refresh ) {
    echo "  (absent)\n";
} elseif ( is_array( $refresh ) ) {
    foreach ( $refresh as $uid => $data ) {
        echo "  user_id={$uid} : " . print_r( $data, true );
    }
} else {
    echo "  type=" . gettype( $refresh ) . " value=" . substr( (string) $refresh, 0, 80 ) . "\n";
}

// 4. Access token transient.
echo "\n4. Transient et_cloud_access_token :\n";
$at = get_transient( 'et_cloud_access_token' );
if ( false === $at ) {
    echo "  (absent / expiré)\n";
} else {
    echo "  présent — longueur=" . strlen( (string) $at ) . " octets\n";
    echo "  preview : " . substr( (string) $at, 0, 40 ) . "...\n";
}

// 5. Voir s'il y a un access_token par user_id stocké ailleurs.
echo "\n5. Options contenant 'token' :\n";
$rows = $wpdb->get_results( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%token%' ORDER BY option_name" );
foreach ( $rows as $r ) {
    echo "  - {$r->option_name}\n";
}

// 6. Listes des classes Cloud chargées.
echo "\n6. Classes Cloud chargées :\n";
$classes = array( 'ET_Cloud_App', 'ET_Cloud_Helper', 'ET_Cloud_Plugin_Manager', 'ET_Cloud_REST_Library', 'ET_Builder_Library' );
foreach ( $classes as $c ) {
    echo "  - {$c} : " . ( class_exists( $c ) ? '✓' : '✗' ) . "\n";
}

// 7. ET_Builder_Library methods (premade ?)
if ( class_exists( 'ET_Builder_Library' ) ) {
    echo "\n7. Méthodes publiques d'ET_Builder_Library :\n";
    $methods = get_class_methods( 'ET_Builder_Library' );
    foreach ( $methods as $m ) {
        if ( false !== stripos( $m, 'premade' )
            || false !== stripos( $m, 'cloud' )
            || false !== stripos( $m, 'layout' )
            || false !== stripos( $m, 'remote' ) ) {
            echo "  - {$m}()\n";
        }
    }
}

// 8. Tester builder_library_layouts_data avec différents paramètres.
echo "\n8. ET_Builder_Library::builder_library_layouts_data() :\n";
if ( class_exists( 'ET_Builder_Library' ) && method_exists( 'ET_Builder_Library', 'builder_library_layouts_data' ) ) {
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
    wp_set_current_user( $admins[0]->ID );
    foreach ( array( 'layout', 'section', 'row', 'module', 'premade_layouts', 'cloud_layouts' ) as $type ) {
        try {
            $data = ET_Builder_Library::instance()->builder_library_layouts_data( $type );
            $key_count = is_array( $data ) ? count( $data ) : 0;
            $first = is_array( $data ) && ! empty( $data ) ? array_keys( $data ) : array();
            echo "  type={$type} : " . count( $first ) . " keys [" . implode( ', ', array_slice( $first, 0, 5 ) ) . "]";
            if ( is_array( $data ) && isset( $data['layouts_data'] ) ) {
                echo " (items=" . count( (array) $data['layouts_data'] ) . ")";
            }
            echo "\n";
        } catch ( \Throwable $e ) {
            echo "  type={$type} : ERREUR " . $e->getMessage() . "\n";
        }
    }
}

// 9. Y a-t-il une route pour les premade layouts ?
echo "\n9. Routes REST contenant 'premade' ou 'layouts' :\n";
$server = rest_get_server();
do_action( 'rest_api_init', $server );
$routes = $server->get_routes();
foreach ( $routes as $route => $h ) {
    if ( false !== stripos( $route, 'premade' )
        || ( false !== stripos( $route, 'layout' ) && false !== stripos( $route, 'divi' ) ) ) {
        echo "  - {$route}\n";
    }
}

echo "\n=== Fin inspection ===\n";
