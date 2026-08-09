<?php
/**
 * Contrat éditorial, commercial et visuel de la page Déclaration préalable.
 */

$root = dirname( __DIR__, 2 );
$tpl  = (string) file_get_contents( $root . '/wordpress/urbizen-child/templates/page-declaration-prealable.html' );
$css  = (string) file_get_contents( $root . '/wordpress/urbizen-child/assets/css/urbizen-pages.css' );

$errors = 0;
function check( $label, $condition ) {
	global $errors;
	printf( "%-86s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
	if ( ! $condition ) {
		$errors++;
	}
}

check( 'Un seul titre principal', 1 === preg_match_all( '/<h1[\s>]/', $tpl ) );
check( 'Le titre éditorial historique est conservé', str_contains( $tpl, 'La déclaration préalable de travaux, sans prise de tête.' ) );
check( 'Les CTA ouvrent directement le formulaire DP', substr_count( $tpl, 'href="/formulaire-declaration-prealable/"' ) >= 5 );
check( 'Aucun CTA ne renvoie vers le tunnel de l’accueil', ! str_contains( $tpl, 'href="/#localisation"' ) );
check( 'Aucune promesse de qualification ou d’étude préalable',
	! str_contains( strtolower( $tpl ), 'qualifier mon projet' )
	&& ! str_contains( strtolower( $tpl ), 'vérifions si votre projet' )
	&& ! str_contains( strtolower( $tpl ), 'étude gratuite' ) );
check( 'Le contrôle intervient après l’envoi avec rappel sous 24 h',
	substr_count( $tpl, 'après l\'envoi' ) >= 3
	&& substr_count( $tpl, '24&nbsp;h ouvrées' ) >= 3 );

check( 'Le hero utilise une fiche de dossier et aucune fausse illustration de plan',
	1 === substr_count( $tpl, 'class="dp-hero-dossier"' )
	&& ! str_contains( $tpl, 'class="page-hero-plan"' )
	&& ! str_contains( $tpl, 'DP2 · PLAN DE MASSE' ) );
check( 'Aucun sommaire numéroté n’est affiché sous le hero', ! str_contains( $tpl, 'dp-topic-nav' ) );

check( 'Le tableau compare sans formalité, DP et permis de construire',
	str_contains( $tpl, 'class="seuils"' )
	&& str_contains( $tpl, 'Sans formalité' )
	&& str_contains( $tpl, 'Déclaration préalable' )
	&& str_contains( $tpl, 'Permis de construire' ) );
check( 'Les projets courants incluent garage, pergola, piscine, façade et solaire',
	str_contains( $tpl, 'Abri, garage, pergola' )
	&& str_contains( $tpl, 'Piscine enterrée' )
	&& str_contains( $tpl, 'Façade, toiture' )
	&& str_contains( $tpl, 'Panneaux solaires' ) );
check( 'Surface de plancher, emprise au sol et surface totale sont expliquées',
	str_contains( $tpl, 'Surface de plancher' )
	&& str_contains( $tpl, 'Emprise au sol' )
	&& str_contains( $tpl, 'Surface existante après travaux' ) );
check( 'Une comparaison dédiée distingue DP et PC',
	2 === substr_count( $tpl, 'class="dp-compare-card' )
	&& str_contains( $tpl, 'délai d\'instruction de droit commun d\'un mois' )
	&& str_contains( $tpl, 'deux mois d\'instruction pour une maison individuelle' ) );
check( 'La procédure après dépôt conserve ses six étapes utiles',
	6 === preg_match_all( '/<li><span class="f-n">0[1-6]<\/span>/', $tpl )
	&& str_contains( $tpl, 'Recours des tiers' )
	&& str_contains( $tpl, 'DAACT' ) );
check( 'ABF, taxe d’aménagement, PLU et copropriété sont traités',
	str_contains( $tpl, 'Architecte des Bâtiments de France' )
	&& str_contains( $tpl, 'Taxe d\'aménagement' )
	&& str_contains( $tpl, 'PLU et lotissement' )
	&& str_contains( $tpl, 'Copropriété' ) );
check( 'Les quatre cartes réglementaires ont quatre icônes au trait',
	4 === substr_count( $tpl, 'class="dp-info-card"' )
	&& 4 === preg_match_all( '/class="dp-info-icon" aria-hidden="true"><svg[^>]+fill="none"[^>]+stroke="currentColor"/', $tpl ) );
preg_match( '/<ul class="dp-check-list">(.*?)<\/ul>/s', $tpl, $check_list );
check( 'La liste des renseignements à préparer contient cinq éléments',
	str_contains( $tpl, 'Les renseignements utiles pour préparer le dossier' )
	&& isset( $check_list[1] )
	&& 5 === substr_count( $check_list[1], '<li>' ) );
preg_match( '/<ul class="erreurs">(.*?)<\/ul>/s', $tpl, $error_list );
check( 'Les erreurs fréquentes et leur conséquence sont conservées',
	isset( $error_list[1] )
	&& 5 === substr_count( $error_list[1], '<li>' )
	&& str_contains( $tpl, 'demande de pièces complémentaires' ) );
check( 'Les dix pièces réglementaires du dossier sont listées', 10 === substr_count( $tpl, 'class="planche-item"' ) );
check( 'Les dix pièces reprennent les vignettes illustrées de l’accueil',
	10 === substr_count( $tpl, 'class="planche-fig"' )
	&& str_contains( $tpl, 'class="dp-dossier-panel"' )
	&& str_contains( $css, '.urbizen-page-dp .dp-dossier .planche-fig' ) );

check( 'Les trois tarifs correspondent au formulaire réel',
	str_contains( $tpl, '189&nbsp;€' )
	&& str_contains( $tpl, '249&nbsp;€' )
	&& str_contains( $tpl, '549&nbsp;€' )
	&& ! str_contains( $tpl, '149&nbsp;€' ) );
check( 'Les trois options tarifaires réelles sont affichées',
	str_contains( $tpl, '+80&nbsp;€' )
	&& str_contains( $tpl, '+30&nbsp;€' )
	&& str_contains( $tpl, '+100&nbsp;€' ) );
check( 'Les options, la note et les services des tarifs sont alignés',
	str_contains( $css, '.urbizen-page-dp .dp-options {' )
	&& str_contains( $css, 'grid-template-columns: repeat(3, minmax(0, 1fr));' )
	&& str_contains( $css, '.urbizen-page-dp .dp-price-note { width: 100%; max-width: none;' )
	&& str_contains( $css, '.urbizen-page-dp .dp-related-services .service-route { height: 100%; }' ) );
check( 'Les pages Permis et Conception sont reliées depuis les tarifs',
	str_contains( $tpl, 'href="/permis-de-construire/"' )
	&& str_contains( $tpl, 'href="/conception/"' ) );
check( 'La FAQ conserve cinq réponses détaillées', 5 === preg_match_all( '/<details><summary>/', $tpl ) );
check( 'Le dernier bandeau reprend le composant complet de l’accueil',
	str_contains( $tpl, '<span class="eyebrow-highlight-text">Parlons de votre projet</span>' )
	&& str_contains( $tpl, 'Prêt à faire avancer votre projet&nbsp;?' )
	&& str_contains( $tpl, 'class="cta-actions"' )
	&& str_contains( $tpl, 'class="btn btn-contact"' )
	&& str_contains( $tpl, 'Demander des renseignements' )
	&& str_contains( $tpl, 'href="/#demander-des-renseignements"' ) );
check( 'Les sources officielles sont accessibles',
	str_contains( $tpl, 'legifrance.gouv.fr' )
	&& str_contains( $tpl, 'service-public.fr' ) );

check( 'La page ne reprend ni bande confiance ni cartes-projets de l’accueil',
	! str_contains( $tpl, 'class="trust' )
	&& ! str_contains( $tpl, 'dp-project-grid' )
	&& ! str_contains( $tpl, 'dp-steps' ) );
check( 'Les surlignages reprennent exactement le composant de l’accueil',
	substr_count( $tpl, 'class="eyebrow eyebrow-highlight"' ) >= 8
	&& substr_count( $tpl, 'class="eyebrow-highlight-text"' ) >= 8
	&& ! str_contains( $tpl, 'dp-section-label' ) );
check( 'Le quadrillage de la charte rythme les sections principales',
	str_contains( $css, '.urbizen-page-dp .dp-understand' )
	&& str_contains( $css, '.urbizen-page-dp .dp-special-cases' )
	&& str_contains( $css, '.urbizen-page-dp .dp-pricing' )
	&& str_contains( $css, 'background: var(--u-grid-bg);' ) );
check( 'Quadrillage et fond uni alternent strictement après le hero',
	str_contains( $css, '.urbizen-page-dp #seuils,' )
	&& str_contains( $css, '.urbizen-page-dp .dp-procedure,' )
	&& str_contains( $css, '.urbizen-page-dp .dp-dossier,' )
	&& str_contains( $css, '.urbizen-page-dp .dp-faq' )
	&& str_contains( $css, 'background: var(--u-surface-2);' )
	&& str_contains( $tpl, '<section class="dp-procedure">' ) );
check( 'Les titres, introductions, notes et encarts utilisent toute la largeur',
	str_contains( $css, '.urbizen-page-dp .sec-head,' )
	&& str_contains( $css, '.urbizen-page-dp .note,' )
	&& str_contains( $css, '.urbizen-page-dp .frise,' )
	&& str_contains( $css, '.urbizen-page-dp .encart,' )
	&& str_contains( $css, 'max-width: none;' ) );
check( 'La FAQ et son surtitre sont alignés à gauche sur la largeur de la section',
	! str_contains( $tpl, '<div class="sec-head center">' )
	&& str_contains( $css, '.urbizen-page-dp .dp-faq .faq { display: grid; gap: 12px; width: 100%; max-width: none; margin: 0;' ) );
check( 'L’échelle typographique suit celle de l’accueil',
	str_contains( $css, '.urbizen-page-dp .dp-hero h1 {' )
	&& str_contains( $css, 'font-size: clamp(36px, 4.4vw, 52px);' )
	&& str_contains( $css, '.urbizen-page-dp .dp-hero .lead {' )
	&& str_contains( $css, '.urbizen-page-dp .dp-info-card p { margin: 0; color: var(--u-ink-soft); font-size: 15.5px;' )
	&& str_contains( $css, '.urbizen-page-dp .dp-faq .faq .a { padding: 0 60px 22px 20px; color: var(--u-ink-soft); font-size: 15.5px;' ) );
check( 'Tous les nouveaux composants sont limités à la page DP',
	str_contains( $css, '.urbizen-page-dp .dp-hero-dossier' )
	&& str_contains( $css, '.urbizen-page-dp .dp-preparation' )
	&& str_contains( $css, '.urbizen-page-dp .dp-price-grid' ) );

$dp_css = strstr( $css, '/* Déclaration préalable — guide de service' ) ?: '';
preg_match_all( '/font-family:\s*([^;]+);/', preg_replace( '/\/\*.*?\*\//s', '', $dp_css ), $font_matches );
$fonts = array_values( array_unique( array_map( 'trim', $font_matches[1] ) ) );
check( 'Les polices restent celles de la charte',
	array() === array_values( array_diff( $fonts, array( 'var(--u-font-title)', 'var(--u-font-body)', 'var(--u-font-mono)' ) ) ) );

echo "\n" . ( $errors ? "$errors CONTROLE(S) EN ECHEC\n" : "TOUS LES CONTROLES PASSENT\n" );
exit( $errors ? 1 : 0 );
