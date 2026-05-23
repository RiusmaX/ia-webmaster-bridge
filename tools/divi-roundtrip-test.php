<?php
/**
 * Test du round-trip Divi 5 :
 *   1. Lit le post_content du post 19 (page de référence).
 *   2. parse_blocks() → serialize_blocks() = même chaîne ?
 *   3. Si différent, diff les premières divergences.
 *
 * Ce test détermine si on peut faire de l'écriture trivialement
 * (parse → serialize est l'identité) ou si Divi a un format que
 * parse_blocks abîme à la sérialisation.
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

$post = get_post( 19 );
if ( ! $post ) {
    fwrite( STDERR, "Post 19 introuvable.\n" );
    exit( 1 );
}

$original = $post->post_content;
$blocks   = parse_blocks( $original );
$re       = serialize_blocks( $blocks );

echo "Post 19 — IAWM Divi Reference\n";
echo "Taille originale : " . strlen( $original ) . " octets\n";
echo "Taille re-sérialisée : " . strlen( $re ) . " octets\n";
echo "Identique au bit près : " . ( $original === $re ? 'OUI' : 'NON' ) . "\n";

if ( $original !== $re ) {
    // Trouver le premier point de divergence.
    $min  = min( strlen( $original ), strlen( $re ) );
    $diff = null;
    for ( $i = 0; $i < $min; $i++ ) {
        if ( $original[ $i ] !== $re[ $i ] ) {
            $diff = $i;
            break;
        }
    }
    if ( null === $diff ) {
        $diff = $min; // L'un est préfixe de l'autre.
    }
    $start = max( 0, $diff - 80 );
    echo "\nPremière divergence à l'offset {$diff} :\n";
    echo "--- ORIGINAL (depuis {$start}) ---\n";
    echo substr( $original, $start, 250 ) . "\n";
    echo "--- RE-SÉRIALISÉ (depuis {$start}) ---\n";
    echo substr( $re, $start, 250 ) . "\n";
}

// Stats détaillées.
function count_blocks( $blocks ) {
    $c = 0;
    foreach ( $blocks as $b ) {
        if ( ! empty( $b['blockName'] ) ) {
            $c++;
        }
        if ( ! empty( $b['innerBlocks'] ) ) {
            $c += count_blocks( $b['innerBlocks'] );
        }
    }
    return $c;
}
echo "\nNb de blocs parsés : " . count_blocks( $blocks ) . "\n";
