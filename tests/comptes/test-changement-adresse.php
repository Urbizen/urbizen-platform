<?php
/**
 * Banc : la demande de changement d'adresse.
 *
 * Un seul point porte tout le reste : **validation, disponibilité, écriture de
 * la cible et préparation de l'émission se font sous UNE seule acquisition du
 * verrou**. Deux acquisitions successives laisseraient une demande concurrente
 * remplacer la cible entre son enregistrement et la création du jeton — et le
 * lien confirmerait alors une adresse que personne n'a demandée.
 *
 * On éprouve aussi que l'adresse du compte ne bouge pas avant consommation, et
 * qu'un refus de préparation ne laisse derrière lui aucune adresse en attente
 * orpheline.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\EmissionEnAttente;
use Urbizen\Platform\Account\JetonVerification;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Account\VerrouCompte;

$t = 1785000000;

/**
 * Monte un compte vérifié, prêt pour un changement.
 *
 * @return array{0: ComptesDouble, 1: PasserelleOptions, 2: VerificationService, 3: int}
 */
function monter_chg(): array {
	$comptes = new ComptesDouble();
	$db      = new PasserelleOptions();
	$id      = $comptes->creer( 'urb_x', 'claire@exemple.fr', 'motdepasse-long' );

	$comptes->metas[ $id ][ VerificationService::META_VERIFIE ] = VerificationService::VALEUR_VERIFIE;

	return array( $comptes, $db, new VerificationService( $comptes, $db ), $id );
}

// ======================================================================
// 1 · LE CHEMIN NOMINAL
// ======================================================================
list( $c1, $d1, $s1, $i1 ) = monter_chg();

$r1 = $s1->demander_changement_adresse( $i1, 'Nouvelle@Exemple.FR', $t );

check( '1 · demande acceptée', '' === $r1['motif'] );
check( '1 · l\'ancienne adresse est rendue à l\'appelant', 'claire@exemple.fr' === $r1['ancienne'] );
check( '1 · une émission est préparée', null !== $r1['emission'] && $r1['emission']->est_prepare() );
check( '1 · LA CIBLE EST LA NOUVELLE ADRESSE',
	'nouvelle@exemple.fr' === $r1['emission']->cible() );
check( '1 · l\'adresse en attente est enregistrée',
	'nouvelle@exemple.fr' === $c1->lire_meta( $i1, VerificationService::META_EN_ATTENTE ) );
check( '1 · L\'ADRESSE DU COMPTE N\'A PAS BOUGÉ',
	'claire@exemple.fr' === $c1->trouver_par_id( $i1 )->adresse()->valeur() );
check( '1 · le jeton est lié à la nouvelle adresse',
	'nouvelle@exemple.fr' === $c1->lire_meta( $i1, JetonVerification::META_CIBLE ) );
check( '1 · le verrou est libéré avant tout envoi',
	null !== VerrouCompte::acquerir( $d1, $i1, $t ) );

// ======================================================================
// 2 · VALIDATION ET DISPONIBILITÉ
// ======================================================================
list( $c2, $d2, $s2, $i2 ) = monter_chg();

check( '2 · adresse invalide refusée',
	'adresse_invalide' === $s2->demander_changement_adresse( $i2, 'pas-une-adresse', $t )['motif'] );
check( '2 · rien n\'est écrit après un refus de validation',
	null === $c2->lire_meta( $i2, VerificationService::META_EN_ATTENTE ) );

check( '2 · adresse identique refusée',
	'adresse_inchangee' === $s2->demander_changement_adresse( $i2, 'claire@exemple.fr', $t )['motif'] );

// Une autre personne occupe déjà l'adresse visée.
$c2->creer( 'urb_y', 'occupee@exemple.fr', 'motdepasse-long' );

check( '2 · ADRESSE DÉJÀ PRISE : refusée',
	'adresse_indisponible' === $s2->demander_changement_adresse( $i2, 'occupee@exemple.fr', $t )['motif'] );
check( '2 · et aucune cible n\'est laissée derrière',
	null === $c2->lire_meta( $i2, VerificationService::META_EN_ATTENTE ) );

check( '2 · compte absent refusé',
	'compte_absent' === $s2->demander_changement_adresse( 99999, 'x@exemple.fr', $t )['motif'] );

// ======================================================================
// 3 · UN REFUS DE PRÉPARATION RESTAURE LA CIBLE
// ======================================================================
list( $c3, $d3, $s3, $i3 ) = monter_chg();

// Quota épuisé : la préparation refusera après l'écriture de la cible.
$c3->metas[ $i3 ][ LimiteEnvois::META_SOURCE ] = LimiteEnvois::encoder_source(
	array(
		array( 'a' => $t - 30, 'e' => 'a' ),
		array( 'a' => $t - 20, 'e' => 'b' ),
		array( 'a' => $t - 10, 'e' => 'c' ),
	)
);

$r3 = $s3->demander_changement_adresse( $i3, 'nouvelle@exemple.fr', $t );

check( '3 · quota épuisé : la demande est refusée', 'quota_epuise' === $r3['motif'] );
check( '3 · LA CIBLE EST RESTAURÉE — aucune adresse en attente orpheline',
	null === $c3->lire_meta( $i3, VerificationService::META_EN_ATTENTE ) );
check( '3 · aucun jeton n\'a survécu',
	null === $c3->lire_meta( $i3, JetonVerification::META_CONDENSAT ) );

// Restauration d'une valeur précédente, et non simple suppression.
list( $c3b, $d3b, $s3b, $i3b ) = monter_chg();

$c3b->metas[ $i3b ][ VerificationService::META_EN_ATTENTE ]  = 'ancienne-demande@exemple.fr';
$c3b->metas[ $i3b ][ LimiteEnvois::META_SOURCE ]             = LimiteEnvois::encoder_source(
	array(
		array( 'a' => $t - 30, 'e' => 'a' ),
		array( 'a' => $t - 20, 'e' => 'b' ),
		array( 'a' => $t - 10, 'e' => 'c' ),
	)
);

$s3b->demander_changement_adresse( $i3b, 'nouvelle@exemple.fr', $t );

check( '3 · une cible PRÉCÉDENTE est remise telle quelle, pas effacée',
	'ancienne-demande@exemple.fr' === $c3b->lire_meta( $i3b, VerificationService::META_EN_ATTENTE ) );

// ======================================================================
// 4 · LA CIBLE NE PEUT PAS ÊTRE REMPLACÉE EN COURS DE ROUTE
// ======================================================================
list( $c4, $d4, $s4, $i4 ) = monter_chg();

/*
 * Le piège se déclenche à la première lecture de l'adresse en attente,
 * c'est-à-dire À L'INTÉRIEUR de la section critique, avant l'écriture de la
 * cible et avant la fabrication du jeton.
 *
 * Une demande concurrente y tente d'imposer une autre cible. Le verrou n'ayant
 * pas été relâché entre l'écriture et la préparation, elle ne peut pas
 * l'obtenir : elle est refusée, et le jeton reste lié à l'adresse de la
 * demande gagnante. C'est précisément la fenêtre qu'une seconde acquisition du
 * verrou rouvrirait.
 */
$refus_concurrent = '';

$c4->piege = array(
	'cle'    => VerificationService::META_EN_ATTENTE,
	'rappel' => static function ( ComptesDouble $comptes, int $id ) use ( $d4, $t, &$refus_concurrent ): void {
		$service          = new VerificationService( $comptes, $d4 );
		$concurrent       = $service->demander_changement_adresse( $id, 'pirate@exemple.fr', $t );
		$refus_concurrent = $concurrent['motif'];
	},
);

$r4 = $s4->demander_changement_adresse( $i4, 'legitime@exemple.fr', $t );

check( '4 · la demande légitime aboutit', '' === $r4['motif'] );
check( '4 · LA DEMANDE CONCURRENTE EST REFUSÉE sur le verrou',
	'verrou_indisponible' === $refus_concurrent );
check( '4 · LA CIBLE N\'A PAS ÉTÉ REMPLACÉE',
	'legitime@exemple.fr' === $c4->lire_meta( $i4, VerificationService::META_EN_ATTENTE ) );
check( '4 · et le jeton confirme bien CETTE cible-là',
	'legitime@exemple.fr' === $r4['emission']->cible()
	&& 'legitime@exemple.fr' === $c4->lire_meta( $i4, JetonVerification::META_CIBLE ) );

// ======================================================================
// 5 · UN SECOND CHANGEMENT INVALIDE PROPREMENT LE PREMIER
// ======================================================================
list( $c5, $d5, $s5, $i5 ) = monter_chg();

$premier = $s5->demander_changement_adresse( $i5, 'une@exemple.fr', $t );
check( '5 · premier changement préparé', '' === $premier['motif'] );

$generation_1 = (int) $c5->lire_meta( $i5, JetonVerification::META_GENERATION );

// Tant que l'émission est en vol, aucune seconde préparation n'est possible.
$pendant = $s5->demander_changement_adresse( $i5, 'autre@exemple.fr', $t + 10 );
check( '5 · pendant qu\'une émission est en vol : REFUSÉ',
	'emission_en_attente' === $pendant['motif'] );
check( '5 · la première cible tient bon',
	'une@exemple.fr' === $c5->lire_meta( $i5, VerificationService::META_EN_ATTENTE ) );

// L'émission est close ; le délai minimal passé, un second changement passe.
$s5->confirmer_emission( $i5, $premier['emission']->emission_id(), $t + 1 );

$second = $s5->demander_changement_adresse( $i5, 'autre@exemple.fr', $t + 200 );
check( '5 · une fois l\'émission close, le second changement passe', '' === $second['motif'] );
check( '5 · LA GÉNÉRATION A AVANCÉ — l\'ancien condensat est mort',
	(int) $c5->lire_meta( $i5, JetonVerification::META_GENERATION ) > $generation_1 );
check( '5 · le premier jeton ne vérifie plus rien',
	'' !== $s5->consommer( $i5, $premier['emission']->jeton(), $t + 201 ) );
check( '5 · la cible est désormais la seconde adresse',
	'autre@exemple.fr' === $c5->lire_meta( $i5, VerificationService::META_EN_ATTENTE ) );

// ======================================================================
// 6 · SEULE LA CONSOMMATION PROMEUT L'ADRESSE
// ======================================================================
list( $c6, $d6, $s6, $i6 ) = monter_chg();

$r6 = $s6->demander_changement_adresse( $i6, 'promue@exemple.fr', $t );

check( '6 · avant consommation, l\'adresse du compte est l\'ancienne',
	'claire@exemple.fr' === $c6->trouver_par_id( $i6 )->adresse()->valeur() );

check( '6 · consommation réussie', '' === $s6->consommer( $i6, $r6['emission']->jeton(), $t + 1 ) );

check( '6 · APRÈS consommation, l\'adresse est promue',
	'promue@exemple.fr' === $c6->trouver_par_id( $i6 )->adresse()->valeur() );
check( '6 · et l\'adresse en attente est effacée',
	null === $c6->lire_meta( $i6, VerificationService::META_EN_ATTENTE ) );
check( '6 · l\'émission en attente est close',
	null === $c6->lire_meta( $i6, EmissionEnAttente::META ) );

verdict();
