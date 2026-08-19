<?php
/**
 * Banc des gabarits de guides — contrat de structure.
 *
 * CE QU'IL PROTÈGE
 *
 * Le thème enfant n'avait aucun gabarit d'article. Sans `home.html`,
 * `single.html` et `archive.html`, WordPress retombe sur ceux du thème parent
 * Hostinger : son en-tête (le menu mort à quatre entrées), son pied de page, et
 * un `<h1>` écrit en dur — « Latest posts », en anglais. Un guide publié
 * aujourd'hui serait donc servi avec l'apparence d'un autre site.
 *
 * Ce banc vérifie que ces trois gabarits existent, qu'ils passent par les
 * template parts Urbizen, et qu'aucun ne laisse entrer une part du parent.
 *
 * CE QU'IL NE PEUT PAS VÉRIFIER
 *
 * Le rendu réel demande WordPress, une base et des articles. Il a été fait à
 * part, sur une installation locale, et les captures sont dans la PR. Ici, on
 * fige ce qui se lit dans les sources — et notamment les pièges qui ne
 * pardonnent pas : un `wp:html` non refermé, une fonction déclarée dans un
 * pattern rendu deux fois, un lien vers l'archive d'auteur.
 *
 * Aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

const GABARITS = array( 'home', 'single', 'archive' );
const PATTERNS = array( 'guides-grille', 'guide-entete', 'guide-pied', 'guides-archive-entete' );

// ------------------------------------------------- 1 · les gabarits existent --

foreach ( GABARITS as $nom ) {
	check( "Le gabarit $nom.html existe", is_file( $theme . "/templates/$nom.html" ) );
}

$contenus = array();
foreach ( GABARITS as $nom ) {
	$chemin = $theme . "/templates/$nom.html";
	$contenus[ $nom ] = is_file( $chemin ) ? file_get_contents( $chemin ) : '';
}

// ------------------------------------------------- 2 · en-tête et pied Urbizen --

foreach ( GABARITS as $nom ) {
	check(
		"$nom.html : en-tête et pied de page Urbizen",
		str_contains( $contenus[ $nom ], '"slug":"header-urbizen"' )
		&& str_contains( $contenus[ $nom ], '"slug":"footer-urbizen"' )
	);
	// C'est le défaut que ce lot corrige : ne pas le réintroduire par mégarde.
	check(
		"$nom.html : aucune part du thème parent (header, footer, superposition)",
		! preg_match( '/"slug":"(header|footer|footer-landing|superposition-de-navigation)"/', $contenus[ $nom ] )
	);
	check(
		"$nom.html : porte les classes de portée des feuilles Urbizen",
		(bool) preg_match( '/class="urbizen-accueil urbizen-page urbizen-guides/', $contenus[ $nom ] )
	);
	check( "$nom.html : lien d'évitement vers le contenu", str_contains( $contenus[ $nom ], 'class="u-skip"' ) );
}

// ------------------------------------------------- 3 · le titre de l'index ----

// `home.html` interroge la LISTE des articles : l'objet interrogé n'est pas la
// page assignée à `page_for_posts`. Un `core/post-title` y rendrait le titre du
// premier article, ou rien. Le libellé doit donc être écrit dans le gabarit.
check(
	'home.html : le H1 « Guides d’urbanisme » est écrit dans le gabarit',
	(bool) preg_match( '#<h1>Guides d’urbanisme</h1>#u', $contenus['home'] )
);
check(
	'home.html : aucun bloc de titre dynamique, dont le rendu serait incertain',
	! str_contains( $contenus['home'], 'wp:post-title' )
	&& ! str_contains( $contenus['home'], 'wp:query-title' )
);
check(
	'archive.html : le titre vient du terme, pas d’un bloc préfixé',
	! str_contains( $contenus['archive'], 'wp:query-title' )
);
check(
	'single.html : le corps de l’article est rendu par wp:post-content',
	str_contains( $contenus['single'], 'wp:post-content' )
);

// ------------------------------------------------- 4 · les patterns ----------

foreach ( PATTERNS as $nom ) {
	$chemin = $theme . "/patterns/$nom.php";
	check( "Le pattern $nom.php existe", is_file( $chemin ) );

	if ( ! is_file( $chemin ) ) {
		continue;
	}

	$src = file_get_contents( $chemin );

	check( "$nom.php : slug déclaré urbizen-child/$nom", str_contains( $src, "Slug: urbizen-child/$nom" ) );
	check( "$nom.php : sortie directe refusée hors WordPress", str_contains( $src, "defined( 'ABSPATH' ) || exit;" ) );

	// Un `wp:html` non refermé casse le gabarit entier : le parseur avale tout
	// ce qui suit. Les deux délimiteurs doivent s'équilibrer.
	$ouverts  = substr_count( $src, '<!-- wp:html -->' );
	$fermes   = substr_count( $src, '<!-- /wp:html -->' );
	check( "$nom.php : les blocs wp:html sont équilibrés", $ouverts === $fermes, "$ouverts ouvert(s), $fermes fermé(s)" );
	check( "$nom.php : le markup est bien dans un bloc wp:html (sinon wpautop le mange)", $ouverts >= 1 );

	// Un fichier de pattern est inclus à CHAQUE rendu. Une fonction déclarée
	// dedans est redéclarée au second — erreur fatale. `guide-pied` rend la
	// même page que `guides-grille` sur un article.
	check( "$nom.php : ne déclare aucune fonction", ! preg_match( '/^\s*function\s+\w+\s*\(/m', $src ) );

	check( "$nom.php : aucun lien vers une archive d’auteur", ! str_contains( $src, '/author/' ) );
}

// ------------------------------------------------- 5 · aucun /author/ servi ---

foreach ( GABARITS as $nom ) {
	check(
		"$nom.html : la chaîne « author » n’est jamais servie au client",
		! str_contains( $contenus[ $nom ], 'author' ),
		'même en commentaire HTML : le commentaire part chez le visiteur'
	);
}

// ------------------------------------------------- 6 · functions.php ---------

$fn = file_get_contents( $theme . '/functions.php' );

// `is_home()`, `is_single()` et `is_category()` ne sont PAS des pages :
// `get_page_template_slug()` n'y renvoie rien, et le test des gabarits internes
// les rejetait tous les trois. Sans ce prédicat, les guides sortaient sans
// polices, sans charte et sans feuille.
check( 'functions.php : le contexte « guides » est reconnu',
	str_contains( $fn, 'function urbizen_child_est_page_guides()' ) );
check( 'functions.php : il couvre l’index, l’article et les archives',
	(bool) preg_match( '/is_home\(\)\s*\|\|\s*is_singular\(\s*\'post\'\s*\)\s*\|\|\s*is_category\(\)/', $fn ) );
// Le corps de la fonction, et lui seul : un `.*?` non borné aurait couru
// jusqu'au premier `is_author()` du reste du fichier et échoué à tort.
preg_match( '/function urbizen_child_est_page_guides\(\)\s*\{(.*?)\n\}/s', $fn, $corps_guides );
check( 'functions.php : l’archive d’auteur reste exclue, elle est en 404',
	isset( $corps_guides[1] ) && ! str_contains( $corps_guides[1], 'is_author' ) );
check( 'functions.php : le contexte guides ouvre droit aux feuilles Urbizen',
	(bool) preg_match( '/function urbizen_child_est_page_urbizen\(\).*?urbizen_child_est_page_guides\(\)/s', $fn ) );
check( 'functions.php : la feuille des guides est enfilée sur ce seul contexte',
	(bool) preg_match( '/urbizen_child_est_page_guides\(\)\s*\)\s*\{\s*\$guides_css/s', $fn ) );

// Les helpers doivent être ici, et nulle part ailleurs : deux patterns les
// utilisent, et l'un d'eux est rendu sur une page où l'autre est absent.
foreach ( array( 'urbizen_child_vignette_guide', 'urbizen_child_categorie_guide' ) as $helper ) {
	check( "functions.php : $helper() y est déclaré, une seule fois",
		1 === preg_match_all( '/function ' . $helper . '\s*\(/', $fn ) );
}

// ------------------------------------------------- 7 · noindex des catégories --

check( 'functions.php : les trois catégories de guides sont nommées',
	str_contains( $fn, "'autorisations-projets'" )
	&& str_contains( $fn, "'regles-urbanisme'" )
	&& str_contains( $fn, "'conseils-demarches'" ) );
// La règle existante ne désindexe que les archives VIDES : elle s'efface au
// premier article. Celle-ci tient les trois catégories hors de l'index même
// remplies, le temps de juger de leur utilité.
check( 'functions.php : elles restent noindex même une fois remplies',
	str_contains( $fn, 'function urbizen_child_noindex_categories_guides' )
	&& (bool) preg_match( '/urbizen_child_noindex_categories_guides.*?\$attributs\[\'noindex\'\] = \'noindex\'/s', $fn ) );
check( 'functions.php : elle passe par aioseo_robots_meta, comme la précédente',
	str_contains( $fn, "add_filter( 'aioseo_robots_meta', 'urbizen_child_noindex_categories_guides'" ) );
check( 'functions.php : la règle des archives vides est conservée',
	str_contains( $fn, 'function urbizen_child_noindex_archives_vides' ) );

// ------------------------------------------------- 8 · la feuille -------------

$css = file_get_contents( $theme . '/assets/css/urbizen-guides.css' );

check( 'La feuille des guides existe', '' !== $css );
// Une règle non scopée déborderait sur l'accueil et les pages commerciales.
$non_scopees = array();
foreach ( preg_split( '/\}/', preg_replace( '#/\*.*?\*/#s', '', $css ) ) as $bloc ) {
	$selecteur = trim( preg_replace( '/\{.*$/s', '', $bloc ) );
	if ( '' === $selecteur || str_starts_with( $selecteur, '@' ) ) {
		continue;
	}
	/*
	 * Découpe sur les virgules de PREMIER NIVEAU seulement : `:is(img,
	 * .wp-block-image img)` en contient une, et un `explode` naïf en tirait un
	 * faux sélecteur « .wp-block-image img) » réputé non scopé.
	 */
	$parts = array();
	$niveau = 0;
	$courant = '';
	foreach ( str_split( $selecteur ) as $c ) {
		if ( '(' === $c ) { $niveau++; }
		if ( ')' === $c ) { $niveau--; }
		if ( ',' === $c && 0 === $niveau ) {
			$parts[] = $courant;
			$courant = '';
			continue;
		}
		$courant .= $c;
	}
	$parts[] = $courant;

	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( '' !== $part && ! str_contains( $part, '.urbizen-guides' ) ) {
			$non_scopees[] = $part;
		}
	}
}
check( 'Chaque règle est scopée .urbizen-guides', array() === $non_scopees,
	implode( ' | ', array_slice( $non_scopees, 0, 4 ) ) );

/*
 * DEUX LARGEURS, DEUX JETONS
 *
 * La feuille portait `max-width: 72ch` deux fois, sur `.guide-corps` et sur
 * `.guide-cta`. Deux écritures identiques, deux largeurs différentes : `ch`
 * vaut l'avance du « 0 » de la police COURANTE, et ces deux blocs n'ont pas la
 * même taille de texte (17px contre 16px). Mesuré : 734,4 px contre 691,2 px.
 *
 * Et une seule largeur ne suffisait pas : un guide fait cohabiter du texte, qui
 * se lit, et des planches, qui se regardent. D'où A — la colonne de lecture —
 * et B — la largeur éditoriale. Le banc vérifie que ces deux-là existent, qu'ils
 * sont dans une unité qui ne dépende pas du contexte de lecture, et que rien ne
 * réintroduise une troisième largeur en dur.
 */
check( 'La colonne de lecture est déclarée une seule fois',
	1 === preg_match_all( '/--u-guide-col:/', $css ) );
check( 'La largeur éditoriale est déclarée une seule fois',
	1 === preg_match_all( '/--u-guide-large:/', $css ) );
check( 'Les deux jetons sont déclarés sur la portée de la feuille',
	(bool) preg_match( '/\.urbizen-guides \{[^}]*--u-guide-col:[^}]*--u-guide-large:/s', $css ) );
/*
 * `rem` et pas `ch` ni `em` : ces deux dernières se résolvent sur la taille de
 * texte de l'élément qui les lit, ce qui est exactement le défaut corrigé. Un
 * retour à `ch` réintroduirait le décalage sans rien changer d'apparent dans la
 * feuille — d'où ce contrôle sur l'unité elle-même.
 */
foreach ( array( '--u-guide-col', '--u-guide-large' ) as $jeton ) {
	check( "$jeton est exprimé dans une unité indépendante du contexte de lecture",
		(bool) preg_match( '/' . $jeton . ':\s*[\d.]+rem\s*;/', $css ),
		'attendu une valeur en rem' );
}
/*
 * La colonne vaut 37,5rem parce qu'une mesure l'a dit : corps de trois guides
 * rendu avec IBM Plex Sans chargée, signes comptés ligne à ligne, lignes
 * finales de paragraphe exclues — médiane 77, quartiles 74 à 80. Le banc ne
 * refait pas la mesure, il fige la borne : au-delà de 40rem la médiane sort des
 * 80 signes, en deçà de 34rem les planches ne tiennent plus.
 */
if ( preg_match( '/--u-guide-col:\s*([\d.]+)rem/', $css, $m ) ) {
	$rem = (float) $m[1];
	check( 'La colonne de lecture reste dans la plage mesurée (34 à 40rem)',
		$rem >= 34 && $rem <= 40, $m[1] . 'rem' );
}
check( 'Aucune largeur de colonne n’est réécrite en dur dans la feuille',
	! preg_match( '/max-width:\s*\d+(\.\d+)?(ch|em)\s*;/', preg_replace( '#/\*.*?\*/#s', '', $css ) ) );

/*
 * A · CE QUI SE LIT. Ces blocs doivent tomber sur la même verticale, à gauche
 * comme à droite : c'est le contrat du lot.
 */
foreach ( array(
	'guide-cta'          => 'l’appel à l’action',
	'guide-retour-ligne' => 'le retour à l’index',
) as $classe => $quoi ) {
	check( "La largeur de $quoi vient de la colonne de lecture",
		(bool) preg_match( '/\.' . $classe . ' \{[^}]*max-width: var\(--u-guide-col\)/s', $css ),
		".$classe" );
}
check( 'Le chapô du hero d’article vient de la colonne lui aussi',
	(bool) preg_match( '/\.guide-hero \.wrap > :is\([^)]*\) \{[^}]*max-width: var\(--u-guide-col\)/s', $css ) );
/*
 * L'ARCHITECTURE CONTRAINTE : tout enfant du contenu prend la colonne par
 * DÉFAUT — un bloc Gutenberg ajouté demain est lisible sans que personne y
 * pense — et seuls les objets nommés en sortent.
 */
check( 'Tout bloc du contenu prend la colonne par défaut',
	(bool) preg_match( '/\.guide-corps \.entry-content > \* \{[^}]*max-width: var\(--u-guide-col\)/s', $css ) );

/*
 * B · CE QUI SE REGARDE. Le corps est le CADRE, pas la colonne : c'est ce qui
 * permet à une planche de s'étendre sans marge négative, donc sans risque de
 * débordement à une largeur intermédiaire.
 */
foreach ( array(
	'guide-corps'   => 'le cadre de l’article',
	'guide-visuel'  => 'le visuel d’en-tête',
	'guide-voisins' => 'la grille « À lire aussi »',
) as $classe => $quoi ) {
	check( "La largeur de $quoi vient de la largeur éditoriale",
		(bool) preg_match( '/\.' . $classe . ' \{[^}]*max-width: var\(--u-guide-large\)/s', $css ),
		".$classe" );
}
check( 'Les planches et les tableaux ont le droit de sortir de la colonne',
	(bool) preg_match( '/\.entry-content > :is\(\.wp-block-image, \.wp-block-table\) \{[^}]*max-width: var\(--u-guide-large\)/s', $css ) );
check( 'Une planche plus étroite que le cadre se centre au lieu de se caler à gauche',
	(bool) preg_match( '/\.guide-corps :is\(img, \.wp-block-image img\) \{[^}]*margin-inline: auto/s', $css ) );
/*
 * `.entry-content` est lui-même un enfant de `.guide-corps` : sans la reprise,
 * il hériterait de la colonne et emporterait les planches avec lui. Le défaut
 * serait invisible à la lecture de la règle, et net à l'écran.
 */
check( 'Le conteneur du contenu ne rétrécit pas ce qu’il contient',
	(bool) preg_match( '/\.guide-corps > \.entry-content \{[^}]*max-width: none/s', $css ) );

// Le `sizes` du visuel doit suivre la largeur éditoriale, pas la colonne :
// sinon le navigateur télécharge une image dimensionnée pour la mauvaise.
$entete_guide = file_get_contents( $theme . '/patterns/guide-entete.php' );
check( 'Le `sizes` du visuel annonce la largeur éditoriale',
	str_contains( $entete_guide, '1040px' ) && ! str_contains( $entete_guide, '1140px' ) );
check( 'Les tableaux longs défilent seuls plutôt que d’élargir la page',
	(bool) preg_match( '/wp-block-table \{[^}]*overflow-x: auto/s', $css ) );
// Le cadrage est passé d'une hauteur maximale à un RAPPORT fixe le 14 août
// 2026 : la bande garde alors la même proportion quelle que soit l'image
// fournie. Le contrôle accepte les deux, il vise l'intention — un visuel cadré,
// qui ne remplit pas l'écran — et non un moyen particulier de l'obtenir.
check( 'Le visuel d’article est cadré et ne remplit pas l’écran',
	(bool) preg_match( '/\.guide-visuel img \{[^}]*(max-height|aspect-ratio)/s', $css )
	&& (bool) preg_match( '/\.guide-visuel img \{[^}]*object-fit: cover/s', $css ) );

// Le corps de l'article ne doit pas hériter de la trame technique du <body>.
check( 'La trame technique est bornée au haut de page',
	(bool) preg_match( '/\.urbizen-guides main \{[^}]*background: var\(--u-surface\)/s', $css ) );

// Le rythme du texte doit viser le conteneur RÉEL : `wp:post-content` enveloppe
// tout dans un `.entry-content`, et un sélecteur d'enfant direct sur
// `.guide-corps` ne toucherait qu'un seul nœud.
check( 'Le rythme des paragraphes cible le conteneur réel du contenu',
	str_contains( $css, '.guide-corps .entry-content > * + *' ) );

// Les trois blocs éditoriaux du gabarit, réutilisables par tout guide à venir.
foreach ( array( 'guide-hypotheses', 'guide-resultat', 'guide-variante' ) as $bloc ) {
	check( "Le bloc éditorial « $bloc » est défini dans le gabarit",
		str_contains( $css, '.guide-corps .' . $bloc ) );
}

check( 'Les sections sont marquées visuellement (filet et barre d’accent)',
	(bool) preg_match( '/\.guide-corps h2,[^{]*\{[^}]*border-top/s', $css )
	&& str_contains( $css, '.guide-corps h2::before' ) );

// La règle de rythme porte trois classes : sans un sélecteur de titre de même
// poids, elle l'emporte et les sections perdent leur respiration. Mesuré une
// fois — 34,5 px au lieu de 78.
check( 'Les titres résistent à la règle de rythme (spécificité alignée)',
	str_contains( $css, '.guide-corps .entry-content > h2' )
	&& str_contains( $css, '.guide-corps .entry-content > h3' ) );
check( 'La pagination garde des cibles de 44 px',
	(bool) preg_match( '/\.guides-pagination \.page-numbers \{[^}]*min-height: 44px/s', $css ) );
check( 'Le focus clavier est visible sur la carte entière',
	str_contains( $css, ':has(.guide-lien:focus-visible)' ) );
check( 'Le mouvement réduit est respecté', str_contains( $css, 'prefers-reduced-motion' ) );

// ------------------------------------------------- 9 · ce qui ne bouge pas ----

/*
 * LE CTA DES GUIDES — TROIS ACTIONS, DEUX BOUTONS, UN SEUL TITRE
 *
 * Le bloc a changé trois fois : `/contact/`, puis deux ancres d'accueil avec la
 * prestation en lien de texte, puis l'inverse. Il se cale sur le tunnel du
 * site, et ce banc l'y tient.
 *
 * POURQUOI CONTRÔLER LE PATTERN ET NON LES DIX-HUIT ARTICLES
 *
 * Le bloc n'est pas recopié dans les guides : il est rendu une fois, par
 * `guide-pied.php`, appelé par `single.html`, qui est le gabarit de tout
 * article. Vérifier le pattern ET son appel prouve les dix-huit d'un coup — et
 * le prouve encore pour le dix-neuvième. Recopier l'assertion dix-huit fois
 * n'aurait rien couvert de plus, et aurait manqué le cas où le gabarit cesse
 * d'appeler le pattern.
 */
$pied = file_get_contents( $theme . '/patterns/guide-pied.php' );

check( 'Le gabarit d’article appelle bien le pied de guide',
	str_contains( $contenus['single'], '"slug":"urbizen-child/guide-pied"' ),
	'sans cet appel, aucun guide ne sert le CTA' );

$actions = array(
	'principal' => array( 'btn-primary', 'Étudier mon projet',  '/#localisation' ),
	'secondaire'=> array( 'btn-ghost',   'Poser mes questions', '/#demander-des-renseignements' ),
);
foreach ( $actions as $rang => $attendu ) {
	list( $classe, $libelle, $ancre ) = $attendu;
	check( "CTA · le bouton $rang porte « $libelle »",
		(bool) preg_match( '~' . $classe . '[^\n]*>' . preg_quote( $libelle, '~' ) . '<~u', $pied ) );
	check( "CTA · le bouton $rang mène à $ancre",
		(bool) preg_match( '~' . $classe . '[^\n]*' . preg_quote( $ancre, '~' ) . '~', $pied ) );
}
check( 'CTA · le lien de texte porte « Tarifs et délais » et mène à /tarifs/',
	(bool) preg_match( '~guide-cta-lien[^\n]*/tarifs/[^\n]*>Tarifs et délais<~u', $pied ) );

/*
 * DEUX BOUTONS, PAS TROIS. Trois appels à l'action de même poids ne
 * hiérarchisent plus rien. On compte les boutons du bloc, pas ceux du fichier :
 * la grille des guides voisins n'en a pas, mais rien ne dit qu'elle n'en aura
 * jamais.
 */
preg_match( '~<aside class="guide-cta".*?</aside>~s', $pied, $bloc_cta );
check( 'CTA · le bloc porte deux boutons, pas trois',
	isset( $bloc_cta[0] ) && 2 === substr_count( $bloc_cta[0], 'class="btn ' ),
	isset( $bloc_cta[0] ) ? substr_count( $bloc_cta[0], 'class="btn ' ) . ' bouton(s)' : 'bloc introuvable' );

/*
 * « Démarrer mon projet » NE REVIENT PAS. Le site a été harmonisé sur
 * « Étudier mon projet » pour l'ancre `/#localisation` ; le libellé abandonné
 * doit rester hors des guides — pattern, gabarits et contenu compris.
 */
/*
 * Le contrôle porte sur ce qui est RENDU, pas sur le fichier : le docblock
 * ci-dessus nomme le libellé abandonné pour expliquer pourquoi il l'est, et un
 * commentaire PHP ne part pas chez le visiteur. Les commentaires sont donc
 * retirés avant l'examen — sans quoi la seule façon de faire passer le banc
 * serait d'effacer l'explication, c'est-à-dire la mémoire de la décision.
 */
$pied_rendu = preg_replace( array( '#/\*.*?\*/#s', '#^\s*//.*$#m' ), '', $pied );
check( 'Aucun libellé « Démarrer mon projet » dans ce qui est rendu',
	! str_contains( $pied_rendu, 'Démarrer mon projet' ) );
foreach ( GABARITS as $nom ) {
	check( "$nom.html : aucun libellé « Démarrer mon projet »",
		! str_contains( $contenus[ $nom ], 'Démarrer mon projet' ) );
}

check( 'Le CTA ne renvoie plus vers /contact/', ! str_contains( $pied, "home_url( '/contact/' )" ) );
check( 'Le titre du CTA est commun aux dix-huit guides',
	(bool) preg_match( '~<h2 id="guide-cta-titre">Besoin d’aide pour votre projet~u', $pied )
	&& ! str_contains( $pied, "\$cta['titre']" ) );
check( 'Ce qui varie par catégorie reste l’éditorial, pas les URL',
	str_contains( $pied, "\$cta['texte']" ) && str_contains( $pied, "\$cta['points']" )
	&& ! str_contains( $pied, "\$cta['url']" ) );

// Le bloc NOMME le service : sans ce cartouche, il s'ouvrait sur une question
// dont le lecteur ne savait pas qui la pose.
check( 'Le CTA nomme le service Urbizen',
	str_contains( $pied, 'class="guide-cta-marque"' )
	&& str_contains( $pied, 'Le service Urbizen' )
	&& str_contains( $css, '.guide-cta-marque' ) );
check( 'Le CTA énumère ce que le service produit',
	str_contains( $pied, 'class="guide-cta-points"' )
	&& str_contains( $css, '.guide-cta-points li::before' ) );
check( 'Le lien de texte est bien dessiné comme tel dans la feuille',
	(bool) preg_match( '/\.guide-cta-lien a \{/', $css ) );
// Relevé à la recette : 20 px de haut de 834 à 1440 px, contre 44 pour les deux
// boutons du même bloc. Troisième action, même cible.
check( 'Le lien de texte garde une cible de 44 px, comme les boutons',
	(bool) preg_match( '/\.guide-cta-lien a \{[^}]*min-height: 44px/s', $css ) );
check( 'Le retour à l’index partage l’axe de la colonne',
	str_contains( $pied, 'class="guide-retour-ligne"' ) );

/*
 * LA TABLE ÉDITORIALE NE PORTE PLUS D'URL. Une table qui en porte finit par en
 * porter une de travers ; les trois destinations sont écrites une seule fois,
 * dans le pattern.
 */
$fn_cta = file_get_contents( $theme . '/functions.php' );
preg_match( '/function urbizen_child_cta_guide\(.*?\n\}/s', $fn_cta, $corps_cta );
check( 'urbizen_child_cta_guide() ne porte aucune URL',
	isset( $corps_cta[0] ) && ! str_contains( $corps_cta[0], 'home_url(' ) );
check( 'urbizen_child_cta_guide() donne trois points à chaque catégorie',
	isset( $corps_cta[0] ) && 4 === preg_match_all( '/.points.\s*=>\s*array\(/', $corps_cta[0] )
	&& 4 === preg_match_all( '/.texte.\s*=>/', $corps_cta[0] ) );
/*
 * Aucun montant, aucune promesse d'obtention : la règle du lot C ne s'arrête
 * pas au corps des guides. Ce bloc-ci argumente, il est donc plus exposé.
 */
check( 'Aucun montant de prestation dans les textes du CTA',
	isset( $corps_cta[0] ) && ! preg_match( '/\d+\s*(&nbsp;)?€/u', $corps_cta[0] ) );
check( 'Aucune promesse d’obtention dans les textes du CTA',
	isset( $corps_cta[0] )
	&& ! preg_match( '/(garanti|nous obtenons|obtention assurée|accord assuré)/iu', $corps_cta[0] ) );

/*
 * L'INSERTION N'EST PAS SYSTÉMATIQUE
 *
 * « Le dossier monté de bout en bout, du plan de masse à l'insertion » laissait
 * entendre que tout dossier en contient une. C'est faux : la composition dépend
 * du projet, et la notice officielle l'écrit dès sa première ligne — c'est même
 * le sujet du guide `pieces-declaration-prealable`. Un argumentaire qui
 * contredit le guide sur lequel il est posé est pire qu'un argumentaire absent.
 *
 * La mention reste permise comme EXEMPLE ; elle doit être conditionnée. Le banc
 * exige donc qu'un marqueur de condition accompagne chaque phrase où le mot
 * apparaît — pas qu'il disparaisse.
 */
$phrases_insertion = array();
if ( isset( $corps_cta[0] ) ) {
	foreach ( preg_split( "/\n/", $corps_cta[0] ) as $ligne ) {
		if ( ! preg_match( '/insertion/iu', $ligne ) ) { continue; }
		if ( ! preg_match( '/(lorsque|si |selon|requis|exigé|le cas échéant|quand)/iu', $ligne ) ) {
			$phrases_insertion[] = trim( $ligne );
		}
	}
}
check( 'Le mot « insertion » n’apparaît dans le CTA que sous condition',
	array() === $phrases_insertion, implode( ' | ', $phrases_insertion ) );

/*
 * Pas de promesse d'ORGANISATION non plus. « Un interlocuteur unique » engageait
 * qu'une seule personne physique traite toutes les étapes — invérifiable, et
 * sans rapport avec la valeur rendue, qui est la continuité de
 * l'accompagnement.
 */
check( 'Aucune promesse sur l’organisation dans les textes du CTA',
	isset( $corps_cta[0] )
	&& ! preg_match( '/(interlocuteur unique|une seule personne|toujours la même personne)/iu', $corps_cta[0] ) );

/*
 * NI CHIFFRE, NI ABSOLU
 *
 * « Un dossier complet du premier coup, c'est un mois gagné » cumulait les deux
 * : un dossier réputé complet d'emblée, et un gain de temps quantifié. Ni l'un
 * ni l'autre ne dépend d'Urbizen seul — une demande de pièces peut venir d'une
 * exigence locale, et le délai d'instruction appartient à l'administration.
 *
 * Le contrôle ne vise QUE les textes du CTA, jamais le corps des guides : ces
 * derniers citent la garantie de l'article R.431-36 et des délais légaux, qui
 * sont des faits réglementaires. Un motif appliqué partout aurait rendu le banc
 * rouge sur du contenu juste — et l'aurait fait désactiver.
 */
$promesses_cta = array(
	'du premier coup',
	'/\b(un|deux|trois|\d+)\s+(jour|semaine|mois|an)s?\s+(gagn|[ée]conomis)/iu',
	'/(z[ée]ro|aucune)\s+(pi[èe]ce|demande)\s+(compl[ée]mentaire|suppl[ée]mentaire)/iu',
	'/\bgaranti(e|es|s)?\b/iu',
	'/100\s*%/',
);
$trouve = array();
if ( isset( $corps_cta[0] ) ) {
	foreach ( $promesses_cta as $motif ) {
		$hit = str_starts_with( $motif, '/' )
			? preg_match( $motif, $corps_cta[0], $m )
			: ( str_contains( $corps_cta[0], $motif ) ? ( $m = array( $motif ) ) && true : false );
		if ( $hit ) { $trouve[] = $m[0]; }
	}
}
check( 'Aucune promesse chiffrée ni absolue dans les textes du CTA',
	array() === $trouve, implode( ' | ', $trouve ) );

// Miroir du contrôle de `test-navigation.php` : les deux ont basculé ensemble
// le jour où /guides/ a répondu 200.
$entete = file_get_contents( $theme . '/patterns/header-accueil.php' );
check( 'Le menu porte l’entrée Guides', str_contains( $entete, '>Guides</a>' ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
