<?php
/**
 * Banc d'essai du PIPELINE d'upload entièrement piloté par un profil serveur
 * (Lot 1, incrément 5 bis).
 *
 * Démontre qu'un profil AUTRE que Conception traverse, sans une ligne de code
 * métier, toute la chaîne : normalisation → validation → manifeste → staging →
 * finalisation. Et qu'un profil manquant ne réveille JAMAIS les règles
 * Conception : le moteur exige un profil explicite.
 *
 * Réutilise le harnais tests/submissions (fixtures fx_*, doubles WordPress,
 * doublure de déplacement de fichiers). Données fictives. Décision : D-050.
 */

require __DIR__ . '/../submissions/bootstrap.php';

use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Files\UploadManifest;
use Urbizen\Platform\Files\UploadNormalizer as N;
use Urbizen\Platform\Files\UploadPolicy as P;
use Urbizen\Platform\Files\UploadProfile;
use Urbizen\Platform\Files\UploadProfileRegistry as R;

// Stockage privé temporaire, hors racine publique.
$RACINE = sys_get_temp_dir() . '/urb-pipeline-' . getmypid();
@mkdir( $RACINE, 0700, true );
add_filter( 'urbizen_private_storage_dir', static fn() => $RACINE );

// Profil FICTIF : bloc, format et limites distincts de Conception.
$FICTIF = new UploadProfile(
	'devis_fictif',
	array( 'piece_devis' ),
	array( 'pdf' => 'application/pdf' ),
	3,
	5,
	5 * 1024 * 1024,
	10 * 1024 * 1024,
	true,
);

// $_FILES : un PDF dans le bloc fictif + un PDF dans un bloc Conception.
$files = array_merge(
	fx_files( 'piece_devis', array( array( 'devis.pdf', fx_copie( fx_pdf() ) ) ) ),
);

// ======================================================================
// §11-A · SCÉNARIO SUCCÈS — le profil fictif traverse toute la chaîne
// ======================================================================
$norm = N::normalize( $files, $FICTIF );
check( '11-A · normalisation OK (bloc fictif seul)', $norm['ok'] && 1 === count( $norm['files'] ) && 'piece_devis' === $norm['files'][0]['block'] );

$val = P::validate( $norm['files'], $FICTIF );
check( '11-A · validation selon le profil fictif : OK', $val['ok'] && 1 === count( $val['files'] ) );

$taille = (int) $val['files'][0]['size'];
$decl   = (string) wp_json_encode(
	array(
		'version'     => 1,
		'total_count' => 1,
		'total_size'  => $taille,
		'blocks'      => array( 'piece_devis' => array( 'count' => 1, 'size' => $taille ) ),
	)
);
check( '11-A · le manifeste accepte le bloc fictif (schéma inchangé)', UploadManifest::verify( $decl, $norm['files'] )['ok'] );

$staging = Storage::open_staging();
check( '11-A · staging ouvert', null !== $staging );

$depose = Storage::stage( $staging, $val['files'][0], 0 );
check( '11-A · le fichier fictif est déposé (SHA-256, bloc fictif)', null !== $depose && isset( $depose['sha256'] ) && 'piece_devis' === $depose['block'] );

$meta = Storage::finalize( $staging, 'URB-2026-0001', array( $depose ), 1700000000 );
check( '11-A · finalisation : le bloc fictif traverse le stockage', is_array( $meta ) && 1 === count( $meta ) && 'piece_devis' === $meta[0]['block'] );

Storage::rollback( array_column( is_array( $meta ) ? $meta : array(), 'final' ) );

// ======================================================================
// §11-B · BLOC CONCEPTION SOUS PROFIL FICTIF — rejet explicite, pas d'oubli
// ======================================================================
$files_b = fx_files( 'croquis_plans', array( array( 'plan.pdf', fx_copie( fx_pdf() ) ) ) );
$norm_b  = N::normalize( $files_b, $FICTIF );
check( '11-B · un VRAI fichier hors profil est REJETÉ, pas ignoré', ! $norm_b['ok'] && 'upload_invalid_structure' === $norm_b['code'] && array() === $norm_b['files'] );

// ======================================================================
// §11-C · LOT MIXTE — le lot entier est rejeté, aucune persistance partielle
// ======================================================================
$files_c = array_merge(
	fx_files( 'piece_devis', array( array( 'devis.pdf', fx_copie( fx_pdf() ) ) ) ),
	fx_files( 'croquis_plans', array( array( 'plan.pdf', fx_copie( fx_pdf() ) ) ) ),
);
$norm_c = N::normalize( $files_c, $FICTIF );
check( '11-C · lot mixte (fictif + interdit) → lot entier rejeté', ! $norm_c['ok'] && 'upload_invalid_structure' === $norm_c['code'] && array() === $norm_c['files'] );

// Les composants réellement génériques ne portent aucune chaîne Conception.
$code_seul = static function ( string $chemin ): string {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $chemin ) ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= $t[1];
		} else {
			$out .= $t;
		}
	}
	return strtolower( $out );
};
$src_norm = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Files/UploadNormalizer.php' );
$interdits = array( 'is_block', 'blocks', 'croquis_plans', 'urbanisme', 'conception' );
$fuites    = array_filter( $interdits, static fn( $s ) => str_contains( $src_norm, $s ) );
check( '11 · UploadNormalizer ne référence plus aucun bloc/liste Conception', array() === $fuites );

// ======================================================================
// §12 · PROFIL MANQUANT — jamais de repli sur Conception
// ======================================================================
check( '12 · for_type(inconnu) → null', null === R::for_type( 'inconnu' ) && null === R::for_type( 'localisation' ) );

$leve = false;
try {
	R::require_for_type( 'inconnu' );
} catch ( \RuntimeException $e ) {
	$leve = true;
}
check( '12 · require_for_type(inconnu) → exception contrôlée', $leve );

// Les signatures interdisent l'oubli du profil : plus de repli implicite.
check( '12 · UploadPolicy::validate exige un profil explicite', 2 === ( new ReflectionMethod( P::class, 'validate' ) )->getNumberOfRequiredParameters() );
check( '12 · UploadPolicy::validate_one exige un profil explicite', 2 === ( new ReflectionMethod( P::class, 'validate_one' ) )->getNumberOfRequiredParameters() );
check( '12 · UploadNormalizer::normalize exige un profil explicite', 2 === ( new ReflectionMethod( N::class, 'normalize' ) )->getNumberOfRequiredParameters() );

// Un profil fermé + fichiers → refus, jamais les règles Conception.
$FERME = new UploadProfile( 'ferme', array(), array(), 0, 0, 1, 1, false );
check( '12 · profil fermé + fichiers → upload_not_allowed (pas de repli)', 'upload_not_allowed' === P::validate( array( array( 'block' => 'photos', 'name' => 'x.pdf', 'tmp_name' => fx_pdf(), 'error' => UPLOAD_ERR_OK ) ), $FERME )['code'] );

verdict();
