<?php
/**
 * Banc d'essai de la définition « conception » et du socle FormDefinition.
 *
 * Contrôle la structure déclarative : étapes, champs, types, listes fermées,
 * conditions, bornes. Contrôle aussi que l'extension du moteur n'a rien coûté
 * à la définition « localisation », qui doit se charger à l'identique.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\Pricing;

$raw = brut( 'conception' );
$def = definition( $raw );

// ------------------------------------------------------- chargement --------
check( 'la définition se charge sans anomalie', $def->is_valid() );

if ( ! $def->is_valid() ) {
	foreach ( $def->errors() as $anomalie ) {
		echo '    anomalie : ' . $anomalie . "\n";
	}
}

check( 'identifiant « conception »', 'conception' === $def->type() );
check( 'le titre est renseigné', '' !== $def->title() );
check( 'l’intitulé du bouton est renseigné', '' !== $def->submit_label() );

// ------------------------------------------------------------ étapes -------
$attendues = array( 'programme', 'pieces', 'terrain', 'style_options', 'documents', 'contact' );

check( 'exactement six étapes', 6 === count( $def->steps() ) );
check( 'les six étapes sont dans l’ordre attendu', $attendues === $def->step_ids() );
check(
	'chaque étape porte un libellé et un titre',
	6 === count( array_filter( $def->steps(), static fn( $e ) => '' !== $e['label'] && '' !== $e['title'] ) )
);

// ------------------------------------------------------------- champs ------
$noms = array_column( $def->fields(), 'name' );

check( 'aucun identifiant de champ en double', count( $noms ) === count( array_unique( $noms ) ) );
check(
	'tous les identifiants sont stables et sans accent',
	$noms === array_values( array_filter( $noms, static fn( $n ) => (bool) preg_match( FormDefinition::ID_PATTERN, $n ) ) )
);
check(
	'tous les types appartiennent à la liste autorisée',
	array() === array_diff( array_unique( array_column( $def->fields(), 'type' ) ), FormDefinition::TYPES )
);
check(
	'chaque champ est rattaché à une étape déclarée',
	count( $noms ) === count( array_filter( $def->fields(), static fn( $f ) => in_array( $f['step'] ?? '', $attendues, true ) ) )
);
check(
	'aucune étape n’est vide',
	6 === count( array_filter( $attendues, static fn( $e ) => array() !== $def->fields_for_step( $e ) ) )
);

// Champs attendus, étape par étape.
$plan = array(
	'programme'     => array( 'nature', 'situation', 'niveaux', 'surface', 'delai' ),
	'pieces'        => array( 'chambres', 'sdb', 'wc', 'cuisine', 'pieces', 'surfaces', 'pieces_detail' ),
	'terrain'       => array(
		'a_terrain',
		'terrain_adresse',
		'terrain_cp',
		'terrain_ville',
		'cad_section',
		'cad_numero',
		'terrain_surface',
		'pente',
		'orientation',
		'viabilisation',
		'contraintes',
		'bati_type',
		'bati_surface',
		'bati_niveaux',
		'plans_existants',
		'releves',
		'nature_travaux',
	),
	'style_options' => array( 'style', 'toiture', 'materiaux', 'inspirations', 'options_tarifees', 'options_sur_devis' ),
	'documents'     => array( 'croquis_plans', 'plan_terrain', 'photos', 'inspirations_docs', 'urbanisme' ),
	'contact'       => array( 'nom', 'email', 'tel', 'message', 'rgpd' ),
);

foreach ( $plan as $etape => $champs ) {
	check(
		"[$etape] les champs attendus sont présents, dans l’ordre",
		$champs === array_column( $def->fields_for_step( $etape ), 'name' )
	);
}

// ----------------------------------------------------------- requis --------
$requis = array_column(
	array_filter( $def->fields(), static fn( $f ) => ! empty( $f['required'] ) ),
	'name'
);

check(
	'les champs obligatoires sont exactement ceux prévus',
	array( 'nature', 'situation', 'a_terrain', 'nom', 'email', 'rgpd' ) === $requis
);
check( 'le consentement est un champ de type consent et obligatoire', 'consent' === $def->field( 'rgpd' )['type'] );
check( 'aucun champ de terrain n’est obligatoire', ! array_intersect( $requis, $plan['terrain'] ) || array( 'a_terrain' ) === array_values( array_intersect( $requis, $plan['terrain'] ) ) );
check( 'aucun dépôt de fichier n’est obligatoire', array() === array_intersect( $requis, $plan['documents'] ) );

// ----------------------------------------------------- listes fermées ------
$a_options = array_filter( $def->fields(), static fn( $f ) => in_array( $f['type'], FormDefinition::TYPES_A_OPTIONS, true ) );

check(
	'toute liste fermée déclare au moins deux valeurs',
	count( $a_options ) === count( array_filter( $a_options, static fn( $f ) => count( $f['options'] ) >= 2 ) )
);
check(
	'toute valeur de liste fermée est un identifiant stable',
	count( $a_options ) === count(
		array_filter(
			$a_options,
			static fn( $f ) => array() === array_filter(
				array_column( $f['options'], 'value' ),
				static fn( $v ) => ! preg_match( FormDefinition::ID_PATTERN, $v )
			)
		)
	)
);

$verifie_valeurs = static function ( string $champ, array $valeurs ) use ( $def ) {
	check(
		"[$champ] valeurs autorisées conformes",
		$valeurs === array_column( $def->field( $champ )['options'], 'value' )
	);
};

$verifie_valeurs( 'nature', array( 'maison', 'extension', 'garage_annexe', 'abri', 'surelevation', 'reamenagement', 'transformation', 'autre' ) );
$verifie_valeurs( 'situation', array( 'terrain_nu', 'batiment_existant', 'projet_esquisse', 'conception_complete' ) );
$verifie_valeurs( 'niveaux', array( 'plain_pied', 'etages', 'a_definir' ) );
$verifie_valeurs( 'a_terrain', array( 'oui', 'non' ) );
$verifie_valeurs( 'pieces', array( 'suite_parentale', 'bureau', 'buanderie_cellier', 'dressing', 'sous_sol', 'garage', 'terrasse_couverte' ) );
$verifie_valeurs( 'options_tarifees', array( 'facades', 'toiture', 'coupe', 'pack_ftc', 'masse', 'vue3d' ) );
$verifie_valeurs( 'options_sur_devis', array( 'insertion3d', 'complexe', 'particulier' ) );

// --------------------------------------------------------- conditions ------
$conditions = array();

foreach ( $def->fields() as $field ) {
	if ( isset( $field['visible_if'] ) ) {
		$conditions[ $field['name'] ] = $field['visible_if']['field'] . '=' . implode( '|', $field['visible_if']['in'] );
	}
}

$terrain_conditionnes = array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'cad_section', 'cad_numero', 'terrain_surface', 'pente', 'orientation', 'viabilisation', 'contraintes' );
$bati_conditionnes    = array( 'bati_type', 'bati_surface', 'bati_niveaux', 'plans_existants', 'releves', 'nature_travaux' );

check(
	'les dix champs de terrain dépendent de a_terrain=oui',
	count( $terrain_conditionnes ) === count( array_filter( $terrain_conditionnes, static fn( $n ) => 'a_terrain=oui' === ( $conditions[ $n ] ?? '' ) ) )
);
check(
	'les six champs d’existant dépendent de situation=batiment_existant',
	count( $bati_conditionnes ) === count( array_filter( $bati_conditionnes, static fn( $n ) => 'situation=batiment_existant' === ( $conditions[ $n ] ?? '' ) ) )
);
check(
	'aucune condition ne vise un champ inexistant',
	array() === array_diff( array_map( static fn( $c ) => explode( '=', $c )[0], $conditions ), $noms )
);
check( 'a_terrain lui-même n’est pas conditionné', ! isset( $conditions['a_terrain'] ) );

// ------------------------------------------------------------ bornes -------
$bornes = array(
	'surface'         => array( 10, 1000 ),
	'chambres'        => array( 0, 20 ),
	'sdb'             => array( 0, 10 ),
	'wc'              => array( 0, 10 ),
	'bati_niveaux'    => array( 1, 10 ),
);

foreach ( $bornes as $champ => $attendu ) {
	$f = $def->field( $champ );
	check( "[$champ] bornes {$attendu[0]}–{$attendu[1]}", array( (int) $f['min'], (int) $f['max'] ) === $attendu );
}

$longueurs = array(
	'pieces_detail'  => 2000,
	'contraintes'    => 2000,
	'nature_travaux' => 2000,
	'inspirations'   => 2000,
	'message'        => 3000,
	'nom'            => 120,
	'email'          => 200,
	'tel'            => 30,
);

foreach ( $longueurs as $champ => $attendu ) {
	check( "[$champ] longueur maximale de $attendu", $attendu === (int) $def->field( $champ )['maxlength'] );
}

// -------------------------------------------------- surfaces dynamiques ----
$surfaces = $def->field( 'surfaces' );
$cles     = $surfaces['keys'];

check( 'la famille des surfaces est déclarée', 'surfaces' === ( $surfaces['family'] ?? '' ) );
check( 'exactement 39 clés de surface', 39 === count( $cles ) );
check( 'aucune clé de surface en double', count( $cles ) === count( array_unique( $cles ) ) );
check(
	'toutes les clés de surface sont stables et sans accent',
	$cles === array_values( array_filter( $cles, static fn( $c ) => (bool) preg_match( FormDefinition::ID_PATTERN, $c ) ) )
);
check( 'chambre_1 à chambre_20 sont déclarées', 20 === count( array_filter( $cles, static fn( $c ) => (bool) preg_match( '/^chambre_\d+$/', $c ) ) ) );
check( 'sdb_1 à sdb_10 sont déclarées', 10 === count( array_filter( $cles, static fn( $c ) => (bool) preg_match( '/^sdb_\d+$/', $c ) ) ) );
check( 'chambre_21 n’est pas déclarée', ! in_array( 'chambre_21', $cles, true ) );
check( 'sdb_11 n’est pas déclarée', ! in_array( 'sdb_11', $cles, true ) );
check(
	'toutes les autres pièces cochables ont leur surface',
	array() === array_diff( array_column( $def->field( 'pieces' )['options'], 'value' ), $cles )
);
check( 'bornes des surfaces : 1 à 200 m²', array( 1, 200 ) === array( (int) $surfaces['min'], (int) $surfaces['max'] ) );
check( 'seuil de devis à 1 000 m² cumulés', 1000 === (int) $surfaces['total_max'] );

// ------------------------------------------------------------ fichiers -----
$formats = array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' );

foreach ( $plan['documents'] as $bloc ) {
	$f = $def->field( $bloc );
	check( "[$bloc] déclaré comme dépôt de fichiers multiple", 'file' === $f['type'] && ! empty( $f['multiple'] ) );
	check( "[$bloc] au plus 10 fichiers de 10 Mo", 10 === (int) $f['max_files'] && 10485760 === (int) $f['max_size'] );
	check( "[$bloc] formats limités à une liste blanche", array() === array_diff( $f['accept'], $formats ) );
}

check(
	'le total des dépôts respecte la limite serveur de 20 fichiers par requête',
	array_sum( array_map( static fn( $b ) => (int) $def->field( $b )['max_files'], $plan['documents'] ) ) >= 20
);
check( 'les photographies n’acceptent pas de PDF', ! in_array( 'pdf', $def->field( 'photos' )['accept'], true ) );

// ------------------------------------------------------- règles métier -----
$prix = array_column( $def->field( 'options_tarifees' )['options'], 'price_id', 'value' );

check( 'les six options exposées portent toutes un identifiant tarifaire', 6 === count( array_filter( $prix ) ) );
check( 'chaque identifiant tarifaire exposé existe au catalogue', array() === array_diff( $prix, Pricing::known_ids() ) );
check( 'modifs_sup n’est pas exposée dans la définition initiale', ! in_array( 'modifs_sup', $prix, true ) );
check( 'modifs_sup reste au catalogue serveur', in_array( 'modifs_sup', Pricing::known_ids(), true ) );
check(
	'les trois prestations sur devis sont marquées comme telles',
	3 === count( array_filter( $def->field( 'options_sur_devis' )['options'], static fn( $o ) => ! empty( $o['quote_only'] ) ) )
);
check( 'aucune option sur devis ne figure au catalogue chiffré', array() === array_intersect( Pricing::SUR_DEVIS, Pricing::known_ids() ) );

$fichier    = URBIZEN_PLATFORM_DIR . 'src/Forms/definitions/conception.php';
$texte_brut = file_get_contents( $fichier );

// Le code seul, commentaires retirés : la documentation a le droit d'expliquer
// la règle commerciale, la définition n'a pas le droit de porter un montant.
$code = implode(
	'',
	array_map(
		static fn( $t ) => is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $t ) ? $t[1] : $t ),
		token_get_all( $texte_brut )
	)
);

check(
	'la formulation des modifications incluses est exacte',
	str_contains( $texte_brut, 'Une série comprenant jusqu’à trois demandes de modification est incluse.' )
);
// Les nombres présents dans la définition sont des bornes et des longueurs,
// jamais des prix : on contrôle la structure, pas le texte. Un montant se
// reconnaît à une clé de prix ou à un symbole monétaire.
$cles_interdites = array( 'price', 'prix', 'amount', 'montant', 'total', 'discount', 'remise' );
$fautes          = array();

$parcours = static function ( $noeud, string $chemin ) use ( &$parcours, &$fautes, $cles_interdites ): void {
	foreach ( $noeud as $cle => $valeur ) {
		$ici = $chemin . '/' . $cle;

		if ( is_string( $cle ) && in_array( strtolower( $cle ), $cles_interdites, true ) ) {
			$fautes[] = $ici;
		}

		if ( is_array( $valeur ) ) {
			$parcours( $valeur, $ici );
		} elseif ( is_string( $valeur ) && str_contains( $valeur, '€' ) ) {
			$fautes[] = $ici . ' (symbole monétaire)';
		}
	}
};

$parcours( $raw, '' );

check( 'aucun montant ni clé de prix dans la définition', array() === $fautes );

if ( array() !== $fautes ) {
	echo '    faute : ' . implode( ' | ', $fautes ) . "\n";
}

check( 'la définition n’évoque aucune remise', 0 === preg_match( '/remise|déduit|déduction/iu', $code ) );
check( 'la définition ne référence jamais la classe Pricing', ! str_contains( $code, 'Pricing' ) );

// --------------------------------------- compatibilité avec localisation ---
$loc = definition( brut( 'localisation' ) );

check( 'localisation se charge toujours sans anomalie', $loc->is_valid() );
check( 'localisation ne déclare aucune étape', array() === $loc->steps() );
check( 'localisation conserve ses 14 champs', 14 === count( $loc->fields() ) );
check( 'localisation conserve 6 champs visibles', 6 === count( $loc->visible_fields() ) );
check( 'localisation conserve 8 champs techniques', 8 === count( $loc->hidden_fields() ) );
check(
	'localisation n’emploie que les trois types historiques',
	array() === array_diff( array_unique( array_column( $loc->fields(), 'type' ) ), array( 'text', 'number', 'hidden' ) )
);
check(
	'localisation conserve ses cinq champs obligatoires',
	array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'cad_section', 'cad_numero' )
		=== array_column( array_filter( $loc->fields(), static fn( $f ) => ! empty( $f['required'] ) ), 'name' )
);
check(
	'localisation conserve tous ses chemins vers le contrat cadastre',
	14 === count( array_filter( $loc->fields(), static fn( $f ) => ! empty( $f['from'] ) ) )
);

// ------------------------------------------- aucun effet public à ce stade -
$racine = dirname( __DIR__, 2 );
$refs   = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine . '/wordpress' ) ) as $f ) {
	// La définition elle-même se nomme évidemment « conception » : elle n'est
	// pas une demande de rendu, on ne la compte pas.
	if ( ! $f->isFile() || str_contains( $f->getPathname(), '/definitions/' ) ) {
		continue;
	}

	$contenu = (string) file_get_contents( $f->getPathname() );

	if ( preg_match( '/(formType|form_type|form-type)["\\\'\\s=:]+conception/', $contenu ) ) {
		$refs[] = str_replace( $racine . '/', '', $f->getPathname() );
	}
}

check( 'aucun gabarit ni bloc ne demande le formulaire conception', array() === $refs );

if ( array() !== $refs ) {
	echo '    référence : ' . implode( ' | ', $refs ) . "\n";
}

check(
	'le rendu public refuse un formulaire en étapes',
	'' === \Urbizen\Platform\Forms\Renderer::render( $def )
);
check(
	'le rendu public accepte toujours un formulaire sans étape',
	'' !== \Urbizen\Platform\Forms\Renderer::render( $loc )
);

verdict();
