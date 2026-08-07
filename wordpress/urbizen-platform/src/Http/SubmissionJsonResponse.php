<?php
/**
 * Réponse structurée d'une soumission, pour les clients qui attendent du JSON.
 *
 * Ce que cette classe compose n'est pas un reflet de la demande enregistrée :
 * c'est le strict nécessaire pour qu'une interface affiche une confirmation
 * honnête. Tout le reste — identifiant WordPress, journal de transaction,
 * chemins de fichiers, charge complète, jeton — reste côté serveur.
 *
 * Deux règles gouvernent la composition :
 *
 * 1. **Rien qui vienne du navigateur.** Le tarif, la référence et le statut
 *    sont relus depuis la demande **persistée**, jamais repris de la requête.
 *    Un total injecté dans le POST n'a donc aucun chemin jusqu'ici.
 * 2. **Rien de technique dans un message.** Les erreurs portent une catégorie
 *    publique en liste blanche et un texte destiné à une personne. Aucun code
 *    interne, aucun nom de classe, aucune trace.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

use Urbizen\Platform\Forms\CatalogueProjets;
use Urbizen\Platform\Forms\CatalogueRegistry;
use Urbizen\Platform\Submissions\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Composition des réponses JSON de soumission.
 */
final class SubmissionJsonResponse {

	/**
	 * Messages publics par catégorie d'erreur.
	 *
	 * La catégorie vient de la liste blanche de {@see SubmissionFeedback} ; le
	 * message est écrit pour être lu, pas décodé.
	 *
	 * @var array<string, string>
	 */
	private const MESSAGES = array(
		'validation'  => 'Certaines informations n’ont pas pu être validées. Vérifiez les champs signalés, puis renvoyez votre demande.',
		'rate_limited' => 'Vous avez envoyé plusieurs demandes coup sur coup. Patientez quelques minutes avant de réessayer.',
		'unavailable' => 'Le service est momentanément indisponible. Réessayez dans quelques instants.',
		'technical'   => 'Votre demande n’a pas pu être envoyée. Réessayez, ou contactez-nous si le problème persiste.',
	);

	/**
	 * Compose la réponse d'une soumission réussie.
	 *
	 * @param SubmissionResult $resultat Issue du traitement.
	 * @return array<string, mixed>
	 */
	public static function succes( SubmissionResult $resultat ): array {
		$demande = SubmissionRepository::get( $resultat->id() );
		$charge  = is_array( $demande ) ? ( $demande['payload'] ?? array() ) : array();
		$tarif   = is_array( $demande ) ? ( $demande['pricing'] ?? array() ) : array();

		// Les libellés viennent du catalogue **du type de la demande**. Le nommer
		// en dur ferait disparaître du récapitulatif tout projet d'un autre
		// parcours, sans rien signaler : `libelle_nature()` rend `null` hors
		// catalogue, et l'entrée serait silencieusement écartée.
		$type      = is_array( $demande ) ? (string) ( $demande['form_type'] ?? '' ) : '';
		$principal = isset( $charge['nature'] ) ? (string) $charge['nature'] : '';

		return array(
			'success'                        => true,
			'reference'                      => $resultat->reference(),
			'status'                         => is_array( $demande ) ? (string) ( $demande['status'] ?? '' ) : '',
			// Le tarif est relu depuis ce qui a été persisté, donc recalculé par
			// le serveur : aucun montant du navigateur n'y survit.
			'pricing'                        => self::tarif( $tarif, $type ),
			'project'                        => self::projet( $type, $principal ),
			'additional_projects'            => self::projets_supplementaires( $type, $charge ),
			'deferred_documents'             => self::pieces_differees( $type, $charge ),
			'deferred_cadastral_information' => self::option_active( $charge, 'informations_cadastrales_differees' ),
		);
	}

	/**
	 * Compose la réponse d'un refus corrigeable ou d'un incident.
	 *
	 * @param string                $categorie Catégorie publique en liste blanche.
	 * @param array<string, string> $erreurs   Erreurs par champ, déjà publiques.
	 * @return array<string, mixed>
	 */
	public static function echec( string $categorie, array $erreurs = array() ): array {
		$categorie = in_array( $categorie, SubmissionFeedback::CATEGORIES, true ) ? $categorie : 'technical';

		$reponse = array(
			'success' => false,
			'code'    => $categorie,
			'message' => self::MESSAGES[ $categorie ] ?? self::MESSAGES['technical'],
		);

		// Les champs en erreur ne sont utiles qu'à la validation, et seuls les
		// noms canoniques sortent — jamais la valeur reçue, jamais le code
		// interne du contrôle qui a échoué.
		if ( 'validation' === $categorie && array() !== $erreurs ) {
			$reponse['fields'] = array_values( array_keys( $erreurs ) );
		}

		return $reponse;
	}

	/**
	 * Code HTTP correspondant à une catégorie publique.
	 *
	 * Un refus corrigeable est une erreur de requête ; une indisponibilité est
	 * une erreur de service. La distinction compte pour les intermédiaires
	 * réseau autant que pour l'interface.
	 *
	 * @param string $categorie Catégorie publique.
	 * @return int
	 */
	public static function statut_http( string $categorie ): int {
		switch ( $categorie ) {
			case 'validation':
				return 422;

			case 'rate_limited':
				return 429;

			case 'unavailable':
				return 503;

			default:
				return 500;
		}
	}

	/**
	 * Une option à liste fermée est-elle active ?
	 *
	 * Le validateur normalise une case à options en **tableau** de valeurs
	 * retenues. Comparer à une chaîne rendrait donc toujours faux — le drapeau
	 * cadastral remontait à « non » alors que le client l'avait coché.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @param string               $champ  Nom du champ.
	 * @return bool
	 */
	private static function option_active( array $charge, string $champ ): bool {
		$valeur = $charge[ $champ ] ?? null;

		if ( is_array( $valeur ) ) {
			return in_array( 'oui', array_map( 'strval', $valeur ), true );
		}

		return is_scalar( $valeur ) && 'oui' === (string) $valeur;
	}

	/**
	 * Détail tarifaire, réduit à ce qui s'affiche.
	 *
	 * @param array<string, mixed> $tarif Tarif persisté.
	 * @param string               $type  Type de formulaire.
	 * @return array<string, mixed>
	 */
	private static function tarif( array $tarif, string $type ): array {
		$options = array();

		foreach ( (array) ( $tarif['options'] ?? array() ) as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$options[] = array(
				'label'  => self::libelle_option( $type, (string) ( $option['id'] ?? '' ) ),
				'amount' => (int) ( $option['price'] ?? 0 ),
			);
		}

		return array(
			// Le socle suit la même règle que le total : `null` quand il n'est
			// pas chiffrable, jamais `0`, qui se lirait comme la gratuité.
			'base'    => array_key_exists( 'base', $tarif ) ? self::montant( $tarif['base'] ) : 0,
			'options' => $options,
			// `array_key_exists` et non `isset` : un total volontairement non
			// chiffré vaut `null`, et doit se distinguer d'un total absent.
			'total'   => array_key_exists( 'total', $tarif ) ? self::montant( $tarif['total'] ) : null,
			// Le statut persisté fait foi ; à défaut, il se déduit du total.
			'status'  => isset( $tarif['pricing_status'] ) && in_array( $tarif['pricing_status'], array( 'estime', 'sur_etude' ), true )
				? (string) $tarif['pricing_status']
				: ( null === ( $tarif['total'] ?? null ) ? 'sur_etude' : 'estime' ),
		);
	}

	/**
	 * Montant public : entier, ou `null` s'il est volontairement non chiffré.
	 *
	 * @param mixed $valeur Valeur persistée.
	 * @return int|null
	 */
	private static function montant( $valeur ): ?int {
		return null === $valeur ? null : (int) $valeur;
	}

	/**
	 * Libellé client d'une ligne de tarif.
	 *
	 * @param string $type Type de formulaire.
	 * @param string $id   Identifiant de l'option.
	 * @return string
	 */
	private static function libelle_option( string $type, string $id ): string {
		if ( str_starts_with( $id, 'projet_supplementaire:' ) ) {
			$nature = substr( $id, strlen( 'projet_supplementaire:' ) );

			return (string) ( CatalogueRegistry::libelle_nature( $type, $nature ) ?? '' );
		}

		switch ( $id ) {
			case 'secteur_abf':
				return 'Secteur Bâtiments de France';

			case 'depot_guichet':
				return 'Dépôt sur le guichet numérique';

			default:
				// Un identifiant inconnu ne ressort pas tel quel : il serait la
				// seule donnée technique visible de toute la réponse.
				return '';
		}
	}

	/**
	 * Projet principal, sous son libellé client.
	 *
	 * @param string $type   Type de formulaire.
	 * @param string $nature Identifiant canonique.
	 * @return array<string, string>
	 */
	private static function projet( string $type, string $nature ): array {
		return array(
			'id'    => $nature,
			'label' => (string) ( CatalogueRegistry::libelle_nature( $type, $nature ) ?? '' ),
		);
	}

	/**
	 * Projets supplémentaires retenus, avec leur description éventuelle.
	 *
	 * @param string               $type   Type de formulaire.
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return array<int, array<string, string>>
	 */
	private static function projets_supplementaires( string $type, array $charge ): array {
		$liste = array();

		foreach ( (array) ( $charge['projets_supplementaires'] ?? array() ) as $nature ) {
			$nature  = (string) $nature;
			$libelle = CatalogueRegistry::libelle_nature( $type, $nature );

			if ( null === $libelle ) {
				continue;
			}

			$entree = array(
				'id'    => $nature,
				'label' => $libelle,
			);

			$cle = CatalogueProjets::PREFIXE_DESCRIPTION . $nature;

			if ( isset( $charge[ $cle ] ) && '' !== (string) $charge[ $cle ] ) {
				$entree['description'] = (string) $charge[ $cle ];
			}

			$liste[] = $entree;
		}

		return $liste;
	}

	/**
	 * Pièces annoncées comme transmises plus tard.
	 *
	 * @param string               $type   Type de formulaire.
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return array<int, array<string, string>>
	 */
	private static function pieces_differees( string $type, array $charge ): array {
		$liste = array();

		foreach ( (array) ( $charge['pieces_differees'] ?? array() ) as $piece ) {
			$piece   = (string) $piece;
			$libelle = CatalogueRegistry::libelle_piece( $type, $piece );

			if ( null === $libelle ) {
				continue;
			}

			$liste[] = array(
				'id'    => $piece,
				'label' => $libelle,
			);
		}

		return $liste;
	}
}
