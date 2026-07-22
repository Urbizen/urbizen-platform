<?php
/**
 * Banc : les identifiants exposés.
 *
 * Trois propriétés sont éprouvées, parce que ce sont les trois qu'on a
 * choisies : non énumérable, triable, lisible. La monotonie est celle qui se
 * casse le plus discrètement — deux appels dans la même milliseconde qui
 * rendraient des valeurs désordonnées ne se verraient qu'un jour de forte
 * charge, dans un index.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Domain\Support\Ulid;

// ======================================================================
// 1 · FORME
// ======================================================================
Ulid::reset();
$u = Ulid::generer();

check( '1 · longueur de vingt-six caractères', 26 === strlen( $u ) );
check( '1 · longueur déclarée cohérente', Ulid::LONGUEUR === strlen( $u ) );
check( '1 · il se valide lui-même', Ulid::est_valide( $u ) );
check( '1 · alphabet Crockford exclusivement',
	1 === preg_match( '/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $u ) );
check( '1 · l’alphabet compte trente-deux symboles', 32 === strlen( Ulid::ALPHABET ) );

foreach ( array( 'I', 'L', 'O', 'U' ) as $ambigue ) {
	check( "1 · la lettre ambiguë « $ambigue » est absente de l’alphabet",
		false === strpos( Ulid::ALPHABET, $ambigue ) );
}

// ======================================================================
// 2 · VALIDATION STRICTE
// ======================================================================
check( '2 · une chaîne vide est refusée', false === Ulid::est_valide( '' ) );
check( '2 · trop court est refusé', false === Ulid::est_valide( substr( $u, 0, 25 ) ) );
check( '2 · trop long est refusé', false === Ulid::est_valide( $u . '0' ) );
check( '2 · LES MINUSCULES SONT REFUSÉES', false === Ulid::est_valide( strtolower( $u ) ) );
check( '2 · un « I » est refusé', false === Ulid::est_valide( str_repeat( 'I', 26 ) ) );
check( '2 · un « L » est refusé', false === Ulid::est_valide( str_repeat( 'L', 26 ) ) );
check( '2 · un « O » est refusé', false === Ulid::est_valide( str_repeat( 'O', 26 ) ) );
check( '2 · un « U » est refusé', false === Ulid::est_valide( str_repeat( 'U', 26 ) ) );
check( '2 · un tiret est refusé', false === Ulid::est_valide( substr( $u, 0, 25 ) . '-' ) );
check( '2 · un espace est refusé', false === Ulid::est_valide( substr( $u, 0, 25 ) . ' ' ) );

// ======================================================================
// 3 · UNICITÉ
// ======================================================================
Ulid::reset();
$vus  = array();
$tirs = 100000;

for ( $i = 0; $i < $tirs; $i++ ) {
	$vus[ Ulid::generer() ] = true;
}

check( "3 · $tirs tirages, aucune collision", $tirs === count( $vus ) );

// ======================================================================
// 4 · MONOTONIE
// ======================================================================
// Horodatage imposé : sans cela, l'horloge avancerait et masquerait le seul
// cas intéressant — plusieurs identifiants dans la même milliseconde.
Ulid::reset();
$fige     = 1785000000000;
$serie    = array();
$ordonnee = true;

for ( $i = 0; $i < 5000; $i++ ) {
	$serie[] = Ulid::generer( $fige );
}

for ( $i = 1, $n = count( $serie ); $i < $n; $i++ ) {
	if ( strcmp( $serie[ $i ], $serie[ $i - 1 ] ) <= 0 ) {
		$ordonnee = false;
		break;
	}
}

check( '4 · MÊME MILLISECONDE : LA SÉRIE EST STRICTEMENT CROISSANTE', $ordonnee );
check( '4 · et sans doublon', 5000 === count( array_unique( $serie ) ) );

// Deux millisecondes différentes : l'ordre suit le temps.
Ulid::reset();
$tot = Ulid::generer( 1785000000000 );
Ulid::reset();
$tard = Ulid::generer( 1785000000001 );

check( '4 · une milliseconde plus tard trie après', strcmp( $tard, $tot ) > 0 );

Ulid::reset();
$ancien = Ulid::generer( 1000 );
Ulid::reset();
$recent = Ulid::generer( 1785000000000 );

check( '4 · un écart d’années trie dans le bon sens', strcmp( $recent, $ancien ) > 0 );

// ======================================================================
// 5 · HORODATAGE RELU
// ======================================================================
Ulid::reset();
$quand = 1785123456789;
$avec  = Ulid::generer( $quand );

check( '5 · l’horodatage se relit exactement', $quand === Ulid::horodatage( $avec ) );
check( '5 · une chaîne invalide ne rend pas d’horodatage', null === Ulid::horodatage( 'pas-un-ulid' ) );

Ulid::reset();
check( '5 · l’horodatage zéro est accepté', 0 === Ulid::horodatage( Ulid::generer( 0 ) ) );

Ulid::reset();
check( '5 · un horodatage négatif est ramené à zéro', 0 === Ulid::horodatage( Ulid::generer( -1 ) ) );

// ======================================================================
// 6 · ENTROPIE
// ======================================================================
// Deux identifiants du même instant, générés par des séries distinctes, ne
// doivent pas se ressembler : c'est ce qui interdit de deviner un voisin.
Ulid::reset();
$a = Ulid::generer( $fige );
Ulid::reset();
$b = Ulid::generer( $fige );

check( '6 · deux séries indépendantes diffèrent au même instant', $a !== $b );
check( '6 · leurs dix premiers caractères sont identiques (le temps)',
	substr( $a, 0, 10 ) === substr( $b, 0, 10 ) );
check( '6 · LEUR QUEUE DIFFÈRE (l’aléa)', substr( $a, 10 ) !== substr( $b, 10 ) );

// La queue doit varier réellement : mille tirages au même instant, mille
// queues distinctes.
Ulid::reset();
$queues = array();

for ( $i = 0; $i < 1000; $i++ ) {
	Ulid::reset();
	$queues[ substr( Ulid::generer( $fige ), 10 ) ] = true;
}

check( '6 · mille queues indépendantes, mille valeurs', 1000 === count( $queues ) );

verdict();
