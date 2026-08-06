<?php
/**
 * L'adresse du terrain : un mode, une adresse, et rien d'autre.
 *
 * Une demande peut arriver avec les deux jeux de champs — celui de la recherche
 * assistée et celui de la saisie manuelle. Le navigateur désactive le mode
 * abandonné, donc il ne part pas ; mais une charge forgée les enverrait tous.
 *
 * Ce banc défend un invariant : **une demande ne porte qu'une adresse, et on
 * sait toujours d'où elle vient**. Un mode absent ou inventé refuse la demande
 * plutôt que d'en choisir une à la place du demandeur.
 *
 * Usage : php tests/formulaires/test-adresse-terrain.php
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
		$fichier = $src . '/' . str_replace( '\\', '/', str_replace( 'Urbizen\\Platform\\', '', $classe ) ) . '.php';

		if ( file_exists( $fichier ) ) {
			require $fichier;
		}
	}
);

use Urbizen\Platform\Forms\AdresseTerrain;
use Urbizen\Platform\Forms\ValidationMessages;

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

	printf( "%-72s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

$auto = array(
	'mode_adresse'    => 'automatique',
	'terrain_adresse' => '12 rue Exemple, 31000 Toulouse',
	'terrain_insee'   => '31555',
	'terrain_cp'      => '31000',
	'terrain_ville'   => 'Toulouse',
	'terrain_lat'     => '43.6',
	'terrain_lon'     => '1.44',
);

$manuel = array(
	'mode_adresse'       => 'manuel',
	'terrain_voie'       => 'Lieu-dit Les Vignes',
	'terrain_complement' => 'Bâtiment B',
	'terrain_cp'         => '20000',
	'terrain_ville'      => 'Ajaccio',
);

echo "\n── 1. Le mode est une liste fermée\n";

verifier( 'un mode automatique complet est accepté', array() === AdresseTerrain::verifier( $auto ) );
verifier( 'un mode manuel complet est accepté', array() === AdresseTerrain::verifier( $manuel ) );

foreach ( array( 'absent' => null, 'vide' => '', 'inventé' => 'devine', 'numérique' => 42, 'tableau' => array( 'manuel' ) ) as $nom => $valeur ) {
	$e = AdresseTerrain::verifier( array_merge( $auto, array( 'mode_adresse' => $valeur ) ) );

	verifier( sprintf( 'mode %s : la demande est refusée', $nom ), isset( $e['mode_adresse'] ) );
}

verifier( 'le refus nomme le bloc d’adresse',
	'adresse_mode_absent' === ( AdresseTerrain::verifier( array_merge( $auto, array( 'mode_adresse' => null ) ) )['mode_adresse'] ?? '' ) );
verifier( 'un mode inventé se distingue d’un mode absent',
	'adresse_mode_inconnu' === ( AdresseTerrain::verifier( array_merge( $auto, array( 'mode_adresse' => 'devine' ) ) )['mode_adresse'] ?? '' ) );

// Un parcours qui ne pose pas d'adresse n'est pas concerné : la clé est absente
// de la charge nettoyée. Sans cette porte, la conception refuserait toute
// demande sans terrain.
verifier( 'un parcours sans adresse n’est pas contraint', array() === AdresseTerrain::verifier( array( 'nature' => 'piscine' ) ) );

echo "\n── 2. Ce que chaque mode exige\n";

foreach ( array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_insee' ) as $champ ) {
	$e = AdresseTerrain::verifier( array_merge( $auto, array( $champ => null ) ) );

	verifier( sprintf( 'automatique · « %s » est exigé', $champ ), 'champ_requis' === ( $e[ $champ ] ?? '' ) );
}

foreach ( array( 'terrain_voie', 'terrain_cp', 'terrain_ville' ) as $champ ) {
	$e = AdresseTerrain::verifier( array_merge( $manuel, array( $champ => null ) ) );

	verifier( sprintf( 'manuel · « %s » est exigé', $champ ), 'champ_requis' === ( $e[ $champ ] ?? '' ) );
}

verifier( 'manuel · le complément reste facultatif',
	array() === AdresseTerrain::verifier( array_merge( $manuel, array( 'terrain_complement' => null ) ) ) );

// Une voie n'est pas toujours numérotée : un lieu-dit ou un chemin sont des
// adresses parfaitement valides, et exiger un numéro écarterait la campagne.
foreach ( array( '12 rue Exemple', 'Chemin du Moulin', 'Lieu-dit Les Vignes' ) as $voie ) {
	verifier( sprintf( 'manuel · « %s » est acceptée', $voie ),
		array() === AdresseTerrain::verifier( array_merge( $manuel, array( 'terrain_voie' => $voie ) ) ) );
}

// Les codes postaux corses et ultramarins sont des codes postaux français.
foreach ( array( '20000' => 'Ajaccio', '97400' => 'Saint-Denis', '98800' => 'Nouméa' ) as $cp => $ville ) {
	verifier( sprintf( 'code postal « %s » accepté', $cp ),
		array() === AdresseTerrain::verifier( array_merge( $manuel, array( 'terrain_cp' => $cp, 'terrain_ville' => $ville ) ) ) );
}

echo "\n── 3. Les coordonnées vont par deux\n";

verifier( 'aucune coordonnée : accepté', array() === AdresseTerrain::verifier( array_merge( $auto, array( 'terrain_lat' => null, 'terrain_lon' => null ) ) ) );
verifier( 'latitude seule : refusée', isset( AdresseTerrain::verifier( array_merge( $auto, array( 'terrain_lon' => null ) ) )['terrain_lon'] ) );
verifier( 'longitude seule : refusée', isset( AdresseTerrain::verifier( array_merge( $auto, array( 'terrain_lat' => null ) ) )['terrain_lat'] ) );
verifier( 'latitude hors bornes', 'hors_bornes' === ( AdresseTerrain::verifier( array_merge( $auto, array( 'terrain_lat' => '95' ) ) )['terrain_lat'] ?? '' ) );
verifier( 'longitude hors bornes', 'hors_bornes' === ( AdresseTerrain::verifier( array_merge( $auto, array( 'terrain_lon' => '-200' ) ) )['terrain_lon'] ?? '' ) );
verifier( 'latitude non numérique', 'hors_bornes' === ( AdresseTerrain::verifier( array_merge( $auto, array( 'terrain_lat' => 'nord' ) ) )['terrain_lat'] ?? '' ) );

// Aucune absence ne devient zéro : le point (0, 0) est au large du golfe de
// Guinée, et aucune demande n'y a jamais lieu.
$sans = AdresseTerrain::filtrer( array_merge( $auto, array( 'terrain_lat' => '', 'terrain_lon' => '' ) ) );

verifier( 'une coordonnée vide n’est pas persistée à zéro',
	! array_key_exists( 'terrain_lat', $sans ) && ! array_key_exists( 'terrain_lon', $sans ) );

echo "\n── 4. Une seule adresse survit au filtrage\n";

$deux = array_merge( $auto, array( 'terrain_voie' => 'Lieu-dit Les Vignes', 'terrain_complement' => 'Bâtiment B' ) );

$e = array();
$r = AdresseTerrain::filtrer( $deux, $e );

verifier( 'forgé en automatique · la voie manuelle est écartée', ! array_key_exists( 'terrain_voie', $r ) );
verifier( 'forgé en automatique · le complément aussi', ! array_key_exists( 'terrain_complement', $r ) );
verifier( 'forgé en automatique · l’adresse du service survit', '12 rue Exemple, 31000 Toulouse' === $r['terrain_adresse'] );
verifier( 'forgé en automatique · les écarts sont consignés', 2 === count( $e ) );

$e = array();
$r = AdresseTerrain::filtrer( array_merge( $deux, array( 'mode_adresse' => 'manuel' ) ), $e );

verifier( 'forgé en manuel · l’adresse du service est écartée', ! array_key_exists( 'terrain_adresse', $r ) );
verifier( 'forgé en manuel · l’INSEE aussi', ! array_key_exists( 'terrain_insee', $r ) );
verifier( 'forgé en manuel · les coordonnées aussi',
	! array_key_exists( 'terrain_lat', $r ) && ! array_key_exists( 'terrain_lon', $r ) );
verifier( 'forgé en manuel · la voie survit', 'Lieu-dit Les Vignes' === $r['terrain_voie'] );

// Mode illisible : rien ne subsiste, pas même le code postal. Un fragment
// d'adresse sans savoir de quelle adresse c'est le fragment ne vaut rien.
foreach ( array( null, '', 'devine' ) as $m ) {
	$r = AdresseTerrain::filtrer( array_merge( $deux, array( 'mode_adresse' => $m ) ) );

	verifier( sprintf( 'mode %s : aucun champ d’adresse ne subsiste', var_export( $m, true ) ),
		array() === array_intersect( AdresseTerrain::champs(), array_keys( $r ) ) );
}

echo "\n── 5. Ce que l’adresse donne à lire\n";

$lu_auto   = AdresseTerrain::filtrer( $auto );
$lu_manuel = AdresseTerrain::filtrer( $manuel );

verifier( 'automatique · une seule ligne, celle du service',
	array( '12 rue Exemple, 31000 Toulouse' ) === AdresseTerrain::lignes_adresse( $lu_auto ) );
verifier( 'automatique · la commune n’est pas répétée',
	1 === count( AdresseTerrain::lignes_adresse( $lu_auto ) ) );
verifier( 'automatique · un libellé partiel reçoit sa ligne basse',
	array( '12 rue Exemple', '31000 Toulouse' )
	=== AdresseTerrain::lignes_adresse( array_merge( $lu_auto, array( 'terrain_adresse' => '12 rue Exemple' ) ) ) );
verifier( 'manuel · voie, complément, puis commune',
	array( 'Lieu-dit Les Vignes', 'Bâtiment B', '20000 Ajaccio' ) === AdresseTerrain::lignes_adresse( $lu_manuel ) );
verifier( 'la provenance se dit en clair · automatique',
	'Adresse sélectionnée automatiquement' === AdresseTerrain::provenance( $lu_auto ) );
verifier( 'la provenance se dit en clair · manuel',
	'Adresse renseignée manuellement' === AdresseTerrain::provenance( $lu_manuel ) );

$reperes = AdresseTerrain::reperes( $lu_auto );

verifier( 'les repères portent le code commune', '31555' === ( $reperes['Code commune'] ?? '' ) );
verifier( 'et les coordonnées en écriture française', '43,6 · 1,44' === ( $reperes['Coordonnées'] ?? '' ) );
verifier( 'le mode manuel n’a aucun repère', array() === AdresseTerrain::reperes( $lu_manuel ) );

verifier( 'aucun identifiant technique dans les lignes',
	array() === array_filter( AdresseTerrain::lignes_adresse( $lu_manuel ), static fn( $l ) => str_contains( $l, '_' ) ) );

echo "\n── 6. Le catalogue déclare ce qu’il assume\n";

foreach ( array( 'mode_adresse', 'terrain_adresse', 'terrain_voie', 'terrain_complement', 'terrain_cp', 'terrain_ville', 'terrain_insee', 'terrain_lat', 'terrain_lon' ) as $champ ) {
	verifier( sprintf( 'le catalogue assume « %s »', $champ ), AdresseTerrain::porte( $champ ) );
}

verifier( 'et n’assume pas « nature »', ! AdresseTerrain::porte( 'nature' ) );
verifier( 'ni « adresse_declarant »', ! AdresseTerrain::porte( 'adresse_declarant' ) );

echo "\n── 7. Les messages se lisent\n";

foreach ( array( 'adresse_mode_absent', 'adresse_mode_inconnu', 'coordonnee_orpheline', 'hors_bornes' ) as $code ) {
	$m = ValidationMessages::message( $code );

	verifier( sprintf( '« %s » a un message', $code ), '' !== $m && $m !== $code );
	verifier( sprintf( '« %s » ne montre rien de technique', $code ),
		! str_contains( $m, '_' ) && ! str_contains( $m, 'null' ) );
}

echo "\n── 8. Les trois rendus, sans doublon\n";

if ( ! defined( 'URBIZEN_PLATFORM_DIR' ) ) {
	define( 'URBIZEN_PLATFORM_DIR', $racine . '/wordpress/urbizen-platform/' );
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Doublure d'échappement.
	 *
	 * @param string $t Texte.
	 * @return string
	 */
	function esc_html( $t ) { // phpcs:ignore
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Doublure de filtre.
	 *
	 * @param string $h Crochet.
	 * @param mixed  $v Valeur.
	 * @return mixed
	 */
	function apply_filters( $h, $v ) { // phpcs:ignore
		return $v;
	}
}

$charge_auto = array(
	'nature'          => 'piscine',
	'description'     => 'Bassin enterré.',
	'mode_adresse'    => 'automatique',
	'terrain_adresse' => '12 rue Exemple, 31000 Toulouse',
	'terrain_insee'   => '31555',
	'terrain_cp'      => '31000',
	'terrain_ville'   => 'Toulouse',
	'terrain_lat'     => '43.6',
	'terrain_lon'     => '1.44',
);

$corps = \Urbizen\Platform\Mail\MailRenderer::body(
	array(
		'id'             => 1,
		'reference'      => 'URB-2026-0000',
		'created_at_gmt' => '2026-08-06 00:00:00',
		'form_type'      => 'declaration_prealable',
		'consent_at_gmt' => '2026-08-06 00:00:00',
		'payload'        => $charge_auto,
		'pricing'        => array( 'base' => 249, 'total' => 249 ),
		'files'          => array(),
	),
	0
);

verifier( 'notification · la rubrique d’adresse est présente', str_contains( $corps, 'Adresse du terrain' ) );
verifier( 'notification · l’adresse ne paraît qu’une fois',
	1 === substr_count( $corps, '12 rue Exemple, 31000 Toulouse' ) );
verifier( 'notification · la provenance y figure', str_contains( $corps, 'Adresse sélectionnée automatiquement' ) );
verifier( 'notification · le code commune ne paraît qu’une fois', 1 === substr_count( $corps, '31555' ) );

// Le tableau générique ne doit reprendre aucun champ assumé par la classe.
foreach ( AdresseTerrain::champs() as $champ ) {
	verifier( sprintf( 'notification · « %s » n’est pas répété', $champ ), ! str_contains( $corps, $champ ) );
}

verifier( 'notification · aucun libellé de formulaire ne double la rubrique',
	! str_contains( $corps, '>Commune<' ) && ! str_contains( $corps, '>Latitude<' ) );
verifier( 'notification · le reste du tableau survit', str_contains( $corps, 'Bassin enterré.' ) );

// L'accusé client : l'adresse lisible, et rien de technique.
$vue = new ReflectionMethod( \Urbizen\Platform\Mail\CustomerAcknowledgementRenderer::class, 'adresse' );
$accuse = (string) $vue->invoke( null, $charge_auto );

verifier( 'accusé · l’adresse lisible y est', str_contains( $accuse, '12 rue Exemple, 31000 Toulouse' ) );
verifier( 'accusé · aucun code commune', ! str_contains( $accuse, '31555' ) );
verifier( 'accusé · aucune coordonnée', ! str_contains( $accuse, '43.6' ) && ! str_contains( $accuse, '1.44' ) );
verifier( 'accusé · aucun mode technique',
	! str_contains( $accuse, 'automatique' ) && ! str_contains( $accuse, 'mode_adresse' ) );
verifier( 'accusé · aucun identifiant de champ', ! str_contains( $accuse, 'terrain_' ) );

// Échappement : une valeur hostile ne peut pas venir du service, mais le rendu
// doit rester sûr quoi qu'il arrive.
$hostile = $vue->invoke(
	null,
	array( 'mode_adresse' => 'manuel', 'terrain_voie' => '<script>alert(1)</script>', 'terrain_cp' => '31000', 'terrain_ville' => 'Toulouse' )
);

verifier( 'accusé · une valeur hostile est échappée',
	! str_contains( $hostile, '<script>' ) && str_contains( $hostile, '&lt;script&gt;' ) );

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
