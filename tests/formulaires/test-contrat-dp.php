<?php
/**
 * Contrat de la déclaration préalable : interface ↔ définition ↔ tarification.
 *
 * Ce banc existe pour une raison précise : la même liste de natures vit dans
 * quatre documents HTML, dans une définition serveur et dans un catalogue
 * tarifaire. Sans contrôle, un ajout ou un renommage passe dans l'un et pas
 * dans les autres, et le tarif devient silencieusement faux — le pire des
 * défauts, parce qu'il ne se voit pas.
 *
 * Toute divergence fait donc échouer ce banc, sans tolérance ni traduction.
 *
 * Aucun WordPress n'est requis : la définition est chargée avec des doublures
 * minimales, et les documents HTML sont lus tels qu'ils seront servis.
 *
 * Usage : php tests/formulaires/test-contrat-dp.php
 */

define( 'ABSPATH', true );

if ( ! function_exists( '__' ) ) {
	/**
	 * Doublure de traduction.
	 *
	 * @param string $texte   Texte.
	 * @param string $domaine Domaine.
	 * @return string
	 */
	function __( $texte, $domaine = null ) { // phpcs:ignore
		return $texte;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Doublure de filtre : les limites d'upload sont filtrables en production ;
	 * hors WordPress, le banc éprouve les valeurs par défaut.
	 *
	 * @param string $crochet Nom du filtre.
	 * @param mixed  $valeur  Valeur par défaut.
	 * @return mixed
	 */
	function apply_filters( $crochet, $valeur ) { // phpcs:ignore
		return $valeur;
	}
}

$racine = dirname( __DIR__, 2 );
$src    = $racine . '/wordpress/urbizen-platform/src';

spl_autoload_register(
	static function ( $classe ) use ( $src ) {
		$relatif = str_replace( 'Urbizen\\Platform\\', '', $classe );
		$fichier = $src . '/' . str_replace( '\\', '/', $relatif ) . '.php';

		if ( file_exists( $fichier ) ) {
			require $fichier;
		}
	}
);

use Urbizen\Platform\Files\UploadPolicy;
use Urbizen\Platform\Files\UploadProfileRegistry;
use Urbizen\Platform\Forms\CatalogueDeclarationPrealable;
use Urbizen\Platform\Forms\DeclarationPrealablePricingStrategy;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\PricingDeclarationPrealable;
use Urbizen\Platform\Forms\ValidationMetierDeclarationPrealable;

$brut = require $src . '/Forms/definitions/declaration_prealable.php';
$def  = new FormDefinition( $brut['type'], $brut['title'], $brut['submit_label'], $brut['fields'], $brut['steps'] );

$documents = array(
	'DP thème'    => $racine . '/wordpress/urbizen-child/assets/forms/dp-formulaire.html',
	'DP maquette' => $racine . '/frontend/formulaires/dp-formulaire.html',
);

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $label     Intitulé.
 * @param bool   $condition Verdict.
 * @return void
 */
function verifier( $label, $condition ) {
	global $echecs;

	if ( ! $condition ) {
		$echecs++;
	}

	printf( "%-76s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

/**
 * Valeurs d'un champ à options dans la définition.
 *
 * @param FormDefinition $def  Définition.
 * @param string         $name Nom du champ.
 * @return array<int, string>
 */
function valeurs_definition( FormDefinition $def, string $name ): array {
	$champ = $def->field( $name );

	return array_column( $champ['options'] ?? array(), 'value' );
}

/**
 * Valeurs d'un groupe de boutons radio ou de cases, dans un document HTML.
 *
 * @param string $html Document.
 * @param string $name Nom du groupe.
 * @return array<int, string>
 */
function valeurs_html( string $html, string $name ): array {
	preg_match_all( '/name="' . preg_quote( $name, '/' ) . '" value="([^"]+)"/', $html, $m );

	return $m[1];
}

$natures = CatalogueDeclarationPrealable::natures();

/* ================================================================== *
 *  1. Les douze identifiants canoniques
 * ================================================================== */

echo "\n── 1. Les douze identifiants canoniques\n";

$attendus = array(
	'extension',
	'abri_annexe',
	'garage',
	'carport',
	'piscine',
	'cloture_mur',
	'modification_facade',
	'ravalement',
	'toiture',
	'panneaux_solaires',
	'changement_destination',
	'autre',
);

verifier( 'le catalogue déclare exactement les douze identifiants attendus', $attendus === $natures );
verifier(
	'tous respectent le motif imposé aux identifiants du socle',
	array() === array_filter( $natures, static fn( $id ) => ! preg_match( FormDefinition::ID_PATTERN, $id ) )
);

/* ================================================================== *
 *  2. Parité interface / définition / tarification
 * ================================================================== */

echo "\n── 2. Parité des quatre sources\n";

verifier( 'définition · le projet principal propose les douze identifiants', $natures === valeurs_definition( $def, 'nature' ) );
verifier( 'définition · les projets supplémentaires proposent les mêmes', $natures === valeurs_definition( $def, 'projets_supplementaires' ) );
verifier( 'tarification · le catalogue est indexé sur les mêmes identifiants', $natures === array_keys( PricingDeclarationPrealable::NATURES ) );

foreach ( $documents as $nom => $chemin ) {
	$html = (string) file_get_contents( $chemin );

	verifier( sprintf( '%s · les boutons radio portent les douze identifiants', $nom ), $natures === valeurs_html( $html, 'nature' ) );

	// Le barème de l'interface doit nommer les mêmes clés que le serveur —
	// pas les mêmes montants : ceux de l'interface sont indicatifs, seul le
	// serveur fait foi. Mais une clé absente ferait retomber une nature sur le
	// tarif par défaut sans que personne ne le voie.
	$manquantes = array_filter(
		$natures,
		static fn( $id ) => ! str_contains( $html, '"' . $id . '"' )
	);

	verifier( sprintf( '%s · chaque identifiant apparaît dans la configuration', $nom ), array() === $manquantes );

	// Les anciennes valeurs techniques françaises ne doivent plus exister.
	$anciennes = array( 'value="Extension"', 'value="Piscine"', 'value="Garage"', 'value="Autre"', 'Abri / annexe', 'Clôture / mur', 'Modification de façade', 'Carport / abri de voiture' );

	verifier(
		sprintf( '%s · aucune ancienne valeur technique ne subsiste', $nom ),
		array() === array_filter( $anciennes, static fn( $a ) => str_contains( $html, $a ) )
	);
}

/* ================================================================== *
 *  3. Les libellés vus par le client n'ont pas bougé
 * ================================================================== */

echo "\n── 3. Libellés client inchangés\n";

$libelles_attendus = array(
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

verifier( 'le catalogue serveur porte les libellés attendus', $libelles_attendus === CatalogueDeclarationPrealable::NATURES );

foreach ( $documents as $nom => $chemin ) {
	$html    = (string) file_get_contents( $chemin );
	$ecarts  = array();

	foreach ( $libelles_attendus as $id => $libelle ) {
		$motif = '/name="nature" value="' . preg_quote( $id, '/' ) . '">\s*([^<]+)</';

		if ( ! preg_match( $motif, $html, $m ) || trim( $m[1] ) !== $libelle ) {
			$ecarts[] = $id;
		}
	}

	verifier( sprintf( '%s · chaque identifiant affiche son libellé attendu', $nom ), array() === $ecarts );
}

/* ================================================================== *
 *  4. Champs obligatoires et facultatifs
 * ================================================================== */

echo "\n── 4. Ce que le serveur exige, et ce qu'il n'exige pas\n";

$requis = array_column( array_filter( $def->fields(), static fn( $f ) => ! empty( $f['required'] ) ), 'name' );

foreach ( array( 'email', 'telephone', 'nature', 'qualite', 'declarant_type' ) as $name ) {
	verifier( sprintf( '« %s » est obligatoire', $name ), in_array( $name, $requis, true ) );
}

/*
 * Les adresses ne portent plus `required`, et ce n'est pas un relâchement :
 * l'obligation a changé de couche. Le validateur générique n'accepte qu'une
 * condition par champ — il ne saurait pas combiner « mode de saisie » et
 * « même adresse que le déclarant ». Lui laisser l'obligation aurait rendu
 * obligatoire, case cochée, un bloc terrain que la personne ne remplit plus.
 *
 * C'est donc AdresseTerrain qui l'exige, et le banc le prouve ici plutôt que
 * de se contenter de constater l'absence du drapeau.
 */
foreach ( array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_voie', 'adresse_declarant', 'cp_declarant', 'ville_declarant', 'voie_declarant' ) as $name ) {
	verifier( sprintf( '« %s » ne porte pas `required` générique', $name ), ! in_array( $name, $requis, true ) );
}

$metier = new ValidationMetierDeclarationPrealable();

// Une DP sans la moindre adresse : les deux rôles doivent se plaindre, chacun
// sous son propre nom de mode.
$sans_adresse = $metier->valider( array( 'nature' => 'piscine' ) );

verifier( 'le métier exige l’adresse du déclarant',
	'adresse_mode_absent' === ( $sans_adresse['mode_adresse_declarant'] ?? '' ) );
verifier( 'le métier exige l’adresse du terrain',
	'adresse_mode_absent' === ( $sans_adresse['mode_adresse'] ?? '' ) );

// Et il exige le détail de chaque mode, pas seulement le mode lui-même.
$mode_seul = $metier->valider(
	array(
		'nature'                 => 'piscine',
		'mode_adresse'           => 'automatique',
		'mode_adresse_declarant' => 'manuel',
	)
);

foreach ( array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_insee', 'voie_declarant', 'cp_declarant', 'ville_declarant' ) as $name ) {
	verifier( sprintf( 'le métier exige « %s »', $name ), 'champ_requis' === ( $mode_seul[ $name ] ?? '' ) );
}

foreach ( array( 'cad_section', 'cad_numero', 'terrain_superficie', 'pieces_differees', 'projets_supplementaires', 'informations_cadastrales_differees', 'depot_guichet', 'materiaux', 'remarques' ) as $name ) {
	verifier( sprintf( '« %s » n’est pas obligatoire', $name ), ! in_array( $name, $requis, true ) );
}

verifier(
	'aucun champ de dépôt n’est obligatoire',
	array() === array_filter(
		$def->fields(),
		static fn( $f ) => 'file' === $f['type'] && ! empty( $f['required'] )
	)
);

/* ================================================================== *
 *  5. Pièces : liste canonique unique
 * ================================================================== */

echo "\n── 5. Pièces, une seule liste canonique\n";

$pieces = CatalogueDeclarationPrealable::pieces();

verifier( 'sept types de pièces', 7 === count( $pieces ) );
verifier(
	'tous respectent le motif des identifiants',
	array() === array_filter( $pieces, static fn( $id ) => ! preg_match( FormDefinition::ID_PATTERN, $id ) )
);
verifier( 'définition · « pieces_differees » propose les mêmes identifiants', $pieces === valeurs_definition( $def, 'pieces_differees' ) );

$champs_fichiers = array_column(
	array_filter( $def->fields(), static fn( $f ) => 'file' === $f['type'] ),
	'name'
);

verifier( 'définition · un champ de dépôt par pièce, nommé piece_<id>', CatalogueDeclarationPrealable::blocs() === $champs_fichiers );

$profil = UploadProfileRegistry::for_type( 'declaration_prealable' );

verifier( 'un profil d’upload existe pour la DP', null !== $profil );
verifier( 'profil · les blocs sont exactement les sept champs de dépôt', CatalogueDeclarationPrealable::blocs() === $profil->blocks );
verifier( 'profil · un champ fichier inconnu est refusé', ! $profil->has_block( 'piece_inconnue' ) );

/* ================================================================== *
 *  6. Profil d'upload : les limites retenues
 * ================================================================== */

echo "\n── 6. Limites du profil d'upload\n";

verifier( 'formats : pdf, jpg, jpeg, png, webp', array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ) === array_keys( $profil->types ) );
verifier( '10 fichiers par bloc', 10 === $profil->max_per_block );
verifier( '20 fichiers au total', 20 === $profil->max_total );
verifier( '10 Mio par fichier', 10485760 === $profil->max_file_size );
verifier( '25 Mio cumulés', 26214400 === $profil->max_total_size );
verifier( 'les dépôts sont ouverts', true === $profil->uploads_enabled );
verifier( 'noms tronqués à 120 caractères', 120 === UploadPolicy::MAX_NAME_LENGTH );

// Sept blocs à dix fichiers permettraient soixante-dix documents : c'est le
// plafond global qui doit trancher, sinon la limite par bloc suffirait à le
// contourner.
verifier(
	'le plafond global prime sur le cumul des blocs (7 × 10 > 20)',
	$profil->max_per_block * count( $profil->blocks ) > $profil->max_total
);

/* ================================================================== *
 *  7. Tarification recalculée côté serveur
 * ================================================================== */

echo "\n── 7. Le tarif vient du serveur, jamais du navigateur\n";

$strategie = new DeclarationPrealablePricingStrategy();

$cas = array(
	'extension seule → 549 €'                    => array( array( 'extension' ), array(), 549 ),
	'clôture seule → 189 €'                      => array( array( 'cloture_mur' ), array(), 189 ),
	'panneaux solaires → 189 €'                  => array( array( 'panneaux_solaires' ), array(), 189 ),
	'garage → 249 €'                             => array( array( 'garage' ), array(), 249 ),
	'carport → 249 €'                            => array( array( 'carport' ), array(), 249 ),
	'autre → 249 € (comportement DP validé)'     => array( array( 'autre' ), array(), 249 ),
	'extension + 1 projet → 649 €'               => array( array( 'extension' ), array( 'projets_supplementaires' => array( 'piscine' ) ), 649 ),
	'extension + 3 projets → 849 €'              => array( array( 'extension' ), array( 'projets_supplementaires' => array( 'piscine', 'toiture', 'ravalement' ) ), 849 ),
	'extension + ABF → 629 €'                    => array( array( 'extension' ), array( 'abf' => 'oui' ), 629 ),
	'extension + dépôt → 579 €'                  => array( array( 'extension' ), array( 'depot_guichet' => 'oui' ), 579 ),
	'extension + 3 projets + ABF + dépôt → 959 €' => array(
		array( 'extension' ),
		array(
			'projets_supplementaires' => array( 'piscine', 'toiture', 'ravalement' ),
			'abf'                     => 'oui',
			'depot_guichet'           => 'oui',
		),
		959,
	),
);

foreach ( $cas as $label => $donnees ) {
	list( $selection, $contexte, $attendu ) = $donnees;
	$prix = $strategie->calculate_with_context( $selection, $contexte );

	verifier( $label, $attendu === $prix['total'] );
}

echo "\n── 8. Ce que le serveur refuse de facturer\n";

$refus = array(
	'nature inconnue → non facturée'              => array( array( 'extension' ), array( 'projets_supplementaires' => array( 'chateau_fort' ) ), 549 ),
	'doublon → facturé une seule fois'            => array( array( 'extension' ), array( 'projets_supplementaires' => array( 'piscine', 'piscine' ) ), 649 ),
	'projet identique au principal → non facturé' => array( array( 'extension' ), array( 'projets_supplementaires' => array( 'extension' ) ), 549 ),
	'liste anormalement longue → aucune facturée' => array( array( 'extension' ), array( 'projets_supplementaires' => array_fill( 0, 12, 'piscine' ) ), 549 ),
	'ABF falsifié en « peut-être » → non facturé' => array( array( 'extension' ), array( 'abf' => 'peut_etre' ), 549 ),
	'dépôt falsifié en « 1 » → non facturé'       => array( array( 'extension' ), array( 'depot_guichet' => '1' ), 549 ),
);

foreach ( $refus as $label => $donnees ) {
	list( $selection, $contexte, $attendu ) = $donnees;
	$prix = $strategie->calculate_with_context( $selection, $contexte );

	verifier( $label, $attendu === $prix['total'] );
}

// Le cœur du sujet : un total annoncé par le navigateur n'a aucun effet.
$falsifie = $strategie->calculate_with_context(
	array( 'extension' ),
	array(
		'projets_supplementaires' => array( 'piscine' ),
		'abf'                     => 'oui',
		// Tout ce qu'un client pourrait glisser dans sa requête.
		'total'                   => 1,
		'pricing'                 => array( 'total' => 1, 'base' => 1 ),
		'base'                    => 1,
		'prix'                    => 0,
		'montant'                 => 0,
	)
);

verifier( 'un total falsifié par le navigateur est intégralement ignoré', 729 === $falsifie['total'] );
verifier( 'le socle reste celui de la nature déclarée', 549 === $falsifie['base'] );

verifier(
	'chaque socle du catalogue est accepté par la stratégie',
	array() === array_filter( PricingDeclarationPrealable::socles(), static fn( $b ) => ! $strategie->accepts_base( $b ) )
);
verifier( 'un socle hors catalogue est refusé', ! $strategie->accepts_base( 1 ) );

/* ================================================================== *
 *  9. Descriptions facultatives et informations différées
 * ================================================================== */

echo "\n── 9. Descriptions et reports\n";

$descriptions = array_column(
	array_filter(
		$def->fields(),
		static fn( $f ) => str_starts_with( $f['name'], CatalogueDeclarationPrealable::PREFIXE_DESCRIPTION )
	),
	'name'
);

verifier( 'une description facultative par nature possible', 12 === count( $descriptions ) );
verifier( 'la clé est déterministe, dérivée de la nature', in_array( 'description_projet_piscine', $descriptions, true ) );
verifier(
	'aucune description n’est obligatoire',
	array() === array_filter(
		$def->fields(),
		static fn( $f ) => str_starts_with( $f['name'], CatalogueDeclarationPrealable::PREFIXE_DESCRIPTION ) && ! empty( $f['required'] )
	)
);

// Une description n'est retenue que si sa nature l'est : c'est ce qui rend une
// description orpheline sans effet, plutôt que conservée sans rattachement.
$champ_desc = $def->field( 'description_projet_piscine' );

verifier( 'une description est conditionnée à sa nature', isset( $champ_desc['visible_if'] ) && array( 'piscine' ) === $champ_desc['visible_if']['in'] );
verifier( 'et elle ne porte aucun price_id', ! isset( $champ_desc['price_id'] ) );

$champ_cad = $def->field( 'informations_cadastrales_differees' );

verifier( 'le report cadastral est un champ déclaré', null !== $champ_cad );
verifier( 'il n’admet que « oui » ou « non »', array( 'oui', 'non' ) === array_column( $champ_cad['options'], 'value' ) );
verifier( 'il n’est pas obligatoire', empty( $champ_cad['required'] ) );

/* ================================================================== *
 *  10. Aucun champ inconnu, aucun montant dans la définition
 * ================================================================== */

echo "\n── 10. Hygiène de la définition\n";

verifier( 'la définition est valide', $def->is_valid() );
verifier( 'aucune erreur de déclaration', array() === $def->errors() );

$source_def = (string) file_get_contents( $src . '/Forms/definitions/declaration_prealable.php' );

// Les socles 189, 249 et 549 sont sans ambiguïté : s'ils apparaissent dans la
// définition, c'est qu'un montant y a été recopié. Les suppléments (100, 80,
// 30) ne sont pas cherchés ici — ils se confondraient avec des longueurs de
// champ légitimes, et le contrôle deviendrait bruyant plutôt que probant.
verifier(
	'aucun socle tarifaire n’est recopié dans la définition',
	! preg_match( '/\b(189|249|549)\b/', $source_def )
);

verifier(
	'chaque nature porte un price_id égal à son identifiant',
	array() === array_filter(
		$def->field( 'nature' )['options'],
		static fn( $o ) => ( $o['price_id'] ?? null ) !== $o['value']
	)
);

echo "\n";

if ( $echecs > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $echecs );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
