<?php
/**
 * Banc : le quota d'émissions.
 *
 * La classe est pure : elle transforme des tableaux. Sa sûreté sous concurrence
 * vient de son appelant, qui la manipule sous verrou — c'est le banc des
 * services qui l'éprouve.
 *
 * Ici, un point tient tout le reste : **une valeur corrompue est traitée comme
 * pleine, jamais comme vide**. Considérer l'illisible comme « aucun envoi »
 * élargirait les droits exactement là où l'on ne comprend plus l'état.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Account\LimiteEnvois;

$t = 1785000000;

/**
 * Raccourci : état décodé depuis des horodatages.
 *
 * @param array<int, int> $h Horodatages.
 * @return array{horodatages: array<int, int>, corrompue: bool}
 */
function etat( array $h ): array {
	return array( 'horodatages' => $h, 'corrompue' => false );
}

// ======================================================================
// 1 · QUOTA
// ======================================================================
check( '1 · aucun envoi : permis', '' === LimiteEnvois::motif_de_refus( etat( array() ), $t ) );
check( '1 · un envoi ancien : permis',
	'' === LimiteEnvois::motif_de_refus( etat( array( $t - 3600 ) ), $t ) );
check( '1 · deux envois : permis',
	'' === LimiteEnvois::motif_de_refus( etat( array( $t - 7200, $t - 3600 ) ), $t ) );
check( '1 · TROIS ENVOIS : REFUSÉ',
	'quota_epuise' === LimiteEnvois::motif_de_refus( etat( array( $t - 10800, $t - 7200, $t - 3600 ) ), $t ) );

// ======================================================================
// 2 · FENÊTRE GLISSANTE
// ======================================================================
$vieux = array( $t - 90000, $t - 7200, $t - 3600 );

check( '2 · un envoi de plus de 24 h sort de la fenêtre',
	array( $t - 7200, $t - 3600 ) === LimiteEnvois::purger( $vieux, $t ) );
check( '2 · LA PREMIÈRE ÉMISSION REDEVIENT DISPONIBLE APRÈS 24 H',
	'' === LimiteEnvois::motif_de_refus( etat( $vieux ), $t ) );
check( '2 · exactement 24 h : encore dans la fenêtre',
	array( $t - 86400 ) !== LimiteEnvois::purger( array( $t - 86400 ), $t ) );

// ======================================================================
// 3 · DÉLAI MINIMAL DE 60 SECONDES
// ======================================================================
check( '3 · un envoi il y a 10 s : REFUSÉ',
	'delai_minimal' === LimiteEnvois::motif_de_refus( etat( array( $t - 10 ) ), $t ) );
check( '3 · un envoi il y a 59 s : refusé',
	'delai_minimal' === LimiteEnvois::motif_de_refus( etat( array( $t - 59 ) ), $t ) );
check( '3 · un envoi il y a 60 s : permis',
	'' === LimiteEnvois::motif_de_refus( etat( array( $t - 60 ) ), $t ) );
check( '3 · le délai porte sur le PLUS RÉCENT',
	'delai_minimal' === LimiteEnvois::motif_de_refus( etat( array( $t - 5000, $t - 5 ) ), $t ) );

// ======================================================================
// 4 · VALEUR CORROMPUE — RESTRICTIVE
// ======================================================================
foreach ( array( 'pas du json', '{"a":1}', '[1,2,3,4]', '["texte"]', '[null]' ) as $brut ) {
	$decode = LimiteEnvois::decoder( $brut );

	check(
		sprintf( '4 · « %s » est jugée corrompue', substr( $brut, 0, 14 ) ),
		true === $decode['corrompue']
	);
}

check( '4 · UNE VALEUR CORROMPUE REFUSE L’ÉMISSION',
	'quota_illisible' === LimiteEnvois::motif_de_refus( LimiteEnvois::decoder( 'pas du json' ), $t ) );
check( '4 · elle n’élargit jamais les droits',
	'' !== LimiteEnvois::motif_de_refus( LimiteEnvois::decoder( '[9,9,9,9,9]' ), $t ) );

// ======================================================================
// 5 · DÉCODAGE ET ENCODAGE
// ======================================================================
check( '5 · absente : aucun envoi, non corrompue',
	array() === LimiteEnvois::decoder( null )['horodatages'] && false === LimiteEnvois::decoder( null )['corrompue'] );
check( '5 · chaîne vide : idem', false === LimiteEnvois::decoder( '' )['corrompue'] );
check( '5 · un aller-retour conserve les valeurs',
	array( 10, 20 ) === LimiteEnvois::decoder( LimiteEnvois::encoder( array( 20, 10 ) ) )['horodatages'] );
check( '5 · l’encodage trie', '[10,20]' === LimiteEnvois::encoder( array( 20, 10 ) ) );
check( '5 · des entiers en chaîne sont acceptés',
	array( 10, 20 ) === LimiteEnvois::decoder( '["10","20"]' )['horodatages'] );

// ======================================================================
// 6 · CONFIRMATION
// ======================================================================
$apres = LimiteEnvois::confirmer( array( $t - 3600 ), $t );

check( '6 · la confirmation ajoute un horodatage', 2 === count( $apres ) );
check( '6 · elle purge au passage', 2 === count( LimiteEnvois::confirmer( array( $t - 90000, $t - 3600 ), $t ) ) );
check( '6 · elle ne dépasse jamais le maximum',
	LimiteEnvois::MAX >= count( LimiteEnvois::confirmer( array( $t - 3, $t - 2, $t - 1 ), $t ) ) );

// ======================================================================
// 7 · TROIS ÉMISSIONS SUR 24 H, LA QUATRIÈME REFUSÉE
// ======================================================================
$h = array();

for ( $i = 0; $i < 3; $i++ ) {
	$quand = $t + ( $i * 120 );

	check(
		sprintf( '7 · émission %d permise', $i + 1 ),
		'' === LimiteEnvois::motif_de_refus( etat( $h ), $quand )
	);

	$h = LimiteEnvois::confirmer( $h, $quand );
}

check( '7 · LA QUATRIÈME EST REFUSÉE',
	'quota_epuise' === LimiteEnvois::motif_de_refus( etat( $h ), $t + 400 ) );
check( '7 · et le reste 23 heures plus tard',
	'quota_epuise' === LimiteEnvois::motif_de_refus( etat( $h ), $t + 82800 ) );
check( '7 · MAIS PLUS APRÈS 24 H',
	'' === LimiteEnvois::motif_de_refus( etat( $h ), $t + 86500 ) );

verdict();
