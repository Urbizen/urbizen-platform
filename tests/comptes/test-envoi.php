<?php
/**
 * Banc : la séquence d'émission.
 *
 * Quatre règles, et aucune n'admet d'exception :
 *
 * 1. un refus de préparation n'envoie rien et ne clôt rien ;
 * 2. `ok = false` ET une exception du transport mènent au MÊME appel —
 *    `annuler_emission()`, immédiatement ;
 * 3. **après `ok = true`, l'annulation est interdite**, même si la clôture
 *    échoue : le message est parti, le lien est peut-être déjà dans une boîte,
 *    et annuler détruirait le jeton d'un lien vivant ;
 * 4. rien ne s'intercale entre le retour de l'envoi et la clôture — prouvé par
 *    journal d'ordre, comme la relecture sous verrou en E2.1.
 *
 * Le transport est une doublure fidèle du contrat. Aucun message ne quitte la
 * machine, et la fonction d'envoi de WordPress n'est jamais appelée.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\EmissionEnAttente;
use Urbizen\Platform\Account\EnvoiVerification;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\VerificationService;

$t    = 1785000000;
$base = 'https://exemple.fr/wp-admin/admin-post.php';

/**
 * Monte un compte, son service et son transport.
 *
 * @return array{0: ComptesDouble, 1: PasserelleOptions, 2: VerificationService, 3: TransportDouble, 4: EnvoiVerification, 5: int}
 */
function monter_envoi(): array {
	global $base;

	$comptes = new ComptesDouble();
	$db      = new PasserelleOptions();
	$service = new VerificationService( $comptes, $db );
	$tr      = new TransportDouble();
	$envoi   = new EnvoiVerification( $service, $tr, $base, 'Urbizen' );
	$id      = $comptes->creer( 'urb_x', 'claire@exemple.fr', 'motdepasse-long' );

	return array( $comptes, $db, $service, $tr, $envoi, $id );
}

// ======================================================================
// 1 · LE CHEMIN NOMINAL
// ======================================================================
list( $c1, $d1, $s1, $tr1, $e1, $i1 ) = monter_envoi();

JournalOrdre::armer();
$r1 = $e1->emettre( $i1, $t );
JournalOrdre::reset();

check( '1 · émission réussie', true === $r1['ok'] && '' === $r1['motif'] );
check( '1 · un seul message remis au transport', 1 === count( $tr1->messages ) );
check( '1 · remis à la bonne adresse', 'claire@exemple.fr' === $tr1->messages[0]['destinataire'] );
check( '1 · le corps porte le lien', false !== strpos( $tr1->messages[0]['corps'], 'action=urbizen_verification' ) );
check( '1 · le créneau est décompté', 1 === count(
	LimiteEnvois::decoder_source( $c1->lire_meta( $i1, LimiteEnvois::META_SOURCE ) )['entrees'] ) );
check( '1 · l\'émission est close', null === $c1->lire_meta( $i1, EmissionEnAttente::META ) );

// ======================================================================
// 2 · UN REFUS DE PRÉPARATION N'ENVOIE RIEN ET NE CLÔT RIEN
// ======================================================================
list( $c2, $d2, $s2, $tr2, $e2, $i2 ) = monter_envoi();

$c2->metas[ $i2 ][ LimiteEnvois::META_SOURCE ] = LimiteEnvois::encoder_source(
	array(
		array( 'a' => $t - 30, 'e' => 'a' ),
		array( 'a' => $t - 20, 'e' => 'b' ),
		array( 'a' => $t - 10, 'e' => 'c' ),
	)
);

$r2 = $e2->emettre( $i2, $t );

check( '2 · quota épuisé : refusé', false === $r2['ok'] && 'quota_epuise' === $r2['motif'] );
check( '2 · AUCUN message n\'a été remis', array() === $tr2->messages );
check( '2 · aucune émission n\'a été posée', null === $c2->lire_meta( $i2, EmissionEnAttente::META ) );

// ======================================================================
// 3 · ok = false → ANNULATION IMMÉDIATE
// ======================================================================
list( $c3, $d3, $s3, $tr3, $e3, $i3 ) = monter_envoi();

$tr3->reponses = array( array( 'ok' => false, 'code' => 'transport_refused' ) );

JournalOrdre::armer();
$r3    = $e3->emettre( $i3, $t );
$suite = JournalOrdre::$suite;
JournalOrdre::reset();

check( '3 · échec signalé', false === $r3['ok'] && 'envoi_echoue' === $r3['motif'] );
check( '3 · le code technique est remonté', 'transport_refused' === $r3['code'] );
check( '3 · le quota reste INTACT', null === $c3->lire_meta( $i3, LimiteEnvois::META_SOURCE ) );
check( '3 · l\'émission est annulée', null === $c3->lire_meta( $i3, EmissionEnAttente::META ) );
check( '3 · le jeton est détruit', null === $c3->lire_meta( $i3, 'urbizen_verif_condensat' )
	&& null === $c3->lire_meta( $i3, Urbizen\Platform\Account\JetonVerification::META_CONDENSAT ) );

$position = array_search( 'send', $suite, true );
check( '3 · RIEN NE S\'INTERCALE : l\'opération suivant l\'envoi appartient à l\'annulation',
	false !== $position
	&& isset( $suite[ $position + 1 ] )
	&& 0 === strpos( (string) $suite[ $position + 1 ], 'supprimer:' ) );

// ======================================================================
// 4 · UNE EXCEPTION EST UN ÉCHEC, SANS DISTINCTION
// ======================================================================
list( $c4, $d4, $s4, $tr4, $e4, $i4 ) = monter_envoi();

$tr4->reponses = array( new RuntimeException( 'smtp injoignable' ) );

$r4 = $e4->emettre( $i4, $t );

check( '4 · l\'exception ne remonte pas', false === $r4['ok'] );
check( '4 · elle mène au MÊME motif qu\'un refus', 'envoi_echoue' === $r4['motif'] );
check( '4 · avec un code qui la nomme', 'transport_exception' === $r4['code'] );
check( '4 · L\'ÉMISSION EST ANNULÉE — le compte n\'est pas fermé cinq minutes pour rien',
	null === $c4->lire_meta( $i4, EmissionEnAttente::META ) );
check( '4 · et le quota reste intact', null === $c4->lire_meta( $i4, LimiteEnvois::META_SOURCE ) );

// ======================================================================
// 5 · APRÈS ok = true, L'ANNULATION EST INTERDITE
// ======================================================================
list( $c5, $d5, $s5, $tr5, $e5, $i5 ) = monter_envoi();

// La clôture échouera : le miroir refuse l'écriture.
$c5->ecritures_refusees = array( LimiteEnvois::META );

JournalOrdre::armer();
$r5     = $e5->emettre( $i5, $t );
$suite5 = JournalOrdre::$suite;
JournalOrdre::reset();

check( '5 · l\'envoi est déclaré réussi malgré la clôture manquée', true === $r5['ok'] );
check( '5 · et le motif le nomme', 'cloture_manquee' === $r5['motif'] );
check( '5 · L\'ÉMISSION EN ATTENTE RESTE POSÉE — elle sera rejouée ou expirera',
	null !== $c5->lire_meta( $i5, EmissionEnAttente::META ) );
check( '5 · LE JETON EST TOUJOURS VIVANT — le lien parti n\'est pas mort',
	null !== $c5->lire_meta( $i5, Urbizen\Platform\Account\JetonVerification::META_CONDENSAT ) );

$suppressions = array_filter(
	$suite5,
	static function ( string $appel ): bool {
		return 0 === strpos( $appel, 'supprimer:' . EmissionEnAttente::META );
	}
);

check( '5 · ANNULER_EMISSION N\'A PAS ÉTÉ APPELÉ', array() === $suppressions );

// Le rejeu, plus tard, aboutit sans second créneau.
$c5->ecritures_refusees = array();
check( '5 · le rejeu de la clôture aboutit',
	true === $s5->confirmer_emission( $i5, EmissionEnAttente::decoder(
		$c5->lire_meta( $i5, EmissionEnAttente::META ) )['id'], $t + 1 ) );
check( '5 · sans second créneau', 1 === count(
	LimiteEnvois::decoder_source( $c5->lire_meta( $i5, LimiteEnvois::META_SOURCE ) )['entrees'] ) );

// ======================================================================
// 6 · RIEN NE S'INTERCALE ENTRE L'ENVOI ET LA CLÔTURE
// ======================================================================
list( $c6, $d6, $s6, $tr6, $e6, $i6 ) = monter_envoi();

JournalOrdre::armer();
$e6->emettre( $i6, $t );
$suite6 = JournalOrdre::$suite;
JournalOrdre::reset();

$pos6 = array_search( 'send', $suite6, true );

check( '6 · l\'envoi figure au journal', false !== $pos6 );
check( '6 · L\'OPÉRATION SUIVANTE APPARTIENT À LA CLÔTURE',
	isset( $suite6[ $pos6 + 1 ] )
	&& 'ecrire:' . LimiteEnvois::META_SOURCE === $suite6[ $pos6 + 1 ] );
check( '6 · un seul envoi dans toute la séquence',
	1 === count( array_filter( $suite6, static fn( $a ) => 'send' === $a ) ) );

// ======================================================================
// 7 · L'AVERTISSEMENT À L'ANCIENNE ADRESSE
// ======================================================================
list( $c7, $d7, $s7, $tr7, $e7, $i7 ) = monter_envoi();

$c7->metas[ $i7 ][ VerificationService::META_VERIFIE ] = VerificationService::VALEUR_VERIFIE;

$r7 = $e7->emettre_changement_adresse( $i7, 'nouvelle@exemple.fr', $t );

check( '7 · le changement aboutit', true === $r7['ok'] );
check( '7 · DEUX messages : l\'avertissement puis la vérification', 2 === count( $tr7->messages ) );
check( '7 · L\'AVERTISSEMENT PART EN PREMIER, à l\'ANCIENNE adresse',
	'claire@exemple.fr' === $tr7->messages[0]['destinataire'] );
check( '7 · la vérification part ensuite, à la NOUVELLE',
	'nouvelle@exemple.fr' === $tr7->messages[1]['destinataire'] );
check( '7 · l\'avertissement ne porte AUCUN lien',
	false === stripos( $tr7->messages[0]['corps'], 'action=urbizen_verification' ) );
check( '7 · ni aucune URL', false === stripos( $tr7->messages[0]['corps'], 'http' ) );
check( '7 · IL NE NOMME PAS LA NOUVELLE ADRESSE',
	false === strpos( $tr7->messages[0]['corps'], 'nouvelle@exemple.fr' ) );
check( '7 · UN SEUL CRÉNEAU consommé — l\'avertissement n\'en coûte pas un second',
	1 === count( LimiteEnvois::decoder_source( $c7->lire_meta( $i7, LimiteEnvois::META_SOURCE ) )['entrees'] ) );

// ======================================================================
// 8 · L'ÉCHEC DE L'AVERTISSEMENT NE BLOQUE RIEN ET NE FUIT RIEN
// ======================================================================
list( $c8, $d8, $s8, $tr8, $e8, $i8 ) = monter_envoi();

$c8->metas[ $i8 ][ VerificationService::META_VERIFIE ] = VerificationService::VALEUR_VERIFIE;

// Le premier envoi — l'avertissement — échoue. Le second doit partir.
$tr8->reponses = array( array( 'ok' => false, 'code' => 'transport_refused' ) );

\Urbizen\Platform\Support\Logger::reset();
$r8 = $e8->emettre_changement_adresse( $i8, 'nouvelle@exemple.fr', $t );

check( '8 · le changement aboutit malgré l\'avertissement manqué', true === $r8['ok'] );
check( '8 · la vérification a bien été remise', 2 === count( $tr8->messages ) );
check( '8 · L\'ÉMISSION N\'EST PAS ANNULÉE', '' === $r8['motif'] );

$journal = \Urbizen\Platform\Support\Logger::tout();

check( '8 · l\'échec est journalisé par CODE technique',
	false !== strpos( $journal, 'avertissement non remis' )
	&& false !== strpos( $journal, 'transport_refused' ) );
check( '8 · AUCUNE ADRESSE dans le journal',
	false === strpos( $journal, 'claire@exemple.fr' )
	&& false === strpos( $journal, 'nouvelle@exemple.fr' ) );
check( '8 · aucun jeton dans le journal',
	1 !== preg_match( '/[0-9a-f]{64}/', $journal ) );

// Une exception de l'avertissement ne remonte pas davantage.
list( $c8b, $d8b, $s8b, $tr8b, $e8b, $i8b ) = monter_envoi();
$c8b->metas[ $i8b ][ VerificationService::META_VERIFIE ] = VerificationService::VALEUR_VERIFIE;
$tr8b->reponses = array( new RuntimeException( 'boum' ) );

$r8b = $e8b->emettre_changement_adresse( $i8b, 'nouvelle@exemple.fr', $t );
check( '8 · une exception de l\'avertissement ne bloque pas non plus', true === $r8b['ok'] );

// Un avertissement sans destinataire ne tente rien.
check( '8 · destinataire vide : aucun envoi tenté',
	false === $e8b->avertir_ancienne_adresse( '' )['ok'] );

/*
 * La trace de l'avertissement ne porte QUE le code technique. On l'isole :
 * les autres traces du domaine portent légitimement un identifiant de compte —
 * c'est la convention de D-046, « un identifiant de compte et jamais une
 * adresse ». C'est cette ligne-ci qui ne doit rien porter de plus.
 */
$ligne_avert = '';

foreach ( explode( "\n", $journal ) as $ligne ) {
	if ( false !== strpos( $ligne, 'avertissement non remis' ) ) {
		$ligne_avert = $ligne;
	}
}

check( '8 · la trace de l\'avertissement existe', '' !== $ligne_avert );
check( '8 · AUCUN IDENTIFIANT DE COMPTE dans cette trace — donnée personnelle indirecte',
	1 !== preg_match( '/compte\s*\d+/', $ligne_avert ) );
check( '8 · aucune adresse dans cette trace', false === strpos( $ligne_avert, '@' ) );
check( '8 · aucun jeton dans cette trace', 1 !== preg_match( '/[0-9a-f]{64}/', $ligne_avert ) );
check( '8 · elle ne porte que le code technique',
	false !== strpos( $ligne_avert, 'transport_refused' ) );

/*
 * La signature interdit la fuite : `avertir_ancienne_adresse()` ne reçoit pas
 * l'identifiant du compte. Ce qu'elle ne connaît pas ne peut pas atteindre le
 * journal.
 */
$params_avert = array();

foreach ( ( new ReflectionMethod( EnvoiVerification::class, 'avertir_ancienne_adresse' ) )->getParameters() as $p ) {
	$params_avert[] = $p->getName();
}

check( '8 · la méthode ne reçoit QUE le destinataire', array( 'ancienne' ) === $params_avert );

// ======================================================================
// 11 · UNE ÉMISSION DÉJÀ PRÉPARÉE NE SE PRÉPARE PAS UNE SECONDE FOIS
// ======================================================================
list( $ca1, $da1, $sa1, $tra1, $ea1, $ia1 ) = monter_envoi();

$inscription = ( new Urbizen\Platform\Account\InscriptionService( $ca1, $sa1 ) )
	->inscrire( 'nouvelle-cliente@exemple.fr', 'motdepasse-long', $t );

check( '11 · l\'inscription a préparé son émission',
	null !== $inscription['emission'] && $inscription['emission']->est_prepare() );

$prepare_id = $inscription['emission']->emission_id();

JournalOrdre::armer();
$ra1     = $ea1->emettre_prepare( $inscription['compte'], $inscription['emission'], $t );
$suite11 = JournalOrdre::$suite;
JournalOrdre::reset();

check( '11 · l\'envoi aboutit', true === $ra1['ok'] && '' === $ra1['motif'] );
check( '11 · AUCUN REFUS emission_en_attente', 'emission_en_attente' !== $ra1['motif'] );
check( '11 · UN SEUL courriel remis', 1 === count( $tra1->messages ) );
check( '11 · remis à la bonne adresse',
	'nouvelle-cliente@exemple.fr' === $tra1->messages[0]['destinataire'] );
check( '11 · AUCUNE SECONDE PRÉPARATION : aucun jeton réengendré pendant l\'envoi',
	0 === count( array_filter(
		$suite11,
		static fn( $a ) => 'ecrire:' . Urbizen\Platform\Account\JetonVerification::META_CONDENSAT === $a
	) ) );
check( '11 · le créneau est décompté une seule fois',
	1 === count( LimiteEnvois::decoder_source(
		$ca1->lire_meta( $inscription['compte'], LimiteEnvois::META_SOURCE ) )['entrees'] ) );
check( '11 · CLÔTURE AVEC L\'IDENTIFIANT EXACT de l\'émission préparée',
	$prepare_id === LimiteEnvois::decoder_source(
		$ca1->lire_meta( $inscription['compte'], LimiteEnvois::META_SOURCE ) )['entrees'][0]['e'] );
check( '11 · l\'émission en attente est close',
	null === $ca1->lire_meta( $inscription['compte'], EmissionEnAttente::META ) );

// Le chemin fautif, pour mémoire : repasser par `emettre()` échouerait.
list( $cb1, $db1, $sb1, $trb1, $eb1, $ib1 ) = monter_envoi();
$ins2 = ( new Urbizen\Platform\Account\InscriptionService( $cb1, $sb1 ) )
	->inscrire( 'autre@exemple.fr', 'motdepasse-long', $t );

check( '11 · REPASSER PAR emettre() serait refusé, et n\'enverrait rien',
	'emission_en_attente' === $eb1->emettre( $ins2['compte'], $t )['motif']
	&& array() === $trb1->messages );

// Une émission refusée ne passe pas non plus par la nouvelle entrée.
check( '11 · une émission NON préparée est refusée telle quelle',
	'quota_epuise' === $ea1->emettre_prepare(
		$ia1, Urbizen\Platform\Account\ResultatEmission::refuse( 'quota_epuise' ), $t )['motif'] );

// ======================================================================
// 9 · UN REFUS DE CHANGEMENT N'ENVOIE RIEN DU TOUT
// ======================================================================
list( $c9, $d9, $s9, $tr9, $e9, $i9 ) = monter_envoi();

$r9 = $e9->emettre_changement_adresse( $i9, 'pas-une-adresse', $t );

check( '9 · refusé', false === $r9['ok'] && 'adresse_invalide' === $r9['motif'] );
check( '9 · AUCUN message, pas même l\'avertissement', array() === $tr9->messages );

// ======================================================================
// 10 · LA DOUBLURE N'APPELLE JAMAIS LA FONCTION D'ENVOI DE WORDPRESS
// ======================================================================
$code_doublure = (string) file_get_contents( __DIR__ . '/doublures.php' );
check( '10 · la doublure de transport ne nomme jamais la fonction d\'envoi',
	1 !== preg_match( '/\bwp_mail\s*\(/', $code_doublure ) );

$code_envoi = (string) file_get_contents(
	dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Account/EnvoiVerification.php'
);
check( '10 · l\'orchestrateur non plus',
	1 !== preg_match( '/\bwp_mail\s*\(/', $code_envoi ) );
check( '10 · il reçoit son transport par CONSTRUCTION',
	false !== strpos( $code_envoi, 'MailTransport $transport' ) );

verdict();
