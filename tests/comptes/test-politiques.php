<?php
/**
 * Banc : les trois politiques.
 *
 * Le contrôle qui porte tout : **un rôle ne suffit jamais**. Ni
 * `urbizen_client`, ni `administrator` n'ouvrent quoi que ce soit.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\AutorisationComptes;
use Urbizen\Platform\Domain\Account\ActionVerifiee;
use Urbizen\Platform\Domain\Account\AdresseCourriel;
use Urbizen\Platform\Domain\Account\Compte;
use Urbizen\Platform\Domain\Account\DemandeVerification;
use Urbizen\Platform\Domain\Authorization\PolitiqueActionVerifiee;
use Urbizen\Platform\Domain\Authorization\PolitiqueCompte;
use Urbizen\Platform\Domain\Authorization\PolitiqueVerification;
use Urbizen\Platform\Domain\Identity\ActeurCourant;

/**
 * Fabrique une porte pour un acteur donné.
 *
 * @param ActeurCourant $acteur Acteur.
 * @return Urbizen\Platform\Domain\Authorization\Authorization
 */
function porte_pour( ActeurCourant $acteur ) {
	return AutorisationComptes::porte( new IdentiteDouble( $acteur ) );
}

$adresse   = new AdresseCourriel( 'claire@exemple.fr' );
$compte42  = new Compte( 42, $adresse, true );
$compte43  = new Compte( 43, new AdresseCourriel( 'paul@exemple.fr' ), true );
$demande42 = new DemandeVerification( $compte42 );
$action    = new ActionVerifiee( 'commander' );

$claire      = new ActeurCourant( 42, array( 'urbizen_client' ), true );
$claire_non  = new ActeurCourant( 42, array( 'urbizen_client' ), false );
$paul        = new ActeurCourant( 43, array( 'urbizen_client' ), true );
$admin       = new ActeurCourant( 1, array( 'administrator' ), true );
$admin_non   = new ActeurCourant( 1, array( 'administrator' ), false );
$anonyme     = ActeurCourant::anonyme();

// ======================================================================
// 1 · POLITIQUE DE COMPTE
// ======================================================================
check( '1 · le propriétaire voit son compte', porte_pour( $claire )->peut( PolitiqueCompte::VOIR, $compte42 ) );
check( '1 · et le modifie', porte_pour( $claire )->peut( PolitiqueCompte::MODIFIER, $compte42 ) );
check( '1 · UN TIERS NE VOIT PAS', false === porte_pour( $paul )->peut( PolitiqueCompte::VOIR, $compte42 ) );
check( '1 · le motif le dit',
	'compte_d_autrui' === porte_pour( $paul )->decider( PolitiqueCompte::VOIR, $compte42 )->motif() );
check( '1 · l’anonyme est refusé', false === porte_pour( $anonyme )->peut( PolitiqueCompte::VOIR, $compte42 ) );
check( '1 · une action inconnue est refusée', false === porte_pour( $claire )->peut( 'compte.detruire', $compte42 ) );
check( '1 · UN ADMINISTRATEUR NE VOIT PAS LE COMPTE D’AUTRUI',
	false === porte_pour( $admin )->peut( PolitiqueCompte::VOIR, $compte42 ) );

// ======================================================================
// 2 · POLITIQUE DE DEMANDE DE VÉRIFICATION
// ======================================================================
check( '2 · le propriétaire peut demander', porte_pour( $claire )->peut( PolitiqueVerification::DEMANDER, $demande42 ) );
check( '2 · même non vérifié — c’est tout l’objet de la demande',
	porte_pour( $claire_non )->peut( PolitiqueVerification::DEMANDER, $demande42 ) );
check( '2 · UN TIERS NE PEUT PAS', false === porte_pour( $paul )->peut( PolitiqueVerification::DEMANDER, $demande42 ) );
check( '2 · l’anonyme non plus', false === porte_pour( $anonyme )->peut( PolitiqueVerification::DEMANDER, $demande42 ) );
check( '2 · un administrateur non plus',
	false === porte_pour( $admin )->peut( PolitiqueVerification::DEMANDER, $demande42 ) );

// La politique ne doit consulter NI quota, NI limitation, NI verrou.
$source = (string) file_get_contents( URBIZEN_SRC . 'Domain/Authorization/PolitiqueVerification.php' );
$code   = (string) preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#' ), '', $source );

foreach ( array( 'LimiteEnvois', 'RateLimiter', 'VerrouCompte', 'lire_meta', 'quota' ) as $interdit ) {
	check(
		sprintf( '2 · elle ne consulte pas « %s »', $interdit ),
		false === strpos( $code, $interdit )
	);
}

// ======================================================================
// 3 · POLITIQUE D’ACTION VÉRIFIÉE
// ======================================================================
check( '3 · un compte vérifié agit', porte_pour( $claire )->peut( PolitiqueActionVerifiee::EXECUTER, $action ) );
check( '3 · UN COMPTE NON VÉRIFIÉ EST REFUSÉ',
	false === porte_pour( $claire_non )->peut( PolitiqueActionVerifiee::EXECUTER, $action ) );
check( '3 · avec le motif exact',
	'courriel_non_verifie' === porte_pour( $claire_non )->decider( PolitiqueActionVerifiee::EXECUTER, $action )->motif() );
check( '3 · UN ADMINISTRATEUR NON VÉRIFIÉ EST REFUSÉ',
	false === porte_pour( $admin_non )->peut( PolitiqueActionVerifiee::EXECUTER, $action ) );
check( '3 · l’anonyme est refusé', false === porte_pour( $anonyme )->peut( PolitiqueActionVerifiee::EXECUTER, $action ) );

// ======================================================================
// 4 · LE RÔLE N’AUTORISE RIEN   ← CONTRÔLE CENTRAL
// ======================================================================
$client_seul = new ActeurCourant( 99, array( 'urbizen_client' ), false );

check( '4 · urbizen_client SEUL N’AUTORISE AUCUNE ACTION MÉTIER',
	false === porte_pour( $client_seul )->peut( PolitiqueActionVerifiee::EXECUTER, $action ) );
check( '4 · ni l’accès au compte d’autrui',
	false === porte_pour( $client_seul )->peut( PolitiqueCompte::VOIR, $compte42 ) );
check( '4 · ni la demande de vérification pour autrui',
	false === porte_pour( $client_seul )->peut( PolitiqueVerification::DEMANDER, $demande42 ) );

// Aucune politique ne lit de rôle.
foreach ( array( 'PolitiqueCompte', 'PolitiqueVerification', 'PolitiqueActionVerifiee' ) as $nom ) {
	$src  = (string) file_get_contents( URBIZEN_SRC . 'Domain/Authorization/' . $nom . '.php' );
	$net  = (string) preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#' ), '', $src );

	check( sprintf( '4 · %s n’appelle jamais a_role()', $nom ), false === strpos( $net, 'a_role' ) );
	check( sprintf( '4 · %s ne mentionne pas administrator', $nom ), false === strpos( $net, 'administrator' ) );
}

// ======================================================================
// 5 · REFUS PAR DÉFAUT CONSERVÉ
// ======================================================================
check( '5 · une ressource inconnue reste refusée',
	false === porte_pour( $claire )->peut( 'quoi.que.ce.soit', new stdClass() ) );

$registre = AutorisationComptes::registre();

check( '5 · le registre couvre exactement trois classes', 3 === count( $registre->classes_couvertes() ) );

verdict();
