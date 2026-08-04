<?php
/**
 * Catalogue des formulaires déclarés.
 *
 * Chaque définition est un fichier de `definitions/` renvoyant un tableau.
 * Le catalogue est une LISTE BLANCHE : aucune valeur reçue du navigateur ne
 * peut désigner un fichier, une classe ni une définition arbitraire.
 *
 * Deux formulaires sont livrés avec le socle : `localisation` et `conception`
 * (constante {@see self::KNOWN}). Le registre les enregistre au démarrage et
 * expose `register()` pour que de FUTURS formulaires commerciaux (Lot 2 et
 * suivants) s'ajoutent EXPLICITEMENT, côté serveur. Tant qu'aucun `register()`
 * supplémentaire n'est appelé, seuls les deux formulaires livrés sont connus.
 *
 * Décision : docs/DECISIONS.md D-050.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Forms;

use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistrement (liste blanche), chargement et mise en cache des définitions.
 */
final class FormRegistry {

	/**
	 * Formulaires livrés avec le socle. Sert de graine à la liste blanche.
	 *
	 * Reste public et strictement égal à ces deux identifiants : d'autres
	 * composants et bancs s'appuient dessus comme sur l'inventaire minimal.
	 */
	public const KNOWN = array( 'localisation', 'conception', 'declaration_prealable' );

	/**
	 * Règle des identifiants : minuscule initiale, puis minuscules, chiffres,
	 * tiret ou souligné ; 64 caractères au plus. Volontairement stricte —
	 * elle exclut d'emblée espaces, majuscules, Unicode, « / », « \ », « . »,
	 * « .. », octet nul, noms de classe et chemins. Aucun identifiant n'est
	 * normalisé : une valeur non conforme est rejetée, jamais réécrite (aucune
	 * collision ambiguë introduite par une normalisation silencieuse).
	 */
	private const REGLE_IDENTIFIANT = '/^[a-z][a-z0-9_-]{0,63}$/';

	/**
	 * Liste blanche effective. Initialisée paresseusement depuis self::KNOWN.
	 *
	 * @var array<int, string>|null
	 */
	private static ?array $registered = null;

	/**
	 * Définitions déjà chargées, indexées par identifiant.
	 *
	 * @var array<string, FormDefinition>
	 */
	private static array $loaded = array();

	/**
	 * Vrai si l'identifiant respecte la règle {@see self::REGLE_IDENTIFIANT}.
	 *
	 * @param string $id Identifiant candidat.
	 * @return bool
	 */
	private static function identifiant_valide( string $id ): bool {
		return 1 === preg_match( self::REGLE_IDENTIFIANT, $id );
	}

	/**
	 * Liste blanche courante (graine self::KNOWN au premier appel).
	 *
	 * @return array<int, string>
	 */
	private static function liste(): array {
		if ( null === self::$registered ) {
			self::$registered = array_values( self::KNOWN );
		}

		return self::$registered;
	}

	/**
	 * Enregistre explicitement un formulaire autorisé.
	 *
	 * **Contrat serveur.** Appelée UNIQUEMENT au démarrage (amorçage du plugin),
	 * avec un identifiant **littéral du dépôt** — JAMAIS avec une valeur de
	 * requête (`$_POST`/`$_GET`/attribut de bloc). C'est le seul moyen d'étendre
	 * la liste blanche au-delà des formulaires livrés ; le routage des
	 * soumissions n'y recourt pas : il résout un type via une table action→type
	 * fixe, côté serveur.
	 *
	 * Rejet explicite (journalisé, sans effet) d'un identifiant invalide ou
	 * déjà déclaré : aucune définition n'est jamais écrasée silencieusement.
	 * Le message ne divulgue aucun chemin interne.
	 *
	 * @param string $id Identifiant à enregistrer.
	 * @return bool Vrai si l'enregistrement a eu lieu.
	 */
	public static function register( string $id ): bool {
		self::liste();

		if ( ! self::identifiant_valide( $id ) ) {
			Logger::error( 'FormRegistry : enregistrement refusé (identifiant de formulaire invalide).' );
			return false;
		}

		if ( in_array( $id, self::$registered, true ) ) {
			Logger::error( 'FormRegistry : enregistrement refusé (formulaire déjà déclaré).' );
			return false;
		}

		self::$registered[] = $id;

		return true;
	}

	/**
	 * Vrai si l'identifiant est valide ET présent dans la liste blanche.
	 *
	 * @param string $id Identifiant.
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return self::identifiant_valide( $id ) && in_array( $id, self::liste(), true );
	}

	/**
	 * Liste blanche courante (copie).
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return self::liste();
	}

	/**
	 * Renvoie une définition, ou null si le type n'est pas autorisé ou illisible.
	 *
	 * La résolution est exclusivement serveur : le fichier n'est cherché que
	 * pour un identifiant déjà présent dans la liste blanche et conforme à la
	 * règle — jamais à partir d'une chaîne libre du navigateur.
	 *
	 * @param string $type Identifiant du formulaire.
	 * @return FormDefinition|null
	 */
	public static function get( string $type ): ?FormDefinition {
		if ( ! self::has( $type ) ) {
			return null;
		}

		if ( isset( self::$loaded[ $type ] ) ) {
			return self::$loaded[ $type ];
		}

		$file = URBIZEN_PLATFORM_DIR . 'src/Forms/definitions/' . $type . '.php';

		if ( ! is_readable( $file ) ) {
			return null;
		}

		$raw = require $file;

		if ( ! is_array( $raw ) || empty( $raw['fields'] ) || ! is_array( $raw['fields'] ) ) {
			return null;
		}

		$definition = new FormDefinition(
			(string) ( $raw['type'] ?? $type ),
			(string) ( $raw['title'] ?? '' ),
			(string) ( $raw['submit_label'] ?? '' ),
			$raw['fields'],
			isset( $raw['steps'] ) && is_array( $raw['steps'] ) ? $raw['steps'] : array()
		);

		// Une définition fautive n'interrompt pas la page, mais elle ne passe
		// jamais inaperçue : les anomalies sont des erreurs de développement.
		foreach ( $definition->errors() as $anomalie ) {
			Logger::error( sprintf( 'définition « %s » : %s', $type, $anomalie ) );
		}

		// Invariant du socle multi-formulaire : une définition ne peut pas
		// déclarer un type DIFFÉRENT de la clé sous laquelle elle est résolue.
		// Sans cette garantie, le type de la route cesserait d'être la source
		// canonique : prix, profil d'upload et notification pourraient suivre un
		// type divergent. Une telle définition est rejetée, jamais mise en cache.
		if ( $definition->type() !== $type ) {
			Logger::error( sprintf( 'définition « %s » : type déclaré « %s » incohérent, rejetée', $type, $definition->type() ) );

			return null;
		}

		self::$loaded[ $type ] = $definition;

		return self::$loaded[ $type ];
	}

	/**
	 * Type par défaut (premier formulaire livré).
	 */
	public static function default_type(): string {
		return self::liste()[0];
	}

	/**
	 * Réinitialise la liste blanche et le cache.
	 *
	 * @internal Ne fait PAS partie de l'API métier : primitive d'isolation des
	 * bancs d'essai uniquement. Hors contexte de test (`URBIZEN_TESTING`), elle
	 * lève une exception plutôt que d'agir ou de se taire — un appel accidentel
	 * en production doit être bruyant, jamais silencieux, et ne peut effacer
	 * aucun formulaire enregistré au démarrage.
	 *
	 * @throws \LogicException Si appelée hors d'un contexte de test.
	 */
	public static function reset_for_tests(): void {
		if ( ! ( defined( 'URBIZEN_TESTING' ) && URBIZEN_TESTING ) ) {
			throw new \LogicException( 'FormRegistry::reset_for_tests() est réservée aux bancs d\'essai (URBIZEN_TESTING).' );
		}

		self::$registered = null;
		self::$loaded     = array();
	}
}
