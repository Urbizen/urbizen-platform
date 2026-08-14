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

	// L'explorateur est une SECTION à part depuis le 14 août 2026 : il ne fallait
	// pas le loger dans « Nos services », qui porte les parcours et les tarifs.
	$d = section( $h, 'dossier' );
	check( "[$nom] la section de l'explorateur est repérée", '' !== $d );

	/* ------------------------------------ l'explorateur de pièces du dossier ---

	   Les dix « planches » — dix vignettes SVG et leur libellé — ont été
	   remplacées le 14 août 2026 par un explorateur à deux niveaux d'onglets.
	   Ce que les anciens contrôles protégeaient reste protégé : les dix pièces
	   sont toujours nommées, avec leurs codes réglementaires, et rien n'y est
	   présenté comme systématiquement fourni. Ce sont les moyens qui changent.  */

	check( "[$nom] trois familles de pièces, en onglets",
		3 === substr_count( $d, 'class="dx-tab"' )
		&& 3 === preg_match_all( '#class="dx-tab" role="tab"#', $d ) );

	check( "[$nom] dix pièces réparties dans les trois familles",
		10 === substr_count( $d, 'class="dx-item"' )
		&& 10 === substr_count( $d, 'class="dx-vue"' ) );

	// Les mêmes codes qu'avant, aux mêmes intitulés : c'est la donnée métier,
	// elle ne dépend pas du composant qui l'affiche.
	$pieces = array(
		'DP1 · PCMI1' => 'Plan de situation',
		'DP2 · PCMI2' => 'Plan de masse',
		'DP3 · PCMI3' => 'Plan en coupe',
		'DP4 · PCMI5' => 'Façades et toitures',
		'DP6 · PCMI6' => 'Insertion graphique',
		'DP7 · PCMI7' => 'Environnement proche',
		'DP8 · PCMI8' => 'Paysage lointain',
		'PCMI4'       => 'Notice descriptive',
		'CERFA'       => 'Formulaire administratif',
		'BORDEREAU'   => 'Bordereau des pièces',
	);
	$manquants = array();
	foreach ( $pieces as $code => $intitule ) {
		if ( ! str_contains( $d, $code ) || ! str_contains( $d, $intitule ) ) {
			$manquants[] = $code;
		}
	}
	check( "[$nom] les dix codes réglementaires et leurs intitulés", array() === $manquants,
		'absents : ' . implode( ', ', $manquants ) );

	// Le contrat ARIA : sans lui, l'explorateur n'est qu'une pile de boutons.
	check( "[$nom] les onglets déclarent leur état et leur panneau",
		13 === preg_match_all( '#role="tab"[^>]*aria-controls="#', $d )
		&& 13 === preg_match_all( '#role="tab"[^>]*aria-selected="#', $d ) );

	// Aucune pièce ne doit être présentée comme systématiquement fournie.
	check( "[$nom] le contenu du dossier reste annoncé comme variable",
		str_contains( $d, "dépend de la nature du projet" ) );

	check( "[$nom] les documents montrés sont annoncés comme des exemples",
		str_contains( $d, 'projet fictif' ) );

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
