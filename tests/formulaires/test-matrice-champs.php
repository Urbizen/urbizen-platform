<?php
/**
 * Matrice métier : quelle nature justifie quels champs.
 *
 * Ce banc défend une chose : **une donnée sans objet est une donnée fausse**.
 * Une surface de plancher enregistrée sur une piscine ne serait pas un
 * remplissage superflu, elle se retrouverait dans le CERFA. Le formulaire
 * demandait pourtant les six mêmes surfaces à toutes les natures.
 *
 * Deux familles de contrôles :
 *
 * 1. **La matrice dit ce qu'elle doit dire** — les natures sans plancher n'en
 *    admettent aucun, et celles qui en créent un le proposent.
 * 2. **Le filtrage est réellement appliqué**, y compris à une charge forgée. Le
 *    masquage côté navigateur ne protège rien : il suffit de poster sans lui.
 *
 * Usage : php tests/formulaires/test-matrice-champs.php
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

use Urbizen\Platform\Forms\CatalogueDeclarationPrealable;
use Urbizen\Platform\Forms\CataloguePermisConstruire;
use Urbizen\Platform\Forms\MatriceChamps;

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

	printf( "%-78s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

/** Les trois champs de surface de plancher. */
const PLANCHER = array( 'sp_existante', 'sp_creee', 'sp_totale' );

/* ================================================================== *
 *  1. Toute nature du catalogue figure à la matrice
 * ================================================================== */

echo "\n── 1. Couverture\n";

verifier(
	'la matrice DP couvre exactement les douze natures du catalogue',
	CatalogueDeclarationPrealable::natures() === array_keys( MatriceChamps::DP )
);
verifier(
	'la matrice PC couvre exactement les six natures du catalogue',
	CataloguePermisConstruire::natures() === array_keys( MatriceChamps::PC )
);

// Aucune matrice ne doit citer un champ qui ne serait pas déclaré conditionnel :
// il ne serait jamais filtré, et la matrice mentirait sur son propre effet.
foreach ( array( 'DP' => MatriceChamps::DP, 'PC' => MatriceChamps::PC ) as $nom => $matrice ) {
	$inconnus = array();

	foreach ( $matrice as $nature => $champs ) {
		foreach ( $champs as $champ ) {
			if ( ! in_array( $champ, MatriceChamps::CONDITIONNELS, true ) ) {
				$inconnus[] = $nature . ':' . $champ;
			}
		}
	}

	verifier( sprintf( '%s · tout champ cité est déclaré conditionnel', $nom ), array() === $inconnus );
}

/* ================================================================== *
 *  2. Ce qui ne crée pas de plancher n'en demande pas
 * ================================================================== */

echo "\n── 2. Aucune surface de plancher là où il n'y en a pas\n";

$sans_plancher = array(
	'piscine'             => 'une piscine',
	'cloture_mur'         => 'une clôture ou un mur',
	'panneaux_solaires'   => 'des panneaux solaires',
	'ravalement'          => 'un ravalement',
	'toiture'             => 'une réfection de toiture',
	'modification_facade' => 'une modification de façade',
	'carport'             => 'un carport ouvert',
);

foreach ( $sans_plancher as $nature => $quoi ) {
	$champs = MatriceChamps::champs( 'declaration_prealable', $nature );

	verifier(
		sprintf( '%s : aucune surface de plancher', ucfirst( $quoi ) ),
		array() === array_intersect( PLANCHER, $champs )
	);
}

// La piscine ne demande QUE son bassin : ni plancher, ni emprise au sens du bâti.
$bassin = array( 'longueur_bassin_m', 'largeur_bassin_m', 'surface_bassin_m2', 'profondeur_bassin_m', 'presence_abri_piscine', 'hauteur_abri_m' );

verifier(
	'une piscine ne décrit que son bassin — et le décrit vraiment',
	$bassin === MatriceChamps::champs( 'declaration_prealable', 'piscine' )
);
verifier(
	'aucun doublon de surface de bassin ne subsiste',
	! in_array( 'piscine_m2', MatriceChamps::CONDITIONNELS, true )
);
verifier(
	'une maison neuve peut aussi décrire un bassin',
	array() === array_diff( $bassin, MatriceChamps::champs( 'permis_construire', 'maison_individuelle' ) )
);

// Une clôture, un ravalement, des panneaux : rien de chiffré tant que les
// questions propres à ces natures n'existent pas.
foreach ( array( 'cloture_mur', 'ravalement', 'panneaux_solaires', 'toiture', 'modification_facade' ) as $nature ) {
	verifier(
		sprintf( '« %s » n\'admet aucun champ conditionnel pour l\'instant', $nature ),
		array() === MatriceChamps::champs( 'declaration_prealable', $nature )
	);
}

/* ================================================================== *
 *  3. Ce qui en crée un le propose — facultativement
 * ================================================================== */

echo "\n── 3. Les natures bâties gardent leurs surfaces\n";

foreach ( array( 'extension', 'abri_annexe', 'garage' ) as $nature ) {
	verifier(
		sprintf( 'DP · « %s » propose une surface créée', $nature ),
		in_array( 'sp_creee', MatriceChamps::champs( 'declaration_prealable', $nature ), true )
	);
}

verifier(
	'DP · une extension propose l\'existant et le total',
	in_array( 'sp_existante', MatriceChamps::champs( 'declaration_prealable', 'extension' ), true )
	&& in_array( 'sp_totale', MatriceChamps::champs( 'declaration_prealable', 'extension' ), true )
);

verifier(
	'DP · un changement de destination porte sur une surface existante',
	in_array( 'sp_existante', MatriceChamps::champs( 'declaration_prealable', 'changement_destination' ), true )
);

foreach ( array( 'maison_individuelle', 'extension', 'surelevation', 'annexe_garage' ) as $nature ) {
	verifier(
		sprintf( 'PC · « %s » propose une surface créée', $nature ),
		in_array( 'sp_creee', MatriceChamps::champs( 'permis_construire', $nature ), true )
	);
}

verifier(
	'PC · une maison neuve compte ses logements et son stationnement',
	in_array( 'nb_logements', MatriceChamps::champs( 'permis_construire', 'maison_individuelle' ), true )
	&& in_array( 'nb_stationnement', MatriceChamps::champs( 'permis_construire', 'maison_individuelle' ), true )
);

verifier(
	'PC · une surélévation ne parle pas d\'emprise au sol',
	! in_array( 'emprise_creee', MatriceChamps::champs( 'permis_construire', 'surelevation' ), true )
);

/* ================================================================== *
 *  4. « Autre » n'impose aucune surface générique
 * ================================================================== */

echo "\n── 4. « Autre »\n";

verifier( 'DP · « Autre » n\'impose aucune surface', array() === MatriceChamps::champs( 'declaration_prealable', 'autre' ) );
verifier( 'PC · « Autre » n\'impose aucune surface', array() === MatriceChamps::champs( 'permis_construire', 'autre' ) );

/* ================================================================== *
 *  5. Le filtrage, éprouvé sur des charges forgées
 * ================================================================== */

echo "\n── 5. Le filtrage protège réellement\n";

// Le cas exact du reliquat : une extension saisie, puis le projet devient une
// piscine. Les surfaces restent dans le formulaire — elles ne doivent pas être
// persistées.
$charge = array(
	'nature'        => 'piscine',
	'sp_existante'  => 120.0,
	'sp_creee'      => 18.0,
	'sp_totale'     => 138.0,
	'emprise_creee' => 20.0,
	'surface_bassin_m2' => 32.0,
	'description'   => 'Bassin 4 × 8 m',
	'email'         => 'camille@exemple.test',
);

$ecarts = array();
$filtre = MatriceChamps::filtrer( 'declaration_prealable', $charge, $ecarts );

foreach ( PLANCHER as $champ ) {
	verifier( sprintf( 'piscine · « %s » est écarté', $champ ), ! array_key_exists( $champ, $filtre ) );
}

verifier( 'piscine · l\'emprise est écartée elle aussi', ! array_key_exists( 'emprise_creee', $filtre ) );
verifier( 'piscine · le bassin est conservé', 32.0 === $filtre['surface_bassin_m2'] );
verifier( 'piscine · la description est conservée', 'Bassin 4 × 8 m' === $filtre['description'] );
verifier( 'piscine · l\'adresse de courriel est conservée', 'camille@exemple.test' === $filtre['email'] );
verifier( 'piscine · la nature est conservée', 'piscine' === $filtre['nature'] );
verifier( 'piscine · les quatre écarts sont consignés', 4 === count( $ecarts ) );

// Un champ vide n'a rien à signaler : il ne serait pas persisté de toute façon.
$vides  = array();
MatriceChamps::filtrer( 'declaration_prealable', array( 'nature' => 'piscine', 'sp_creee' => '' ), $vides );
verifier( 'un champ vide sans objet ne produit aucun écart consigné', array() === $vides );

// Une extension, elle, garde tout : le filtrage ne doit pas mordre là où il ne
// faut pas.
$ext = MatriceChamps::filtrer(
	'declaration_prealable',
	array( 'nature' => 'extension', 'sp_existante' => 120.0, 'sp_creee' => 18.0, 'sp_totale' => 138.0 )
);

verifier( 'extension · les trois surfaces sont conservées', 3 === count( array_intersect_key( $ext, array_flip( PLANCHER ) ) ) );

// Une nature forgée n'ouvre aucune porte : le validateur métier la refuse
// déjà, mais le filtrage ne doit pas la traiter comme permissive.
$forge = MatriceChamps::filtrer( 'declaration_prealable', array( 'nature' => 'villa', 'sp_creee' => 99.0 ) );
verifier( 'une nature inconnue n\'admet aucun champ conditionnel', ! array_key_exists( 'sp_creee', $forge ) );

// Un type sans matrice n'est pas touché : Conception a ses propres champs.
$conception = MatriceChamps::filtrer( 'conception', array( 'nature' => 'maison', 'sp_creee' => 42.0 ) );
verifier( 'un type sans matrice traverse intact', 42.0 === $conception['sp_creee'] );

// Les champs inconditionnels traversent toujours, quelle que soit la nature.
$incond = MatriceChamps::filtrer(
	'declaration_prealable',
	array( 'nature' => 'cloture_mur', 'nom' => 'Fictif', 'terrain_cp' => '33000', 'abf' => 'oui' )
);

verifier( 'les champs inconditionnels ne sont jamais filtrés', 3 === count( $incond ) - 1 );

/* ================================================================== *
 *  6. Le PC suit la même règle
 * ================================================================== */

echo "\n── 6. Permis de construire\n";

$pc = MatriceChamps::filtrer(
	'permis_construire',
	array( 'nature' => 'autre', 'sp_creee' => 300.0, 'nb_logements' => 4, 'description' => 'Bâtiment mixte' )
);

verifier( 'PC · « Autre » écarte la surface', ! array_key_exists( 'sp_creee', $pc ) );
verifier( 'PC · « Autre » écarte le nombre de logements', ! array_key_exists( 'nb_logements', $pc ) );
verifier( 'PC · la description survit', 'Bâtiment mixte' === $pc['description'] );

/* Une maison neuve peut comporter un bassin — à condition qu'on l'ait dit.
 * `piscine_prevue` est la porte : sans un « oui », les mesures décrivent un
 * ouvrage que personne n'a annoncé, et une charge forgée ne doit pas pouvoir
 * les faire entrer par la fenêtre. */
$maison = MatriceChamps::filtrer(
	'permis_construire',
	array( 'nature' => 'maison_individuelle', 'sp_creee' => 110.0, 'nb_logements' => 1, 'piscine_prevue' => 'oui', 'surface_bassin_m2' => 24.0 )
);

verifier( 'PC · une maison neuve garde surface, logements et bassin annoncé', 4 === count( $maison ) - 1 );
verifier( 'PC · le bassin annoncé survit', 24.0 === $maison['surface_bassin_m2'] );

foreach ( array( 'non', 'inconnu' ) as $reponse ) {
	$ecarts = array();
	$sans   = MatriceChamps::filtrer(
		'permis_construire',
		array(
			'nature'                => 'maison_individuelle',
			'piscine_prevue'        => $reponse,
			'longueur_bassin_m'     => 8.5,
			'largeur_bassin_m'      => 4.0,
			'surface_bassin_m2'     => 34.0,
			'profondeur_bassin_m'   => 1.5,
			'presence_abri_piscine' => 'oui',
			'hauteur_abri_m'        => 1.8,
		),
		$ecarts
	);

	$restants = array_diff( array_keys( $sans ), array( 'nature', 'piscine_prevue' ) );

	verifier( sprintf( 'PC · « %s » écarte toutes les mesures de bassin', $reponse ), array() === $restants );
	verifier( sprintf( 'PC · « %s » conserve la réponse elle-même', $reponse ), $reponse === $sans['piscine_prevue'] );
	verifier( sprintf( 'PC · « %s » journalise les six écarts', $reponse ), 6 === count( $ecarts ) );
}

// L'abri s'efface avec la piscine, et la hauteur avec l'abri : la chaîne se
// referme d'un bout à l'autre, sans qu'aucune règle ne nomme deux pilotes.
$chaine = MatriceChamps::filtrer(
	'permis_construire',
	array( 'nature' => 'maison_individuelle', 'piscine_prevue' => 'oui', 'presence_abri_piscine' => 'non', 'hauteur_abri_m' => 1.8 )
);

verifier( 'PC · sans abri, la hauteur tombe', ! array_key_exists( 'hauteur_abri_m', $chaine ) );
verifier( 'PC · l’abri annoncé « non » se conserve', 'non' === $chaine['presence_abri_piscine'] );

// Et la DP « piscine » ne connaît pas cette porte : l'exiger d'un projet qui
// EST une piscine effacerait précisément ce qu'il décrit.
$dp_piscine = MatriceChamps::filtrer(
	'declaration_prealable',
	array( 'nature' => 'piscine', 'longueur_bassin_m' => 8.5, 'largeur_bassin_m' => 4.0 )
);

verifier( 'DP · une piscine garde ses mesures sans question préalable', 8.5 === $dp_piscine['longueur_bassin_m'] && 4.0 === $dp_piscine['largeur_bassin_m'] );

$dp_forge = MatriceChamps::filtrer(
	'declaration_prealable',
	array( 'nature' => 'piscine', 'piscine_prevue' => 'oui', 'longueur_bassin_m' => 8.5 )
);

verifier( 'DP · « piscine_prevue » n’y est pas admis', ! array_key_exists( 'piscine_prevue', $dp_forge ) );

/* ================================================================== *
 *  7. Le filtrage est branché dans le pipeline
 * ================================================================== */

echo "\n── 7. Branchement\n";

$controleur = (string) file_get_contents( $src . '/Http/SubmissionController.php' );

verifier( 'le contrôleur applique la matrice', str_contains( $controleur, 'MatriceChamps::filtrer(' ) );

// L'ordre compte : filtrer APRÈS la cohérence métier — qui doit voir la charge
// telle qu'elle a été envoyée — et AVANT la tarification et l'écriture.
$pos_metier  = strpos( $controleur, 'ValidationMetierRegistry::for_type' );
$pos_filtre  = strpos( $controleur, 'MatriceChamps::filtrer(' );
$pos_pricing = strpos( $controleur, "\$pricing = \$validation['pricing']" );

verifier( 'il filtre après la cohérence métier', $pos_metier < $pos_filtre );
verifier( 'et avant la tarification', $pos_filtre < $pos_pricing );
verifier( 'les écarts sont journalisés', str_contains( $controleur, 'sans objet pour la nature déclarée' ) );

/* ================================================================== *
 *  Bilan
 * ================================================================== */

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
