<?php
/**
 * Banc d'essai de la persistance vérifiée des métadonnées.
 *
 * `update_post_meta()` rend `false` dans **deux** situations que rien ne
 * distingue au retour : l'écriture a échoué, ou la valeur était déjà la bonne.
 * Un code qui confond les deux fait échouer toute écriture idempotente — et
 * c'est exactement ce qui arrivait à `finalize()`, qui réécrivait
 * `_urbizen_files_status` avec la valeur que `persist()` venait de poser.
 *
 * La seule preuve d'écriture est la relecture. Dans les deux sens : un `false`
 * suivi d'une relecture conforme est un succès, un `true` suivi d'une relecture
 * divergente est un échec.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Submissions\TransactionRecovery;

function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

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

function un_post(): int {
	return wp_insert_post( array( 'post_type' => SubmissionPostType::POST_TYPE, 'post_status' => 'private' ) );
}

$jeu = jeu_valide();

// ======================================================================
// A · MÉTADONNÉE ABSENTE
// ======================================================================
neuf();
$id = un_post();

check( 'A · update_post_meta rend un identifiant à la création', 123 === update_post_meta( $id, '_essai', 'valeur' ) );

$id = un_post();

check( 'A · persist_meta réussit', true === SubmissionRepository::persist_meta( $id, '_essai', 'valeur' ) );
check( 'A · la relecture est correcte', 'valeur' === get_post_meta( $id, '_essai', true ) );

// ======================================================================
// B · VALEUR EXISTANTE DIFFÉRENTE
// ======================================================================
check( 'B · update_post_meta rend true sur une modification réelle', true === update_post_meta( $id, '_essai', 'autre' ) );
check( 'B · persist_meta réussit', true === SubmissionRepository::persist_meta( $id, '_essai', 'troisième' ) );
check( 'B · la relecture est correcte', 'troisième' === get_post_meta( $id, '_essai', true ) );

// ======================================================================
// C · VALEUR DÉJÀ IDENTIQUE — LE CŒUR DU DÉFAUT
// ======================================================================
check( 'C · update_post_meta rend FALSE sur une valeur identique', false === update_post_meta( $id, '_essai', 'troisième' ) );
check( 'C · la relecture est pourtant identique', 'troisième' === get_post_meta( $id, '_essai', true ) );
check( 'C · persist_meta considère donc l’opération RÉUSSIE',
	true === SubmissionRepository::persist_meta( $id, '_essai', 'troisième' ) );
check( 'C · et l’opération est idempotente',
	SubmissionRepository::persist_meta( $id, '_essai', 'troisième' )
	&& SubmissionRepository::persist_meta( $id, '_essai', 'troisième' ) );

// ======================================================================
// D · VÉRITABLE ÉCHEC D'ÉCRITURE, ANCIENNE VALEUR DIFFÉRENTE PRÉSENTE
// ======================================================================
$GLOBALS['wpd_meta_fail'] = '_essai';

check( 'D · update_post_meta rend false', false === update_post_meta( $id, '_essai', 'nouvelle' ) );
check( 'D · l’ancienne valeur, différente, est toujours là', 'troisième' === get_post_meta( $id, '_essai', true ) );
check( 'D · persist_meta considère l’opération ÉCHOUÉE',
	false === SubmissionRepository::persist_meta( $id, '_essai', 'nouvelle' ) );

$GLOBALS['wpd_meta_fail'] = '';

// ======================================================================
// E · CRÉATION RÉELLEMENT ÉCHOUÉE
// ======================================================================
$GLOBALS['wpd_meta_fail'] = '_absent';

check( 'E · update_post_meta rend false', false === update_post_meta( $id, '_absent', 'x' ) );
check( 'E · la valeur est absente après relecture', '' === get_post_meta( $id, '_absent', true ) );
check( 'E · persist_meta échoue', false === SubmissionRepository::persist_meta( $id, '_absent', 'x' ) );

$GLOBALS['wpd_meta_fail'] = '';

// ======================================================================
// F · RETOUR POSITIF MENSONGER
// ======================================================================
$GLOBALS['wpd_meta_lie'] = '_menteur';

check( 'F · update_post_meta rend un identifiant', 123 === update_post_meta( $id, '_menteur', 'promis' ) );
check( 'F · rien n’a pourtant été écrit', '' === get_post_meta( $id, '_menteur', true ) );
check( 'F · persist_meta ÉCHOUE malgré le retour positif',
	false === SubmissionRepository::persist_meta( $id, '_menteur', 'promis' ) );

update_post_meta( $id, '_menteur2', 'initiale' );
$GLOBALS['wpd_meta_lie'] = '_menteur2';

check( 'F · update_post_meta rend true sur une valeur existante', true === update_post_meta( $id, '_menteur2', 'modifiée' ) );
check( 'F · la valeur n’a pas bougé', 'initiale' === get_post_meta( $id, '_menteur2', true ) );
check( 'F · persist_meta échoue', false === SubmissionRepository::persist_meta( $id, '_menteur2', 'modifiée' ) );

$GLOBALS['wpd_meta_lie'] = '';

// ======================================================================
// G · VALEURS SCALAIRES
// ======================================================================
neuf();
$id = un_post();

check( 'G · entier', SubmissionRepository::persist_meta( $id, '_n', 42 ) && '42' === (string) get_post_meta( $id, '_n', true ) );
check( 'G · entier zéro', SubmissionRepository::persist_meta( $id, '_zero', 0 ) );
check( 'G · entier réécrit à l’identique', SubmissionRepository::persist_meta( $id, '_n', 42 ) );
check( 'G · chaîne', SubmissionRepository::persist_meta( $id, '_s', 'texte' ) );
check( 'G · chaîne vide', SubmissionRepository::persist_meta( $id, '_vide', '' ) );
check( 'G · chaîne vide réécrite à l’identique', SubmissionRepository::persist_meta( $id, '_vide', '' ) );
check( 'G · booléen vrai', SubmissionRepository::persist_meta( $id, '_b', true ) );
check( 'G · booléen faux', SubmissionRepository::persist_meta( $id, '_bf', false ) );
check( 'G · flottant', SubmissionRepository::persist_meta( $id, '_f', 1.5 ) );

// Un entier relu en chaîne ne doit pas être pris pour une divergence, mais une
// chaîne réellement différente, si.
$GLOBALS['wpd_meta_lie'] = '_piege';
update_post_meta( $id, '_piege', '43' );

check( 'G · 42 attendu, 43 stocké → échec', false === SubmissionRepository::persist_meta( $id, '_piege', 42 ) );

$GLOBALS['wpd_meta_lie'] = '';

// ======================================================================
// H · STRUCTURES
// ======================================================================
$transaction = (string) wp_json_encode(
	array( 'id' => 'tx-fictive', 'state' => 'committed', 'reference' => 'URB-2026-0001' )
);

check( 'H · transaction JSON', SubmissionRepository::persist_meta( $id, '_urbizen_transaction', $transaction ) );
check( 'H · relue caractère pour caractère', $transaction === get_post_meta( $id, '_urbizen_transaction', true ) );
check( 'H · réécrite à l’identique', SubmissionRepository::persist_meta( $id, '_urbizen_transaction', $transaction ) );

$fichiers = (string) wp_json_encode(
	array( array( 'bloc' => 'photos', 'relative_path' => 'URB-2026-0001/a.jpg', 'size' => 10 ) )
);

check( 'H · métadonnées de fichiers', SubmissionRepository::persist_meta( $id, '_urbizen_files', $fichiers ) );
check( 'H · réécrites à l’identique', SubmissionRepository::persist_meta( $id, '_urbizen_files', $fichiers ) );

// Deux JSON sémantiquement égaux mais textuellement différents ne sont **pas**
// équivalents : la comparaison reste stricte.
$autre_ordre = '{"state":"committed","id":"tx-fictive","reference":"URB-2026-0001"}';
$GLOBALS['wpd_meta_lie'] = '_urbizen_transaction';

check( 'H · aucune comparaison laxiste du JSON',
	false === SubmissionRepository::persist_meta( $id, '_urbizen_transaction', $autre_ordre ) );

$GLOBALS['wpd_meta_lie'] = '';

// Un tableau réellement persisté est comparé après restitution normale.
check( 'H · tableau', SubmissionRepository::persist_meta( $id, '_tableau', array( 'a' => 1, 'b' => array( 2, 3 ) ) ) );
check( 'H · tableau réécrit à l’identique',
	SubmissionRepository::persist_meta( $id, '_tableau', array( 'a' => 1, 'b' => array( 2, 3 ) ) ) );

// ======================================================================
// I · SOUMISSION SANS FICHIER
// ======================================================================
neuf();

$creation = SubmissionRepository::create( $jeu['clean'], $jeu['pricing'], array( 'now' => wpd_now() ) );
$id       = (int) $creation['id'];
$ref      = (string) $creation['reference'];

check( 'I · la soumission réussit', ! empty( $creation['ok'] ) );
check( 'I · transaction committed', 'committed' === ( SubmissionRepository::transaction( $id )['state'] ?? '' ) );
check( 'I · référence attributed',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );
check( 'I · la réservation est rattachée à la demande',
	$id === (int) ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['post'] ?? 0 ) );
check( 'I · _urbizen_status = received', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $id, '_urbizen_status', true ) );
check( 'I · files_status = none', 'none' === get_post_meta( $id, '_urbizen_files_status', true ) );

// La récupération transactionnelle ne doit rien emporter une heure plus tard.
$plus_tard = wpd_now() + TransactionRecovery::TTL + 10;
$bilan     = TransactionRecovery::run( $plus_tard );

check( 'I · aucun rollback une heure plus tard', 0 === $bilan['rollback'] );
check( 'I · la demande est toujours là', null !== get_post( $id ) );
check( 'I · la référence reste attributed',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );

// ======================================================================
// J · SOUMISSION AVEC FICHIERS
// ======================================================================
neuf();

$creation = SubmissionRepository::create(
	$jeu['clean'],
	$jeu['pricing'],
	array( 'now' => wpd_now(), 'files_status' => 'stored', 'finalize' => false )
);
$id  = (int) $creation['id'];
$ref = (string) $creation['reference'];

SubmissionRepository::set_files(
	$id,
	array( array( 'bloc' => 'photos', 'relative_path' => $ref . '/a.jpg', 'size' => 10 ) )
);
SubmissionRepository::finalize( $id, $ref, 'stored', wpd_now() );

check( 'J · transaction committed', 'committed' === ( SubmissionRepository::transaction( $id )['state'] ?? '' ) );
check( 'J · référence attributed',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );
check( 'J · files_status = stored', 'stored' === get_post_meta( $id, '_urbizen_files_status', true ) );
check( 'J · _urbizen_status = received', SubmissionPostType::STATUS_RECEIVED === get_post_meta( $id, '_urbizen_status', true ) );

$bilan = TransactionRecovery::run( wpd_now() + TransactionRecovery::TTL + 10 );

check( 'J · aucun nettoyage différé ne supprime la demande', 0 === $bilan['rollback'] && null !== get_post( $id ) );
check( 'J · ni ne la marque incohérente', 0 === $bilan['incoherent'] );

// Une seconde finalisation, strictement identique, doit rester un succès.
check( 'J · finalize est idempotente', true === SubmissionRepository::finalize( $id, $ref, 'stored', wpd_now() ) );

// ======================================================================
// K · ÉCRITURE RÉELLEMENT IMPOSSIBLE
// ======================================================================
neuf();

$creation = SubmissionRepository::create(
	$jeu['clean'],
	$jeu['pricing'],
	array( 'now' => wpd_now(), 'files_status' => 'stored', 'finalize' => false )
);
$id  = (int) $creation['id'];
$ref = (string) $creation['reference'];

$GLOBALS['wpd_meta_fail'] = '_urbizen_status';
$finalisee                = SubmissionRepository::finalize( $id, $ref, 'stored', wpd_now() );
$GLOBALS['wpd_meta_fail'] = '';

check( 'K · finalize échoue', false === $finalisee );
check( 'K · aucun faux succès : le statut n’est pas received',
	SubmissionPostType::STATUS_RECEIVED !== get_post_meta( $id, '_urbizen_status', true ) );
check( 'K · la référence n’est PAS attribuée à tort',
	'attributed' !== ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );

// La récupération doit alors faire son office : référence encore réservée,
// donc la transaction n'a jamais atteint son point de non-retour.
$bilan = TransactionRecovery::run( wpd_now() + TransactionRecovery::TTL + 10 );

check( 'K · la récupération annule la transaction', 1 === $bilan['rollback'] );
check( 'K · la demande est supprimée', null === get_post( $id ) );
check( 'K · la référence est libérée', null === get_option( SubmissionRepository::RESERVATION_PREFIX . $ref, null ) );

// Le même échec sur la première écriture de finalize.
neuf();

$creation = SubmissionRepository::create(
	$jeu['clean'],
	$jeu['pricing'],
	array( 'now' => wpd_now(), 'files_status' => 'stored', 'finalize' => false )
);
$id  = (int) $creation['id'];
$ref = (string) $creation['reference'];

// L'échec porte ici sur la transaction, qui passe réellement de `processing` à
// `committed` : une écriture bloquée sur une valeur *déjà* en place ne serait
// pas un échec, puisque l'état voulu est atteint.
$GLOBALS['wpd_meta_fail'] = '_urbizen_transaction';
$finalisee                = SubmissionRepository::finalize( $id, $ref, 'stored', wpd_now() );
$GLOBALS['wpd_meta_fail'] = '';

check( 'K · finalize échoue sur la transaction', false === $finalisee );

// Le cas symétrique : `files_status` porte déjà la valeur voulue. Bloquer
// l'écriture ne change rien, et ce n'est pas un échec.
check( 'K · une écriture bloquée sur une valeur déjà en place n’est pas un échec',
	'stored' === get_post_meta( $id, '_urbizen_files_status', true ) );
check( 'K · la transaction n’est pas committed', 'committed' !== ( SubmissionRepository::transaction( $id )['state'] ?? '' ) );
check( 'K · aucune référence attribuée',
	'attributed' !== ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );

verdict();
