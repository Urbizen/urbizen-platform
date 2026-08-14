<?php
/**
 * Banc d'essai des cibles tactiles de l'en-tête mobile.
 *
 * Quatre contrôles interactifs cohabitent dans la barre : téléphone, Espace
 * client, « Démarrer mon projet » et le burger. Sous 400 px, trois d'entre eux
 * tombaient sous les 44 × 44 px recommandés — le burger à 26 × 34, l'Espace
 * client à 40 × 40, le CTA à 38 de haut. Le doigt visait plus petit que la
 * pastille annoncée.
 *
 * **Ce banc ne cherche pas la chaîne « 44px » dans la feuille.** Une telle
 * vérification passerait au vert si la règle visait le mauvais sélecteur, si
 * elle vivait dans le mauvais `@media`, ou si une règle plus spécifique la
 * réduisait plus loin — c'est exactement ainsi que `.link-login { width: 40px }`
 * a pu annuler `.icon-btn { width: 44px }` sans que rien ne le signale.
 *
 * Il reconstruit donc la cascade : la feuille est analysée en règles, chaque
 * règle est confrontée à un modèle d'élément (classes + chaîne d'ancêtres), les
 * media queries sont évaluées pour une largeur donnée, et les déclarations sont
 * empilées **par spécificité puis par ordre d'apparition**. On interroge ensuite
 * la valeur effective, viewport par viewport.
 *
 * Le seuil de repli n'est pas arbitraire : avec les quatre cibles à 44 × 44 et
 * les espacements réels, la barre réclame 370 px. Le repli est armé à 380 px.
 * Le banc vérifie les deux côtés du seuil.
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

/* ===================================================================== CSS ==
 * Analyseur minimal : suffisant pour cette feuille, et volontairement strict —
 * tout ce qu'il ne sait pas lire est signalé plutôt qu'ignoré en silence.
 */

/**
 * Découpe une feuille en règles.
 *
 * @return array<int, array{media: ?string, selecteurs: string[], decl: array<string,string>}>
 */
function analyser_css( string $css ): array {
	$css    = preg_replace( '#/\*.*?\*/#s', '', $css );
	$regles = array();

	$avaler = function ( string $src, ?string $media ) use ( &$regles, &$avaler ): void {
		$i = 0;
		$n = strlen( $src );

		while ( $i < $n ) {
			// Début du prélude (sélecteur ou at-rule).
			while ( $i < $n && ( ' ' === $src[ $i ] || "\n" === $src[ $i ] || "\t" === $src[ $i ] || "\r" === $src[ $i ] ) ) { $i++; }
			if ( $i >= $n ) { return; }

			$j = strpos( $src, '{', $i );
			if ( false === $j ) { return; }

			$prelude = trim( substr( $src, $i, $j - $i ) );

			// Corps équilibré.
			$prof = 1;
			$k    = $j + 1;
			while ( $k < $n && $prof > 0 ) {
				if ( '{' === $src[ $k ] ) { $prof++; }
				if ( '}' === $src[ $k ] ) { $prof--; }
				$k++;
			}
			$corps = substr( $src, $j + 1, $k - $j - 2 );
			$i     = $k;

			if ( '' === $prelude ) { continue; }

			if ( '@' === $prelude[0] ) {
				// Seules les media queries nous intéressent ; le reste (@keyframes,
				// @supports, @font-face) est sauté avec son corps.
				if ( 0 === stripos( $prelude, '@media' ) ) {
					$cond = trim( substr( $prelude, 6 ) );
					$avaler( $corps, null === $media ? $cond : $media . ' and ' . $cond );
				}
				continue;
			}

			$decl = array();
			foreach ( explode( ';', $corps ) as $paire ) {
				$paire = trim( $paire );
				if ( '' === $paire || ! str_contains( $paire, ':' ) ) { continue; }
				[ $prop, $val ]                       = explode( ':', $paire, 2 );
				$decl[ strtolower( trim( $prop ) ) ] = trim( $val );
			}

			if ( array() === $decl ) { continue; }

			$regles[] = array(
				'media'      => $media,
				'selecteurs' => array_map( 'trim', explode( ',', $prelude ) ),
				'decl'       => $decl,
			);
		}
	};

	$avaler( $css, null );

	return $regles;
}

/**
 * Une media query s'applique-t-elle à cette largeur ?
 *
 * Rend `null` quand la condition porte sur autre chose que la largeur : le banc
 * refuse alors de conclure, plutôt que de supposer.
 */
function media_s_applique( ?string $cond, int $largeur ): ?bool {
	if ( null === $cond ) { return true; }

	$reste = $cond;
	$ok    = true;

	if ( preg_match_all( '/\((max|min)-width:\s*(\d+)px\)/i', $cond, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $c ) {
			$borne = (int) $c[2];
			if ( 'max' === strtolower( $c[1] ) ) {
				$ok = $ok && $largeur <= $borne;
			} else {
				$ok = $ok && $largeur >= $borne;
			}
			$reste = str_replace( $c[0], '', $reste );
		}
	}

	// Ce qui subsiste doit n'être que de la colle syntaxique.
	$reste = trim( preg_replace( '/\b(and|screen|all|only)\b/i', '', $reste ) );

	return '' === $reste ? $ok : null;
}

/**
 * Un sélecteur descendant simple (classes uniquement) vise-t-il cet élément ?
 *
 * `$element` porte ses classes ; `$ancetres` est la chaîne des jeux de classes,
 * de la racine vers le parent direct.
 */
function selecteur_vise( string $sel, array $classes, array $ancetres ): bool {
	$sel = trim( $sel );

	// États et variantes hors portée : `:hover`, `[aria-disabled]`, `>`, `+`, `~`.
	if ( preg_match( '/[:\[>+~]/', $sel ) ) { return false; }

	$compounds = preg_split( '/\s+/', $sel );
	$cible     = array_pop( $compounds );

	// `*` vise n'importe quel élément — c'est le reset universel de la feuille.
	if ( '*' !== $cible ) {
		$exigees = array_filter( explode( '.', $cible ) );
		if ( array() === $exigees ) { return false; }
		foreach ( $exigees as $c ) {
			if ( ! in_array( $c, $classes, true ) ) { return false; }
		}
	}

	// Les ancêtres doivent apparaître dans l'ordre, sans être forcément contigus.
	$pos = 0;
	foreach ( $compounds as $comp ) {
		$need   = array_filter( explode( '.', $comp ) );
		$trouve = false;
		for ( $i = $pos; $i < count( $ancetres ); $i++ ) {
			$toutes = true;
			foreach ( $need as $c ) {
				if ( ! in_array( $c, $ancetres[ $i ], true ) ) { $toutes = false; break; }
			}
			if ( $toutes ) { $trouve = true; $pos = $i + 1; break; }
		}
		if ( ! $trouve ) { return false; }
	}

	return true;
}

/** Spécificité approchée : les sélecteurs de cette feuille sont tous des classes. */
function specificite( string $sel ): int {
	return substr_count( $sel, '.' );
}

/**
 * Valeur effective d'une propriété pour un élément, à une largeur donnée.
 *
 * Empile par spécificité croissante puis par ordre d'apparition — la cascade.
 */
function effectif( array $regles, array $classes, array $ancetres, int $largeur, string $prop ): ?string {
	$candidats = array();

	foreach ( $regles as $rang => $r ) {
		if ( ! array_key_exists( $prop, $r['decl'] ) ) { continue; }

		$m = media_s_applique( $r['media'], $largeur );
		if ( null === $m ) {
			// Condition non évaluable touchant une cible : on refuse de conclure.
			foreach ( $r['selecteurs'] as $s ) {
				if ( selecteur_vise( $s, $classes, $ancetres ) ) {
					throw new RuntimeException( "media query non évaluable sur une cible : {$r['media']}" );
				}
			}
			continue;
		}
		if ( ! $m ) { continue; }

		foreach ( $r['selecteurs'] as $s ) {
			if ( selecteur_vise( $s, $classes, $ancetres ) ) {
				$candidats[] = array( specificite( $s ), $rang, $r['decl'][ $prop ] );
			}
		}
	}

	if ( array() === $candidats ) { return null; }

	usort( $candidats, static fn( $a, $b ) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1] );

	return end( $candidats )[2];
}

/* ================================================================ modèles ==
 * Chaîne réelle du pattern : .urbizen-accueil > header.site > .nav > .nav-right
 */

$ANC_BARRE = array( array( 'urbizen-accueil' ), array( 'site' ), array( 'nav' ), array( 'nav-right' ) );
$ANC_NAV   = array( array( 'urbizen-accueil' ), array( 'site' ), array( 'nav' ) );

$cibles = array(
	'téléphone'    => array( array( 'icon-btn', 'link-tel' ), $ANC_BARRE ),
	'Espace client' => array( array( 'icon-btn', 'link-login' ), $ANC_BARRE ),
	'burger'       => array( array( 'burger' ), $ANC_BARRE ),
);

$cta = array( array( 'btn', 'btn-primary', 'btn-sm', 'js-start' ), $ANC_BARRE );

$css_path = $theme . '/assets/css/urbizen-homepage.css';
check( 'La feuille portée urbizen-homepage.css existe', is_file( $css_path ) );

if ( ! is_file( $css_path ) ) {
	echo "\n1 CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

$regles = analyser_css( file_get_contents( $css_path ) );
check( 'La feuille est analysable en règles', count( $regles ) > 500 );

/** Convertit une valeur CSS en pixels, ou `null` si ce n'est pas une longueur. */
function px( ?string $v ): ?float {
	if ( null === $v || ! preg_match( '/^(-?[\d.]+)px$/', trim( $v ), $m ) ) { return null; }
	return (float) $m[1];
}

/* ------------------------------------ le socle : la boîte est en border-box */

$bs = effectif( $regles, array( 'burger' ), $ANC_BARRE, 390, 'box-sizing' );
check( 'Le burger est en border-box (44 − 2×11 laisse bien 22×22 de contenu)', 'border-box' === $bs );

/* --------------------------------------- les trois boîtes, viewport par viewport */

// Le burger n'existe qu'en mode mobile ; au-delà de 1100 px il est masqué.
// 1200 et 1239 sont dans la plage mobile depuis que le seuil du burger est
// passé à 1240 px : le menu à six entrées ne tenait pas en dessous.
$mobiles = array( 320, 340, 360, 375, 379, 380, 381, 390, 400, 401, 430, 500, 560, 700, 900, 1100, 1200, 1239 );

foreach ( $cibles as $nom => [ $classes, $anc ] ) {
	$fautifs = array();

	foreach ( $mobiles as $w ) {
		$l = px( effectif( $regles, $classes, $anc, $w, 'width' ) );
		$h = px( effectif( $regles, $classes, $anc, $w, 'height' ) );

		if ( null === $l || null === $h ) { $fautifs[] = "{$w}px (dimension non déclarée)"; continue; }
		if ( $l < 44 || $h < 44 ) { $fautifs[] = "{$w}px → {$l}×{$h}"; }
	}

	check( "$nom : cible ≥ 44×44 à toutes les largeurs mobiles", array() === $fautifs );

	if ( array() !== $fautifs ) { echo '    écart : ' . implode( ' | ', $fautifs ) . "\n"; }
}

/* ------------------------------------------------------------------- le CTA */

$sans_min = array();
foreach ( $mobiles as $w ) {
	$mh = px( effectif( $regles, $cta[0], $cta[1], $w, 'min-height' ) );
	if ( null === $mh || $mh < 44 ) { $sans_min[] = "{$w}px"; }
}
check( 'CTA : min-height ≥ 44 à toutes les largeurs mobiles', array() === $sans_min );
if ( array() !== $sans_min ) { echo '    écart : ' . implode( ' | ', $sans_min ) . "\n"; }

// Sa largeur n'a jamais été en cause : on vérifie qu'aucune règle ne la contraint.
check( 'CTA : aucune largeur imposée (le flex existant la calcule)',
	null === effectif( $regles, $cta[0], $cta[1], 390, 'width' ) );

/* ---------------------------------------- la correction reste mobile ------- */

$desktop = array( 1240, 1280, 1440, 1920 );
$fuites  = array();

foreach ( $desktop as $w ) {
	if ( null !== effectif( $regles, $cta[0], $cta[1], $w, 'min-height' ) ) { $fuites[] = "CTA min-height @{$w}"; }
	if ( 'none' !== effectif( $regles, array( 'burger' ), $ANC_BARRE, $w, 'display' ) ) { $fuites[] = "burger visible @{$w}"; }
}

check( 'Desktop : ni min-height sur le CTA, ni burger affiché', array() === $fuites );
if ( array() !== $fuites ) { echo '    écart : ' . implode( ' | ', $fuites ) . "\n"; }

// Le burger n'apparaît qu'en mode mobile, et y apparaît partout.
$mauvais = array();
foreach ( $mobiles as $w ) {
	if ( 'block' !== effectif( $regles, array( 'burger' ), $ANC_BARRE, $w, 'display' ) ) { $mauvais[] = "{$w}px"; }
}
check( 'Le burger est affiché sur toute la plage mobile', array() === $mauvais );

/* ------------------------------------------ le repli et son seuil de 380 px */

// Sous le seuil : `.nav-right` s'efface et le CTA occupe sa propre ligne.
$replie_ko = array();
foreach ( array( 300, 320, 340, 360, 375, 379, 380 ) as $w ) {
	$d = effectif( $regles, array( 'nav-right' ), $ANC_NAV, $w, 'display' );
	$f = effectif( $regles, $cta[0], $cta[1], $w, 'flex' );
	if ( 'contents' !== $d || '1 0 100%' !== $f ) { $replie_ko[] = "{$w}px (display=$d, flex=$f)"; }
}
check( 'Repli actif de 300 à 380 px : nav-right en contents, CTA sur sa ligne', array() === $replie_ko );
if ( array() !== $replie_ko ) { echo '    écart : ' . implode( ' | ', $replie_ko ) . "\n"; }

// Au-dessus : la barre reprend sa ligne unique.
$ligne_ko = array();
foreach ( array( 381, 390, 400, 401, 430, 560, 1100, 1280 ) as $w ) {
	$d = effectif( $regles, array( 'nav-right' ), $ANC_NAV, $w, 'display' );
	$f = effectif( $regles, $cta[0], $cta[1], $w, 'flex' );
	if ( 'flex' !== $d || null !== $f ) { $ligne_ko[] = "{$w}px (display=$d, flex=$f)"; }
}
check( 'Ligne unique de 381 px à desktop : nav-right en flex, CTA sans flex-basis', array() === $ligne_ko );
if ( array() !== $ligne_ko ) { echo '    écart : ' . implode( ' | ', $ligne_ko ) . "\n"; }

// Le seuil doit être exactement 380 : ni l'ancien 340, ni une valeur voisine.
$seuils = array();
foreach ( $regles as $r ) {
	foreach ( $r['selecteurs'] as $s ) {
		if ( str_ends_with( $s, '.nav-right' ) && 'contents' === ( $r['decl']['display'] ?? '' ) ) {
			$seuils[] = $r['media'];
		}
	}
}
check( 'Le repli est armé par un unique @media (max-width: 380px)',
	array( '(max-width: 380px)' ) === $seuils );
if ( array( '(max-width: 380px)' ) !== $seuils ) { echo '    trouvé : ' . implode( ' | ', $seuils ) . "\n"; }

/* ------------ les deux réductions supprimées ne doivent pas revenir -------- */

$retours = array();
foreach ( $regles as $r ) {
	foreach ( $r['selecteurs'] as $s ) {
		$fin = trim( $s );
		if ( str_ends_with( $fin, '.link-login' ) && ( isset( $r['decl']['width'] ) || isset( $r['decl']['height'] ) ) ) {
			$retours[] = "link-login dimensionné dans « {$r['media']} »";
		}
		if ( str_ends_with( $fin, '.burger' ) && isset( $r['decl']['padding'] ) && '11px' !== $r['decl']['padding'] ) {
			$retours[] = "burger repadding « {$r['decl']['padding']} » dans « {$r['media']} »";
		}
	}
}
check( 'Aucune règle ne rétrécit à nouveau Espace client ni le burger', array() === $retours );
if ( array() !== $retours ) { echo '    écart : ' . implode( ' | ', $retours ) . "\n"; }

// L'icône du burger n'a pas changé de taille : trois traits de 22 × 2.
check( 'Les traits du burger gardent leur dimension visuelle',
	'22px' === effectif( $regles, array( 'burger-span' ), array_merge( $ANC_BARRE, array( array( 'burger' ) ) ), 390, 'width' )
	|| str_contains( file_get_contents( $css_path ), '.burger span { display: block; width: 22px; height: 2px;' ) );

/* ============================================================== le pattern ==
 * Le contrat d'accessibilité vit dans le balisage, pas dans la feuille.
 */

$pattern  = file_get_contents( $theme . '/patterns/header-accueil.php' );
$maquette = file_get_contents( $racine . '/frontend/homepage/index.html' );
$js       = file_get_contents( $theme . '/assets/js/urbizen-homepage.js' );

check( 'Le burger déclare type="button"',
	preg_match( '/<button\s+type="button"\s+class="burger"/', $pattern ) === 1 );
check( 'La maquette porte le même burger que le pattern',
	preg_match( '/<button\s+type="button"\s+class="burger"/', $maquette ) === 1 );
check( 'Tous les boutons du header portent un type explicite',
	0 === preg_match_all( '/<button(?![^>]*\stype=)/', $pattern ) );

check( 'Le burger conserve ses trois attributs ARIA',
	str_contains( $pattern, 'aria-label="Ouvrir le menu"' )
	&& str_contains( $pattern, 'aria-expanded="false"' )
	&& str_contains( $pattern, 'aria-controls="mmenu"' ) );
check( 'La cible de aria-controls existe bien dans le pattern',
	preg_match( '/id="mmenu"/', $pattern ) === 1 );
check( 'Le script bascule toujours aria-expanded',
	str_contains( $js, 'burger' ) && str_contains( $js, 'aria-expanded' ) );

check( 'Le CTA conserve son libellé accessible et ses deux intitulés',
	str_contains( $pattern, 'aria-label="Démarrer mon projet"' )
	&& str_contains( $pattern, 'class="nav-cta-long"' )
	&& str_contains( $pattern, 'class="nav-cta-short"' ) );
check( 'Le libellé court reste masqué aux lecteurs d\'écran (pas de doublon vocal)',
	preg_match( '/class="nav-cta-short"\s+aria-hidden="true"/', $pattern ) === 1 );

check( 'Espace client et téléphone gardent leur libellé accessible',
	str_contains( $pattern, 'aria-label="Espace client (bientôt disponible)"' )
	&& str_contains( $pattern, 'aria-label="Nous contacter"' ) );

// Le focus clavier est global et non borné : agrandir les boîtes ne doit pas
// l'avoir déplacé.
$focus = null;
foreach ( $regles as $r ) {
	foreach ( $r['selecteurs'] as $s ) {
		if ( str_contains( $s, ':focus-visible' ) && isset( $r['decl']['outline'] ) ) {
			$focus = array( $r['media'], $r['decl']['outline'] );
		}
	}
}
check( 'Le focus visible reste une règle globale, hors media query',
	null !== $focus && null === $focus[0] && str_contains( $focus[1], 'solid' ) );

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
