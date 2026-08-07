<?php
/**
 * Socle commun des catalogues de projets d'une autorisation d'urbanisme.
 *
 * La déclaration préalable et le permis de construire ne proposent pas les
 * mêmes natures de projet — c'est même la seule chose qui les distingue à ce
 * niveau. Tout le reste est identique : la façon de nommer un projet devant un
 * humain, de dériver les blocs de dépôt, de composer les options d'une
 * définition, et la liste des pièces elle-même.
 *
 * Ce socle porte donc tout ce qui est commun, et chaque catalogue concret ne
 * déclare que sa table de natures. Écrire deux fois les mêmes helpers aurait
 * garanti qu'un correctif finisse par n'être appliqué qu'à l'un des deux.
 *
 * Les identifiants sont **canoniques** : minuscules, sans accent ni espace,
 * conformes au motif imposé par {@see FormDefinition::ID_PATTERN}. Les libellés
 * sont ceux que le client lit. Les deux ne se déduisent pas l'un de l'autre, et
 * c'est voulu : un libellé peut être reformulé sans qu'aucune donnée déjà
 * enregistrée ne change de sens.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Comportement commun aux catalogues de natures et de pièces.
 */
abstract class CatalogueProjets {

	/**
	 * Natures de projet : identifiant canonique → libellé lu par le client.
	 *
	 * Redéclarée par chaque catalogue concret. L'ordre est celui des cartes du
	 * formulaire : il détermine l'ordre du récapitulatif, afin que deux dossiers
	 * identiques se lisent pareil quel que soit l'ordre de saisie.
	 *
	 * @var array<string, string>
	 */
	public const NATURES = array();

	/**
	 * Types de pièces : identifiant canonique → libellé lu par le client.
	 *
	 * **Communs aux deux parcours**, et pas par commodité : ce sont les mêmes
	 * pièces que l'interface propose dans les deux formulaires. Les dupliquer
	 * par type aurait créé la possibilité qu'ils divergent sans raison.
	 *
	 * Ces identifiants nomment à la fois le champ de dépôt (`piece_<id>`), le
	 * bloc du profil d'upload et la valeur admise dans `pieces_differees[]`.
	 * Une seule liste, trois usages : c'est ce qui garantit qu'une pièce
	 * déposable est aussi une pièce reportable.
	 *
	 * @var array<string, string>
	 */
	public const PIECES = array(
		'photo'   => 'Photos du terrain et de la maison existante',
		'rue'     => 'Photos prises depuis la rue et de l’accès',
		'facades' => 'Photos des façades concernées',
		'croquis' => 'Croquis du projet, même à main levée',
		'plans'   => 'Plans existants en votre possession',
		'mesures' => 'Relevés de dimensions ou mesures utiles',
		'autres'  => 'Autres documents utiles : devis, matériaux, étude de sol…',
	);

	/**
	 * Préfixe des champs de dépôt, et donc des blocs du profil d'upload.
	 */
	public const PREFIXE_PIECE = 'piece_';

	/**
	 * Préfixe des descriptions facultatives de projet supplémentaire.
	 *
	 * La clé est déterministe — `description_projet_<nature>` — parce que les
	 * doublons de nature sont interdits : une nature identifie sa description
	 * sans ambiguïté, sans index positionnel fragile.
	 */
	public const PREFIXE_DESCRIPTION = 'description_projet_';

	/**
	 * Identifiants de nature, dans l'ordre du catalogue.
	 *
	 * @return array<int, string>
	 */
	public static function natures(): array {
		return array_keys( static::NATURES );
	}

	/**
	 * Identifiants de pièce, dans l'ordre du catalogue.
	 *
	 * @return array<int, string>
	 */
	public static function pieces(): array {
		return array_keys( static::PIECES );
	}

	/**
	 * Blocs de dépôt admis par le profil d'upload.
	 *
	 * @return array<int, string>
	 */
	public static function blocs(): array {
		return array_map(
			static fn( $id ) => static::PREFIXE_PIECE . $id,
			static::pieces()
		);
	}

	/**
	 * Libellé client d'une nature, ou null si l'identifiant est inconnu.
	 *
	 * Rend `null` plutôt qu'une chaîne de repli : un identifiant hors catalogue
	 * est une anomalie qui doit se voir, pas se traduire silencieusement.
	 *
	 * @param string $id Identifiant canonique.
	 * @return string|null
	 */
	public static function libelle_nature( string $id ): ?string {
		return static::NATURES[ $id ] ?? null;
	}

	/**
	 * Libellé client d'un type de pièce, ou null si l'identifiant est inconnu.
	 *
	 * @param string $id Identifiant canonique.
	 * @return string|null
	 */
	public static function libelle_piece( string $id ): ?string {
		return static::PIECES[ $id ] ?? null;
	}

	/**
	 * Options prêtes pour une définition de formulaire.
	 *
	 * @param bool $avec_price_id Attacher l'identifiant tarifaire à chaque option.
	 * @return array<int, array<string, string>>
	 */
	public static function options_natures( bool $avec_price_id = false ): array {
		$options = array();

		foreach ( static::NATURES as $id => $libelle ) {
			$option = array(
				'value' => $id,
				'label' => $libelle,
			);

			// Le `price_id` vaut l'identifiant lui-même : le catalogue tarifaire
			// est indexé sur les mêmes clés, ce qui interdit une table de
			// correspondance intermédiaire — donc une divergence possible.
			if ( $avec_price_id ) {
				$option['price_id'] = $id;
			}

			$options[] = $option;
		}

		return $options;
	}

	/**
	 * Options prêtes pour le champ `pieces_differees`.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function options_pieces(): array {
		$options = array();

		foreach ( static::PIECES as $id => $libelle ) {
			$options[] = array(
				'value' => $id,
				'label' => $libelle,
			);
		}

		return $options;
	}
}
