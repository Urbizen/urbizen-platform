<?php
/**
 * Contrat du permis de construire : interface ↔ définition ↔ tarification.
 *
 * Même raison d'être que le banc de la déclaration préalable : la liste des
 * natures vit dans deux documents HTML, dans une définition serveur et dans un
 * catalogue tarifaire. Sans contrôle, un ajout ou un renommage passe dans l'un
 * et pas dans les autres, et le tarif devient silencieusement faux — le pire
 * des défauts, parce qu'il ne se voit pas.
 *
 * Ce banc éprouve en plus ce que le permis de construire introduit et que la DP
 * ne connaissait pas : une nature **sans socle**. « Autre » y vaut un tarif sur
 * étude, et le contrôle porte autant sur ce qui est produit — clé `total`
 * présente, valeur nulle, statut explicite — que sur ce qui ne l'est pas :
 * aucun montant inventé, jamais un zéro.
 *
 * Aucun WordPress n'est requis : la définition est chargée avec des doublures
 * minimales, et les documents HTML sont lus tels qu'ils seront servis.
 *
 * Usage : php tests/formulaires/test-contrat-pc.php
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
	 * Doublure de filtre.
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
use Urbizen\Platform\Forms\CataloguePermisConstruire;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\PermisConstruirePricingStrategy;
use Urbizen\Platform\Forms\PricingPermisConstruire;
use Urbizen\Platform\Forms\ValidationMetierPermisConstruire;

$brut = require $src . '/Forms/definitions/permis_construire.php';
$def  = new FormDefinition( $brut['type'], $brut['title'], $brut['submit_label'], $brut['fields'], $brut['steps'] );

$documents = array(
	'PC thème'    => $racine . '/wordpress/urbizen-child/assets/forms/pc-formulaire.html',
	'PC maquette' => $racine . '/frontend/formulaires/pc-formulaire.html',
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

$natures = CataloguePermisConstruire::natures();

/* ================================================================== *
 *  1. Les six identifiants canoniques
 * ================================================================== */

echo "\n── 1. Les six identifiants canoniques\n";

$attendus = array(
	'maison_individuelle',
	'extension',
	'annexe_garage',
	'surelevation',
	'changement_destination',
	'autre',
);

verifier( 'le catalogue porte exactement les six natures attendues', $attendus === $natures );

foreach ( $natures as $id ) {
	verifier(
		sprintf( '« %s » est un identifiant canonique', $id ),
		1 === preg_match( FormDefinition::ID_PATTERN, $id )
	);
}

verifier(
	'aucune valeur technique française ne subsiste',
	array() === array_filter(
		$natures,
		static fn( $id ) => (bool) preg_match( '/[A-ZÀ-ÿ\s\'\/]/u', $id )
	)
);

/* ================================================================== *
 *  2. Les libellés lus par le client
 * ================================================================== */

echo "\n── 2. Les libellés lus par le client\n";

$libelles = array(
	'maison_individuelle'    => 'Maison neuve',
	'extension'              => 'Extension',
	'annexe_garage'          => 'Annexe / garage',
	'surelevation'           => 'Surélévation',
	'changement_destination' => 'Changement de destination',
	'autre'                  => 'Autre',
);

foreach ( $libelles as $id => $attendu ) {
	verifier(
		sprintf( '« %s » s’affiche « %s »', $id, $attendu ),
		$attendu === CataloguePermisConstruire::libelle_nature( $id )
	);
}

verifier( 'un identifiant inconnu ne se traduit pas', null === CataloguePermisConstruire::libelle_nature( 'villa' ) );

/* ================================================================== *
 *  3. Parité entre les documents et la définition
 * ================================================================== */

echo "\n── 3. Parité interface ↔ définition\n";

verifier( 'la définition propose les six natures', $natures === valeurs_definition( $def, 'nature' ) );
verifier( 'les projets supplémentaires aussi', $natures === valeurs_definition( $def, 'projets_supplementaires' ) );
verifier( 'les pièces différées suivent le catalogue', CataloguePermisConstruire::pieces() === valeurs_definition( $def, 'pieces_differees' ) );

foreach ( $documents as $nom => $chemin ) {
	$html = (string) file_get_contents( $chemin );

	verifier( sprintf( '%s : les natures du formulaire = le catalogue', $nom ), $natures === valeurs_html( $html, 'nature' ) );

	foreach ( $libelles as $id => $attendu ) {
		verifier(
			sprintf( '%s : « %s » porte son libellé exact', $nom, $id ),
			1 === preg_match( '/value="' . preg_quote( $id, '/' ) . '">\s*' . preg_quote( $attendu, '/' ) . '/u', $html )
		);
	}

	// Les listes déroulantes du PC portaient des libellés français en guise de
	// valeur. Une seule convention subsiste, et un banc l'exige.
	foreach ( array( 'qualite', 'raccord_eau', 'raccord_assainissement', 'raccord_elec' ) as $champ ) {
		preg_match( '/<select[^>]*name="' . $champ . '"[^>]*>(.*?)<\/select>/s', $html, $bloc );
		preg_match_all( '/<option([^>]*)>/', $bloc[1] ?? '', $opts );

		$sans_valeur = array_filter(
			$opts[1] ?? array(),
			static fn( $attrs ) => ! str_contains( $attrs, 'value=' )
		);

		verifier( sprintf( '%s : « %s » — chaque option porte une valeur', $nom, $champ ), array() === $sans_valeur );
	}

	verifier(
		sprintf( '%s : la parité des pièces est tenue', $nom ),
		array() === array_filter(
			CataloguePermisConstruire::pieces(),
			static fn( $piece ) => ! str_contains( $html, '"' . $piece . '"' )
		)
	);
}

/* ================================================================== *
 *  4. La définition serveur
 * ================================================================== */

echo "\n── 4. La définition serveur\n";

verifier( 'le type serveur est « permis_construire »', 'permis_construire' === $def->type() );
verifier( 'la définition est valide', $def->is_valid() );
verifier( 'et ne porte aucune erreur', array() === $def->errors() );

$requis = array_column( array_filter( $def->fields(), static fn( $f ) => ! empty( $f['required'] ) ), 'name' );

foreach ( array( 'email', 'telephone' ) as $champ ) {
	verifier( sprintf( '« %s » est exigé', $champ ), in_array( $champ, $requis, true ) );
}

/*
 * Les adresses ne portent plus `required`, et ce n'est pas un relâchement :
 * l'obligation a changé de couche, comme en déclaration préalable. Le
 * validateur générique n'accepte qu'une condition par champ — il ne saurait pas
 * combiner « mode de saisie » et « même adresse que le déclarant », et lui
 * laisser l'obligation aurait rendu obligatoire, case cochée, un bloc terrain
 * que la personne ne remplit plus.
 *
 * Le banc le prouve donc là où l'obligation vit désormais, plutôt que de se
 * contenter de constater l'absence du drapeau.
 */
foreach ( array( 'adresse_declarant', 'cp_declarant', 'ville_declarant', 'voie_declarant', 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_voie' ) as $champ ) {
	verifier( sprintf( '« %s » ne porte pas `required` générique', $champ ), ! in_array( $champ, $requis, true ) );
}

$metier_pc = new ValidationMetierPermisConstruire();

$sans_adresse = $metier_pc->valider( array( 'nature' => 'extension' ) );

verifier( 'le métier exige l’adresse du déclarant',
	'adresse_mode_absent' === ( $sans_adresse['mode_adresse_declarant'] ?? '' ) );
verifier( 'le métier exige l’adresse du terrain',
	'adresse_mode_absent' === ( $sans_adresse['mode_adresse'] ?? '' ) );

$mode_seul = $metier_pc->valider(
	array(
		'nature'                 => 'extension',
		'mode_adresse'           => 'automatique',
		'mode_adresse_declarant' => 'manuel',
	)
);

foreach ( array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_insee', 'voie_declarant', 'cp_declarant', 'ville_declarant' ) as $champ ) {
	verifier( sprintf( 'le métier exige « %s »', $champ ), 'champ_requis' === ( $mode_seul[ $champ ] ?? '' ) );
}

verifier( 'le projet principal est exigé', in_array( 'nature', $requis, true ) );
verifier( 'la description l’est aussi', in_array( 'description', $requis, true ) );
verifier( 'les deux consentements le sont', in_array( 'attest_exact', $requis, true ) && in_array( 'attest_rgpd', $requis, true ) );

// Facultatif au stade de la demande initiale : les réclamer bloquerait une
// demande qu'Urbizen sait compléter après réception.
foreach ( array( 'cad_section', 'cad_numero', 'terrain_superficie' ) as $champ ) {
	verifier( sprintf( '« %s » reste facultatif', $champ ), ! in_array( $champ, $requis, true ) );
}

foreach ( array( 'sp_existante', 'sp_creee', 'sp_totale', 'emprise_avant', 'emprise_creee', 'surface_taxable', 'nb_logements', 'nb_stationnement', 'piscine_m2' ) as $champ ) {
	verifier( sprintf( 'la surface « %s » reste facultative', $champ ), ! in_array( $champ, $requis, true ) );
}

verifier( 'les projets supplémentaires restent facultatifs', ! in_array( 'projets_supplementaires', $requis, true ) );
verifier( 'les pièces différées aussi', ! in_array( 'pieces_differees', $requis, true ) );

$fichiers_requis = array_filter(
	$def->fields(),
	static fn( $f ) => 'file' === $f['type'] && ! empty( $f['required'] )
);

verifier( 'aucun document n’est obligatoire', array() === $fichiers_requis );

$descriptions_requises = array_filter(
	$def->fields(),
	static fn( $f ) => str_starts_with( $f['name'], CataloguePermisConstruire::PREFIXE_DESCRIPTION ) && ! empty( $f['required'] )
);

verifier( 'aucune description supplémentaire n’est obligatoire', array() === $descriptions_requises );

$champ_cad = $def->field( 'informations_cadastrales_differees' );
verifier( 'le report cadastral est déclarable', is_array( $champ_cad ) );
verifier( 'il n’est pas obligatoire', empty( $champ_cad['required'] ) );

// Le champ caché de l'interface devient une liste fermée côté serveur.
verifier( 'le type de dossier est une liste fermée', array( 'pcmi', 'pc' ) === valeurs_definition( $def, 'dossier_type' ) );

/* ================================================================== *
 *  5. Le barème : les cinq cas chiffrés
 * ================================================================== */

echo "\n── 5. Le barème\n";

$socles = array(
	'maison_individuelle'    => 849,
	'extension'              => 649,
	'surelevation'           => 649,
	'changement_destination' => 649,
	'annexe_garage'          => 449,
);

foreach ( $socles as $id => $attendu ) {
	$r = PricingPermisConstruire::compute( array( $id ) );

	verifier( sprintf( '« %s » vaut %d €', $id, $attendu ), $attendu === $r['base'] && $attendu === $r['total'] );
	verifier( sprintf( '« %s » est marqué estimé', $id ), 'estime' === $r['pricing_status'] );
}

verifier( 'les suppléments sont ceux arrêtés',
	100 === PricingPermisConstruire::SUPPLEMENT_PROJET
	&& 80 === PricingPermisConstruire::SUPPLEMENT_ABF
	&& 30 === PricingPermisConstruire::SUPPLEMENT_DEPOT );

$complet = PricingPermisConstruire::compute(
	array( 'maison_individuelle' ),
	array(
		'projets_supplementaires' => array( 'extension' ),
		'abf'                     => 'oui',
		'depot_guichet'           => array( 'oui' ),
	)
);

verifier( 'maison neuve + 1 projet + ABF + dépôt = 1 059 €', 1059 === $complet['total'] );
verifier( 'et le détail porte les trois suppléments', 3 === count( $complet['options'] ) );

// La borne découle du catalogue : six natures, principal exclu, doublons
// interdits — cinq suppléments au plus.
verifier( 'le plafond de projets supplémentaires vaut 5', 5 === PricingPermisConstruire::max_projets_supplementaires() );

/* ================================================================== *
 *  6. « Autre » : sur étude, sans montant inventé
 * ================================================================== */

echo "\n── 6. Le tarif sur étude\n";

$etude = PricingPermisConstruire::compute(
	array( 'autre' ),
	array(
		'projets_supplementaires' => array( 'extension' ),
		'abf'                     => 'oui',
	)
);

verifier( 'la clé « total » est présente', array_key_exists( 'total', $etude ) );
verifier( 'et sa valeur est nulle', null === $etude['total'] );
verifier( 'le socle est nul lui aussi', null === $etude['base'] );
verifier( 'le statut est « sur_etude »', 'sur_etude' === $etude['pricing_status'] );
verifier( 'le devis est requis', true === $etude['devis_requis'] );

// Les suppléments restent connus et chiffrés : ce qui ne peut pas être connu,
// c'est leur somme avec un socle qui n'existe pas encore.
$montants = array_column( $etude['options'], 'price' );

verifier( 'les suppléments restent listés', 2 === count( $etude['options'] ) );
verifier( 'et chiffrés séparément', array( 100, 80 ) === $montants );
verifier( 'aucun total général n’est fabriqué depuis eux', 180 !== $etude['total'] );
verifier( 'et surtout pas un zéro', 0 !== $etude['total'] );

$strategie = new PermisConstruirePricingStrategy();

verifier( 'la stratégie accepte un socle sur étude', $strategie->accepts_base( null ) );
verifier( 'elle accepte les socles du catalogue',
	$strategie->accepts_base( 849 ) && $strategie->accepts_base( 649 ) && $strategie->accepts_base( 449 ) );
verifier( 'elle refuse un socle hors catalogue', ! $strategie->accepts_base( 999 ) );

/* ================================================================== *
 *  7. La validation métier
 * ================================================================== */

echo "\n── 7. La validation métier\n";

$metier = new ValidationMetierPermisConstruire();

$refus = array(
	'nature inconnue'                  => array( 'nature' => 'villa' ),
	'identifiant mal formé'            => array( 'nature' => 'Maison Individuelle' ),
	'nature absente'                   => array(),
	'projet supplémentaire inconnu'    => array( 'nature' => 'extension', 'projets_supplementaires' => array( 'villa' ) ),
	'principal répété en supplément'   => array( 'nature' => 'extension', 'projets_supplementaires' => array( 'extension' ) ),
	'doublon en supplément'            => array( 'nature' => 'extension', 'projets_supplementaires' => array( 'surelevation', 'surelevation' ) ),
	'liste non structurée'             => array( 'nature' => 'extension', 'projets_supplementaires' => 'extension' ),
	'projets trop nombreux'            => array( 'nature' => 'extension', 'projets_supplementaires' => array_fill( 0, 7, 'autre' ) ),
);

foreach ( $refus as $quoi => $clean ) {
	verifier( sprintf( 'refusé : %s', $quoi ), array() !== $metier->valider( $clean ) );
}

/**
 * Les deux adresses d'un dossier recevable.
 *
 * Le métier les exige désormais : un dossier qui ne les porterait pas serait
 * refusé pour une raison étrangère à ce que ces contrôles éprouvent — la
 * cohérence des natures de projet. Les fournir valides, c'est isoler ce qui est
 * réellement mesuré ici.
 *
 * @param array<string, mixed> $ajouts Ce que le contrôle impose.
 * @return array<string, mixed>
 */
function avec_adresses( array $ajouts = array() ): array {
	return array_merge(
		array(
			'mode_adresse_declarant' => 'automatique',
			'adresse_declarant'      => '10 Rue de Rivoli 75004 Paris',
			'insee_declarant'        => '75104',
			'cp_declarant'           => '75004',
			'ville_declarant'        => 'Paris',
			'mode_adresse'           => 'automatique',
			'terrain_adresse'        => '5 Avenue Anatole France 75007 Paris',
			'terrain_insee'          => '75107',
			'terrain_cp'             => '75007',
			'terrain_ville'          => 'Paris',
		),
		$ajouts
	);
}

$accepte = avec_adresses(
	array(
		'nature'                  => 'maison_individuelle',
		'projets_supplementaires' => array( 'extension', 'annexe_garage' ),
	)
);

verifier( 'accepté : un dossier cohérent', array() === $metier->valider( $accepte ) );

// La matrice des six : chaque nature, principale, doit passer.
foreach ( $natures as $id ) {
	verifier( sprintf( 'la nature « %s » est acceptée comme principale', $id ), array() === $metier->valider( avec_adresses( array( 'nature' => $id ) ) ) );
}

// Les descriptions n'ont aucune incidence : ni sur l'acceptation, ni sur le prix.
$avec_description = PricingPermisConstruire::compute(
	array( 'extension' ),
	array(
		'projets_supplementaires'         => array( 'surelevation' ),
		'description_projet_surelevation' => 'Surélévation d’un niveau',
	)
);

verifier( 'une description ne change pas le tarif', 749 === $avec_description['total'] );

/* ================================================================== *
 *  8. Le profil d'upload
 * ================================================================== */

echo "\n── 8. Le profil d’upload\n";

$profil = UploadProfileRegistry::for_type( 'permis_construire' );

verifier( 'un profil existe pour le PC', null !== $profil );
verifier( 'les blocs sont ceux du catalogue', CataloguePermisConstruire::blocs() === $profil->blocks );
verifier( 'les dépôts sont ouverts', true === $profil->uploads_enabled );
verifier( 'les formats admis sont les cinq attendus',
	array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ) === array_keys( $profil->types ) );
verifier( 'dix fichiers par bloc au plus', 10 === $profil->max_per_block );
verifier( 'vingt au total', 20 === $profil->max_total );
verifier( 'dix Mio par fichier', 10485760 === $profil->max_file_size );
verifier( 'vingt-cinq Mio cumulés', 26214400 === $profil->max_total_size );
verifier( 'un bloc hors catalogue est inconnu du profil', ! in_array( 'piece_inventee', $profil->blocks, true ) );
verifier( 'les noms restent bornés à 120 caractères', 120 === UploadPolicy::MAX_NAME_LENGTH );

// Le profil du PC et celui de la DP portent les mêmes blocs : c'est le même
// catalogue de pièces. Le vérifier interdit qu'ils divergent en silence.
$profil_dp = UploadProfileRegistry::for_type( 'declaration_prealable' );

verifier( 'les deux parcours partagent les mêmes blocs', $profil_dp->blocks === $profil->blocks );
verifier( 'mais chacun porte son propre identifiant', 'declaration_prealable' === $profil_dp->id && 'permis_construire' === $profil->id );

/* ================================================================== *
 *  Bilan
 * ================================================================== */

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
