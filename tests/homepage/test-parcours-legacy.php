<?php
/**
 * L'ancien parcours de commande ne doit plus constituer un second tunnel.
 *
 * CE QUE CE BANC EXISTE POUR EMPÊCHER
 *
 * `/commander-un-dossier/` sert un formulaire Fluent Forms antérieur au tunnel
 * de qualification. Elle était déjà en `noindex` et hors du plan de site — donc
 * invisible des moteurs — mais le bouton principal de l'en-tête, présent sur
 * CHAQUE page du site, guides compris, y menait encore. C'était le chemin le
 * plus court vers un dossier non qualifié, et il annulait l'intérêt du tunnel.
 *
 * Le `noindex` avait masqué le problème plutôt que de le régler : la page ne
 * remontait plus dans les résultats, mais elle restait le premier clic proposé
 * à tout visiteur.
 *
 * CE QUI EST VÉRIFIÉ ICI
 *
 * La redirection existe et vise le tunnel ; elle ne s'applique qu'à cette page
 * et qu'en GET ; aucun lien interne actif n'y mène plus ; et le bouton d'en-tête
 * conduit désormais au tunnel sous un libellé qui décrit ce qu'on y trouve.
 *
 * Ce banc est STATIQUE : il lit le thème, il n'interroge pas la production. Un
 * contrôle en ligne dépendrait d'un déploiement, et échouerait pour une raison
 * qui n'est pas celle qu'il mesure.
 *
 * @package Urbizen\Tests
 */

declare( strict_types=1 );

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fns    = (string) file_get_contents( $theme . '/functions.php' );
$header = (string) file_get_contents( $theme . '/parts/header.html' );

$echecs = 0;

/**
 * Enregistre le résultat d'un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $ok      Issue.
 * @param string $detail  Complément affiché en cas d'échec.
 * @return void
 */
function verifier( string $libelle, bool $ok, string $detail = '' ): void {
	global $echecs;

	printf( "%-74s %s\n", $libelle, $ok ? 'OK' : 'ECHEC' );

	if ( ! $ok ) {
		$echecs++;

		if ( '' !== $detail ) {
			printf( "    %s\n", $detail );
		}
	}
}

echo "\n── 1. La redirection de l'ancien parcours\n";

verifier(
	'La redirection est branchée sur template_redirect',
	str_contains( $fns, "add_action( 'template_redirect', 'urbizen_child_rediriger_ancien_parcours' )" )
);

preg_match( '/function urbizen_child_rediriger_ancien_parcours\(\).*?\n}/s', $fns, $bloc );
$corps = $bloc[0] ?? '';

verifier( 'La fonction de redirection existe', '' !== $corps );

verifier(
	'Elle ne vise QUE le slug de l\'ancien parcours',
	str_contains( $corps, "'commander-un-dossier' !== \$page->post_name" ),
	'sans ce test, toute page serait redirigée'
);

verifier(
	'Elle vise le tunnel de qualification',
	str_contains( $corps, "home_url( '/#localisation' )" )
);

verifier(
	'Elle rend un 301, et non un 302',
	(bool) preg_match( "/wp_safe_redirect\(\s*home_url\( '\/#localisation' \),\s*301\s*\)/", $corps ),
	'une redirection temporaire ne transmettrait aucun signal'
);

// Une soumission encore en vol doit aboutir ou échouer franchement, jamais être
// détournée en chemin.
verifier(
	'Elle ne redirige que les requêtes GET',
	str_contains( $corps, "'GET' !== strtoupper" )
);

verifier(
	'Elle ne s\'applique pas dans l\'administration',
	str_contains( $corps, 'is_admin()' )
);

// La cible est l'accueil : une page qui se redirigerait vers elle-même
// boucherait le navigateur. Le test de slug l'interdit déjà, mais il doit rester.
verifier(
	'Aucune boucle possible : la cible n\'est pas la page redirigée',
	! str_contains( $corps, "home_url( '/commander-un-dossier" )
);

verifier(
	'Elle sort après avoir redirigé',
	str_contains( $corps, 'exit;' ),
	'sans sortie, WordPress continuerait de rendre la page'
);

echo "\n── 2. Le bouton d'en-tête\n";

verifier(
	'L\'en-tête ne pointe plus vers l\'ancien parcours',
	! str_contains( $header, 'commander-un-dossier' ),
	'le CTA le plus visible du site y menait encore'
);

verifier(
	'L\'en-tête pointe vers le tunnel',
	str_contains( $header, 'href="https://urbizen.fr/#localisation"' )
);

verifier(
	'Son libellé décrit ce que le premier écran propose',
	str_contains( $header, '>Étudier mon projet</a>' ),
	'« Commander mon dossier » promettait une commande là où commence une qualification'
);

verifier(
	'Le libellé promettant une commande a disparu',
	! str_contains( $header, 'Commander mon dossier' )
);

echo "\n── 3. Plus aucun lien interne vers l'ancien parcours\n";

$sources = array();

foreach ( array( '/wordpress', '/frontend', '/content' ) as $dossier ) {
	$chemin = $racine . $dossier;

	if ( ! is_dir( $chemin ) ) {
		continue;
	}

	$iterateur = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $chemin, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterateur as $fichier ) {
		if ( ! $fichier->isFile() ) {
			continue;
		}

		if ( ! in_array( strtolower( $fichier->getExtension() ), array( 'html', 'php', 'js' ), true ) ) {
			continue;
		}

		$sources[ $fichier->getPathname() ] = (string) file_get_contents( $fichier->getPathname() );
	}
}

$liens = array();

foreach ( $sources as $chemin => $contenu ) {
	// La fonction de redirection NOMME le slug : c'est son objet, pas un lien.
	if ( str_ends_with( $chemin, '/functions.php' ) ) {
		continue;
	}

	if ( preg_match( '#(href|src)="[^"]*commander-un-dossier#', $contenu ) ) {
		$liens[] = str_replace( $racine . '/', '', $chemin );
	}
}

verifier(
	'Aucun lien actif ne mène encore à l\'ancien parcours',
	array() === $liens,
	implode( ', ', $liens )
);

echo "\n";

if ( $echecs > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $echecs );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
exit( 0 );
