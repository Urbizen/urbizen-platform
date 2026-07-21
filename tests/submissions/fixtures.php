<?php
/**
 * Fabriques de fichiers d'essai.
 *
 * Chaque fichier porte une **véritable signature de format**, afin que
 * `finfo` en déduise le type réel comme il le ferait en production. Un banc
 * qui se contenterait de renommer un fichier texte ne prouverait rien.
 *
 * Tous les fichiers sont créés dans le répertoire temporaire du système et
 * détruits à la fin du processus.
 */

require_once __DIR__ . '/test-mover.php';

$GLOBALS['fx_temp']  = array();
$GLOBALS['fx_mover'] = new FixtureFileMover();

\Urbizen\Platform\Files\Storage::set_mover( $GLOBALS['fx_mover'] );

register_shutdown_function(
	static function () {
		foreach ( $GLOBALS['fx_temp'] as $f ) {
			@unlink( $f );
		}
	}
);

/**
 * Écrit un contenu dans un fichier temporaire.
 *
 * @param string $contenu Contenu binaire.
 * @return string Chemin.
 */
function fx_write( string $contenu ): string {
	$chemin = tempnam( sys_get_temp_dir(), 'urbfx' );
	file_put_contents( $chemin, $contenu );
	$GLOBALS['fx_temp'][] = $chemin;

	return $GLOBALS['fx_mover']->autoriser( $chemin );
}

/**
 * Écrit un fichier temporaire **sans** le déclarer comme téléversé.
 *
 * Sert à éprouver le refus des chemins forgés : ce fichier existe, mais aucun
 * upload HTTP ne l'a produit.
 *
 * @param string $contenu Contenu.
 * @return string
 */
function fx_write_brut( string $contenu ): string {
	$chemin = tempnam( sys_get_temp_dir(), 'urbfx' );
	file_put_contents( $chemin, $contenu );
	$GLOBALS['fx_temp'][] = $chemin;

	return $chemin;
}

/** PDF minimal, reconnu comme application/pdf. */
function fx_pdf(): string {
	return fx_write( "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" );
}

/** JPEG minimal, reconnu comme image/jpeg. */
function fx_jpeg(): string {
	return fx_write(
		"\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
		. "\xFF\xDB\x00\x43\x00" . str_repeat( "\x08", 64 )
		. "\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00"
		. "\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xD2\xCF\x20\xFF\xD9"
	);
}

/** PNG minimal 1×1, reconnu comme image/png. */
function fx_png(): string {
	return fx_write( (string) base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );
}

/** WEBP minimal, reconnu comme image/webp. */
function fx_webp(): string {
	return fx_write( (string) base64_decode( 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA' ) );
}

/** SVG — format volontairement refusé. */
function fx_svg(): string {
	return fx_write( '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><text>x</text></svg>' );
}

/** Script PHP, quel que soit le nom qu'on lui donnera. */
function fx_php(): string {
	return fx_write( "<?php\n echo 'compromis';\n" );
}

/** Fichier vide. */
function fx_vide(): string {
	return fx_write( '' );
}

/** Fichier d'une taille donnée, au format PDF. */
function fx_pdf_taille( int $octets ): string {
	$entete = "%PDF-1.4\n";
	$reste  = max( 0, $octets - strlen( $entete ) );

	return fx_write( $entete . str_repeat( 'A', $reste ) );
}

/**
 * Construit une entrée `$_FILES` pour un bloc.
 *
 * @param string             $bloc     Bloc.
 * @param array<int, array>  $fichiers Couples [nom, chemin].
 * @return array<string, array<string, array<int, mixed>>>
 */
function fx_files( string $bloc, array $fichiers ): array {
	$entree = array( 'name' => array(), 'type' => array(), 'tmp_name' => array(), 'error' => array(), 'size' => array() );

	foreach ( $fichiers as $f ) {
		$entree['name'][]     = $f[0];
		// Type annoncé par le navigateur : volontairement mensonger dans
		// plusieurs bancs, il ne doit jamais être cru.
		$entree['type'][]     = $f[2] ?? 'application/octet-stream';
		$entree['tmp_name'][] = $f[1];
		$entree['error'][]    = $f[3] ?? UPLOAD_ERR_OK;
		$entree['size'][]     = is_file( $f[1] ) ? filesize( $f[1] ) : 0;
	}

	return array( $bloc => $entree );
}

/**
 * Copie un fichier d'essai, pour qu'un déplacement ne le consomme pas.
 *
 * @param string $source Chemin.
 * @return string
 */
function fx_copie( string $source ): string {
	$copie = tempnam( sys_get_temp_dir(), 'urbfx' );
	copy( $source, $copie );
	$GLOBALS['fx_temp'][] = $copie;

	return $GLOBALS['fx_mover']->autoriser( $copie );
}

/**
 * Compte les fichiers présents sous la racine privée d'essai.
 *
 * @return int
 */
function fx_compte_fichiers(): int {
	$racine = URBIZEN_TEST_STORAGE;

	if ( ! is_dir( $racine ) ) {
		return 0;
	}

	$n = 0;

	$technique = $racine . '/' . \Urbizen\Platform\Mail\MailProcessLock::SOUS_DOSSIER;

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		// index.php et .htaccess sont les défenses posées par Storage.
		if ( ! $f->isFile() || in_array( $f->getFilename(), array( 'index.php', '.htaccess' ), true ) ) {
			continue;
		}

		// Les verrous de notification sont des fichiers **techniques**, vides,
		// sans donnée personnelle. Ce compteur mesure les documents clients.
		if ( 0 === strpos( (string) $f->getPathname(), $technique . '/' ) ) {
			continue;
		}

		++$n;
	}

	return $n;
}

/**
 * Compte les fichiers techniques de verrou.
 *
 * @return int
 */
function fx_compte_verrous(): int {
	$base = URBIZEN_TEST_STORAGE . '/' . \Urbizen\Platform\Mail\MailProcessLock::SOUS_DOSSIER;

	if ( ! is_dir( $base ) ) {
		return 0;
	}

	return count( array_diff( (array) scandir( $base ), array( '.', '..' ) ) );
}

/**
 * Compte les répertoires de staging.
 *
 * @return int
 */
function fx_compte_staging(): int {
	$base = URBIZEN_TEST_STORAGE . '/' . \Urbizen\Platform\Files\Storage::DIR_STAGING;

	if ( ! is_dir( $base ) ) {
		return 0;
	}

	return count( array_diff( (array) scandir( $base ), array( '.', '..' ) ) );
}

/**
 * Efface entièrement la racine privée d'essai.
 *
 * @return void
 */
function fx_vide_stockage(): void {
	$racine = URBIZEN_TEST_STORAGE;

	if ( ! is_dir( $racine ) ) {
		return;
	}

	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $racine, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
	}
}
