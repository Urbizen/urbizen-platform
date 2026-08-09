<?php
/**
 * Le report « même adresse que le déclarant », éprouvé sur la forme réelle.
 *
 * Ce banc existe parce que les précédents ont laissé passer une panne complète.
 * Ils appelaient `AdresseTerrain::reportee()` avec un **scalaire** — la forme
 * que le navigateur envoie — alors que le serveur ne voit jamais cette
 * forme-là : `Validator::clean_liste()` transforme tout champ `checkbox` en
 * **liste**, même à case unique. La lecture scalaire ne levait aucune erreur,
 * elle rendait simplement « non » en toutes circonstances, et le report ne
 * s'activait jamais. Une recette de production l'a découvert ; un banc aurait
 * dû le faire.
 *
 * D'où la règle que ce fichier applique : **rien n'est injecté à la main**. Les
 * charges partent d'un `$_POST` brut et traversent le vrai `Validator` avec la
 * vraie définition, puis la séquence d'adresse du contrôleur, dans son ordre.
 * Ce qui est éprouvé ici est ce qui arrivera en production, pas une idée de ce
 * qui devrait arriver.
 *
 * **Les deux parcours sont éprouvés par le même code.** Déclaration préalable
 * et permis de construire posent la même question et partagent la même
 * fabrique ; les recopier aurait suffi à les faire diverger. Ce qui leur est
 * propre — natures, interventions, chemin vers les mesures — tient dans une
 * table, et tout le reste est commun.
 *
 * Usage : php tests/formulaires/test-report-adresse.php
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

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Doublure d'échappement : l'accusé client en dépend.
	 *
	 * @param string $texte Texte.
	 * @return string
	 */
	function esc_html( $texte ) { // phpcs:ignore
		return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' );
	}
}

$racine = dirname( __DIR__, 2 );
$src    = $racine . '/wordpress/urbizen-platform/src';

spl_autoload_register(
	static function ( $classe ) use ( $src ) {
		$fichier = $src . '/' . str_replace( '\\', '/', str_replace( 'Urbizen\\Platform\\', '', $classe ) ) . '.php';

		if ( file_exists( $fichier ) ) {
			require $fichier;
		}
	}
);

use Urbizen\Platform\Forms\AdresseTerrain;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\ValidationMetierDeclarationPrealable;
use Urbizen\Platform\Forms\ValidationMetierPermisConstruire;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Mail\CustomerAcknowledgementRenderer;

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

	printf( "%-74s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

$DECLARANT = AdresseTerrain::pour( AdresseTerrain::DECLARANT );
$TERRAIN   = AdresseTerrain::pour( AdresseTerrain::TERRAIN );

/** Le déclarant en mode automatique, coordonnées à six décimales. */
const DECLARANT_AUTO = array(
	'mode_adresse_declarant' => 'automatique',
	'adresse_declarant'      => '10 Rue de Rivoli 75004 Paris',
	'insee_declarant'        => '75104',
	'cp_declarant'           => '75004',
	'ville_declarant'        => 'Paris',
	'lat_declarant'          => '48.855500',
	'lon_declarant'          => '2.360410',
);

/** Le déclarant en mode manuel. */
const DECLARANT_MANUEL = array(
	'mode_adresse_declarant' => 'manuel',
	'voie_declarant'         => 'Lieu-dit Les Vignes',
	'complement_declarant'   => 'Bâtiment B',
	'cp_declarant'           => '20000',
	'ville_declarant'        => 'Ajaccio',
);

/** Un terrain propre, en automatique, distinct du déclarant. */
const TERRAIN_AUTO = array(
	'mode_adresse'    => 'automatique',
	'terrain_adresse' => '5 Avenue Anatole France 75007 Paris',
	'terrain_insee'   => '75107',
	'terrain_cp'      => '75007',
	'terrain_ville'   => 'Paris',
	'terrain_lat'     => '48.858819',
	'terrain_lon'     => '2.294597',
);

/**
 * Ce qui distingue les deux parcours, et rien d'autre.
 *
 * @var array<string, array<string, mixed>>
 */
$PARCOURS = array(
	'declaration_prealable' => array(
		'metier'  => ValidationMetierDeclarationPrealable::class,
		'base'    => array(
			'declarant_type' => 'particulier',
			'nom'            => 'Martin',
			'prenom'         => 'Claire',
			'qualite'        => 'proprietaire',
			'email'          => 'claire.martin@example.com',
			'telephone'      => '0600000000',
			'nature'         => 'cloture_mur',
			'intervention'   => 'existant',
			'description'    => 'Projet décrit par le demandeur.',
			'abf'            => 'non',
			'demolition'     => 'non',
			'attest_exact'   => '1',
			'attest_rgpd'    => '1',
		),
		// La DP attache ses mesures de bassin à la nature « piscine ».
		'mesures' => array( 'nature' => 'piscine', 'intervention' => 'nouvelle' ),
		// La chaîne conditionnelle propre au parcours, et son déclencheur.
		'chaine'  => array( 'nature' => 'piscine', 'intervention' => 'nouvelle' ),
	),
	'permis_construire'     => array(
		'metier'  => ValidationMetierPermisConstruire::class,
		'base'    => array(
			'declarant_type' => 'particulier',
			'nom'            => 'Martin',
			'prenom'         => 'Claire',
			'qualite'        => 'proprietaire',
			'email'          => 'claire.martin@example.com',
			'telephone'      => '0600000000',
			'nature'         => 'extension',
			'intervention'   => 'existant',
			// Le banc porte sur les adresses. Deux mesures cohérentes avec un PC
			// empêchent le contrôle du régime d'en masquer le résultat.
			'sp_creee'       => 60,
			'emprise_creee'  => 60,
			'description'    => 'Projet décrit par le demandeur.',
			'abf'            => 'non',
			'demolition'     => 'non',
			'attest_exact'   => '1',
			'attest_rgpd'    => '1',
		),
		// Le PC, lui, pose une question dédiée : la piscine est une option du
		// projet, pas sa nature.
		'mesures' => array( 'piscine_prevue' => 'oui' ),
		'chaine'  => array( 'piscine_prevue' => 'oui' ),
	),
);

/**
 * Rejoue la séquence d'adresse du contrôleur, dans son ordre exact.
 *
 * Reproduire l'ordre est le cœur du banc : filtrer le déclarant avant de le
 * juger, et purger le terrain avant d'y recopier, sont précisément ce qui
 * empêche une charge forgée d'aboutir.
 *
 * @param FormDefinition       $def    Définition du parcours.
 * @param string               $metier Classe de validation métier.
 * @param array<string, mixed> $post   Charge brute.
 * @return array{valide:bool,clean:array,erreurs:array,ecarts:array}
 */
function pipeline( FormDefinition $def, string $metier, array $post ): array {
	global $DECLARANT, $TERRAIN;

	$validation = Validator::validate( $def, $post );

	if ( ! $validation['valid'] ) {
		return array( 'valide' => false, 'clean' => $validation['clean'], 'erreurs' => $validation['errors'], 'ecarts' => array() );
	}

	$ecartes = array();
	$clean   = $DECLARANT->filtrer( $validation['clean'], $ecartes );

	$erreurs = $DECLARANT->verifier( $clean, true );

	if ( array() !== $erreurs ) {
		return array( 'valide' => false, 'clean' => $clean, 'erreurs' => $erreurs, 'ecarts' => $ecartes );
	}

	$clean = AdresseTerrain::normaliser_report( $clean );

	if ( AdresseTerrain::reportee( $clean ) ) {
		$reporte = $DECLARANT->exporter( $clean );
		$clean   = $TERRAIN->purger( $clean, $ecartes );
		$clean   = $TERRAIN->importer( $clean, $reporte );
	}

	$erreurs_metier = ( new $metier() )->valider( $clean );

	if ( array() !== $erreurs_metier ) {
		return array( 'valide' => false, 'clean' => $clean, 'erreurs' => $erreurs_metier, 'ecarts' => $ecartes );
	}

	$clean = $TERRAIN->filtrer( $clean, $ecartes );

	return array( 'valide' => true, 'clean' => $clean, 'erreurs' => array(), 'ecarts' => $ecartes );
}

/* ================================================================== *
 *  Ce qui ne dépend d'aucun parcours
 * ================================================================== */

echo "\n══ Lecture de la case, indépendamment du parcours ══\n";

$cas = array(
	'absent'               => array( array(), false ),
	'liste vide'           => array( array(), false ),
	"['oui']"              => array( array( 'oui' ), true ),
	"['1']"                => array( array( '1' ), false ),
	"['true']"             => array( array( 'true' ), false ),
	"['on']"               => array( array( 'on' ), false ),
	"['yes']"              => array( array( 'yes' ), false ),
	"['OUI']"              => array( array( 'OUI' ), false ),
	"['Oui']"              => array( array( 'Oui' ), false ),
	"[' oui']"             => array( array( ' oui' ), false ),
	"['non']"              => array( array( 'non' ), false ),
	"['oui','non']"        => array( array( 'oui', 'non' ), false ),
	"['non','oui']"        => array( array( 'non', 'oui' ), false ),
	"['oui','oui']"        => array( array( 'oui', 'oui' ), false ),
	"['oui','inattendu']"  => array( array( 'oui', 'inattendu' ), false ),
	'scalaire « oui »'     => array( 'oui', true ),
	'scalaire « 1 »'       => array( '1', false ),
	'booléen vrai'         => array( true, false ),
	'entier 1'             => array( 1, false ),
	'liste imbriquée'      => array( array( array( 'oui' ) ), false ),
);

foreach ( $cas as $nom => $attendu ) {
	list( $valeur, $vrai ) = $attendu;

	$charge = 'absent' === $nom ? array() : array( AdresseTerrain::REPORT => $valeur );

	verifier(
		sprintf( '%s %s', str_pad( $nom, 22 ), $vrai ? '→ report actif' : '→ report inactif' ),
		$vrai === AdresseTerrain::reportee( $charge )
	);
}

verifier( 'normaliser rend la valeur canonique en clair',
	'oui' === ( AdresseTerrain::normaliser_report( array( AdresseTerrain::REPORT => array( 'oui' ) ) )[ AdresseTerrain::REPORT ] ?? '' ) );
verifier( 'et retire une liste vide',
	! array_key_exists( AdresseTerrain::REPORT, AdresseTerrain::normaliser_report( array( AdresseTerrain::REPORT => array() ) ) ) );

echo "\n══ Charges historiques : les anciennes demandes restent lisibles ══\n";

/*
 * Des demandes antérieures à cette tranche portent une adresse en texte plat,
 * sans mode, sans code commune et sans coordonnées. Elles doivent continuer de
 * se lire — la compatibilité porte sur la LECTURE, jamais sur ce que le contrat
 * exige des nouvelles soumissions.
 */
$historique = array(
	'adresse_declarant' => '12 rue des Lilas',
	'cp_declarant'      => '33000',
	'ville_declarant'   => 'Bordeaux',
	'terrain_adresse'   => '5 chemin du Moulin',
	'terrain_cp'        => '33200',
	'terrain_ville'     => 'Bordeaux',
	'cad_section'       => 'AB',
);

verifier( 'historique · l’adresse du déclarant reste visible', $DECLARANT->existe( $historique ) );
verifier( 'historique · celle du terrain aussi', $TERRAIN->existe( $historique ) );
verifier( 'historique · le déclarant se lit sur deux lignes',
	array( '12 rue des Lilas', '33000 Bordeaux' ) === $DECLARANT->lignes_adresse( $historique ) );
verifier( 'historique · le terrain aussi',
	array( '5 chemin du Moulin', '33200 Bordeaux' ) === $TERRAIN->lignes_adresse( $historique ) );
verifier( 'historique · aucune provenance n’est inventée',
	'' === $DECLARANT->provenance( $historique ) && '' === $TERRAIN->provenance( $historique ) );
verifier( 'historique · aucun code commune fantôme',
	array() === $DECLARANT->reperes( $historique ) && array() === $TERRAIN->reperes( $historique ) );
verifier( 'historique · aucune coordonnée fantôme',
	! isset( $DECLARANT->reperes( $historique )['Coordonnées'], $TERRAIN->reperes( $historique )['Coordonnées'] ) );
verifier( 'historique · le report n’est pas réputé actif', ! AdresseTerrain::reportee( $historique ) );
verifier( 'historique · le filtrage laisse la charge intacte',
	$historique === $TERRAIN->filtrer( $DECLARANT->filtrer( $historique ) ) );
verifier( 'historique · le cadastre n’est pas assumé par l’adresse', ! AdresseTerrain::porte( 'cad_section' ) );

// L'accusé client d'une demande historique : deux adresses, aucun rouage.
$vue    = new ReflectionMethod( CustomerAcknowledgementRenderer::class, 'adresse' );
$vue->setAccessible( true );
$accuse = $vue->invoke( null, $historique );

verifier( 'historique · l’accusé porte les deux titres',
	str_contains( $accuse, 'Adresse du déclarant' ) && str_contains( $accuse, 'Adresse du terrain' ) );
verifier( 'historique · et les deux adresses',
	str_contains( $accuse, '12 rue des Lilas' ) && str_contains( $accuse, '5 chemin du Moulin' ) );
verifier( 'historique · aucun nom de champ technique',
	! str_contains( $accuse, 'terrain_' ) && ! str_contains( $accuse, '_declarant' ) );
verifier( 'historique · aucun mode technique',
	! str_contains( $accuse, 'automatique' ) && ! str_contains( $accuse, 'manuel' ) );
verifier( 'historique · aucun titre commun trompeur',
	! str_contains( $accuse, 'Adresse du déclarant et du terrain' ) );

/* ================================================================== *
 *  Ce que chaque parcours doit tenir
 * ================================================================== */

foreach ( $PARCOURS as $type => $p ) {
	$brut = require $src . '/Forms/definitions/' . $type . '.php';
	$def  = new FormDefinition( $brut['type'], $brut['title'], $brut['submit_label'], $brut['fields'], $brut['steps'] );

	/**
	 * Un `$_POST` du parcours courant, tel que le navigateur le compose.
	 *
	 * @param array<string, mixed> $ajouts Ce que le scénario impose.
	 * @return array<string, mixed>
	 */
	$poster = static function ( array $ajouts = array() ) use ( $p ): array {
		return array_merge( $p['base'], $ajouts );
	};

	$jouer = static function ( array $ajouts = array() ) use ( $def, $p, $poster ): array {
		return pipeline( $def, $p['metier'], $poster( $ajouts ) );
	};

	echo "\n══════════ $type ══════════\n";

	echo "\n── 1. La case telle que le validateur la rend\n";

	$v = Validator::validate( $def, $poster( array_merge( DECLARANT_AUTO, TERRAIN_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

	verifier( 'le scalaire « oui » du navigateur devient une LISTE',
		is_array( $v['clean'][ AdresseTerrain::REPORT ] ?? null ) && array( 'oui' ) === $v['clean'][ AdresseTerrain::REPORT ] );
	verifier( 'et cette liste est bien lue comme un report', AdresseTerrain::reportee( $v['clean'] ) );

	echo "\n── 2. Une case décochée ne laisse rien d'ambigu\n";

	$r = $jouer( array_merge( DECLARANT_AUTO, TERRAIN_AUTO ) );

	verifier( 'la demande est recevable', $r['valide'] );
	verifier( 'la clé de report a disparu de la charge', ! array_key_exists( AdresseTerrain::REPORT, $r['clean'] ) );
	verifier( 'les deux adresses subsistent, distinctes',
		'10 Rue de Rivoli 75004 Paris' === ( $r['clean']['adresse_declarant'] ?? '' )
		&& '5 Avenue Anatole France 75007 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );

	$r = $jouer( array_merge( DECLARANT_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'cochée, la clé porte « oui » en clair', 'oui' === ( $r['clean'][ AdresseTerrain::REPORT ] ?? '' ) );
	verifier( 'et non une liste', ! is_array( $r['clean'][ AdresseTerrain::REPORT ] ?? null ) );

	echo "\n── 3. Déclarant automatique + case cochée\n";

	verifier( 'la demande est recevable', $r['valide'] );
	verifier( 'le terrain est reconstruit en automatique', 'automatique' === ( $r['clean']['mode_adresse'] ?? '' ) );
	verifier( 'avec l’adresse du déclarant', '10 Rue de Rivoli 75004 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );
	verifier( 'son code postal', '75004' === ( $r['clean']['terrain_cp'] ?? '' ) );
	verifier( 'sa commune', 'Paris' === ( $r['clean']['terrain_ville'] ?? '' ) );
	verifier( 'son code commune', '75104' === ( $r['clean']['terrain_insee'] ?? '' ) );
	verifier( 'aucun champ du mode manuel', ! isset( $r['clean']['terrain_voie'], $r['clean']['terrain_complement'] ) );

	echo "\n── 4. Déclarant manuel + case cochée\n";

	$r = $jouer( array_merge( DECLARANT_MANUEL, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'la demande est recevable', $r['valide'] );
	verifier( 'le terrain est reconstruit en manuel', 'manuel' === ( $r['clean']['mode_adresse'] ?? '' ) );
	verifier( 'avec la voie du déclarant', 'Lieu-dit Les Vignes' === ( $r['clean']['terrain_voie'] ?? '' ) );
	verifier( 'et son complément', 'Bâtiment B' === ( $r['clean']['terrain_complement'] ?? '' ) );
	verifier( 'son code postal', '20000' === ( $r['clean']['terrain_cp'] ?? '' ) );
	verifier( 'sa commune', 'Ajaccio' === ( $r['clean']['terrain_ville'] ?? '' ) );
	verifier( 'aucun code commune', ! isset( $r['clean']['terrain_insee'] ) );
	verifier( 'aucune coordonnée', ! isset( $r['clean']['terrain_lat'], $r['clean']['terrain_lon'] ) );
	verifier( 'aucun libellé de service', ! isset( $r['clean']['terrain_adresse'] ) );

	echo "\n── 5. La précision des coordonnées est conservée\n";

	$r = $jouer( array_merge( DECLARANT_AUTO, TERRAIN_AUTO ) );

	verifier( 'la latitude du déclarant garde ses décimales', '48.8555' === ( $r['clean']['lat_declarant'] ?? '' ) );
	verifier( 'sa longitude aussi', '2.36041' === ( $r['clean']['lon_declarant'] ?? '' ) );
	verifier( 'la latitude du terrain garde les siennes', '48.858819' === ( $r['clean']['terrain_lat'] ?? '' ) );
	verifier( 'sa longitude aussi', '2.294597' === ( $r['clean']['terrain_lon'] ?? '' ) );
	verifier( 'aucune n’est ramenée au centième',
		'48.86' !== ( $r['clean']['lat_declarant'] ?? '' ) && '2.36' !== ( $r['clean']['lon_declarant'] ?? '' ) );

	$r = $jouer( array_merge( DECLARANT_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'le report conserve la latitude', '48.8555' === ( $r['clean']['terrain_lat'] ?? '' ) );
	verifier( 'et la longitude', '2.36041' === ( $r['clean']['terrain_lon'] ?? '' ) );

	/*
	 * Le filtrage précède le jugement : une coordonnée orpheline est **écartée**,
	 * pas refusée. C'est délibéré — un reliquat de saisie n'est pas une attaque.
	 * Ce qui compte est qu'elle ne survive nulle part.
	 */
	$orpheline = DECLARANT_AUTO;
	unset( $orpheline['lon_declarant'] );
	$r = $jouer( array_merge( $orpheline, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'la demande reste recevable', $r['valide'] );
	verifier( 'la latitude orpheline est écartée', ! isset( $r['clean']['lat_declarant'] ) );
	verifier( 'et l’écart est consigné', in_array( 'lat_declarant', $r['ecarts'], true ) );
	verifier( 'aucune coordonnée n’est recopiée sur le terrain',
		! isset( $r['clean']['terrain_lat'], $r['clean']['terrain_lon'] ) );
	verifier( 'le reste de l’adresse est bien reporté', '75104' === ( $r['clean']['terrain_insee'] ?? '' ) );

	echo "\n── 6. Les autres nombres n'ont pas bougé\n";

	$mesures = Validator::validate(
		$def,
		$poster(
			array_merge(
				DECLARANT_AUTO,
				TERRAIN_AUTO,
				$p['mesures'],
				array(
					'longueur_bassin_m'   => '8.567',
					'largeur_bassin_m'    => '4,239',
					'profondeur_bassin_m' => '1.999',
					'terrain_superficie'  => '450',
				)
			)
		)
	);

	$c = $mesures['clean'];

	verifier( 'une longueur reste au centième', '8.57' === ( $c['longueur_bassin_m'] ?? '' ) );
	verifier( 'une largeur écrite à la virgule aussi', '4.24' === ( $c['largeur_bassin_m'] ?? '' ) );
	verifier( 'une profondeur aussi', '2' === ( $c['profondeur_bassin_m'] ?? '' ) );
	verifier( 'une superficie entière reste un entier', 450 === ( $c['terrain_superficie'] ?? null ) );
	verifier( 'et les coordonnées gardent six décimales',
		'48.8555' === ( $c['lat_declarant'] ?? '' ) && '48.858819' === ( $c['terrain_lat'] ?? '' ) );

	$familles = array();

	foreach ( $def->fields() as $f ) {
		if ( 'number' !== ( $f['type'] ?? '' ) ) {
			continue;
		}

		$familles[ (string) (float) ( $f['increment'] ?? 0.01 ) ][] = $f['name'];
	}

	verifier( 'seules les coordonnées demandent le millionième',
		array( 'lat_declarant', 'lon_declarant', 'terrain_lat', 'terrain_lon' ) === ( $familles['1.0E-6'] ?? array() ) );

	echo "\n── 7. La charge forgée n'atteint pas le dossier\n";

	$r = $jouer( array_merge( DECLARANT_AUTO, TERRAIN_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'la demande reste recevable', $r['valide'] );
	verifier( 'l’adresse forgée a disparu', '5 Avenue Anatole France 75007 Paris' !== ( $r['clean']['terrain_adresse'] ?? '' ) );
	verifier( 'remplacée par celle du déclarant', '10 Rue de Rivoli 75004 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );
	verifier( 'le code commune forgé aussi', '75104' === ( $r['clean']['terrain_insee'] ?? '' ) );
	verifier( 'les coordonnées forgées aussi', '48.8555' === ( $r['clean']['terrain_lat'] ?? '' ) );
	verifier( 'et l’écart est consigné', in_array( 'terrain_adresse', $r['ecarts'], true ) );

	$deux = array_merge( DECLARANT_AUTO, array( 'voie_declarant' => 'Concurrente', 'complement_declarant' => 'Bis', AdresseTerrain::REPORT => 'oui' ) );
	$r    = $jouer( $deux );

	verifier( 'les deux modes déclarant : le mode inactif est écarté',
		! isset( $r['clean']['voie_declarant'], $r['clean']['complement_declarant'] ) );
	verifier( 'et n’a pas alimenté le terrain', ! isset( $r['clean']['terrain_voie'] ) );

	$r = $jouer( array_merge( DECLARANT_AUTO, TERRAIN_AUTO, array( AdresseTerrain::REPORT => '1' ) ) );

	verifier( 'une valeur hors liste est refusée par le socle', ! $r['valide'] );
	verifier( 'et nommée « hors_liste »', 'hors_liste' === ( $r['erreurs'][ AdresseTerrain::REPORT ] ?? '' ) );

	echo "\n── 8. Le cadastre ne suit jamais l'adresse\n";

	/*
	 * La case ne concerne QUE le composant d'adresse. Les références
	 * cadastrales décrivent la parcelle ; elles ne se déduisent pas du lieu où
	 * le demandeur reçoit son courrier, et ne doivent ni être recopiées depuis
	 * le déclarant, ni partir avec l'adresse purgée.
	 */
	$cadastre = array(
		'cad_section'                        => 'AB',
		'cad_numero'                         => '0142',
		'terrain_superficie'                 => '450',
		'informations_cadastrales_differees' => 'non',
	);

	$r = $jouer( array_merge( DECLARANT_AUTO, $cadastre, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'la demande est recevable', $r['valide'] );
	verifier( 'l’adresse du terrain est bien reconstruite', '10 Rue de Rivoli 75004 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );
	verifier( 'la section cadastrale est intacte', 'AB' === ( $r['clean']['cad_section'] ?? '' ) );
	verifier( 'le numéro de parcelle aussi', '0142' === ( $r['clean']['cad_numero'] ?? '' ) );
	verifier( 'la superficie aussi', 450 === ( $r['clean']['terrain_superficie'] ?? null ) );
	verifier( 'le report cadastral aussi', array( 'non' ) === ( $r['clean']['informations_cadastrales_differees'] ?? null ) );
	verifier( 'aucun champ cadastral n’est consigné comme écart',
		array() === array_intersect( array( 'cad_section', 'cad_numero', 'terrain_superficie' ), $r['ecarts'] ) );

	// Et la purge, prise seule, ne touche qu'aux neuf rôles d'adresse.
	$avant = array_merge( TERRAIN_AUTO, $cadastre, array( 'terrain_etat' => 'Terrain en pente douce.' ) );
	$ec    = array();
	$apres = $TERRAIN->purger( $avant, $ec );

	verifier( 'la purge laisse le cadastre entier',
		'AB' === ( $apres['cad_section'] ?? '' ) && '0142' === ( $apres['cad_numero'] ?? '' ) );
	verifier( 'et la superficie', '450' === ( $apres['terrain_superficie'] ?? '' ) );
	verifier( 'et l’état du terrain', 'Terrain en pente douce.' === ( $apres['terrain_etat'] ?? '' ) );
	verifier( 'mais retire toute l’adresse', array() === array_intersect( $TERRAIN->champs(), array_keys( $apres ) ) );

	echo "\n── 9. La chaîne conditionnelle du parcours est inchangée\n";

	/*
	 * L'arrivée des deux blocs d'adresse ne doit rien changer à la chaîne
	 * piscine → abri → hauteur, que `MatriceChamps` sécurise. Une hauteur
	 * déclarée sans abri reste écartée, adresse ou pas.
	 */
	$avec_abri = $jouer(
		array_merge(
			DECLARANT_AUTO,
			$p['chaine'],
			array( AdresseTerrain::REPORT => 'oui', 'presence_abri_piscine' => 'oui', 'hauteur_abri_m' => '2.5' )
		)
	);

	verifier( 'un abri déclaré avec sa piscine garde sa hauteur', '2.5' === ( $avec_abri['clean']['hauteur_abri_m'] ?? '' ) );
	verifier( 'et l’adresse reste reconstruite', '10 Rue de Rivoli 75004 Paris' === ( $avec_abri['clean']['terrain_adresse'] ?? '' ) );

	$sans_abri = Validator::validate(
		$def,
		$poster( array_merge( DECLARANT_AUTO, array( 'hauteur_abri_m' => '2.5' ) ) )
	);

	verifier( 'une hauteur déclarée sans abri n’est pas retenue',
		! array_key_exists( 'hauteur_abri_m', $sans_abri['clean'] ) || '' === $sans_abri['clean']['hauteur_abri_m'] );

	echo "\n── 10. Déclarant invalide + case cochée : une seule série d'erreurs\n";

	$creux = DECLARANT_AUTO;
	unset( $creux['insee_declarant'] );
	$r = $jouer( array_merge( $creux, array( AdresseTerrain::REPORT => 'oui' ) ) );

	verifier( 'la demande est refusée', ! $r['valide'] );
	verifier( 'l’erreur porte sur le déclarant', isset( $r['erreurs']['insee_declarant'] ) );
	verifier( 'et aucune erreur n’est doublonnée sous le terrain',
		array() === array_filter( array_keys( $r['erreurs'] ), static fn( $k ) => str_starts_with( $k, 'terrain_' ) || 'mode_adresse' === $k ) );
}

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
