<?php
/**
 * Diff précis entre le post_content de la page 19 et de la page 24
 * après round-trip. Identifie les divergences caractère par caractère.
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

$src = get_post( 19 )->post_content;
$tgt = get_post( 24 )->post_content;

echo "Source : " . strlen( $src ) . " octets\n";
echo "Cible  : " . strlen( $tgt ) . " octets\n";
echo "Diff   : " . ( strlen( $src ) - strlen( $tgt ) ) . " octets\n\n";

// Trouver toutes les divergences.
$min = min( strlen( $src ), strlen( $tgt ) );
$diffs = array();
$in_diff = false;
$start = 0;
$si = 0;
$ti = 0;

// Recherche de séquences. On va comparer en marquant les divergences.
// Algo simple : avancer en parallèle, quand ça diverge, chercher où ça
// resynchronise (via une fenêtre de 20 caractères).
function find_resync( $a, $b, $i_a, $i_b, $window = 30 ) {
    for ( $shift = 0; $shift < $window; $shift++ ) {
        for ( $delta = -$shift; $delta <= $shift; $delta++ ) {
            $ja = $i_a + $shift;
            $jb = $i_b + $shift + $delta;
            if ( $ja >= strlen( $a ) || $jb >= strlen( $b ) || $jb < 0 ) continue;
            // Match 10 caractères ?
            if ( substr( $a, $ja, 10 ) === substr( $b, $jb, 10 ) ) {
                return array( $ja, $jb );
            }
        }
    }
    return null;
}

while ( $si < strlen( $src ) && $ti < strlen( $tgt ) ) {
    if ( $src[ $si ] === $tgt[ $ti ] ) {
        $si++;
        $ti++;
        continue;
    }
    // Divergence trouvée.
    $resync = find_resync( $src, $tgt, $si, $ti );
    if ( null === $resync ) {
        // Fin.
        $diffs[] = array(
            'offset_src' => $si,
            'offset_tgt' => $ti,
            'src_chunk'  => substr( $src, max(0, $si - 20), 60 ),
            'tgt_chunk'  => substr( $tgt, max(0, $ti - 20), 60 ),
        );
        break;
    }
    $diffs[] = array(
        'offset_src' => $si,
        'offset_tgt' => $ti,
        'src_chunk'  => substr( $src, $si, $resync[0] - $si ),
        'tgt_chunk'  => substr( $tgt, $ti, $resync[1] - $ti ),
    );
    $si = $resync[0];
    $ti = $resync[1];
    if ( count( $diffs ) > 20 ) break; // safety
}

echo "Nombre de divergences : " . count( $diffs ) . "\n\n";
foreach ( $diffs as $i => $d ) {
    echo "--- Divergence " . ( $i + 1 ) . " (src@{$d['offset_src']}, tgt@{$d['offset_tgt']}) ---\n";
    echo "SRC : " . var_export( $d['src_chunk'], true ) . "\n";
    echo "TGT : " . var_export( $d['tgt_chunk'], true ) . "\n\n";
}
