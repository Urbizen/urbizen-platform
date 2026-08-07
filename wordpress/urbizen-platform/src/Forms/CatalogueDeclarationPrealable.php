<?php
/**
 * Catalogue canonique de la déclaration préalable.
 *
 * Source unique de la correspondance « identifiant technique → libellé client »
 * pour les natures de projet. Tout ce qui doit nommer un projet devant un
 * humain — notification interne, écran d'administration, récapitulatif serveur,
 * accusé de réception — passe par ici.
 *
 * Pourquoi un catalogue plutôt que des libellés recopiés là où ils servent :
 * jusqu'ici, la même liste vivait dans le HTML des quatre formulaires, dans la
 * configuration tarifaire de chacun et dans les bancs. Un ajout de nature
 * obligeait à quatre modifications concordantes, et rien ne signalait un oubli.
 * Le catalogue rend l'oubli visible : un banc de contrat compare cette liste à
 * celle des formulaires et échoue à la moindre divergence.
 *
 * Les pièces, les préfixes et tous les helpers vivent dans
 * {@see CatalogueProjets} : ils sont identiques d'un parcours à l'autre, et
 * seule la table des natures distingue une DP d'un permis de construire.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Natures de projet de la déclaration préalable.
 */
final class CatalogueDeclarationPrealable extends CatalogueProjets {

	/**
	 * Natures de projet : identifiant canonique → libellé lu par le client.
	 *
	 * L'ordre est celui des cartes du formulaire.
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
}
