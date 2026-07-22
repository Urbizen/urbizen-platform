<?php
/**
 * Banc : émission et consommation.
 *
 * Deux contrôles portent la sûreté du service :
 *
 *   section 4 — la RELECTURE SOUS VERROU. Un processus qui a lu un jeton avant
 *   d'acquérir le verrou doit être refusé si l'état a changé entre-temps ;
 *
 *   section 5 — un échec ne laisse RIEN de définitif. Le jeton reste valide,
 *   le quota reste intact, un nouvel essai réussit.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\JetonVerification as J;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Support\Logger;

$t = 1785000000;

/**
 * Monte un compte non vérifié.
 *
 * @param string $adresse Adresse.
 * @return array{0: ComptesDouble, 1: PasserelleOptions, 2: VerificationService, 3: int}
 */
function monter( string $adresse = 'claire@exemple.fr' ): array {
	$comptes = new ComptesDouble();
	$db      = new PasserelleOptions();
	$id      = $comptes->creer( 'urb_x', $adresse, 'motdepasse-long' );

	return array( $comptes, $db, new VerificationService( $comptes, $db ), $id );
}

// ======================================================================
// 1 · PRÉPARATION
// ======================================================================
list( $comptes, $db, $service, $id ) = monter();

$r = $service->preparer( $id, $t );

check( '1 · la préparation réussit', $r->est_prepare() );
check( '1 · elle rend un jeton bien formé', J::forme_valide( $r->jeton() ) );
check( '1 · la cible est l’adresse du compte', 'claire@exemple.fr' === $r->cible() );
check( '1 · l’échéance est à 24 h', $t + J::TTL === $r->expire_le() );
check( '1 · le condensat est stocké', null !== $comptes->lire_meta( $id, J::META_CONDENSAT ) );
check( '1 · LE JETON BRUT N’EST PAS STOCKÉ',
	false === strpos( json_encode( $comptes->metas ), $r->jeton() ) );
check( '1 · LE QUOTA N’EST PAS ENCORE CONSOMMÉ',
	null === $comptes->lire_meta( $id, LimiteEnvois::META ) );
check( '1 · le verrou est libéré', array() === $db->options );

// ======================================================================
// 2 · CONFIRMATION ET ANNULATION
// ======================================================================
check( '2 · la confirmation réussit', $service->confirmer_emission( $id, $t ) );
check( '2 · le quota porte un horodatage',
	array( $t ) === LimiteEnvois::decoder( $comptes->lire_meta( $id, LimiteEnvois::META ) )['horodatages'] );

list( $c2, $db2, $s2, $id2 ) = monter( 'paul@exemple.fr' );
$s2->preparer( $id2, $t );
$s2->annuler_emission( $id2 );

check( '2 · L’ANNULATION NE CONSOMME PAS LE QUOTA', null === $c2->lire_meta( $id2, LimiteEnvois::META ) );
check( '2 · et le jeton reste valide', null !== $c2->lire_meta( $id2, J::META_CONDENSAT ) );

// ======================================================================
// 3 · CONSOMMATION
// ======================================================================
list( $c3, $db3, $s3, $id3 ) = monter( 'anne@exemple.fr' );
$r3 = $s3->preparer( $id3, $t );

check( '3 · un jeton valide est consommé', '' === $s3->consommer( $id3, $r3->jeton(), $t + 10 ) );
check( '3 · le compte est vérifié',
	'1' === $c3->lire_meta( $id3, VerificationService::META_VERIFIE ) );
check( '3 · la date est consignée', null !== $c3->lire_meta( $id3, VerificationService::META_VERIFIE_LE ) );
check( '3 · LE CONDENSAT EST SUPPRIMÉ', null === $c3->lire_meta( $id3, J::META_CONDENSAT ) );
check( '3 · UNE SECONDE UTILISATION ÉCHOUE',
	'jeton_absent' === $s3->consommer( $id3, $r3->jeton(), $t + 20 ) );
check( '3 · un jeton malformé est refusé avant tout accès',
	'jeton_invalide' === $s3->consommer( $id3, 'court', $t + 20 ) );

// Un AUTRE jeton, alors qu'un jeton valide est bien en place.
list( $c3b, $db3b, $s3b, $id3b ) = monter( 'yann@exemple.fr' );
$s3b->preparer( $id3b, $t );

check( '3 · UN AUTRE JETON EST REFUSÉ ALORS QU’UN VALIDE EXISTE',
	'jeton_invalide' === $s3b->consommer( $id3b, J::engendrer(), $t + 10 ) );
check( '3 · et le jeton légitime reste en place', null !== $c3b->lire_meta( $id3b, J::META_CONDENSAT ) );

// Expiration.
list( $c4, $db4, $s4, $id4 ) = monter( 'luc@exemple.fr' );
$r4 = $s4->preparer( $id4, $t );

check( '3 · un jeton expiré est refusé', 'jeton_expire' === $s4->consommer( $id4, $r4->jeton(), $t + J::TTL + 1 ) );
check( '3 · et le compte reste non vérifié',
	null === $c4->lire_meta( $id4, VerificationService::META_VERIFIE ) );

// ======================================================================
// 4 · RELECTURE SOUS VERROU   ← CONTRÔLE CENTRAL
// ======================================================================
// P1 obtient un jeton. P2 en émet un nouveau, qui remplace le premier.
// P1 présente ensuite le sien : il doit être refusé après relecture.
list( $c5, $db5, $s5, $id5 ) = monter( 'marie@exemple.fr' );

$ancien = $s5->preparer( $id5, $t );
$nouveau = $s5->preparer( $id5, $t + 120 );

check( '4 · P2 a bien obtenu un second jeton', $nouveau->est_prepare() );
check( '4 · les deux jetons diffèrent', $ancien->jeton() !== $nouveau->jeton() );
check( '4 · P1 EST REFUSÉ APRÈS RELECTURE SOUS VERROU',
	'jeton_invalide' === $s5->consommer( $id5, $ancien->jeton(), $t + 130 ) );
check( '4 · le compte n’est pas vérifié par l’ancien jeton',
	null === $c5->lire_meta( $id5, VerificationService::META_VERIFIE ) );
check( '4 · le nouveau jeton, lui, fonctionne',
	'' === $s5->consommer( $id5, $nouveau->jeton(), $t + 140 ) );

// La génération s'incrémente : un ancien condensat n'est pas recalculable.
check( '4 · la génération a augmenté',
	(int) $c5->lire_meta( $id5, J::META_GENERATION ) >= 2 );

// ======================================================================
// 5 · ÉCHECS PARTIELS — RIEN DE DÉFINITIF
// ======================================================================
// Le drapeau de vérification ne peut pas être écrit : rien ne doit être
// supprimé, et un nouvel essai doit réussir une fois la panne levée.
list( $c6, $db6, $s6, $id6 ) = monter( 'jean@exemple.fr' );
$r6 = $s6->preparer( $id6, $t );

$c6->ecritures_refusees = array( VerificationService::META_VERIFIE );

check( '5 · l’écriture du drapeau échoue proprement',
	'ecriture_verifie_echouee' === $s6->consommer( $id6, $r6->jeton(), $t + 10 ) );
check( '5 · LE CONDENSAT N’EST PAS SUPPRIMÉ', null !== $c6->lire_meta( $id6, J::META_CONDENSAT ) );
check( '5 · le compte n’est pas vérifié', null === $c6->lire_meta( $id6, VerificationService::META_VERIFIE ) );
check( '5 · le verrou est libéré malgré l’échec', array() === $db6->options );

$c6->ecritures_refusees = array();

check( '5 · UNE FOIS LA PANNE LEVÉE, LE MÊME JETON RÉUSSIT',
	'' === $s6->consommer( $id6, $r6->jeton(), $t + 20 ) );

// Écriture partielle à l'émission : l'état ne doit pas rester à demi valide.
list( $c7, $db7, $s7, $id7 ) = monter( 'zoe@exemple.fr' );
$c7->ecritures_refusees = array( J::META_CIBLE );

$r7 = $s7->preparer( $id7, $t );

check( '5 · une écriture partielle refuse la préparation', false === $r7->est_prepare() );
check( '5 · le motif est explicite', 'ecriture_incomplete' === $r7->motif() );
check( '5 · AUCUN ÉTAT PARTIEL NE SUBSISTE', null === $c7->lire_meta( $id7, J::META_CONDENSAT ) );

// ======================================================================
// 6 · VERROU OCCUPÉ
// ======================================================================
list( $c8, $db8, $s8, $id8 ) = monter( 'ines@exemple.fr' );

Urbizen\Platform\Account\VerrouCompte::acquerir( $db8, $id8, $t );

check( '6 · préparation refusée si le verrou est tenu',
	'verrou_indisponible' === $s8->preparer( $id8, $t )->motif() );
check( '6 · consommation refusée aussi',
	'verrou_indisponible' === $s8->consommer( $id8, J::engendrer(), $t ) );

// ======================================================================
// 7 · LE JOURNAL NE PORTE AUCUNE DONNÉE PERSONNELLE
// ======================================================================
Logger::reset();

list( $c9, $db9, $s9, $id9 ) = monter( 'secrete@exemple.fr' );
$r9 = $s9->preparer( $id9, $t );

// Le condensat est relevé AVANT consommation : après, il est effacé, et
// chercher une chaîne vide rendrait toujours la position zéro.
$condensat9 = (string) $c9->lire_meta( $id9, J::META_CONDENSAT );

$s9->consommer( $id9, $r9->jeton(), $t + 10 );
$s9->annuler_emission( $id9 );

$journal = Logger::tout();

check( '7 · le journal a bien reçu des lignes', '' !== $journal );
check( '7 · AUCUNE ADRESSE DANS LE JOURNAL', false === strpos( $journal, 'secrete@exemple.fr' ) );
check( '7 · AUCUN JETON DANS LE JOURNAL', false === strpos( $journal, $r9->jeton() ) );
check( '7 · AUCUN CONDENSAT NON PLUS', '' !== $condensat9 && false === strpos( $journal, $condensat9 ) );

verdict();
