<?php
/**
 * Validation métier de la déclaration préalable.
 *
 * Deux promesses sont éprouvées ici, et elles tirent en sens contraire :
 *
 * 1. **Une demande recevable passe.** Une clôture, un ravalement ou des
 *    panneaux solaires ne créent aucune surface ; exiger des mètres carrés
 *    rejetterait des dossiers parfaitement valides. En revanche, les deux
 *    mesures d'une extension et les caractéristiques d'une piscine sont
 *    déterminantes pour choisir le bon formulaire : les retirer ne doit pas
 *    permettre de contourner ce choix.
 * 2. **Une demande incohérente est refusée, pas nettoyée.** Un doublon, un
 *    projet répétant le principal ou une liste forgée passent la validation de
 *    forme. Le catalogue tarifaire ne les facture pas — mais un calcul prudent
 *    ne vaut pas acceptation. Le refus doit être explicite.
 *
 * Usage : php tests/formulaires/test-validation-metier-dp.php
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
use Urbizen\Platform\Forms\DeclarationPrealablePricingStrategy;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\PricingDeclarationPrealable;
use Urbizen\Platform\Forms\ValidationMessages;
use Urbizen\Platform\Forms\ValidationMetierDeclarationPrealable;
use Urbizen\Platform\Forms\ValidationMetierRegistry;
use Urbizen\Platform\Forms\Validator;

$brut = require $src . '/Forms/definitions/declaration_prealable.php';
$def  = new FormDefinition( $brut['type'], $brut['title'], $brut['submit_label'], $brut['fields'], $brut['steps'] );

$metier = new ValidationMetierDeclarationPrealable();

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
 * Réponses minimales d'une demande recevable avant contrôle du régime.
 *
 * @param string               $nature Nature du projet principal.
 * @param array<string, mixed> $ajouts Champs supplémentaires.
 * @return array<string, mixed>
 */
function demande_minimale( string $nature, array $ajouts = array() ): array {
	return array_merge(
		array(
			'declarant_type'    => 'particulier',
			'nom'               => 'Martin',
			'prenom'            => 'Claire',
			'qualite'           => 'proprietaire',
			'email'             => 'claire.martin@exemple.fr',
			'telephone'         => '0600000000',
			// L'adresse du déclarant est elle aussi assistée, et exigée : elle
			// porte donc son mode et son code commune, comme celle du terrain.
			// Un jeu volontairement invalide n'a pas sa place ici — ce banc
			// éprouve la recevabilité sans surface, et les adresses fautives
			// sont couvertes par `test-adresse-terrain.php`.
			'mode_adresse_declarant' => 'automatique',
			'adresse_declarant' => '12 rue des Lilas, 33000 Bordeaux',
			'insee_declarant'   => '33063',
			'cp_declarant'      => '33000',
			'ville_declarant'   => 'Bordeaux',
			// L'adresse du terrain est désormais exigée, et son mode avec elle :
			// une DP sans mode d'adresse est refusée, ce que couvre
			// `test-adresse-terrain.php`. Ici on la fournit valide, pour que le
			// banc éprouve bien ce qu'il annonce — la recevabilité sans surface.
			'mode_adresse'      => 'automatique',
			'terrain_adresse'   => '12 rue des Lilas, 33000 Bordeaux',
			'terrain_insee'     => '33063',
			'terrain_cp'        => '33000',
			'terrain_ville'     => 'Bordeaux',
			'nature'            => $nature,
			'intervention'      => 'existant',
			'description'       => 'Projet décrit par le demandeur.',
			'abf'               => 'non',
			'demolition'        => 'non',
			'attest_exact'      => '1',
			'attest_rgpd'       => '1',
		),
		$ajouts
	);
}

/* ================================================================== *
 *  1. Aucune surface n'est obligatoire
 * ================================================================== */

echo "\n── 1. Les surfaces ne conditionnent aucune demande\n";

$surfaces = array( 'sp_existante', 'sp_creee', 'sp_totale', 'emprise_avant', 'emprise_creee', 'surface_taxable' );

foreach ( $surfaces as $champ ) {
	$declaration = $def->field( $champ );

	verifier( sprintf( '« %s » n’est pas obligatoire', $champ ), empty( $declaration['required'] ) );
}

/* ================================================================== *
 *  2. Matrice des douze natures et données déterminantes
 * ================================================================== */

echo "\n── 2. Matrice : les données déterminantes ne sont pas contournables\n";

foreach ( CatalogueDeclarationPrealable::natures() as $nature ) {
	$resultat = Validator::validate( $def, demande_minimale( $nature ) );
	$metiers  = $metier->valider( $resultat['clean'] );

	$libelle = (string) CatalogueDeclarationPrealable::libelle_nature( $nature );

	$determinants_requis = in_array( $nature, array( 'extension', 'piscine' ), true );

	verifier(
		sprintf( '%-24s (%s) — comportement attendu sans déterminants', $nature, $libelle ),
		$resultat['valid']
			&& ( $determinants_requis
				? isset( $metiers['regime'] )
				: array() === $metiers )
	);
}

$extension_complete = $metier->valider(
	demande_minimale(
		'extension',
		array(
			'sp_creee'      => 15,
			'emprise_creee' => 15,
		)
	)
);

verifier( 'extension · les deux mesures permettent la vérification', ! isset( $extension_complete['regime'] ) );

$piscine_complete = $metier->valider(
	demande_minimale(
		'piscine',
		array(
			'surface_bassin_m2'     => 40,
			'presence_abri_piscine' => 'non',
		)
	)
);

verifier( 'piscine · bassin et couverture permettent la vérification', ! isset( $piscine_complete['regime'] ) );

// Le point de départ du sujet : ces cinq natures ne créent jamais de surface.
foreach ( array( 'cloture_mur', 'panneaux_solaires', 'modification_facade', 'ravalement', 'toiture' ) as $nature ) {
	$resultat = Validator::validate( $def, demande_minimale( $nature ) );

	verifier(
		sprintf( '%s — aucune surface n’est réclamée', $nature ),
		! isset( $resultat['errors']['sp_creee'] )
	);
}

/* ================================================================== *
 *  3. Une absence reste une absence
 * ================================================================== */

echo "\n── 3. Une surface absente n'est pas remplacée par un zéro\n";

$sans_surface = Validator::validate( $def, demande_minimale( 'extension' ) );

foreach ( $surfaces as $champ ) {
	$valeur = $sans_surface['clean'][ $champ ] ?? null;

	verifier(
		sprintf( '« %s » absent ne devient pas 0', $champ ),
		0 !== $valeur && '0' !== $valeur
	);
}

$avec_surface = Validator::validate(
	$def,
	demande_minimale(
		'extension',
		array(
			'sp_existante' => '  120  ',
			'sp_creee'     => '18',
			'sp_totale'    => '138',
		)
	)
);

verifier( 'une surface renseignée est validée', $avec_surface['valid'] );
verifier( 'une surface renseignée est nettoyée et conservée', 18 === (int) $avec_surface['clean']['sp_creee'] );
verifier( 'les espaces autour d’une valeur sont retirés', 120 === (int) $avec_surface['clean']['sp_existante'] );

/* ================================================================== *
 *  4. Refus métier
 * ================================================================== */

echo "\n── 4. Ce que le validateur métier refuse\n";

$refus = array(
	'projet supplémentaire en double'                 => array( 'extension', array( 'piscine', 'piscine' ), 'projet_en_double' ),
	'projet supplémentaire identique au principal'    => array( 'extension', array( 'extension' ), 'projet_identique_au_principal' ),
	'projet supplémentaire inconnu'                   => array( 'extension', array( 'chateau_fort' ), 'projet_inconnu' ),
	'identifiant avec majuscule'                      => array( 'extension', array( 'Piscine' ), 'projet_inconnu' ),
	'identifiant avec accent'                         => array( 'extension', array( 'clôture' ), 'projet_inconnu' ),
	'identifiant avec espace'                         => array( 'extension', array( 'abri annexe' ), 'projet_inconnu' ),
	'identifiant avec slash'                          => array( 'extension', array( 'abri / annexe' ), 'projet_inconnu' ),
);

foreach ( $refus as $label => $donnees ) {
	list( $principal, $supplements, $code ) = $donnees;

	$erreurs = $metier->valider(
		array(
			'nature'                  => $principal,
			'projets_supplementaires' => $supplements,
		)
	);

	verifier( sprintf( '%s → refusé (%s)', $label, $code ), ( $erreurs['projets_supplementaires'] ?? '' ) === $code );
}

$limite = PricingDeclarationPrealable::max_projets_supplementaires();

$trop = $metier->valider(
	array(
		'nature'                  => 'extension',
		'projets_supplementaires' => array_fill( 0, $limite + 1, 'piscine' ),
	)
);

verifier( sprintf( 'liste dépassant la limite de %d → refusée', $limite ), 'projets_trop_nombreux' === ( $trop['projets_supplementaires'] ?? '' ) );

$malforme = $metier->valider(
	array(
		'nature'                  => 'extension',
		'projets_supplementaires' => 'piscine',
	)
);

verifier( 'liste envoyée comme chaîne → refusée', 'projet_malforme' === ( $malforme['projets_supplementaires'] ?? '' ) );

$nature_inconnue = $metier->valider( array( 'nature' => 'chateau_fort' ) );

verifier( 'nature principale inconnue → refusée', 'projet_inconnu' === ( $nature_inconnue['nature'] ?? '' ) );

$sans_nature = $metier->valider( array() );

verifier( 'nature principale absente → refusée', 'projet_inconnu' === ( $sans_nature['nature'] ?? '' ) );

// Un dossier légitime, lui, passe : la limite ne doit pas gêner l'usage réel.
$maximum = array_values( array_diff( CatalogueDeclarationPrealable::natures(), array( 'extension' ) ) );

verifier(
	sprintf( 'un dossier réunissant les %d autres natures reste accepté', $limite ),
	// Un dossier légitime porte ses deux adresses : la limite éprouvée ici est
	// celle des projets supplémentaires, pas celle des adresses. Les lui
	// retirer ferait échouer le banc pour une raison qui n'est pas la sienne.
	array() === $metier->valider(
		demande_minimale(
			'extension',
			array(
				'projets_supplementaires' => $maximum,
				'sp_creee'                 => 15,
				'emprise_creee'            => 15,
			)
		)
	)
);

/* ================================================================== *
 *  5. Les messages sont lisibles par une personne
 * ================================================================== */

echo "\n── 5. Des messages, pas des codes\n";

foreach ( array( 'projet_inconnu', 'projet_identique_au_principal', 'projet_en_double', 'projets_trop_nombreux', 'projet_malforme', 'email_invalide' ) as $code ) {
	$message = ValidationMessages::message( $code );

	verifier(
		sprintf( '« %s » a un message dédié', $code ),
		'' !== $message
			&& 'Cette information n’a pas pu être validée.' !== $message
			&& ! str_contains( $message, $code )
	);
}

/* ================================================================== *
 *  6. Adresse électronique
 * ================================================================== */

echo "\n── 6. L'adresse électronique est vérifiée par le serveur\n";

$valides   = array( 'claire.martin@exemple.fr', 'c.martin+dp@sous.domaine.fr' );
$invalides = array( 'claire.martin', 'claire@', '@exemple.fr', 'claire martin@exemple.fr', 'claire@exemple' );

foreach ( $valides as $adresse ) {
	$r = Validator::validate( $def, demande_minimale( 'extension', array( 'email' => $adresse ) ) );

	verifier( sprintf( '« %s » est acceptée', $adresse ), ! isset( $r['errors']['email'] ) );
}

foreach ( $invalides as $adresse ) {
	$r = Validator::validate( $def, demande_minimale( 'extension', array( 'email' => $adresse ) ) );

	verifier( sprintf( '« %s » est refusée', $adresse ), 'email_invalide' === ( $r['errors']['email'] ?? '' ) );
}

$vide = Validator::validate( $def, demande_minimale( 'extension', array( 'email' => '' ) ) );

verifier( 'une adresse vide est refusée comme champ requis', 'requis' === ( $vide['errors']['email'] ?? '' ) );
verifier(
	'le message d’adresse invalide reste compréhensible',
	'Cette adresse électronique n’est pas valide.' === ValidationMessages::message( 'email_invalide' )
);

/* ================================================================== *
 *  7. Descriptions
 * ================================================================== */

echo "\n── 7. Descriptions de projet supplémentaire\n";

$avec_desc = Validator::validate(
	$def,
	demande_minimale(
		'extension',
		array(
			'projets_supplementaires'    => array( 'piscine' ),
			'description_projet_piscine' => '  Bassin 4 × 8 m à l’arrière  ',
			// Orpheline : la nature « toiture » n'est pas retenue.
			'description_projet_toiture' => 'Réfection complète',
		)
	)
);

verifier( 'une demande avec description est valide', $avec_desc['valid'] );
verifier( 'la description liée est nettoyée et conservée', 'Bassin 4 × 8 m à l’arrière' === ( $avec_desc['clean']['description_projet_piscine'] ?? '' ) );
verifier( 'la description orpheline est écartée', ! isset( $avec_desc['clean']['description_projet_toiture'] ) );

$strategie = new DeclarationPrealablePricingStrategy();

$sans_desc = $strategie->calculate_with_context( array( 'extension' ), array( 'projets_supplementaires' => array( 'piscine' ) ) );
$avec      = $strategie->calculate_with_context(
	array( 'extension' ),
	array(
		'projets_supplementaires'    => array( 'piscine' ),
		'description_projet_piscine' => 'Bassin 4 × 8 m',
	)
);

verifier( 'une description n’a aucune incidence tarifaire', $sans_desc['total'] === $avec['total'] );

$champ_desc = $def->field( 'description_projet_piscine' );

verifier( 'la longueur d’une description est bornée côté serveur', 200 === ( $champ_desc['maxlength'] ?? 0 ) );

/* ================================================================== *
 *  8. Le registre, et la limite partagée
 * ================================================================== */

echo "\n── 8. Une seule limite, une seule table de confiance\n";

verifier( 'la DP a des règles métier', ValidationMetierRegistry::has( 'declaration_prealable' ) );
verifier( 'la conception n’en hérite pas', ! ValidationMetierRegistry::has( 'conception' ) );
verifier( 'un type inconnu n’en hérite pas', ! ValidationMetierRegistry::has( 'inconnu' ) );

verifier(
	'la limite découle du catalogue, elle n’est pas posée à la main',
	count( CatalogueDeclarationPrealable::NATURES ) - 1 === $limite
);

$html = (string) file_get_contents( $racine . '/wordpress/urbizen-child/assets/forms/dp-formulaire.html' );

// L'interface ne peut pas proposer plus de projets qu'il n'existe de natures :
// l'anti-doublon retire chaque nature déjà prise. La limite serveur est donc
// atteignable par construction, jamais franchissable.
verifier(
	'l’interface ne peut pas dépasser cette limite (anti-doublon)',
	str_contains( $html, 'urbizen-form-tarifs.js' )
		&& 12 === substr_count( $html, 'name="nature" value="' )
);

echo "\n";

if ( $echecs > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $echecs );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
