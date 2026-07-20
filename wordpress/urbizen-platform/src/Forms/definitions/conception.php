<?php
/**
 * Formulaire « Conception de plans sur mesure ».
 *
 * Socle déclaratif du troisième service Urbizen. Six étapes, décrites ici et
 * nulle part ailleurs : le rendu, la validation et la tarification lisent tous
 * cette définition, jamais une liste dupliquée.
 *
 * Cette PR ne rend le formulaire sur aucune page. La définition existe pour
 * être chargée, contrôlée et testée ; l'interface publique viendra en PR C, la
 * soumission en PR B1, les fichiers en PR B2 et les courriels en PR B3.
 *
 * Deux règles commerciales sont inscrites en creux, et doivent le rester :
 *
 * - la remise de 200 € sur un futur permis n'est **pas** une option : elle ne
 *   figure ni dans les champs, ni dans le calcul (voir Pricing) ;
 * - la série supplémentaire de modifications (`modifs_sup`) existe au catalogue
 *   mais n'est **pas** exposée ici : elle se vend à la livraison, pas avant que
 *   le client ait vu sa première proposition.
 *
 * @package Urbizen\Platform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Raccourci de déclaration d'une option de liste fermée.
 *
 * Fermeture locale plutôt que fonction globale : ce fichier peut être requis
 * plusieurs fois dans un même processus (bancs d'essai), sans conflit de nom.
 *
 * @var callable(string,string):array<string,string> $opt
 */
$opt = static function ( string $value, string $label ): array {
	return array(
		'value' => $value,
		'label' => $label,
	);
};

/**
 * Liste blanche des surfaces par pièce.
 *
 * Identifiants stables, sans accent ni espace : ce sont des clés de tableau et
 * des noms de champs HTML. Le libellé lisible est reconstitué à l'affichage, il
 * ne transite jamais par le navigateur.
 *
 * @var array<int, string> $surface_keys
 */
$surface_keys = ( static function (): array {
	$keys = array( 'sejour', 'cuisine' );

	for ( $i = 1; $i <= 20; $i++ ) {
		$keys[] = 'chambre_' . $i;
	}

	for ( $i = 1; $i <= 10; $i++ ) {
		$keys[] = 'sdb_' . $i;
	}

	return array_merge(
		$keys,
		array(
			'suite_parentale',
			'bureau',
			'buanderie_cellier',
			'dressing',
			'sous_sol',
			'garage',
			'terrasse_couverte',
		)
	);
} )();

return array(
	'type'         => 'conception',
	'title'        => __( 'Conception de plans sur mesure', 'urbizen-platform' ),
	'submit_label' => __( 'Envoyer ma demande', 'urbizen-platform' ),

	'steps'        => array(
		array(
			'id'          => 'programme',
			'label'       => __( 'Programme', 'urbizen-platform' ),
			'title'       => __( 'Votre projet', 'urbizen-platform' ),
			'description' => __(
				'Décrivez la nature de votre projet et son point de départ.',
				'urbizen-platform'
			),
		),
		array(
			'id'          => 'pieces',
			'label'       => __( 'Pièces', 'urbizen-platform' ),
			'title'       => __( 'Les pièces souhaitées', 'urbizen-platform' ),
			'description' => __(
				'Indiquez les pièces à prévoir. Les surfaces sont facultatives : elles affinent l’étude sans la conditionner.',
				'urbizen-platform'
			),
		),
		array(
			'id'          => 'terrain',
			'label'       => __( 'Terrain', 'urbizen-platform' ),
			'title'       => __( 'Le terrain et l’existant', 'urbizen-platform' ),
			'description' => __(
				'Si vous n’avez pas encore de terrain, la conception peut démarrer sur le programme seul.',
				'urbizen-platform'
			),
		),
		array(
			'id'          => 'style_options',
			'label'       => __( 'Style et options', 'urbizen-platform' ),
			'title'       => __( 'Style architectural et documents', 'urbizen-platform' ),
			'description' => __(
				'Une série comprenant jusqu’à trois demandes de modification est incluse.',
				'urbizen-platform'
			),
		),
		array(
			'id'          => 'documents',
			'label'       => __( 'Documents', 'urbizen-platform' ),
			'title'       => __( 'Vos documents', 'urbizen-platform' ),
			'description' => __(
				'Tout document existant nous fait gagner du temps. Aucun n’est obligatoire.',
				'urbizen-platform'
			),
		),
		array(
			'id'          => 'contact',
			'label'       => __( 'Contact', 'urbizen-platform' ),
			'title'       => __( 'Vos coordonnées', 'urbizen-platform' ),
			'description' => __(
				'Un interlocuteur Urbizen reprend votre demande et vous répond.',
				'urbizen-platform'
			),
		),
	),

	'fields'       => array(

		// ================================================ 1 · Programme ====
		array(
			'name'     => 'nature',
			'type'     => 'radio',
			'step'     => 'programme',
			'label'    => __( 'Nature du projet', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				$opt( 'maison', __( 'Maison individuelle', 'urbizen-platform' ) ),
				$opt( 'extension', __( 'Extension', 'urbizen-platform' ) ),
				$opt( 'garage_annexe', __( 'Garage ou annexe', 'urbizen-platform' ) ),
				$opt( 'abri', __( 'Abri', 'urbizen-platform' ) ),
				$opt( 'surelevation', __( 'Surélévation', 'urbizen-platform' ) ),
				$opt( 'reamenagement', __( 'Réaménagement intérieur', 'urbizen-platform' ) ),
				$opt( 'transformation', __( 'Transformation ou changement d’usage', 'urbizen-platform' ) ),
				$opt( 'autre', __( 'Autre projet', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'     => 'situation',
			'type'     => 'radio',
			'step'     => 'programme',
			'label'    => __( 'Où en êtes-vous ?', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				$opt( 'terrain_nu', __( 'J’ai un terrain nu', 'urbizen-platform' ) ),
				$opt( 'batiment_existant', __( 'Le projet porte sur un bâtiment existant', 'urbizen-platform' ) ),
				$opt( 'projet_esquisse', __( 'J’ai déjà une esquisse ou un croquis', 'urbizen-platform' ) ),
				$opt( 'conception_complete', __( 'Je pars de zéro', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'    => 'niveaux',
			'type'    => 'radio',
			'step'    => 'programme',
			'label'   => __( 'Nombre de niveaux', 'urbizen-platform' ),
			'options' => array(
				$opt( 'plain_pied', __( 'De plain-pied', 'urbizen-platform' ) ),
				$opt( 'etages', __( 'Avec étage', 'urbizen-platform' ) ),
				$opt( 'a_definir', __( 'À définir ensemble', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'      => 'surface',
			'type'      => 'number',
			'step'      => 'programme',
			'label'     => __( 'Surface de plancher envisagée', 'urbizen-platform' ),
			'unit'      => 'm²',
			'min'       => 10,
			'max'       => 1000,
			'increment' => 1,
			'inputmode' => 'numeric',
			'help'      => __( 'Une estimation suffit. Au-delà de 1 000 m², le projet fait l’objet d’un devis.', 'urbizen-platform' ),
		),
		array(
			'name'    => 'delai',
			'type'    => 'select',
			'step'    => 'programme',
			'label'   => __( 'Échéance souhaitée', 'urbizen-platform' ),
			'options' => array(
				$opt( 'des_que_possible', __( 'Dès que possible', 'urbizen-platform' ) ),
				$opt( 'un_a_trois_mois', __( 'Dans un à trois mois', 'urbizen-platform' ) ),
				$opt( 'trois_a_six_mois', __( 'Dans trois à six mois', 'urbizen-platform' ) ),
				$opt( 'plus_de_six_mois', __( 'Dans plus de six mois', 'urbizen-platform' ) ),
				$opt( 'non_defini', __( 'Pas encore défini', 'urbizen-platform' ) ),
			),
		),

		// =================================================== 2 · Pièces ====
		array(
			'name'      => 'chambres',
			'type'      => 'number',
			'step'      => 'pieces',
			'label'     => __( 'Chambres', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 20,
			'increment' => 1,
			'inputmode' => 'numeric',
		),
		array(
			'name'      => 'sdb',
			'type'      => 'number',
			'step'      => 'pieces',
			'label'     => __( 'Salles de bain ou d’eau', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 10,
			'increment' => 1,
			'inputmode' => 'numeric',
		),
		array(
			'name'      => 'wc',
			'type'      => 'number',
			'step'      => 'pieces',
			'label'     => __( 'WC séparés', 'urbizen-platform' ),
			'min'       => 0,
			'max'       => 10,
			'increment' => 1,
			'inputmode' => 'numeric',
		),
		array(
			'name'    => 'cuisine',
			'type'    => 'radio',
			'step'    => 'pieces',
			'label'   => __( 'Cuisine', 'urbizen-platform' ),
			'options' => array(
				$opt( 'ouverte', __( 'Ouverte sur le séjour', 'urbizen-platform' ) ),
				$opt( 'fermee', __( 'Fermée', 'urbizen-platform' ) ),
				$opt( 'indifferent', __( 'Indifférent', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'     => 'pieces',
			'type'     => 'checkbox',
			'step'     => 'pieces',
			'label'    => __( 'Autres pièces souhaitées', 'urbizen-platform' ),
			'multiple' => true,
			'options'  => array(
				$opt( 'suite_parentale', __( 'Suite parentale', 'urbizen-platform' ) ),
				$opt( 'bureau', __( 'Bureau', 'urbizen-platform' ) ),
				$opt( 'buanderie_cellier', __( 'Buanderie ou cellier', 'urbizen-platform' ) ),
				$opt( 'dressing', __( 'Dressing', 'urbizen-platform' ) ),
				$opt( 'sous_sol', __( 'Sous-sol', 'urbizen-platform' ) ),
				$opt( 'garage', __( 'Garage', 'urbizen-platform' ) ),
				$opt( 'terrasse_couverte', __( 'Terrasse couverte', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'      => 'surfaces',
			'type'      => 'number',
			'step'      => 'pieces',
			'label'     => __( 'Surface par pièce', 'urbizen-platform' ),
			'unit'      => 'm²',
			'multiple'  => true,
			// Famille dynamique : les lignes réellement affichées dépendent des
			// réponses précédentes, mais l'ensemble des clés possibles est
			// arrêté ici. Le serveur ne reconstruit jamais une clé reçue.
			'family'    => 'surfaces',
			'keys'      => $surface_keys,
			'min'       => 1,
			'max'       => 200,
			'total_max' => 1000,
			'increment' => 1,
			'inputmode' => 'numeric',
			'help'      => __( 'Facultatif. Au-delà de 1 000 m² cumulés, le projet fait l’objet d’un devis.', 'urbizen-platform' ),
		),
		array(
			'name'      => 'pieces_detail',
			'type'      => 'textarea',
			'step'      => 'pieces',
			'label'     => __( 'Précisions sur la distribution', 'urbizen-platform' ),
			'maxlength' => 2000,
			'rows'      => 4,
		),

		// ================================================== 3 · Terrain ====
		array(
			'name'     => 'a_terrain',
			'type'     => 'radio',
			'step'     => 'terrain',
			'label'    => __( 'Disposez-vous déjà d’un terrain ?', 'urbizen-platform' ),
			'required' => true,
			'options'  => array(
				$opt( 'oui', __( 'Oui', 'urbizen-platform' ) ),
				$opt( 'non', __( 'Pas encore', 'urbizen-platform' ) ),
			),
		),

		// --- Localisation reprise du composant cadastre (contrat 1.0) ---
		array(
			'name'       => 'terrain_adresse',
			'type'       => 'text',
			'step'       => 'terrain',
			'label'      => __( 'Adresse du terrain', 'urbizen-platform' ),
			'from'       => 'address.label',
			'maxlength'  => 300,
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'terrain_cp',
			'type'       => 'text',
			'step'       => 'terrain',
			'label'      => __( 'Code postal', 'urbizen-platform' ),
			'from'       => 'address.postcode',
			'maxlength'  => 10,
			'inputmode'  => 'numeric',
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'terrain_ville',
			'type'       => 'text',
			'step'       => 'terrain',
			'label'      => __( 'Commune', 'urbizen-platform' ),
			'from'       => 'address.city',
			'maxlength'  => 120,
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'cad_section',
			'type'       => 'text',
			'step'       => 'terrain',
			'label'      => __( 'Section cadastrale', 'urbizen-platform' ),
			'from'       => 'parcel.section',
			'maxlength'  => 10,
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'cad_numero',
			'type'       => 'text',
			'step'       => 'terrain',
			'label'      => __( 'Numéro de parcelle', 'urbizen-platform' ),
			'from'       => 'parcel.number',
			'maxlength'  => 10,
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'terrain_surface',
			'type'       => 'number',
			'step'       => 'terrain',
			'label'      => __( 'Surface du terrain', 'urbizen-platform' ),
			'unit'       => 'm²',
			'from'       => 'parcel.surfaceM2',
			'min'        => 0,
			'max'        => 1000000,
			'increment'  => 1,
			'inputmode'  => 'numeric',
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'pente',
			'type'       => 'radio',
			'step'       => 'terrain',
			'label'      => __( 'Relief du terrain', 'urbizen-platform' ),
			'options'    => array(
				$opt( 'plat', __( 'Plat', 'urbizen-platform' ) ),
				$opt( 'leger', __( 'Légèrement en pente', 'urbizen-platform' ) ),
				$opt( 'marque', __( 'En pente marquée', 'urbizen-platform' ) ),
				$opt( 'tres_pentu', __( 'Très pentu', 'urbizen-platform' ) ),
				$opt( 'ne_sais_pas', __( 'Je ne sais pas', 'urbizen-platform' ) ),
			),
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'orientation',
			'type'       => 'radio',
			'step'       => 'terrain',
			'label'      => __( 'Orientation principale', 'urbizen-platform' ),
			'options'    => array(
				$opt( 'nord', __( 'Nord', 'urbizen-platform' ) ),
				$opt( 'sud', __( 'Sud', 'urbizen-platform' ) ),
				$opt( 'est', __( 'Est', 'urbizen-platform' ) ),
				$opt( 'ouest', __( 'Ouest', 'urbizen-platform' ) ),
				$opt( 'ne_sais_pas', __( 'Je ne sais pas', 'urbizen-platform' ) ),
			),
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'viabilisation',
			'type'       => 'checkbox',
			'step'       => 'terrain',
			'label'      => __( 'Raccordements existants', 'urbizen-platform' ),
			'multiple'   => true,
			'options'    => array(
				$opt( 'eau', __( 'Eau potable', 'urbizen-platform' ) ),
				$opt( 'electricite', __( 'Électricité', 'urbizen-platform' ) ),
				$opt( 'assainissement_collectif', __( 'Assainissement collectif', 'urbizen-platform' ) ),
				$opt( 'assainissement_individuel', __( 'Assainissement individuel', 'urbizen-platform' ) ),
				$opt( 'gaz', __( 'Gaz', 'urbizen-platform' ) ),
				$opt( 'telecom', __( 'Téléphone ou fibre', 'urbizen-platform' ) ),
				$opt( 'aucune', __( 'Aucun raccordement', 'urbizen-platform' ) ),
				$opt( 'ne_sais_pas', __( 'Je ne sais pas', 'urbizen-platform' ) ),
			),
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),
		array(
			'name'       => 'contraintes',
			'type'       => 'textarea',
			'step'       => 'terrain',
			'label'      => __( 'Contraintes connues', 'urbizen-platform' ),
			'maxlength'  => 2000,
			'rows'       => 3,
			'help'       => __( 'Servitude, mitoyenneté, secteur protégé, zone inondable, arbres à conserver…', 'urbizen-platform' ),
			'visible_if' => array(
				'field' => 'a_terrain',
				'value' => 'oui',
			),
		),

		// --- Bâtiment existant ---
		array(
			'name'       => 'bati_type',
			'type'       => 'radio',
			'step'       => 'terrain',
			'label'      => __( 'Nature du bâtiment existant', 'urbizen-platform' ),
			'options'    => array(
				$opt( 'maison', __( 'Maison', 'urbizen-platform' ) ),
				$opt( 'appartement', __( 'Appartement', 'urbizen-platform' ) ),
				$opt( 'batiment_agricole', __( 'Bâtiment agricole', 'urbizen-platform' ) ),
				$opt( 'local_commercial', __( 'Local commercial', 'urbizen-platform' ) ),
				$opt( 'autre', __( 'Autre', 'urbizen-platform' ) ),
			),
			'visible_if' => array(
				'field' => 'situation',
				'value' => 'batiment_existant',
			),
		),
		array(
			'name'       => 'bati_surface',
			'type'       => 'number',
			'step'       => 'terrain',
			'label'      => __( 'Surface existante', 'urbizen-platform' ),
			'unit'       => 'm²',
			'min'        => 1,
			'max'        => 5000,
			'increment'  => 1,
			'inputmode'  => 'numeric',
			'visible_if' => array(
				'field' => 'situation',
				'value' => 'batiment_existant',
			),
		),
		array(
			'name'       => 'bati_niveaux',
			'type'       => 'number',
			'step'       => 'terrain',
			'label'      => __( 'Nombre de niveaux existants', 'urbizen-platform' ),
			'min'        => 1,
			'max'        => 10,
			'increment'  => 1,
			'inputmode'  => 'numeric',
			'visible_if' => array(
				'field' => 'situation',
				'value' => 'batiment_existant',
			),
		),
		array(
			'name'       => 'plans_existants',
			'type'       => 'radio',
			'step'       => 'terrain',
			'label'      => __( 'Disposez-vous des plans existants ?', 'urbizen-platform' ),
			'options'    => array(
				$opt( 'oui', __( 'Oui', 'urbizen-platform' ) ),
				$opt( 'partiels', __( 'Partiellement', 'urbizen-platform' ) ),
				$opt( 'non', __( 'Non', 'urbizen-platform' ) ),
			),
			'visible_if' => array(
				'field' => 'situation',
				'value' => 'batiment_existant',
			),
		),
		array(
			'name'       => 'releves',
			'type'       => 'radio',
			'step'       => 'terrain',
			'label'      => __( 'Des relevés de mesures ont-ils été réalisés ?', 'urbizen-platform' ),
			'options'    => array(
				$opt( 'oui', __( 'Oui', 'urbizen-platform' ) ),
				$opt( 'non', __( 'Non', 'urbizen-platform' ) ),
				$opt( 'ne_sais_pas', __( 'Je ne sais pas', 'urbizen-platform' ) ),
			),
			'visible_if' => array(
				'field' => 'situation',
				'value' => 'batiment_existant',
			),
		),
		array(
			'name'       => 'nature_travaux',
			'type'       => 'textarea',
			'step'       => 'terrain',
			'label'      => __( 'Travaux envisagés sur l’existant', 'urbizen-platform' ),
			'maxlength'  => 2000,
			'rows'       => 3,
			'visible_if' => array(
				'field' => 'situation',
				'value' => 'batiment_existant',
			),
		),

		// ========================================= 4 · Style et options ====
		array(
			'name'    => 'style',
			'type'    => 'radio',
			'step'    => 'style_options',
			'label'   => __( 'Style architectural', 'urbizen-platform' ),
			'options' => array(
				$opt( 'contemporain', __( 'Contemporain', 'urbizen-platform' ) ),
				$opt( 'traditionnel', __( 'Traditionnel', 'urbizen-platform' ) ),
				$opt( 'regional', __( 'Régional', 'urbizen-platform' ) ),
				$opt( 'mixte', __( 'Mixte', 'urbizen-platform' ) ),
				$opt( 'sans_preference', __( 'Sans préférence', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'    => 'toiture',
			'type'    => 'radio',
			'step'    => 'style_options',
			'label'   => __( 'Forme de toiture', 'urbizen-platform' ),
			'options' => array(
				$opt( 'deux_pans', __( 'Deux pans', 'urbizen-platform' ) ),
				$opt( 'quatre_pans', __( 'Quatre pans', 'urbizen-platform' ) ),
				$opt( 'monopente', __( 'Monopente', 'urbizen-platform' ) ),
				$opt( 'plate', __( 'Toit plat', 'urbizen-platform' ) ),
				$opt( 'mixte', __( 'Mixte', 'urbizen-platform' ) ),
				$opt( 'sans_preference', __( 'Sans préférence', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'     => 'materiaux',
			'type'     => 'checkbox',
			'step'     => 'style_options',
			'label'    => __( 'Matériaux de façade envisagés', 'urbizen-platform' ),
			'multiple' => true,
			'options'  => array(
				$opt( 'enduit', __( 'Enduit', 'urbizen-platform' ) ),
				$opt( 'bois', __( 'Bois', 'urbizen-platform' ) ),
				$opt( 'pierre', __( 'Pierre', 'urbizen-platform' ) ),
				$opt( 'brique', __( 'Brique', 'urbizen-platform' ) ),
				$opt( 'bardage_metal', __( 'Bardage métallique', 'urbizen-platform' ) ),
				$opt( 'sans_preference', __( 'Sans préférence', 'urbizen-platform' ) ),
			),
		),
		array(
			'name'      => 'inspirations',
			'type'      => 'textarea',
			'step'      => 'style_options',
			'label'     => __( 'Références et inspirations', 'urbizen-platform' ),
			'maxlength' => 2000,
			'rows'      => 3,
		),

		// --- Documents complémentaires facturés ---
		array(
			'name'     => 'options_tarifees',
			'type'     => 'checkbox',
			'step'     => 'style_options',
			'label'    => __( 'Documents complémentaires', 'urbizen-platform' ),
			'multiple' => true,
			'help'     => __(
				'La conception comprend les plans de niveaux. Ces documents s’y ajoutent.',
				'urbizen-platform'
			),
			'options'  => array(
				array(
					'value'    => 'facades',
					'label'    => __( 'Plans des façades', 'urbizen-platform' ),
					'price_id' => 'facades',
				),
				array(
					'value'    => 'toiture',
					'label'    => __( 'Plan de toiture', 'urbizen-platform' ),
					'price_id' => 'toiture',
				),
				array(
					'value'    => 'coupe',
					'label'    => __( 'Plan en coupe', 'urbizen-platform' ),
					'price_id' => 'coupe',
				),
				array(
					'value'    => 'pack_ftc',
					'label'    => __( 'Pack façades, toiture et coupe', 'urbizen-platform' ),
					'price_id' => 'pack_ftc',
					'help'     => __(
						'Comprend les trois documents ci-dessus. Les cocher séparément n’ajoute rien au pack.',
						'urbizen-platform'
					),
				),
				array(
					'value'    => 'masse',
					'label'    => __( 'Plan de masse et implantation', 'urbizen-platform' ),
					'price_id' => 'masse',
					'help'     => __( 'Nécessite un terrain identifié.', 'urbizen-platform' ),
				),
				array(
					'value'    => 'vue3d',
					'label'    => __( 'Proposition 3D extérieure simple', 'urbizen-platform' ),
					'price_id' => 'vue3d',
					'help'     => __(
						'Réalisée après validation des plans en deux dimensions.',
						'urbizen-platform'
					),
				),
			),
		),
		array(
			'name'       => 'options_sur_devis',
			'type'       => 'checkbox',
			'step'       => 'style_options',
			'label'      => __( 'Prestations sur devis', 'urbizen-platform' ),
			'multiple'   => true,
			'quote_only' => true,
			'help'       => __(
				'Ces prestations ne sont pas chiffrées automatiquement : nous vous adressons un devis.',
				'urbizen-platform'
			),
			'options'    => array(
				array(
					'value'      => 'insertion3d',
					'label'      => __( 'Insertion 3D dans le paysage', 'urbizen-platform' ),
					'price_id'   => 'insertion3d',
					'quote_only' => true,
				),
				array(
					'value'      => 'complexe',
					'label'      => __( 'Projet complexe ou de grande surface', 'urbizen-platform' ),
					'price_id'   => 'complexe',
					'quote_only' => true,
				),
				array(
					'value'      => 'particulier',
					'label'      => __( 'Demande particulière', 'urbizen-platform' ),
					'price_id'   => 'particulier',
					'quote_only' => true,
				),
			),
		),

		// ================================================ 5 · Documents ====
		// La politique déclarée ici sera appliquée par UploadPolicy en PR B2 :
		// aucun fichier n'est reçu, stocké ni contrôlé à ce stade.
		array(
			'name'      => 'croquis_plans',
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => __( 'Croquis ou plans existants', 'urbizen-platform' ),
			'multiple'  => true,
			'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
		),
		array(
			'name'      => 'plan_terrain',
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => __( 'Plan de terrain ou extrait cadastral', 'urbizen-platform' ),
			'multiple'  => true,
			'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
		),
		array(
			'name'      => 'photos',
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => __( 'Photographies du terrain ou de l’existant', 'urbizen-platform' ),
			'multiple'  => true,
			'accept'    => array( 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
		),
		array(
			'name'      => 'inspirations_docs',
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => __( 'Images d’inspiration', 'urbizen-platform' ),
			'multiple'  => true,
			'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
		),
		array(
			'name'      => 'urbanisme',
			'type'      => 'file',
			'step'      => 'documents',
			'label'     => __( 'Documents d’urbanisme', 'urbizen-platform' ),
			'multiple'  => true,
			'accept'    => array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ),
			'max_files' => 10,
			'max_size'  => 10485760,
			'help'      => __( 'Certificat d’urbanisme, extrait du PLU, règlement de lotissement…', 'urbizen-platform' ),
		),

		// ================================================== 6 · Contact ====
		array(
			'name'         => 'nom',
			'type'         => 'text',
			'step'         => 'contact',
			'label'        => __( 'Nom et prénom', 'urbizen-platform' ),
			'required'     => true,
			'maxlength'    => 120,
			'autocomplete' => 'name',
		),
		array(
			'name'         => 'email',
			'type'         => 'text',
			'step'         => 'contact',
			'label'        => __( 'Adresse électronique', 'urbizen-platform' ),
			'required'     => true,
			'maxlength'    => 200,
			'inputmode'    => 'email',
			'autocomplete' => 'email',
		),
		array(
			'name'         => 'tel',
			'type'         => 'text',
			'step'         => 'contact',
			'label'        => __( 'Téléphone', 'urbizen-platform' ),
			'maxlength'    => 30,
			'inputmode'    => 'tel',
			'autocomplete' => 'tel',
		),
		array(
			'name'      => 'message',
			'type'      => 'textarea',
			'step'      => 'contact',
			'label'     => __( 'Votre message', 'urbizen-platform' ),
			'maxlength' => 3000,
			'rows'      => 5,
		),
		array(
			'name'     => 'rgpd',
			'type'     => 'consent',
			'step'     => 'contact',
			'label'    => __(
				'J’accepte que ces informations soient utilisées pour traiter ma demande.',
				'urbizen-platform'
			),
			'required' => true,
		),
	),
);
