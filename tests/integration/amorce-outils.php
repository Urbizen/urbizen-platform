<?php
/**
 * Utilitaires d'isolation des bancs exécutés contre un vrai WordPress.
 *
 * Séparés de l'amorce, car le banc principal charge ses classes lui-même et
 * n'a pas besoin du reste. Un banc ne doit dépendre ni de ce qui l'a précédé,
 * ni de ce qui le suivra.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exige que WP-Cron soit désactivé dans l'installation jetable.
 *
 * WordPress exécute les événements dus **pendant l'amorçage** de tout
 * processus qui charge `wp-load.php`. Or `finalize()` programme la
 * notification à `time()` : elle est donc immédiatement due, et un processus
 * fils la déclenche en démarrant, avant même que le code du banc ne s'exécute.
 *
 * Le banc observait alors `mail_status = sent` là où il attendait `pending`,
 * et concluait à tort à un défaut applicatif. Le greffon, lui, se comportait
 * exactement comme prévu : la notification était légitimement partie.
 *
 * Les bancs déclenchent donc les événements **explicitement**, par
 * `do_action()`. Sans cette constante, un banc multiprocessus mesure le hasard
 * de l'ordonnancement plutôt que ce qu'il prétend mesurer.
 *
 * @return void
 */
function urbizen_banc_exiger_cron_desactive(): void {
	if ( defined( 'DISABLE_WP_CRON' ) && true === DISABLE_WP_CRON ) {
		return;
	}

	fwrite(
		STDERR,
		"\n✗ L'installation jetable doit définir DISABLE_WP_CRON.\n"
		. "  WordPress exécute les événements dus pendant l'amorçage de chaque\n"
		. "  processus : sans cette constante, un processus fils déclenche la\n"
		. "  notification en démarrant, et le banc mesure autre chose que ce\n"
		. "  qu'il croit.\n\n"
		. "  Ajoutez dans wp-config.php :  define( 'DISABLE_WP_CRON', true );\n\n"
	);

	exit( 2 );
}

/**
 * Remet l'installation jetable dans un état vierge.
 *
 * Un banc ne doit dépendre ni de ce qui l'a précédé, ni de ce qui le suivra.
 * Le premier passage du banc multiprocessus a échoué pour cette seule raison :
 * il héritait des demandes laissées par le banc multipart. Chaque banc crée
 * donc son état, et le rend.
 *
 * @return void
 */
function urbizen_banc_reset(): void {
	global $wpdb;

	wp_cache_flush();

	foreach ( (array) get_posts(
		array(
			'post_type'        => 'urbizen_demande',
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	) as $id ) {
		// Suppression directe : on court-circuite les gardes métier, qui ne
		// concernent pas le ménage d'un banc.
		$wpdb->delete( $wpdb->postmeta, array( 'post_id' => (int) $id ) );
		$wpdb->delete( $wpdb->posts, array( 'ID' => (int) $id ) );
	}

	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'urbizen\_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'cron'" );

	// Le tableau cron reparti de zéro : aucun événement résiduel.
	update_option( 'cron', array( 'version' => 2 ) );

	$prive = dirname( ABSPATH ) . '/private/urbizen-conception';

	if ( is_dir( $prive ) ) {
		urbizen_effacer_recursif( $prive );
	}

	wp_cache_flush();
}

/**
 * Efface un répertoire et son contenu.
 *
 * @param string $chemin Chemin.
 * @return void
 */
function urbizen_effacer_recursif( string $chemin ): void {
	foreach ( (array) glob( $chemin . '/{,.}*', GLOB_BRACE ) as $entree ) {
		$nom = basename( (string) $entree );

		if ( '.' === $nom || '..' === $nom ) {
			continue;
		}

		if ( is_dir( $entree ) && ! is_link( $entree ) ) {
			urbizen_effacer_recursif( (string) $entree );
			@rmdir( (string) $entree );
		} else {
			@unlink( (string) $entree );
		}
	}

	@rmdir( $chemin );
}

/**
 * État résiduel d'un banc, qui doit être nul à la sortie.
 *
 * @return array<string, int>
 */
function urbizen_banc_etat(): array {
	global $wpdb;

	wp_cache_flush();

	$prive = dirname( ABSPATH ) . '/private/urbizen-conception';
	$cron  = 0;

	foreach ( (array) _get_cron_array() as $ts => $c ) {
		$cron += count( (array) ( $c['urbizen_send_submission_mail'] ?? array() ) );
	}

	$fichiers = 0;
	$verrous  = 0;

	if ( is_dir( $prive ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $prive, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( ! $f->isFile() || in_array( $f->getFilename(), array( 'index.php', '.htaccess' ), true ) ) {
				continue;
			}

			if ( str_contains( $f->getPathname(), '/locks/' ) ) {
				++$verrous;
			} else {
				++$fichiers;
			}
		}
	}

	return array(
		'demandes'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='urbizen_demande'" ),
		'references'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_ref_%'" ),
		'notifs'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_urbizen_mail_status'" ),
		'evenements'  => $cron,
		'documents'   => $fichiers,
		'staging'     => is_dir( $prive . '/.staging' ) ? 1 : 0,
		'verrous_opt' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_mail_lock_%'" ),
	);
}

/**
 * Programme le ménage de sortie, quoi qu'il arrive.
 *
 * @return void
 */
function urbizen_banc_menage_a_la_sortie(): void {
	register_shutdown_function(
		static function () {
			urbizen_banc_reset();
		}
	);
}
