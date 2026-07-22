<?php
/**
 * Banc : le contrat de migration, l'exécuteur et le verrou.
 *
 * Le contrôle qui porte toute la PR est en section 2 : **catalogue vide, aucun
 * appel à la passerelle**. Il est prouvé par un espion qui lève à la moindre
 * sollicitation — l'exécuteur ne peut donc pas « presque » respecter la
 * garantie.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/passerelle-espion.php';

use Urbizen\Platform\Schema\MigrationCatalogue;
use Urbizen\Platform\Schema\MigrationLock;
use Urbizen\Platform\Schema\MigrationRunner;
use Urbizen\Platform\Schema\ResultatMigration;
use Urbizen\Platform\Schema\SchemaGuard;

/**
 * Vide le magasin d'options entre deux scénarios.
 *
 * @return void
 */
function options_neuves(): void {
	$GLOBALS['urbizen_options'] = array();
}

// ======================================================================
// 1 · CATALOGUE
// ======================================================================
$vide = MigrationCatalogue::plateforme();

check( '1 · LE CATALOGUE DE LA PLATEFORME EST VIDE EN E1', $vide->est_vide() );
check( '1 · il ne déclare aucune migration', 0 === $vide->nombre() );
check( '1 · et n’expose aucun identifiant', array() === $vide->identifiants() );

$deux = new MigrationCatalogue( array( new MigrationEprouvee( '0001_a' ), new MigrationEprouvee( '0002_b' ) ) );

check( '1 · un catalogue peuplé n’est pas vide', false === $deux->est_vide() );
check( '1 · l’ordre déclaré est conservé', array( '0001_a', '0002_b' ) === $deux->identifiants() );

$leve = false;

try {
	new MigrationCatalogue( array( new MigrationEprouvee( '0001_a' ), new MigrationEprouvee( '0001_a' ) ) );
} catch ( InvalidArgumentException $e ) {
	$leve = true;
}

check( '1 · UN IDENTIFIANT RÉPÉTÉ EST REFUSÉ', $leve );

$leve = false;

try {
	new MigrationCatalogue( array( new MigrationEprouvee( '  ' ) ) );
} catch ( InvalidArgumentException $e ) {
	$leve = true;
}

check( '1 · un identifiant vide est refusé', $leve );

$leve = false;

try {
	new MigrationCatalogue( array( 'pas une migration' ) );
} catch ( InvalidArgumentException $e ) {
	$leve = true;
}

check( '1 · un objet étranger est refusé', $leve );

// ======================================================================
// 2 · CATALOGUE VIDE → AUCUNE REQUÊTE  ← LE CONTRÔLE CENTRAL D'E1
// ======================================================================
options_neuves();

/**
 * Appelle une entrée de l'exécuteur sous espion muet.
 *
 * L'exception de l'espion est rattrapée **ici**, et non laissée traverser :
 * une mutation qui solliciterait la passerelle doit produire un échec nommé,
 * pas une erreur fatale qui interromprait le banc avant son verdict.
 *
 * @param string $methode Entrée éprouvée.
 * @return array{appels: array<int, string>, resultat: ResultatMigration|null}
 */
function sous_espion( string $methode ): array {
	$espion = new PasserelleMuette();
	$runner = new MigrationRunner( $espion, MigrationCatalogue::plateforme() );

	$resultat = null;

	try {
		$resultat = $runner->$methode();
	} catch ( Throwable $e ) {
		// L'espion a été sollicité : `$espion->appels` le dira.
		$resultat = null;
	}

	return array( 'appels' => $espion->appels, 'resultat' => $resultat );
}

$espion = new PasserelleMuette();
$runner = new MigrationRunner( $espion, MigrationCatalogue::plateforme() );

check( '2 · CONSTRUIRE L’EXÉCUTEUR N’INTERROGE RIEN', array() === $espion->appels );

$sonde    = sous_espion( 'executer' );
$resultat = $sonde['resultat'];

check( '2 · CATALOGUE VIDE → AUCUN APPEL À LA PASSERELLE', array() === $sonde['appels'] );
check( '2 · l’exécution rend bien un résultat', $resultat instanceof ResultatMigration );
check( '2 · l’état rendu est « rien à faire »', null !== $resultat && $resultat->rien_a_faire() );
check( '2 · qui est un succès', null !== $resultat && $resultat->reussi() );
check( '2 · le motif est explicite', null !== $resultat && 'catalogue_vide' === $resultat->motif() );
check( '2 · AUCUNE OPTION N’EST CRÉÉE', array() === $GLOBALS['urbizen_options'] );
check( '2 · AUCUN VERROU N’EST POSÉ', false === array_key_exists( MigrationLock::OPTION, $GLOBALS['urbizen_options'] ) );

// Les deux autres entrées publiques respectent la même garantie.
$sonde_etat = sous_espion( 'etat' );

check( '2 · etat() n’interroge rien non plus', array() === $sonde_etat['appels'] );
check( '2 · et rend un résultat', $sonde_etat['resultat'] instanceof ResultatMigration );

$sonde_verif = sous_espion( 'verifier' );

check( '2 · verifier() n’interroge rien non plus', array() === $sonde_verif['appels'] );
check( '2 · et rend un résultat', $sonde_verif['resultat'] instanceof ResultatMigration );
check( '2 · et toujours aucune option', array() === $GLOBALS['urbizen_options'] );

// ======================================================================
// 3 · APPLICATION D’UNE SÉRIE
// ======================================================================
options_neuves();

$db  = new PasserelleMemoire();
$m1  = new MigrationEprouvee( '0001_a' );
$m2  = new MigrationEprouvee( '0002_b' );
$run = new MigrationRunner( $db, new MigrationCatalogue( array( $m1, $m2 ) ) );

$r = $run->executer();

check( '3 · la série réussit', $r->reussi() );
check( '3 · les deux migrations sont appliquées', array( '0001_a', '0002_b' ) === $r->appliquees() );
check( '3 · l’état est « appliquées »', ResultatMigration::APPLIQUEES === $r->etat() );
check( '3 · le registre a été créé', $db->table_existe( 'wp_urbizen_migration' ) );
check( '3 · le préfixe est calculé, jamais écrit en dur',
	false !== strpos( $db->instructions[1] ?? '', 'wp_urbizen_migration' ) );
check( '3 · le verrou est libéré à la fin',
	false === array_key_exists( MigrationLock::OPTION, $GLOBALS['urbizen_options'] ) );

// ======================================================================
// 4 · UNE MIGRATION DÉJÀ APPLIQUÉE N’EST PAS REJOUÉE
// ======================================================================
options_neuves();

$r2 = $run->executer();

check( '4 · le second passage n’applique rien', array() === $r2->appliquees() );
check( '4 · il les reconnaît comme déjà en place',
	array( '0001_a', '0002_b' ) === $r2->deja_appliquees() );
check( '4 · l’état est « déjà à jour »', ResultatMigration::DEJA_A_JOUR === $r2->etat() );
check( '4 · APPLIQUER N’A PAS ÉTÉ RAPPELÉ', 1 === $m1->applications && 1 === $m2->applications );

// ======================================================================
// 5 · UN ÉCHEC ARRÊTE LA SÉRIE
// ======================================================================
options_neuves();

$db3 = new PasserelleMemoire();
$ok  = new MigrationEprouvee( '0001_ok' );
$ko  = new MigrationEprouvee( '0002_ko', array(), false );
$apr = new MigrationEprouvee( '0003_apres' );

$r3 = ( new MigrationRunner( $db3, new MigrationCatalogue( array( $ok, $ko, $apr ) ) ) )->executer();

check( '5 · la série échoue', false === $r3->reussi() );
check( '5 · la migration fautive est nommée', '0002_ko' === $r3->migration_en_echec() );
check( '5 · le motif est la vérification négative', 'verification_negative' === $r3->motif() );
check( '5 · la première reste appliquée', array( '0001_ok' ) === $r3->appliquees() );
check( '5 · LA SUIVANTE N’EST PAS TENTÉE', 0 === $apr->applications );
check( '5 · RIEN N’EST INSCRIT POUR LA FAUTIVE',
	false === in_array( '0002_ko', array_column( $db3->registre, 'migration' ), true ) );
check( '5 · le verrou est tout de même libéré',
	false === array_key_exists( MigrationLock::OPTION, $GLOBALS['urbizen_options'] ) );

// ======================================================================
// 6 · UNE EXCEPTION EST RATTRAPÉE
// ======================================================================
options_neuves();

$db4 = new PasserelleMemoire();
$exp = new MigrationEprouvee( '0001_leve', array(), true, true );

$r4 = ( new MigrationRunner( $db4, new MigrationCatalogue( array( $exp ) ) ) )->executer();

check( '6 · une exception ne traverse pas', false === $r4->reussi() );
check( '6 · elle est rapportée comme motif', false !== strpos( $r4->motif(), 'échec voulu' ) );
check( '6 · LE VERROU EST LIBÉRÉ MALGRÉ L’EXCEPTION',
	false === array_key_exists( MigrationLock::OPTION, $GLOBALS['urbizen_options'] ) );

// ======================================================================
// 7 · CAPACITÉS EXIGÉES
// ======================================================================
options_neuves();

$db5 = new PasserelleMemoire();
$db5->capacites['check'] = false;

$exige = new MigrationEprouvee( '0001_check', array( SchemaGuard::CHECK ) );
$r5    = ( new MigrationRunner( $db5, new MigrationCatalogue( array( $exige ) ) ) )->executer();

check( '7 · une capacité absente arrête la migration', false === $r5->reussi() );
check( '7 · le motif nomme la capacité', false !== strpos( $r5->motif(), 'check' ) );
check( '7 · LA MIGRATION N’EST PAS APPLIQUÉE', 0 === $exige->applications );

options_neuves();
$db6 = new PasserelleMemoire();
$ok6 = new MigrationEprouvee( '0001_check', array( SchemaGuard::CHECK, SchemaGuard::INNODB ) );

check( '7 · capacités présentes : la migration passe',
	( new MigrationRunner( $db6, new MigrationCatalogue( array( $ok6 ) ) ) )->executer()->reussi() );

// ======================================================================
// 8 · SONDE DE CAPACITÉS
// ======================================================================
$db7   = new PasserelleMemoire();
$garde = new SchemaGuard( $db7 );

check( '8 · construire la garde n’interroge rien', array() === $db7->instructions );
check( '8 · InnoDB est reconnu', $garde->possede( SchemaGuard::INNODB ) );
check( '8 · utf8mb4 est reconnu', $garde->possede( SchemaGuard::UTF8MB4 ) );
check( '8 · les contraintes CHECK sont reconnues', $garde->possede( SchemaGuard::CHECK ) );
check( '8 · UNE CAPACITÉ INCONNUE EST REFUSÉE', false === $garde->possede( 'licorne' ) );
check( '8 · la première absente est nommée',
	'licorne' === $garde->premiere_absente( array( SchemaGuard::INNODB, 'licorne' ) ) );
check( '8 · rien à signaler quand tout est là',
	'' === $garde->premiere_absente( array( SchemaGuard::INNODB, SchemaGuard::UTF8MB4 ) ) );
check( '8 · une liste vide n’exige rien', '' === $garde->premiere_absente( array() ) );

// La sonde nettoie toujours derrière elle.
$db8 = new PasserelleMemoire();
( new SchemaGuard( $db8 ) )->possede( SchemaGuard::CHECK );

$drops = 0;

foreach ( $db8->instructions as $sql ) {
	if ( false !== strpos( $sql, 'DROP TEMPORARY TABLE' ) ) {
		++$drops;
	}
}

check( '8 · LA SONDE SUPPRIME SA TABLE TEMPORAIRE', 1 === $drops );
check( '8 · elle emploie une table temporaire',
	false !== strpos( $db8->instructions[0] ?? '', 'CREATE TEMPORARY TABLE' ) );
check( '8 · au nom imprévisible',
	1 === preg_match( '/`urbizen_sonde_[a-z0-9]{26}`/', $db8->instructions[0] ?? '' ) );

// ======================================================================
// 9 · VERROU
// ======================================================================
options_neuves();

$v1 = MigrationLock::acquerir( 1000 );

check( '9 · le verrou s’acquiert', $v1 instanceof MigrationLock );
check( '9 · son propriétaire est un ULID',
	\Urbizen\Platform\Domain\Support\Ulid::est_valide( $v1->proprietaire() ) );
check( '9 · il porte une échéance', 1000 + MigrationLock::TTL === $v1->expire_le() );
check( '9 · il est vivant', $v1->est_vivant( 1000 ) );

$v2 = MigrationLock::acquerir( 1001 );

check( '9 · UN SECOND PROCESSUS EST REFUSÉ', null === $v2 );

// Libération étrangère : impossible.
$intrus = new ReflectionClass( MigrationLock::class );
$faux   = $intrus->newInstanceWithoutConstructor();
$champ  = $intrus->getProperty( 'proprietaire' );
$champ->setAccessible( true );
$champ->setValue( $faux, \Urbizen\Platform\Domain\Support\Ulid::generer() );

check( '9 · UN AUTRE PROCESSUS NE PEUT PAS LIBÉRER', false === $faux->liberer() );
check( '9 · le verrou est toujours là', array_key_exists( MigrationLock::OPTION, $GLOBALS['urbizen_options'] ) );
check( '9 · le propriétaire, lui, libère', $v1->liberer() );
check( '9 · l’option a disparu',
	false === array_key_exists( MigrationLock::OPTION, $GLOBALS['urbizen_options'] ) );

// Expiration : reprise sûre.
options_neuves();
$vieux = MigrationLock::acquerir( 1000 );

check( '9 · un verrou expiré n’est plus vivant',
	false === $vieux->est_vivant( 1000 + MigrationLock::TTL + 1 ) );

$repreneur = MigrationLock::acquerir( 1000 + MigrationLock::TTL + 1 );

check( '9 · UN VERROU EXPIRÉ EST REPRIS', $repreneur instanceof MigrationLock );
check( '9 · par un propriétaire différent', $repreneur->proprietaire() !== $vieux->proprietaire() );
check( '9 · l’ancien ne peut plus libérer', false === $vieux->liberer() );

// Valeur corrompue : on refuse plutôt que de présumer.
options_neuves();
$GLOBALS['urbizen_options'][ MigrationLock::OPTION ] = 'corrompu';

check( '9 · UN VERROU ILLISIBLE FAIT REFUSER L’ACQUISITION', null === MigrationLock::acquerir( 2000 ) );

// ======================================================================
// 10 · RÉSULTAT
// ======================================================================
$rien = ResultatMigration::rien();

check( '10 · « rien » est un succès', $rien->reussi() );
check( '10 · et se reconnaît', $rien->rien_a_faire() );
check( '10 · son résumé est lisible', false !== strpos( $rien->resume(), 'rien à faire' ) );

$ech = ResultatMigration::echec( '0007_x', 'motif_precis' );

check( '10 · un échec n’est pas un succès', false === $ech->reussi() );
check( '10 · il nomme la migration', '0007_x' === $ech->migration_en_echec() );
check( '10 · et le motif', 'motif_precis' === $ech->motif() );
check( '10 · son résumé porte les deux',
	false !== strpos( $ech->resume(), '0007_x' ) && false !== strpos( $ech->resume(), 'motif_precis' ) );

verdict();
