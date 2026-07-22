<?php
/**
 * Banc : le jeton de vérification.
 *
 * Le contrôle décisif est en section 3 : **un jeton émis pour une cible ne peut
 * pas en confirmer une autre**. Sans cette liaison, il suffirait de demander un
 * changement d'adresse, de recevoir le lien, puis d'en demander un second avant
 * de cliquer.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Account\JetonVerification as J;

// ======================================================================
// 1 · FORME
// ======================================================================
$jeton = J::engendrer();

check( '1 · 64 caractères hexadécimaux, soit 256 bits', 64 === strlen( $jeton ) );
check( '1 · alphabet hexadécimal minuscule', 1 === preg_match( '/^[0-9a-f]{64}$/', $jeton ) );
check( '1 · il se reconnaît lui-même', J::forme_valide( $jeton ) );
check( '1 · trop court : refusé', false === J::forme_valide( substr( $jeton, 0, 63 ) ) );
check( '1 · trop long : refusé', false === J::forme_valide( $jeton . 'a' ) );
check( '1 · majuscules : refusées', false === J::forme_valide( strtoupper( $jeton ) ) );
check( '1 · vide : refusé', false === J::forme_valide( '' ) );

// ======================================================================
// 2 · UNICITÉ
// ======================================================================
$vus = array();

for ( $i = 0; $i < 20000; $i++ ) {
	$vus[ J::engendrer() ] = true;
}

check( '2 · 20 000 tirages, aucune collision', 20000 === count( $vus ) );

// ======================================================================
// 3 · LIAISON À LA CIBLE   ← CONTRÔLE CENTRAL
// ======================================================================
$t = J::engendrer();
$c = J::condensat( 42, 'claire@exemple.fr', 1, $t );

check( '3 · le jeton correspond à son condensat',
	J::correspond( $c, 42, 'claire@exemple.fr', 1, $t ) );
check( '3 · UNE AUTRE CIBLE NE CORRESPOND PAS',
	false === J::correspond( $c, 42, 'paul@exemple.fr', 1, $t ) );
check( '3 · UN AUTRE COMPTE NON PLUS',
	false === J::correspond( $c, 43, 'claire@exemple.fr', 1, $t ) );
check( '3 · UNE AUTRE GÉNÉRATION NON PLUS',
	false === J::correspond( $c, 42, 'claire@exemple.fr', 2, $t ) );
check( '3 · un autre jeton non plus',
	false === J::correspond( $c, 42, 'claire@exemple.fr', 1, J::engendrer() ) );
check( '3 · un condensat vide ne correspond jamais',
	false === J::correspond( '', 42, 'claire@exemple.fr', 1, $t ) );
check( '3 · un jeton malformé ne correspond jamais',
	false === J::correspond( $c, 42, 'claire@exemple.fr', 1, 'court' ) );

// Deux cibles proches produisent des condensats sans rapport.
$c1 = J::condensat( 42, 'claire@exemple.fr', 1, $t );
$c2 = J::condensat( 42, 'claire@exemple.fr.', 1, $t );

check( '3 · une cible presque identique donne un condensat sans rapport', $c1 !== $c2 );

// ======================================================================
// 4 · LE JETON BRUT N’APPARAÎT PAS DANS LE CONDENSAT
// ======================================================================
check( '4 · LE CONDENSAT NE CONTIENT PAS LE JETON', false === strpos( $c, $t ) );
check( '4 · ni l’adresse', false === strpos( $c, 'claire@exemple.fr' ) );
check( '4 · il fait 64 caractères hexadécimaux', 1 === preg_match( '/^[0-9a-f]{64}$/', $c ) );

// ======================================================================
// 5 · COMPARAISON EN TEMPS CONSTANT
// ======================================================================
$source = (string) file_get_contents( URBIZEN_SRC . 'Account/JetonVerification.php' );
$code   = (string) preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#' ), '', $source );

check( '5 · la comparaison emploie hash_equals', false !== strpos( $code, 'hash_equals' ) );
check( '5 · AUCUNE COMPARAISON DIRECTE DE CONDENSATS',
	false === preg_match( '/\$attendu\s*===/', $code ) || 0 === preg_match( '/\$attendu\s*===/', $code ) );
check( '5 · aucun repli sur mt_rand', false === strpos( $code, 'mt_rand' ) );
check( '5 · l’aléa est cryptographique', false !== strpos( $code, 'random_bytes' ) );

// ======================================================================
// 6 · DÉTERMINISME
// ======================================================================
check( '6 · le condensat est reproductible',
	J::condensat( 42, 'a@b.fr', 3, $t ) === J::condensat( 42, 'a@b.fr', 3, $t ) );

verdict();
