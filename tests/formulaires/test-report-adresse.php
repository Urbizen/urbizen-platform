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
use Urbizen\Platform\Forms\Validator;

$brut = require $src . '/Forms/definitions/declaration_prealable.php';
$def  = new FormDefinition( $brut['type'], $brut['title'], $brut['submit_label'], $brut['fields'], $brut['steps'] );

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

/**
 * Un `$_POST` de déclaration préalable, tel que le navigateur le compose.
 *
 * @param array<string, mixed> $ajouts Ce que le scénario impose.
 * @return array<string, mixed>
 */
function poster( array $ajouts = array() ): array {
	return array_merge(
		array(
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
		$ajouts
	);
}

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
 * Rejoue la séquence d'adresse du contrôleur, dans son ordre exact.
 *
 * Reproduire l'ordre est le cœur du banc : filtrer le déclarant avant de le
 * juger, et purger le terrain avant d'y recopier, sont précisément ce qui
 * empêche une charge forgée d'aboutir.
 *
 * @param array<string, mixed> $post Charge brute.
 * @return array{valide:bool,clean:array,erreurs:array,ecarts:array}
 */
function pipeline( array $post ): array {
	global $def, $DECLARANT, $TERRAIN;

	$validation = Validator::validate( $def, $post );

	if ( ! $validation['valid'] ) {
		return array(
			'valide'  => false,
			'clean'   => $validation['clean'],
			'erreurs' => $validation['errors'],
			'ecarts'  => array(),
		);
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

	$metier = ( new ValidationMetierDeclarationPrealable() )->valider( $clean );

	if ( array() !== $metier ) {
		return array( 'valide' => false, 'clean' => $clean, 'erreurs' => $metier, 'ecarts' => $ecartes );
	}

	$clean = $TERRAIN->filtrer( $clean, $ecartes );

	return array( 'valide' => true, 'clean' => $clean, 'erreurs' => array(), 'ecarts' => $ecartes );
}

echo "\n── 1. La case telle que le validateur la rend\n";

// Ce que le banc précédent n'éprouvait pas : la FORME. Le navigateur envoie un
// scalaire, le validateur rend une liste, et c'est la liste qui compte.
$nettoyee = function ( $envoye ) use ( $def ) {
	$v = Validator::validate( $def, poster( array_merge( DECLARANT_AUTO, TERRAIN_AUTO, array( AdresseTerrain::REPORT => $envoye ) ) ) );

	return array( $v['valid'], $v['clean'][ AdresseTerrain::REPORT ] ?? '(absent)', $v['errors'][ AdresseTerrain::REPORT ] ?? '' );
};

list( $ok, $forme ) = $nettoyee( 'oui' );

verifier( 'le scalaire « oui » du navigateur devient une LISTE', is_array( $forme ) && array( 'oui' ) === $forme );
verifier( 'et cette liste est bien lue comme un report', AdresseTerrain::reportee( array( AdresseTerrain::REPORT => $forme ) ) );

echo "\n── 2. Seule la valeur canonique coche, sous toutes ses formes\n";

$cas = array(
	'absent'                 => array( array(), false ),
	'liste vide'             => array( array(), false ),
	"['oui']"                => array( array( 'oui' ), true ),
	"['1']"                  => array( array( '1' ), false ),
	"['true']"               => array( array( 'true' ), false ),
	"['on']"                 => array( array( 'on' ), false ),
	"['yes']"                => array( array( 'yes' ), false ),
	"['OUI']"                => array( array( 'OUI' ), false ),
	"['Oui']"                => array( array( 'Oui' ), false ),
	"[' oui']"               => array( array( ' oui' ), false ),
	"['non']"                => array( array( 'non' ), false ),
	"['oui','non']"          => array( array( 'oui', 'non' ), false ),
	"['non','oui']"          => array( array( 'non', 'oui' ), false ),
	"['oui','oui']"          => array( array( 'oui', 'oui' ), false ),
	"['oui', 'inattendu']"   => array( array( 'oui', 'inattendu' ), false ),
	'scalaire « oui »'       => array( 'oui', true ),
	'scalaire « 1 »'         => array( '1', false ),
	'booléen vrai'           => array( true, false ),
	'entier 1'               => array( 1, false ),
	'liste imbriquée'        => array( array( array( 'oui' ) ), false ),
);

foreach ( $cas as $nom => $attendu ) {
	list( $valeur, $vrai ) = $attendu;

	$charge = 'absent' === $nom ? array() : array( AdresseTerrain::REPORT => $valeur );

	verifier(
		sprintf( '%s %s', str_pad( $nom, 22 ), $vrai ? '→ report actif' : '→ report inactif' ),
		$vrai === AdresseTerrain::reportee( $charge )
	);
}

echo "\n── 3. Une case décochée ne laisse rien d'ambigu\n";

$r = pipeline( poster( array_merge( DECLARANT_AUTO, TERRAIN_AUTO ) ) );

verifier( 'la demande est recevable', $r['valide'] );
verifier( 'la clé de report a disparu de la charge', ! array_key_exists( AdresseTerrain::REPORT, $r['clean'] ) );
verifier( 'aucune liste vide n’est persistée', ! isset( $r['clean'][ AdresseTerrain::REPORT ] ) );
verifier( 'les deux adresses subsistent, distinctes',
	'10 Rue de Rivoli 75004 Paris' === ( $r['clean']['adresse_declarant'] ?? '' )
	&& '5 Avenue Anatole France 75007 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );

// Cochée, la clé porte la valeur canonique EN CLAIR : le dossier se relit sans
// avoir à redécouvrir que la liste vide voulait dire non.
$r = pipeline( poster( array_merge( DECLARANT_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'cochée, la clé porte « oui » en clair', 'oui' === ( $r['clean'][ AdresseTerrain::REPORT ] ?? '' ) );
verifier( 'et non une liste', ! is_array( $r['clean'][ AdresseTerrain::REPORT ] ?? null ) );

echo "\n── 4. Déclarant automatique + case cochée\n";

$r = pipeline( poster( array_merge( DECLARANT_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'la demande est recevable', $r['valide'] );
verifier( 'le terrain est reconstruit en automatique', 'automatique' === ( $r['clean']['mode_adresse'] ?? '' ) );
verifier( 'avec l’adresse du déclarant', '10 Rue de Rivoli 75004 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );
verifier( 'son code postal', '75004' === ( $r['clean']['terrain_cp'] ?? '' ) );
verifier( 'sa commune', 'Paris' === ( $r['clean']['terrain_ville'] ?? '' ) );
verifier( 'son code commune', '75104' === ( $r['clean']['terrain_insee'] ?? '' ) );
verifier( 'aucun champ du mode manuel', ! isset( $r['clean']['terrain_voie'], $r['clean']['terrain_complement'] ) );

echo "\n── 5. Déclarant manuel + case cochée\n";

$r = pipeline( poster( array_merge( DECLARANT_MANUEL, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'la demande est recevable', $r['valide'] );
verifier( 'le terrain est reconstruit en manuel', 'manuel' === ( $r['clean']['mode_adresse'] ?? '' ) );
verifier( 'avec la voie du déclarant', 'Lieu-dit Les Vignes' === ( $r['clean']['terrain_voie'] ?? '' ) );
verifier( 'et son complément', 'Bâtiment B' === ( $r['clean']['terrain_complement'] ?? '' ) );
verifier( 'son code postal', '20000' === ( $r['clean']['terrain_cp'] ?? '' ) );
verifier( 'sa commune', 'Ajaccio' === ( $r['clean']['terrain_ville'] ?? '' ) );
verifier( 'aucun code commune', ! isset( $r['clean']['terrain_insee'] ) );
verifier( 'aucune coordonnée', ! isset( $r['clean']['terrain_lat'], $r['clean']['terrain_lon'] ) );
verifier( 'aucun libellé de service', ! isset( $r['clean']['terrain_adresse'] ) );

echo "\n── 6. La précision des coordonnées est conservée\n";

$r = pipeline( poster( array_merge( DECLARANT_AUTO, TERRAIN_AUTO ) ) );

verifier( 'la latitude du déclarant garde ses décimales', '48.8555' === ( $r['clean']['lat_declarant'] ?? '' ) );
verifier( 'sa longitude aussi', '2.36041' === ( $r['clean']['lon_declarant'] ?? '' ) );
verifier( 'la latitude du terrain garde les siennes', '48.858819' === ( $r['clean']['terrain_lat'] ?? '' ) );
verifier( 'sa longitude aussi', '2.294597' === ( $r['clean']['terrain_lon'] ?? '' ) );
verifier( 'aucune n’est ramenée au centième',
	'48.86' !== ( $r['clean']['lat_declarant'] ?? '' ) && '2.36' !== ( $r['clean']['lon_declarant'] ?? '' ) );

// Reportées, les coordonnées du déclarant deviennent celles du terrain, sans
// perdre un chiffre au passage.
$r = pipeline( poster( array_merge( DECLARANT_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'le report conserve la latitude', '48.8555' === ( $r['clean']['terrain_lat'] ?? '' ) );
verifier( 'et la longitude', '2.36041' === ( $r['clean']['terrain_lon'] ?? '' ) );

/*
 * Les coordonnées vont par deux, même reportées.
 *
 * Le filtrage précède le jugement, dans le contrôleur comme ici : une
 * coordonnée orpheline est donc **écartée**, pas refusée. C'est délibéré — un
 * reliquat de saisie n'est pas une attaque, et refuser la demande pour cela
 * punirait une hésitation. Ce qui compte est qu'elle ne survive nulle part :
 * ni chez le déclarant, ni recopiée sur le terrain.
 */
$orpheline = DECLARANT_AUTO;
unset( $orpheline['lon_declarant'] );
$r = pipeline( poster( array_merge( $orpheline, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'la demande reste recevable', $r['valide'] );
verifier( 'la latitude orpheline est écartée du déclarant', ! isset( $r['clean']['lat_declarant'] ) );
verifier( 'et l’écart est consigné', in_array( 'lat_declarant', $r['ecarts'], true ) );
verifier( 'aucune coordonnée n’est recopiée sur le terrain',
	! isset( $r['clean']['terrain_lat'], $r['clean']['terrain_lon'] ) );
verifier( 'le reste de l’adresse est bien reporté', '75104' === ( $r['clean']['terrain_insee'] ?? '' ) );

echo "\n── 6 bis. Les autres nombres n'ont pas bougé\n";

/*
 * La précision vient désormais du pas déclaré. Ce banc prouve que cela n'a
 * déplacé QUE les coordonnées : la déclaration préalable compte trois familles
 * de nombres, et deux d'entre elles doivent se comporter exactement comme
 * avant. Sans cette preuve, la correction d'un champ serait une régression
 * silencieuse sur quinze autres.
 */
$mesures = Validator::validate(
	$def,
	poster(
		array_merge(
			DECLARANT_AUTO,
			TERRAIN_AUTO,
			array(
				'nature'              => 'piscine',
				'intervention'        => 'nouvelle',
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
// Les comptages ne rendent pas le même type que les mesures : `clean_entier`
// rend un `int`, `clean_decimal` une chaîne canonique. C'est antérieur à la
// précision par champ, et cela ne doit pas changer.
verifier( 'une superficie entière reste un entier', 450 === ( $c['terrain_superficie'] ?? null ) );
verifier( 'et les coordonnées, elles, gardent six décimales',
	'48.8555' === ( $c['lat_declarant'] ?? '' ) && '48.858819' === ( $c['terrain_lat'] ?? '' ) );

// Le pas déclaré est la seule source : un champ sans pas fractionnaire retombe
// sur le défaut, et un champ au pas entier ne passe même pas par là.
$familles = array();

foreach ( $def->fields() as $f ) {
	if ( 'number' !== ( $f['type'] ?? '' ) ) {
		continue;
	}

	$pas                 = (float) ( $f['increment'] ?? 0.01 );
	$familles[ (string) $pas ][] = $f['name'];
}

verifier( 'la définition ne connaît que trois familles de nombres', 3 === count( $familles ) );
verifier( 'et seules les coordonnées demandent le millionième',
	array( 'lat_declarant', 'lon_declarant', 'terrain_lat', 'terrain_lon' ) === ( $familles['1.0E-6'] ?? array() ) );

echo "\n── 7. La charge forgée n'atteint pas le dossier\n";

// Case cochée ET adresse de terrain concurrente : le terrain envoyé doit
// disparaître entièrement, remplacé par la copie du déclarant.
$r = pipeline( poster( array_merge( DECLARANT_AUTO, TERRAIN_AUTO, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'la demande reste recevable', $r['valide'] );
verifier( 'l’adresse forgée a disparu', '5 Avenue Anatole France 75007 Paris' !== ( $r['clean']['terrain_adresse'] ?? '' ) );
verifier( 'remplacée par celle du déclarant', '10 Rue de Rivoli 75004 Paris' === ( $r['clean']['terrain_adresse'] ?? '' ) );
verifier( 'le code commune forgé aussi', '75104' === ( $r['clean']['terrain_insee'] ?? '' ) );
verifier( 'les coordonnées forgées aussi', '48.8555' === ( $r['clean']['terrain_lat'] ?? '' ) );
verifier( 'et l’écart est consigné', in_array( 'terrain_adresse', $r['ecarts'], true ) );

// Deux modes déclarant envoyés ensemble : le mode inactif ne doit jamais
// alimenter la copie.
$deux = array_merge( DECLARANT_AUTO, array( 'voie_declarant' => 'Concurrente', 'complement_declarant' => 'Bis', AdresseTerrain::REPORT => 'oui' ) );
$r    = pipeline( poster( $deux ) );

verifier( 'les deux modes déclarant : le mode inactif est écarté',
	! isset( $r['clean']['voie_declarant'], $r['clean']['complement_declarant'] ) );
verifier( 'et n’a pas alimenté le terrain', ! isset( $r['clean']['terrain_voie'] ) );
verifier( 'le terrain porte bien le mode actif', 'automatique' === ( $r['clean']['mode_adresse'] ?? '' ) );

// Une valeur hors liste ne coche pas — et le socle la refuse même avant.
$r = pipeline( poster( array_merge( DECLARANT_AUTO, TERRAIN_AUTO, array( AdresseTerrain::REPORT => '1' ) ) ) );

verifier( 'une valeur hors liste est refusée par le socle', ! $r['valide'] );
verifier( 'et nommée « hors_liste »', 'hors_liste' === ( $r['erreurs'][ AdresseTerrain::REPORT ] ?? '' ) );

echo "\n── 8. Déclarant invalide + case cochée : une seule série d'erreurs\n";

$creux = DECLARANT_AUTO;
unset( $creux['insee_declarant'] );
$r = pipeline( poster( array_merge( $creux, array( AdresseTerrain::REPORT => 'oui' ) ) ) );

verifier( 'la demande est refusée', ! $r['valide'] );
verifier( 'l’erreur porte sur le déclarant', isset( $r['erreurs']['insee_declarant'] ) );
verifier( 'et aucune erreur n’est doublonnée sous le terrain',
	array() === array_filter( array_keys( $r['erreurs'] ), static fn( $k ) => str_starts_with( $k, 'terrain_' ) || 'mode_adresse' === $k ) );

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
