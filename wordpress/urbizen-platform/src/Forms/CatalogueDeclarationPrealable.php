<?php
/**
 * Catalogue canonique de la déclaration préalable.
 *
 * Source unique de la correspondance « identifiant technique → libellé client »
 * pour les natures de projet et les types de pièces. Tout ce qui doit nommer un
 * projet devant un humain — notification interne, écran d'administration,
 * récapitulatif serveur, accusé de réception — passe par ici.
 *
 * Pourquoi un catalogue plutôt que des libellés recopiés là où ils servent :
 * jusqu'ici, la même liste vivait dans le HTML des quatre formulaires, dans la
 * configuration tarifaire de chacun et dans les bancs. Un ajout de nature
 * obligeait à quatre modifications concordantes, et rien ne signalait un oubli.
 * Le catalogue rend l'oubli visible : un banc de contrat compare cette liste à
 * celle des formulaires et échoue à la moindre divergence.
 *
 * Les identifiants sont **canoniques** : minuscules, sans accent ni espace,
 * conformes au motif imposé par {@see FormDefinition::ID_PATTERN}. Les libellés,
 * eux, sont ceux que le client lit — accents et ponctuation compris. Les deux ne
 * se déduisent pas l'un de l'autre, et c'est voulu : un libellé peut être
 * reformulé sans qu'aucune donnée déjà enregistrée ne change de sens.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Natures de projet et types de pièces de la déclaration préalable.
 */
final class CatalogueDeclarationPrealable {

	/**
	 * Natures de projet : identifiant canonique → libellé lu par le client.
	 *
	 * L'ordre est celui des cartes du formulaire. Il détermine l'ordre du
	 * récapitulatif, afin que deux dossiers identiques se lisent pareil quel
	 * que soit l'ordre de saisie.
	 *
	 * @var array<string, string>
	 */
	public const NATURES = array(
		'extension'              => 'Extension',
		'abri_annexe'            => 'Abri, annexe',
		'garage'                 => 'Garage',
		'carport'                => 'Carport, abri de voiture',
		'piscine'                => 'Piscine',
		'cloture_mur'            => 'Clôture, mur',
		'modification_facade'    => 'Façade / ouverture',
		'ravalement'             => 'Ravalement',
		'toiture'                => 'Toiture',
		'panneaux_solaires'      => 'Panneaux solaires',
		'changement_destination' => 'Changement de destination',
		'autre'                  => 'Autre',
	);

	/**
	 * Types de pièces : identifiant canonique → libellé lu par le client.
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
		return array_keys( self::NATURES );
	}

	/**
	 * Identifiants de pièce, dans l'ordre du catalogue.
	 *
	 * @return array<int, string>
	 */
	public static function pieces(): array {
		return array_keys( self::PIECES );
	}

	/**
	 * Blocs de dépôt admis par le profil d'upload.
	 *
	 * @return array<int, string>
	 */
	public static function blocs(): array {
		return array_map(
			static fn( $id ) => self::PREFIXE_PIECE . $id,
			self::pieces()
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
		return self::NATURES[ $id ] ?? null;
	}

	/**
	 * Libellé client d'un type de pièce, ou null si l'identifiant est inconnu.
	 *
	 * @param string $id Identifiant canonique.
	 * @return string|null
	 */
	public static function libelle_piece( string $id ): ?string {
		return self::PIECES[ $id ] ?? null;
	}

	/**
	 * Options prêtes pour une définition de formulaire.
	 *
	 * @param bool $avec_price_id Attacher l'identifiant tarifaire à chaque option.
	 * @return array<int, array<string, string>>
	 */
	public static function options_natures( bool $avec_price_id = false ): array {
		$options = array();

		foreach ( self::NATURES as $id => $libelle ) {
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

		foreach ( self::PIECES as $id => $libelle ) {
			$options[] = array(
				'value' => $id,
				'label' => $libelle,
			);
		}

		return $options;
	}
}
