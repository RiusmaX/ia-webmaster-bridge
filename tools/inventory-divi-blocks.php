<?php
/**
 * Liste exhaustive des blocs Gutenberg enregistrés dans le namespace
 * `divi/*`. Source de vérité côté serveur : si un bloc est enregistré
 * ici, on peut le produire et Divi saura le rendre.
 *
 * Écrit le résultat dans docs/divi5-blocks-registry.json.
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

$registry = WP_Block_Type_Registry::get_instance();
$all = $registry->get_all_registered();

$divi_blocks = array();
foreach ( $all as $name => $block ) {
    if ( 0 !== strpos( $name, 'divi/' ) ) {
        continue;
    }
    $info = array(
        'name'        => $name,
        'title'       => isset( $block->title ) ? $block->title : null,
        'category'    => isset( $block->category ) ? $block->category : null,
        'description' => isset( $block->description ) ? $block->description : null,
        'parent'      => isset( $block->parent ) ? $block->parent : null,
        'attributes_keys' => isset( $block->attributes ) && is_array( $block->attributes ) ? array_keys( $block->attributes ) : array(),
        'supports'    => isset( $block->supports ) ? $block->supports : null,
    );
    $divi_blocks[ $name ] = $info;
}

ksort( $divi_blocks );

echo "Total blocs divi/* enregistrés : " . count( $divi_blocks ) . "\n\n";

// Grouper par préfixe court pour lisibilité.
$by_short = array();
foreach ( $divi_blocks as $name => $info ) {
    $short = substr( $name, strlen( 'divi/' ) );
    // Regrouper les composés (accordion / accordion-item) sous une racine.
    $root = explode( '-', $short )[0];
    $by_short[ $root ][] = $name;
}

foreach ( $by_short as $root => $names ) {
    echo "[" . count( $names ) . "] {$root} :\n";
    foreach ( $names as $n ) {
        echo "    {$n}\n";
    }
}

file_put_contents(
    __DIR__ . '/../docs/divi5-blocks-registry.json',
    json_encode( $divi_blocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
);
echo "\nJSON complet écrit dans docs/divi5-blocks-registry.json (" . filesize( __DIR__ . '/../docs/divi5-blocks-registry.json' ) . " octets)\n";
