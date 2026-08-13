<?php
/**
 * Contrat éditorial, commercial et visuel de la page Permis de construire.
 */

$root = dirname( __DIR__, 2 );
$tpl  = (string) file_get_contents( $root . '/wordpress/urbizen-child/templates/page-permis-de-construire.html' );
$css  = (string) file_get_contents( $root . '/wordpress/urbizen-child/assets/css/urbizen-pages.css' );

$errors = 0;
function check_pc( $label, $condition ) {
	global $errors;
	printf( "%-88s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
	if ( ! $condition ) {
		$errors++;
	}
}

check_pc( 'Un seul titre principal', 1 === preg_match_all( '/<h1[\s>]/', $tpl ) );
// Le H1 a gagné le mot « dossier » au lot C, le 13 août 2026. Ce n'est pas un
// ajustement de style : « Votre permis de construire, préparé de A à Z » pouvait
// se lire comme une promesse d'obtention de l'autorisation. Avec « dossier », le
// verbe « préparé » porte sur ce qui est réellement livré — et le H1 s'aligne
// sur le title « Dossier de permis de construire à distance ».
check_pc( 'Le titre éditorial est conservé', str_contains( $tpl, 'Votre dossier de permis de construire, préparé de A à Z.' ) );
check_pc( 'Le titre ne promet pas l\'obtention du permis', ! preg_match( '/<h1[^>]*>\s*Votre permis de construire/', $tpl ) );
check_pc( 'Les CTA ouvrent directement le formulaire PC', substr_count( $tpl, 'href="/formulaire-permis-de-construire/"' ) >= 5 );
check_pc( 'Les CTA principaux nomment le permis de construire en toutes lettres',
	2 === substr_count( $tpl, '>Démarrer mon permis de construire</a>' )
	&& ! str_contains( $tpl, '>Démarrer mon permis</a>' ) );
check_pc( 'Aucun CTA ne renvoie vers le tunnel de localisation', ! str_contains( $tpl, 'href="/#localisation"' ) );
check_pc( 'Aucune promesse de qualification ou étude préalable',
	! str_contains( strtolower( $tpl ), 'qualifier mon projet' )
	&& ! str_contains( strtolower( $tpl ), 'étude gratuite' )
	&& ! str_contains( strtolower( $tpl ), 'nous étudions votre projet' ) );
check_pc( 'Le contrôle intervient après l’envoi avec rappel sous 24 h',
	substr_count( strtolower( $tpl ), 'après l\'envoi' ) >= 3
	&& substr_count( $tpl, '24&nbsp;h ouvrées' ) >= 3 );

check_pc( 'Le hero montre un dossier concret sans fausse illustration de plan',
	1 === substr_count( $tpl, 'class="pc-hero-dossier"' )
	&& ! str_contains( $tpl, 'class="page-hero-plan"' ) );
check_pc( 'Le tableau distingue absence de formalité, DP et PC',
	str_contains( $tpl, 'class="seuils"' )
	&& str_contains( $tpl, 'Sans formalité' )
	&& str_contains( $tpl, 'Déclaration préalable' )
	&& str_contains( $tpl, 'Permis de construire' ) );
check_pc( 'Les principaux cas de permis sont couverts',
	str_contains( $tpl, 'Construction nouvelle ou annexe' )
	&& str_contains( $tpl, 'Extension en zone U' )
	&& str_contains( $tpl, 'Piscine enterrée' )
	&& str_contains( $tpl, 'Changement de destination' )
	&& str_contains( $tpl, 'Maison individuelle neuve' ) );
check_pc( 'Surface de plancher, emprise et total après travaux sont expliqués',
	str_contains( $tpl, 'Surface de plancher' )
	&& str_contains( $tpl, 'Emprise au sol' )
	&& str_contains( $tpl, 'Total après travaux' ) );
check_pc( 'Le seuil architecte et son exemple sont visibles',
	str_contains( $tpl, 'seuil des 150&nbsp;m²' )
	&& str_contains( $tpl, 'Maison existante de <b>130&nbsp;m²</b>' )
	&& str_contains( $tpl, 'Une personne morale, comme une SCI' ) );
check_pc( 'La procédure conserve ses six étapes',
	6 === preg_match_all( '/<li><span class="f-n">0[1-6]<\/span>/', $tpl )
	&& str_contains( strtolower( $tpl ), 'déclaration d\'ouverture de chantier' )
	&& str_contains( $tpl, 'DAACT' ) );
check_pc( 'PLU, ABF, RE2020 et taxe d’aménagement sont traités',
	str_contains( $tpl, 'PLU et lotissement' )
	&& str_contains( $tpl, 'Architecte des Bâtiments de France' )
	&& str_contains( $tpl, 'RE2020 et attestation' )
	&& str_contains( $tpl, 'Taxe d\'aménagement' ) );
check_pc( 'Les quatre conditions particulières ont des icônes au trait',
	4 === substr_count( $tpl, 'class="pc-info-card"' )
	&& 4 === preg_match_all( '/class="pc-info-icon" aria-hidden="true"><svg[^>]+fill="none"[^>]+stroke="currentColor"/', $tpl ) );
preg_match( '/<ul class="pc-check-list">(.*?)<\/ul>/s', $tpl, $check_list );
check_pc( 'La liste de préparation contient cinq informations utiles', isset( $check_list[1] ) && 5 === substr_count( $check_list[1], '<li>' ) );
preg_match( '/<ul class="erreurs">(.*?)<\/ul>/s', $tpl, $error_list );
check_pc( 'Les cinq erreurs fréquentes et leur conséquence sont conservées',
	isset( $error_list[1] )
	&& 5 === substr_count( $error_list[1], '<li>' )
	&& str_contains( $tpl, 'demande de pièces complémentaires' ) );
check_pc( 'Les dix pièces du dossier sont illustrées',
	10 === substr_count( $tpl, 'class="planche-item"' )
	&& 10 === substr_count( $tpl, 'class="planche-fig"' )
	&& str_contains( $tpl, 'PCMI4' )
	&& str_contains( $tpl, 'Notice descriptive' ) );

check_pc( 'Les trois tarifs correspondent au formulaire réel',
	str_contains( $tpl, '449&nbsp;€' )
	&& str_contains( $tpl, '649&nbsp;€' )
	&& str_contains( $tpl, '849&nbsp;€' ) );
check_pc( 'Les options et exclusions importantes sont explicites',
	str_contains( $tpl, '+80&nbsp;€' )
	&& str_contains( $tpl, '+30&nbsp;€' )
	&& str_contains( $tpl, 'hors mission d\'architecte' )
	&& str_contains( $tpl, 'étude BBIO ou RE2020' ) );
check_pc( 'Les pages DP et Conception sont reliées depuis les tarifs',
	str_contains( $tpl, 'href="/declarations-prealables/"' )
	&& str_contains( $tpl, 'href="/conception/"' ) );
check_pc( 'La FAQ conserve cinq réponses détaillées', 5 === preg_match_all( '/<details><summary>/', $tpl ) );
check_pc( 'Le dernier bandeau reprend le composant complet de l’accueil',
	str_contains( $tpl, '<span class="eyebrow-highlight-text">Parlons de votre projet</span>' )
	&& str_contains( $tpl, 'Prêt à faire avancer votre projet&nbsp;?' )
	&& str_contains( $tpl, 'class="cta-actions"' )
	&& str_contains( $tpl, 'class="btn btn-contact"' )
	&& str_contains( $tpl, 'Demander des renseignements' ) );
check_pc( 'Les sources officielles sont accessibles',
	str_contains( $tpl, 'legifrance.gouv.fr' )
	&& str_contains( $tpl, 'service-public.fr' )
	&& str_contains( $tpl, 'developpement-durable.gouv.fr' ) );

check_pc( 'Les surlignages reprennent exactement le composant de l’accueil',
	substr_count( $tpl, 'class="eyebrow eyebrow-highlight"' ) >= 10
	&& substr_count( $tpl, 'class="eyebrow-highlight-text"' ) >= 10 );
check_pc( 'Quadrillage et fond uni alternent strictement après le hero',
	str_contains( $css, '.urbizen-page-pc .pc-thresholds,' )
	&& str_contains( $css, '.urbizen-page-pc .pc-architect,' )
	&& str_contains( $css, '.urbizen-page-pc .pc-pricing,' )
	&& str_contains( $css, '.urbizen-page-pc .pc-final { background: var(--u-grid-bg); }' )
	&& str_contains( $css, '.urbizen-page-pc .pc-understand,' )
	&& str_contains( $css, '.urbizen-page-pc .pc-faq { background: var(--u-surface-2); }' ) );
check_pc( 'Les titres, introductions, notes et encarts utilisent toute la largeur',
	str_contains( $css, '.urbizen-page-pc .sec-head,' )
	&& str_contains( $css, '.urbizen-page-pc .note,' )
	&& str_contains( $css, '.urbizen-page-pc .frise,' )
	&& str_contains( $css, '.urbizen-page-pc .encart,' )
	&& str_contains( $css, 'width: 100%; max-width: none;' ) );
check_pc( 'La FAQ et son surtitre sont alignés à gauche',
	! str_contains( $tpl, '<div class="sec-head center">' )
	&& str_contains( $css, '.urbizen-page-pc .pc-faq .faq { display: grid; gap: 12px; width: 100%; max-width: none; margin: 0;' ) );
check_pc( 'L’échelle typographique suit celle de l’accueil',
	str_contains( $css, '.urbizen-page-pc .pc-hero h1 {' )
	&& str_contains( $css, 'font-size: clamp(36px, 4.4vw, 52px);' )
	&& str_contains( $css, '.urbizen-page-pc .pc-info-card p { margin: 0; color: var(--u-ink-soft); font-size: 15.5px;' ) );

$pc_css = strstr( $css, '/* Permis de construire — guide réglementaire' ) ?: '';
preg_match_all( '/font-family:\s*([^;]+);/', preg_replace( '/\/\*.*?\*\//s', '', $pc_css ), $font_matches );
$fonts = array_values( array_unique( array_map( 'trim', $font_matches[1] ) ) );
check_pc( 'Les polices restent celles de la charte',
	array() === array_values( array_diff( $fonts, array( 'var(--u-font-title)', 'var(--u-font-body)', 'var(--u-font-mono)' ) ) ) );

echo "\n" . ( $errors ? "$errors CONTROLE(S) EN ECHEC\n" : "TOUS LES CONTROLES PASSENT\n" );
exit( $errors ? 1 : 0 );
