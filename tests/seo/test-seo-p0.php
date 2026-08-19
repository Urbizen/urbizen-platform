<?php
/**
 * Volet statique du banc P0 : ce que le dépôt doit garantir de lui-même.
 *
 * Le banc en ligne (`test-seo-p0.mjs`) observe le site servi. Il ne peut donc
 * rien dire tant que le site n'est pas déployé — et il passerait au vert si
 * quelqu'un retirait le filtre du thème tout en laissant la base corrigée, ce
 * qui rouvrirait l'archive d'auteur au prochain déploiement.
 *
 * Ce contrôle-ci ferme cette porte : il vérifie que le code qui supprime
 * l'archive est présent, et que le script de correction n'a pas dérivé de la
 * décision prise.
 *
 * Usage : php tests/seo/test-seo-p0.php
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 *
 * @package Urbizen\Tests
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$echecs = 0;

/**
 * Affiche le résultat d'un contrôle.
 *
 * @param string $nom    Intitulé.
 * @param bool   $ok     Résultat.
 * @param string $detail Précision affichée en cas d'échec.
 * @return void
 */
function check( $nom, $ok, $detail = '' ) {
	global $echecs;
	printf( "%-72s %s\n", $nom, $ok ? 'OK' : 'ECHEC' );
	if ( ! $ok && '' !== $detail ) {
		echo "    $detail\n";
	}
	if ( ! $ok ) {
		++$echecs;
	}
}

echo "\n════ P0 SEO — contrôles statiques ════\n\n";

$fns = (string) file_get_contents( $theme . '/functions.php' );

// ---- 1 · Le filtre qui supprime les archives d'auteur ----------------------

check(
	'1 · le thème enfant supprime les archives d\'auteur',
	str_contains( $fns, 'function urbizen_child_desactive_archives_auteur' )
	&& str_contains( $fns, "add_action( 'parse_query', 'urbizen_child_desactive_archives_auteur' )" )
);

check(
	'1 · il s\'accroche à parse_query, pas à template_redirect',
	// `template_redirect` produirait une page d'auteur habillée en 404 : le
	// gabarit changerait, l'en-tête HTTP non. On veut un vrai 404.
	preg_match( '/add_action\(\s*\'parse_query\',\s*\'urbizen_child_desactive_archives_auteur\'/', $fns )
	&& ! preg_match( '/add_action\(\s*\'template_redirect\',\s*\'urbizen_child_desactive_archives_auteur\'/', $fns )
);

check(
	'1 · il épargne l\'administration et les requêtes secondaires',
	preg_match( '/function urbizen_child_desactive_archives_auteur.*?is_admin\(\).*?is_main_query\(\)/s', $fns )
);

check(
	'1 · il pose les drapeaux via set_404(), pas à la main',
	// Poser `is_404 = true` directement ne change que le drapeau : l'en-tête
	// HTTP reste 200, parce que c'est `WP::handle_404()` qui l'envoie et qu'il
	// ne le fait que si la requête ne rapporte aucun article. Mesuré en
	// production le 13 août 2026 — gabarit 404, statut 200, soit un soft 404.
	preg_match( '/function urbizen_child_desactive_archives_auteur.*?set_404\(\)/s', $fns )
	&& ! preg_match( '/function urbizen_child_desactive_archives_auteur.*?is_404\s*=\s*true/s', $fns )
);

check(
	'1 · il envoie l\'en-tête 404 sans dépendre de la requête',
	preg_match( '/function urbizen_child_desactive_archives_auteur.*?status_header\(\s*404\s*\)/s', $fns )
);

check(
	'1 · il interdit la mise en cache de ce 404',
	preg_match( '/function urbizen_child_desactive_archives_auteur.*?nocache_headers\(\)/s', $fns )
);

/*
 * LE CONTRÔLE PORTE SUR LE CORPS DE LA FONCTION, PAS SUR LE FICHIER
 *
 * Il s'écrivait `…auteur.*?wp_redirect|wp_safe_redirect`. Deux défauts, et le
 * second survivait à la correction du premier.
 *
 * En PCRE, `|` a la précédence la PLUS BASSE : le motif se coupait en deux, et
 * sa seconde branche valait « `wp_safe_redirect` n'importe où dans le fichier ».
 * Le contrôle ne mesurait plus la fonction visée mais tout `functions.php`, et
 * il a échoué le jour où une redirection sans rapport — celle de l'ancien
 * parcours de commande — y est apparue.
 *
 * Grouper l'alternance ne suffisait pas : `.*?` traverse les frontières de
 * fonction, si bien que le résultat dépendait de l'ORDRE des définitions. Le
 * contrôle serait repassé au vert par pur effet de rangement, et aurait de
 * nouveau échoué le jour où une redirection serait déclarée plus bas.
 *
 * Le corps est donc isolé d'abord, et la recherche s'y limite. La décision
 * qu'il protège n'a pas bougé : l'archive d'auteur rend un 404, jamais un 301.
 */
preg_match( '/function urbizen_child_desactive_archives_auteur\(.*?\n}/s', $fns, $corps_archives );

check(
	'1 · le corps de la fonction d\'archive est isolable',
	isset( $corps_archives[0] ) && '' !== $corps_archives[0]
);

check(
	'1 · aucune redirection n\'est créée pour l\'ancienne archive',
	// Décision explicite de la propriétaire : 404, pas de 301.
	! preg_match( '/wp_redirect|wp_safe_redirect/', $corps_archives[0] ?? '' )
);

// ---- 2 · Le script de correction reste fidèle à la décision ----------------

$script = $racine . '/scripts/seo-p0.php';
check( '2 · le script de correction existe', file_exists( $script ) );

if ( file_exists( $script ) ) {
	$s = (string) file_get_contents( $script );

	check(
		'2 · il n\'écrit aucun prix dans les métadonnées',
		// La règle retenue n'est pas « un prix à jour » mais « aucun prix » :
		// une métadonnée est permanente, un tarif ne l'est pas.
		! preg_match( '/\$cible\s*=\s*array\(.*?\d+\s*(€|euros).*?\);/s', $s ),
		'un montant apparaît dans les valeurs cibles'
	);

	check(
		'2 · il renseigne les six champs, Open Graph et Twitter compris',
		preg_match( '/\'title\'/', $s ) && preg_match( '/\'description\'/', $s )
		&& preg_match( '/\'og_title\'/', $s ) && preg_match( '/\'og_description\'/', $s )
		&& preg_match( '/\'twitter_title\'/', $s ) && preg_match( '/\'twitter_description\'/', $s ),
		'laisser og_* ou twitter_* à NULL les ferait hériter du titre'
	);

	check(
		'2 · il n\'emploie aucune balise dynamique AIOSEO',
		// `#site_title` est vide sur ce site : toute balise dynamique
		// réintroduirait le séparateur orphelin.
		! preg_match( '/\$cible\s*=\s*array\(.*?#(post_title|site_title|separator_sa|tagline).*?\);/s', $s )
	);

	check(
		'2 · il passe par le modèle applicatif AIOSEO, pas par un UPDATE',
		str_contains( $s, 'AIOSEO\Plugin\Common\Models\Post::getPost' )
		&& ! preg_match( '/UPDATE\s+\w*aioseo/i', $s )
	);

	check(
		'2 · il ne touche pas à user_login',
		preg_match( '/wp_update_user\(.*?\)/s', $s )
		&& ! preg_match( '/wp_update_user\(\s*array\(.*?\'user_login\'\s*=>/s', $s ),
		'changer l\'identifiant de connexion risquerait l\'authentification'
	);

	check(
		'2 · il corrige user_nicename ET display_name',
		// Le premier fabrique l'URL, le second le titre, le nom d'article, le
		// JSON-LD et l'API REST. Corriger l'un sans l'autre laisse une fuite.
		preg_match( '/\'user_nicename\'\s*=>/', $s ) && preg_match( '/\'display_name\'\s*=>/', $s )
	);

	check(
		'2 · il est simulable sans écrire',
		str_contains( $s, '$appliquer' ) && preg_match( '/in_array\(\s*\'appliquer\'/', $s )
	);

	check(
		'2 · aucune adresse de courriel n\'est écrite en dur comme valeur cible',
		! preg_match( '/=>\s*\'[^\']*@[^\']*\'/', $s )
	);
}

echo "\n";

if ( $echecs ) {
	echo "$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

echo "TOUS LES CONTROLES PASSENT\n";
exit( 0 );
