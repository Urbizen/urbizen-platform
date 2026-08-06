<?php
/**
 * Nombres saisis par une personne, et non par une machine.
 *
 * En France, « huit mètres et demi » s'écrit `8,5`. Le formulaire le refusait,
 * et PHP faisait pire en silence : `(float) "8,5"` vaut **8**. Un bassin de
 * 8,5 m serait devenu un bassin de 8 m sans que rien ne le signale — et cette
 * valeur-là finit dans un CERFA.
 *
 * Ce banc éprouve les deux moitiés du contrat : ce qui doit être accepté, et
 * ce qui doit être refusé plutôt que deviné.
 *
 * Usage : php tests/formulaires/test-nombre-localise.php
 */

define( 'ABSPATH', true );

$racine = dirname( __DIR__, 2 );
require $racine . '/wordpress/urbizen-platform/src/Forms/NombreLocalise.php';

use Urbizen\Platform\Forms\NombreLocalise as N;

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $label     Intitulé.
 * @param bool   $condition Verdict.
 * @return void
 */
function verifier( $label, $condition ) {
	global $echecs;

	if ( ! $condition ) {
		$echecs++;
	}

	printf( "%-72s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

/* ================================================================== *
 *  1. Ce qui doit être accepté
 * ================================================================== */

echo "\n── 1. Écritures acceptées\n";

$acceptes = array(
	'8'       => 8.0,
	'8,5'     => 8.5,
	'8.5'     => 8.5,
	' 8,5 '   => 8.5,
	'34,25'   => 34.25,
	',5'      => 0.5,
	'0,01'    => 0.01,
	'99,999'  => 100.0,   // arrondi à deux décimales
);

foreach ( $acceptes as $brut => $attendu ) {
	$r = N::decimal( $brut, 0, 200 );

	verifier(
		sprintf( '« %s » vaut %s', $brut, $attendu ),
		N::VALIDE === $r['etat'] && abs( $attendu - $r['valeur'] ) < 0.0001
	);
}

// L'espace insécable arrive de tous les copier-coller depuis un tableur.
verifier( 'un espace insécable ne fait pas échouer la lecture',
	N::VALIDE === N::decimal( "8\xc2\xa0,5" )['etat'] );

// Un nombre déjà typé traverse sans dommage.
verifier( 'un flottant PHP traverse', 8.5 === N::decimal( 8.5 )['valeur'] );
verifier( 'un entier PHP aussi', 8.0 === N::decimal( 8 )['valeur'] );

/* ================================================================== *
 *  2. Ce qui doit être refusé plutôt que deviné
 * ================================================================== */

echo "\n── 2. Écritures refusées\n";

$refuses = array(
	'8,5,2'    => 'deux séparateurs',
	'8,5.2'    => 'virgule et point mélangés',
	'1e3'      => 'notation scientifique',
	'1E3'      => 'notation scientifique majuscule',
	'abc'      => 'texte',
	'8 m'      => 'unité collée à la valeur',
	'NaN'      => 'NaN',
	'Infinity' => 'Infinity',
	'8.'       => 'point final sans décimale',
	'.'        => 'séparateur seul',
	'--3'      => 'double signe',
);

foreach ( $refuses as $brut => $quoi ) {
	verifier( sprintf( 'refusé : %s (« %s »)', $quoi, $brut ), N::FORMAT === N::decimal( $brut )['etat'] );
}

verifier( 'un tableau n’est pas un nombre mal écrit', N::FORMAT === N::decimal( array( 8 ) )['etat'] );
verifier( 'un booléen non plus', N::FORMAT === N::decimal( true )['etat'] );

/* ================================================================== *
 *  3. Absence, zéro et bornes
 * ================================================================== */

echo "\n── 3. Absence et bornes\n";

foreach ( array( '', '   ', null ) as $vide ) {
	$r = N::decimal( $vide );

	verifier( 'une valeur vide est absente, pas nulle', N::ABSENT === $r['etat'] && null === $r['valeur'] );
}

// La distinction qui compte : « pas mesuré » n'est pas « mesuré zéro ».
verifier( 'une absence ne devient jamais 0', null === N::decimal( '' )['valeur'] );
verifier( 'un zéro explicite reste un zéro', 0.0 === N::decimal( '0' )['valeur'] );
verifier( 'mais il est refusé quand une mesure réelle est attendue',
	N::BORNE === N::decimal( '0', 0, 100, true )['etat'] );

verifier( 'sous la borne basse', N::BORNE === N::decimal( '-3', 0, 100 )['etat'] );
verifier( 'au-dessus de la borne haute', N::BORNE === N::decimal( '1000', 0, 100 )['etat'] );
verifier( 'la borne haute est incluse', N::VALIDE === N::decimal( '100', 0, 100 )['etat'] );
verifier( 'sans borne, une grande valeur passe', N::VALIDE === N::decimal( '1000' )['etat'] );

/* ================================================================== *
 *  4. Entiers : une règle distincte
 * ================================================================== */

echo "\n── 4. Comptages entiers\n";

verifier( 'un entier est accepté', 3 === N::entier( '3' )['valeur'] );
verifier( 'un décimal n’est PAS arrondi en douce', N::FORMAT === N::entier( '3,5' )['etat'] );
verifier( 'ni avec un point', N::FORMAT === N::entier( '3.5' )['etat'] );
verifier( 'un négatif est refusé par défaut', N::BORNE === N::entier( '-1' )['etat'] );
verifier( 'zéro est un comptage valide', 0 === N::entier( '0' )['valeur'] );
verifier( 'une absence reste une absence', N::ABSENT === N::entier( '' )['etat'] );
verifier( 'la borne haute s’applique', N::BORNE === N::entier( '99', 0, 50 )['etat'] );

/* ================================================================== *
 *  5. Persistance et affichage
 * ================================================================== */

echo "\n── 5. Forme canonique et écriture française\n";

verifier( 'la forme persistée emploie le point', '8.5' === N::canonique( 8.5 ) );
verifier( 'sans décimale inutile', '34' === N::canonique( 34.0 ) );
verifier( 'deux décimales au plus', '8.13' === N::canonique( 8.1289 ) );
verifier( 'la précision persistée est documentée', 2 === N::DECIMALES );

verifier( 'l’affichage emploie la virgule', '8,5 m' === N::afficher( 8.5, 'm' ) );
verifier( 'sans décimale inutile', '34 m²' === N::afficher( 34.0, 'm²' ) );
verifier( 'sans unité si aucune n’est donnée', '1,5' === N::afficher( 1.5 ) );

// Le calcul de bassin, tel qu'il sera fait : 8,5 × 4 = 34, pas 32.
$l = N::decimal( '8,5', 0, 100 );
$g = N::decimal( '4', 0, 100 );

verifier( '8,5 × 4 donne bien 34', 34.0 === round( $l['valeur'] * $g['valeur'], 2 ) );
// Le piège que ce normaliseur ferme : PHP transtype « 8,5 » en 8, donc un
// calcul naïf donnerait 32 — un bassin amputé d'un demi-mètre, sans alerte.
verifier( 'un transtypage PHP naïf aurait donné 32', 32.0 === round( (float) '8,5' * 4, 2 ) );
verifier( 'le normaliseur, lui, donne 34', 34.0 === round( $l['valeur'] * $g['valeur'], 2 ) );

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
