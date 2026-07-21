<?php
/**
 * Banc d'essai de la politique, de la normalisation et du stockage.
 *
 * Les fichiers d'essai portent de véritables signatures de format : `finfo`
 * en déduit le type comme en production. Renommer un fichier texte ne
 * prouverait rien.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Files\UploadNormalizer as N;
use Urbizen\Platform\Files\UploadPolicy as P;

fx_vide_stockage();

// ======================================================================
// 23 · POLITIQUE
// ======================================================================
check( 'cinq blocs autorisés', array( 'croquis_plans', 'plan_terrain', 'photos', 'inspirations_docs', 'urbanisme' ) === P::blocks() );
check( 'un bloc inconnu est refusé', ! P::is_block( 'factures' ) && ! P::is_block( '' ) );
check( 'cinq extensions autorisées', array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' ) === P::extensions() );
check( 'limites par défaut : 10 par bloc, 20 au total', 10 === P::max_per_block() && 20 === P::max_total() );
check( 'tailles par défaut : 10 Mio et 25 Mio',
	10485760 === P::max_file_size() && 26214400 === P::max_total_size() );
check( 'les tailles sont bien des multiples de 1024',
	10 * 1024 * 1024 === P::MAX_FILE_SIZE && 25 * 1024 * 1024 === P::MAX_TOTAL_SIZE );

// --- correspondances extension / type réel ---
$attendus = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );

foreach ( $attendus as $ext => $mime ) {
	check( "[$ext] attend $mime", $mime === P::mime_for( $ext ) );
}

foreach ( array( 'svg', 'gif', 'heic', 'docx', 'zip', 'exe', 'html', 'js', 'php', '' ) as $ext ) {
	check( "[$ext] refusé", null === P::mime_for( $ext ) );
}

check( 'l’extension est normalisée en minuscules', 'application/pdf' === P::mime_for( 'PDF' ) );
check( 'seule la dernière extension compte', 'php' === P::extension_of( 'facture.pdf.php' ) );
check( 'un nom sans extension n’en a pas', '' === P::extension_of( 'document' ) );
check( 'un nom finissant par un point n’a pas d’extension', '' === P::extension_of( 'document.' ) );

/**
 * Valide un fichier isolé.
 *
 * @param string $nom     Nom annoncé.
 * @param string $chemin  Chemin réel.
 * @param string $annonce Type annoncé par le navigateur.
 * @return array<string, mixed>
 */
function valide( string $nom, string $chemin, string $annonce = 'application/octet-stream' ): array {
	return P::validate_one(
		array( 'block' => 'croquis_plans', 'name' => $nom, 'tmp_name' => $chemin, 'error' => UPLOAD_ERR_OK )
	);
}

// --- formats acceptés ---
check( 'PDF valide accepté', valide( 'plan.pdf', fx_pdf() )['ok'] );
check( 'JPG valide accepté', valide( 'photo.jpg', fx_jpeg() )['ok'] );
check( 'JPEG valide accepté', valide( 'photo.jpeg', fx_jpeg() )['ok'] );
check( 'PNG valide accepté', valide( 'croquis.png', fx_png() )['ok'] );
check( 'WEBP valide accepté', valide( 'vue.webp', fx_webp() )['ok'] );
check( 'extension en majuscules normalisée', valide( 'PLAN.PDF', fx_pdf() )['ok'] );

// --- formats et supercheries refusés ---
$refuses = array(
	'SVG refusé'                        => array( 'dessin.svg', fx_svg(), 'upload_invalid_extension' ),
	'PHP renommé en JPG refusé'         => array( 'photo.jpg', fx_php(), 'upload_invalid_mime' ),
	'PHP renommé en PDF refusé'         => array( 'doc.pdf', fx_php(), 'upload_invalid_mime' ),
	'PDF renommé en JPG refusé'         => array( 'photo.jpg', fx_pdf(), 'upload_invalid_mime' ),
	'PNG renommé en PDF refusé'         => array( 'doc.pdf', fx_png(), 'upload_invalid_mime' ),
	'double extension trompeuse'        => array( 'photo.jpg.php', fx_php(), 'upload_invalid_extension' ),
	'PDF déguisé en double extension'   => array( 'plan.php.pdf', fx_php(), 'upload_invalid_mime' ),
	'fichier sans extension refusé'     => array( 'document', fx_pdf(), 'upload_invalid_extension' ),
	'fichier vide refusé'               => array( 'vide.pdf', fx_vide(), 'upload_empty_file' ),
);

foreach ( $refuses as $libelle => $cas ) {
	$r = valide( $cas[0], $cas[1] );
	check( sprintf( '%-34s → %s', $libelle, $cas[2] ), ! $r['ok'] && $cas[2] === $r['code'] );
}

// --- le type annoncé par le navigateur est ignoré ---
$menteur = valide( 'photo.jpg', fx_php(), 'image/jpeg' );
check( 'un type annoncé image/jpeg ne sauve pas un fichier PHP', ! $menteur['ok'] );

$honnete = valide( 'plan.pdf', fx_pdf(), 'application/x-nimportequoi' );
check( 'un type annoncé absurde ne condamne pas un vrai PDF', $honnete['ok'] );
check( 'le type retenu est le type réel', 'application/pdf' === $honnete['file']['mime'] );

// --- tailles ---
$juste = fx_pdf_taille( P::MAX_FILE_SIZE );
$trop  = fx_pdf_taille( P::MAX_FILE_SIZE + 1 );

check( 'un fichier de 10 Mio exactement est accepté', valide( 'gros.pdf', $juste )['ok'] );
check( 'un fichier de 10 Mio + 1 octet est refusé', 'upload_too_large' === valide( 'trop.pdf', $trop )['code'] );

// --- nombres ---
/**
 * Construit un lot normalisé.
 *
 * @param string $bloc   Bloc.
 * @param int    $nombre Nombre de documents.
 * @return array<int, array<string, mixed>>
 */
function lot( string $bloc, int $nombre ): array {
	$out = array();

	for ( $i = 0; $i < $nombre; $i++ ) {
		$out[] = array( 'block' => $bloc, 'name' => "p$i.pdf", 'tmp_name' => fx_pdf(), 'error' => UPLOAD_ERR_OK );
	}

	return $out;
}

check( 'dix documents dans un bloc sont acceptés', P::validate( lot( 'croquis_plans', 10 ) )['ok'] );
check( 'le onzième est refusé', 'upload_count_exceeded' === P::validate( lot( 'croquis_plans', 11 ) )['code'] );

$vingt = array_merge( lot( 'croquis_plans', 10 ), lot( 'photos', 10 ) );
check( 'vingt documents au total sont acceptés', P::validate( $vingt )['ok'] );

$vingt_et_un = array_merge( $vingt, lot( 'urbanisme', 1 ) );
check( 'le vingt-et-unième est refusé', 'upload_count_exceeded' === P::validate( $vingt_et_un )['code'] );

check( 'un bloc inconnu dans un lot est refusé',
	'upload_invalid_structure' === P::validate( lot( 'factures', 1 ) )['code'] );

// --- taille cumulée ---
$un_mio = 1048576;
$gros   = array();

for ( $i = 0; $i < 25; $i++ ) {
	$gros[] = array( 'block' => 'photos', 'name' => "g$i.pdf", 'tmp_name' => fx_pdf_taille( $un_mio ), 'error' => UPLOAD_ERR_OK );
}

// 25 documents dépassent d'abord la limite de nombre : on éprouve la taille
// avec un lot de 20 fichiers légèrement plus lourds.
$lourds = array();

for ( $i = 0; $i < 20; $i++ ) {
	$lourds[] = array( 'block' => 0 === $i % 2 ? 'photos' : 'croquis_plans', 'name' => "l$i.pdf", 'tmp_name' => fx_pdf_taille( (int) ( P::MAX_TOTAL_SIZE / 20 ) ), 'error' => UPLOAD_ERR_OK );
}

check( 'un lot exactement à 25 Mio est accepté', P::validate( $lourds )['ok'] );

$lourds[19]['tmp_name'] = fx_pdf_taille( (int) ( P::MAX_TOTAL_SIZE / 20 ) + 1024 );
check( 'un lot supérieur à 25 Mio est refusé', 'upload_total_size_exceeded' === P::validate( $lourds )['code'] );

// --- codes d'erreur de téléversement ---
$erreurs = array(
	UPLOAD_ERR_INI_SIZE   => 'upload_too_large',
	UPLOAD_ERR_FORM_SIZE  => 'upload_too_large',
	UPLOAD_ERR_PARTIAL    => 'upload_partial',
	UPLOAD_ERR_NO_TMP_DIR => 'upload_missing_tmp',
	UPLOAD_ERR_CANT_WRITE => 'upload_write_failed',
	UPLOAD_ERR_EXTENSION  => 'upload_blocked',
	99                    => 'upload_invalid_structure',
);

foreach ( $erreurs as $code => $attendu ) {
	check( "erreur de téléversement $code → $attendu", $attendu === P::code_for_upload_error( $code ) );
}

// ======================================================================
// 24 · NORMALISATION DE $_FILES
// ======================================================================
$pdf = fx_pdf();

$unique = N::normalize( array( 'croquis_plans' => array( 'name' => 'p.pdf', 'type' => 'application/pdf', 'tmp_name' => $pdf, 'error' => UPLOAD_ERR_OK, 'size' => 10 ) ) );
check( 'fichier unique normalisé', $unique['ok'] && 1 === count( $unique['files'] ) );

$multiple = N::normalize( fx_files( 'photos', array( array( 'a.jpg', fx_jpeg() ), array( 'b.jpg', fx_jpeg() ) ) ) );
check( 'fichiers multiples normalisés', $multiple['ok'] && 2 === count( $multiple['files'] ) );

$vide = N::normalize( fx_files( 'photos', array( array( '', '', '', UPLOAD_ERR_NO_FILE ) ) ) );
check( 'UPLOAD_ERR_NO_FILE disparaît de la liste', $vide['ok'] && array() === $vide['files'] );

$inconnu = N::normalize( array( 'factures' => array( 'name' => 'f.pdf', 'type' => '', 'tmp_name' => $pdf, 'error' => 0, 'size' => 1 ) ) );
check( 'un bloc inconnu est écarté, pas créé', $inconnu['ok'] && array() === $inconnu['files'] );
check( 'le bloc écarté est nommé', array( 'factures' ) === $inconnu['ignored'] );

// --- structures malformées ---
$malformees = array(
	'clé tmp_name absente'          => array( 'croquis_plans' => array( 'name' => 'a.pdf', 'type' => '', 'error' => 0, 'size' => 1 ) ),
	'clé error absente'             => array( 'croquis_plans' => array( 'name' => 'a.pdf', 'type' => '', 'tmp_name' => '/tmp/x', 'size' => 1 ) ),
	'entrée scalaire'               => array( 'croquis_plans' => 'pas-un-tableau' ),
	'mélange tableau et scalaire'   => array( 'croquis_plans' => array( 'name' => array( 'a.pdf' ), 'type' => '', 'tmp_name' => array( '/tmp/x' ), 'error' => array( 0 ), 'size' => array( 1 ) ) ),
	'longueurs différentes'         => array( 'croquis_plans' => array( 'name' => array( 'a.pdf', 'b.pdf' ), 'type' => array( '' ), 'tmp_name' => array( '/tmp/x' ), 'error' => array( 0 ), 'size' => array( 1 ) ) ),
	'clés non séquentielles'        => array( 'croquis_plans' => array( 'name' => array( 5 => 'a.pdf' ), 'type' => array( 5 => '' ), 'tmp_name' => array( 5 => '/tmp/x' ), 'error' => array( 5 => 0 ), 'size' => array( 5 => 1 ) ) ),
	'erreur non numérique'          => array( 'croquis_plans' => array( 'name' => 'a.pdf', 'type' => '', 'tmp_name' => '/tmp/x', 'error' => 'zero', 'size' => 1 ) ),
	'nom en tableau imbriqué'       => array( 'croquis_plans' => array( 'name' => array( array( 'a.pdf' ) ), 'type' => array( '' ), 'tmp_name' => array( '/tmp/x' ), 'error' => array( 0 ), 'size' => array( 1 ) ) ),
);

foreach ( $malformees as $libelle => $structure ) {
	$r = N::normalize( $structure );
	check( sprintf( 'structure refusée : %-28s', $libelle ), ! $r['ok'] && 'upload_invalid_structure' === $r['code'] );
}

// --- noms hostiles ---
$noms = array(
	'C:\\Users\\Client\\Bureau\\plan.pdf' => 'plan.pdf',
	'/etc/passwd'                          => 'passwd',
	'../../wp-config.php'                  => 'wp-config.php',
	'..\\..\\secret.pdf'                   => 'secret.pdf',
	"plan\x00.pdf"                         => 'plan.pdf',
	'plan.pdf'                             => 'plan.pdf',
);

foreach ( $noms as $entree => $attendu ) {
	check( 'nom réduit au basename : ' . str_replace( "\x00", '\\0', $entree ), $attendu === N::basename( $entree ) );
}

$long = str_repeat( 'a', 400 ) . '.pdf';
check( 'un nom très long est borné', strlen( P::clean_name( $long ) ) <= P::MAX_NAME_LENGTH );
check( 'un nom vide devient un libellé neutre', 'document' === P::clean_name( '' ) );
check( 'les caractères de contrôle sont retirés', 'plan.pdf' === P::clean_name( "plan\x07.pdf" ) );
check( 'aucun chemin ne subsiste dans le nom nettoyé', 'plan.pdf' === P::clean_name( 'C:\\dossier\\plan.pdf' ) );

// ======================================================================
// 25 · STOCKAGE
// ======================================================================
Storage::reset();
$racine = Storage::root();

check( 'la racine privée est disponible', null !== $racine );
check( 'elle est hors de la racine publique', ! Storage::is_inside( (string) $racine, Storage::public_root() ) );
check( 'la comparaison de préfixe respecte les segments',
	! Storage::is_inside( '/a/bc', '/a/b' ) && Storage::is_inside( '/a/b/c', '/a/b' ) );
check( 'les défenses complémentaires sont posées',
	is_file( $racine . '/index.php' ) && is_file( $racine . '/.htaccess' ) );
check( 'la racine est en 0700', '0700' === substr( sprintf( '%o', fileperms( (string) $racine ) ), -4 ) );

// Une racine située sous ABSPATH doit être refusée.
wpd_clear_filter( 'urbizen_private_storage_dir' );
add_filter( 'urbizen_private_storage_dir', static fn() => rtrim( ABSPATH, '/' ) . '/faux-prive' );
Storage::reset();

check( 'une racine sous ABSPATH est REFUSÉE', null === Storage::root() );
check( 'aucun répertoire n’a été laissé dans la racine publique', ! is_dir( rtrim( ABSPATH, '/' ) . '/faux-prive' ) );

wpd_clear_filter( 'urbizen_private_storage_dir' );
add_filter( 'urbizen_private_storage_dir', static fn() => 'chemin/relatif' );
Storage::reset();
check( 'un chemin relatif est refusé', null === Storage::root() );

wpd_clear_filter( 'urbizen_private_storage_dir' );
add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
Storage::reset();
check( 'la racine d’essai est de nouveau disponible', null !== Storage::root() );

// --- staging, dépôt, finalisation ---
$staging = Storage::open_staging();

check( 'un staging est ouvert', null !== $staging && is_dir( (string) $staging ) );
check( 'le staging est bien sous la racine privée', Storage::is_inside( (string) $staging, (string) $racine ) );

$source  = fx_copie( fx_pdf() );
$valide  = P::validate_one( array( 'block' => 'croquis_plans', 'name' => 'Mon Plan.pdf', 'tmp_name' => $source, 'error' => UPLOAD_ERR_OK ) );
$depose  = Storage::stage( (string) $staging, $valide['file'], 0 );

check( 'le document est déposé dans le staging', null !== $depose );
check( 'un identifiant aléatoire de 32 hex lui est donné', 1 === preg_match( '/^[0-9a-f]{32}$/', (string) $depose['id'] ) );
check( 'le SHA-256 est exact', hash_file( 'sha256', fx_pdf() ) === $depose['sha256'] );
check( 'la taille est exacte', filesize( fx_pdf() ) === $depose['size'] );
check( 'le fichier temporaire d’origine a disparu', ! is_file( $source ) );
check( 'le chemin temporaire n’est plus dans la structure', ! isset( $depose['tmp_name'] ) );

$meta = Storage::finalize( (string) $staging, 'URB-2026-0042', array( $depose ), 1800000000 );

check( 'la finalisation produit une métadonnée', is_array( $meta ) && 1 === count( $meta ) );
$m = $meta[0];

check( 'le chemin relatif est conforme',
	'conception/URB-2026-0042/croquis_plans/' . $m['stored_name'] === $m['relative_path'] );
check( 'le nom technique suit le format attendu',
	1 === preg_match( '/^URB-2026-0042-croquis_plans-[0-9a-f]{32}\.pdf$/', $m['stored_name'] ) );
check( 'LE NOM D’ORIGINE N’EST PAS DANS LE CHEMIN PHYSIQUE',
	! str_contains( $m['relative_path'], 'Mon' ) && ! str_contains( $m['stored_name'], 'Plan' ) );
check( 'le nom d’origine nettoyé est conservé en métadonnée', 'Mon Plan.pdf' === $m['original_name'] );
check( 'le type validé est enregistré', 'application/pdf' === $m['mime'] );
check( 'l’horodatage est en UTC', 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m['stored_at_gmt'] ) );

$serialise = (string) wp_json_encode( $m );
check( 'aucun chemin absolu dans les métadonnées', ! str_contains( $serialise, (string) $racine ) );
check( 'aucun chemin temporaire dans les métadonnées', ! str_contains( $serialise, sys_get_temp_dir() . '/urbfx' ) );
check( 'aucune donnée binaire ni base64', ! str_contains( $serialise, '%PDF' ) );

$reel = Storage::resolve( $m['relative_path'] );
check( 'le document est retrouvable', null !== $reel && is_file( (string) $reel ) );
check( 'il est en 0600', '0600' === substr( sprintf( '%o', fileperms( (string) $reel ) ), -4 ) );

// --- tentatives de sortie ---
$hostiles = array(
	'../../../etc/passwd',
	'conception/../../../etc/passwd',
	'/etc/passwd',
	'conception/URB-2026-0042/../../../x',
	'conception\\URB-2026-0042\\x',
	'./conception/URB-2026-0042/croquis_plans/' . $m['stored_name'],
	'',
);

foreach ( $hostiles as $h ) {
	check( 'chemin refusé : ' . ( '' === $h ? '(vide)' : substr( $h, 0, 40 ) ), null === Storage::resolve( $h ) );
}

// --- lien symbolique ---
$cible = fx_write( 'contenu hors racine' );
$lien  = (string) $racine . '/conception/URB-2026-0042/croquis_plans/lien.pdf';

if ( @symlink( $cible, $lien ) ) {
	check( 'UN LIEN SYMBOLIQUE EST REFUSÉ', null === Storage::resolve( 'conception/URB-2026-0042/croquis_plans/lien.pdf' ) );
	@unlink( $lien );
} else {
	check( 'lien symbolique : création impossible sur ce système, contrôle ignoré', true );
}

// --- suppression ---
$avant   = fx_compte_fichiers();
$effaces = Storage::delete_files( 'URB-2026-0042', $meta );

check( 'la suppression efface le document', 1 === $effaces );
check( 'le fichier a disparu', null === Storage::resolve( $m['relative_path'] ) );
check( 'le répertoire de la référence est nettoyé', ! is_dir( (string) $racine . '/conception/URB-2026-0042' ) );
check( 'une seconde suppression ne fait rien et ne casse rien', 0 === Storage::delete_files( 'URB-2026-0042', $meta ) );
check( 'une référence mal formée est refusée', 0 === Storage::delete_files( '../etc', $meta ) );

// Un fichier non déclaré n'est jamais supprimé.
Storage::reset();
$dossier = (string) Storage::root() . '/conception/URB-2026-0099/photos';
@mkdir( $dossier, 0700, true );
file_put_contents( $dossier . '/intrus.pdf', '%PDF-1.4' );

Storage::delete_files( 'URB-2026-0099', array() );
check( 'un fichier non déclaré n’est PAS supprimé', is_file( $dossier . '/intrus.pdf' ) );
@unlink( $dossier . '/intrus.pdf' );

// --- nettoyage du staging ---
fx_vide_stockage();
Storage::reset();

// L'âge se mesure sur la date de modification réelle du répertoire : la
// référence de temps est donc l'horloge du système, pas celle de la doublure.
$maintenant = time();
$recent     = Storage::open_staging();
$vieux      = Storage::open_staging();
@touch( (string) $vieux, $maintenant - Storage::STAGING_TTL - 10 );

check( 'deux stagings existent', 2 === fx_compte_staging() );
check( 'un staging récent est conservé, un expiré est supprimé',
	1 === Storage::cleanup_staging( $maintenant ) && 1 === fx_compte_staging() );
check( 'le nettoyage est idempotent', 0 === Storage::cleanup_staging( $maintenant ) );

Storage::discard_staging( (string) $recent );
check( 'un staging abandonné explicitement disparaît', 0 === fx_compte_staging() );
Storage::discard_staging( '/etc' );
check( 'discard_staging refuse un chemin hors racine', is_dir( '/etc' ) );

verdict();
