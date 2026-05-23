<?php
/**
 * Produit un index lisible des routes divi/v1 à partir de docs/divi5-api-map.json.
 * Dédoublonne, regroupe par segment racine, écrit docs/divi5-api-index.md.
 */

$json_path = __DIR__ . '/../docs/divi5-api-map.json';
if ( ! is_file( $json_path ) ) {
    fwrite( STDERR, "Fichier introuvable : {$json_path}\n" );
    exit( 1 );
}

$data = json_decode( file_get_contents( $json_path ), true );
if ( ! isset( $data['divi/v1'] ) ) {
    fwrite( STDERR, "Pas de divi/v1 dans la carte.\n" );
    exit( 1 );
}

// Dédoublonner par (route, méthodes-triées).
$seen = array();
$groups = array();
foreach ( $data['divi/v1'] as $group_name => $routes ) {
    foreach ( $routes as $r ) {
        sort( $r['methods'] );
        $key = $r['route'] . '|' . implode( ',', $r['methods'] );
        if ( isset( $seen[ $key ] ) ) {
            continue;
        }
        $seen[ $key ] = true;
        $groups[ $group_name ][] = $r;
    }
}

ksort( $groups );

$out = "# Carte de l'API REST `divi/v1` (site local)\n\n";
$out .= "> Généré automatiquement par `tools/summarize-divi-api.php`.\n";
$out .= "> Source : `docs/divi5-api-map.json`.\n\n";

$total = 0;
foreach ( $groups as $g => $routes ) {
    $total += count( $routes );
}
$out .= "**Total** : {$total} routes uniques, " . count( $groups ) . " groupes.\n\n";

foreach ( $groups as $g => $routes ) {
    $out .= "## `" . $g . "/` (" . count( $routes ) . " routes)\n\n";
    foreach ( $routes as $r ) {
        $methods = implode( ',', $r['methods'] );
        $out .= "- `[" . $methods . "]` `" . $r['route'] . "`";
        if ( ! empty( $r['args'] ) ) {
            $arg_names = array_keys( $r['args'] );
            $out .= "  \n  args : `" . implode( '`, `', $arg_names ) . "`";
        }
        $out .= "\n";
    }
    $out .= "\n";
}

$dest = __DIR__ . '/../docs/divi5-api-index.md';
file_put_contents( $dest, $out );
echo "OK : index écrit dans " . realpath( $dest ) . "\n";
echo "Total : {$total} routes uniques\n";
