<?php
/**
 * Teste l'appel à /divi/v1/divi-library en générant nous-mêmes le bon
 * nonce X-ET-Nonce (nom : etRestDiviLibrary).
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
echo "Connecté comme : " . $admins[0]->user_login . "\n\n";

$tests = array(
    'divi-library'             => '/divi/v1/divi-library--POST',
    'divi-library/cloud-token' => '/divi/v1/divi-library/cloud-token--POST',
    'divi-library/item'        => '/divi/v1/divi-library/item--POST',
    'divi-library/load'        => '/divi/v1/divi-library/load--POST',
);

foreach ( $tests as $route => $nonce_name ) {
    echo "==== POST /divi/v1/{$route} (nonce: {$nonce_name}) ====\n";
    $nonce = wp_create_nonce( $nonce_name );
    echo "  Nonce généré : {$nonce}\n";

    $req = new WP_REST_Request( 'POST', "/divi/v1/{$route}" );
    $req->set_header( 'Content-Type', 'application/json' );
    $req->set_header( 'X-ET-Nonce', $nonce );

    // Payload approprié.
    $body = array();
    if ( $route === 'divi-library' ) {
        $body = array( 'type' => 'layout' );
    } elseif ( $route === 'divi-library/item' ) {
        $body = array( 'id' => 1, 'libraryType' => 'layout', 'builtFor' => 'page', 'contentType' => 'layout' );
    } elseif ( $route === 'divi-library/load' ) {
        $body = array( 'post_id' => 19 );
    }
    $req->set_body( json_encode( $body ) );

    $response = rest_do_request( $req );
    echo "  HTTP " . $response->get_status() . "\n";
    $data = $response->get_data();
    if ( is_array( $data ) ) {
        $keys = array_keys( $data );
        echo "  Clés réponse : " . implode( ', ', $keys ) . "\n";
        // Aperçu de la racine.
        foreach ( $data as $k => $v ) {
            if ( is_scalar( $v ) ) {
                $shown = is_string( $v ) && strlen( $v ) > 100 ? substr( $v, 0, 100 ) . '...' : (string) $v;
                echo "    {$k} = {$shown}\n";
            } elseif ( is_array( $v ) ) {
                echo "    {$k} = array(" . count( $v ) . ")\n";
                if ( count( $v ) > 0 && count( $v ) <= 5 ) {
                    foreach ( $v as $ck => $cv ) {
                        if ( is_scalar( $cv ) ) {
                            echo "      [{$ck}] = " . substr( (string) $cv, 0, 80 ) . "\n";
                        } else {
                            echo "      [{$ck}] = (" . gettype( $cv ) . ")\n";
                        }
                    }
                }
            }
        }
    } else {
        echo "  data = " . var_export( $data, true ) . "\n";
    }
    echo "\n";
}
