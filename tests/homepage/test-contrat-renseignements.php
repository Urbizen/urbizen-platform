<?php
/**
 * Contrat statique du parcours « Écrire à Urbizen ».
 *
 * `test-parcours-renseignements.py` rejoue le parcours dans un navigateur et
 * prouve qu'il fonctionne. Ce banc-ci garde ce qu'un navigateur ne voit pas, ou
 * ne verrait qu'indirectement :
 *
 * - le **motif WordPress**, que la maquette ne rend pas — c'est lui qui est
 *   déployé, et lui seul porte le préfixe de permalien ;
 * - la **promesse d'honnêteté** : « Réserver un appel » reste annoncé
 *   indisponible tant qu'il l'est. Rendre un canal fonctionnel ne doit pas
 *   emporter l'aveu des autres ;
 * - l'**unicité de l'implémentation** : un seul endroit décide de l'état du
 *   bloc. C'est la règle qui a justifié le refactor, et rien dans le rendu ne
 *   la protégerait ;
 * - le **repli sans JavaScript** : de vrais liens vers une ancre qui existe ;
 * - les trois `scroll-margin-top`, exprimés en CSS et non calculés au clic.
 *
 * Hors WordPress : aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
}

$pattern  = file_get_contents( $theme . '/patterns/header-accueil.php' );
$maquette = file_get_contents( $racine . '/frontend/homepage/index.html' );
$gabarit  = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front    = file_get_contents( $theme . '/templates/front-page.html' );
$css      = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );
$css_src  = file_get_contents( $racine . '/frontend/homepage/homepage.css' );
$js       = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );
$js_src   = file_get_contents( $racine . '/frontend/homepage/homepage.js' );

/* ------------------------------------------------- les deux déclencheurs --- */

// Le motif porte le préfixe de permalien : sur une page interne, une ancre nue
// pointerait vers la page courante et ne mènerait nulle part.
check( 'Motif : le canal « Écrire » est un lien vers l\'ancre, préfixe compris',
	1 === preg_match(
		'#<a class="contact-ch-link js-open-inquiry" href="<\?php echo \$pfx; \?>\#demander-des-renseignements">#',
		$pattern ) );

check( 'Motif : le menu mobile porte l\'entrée « Écrire à Urbizen »',
	1 === preg_match(
		'#<a class="js-open-inquiry" href="<\?php echo \$pfx; \?>\#demander-des-renseignements">Écrire à Urbizen</a>#',
		$pattern ) );

check( 'Motif : l\'entrée du menu est placée après les liens métier, avant le CTA',
	strpos( $pattern, '#tarifs">Tarifs' ) < strpos( $pattern, 'js-open-inquiry" href="<?php echo $pfx; ?>#demander-des-renseignements">Écrire' )
	&& strpos( $pattern, 'js-open-inquiry" href="<?php echo $pfx; ?>#demander-des-renseignements">Écrire' ) < strpos( $pattern, 'btn btn-primary js-start' ) );

check( 'Maquette : les deux mêmes déclencheurs, sans préfixe',
	2 === substr_count( $maquette, 'js-open-inquiry' )
	&& 2 === substr_count( $maquette, 'href="#demander-des-renseignements"' ) );

check( 'Motif : exactement deux déclencheurs, pas un de plus',
	2 === substr_count( $pattern, 'js-open-inquiry' ) );

/* ------------------------------------- « Écrire » n'est plus indisponible --- */

// Un `aria-disabled` résiduel annoncerait aux lecteurs d'écran un contrôle mort
// alors qu'il fonctionne — pire qu'avant, car le lien serait bien focalisable.
check( 'Le canal « Écrire » n\'est plus un faux bouton désactivé',
	! preg_match( '#<span class="contact-ch-link" role="button" aria-disabled="true">\s*<span class="contact-ch-ico"[^>]*><svg[^>]*><rect x="3\.5"#', $pattern )
	&& ! preg_match( '#<span class="contact-ch-link" role="button" aria-disabled="true">\s*<span class="contact-ch-ico"[^>]*><svg[^>]*><rect x="3\.5"#', $maquette ) );

/** Isole la liste des canaux : ailleurs, la page porte d'autres « bientôt ». */
function canaux( string $doc ): string {
	$i = strpos( $doc, '<ul class="contact-channels">' );
	$j = false === $i ? false : strpos( $doc, '</ul>', $i );
	return false === $j ? '' : substr( $doc, $i, $j - $i );
}

foreach ( array( 'motif' => $pattern, 'maquette' => $maquette ) as $nom => $doc ) {
	$liste = canaux( $doc );
	check( "[$nom] la liste des canaux de contact est bien délimitée", '' !== $liste );
	check( "[$nom] « Écrire à Urbizen » annonce le délai de réponse, plus « bientôt »",
		str_contains( $doc, '<span class="contact-ch-title">Écrire à Urbizen</span><span class="contact-ch-sub">Réponse sous 24 h ouvrées</span>' ) );

	// Le seul canal encore indisponible. L'annoncer disponible serait mentir.
	check( "[$nom] « Réserver un appel » reste honnêtement indisponible",
		str_contains( $doc, '<span class="contact-ch-title">Réserver un appel</span><span class="contact-ch-sub">Bientôt disponible</span>' )
		&& 1 === substr_count( $liste, 'contact-ch is-soon' )
		&& 1 === substr_count( $liste, 'Bientôt disponible' ) );

	// Les autres « bientôt » du site ne relèvent pas de ce canal.
	check( "[$nom] l'Espace client garde sa mention « bientôt disponible »",
		str_contains( $doc, 'aria-label="Espace client (bientôt disponible)"' ) );
}

/* ------------------------------------------------------- l'ancre et sa cible */

foreach ( array( 'maquette' => $maquette, 'gabarit' => $gabarit, 'front-page' => $front ) as $nom => $doc ) {
	check( "[$nom] l'ancre existe, une seule fois",
		1 === substr_count( $doc, 'id="demander-des-renseignements"' ) );
	check( "[$nom] le titre du bloc est focalisable programmatiquement",
		1 === substr_count( $doc, '<h3 id="titre-renseignements" tabindex="-1">Votre demande de renseignements</h3>' ) );
	check( "[$nom] le bouton natif pilote toujours le même panneau",
		str_contains( $doc, 'class="btn btn-contact js-toggle-inquiry"' )
		&& str_contains( $doc, 'aria-controls="cta-inquiry-form"' )
		&& str_contains( $doc, 'id="cta-inquiry-form"' ) );
}

check( 'Les deux gabarits restent strictement synchronisés', $gabarit === $front );

/* ----------------------------------------- une seule implémentation en JS --- */

foreach ( array( 'source' => $js_src, 'porté' => $js ) as $nom => $doc ) {
	check( "[$nom] setInquiry et openInquiry existent",
		str_contains( $doc, 'setInquiry = function (open)' )
		&& str_contains( $doc, 'openInquiry = function ()' ) );

	// `openInquiry` ne doit jamais refermer : c'est toute la différence avec un
	// basculement, et c'est ce qui garantit l'idempotence du parcours.
	check( "[$nom] openInquiry ouvre sans jamais refermer",
		1 === preg_match( '#openInquiry = function \(\) \{\s*if \(inquiryPanel\.hidden\) \{ setInquiry\(true\); \}\s*\};#', $doc ) );

	// Un seul endroit touche la visibilité du panneau et l'état du bouton.
	check( "[$nom] un seul endroit change l'état du bloc",
		1 === substr_count( $doc, 'inquiryPanel.hidden = !open;' )
		&& 1 === substr_count( $doc, 'inquiryToggle.setAttribute( "aria-expanded"' )
			+ substr_count( $doc, 'inquiryToggle.setAttribute("aria-expanded"' ) );

	check( "[$nom] closeMobileMenu est extraite et unique",
		str_contains( $doc, 'closeMobileMenu = function ()' )
		&& 1 === substr_count( $doc, 'burger.setAttribute("aria-expanded", "false");' ) );

	check( "[$nom] le parcours réutilise closeContact(false)",
		str_contains( $doc, 'closeContact(false)' ) );

	// L'ordre se lit dans le gestionnaire du parcours : `closeMobileMenu` est
	// aussi appelée par le menu lui-même, bien plus haut dans le fichier.
	$debut = strpos( $doc, 'inquiryLinks.forEach' );
	$gest  = false === $debut ? '' : substr( $doc, $debut );
	check( "[$nom] l'ordre est : fermer le dialogue, fermer le menu, ouvrir, défiler",
		'' !== $gest
		&& strpos( $gest, 'closeContact(false)' ) < strpos( $gest, 'closeMobileMenu();' )
		&& strpos( $gest, 'closeMobileMenu();' ) < strpos( $gest, 'openInquiry();' )
		&& strpos( $gest, 'openInquiry();' ) < strpos( $gest, 'inquirySection.scrollIntoView' ) );

	check( "[$nom] le focus vise le titre, jamais un champ du formulaire",
		str_contains( $doc, 'focusSafe(inquiryTitle || inquiryPanel);' )
		&& ! preg_match( '#focusSafe\([^)]*querySelector\([^)]*input#', $doc ) );

	/* ---------------------------------------------------- mouvement réduit --- */

	// Les deux défilements de ce parcours passent par `glissement()`. Un
	// `behavior: "smooth"` écrit en dur ici imposerait l'animation à qui demande
	// justement qu'on la lui épargne.
	check( "[$nom] les défilements du bloc respectent la préférence de mouvement",
		2 === substr_count( $doc, 'behavior: glissement()' )
		&& str_contains( $doc, 'return reduceMotion ? "auto" : "smooth";' ) );

	/* ------------------------------------------------ arrivée par l'ancre --- */

	check( "[$nom] openInquiryFromHash existe et ne réagit qu'à la bonne ancre",
		str_contains( $doc, 'var ANCRE_RENSEIGNEMENTS = "#demander-des-renseignements";' )
		&& str_contains( $doc, 'openInquiryFromHash = function (anime)' )
		&& str_contains( $doc, 'if (window.location.hash !== ANCRE_RENSEIGNEMENTS) { return; }' ) );

	// Elle passe par la fonction centrale : aucun second endroit ne déplie.
	check( "[$nom] l'arrivée par l'ancre réutilise openInquiry, sans toucher l'état",
		1 === preg_match( '#openInquiryFromHash = function \(anime\) \{.*?openInquiry\(\);#s', $doc )
		&& 1 === substr_count( $doc, 'inquiryPanel.hidden = !open;' ) );

	// L'ancre garantit l'ouverture ; la quitter ne referme jamais. Un formulaire
	// à moitié rempli ne doit pas disparaître au premier clic vers une autre
	// section.
	check( "[$nom] hashchange est écouté, et rien n'y referme le bloc",
		str_contains( $doc, 'window.addEventListener("hashchange", function () { openInquiryFromHash(true); });' )
		&& ! preg_match( '#hashchange.*?setInquiry\(false\)#s', $doc ) );

	// À l'arrivée, `instant` — jamais `auto`, qui hériterait du `scroll-behavior:
	// smooth` de la feuille et animerait douze mille pixels malgré l'intention.
	check( "[$nom] l'arrivée ne défile jamais en animé, le hashchange respecte la préférence",
		str_contains( $doc, 'behavior: anime ? glissement() : "instant"' )
		&& str_contains( $doc, 'openInquiryFromHash(false);' ) );

	// Le recalage attend deux trames : mesuré nécessaire, la page se posant
	// après le saut d'ancre du navigateur.
	check( "[$nom] le recalage laisse la mise en page se stabiliser",
		2 === substr_count( $doc, 'window.requestAnimationFrame(function () {' ) );
}

/* --------------------------------------------------------------- le CSS ---- */

check( 'Le défilement doux est neutralisé en mouvement réduit',
	1 === preg_match( '#@media \(prefers-reduced-motion: reduce\) \{\s*html \{ scroll-behavior: auto; \}\s*\}#', $css_src )
	&& str_contains( $css, 'html { scroll-behavior: auto; }' ) );

// Trois valeurs, exactement les trois hauteurs d'en-tête fermé validées par la
// PR #56. Calculées au clic, elles seraient fausses au redimensionnement suivant.
$paliers = array(
	array( '', 71 ),
	array( '(max-width: 420px)', 63 ),
	array( '(max-width: 380px)', 95 ),
);
$manquants = array();

foreach ( $paliers as list( $media, $valeur ) ) {
	$regle = sprintf( '.urbizen-accueil #demander-des-renseignements { scroll-margin-top: %dpx; }', $valeur );
	$attendu = '' === $media ? $regle : sprintf( '@media %s { %s }', $media, $regle );
	if ( ! str_contains( $css, $attendu ) ) { $manquants[] = '' === $media ? 'base' : $media; }
}

check( 'Trois scroll-margin-top, un par géométrie d\'en-tête (95 / 63 / 71)', array() === $manquants );
if ( array() !== $manquants ) { echo '    absent : ' . implode( ' | ', $manquants ) . "\n"; }

check( 'Aucun décalage d\'en-tête calculé en JavaScript',
	! preg_match( '#(offsetHeight|getBoundingClientRect\(\)\.height)[^;]*(header|nav)#i', $js )
	&& ! str_contains( $js, 'scrollTo({ top:' ) );

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
