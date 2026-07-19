<?php
/**
 * Formulaire « localisation » — première définition Urbizen.
 *
 * Reprend la localisation confirmée par le composant cadastre. Les noms de
 * champs suivent la convention déjà consommée par le service Python et par les
 * prototypes DP / PCMI (`terrain_*`, `cad_*`), pour que le portage des parcours
 * complets n'impose aucune traduction supplémentaire.
 *
 * `from` désigne le chemin dans le contrat canonique 1.0. Ces chemins sont
 * exploités **côté navigateur uniquement** à cette étape : rien n'est transmis
 * à un serveur en version 0.4.0.
 *
 * @package Urbizen\Platform
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'         => 'localisation',
	'title'        => __( 'Localisation du projet', 'urbizen-platform' ),
	'submit_label' => __( 'Valider ma localisation', 'urbizen-platform' ),
	'fields'       => array(

		// --- Champs visibles, vérifiables et modifiables ---
		array(
			'name'      => 'terrain_adresse',
			'type'      => 'text',
			'label'     => __( 'Adresse du terrain', 'urbizen-platform' ),
			'from'      => 'address.label',
			'maxlength' => 300,
			'required'  => true,
			'autocomplete' => 'off',
		),
		array(
			'name'      => 'terrain_cp',
			'type'      => 'text',
			'label'     => __( 'Code postal', 'urbizen-platform' ),
			'from'      => 'address.postcode',
			'maxlength' => 10,
			'required'  => true,
			'inputmode' => 'numeric',
		),
		array(
			'name'      => 'terrain_ville',
			'type'      => 'text',
			'label'     => __( 'Commune', 'urbizen-platform' ),
			'from'      => 'address.city',
			'maxlength' => 120,
			'required'  => true,
		),
		array(
			'name'      => 'cad_section',
			'type'      => 'text',
			'label'     => __( 'Section cadastrale', 'urbizen-platform' ),
			'from'      => 'parcel.section',
			'maxlength' => 10,
			'required'  => true,
		),
		array(
			'name'      => 'cad_numero',
			'type'      => 'text',
			'label'     => __( 'Numéro de parcelle', 'urbizen-platform' ),
			'from'      => 'parcel.number',
			'maxlength' => 10,
			'required'  => true,
		),
		array(
			'name'      => 'terrain_superficie',
			'type'      => 'number',
			'label'     => __( 'Surface cadastrale', 'urbizen-platform' ),
			'from'      => 'parcel.surfaceM2',
			'unit'      => 'm²',
			'min'       => 0,
			'step'      => 1,
			'required'  => false,
			'note'      => __(
				'Surface cadastrale indicative. Vérifiez-la si le projet concerne plusieurs parcelles ou seulement une partie du terrain.',
				'urbizen-platform'
			),
		),

		// --- Champs techniques, masqués mais inspectables ---
		array(
			'name'      => 'adresse_code_commune',
			'type'      => 'hidden',
			'label'     => __( 'Code commune de l’adresse', 'urbizen-platform' ),
			'from'      => 'address.cityCode',
			'maxlength' => 10,
		),
		array(
			'name'      => 'parcelle_code_commune',
			'type'      => 'hidden',
			'label'     => __( 'Code commune de la parcelle', 'urbizen-platform' ),
			'from'      => 'parcel.communeCode',
			'maxlength' => 10,
		),
		array(
			'name'      => 'terrain_latitude',
			'type'      => 'hidden',
			'label'     => __( 'Latitude', 'urbizen-platform' ),
			'from'      => 'location.latitude',
		),
		array(
			'name'      => 'terrain_longitude',
			'type'      => 'hidden',
			'label'     => __( 'Longitude', 'urbizen-platform' ),
			'from'      => 'location.longitude',
		),
		array(
			'name'      => 'cad_prefixe',
			'type'      => 'hidden',
			'label'     => __( 'Préfixe cadastral', 'urbizen-platform' ),
			'from'      => 'parcel.prefix',
			'maxlength' => 10,
		),
		array(
			'name'      => 'cad_identifiant',
			'type'      => 'hidden',
			'label'     => __( 'Identifiant cadastral', 'urbizen-platform' ),
			'from'      => 'parcel.id',
			'maxlength' => 20,
		),
		array(
			'name'      => 'schema_version',
			'type'      => 'hidden',
			'label'     => __( 'Version du schéma', 'urbizen-platform' ),
			'from'      => 'schemaVersion',
			'maxlength' => 10,
		),
		array(
			'name'      => 'confirme_le',
			'type'      => 'hidden',
			'label'     => __( 'Date de confirmation', 'urbizen-platform' ),
			'from'      => 'confirmedAt',
			'maxlength' => 40,
		),
	),
);
