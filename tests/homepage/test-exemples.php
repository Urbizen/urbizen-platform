<?php
/**
 * Banc d'essai des illustrations de la section « Exemples de dossiers ».
 *
 * Les quatre cartes reçoivent une illustration décorative. Ce banc vérifie
 * que l'ajout est complet, homogène, purement décoratif, et surtout qu'il
 * n'a **rien** emporté d'autre au passage : les fichiers de référence d'où
 * viennent les dessins mêlaient plusieurs chantiers, dont une carte
 * « Conception de plans sur mesure » non validée et des textes antérieurs à
 * la PR #12.
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

/** Extrait la section #exemples d'un document. */
function section_exemples( $html ) {
	return preg_match( '#<section class="exemples" id="exemples">.*?</section>#s', $html, $m ) ? $m[0] : '';
}

$sources = array(
	'gabarit'  => $theme . '/templates/page-accueil-urbizen.html',
	'accueil'  => $theme . '/templates/front-page.html',
	'maquette' => $racine . '/frontend/homepage/index.html',
);

// ------------------------------------------------- structure des cartes ---
foreach ( $sources as $nom => $chemin ) {
	$sec = section_exemples( file_get_contents( $chemin ) );

	check( "[$nom] la section #exemples est présente", '' !== $sec );

	if ( '' === $sec ) { continue; }

	$cartes = preg_match_all( '#<article class="exemple">.*?</article>#s', $sec, $m );
	$m      = $m[0];

	check( "[$nom] exactement 4 cartes", 4 === $cartes );
	check( "[$nom] exactement 4 .exemple-img", 4 === substr_count( $sec, 'class="exemple-img"' ) );
	check( "[$nom] exactement 4 .exemple-txt", 4 === substr_count( $sec, 'class="exemple-txt"' ) );
	check( "[$nom] exactement 4 SVG", 4 === substr_count( $sec, '<svg' ) && 4 === substr_count( $sec, '</svg>' ) );
	check( "[$nom] les 4 illustrations sont aria-hidden",
		4 === preg_match_all( '#<div class="exemple-img" aria-hidden="true">#', $sec ) );

	$uniformes = true;
	foreach ( $m as $carte ) {
		$uniformes = $uniformes
			&& 1 === substr_count( $carte, 'class="exemple-txt"' )
			&& 1 === substr_count( $carte, 'class="exemple-img"' )
			&& 1 === substr_count( $carte, '<svg' );
	}
	check( "[$nom] chaque carte : un .exemple-txt, un .exemple-img, un SVG", $uniformes );

	// Le SVG doit rester dans l'illustration, jamais dans le texte.
	check( "[$nom] aucun SVG dans le bloc de texte",
		! preg_match( '#<div class="exemple-txt">(?:(?!</div>).)*<svg#s', $sec ) );
}

// ------------------------------------------ textes de la section intacts ---
// Les libellés d'origine, relevés sur main avant l'ajout des illustrations.
$attendus = array(
	'Exemples de dossiers',
	'À quoi ressemble un dossier préparé',
	'Exemples de démonstration, présentés à titre illustratif.',
	'Projet : piscine',        'Type : déclaration préalable',   'Pièces : DP1, DP2, DP3, DP6',
	'Projet : façade &amp; toiture',                              'Pièces : DP1, DP2, DP4',
	'Projet : extension',      'Type : DP ou permis selon surface', 'Pièces : DP1, DP2, DP3, DP4, DP6',
	'Projet : maison individuelle', 'Type : permis de construire', 'Pièces : PCMI1 à PCMI6 + notice',
);

foreach ( $sources as $nom => $chemin ) {
	$sec     = section_exemples( file_get_contents( $chemin ) );
	$manque  = array_values( array_filter( $attendus, static fn( $t ) => ! str_contains( $sec, $t ) ) );

	check( "[$nom] les " . count( $attendus ) . ' libellés de la section sont inchangés', array() === $manque );

	if ( array() !== $manque ) {
		echo '    manquant : ' . implode( ' | ', $manque ) . "\n";
	}

	check( "[$nom] 4 badges « Démonstration »", 4 === substr_count( $sec, '>Démonstration<' ) );
}

// ------------------------------------- la carte non validée est écartée ---
foreach ( $sources as $nom => $chemin ) {
	check( "[$nom] aucune carte « Conception de plans sur mesure »",
		! str_contains( file_get_contents( $chemin ), 'Conception de plans sur mesure' ) );
}

// ------------------------------------------- textes de la PR #12 intacts ---
$pr12 = array(
	'Dossier complet',
	'Un concepteur humain dédié',
	'<b>Service 100&nbsp;% à distance</b>',
	'<b>Plans et pièces graphiques réalisés par nos soins</b>',
	'France métropolitaine',
	'Un interlocuteur dédié',
	'Devis et étude gratuits',
	'Un interlocuteur humain dédié',
	'Votre dossier est étudié et préparé par une personne qui réalise vos plans, suit votre projet et répond à vos questions.',
);

foreach ( $sources as $nom => $chemin ) {
	$h      = file_get_contents( $chemin );
	$manque = array_values( array_filter( $pr12, static fn( $t ) => ! str_contains( $h, $t ) ) );

	check( "[$nom] les " . count( $pr12 ) . ' textes de la PR #12 sont toujours là', array() === $manque );

	if ( array() !== $manque ) {
		echo '    manquant : ' . implode( ' | ', $manque ) . "\n";
	}
}

// ------------------------------------------------ les deux gabarits ---------
$src   = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front = file_get_contents( $theme . '/templates/front-page.html' );

check( 'Les deux gabarits sont strictement identiques', $src === $front );
check( 'Empreintes SHA-256 identiques', hash( 'sha256', $src ) === hash( 'sha256', $front ) );

// ------------------------------------------- le CSS généré vient bien de ---
// la source : mêmes déclarations, seuls les sélecteurs sont portés.
$css_src = file_get_contents( $racine . '/frontend/homepage/homepage.css' );
$css_wp  = file_get_contents( $theme . '/assets/css/urbizen-homepage.css' );

$decls = static function ( $c ) {
	preg_match_all( '/([-a-z]+)\s*:\s*([^;{}]+)[;}]/', preg_replace( '#/\*.*?\*/#s', '', $c ), $m, PREG_SET_ORDER );
	$o = array_map( static fn( $x ) => trim( $x[1] ) . ':' . trim( $x[2] ), $m );
	sort( $o );
	return $o;
};

check( 'CSS WordPress : déclarations identiques à la source', $decls( $css_src ) === $decls( $css_wp ) );
check( 'CSS WordPress : en-tête « fichier généré » présent',
	str_contains( $css_wp, 'GÉNÉRÉ par scripts/scope-css.py' ) );

// Les règles demandées pour la mise en page des illustrations.
foreach ( array(
	'.exemple-grid { display: grid; grid-template-columns: 1fr 1fr;' => 'deux cartes par ligne',
	'display: flex; align-items: center; gap: 16px;'                 => 'carte en flex, texte et dessin alignés',
	'.exemple-txt { flex: 1; min-width: 0; }'                        => 'texte : flex 1 et min-width 0',
	'.exemple-img { flex: none; width: 100px; }'                     => 'illustration : non compressible, 100 px',
	'.exemple-img svg { width: 100%; height: auto; display: block; }' => 'SVG à la largeur de son conteneur',
) as $regle => $intitule ) {
	check( 'CSS source : ' . $intitule, str_contains( $css_src, $regle ) );
}

check( 'CSS source : une seule colonne sous 860 px',
	(bool) preg_match( '/@media \(max-width: 860px\) \{ \.exemple-grid \{ grid-template-columns: 1fr; \} \}/', $css_src ) );
check( 'CSS source : illustration à 84 px sous 460 px',
	(bool) preg_match( '/@media \(max-width: 460px\) \{ \.exemple-img \{ width: 84px; \} \}/', $css_src ) );

// Le scoping doit avoir porté ces sélecteurs, sans les dupliquer.
check( 'CSS WordPress : les nouveaux sélecteurs sont portés sous .urbizen-accueil',
	str_contains( $css_wp, '.urbizen-accueil .exemple-txt' )
	&& str_contains( $css_wp, '.urbizen-accueil .exemple-img' ) );
check( 'CSS WordPress : aucun sélecteur non porté',
	! preg_match( '/(?:^|\})\s*\.exemple-(txt|img)\s*\{/m', $css_wp ) );

// ------------------------------------------------ rien d'autre n'a bougé ---
// Nombre d'occurrences relevé sur main : 149 € et 449 € figurent deux fois,
// une dans « À partir de » de la section prestations, une dans la grille
// tarifaire. Les trois autres n'apparaissent qu'une fois.
$tarifs = array( '149&nbsp;€' => 2, '249&nbsp;€' => 1, '449&nbsp;€' => 2, '649&nbsp;€' => 1, '849&nbsp;€' => 1 );

foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );

	$ecarts = array();
	foreach ( $tarifs as $prix => $attendu ) {
		$vu = substr_count( $h, $prix );
		if ( $vu !== $attendu ) {
			$ecarts[] = "$prix : $vu au lieu de $attendu";
		}
	}

	check( "[$nom] les 5 tarifs sont inchangés", array() === $ecarts );

	if ( array() !== $ecarts ) {
		echo '    ' . implode( ' | ', $ecarts ) . "\n";
	}

	check( "[$nom] la vignette du hero est intacte", str_contains( $h, 'DP6 · INSERTION 3D' ) );
	check( "[$nom] aucun gestionnaire d'événement en ligne", ! preg_match( '#\son[a-z]+\s*=#', $h ) );
}

// La maquette est une page autonome : elle charge ses propres scripts, et
// c'est normal. Les gabarits WordPress, eux, ne doivent en contenir aucun —
// le JavaScript y est mis en file par functions.php.
foreach ( array( 'gabarit', 'accueil' ) as $nom ) {
	check( "[$nom] aucune balise script dans le gabarit",
		! str_contains( file_get_contents( $sources[ $nom ] ), '<script' ) );
}

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
