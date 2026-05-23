<?php
/**
 * Outil de cartographie : liste toutes les routes du namespace divi/v1
 * sur le site WordPress local, regroupées par préfixe, avec leurs méthodes
 * HTTP et args. Sortie : un fichier JSON dans docs/.
 *
 * Utilisation (PowerShell) :
 *   $php = "~/wordpress-dev\\AppData\\Local\\Local\\lightning-services\\php-8.2.30+1\\bin\\win64\\php.exe"
 *   $ext = "~/wordpress-dev\\AppData\\Local\\Local\\lightning-services\\php-8.2.30+1\\bin\\win64\\ext"
 *   & $php -d extension_dir="$ext" -d extension=mysqli tools/map-divi-api.php
 */

// ------------------------------------------------------------
// Bootstrap WordPress en CLI (LocalWP MySQL sur 127.0.0.1:10011)
// ------------------------------------------------------------
$site_root = '~/wordpress-dev/Local Sites/ia-webmaster-bridge/app/public';

if ( ! is_dir( $site_root ) ) {
    fwrite( STDERR, "Site root introuvable : {$site_root}\n" );
    exit( 1 );
}

define( 'ABSPATH', $site_root . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_USE_THEMES', false );

// Constantes DB connues du site LocalWP.
define( 'DB_NAME', 'local' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1:10011' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Tirées de wp-config.php du site local.
$table_prefix = 'wp_';

// Clés salt (peu importantes pour un script CLI, on met des placeholders).
foreach ( array(
    'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
    'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
) as $k ) {
    if ( ! defined( $k ) ) {
        define( $k, 'cli-placeholder' );
    }
}

require ABSPATH . 'wp-settings.php';

// ------------------------------------------------------------
// Récupération des routes du serveur REST
// ------------------------------------------------------------
$server = rest_get_server();
do_action( 'rest_api_init', $server );

$routes = $server->get_routes();

$by_namespace = array();
foreach ( $routes as $route => $handlers ) {
    // Namespaces visés : divi/v1 et ia-webmaster/v1 (référence).
    if ( ! preg_match( '~^/(divi|ia-webmaster|wp/v2)/v?\d?~', $route ) ) {
        continue;
    }

    foreach ( $handlers as $handler ) {
        $methods = isset( $handler['methods'] ) ? array_keys( $handler['methods'] ) : array();
        $args    = array();
        if ( isset( $handler['args'] ) && is_array( $handler['args'] ) ) {
            foreach ( $handler['args'] as $name => $arg ) {
                $args[ $name ] = array(
                    'required'    => ! empty( $arg['required'] ),
                    'type'        => isset( $arg['type'] ) ? $arg['type'] : null,
                    'description' => isset( $arg['description'] ) ? $arg['description'] : null,
                    'enum'        => isset( $arg['enum'] ) ? $arg['enum'] : null,
                );
            }
        }

        // Regrouper par segment racine (divi/v1, divi/v1/library, etc.).
        $segments = explode( '/', trim( $route, '/' ) );
        $ns       = $segments[0] . '/' . ( $segments[1] ?? '' );
        $group    = $segments[2] ?? '_root';

        if ( ! isset( $by_namespace[ $ns ] ) ) {
            $by_namespace[ $ns ] = array();
        }
        if ( ! isset( $by_namespace[ $ns ][ $group ] ) ) {
            $by_namespace[ $ns ][ $group ] = array();
        }

        $by_namespace[ $ns ][ $group ][] = array(
            'route'   => $route,
            'methods' => $methods,
            'args'    => $args,
        );
    }
}

// ------------------------------------------------------------
// Sortie : JSON dans docs/ + résumé console
// ------------------------------------------------------------
$output_file = __DIR__ . '/../docs/divi5-api-map.json';
file_put_contents(
    $output_file,
    json_encode( $by_namespace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
);

echo "OK : carte écrite dans " . realpath( $output_file ) . "\n\n";

// Résumé par namespace + groupe.
foreach ( $by_namespace as $ns => $groups ) {
    $total = 0;
    foreach ( $groups as $routes ) {
        $total += count( $routes );
    }
    echo "===== {$ns} ({$total} routes) =====\n";
    foreach ( $groups as $group => $routes ) {
        echo "  [{$group}] : " . count( $routes ) . "\n";
        foreach ( $routes as $r ) {
            echo "    " . implode( ',', $r['methods'] ) . "  " . $r['route'] . "\n";
        }
    }
    echo "\n";
}
