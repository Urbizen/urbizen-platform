<?php
/**
 * Banc d'essai du registre des références et du verrou de programmation.
 *
 * Deux garanties, distinctes mais de même nature : quelque chose doit survivre
 * à ce qui l'entoure.
 *
 * Le registre des références doit survivre à l'effacement des données
 * personnelles. C'est ce qui distingue une **donnée personnelle**, effacée à
 * 365 jours parce que la loi le veut, d'un **registre technique d'unicité**,
 * conservé parce qu'il ne contient rien de personnel et que sans lui un ancien
 * numéro pourrait être réattribué.
 *
 * Le verrou de programmation, lui, doit survivre à une requête interrompue.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

/**
 * Repart d'un état propre.
 */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

/**
 * Soumission d'essai validée.
 *
 * @return array{clean:array<string,mixed>,pricing:array<string,mixed>}
 */
function jeu_valide(): array {
	$v = Validator::validate(
		FormRegistry::get( 'conception' ),
		array(
			'nature'    => 'maison',
			'situation' => 'terrain_nu',
			'a_terrain' => 'non',
			'nom'       => 'Camille Fictif',
			'email'     => 'camille@exemple.test',
			'tel'       => '0100000000',
			'rgpd'      => '1',
		)
	);

	return array( 'clean' => $v['clean'], 'pricing' => $v['pricing'] );
}

$jeu   = jeu_valide();
$annee = (int) gmdate( 'Y', wpd_now() );

/**
 * Nom d'option d'une réservation.
 *
 * @param string $reference Référence.
 * @return string
 */
function cle_ref( string $reference ): string {
	return SubmissionRepository::RESERVATION_PREFIX . $reference;
}

// ======================================================================
// 1 · RÉSERVATION EN ATTENTE : ABANDONNÉE PUIS SUPPRIMÉE
// ======================================================================
neuf();

SubmissionRepository::reserve_reference( 'URB-2026-0700', wpd_now() - SubmissionRepository::RESERVATION_TTL - 1 );
SubmissionRepository::reserve_reference( 'URB-2026-0701', wpd_now() );

check( '1 · une réservation en attente porte l’état « reserved »', 'reserved' === get_option( cle_ref( 'URB-2026-0700' ) )['state'] );
check( '1 · une réservation abandonnée depuis plus d’une heure est supprimée',
	1 === SubmissionRepository::cleanup_abandoned_references( wpd_now() ) );
check( '1 · elle a bien disparu', null === get_option( cle_ref( 'URB-2026-0700' ), null ) );
check( '1 · une réservation récente est conservée', null !== get_option( cle_ref( 'URB-2026-0701' ), null ) );
check( '1 · une référence en attente ne bloque pas indéfiniment',
	SubmissionRepository::reserve_reference( 'URB-2026-0700', wpd_now() ) );

// ======================================================================
// 2 · RÉSERVATION EN ATTENTE : LIBÉRÉE APRÈS ÉCHEC DE PERSISTANCE
// ======================================================================
neuf();

$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$echec                    = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => wpd_now() ) );
$GLOBALS['wpd_meta_fail'] = '';

check( '2 · la persistance échoue', empty( $echec['ok'] ) );
check( '2 · la réservation est libérée immédiatement', null === get_option( cle_ref( sprintf( 'URB-%d-0001', $annee ) ), null ) );
check( '2 · aucune demande ne subsiste', array() === $GLOBALS['wpd_posts'] );
check( '2 · aucune attente d’une heure n’est nécessaire',
	SubmissionRepository::reserve_reference( sprintf( 'URB-%d-0001', $annee ), wpd_now() ) );

// ======================================================================
// 3 · RÉSERVATION ATTRIBUÉE : LE NETTOYAGE NE LA TOUCHE PAS
// ======================================================================
neuf();

$creation = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => wpd_now() ) );
$ref      = $creation['reference'];
$cle      = cle_ref( $ref );

check( '3 · la demande est créée', ! empty( $creation['ok'] ) );
check( '3 · la réservation passe à « attributed »', 'attributed' === get_option( $cle )['state'] );

$libere = SubmissionRepository::cleanup_abandoned_references( wpd_now() + ( 100 * 365 * 86400 ) );

check( '3 · le nettoyage, même cent ans plus tard, ne libère rien', 0 === $libere );
check( '3 · la réservation attribuée est intacte', 'attributed' === get_option( $cle )['state'] );

// Une valeur devenue illisible n'est pas non plus supprimée : en cas de doute,
// on garde.
update_option( cle_ref( 'URB-2026-0666' ), 'valeur corrompue', false );
SubmissionRepository::cleanup_abandoned_references( wpd_now() + 999999 );

check( '3 · une réservation illisible est conservée par prudence', null !== get_option( cle_ref( 'URB-2026-0666' ), null ) );

// ======================================================================
// 4 · ELLE SURVIT À LA SUPPRESSION DE LA DEMANDE
// ======================================================================
wp_delete_post( $creation['id'], true );

check( '4 · la demande a disparu', null === get_post( $creation['id'] ) );
check( '4 · ses métadonnées ont disparu', ! isset( $GLOBALS['wpd_meta'][ $creation['id'] ] ) );
check( '4 · LA RÉSERVATION ATTRIBUÉE SURVIT', 'attributed' === get_option( $cle )['state'] );

// ======================================================================
// 5 · ELLE SURVIT À LA RÉTENTION À 365 JOURS
// ======================================================================
neuf();

$creation = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => wpd_now() ) );
$ref      = $creation['reference'];
$cle      = cle_ref( $ref );

// La demande vieillit au-delà du délai de conservation.
update_post_meta( $creation['id'], '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );

$bilan = Retention::run_daily( wpd_now() );

check( '5 · la demande est purgée après 365 jours', 1 === $bilan['demandes'] && null === get_post( $creation['id'] ) );
check( '5 · les données personnelles ont bien disparu',
	! preg_match( '/Camille|camille@exemple\.test|0100000000/', (string) wp_json_encode( $GLOBALS['wpd_meta'] ) ) );
check( '5 · LA RÉSERVATION ATTRIBUÉE SURVIT À LA RÉTENTION', 'attributed' === get_option( $cle )['state'] );
check( '5 · le ménage ne l’a pas comptée comme libérée', 0 === $bilan['references'] );

// Le hook de pré-suppression ne doit pas non plus l'emporter : la PR B2 s'y
// branchera pour les fichiers, pas pour le registre.
check( '5 · aucun abonné du hook n’a supprimé la réservation', null !== get_option( $cle, null ) );

// ======================================================================
// 6 · APRÈS SUPPRESSION ET REMISE À ZÉRO DU COMPTEUR
// ======================================================================
// Le pire des cas : la demande n'existe plus (donc reference_exists() ne voit
// rien), et le compteur est revenu à zéro. Seul le registre protège.
update_option( SubmissionRepository::SEQUENCE_OPTION, array( $annee => 0 ) );

$suivante = SubmissionRepository::next_reference( wpd_now() );

check( '6 · la référence supprimée n’est PAS réattribuée', $suivante !== $ref );
check( '6 · la référence libre suivante est utilisée', str_ends_with( $suivante, '-0002' ) );
check( '6 · la réservation d’origine est toujours là', 'attributed' === get_option( $cle )['state'] );

// Et cela tient aussi après une purge des caches et des transients.
wpd_purger_caches();
update_option( SubmissionRepository::SEQUENCE_OPTION, array( $annee => 0 ) );

check( '6 · APRÈS PURGE DES CACHES, la référence reste protégée',
	SubmissionRepository::next_reference( wpd_now() ) !== $ref );

// ======================================================================
// 7 et 8 · CONTENU DE L'OPTION : AUCUNE DONNÉE PERSONNELLE
// ======================================================================
neuf();

$creation = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => wpd_now() ) );
$valeur   = get_option( cle_ref( $creation['reference'] ) );

check( '7 · trois clés exactement', array( 'state', 'at', 'post' ) === array_keys( $valeur ) );
check( '7 · l’état est « attributed »', 'attributed' === $valeur['state'] );
check( '7 · la date d’attribution est technique et en UTC', 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $valeur['at'] ) );
check( '7 · l’identifiant du contenu est un entier', is_int( $valeur['post'] ) && $valeur['post'] > 0 );

$serialise = (string) wp_json_encode( $valeur );

foreach ( array(
	'nom'               => 'Camille',
	'adresse'           => 'exemple.test',
	'téléphone'         => '0100000000',
	'charge utile'      => 'nature',
	'adresse IP'        => '203.0.113',
	'agent utilisateur' => 'Mozilla',
) as $libelle => $motif ) {
	check( "8 · aucune trace de : $libelle", ! str_contains( $serialise, $motif ) );
}

check( '8 · la référence elle-même n’est que dans le nom de l’option', ! str_contains( $serialise, 'URB-' ) );

// ======================================================================
// 9 · AUTOLOAD
// ======================================================================
check( '9 · une réservation attribuée n’est pas autoloadée', 'no' === wpd_autoload( cle_ref( $creation['reference'] ) ) );

SubmissionRepository::reserve_reference( 'URB-2026-0777', wpd_now() );
check( '9 · une réservation en attente n’est pas autoloadée', 'no' === wpd_autoload( cle_ref( 'URB-2026-0777' ) ) );

// ======================================================================
// 10 · VERROU DE PROGRAMMATION : ENTRELACEMENT DÉTERMINISTE
// ======================================================================
wpd_reset();

// A entre dans ensure_scheduled : aucune tâche, elle prend le verrou.
check( '10 · aucune tâche au départ', false === wp_next_scheduled( Retention::HOOK ) );
check( '10 · A obtient le verrou', Retention::acquire_lock( wpd_now() ) );

// B arrive au même instant. Elle ne trouve pas de tâche non plus — c'est
// exactement la course. Mais elle n'obtient pas le verrou.
Retention::ensure_scheduled_at( wpd_now() );

check( '10 · B ne programme rien pendant que A travaille', false === wp_next_scheduled( Retention::HOOK ) );
check( '10 · B n’a appelé wp_schedule_event aucune fois', 0 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

// A termine son travail.
Retention::schedule_now();
Retention::release_lock();

check( '10 · A a programmé la tâche', false !== wp_next_scheduled( Retention::HOOK ) );
check( '10 · A a libéré le verrou', null === get_option( Retention::LOCK_OPTION, null ) );

// B repasse : la tâche existe désormais, elle ne fait rien.
Retention::ensure_scheduled_at( wpd_now() );

check( '10 · B ne programme toujours rien', 1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '10 · LE NOMBRE D’APPELS RÉELS À wp_schedule_event EST EXACTEMENT 1',
	1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '10 · une seule tâche existe', 1 === count( $GLOBALS['wpd_cron'] ) );

// ======================================================================
// 11 · VERROU ABANDONNÉ
// ======================================================================
wpd_reset();

// Une requête a pris le verrou puis s'est interrompue. Le verrou est encore
// frais : personne ne doit programmer à sa place.
Retention::acquire_lock( wpd_now() );
Retention::ensure_scheduled_at( wpd_now() + 5 );

check( '11 · verrou frais → aucune seconde programmation', 0 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '11 · aucune tâche n’existe encore', false === wp_next_scheduled( Retention::HOOK ) );

// Une fois périmé, il se reprend : un arrêt brutal ne bloque pas la
// programmation pour toujours.
Retention::ensure_scheduled_at( wpd_now() + Retention::LOCK_TTL + 1 );

check( '11 · verrou périmé → la programmation reprend', 1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '11 · la tâche existe', false !== wp_next_scheduled( Retention::HOOK ) );
check( '11 · le verrou périmé a été remplacé puis rendu', null === get_option( Retention::LOCK_OPTION, null ) );

check( '11 · le verrou porte une expiration',
	isset( ( (array) ( Retention::acquire_lock( wpd_now() ) ? get_option( Retention::LOCK_OPTION ) : array() ) )['expires'] ) );
Retention::release_lock();

// ======================================================================
// 12 · LES AUTRES SCÉNARIOS
// ======================================================================

// Tâche déjà existante : aucune programmation, et pas même de verrou posé.
wpd_reset();
Retention::ensure_scheduled_at( wpd_now() );
$appels = $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0;
Retention::ensure_scheduled_at( wpd_now() );

check( '12 · tâche déjà existante → aucune programmation', $appels === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '12 · aucun verrou n’est posé sur le chemin rapide', null === get_option( Retention::LOCK_OPTION, null ) );

// Chargements séquentiels.
wpd_reset();

for ( $i = 1; $i <= 20; $i++ ) {
	Retention::ensure_scheduled_at( wpd_now() + $i );
}

check( '12 · vingt chargements → une seule programmation', 1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '12 · aucun verrou résiduel', null === get_option( Retention::LOCK_OPTION, null ) );

// Désactivation puis réactivation.
Retention::unschedule();
check( '12 · désactivation : la tâche est supprimée', false === wp_next_scheduled( Retention::HOOK ) );

Retention::schedule();
check( '12 · réactivation : une seule tâche', 1 === count( $GLOBALS['wpd_cron'] ) );
check( '12 · réactivation : une seule programmation de plus', 2 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

// Mise à jour 0.5.0 → 0.6.0 sans réactivation.
wpd_reset();
Retention::register();

for ( $i = 1; $i <= 5; $i++ ) {
	do_action( 'init' );
}

check( '12 · MISE À JOUR SANS RÉACTIVATION : une seule tâche', 1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '12 · la tâche existe', false !== wp_next_scheduled( Retention::HOOK ) );
check( '12 · aucun verrou permanent après succès', null === get_option( Retention::LOCK_OPTION, null ) );

// Autoload du verrou.
wpd_reset();
Retention::acquire_lock( wpd_now() );

check( '12 · le verrou n’est pas autoloadé', 'no' === wpd_autoload( Retention::LOCK_OPTION ) );
check( '12 · le verrou ne contient aucune donnée personnelle',
	array( 'expires' ) === array_keys( (array) get_option( Retention::LOCK_OPTION ) ) );
Retention::release_lock();

// ======================================================================
// 13 · TABLEAU DES OPTIONS TECHNIQUES
// ======================================================================
neuf();
traiter( soumission() );
Retention::ensure_scheduled_at( wpd_now() );

$prefixes = array(
	'urbizen_tok_'              => 'jeton',
	'urbizen_rl_'               => 'créneau de débit',
	'urbizen_ref_'              => 'référence',
	'urbizen_reference_sequence' => 'compteur',
);

$non_conformes = array();

foreach ( array_keys( $GLOBALS['wpd_options'] ) as $option ) {
	if ( ! str_starts_with( (string) $option, 'urbizen_' ) ) {
		continue;
	}

	if ( 'no' !== wpd_autoload( (string) $option ) ) {
		$non_conformes[] = $option;
	}
}

check( '13 · toutes les options techniques sont en autoload=false', array() === $non_conformes );

if ( array() !== $non_conformes ) {
	echo '    autoloadée : ' . implode( ' | ', $non_conformes ) . "\n";
}

$presentes = array();

foreach ( $prefixes as $prefixe => $libelle ) {
	if ( array() !== array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => str_starts_with( (string) $c, $prefixe ) ) ) {
		$presentes[] = $libelle;
	}
}

check( '13 · les quatre familles d’options existent après une soumission', 4 === count( $presentes ) );
check( '13 · aucun verrou ne subsiste', null === get_option( Retention::LOCK_OPTION, null ) );
check( '13 · aucune donnée personnelle dans l’ensemble des options techniques',
	! preg_match( '/Camille|exemple\.test|203\.0\.113|0100000000/', (string) wp_json_encode( $GLOBALS['wpd_options'] ) ) );

verdict();
