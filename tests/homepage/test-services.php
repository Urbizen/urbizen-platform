<?php
/**
 * Banc d'essai de la section « Nos tarifs » et du contenu du dossier.
 *
 * Cette section porte deux promesses commerciales, et c'est ce qui la rend
 * sensible :
 *
 * 1. **sept forfaits de départ**, répartis entre déclaration préalable,
 *    permis de construire et conception sur mesure. Le formulaire reste la
 *    source du devis estimatif personnalisé ;
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
	$fragment = substr( $html, $i );
	preg_match_all( '#</?section\b[^>]*>#', $fragment, $balises, PREG_OFFSET_CAPTURE );
	$profondeur = 0;

	foreach ( $balises[0] as $balise ) {
		$profondeur += str_starts_with( $balise[0], '</' ) ? -1 : 1;

		if ( 0 === $profondeur ) {
			return substr( $fragment, 0, $balise[1] + strlen( $balise[0] ) );
		}
	}

	return '';
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

foreach ( $sources as $nom => $chemin ) {
	$h = file_get_contents( $chemin );
	$s = section( $h, 'services' );

	check( "[$nom] la section #services est présente", '' !== $s );
	check( "[$nom] son titre est celui de la charte",
		str_contains( $s, 'Des tarifs clairs, une estimation personnalisée' )
		&& str_contains( $s, '>Nos tarifs<' ) );

	/* ---------------------------------------------- sept forfaits de départ */

	check( "[$nom] exactement trois familles tarifaires et sept forfaits",
		3 === substr_count( $s, 'class="tarif-group ' )
		&& 7 === preg_match_all( '#class="tarif(?: featured)?"#', $s )
		&& 7 === substr_count( $s, 'class="tarif-price"' ) );

	check( "[$nom] les trois familles sont dans l'ordre attendu",
		strpos( $s, 'tarif-group-dp' ) < strpos( $s, 'tarif-group-pc' )
		&& strpos( $s, 'tarif-group-pc' ) < strpos( $s, 'tarif-group-plans' )
		&& str_contains( $s, '>Déclaration préalable<' )
		&& str_contains( $s, '>Permis de construire<' )
		&& str_contains( $s, '>Conception de plans sur mesure<' ) );

	// Chaque forfait annonce un prix d'appel, jamais un prix ferme.
	check( "[$nom] chaque forfait annonce « À partir de »",
		7 === substr_count( $s, 'class="tarif-from"' )
		&& str_contains( $s, '189&nbsp;€' )
		&& str_contains( $s, '849&nbsp;€' ) );

	check( "[$nom] le formulaire reste la source du devis personnalisé",
		str_contains( $s, 'Le formulaire calcule ensuite un devis estimatif' )
		&& str_contains( $s, 'href="https://urbizen.fr/conception/"' ) );

	check( "[$nom] le supplément ABF reste explicite et transversal",
		str_contains( $s, 'Secteur Bâtiments de France' )
		&& str_contains( $s, '+80&nbsp;€' ) );

	check( "[$nom] l'ancienne section « Nos services » n'est pas dupliquée",
		! str_contains( $s, 'class="service-route"' )
		&& ! str_contains( $s, '>Nos services<' ) );

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

	// La mention d'option a été retirée : elle refroidissait le parcours et ne
	// remplaçait pas le devis détaillé produit par le formulaire.
	check( "[$nom] aucun encart « dépôt dématérialisé en option »",
		! str_contains( $s, 'Dépôt dématérialisé en option' ) );

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
