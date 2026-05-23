<?php
/**
 * Explore le mécanisme Divi Cloud :
 *  1. Comprendre comment Divi génère son token Cloud.
 *  2. Tester l'appel à /divi/v1/divi-library en interne (rest_do_request).
 *  3. Si possible, lister les layouts disponibles dans la library locale
 *     et/ou Divi Cloud.
 *  4. Identifier les classes/fonctions PHP Divi qu'on peut appeler en
 *     direct (plus simple que via REST).
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

echo "=== Exploration Divi Cloud ===\n\n";

// 1. Vérifier la présence des classes Divi.
echo "1. Présence des classes Divi-clés :\n";
$classes = array(
    'ET_Core_API_ElegantMarketplace',
    'ET_Cloud_App',
    'ET_Cloud_Helper',
    'ET_Cloud_REST_Library',
    'ET_Core_PageResource',
    'ET_Builder_API_Library',
    'ET_Builder_Library',
);
foreach ( $classes as $c ) {
    echo "  - {$c} : " . ( class_exists( $c ) ? '✓' : '✗' ) . "\n";
}

echo "\n2. Constantes Divi :\n";
$consts = array( 'ET_BUILDER_VERSION', 'ET_CORE_VERSION', 'ET_BUILDER_DIR', 'ET_BUILDER_PLUGIN_DIR', 'ET_CLOUD_PLUGIN_DIR' );
foreach ( $consts as $k ) {
    echo "  - {$k} = " . ( defined( $k ) ? constant( $k ) : '(non défini)' ) . "\n";
}

// 3. Options stockées par Divi (compte utilisateur, license, etc.).
echo "\n3. Options Divi stockées :\n";
$options = array( 'et_automatic_updates_options', 'et_core_api_email_account', 'et_cloud_token', 'et_cloud_user' );
foreach ( $options as $opt ) {
    $val = get_option( $opt );
    if ( false === $val ) {
        echo "  - {$opt} : (absent)\n";
    } elseif ( is_string( $val ) || is_numeric( $val ) ) {
        $shown = strlen( (string) $val ) > 60 ? substr( $val, 0, 60 ) . '...' : (string) $val;
        echo "  - {$opt} = " . $shown . "\n";
    } else {
        echo "  - {$opt} = " . substr( print_r( $val, true ), 0, 200 ) . "\n";
    }
}

// 4. Lister les routes divi-library disponibles dans le serveur REST.
echo "\n4. Routes divi/v1/divi-library disponibles :\n";
$server = rest_get_server();
do_action( 'rest_api_init', $server );
$routes = $server->get_routes();
foreach ( $routes as $route => $handlers ) {
    if ( 0 === strpos( $route, '/divi/v1/divi-library' ) ) {
        foreach ( $handlers as $h ) {
            $methods = isset( $h['methods'] ) ? implode( ',', array_keys( $h['methods'] ) ) : '?';
            echo "  - [{$methods}] {$route}\n";
            // Stocker les permissions callbacks pour info.
            if ( isset( $h['permission_callback'] ) && is_callable( $h['permission_callback'] ) ) {
                $cb_repr = is_array( $h['permission_callback'] )
                    ? ( is_object( $h['permission_callback'][0] ) ? get_class( $h['permission_callback'][0] ) : $h['permission_callback'][0] ) . '::' . $h['permission_callback'][1]
                    : ( is_string( $h['permission_callback'] ) ? $h['permission_callback'] : '(closure)' );
                echo "      perm: {$cb_repr}\n";
            }
        }
        break; // On en montre juste un, les autres seront listés au coup suivant.
    }
}

// Plus exhaustivement.
echo "\n5. Toutes les routes divi-library (uniques) :\n";
$seen = array();
foreach ( $routes as $route => $handlers ) {
    if ( 0 === strpos( $route, '/divi/v1/divi-library' ) ) {
        foreach ( $handlers as $h ) {
            $methods = isset( $h['methods'] ) ? implode( ',', array_keys( $h['methods'] ) ) : '?';
            $key = $route . '|' . $methods;
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            echo "  - [{$methods}] {$route}\n";
        }
    }
}

// 6. Tester un appel interne à divi-library (POST avec type=layout).
echo "\n6. Test interne : POST /divi/v1/divi-library (type=layout)\n";

// Se connecter comme admin.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC' ) );
if ( $admins ) {
    wp_set_current_user( $admins[0]->ID );
    echo "  Connecté comme : {$admins[0]->user_login}\n";
}

$req = new WP_REST_Request( 'POST', '/divi/v1/divi-library' );
$req->set_header( 'Content-Type', 'application/json' );
$req->set_body( json_encode( array( 'type' => 'layout' ) ) );

$response = rest_do_request( $req );
echo "  HTTP " . $response->get_status() . "\n";
$data = $response->get_data();
if ( is_array( $data ) ) {
    // Aperçu raccourci.
    foreach ( $data as $k => $v ) {
        if ( is_array( $v ) || is_object( $v ) ) {
            echo "  {$k} : (" . gettype( $v ) . " avec " . count( (array) $v ) . " éléments)\n";
        } else {
            $shown = is_string( $v ) ? ( strlen( $v ) > 100 ? substr( $v, 0, 100 ) . '...' : $v ) : var_export( $v, true );
            echo "  {$k} = {$shown}\n";
        }
    }
} else {
    echo "  data = " . var_export( $data, true ) . "\n";
}

// 7. Tester cloud-token.
echo "\n7. Test : POST /divi/v1/divi-library/cloud-token\n";
$req2 = new WP_REST_Request( 'POST', '/divi/v1/divi-library/cloud-token' );
$req2->set_header( 'Content-Type', 'application/json' );
$req2->set_body( json_encode( new stdClass() ) );
$response2 = rest_do_request( $req2 );
echo "  HTTP " . $response2->get_status() . "\n";
$data2 = $response2->get_data();
if ( is_array( $data2 ) ) {
    foreach ( $data2 as $k => $v ) {
        $shown = is_scalar( $v ) ? ( is_string( $v ) && strlen( $v ) > 80 ? substr( $v, 0, 80 ) . '...' : (string) $v )
            : substr( print_r( $v, true ), 0, 120 );
        echo "  {$k} = {$shown}\n";
    }
} else {
    echo "  data = " . var_export( $data2, true ) . "\n";
}

// 8. Tenter divi-library/item-location avec un id de layout.
echo "\n8. Premiers layouts Divi locaux via la table custom 'et_pb_layout' (post_type) :\n";
$layouts = get_posts( array(
    'post_type'      => 'et_pb_layout',
    'posts_per_page' => 10,
    'post_status'    => 'any',
) );
foreach ( $layouts as $l ) {
    echo "  - id={$l->ID} title=\"" . substr( $l->post_title, 0, 50 ) . "\"\n";
}
echo "  Total : " . count( $layouts ) . "\n";

echo "\n=== Fin exploration ===\n";
