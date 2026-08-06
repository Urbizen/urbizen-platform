<?php
/**
 * Quels champs une nature de projet rend-elle pertinents ?
 *
 * Un formulaire générique pose les mêmes questions à tout le monde. C'est
 * commode à écrire et absurde à remplir : on demandait une **surface de
 * plancher** pour une piscine, une clôture, un ravalement ou des panneaux
 * solaires — des projets qui n'en créent aucune. Le client comprend qu'on ne
 * comprend pas son projet, et il a raison.
 *
 * Cette matrice dit, pour chaque nature, quels champs ont un sens. Elle sert
 * deux fois :
 *
 * 1. **À l'affichage**, pour ne montrer que ce qui s'applique ;
 * 2. **Au serveur**, pour **écarter** ce qui ne s'applique pas.
 *
 * Le second usage est le seul qui protège réellement les données. Un champ
 * masqué en JavaScript reste envoyable : il suffit de le réafficher, ou de
 * poster sans navigateur. Une surface de plancher persistée sur une piscine
 * n'est pas une donnée inutile, c'est une donnée **fausse** — elle se
 * retrouverait dans le CERFA. Le filtrage serveur n'est donc pas une
 * précaution, c'est la règle ; le masquage n'en est que la politesse.
 *
 * **Ce que cette matrice ne fait pas encore.** Elle ne porte que les champs qui
 * existent déjà dans les définitions. Les questions propres à chaque nature —
 * longueur et profondeur d'un bassin, nombre de panneaux, couleur avant et
 * après un ravalement — restent à créer. Les déclarer ici avant qu'elles
 * existent produirait une matrice qui décrit un formulaire imaginaire.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Champs applicables par nature de projet.
 */
final class MatriceChamps {

	/**
	 * Champs conditionnés par la nature du projet principal.
	 *
	 * **Seuls ces champs sont filtrés.** Tout ce qui n'y figure pas — identité,
	 * terrain, description, contexte, consentements — est applicable quelle que
	 * soit la nature et traverse la matrice sans être touché. Lister les champs
	 * conditionnels plutôt que les inconditionnels évite qu'un champ ajouté
	 * demain disparaisse silencieusement faute d'avoir été déclaré.
	 *
	 * @var array<int, string>
	 */
	public const CONDITIONNELS = array(
		'sp_existante',
		'sp_creee',
		'sp_totale',
		'emprise_avant',
		'emprise_creee',
		'surface_taxable',
		'nb_logements',
		'nb_stationnement',
		// Une maison neuve peut comporter un bassin, ou non. La question précède
		// les mesures : sans elle, on demanderait ses dimensions de piscine à
		// tout constructeur de maison.
		'piscine_prevue',
		// Précisions propres à la piscine. Elles suivent la même règle que les
		// surfaces : conditionnées par la nature, écartées sinon.
		'longueur_bassin_m',
		'largeur_bassin_m',
		'surface_bassin_m2',
		'profondeur_bassin_m',
		'presence_abri_piscine',
		'hauteur_abri_m',
	);

	/**
	 * Les six précisions de piscine, dans l'ordre où elles se posent.
	 *
	 * Nommées une fois : la DP les attache à sa nature « piscine », le PC à sa
	 * maison individuelle — un projet neuf comporte souvent un bassin. Les
	 * recopier aux deux endroits aurait suffi à les faire diverger.
	 *
	 * La différence entre les deux natures n'est pas la liste, c'est la porte
	 * d'entrée. Une DP « piscine » **est** une piscine : les mesures se posent
	 * d'emblée. Une maison individuelle peut en comporter une : `piscine_prevue`
	 * ouvre le bloc, et rien ne se demande tant qu'on n'a pas répondu.
	 *
	 * @var array<int, string>
	 */
	private const BASSIN = array(
		'longueur_bassin_m',
		'largeur_bassin_m',
		'surface_bassin_m2',
		'profondeur_bassin_m',
		'presence_abri_piscine',
		'hauteur_abri_m',
	);

	/**
	 * Déclaration préalable : nature → champs conditionnels applicables.
	 *
	 * Une nature absente de cette table n'admet **aucun** champ conditionnel :
	 * c'est le cas de « Autre », dont l'ampleur n'est pas bornée et pour lequel
	 * seule la description libre a du sens.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const DP = array(
		'extension'              => array( 'sp_existante', 'sp_creee', 'sp_totale', 'emprise_avant', 'emprise_creee', 'surface_taxable' ),
		'abri_annexe'            => array( 'sp_creee', 'emprise_avant', 'emprise_creee', 'surface_taxable' ),
		'garage'                 => array( 'sp_creee', 'emprise_avant', 'emprise_creee', 'surface_taxable' ),
		// Un carport ouvert ne crée pas de surface de plancher : il a une
		// emprise, pas un plancher clos.
		'carport'                => array( 'emprise_avant', 'emprise_creee' ),
		// Une piscine n'a ni plancher ni emprise au sens du bâti. Le bassin est
		// la seule mesure qui la décrive.
		// Une piscine ne décrit que son bassin — et le décrit vraiment.
		'piscine'                => self::BASSIN,
		'cloture_mur'            => array(),
		'modification_facade'    => array(),
		'ravalement'             => array(),
		'toiture'                => array(),
		'panneaux_solaires'      => array(),
		// Un changement de destination porte sur une surface existante : elle
		// est pertinente, et reste facultative.
		'changement_destination' => array( 'sp_existante', 'sp_totale' ),
		'autre'                  => array(),
	);

	/**
	 * Permis de construire : nature → champs conditionnels applicables.
	 *
	 * @var array<string, array<int, string>>
	 */
	public const PC = array(
		// L'ordre suit celui du catalogue — donc celui des cartes du formulaire.
		// Un banc l'exige : deux listes qui décrivent la même chose et divergent
		// finissent par se contredire.
		'maison_individuelle'    => array( 'sp_creee', 'sp_totale', 'emprise_avant', 'emprise_creee', 'surface_taxable', 'nb_logements', 'nb_stationnement', 'piscine_prevue', ...self::BASSIN ),
		'extension'              => array( 'sp_existante', 'sp_creee', 'sp_totale', 'emprise_avant', 'emprise_creee', 'surface_taxable' ),
		'annexe_garage'          => array( 'sp_creee', 'emprise_avant', 'emprise_creee', 'surface_taxable', 'nb_stationnement' ),
		'surelevation'           => array( 'sp_existante', 'sp_creee', 'sp_totale', 'surface_taxable', 'nb_logements' ),
		'changement_destination' => array( 'sp_existante', 'sp_totale', 'nb_logements' ),
		'autre'                  => array(),
	);

	/**
	 * Matrice d'un type de formulaire, ou null s'il n'en a pas.
	 *
	 * @param string $type Type de formulaire, résolu côté serveur.
	 * @return array<string, array<int, string>>|null
	 */
	public static function pour_type( string $type ): ?array {
		switch ( $type ) {
			case 'declaration_prealable':
				return self::DP;

			case 'permis_construire':
				return self::PC;

			default:
				// Conception et localisation ne décrivent pas de natures de
				// projet : leurs champs ne sont conditionnés par rien.
				return null;
		}
	}

	/**
	 * Un champ est-il applicable à cette nature, pour ce type ?
	 *
	 * Un champ inconditionnel l'est toujours. Un champ conditionnel ne l'est que
	 * si la nature le déclare. Une nature inconnue n'en admet aucun : le
	 * validateur métier la refuse déjà, et il ne faut pas qu'un identifiant
	 * forgé ouvre la porte à des champs qu'aucune nature n'autorise.
	 *
	 * @param string $type   Type de formulaire.
	 * @param string $nature Nature du projet principal.
	 * @param string $champ  Nom du champ.
	 * @return bool
	 */
	public static function applicable( string $type, string $nature, string $champ ): bool {
		if ( ! in_array( $champ, self::CONDITIONNELS, true ) ) {
			return true;
		}

		$matrice = self::pour_type( $type );

		if ( null === $matrice ) {
			return true;
		}

		return in_array( $champ, $matrice[ $nature ] ?? array(), true );
	}

	/**
	 * Champs conditionnels applicables à une nature.
	 *
	 * @param string $type   Type de formulaire.
	 * @param string $nature Nature du projet principal.
	 * @return array<int, string>
	 */
	public static function champs( string $type, string $nature ): array {
		$matrice = self::pour_type( $type );

		return null === $matrice ? self::CONDITIONNELS : ( $matrice[ $nature ] ?? array() );
	}

	/**
	 * Retire d'une charge nettoyée les champs que la nature ne justifie pas.
	 *
	 * Le retrait est **silencieux et non bloquant** : une surface envoyée pour
	 * une piscine n'est pas une tentative d'attaque, c'est le plus souvent le
	 * reliquat d'une nature changée en cours de saisie. La refuser ferait échouer
	 * une demande parfaitement légitime. L'écarter suffit — et l'écart est
	 * consigné, pour qu'un masquage devenu défaillant se voie dans les journaux
	 * plutôt que dans les dossiers.
	 *
	 * @param string               $type   Type de formulaire.
	 * @param array<string, mixed> $clean  Réponses nettoyées.
	 * @param array<int, string>   $ecarts Noms écartés, modifiés sur place.
	 * @return array<string, mixed>
	 */
	public static function filtrer( string $type, array $clean, array &$ecarts = array() ): array {
		$matrice = self::pour_type( $type );

		if ( null === $matrice ) {
			return $clean;
		}

		$nature = isset( $clean['nature'] ) && is_string( $clean['nature'] ) ? $clean['nature'] : '';

		// D'abord les sous-champs : leur pertinence dépend d'une autre réponse
		// autant que de la nature. Les traiter avant évite qu'un champ conservé
		// par la matrice survive à un pilote qui ne le justifie plus.
		$clean = self::filtrer_sous_champs( $type, $nature, $clean, $ecarts );

		foreach ( self::CONDITIONNELS as $champ ) {
			if ( ! array_key_exists( $champ, $clean ) ) {
				continue;
			}

			if ( self::applicable( $type, $nature, $champ ) ) {
				// Applicable mais vide : le champ n'est pas persisté pour autant.
				// Un `null` dans la charge se lit « mesuré, valeur inconnue »,
				// alors que la personne n'a simplement rien écrit. L'absence de
				// clé dit exactement cela, et rien de plus.
				if ( null === $clean[ $champ ] || '' === $clean[ $champ ] ) {
					unset( $clean[ $champ ] );
				}

				continue;
			}

			// Une valeur vide n'a rien à signaler : elle ne serait pas persistée
			// de toute façon, et la consigner noierait les vrais écarts.
			if ( null !== $clean[ $champ ] && '' !== $clean[ $champ ] ) {
				$ecarts[] = $champ;
			}

			unset( $clean[ $champ ] );
		}

		return $clean;
	}

	/**
	 * Champs dont la pertinence dépend d'une autre réponse, pas de la nature.
	 *
	 * `champ => array( pilote, valeur_qui_l_autorise )`. La hauteur d'un abri
	 * n'a de sens que si un abri est annoncé : reçue sans lui, elle décrit un
	 * objet qui n'existe pas. C'est le cas typique du reliquat — la personne a
	 * répondu « oui », mesuré, puis changé d'avis.
	 *
	 * **Un pilote que la nature ne pose pas ne gouverne rien.** `piscine_prevue`
	 * n'existe que pour la maison individuelle ; l'exiger d'une DP « piscine »
	 * effacerait les mesures d'un projet qui est une piscine. La règle est donc
	 * inerte quand son pilote n'est pas applicable, plutôt que d'être recopiée
	 * une fois par nature.
	 *
	 * **L'ordre compte** : les règles s'appliquent en cascade, chacune lisant
	 * une charge déjà nettoyée par les précédentes. `presence_abri_piscine`
	 * disparaît avec la piscine, et `hauteur_abri_m` disparaît avec l'abri.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const SOUS_CHAMPS = array(
		'longueur_bassin_m'     => array( 'piscine_prevue', 'oui' ),
		'largeur_bassin_m'      => array( 'piscine_prevue', 'oui' ),
		'surface_bassin_m2'     => array( 'piscine_prevue', 'oui' ),
		'profondeur_bassin_m'   => array( 'piscine_prevue', 'oui' ),
		'presence_abri_piscine' => array( 'piscine_prevue', 'oui' ),
		'hauteur_abri_m'        => array( 'presence_abri_piscine', 'oui' ),
	);

	/**
	 * Retire les sous-champs que leur pilote ne justifie pas.
	 *
	 * @param string               $type   Type de formulaire.
	 * @param string               $nature Nature du projet principal.
	 * @param array<string, mixed> $clean  Réponses nettoyées.
	 * @param array<int, string>   $ecarts Noms écartés, modifiés sur place.
	 * @return array<string, mixed>
	 */
	private static function filtrer_sous_champs( string $type, string $nature, array $clean, array &$ecarts ): array {
		foreach ( self::SOUS_CHAMPS as $champ => $regle ) {
			if ( ! array_key_exists( $champ, $clean ) ) {
				continue;
			}

			list( $pilote, $attendue ) = $regle;

			// Pilote non posé pour cette nature : la question n'existe pas, donc
			// elle ne conditionne rien.
			if ( ! self::applicable( $type, $nature, $pilote ) ) {
				continue;
			}

			$valeur = isset( $clean[ $pilote ] ) ? $clean[ $pilote ] : null;

			if ( is_scalar( $valeur ) && $attendue === (string) $valeur ) {
				continue;
			}

			if ( null !== $clean[ $champ ] && '' !== $clean[ $champ ] ) {
				$ecarts[] = $champ;
			}

			unset( $clean[ $champ ] );
		}

		return $clean;
	}
}
