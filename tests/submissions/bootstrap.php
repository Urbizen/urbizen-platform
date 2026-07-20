<?php
/**
 * Amorce commune des bancs d'essai « soumissions ».
 *
 * Charge la doublure WordPress puis les classes du plugin, sans WordPress,
 * sans base de données et sans réseau.
 *
 * Toutes les données employées dans ces bancs sont **fictives**.
 */

require_once __DIR__ . '/logger-double.php';
require_once __DIR__ . '/wp-double.php';

define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );

foreach (
	array(
		'src/Support/Logger.php',
		'src/Support/Reference.php',
		'src/Support/OptionsScan.php',
		'src/Forms/FormDefinition.php',
		'src/Forms/FormRegistry.php',
		'src/Forms/Pricing.php',
		'src/Forms/Validator.php',
		'src/Forms/Renderer.php',
		'src/Security/AntiSpam.php',
		'src/Security/RateLimiter.php',
		'src/Submissions/SubmissionPostType.php',
		'src/Submissions/SubmissionRepository.php',
		'src/Privacy/Retention.php',
		'src/Admin/SubmissionsAdmin.php',
		'src/Http/SubmissionResult.php',
		'src/Support/PhpLimits.php',
		'src/Files/UploadedFileMover.php',
		'src/Files/HttpUploadedFileMover.php',
		'src/Files/UploadPolicy.php',
		'src/Files/UploadNormalizer.php',
		'src/Files/Storage.php',
		'src/Files/SignedLink.php',
		'src/Files/FileCleaner.php',
		'src/Http/SubmissionController.php',
		'src/Http/FileDownloadController.php',
	) as $fichier
) {
	require_once URBIZEN_PLATFORM_DIR . $fichier;
}

/**
 * Racine privée d'essai, hors de l'« ABSPATH » de la doublure.
 *
 * La doublure fixe ABSPATH au répertoire des bancs ; un répertoire du dossier
 * temporaire du système est donc bien à l'extérieur, comme en production.
 */
define( 'URBIZEN_TESTING', true );
define( 'URBIZEN_TEST_STORAGE', sys_get_temp_dir() . '/urbizen-b2-' . getmypid() );

add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );

/**
 * Efface tout ce qu'un banc a pu écrire sur le disque.
 *
 * Outre la racine d'essai, on balaie les emplacements qu'un scénario de refus
 * ou une classe mutée pourrait créer : un banc ne doit jamais laisser de
 * fichier dans le dépôt, fût-ce en démontrant qu'un chemin est interdit.
 */
function urbizen_test_menage(): void {
	$cibles = array(
		URBIZEN_TEST_STORAGE,
		dirname( rtrim( ABSPATH, '/' ) ) . '/private',
		rtrim( ABSPATH, '/' ) . '/mutant-public',
		rtrim( ABSPATH, '/' ) . '/faux-prive',
		rtrim( ABSPATH, '/' ) . '/interdit',
	);

	foreach ( $cibles as $racine ) {
		if ( ! is_dir( $racine ) ) {
			continue;
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $racine, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}

		@rmdir( $racine );
	}
}

register_shutdown_function( 'urbizen_test_menage' );

require_once __DIR__ . '/fixtures.php';

$GLOBALS['fail'] = 0;

/**
 * Consigne le résultat d'un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @return void
 */
function check( string $libelle, bool $reussi ): void {
	if ( ! $reussi ) {
		++$GLOBALS['fail'];
	}

	printf( "%-88s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );
}

/**
 * Soumission de référence, valide et entièrement fictive.
 *
 * @param array<string, mixed> $extra Champs remplacés ou ajoutés.
 * @param int|null             $emis  Instant d'émission du jeton.
 * @return array<string, mixed>
 */
function soumission( array $extra = array(), ?int $emis = null ): array {
	$emis = null === $emis ? wpd_now() - 60 : $emis;

	return array_merge(
		array(
			\Urbizen\Platform\Http\SubmissionController::NONCE_FIELD
				=> wp_create_nonce( \Urbizen\Platform\Http\SubmissionController::NONCE_ACTION ),
			\Urbizen\Platform\Http\SubmissionController::TOKEN_FIELD
				=> \Urbizen\Platform\Security\AntiSpam::issue_token( $emis ),
			\Urbizen\Platform\Http\SubmissionController::HONEYPOT_FIELD => '',
			'nature'    => 'maison',
			'situation' => 'terrain_nu',
			'a_terrain' => 'non',
			'nom'       => 'Camille Fictif',
			'email'     => 'camille@exemple.test',
			'rgpd'      => '1',
		),
		$extra
	);
}

/**
 * Superglobale serveur d'une requête POST.
 *
 * @param array<string, mixed> $extra Entrées supplémentaires.
 * @return array<string, mixed>
 */
function serveur( array $extra = array() ): array {
	return array_merge(
		array(
			'REQUEST_METHOD' => 'POST',
			'REMOTE_ADDR'    => '203.0.113.10', // Plage de documentation RFC 5737.
		),
		$extra
	);
}

/**
 * Raccourci d'appel du contrôleur.
 *
 * @param array<string, mixed> $post   Données postées.
 * @param array<string, mixed> $files  Fichiers.
 * @param array<string, mixed> $server Superglobale serveur.
 * @return \Urbizen\Platform\Http\SubmissionResult
 */
function traiter( array $post, array $files = array(), array $server = array() ): \Urbizen\Platform\Http\SubmissionResult {
	return \Urbizen\Platform\Http\SubmissionController::process(
		$post,
		$files,
		array() === $server ? serveur() : $server,
		wpd_now()
	);
}

/**
 * Concatène le journal capturé.
 *
 * @return string
 */
function journal(): string {
	return implode( "\n", $GLOBALS['wpd_logs'] );
}

/**
 * Verdict final d'un banc.
 *
 * @return void
 */
function verdict(): void {
	echo "\n";
	echo 0 === $GLOBALS['fail']
		? "TOUS LES CONTROLES PASSENT\n"
		: $GLOBALS['fail'] . " CONTROLE(S) EN ECHEC\n";
	exit( 0 === $GLOBALS['fail'] ? 0 : 1 );
}
