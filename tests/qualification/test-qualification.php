<?php
/**
 * Banc du moteur de qualification — côté serveur.
 *
 * Rejoue `cas.json`, le corpus partagé avec le moteur navigateur. Les deux
 * moteurs ne partagent aucun code ; ils partagent ce corpus, et doivent en
 * tirer les mêmes verdicts. C'est la garantie contre la divergence.
 *
 * Les seuils testés viennent des textes vérifiés sur Légifrance, pas des
 * constantes du code : un test qui relirait la constante qu'il vérifie ne
 * vérifierait rien.
 */

$racine = dirname( __DIR__, 2 );
require_once $racine . '/wordpress/urbizen-platform/src/Forms/QualificationUrbanisme.php';

use Urbizen\Platform\Forms\QualificationUrbanisme;

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

$brut = json_decode( file_get_contents( __DIR__ . '/cas.json' ), true );
check( 'Le corpus partagé est lisible', is_array( $brut ) && isset( $brut['cas'] ) );

$cas = $brut['cas'];
check( 'Le corpus couvre au moins 60 cas', count( $cas ) >= 60 );

/* ------------------------------------------------- le fallback est mort -- */

$moteur = file_get_contents( $racine . '/wordpress/urbizen-platform/src/Forms/QualificationUrbanisme.php' );
check( 'Aucun défaut implicite vers la déclaration préalable',
	! preg_match( '/\|\|\s*[\'"]dp[\'"]/', $moteur )
	&& ! preg_match( '/\?\?\s*[\'"]dp[\'"]/', $moteur ) );

check( 'Les cinq états sont déclarés, et cinq seulement',
	array( 'dp', 'pcmi', 'none', 'confirm', 'conception' ) === QualificationUrbanisme::ETATS );

/* ------------------------------------------------------- le corpus entier */

$ecarts = array();
$regles = array();
$etats  = array();

foreach ( $cas as $c ) {
	$obtenu = QualificationUrbanisme::qualifier( $c['donnees'] );
	$etats[ $obtenu['status'] ] = ( $etats[ $obtenu['status'] ] ?? 0 ) + 1;

	if ( $obtenu['status'] !== $c['attendu'] ) {
		$ecarts[] = sprintf( '%s → %s (attendu %s)', $c['nom'], $obtenu['status'], $c['attendu'] );
	}
	if ( isset( $c['rule'] ) && $obtenu['rule'] !== $c['rule'] ) {
		$regles[] = sprintf( '%s → %s (attendu %s)', $c['nom'], $obtenu['rule'] ?? 'null', $c['rule'] );
	}
}

check( sprintf( 'Les %d cas du corpus rendent l\'état attendu', count( $cas ) ), array() === $ecarts );
foreach ( array_slice( $ecarts, 0, 12 ) as $e ) { echo '    ' . $e . "\n"; }

check( 'Chaque cas cite l\'article qui le fonde', array() === $regles );
foreach ( array_slice( $regles, 0, 8 ) as $e ) { echo '    ' . $e . "\n"; }

/* --------------------------------------------- invariants du moteur ------ */

// Un état hors des cinq serait une régression silencieuse.
$hors = array_diff( array_keys( $etats ), QualificationUrbanisme::ETATS );
check( 'Aucun état hors des cinq autorisés', array() === $hors );

// Les cinq états sont réellement atteignables : un état jamais rendu serait
// une branche morte, et `none` en est la démonstration la plus utile.
$manquants = array_diff( QualificationUrbanisme::ETATS, array_keys( $etats ) );
check( 'Les cinq états sont atteints par le corpus', array() === $manquants );
if ( array() !== $manquants ) { echo '    jamais rendus : ' . implode( ', ', $manquants ) . "\n"; }

// Le cœur de la tranche : rien d'inconnu ne part en déclaration préalable.
$inconnus = array(
	array( 'projet' => 'autre' ),
	array( 'projet' => 'chose-inexistante' ),
	array(),
	array( 'projet' => 'extension' ),
	array( 'projet' => 'garage' ),
	array( 'projet' => 'abri' ),
	array( 'projet' => 'transformation' ),
	array( 'projet' => 'piscine' ),
);
$fuites = array();
foreach ( $inconnus as $d ) {
	$s = QualificationUrbanisme::qualifier( $d )['status'];
	if ( 'confirm' !== $s ) { $fuites[] = ( $d['projet'] ?? '(aucun)' ) . ' → ' . $s; }
}
check( 'Un projet non qualifié rend « à confirmer », jamais « dp »', array() === $fuites );
if ( array() !== $fuites ) { echo '    ' . implode( ' | ', $fuites ) . "\n"; }

// Toute qualification incomplète doit dire ce qui manque, sinon l'interface ne
// saurait pas quelle question poser.
$muets = array();
foreach ( $cas as $c ) {
	$o = QualificationUrbanisme::qualifier( $c['donnees'] );
	if ( 'confirm' === $o['status'] && array() === $o['missing'] && '' === $o['reason'] ) {
		$muets[] = $c['nom'];
	}
}
check( 'Chaque « à confirmer » explique ce qui lui manque', array() === $muets );

echo "\n";
echo "répartition des états : ";
foreach ( $etats as $e => $n ) { echo "$e=$n "; }
echo "\n\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
