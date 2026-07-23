<?php
/**
 * Banc : la confirmation de quota est idempotente et rejouable.
 *
 * Le défaut d'origine tenait en une phrase : `_urbizen_verif_envois` ne portait
 * que des horodatages. Si l'écriture du quota réussissait et que l'effacement
 * de l'émission échouait, une seconde confirmation décomptait un second
 * créneau — rien dans l'état ne disait que celui-ci avait déjà servi.
 *
 * Ce qu'on éprouve ici :
 *
 * - la source `_urbizen_verif_emissions` est la SEULE lue pour décider ;
 * - le miroir `_urbizen_verif_envois` n'est jamais un recours ;
 * - absente n'est pas corrompue ;
 * - l'émission en attente n'est effacée que si source ET miroir ont été écrits ;
 * - le rejeu reconnaît l'identifiant et n'ajoute aucun second créneau.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\EmissionEnAttente;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\VerificationService;

$t = 1785000000;

/**
 * Monte un compte non vérifié et son service.
 *
 * @return array{0: ComptesDouble, 1: PasserelleOptions, 2: VerificationService, 3: int}
 */
function monter_idem(): array {
	$comptes = new ComptesDouble();
	$db      = new PasserelleOptions();
	$id      = $comptes->creer( 'urb_x', 'claire@exemple.fr', 'motdepasse-long' );

	return array( $comptes, $db, new VerificationService( $comptes, $db ), $id );
}

/**
 * Source décodée du compte.
 *
 * @param ComptesDouble $comptes Doublure.
 * @param int           $id      Compte.
 * @return array{entrees: array<int, array{a: int, e: string}>, corrompue: bool, absente: bool}
 */
function source_de( ComptesDouble $comptes, int $id ): array {
	return LimiteEnvois::decoder_source( $comptes->lire_meta( $id, LimiteEnvois::META_SOURCE ) );
}

/**
 * Miroir décodé du compte.
 *
 * @param ComptesDouble $comptes Doublure.
 * @param int           $id      Compte.
 * @return array<int, int>
 */
function miroir_de( ComptesDouble $comptes, int $id ): array {
	return LimiteEnvois::decoder( $comptes->lire_meta( $id, LimiteEnvois::META ) )['horodatages'];
}

// ======================================================================
// 1 · UNE CONFIRMATION NORMALE ÉCRIT LES DEUX VUES
// ======================================================================
list( $c1, $d1, $s1, $i1 ) = monter_idem();

$r1 = $s1->preparer( $i1, $t );
check( '1 · préparation acceptée', $r1->est_prepare() );

check( '1 · confirmation acceptée', true === $s1->confirmer_emission( $i1, $r1->emission_id(), $t + 1 ) );

$src = source_de( $c1, $i1 );
check( '1 · la source porte un créneau NOMMÉ',
	1 === count( $src['entrees'] ) && $r1->emission_id() === $src['entrees'][0]['e'] );
check( '1 · le miroir dérive exactement de la source',
	LimiteEnvois::horodatages_de( $src['entrees'] ) === miroir_de( $c1, $i1 ) );
check( '1 · l\'émission en attente est effacée',
	null === $c1->lire_meta( $i1, EmissionEnAttente::META ) );

// ======================================================================
// 2 · LE SCÉNARIO EN TROIS TEMPS — miroir en échec, puis rejeu
// ======================================================================
list( $c2, $d2, $s2, $i2 ) = monter_idem();

$r2 = $s2->preparer( $i2, $t );

// Temps 1 : le miroir refuse l'écriture. La source, elle, passe.
$c2->ecritures_refusees = array( LimiteEnvois::META );

check( '2 · miroir en échec : la confirmation REND FAUX',
	false === $s2->confirmer_emission( $i2, $r2->emission_id(), $t + 1 ) );
check( '2 · mais la source a bien enregistré le créneau',
	1 === count( source_de( $c2, $i2 )['entrees'] ) );
check( '2 · ET L\'ÉMISSION N\'EST PAS SUPPRIMÉE — c\'est elle qui permet le rejeu',
	null !== $c2->lire_meta( $i2, EmissionEnAttente::META ) );
check( '2 · le miroir est resté vide', array() === miroir_de( $c2, $i2 ) );

// Temps 2 : le miroir redevient écrivable, on rejoue.
$c2->ecritures_refusees = array();

check( '2 · le rejeu aboutit', true === $s2->confirmer_emission( $i2, $r2->emission_id(), $t + 2 ) );

$src2 = source_de( $c2, $i2 );
check( '2 · AUCUN SECOND CRÉNEAU : l\'identifiant a été reconnu',
	1 === count( $src2['entrees'] ) );
check( '2 · le créneau garde son horodatage d\'origine',
	$t + 1 === $src2['entrees'][0]['a'] );
check( '2 · le miroir est réécrit en entier depuis la source',
	LimiteEnvois::horodatages_de( $src2['entrees'] ) === miroir_de( $c2, $i2 ) );
check( '2 · l\'émission est enfin effacée',
	null === $c2->lire_meta( $i2, EmissionEnAttente::META ) );

// ======================================================================
// 3 · LE CAS D'ORIGINE — les deux vues écrites, effacement manqué
// ======================================================================
list( $c3, $d3, $s3, $i3 ) = monter_idem();

$r3 = $s3->preparer( $i3, $t );
check( '3 · première confirmation', true === $s3->confirmer_emission( $i3, $r3->emission_id(), $t + 1 ) );

// On remet l'émission en place : c'est exactement ce que laisse un effacement
// qui a échoué sans être détecté.
$c3->metas[ $i3 ][ EmissionEnAttente::META ] = EmissionEnAttente::encoder(
	$r3->emission_id(),
	$r3->generation(),
	$r3->cible(),
	$t
);

check( '3 · la confirmation rejouée aboutit',
	true === $s3->confirmer_emission( $i3, $r3->emission_id(), $t + 2 ) );
check( '3 · SANS SECOND CRÉNEAU — le défaut d\'origine est clos',
	1 === count( source_de( $c3, $i3 )['entrees'] ) );

// ======================================================================
// 4 · AMORÇAGE DEPUIS LE MIROIR HÉRITÉ
// ======================================================================
list( $c4, $d4, $s4, $i4 ) = monter_idem();

// Un compte venu de 0.11.0 : miroir garni, source absente.
$c4->metas[ $i4 ][ LimiteEnvois::META ] = LimiteEnvois::encoder( array( $t - 1000, $t - 500 ) );

check( '4 · source absente au départ', true === source_de( $c4, $i4 )['absente'] );

$r4 = $s4->preparer( $i4, $t );
check( '4 · la préparation compte les créneaux hérités et reste permise',
	$r4->est_prepare() );

check( '4 · confirmation', true === $s4->confirmer_emission( $i4, $r4->emission_id(), $t + 1 ) );

$src4 = source_de( $c4, $i4 );
check( '4 · les deux créneaux hérités ont été amorcés, plus le nouveau',
	3 === count( $src4['entrees'] ) );

$vides = 0;

foreach ( $src4['entrees'] as $entree ) {
	if ( '' === $entree['e'] ) {
		$vides++;
	}
}

check( '4 · les hérités portent un identifiant VIDE', 2 === $vides );
check( '4 · et ne seront JAMAIS reconnus comme déjà confirmés',
	false === LimiteEnvois::contient_emission( $src4['entrees'], '' ) );
check( '4 · le quota est désormais épuisé',
	'quota_epuise' === LimiteEnvois::motif_de_refus(
		LimiteEnvois::etat_depuis_source( $src4 ), $t + 2 ) );

// ======================================================================
// 5 · UNE SOURCE CORROMPUE FERME. LE MIROIR N'EST JAMAIS UN RECOURS.
// ======================================================================
list( $c5, $d5, $s5, $i5 ) = monter_idem();

// Miroir parfaitement lisible et VIDE : s'il était lu en recours, la
// préparation serait permise. Elle ne doit pas l'être.
$c5->metas[ $i5 ][ LimiteEnvois::META ]        = LimiteEnvois::encoder( array() );
$c5->metas[ $i5 ][ LimiteEnvois::META_SOURCE ] = 'nawak';

$r5 = $s5->preparer( $i5, $t );
check( '5 · SOURCE CORROMPUE : la préparation est refusée',
	! $r5->est_prepare() );
check( '5 · et le motif nomme le quota illisible',
	'quota_illisible' === $r5->motif() );
check( '5 · LE MIROIR VIDE N\'A PAS SERVI DE RECOURS',
	'nawak' === $c5->lire_meta( $i5, LimiteEnvois::META_SOURCE ) );

// ======================================================================
// 6 · AU-DELÀ DE MAX, LA SOURCE EST CORROMPUE, DONC PLEINE
// ======================================================================
list( $c6, $d6, $s6, $i6 ) = monter_idem();

$c6->metas[ $i6 ][ LimiteEnvois::META_SOURCE ] = (string) json_encode(
	array(
		array( 'a' => $t - 4, 'e' => 'a' ),
		array( 'a' => $t - 3, 'e' => 'b' ),
		array( 'a' => $t - 2, 'e' => 'c' ),
		array( 'a' => $t - 1, 'e' => 'd' ),
	)
);

$r6 = $s6->preparer( $i6, $t );
check( '6 · quatre entrées : refusé', ! $r6->est_prepare() );
check( '6 · traité comme illisible, jamais tronqué', 'quota_illisible' === $r6->motif() );

// ======================================================================
// 7 · UNE SOURCE ILLISIBLE REFUSE AUSSI LA CONFIRMATION
// ======================================================================
list( $c7, $d7, $s7, $i7 ) = monter_idem();

$r7 = $s7->preparer( $i7, $t );

// La source se corrompt entre la préparation et la confirmation.
$c7->metas[ $i7 ][ LimiteEnvois::META_SOURCE ] = '{"a":1}';

check( '7 · confirmation refusée sur source illisible',
	false === $s7->confirmer_emission( $i7, $r7->emission_id(), $t + 1 ) );
check( '7 · l\'émission reste posée pour un rejeu ultérieur',
	null !== $c7->lire_meta( $i7, EmissionEnAttente::META ) );

// ======================================================================
// 8 · LA SOURCE EN ÉCHEC N'ÉCRIT PAS LE MIROIR
// ======================================================================
list( $c8, $d8, $s8, $i8 ) = monter_idem();

$r8                     = $s8->preparer( $i8, $t );
$c8->ecritures_refusees = array( LimiteEnvois::META_SOURCE );

check( '8 · source en échec : confirmation refusée',
	false === $s8->confirmer_emission( $i8, $r8->emission_id(), $t + 1 ) );
check( '8 · LE MIROIR N\'A PAS DEVANCÉ SA SOURCE', array() === miroir_de( $c8, $i8 ) );
check( '8 · et l\'émission reste posée',
	null !== $c8->lire_meta( $i8, EmissionEnAttente::META ) );

// ======================================================================
// 9 · CONSOMMER TRANSMET L'IDENTIFIANT D'ÉMISSION
// ======================================================================
list( $c9, $d9, $s9, $i9 ) = monter_idem();

$r9 = $s9->preparer( $i9, $t );

// Le client clique AVANT que l'appelant n'ait confirmé : le créneau doit être
// décompté là, sous l'identifiant de cette émission.
check( '9 · consommation réussie', '' === $s9->consommer( $i9, $r9->jeton(), $t + 1 ) );

$src9 = source_de( $c9, $i9 );
check( '9 · le créneau est décompté sous l\'identifiant de l\'émission',
	1 === count( $src9['entrees'] ) && $r9->emission_id() === $src9['entrees'][0]['e'] );
check( '9 · le miroir suit', LimiteEnvois::horodatages_de( $src9['entrees'] ) === miroir_de( $c9, $i9 ) );

// ======================================================================
// 10 · ÉGALITÉ DES DEUX VUES APRÈS CHAQUE OPÉRATION RÉUSSIE
// ======================================================================
list( $ca, $da, $sa, $ia ) = monter_idem();

$egales = true;

for ( $n = 0; $n < 3; $n++ ) {
	$r = $sa->preparer( $ia, $t + ( $n * 200 ) );

	if ( ! $r->est_prepare() ) {
		break;
	}

	$sa->confirmer_emission( $ia, $r->emission_id(), $t + ( $n * 200 ) + 1 );

	if ( LimiteEnvois::horodatages_de( source_de( $ca, $ia )['entrees'] ) !== miroir_de( $ca, $ia ) ) {
		$egales = false;
	}
}

check( '10 · source et miroir restent égaux après chaque opération réussie', $egales );
check( '10 · trois créneaux consommés', 3 === count( source_de( $ca, $ia )['entrees'] ) );

// ======================================================================
// 11 · INSPECTION — lecture seule, et aucune fuite d'adresse
// ======================================================================
list( $cb, $db, $sb, $ib ) = monter_idem();

$rb = $sb->preparer( $ib, $t );

// Un faux jeton BIEN FORMÉ, sur un VRAI identifiant.
$faux = str_repeat( 'ab', 32 );

$insp = $sb->inspecter( $ib, $faux, $t + 1 );
check( '11 · FAUX JETON BIEN FORMÉ : refusé', 'jeton_invalide' === $insp['motif'] );
check( '11 · ET AUCUNE ADRESSE N\'EST RENDUE', '' === $insp['cible'] );

// Compte absent : MÊME issue qu'un jeton invalide.
check( '11 · compte absent : même motif public',
	'jeton_invalide' === $sb->inspecter( 999999, $faux, $t + 1 )['motif'] );
check( '11 · et aucune adresse', '' === $sb->inspecter( 999999, $faux, $t + 1 )['cible'] );

check( '11 · identifiant nul refusé', 'jeton_invalide' === $sb->inspecter( 0, $faux, $t )['motif'] );
check( '11 · jeton de forme invalide refusé',
	'jeton_invalide' === $sb->inspecter( $ib, 'court', $t )['motif'] );

// Le vrai jeton, lui, rend la bonne cible.
$ok = $sb->inspecter( $ib, $rb->jeton(), $t + 1 );
check( '11 · JETON VALIDE : accepté', '' === $ok['motif'] );
check( '11 · et il rend LA BONNE CIBLE', 'claire@exemple.fr' === $ok['cible'] );

// Expiré.
check( '11 · jeton expiré rend « expire »',
	'jeton_expire' === $sb->inspecter( $ib, $rb->jeton(), $t + 90000 )['motif'] );

// ======================================================================
// 12 · L'INSPECTION N'ÉCRIT ABSOLUMENT RIEN
// ======================================================================
list( $cc, $dc, $sc, $ic ) = monter_idem();

$rc    = $sc->preparer( $ic, $t );
$avant = $cc->metas[ $ic ];

$sc->inspecter( $ic, $rc->jeton(), $t + 1 );
$sc->inspecter( $ic, $faux, $t + 1 );
$sc->inspecter( 999999, $faux, $t + 1 );

check( '12 · TOUTES LES MÉTADONNÉES SONT IDENTIQUES avant et après',
	$avant === $cc->metas[ $ic ] );
check( '12 · l\'émission en attente est intacte',
	null !== $cc->lire_meta( $ic, EmissionEnAttente::META ) );
check( '12 · le quota n\'a pas bougé',
	null === $cc->lire_meta( $ic, LimiteEnvois::META_SOURCE ) );
check( '12 · le compte n\'est pas vérifié',
	null === $cc->lire_meta( $ic, VerificationService::META_VERIFIE ) );
check( '12 · le jeton reste consommable après inspection',
	'' === $sc->consommer( $ic, $rc->jeton(), $t + 2 ) );

// ======================================================================
// 13 · LE QUOTA EST CLOS AVANT TOUTE MUTATION IRRÉVERSIBLE
// ======================================================================
list( $cd, $dd, $sd, $id ) = monter_idem();

$rd = $sd->preparer( $id, $t );

// Le destinataire clique AVANT que l'émetteur n'ait confirmé.
check( '13 · consommation réussie', '' === $sd->consommer( $id, $rd->jeton(), $t + 1 ) );
check( '13 · EXACTEMENT UN CRÉNEAU', 1 === count( source_de( $cd, $id )['entrees'] ) );
check( '13 · sous l\'identifiant de l\'émission',
	$rd->emission_id() === source_de( $cd, $id )['entrees'][0]['e'] );

// Une confirmation TARDIVE de l'émetteur n'ajoute rien.
$sd->confirmer_emission( $id, $rd->emission_id(), $t + 2 );
check( '13 · une confirmation tardive N\'AJOUTE PAS de second créneau',
	1 === count( source_de( $cd, $id )['entrees'] ) );

// ── Échec d'écriture de la SOURCE : rien n'est vérifié ────────────────
list( $ce, $de, $se, $ie ) = monter_idem();

$re                     = $se->preparer( $ie, $t );
$ce->ecritures_refusees = array( LimiteEnvois::META_SOURCE );

check( '13 · SOURCE EN ÉCHEC : la consommation est refusée',
	'quota_non_clos' === $se->consommer( $ie, $re->jeton(), $t + 1 ) );
check( '13 · LE COMPTE N\'EST PAS VÉRIFIÉ',
	null === $ce->lire_meta( $ie, VerificationService::META_VERIFIE ) );
check( '13 · LE JETON EST CONSERVÉ',
	null !== $ce->lire_meta( $ie, Urbizen\Platform\Account\JetonVerification::META_CONDENSAT ) );
check( '13 · L\'ÉMISSION EST CONSERVÉE',
	null !== $ce->lire_meta( $ie, EmissionEnAttente::META ) );

// ── Source écrite, MIROIR en échec : un créneau, puis rejeu ───────────
list( $cf, $df, $sf, $if ) = monter_idem();

$rf                     = $sf->preparer( $if, $t );
$cf->ecritures_refusees = array( LimiteEnvois::META );

check( '13 · MIROIR EN ÉCHEC : la consommation est refusée',
	'quota_non_clos' === $sf->consommer( $if, $rf->jeton(), $t + 1 ) );
check( '13 · mais la source porte UN créneau', 1 === count( source_de( $cf, $if )['entrees'] ) );
check( '13 · le compte n\'est toujours pas vérifié',
	null === $cf->lire_meta( $if, VerificationService::META_VERIFIE ) );

// Le rejeu du MÊME clic répare et va jusqu'au bout.
$cf->ecritures_refusees = array();

check( '13 · LE REJEU DU MÊME CLIC ABOUTIT', '' === $sf->consommer( $if, $rf->jeton(), $t + 2 ) );
check( '13 · AUCUN SECOND CRÉNEAU', 1 === count( source_de( $cf, $if )['entrees'] ) );
check( '13 · source et miroir sont désormais identiques',
	LimiteEnvois::horodatages_de( source_de( $cf, $if )['entrees'] ) === miroir_de( $cf, $if ) );
check( '13 · le compte est vérifié',
	'1' === $cf->lire_meta( $if, VerificationService::META_VERIFIE ) );
check( '13 · le jeton est supprimé',
	null === $cf->lire_meta( $if, Urbizen\Platform\Account\JetonVerification::META_CONDENSAT ) );
check( '13 · l\'émission est supprimée',
	null === $cf->lire_meta( $if, EmissionEnAttente::META ) );

verdict();
