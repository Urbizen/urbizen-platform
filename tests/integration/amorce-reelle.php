<?php
/**
 * Amorce commune des bancs exécutés contre un vrai WordPress.
 *
 * Chargée par le banc principal et par les processus fils des scénarios de
 * concurrence. Elle ne crée rien : elle charge WordPress et les classes du
 * greffon, et rien d'autre.
 */

$racine = (string) getenv( 'URBIZEN_WP_ROOT' );

if ( '' === $racine || ! is_readable( $racine . '/wp-load.php' ) ) {
	fwrite( STDERR, "URBIZEN_WP_ROOT non défini ou illisible\n" );
	exit( 0 );
}

require $racine . '/wp-load.php';

// Banc d'essai : la constante lève le plancher de durée des baux, jamais la
// protection elle-même. Elle n'existe pas en production.
if ( ! defined( 'URBIZEN_TESTING' ) ) {
	define( 'URBIZEN_TESTING', true );
}

if ( ! defined( 'URBIZEN_PLATFORM_DIR' ) ) {
	define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );
}

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
		'src/Submissions/SubmissionPostType.php',
		'src/Submissions/SubmissionRepository.php',
		'src/Submissions/TransactionRecovery.php',
		'src/Submissions/TrashGuard.php',
		'src/Files/UploadPolicy.php',
		'src/Files/UploadNormalizer.php',
		'src/Files/Storage.php',
		'src/Files/SignedLink.php',
		'src/Files/FileCleaner.php',
		'src/Mail/MailPolicy.php',
		'src/Mail/MailLockHandle.php',
		'src/Mail/MailProcessLock.php',
		'src/Mail/MailQueue.php',
		'src/Mail/MailRenderer.php',
		'src/Mail/MailTransport.php',
		'src/Mail/WordPressMailTransport.php',
		'src/Mail/MailScheduler.php',
		'src/Admin/SubmissionsAdmin.php',
	) as $fichier
) {
	require_once URBIZEN_PLATFORM_DIR . $fichier;
}

\Urbizen\Platform\Submissions\SubmissionPostType::register_post_type();

/**
 * Crée une demande finalisée, sans document.
 *
 * @return array{id:int,ref:string}
 */
function urbizen_demande_reelle(): array {
	$v = \Urbizen\Platform\Forms\Validator::validate(
		\Urbizen\Platform\Forms\FormRegistry::get( 'conception' ),
		array(
			'nature'    => 'maison',
			'situation' => 'terrain_nu',
			'a_terrain' => 'non',
			'nom'       => 'Camille Fictif',
			'email'     => 'camille@exemple.test',
			'tel'       => '0100000000',
			'rgpd'      => '1',
		)
	);

	$c = \Urbizen\Platform\Submissions\SubmissionRepository::create( $v['clean'], $v['pricing'], array( 'now' => time() ) );

	return array( 'id' => (int) ( $c['id'] ?? 0 ), 'ref' => (string) ( $c['reference'] ?? '' ) );
}

/**
 * Attend qu'un fichier de rendez-vous apparaisse.
 *
 * @param string $chemin  Fichier.
 * @param float  $secondes Délai maximal.
 * @return bool
 */
function urbizen_attendre( string $chemin, float $secondes = 15.0 ): bool {
	$fin = microtime( true ) + $secondes;

	while ( microtime( true ) < $fin ) {
		clearstatcache( true, $chemin );

		if ( file_exists( $chemin ) ) {
			return true;
		}

		usleep( 20000 );
	}

	return false;
}

/**
 * Pose un jalon.
 *
 * @param string $chemin Fichier.
 * @return void
 */
function urbizen_jalon( string $chemin ): void {
	file_put_contents( $chemin, (string) getmypid() );
}
