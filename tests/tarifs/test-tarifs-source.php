<?php
/**
 * Banc de cohérence de la source tarifaire.
 *
 * Le catalogue du thème (`urbizen_child_tarifs()`) est ce que le VISITEUR
 * lit. Les classes `Pricing*` du greffon sont ce que le CLIENT paie. Rien,
 * dans le code, n'oblige les deux à coïncider : le thème ne lit pas le
 * greffon au moment du rendu, précisément pour que la page survive à un
 * greffon désactivé.
 *
 * C'est donc ici, et nulle part ailleurs, que la divergence est interdite.
 * Un montant modifié d'un seul côté fait échouer ce banc — au lieu de
 * s'afficher.
 *
 * Aucun montant n'est exempté du contrôle, Conception comprise.
 *
 * Toutes les données sont celles du dépôt. Aucun réseau, aucune base.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';
$plugin = $racine . '/wordpress/urbizen-platform';

define( 'ABSPATH', $racine );

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @param string $detail  Précision affichée en cas d'échec.
 * @return void
 */
function check( $libelle, $reussi, $detail = '' ) {
	global $echecs;

	if ( ! $reussi ) {
		++$echecs;
	}

	printf( "%-72s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );

	if ( ! $reussi && '' !== $detail ) {
		echo '    ' . $detail . "\n";
	}
}

// --- Catalogue du thème ----------------------------------------------------
// Extrait par lecture du source : exécuter tout functions.php supposerait un
// WordPress complet, pour aucun gain ici.
$src = (string) file_get_contents( $theme . '/functions.php' );

if ( ! preg_match( '/^function urbizen_child_tarifs\(\).*?^}$/ms', $src, $m ) ) {
	echo "urbizen_child_tarifs() introuvable dans functions.php\n";
	exit( 1 );
}

eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- source du dépôt.
$catalogue = urbizen_child_tarifs();

// --- Constantes du greffon -------------------------------------------------
require_once $plugin . '/src/Forms/PricingProjets.php';
require_once $plugin . '/src/Forms/PricingDeclarationPrealable.php';
require_once $plugin . '/src/Forms/PricingPermisConstruire.php';
require_once $plugin . '/src/Forms/Pricing.php';

use Urbizen\Platform\Forms\Pricing;
use Urbizen\Platform\Forms\PricingDeclarationPrealable;
use Urbizen\Platform\Forms\PricingPermisConstruire;
use Urbizen\Platform\Forms\PricingProjets;

/**
 * Montants du catalogue pour un groupe donné.
 *
 * @param array  $catalogue Catalogue du thème.
 * @param string $id        Identifiant de groupe.
 * @return array<int, int>
 */
function montants( $catalogue, $id ) {
	foreach ( $catalogue['groupes'] as $groupe ) {
		if ( $id === $groupe['id'] ) {
			return array_map( fn( $o ) => (int) $o['prix'], $groupe['offres'] );
		}
	}

	return array();
}

$dp = montants( $catalogue, 'dp' );
$pc = montants( $catalogue, 'pc' );

// --- Déclaration préalable -------------------------------------------------
$socles_dp = array_values( array_unique( array_filter( PricingDeclarationPrealable::NATURES, 'is_int' ) ) );
sort( $socles_dp );
$attendu_dp = $dp;
sort( $attendu_dp );

check(
	'DP : les trois forfaits existent dans PricingDeclarationPrealable::NATURES',
	array() === array_diff( $dp, array_values( PricingDeclarationPrealable::NATURES ) ),
	'catalogue = ' . implode( ', ', $dp ) . ' · socles = ' . implode( ', ', $socles_dp )
);
check(
	'DP : le forfait le plus bas est bien le socle minimum du greffon',
	min( $dp ) === min( $socles_dp ),
	'catalogue min = ' . min( $dp ) . ' · greffon min = ' . min( $socles_dp )
);
check( 'DP : clôtures et panneaux solaires à 189 €', 189 === $dp[0], 'valeur : ' . $dp[0] );
check( 'DP : projet standard à 249 €', 249 === $dp[1], 'valeur : ' . $dp[1] );
check( 'DP : projet important à 549 €', 549 === $dp[2], 'valeur : ' . $dp[2] );

// --- Permis de construire --------------------------------------------------
$socles_pc = array_values( array_unique( array_filter( PricingPermisConstruire::NATURES, 'is_int' ) ) );
sort( $socles_pc );

check(
	'PC : les trois forfaits existent dans PricingPermisConstruire::NATURES',
	array() === array_diff( $pc, array_values( PricingPermisConstruire::NATURES ) ),
	'catalogue = ' . implode( ', ', $pc ) . ' · socles = ' . implode( ', ', $socles_pc )
);
check( 'PC : projet simple à 449 €', 449 === $pc[0], 'valeur : ' . $pc[0] );
check( 'PC : extension / agrandissement à 649 €', 649 === $pc[1], 'valeur : ' . $pc[1] );
check( 'PC : maison individuelle à 849 €', 849 === $pc[2], 'valeur : ' . $pc[2] );

// --- Conception ------------------------------------------------------------
// Aucune exemption : le montant affiché est celui dont part le formulaire.
$conception = (int) $catalogue['conception']['prix'];

check(
	'Conception : le catalogue vaut exactement Pricing::BASE',
	Pricing::BASE === $conception,
	'catalogue = ' . $conception . ' · Pricing::BASE = ' . Pricing::BASE
);
check( 'Conception : 449 €', 449 === $conception, 'valeur : ' . $conception );

// --- Supplément ABF --------------------------------------------------------
$abf = (int) $catalogue['abf']['montant'];

check(
	'ABF : le catalogue vaut exactement PricingProjets::SUPPLEMENT_ABF',
	PricingProjets::SUPPLEMENT_ABF === $abf,
	'catalogue = ' . $abf . ' · greffon = ' . PricingProjets::SUPPLEMENT_ABF
);
check( 'ABF : +80 €', 80 === $abf, 'valeur : ' . $abf );

// --- L'ancien tarif ne doit pas revenir ------------------------------------
$tous = array_merge( $dp, $pc, array( $conception, $abf ) );
check(
	'Aucun forfait à 149 € (ancienne offre panneaux solaires)',
	! in_array( 149, $tous, true ),
	'montants : ' . implode( ', ', $tous )
);

echo "\n";

if ( $echecs ) {
	echo "$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

echo "TOUS LES CONTROLES PASSENT\n";
exit( 0 );
