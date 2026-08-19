<?php
/**
 * Banc d'essai de la planche du hero — « Illustration des pièces ».
 *
 * La planche est une illustration décorative de quatre vignettes, animée en
 * CSS et déclenchée par le JavaScript. Ce banc protège trois choses, et la
 * troisième est la seule qui compte pour l'accessibilité :
 *
 * 1. **la structure** — quatre vignettes, leurs libellés, leur géométrie SVG ;
 * 2. **la séquence** — trois phases enchaînées, une seule lecture, jamais de
 *    boucle, et une fin qui laisse tout visible ;
 * 3. **le mouvement réduit** — et c'est ici que l'architecture de production
 *    est remarquable : rien n'est masqué tant que l'animation n'est pas
 *    lancée. Les règles qui posent `opacity: 0` vivent **sous** la classe
 *    `.is-animated`, que le JavaScript n'ajoute jamais si la personne demande
 *    moins de mouvement. Une planche non animée est donc une planche
 *    entièrement visible — sans qu'aucune media query n'ait à la rattraper.
 *
 * L'accueil de référence est celui **servi en production**. Une refonte
 * antérieure (`ee1415c`) vivait dans le dépôt sans avoir jamais été déployée :
 * son historique reste dans Git, mais sa planche `.hero-plan` n'est plus la
 * référence, et ce banc vérifie qu'elle ne revient pas.
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

/**
 * Extrait la planche du hero d'un document.
 *
 * Le `<div class="hero-board">` contient des `<svg>` imbriqués — un par
 * vignette — et s'arrêter au premier `</svg>` n'en rendrait qu'un morceau. On
 * suit donc l'imbrication des `<div>` jusqu'à la fermeture de la planche.
 */
function planche( $html ) {
	$i = strpos( $html, '<div class="hero-board"' );

	if ( false === $i ) { return ''; }

	$profondeur = 0;
	$j          = $i;
	$n          = strlen( $html );

	while ( $j < $n ) {
		if ( 0 === substr_compare( $html, '<div', $j, 4 ) ) {
			$profondeur++;
			$j += 4;
			continue;
		}

		if ( 0 === substr_compare( $html, '</div>', $j, 6 ) ) {
			$profondeur--;
			$j += 6;

			if ( 0 === $profondeur ) { return substr( $html, $i, $j - $i ); }

			continue;
		}

		$j++;
	}

	return '';
}

$sources = array(
	'gabarit'  => $theme . '/templates/page-accueil-urbizen.html',
	'accueil'  => $theme . '/templates/front-page.html',
	'maquette' => $racine . '/frontend/homepage/index.html',
);

/* ------------------------------------------------ structure du hero ------ */

foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );
	$p = planche( $h );

	check( "[$nom] le hero porte sa variante et son titre",
		1 === substr_count( $h, 'class="hero hero-v7"' )
		&& str_contains( $h, 'id="accueil"' )
		&& str_contains( $h, 'aria-labelledby="hero-title"' )
		// Libellé réécrit le 19 août 2026. Le H1 nommait le livrable — « Dossiers
		// d'urbanisme : du projet au dossier prêt à déposer. » — là où il parle
		// désormais du projet de la personne qui lit : ce sont ses travaux qui
		// l'amènent, la démarche administrative n'est que le chemin. La structure
		// en deux temps ne bouge pas : l'<em> porte toujours l'accent vert, et
		// c'est lui qui rend le titre bicolore. Le contrôle reste sur la chaîne
		// exacte, balisage compris, pour qu'aucune retouche silencieuse — accent
		// déplacé, <em> perdu — ne puisse passer sans être vue.
		&& str_contains( $h, '<h1 id="hero-title">Vos travaux commencent par <em>les bonnes démarches.</em></h1>' ) );

	// L'accroche validée ne réduit pas l'offre aux quatre exemples visibles
	// dans le formulaire : elle couvre aussi la construction neuve et les
	// aménagements extérieurs. Le titre reste propre à Urbizen.
	check( "[$nom] l'accroche couvre l'ensemble des déclarations de travaux",
		str_contains( $h, 'Construction neuve, extension, modification de l’existant ou aménagement extérieur' )
		&& str_contains( $h, 'devis estimatif' )
		&& str_contains( $h, 'règles d’urbanisme' )
		&& ! str_contains( $h, 'sans complication' ) );

	// Le texte d'abord, l'illustration ensuite : empilés sur mobile, on lit le
	// titre avant de voir la planche.
	check( "[$nom] le texte précède l'illustration dans le DOM",
		strpos( $h, 'class="hero-copy"' ) < strpos( $h, 'class="hero-board"' ) );

	check( "[$nom] les deux actions du hero",
		str_contains( $h, 'class="btn btn-primary js-start"' )
		&& str_contains( $h, 'class="btn btn-secondary js-open-contact"' )
		&& str_contains( $h, 'aria-haspopup="dialog"' )
		&& str_contains( $h, 'aria-controls="contact-panel"' ) );

	// Les trois prestations mènent à leurs pages, et chaque icône est décorative.
	check( "[$nom] les trois cartes de prestation et leurs destinations",
		3 === substr_count( $h, 'class="hero-service-card"' )
		&& str_contains( $h, 'href="https://urbizen.fr/permis-de-construire/"' )
		&& str_contains( $h, 'href="https://urbizen.fr/declarations-prealables/"' )
		&& str_contains( $h, 'href="https://urbizen.fr/conception/"' ) );
	check( "[$nom] les icônes de prestation sont décoratives",
		3 === substr_count( $h, 'class="hero-service-icon" aria-hidden="true"' ) );

	/* ---------------------------------------------- la planche elle-même -- */

	check( "[$nom] exactement une .hero-board", 1 === substr_count( $h, 'class="hero-board"' ) );
	check( "[$nom] la planche SVG est présente", '' !== $p );

	// Décorative mais décrite : `role="img"` sans description ne dirait rien à
	// un lecteur d'écran, et l'illustration porte du sens.
	check( "[$nom] la planche est décrite pour les lecteurs d'écran",
		str_contains( $p, 'role="img"' )
		&& str_contains( $p, 'aria-label="Exemples de plans et de pièces graphiques préparés par Urbizen"' )
		&& str_contains( $p, 'class="board-tag"' )
		&& str_contains( $p, '>Ce que nous préparons<' ) );

	check( "[$nom] le SVG intérieur est masqué aux lecteurs d'écran",
		str_contains( $p, 'aria-hidden="true"' ) );
	check( "[$nom] viewBox de la planche inchangé", str_contains( $p, 'viewBox="0 0 440 360"' ) );

	check( "[$nom] quatre cadres de vignette",
		4 === preg_match_all( '#class="hp-fade hp-d[1-4]"#', $p ) );

	foreach ( array( 'DP2 · PLAN DE MASSE', 'DP4 · FAÇADES', 'DP3 · PLAN EN COUPE', 'DP6 · INSERTION' ) as $lib ) {
		check( "[$nom] libellé « " . $lib . ' »',
			str_contains( $p, '>' . $lib . '</text>' ) );
	}

	// Les motifs et le marqueur de cote sont déclarés une fois, dans <defs>.
	check( "[$nom] les trois motifs techniques sont déclarés",
		str_contains( $p, 'id="board-hatch"' )
		&& str_contains( $p, 'id="board-existing"' )
		&& str_contains( $p, 'id="board-tick"' ) );

	// Le recensement des éléments : c'est lui qui détecte un ajout ou une
	// suppression silencieuse dans l'illustration.
	check( "[$nom] 26 <path>, 16 <rect> et 12 <text> dans la planche",
		26 === substr_count( $p, '<path' )
		&& 16 === substr_count( $p, '<rect' )
		&& 12 === substr_count( $p, '<text' ) );

	/* ------------------------------------------------ les trois phases ---- */

	check( "[$nom] phase 1 : 4 cadres en hp-fade", 4 === preg_match_all( '#class="hp-fade hp-d[1-4]"#', $p ) );
	check( "[$nom] phase 2 : 4 tracés hp-draw", 4 === preg_match_all( '#class="hp-draw hp-dr[1-4]"#', $p ) );
	check( "[$nom] phase 3 : 4 groupes de remplissage", 4 === preg_match_all( '#class="hp-fade hp-fd[1-4]"#', $p ) );
	check( "[$nom] chaque tracé animé porte pathLength", 4 === substr_count( $p, 'pathLength="1"' ) );

	// Les quatre tracés dessinés, à la coordonnée près : ce sont eux qui
	// portent l'animation, et les modifier changerait le dessin.
	$traces = array(
		'DP2' => 'M206 112H310V200H206Z',
		'DP4' => 'M196 246V156H346V246M188 156 354 132',
		'DP3' => 'M200 240V150L336 130V240',
		'DP6' => 'M186 252V150L316 138V252M316 138 356 124V236L316 252',
	);

	foreach ( $traces as $vignette => $d ) {
		check( "[$nom] $vignette : le tracé animé est inchangé", str_contains( $p, $d ) );
	}

	/* ------------------------------------------ animation sobre, sans JS -- */

	check( "[$nom] aucune balise d'animation SMIL",
		! str_contains( $p, '<animate' ) && ! str_contains( $p, 'animateTransform' ) && ! str_contains( $p, '<set' ) );
	check( "[$nom] aucun JavaScript dans la planche",
		! str_contains( $p, '<script' ) && ! str_contains( $p, 'onload=' ) );

	/* ------------------------------- la planche de ee1415c ne revient pas -- */

	check( "[$nom] l'ancienne planche .hero-plan n'est pas réintroduite",
		! str_contains( $h, 'class="hero-plan"' ) && ! str_contains( $h, 'DP6 · INSERTION 3D' ) );
}

/* ------------------------------------------------------------- le CSS ---- */

$css_src = file_get_contents( $racine . '/frontend/homepage/homepage.css' );
$css_wp  = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );

/*
 * Toute l'animation vit sous `.is-animated`. C'est l'invariant d'accessibilité
 * de cette planche : sans la classe, aucune règle ne pose `opacity: 0` ni
 * `stroke-dashoffset`, donc rien n'est masqué. Une règle d'animation qui
 * s'échapperait de cette garde rendrait la planche invisible pour qui refuse
 * le mouvement — c'est exactement ce que ce banc doit empêcher.
 */
preg_match_all( '#([^{}\n]*\bhp-[a-z0-9]+[^{}]*)\{([^}]*)\}#', $css_src, $regles, PREG_SET_ORDER );

check( 'CSS : les règles hp- existent', count( $regles ) >= 14 );

$hors_garde = array_filter(
	$regles,
	static fn( $r ) => ! str_contains( $r[1], '.is-animated' )
);

check( 'CSS : toutes les règles hp- sont sous .is-animated', array() === $hors_garde );

$masquantes = array_filter(
	$regles,
	static fn( $r ) => ( str_contains( $r[2], 'opacity: 0' ) || str_contains( $r[2], 'stroke-dashoffset' ) )
		&& ! str_contains( $r[1], '.is-animated' )
);

check( 'CSS : rien n\'est masqué hors de la garde', array() === $masquantes );

check( 'CSS : la garde nomme bien la planche du hero',
	str_contains( $css_src, '.hero-v7 .hero-board.is-animated .hp-fade' )
	&& str_contains( $css_src, '.hero-v7 .hero-board.is-animated .hp-draw' ) );

// --- une seule lecture, jamais de boucle ---
check( 'CSS : aucune animation infinie', ! str_contains( $css_src, 'infinite' ) );
check( 'CSS : aucun animation-iteration-count', ! str_contains( $css_src, 'animation-iteration-count' ) );
check( 'CSS : les deux phases se figent sur leur état final (forwards)',
	str_contains( $css_src, 'animation: hpFade .4s ease forwards' )
	&& (bool) preg_match( '#animation: hpDraw 1\.1s cubic-bezier\([^)]*\) forwards#', $css_src ) );
check( 'CSS : les deux keyframes finissent sur l\'état visible',
	str_contains( $css_src, '@keyframes hpFade { to { opacity: 1; } }' )
	&& str_contains( $css_src, '@keyframes hpDraw { to { stroke-dashoffset: 0; } }' ) );

// --- chronologie : les délais de production, à la milliseconde ---
$delai = static function ( $classe ) use ( $css_src ) {
	return preg_match( '#\.is-animated \.' . $classe . ' \{ animation-delay: ([\d.]+)s; \}#', $css_src, $m )
		? (float) $m[1] : -1.0;
};

$cadres  = array( $delai( 'hp-d1' ), $delai( 'hp-d2' ), $delai( 'hp-d3' ), $delai( 'hp-d4' ) );
$traces  = array( $delai( 'hp-dr1' ), $delai( 'hp-dr2' ), $delai( 'hp-dr3' ), $delai( 'hp-dr4' ) );
$remplis = array( $delai( 'hp-fd1' ), $delai( 'hp-fd2' ), $delai( 'hp-fd3' ), $delai( 'hp-fd4' ) );

check( 'Chronologie : les quatre cadres apparaissent presque ensemble',
	array( 0.05, 0.12, 0.19, 0.26 ) === $cadres );
check( 'Chronologie : les quatre tracés se dessinent l\'un après l\'autre',
	array( 0.45, 1.75, 3.05, 4.35 ) === $traces );
check( 'Chronologie : chaque remplissage suit son tracé',
	array( 1.55, 2.85, 4.15, 5.45 ) === $remplis );

// Chaque vignette est traitée en entier avant la suivante : le remplissage
// d'une vignette précède toujours le tracé de la suivante.
$ordonnee = true;

for ( $i = 0; $i < 3; $i++ ) {
	if ( $remplis[ $i ] >= $traces[ $i + 1 ] || $traces[ $i ] >= $remplis[ $i ] ) {
		$ordonnee = false;
	}
}

check( 'Chronologie : chaque vignette est dessinée puis remplie, dans l\'ordre', $ordonnee );
check( 'Chronologie : fin réelle à ' . number_format( max( $remplis ) + 0.4, 2 ) . ' s',
	abs( ( max( $remplis ) + 0.4 ) - 5.85 ) < 0.01 );

// --- aucune couche de l'ancienne planche ne subsiste ---
check( 'CSS : aucune couche hp-det, hp-td ni hp-mini résiduelle',
	! str_contains( $css_src, 'hp-det' ) && ! str_contains( $css_src, 'hp-td' ) && ! str_contains( $css_src, 'hp-mini' ) );
check( 'CSS : aucune règle .hero-plan résiduelle', ! str_contains( $css_src, '.hero-plan' ) );

// --- le CSS WordPress vient bien de la source ---
$decls = static function ( $c ) {
	preg_match_all( '/([-a-z]+)\s*:\s*([^;{}]+)[;}]/', preg_replace( '#/\*.*?\*/#s', '', $c ), $m, PREG_SET_ORDER );
	$o = array_map( static fn( $x ) => trim( $x[1] ) . ':' . trim( $x[2] ), $m );
	sort( $o );
	return $o;
};

check( 'CSS WordPress : déclarations identiques à la source', $decls( $css_src ) === $decls( $css_wp ) );
check( 'CSS WordPress : les sélecteurs de la planche sont portés',
	str_contains( $css_wp, '.urbizen-accueil .hero-v7 .hero-board.is-animated .hp-fade' )
	&& str_contains( $css_wp, '.urbizen-accueil .hero-v7 .hero-board.is-animated .hp-draw' ) );
check( 'CSS WordPress : les keyframes ne sont pas portés',
	str_contains( $css_wp, '@keyframes hpFade' ) && ! str_contains( $css_wp, '.urbizen-accueil @keyframes' ) );

/* ------------------------------------------- le déclenchement, en JS ----- */

$js = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );

check( 'JS : la planche est repérée par son sélecteur de production',
	str_contains( $js, 'document.querySelector(".hero-v7 .hero-board")' ) );

/*
 * Le mouvement réduit est respecté **en amont** : la classe n'est jamais
 * ajoutée. C'est plus sûr qu'une media query qui annulerait l'animation, car
 * rien n'a alors besoin d'être remis visible — rien n'a été masqué.
 */
check( 'JS : le mouvement réduit empêche l\'animation d\'être armée',
	str_contains( $js, 'window.matchMedia("(prefers-reduced-motion: reduce)").matches' )
	&& (bool) preg_match( '#if \(heroBoard && !reduceMotion\)#', $js ) );

check( 'JS : l\'animation démarre quand la planche devient visible',
	str_contains( $js, 'new IntersectionObserver' )
	&& str_contains( $js, 'threshold: 0.25' )
	&& str_contains( $js, 'isIntersecting' ) );
check( 'JS : une seule lecture — l\'observateur se déconnecte',
	str_contains( $js, 'heroBoardObserver.disconnect()' ) );
check( 'JS : la classe est posée une seule fois',
	str_contains( $js, 'classList.contains("is-animated")' )
	&& str_contains( $js, 'classList.add("is-animated")' ) );
check( 'JS : repli sans IntersectionObserver — la planche s\'anime quand même',
	(bool) preg_match( '#if \("IntersectionObserver" in window\)[\s\S]{0,400}\} else \{\s*startHeroBoardAnimation\(\);#', $js ) );

/* --------------------------------------------- gabarits synchronisés ----- */

$src   = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front = file_get_contents( $theme . '/templates/front-page.html' );

check( 'Les deux gabarits sont strictement identiques', $src === $front );
check( 'Empreintes SHA-256 identiques', hash( 'sha256', $src ) === hash( 'sha256', $front ) );
check( 'La planche est identique dans les trois fichiers',
	planche( $src ) === planche( $front )
	&& planche( $src ) === planche( file_get_contents( $racine . '/frontend/homepage/index.html' ) ) );

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
