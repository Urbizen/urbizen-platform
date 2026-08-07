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

// Le banc s'exerce sur le rôle « terrain » ; la section 9 rejoue les mêmes
// invariants sur « declarant », pour prouver que la généralisation par rôles
// n'a rien changé au terrain et rien oublié au déclarant.
$A = AdresseTerrain::pour( AdresseTerrain::TERRAIN );

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

verifier( 'un mode automatique complet est accepté', array() === $A->verifier( $auto ) );
verifier( 'un mode manuel complet est accepté', array() === $A->verifier( $manuel ) );

foreach ( array( 'absent' => null, 'vide' => '', 'inventé' => 'devine', 'numérique' => 42, 'tableau' => array( 'manuel' ) ) as $nom => $valeur ) {
	$e = $A->verifier( array_merge( $auto, array( 'mode_adresse' => $valeur ) ) );

	verifier( sprintf( 'mode %s : la demande est refusée', $nom ), isset( $e['mode_adresse'] ) );
}

verifier( 'le refus nomme le bloc d’adresse',
	'adresse_mode_absent' === ( $A->verifier( array_merge( $auto, array( 'mode_adresse' => null ) ) )['mode_adresse'] ?? '' ) );
verifier( 'un mode inventé se distingue d’un mode absent',
	'adresse_mode_inconnu' === ( $A->verifier( array_merge( $auto, array( 'mode_adresse' => 'devine' ) ) )['mode_adresse'] ?? '' ) );

// Un parcours qui ne pose pas d'adresse n'est pas concerné : la clé est absente
// de la charge nettoyée. Sans cette porte, la conception refuserait toute
// demande sans terrain.
verifier( 'un parcours sans adresse n’est pas contraint', array() === $A->verifier( array( 'nature' => 'piscine' ) ) );

echo "\n── 2. Ce que chaque mode exige\n";

foreach ( array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_insee' ) as $champ ) {
	$e = $A->verifier( array_merge( $auto, array( $champ => null ) ) );

	verifier( sprintf( 'automatique · « %s » est exigé', $champ ), 'champ_requis' === ( $e[ $champ ] ?? '' ) );
}

foreach ( array( 'terrain_voie', 'terrain_cp', 'terrain_ville' ) as $champ ) {
	$e = $A->verifier( array_merge( $manuel, array( $champ => null ) ) );

	verifier( sprintf( 'manuel · « %s » est exigé', $champ ), 'champ_requis' === ( $e[ $champ ] ?? '' ) );
}

verifier( 'manuel · le complément reste facultatif',
	array() === $A->verifier( array_merge( $manuel, array( 'terrain_complement' => null ) ) ) );

// Une voie n'est pas toujours numérotée : un lieu-dit ou un chemin sont des
// adresses parfaitement valides, et exiger un numéro écarterait la campagne.
foreach ( array( '12 rue Exemple', 'Chemin du Moulin', 'Lieu-dit Les Vignes' ) as $voie ) {
	verifier( sprintf( 'manuel · « %s » est acceptée', $voie ),
		array() === $A->verifier( array_merge( $manuel, array( 'terrain_voie' => $voie ) ) ) );
}

// Les codes postaux corses et ultramarins sont des codes postaux français.
foreach ( array( '20000' => 'Ajaccio', '97400' => 'Saint-Denis', '98800' => 'Nouméa' ) as $cp => $ville ) {
	verifier( sprintf( 'code postal « %s » accepté', $cp ),
		array() === $A->verifier( array_merge( $manuel, array( 'terrain_cp' => $cp, 'terrain_ville' => $ville ) ) ) );
}

echo "\n── 3. Les coordonnées vont par deux\n";

verifier( 'aucune coordonnée : accepté', array() === $A->verifier( array_merge( $auto, array( 'terrain_lat' => null, 'terrain_lon' => null ) ) ) );
verifier( 'latitude seule : refusée', isset( $A->verifier( array_merge( $auto, array( 'terrain_lon' => null ) ) )['terrain_lon'] ) );
verifier( 'longitude seule : refusée', isset( $A->verifier( array_merge( $auto, array( 'terrain_lat' => null ) ) )['terrain_lat'] ) );
verifier( 'latitude hors bornes', 'hors_bornes' === ( $A->verifier( array_merge( $auto, array( 'terrain_lat' => '95' ) ) )['terrain_lat'] ?? '' ) );
verifier( 'longitude hors bornes', 'hors_bornes' === ( $A->verifier( array_merge( $auto, array( 'terrain_lon' => '-200' ) ) )['terrain_lon'] ?? '' ) );
verifier( 'latitude non numérique', 'hors_bornes' === ( $A->verifier( array_merge( $auto, array( 'terrain_lat' => 'nord' ) ) )['terrain_lat'] ?? '' ) );

// Aucune absence ne devient zéro : le point (0, 0) est au large du golfe de
// Guinée, et aucune demande n'y a jamais lieu.
$sans = $A->filtrer( array_merge( $auto, array( 'terrain_lat' => '', 'terrain_lon' => '' ) ) );

verifier( 'une coordonnée vide n’est pas persistée à zéro',
	! array_key_exists( 'terrain_lat', $sans ) && ! array_key_exists( 'terrain_lon', $sans ) );

echo "\n── 4. Une seule adresse survit au filtrage\n";

$deux = array_merge( $auto, array( 'terrain_voie' => 'Lieu-dit Les Vignes', 'terrain_complement' => 'Bâtiment B' ) );

$e = array();
$r = $A->filtrer( $deux, $e );

verifier( 'forgé en automatique · la voie manuelle est écartée', ! array_key_exists( 'terrain_voie', $r ) );
verifier( 'forgé en automatique · le complément aussi', ! array_key_exists( 'terrain_complement', $r ) );
verifier( 'forgé en automatique · l’adresse du service survit', '12 rue Exemple, 31000 Toulouse' === $r['terrain_adresse'] );
verifier( 'forgé en automatique · les écarts sont consignés', 2 === count( $e ) );

$e = array();
$r = $A->filtrer( array_merge( $deux, array( 'mode_adresse' => 'manuel' ) ), $e );

verifier( 'forgé en manuel · l’adresse du service est écartée', ! array_key_exists( 'terrain_adresse', $r ) );
verifier( 'forgé en manuel · l’INSEE aussi', ! array_key_exists( 'terrain_insee', $r ) );
verifier( 'forgé en manuel · les coordonnées aussi',
	! array_key_exists( 'terrain_lat', $r ) && ! array_key_exists( 'terrain_lon', $r ) );
verifier( 'forgé en manuel · la voie survit', 'Lieu-dit Les Vignes' === $r['terrain_voie'] );

// Mode illisible : rien ne subsiste, pas même le code postal. Un fragment
// d'adresse sans savoir de quelle adresse c'est le fragment ne vaut rien.
foreach ( array( null, '', 'devine' ) as $m ) {
	$r = $A->filtrer( array_merge( $deux, array( 'mode_adresse' => $m ) ) );

	verifier( sprintf( 'mode %s : aucun champ d’adresse ne subsiste', var_export( $m, true ) ),
		array() === array_intersect( $A->champs(), array_keys( $r ) ) );
}

echo "\n── 5. Ce que l’adresse donne à lire\n";

$lu_auto   = $A->filtrer( $auto );
$lu_manuel = $A->filtrer( $manuel );

verifier( 'automatique · une seule ligne, celle du service',
	array( '12 rue Exemple, 31000 Toulouse' ) === $A->lignes_adresse( $lu_auto ) );
verifier( 'automatique · la commune n’est pas répétée',
	1 === count( $A->lignes_adresse( $lu_auto ) ) );
verifier( 'automatique · un libellé partiel reçoit sa ligne basse',
	array( '12 rue Exemple', '31000 Toulouse' )
	=== $A->lignes_adresse( array_merge( $lu_auto, array( 'terrain_adresse' => '12 rue Exemple' ) ) ) );
verifier( 'manuel · voie, complément, puis commune',
	array( 'Lieu-dit Les Vignes', 'Bâtiment B', '20000 Ajaccio' ) === $A->lignes_adresse( $lu_manuel ) );
verifier( 'la provenance se dit en clair · automatique',
	'Adresse sélectionnée automatiquement' === $A->provenance( $lu_auto ) );
verifier( 'la provenance se dit en clair · manuel',
	'Adresse renseignée manuellement' === $A->provenance( $lu_manuel ) );

$reperes = $A->reperes( $lu_auto );

verifier( 'les repères portent le code commune', '31555' === ( $reperes['Code commune'] ?? '' ) );
verifier( 'et les coordonnées en écriture française', '43,6 · 1,44' === ( $reperes['Coordonnées'] ?? '' ) );
verifier( 'le mode manuel n’a aucun repère', array() === $A->reperes( $lu_manuel ) );

verifier( 'aucun identifiant technique dans les lignes',
	array() === array_filter( $A->lignes_adresse( $lu_manuel ), static fn( $l ) => str_contains( $l, '_' ) ) );

echo "\n── 6. Le catalogue déclare ce qu’il assume\n";

foreach ( array( 'mode_adresse', 'terrain_adresse', 'terrain_voie', 'terrain_complement', 'terrain_cp', 'terrain_ville', 'terrain_insee', 'terrain_lat', 'terrain_lon' ) as $champ ) {
	verifier( sprintf( 'le catalogue assume « %s »', $champ ), AdresseTerrain::porte( $champ ) );
}

verifier( 'et n’assume pas « nature »', ! AdresseTerrain::porte( 'nature' ) );
verifier( 'ni « email »', ! AdresseTerrain::porte( 'email' ) );

// Le déclarant est assumé lui aussi, et c'est délibéré : sans cela, le tableau
// générique des courriels afficherait « insee_declarant : 75104 » en brut, à
// côté de la rubrique qui montre déjà l'adresse lisible.
foreach ( array( 'mode_adresse_declarant', 'adresse_declarant', 'voie_declarant', 'complement_declarant', 'cp_declarant', 'ville_declarant', 'insee_declarant', 'lat_declarant', 'lon_declarant' ) as $champ ) {
	verifier( sprintf( 'le catalogue assume « %s »', $champ ), AdresseTerrain::porte( $champ ) );
}

verifier( 'et la case de report', AdresseTerrain::porte( AdresseTerrain::REPORT ) );

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
foreach ( $A->champs() as $champ ) {
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

echo "\n── 9. Les deux rôles suivent la même règle\n";

$D = AdresseTerrain::pour( AdresseTerrain::DECLARANT );

// Les mêmes adresses, écrites dans le vocabulaire du déclarant. Si la
// généralisation avait laissé un nom du terrain en dur, ces jeux-là
// échoueraient là où leurs jumeaux terrain passent.
$auto_d = array(
	$D->nom( 'mode' )    => 'automatique',
	$D->nom( 'adresse' ) => '12 rue Exemple, 31000 Toulouse',
	$D->nom( 'insee' )   => '31555',
	$D->nom( 'cp' )      => '31000',
	$D->nom( 'ville' )   => 'Toulouse',
	$D->nom( 'lat' )     => '43.6',
	$D->nom( 'lon' )     => '1.44',
);

$manuel_d = array(
	$D->nom( 'mode' )       => 'manuel',
	$D->nom( 'voie' )       => 'Lieu-dit Les Vignes',
	$D->nom( 'complement' ) => 'Bâtiment B',
	$D->nom( 'cp' )         => '20000',
	$D->nom( 'ville' )      => 'Ajaccio',
);

verifier( 'déclarant · un mode automatique complet est accepté', array() === $D->verifier( $auto_d ) );
verifier( 'déclarant · un mode manuel complet est accepté', array() === $D->verifier( $manuel_d ) );
verifier( 'déclarant · un mode inventé est refusé',
	'adresse_mode_inconnu' === ( $D->verifier( array_merge( $auto_d, array( $D->nom( 'mode' ) => 'devine' ) ) )[ $D->nom( 'mode' ) ] ?? '' ) );
verifier( 'déclarant · un champ exigé manquant est signalé',
	'champ_requis' === ( $D->verifier( array_merge( $auto_d, array( $D->nom( 'insee' ) => '' ) ) )[ $D->nom( 'insee' ) ] ?? '' ) );
verifier( 'déclarant · les lignes se lisent comme celles du terrain',
	array( 'Lieu-dit Les Vignes', 'Bâtiment B', '20000 Ajaccio' ) === $D->lignes_adresse( $manuel_d ) );
verifier( 'déclarant · sa rubrique lui est propre', 'Adresse du déclarant' === $D->rubrique() );
verifier( 'terrain · sa rubrique lui est propre', 'Adresse du terrain' === $A->rubrique() );

// Les deux jeux de noms ne se recouvrent jamais : filtrer l'un ne doit
// jamais pouvoir emporter un champ de l'autre.
verifier( 'aucun nom partagé entre les deux rôles',
	array() === array_intersect( $A->champs(), $D->champs() ) );

$deux_d = array_merge( $auto_d, array( $D->nom( 'voie' ) => 'Concurrente', $D->nom( 'complement' ) => 'Bis' ) );
$ec     = array();
$r      = $D->filtrer( $deux_d, $ec );

verifier( 'déclarant · le mode inactif est écarté',
	! array_key_exists( $D->nom( 'voie' ), $r ) && ! array_key_exists( $D->nom( 'complement' ), $r ) );
verifier( 'déclarant · le mode retenu survit', '31555' === ( $r[ $D->nom( 'insee' ) ] ?? '' ) );
verifier( 'déclarant · l’écart est consigné', in_array( $D->nom( 'voie' ), $ec, true ) );
verifier( 'déclarant · son filtrage laisse le terrain intact',
	array_key_exists( 'terrain_adresse', $D->filtrer( array_merge( $auto_d, array( 'terrain_adresse' => 'Ailleurs' ) ) ) ) );

echo "\n── 10. L’adresse exigée, et l’adresse absente\n";

// Deux absences que rien d'autre ne sépare : le parcours qui ne pose pas
// d'adresse, et la charge forgée qui a retiré le mode pour y échapper.
verifier( 'un parcours sans adresse reste libre', array() === $D->verifier( array( 'nature' => 'piscine' ) ) );
verifier( 'mais une adresse exigée et absente est refusée',
	'adresse_mode_absent' === ( $D->verifier( array( 'nature' => 'piscine' ), true )[ $D->nom( 'mode' ) ] ?? '' ) );
verifier( 'une adresse exigée et présente reste acceptée', array() === $D->verifier( $auto_d, true ) );

echo "\n── 11. Le report « même adresse que le déclarant »\n";

verifier( 'la valeur canonique coche', AdresseTerrain::reportee( array( AdresseTerrain::REPORT => 'oui' ) ) );
verifier( 'la case absente ne coche pas', ! AdresseTerrain::reportee( array() ) );

// Liste fermée, sans conversion permissive : une charge forgée qui tenterait
// « 1 », « true » ou « on » ne doit pas déclencher la reconstruction.
foreach ( array( '1', 1, 'true', true, 'on', 'yes', 'OUI', 'Oui', ' oui', 'non', '', null ) as $i => $valeur ) {
	verifier(
		sprintf( 'la valeur non canonique #%d ne coche pas', $i ),
		! AdresseTerrain::reportee( array( AdresseTerrain::REPORT => $valeur ) )
	);
}

/*
 * La forme que le serveur voit réellement est une LISTE : `clean_liste()`
 * transforme tout champ `checkbox` en tableau, même à case unique. Ce banc
 * exigeait autrefois qu'une liste ne coche jamais — il figeait la panne au lieu
 * de la voir. Le vocabulaire complet des formes est éprouvé dans
 * `test-report-adresse.php`, qui part d'un `$_POST` brut ; ici on garde le
 * strict nécessaire pour que l'invariant ne se reperde pas.
 */
verifier( 'la liste canonique coche', AdresseTerrain::reportee( array( AdresseTerrain::REPORT => array( 'oui' ) ) ) );
verifier( 'la liste vide ne coche pas', ! AdresseTerrain::reportee( array( AdresseTerrain::REPORT => array() ) ) );
verifier( 'une liste contradictoire ne coche pas', ! AdresseTerrain::reportee( array( AdresseTerrain::REPORT => array( 'oui', 'non' ) ) ) );
verifier( 'une liste non canonique ne coche pas', ! AdresseTerrain::reportee( array( AdresseTerrain::REPORT => array( '1' ) ) ) );

// La normalisation tranche : « oui » en clair, ou rien du tout.
verifier( 'normaliser rend la valeur canonique en clair',
	'oui' === ( AdresseTerrain::normaliser_report( array( AdresseTerrain::REPORT => array( 'oui' ) ) )[ AdresseTerrain::REPORT ] ?? '' ) );
verifier( 'et retire une liste vide',
	! array_key_exists( AdresseTerrain::REPORT, AdresseTerrain::normaliser_report( array( AdresseTerrain::REPORT => array() ) ) ) );

// L'export ne sort QUE le mode actif : c'est lui qui interdit qu'une charge
// portant les deux modes laisse le mode abandonné alimenter la copie.
$export_auto = $D->exporter( $deux_d );

verifier( 'l’export ne sort que le mode actif',
	! array_key_exists( 'voie', $export_auto ) && ! array_key_exists( 'complement', $export_auto ) );
verifier( 'l’export porte le mode réel', 'automatique' === ( $export_auto['mode'] ?? '' ) );
verifier( 'l’export porte le code commune', '31555' === ( $export_auto['insee'] ?? '' ) );

$export_manuel = $D->exporter( $manuel_d );

verifier( 'l’export manuel porte la voie', 'Lieu-dit Les Vignes' === ( $export_manuel['voie'] ?? '' ) );
verifier( 'et aucun code commune', ! array_key_exists( 'insee', $export_manuel ) );
verifier( 'un mode inexploitable n’exporte rien', array() === $D->exporter( array() ) );

$orpheline = $D->exporter( array_merge( $auto_d, array( $D->nom( 'lon' ) => '' ) ) );

verifier( 'une coordonnée orpheline n’est jamais exportée',
	! array_key_exists( 'lat', $orpheline ) && ! array_key_exists( 'lon', $orpheline ) );

// La purge ne laisse rien, quelle que soit la valeur : c'est ce qui garantit
// qu'aucune adresse forgée ne survit à la recopie.
$forge = array_merge( $auto, array( 'terrain_voie' => 'Forgée', 'nature' => 'piscine' ) );
$ec    = array();
$purge = $A->purger( $forge, $ec );

verifier( 'la purge n’épargne aucun champ du rôle',
	array() === array_intersect( $A->champs(), array_keys( $purge ) ) );
verifier( 'et laisse le reste intact', 'piscine' === ( $purge['nature'] ?? '' ) );
verifier( 'les valeurs purgées sont consignées', in_array( 'terrain_adresse', $ec, true ) );

$reconstruit = $A->importer( $purge, $export_auto );

verifier( 'le terrain reconstruit porte le mode du déclarant', 'automatique' === ( $reconstruit['mode_adresse'] ?? '' ) );
verifier( 'et son adresse', '12 rue Exemple, 31000 Toulouse' === ( $reconstruit['terrain_adresse'] ?? '' ) );
verifier( 'et son code commune', '31555' === ( $reconstruit['terrain_insee'] ?? '' ) );
verifier( 'et ses coordonnées par paire',
	'43.6' === ( $reconstruit['terrain_lat'] ?? '' ) && '1.44' === ( $reconstruit['terrain_lon'] ?? '' ) );
verifier( 'l’adresse forgée n’a pas survécu', ! array_key_exists( 'terrain_voie', $reconstruit ) );
verifier( 'et le terrain reconstruit est valide', array() === $A->verifier( $reconstruit, true ) );

$reconstruit_m = $A->importer( $A->purger( $forge ), $export_manuel );

verifier( 'un déclarant manuel donne un terrain manuel', 'manuel' === ( $reconstruit_m['mode_adresse'] ?? '' ) );
verifier( 'avec sa voie et son complément',
	'Lieu-dit Les Vignes' === ( $reconstruit_m['terrain_voie'] ?? '' ) && 'Bâtiment B' === ( $reconstruit_m['terrain_complement'] ?? '' ) );
verifier( 'et sans code commune', ! array_key_exists( 'terrain_insee', $reconstruit_m ) );
verifier( 'ce terrain manuel est valide', array() === $A->verifier( $reconstruit_m, true ) );

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
