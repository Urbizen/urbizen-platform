<?php
/**
 * Banc d'essai des PROFILS D'UPLOAD par formulaire (Lot 1, incrément 5).
 *
 * UploadPolicy reste le moteur de sécurité générique ; ce qui est autorisé —
 * blocs, formats, quantités, tailles — vient d'un UploadProfile résolu depuis le
 * TYPE serveur via UploadProfileRegistry. Le navigateur transmet des fichiers,
 * jamais le profil qui les juge.
 *
 * Vérifie : le profil Conception inchangé (matrice figée), la résolution par
 * type, l'absence de dépôt pour Localisation, le refus des blocs/champs/fichiers
 * hostiles, l'impossibilité de choisir le profil depuis $_POST, et un profil
 * fictif prouvant la généricité du moteur.
 *
 * Réutilise le harnais de tests/submissions (finfo réel, fixtures fx_*, doubles
 * WordPress). Toutes les données sont fictives. Décision : D-050.
 */

require __DIR__ . '/../submissions/bootstrap.php';

use Urbizen\Platform\Files\UploadPolicy as P;
use Urbizen\Platform\Files\UploadNormalizer as N;
use Urbizen\Platform\Files\UploadProfile;
use Urbizen\Platform\Files\UploadProfileRegistry as R;

/** Entrée $_FILES brute (un fichier réel) pour un bloc/champ donné. */
function brut_reel( string $champ, string $tmp ): array {
	return array( $champ => array( 'name' => 'x.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 1 ) );
}

/** Entrée $_FILES brute réellement vide (champ non rempli). */
function brut_vide( string $champ ): array {
	return array( $champ => array( 'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 ) );
}

/** Document normalisé (post-UploadNormalizer). */
function doc( string $bloc, string $nom, string $tmp, int $err = UPLOAD_ERR_OK ): array {
	return array( 'block' => $bloc, 'name' => $nom, 'tmp_name' => $tmp, 'error' => $err );
}

/** Lot de $n PDF valides dans un bloc. */
function lot_pdf( string $bloc, int $n ): array {
	$out = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$out[] = doc( $bloc, "p$i.pdf", fx_pdf() );
	}
	return $out;
}

$CONCEPTION = R::for_type( 'conception' );
$BLOCS      = array( 'croquis_plans', 'plan_terrain', 'photos', 'inspirations_docs', 'urbanisme' );

// ======================================================================
// §13 · MATRICE DU PROFIL CONCEPTION — figée, identique à l'existant
// ======================================================================
check( '13 · le type conception résout un profil', $CONCEPTION instanceof UploadProfile );
check( '13 · dépôts ouverts', true === $CONCEPTION->uploads_enabled );
check( '13 · cinq blocs, à l’identique', $BLOCS === $CONCEPTION->blocks );
check( '13 · formats : pdf/jpg/jpeg/png/webp', array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) === $CONCEPTION->types );
check( '13 · 10 par bloc, 20 au total', 10 === $CONCEPTION->max_per_block && 20 === $CONCEPTION->max_total );
check( '13 · 10 Mio par fichier, 25 Mio au total', 10485760 === $CONCEPTION->max_file_size && 26214400 === $CONCEPTION->max_total_size );
check( '13 · le profil reflète exactement les constantes UploadPolicy',
	P::BLOCKS === $CONCEPTION->blocks && P::TYPES === $CONCEPTION->types
	&& P::MAX_PER_BLOCK === $CONCEPTION->max_per_block && P::MAX_TOTAL === $CONCEPTION->max_total );

$echecs_blocs = array();
foreach ( $BLOCS as $b ) {
	if ( ! P::validate( array( doc( $b, 'plan.pdf', fx_pdf() ) ), $CONCEPTION )['ok'] ) {
		$echecs_blocs[] = $b;
	}
}
check( '13 · un PDF valide est accepté dans chacun des cinq blocs', array() === $echecs_blocs );

// ======================================================================
// §14-A · RÉSOLUTION DU PROFIL DEPUIS LE TYPE SERVEUR
// ======================================================================
check( 'A · conception → profil ouvert', R::has( 'conception' ) && R::for_type( 'conception' )->uploads_enabled );
check( 'A · localisation → AUCUN profil (ne passe par aucune route commerciale)', false === R::has( 'localisation' ) && null === R::for_type( 'localisation' ) );
$sans_profil = array();
foreach ( array( 'dp', 'pc', 'pcmi', 'permis-general', 'cerfa', 'contact', 'inconnu' ) as $t ) {
	if ( null !== R::for_type( $t ) || R::has( $t ) ) {
		$sans_profil[] = $t;
	}
}
check( 'A · aucun profil pour dp/pc/pcmi/cerfa/… (null)', array() === $sans_profil );

// ======================================================================
// §14-B · BLOCS HOSTILES refusés par le profil Conception
// ======================================================================
$blocs_hostiles = array( 'factures', '', '../photos', 'photоs' /* cyrillique */, str_repeat( 'a', 200 ), 'piece_devis' );
$echecs_b = array();
foreach ( $blocs_hostiles as $b ) {
	$r = P::validate( array( doc( $b, 'x.pdf', fx_pdf() ) ), $CONCEPTION );
	if ( $r['ok'] || 'upload_invalid_structure' !== $r['code'] ) {
		$echecs_b[] = $b;
	}
}
check( 'B · tout bloc inconnu/vide/traversal/unicode/long/étranger → upload_invalid_structure', array() === $echecs_b );

// ======================================================================
// §14-C · CHAMP / structure de document
// ======================================================================
check( 'C · un document sans clé « block » est refusé', 'upload_invalid_structure' === P::validate( array( array( 'name' => 'x.pdf', 'tmp_name' => fx_pdf(), 'error' => UPLOAD_ERR_OK ) ), $CONCEPTION )['code'] );
check( 'C · un document valide dans un bloc autorisé passe', P::validate( array( doc( 'photos', 'p.jpg', fx_jpeg() ) ), $CONCEPTION )['ok'] );

// ======================================================================
// §14-D · FICHIERS — le profil Conception donne exactement les mêmes verdicts
// ======================================================================
$un = static fn( string $nom, string $tmp, int $err = UPLOAD_ERR_OK ) => P::validate_one( doc( 'croquis_plans', $nom, $tmp, $err ), $CONCEPTION );
check( 'D · PDF valide accepté', $un( 'plan.pdf', fx_pdf() )['ok'] );
check( 'D · extension majuscule normalisée', $un( 'PLAN.PDF', fx_pdf() )['ok'] );
check( 'D · PHP renommé en JPG → upload_invalid_mime', 'upload_invalid_mime' === $un( 'photo.jpg', fx_php() )['code'] );
check( 'D · double extension trompeuse → upload_invalid_extension', 'upload_invalid_extension' === $un( 'photo.jpg.php', fx_php() )['code'] );
check( 'D · sans extension → upload_invalid_extension', 'upload_invalid_extension' === $un( 'document', fx_pdf() )['code'] );
check( 'D · fichier vide → upload_empty_file', 'upload_empty_file' === $un( 'vide.pdf', fx_vide() )['code'] );
check( 'D · trop volumineux → upload_too_large', 'upload_too_large' === $un( 'gros.pdf', fx_pdf_taille( P::MAX_FILE_SIZE + 4096 ) )['code'] );
check( 'D · tmp absent → upload_invalid_structure', 'upload_invalid_structure' === $un( 'x.pdf', '/inexistant/nulle-part', UPLOAD_ERR_OK )['code'] );
check( 'D · erreur PHP d’upload → refus', ! $un( 'x.pdf', fx_pdf(), UPLOAD_ERR_INI_SIZE )['ok'] );
check( 'D · onze dans un bloc → upload_count_exceeded', 'upload_count_exceeded' === P::validate( lot_pdf( 'photos', 11 ), $CONCEPTION )['code'] );
check( 'D · vingt-et-un au total → upload_count_exceeded', 'upload_count_exceeded' === P::validate( array_merge( lot_pdf( 'photos', 10 ), lot_pdf( 'croquis_plans', 10 ), lot_pdf( 'urbanisme', 1 ) ), $CONCEPTION )['code'] );

// ======================================================================
// §14-E · LE CLIENT NE CHOISIT PAS LE PROFIL
// ======================================================================
// Statique : ni le contrôleur ni le registre ne lisent un profil/bloc depuis
// une superglobale (code seul, commentaires retirés).
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
	return $out;
};
$ctrl = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Http/SubmissionController.php' );
$reg  = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Files/UploadProfileRegistry.php' );
check( 'E · le contrôleur ne lit aucun upload_profile / block depuis $_POST',
	! str_contains( $ctrl, "upload_profile" ) && ! str_contains( $ctrl, "\$_POST['block']" ) && ! str_contains( $ctrl, '$_POST["block"]' ) );
check( 'E · le registre ne consulte aucune superglobale', ! str_contains( $reg, '$_POST' ) && ! str_contains( $reg, '$_GET' ) && ! str_contains( $reg, '$_FILES' ) );
// Unité : un bloc d'un AUTRE profil est refusé par le profil Conception.
check( 'E · un bloc étranger (pc_documents) est refusé par Conception', 'upload_invalid_structure' === P::validate( array( doc( 'pc_documents', 'x.pdf', fx_pdf() ) ), $CONCEPTION )['code'] );
check( 'E · for_type() est une fonction pure du type (conception≠dp)', R::for_type( 'conception' ) instanceof UploadProfile && null === R::for_type( 'dp' ) );

// ======================================================================
// §14-F · EFFETS SECONDAIRES — un profil fermé refuse sans rien retenir
// ======================================================================
// Un profil fermé explicite (dépôts désactivés) refuse tout, sans rien retenir.
$FERME = new UploadProfile( 'ferme', array(), array(), 0, 0, 1, 1, false );
check( 'F · profil fermé + fichiers → upload_not_allowed', 'upload_not_allowed' === P::validate( array( doc( 'photos', 'x.pdf', fx_pdf() ) ), $FERME )['code'] );
check( 'F · profil fermé + aucun fichier → succès (rien à retenir)', P::validate( array(), $FERME )['ok'] );
check( 'F · un refus ne retient aucun fichier', array() === P::validate( array( doc( 'photos', 'x.pdf', fx_pdf() ) ), $FERME )['files'] );

// ======================================================================
// §15 · PROFIL FICTIF — le moteur est générique
// ======================================================================
$FICTIF = new UploadProfile(
	'devis_fictif',
	array( 'piece_devis' ),                 // bloc différent
	array( 'pdf' => 'application/pdf' ),     // PDF seulement
	2,                                       // 2 par bloc
	3,                                       // 3 au total
	1048576,                                 // 1 Mio par fichier
	2097152,                                 // 2 Mio au total
	true,
);
check( '15 · le profil fictif accepte un PDF dans son bloc', P::validate( array( doc( 'piece_devis', 'devis.pdf', fx_pdf() ) ), $FICTIF )['ok'] );
check( '15 · le profil fictif refuse un JPG (format hors profil)', 'upload_invalid_extension' === P::validate_one( doc( 'piece_devis', 'photo.jpg', fx_jpeg() ), $FICTIF )['code'] );
check( '15 · un bloc Conception (croquis_plans) est refusé par le profil fictif', 'upload_invalid_structure' === P::validate( array( doc( 'croquis_plans', 'x.pdf', fx_pdf() ) ), $FICTIF )['code'] );
check( '15 · le bloc fictif (piece_devis) est refusé par le profil Conception', 'upload_invalid_structure' === P::validate( array( doc( 'piece_devis', 'x.pdf', fx_pdf() ) ), $CONCEPTION )['code'] );
check( '15 · les limites de quantité fictives mordent', 'upload_count_exceeded' === P::validate( lot_pdf_bloc_fictif(), $FICTIF )['code'] );
check( '15 · le profil fictif n’est PAS enregistrable depuis une soumission', null === R::for_type( 'devis_fictif' ) );

// Le moteur générique (l'objet-valeur) ne porte aucune chaîne métier.
$src_profil = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Files/UploadProfile.php' );
$interdits  = array( 'conception', 'croquis_plans', 'urbanisme', 'urbizen_conception', 'cerfa', 'permis' );
$fuites     = array_filter( $interdits, static fn( $s ) => str_contains( strtolower( $src_profil ), $s ) );
check( '15 · l’objet UploadProfile ne contient aucune chaîne métier', array() === $fuites );

// ======================================================================
// §7 · ROUTE CONCEPTION — tentative réelle hors profil = rejet explicite
// ======================================================================
$hostiles_reels = array( 'pc_documents', 'dp_documents', '../photos', 'photоs' /* cyrillique */, 'champ_arbitraire', str_repeat( 'a', 200 ) );
$echecs_7       = array();
foreach ( $hostiles_reels as $champ ) {
	$r = N::normalize( brut_reel( $champ, fx_pdf() ), $CONCEPTION );
	if ( $r['ok'] || 'upload_invalid_structure' !== $r['code'] || array() !== $r['files'] ) {
		$echecs_7[] = $champ;
	}
}
check( '7 · un VRAI fichier dans pc_documents/dp_documents/traversal/unicode/arbitraire → rejet', array() === $echecs_7 );

// Un emplacement inconnu réellement vide (champ non rempli) reste sûr : ignoré.
$vide = N::normalize( brut_vide( 'champ_inconnu' ), $CONCEPTION );
check( '7 · un champ inconnu réellement vide (NO_FILE) est ignoré, sans faux rejet', $vide['ok'] && array() === $vide['files'] && array( 'champ_inconnu' ) === $vide['ignored'] );

// Champ Conception valide + champ interdit dans le même lot → lot entier rejeté.
$mixte = N::normalize(
	array_merge(
		fx_files( 'croquis_plans', array( array( 'plan.pdf', fx_pdf() ) ) ),
		brut_reel( 'pc_documents', fx_pdf() )
	),
	$CONCEPTION
);
check( '7 · lot mixte (bloc Conception valide + champ interdit) → lot entier rejeté', ! $mixte['ok'] && 'upload_invalid_structure' === $mixte['code'] && array() === $mixte['files'] );

/** Quatre PDF fictifs dans le bloc du profil fictif (dépasse le total de 3). */
function lot_pdf_bloc_fictif(): array {
	$out = array();
	for ( $i = 0; $i < 4; $i++ ) {
		$out[] = doc( 'piece_devis', "d$i.pdf", fx_pdf() );
	}
	return $out;
}

verdict();
