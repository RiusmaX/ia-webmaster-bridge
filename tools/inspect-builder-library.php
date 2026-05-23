<?php
/**
 * Inspecte ce que renvoie ET_Builder_Library::builder_library_layouts_data
 * pour chaque type, et écrit un échantillon dans docs/.
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

$data = ET_Builder_Library::instance()->builder_library_layouts_data( 'layout' );
echo "Type 'layout' :\n";
echo "  Clés racines : " . implode( ', ', array_keys( $data ) ) . "\n";

if ( isset( $data['layouts_data'] ) ) {
    echo "  layouts_data : " . count( $data['layouts_data'] ) . " items\n";
    foreach ( $data['layouts_data'] as $i => $item ) {
        echo "  [{$i}]\n";
        if ( is_array( $item ) ) {
            foreach ( $item as $k => $v ) {
                if ( is_scalar( $v ) ) {
                    $shown = is_string( $v ) ? ( strlen( $v ) > 80 ? substr( $v, 0, 80 ) . '...' : $v ) : (string) $v;
                    echo "    {$k} = {$shown}\n";
                } else {
                    echo "    {$k} = (" . gettype( $v ) . " avec " . ( is_array( $v ) ? count( $v ) : '?' ) . " éléments)\n";
                }
            }
        }
    }
}

// Stocker en JSON pour analyse.
file_put_contents(
    __DIR__ . '/../docs/divi-builder-library-sample.json',
    json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
);
echo "\nDump complet écrit dans docs/divi-builder-library-sample.json\n";
