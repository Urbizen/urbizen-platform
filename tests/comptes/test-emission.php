<?php
/**
 * Banc : protocole d'émission — préparer / confirmer / annuler.
 *
 * Le contrôle central est la section 1 : **une seule émission peut être en vol
 * à la fois**. C'est la propriété que la confirmation après envoi ne pouvait pas
 * tenir, parce qu'elle arrive trop tard. Sans elle, la séquence
 *
 *     P1 prépare A · P2 prépare B et remplace A · P1 envoie A · P2 envoie B
 *
 * fait partir deux courriels dont l'un porte un lien déjà mort.
 *
 * La section 6 mérite un mot. « Aucun premier lien n'est invalidé par une
 * seconde préparation » ne peut être vrai sans réserve : deux liens vivants
 * simultanément sont précisément ce qu'on interdit. La forme démontrable, et
 * celle qui protège réellement, est qu'**aucune seconde préparation n'est
 * autorisée tant que le premier lien est en vol**. Après une confirmation
 * explicite, un renvoi délibéré invalide le précédent : c'est voulu, et c'est
 * éprouvé comme tel.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\EmissionEnAttente as E;
use Urbizen\Platform\Account\JetonVerification as J;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\VerificationService;

$t = 1785000000;

/**
 * Monte un compte non vérifié.
 *
 * @param string $adresse Adresse.
 * @return array{0: ComptesDouble, 1: PasserelleOptions, 2: VerificationService, 3: int}
 */
function monter_e( string $adresse ): array {
	$comptes = new ComptesDouble();
	$db      = new PasserelleOptions();
	$id      = $comptes->creer( 'urb_e', $adresse, 'motdepasse-long' );

	return array( $comptes, $db, new VerificationService( $comptes, $db ), $id );
}

/**
 * Horodatages du quota.
 *
 * @param ComptesDouble $comptes Doublure.
 * @param int           $id      Identifiant.
 * @return array<int, int>
 */
function quota( ComptesDouble $comptes, int $id ): array {
	return LimiteEnvois::decoder( $comptes->lire_meta( $id, LimiteEnvois::META ) )['horodatages'];
}

// ======================================================================
// 1 · UNE SEULE ÉMISSION EN VOL   ← CONTRÔLE CENTRAL
// ======================================================================
list( $c1, $db1, $s1, $id1 ) = monter_e( 'un@exemple.fr' );

$a = $s1->preparer( $id1, $t );

check( '1 · P1 prépare A', $a->est_prepare() );

// P2 arrive AVANT toute confirmation.
$b = $s1->preparer( $id1, $t + 1 );

check( '1 · P2 EST REFUSÉ TANT QUE A N’EST PAS CLOSE', false === $b->est_prepare() );
check( '1 · le motif le dit', 'emission_en_attente' === $b->motif() );
check( '1 · P2 n’a reçu aucun jeton', '' === $b->jeton() );
check( '1 · LE JETON DE P1 EST INTACT',
	'' === $s1->consommer( $id1, $a->jeton(), $t + 2 ) );

// Reprise propre : P1 confirme, puis le délai minimal s'applique.
list( $c1b, $db1b, $s1b, $id1b ) = monter_e( 'unb@exemple.fr' );

$a1 = $s1b->preparer( $id1b, $t );

check( '1 · P1 confirme', $s1b->confirmer_emission( $id1b, $a1->emission_id(), $t ) );
check( '1 · le quota porte un créneau', array( $t ) === quota( $c1b, $id1b ) );

$c1b_apres = $s1b->preparer( $id1b, $t + 30 );

check( '1 · UNE NOUVELLE PRÉPARATION RESTE SOUMISE AU DÉLAI DE 60 s',
	false === $c1b_apres->est_prepare() && 'delai_minimal' === $c1b_apres->motif() );
check( '1 · passé le délai, elle est acceptée',
	$s1b->preparer( $id1b, $t + 61 )->est_prepare() );

// ======================================================================
// 2 · ANNULATION
// ======================================================================
list( $c2, $db2, $s2, $id2 ) = monter_e( 'deux@exemple.fr' );

$a2 = $s2->preparer( $id2, $t );

check( '2 · l’annulation réussit', $s2->annuler_emission( $id2, $a2->emission_id(), $t ) );
check( '2 · UNE ÉMISSION ANNULÉE NE CONSOMME AUCUN QUOTA',
	null === $c2->lire_meta( $id2, LimiteEnvois::META ) );
check( '2 · le jeton annulé est détruit', null === $c2->lire_meta( $id2, J::META_CONDENSAT ) );
check( '2 · et n’est plus consommable',
	'jeton_absent' === $s2->consommer( $id2, $a2->jeton(), $t + 1 ) );

$b2 = $s2->preparer( $id2, $t + 1 );

check( '2 · P2 PEUT PRÉPARER APRÈS UNE ANNULATION', $b2->est_prepare() );
check( '2 · SANS ATTENDRE LE DÉLAI MINIMAL, puisque rien n’a été envoyé',
	$b2->est_prepare() && ( ( $t + 1 ) - $t ) < LimiteEnvois::DELAI_MINIMAL );
check( '2 · alors qu’une émission CONFIRMÉE, elle, l’imposerait',
	'delai_minimal' === ( function () use ( $t ) {
		list( $cx, $dbx, $sx, $idx ) = monter_e( 'deuxbis@exemple.fr' );
		$e = $sx->preparer( $idx, $t );
		$sx->confirmer_emission( $idx, $e->emission_id(), $t );

		return $sx->preparer( $idx, $t + 1 )->motif();
	} )() );
check( '2 · le nouveau jeton fonctionne', '' === $s2->consommer( $id2, $b2->jeton(), $t + 2 ) );

// ======================================================================
// 3 · MORT DU PROCESSUS AVANT CLÔTURE
// ======================================================================
list( $c3, $db3, $s3, $id3 ) = monter_e( 'trois@exemple.fr' );

$a3 = $s3->preparer( $id3, $t );

// P1 meurt ici : ni confirmation, ni annulation. Le verrou, lui, a été libéré.
check( '3 · l’émission reste posée', null !== $c3->lire_meta( $id3, E::META ) );
check( '3 · et bloque encore avant expiration',
	'emission_en_attente' === $s3->preparer( $id3, $t + E::TTL - 1 )->motif() );

$b3 = $s3->preparer( $id3, $t + E::TTL + 1 );

check( '3 · APRÈS EXPIRATION, P2 NETTOIE ET PRÉPARE', $b3->est_prepare() );
check( '3 · L’ÉMISSION MORTE N’A CONSOMMÉ AUCUN QUOTA',
	null === $c3->lire_meta( $id3, LimiteEnvois::META ) );
check( '3 · l’émission posée est bien la nouvelle',
	$b3->emission_id() !== $a3->emission_id()
	&& E::decoder( $c3->lire_meta( $id3, E::META ) )['id'] === $b3->emission_id() );
check( '3 · LE JETON ORPHELIN EST INVALIDÉ AVANT LE NOUVEAU',
	'jeton_invalide' === $s3->consommer( $id3, $a3->jeton(), $t + E::TTL + 2 ) );
check( '3 · le nouveau jeton, lui, fonctionne',
	'' === $s3->consommer( $id3, $b3->jeton(), $t + E::TTL + 3 ) );

// ======================================================================
// 4 · UN ANCIEN IDENTIFIANT NE CLÔT PAS UNE ÉMISSION RÉCENTE
// ======================================================================
list( $c4, $db4, $s4, $id4 ) = monter_e( 'quatre@exemple.fr' );

$vieux = $s4->preparer( $id4, $t );
$s4->confirmer_emission( $id4, $vieux->emission_id(), $t );
$recent = $s4->preparer( $id4, $t + 61 );

check( '4 · une seconde émission existe', $recent->est_prepare() );
check( '4 · les identifiants diffèrent', $vieux->emission_id() !== $recent->emission_id() );
check( '4 · UN ANCIEN IDENTIFIANT NE CONFIRME PAS L’ÉMISSION RÉCENTE',
	false === $s4->confirmer_emission( $id4, $vieux->emission_id(), $t + 62 ) );
check( '4 · le quota n’a pas bougé', 1 === count( quota( $c4, $id4 ) ) );
check( '4 · UN ANCIEN IDENTIFIANT N’ANNULE PAS L’ÉMISSION RÉCENTE',
	false === $s4->annuler_emission( $id4, $vieux->emission_id(), $t + 62 ) );
check( '4 · l’émission récente est toujours en attente',
	null !== $c4->lire_meta( $id4, E::META ) );
check( '4 · et son jeton est intact', null !== $c4->lire_meta( $id4, J::META_CONDENSAT ) );
check( '4 · le titulaire, lui, confirme',
	$s4->confirmer_emission( $id4, $recent->emission_id(), $t + 63 ) );

// Un identifiant inventé ne vaut pas mieux.
list( $c4b, $db4b, $s4b, $id4b ) = monter_e( 'quatreb@exemple.fr' );
$a4b = $s4b->preparer( $id4b, $t );

check( '4 · un identifiant inventé ne confirme rien',
	false === $s4b->confirmer_emission( $id4b, 'AAAAAAAAAAAAAAAAAAAAAAAAAA', $t ) );
check( '4 · ni un identifiant vide',
	false === $s4b->confirmer_emission( $id4b, '', $t ) );
check( '4 · ni n’annule quoi que ce soit',
	false === $s4b->annuler_emission( $id4b, '', $t ) );
check( '4 · l’émission légitime tient toujours', null !== $c4b->lire_meta( $id4b, E::META ) );

// ======================================================================
// 5 · DEUX CLÔTURES CONCURRENTES
// ======================================================================
// Les deux appels partagent la MÊME table d'options : le verrou les sérialise
// exactement comme deux processus. Le second doit trouver l'émission close.
list( $c5, $db5, $s5, $id5 ) = monter_e( 'cinq@exemple.fr' );

$a5 = $s5->preparer( $id5, $t );

$p1 = $s5->confirmer_emission( $id5, $a5->emission_id(), $t );
$p2 = $s5->confirmer_emission( $id5, $a5->emission_id(), $t );

check( '5 · la première confirmation passe', true === $p1 );
check( '5 · LA SECONDE EST SANS EFFET', false === $p2 );
check( '5 · LE QUOTA N’EST CONSOMMÉ QU’UNE FOIS', 1 === count( quota( $c5, $id5 ) ) );

list( $c5b, $db5b, $s5b, $id5b ) = monter_e( 'cinqb@exemple.fr' );

$a5b = $s5b->preparer( $id5b, $t );

$q1 = $s5b->annuler_emission( $id5b, $a5b->emission_id(), $t );
$q2 = $s5b->annuler_emission( $id5b, $a5b->emission_id(), $t );

check( '5 · la première annulation passe', true === $q1 );
check( '5 · la seconde est sans effet', false === $q2 );
check( '5 · AUCUN ÉTAT N’EST CORROMPU', null === $c5b->lire_meta( $id5b, E::META ) );
check( '5 · aucun quota consommé', null === $c5b->lire_meta( $id5b, LimiteEnvois::META ) );
check( '5 · et une préparation reste possible', $s5b->preparer( $id5b, $t )->est_prepare() );

// ======================================================================
// 6 · GÉNÉRATION ET CIBLE RECONTRÔLÉES À LA CONFIRMATION
// ======================================================================
list( $c6, $db6, $s6, $id6 ) = monter_e( 'six@exemple.fr' );

$a6 = $s6->preparer( $id6, $t );

// La génération stockée n'est plus celle de l'émission.
$c6->ecrire_meta( $id6, J::META_GENERATION, '99' );

check( '6 · UNE CONFIRMATION AVEC MAUVAISE GÉNÉRATION EST REFUSÉE',
	false === $s6->confirmer_emission( $id6, $a6->emission_id(), $t ) );
check( '6 · le quota n’a pas bougé', null === $c6->lire_meta( $id6, LimiteEnvois::META ) );

list( $c6b, $db6b, $s6b, $id6b ) = monter_e( 'sixb@exemple.fr' );

$a6b = $s6b->preparer( $id6b, $t );
$c6b->ecrire_meta( $id6b, J::META_CIBLE, 'ailleurs@exemple.fr' );

check( '6 · UNE CONFIRMATION AVEC MAUVAISE CIBLE EST REFUSÉE',
	false === $s6b->confirmer_emission( $id6b, $a6b->emission_id(), $t ) );
check( '6 · le quota n’a pas bougé non plus', null === $c6b->lire_meta( $id6b, LimiteEnvois::META ) );

// Jeton entièrement disparu : rien à confirmer.
list( $c6c, $db6c, $s6c, $id6c ) = monter_e( 'sixc@exemple.fr' );

$a6c = $s6c->preparer( $id6c, $t );
$c6c->supprimer_meta( $id6c, J::META_CIBLE );

check( '6 · sans cible stockée, la confirmation est refusée',
	false === $s6c->confirmer_emission( $id6c, $a6c->emission_id(), $t ) );

// ======================================================================
// 7 · LE PREMIER LIEN N’EST JAMAIS EMPORTÉ PAR UNE PRÉPARATION CONCURRENTE
// ======================================================================
list( $c7, $db7, $s7, $id7 ) = monter_e( 'sept@exemple.fr' );

$premier = $s7->preparer( $id7, $t );

// Toutes les tentatives concurrentes, sur toute la durée de vie de l'émission.
$refus = 0;

foreach ( array( 1, 30, 60, 120, E::TTL - 1 ) as $decalage ) {
	if ( ! $s7->preparer( $id7, $t + $decalage )->est_prepare() ) {
		++$refus;
	}
}

check( '7 · AUCUNE PRÉPARATION CONCURRENTE N’EST AUTORISÉE', 5 === $refus );
check( '7 · LE PREMIER LIEN RESTE VALIDE TOUT DU LONG',
	'' === $s7->consommer( $id7, $premier->jeton(), $t + E::TTL - 1 ) );

// Après confirmation explicite, un renvoi délibéré invalide le précédent :
// c'est la contrepartie assumée de « un seul lien vivant à la fois ».
list( $c7b, $db7b, $s7b, $id7b ) = monter_e( 'septb@exemple.fr' );

$un   = $s7b->preparer( $id7b, $t );
$s7b->confirmer_emission( $id7b, $un->emission_id(), $t );
$deux = $s7b->preparer( $id7b, $t + 61 );

check( '7 · un renvoi délibéré est possible', $deux->est_prepare() );
check( '7 · et invalide le précédent, comme voulu',
	'jeton_invalide' === $s7b->consommer( $id7b, $un->jeton(), $t + 62 ) );
check( '7 · seul le dernier lien vit', '' === $s7b->consommer( $id7b, $deux->jeton(), $t + 63 ) );

// ======================================================================
// 8 · QUATRIÈME ÉMISSION CONFIRMÉE SUR 24 HEURES
// ======================================================================
list( $c8, $db8, $s8, $id8 ) = monter_e( 'huit@exemple.fr' );

$acceptees = 0;

for ( $n = 0; $n < 3; $n++ ) {
	$e = $s8->preparer( $id8, $t + ( $n * 100 ) );

	if ( $e->est_prepare() && $s8->confirmer_emission( $id8, $e->emission_id(), $t + ( $n * 100 ) ) ) {
		++$acceptees;
	}
}

check( '8 · trois émissions sont confirmées', 3 === $acceptees );
check( '8 · le quota porte trois créneaux', 3 === count( quota( $c8, $id8 ) ) );

$quatrieme = $s8->preparer( $id8, $t + 400 );

check( '8 · LA QUATRIÈME EST REFUSÉE', false === $quatrieme->est_prepare() );
check( '8 · pour épuisement du quota', 'quota_epuise' === $quatrieme->motif() );
check( '8 · et rien n’a été posé', null === $c8->lire_meta( $id8, E::META ) );
check( '8 · une fois la fenêtre passée, la préparation repasse',
	$s8->preparer( $id8, $t + LimiteEnvois::FENETRE + 401 )->est_prepare() );

// Les annulations, elles, ne comptent pas.
list( $c8b, $db8b, $s8b, $id8b ) = monter_e( 'huitb@exemple.fr' );

for ( $n = 0; $n < 5; $n++ ) {
	$e = $s8b->preparer( $id8b, $t + $n );
	$s8b->annuler_emission( $id8b, $e->emission_id(), $t + $n );
}

check( '8 · CINQ ANNULATIONS NE CONSOMMENT AUCUN QUOTA',
	null === $c8b->lire_meta( $id8b, LimiteEnvois::META ) );
check( '8 · et la sixième préparation passe encore',
	$s8b->preparer( $id8b, $t + 5 )->est_prepare() );

// ======================================================================
// 9 · LA CONSOMMATION VAUT PREUVE D’ENVOI
// ======================================================================
// Cliquer plus vite que l'appelant ne confirme ne doit pas rendre le créneau
// gratuit : sans cela, l'opération répétée viderait le quota de sa fonction.
list( $c9, $db9, $s9, $id9 ) = monter_e( 'neuf@exemple.fr' );

$a9 = $s9->preparer( $id9, $t );

check( '9 · le quota est vide avant consommation', null === $c9->lire_meta( $id9, LimiteEnvois::META ) );
check( '9 · la consommation réussit', '' === $s9->consommer( $id9, $a9->jeton(), $t + 5 ) );
check( '9 · ELLE A DÉCOMPTÉ LE CRÉNEAU', array( $t + 5 ) === quota( $c9, $id9 ) );
check( '9 · et clos l’émission en attente', null === $c9->lire_meta( $id9, E::META ) );
check( '9 · la confirmation tardive ne redouble pas le décompte',
	false === $s9->confirmer_emission( $id9, $a9->emission_id(), $t + 6 )
	&& 1 === count( quota( $c9, $id9 ) ) );

// ======================================================================
// 10 · ÉMISSION EN ATTENTE ILLISIBLE
// ======================================================================
// Personne ne peut plus la clore : aucun identifiant valide ne lui correspond.
// Elle est donc déclarée expirée — nettoyable, jeton compris — plutôt que de
// condamner le compte. Deux jetons vivants restent impossibles.
list( $c10, $db10, $s10, $id10 ) = monter_e( 'dix@exemple.fr' );

$a10 = $s10->preparer( $id10, $t );
$c10->ecrire_meta( $id10, E::META, '{{{ pas du json' );

check( '10 · une émission illisible ne se confirme pas',
	false === $s10->confirmer_emission( $id10, $a10->emission_id(), $t ) );
check( '10 · ni ne s’annule',
	false === $s10->annuler_emission( $id10, $a10->emission_id(), $t ) );

$b10 = $s10->preparer( $id10, $t + 1 );

check( '10 · LE COMPTE N’EST PAS CONDAMNÉ', $b10->est_prepare() );
check( '10 · et l’ancien jeton est parti avec elle',
	'jeton_invalide' === $s10->consommer( $id10, $a10->jeton(), $t + 2 ) );

// Une liste JSON n'est pas une émission ; un statut inconnu non plus.
foreach ( array( '[1,2,3]', '{"id":"x","generation":1,"cible":"a@b.fr","cree_le":1,"expire_le":2,"statut":"autre"}' ) as $rang => $brut ) {
	check(
		sprintf( '10 · forme refusée n° %d', $rang + 1 ),
		true === E::decoder( $brut )['corrompue']
	);
}

check( '10 · une valeur absente reste absente', null === E::decoder( null ) );
check( '10 · une chaîne vide aussi', null === E::decoder( '' ) );

$valide = E::decoder( E::encoder( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 4, 'a@b.fr', $t ) );

check( '10 · un encodage maison se relit', false === $valide['corrompue'] );
check( '10 · l’échéance est à ' . E::TTL . ' s', $t + E::TTL === $valide['expire_le'] );

// Le jeton brut ne doit apparaître dans l'émission en attente ni en clair, ni
// sous forme de condensat : elle n'a aucune raison de le porter.
list( $c11, $db11, $s11, $id11 ) = monter_e( 'onze@exemple.fr' );

$a11        = $s11->preparer( $id11, $t );
$stockee    = (string) $c11->lire_meta( $id11, E::META );
$condensat  = (string) $c11->lire_meta( $id11, J::META_CONDENSAT );

check( '10 · l’émission stockée est bien lisible', '' !== $stockee );
check( '10 · LE JETON BRUT N’Y FIGURE PAS', false === strpos( $stockee, $a11->jeton() ) );
check( '10 · SON CONDENSAT NON PLUS',
	'' !== $condensat && false === strpos( $stockee, $condensat ) );

verdict();
