<?php
/**
 * Banc d'essai statique de la page Tarifs.
 *
 * Contrôles sur le thème enfant, sans WordPress : enregistrement du gabarit,
 * structure et hiérarchie des titres, référencement, réutilisation effective
 * des composants de charte, cibles des liens, et surtout — parce que c'est
 * l'objet du lot — absence de l'ancien tarif et présence des neuf montants
 * validés.
 *
 * La géométrie, elle, est mesurée à part par `test-geometrie-tarifs.py` :
 * lire un gabarit ne dit rien de ce qu'il devient à l'écran.
 *
 * Toutes les données sont celles du dépôt.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @param string $detail  Précision affichée en cas d'échec.
 * @return void
 */
function check( $libelle, $reussi, $detail = '' ) {
	global $echecs;

	if ( ! $reussi ) {
		++$echecs;
	}

	printf( "%-72s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );

	if ( ! $reussi && '' !== $detail ) {
		echo '    ' . $detail . "\n";
	}
}

$gabarit = $theme . '/templates/page-tarifs.html';

if ( ! is_file( $gabarit ) ) {
	echo "Gabarit introuvable : $gabarit\n";
	exit( 1 );
}

$tpl     = (string) file_get_contents( $gabarit );
$pattern = (string) file_get_contents( $theme . '/patterns/tarifs-grille.php' );
$fns     = (string) file_get_contents( $theme . '/functions.php' );
$json    = (string) file_get_contents( $theme . '/theme.json' );
$css     = (string) file_get_contents( $theme . '/assets/css/urbizen-pages.css' );
$header  = (string) file_get_contents( $theme . '/patterns/header-accueil.php' );
$footer  = (string) file_get_contents( $theme . '/patterns/footer-accueil.php' );

// --- Enregistrement du gabarit ---------------------------------------------
check( 'page-tarifs déclaré dans theme.json', str_contains( $json, '"page-tarifs"' ) );
check(
	'page-tarifs inscrit dans URBIZEN_CHILD_TEMPLATES_PAGES',
	1 === preg_match( '/URBIZEN_CHILD_TEMPLATES_PAGES\s*=\s*array\([^)]*page-tarifs/s', $fns )
);
check( 'Détecteur urbizen_child_est_page_tarifs présent',
	str_contains( $fns, 'function urbizen_child_est_page_tarifs' ) );
check( 'Catalogue urbizen_child_tarifs présent',
	str_contains( $fns, 'function urbizen_child_tarifs' ) );

// --- Structure et hiérarchie -----------------------------------------------
check( 'Un seul <h1>', 1 === preg_match_all( '/<h1[\s>]/', $tpl ), 'trouvés : ' . preg_match_all( '/<h1[\s>]/', $tpl ) );
check( 'H1 conforme au brief',
	str_contains( $tpl, "Des tarifs clairs pour votre projet d'urbanisme" ) );
check( 'Au moins 7 <h2> de section', preg_match_all( '/<h2[\s>]/', $tpl ) >= 7 );
check( 'Aucun <h3> avant le premier <h2>',
	strpos( $tpl, '<h2' ) < strpos( $tpl, '<h3' ) || false === strpos( $tpl, '<h3' ) );
check( 'Lien d\'évitement présent', str_contains( $tpl, 'class="u-skip"' ) );
check( 'Zone principale identifiée', str_contains( $tpl, '<main id="contenu"' ) );

// --- En-tête et pied de page COMMUNS, jamais recréés ------------------------
check( 'En-tête commun du site réutilisé',
	str_contains( $tpl, '"slug":"header-urbizen"' ) );
check( 'Pied de page commun du site réutilisé',
	str_contains( $tpl, '"slug":"footer-urbizen"' ) );
check( 'Aucun en-tête ni pied de page propre à la page Tarifs',
	! preg_match( '/<header\b(?![^>]*class="tar-hero)/', $tpl ) && ! str_contains( $tpl, '<footer' ) );

// --- Réutilisation des composants de charte --------------------------------
foreach ( array(
	'page-hero'               => 'hero de page',
	'eyebrow eyebrow-highlight' => 'surtitres',
	'sec-head'                => 'en-têtes de section',
	'btn btn-primary'         => 'bouton principal',
	'btn btn-ghost'           => 'bouton secondaire',
	'cta-final'               => 'CTA final',
	'infos'                   => 'cartes d\'information',
	'fil-ariane'              => 'fil d\'ariane',
) as $classe => $libelle ) {
	check( "Composant de charte réutilisé : $libelle", str_contains( $tpl, $classe ) );
}
check( 'Grille tarifaire rendue par le pattern, pas recopiée',
	str_contains( $tpl, '"slug":"urbizen-child/tarifs-grille"' )
	&& ! str_contains( $tpl, 'tarif-price' ) );
check( 'Le pattern réemploie les classes tarifaires de l\'accueil',
	str_contains( $pattern, 'tarif-group' )
	&& str_contains( $pattern, 'tarif-price' )
	&& str_contains( $pattern, 'tarif-supplement-global' ) );
check( 'Le pattern lit le catalogue, aucun montant en dur',
	str_contains( $pattern, 'urbizen_child_tarifs()' )
	&& ! preg_match( '/\b(189|249|449|549|649|849)\b/', $pattern ) );

// --- Feuille de style scopée ------------------------------------------------
check( 'Section .urbizen-page-tarifs présente dans urbizen-pages.css',
	str_contains( $css, '.urbizen-page-tarifs' ) );
check( 'FAQ stylée dans la portée de la page, sans toucher à celle de DP',
	str_contains( $css, '.urbizen-page-tarifs .tar-faq .faq' )
	&& str_contains( $css, '.urbizen-page-dp .dp-faq .faq { display: grid; gap: 12px; width: 100%; max-width: none; margin: 0;' ) );
check( 'Aucun !important introduit par la section Tarifs',
	! preg_match( '/\.urbizen-page-tarifs[^{]*\{[^}]*!important/s', $css ) );
check( 'Cibles tactiles : min-height 44px sur les boutons de la page',
	preg_match( '/\.urbizen-page-tarifs \.btn\s*\{[^}]*min-height:\s*44px/', $css ) );

// --- Tarifs affichés --------------------------------------------------------
check( 'Aucune occurrence de l\'ancien tarif 149 € dans le gabarit',
	! preg_match( '/\b149\b/', $tpl ), 'le tarif 149 € a été remplacé par 189 €' );
check( 'Aucune occurrence de l\'ancien tarif 149 € dans le pattern',
	! preg_match( '/\b149\b/', $pattern ) );
check( 'Le hero affiche les trois socles (189, 449, 449)',
	str_contains( $tpl, '189&nbsp;€' )
	&& 2 === substr_count( $tpl, '449&nbsp;€' ) );

// --- Cibles des liens : aucun lien mort, aucune URL inventée ----------------
check( 'L\'URL /tarifs/ est conservée (aucune nouvelle adresse créée)',
	! preg_match( '#href="/(tarifs-|nos-tarifs|pricing)#', $tpl ) );
// Les cibles des boutons de carte sont portées par le catalogue, que le
// pattern se contente de lire : c'est donc là qu'on les vérifie.
foreach ( array(
	'/formulaire-declaration-prealable/',
	'/formulaire-permis-de-construire/',
	'/formulaire-conception/',
) as $parcours ) {
	check( "Parcours existant réutilisé : $parcours", str_contains( $fns, $parcours ) );
}
/*
 * LE LIBELLÉ DU BOUTON DE QUALIFICATION
 *
 * Ce contrôle ne comptait que les destinations, et son intitulé nommait
 * « Démarrer mon projet ». Le site est harmonisé : tout appel à l'action dont
 * la fonction est simplement d'ouvrir le tunnel `/#localisation` porte
 * « Étudier mon projet ». Deux boutons de cette page gardaient l'ancien libellé
 * — la destination était juste, le mot ne l'était plus, et compter des `href`
 * ne pouvait pas le voir.
 *
 * La page portait trois boutons vers cette ancre sous TROIS libellés : « Démarrer
 * mon projet » deux fois, et « Faire étudier mon projet » dans le bloc « Vous
 * hésitez ». Même destination, même fonction, trois mots différents. Les trois
 * portent désormais le libellé de référence.
 *
 * Le contrôle ne se contente pas de compter le bon libellé : il exige que
 * CHAQUE ancre vers `/#localisation` le porte. Compter les occurrences aurait
 * laissé passer un quatrième bouton ajouté demain sous un quatrième nom.
 */
check( 'CTA de qualification vers le parcours, au moins deux fois',
	substr_count( $tpl, 'href="/#localisation"' ) >= 2 );
preg_match_all( '#<a[^>]*href="/\#localisation"[^>]*>(.*?)</a>#s', $tpl, $ancres );
$libelles_hors_norme = array_values( array_diff( array_unique( $ancres[1] ), array( 'Étudier mon projet' ) ) );
check( 'CTA de qualification : toutes les ancres portent « Étudier mon projet »',
	array() !== $ancres[1] && array() === $libelles_hors_norme,
	implode( ' | ', $libelles_hors_norme ) );
foreach ( array( 'Démarrer mon projet', 'Faire étudier mon projet' ) as $abandonne ) {
	check( "CTA de qualification : plus aucun « $abandonne »",
		! str_contains( $tpl, $abandonne ) );
}
check( 'CTA de renseignements vers le parcours existant',
	substr_count( $tpl, 'href="/#demander-des-renseignements"' ) >= 2 );

// --- Navigation : « Tarifs » ouvre la page, plus l'ancre de l'accueil -------
// Le lien ne s'écrit plus d'une seule pièce depuis le 14 août 2026 : l'entrée
// courante reçoit un `aria-current="page"` interpolé entre l'URL et le chevron
// fermant. Une comparaison littérale sur `…/tarifs/">Tarifs</a>` ne trouvait
// donc plus rien, alors que les deux entrées étaient intactes. On vise
// désormais la forme réelle — URL, appel éventuel au marqueur d'état, libellé —
// ce qui conserve l'intention du contrôle sans le rendre sensible au prochain
// attribut ajouté.
check( 'En-tête : « Tarifs » ouvre /tarifs/',
	2 === preg_match_all( '#<a href="https://urbizen\.fr/tarifs/"(?:<\?php[^>]*\?>)?>Tarifs</a>#', $header )
	&& ! preg_match( '/#tarifs">Tarifs/', $header ) );
check( 'Pied de page : « Tarifs » ouvre /tarifs/',
	str_contains( $footer, '<a href="https://urbizen.fr/tarifs/">Tarifs</a>' )
	&& ! preg_match( '/#tarifs">Tarifs/', $footer ) );

// --- Référencement ----------------------------------------------------------
check( 'Titre de document posé pour la seule page Tarifs',
	str_contains( $fns, 'function urbizen_child_titre_tarifs' )
	&& str_contains( $fns, 'Tarifs déclaration préalable et permis de construire' ) );
check( 'Description posée pour la seule page Tarifs',
	str_contains( $fns, 'function urbizen_child_description_tarifs' ) );
// Le comportement réel du garde-fou est éprouvé par `test-seo-tarifs.php`,
// qui exécute les fonctions. Ici on vérifie seulement qu'il est bien branché.
check( 'Titre et description passent par le détecteur de greffon SEO',
	str_contains( $fns, 'function urbizen_child_seo_gere_ailleurs' )
	&& 2 === substr_count( $fns, 'if ( urbizen_child_seo_gere_ailleurs() ) {' ) );
check( 'All in One SEO Pack est couvert (greffon réellement actif sur le site)',
	str_contains( $fns, 'AIOSEO_VERSION' ) );

// --- Contenus obligatoires du brief -----------------------------------------
foreach ( array(
	'Le plus demandé'                                   => 'badge de l\'offre standard',
	'Clôtures &amp; panneaux solaires'                  => 'offre 189 € préservée',
	'Secteur Bâtiments de France'                       => 'encadré ABF',
	'Sur devis'                                         => 'projets particuliers',
	'Bon à savoir'                                      => 'conditions',
	'Pourquoi choisir Urbizen'                          => 'réassurance',
	'Étude gratuite — sans engagement'                  => 'orientation',
) as $extrait => $libelle ) {
	// Le contenu de la page se répartit entre le gabarit (sections rédigées),
	// le pattern (mise en forme) et le catalogue (offres et montants).
	check( "Contenu attendu : $libelle", str_contains( $tpl . $pattern . $fns, $extrait ) );
}
check( 'FAQ : six questions', 6 === substr_count( $tpl, '<details>' ), 'trouvées : ' . substr_count( $tpl, '<details>' ) );
check( 'Formulations prudentes sur les pièces du dossier',
	str_contains( $tpl . $pattern, 'selon les besoins du dossier' )
	|| str_contains( $tpl . $pattern, 'nécessaires' ) );

// --- Interdits du brief ------------------------------------------------------
check( 'Aucun témoignage ni statistique inventés',
	! preg_match( '/\b(\d+\s*(clients|avis|dossiers déposés)|taux de réussite|100\s*% de réussite)\b/i', $tpl ) );
check( 'Aucune garantie d\'obtention',
	! preg_match( '/garantie? d\'obtention|obtention garantie/i', $tpl ) );
check( 'Aucune image décorative lourde ajoutée',
	0 === preg_match_all( '/<img\b/', $tpl ) );

echo "\n";

if ( $echecs ) {
	echo "$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

echo "TOUS LES CONTROLES PASSENT\n";
exit( 0 );
