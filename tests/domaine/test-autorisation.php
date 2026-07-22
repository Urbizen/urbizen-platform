<?php
/**
 * Banc : la porte d'autorisation.
 *
 * Le contrôle central est le premier : **une ressource qu'aucune politique ne
 * couvre est refusée**. Tout le reste en découle — si ce contrôle tombe, le
 * refus par défaut n'existe plus, et chaque ressource oubliée devient ouverte.
 *
 * Second enjeu : `administrator` n'est pas un court-circuit. Un administrateur
 * ne passe que là où une politique l'a écrit.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Domain\Authorization\Authorization;
use Urbizen\Platform\Domain\Authorization\Decision;
use Urbizen\Platform\Domain\Authorization\PolicyRegistry;
use Urbizen\Platform\Domain\Authorization\RefusParDefaut;
use Urbizen\Platform\Domain\Authorization\ResourcePolicy;
use Urbizen\Platform\Domain\Identity\ActeurCourant;
use Urbizen\Platform\Domain\Identity\CurrentUserProvider;

/** Ressource d'épreuve. */
final class RessourceEprouvee {
	public int $proprietaire;

	public function __construct( int $proprietaire = 0 ) {
		$this->proprietaire = $proprietaire;
	}
}

/** Ressource jamais enregistrée. */
final class RessourceOubliee {}

/** Sous-classe : ne doit hériter d'aucun droit. */
final class RessourceVoisine {}

/** Fournisseur d'identité pilotable. */
final class IdentiteFixe implements CurrentUserProvider {
	private ActeurCourant $acteur;

	public function __construct( ActeurCourant $acteur ) {
		$this->acteur = $acteur;
	}

	public function acteur(): ActeurCourant {
		return $this->acteur;
	}
}

/** Politique d'épreuve : le propriétaire voit, personne d'autre. */
final class PolitiqueEprouvee implements ResourcePolicy {
	public function gere(): string {
		return RessourceEprouvee::class;
	}

	public function decider( ActeurCourant $acteur, string $action, object $ressource ): Decision {
		if ( 'ressource.voir' !== $action ) {
			return Decision::non( 'action_inconnue' );
		}

		if ( $acteur->est_anonyme() ) {
			return Decision::non( 'anonyme' );
		}

		if ( $ressource instanceof RessourceEprouvee && $ressource->proprietaire === $acteur->id() ) {
			return Decision::oui( 'proprietaire' );
		}

		return Decision::non( 'pas_proprietaire' );
	}
}

/**
 * Fabrique une façade.
 *
 * @param ActeurCourant             $acteur     Acteur.
 * @param array<int, ResourcePolicy> $politiques Politiques enregistrées.
 * @return Authorization
 */
function porte( ActeurCourant $acteur, array $politiques = array() ): Authorization {
	$registre = new PolicyRegistry();

	foreach ( $politiques as $politique ) {
		$registre->enregistrer( $politique );
	}

	return new Authorization( $registre, new IdentiteFixe( $acteur ) );
}

$proprietaire = new ActeurCourant( 42, array( 'urbizen_client' ), true );
$autre        = new ActeurCourant( 43, array( 'urbizen_client' ), true );
$admin        = new ActeurCourant( 1, array( 'administrator' ), true );
$anonyme      = ActeurCourant::anonyme();

$ressource = new RessourceEprouvee( 42 );

// ======================================================================
// 1 · REFUS PAR DÉFAUT
// ======================================================================
$nue = porte( $admin );

check( '1 · RESSOURCE SANS POLITIQUE → REFUSÉE', false === $nue->peut( 'ressource.voir', $ressource ) );
check( '1 · le motif dit qu’aucune politique ne couvre',
	Decision::AUCUNE_POLITIQUE === $nue->decider( 'ressource.voir', $ressource )->motif() );
check( '1 · une ressource inconnue est refusée aussi',
	false === $nue->peut( 'quoi.que.ce.soit', new RessourceOubliee() ) );
check( '1 · MÊME UN ADMINISTRATEUR EST REFUSÉ SANS POLITIQUE',
	false === $nue->peut( 'ressource.voir', $ressource ) );

// ======================================================================
// 2 · POLITIQUE ENREGISTRÉE
// ======================================================================
$avec = porte( $proprietaire, array( new PolitiqueEprouvee() ) );

check( '2 · le propriétaire voit sa ressource', $avec->peut( 'ressource.voir', $ressource ) );
check( '2 · le motif est celui de la règle appliquée',
	'proprietaire' === $avec->decider( 'ressource.voir', $ressource )->motif() );

$vu_par_autre = porte( $autre, array( new PolitiqueEprouvee() ) );

check( '2 · un tiers ne voit pas', false === $vu_par_autre->peut( 'ressource.voir', $ressource ) );
check( '2 · et le motif le dit',
	'pas_proprietaire' === $vu_par_autre->decider( 'ressource.voir', $ressource )->motif() );

// ======================================================================
// 3 · ADMINISTRATEUR NON IMPLICITE
// ======================================================================
$vu_par_admin = porte( $admin, array( new PolitiqueEprouvee() ) );

check( '3 · UN ADMINISTRATEUR NE PASSE PAS SANS RÈGLE EXPLICITE',
	false === $vu_par_admin->peut( 'ressource.voir', $ressource ) );
check( '3 · il est refusé pour la même raison qu’un tiers',
	'pas_proprietaire' === $vu_par_admin->decider( 'ressource.voir', $ressource )->motif() );

// ======================================================================
// 4 · ANONYME
// ======================================================================
$vu_par_anonyme = porte( $anonyme, array( new PolitiqueEprouvee() ) );

check( '4 · l’anonyme est refusé', false === $vu_par_anonyme->peut( 'ressource.voir', $ressource ) );
check( '4 · avec le motif « anonyme »',
	'anonyme' === $vu_par_anonyme->decider( 'ressource.voir', $ressource )->motif() );

// ======================================================================
// 5 · ACTION
// ======================================================================
check( '5 · une action inconnue est refusée', false === $avec->peut( 'ressource.supprimer', $ressource ) );
check( '5 · UNE ACTION VIDE EST REFUSÉE', false === $avec->peut( '', $ressource ) );
check( '5 · une action faite d’espaces aussi', false === $avec->peut( '   ', $ressource ) );
check( '5 · le motif d’une action vide est explicite',
	'action_vide' === $avec->decider( '', $ressource )->motif() );
check( '5 · l’action est comparée sans espaces parasites',
	$avec->peut( '  ressource.voir  ', $ressource ) );

// ======================================================================
// 6 · LE REGISTRE NE DEVINE PAS
// ======================================================================
check( '6 · UNE AUTRE CLASSE N’HÉRITE PAS DE LA POLITIQUE',
	false === $avec->peut( 'ressource.voir', new RessourceVoisine() ) );
check( '6 · elle est refusée faute de politique',
	Decision::AUCUNE_POLITIQUE === $avec->decider( 'ressource.voir', new RessourceVoisine() )->motif() );

// ======================================================================
// 7 · LE REGISTRE REFUSE D’ÊTRE ÉCRASÉ
// ======================================================================
$registre = new PolicyRegistry();
$registre->enregistrer( new PolitiqueEprouvee() );

$leve = false;

try {
	$registre->enregistrer( new PolitiqueEprouvee() );
} catch ( InvalidArgumentException $e ) {
	$leve = true;
}

check( '7 · UNE SECONDE POLITIQUE POUR LA MÊME CLASSE EST REFUSÉE', $leve );
check( '7 · la première reste en place', $registre->couvre( RessourceEprouvee::class ) );
check( '7 · une classe non enregistrée n’est pas couverte',
	false === $registre->couvre( RessourceOubliee::class ) );
check( '7 · les classes couvertes sont listées',
	array( RessourceEprouvee::class ) === $registre->classes_couvertes() );

// ======================================================================
// 8 · POLITIQUE TERMINALE
// ======================================================================
$refus = new RefusParDefaut();

check( '8 · elle refuse un anonyme',
	false === $refus->decider( $anonyme, 'x', $ressource )->autorisee() );
check( '8 · elle refuse un administrateur',
	false === $refus->decider( $admin, 'x', $ressource )->autorisee() );
check( '8 · elle ne vise aucune classe précise', RefusParDefaut::TOUTES === $refus->gere() );

// ======================================================================
// 9 · DÉCIDER POUR UN ACTEUR DÉSIGNÉ
// ======================================================================
$hors_requete = porte( $anonyme, array( new PolitiqueEprouvee() ) );

check( '9 · décider_pour emprunte le même chemin',
	$hors_requete->decider_pour( $proprietaire, 'ressource.voir', $ressource )->autorisee() );
check( '9 · et refuse un tiers de la même façon',
	false === $hors_requete->decider_pour( $autre, 'ressource.voir', $ressource )->autorisee() );

// ======================================================================
// 10 · DÉCISION
// ======================================================================
check( '10 · un motif vide devient « sans_motif »', 'sans_motif' === Decision::oui( '  ' )->motif() );
check( '10 · la forme lisible porte le sens', 'oui (regle)' === (string) Decision::oui( 'regle' ) );
check( '10 · et le refus aussi', 'non (regle)' === (string) Decision::non( 'regle' ) );

verdict();
