<?php
/**
 * Banc de mutation du parcours de conception.
 *
 * Chaque scénario casse **une** règle — dans une copie, jamais dans le dépôt —
 * et vérifie que le contrôle correspondant tombe. Lorsqu'un comportement peut
 * être éprouvé réellement, il l'est : la recherche textuelle ne sert que là où
 * il n'y a rien à exécuter.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Conception\ConceptionAssets;
use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Files\UploadManifest;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

$compteur = 0;

function mutant( string $relatif, string $classe, array $remplacements ): string {
	global $compteur;

	$source  = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $relatif );
	$nouveau = $classe . 'MutantC' . ( ++$compteur );
	$source  = str_replace( "final class $classe", "final class $nouveau", $source );

	foreach ( $remplacements as $de => $vers ) {
		if ( ! str_contains( $source, $de ) ) {
			throw new RuntimeException( "motif introuvable dans $relatif : $de" );
		}

		$source = str_replace( $de, $vers, $source );
	}

	preg_match( '/^namespace\s+([^;]+);/m', $source, $ns );

	$fichier = sys_get_temp_dir() . '/urbizen-' . $nouveau . '.php';
	file_put_contents( $fichier, $source );
	require $fichier;
	unlink( $fichier );

	return '\\' . trim( $ns[1] ) . '\\' . $nouveau;
}

function neuf(): void {
	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
	FileCleaner::reset();
	ConceptionRenderer::reset();
	ConceptionAssets::register();
	update_option( 'admin_email', 'dossiers@urbizen.test' );
	$GLOBALS['wpd_logged_in'] = false;
	$GLOBALS['wpd_can']       = false;
}

function lot( string $bloc, int $n ): array {
	$f = array();

	for ( $i = 0; $i < $n; $i++ ) {
		$f[] = array( 'doc-' . $i . '.jpg', fx_copie( fx_jpeg() ) );
	}

	return fx_files( $bloc, $f );
}

function soumettre( array $files, $manifeste ) {
	$post = soumission();

	if ( null !== $manifeste ) {
		$post[ UploadManifest::FIELD ] = $manifeste;
	}

	return \Urbizen\Platform\Http\SubmissionController::process( $post, $files, serveur(), wpd_now() );
}

function rien_cree(): bool {
	return array() === $GLOBALS['wpd_posts']
		&& 0 === fx_compte_fichiers()
		&& 0 === fx_compte_staging()
		&& array() === $GLOBALS['wpd_mails'];
}

$def = FormRegistry::get( 'conception' );

// ====== 1 · activation publique par paramètre d'URL ======================
$av = mutant(
	'src/Conception/ConceptionAvailability.php',
	'ConceptionAvailability',
	array( "		return true === apply_filters( 'urbizen_conception_public_enabled', false );" =>
		"		if ( isset( \$_GET['conception'] ) ) { return true; }\n\n		return true === apply_filters( 'urbizen_conception_public_enabled', false );" )
);

neuf();
$_GET['conception'] = '1';

check( '1 · muté → UN PARAMÈTRE D’URL OUVRE LE FORMULAIRE', true === $av::is_public() );
check( '1 · le dépôt reste fermé', false === \Urbizen\Platform\Conception\ConceptionAvailability::is_public() );
check( '1 · et ne rend rien', '' === ConceptionRenderer::render( $def ) );

$_GET = array();

// ====== 2 · assets chargés pour un anonyme ==============================
$as = mutant(
	'src/Conception/ConceptionAssets.php',
	'ConceptionAssets',
	array( "		if ( ! ConceptionAvailability::can_render() ) {
			return;
		}" => '		// garde d\'accès retirée.' )
);

neuf();
$as::register();
$as::enqueue( $def, 'x' );

check( '2 · garde retirée → LES RESSOURCES PARTENT POUR UN ANONYME',
	array() !== $GLOBALS['wpd_styles'] );

neuf();
ConceptionAssets::enqueue( $def, 'x' );

check( '2 · le dépôt ne charge rien', array() === $GLOBALS['wpd_styles'] && array() === $GLOBALS['wpd_scripts'] );
check( '2 · ni schéma', array() === $GLOBALS['wpd_inline'] );

// ====== 3 · filesize false transformé en zéro ===========================
// Deux barrières se superposent : le contrôle d'existence, et le refus de
// convertir `false` en zéro. On mesure d'abord chacune seule, puis les deux.
$mf1 = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array( "		return false === \$taille ? null : (int) \$taille;" => '		return (int) $taille;' )
);

$mf2 = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array(
		"		if ( ! @is_file( \$tmp ) || ! @is_readable( \$tmp ) ) {
			return null;
		}" => '		// contrôle d\'existence retiré.',
		"		return false === \$taille ? null : (int) \$taille;" => '		return (int) $taille;',
	)
);

neuf();
$un = lot( 'photos', 1 );
unlink( $un['photos']['tmp_name'][0] );
$n = \Urbizen\Platform\Files\UploadNormalizer::normalize( $un );

check( '3 · conversion seule retirée, le contrôle d’existence protège', null === $mf1::from_files( $n['files'] ) );
check( '3 · LES DEUX RETIRÉES → UNE TAILLE DE ZÉRO EST INVENTÉE',
	is_array( $mf2::from_files( $n['files'] ) ) && 0 === $mf2::from_files( $n['files'] )['total_size'] );
check( '3 · le dépôt rend null', null === UploadManifest::from_files( $n['files'] ) );

// ====== 4 · repli vers declared_size ====================================
$md = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array( "		return false === \$taille ? null : (int) \$taille;" =>
		"		return false === \$taille ? (int) ( \$document['declared_size'] ?? 0 ) : (int) \$taille;",
		"		if ( ! @is_file( \$tmp ) || ! @is_readable( \$tmp ) ) {
			return null;
		}" => "		if ( ! @is_file( \$tmp ) || ! @is_readable( \$tmp ) ) {
			return (int) ( \$document['declared_size'] ?? 0 );
		}" )
);

neuf();
$un = lot( 'photos', 1 );
$taille_reelle = (int) filesize( $un['photos']['tmp_name'][0] );
unlink( $un['photos']['tmp_name'][0] );
$n = \Urbizen\Platform\Files\UploadNormalizer::normalize( $un );
$m = $md::from_files( $n['files'] );

check( '4 · repli sur declared_size → une taille est inventée',
	is_array( $m ) && $taille_reelle === $m['total_size'] );
check( '4 · le dépôt refuse de deviner', null === UploadManifest::from_files( $n['files'] ) );

// ====== 5 · manifeste absent accepté avec fichiers ======================
$ma = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array( "			return 0 === \$reel['total_count']
				? array( 'ok' => true, 'code' => self::OK )
				: array( 'ok' => false, 'code' => self::MANIFEST_MISSING );" =>
		"			return array( 'ok' => true, 'code' => self::OK );" )
);

neuf();
$un = lot( 'photos', 1 );
$n  = \Urbizen\Platform\Files\UploadNormalizer::normalize( $un );

check( '5 · absence tolérée → un fichier sans manifeste passe',
	true === $ma::verify( null, $n['files'] )['ok'] );
check( '5 · le dépôt refuse', UploadManifest::MANIFEST_MISSING === UploadManifest::verify( null, $n['files'] )['code'] );

neuf();
$r = soumettre( lot( 'photos', 1 ), null );

check( '5 · et la soumission est refusée', SubmissionResult::UPLOAD_MANIFEST_MISSING === $r->code() );
check( '5 · rien n’est créé', rien_cree() );

// ====== 6 · comparaison du nombre total désactivée ======================
// Là encore deux barrières : le total, et le nombre par bloc.
$mc1 = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array( "		if ( \$declare['total_count'] !== \$reel['total_count'] ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}" => '		// comparaison du nombre total retirée.' )
);

$mc = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array(
		"		if ( \$declare['total_count'] !== \$reel['total_count'] ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}" => '		// comparaison du nombre total retirée.',
		"		if ( \$declare['total_size'] !== \$reel['total_size'] ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}" => '		// comparaison de la taille totale retirée.',
		"			if ( \$chiffres['count'] !== \$reel['blocks'][ \$bloc ]['count'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}" => '			// comparaison du nombre par bloc retirée.',
		"			if ( \$chiffres['size'] !== \$reel['blocks'][ \$bloc ]['size'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}" => '			// comparaison de la taille par bloc retirée.',
	)
);

neuf();
$vingt   = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 );
$annonce = fx_manifeste( $vingt );
$tronque = $vingt;

foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $cle ) {
	array_pop( $tronque['plan_terrain'][ $cle ] );
}

$nt = \Urbizen\Platform\Files\UploadNormalizer::normalize( $tronque );

check( '6 · total seul retiré, le nombre par bloc protège encore', false === $mc1::verify( $annonce, $nt['files'] )['ok'] );
check( '6 · TOUTES RETIRÉES → 20 ANNONCÉS / 19 REÇUS EST ACCEPTÉ', true === $mc::verify( $annonce, $nt['files'] )['ok'] );

neuf();
$r = soumettre( $tronque, $annonce );

check( '6 · le dépôt refuse 20 annoncés / 19 reçus', SubmissionResult::UPLOAD_INCOMPLETE === $r->code() );
check( '6 · rien n’est créé', rien_cree() );

// ====== 7 · comparaison des tailles désactivée ==========================
$ms = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array( "		if ( \$declare['total_size'] !== \$reel['total_size'] ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}" => '		// comparaison de la taille totale retirée.',
		"			if ( \$chiffres['size'] !== \$reel['blocks'][ \$bloc ]['size'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}" => '			// comparaison de la taille par bloc retirée.' )
);

neuf();
$un = lot( 'photos', 1 );
$n  = \Urbizen\Platform\Files\UploadNormalizer::normalize( $un );
$fausse = (string) wp_json_encode(
	array( 'version' => 1, 'total_count' => 1, 'total_size' => 99999, 'blocks' => array( 'photos' => array( 'count' => 1, 'size' => 99999 ) ) )
);

check( '7 · comparaison des tailles retirée → une taille fausse passe', true === $ms::verify( $fausse, $n['files'] )['ok'] );
check( '7 · le dépôt refuse', false === UploadManifest::verify( $fausse, $n['files'] )['ok'] );

// ====== 8 · comparaison des blocs désactivée ============================
$mb = mutant(
	'src/Files/UploadManifest.php',
	'UploadManifest',
	array( "		if ( array_keys( \$declare['blocks'] ) !== array_keys( \$reel['blocks'] ) ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}" => '		// comparaison des blocs retirée.',
		"			if ( \$chiffres['count'] !== \$reel['blocks'][ \$bloc ]['count'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}" => '			// comparaison du nombre par bloc retirée.',
		"			if ( \$chiffres['size'] !== \$reel['blocks'][ \$bloc ]['size'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}" => '			// comparaison de la taille par bloc retirée.' )
);

neuf();
$un = lot( 'photos', 1 );
$n  = \Urbizen\Platform\Files\UploadNormalizer::normalize( $un );
$m  = UploadManifest::from_files( $n['files'] );
$autre_bloc = (string) wp_json_encode(
	array( 'version' => 1, 'total_count' => 1, 'total_size' => $m['total_size'], 'blocks' => array( 'urbanisme' => array( 'count' => 1, 'size' => $m['total_size'] ) ) )
);

check( '8 · comparaison des blocs retirée → un autre bloc passe', true === $mb::verify( $autre_bloc, $n['files'] )['ok'] );
check( '8 · le dépôt refuse', false === UploadManifest::verify( $autre_bloc, $n['files'] )['ok'] );

// ====== 9 · le manifeste contourne UploadPolicy =========================
// Le manifeste est vérifié **après** la politique : un manifeste exact ne peut
// donc rien autoriser. On le prouve en exécutant, pas en lisant le code.
neuf();
$svg  = fx_write_brut( '<svg xmlns="http://www.w3.org/2000/svg"></svg>' );
$faux = fx_files( 'croquis_plans', array( array( 'dessin.svg', $svg ) ) );
$r    = soumettre( $faux, fx_manifeste( $faux ) );

check( '9 · manifeste exact, extension toujours refusée', ! $r->is_success() );
check( '9 · le motif reste celui de la politique',
	in_array( $r->code(), array( SubmissionResult::UPLOAD_INVALID_EXTENSION, SubmissionResult::UPLOAD_INVALID_MIME ), true ) );

neuf();
$onze = lot( 'photos', 11 );
$r    = soumettre( $onze, fx_manifeste( $onze ) );

check( '9 · manifeste exact, limite par bloc toujours appliquée', SubmissionResult::UPLOAD_COUNT_EXCEEDED === $r->code() );
check( '9 · rien n’est créé', rien_cree() );

// ====== 10 · une notification est créée malgré un refus =================
neuf();
$vingt   = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 );
$annonce = fx_manifeste( $vingt );
$tronque = $vingt;

foreach ( array( 'name', 'type', 'tmp_name', 'error', 'size' ) as $cle ) {
	array_pop( $tronque['plan_terrain'][ $cle ] );
}

soumettre( $tronque, $annonce );

$notifs = array_filter(
	array_keys( $GLOBALS['wpd_meta'] ),
	static fn( $id ) => MailPolicy::PENDING === ( $GLOBALS['wpd_meta'][ $id ][ MailPolicy::META_STATUS ] ?? '' )
);

check( '10 · aucune notification pending après refus', array() === $notifs );
check( '10 · aucun événement mail', array() === ( $GLOBALS['wpd_cron'][ MailPolicy::EVENT ] ?? array() ) );
check( '10 · aucune référence attribuée',
	array() === array_filter(
		array_keys( $GLOBALS['wpd_options'] ),
		static fn( $c ) => str_starts_with( $c, SubmissionRepository::RESERVATION_PREFIX )
			&& 'attributed' === ( get_option( $c )['state'] ?? '' )
	) );

// ====== 11 · un document final subsiste après retour arrière ============
neuf();
soumettre( $tronque, $annonce );

check( '11 · aucun document final après refus', 0 === fx_compte_fichiers() );
check( '11 · aucun staging résiduel', 0 === fx_compte_staging() );

// Et le témoin : sans troncature, tout aboutit.
neuf();
$vingt = lot( 'photos', 10 ) + lot( 'plan_terrain', 10 );
$r     = soumettre( $vingt, fx_manifeste( $vingt ) );

check( '11 · TÉMOIN : 20 annoncés, 20 reçus → succès', $r->is_success() );
check( '11 · vingt documents stockés', 20 === fx_compte_fichiers() );
check( '11 · notification pending', MailPolicy::PENDING === get_post_meta( $r->id(), MailPolicy::META_STATUS, true ) );

// ====== 12 · le prix du navigateur servirait de vérité ==================
neuf();
$post = soumission();
$post['prix_total'] = '1';
$post['total']      = '1';
$r = \Urbizen\Platform\Http\SubmissionController::process( $post, array(), serveur(), wpd_now() );

check( '12 · la soumission aboutit', $r->is_success() );

$pricing = json_decode( (string) get_post_meta( $r->id(), '_urbizen_pricing', true ), true );

check( '12 · LE PRIX EST CELUI DU SERVEUR', 449 === (int) ( $pricing['base'] ?? 0 ) );
check( '12 · la valeur envoyée par le navigateur est ignorée', 1 !== (int) ( $pricing['total'] ?? 0 ) );

$payload = json_decode( (string) get_post_meta( $r->id(), '_urbizen_payload', true ), true );

check( '12 · et n’est pas conservée comme réponse', ! isset( $payload['prix_total'] ) );

verdict();
