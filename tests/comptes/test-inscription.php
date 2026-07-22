<?php
/**
 * Banc : l'inscription.
 *
 * Trois exigences y sont éprouvées : le rôle est contrôlé avant toute écriture,
 * l'identifiant technique est opaque et résiste aux collisions, et **un échec
 * après création ne détruit pas le compte**.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\JetonVerification as J;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Support\Logger;

$t   = 1785000000;
$mdp = 'motdepasse-assez-long';

/**
 * Monte un service d'inscription neuf.
 *
 * @return array{0: ComptesDouble, 1: PasserelleOptions, 2: InscriptionService}
 */
function inscription(): array {
	$comptes = new ComptesDouble();
	$db      = new PasserelleOptions();

	return array( $comptes, $db, new InscriptionService( $comptes, new VerificationService( $comptes, $db ) ) );
}

// ======================================================================
// 1 · INSCRIPTION NOMINALE
// ======================================================================
list( $c, $db, $service ) = inscription();

$r = $service->inscrire( '  Claire@Exemple.FR ', $mdp, $t );

check( '1 · le compte est créé', true === $r['cree'] );
check( '1 · un identifiant est rendu', $r['compte'] > 0 );
check( '1 · aucun motif d’échec', '' === $r['motif'] );
check( '1 · L’ADRESSE EST NORMALISÉE', 'claire@exemple.fr' === $c->utilisateurs[ $r['compte'] ]['adresse'] );
check( '1 · un jeton est préparé', null !== $r['emission'] && $r['emission']->est_prepare() );
check( '1 · LE COMPTE N’EST PAS VÉRIFIÉ',
	null === $c->lire_meta( $r['compte'], VerificationService::META_VERIFIE ) );

// ======================================================================
// 2 · IDENTIFIANT TECHNIQUE
// ======================================================================
$login = $c->utilisateurs[ $r['compte'] ]['login'];

check( '2 · il porte le préfixe attendu', 0 === strpos( $login, InscriptionService::PREFIXE_LOGIN ) );
check( '2 · il fait 30 caractères', 30 === strlen( $login ) );
check( '2 · il est alphanumérique et souligné', 1 === preg_match( '/^urb_[0-9a-z]{26}$/', $login ) );
check( '2 · L’ADRESSE N’EST PAS L’IDENTIFIANT', false === strpos( $login, '@' ) );

// Deux inscriptions donnent deux identifiants distincts.
list( $c2, $db2, $s2 ) = inscription();
$a = $s2->inscrire( 'a@exemple.fr', $mdp, $t );
$b = $s2->inscrire( 'b@exemple.fr', $mdp, $t );

check( '2 · deux comptes, deux identifiants',
	$c2->utilisateurs[ $a['compte'] ]['login'] !== $c2->utilisateurs[ $b['compte'] ]['login'] );

// Collision : les deux premières tentatives sont refusées, la troisième passe.
list( $c3, $db3, $s3 ) = inscription();
$c3->refuser_creations = 2;

$r3 = $s3->inscrire( 'collision@exemple.fr', $mdp, $t );

check( '2 · APRÈS DEUX COLLISIONS, LA TROISIÈME TENTATIVE RÉUSSIT', true === $r3['cree'] );

// Trois collisions : abandon.
list( $c4, $db4, $s4 ) = inscription();
$c4->refuser_creations = 3;

$r4 = $s4->inscrire( 'impossible@exemple.fr', $mdp, $t );

check( '2 · TROIS COLLISIONS : ABANDON', false === $r4['cree'] );
check( '2 · avec un motif technique', 'creation_echouee' === $r4['motif'] );

// ======================================================================
// 3 · MOT DE PASSE
// ======================================================================
list( $c5, $db5, $s5 ) = inscription();

check( '3 · onze caractères : refusé',
	'mot_de_passe_trop_court' === $s5->inscrire( 'x@exemple.fr', str_repeat( 'a', 11 ), $t )['motif'] );
check( '3 · douze caractères : accepté',
	true === $s5->inscrire( 'y@exemple.fr', str_repeat( 'a', 12 ), $t )['cree'] );
check( '3 · AUCUN COMPTE CRÉÉ SUR MOT DE PASSE TROP COURT',
	null === $c5->trouver_par_adresse( 'x@exemple.fr' ) );

// ======================================================================
// 4 · ADRESSE INVALIDE
// ======================================================================
list( $c6, $db6, $s6 ) = inscription();

check( '4 · une adresse invalide est refusée',
	'adresse_invalide' === $s6->inscrire( 'pas-une-adresse', $mdp, $t )['motif'] );
check( '4 · aucun compte créé', array() === $c6->utilisateurs );

// ======================================================================
// 5 · RÔLE NON CONFORME   ← rien ne doit être créé
// ======================================================================
list( $c7, $db7, $s7 ) = inscription();
$c7->role_conforme = false;

$r7 = $s7->inscrire( 'z@exemple.fr', $mdp, $t );

check( '5 · RÔLE ABSENT : L’INSCRIPTION EST REFUSÉE', false === $r7['cree'] );
check( '5 · avec le motif exact', 'role_non_conforme' === $r7['motif'] );
check( '5 · AUCUN COMPTE N’EST CRÉÉ', array() === $c7->utilisateurs );

// ======================================================================
// 6 · ADRESSE DÉJÀ EMPLOYÉE — SANS ÉNUMÉRATION
// ======================================================================
list( $c8, $db8, $s8 ) = inscription();
$premier = $s8->inscrire( 'occupee@exemple.fr', $mdp, $t );

// Compte non vérifié : un renvoi est tenté.
$second = $s8->inscrire( 'occupee@exemple.fr', $mdp, $t + 120 );

check( '6 · aucun second compte n’est créé', false === $second['cree'] );
check( '6 · le compte visé est le premier', $premier['compte'] === $second['compte'] );
check( '6 · UN RENVOI EST TENTÉ POUR UN COMPTE NON VÉRIFIÉ',
	null !== $second['emission'] && $second['emission']->est_prepare() );

// Compte vérifié : aucun courriel, jamais.
$c8->ecrire_meta( $premier['compte'], VerificationService::META_VERIFIE, '1' );
$troisieme = $s8->inscrire( 'occupee@exemple.fr', $mdp, $t + 240 );

check( '6 · AUCUN RENVOI POUR UN COMPTE VÉRIFIÉ', null === $troisieme['emission'] );
check( '6 · le motif reste technique', 'adresse_prise_verifiee' === $troisieme['motif'] );

// Même sur dix tentatives : aucun courriel préparé.
$prepares = 0;

for ( $i = 0; $i < 10; $i++ ) {
	$essai = $s8->inscrire( 'occupee@exemple.fr', $mdp, $t + 300 + $i );

	if ( null !== $essai['emission'] && $essai['emission']->est_prepare() ) {
		++$prepares;
	}
}

check( '6 · DIX TENTATIVES SUR UN COMPTE VÉRIFIÉ : ZÉRO PRÉPARATION', 0 === $prepares );

// ======================================================================
// 7 · ÉCHEC APRÈS CRÉATION — LE COMPTE SURVIT
// ======================================================================
list( $c9, $db9, $s9 ) = inscription();
$c9->ecritures_refusees = array( J::META_CONDENSAT );

$r9 = $s9->inscrire( 'fragile@exemple.fr', $mdp, $t );

check( '7 · LE COMPTE EST CRÉÉ MALGRÉ L’ÉCHEC D’ÉMISSION', true === $r9['cree'] );
check( '7 · l’échec est signalé', 'emission_echouee' === $r9['motif'] );
check( '7 · LE COMPTE N’EST PAS SUPPRIMÉ', null !== $c9->trouver_par_adresse( 'fragile@exemple.fr' ) );
check( '7 · il reste non vérifié',
	null === $c9->lire_meta( $r9['compte'], VerificationService::META_VERIFIE ) );

// Une fois la panne levée, un renvoi réussit : le compte était récupérable.
$c9->ecritures_refusees = array();
$verif = new VerificationService( $c9, $db9 );

check( '7 · UN RENVOI ULTÉRIEUR RÉUSSIT', $verif->preparer( $r9['compte'], $t + 200 )->est_prepare() );

// ======================================================================
// 8 · JOURNAL SANS DONNÉE PERSONNELLE
// ======================================================================
Logger::reset();

list( $c10, $db10, $s10 ) = inscription();
$c10->role_conforme = false;
$s10->inscrire( 'confidentielle@exemple.fr', 'motdepasse-tres-secret', $t );

$journal = Logger::tout();

check( '8 · le journal a reçu une ligne', '' !== $journal );
check( '8 · AUCUNE ADRESSE', false === strpos( $journal, 'confidentielle@exemple.fr' ) );
check( '8 · AUCUN MOT DE PASSE', false === strpos( $journal, 'motdepasse-tres-secret' ) );

verdict();
