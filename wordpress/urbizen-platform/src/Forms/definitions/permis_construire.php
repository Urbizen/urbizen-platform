<?php
/**
 * Formulaire « permis de construire » — définition serveur.
 *
 * Cette définition ne rend pas l'interface : le parcours PC est un document
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
 *    {@see CataloguePermisConstruire}. Une valeur hors catalogue est
 *    rejetée, jamais traduite ni tolérée.
 * 3. **Aucun montant n'est déclaré ici.** Les options portent un `price_id`
 *    qui n'est qu'un identifiant : les euros vivent dans
 *    {@see PricingPermisConstruire}, et le total est recalculé côté serveur
 *    à partir des seules réponses retenues. La nature « Autre » n'y porte
 *    aucun socle : le tarif reste sur étude, et aucun montant n'est inventé.
 *
 * Les descriptions de projet supplémentaire portent une clé déterministe,
 * `description_projet_<nature>`. Les doublons de nature étant interdits, une
 * nature identifie sa description sans index positionnel — et une description
 * dont la nature n'a pas été retenue n'a simplement aucun effet.
 *
 * @package Urbizen\Platform
 */

use Urbizen\Platform\Forms\AdresseTerrain;
use Urbizen\Platform\Forms\CataloguePermisConstruire;

defined( 'ABSPATH' ) || exit;

// La fabrique d'adresse est partagée par les parcours : elle vit à côté des
// définitions, et chacune la charge. `require_once` et non `require` — la
// déclaration préalable l'a peut-être déjà amenée dans la même requête.
require_once __DIR__ . '/champs-adresse.php';

// L'autochargeur du greffon fournit ces classes en production ; ce garde-fou
// rend le fichier autoportant pour les bancs, qui chargent une définition sans
// démarrer le greffon.
if ( ! class_exists( CataloguePermisConstruire::class ) ) {
	require_once __DIR__ . '/../CatalogueProjets.php';
	require_once __DIR__ . '/../CataloguePermisConstruire.php';
}

/**
 * Champs de description facultative, un par nature possible.
 *
 * @return array<int, array<string, mixed>>
 */
$descriptions_projets = array_map(
	static function ( string $nature ): array {
		return array(
			'name'      => CataloguePermisConstruire::PREFIXE_DESCRIPTION . $nature,
			'type'      => 'text',
			'step'      => 'projets_supplementaires',
			'label'     => sprintf(
				/* translators: %s : libellé de la nature de projet. */
				__( 'Précisions — %s', 'urbizen-platform' ),
				(string) CataloguePermisConstruire::libelle_nature( $nature )
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
	CataloguePermisConstruire::natures()
);

/**
 * Champs de dépôt, un par type de pièce.
 *
 * @return array<int, array<string, mixed>>
 */
$champs_pieces = array_map(
	static function ( string $piece ): array {
		return array(
			'name'      => CataloguePermisConstruire::PREFIXE_PIECE . $piece,
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => (string) CataloguePermisConstruire::libelle_piece( $piece ),
			'multiple'  => true,
			'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
		);
	},
	CataloguePermisConstruire::pieces()
);

$definition = array(
	'type'         => 'permis_construire',
	'title'        => __( 'Permis de construire', 'urbizen-platform' ),
	'submit_label' => __( 'Envoyer ma demande', 'urbizen-platform' ),

	'steps'        => array(
		array(
			'id'          => 'declarant',
			'label'       => __( 'Déclarant', 'urbizen-platform' ),
			'title'       => __( 'Qui demande le permis ?', 'urbizen-platform' ),
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
			'title'       => __( 'En quoi consiste le projet ?', 'urbizen-platform' ),
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
			'id'          => 'equipements',
			'label'       => __( 'Équipements', 'urbizen-platform' ),
			'title'       => __( 'Raccordements et maîtrise d’œuvre', 'urbizen-platform' ),
			'description' => __( 'Réseaux desservant le terrain et architecte éventuel.', 'urbizen-platform' ),
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
		 *  Nature administrative du dossier
		 * ---------------------------------------------------------- */

		// L'interface le porte dans un champ caché. Un champ caché n'est pas une
		// donnée sûre — il se modifie comme n'importe quel autre — d'où la liste
		// fermée : `pcmi` pour une maison individuelle et ses annexes, `pc` pour
		// les autres. Toute autre valeur est refusée, pas normalisée.
		array(
			'name'    => 'dossier_type',
			'type'    => 'radio',
			'step'    => 'declarant',
			'label'   => __( 'Type de dossier', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'pcmi',
					'label' => __( 'Maison individuelle et annexes', 'urbizen-platform' ),
				),
				array(
					'value' => 'pc',
					'label' => __( 'Autre construction', 'urbizen-platform' ),
				),
			),
		),

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
					'value' => 'futur_acquereur',
					'label' => __( 'Futur acquéreur (avec accord)', 'urbizen-platform' ),
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
		// L'adresse du déclarant vient de la fabrique partagée, comme en
		// déclaration préalable : mêmes deux modes, même vocabulaire de rôles,
		// une seule logique. Les noms restent **historiques** —
		// `adresse_declarant` et non `declarant_adresse` : des demandes de
		// permis les portent déjà, et les renommer aurait cassé leur relecture
		// pour la seule satisfaction d'une symétrie.
		//
		// L'obligation n'est plus portée ici mais par
		// {@see AdresseTerrain::verifier()}, seul à connaître le mode retenu.
		...urbizen_champs_adresse( AdresseTerrain::DECLARANT, 'declarant' ),

		/* ---------------------------------------------------------- *
		 *  Terrain
		 * ---------------------------------------------------------- */

		// La case qui évite la double saisie. Cochée, le serveur reconstruit
		// l'adresse du terrain depuis celle du déclarant validé et ignore toute
		// adresse terrain reçue : c'est lui, et non le document, qui fait foi.
		// Seule la valeur canonique la coche — celle déjà retenue en
		// déclaration préalable, jamais une seconde convention.
		//
		// Elle ne concerne QUE le composant d'adresse. Les références
		// cadastrales, la superficie et l'état du terrain restent saisis, quoi
		// qu'il arrive : ils décrivent la parcelle, pas le lieu où l'on écrit.
		array(
			'name'    => AdresseTerrain::REPORT,
			'type'    => 'checkbox',
			'step'    => 'terrain',
			'label'   => __( 'L’adresse du terrain est la même que celle du déclarant', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => AdresseTerrain::REPORT_VRAI,
					'label' => __( 'Même adresse que le déclarant', 'urbizen-platform' ),
				),
				array(
					'value' => 'non',
					'label' => __( 'Adresse du terrain distincte', 'urbizen-platform' ),
				),
			),
		),

		// L'adresse du terrain sort de la même fabrique que celle du déclarant.
		// Le permis n'avait qu'une adresse en texte libre ; il reçoit les deux
		// modes de saisie, sans que ses noms canoniques changent.
		...urbizen_champs_adresse( AdresseTerrain::TERRAIN, 'terrain' ),

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

		array(
			'name'      => 'terrain_etat',
			'type'      => 'textarea',
			'step'      => 'terrain',
			'label'     => __( 'État actuel du terrain', 'urbizen-platform' ),
			'maxlength' => 2000,
			'rows'      => 3,
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
			'options'  => CataloguePermisConstruire::options_natures( true ),
		),
		// Contexte purement indicatif transmis par le tunnel. Il est conservé
		// pour que l'équipe comprenne le parcours suivi, mais n'intervient jamais
		// dans la validation du régime : le serveur recalcule depuis les champs
		// métier ci-dessous. La mention « non vérifié » est volontaire, car toute
		// donnée de session peut être falsifiée par le navigateur.
		array(
			'name'      => 'qualification_contexte',
			'type'      => 'hidden',
			'step'      => 'projet',
			'label'     => __( 'Contexte indicatif du tunnel (non vérifié)', 'urbizen-platform' ),
			'maxlength' => 4000,
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
		array(
			'name'      => 'insertion',
			'type'      => 'textarea',
			'step'      => 'projet',
			'label'     => __( 'Insertion et parti architectural', 'urbizen-platform' ),
			'maxlength' => 2000,
			'rows'      => 3,
		),
		// Facultatif : au stade de la demande initiale, un particulier ne sait
		// pas toujours combien de logements son projet créera au sens du code de
		// l'urbanisme. Un zéro imposé se lirait comme une réponse.
		array(
			'name'      => 'nb_logements',
			'type'      => 'number',
			'step'      => 'projet',
			'label'     => __( 'Nombre de logements créés', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 1,
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

		array(
			'name'      => 'nb_stationnement',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Places de stationnement extérieures', 'urbizen-platform' ),
			'min'       => 0,
			'increment' => 1,
		),


		/* ---------------------------------------------------------- *
		 *  Précisions selon la nature du projet
		 * ---------------------------------------------------------- */

		// Toutes facultatives, et conditionnées par la nature : la matrice
		// {@see MatriceChamps} décide lesquelles s'affichent et lesquelles sont
		// écartées. Les bornes sont larges à dessein — elles écartent l'absurde,
		// pas l'inhabituel. Une piscine de 50 m de long existe ; une de 500 m
		// non, et une valeur négative jamais.

		// Une maison neuve n'est pas une piscine. La question précède les
		// mesures, et rien ne se demande tant qu'elle n'a pas de réponse :
		// afficher six champs de bassin à tout constructeur reviendrait à
		// supposer un projet qu'il n'a pas décrit. « Je ne sais pas » est une
		// réponse à part entière — elle se conserve, sans ouvrir les mesures.
		array(
			'name'    => 'piscine_prevue',
			'type'    => 'radio',
			'step'    => 'surfaces',
			'label'   => __( 'Une piscine est-elle prévue dans le projet ?', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'oui',
					'label' => __( 'Oui', 'urbizen-platform' ),
				),
				array(
					'value' => 'non',
					'label' => __( 'Non', 'urbizen-platform' ),
				),
				array(
					'value' => 'inconnu',
					'label' => __( 'Je ne sais pas', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'      => 'longueur_bassin_m',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Longueur approximative du bassin', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 100,
			'increment' => 0.01,
			'unit'      => 'm',
			// Renseignée, une mesure vaut plus que zéro : « 0 » n'est pas une
			// mesure, c'est une case remplie par habitude. Vide reste vide.
			'strict_positif' => true,
			// La question « une piscine est-elle prévue ? » ouvre le bloc. Sans
			// un « oui », la mesure décrit un ouvrage qui n'existe pas : elle
			// est écartée plutôt que persistée.
			'visible_if' => array(
				'field' => 'piscine_prevue',
				'in'    => array( 'oui' ),
			),
		),
		array(
			'name'      => 'largeur_bassin_m',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Largeur approximative du bassin', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 100,
			'increment' => 0.01,
			'unit'      => 'm',
			// Renseignée, une mesure vaut plus que zéro : « 0 » n'est pas une
			// mesure, c'est une case remplie par habitude. Vide reste vide.
			'strict_positif' => true,
			// La question « une piscine est-elle prévue ? » ouvre le bloc. Sans
			// un « oui », la mesure décrit un ouvrage qui n'existe pas : elle
			// est écartée plutôt que persistée.
			'visible_if' => array(
				'field' => 'piscine_prevue',
				'in'    => array( 'oui' ),
			),
		),
		array(
			'name'      => 'surface_bassin_m2',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Surface approximative du bassin', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 5000,
			'increment' => 0.01,
			'unit'      => 'm²',
			// Renseignée, une mesure vaut plus que zéro : « 0 » n'est pas une
			// mesure, c'est une case remplie par habitude. Vide reste vide.
			'strict_positif' => true,
			// La question « une piscine est-elle prévue ? » ouvre le bloc. Sans
			// un « oui », la mesure décrit un ouvrage qui n'existe pas : elle
			// est écartée plutôt que persistée.
			'visible_if' => array(
				'field' => 'piscine_prevue',
				'in'    => array( 'oui' ),
			),
		),
		array(
			'name'      => 'profondeur_bassin_m',
			'type'      => 'number',
			'step'      => 'surfaces',
			'label'     => __( 'Profondeur approximative', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 20,
			'increment' => 0.01,
			'unit'      => 'm',
			// Renseignée, une mesure vaut plus que zéro : « 0 » n'est pas une
			// mesure, c'est une case remplie par habitude. Vide reste vide.
			'strict_positif' => true,
			// La question « une piscine est-elle prévue ? » ouvre le bloc. Sans
			// un « oui », la mesure décrit un ouvrage qui n'existe pas : elle
			// est écartée plutôt que persistée.
			'visible_if' => array(
				'field' => 'piscine_prevue',
				'in'    => array( 'oui' ),
			),
		),
		// Trois états, et « je ne sais pas » en est un : au stade de la prise de
		// contact, ne pas savoir est une réponse légitime, et l'absence de
		// réponse ne doit pas se lire comme « non ».
		array(
			'name'    => 'presence_abri_piscine',
			'type'    => 'radio',
			'step'    => 'surfaces',
			'label'   => __( 'Abri de piscine', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'oui',
					'label' => __( 'Oui', 'urbizen-platform' ),
				),
				array(
					'value' => 'non',
					'label' => __( 'Non', 'urbizen-platform' ),
				),
				array(
					'value' => 'inconnu',
					'label' => __( 'Je ne sais pas', 'urbizen-platform' ),
				),
			),
			// La question « une piscine est-elle prévue ? » ouvre le bloc. Sans
			// un « oui », la mesure décrit un ouvrage qui n'existe pas : elle
			// est écartée plutôt que persistée.
			'visible_if' => array(
				'field' => 'piscine_prevue',
				'in'    => array( 'oui' ),
			),
		),
		array(
			'name'       => 'hauteur_abri_m',
			'type'       => 'number',
			'step'       => 'surfaces',
			'label'      => __( 'Hauteur approximative de l’abri', 'urbizen-platform' ),
			'min'        => 0,
			'max'        => 20,
			'increment'  => 0.01,
			'unit'       => 'm',
			// Renseignée, une mesure vaut plus que zéro : « 0 » n'est pas une
			// mesure, c'est une case remplie par habitude. Vide reste vide.
			'strict_positif' => true,
			// La hauteur n'a de sens que si un abri est annoncé. `visible_if`
			// rend l'exigence conditionnelle côté définition ; l'interface la
			// masque, et le serveur l'écarte si l'abri n'est pas « oui ».
			'visible_if' => array(
				'field' => 'presence_abri_piscine',
				'in'    => array( 'oui' ),
			),
		),

		/* ---------------------------------------------------------- *
		 *  Équipements et maîtrise d'œuvre
		 * ---------------------------------------------------------- */

		// Trois listes fermées, toutes facultatives : le raccordement d'un
		// terrain est une information d'instruction, pas une condition de
		// recevabilité de la demande. « Non précisé » n'est pas une valeur — un
		// champ vide dit déjà cela, et l'ajouter à la liste ferait exister deux
		// façons de ne rien répondre.
		array(
			'name'    => 'raccord_eau',
			'type'    => 'select',
			'step'    => 'equipements',
			'label'   => __( 'Alimentation en eau', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'reseau_public',
					'label' => __( 'Réseau public', 'urbizen-platform' ),
				),
				array(
					'value' => 'captage_prive',
					'label' => __( 'Captage privé', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'    => 'raccord_assainissement',
			'type'    => 'select',
			'step'    => 'equipements',
			'label'   => __( 'Assainissement', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'collectif',
					'label' => __( 'Collectif (tout-à-l’égout)', 'urbizen-platform' ),
				),
				array(
					'value' => 'individuel',
					'label' => __( 'Individuel (ANC)', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'    => 'raccord_elec',
			'type'    => 'select',
			'step'    => 'equipements',
			'label'   => __( 'Électricité', 'urbizen-platform' ),
			'options' => array(
				array(
					'value' => 'reseau_public',
					'label' => __( 'Réseau public', 'urbizen-platform' ),
				),
				array(
					'value' => 'autre',
					'label' => __( 'Autre', 'urbizen-platform' ),
				),
			),
		),
		array(
			'name'      => 'architecte_nom',
			'type'      => 'text',
			'step'      => 'equipements',
			'label'     => __( 'Architecte — nom', 'urbizen-platform' ),
			'maxlength' => 200,
		),
		array(
			'name'      => 'architecte_ordre',
			'type'      => 'text',
			'step'      => 'equipements',
			'label'     => __( 'N° au Conseil de l’Ordre', 'urbizen-platform' ),
			'maxlength' => 40,
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
			'options'  => CataloguePermisConstruire::options_natures(),
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
			'options'  => CataloguePermisConstruire::options_pieces(),
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
