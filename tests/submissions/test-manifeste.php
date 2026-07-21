<?php
/**
 * Banc d'essai du manifeste de dépôt.
 *
 * `max_file_uploads` vaut 20 en production : au-delà, PHP livre une partie des
 * fichiers **sans le dire**. Le serveur ne peut pas connaître un fichier qui
 * ne lui est jamais parvenu — d'où la déclaration préalable du navigateur, et
 * la confrontation avec ce qui est réellement reçu.
 *
 * Le manifeste ne rend jamais un fichier acceptable : il détecte une perte,
 * rien de plus. Toutes les barrières B2 continuent de s'appliquer, et ce banc
 * le vérifie.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Files\UploadManifest;
use Urbizen\Platform\Files\UploadNormalizer;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

function neuf(): void {
	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
	FileCleaner::reset();
	update_option( 'admin_email', 'dossiers@urbizen.test' );
}

/**
 * Lot de documents dans un bloc.
 *
 * @param string $bloc   Bloc.
 * @param int    $nombre Nombre de documents.
 * @return array<string, mixed>
 */
function lot( string $bloc, int $nombre ): array {
	$fichiers = array();

	for ( $i = 0; $i < $nombre; $i++ ) {
		$fichiers[] = array( 'doc-' . $i . '.jpg', fx_copie( fx_jpeg() ) );
	}

	return fx_files( $bloc, $fichiers );
}

/**
 * Soumet avec une déclaration donnée.
 *
 * @param array<string, mixed> $files       Fichiers.
 * @param mixed                $declaration Manifeste, ou `null` pour l'omettre.
 * @return \Urbizen\Platform\Http\SubmissionResult
 */
function soumettre( array $files, $declaration ) {
	$post = soumission();

	if ( null !== $declaration ) {
		$post[ UploadManifest::FIELD ] = $declaration;
	} else {
		// On neutralise l'ajout automatique de l'assistant partagé.
		$post[ UploadManifest::FIELD ] = null;
		unset( $post[ UploadManifest::FIELD ] );
	}

	return \Urbizen\Platform\Http\SubmissionController::process( $post, $files, serveur(), wpd_now() );
}

/**
 * Fabrique une déclaration à la main.
 *
 * @param array<string, array{count:mixed,size:mixed}> $blocs   Blocs.
 * @param mixed                                        $total_c Total déclaré.
 * @param mixed                                        $total_s Taille déclarée.
 * @param mixed                                        $version Version.
 * @return string
 */
function declaration( array $blocs, $total_c = null, $total_s = null, $version = 1 ): string {
	$c = 0;
	$s = 0;

	foreach ( $blocs as $b ) {
		$c += is_int( $b['count'] ) ? $b['count'] : 0;
		$s += is_int( $b['size'] ) ? $b['size'] : 0;
	}

	return (string) wp_json_encode(
		array(
			'version'     => $version,
			'total_count' => null === $total_c ? $c : $total_c,
			'total_size'  => null === $total_s ? $s : $total_s,
			'blocks'      => $blocs,
		)
	);
}

/**
 * Manifeste serveur des fichiers d'un lot.
 *
 * @param array<string, mixed> $files Fichiers.
 * @return array<string, mixed>
 */
function reel( array $files ): array {
	$n = UploadNormalizer::normalize( $files );

	return UploadManifest::from_files( $n['files'] );
}

/**
 * Aucun état n'a été créé.
 *
 * @return bool
 */
function rien_cree(): bool {
	$options = array_filter(
		array_keys( $GLOBALS['wpd_options'] ),
		static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX )
			&& 'attributed' === ( get_option( $c )['state'] ?? '' )
	);

	return array() === $GLOBALS['wpd_posts']
		&& array() === $options
		&& 0 === fx_compte_fichiers()
		&& 0 === fx_compte_staging()
		&& array() === $GLOBALS['wpd_mails'];
}

// ======================================================================
// 1 · ZÉRO FICHIER
// ======================================================================
neuf();
$r = soumettre( array(), null );

check( '1 · sans document et sans manifeste, la soumission réussit', $r->is_success() );
check( '1 · files_status = none', 'none' === get_post_meta( $r->id(), '_urbizen_files_status', true ) );

neuf();
$r = soumettre( array(), declaration( array() ) );

check( '1 · un manifeste de zéro fichier est accepté', $r->is_success() );

// Un champ de dépôt laissé vide n'entre dans aucun manifeste.
neuf();
$vide = array(
	'photos' => array(
		'name'     => array( '' ),
		'type'     => array( '' ),
		'tmp_name' => array( '' ),
		'error'    => array( UPLOAD_ERR_NO_FILE ),
		'size'     => array( 0 ),
	),
);
$r = soumettre( $vide, null );

check( '1 · un champ vide ne bloque pas', $r->is_success() );

// ======================================================================
// 2 · UN, PUIS VINGT DOCUMENTS
// ======================================================================
neuf();
$un = lot( 'photos', 1 );
$r  = soumettre( $un, fx_manifeste( $un ) );

check( '2 · un document, manifeste exact', $r->is_success() );
check( '2 · le document est stocké', 1 === fx_compte_fichiers() );

neuf();
// Vingt documents : deux blocs de dix, la politique plafonnant à dix par bloc.
$vingt = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 );
$r     = soumettre( $vingt, fx_manifeste( $vingt ) );

check( '2 · vingt documents, manifeste exact', $r->is_success() );
check( '2 · les vingt sont stockés', 20 === fx_compte_fichiers() );

$m = reel( $vingt );

check( '2 · le manifeste serveur compte vingt', 20 === $m['total_count'] );
check( '2 · et deux blocs', 2 === count( $m['blocks'] ) );

// ======================================================================
// 3 · LA PROPRIÉTÉ CHERCHÉE PAR D-032 : 20 ANNONCÉS, 19 REÇUS
// ======================================================================
neuf();
$vingt   = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 );
$annonce = fx_manifeste( $vingt );

// PHP tronque : le dernier fichier n'arrive jamais.
$dix_neuf = $vingt;

foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $cle ) {
	array_pop( $dix_neuf['plan_terrain'][ $cle ] );
}

$r = soumettre( $dix_neuf, $annonce );

check( '3 · 20 ANNONCÉS, 19 REÇUS → REFUS', ! $r->is_success() );
check( '3 · code upload_incomplete', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );
check( '3 · aucune demande', array() === $GLOBALS['wpd_posts'] );
check( '3 · aucun document final', 0 === fx_compte_fichiers() );
check( '3 · aucun staging', 0 === fx_compte_staging() );
check( '3 · aucun courriel', array() === $GLOBALS['wpd_mails'] );
check( '3 · rien n’a été créé', rien_cree() );

// L'inverse : 19 annoncés, 20 reçus.
neuf();
$dix_neuf = $vingt;

foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $cle ) {
	array_pop( $dix_neuf['plan_terrain'][ $cle ] );
}

$r = soumettre( $vingt, fx_manifeste( $dix_neuf ) );

check( '3 · 19 annoncés, 20 reçus → refus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );
check( '3 · rien n’a été créé', rien_cree() );

// ======================================================================
// 4 · ÉCARTS DE BLOC ET DE TAILLE
// ======================================================================
neuf();
$un = lot( 'photos', 1 );
$m  = reel( $un );

// Bon nombre, mauvais bloc.
$r = soumettre( $un, declaration( array( 'urbanisme' => array( 'count' => 1, 'size' => $m['total_size'] ) ) ) );

check( '4 · mauvais bloc → refus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );

// Bon nombre, mauvaise taille.
neuf();
$r = soumettre( $un, declaration( array( 'photos' => array( 'count' => 1, 'size' => $m['total_size'] + 1 ) ) ) );

check( '4 · taille erronée → refus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );

// Bloc supplémentaire.
neuf();
$r = soumettre(
	$un,
	declaration(
		array(
			'photos'    => array( 'count' => 1, 'size' => $m['total_size'] ),
			'urbanisme' => array( 'count' => 1, 'size' => 10 ),
		)
	)
);

check( '4 · bloc supplémentaire → refus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );

// Bloc absent.
neuf();
$deux = lot( 'photos', 1 ) + lot( 'urbanisme', 1 );
$md   = reel( $deux );
$r    = soumettre( $deux, declaration( array( 'photos' => array( 'count' => 1, 'size' => $md['blocks']['photos']['size'] ) ) ) );

check( '4 · bloc absent → refus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );
check( '4 · rien n’a été créé', rien_cree() );

// ======================================================================
// 5 · DÉCLARATIONS MALFORMÉES
// ======================================================================
$un = lot( 'photos', 1 );
$m  = reel( $un );
$ok = array( 'photos' => array( 'count' => 1, 'size' => $m['total_size'] ) );

$malformees = array(
	'JSON tronqué'                => '{"version":1,"total_count":1,',
	'JSON invalide'               => 'pas du json',
	'chaîne vide significative'   => '   ',
	'tableau PHP'                 => array( 'version' => 1 ),
	'clé supplémentaire'          => (string) wp_json_encode( array( 'version' => 1, 'total_count' => 1, 'total_size' => $m['total_size'], 'blocks' => $ok, 'extra' => 1 ) ),
	'clé manquante'               => (string) wp_json_encode( array( 'version' => 1, 'total_count' => 1, 'blocks' => $ok ) ),
	'version inconnue'            => declaration( $ok, null, null, 2 ),
	'entier en chaîne'            => declaration( array( 'photos' => array( 'count' => '1', 'size' => $m['total_size'] ) ), 1, $m['total_size'] ),
	// `wp_json_encode( 1.0 )` produit « 1 » : la distinction disparaîtrait. Un
	// navigateur qui envoie réellement « 1.0 » produit ce texte-ci.
	'flottant'                    => sprintf(
		'{"version":1,"total_count":1.0,"total_size":%d,"blocks":{"photos":{"count":1.0,"size":%d}}}',
		$m['total_size'],
		$m['total_size']
	),
	'négatif'                     => declaration( array( 'photos' => array( 'count' => -1, 'size' => $m['total_size'] ) ), -1, $m['total_size'] ),
	'total ≠ somme des blocs'     => declaration( $ok, 5, $m['total_size'] ),
	'taille ≠ somme des blocs'    => declaration( $ok, 1, 99999 ),
	'bloc inconnu'                => declaration( array( 'inconnu' => array( 'count' => 1, 'size' => 10 ) ) ),
	'bloc déclaré vide'           => declaration( array( 'photos' => array( 'count' => 0, 'size' => 0 ) ) ),
	'clé de bloc supplémentaire'  => (string) wp_json_encode( array( 'version' => 1, 'total_count' => 1, 'total_size' => $m['total_size'], 'blocks' => array( 'photos' => array( 'count' => 1, 'size' => $m['total_size'], 'name' => 'x' ) ) ) ),
	'déclaration démesurée'       => str_repeat( 'a', UploadManifest::MAX_LENGTH + 1 ),
);

foreach ( $malformees as $quoi => $valeur ) {
	neuf();
	$r = soumettre( lot( 'photos', 1 ), $valeur );

	check( "5 · $quoi → refus", ! $r->is_success() );
	check( "5 · $quoi → aucun état créé", rien_cree() );
}

// ======================================================================
// 6 · MANIFESTE ABSENT AVEC DES FICHIERS
// ======================================================================
neuf();
$r = soumettre( lot( 'photos', 1 ), null );

check( '6 · fichiers sans manifeste → refus', SubmissionResult::UPLOAD_MANIFEST_MISSING === $r->code() );
check( '6 · aucun état créé', rien_cree() );

// Manifeste annonçant des fichiers, aucun reçu.
neuf();
$r = soumettre( array(), declaration( array( 'photos' => array( 'count' => 2, 'size' => 200 ) ) ) );

check( '6 · manifeste sans fichiers → refus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );
check( '6 · aucun état créé', rien_cree() );

// ======================================================================
// 7 · LE MANIFESTE NE REMPLACE AUCUNE BARRIÈRE
// ======================================================================
// Un manifeste parfaitement exact ne rend pas un SVG acceptable.
neuf();
$svg  = fx_write_brut( '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );
$faux = fx_files( 'croquis_plans', array( array( 'dessin.svg', $svg ) ) );
$r    = soumettre( $faux, fx_manifeste( $faux ) );

check( '7 · manifeste exact, extension refusée', ! $r->is_success() );
check( '7 · le code reste celui de la politique',
	in_array( $r->code(), array( SubmissionResult::UPLOAD_INVALID_EXTENSION, SubmissionResult::UPLOAD_INVALID_MIME ), true ) );

// Un manifeste exact ne lève pas la limite par bloc.
neuf();
$onze = lot( 'photos', 11 );
$r    = soumettre( $onze, fx_manifeste( $onze ) );

check( '7 · manifeste exact, limite par bloc appliquée', SubmissionResult::UPLOAD_COUNT_EXCEEDED === $r->code() );

// Un manifeste exact ne lève pas la limite totale.
neuf();
$vingt_et_un = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 ) + lot( 'urbanisme', 1 );
$r           = soumettre( $vingt_et_un, fx_manifeste( $vingt_et_un ) );

check( '7 · manifeste exact, limite totale appliquée', SubmissionResult::UPLOAD_COUNT_EXCEEDED === $r->code() );
check( '7 · rien n’a été créé', rien_cree() );

// ======================================================================
// 8 · LA TAILLE COMPARÉE EST CELLE REÇUE, PAS CELLE DÉCLARÉE
// ======================================================================
neuf();
$un = lot( 'photos', 1 );

// La requête HTTP ment sur la taille : le fichier réel fait autre chose.
$un['photos']['size'][0] = 999999;
$reelle                  = (int) filesize( $un['photos']['tmp_name'][0] );

// Le navigateur, lui, déclare la vraie taille de son objet File.
$r = soumettre( $un, declaration( array( 'photos' => array( 'count' => 1, 'size' => $reelle ) ) ) );

check( '8 · la déclaration HTTP mensongère n’est pas la référence', $r->is_success() );

neuf();
$un                      = lot( 'photos', 1 );
$un['photos']['size'][0] = 999999;
$r                       = soumettre( $un, declaration( array( 'photos' => array( 'count' => 1, 'size' => 999999 ) ) ) );

check( '8 · un manifeste calé sur la déclaration HTTP est refusé', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );

// ======================================================================
// 9 · REFUS AVANT TOUT EFFET
// ======================================================================
neuf();
$vingt   = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 );
$annonce = fx_manifeste( $vingt );
$tronque = $vingt;

foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $cle ) {
	array_pop( $tronque['plan_terrain'][ $cle ] );
}

$r = soumettre( $tronque, $annonce );

check( '9 · refus', ! $r->is_success() );
check( '9 · aucune demande received',
	array() === array_filter(
		array_keys( $GLOBALS['wpd_meta'] ),
		static fn( $id ) => SubmissionPostType::STATUS_RECEIVED === ( $GLOBALS['wpd_meta'][ $id ]['_urbizen_status'] ?? '' )
	) );
check( '9 · aucune référence attributed',
	array() === array_filter(
		array_keys( $GLOBALS['wpd_options'] ),
		static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX )
			&& 'attributed' === ( get_option( $c )['state'] ?? '' )
	) );
check( '9 · aucune notification pending',
	array() === array_filter(
		array_keys( $GLOBALS['wpd_meta'] ),
		static fn( $id ) => MailPolicy::PENDING === ( $GLOBALS['wpd_meta'][ $id ][ MailPolicy::META_STATUS ] ?? '' )
	) );
check( '9 · aucun événement mail', false === wp_next_scheduled( MailPolicy::EVENT, array( 1 ) ) );
check( '9 · aucun document orphelin', 0 === fx_compte_fichiers() );
check( '9 · aucun staging', 0 === fx_compte_staging() );

// La référence réservée est libérable : rien n'est resté coincé.
$restantes = array_filter(
	array_keys( $GLOBALS['wpd_options'] ),
	static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX )
);

check( '9 · aucune réservation attribuée à tort',
	array() === array_filter(
		$restantes,
		static fn( $c ) => 'attributed' === ( get_option( $c )['state'] ?? '' )
	) );

// ======================================================================
// 10 · LE MANIFESTE NE PORTE AUCUNE DONNÉE PERSONNELLE
// ======================================================================
$un   = fx_files( 'photos', array( array( 'Plan de Camille Fictif.jpg', fx_copie( fx_jpeg() ) ) ) );
$json = fx_manifeste( $un );

foreach ( array( 'Camille', 'Plan de', '.jpg', 'jpeg', 'image/', '/tmp', 'urbfx' ) as $interdit ) {
	check( "10 · le manifeste ne contient pas « $interdit »", ! str_contains( $json, $interdit ) );
}

$decode = json_decode( $json, true );

check( '10 · exactement quatre clés', array( 'version', 'total_count', 'total_size', 'blocks' ) === array_keys( $decode ) );
check( '10 · exactement deux clés par bloc', array( 'count', 'size' ) === array_keys( $decode['blocks']['photos'] ) );

verdict();
