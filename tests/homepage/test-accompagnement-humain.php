<?php
/**
 * Banc de la section « Accompagnement humain » de l'accueil.
 *
 * La photographie est un visuel d'ambiance. Elle ne doit jamais devenir une
 * preuve sociale trompeuse ni être présentée comme une photographie de
 * l'équipe Urbizen. Ce banc fige à la fois cette règle éditoriale, l'ordre des
 * sections, la parité des deux sources et la présence des deux images WebP.
 */

$racine = dirname( __DIR__, 2 );

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-76s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

/** Extrait une section simple par son identifiant. */
function section( $html, $id ) {
	if ( ! preg_match( '#<section[^>]*id="' . preg_quote( $id, '#' ) . '"#', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}

	$debut = $m[0][1];
	$fin   = strpos( $html, '</section>', $debut );

	return false === $fin ? '' : substr( $html, $debut, $fin - $debut + 10 );
}

$gabarit = file_get_contents( $racine . '/wordpress/urbizen-child/templates/page-accueil-urbizen.html' );
$accueil = file_get_contents( $racine . '/wordpress/urbizen-child/templates/front-page.html' );
$maquette = file_get_contents( $racine . '/frontend/homepage/index.html' );
$css = file_get_contents( $racine . '/frontend/homepage/homepage.css' );

$section_gabarit = section( $gabarit, 'accompagnement' );
$section_accueil = section( $accueil, 'accompagnement' );
$section_maquette = section( $maquette, 'accompagnement' );

check( 'La section #accompagnement est présente dans le gabarit', '' !== $section_gabarit );
check( 'La maquette et le gabarit portent exactement la même section', $section_gabarit === $section_maquette );
check( 'front-page et le gabarit sont strictement synchronisés', $section_gabarit === $section_accueil );

check( 'La section suit « Comment ça marche » et précède l’explorateur',
	strpos( $gabarit, 'id="methode"' ) < strpos( $gabarit, 'id="accompagnement"' )
	&& strpos( $gabarit, 'id="accompagnement"' ) < strpos( $gabarit, 'id="dossier"' ) );

check( 'Le titre et le texte d’accompagnement validés sont présents',
	str_contains( $section_gabarit, 'Un interlocuteur pour suivre votre projet' )
	&& str_contains( $section_gabarit, "votre dossier n'est pas traité de façon impersonnelle" ) );
check( 'Les quatre bénéfices validés sont présents',
	str_contains( $section_gabarit, 'Échanges directs' )
	&& str_contains( $section_gabarit, 'Suivi du dossier' )
	&& str_contains( $section_gabarit, 'Conseils adaptés au projet' )
	&& str_contains( $section_gabarit, 'Interprétation des règles d’urbanisme' ) );

check( 'Aucune légende ne masque l’image',
	! str_contains( $section_gabarit, '<figcaption>' )
	&& ! str_contains( $section_gabarit, "Image d'illustration" ) );
check( 'Aucune formule ne présente les personnes comme l’équipe Urbizen',
	! preg_match( '#(équipe Urbizen|notre équipe|nos collaborateurs)#iu', $section_gabarit ) );
check( 'Le texte alternatif décrit la scène sans inventer une identité',
	str_contains( $section_gabarit, 'alt="Échange autour d\'un projet dans un bureau"' ) );
check( 'L’image est différée, décodée sans blocage et proposée en deux tailles',
	str_contains( $section_gabarit, 'loading="lazy"' )
	&& str_contains( $section_gabarit, 'decoding="async"' )
	&& str_contains( $section_gabarit, 'accompagnement-humain-720.webp 720w' )
	&& str_contains( $section_gabarit, 'accompagnement-humain-1400.webp 1400w' ) );

$images = $racine . '/wordpress/urbizen-child/assets/images/homepage/';
$petite = $images . 'accompagnement-humain-720.webp';
$grande = $images . 'accompagnement-humain-1400.webp';
check( 'Les deux images WebP existent', is_file( $petite ) && is_file( $grande ) );
check( 'Les deux images restent légères',
	is_file( $petite ) && filesize( $petite ) < 100000
	&& is_file( $grande ) && filesize( $grande ) < 150000 );

check( 'L’image conserve son ratio horizontal sans être coupée',
	(bool) preg_match( '#\.accompagnement-visuel img \{.*?height:\s*auto;.*?aspect-ratio:\s*16\s*/\s*9;.*?object-fit:\s*contain;.*?object-position:\s*center;#s', $css ) );
check( 'Le mobile empile le texte et le visuel',
	(bool) preg_match( '#@media \(max-width: 820px\).*?\.accompagnement-grille \{ grid-template-columns: 1fr;#s', $css ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
