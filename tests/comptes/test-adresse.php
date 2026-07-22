<?php
/**
 * Banc : l'adresse de courriel.
 *
 * L'enjeu tient en une phrase : le domaine **valide**, l'adaptateur
 * **normalise**. Une seule transformation, appliquée une seule fois.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Domain\Account\AdresseCourriel;

$adaptateur = new ComptesDouble();

// ======================================================================
// 1 · VALIDATION
// ======================================================================
check( '1 · une adresse ordinaire est acceptée', null !== AdresseCourriel::ou_null( 'claire@exemple.fr' ) );
check( '1 · une adresse vide est refusée', null === AdresseCourriel::ou_null( '' ) );
check( '1 · le motif du vide est nommé', 'adresse_vide' === AdresseCourriel::motif_de_refus( '' ) );
check( '1 · sans arobase, refusée', null === AdresseCourriel::ou_null( 'claire.exemple.fr' ) );
check( '1 · sans domaine, refusée', null === AdresseCourriel::ou_null( 'claire@' ) );
check( '1 · sans partie locale, refusée', null === AdresseCourriel::ou_null( '@exemple.fr' ) );
check( '1 · un espace interne est refusé', null === AdresseCourriel::ou_null( 'cla ire@exemple.fr' ) );
check( '1 · au-delà de 254 caractères, refusée',
	null === AdresseCourriel::ou_null( str_repeat( 'a', 250 ) . '@exemple.fr' ) );

// ======================================================================
// 2 · CARACTÈRES DE CONTRÔLE
// ======================================================================
check( '2 · UN RETOUR CHARIOT EST REFUSÉ',
	'adresse_caractere_de_controle' === AdresseCourriel::motif_de_refus( "claire@exemple.fr\r\nBcc: x@y.z" ) );
check( '2 · un saut de ligne aussi',
	'adresse_caractere_de_controle' === AdresseCourriel::motif_de_refus( "claire@exemple.fr\n" ) );
check( '2 · un octet nul aussi',
	'adresse_caractere_de_controle' === AdresseCourriel::motif_de_refus( "claire@exemple.fr\0" ) );

// ======================================================================
// 3 · LE DOMAINE NE NORMALISE PAS
// ======================================================================
// Une adresse en capitales n'est PAS transformée par le domaine : c'est le
// rôle de l'adaptateur. La refuser serait aussi faux que la corriger.
$capitales = AdresseCourriel::ou_null( 'Claire@Exemple.FR' );

check( '3 · le domaine accepte sans transformer', null !== $capitales );
check( '3 · IL NE MET PAS EN MINUSCULES', 'Claire@Exemple.FR' === (string) $capitales );

// L'adaptateur, lui, normalise.
$canonique = $adaptateur->canoniser( '  Claire@Exemple.FR  ' );

check( '3 · l’adaptateur abaisse la casse et coupe les espaces', 'claire@exemple.fr' === $canonique );
check( '3 · UNE ADRESSE NORMALISÉE EST ACCEPTÉE PAR LE DOMAINE',
	null !== AdresseCourriel::ou_null( $canonique ) );
check( '3 · l’adaptateur retire les caractères de contrôle',
	false === strpos( $adaptateur->canoniser( "claire@exemple.fr\r\n" ), "\r" ) );

// ======================================================================
// 4 · AUCUNE DOUBLE TRANSFORMATION
// ======================================================================
// Normaliser deux fois doit donner le même résultat ; et le domaine, recevant
// une valeur canonique, ne doit pas la modifier une seconde fois.
$une_fois   = $adaptateur->canoniser( '  Claire@Exemple.FR ' );
$deux_fois  = $adaptateur->canoniser( $une_fois );

check( '4 · la normalisation est idempotente', $une_fois === $deux_fois );
check( '4 · LE DOMAINE REND EXACTEMENT CE QU’IL A REÇU',
	$une_fois === (string) AdresseCourriel::ou_null( $une_fois ) );

// ======================================================================
// 5 · COMPARAISON
// ======================================================================
$a = new AdresseCourriel( 'claire@exemple.fr' );
$b = new AdresseCourriel( 'claire@exemple.fr' );
$c = new AdresseCourriel( 'paul@exemple.fr' );

check( '5 · deux adresses identiques se reconnaissent', $a->est_la_meme_que( $b ) );
check( '5 · deux adresses différentes non', false === $a->est_la_meme_que( $c ) );
check( '5 · LA COMPARAISON EST SENSIBLE À LA CASSE',
	false === $a->est_la_meme_que( new AdresseCourriel( 'Claire@exemple.fr' ) ) );

// ======================================================================
// 6 · CONSTRUCTION STRICTE
// ======================================================================
$leve = false;

try {
	new AdresseCourriel( 'pas-une-adresse' );
} catch ( InvalidArgumentException $e ) {
	$leve = true;
}

check( '6 · le constructeur lève sur une adresse invalide', $leve );

// ======================================================================
// 7 · FRONTIÈRE — LE DOMAINE NE DÉPEND PAS DE WORDPRESS
// ======================================================================
$source = (string) file_get_contents( URBIZEN_SRC . 'Domain/Account/AdresseCourriel.php' );
$code   = (string) preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#' ), '', $source );

foreach ( array( 'sanitize_email', 'is_email', 'wp_', 'apply_filters', 'Validator', 'Forms\\' ) as $interdit ) {
	check(
		sprintf( '7 · AdresseCourriel n’emploie pas « %s »', $interdit ),
		false === strpos( $code, $interdit )
	);
}

verdict();
