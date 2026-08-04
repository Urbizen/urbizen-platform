<?php
/**
 * Formulaire « déclaration préalable » — définition serveur.
 *
 * Cette définition ne rend pas l'interface : le parcours DP est un document
 * autonome, servi en iframe, dont le dessin a été validé séparément. Elle est
 * en revanche la **source de vérité** de tout ce qui engage le serveur : quels
 * champs existent, lesquels sont réellement exigés, quelles valeurs sont
 * admises, et quelles natures portent un tarif.
 *
 * Trois principes, qui expliquent les écarts avec ce que l'interface exige :
 *
 * 1. **Le serveur n'exige que l'indispensable.** L'interface peut demander
 *    davantage pour guider la saisie ; le serveur, lui, ne refuse une demande
 *    que s'il lui manque de quoi identifier le demandeur, le terrain et le
 *    projet. Tout ce que le client peut transmettre plus tard — références
 *    cadastrales, documents — reste facultatif ici, sans quoi la promesse
 *    faite à l'écran serait démentie au moment de l'envoi.
 * 2. **Les listes sont fermées.** Nature du projet, projets supplémentaires et
 *    pièces différées ne prennent leurs valeurs que dans
 *    {@see CatalogueDeclarationPrealable}. Une valeur hors catalogue est
 *    rejetée, jamais traduite ni tolérée.
 * 3. **Aucun montant n'est déclaré ici.** Les options portent un `price_id`
 *    qui n'est qu'un identifiant : les euros vivent dans
 *    {@see PricingDeclarationPrealable}, et le total est recalculé côté serveur
 *    à partir des seules réponses retenues.
 *
 * Les descriptions de projet supplémentaire portent une clé déterministe,
 * `description_projet_<nature>`. Les doublons de nature étant interdits, une
 * nature identifie sa description sans index positionnel — et une description
 * dont la nature n'a pas été retenue n'a simplement aucun effet.
 *
 * @package Urbizen\Platform
 */

use Urbizen\Platform\Forms\CatalogueDeclarationPrealable;

defined( 'ABSPATH' ) || exit;

// Première définition à s'appuyer sur une classe. L'autochargeur du greffon la
// fournit en production ; ce garde-fou rend le fichier autoportant pour les
// bancs, qui chargent une définition sans démarrer le greffon.
if ( ! class_exists( CatalogueDeclarationPrealable::class ) ) {
	require_once __DIR__ . '/../CatalogueDeclarationPrealable.php';
}

/**
 * Champs de description facultative, un par nature possible.
 *
 * @return array<int, array<string, mixed>>
 */
$descriptions_projets = array_map(
	static function ( string $nature ): array {
		return array(
			'name'      => CatalogueDeclarationPrealable::PREFIXE_DESCRIPTION . $nature,
			'type'      => 'text',
			'step'      => 'projets_supplementaires',
			'label'     => sprintf(
				/* translators: %s : libellé de la nature de projet. */
				__( 'Précisions — %s', 'urbizen-platform' ),
				(string) CatalogueDeclarationPrealable::libelle_nature( $nature )
			),
			'maxlength' => 200,
			// Facultative par construction, et sans effet tarifaire : une
			// description reçue sans sa nature est conservée nulle part.
			'visible_if' => array(
				'field' => 'projets_supplementaires',
				'in'    => array( $nature ),
			),
		);
	},
	CatalogueDeclarationPrealable::natures()
);

/**
 * Champs de dépôt, un par type de pièce.
 *
 * @return array<int, array<string, mixed>>
 */
$champs_pieces = array_map(
	static function ( string $piece ): array {
		return array(
			'name'      => CatalogueDeclarationPrealable::PREFIXE_PIECE . $piece,
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => (string) CatalogueDeclarationPrealable::libelle_piece( $piece ),
			'multiple'  => true,
			'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
		);
	},
	CatalogueDeclarationPrealable::pieces()
);

$definition = array(
	'type'         => 'declaration_prealable',
	'title'        => __( 'Déclaration préalable de travaux', 'urbizen-platform' ),
	'submit_label' => __( 'Envoyer ma demande', 'urbizen-platform' ),

	'steps'        => array(
		array(
			'id'          => 'declarant',
			'label'       => __( 'Déclarant', 'urbizen-platform' ),
			'title'       => __( 'Qui déclare les travaux ?', 'urbizen-platform' ),
			'description' => __( 'Identité et coordonnées de la personne qui dépose la demande.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'terrain',
			'label'       => __( 'Terrain', 'urbizen-platform' ),
			'title'       => __( 'Où se situe le terrain ?', 'urbizen-platform' ),
			'description' => __( 'Adresse et, si elles sont connues, références cadastrales.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'projet',
			'label'       => __( 'Projet', 'urbizen-platform' ),
			'title'       => __( 'En quoi consistent les travaux ?', 'urbizen-platform' ),
			'description' => __( 'Nature du projet principal et description.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'surfaces',
			'label'       => __( 'Surfaces', 'urbizen-platform' ),
			'title'       => __( 'Surfaces et emprise', 'urbizen-platform' ),
			'description' => __( 'Surfaces avant et après travaux.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'contexte',
			'label'       => __( 'Contexte', 'urbizen-platform' ),
			'title'       => __( 'Contexte réglementaire', 'urbizen-platform' ),
			'description' => __( 'Secteur protégé, démolition et précisions utiles.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'documents',
			'label'       => __( 'Documents', 'urbizen-platform' ),
			'title'       => __( 'Photos, croquis et plans disponibles', 'urbizen-platform' ),
			'description' => __( 'Aucun document n’est obligatoire pour envoyer la demande.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'projets_supplementaires',
			'label'       => __( 'Autres projets', 'urbizen-platform' ),
			'title'       => __( 'Autres projets réunis dans ce dossier', 'urbizen-platform' ),
			'description' => __( 'Projets regroupés avec le projet principal.', 'urbizen-platform' ),
		),
		array(
			'id'          => 'envoi',
			'label'       => __( 'Envoi', 'urbizen-platform' ),
			'title'       => __( 'Vérification et envoi', 'urbizen-platform' ),
			'description' => __( 'Engagements du déclarant.', 'urbizen-platform' ),
		),
	),

	'fields'       => array(

		/* ---------------------------------------------------------- *
		 *  Déclarant
		 * ---------------------------------------------------------- */

		array(
			'name'     => 'declarant_type',
			'type'     => 'radio',
			'step'     => 'declarant',
			'label'    => __( 'Vous êtes', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				array(
					'value' => 'particulier',
					'label' => __( 'Un particulier', 'urbizen-platform' ),
				),
				array(
					'value' => 'personne_morale',
					'label' => __( 'Une personne morale', 'urbizen-platform' ),
				),
			),
		),

		// L'identité est obligatoire, mais elle ne se dit pas de la même façon
		// selon la qualité du déclarant. `visible_if` rend l'exigence
		// conditionnelle : un champ inactif n'est pas réclamé, ce qui évite
		// d'imposer un nom de famille à une société.
		array(
			'name'       => 'nom',
			'type'       => 'text',
			'step'       => 'declarant',
			'label'      => __( 'Nom', 'urbizen-platform' ),
			'required'   => true,
			'maxlength'  => 120,
			'visible_if' => array(
				'field' => 'declarant_type',
				'in'    => array( 'particulier' ),
			),
		),
		array(
			'name'       => 'prenom',
			'type'       => 'text',
			'step'       => 'declarant',
			'label'      => __( 'Prénom', 'urbizen-platform' ),
			'required'   => true,
			'maxlength'  => 120,
			'visible_if' => array(
				'field' => 'declarant_type',
				'in'    => array( 'particulier' ),
			),
		),
		array(
			'name'       => 'denomination',
			'type'       => 'text',
			'step'       => 'declarant',
			'label'      => __( 'Dénomination', 'urbizen-platform' ),
			'required'   => true,
			'maxlength'  => 200,
			'visible_if' => array(
				'field' => 'declarant_type',
				'in'    => array( 'personne_morale' ),
			),
		),
		array(
			'name'       => 'siret',
			'type'       => 'text',
			'step'       => 'declarant',
			'label'      => __( 'SIRET', 'urbizen-platform' ),
			'maxlength'  => 20,
			'inputmode'  => 'numeric',
			'visible_if' => array(
				'field' => 'declarant_type',
				'in'    => array( 'personne_morale' ),
			),
		),
		array(
			'name'       => 'representant',
			'type'       => 'text',
			'step'       => 'declarant',
			'label'      => __( 'Représentant légal', 'urbizen-platform' ),
			'required'   => true,
			'maxlength'  => 200,
			'visible_if' => array(
				'field' => 'declarant_type',
				'in'    => array( 'personne_morale' ),
			),
		),
		array(
			'name'     => 'qualite',
			'type'     => 'select',
			'step'     => 'declarant',
			'label'    => __( 'Qualité du déclarant', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				array(
					'value' => 'proprietaire',
					'label' => __( 'Propriétaire', 'urbizen-platform' ),
				),
				array(
					'value' => 'mandataire',
					'label' => __( 'Mandataire du propriétaire', 'urbizen-platform' ),
				),
				array(
					'value' => 'locataire',
					'label' => __( 'Locataire avec accord du propriétaire', 'urbizen-platform' ),
				),
				array(
					'value' => 'copropriete',
					'label' => __( 'Copropriété (syndic)', 'urbizen-platform' ),
				),
				array(
					'value' => 'autre',
					'label' => __( 'Autre', 'urbizen-platform' ),
				),
			),
		),

		// `FormDefinition` ne connaît pas de type « email » : le champ est un
		// texte, et le format est vérifié côté serveur par `is_email()` dans le
		// validateur. Un contrôle HTML seul ne protège de rien.
		array(
			'name'         => 'email',
			'type'         => 'text',
			'step'         => 'declarant',
			'label'        => __( 'E-mail', 'urbizen-platform' ),
			'required'     => true,
			'maxlength'    => 200,
			'autocomplete' => 'email',
		),
		array(
			'name'      => 'telephone',
			'type'      => 'text',
			'step'      => 'declarant',
			'label'     => __( 'Téléphone', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 30,
			'inputmode' => 'tel',
		),
		array(
			'name'      => 'adresse_declarant',
			'type'      => 'text',
			'step'      => 'declarant',
			'label'     => __( 'Adresse postale du déclarant', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 300,
		),
		array(
			'name'      => 'cp_declarant',
			'type'      => 'text',
			'step'      => 'declarant',
			'label'     => __( 'Code postal', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 10,
			'inputmode' => 'numeric',
		),
		array(
			'name'      => 'ville_declarant',
			'type'      => 'text',
			'step'      => 'declarant',
			'label'     => __( 'Commune', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 120,
		),

		/* ---------------------------------------------------------- *
		 *  Terrain
		 * ---------------------------------------------------------- */

		array(
			'name'      => 'terrain_adresse',
			'type'      => 'text',
			'step'      => 'terrain',
			'label'     => __( 'Adresse du terrain', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 300,
		),
		array(
			'name'      => 'terrain_cp',
			'type'      => 'text',
			'step'      => 'terrain',
			'label'     => __( 'Code postal', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 10,
			'inputmode' => 'numeric',
		),
		array(
			'name'      => 'terrain_ville',
			'type'      => 'text',
			'step'      => 'terrain',
			'label'     => __( 'Commune', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 120,
		),

		// Les trois références cadastrales sont facultatives : elles ne figurent
		// que sur l'acte de propriété. Les exiger ferait abandonner une demande
		// que la cartographie, ou Urbizen après réception, sait compléter.
		array(
			'name'      => 'cad_section',
			'type'      => 'text',
			'step'      => 'terrain',
			'label'     => __( 'Section cadastrale', 'urbizen-platform' ),
			'maxlength' => 10,
		),
		array(
			'name'      => 'cad_numero',
			'type'      => 'text',
			'step'      => 'terrain',
			'label'     => __( 'Numéro de parcelle', 'urbizen-platform' ),
			'maxlength' => 20,
			'inputmode' => 'numeric',
		),
		array(
			'name'      => 'terrain_superficie',
			'type'      => 'number',
			'step'      => 'terrain',
			'label'     => __( 'Superficie totale du terrain', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 1,
			'unit'      => 'm²',
		),

		// Déclaration explicite, jamais déduite. Trois états doivent rester
		// distinguables : valeur fournie, valeur absente sans explication, et
		// report annoncé par le client. Une absence n'a jamais valeur de report.
		array(
			'name'    => 'informations_cadastrales_differees',
			'type'    => 'checkbox',
			'step'    => 'terrain',
			'label'   => __( 'Le déclarant ne connaît pas ses références cadastrales', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'oui',
					'label' => __( 'À compléter ultérieurement', 'urbizen-platform' ),
				),
				array(
					'value' => 'non',
					'label' => __( 'Références renseignées', 'urbizen-platform' ),
				),
			),
		),

		/* ---------------------------------------------------------- *
		 *  Projet principal
		 * ---------------------------------------------------------- */

		// Choix unique, liste fermée, et seule source du socle tarifaire. Le
		// `price_id` vaut l'identifiant : le catalogue tarifaire est indexé sur
		// les mêmes clés, donc aucune table de correspondance ne s'interpose.
		array(
			'name'     => 'nature',
			'type'     => 'radio',
			'step'     => 'projet',
			'label'    => __( 'Nature des travaux', 'urbizen-platform' ),
			'required' => true,
			'options'  => CatalogueDeclarationPrealable::options_natures( true ),
		),
		array(
			'name'     => 'intervention',
			'type'     => 'radio',
			'step'     => 'projet',
			'label'    => __( 'Type d’intervention', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				array(
					'value' => 'existant',
					'label' => __( 'Sur l’existant', 'urbizen-platform' ),
				),
				array(
					'value' => 'nouvelle',
					'label' => __( 'Construction nouvelle', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'      => 'description',
			'type'      => 'textarea',
			'step'      => 'projet',
			'label'     => __( 'Description du projet', 'urbizen-platform' ),
			'required'  => true,
			'maxlength' => 4000,
			'rows'      => 5,
		),
		array(
			'name'      => 'materiaux',
			'type'      => 'text',
			'step'      => 'projet',
			'label'     => __( 'Matériaux et couleurs', 'urbizen-platform' ),
			'maxlength' => 500,
		),

		/* ---------------------------------------------------------- *
		 *  Surfaces
		 * ---------------------------------------------------------- */

		array(
			'name'      => 'sp_existante',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Surface de plancher existante', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 0.01,
			'unit'      => 'm²',
		),
		// Aucune surface n'est exigée. Une clôture, des panneaux solaires, un
		// ravalement ou une réfection de toiture ne créent aucune surface : les
		// réclamer bloquerait des demandes parfaitement recevables. Et pour une
		// extension, un client qui n'a pas encore mesuré doit pouvoir envoyer sa
		// demande — Urbizen réclamera les cotes après étude. Une absence reste
		// une absence : on ne la remplace jamais par un 0 qui se lirait comme
		// une mesure prise.
		array(
			'name'      => 'sp_creee',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Surface de plancher créée', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 0.01,
			'unit'      => 'm²',
		),
		array(
			'name'      => 'sp_totale',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Surface de plancher totale après travaux', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 0.01,
			'unit'      => 'm²',
		),
		array(
			'name'      => 'emprise_avant',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Emprise au sol avant travaux', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 0.01,
			'unit'      => 'm²',
		),
		array(
			'name'      => 'emprise_creee',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Emprise au sol créée', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 0.01,
			'unit'      => 'm²',
		),
		array(
			'name'      => 'surface_taxable',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Surface taxable créée', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 0.01,
			'unit'      => 'm²',
		),

		/* ---------------------------------------------------------- *
		 *  Contexte
		 * ---------------------------------------------------------- */

		// Le supplément ABF découle de cette réponse, jamais d'un montant reçu.
		array(
			'name'     => 'abf',
			'type'     => 'radio',
			'step'     => 'contexte',
			'label'    => __( 'Terrain en secteur protégé (Bâtiments de France)', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				array(
					'value' => 'non',
					'label' => __( 'Non, ou je ne sais pas', 'urbizen-platform' ),
				),
				array(
					'value' => 'oui',
					'label' => __( 'Oui', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'     => 'demolition',
			'type'     => 'radio',
			'step'     => 'contexte',
			'label'    => __( 'Démolition prévue', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				array(
					'value' => 'non',
					'label' => __( 'Non', 'urbizen-platform' ),
				),
				array(
					'value' => 'oui',
					'label' => __( 'Oui, partielle ou totale', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'      => 'changement_destination',
			'type'      => 'text',
			'step'      => 'contexte',
			'label'     => __( 'Changement de destination envisagé', 'urbizen-platform' ),
			'maxlength' => 300,
		),
		array(
			'name'      => 'remarques',
			'type'      => 'textarea',
			'step'      => 'contexte',
			'label'     => __( 'Informations complémentaires', 'urbizen-platform' ),
			'maxlength' => 4000,
			'rows'      => 4,
		),

		/* ---------------------------------------------------------- *
		 *  Projets supplémentaires
		 * ---------------------------------------------------------- */

		// Valeurs répétées à liste fermée. Le validateur écarte nativement toute
		// valeur hors catalogue ; le catalogue tarifaire écarte en outre le
		// doublon, la nature identique au projet principal et les listes
		// anormalement longues, sans jamais les facturer.
		array(
			'name'     => 'projets_supplementaires',
			'type'     => 'checkbox',
			'step'     => 'projets_supplementaires',
			'label'    => __( 'Autres projets réunis dans ce dossier', 'urbizen-platform' ),
			'multiple' => true,
			'options'  => CatalogueDeclarationPrealable::options_natures(),
			'help'     => __( 'Chaque projet supplémentaire est facturé 100 €.', 'urbizen-platform' ),
		),

		// Option payante : décochée par défaut, et sans valeur affirmée par le
		// client elle vaut « non ».
		array(
			'name'    => 'depot_guichet',
			'type'    => 'checkbox',
			'step'    => 'projets_supplementaires',
			'label'   => __( 'Dépôt par Urbizen sur le guichet numérique', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'oui',
					'label' => __( 'Oui, Urbizen dépose le dossier', 'urbizen-platform' ),
				),
				array(
					'value' => 'non',
					'label' => __( 'Non', 'urbizen-platform' ),
				),
			),
		),

		/* ---------------------------------------------------------- *
		 *  Documents
		 * ---------------------------------------------------------- */

		// Pièces annoncées comme transmises plus tard. Liste fermée sur les
		// mêmes identifiants que les blocs de dépôt : une pièce déposable est
		// donc toujours une pièce reportable, et réciproquement.
		array(
			'name'     => 'pieces_differees',
			'type'     => 'checkbox',
			'step'     => 'documents',
			'label'    => __( 'Pièces annoncées comme transmises ultérieurement', 'urbizen-platform' ),
			'multiple' => true,
			'options'  => CatalogueDeclarationPrealable::options_pieces(),
		),

		/* ---------------------------------------------------------- *
		 *  Engagements
		 * ---------------------------------------------------------- */

		array(
			'name'     => 'attest_exact',
			'type'     => 'consent',
			'step'     => 'envoi',
			'label'    => __( 'Je certifie exactes les informations fournies et j’ai qualité pour déclarer ces travaux.', 'urbizen-platform' ),
			'required' => true,
		),
		array(
			'name'     => 'attest_rgpd',
			'type'     => 'consent',
			'step'     => 'envoi',
			'label'    => __( 'J’accepte que ces données soient utilisées pour constituer mon dossier d’urbanisme.', 'urbizen-platform' ),
			'required' => true,
		),
	),
);

// Les champs engendrés — descriptions de projet et blocs de dépôt — sont
// ajoutés après coup : ils dérivent du catalogue, et les écrire à la main
// rouvrirait la porte à la divergence que ce catalogue existe pour fermer.
$definition['fields'] = array_merge( $definition['fields'], $descriptions_projets, $champs_pieces );

return $definition;
