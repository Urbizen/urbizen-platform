<?php
/**
 * Banc d'essai de la section « Nos services » — prestations et contenu du dossier.
 *
 * Cette section porte deux promesses commerciales, et c'est ce qui la rend
 * sensible :
 *
 * 1. **trois parcours de prestation**, chacun menant à sa page dédiée. Une
 *    destination fausse envoie un demandeur de permis vers la déclaration
 *    préalable — il remplirait le mauvais dossier sans le savoir ;
 * 2. **dix planches** décrivant ce que le dossier peut comprendre. Chacune
 *    porte un code réglementaire (`DP1 · PCMI1`…) et son intitulé. Un code
 *    déplacé associerait un plan à la mauvaise pièce du CERFA.
 *
 * Ce banc remplace `test-exemples.php`, qui protégeait une section `#exemples`
 * d'une refonte antérieure (`ee1415c`) jamais déployée. L'accueil retenu est
 * celui servi en production : son historique reste dans Git, mais sa section
 * n'existe plus, et le nom du banc l'aurait rendu trompeur.
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

/** Extrait une section par son identifiant. */
function section( $html, $id ) {
	if ( ! preg_match( '#<section[^>]*id="' . preg_quote( $id, '#' ) . '"#', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$i = $m[0][1];
	$j = strpos( $html, '</section>', $i );

	return false === $j ? '' : substr( $html, $i, $j - $i + 10 );
}

$sources = array(
	'gabarit'  => $theme . '/templates/page-accueil-urbizen.html',
	'accueil'  => $theme . '/templates/front-page.html',
	'maquette' => $racine . '/frontend/homepage/index.html',
);

/** Les dix pièces, dans l'ordre du dossier, avec leur code réglementaire. */
$planches = array(
	'DP1 · PCMI1' => 'Plan de situation',
	'DP2 · PCMI2' => 'Plan de masse',
	'DP3 · PCMI3' => 'Plan en coupe',
	'DP4 · PCMI5' => 'Façades &amp; toitures',
	'DP6 · PCMI6' => 'Insertion graphique',
	'DP7 · PCMI7' => "Photographie de l'environnement proche",
	'DP8 · PCMI8' => 'Photographie du paysage lointain',
	'PCMI4'       => 'Notice descriptive',
	'CERFA'       => 'Formulaire officiel',
	'BORDEREAU'   => 'Bordereau des pièces',
);

/** Les trois prestations et la page qui les sert. */
$prestations = array(
	'Déclaration préalable' => 'https://urbizen.fr/declarations-prealables/',
	'Permis de construire'  => 'https://urbizen.fr/permis-de-construire/',
	'Conception de plans sur mesure' => 'https://urbizen.fr/conception/',
);

foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );
	$s = section( $h, 'services' );

	check( "[$nom] la section #services est présente", '' !== $s );
	check( "[$nom] son titre est celui de la charte",
		str_contains( $s, 'Une prestation adaptée, un dossier complet' )
		&& str_contains( $s, '>Nos services<' ) );

	/* ------------------------------------------- trois parcours de prestation */

	check( "[$nom] exactement trois parcours de prestation",
		3 === substr_count( $s, 'class="service-route"' )
		&& 3 === substr_count( $s, 'class="service-route-kicker"' )
		&& 3 === substr_count( $s, 'class="service-route-copy"' )
		&& 3 === substr_count( $s, 'class="service-route-arrow"' ) );

	$mauvaises = array();

	foreach ( $prestations as $libelle => $url ) {
		if ( ! str_contains( $s, '>' . $libelle . '<' ) ) { $mauvaises[] = "libellé $libelle"; }
		if ( ! str_contains( $s, 'href="' . $url . '"' ) ) { $mauvaises[] = "destination $libelle"; }
	}

	check( "[$nom] chaque prestation porte son libellé et sa destination", array() === $mauvaises );

	if ( array() !== $mauvaises ) { echo '    écart : ' . implode( ' | ', $mauvaises ) . "\n"; }

	// L'ordre commercial : la déclaration préalable, puis le permis, puis la
	// conception. L'inverser mettrait la prestation la plus lourde en tête.
	check( "[$nom] les trois prestations dans l'ordre attendu",
		strpos( $s, 'declarations-prealables' ) < strpos( $s, 'permis-de-construire' )
		&& strpos( $s, 'permis-de-construire' ) < strpos( $s, 'conception' ) );

	// Chaque parcours annonce un prix d'appel, jamais un prix ferme.
	check( "[$nom] chaque parcours annonce « À partir de »",
		3 === substr_count( $s, 'class="tarif-from"' )
		&& 3 === substr_count( $s, 'class="tarif-price"' )
		&& 3 === substr_count( $s, 'class="tarif-detail"' ) );

	// Les icônes des parcours sont décoratives : le libellé porte le sens.
	check( "[$nom] les icônes de parcours sont décoratives",
		3 === substr_count( $s, 'class="service-route-icon"' )
		&& 3 === preg_match_all( '#class="service-route-icon"[^>]*aria-hidden="true"#', $s ) );

	/* --------------------------------------------- les dix planches du dossier */

	check( "[$nom] exactement dix planches",
		10 === substr_count( $s, 'class="planche-item"' )
		&& 10 === substr_count( $s, 'class="planche-fig"' )
		&& 10 === substr_count( $s, 'class="planche-t"' ) );

	check( "[$nom] l'intitulé de la liste est conservé",
		str_contains( $s, 'Votre dossier peut comprendre' )
		&& str_contains( $s, '>CONTENU DU DOSSIER<' ) );

	$ecarts = array();

	foreach ( $planches as $code => $intitule ) {
		if ( ! str_contains( $s, '>' . $code . '</span>' ) ) { $ecarts[] = "code $code"; }
		if ( ! str_contains( $s, '>' . $intitule . '</span>' ) ) { $ecarts[] = "intitulé $code"; }
	}

	check( "[$nom] les dix codes réglementaires et leurs intitulés", array() === $ecarts );

	if ( array() !== $ecarts ) { echo '    écart : ' . implode( ' | ', $ecarts ) . "\n"; }

	// Les codes se suivent dans l'ordre du dossier déposé en mairie.
	$positions = array_map( static fn( $c ) => strpos( $s, '>' . $c . '</span>' ), array_keys( $planches ) );
	$triees    = $positions;
	sort( $triees );

	check( "[$nom] les dix planches se suivent dans l'ordre du dossier", $positions === $triees );

	// Chaque figure est une illustration décorative : aucun texte alternatif à
	// lire, aucun gestionnaire en ligne.
	check( "[$nom] les figures sont décoratives et inertes",
		10 === preg_match_all( '#class="planche-fig" aria-hidden="true"><svg#', $s )
		&& ! preg_match( '#\son(click|load|mouse\w+)=#', $s ) );

	// Le dépôt dématérialisé est une option annoncée, pas une promesse ferme.
	check( "[$nom] le dépôt dématérialisé reste annoncé comme option",
		str_contains( $s, 'Dépôt dématérialisé en option' ) );

	/* ------------------------------ les blocs de ee1415c ne reviennent pas ---- */

	check( "[$nom] aucune section #exemples ni illustration .exemple-img",
		! str_contains( $h, 'id="exemples"' )
		&& ! str_contains( $h, 'class="exemple-img"' )
		&& ! str_contains( $h, 'Démonstration' ) );
}

/* ---------------------------------------------------- gabarits synchronisés */

$src   = file_get_contents( $theme . '/templates/page-accueil-urbizen.html' );
$front = file_get_contents( $theme . '/templates/front-page.html' );

check( 'Les deux gabarits portent la même section', section( $src, 'services' ) === section( $front, 'services' ) );
check( 'La maquette porte la même section que le gabarit',
	section( $src, 'services' ) === section( file_get_contents( $racine . '/frontend/homepage/index.html' ), 'services' ) );

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
