<?php
/**
 * Banc d'essai de fidélité du portage WordPress de la page d'accueil.
 *
 * Compare le markup rendu par les patterns et par le gabarit avec la maquette
 * de référence `frontend/homepage/index.html`. Toute divergence autre que
 * l'URL du logo et ses dimensions intrinsèques fait échouer le test.
 *
 * Hors WordPress : les quelques fonctions utilisées sont doublées ci-dessous.
 * Aucun accès réseau, aucune base de données.
 */

define( 'ABSPATH', __DIR__ );

$racine   = dirname( __DIR__, 2 );
$theme    = $racine . '/wordpress/urbizen-child';
$maquette = $racine . '/frontend/homepage/index.html';

function get_theme_file_uri( $chemin = '' ) {
	return 'https://exemple.test/wp-content/themes/urbizen-child/' . ltrim( $chemin, '/' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
// Le pattern rend l'en-tête de l'ACCUEIL ici : le logo y pointe vers « #top »,
// comme dans la maquette. Sur les pages internes (hors test), il pointe vers
// home_url('/') — voir header-accueil.php.
function is_front_page() {
	return true;
}

/*
 * Doublons ajoutés le 14 août 2026 : l'en-tête interroge désormais la page
 * d'articles pour savoir s'il faut allumer « Guides ». Ces valeurs décrivent un
 * site où /guides/ existe et où l'on est ailleurs que dessus.
 */
function get_option( $nom, $defaut = false ) {
	return 'page_for_posts' === $nom ? 1204 : $defaut;
}
function get_the_title( $id = 0 ) { return 'Guides d’urbanisme'; }
function is_home() { return $GLOBALS['banc_home'] ?? false; }
function is_category() { return $GLOBALS['banc_category'] ?? false; }
function is_tag() { return false; }
function is_date() { return false; }

function get_permalink( $id = 0 ) { return 'https://urbizen.fr/guides/'; }
function is_singular( $t = '' ) { return false; }
function untrailingslashit( $x ) { return rtrim( (string) $x, '/\\' ); }


$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-66s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

$lignes = explode( "\n", file_get_contents( $maquette ) );

/** Extrait des lignes de la maquette, bornes 1-indexées incluses. */
function maquette( array $lignes, $debut, $fin ) {
	return implode( "\n", array_slice( $lignes, $debut - 1, $fin - $debut + 1 ) );
}

/**
 * Repère le bloc délimité par deux balises, et renvoie ses bornes 1-indexées.
 *
 * Les numéros de ligne ne sont jamais codés en dur : la maquette évolue, et un
 * simple décalage ferait échouer la comparaison pour une mauvaise raison.
 *
 * @param array<int, string> $lignes Lignes de la maquette.
 * @param string             $ouvre  Balise ouvrante, comparée sur la ligne nue.
 * @param string             $ferme  Balise fermante.
 * @return array{0: int, 1: int} Bornes, ou [0, 0] si le bloc est introuvable.
 */
function bornes( array $lignes, $ouvre, $ferme ) {
	$debut = 0;
	$fin   = 0;

	foreach ( $lignes as $i => $ligne ) {
		$nu = trim( $ligne );

		if ( 0 === $debut && $ouvre === $nu ) {
			$debut = $i + 1;
		}

		if ( $debut > 0 && 0 === $fin && $ferme === $nu ) {
			$fin = $i + 1;
		}
	}

	return array( $debut, $fin );
}

/** Rend un pattern et retire l'enrobage de bloc. */
function rendre_pattern( $fichier ) {
	ob_start();
	include $fichier;
	$sortie = ob_get_clean();
	$sortie = preg_replace( '/^.*?<!-- wp:html -->\n/s', '', $sortie );
	$sortie = preg_replace( '/\n<!-- \/wp:html -->\s*$/s', '', $sortie );
	return $sortie;
}

/**
 * Ramène l'URL du logo à celle de la maquette, pour comparer tout le reste.
 *
 * Ne remplace QUE l'adresse. La version précédente réécrivait la balise
 * entière à partir d'un motif qui figeait l'ordre et la liste des attributs —
 * `src`, saut de ligne, `alt`, `class` facultative. Ajouter `loading` au logo
 * le 14 août 2026 a suffi à ce que le motif ne reconnaisse plus rien : la
 * neutralisation ne s'appliquait plus, et le banc signalait une divergence de
 * markup là où seule l'URL différait.
 *
 * Un neutraliseur doit être aveugle à ce qu'il ne neutralise pas.
 *
 * @param string $html Sortie du pattern.
 * @return string
 */
function neutraliser_logo( $html ) {
	return preg_replace(
		'#https?://[^"\']*/wp-content/themes/urbizen-child/assets/img/logo-urbizen\.png#',
		'assets/logo-urbizen.png',
		$html
	);
}

// ---------------------------------------------------------------- en-tête ---
$entete_rendu = neutraliser_logo( rendre_pattern( $theme . '/patterns/header-accueil.php' ) );
list( $e_debut, $e_fin ) = bornes( $lignes, '<header class="site" id="top">', '</header>' );
check( 'Maquette : les bornes de <header> sont repérées', $e_debut > 0 && $e_fin > $e_debut );
$entete_ref   = maquette( $lignes, $e_debut, $e_fin );

check( 'En-tête : markup identique à la maquette (hors URL du logo)', $entete_rendu === $entete_ref );
check( 'En-tête : logo résolu par le thème, aucune URL en dur',
	str_contains( rendre_pattern( $theme . '/patterns/header-accueil.php' ), '/wp-content/themes/urbizen-child/assets/img/logo-urbizen.png' ) );
// Mesuré en conditions réelles : ces attributs donnent à l'image un rapport
// d'aspect définitif qui change le calcul flex de l'en-tête. Le logo passait de
// 109 à 290 px et le menu perdait 135 px. Leur absence est donc une exigence.
check( 'En-tête : AUCUN attribut width/height sur le logo',
	! preg_match( '/<img[^>]*logo-urbizen\.png[^>]*(width|height)=/', rendre_pattern( $theme . '/patterns/header-accueil.php' ) ) );
// Le nombre de liens est comparé à la maquette, pas à un total deviné :
// 17 balises <a> — menu desktop, menu mobile, connexion et CTA.
check( 'En-tête : tous les liens de la maquette présents',
	substr_count( $entete_rendu, '<a ' ) === substr_count( $entete_ref, '<a ' ) );
check( 'En-tête : icônes téléphone + compte et CTA « Étudier mon projet » présents',
	str_contains( $entete_rendu, 'class="icon-btn link-tel"' )
	&& str_contains( $entete_rendu, 'class="icon-btn link-login"' )
	&& str_contains( $entete_rendu, 'js-start' )
	&& str_contains( $entete_rendu, '>Étudier mon projet</a>' ) );
check( 'En-tête : espace client = contrôle honnête (bouton aria-disabled, sans faux lien ni texte « Se connecter »)',
	str_contains( $entete_rendu, 'aria-label="Espace client (bientôt disponible)"' )
	&& str_contains( $entete_rendu, 'aria-disabled="true"' )
	&& ! preg_match( '/class="[^"]*link-login[^"]*" href="#"/', $entete_rendu )
	&& ! str_contains( $entete_rendu, '>Se connecter<' ) );
check( 'En-tête : icône téléphone « Nous contacter » pilotant le panneau de contact',
	str_contains( $entete_rendu, 'aria-label="Nous contacter"' )
	&& str_contains( $entete_rendu, 'aria-controls="contact-panel"' ) );
check( 'Centre de contact : panneau unique « Parlons de votre projet » avec ses trois canaux',
	1 === substr_count( $entete_rendu, 'id="contact-panel"' )
	&& str_contains( $entete_rendu, 'Parlons de votre projet' )
	&& str_contains( $entete_rendu, 'Appeler maintenant' )
	&& str_contains( $entete_rendu, 'Réserver un appel' )
	&& str_contains( $entete_rendu, 'Écrire à Urbizen' ) );
// « Écrire à Urbizen » n'est plus une promesse : il mène au formulaire de
// renseignements. Il ne reste donc qu'un seul canal honnêtement « bientôt »,
// « Réserver un appel » — et il doit le rester tant que la prise de rendez-vous
// n'existe pas. Annoncer disponible ce qui ne l'est pas serait pire que de le
// taire.
check( 'Centre de contact : « Appeler » = numéro réel de la charte, 1 seul canal encore « bientôt »',
	str_contains( $entete_rendu, 'href="tel:+33664895815"' )
	&& 1 === substr_count( $entete_rendu, 'contact-ch is-soon' )
	&& 1 === substr_count( $entete_rendu, 'Bientôt disponible' )
	&& str_contains( $entete_rendu, 'Réserver un appel' ) );
// Le CTA de l'en-tête porte deux libellés — long sur grand écran, court sur
// mobile — et son intitulé accessible ne dépend donc pas de celui qui est
// affiché. Sans `aria-label`, un lecteur d'écran n'annoncerait que « Démarrer ».
check( 'En-tête : CTA responsive à deux libellés et intitulé accessible stable',
	str_contains( $entete_rendu, 'class="nav-cta-long"' )
	&& str_contains( $entete_rendu, 'class="nav-cta-short"' )
	&& str_contains( $entete_rendu, 'aria-label="Étudier mon projet"' )
	&& str_contains( $entete_rendu, 'aria-hidden="true"' ) );
check( 'En-tête : burger mobile et ses attributs ARIA conservés',
	str_contains( $entete_rendu, 'class="burger"' )
	&& str_contains( $entete_rendu, 'aria-expanded="false"' )
	&& str_contains( $entete_rendu, 'aria-controls="mmenu"' ) );

// ----------------------------------------------------------- pied de page ---
$pied_rendu = neutraliser_logo( rendre_pattern( $theme . '/patterns/footer-accueil.php' ) );
list( $p_debut, $p_fin ) = bornes( $lignes, '<footer class="site-footer">', '</footer>' );
check( 'Maquette : les bornes de <footer> sont repérées', $p_debut > 0 && $p_fin > $p_debut );
$pied_ref   = maquette( $lignes, $p_debut, $p_fin );

check( 'Pied de page : markup identique à la maquette (hors URL du logo)', $pied_rendu === $pied_ref );
// La grille .foot est en quatre colonnes (CSS) : une marque + trois listes.
check( 'Pied de page : marque et trois listes de liens conservées',
	str_contains( $pied_rendu, 'class="foot-brand"' )
	&& 3 === substr_count( $pied_rendu, '<ul>' )
	&& substr_count( $pied_rendu, '<h4>' ) === substr_count( $pied_ref, '<h4>' ) );
check( 'Pied de page : AUCUN attribut width/height sur le logo',
	! preg_match( '/<img[^>]*logo-urbizen\.png[^>]*(width|height)=/', rendre_pattern( $theme . '/patterns/footer-accueil.php' ) ) );
check( 'Pied de page : coordonnées inchangées',
	str_contains( $pied_rendu, 'contact@urbizen.fr' ) && str_contains( $pied_rendu, '+33 6 64 89 58 15' ) );

// ---------------------------------------------------------------- gabarit ---
$gabarit = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );

check( 'Gabarit : aucun PHP', ! str_contains( $gabarit, '<?php' ) && ! str_contains( $gabarit, '<?=' ) );
check( 'Gabarit : appelle les deux template parts Urbizen',
	str_contains( $gabarit, '"slug":"header-urbizen"' ) && str_contains( $gabarit, '"slug":"footer-urbizen"' ) );
check( 'Gabarit : ne réutilise pas les parts Hostinger',
	! preg_match( '/"slug":"(header|footer|footer-landing|superposition-de-navigation)"/', $gabarit ) );
check( 'Gabarit : bloc cadastre présent avec storageKey « accueil »',
	str_contains( $gabarit, '<!-- wp:urbizen/cadastre' ) && str_contains( $gabarit, '"storageKey":"accueil"' ) );
check( 'Gabarit : ancien point de montage supprimé', ! str_contains( $gabarit, 'cadastre-mount' ) );
check( 'Gabarit : conteneur de portée .urbizen-accueil', str_contains( $gabarit, '<div class="urbizen-accueil">' ) );

// Corps : le contenu de <main> doit être identique à la maquette, au bloc près.
$corps_rendu = $gabarit;
$corps_rendu = preg_replace( '/.*?<!-- wp:html -->\n<main/s', '<main', $corps_rendu, 1 );
$corps_rendu = preg_replace( '#</main>.*#s', '</main>', $corps_rendu );
$corps_rendu = str_replace(
	array(
		"      <!-- Le bloc urbizen/cadastre est rendu ici par WordPress -->\n<!-- /wp:html -->\n\n<!-- wp:urbizen/cadastre {\"label\":\"Adresse du projet\",\"placeholder\":\"Commencez à saisir une adresse…\",\"continueLabel\":\"Continuer\",\"storageKey\":\"accueil\"} /-->\n\n<!-- wp:html -->\n",
	),
	array(
		"      <!-- Le composant partagé se monte ici -->\n      <div id=\"cadastre-mount\"></div>\n",
	),
	$corps_rendu
);
// Bornes repérées sur le contenu, non codées en dur : la maquette évolue, et
// un décalage de lignes ferait échouer la comparaison pour une mauvaise raison.
$debut_main = 0;
$fin_main   = 0;

foreach ( $lignes as $i => $ligne ) {
	$nu = trim( $ligne );

	if ( 0 === $debut_main && '<main>' === $nu ) {
		$debut_main = $i + 1;
	}

	if ( '</main>' === $nu ) {
		$fin_main = $i + 1;
	}
}

check( 'Maquette : les bornes de <main> sont repérées', $debut_main > 0 && $fin_main > $debut_main );

$corps_ref = maquette( $lignes, $debut_main, $fin_main );
check( 'Corps : les 13 sections identiques à la maquette', $corps_rendu === $corps_ref );

check( 'Corps : SVG du hero inline et inchangé',
	substr_count( $gabarit, '<svg' ) === substr_count( $corps_ref, '<svg' )
	&& str_contains( $gabarit, 'DP4 · FAÇADES' ) );

// ------------------------------------------------------------ ressources ---
foreach ( array(
	'assets/css/urbizen-tokens.css', 'assets/css/urbizen-fonts.css', 'assets/css/urbizen-homepage.css',
	'assets/js/urbizen-homepage.js', 'assets/img/logo-urbizen.png',
	'assets/fonts/space-grotesk-latin.woff2', 'assets/fonts/ibm-plex-sans-latin.woff2',
	'assets/fonts/ibm-plex-mono-latin.woff2', 'assets/fonts/OFL-space-grotesk.txt', 'assets/fonts/OFL-ibm-plex.txt',
) as $f ) {
	check( 'Ressource présente : ' . $f, is_file( $theme . '/' . $f ) );
}

$js = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );
check( 'JavaScript : aucun montage manuel du cadastre', ! str_contains( $js, 'UrbizenCadastre.mount' ) );
check( 'JavaScript : comportements de la maquette conservés',
	str_contains( $js, 'urbizen:parcel-confirmed' ) && str_contains( $js, 'js-start' ) && str_contains( $js, 'burger' ) );

$css     = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );
$css_ref = file_get_contents( $racine . '/frontend/homepage/homepage.css' );

check( 'CSS : aucun !important ajouté',
	substr_count( $css, '!important' ) === substr_count( $css_ref, '!important' ) );

// `:root` désigne <html>, un ancêtre de la portée : le préfixer produit un
// sélecteur qui ne peut jamais correspondre. C'est ce défaut qui avait rendu
// muette la règle `--u-pad: 18px` et décalé de 10 px la rupture mobile.
check( 'CSS : aucun sélecteur mort « .urbizen-accueil :root »',
	! str_contains( $css, '.urbizen-accueil :root' ) );
check( 'CSS : aucun sélecteur mort « .urbizen-accueil body »',
	! str_contains( $css, '.urbizen-accueil body' ) );

// La règle décisive du responsive mobile doit exister, portée par le conteneur.
check( 'CSS : la media query 420px porte --u-pad sur le conteneur',
	(bool) preg_match(
		'/@media\s*\(max-width:\s*420px\)\s*\{[^}]*\.urbizen-accueil\s*\{[^}]*--u-pad:\s*18px/',
		$css
	) );

// Déclarations : mêmes couples propriété/valeur, dans le même ordre trié.
$decls = static function ( $source ) {
	$sans = preg_replace( '#/\*.*?\*/#s', '', $source );
	preg_match_all( '/([-a-z]+)\s*:\s*([^;{}]+)[;}]/', $sans, $m, PREG_SET_ORDER );
	$out = array_map( static fn( $x ) => trim( $x[1] ) . ':' . trim( $x[2] ), $m );
	sort( $out );
	return $out;
};
$d_ref = $decls( $css_ref );
$d_css = $decls( $css );
// Le nombre de déclarations n'est pas figé : la maquette évolue. Ce qui doit
// rester vrai, c'est que le fichier généré en contient exactement autant que sa
// source — le scoping ne touche qu'aux sélecteurs.
check( 'CSS : autant de déclarations que la maquette (' . count( $d_ref ) . ')',
	count( $d_ref ) === count( $d_css ) && count( $d_ref ) > 0 );
check( 'CSS : aucune valeur de propriété modifiée', $d_ref === $d_css );

$fonts = file_get_contents( $theme . '/assets/css/urbizen-fonts.css' );
check( 'Polices : aucune référence à Google', ! preg_match( '#url\([^)]*(googleapis|gstatic)#', $fonts ) );
check( 'Polices : seules les graisses relevées sont déclarées',
	str_contains( $fonts, 'font-weight: 500 700' )   // Space Grotesk 500/600/700
	&& str_contains( $fonts, 'font-weight: 400 600' ) // IBM Plex Sans 400/500/600
	&& str_contains( $fonts, 'font-weight: 400;' ) ); // IBM Plex Mono 400

$json = json_decode( file_get_contents( $theme . '/theme.json' ), true );
check( 'theme.json : gabarit déclaré dans customTemplates',
	'page-accueil-urbizen' === ( $json['customTemplates'][0]['name'] ?? '' ) );
check( 'theme.json : parts Urbizen déclarés',
	array( 'header-urbizen', 'footer-urbizen' ) === array_column( $json['templateParts'] ?? array(), 'name' ) );
// Comparaison au fichier tel qu'il était avant ce portage : ni la palette ni
// le CSS personnalisé hérité de la production ne doivent avoir bougé.
$json_ref = json_decode( file_get_contents( $racine . '/tests/homepage/theme-json-reference.json' ), true );
check( 'theme.json : palette intacte',
	( $json_ref['settings']['color']['palette'] ?? null ) === ( $json['settings']['color']['palette'] ?? null ) );
check( 'theme.json : CSS personnalisé intact',
	( $json_ref['styles']['css'] ?? null ) === ( $json['styles']['css'] ?? null ) );
check( 'theme.json : seules customTemplates et templateParts ont été ajoutées',
	array( 'customTemplates', 'templateParts' ) === array_values( array_diff( array_keys( $json ), array_keys( $json_ref ) ) )
	&& array() === array_diff( array_keys( $json_ref ), array_keys( $json ) ) );

// ------------------------------------ centre de contact : visibilité & ordre ---
// Ordre des quatre canaux, du haut vers le bas.
$pos = static function ( $s ) use ( $entete_rendu ) { return strpos( $entete_rendu, $s ); };
check( 'Centre de contact : les trois canaux dans l\'ordre (Appeler → Réserver → Écrire)',
	$pos( 'Appeler maintenant' ) < $pos( 'Réserver un appel' )
	&& $pos( 'Réserver un appel' ) < $pos( 'Écrire à Urbizen' ) );
check( 'Centre de contact : titre + première action avant la dernière (pas de troncature « Écrire » en tête)',
	$pos( 'Parlons de votre projet' ) < $pos( 'Appeler maintenant' )
	&& $pos( 'Appeler maintenant' ) < $pos( 'Écrire à Urbizen' ) );
check( 'Centre de contact : panneau hors flux (position: fixed) et non un popover absolu du header',
	(bool) preg_match( '/\.contact-panel\s*\{[^}]*position:\s*fixed/', $css )
	&& ! (bool) preg_match( '/\.contact-panel\s*\{[^}]*position:\s*absolute/', $css ) );
check( 'Centre de contact : feuille mobile au MÊME seuil que le burger (900px)',
	(bool) preg_match( '/@media \(max-width: 900px\) \{[^@]*\.contact-panel[^}]*bottom:\s*0/s', $css )
	&& str_contains( $css, 'min(85dvh, 720px)' ) );
check( 'Centre de contact : fond d\'écran (CSS) et verrou de défilement (JS en ligne)',
	str_contains( $css, '.contact-backdrop' ) && str_contains( $js, 'docEl.style.overflow' ) );
check( 'JavaScript : panneau sorti du header vers .urbizen-accueil (style scopé conservé)',
	str_contains( $js, 'closest(".urbizen-accueil")' ) && str_contains( $js, 'appendChild(panel)' ) );
check( 'JavaScript : fermeture Échap, focus restitué sans saut (preventScroll), fond cliquable',
	str_contains( $js, 'Escape' ) && str_contains( $js, 'preventScroll' )
	&& str_contains( $js, 'contact-backdrop' ) && str_contains( $js, 'aria-expanded' ) );

// ------------------------------------------------- hero : texte avant image ---
check( 'Hero : le texte précède l\'illustration dans le DOM (empilé = texte puis planche)',
	strpos( $corps_ref, 'class="hero-copy"' ) < strpos( $corps_ref, 'class="hero-board"' ) );
check( 'Hero : aucun ordre CSS ne remonte l\'illustration au-dessus du titre',
	! (bool) preg_match( '/\.hero-board[^{]*\{[^}]*order:\s*-1/', $css ) );

/*
 * Non-régression : l'accueil retenu est celui qui est servi en production. Une
 * refonte antérieure (`ee1415c`) vivait dans le dépôt sans avoir jamais été
 * déployée ; son historique reste dans Git, mais son markup n'est plus la
 * référence. Ces quelques absences suffisent à ce qu'une régénération ne le
 * réintroduise pas en silence — il ne s'agit pas d'éprouver chaque absence,
 * mais de repérer un retour en masse.
 */
foreach ( array( 'id="exemples"', 'class="hero-plan"', 'DP6 · INSERTION 3D' ) as $abandonne ) {
	check(
		sprintf( 'Référence : « %s » n\'est pas réintroduit', $abandonne ),
		! str_contains( $gabarit, $abandonne ) && ! str_contains( $corps_ref, $abandonne )
	);
}

echo "\n", 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
