<?php
/**
 * Banc technique des trois documents légaux.
 *
 * PÉRIMÈTRE — et ce qui n'en fait PAS partie
 *
 * Ce banc vérifie la STRUCTURE et le CONTENU des gabarits : enregistrement,
 * hiérarchie des titres, composants de charte réutilisés, liens, absence des
 * anciens tarifs, distinction consommateurs / professionnels.
 *
 * Il ne dit RIEN de la préparation juridique au déploiement. Un document peut
 * être techniquement irréprochable et rester impubliable faute d'une donnée
 * juridique — c'est ce qui s'est passé jusqu'au 11 août 2026, faute de
 * médiateur désigné. Cette question relève de `test-legal-readiness.php`,
 * délibérément séparé : mélanger les deux rendrait ce banc rouge en permanence
 * et empêcherait tout développement local.
 *
 * Aucune donnée réelle de client, aucun réseau, aucune base.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $libelle Intitulé.
 * @param bool   $reussi  Résultat.
 * @param string $detail  Précision affichée en cas d'échec.
 * @return void
 */
function check( $libelle, $reussi, $detail = '' ) {
	global $echecs;

	if ( ! $reussi ) {
		++$echecs;
	}

	printf( "%-74s %s\n", $libelle, $reussi ? 'OK' : 'ECHEC' );

	if ( ! $reussi && '' !== $detail ) {
		echo '    ' . $detail . "\n";
	}
}

$fns   = (string) file_get_contents( $theme . '/functions.php' );
$json  = (string) file_get_contents( $theme . '/theme.json' );
$css   = (string) file_get_contents( $theme . '/assets/css/urbizen-pages.css' );
$style = (string) file_get_contents( $theme . '/style.css' );
$ident = (string) file_get_contents( $theme . '/patterns/legal-identite.php' );
$nav   = (string) file_get_contents( $theme . '/patterns/legal-navigation.php' );
$assur  = (string) file_get_contents( $theme . '/patterns/legal-assurance.php' );
$tva    = (string) file_get_contents( $theme . '/patterns/legal-tva.php' );
$mediat = (string) file_get_contents( $theme . '/patterns/legal-mediateur.php' );

// Les trois pieds de page que le thème sait rendre. Ils portent les liens vers
// les documents légaux et sont, avec les gabarits, la surface où une adresse
// périmée reste visible pour un visiteur.
$pied_accueil = (string) file_get_contents( $theme . '/patterns/footer-accueil.php' );
$pied_fse     = (string) file_get_contents( $theme . '/parts/footer.html' );
$pied_landing = (string) file_get_contents( $theme . '/parts/footer-landing.html' );

$pages = array(
	'page-mentions-legales' => 'Mentions légales',
	'page-cgv'              => 'Conditions générales de vente',
	'page-confidentialite'  => 'Politique de confidentialité',
);

$tpl = array();

foreach ( array_keys( $pages ) as $slug ) {
	$f = $theme . '/templates/' . $slug . '.html';

	if ( ! is_file( $f ) ) {
		echo "Gabarit introuvable : $slug\n";
		exit( 1 );
	}

	$tpl[ $slug ] = (string) file_get_contents( $f );
}

// ======================================================================
// 1 · ENREGISTREMENT
// ======================================================================
foreach ( array_keys( $pages ) as $slug ) {
	check( "1 · $slug déclaré dans theme.json", str_contains( $json, '"' . $slug . '"' ) );
	check(
		"1 · $slug inscrit dans URBIZEN_CHILD_TEMPLATES_PAGES",
		1 === preg_match( '/URBIZEN_CHILD_TEMPLATES_PAGES\s*=\s*array\([^)]*' . preg_quote( $slug, '/' ) . '/s', $fns )
	);
}
check( '1 · constante URBIZEN_CHILD_TEMPLATES_LEGAL présente', str_contains( $fns, 'URBIZEN_CHILD_TEMPLATES_LEGAL' ) );
check( '1 · détecteur urbizen_child_est_page_legale présent', str_contains( $fns, 'function urbizen_child_est_page_legale' ) );

// ======================================================================
// 2 · STRUCTURE ET HIÉRARCHIE
// ======================================================================
foreach ( $pages as $slug => $nom ) {
	$t = $tpl[ $slug ];

	check( "2 · $nom : un seul <h1>", 1 === preg_match_all( '/<h1[\s>]/', $t ), 'trouvés : ' . preg_match_all( '/<h1[\s>]/', $t ) );
	check( "2 · $nom : lien d'évitement", str_contains( $t, 'class="u-skip"' ) );
	check( "2 · $nom : zone principale identifiée", str_contains( $t, '<main id="contenu"' ) );
	check( "2 · $nom : en-tête commun du site", str_contains( $t, '"slug":"header-urbizen"' ) );
	check( "2 · $nom : pied de page commun du site", str_contains( $t, '"slug":"footer-urbizen"' ) );
	check(
		"2 · $nom : aucun en-tête ni pied propre",
		! preg_match( '/<header\b/', $t ) && ! preg_match( '/<footer\b/', $t )
	);
	check( "2 · $nom : sommaire présent", str_contains( $t, 'class="legal-sommaire"' ) );
	check( "2 · $nom : navigation juridique commune", str_contains( $t, '"slug":"urbizen-child/legal-navigation"' ) );
	check( "2 · $nom : date de mise à jour datée", 1 === preg_match( '/<time datetime="\d{4}-\d{2}-\d{2}">/', $t ) );
	check( "2 · $nom : aucun <h3> avant le premier <h2>",
		false === strpos( $t, '<h3' ) || strpos( $t, '<h2' ) < strpos( $t, '<h3' ) );

	// Chaque entrée de sommaire doit viser une ancre qui existe réellement.
	preg_match_all( '/<a href="#([a-z0-9-]+)">/', $t, $ancres );
	$manquantes = array();

	foreach ( array_unique( $ancres[1] ) as $a ) {
		if ( 'contenu' === $a ) {
			continue;
		}
		if ( ! str_contains( $t, 'id="' . $a . '"' ) ) {
			$manquantes[] = $a;
		}
	}

	check( "2 · $nom : toutes les ancres du sommaire existent", array() === $manquantes, implode( ', ', $manquantes ) );
}

// ======================================================================
// 3 · SOURCE COMMUNE DES DONNÉES LÉGALES
// ======================================================================
check( '3 · fonction urbizen_child_donnees_legales présente', str_contains( $fns, 'function urbizen_child_donnees_legales' ) );
check( '3 · fonction des données manquantes présente', str_contains( $fns, 'function urbizen_child_donnees_legales_manquantes' ) );
check( '3 · les trois documents lisent la source commune',
	3 === substr_count( implode( '', $tpl ), '"slug":"urbizen-child/legal-identite"' ) );
check( '3 · le pattern d\'identité lit la source, sans valeur en dur',
	str_contains( $ident, 'urbizen_child_donnees_legales()' )
	&& ! preg_match( '/105\s?253\s?132/', $ident ) );
check( '3 · aucune coordonnée recopiée dans les gabarits',
	! preg_match( '/105\s?253\s?132/', implode( '', $tpl ) ) );
// La règle « null = inconnu » ne se vérifie plus sur le médiateur, désormais
// connu : elle se vérifie sur ce qui reste inconnu. Deux données le sont, et
// elles doivent le rester tant que personne ne les a confirmées.
check( '3 · les données non vérifiées valent null, jamais une chaîne',
	1 === preg_match( "/'telephone'\s*=> null/", $fns )
	&& 1 === preg_match( "/'numero'\s*=> null/", $fns ) );
check( '3 · TVA, assurance et médiateur sont renseignés, non plus null',
	! preg_match( "/'tva'\s*=> null/", $fns )
	&& ! preg_match( "/'assurance'\s*=> null/", $fns )
	&& ! preg_match( "/'mediateur'\s*=> null/", $fns ) );
check( '3 · le pattern de médiation lit la source, sans coordonnée en dur',
	str_contains( $mediat, 'urbizen_child_donnees_legales()' )
	&& ! preg_match( '/CM2C|cm2c\.net|49 rue de Ponthieu/i', $mediat ) );
check( '3 · aucune coordonnée de médiateur recopiée dans les gabarits',
	! preg_match( '/CM2C|cm2c\.net/i', implode( '', $tpl ) ) );
check( '3 · le pattern d\'assurance lit la source, sans valeur en dur',
	str_contains( $assur, 'urbizen_child_donnees_legales()' )
	&& ! preg_match( '/Zurich|7400042329/i', $assur ) );
check( '3 · le pattern de TVA lit la source, sans texte recopié',
	str_contains( $tva, 'urbizen_child_donnees_legales()' ) );

// LA RÉFÉRENCE RÉGLEMENTAIRE NE DOIT ATTEINDRE AUCUNE PAGE PUBLIQUE
//
// Celle applicable aux factures en franchise en base change au 1er septembre
// 2026. Une page légale est permanente : l'y inscrire programmerait une
// inexactitude datée. Le contrôle porte donc sur TOUT ce qui peut s'afficher —
// gabarits, patterns, et la source elle-même, dont chaque chaîne est destinée
// à une page. Les commentaires de code sont exclus : ils expliquent la
// décision, ils ne l'affichent pas.
$sans_commentaires = static fn( $php ) => (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $php );

$affichable = $sans_commentaires( $ident ) . $sans_commentaires( $tva )
	. $sans_commentaires( $assur ) . $sans_commentaires( $mediat )
	. $sans_commentaires( $fns ) . implode( '', $tpl );

check( '3 · aucune référence réglementaire figée dans ce qui peut s\'afficher',
	! preg_match( '/293\s?B|CIBS|code général des impôts/i', $affichable ) );
check( '3 · le régime est énoncé par son effet, durable',
	preg_match( '/nets de TVA/i', $fns )
	&& preg_match( '/étant pas facturée/i', $fns ) );
check( '3 · aucune coordonnée d\'assurance recopiée dans les gabarits',
	! preg_match( '/Zurich|7400042329/i', implode( '', $tpl ) ) );
check( '3 · aucune mention fiscale recopiée dans les gabarits',
	! preg_match( '/293\s?B/i', implode( '', $tpl ) ) );
check( '3 · aucun « à compléter » dans les gabarits',
	! preg_match( '/à compléter|A COMPLETER|\[…\]|XXXX/i', implode( '', $tpl ) ) );

// ======================================================================
// 4 · CONTENU JURIDIQUE
// ======================================================================
$cgv = $tpl['page-cgv'];

check( '4 · CGV : aucun ancien tarif 149 €', ! preg_match( '/\b149\b/', $cgv ) );
check( '4 · CGV : aucun ancien tarif 349 €', ! preg_match( '/\b349\b/', $cgv ) );
check( '4 · CGV : aucune grille tarifaire recopiée',
	! preg_match( '/\b(189|249|449|549|649|849)\b/', $cgv ) );
check( '4 · CGV : renvoi vers la page Tarifs',
	str_contains( $cgv, '"slug":"urbizen-child/legal-tva"' ) && str_contains( $tva, 'href="/tarifs/"' ) );
check( '4 · CGV : le régime de TVA est tranché, non renvoyé au devis',
	str_contains( $cgv, '"slug":"urbizen-child/legal-tva"' )
	&& ! preg_match( '/régime de taxe sur la valeur ajoutée applicable est celui indiqué/i', $cgv ) );
check( '4 · CGV : la TVA n\'est présentée ni comme une réserve ni comme un obstacle',
	! preg_match( '/sous réserve|le cas échéant.{0,40}TVA|TVA.{0,40}sera précisé|en attente/i', $tva ) );
check( '4 · CGV : assurance professionnelle alimentée par la source commune',
	str_contains( $cgv, '"slug":"urbizen-child/legal-assurance"' )
	&& preg_match( '/Assurance professionnelle/i', $cgv ) );
check( '4 · CGV : aucune promesse générale de 48 heures', ! preg_match( '/48\s*h/i', $cgv ) );
// L'adhésion étant finalisée, le contrôle s'inverse : la section doit NOMMER
// le médiateur. Ce qui reste interdit, c'est la formule d'attente — un
// médiateur « à désigner » ne satisfait pas l'article L.616-1.
check( '4 · CGV : aucune formule « médiateur à désigner »',
	! preg_match( '/une fois le médiateur désigné|à désigner|sera désigné/i', $cgv ) );
check( '4 · CGV : section Médiation présente structurellement', str_contains( $cgv, 'id="mediation"' ) );
check( '4 · CGV : le médiateur est nommé via la source commune',
	str_contains( $cgv, '"slug":"urbizen-child/legal-mediateur"' ) );
check( '4 · CGV : la réclamation préalable reste une condition de saisine',
	preg_match( '/réclamation écrite préalable/i', $cgv )
	&& preg_match( '/délai d\'un an/i', $cgv ) );
check( '4 · CGV : la médiation reste facultative et gratuite',
	preg_match( '/gratuitement/i', $cgv )
	&& preg_match( '/la médiation étant facultative/i', $cgv ) );

check( '4 · CGV : délai de rétractation de 14 jours énoncé',
	preg_match( '/quatorze jours/i', $cgv ) );
check( '4 · CGV : formulaire type de rétractation présent',
	str_contains( $cgv, 'id="formulaire"' ) && str_contains( $cgv, 'legal-formulaire' ) );
check( '4 · CGV : le formulaire n\'est pas rendu obligatoire',
	preg_match( '/sans y être tenu/i', $cgv ) );
check( '4 · CGV : la perte du droit est conditionnée, jamais une renonciation',
	preg_match( '/pleinement exécutée/i', $cgv )
	&& ! preg_match( '/je renonce|renonciation à mon droit|renonce immédiatement/i', $cgv ) );
check( '4 · CGV : remboursement proportionnel en cas d\'exécution commencée',
	preg_match( '/montant proportionné/i', $cgv ) );

check( '4 · CGV : distinction consommateurs / professionnels',
	str_contains( $cgv, 'id="professionnels"' )
	&& preg_match( '/clients consommateurs/i', $cgv ) );
check( '4 · CGV : l\'indemnité de 40 € figure UNIQUEMENT côté professionnels',
	1 === substr_count( $cgv, '40&nbsp;€' )
	&& strpos( $cgv, '40&nbsp;€' ) > strpos( $cgv, 'id="professionnels"' ) );
check( '4 · CGV : pénalités exprimées par un taux, non par un pourcentage figé',
	preg_match( '/Banque centrale européenne/i', $cgv )
	&& preg_match( '/trois fois le taux d\'intérêt légal/i', $cgv ) );
check( '4 · CGV : aucun seuil réglementaire chiffré pour l\'architecte',
	! preg_match( '/150\s*m²|150 m2/i', $cgv ) );
check( '4 · CGV : dépôt conditionné au devis, ni systématique ni exclu',
	preg_match( '/sauf lorsque le devis prévoit/i', $cgv ) );
check( '4 · CGV : clause de recours à un prestataire externe',
	str_contains( $cgv, 'id="externes"' ) );
check( '4 · CGV : pièces complémentaires alignées sur la page Tarifs',
	preg_match( '/restant dans le périmètre initial/i', $cgv ) );

$mentions = $tpl['page-mentions-legales'];

check( '4 · Mentions : identité de l\'éditeur via la source commune',
	str_contains( $mentions, '"slug":"urbizen-child/legal-identite"' ) );
check( '4 · Mentions : directeur de la publication', str_contains( $mentions, 'id="publication"' ) );
check( '4 · Mentions : hébergeur nommé et localisé',
	str_contains( $mentions, 'Hostinger International Ltd' ) && str_contains( $mentions, 'Larnaca' ) );
check( '4 · Mentions : Urbizen n\'est pas présentée comme architecte',
	preg_match( "/n'est pas un cabinet d'architecture/i", $mentions ) );
check( '4 · Mentions : pas d\'exclusion générale de responsabilité',
	! preg_match( /* phpcs:ignore */ '/décline toute responsabilité|en aucun cas responsable/i', $mentions ) );
// L'attestation étant fournie, l'affirmation est justifiée : le contrôle
// s'inverse. Ce qui reste interdit, c'est de la recopier — le pattern lit la
// source, et le contrôle 3 vérifie qu'aucune valeur ne migre vers un gabarit.
check( '4 · Mentions : section Assurances alimentée par la source commune',
	str_contains( $mentions, 'id="assurances"' )
	&& str_contains( $mentions, '"slug":"urbizen-child/legal-assurance"' ) );
check( '4 · Mentions : les deux garanties énoncées',
	preg_match( '/responsabilité civile professionnelle/i', $mentions )
	&& preg_match( '/responsabilité civile décennale/i', $mentions ) );
check( '4 · Mentions : la couverture ne déborde pas sur la maîtrise d\'œuvre',
	preg_match( "/ne couvrent ni la maîtrise d'œuvre/i", $mentions ) );
check( '4 · Mentions : lien vers la politique de confidentialité',
	str_contains( $mentions, 'href="/politique-de-confidentialite/"' ) );

$conf = $tpl['page-confidentialite'];

check( '4 · Confidentialité : titre au singulier',
	str_contains( $conf, '<h1>Politique de confidentialité</h1>' ) );
check( '4 · Confidentialité : durée réelle de 12 mois, pas 3 ans',
	preg_match( '/12 mois/i', $conf ) && ! preg_match( '/jusqu.à 3 ans/i', $conf ) );
check( '4 · Confidentialité : suppression décrite, non anonymisation',
	preg_match( "/Il s'agit d'une suppression, non d'une anonymisation/i", $conf ) );
check( '4 · Confidentialité : les dossiers clients sont exclus de la purge',
	preg_match( /* phpcs:ignore */ '/ne sont pas concernées par cette purge/i', $conf ) );
check( '4 · Confidentialité : prestataires réellement observés, nommés',
	str_contains( $conf, 'Hostinger' ) && str_contains( $conf, 'Google' ) && str_contains( $conf, 'Chatway' ) );
check( '4 · Confidentialité : transferts hors EEE traités', str_contains( $conf, 'id="transferts"' ) );
check( '4 · Confidentialité : aucune formule vague sur les cookies',
	! preg_match( "/lorsqu'un module de gestion est disponible/i", $conf ) );
// L'assertion s'inverse : un gestionnaire de consentement existe désormais.
// Ce qui était mensonger hier — l'affirmer — deviendrait mensonger aujourd'hui
// de le taire.
check( '4 · Confidentialité : le gestionnaire de consentement est décrit',
	preg_match( '/Complianz/i', $conf )
	&& preg_match( '/accepter/i', $conf )
	&& preg_match( '/refuser/i', $conf ) );
check( '4 · Confidentialité : retrait du consentement et lien permanent',
	preg_match( '/Gérer le consentement/i', $conf )
	&& preg_match( '/retirer votre choix|retrait/i', $conf ) );
check( '4 · Confidentialité : AdSense annoncé comme non inséré',
	preg_match( "/n'est pas inséré dans les pages/i", $conf )
	&& preg_match( '/Aucune publicité n.est diffusée/i', $conf ) );
check( '4 · Confidentialité : Chatway annoncé comme retiré',
	preg_match( "/Chatway.{0,40}n'est plus utilisé/i", $conf ) );
// Complianz gratuit fournit une preuve de consentement, pas le registre
// individuel du module Premium. La page ne doit pas laisser croire l'inverse.
check( '4 · Confidentialité : aucun registre individuel revendiqué',
	! preg_match( '/registre des consentements|records of consent|registre individuel/i', $conf ) );
check( '4 · Confidentialité : les cases des formulaires ne sont pas présentées comme un consentement RGPD',
	preg_match( '/ne constituent pas un consentement au sens du règlement/i', $conf ) );
check( '4 · Confidentialité : CNIL et voie de réclamation', str_contains( $conf, 'cnil.fr' ) );

// ======================================================================
// 5 · CHARTE ET FEUILLE DE STYLE
// ======================================================================
foreach ( $pages as $slug => $nom ) {
	check( "5 · $nom : classe racine commune", str_contains( $tpl[ $slug ], 'urbizen-page-legal' ) );
}
check( '5 · variante mentions', str_contains( $tpl['page-mentions-legales'], 'urbizen-page-mentions' ) );
check( '5 · variante CGV', str_contains( $tpl['page-cgv'], 'urbizen-page-cgv' ) );
check( '5 · variante confidentialité', str_contains( $tpl['page-confidentialite'], 'urbizen-page-confidentialite' ) );
check( '5 · section .urbizen-page-legal dans urbizen-pages.css', str_contains( $css, '.urbizen-page-legal' ) );
check( '5 · aucun !important introduit par la section légale',
	! preg_match( '/\.urbizen-page-legal[^{]*\{[^}]*!important/s', $css ) );
check( '5 · sommaire collant en desktop, statique en mobile',
	preg_match( '/\.urbizen-page-legal \.legal-sommaire \{[^}]*position: sticky/s', $css )
	&& preg_match( '/\.urbizen-page-legal \.legal-sommaire \{ position: static/', $css ) );
check( '5 · largeur de lecture bornée', preg_match( '/\.legal-texte \{ max-width: \d+ch/', $css ) );
check( '5 · composants de charte réutilisés',
	str_contains( $mentions, 'eyebrow eyebrow-highlight' ) && str_contains( $mentions, 'fil-ariane' ) );
check( '5 · aucune image ajoutée sur les pages légales',
	0 === preg_match_all( '/<img\b/', implode( '', $tpl ) ) );

// ======================================================================
// 6 · VERSION DU THÈME — cache de patterns
// ======================================================================
preg_match( '/^Version:\s*(.+)$/m', $style, $v );
$version = isset( $v[1] ) ? trim( $v[1] ) : '';

check( '6 · version du thème = 0.3.0 (cinq nouveaux patterns déployés)', '0.3.0' === $version, 'lue : ' . $version );
check( '6 · une seule source de vérité pour la version', 1 === preg_match_all( '/^Version:/m', $style ) );

// ======================================================================
// 7 · SEO — le thème ne double jamais AIOSEO
// ======================================================================
// On compte les ÉMETTEURS réels, pas les mentions : le fichier documente le
// doublon corrigé en production, et cette explication cite la balise.
preg_match_all( "/printf\(\s*\n?\s*'<meta name=\"description\"/", $fns, $emetteurs );
$nb_emetteurs = count( $emetteurs[0] );

check( '7 · un seul émetteur de meta description dans tout le thème',
	1 === $nb_emetteurs, 'trouvés : ' . $nb_emetteurs );
check( '7 · cet émetteur est réservé à la page Tarifs',
	str_contains( $fns, 'function urbizen_child_description_tarifs' )
	&& preg_match( '/function urbizen_child_description_tarifs\(\)\s*\{\s*if \( ! urbizen_child_est_page_tarifs\(\) \)/', $fns ) );
check( '7 · et il s\'efface devant un greffon SEO',
	preg_match( '/function urbizen_child_description_tarifs\(\).*?urbizen_child_seo_gere_ailleurs\(\)/s', $fns ) );
check( '7 · aucune fonction de description propre aux pages légales',
	! preg_match( '/function urbizen_child_(description|titre)_(legal|mentions|cgv|confidentialite)/', $fns ) );

// ======================================================================
// 8 · NAVIGATION JURIDIQUE
// ======================================================================
check( '8 · la navigation marque le document courant sans le lier',
	str_contains( $nav, 'aria-current="page"' ) && str_contains( $nav, 'legal-nav-courant' ) );
check( '8 · les trois documents figurent dans la navigation',
	str_contains( $nav, '/mentions-legales/' )
	&& str_contains( $nav, '/conditions-generales-de-vente/' )
	&& str_contains( $nav, '/politique-de-confidentialite/' ) );

// Les anciennes adresses héritées de WooCommerce ont été migrées le 13 août
// 2026 et répondent 404 : aucune redirection n'a été demandée. Un lien resté
// en arrière est donc un lien mort, pas une simple imprécision — d'où ce
// contrôle, qui balaie tout ce que le thème publie, gabarits et pieds compris.
//
// POURQUOI UN DÉCOMMENTEUR LOCAL
//
// `$sans_commentaires`, plus haut, retire aussi les commentaires `//`. Sur une
// source qui contient des URL absolues, il ampute `https://urbizen.fr/...`
// après `https:` — et masquerait donc exactement la fuite qu'on cherche. Ici
// seuls les blocs `/* */` tombent : c'est là que vit la note d'historique de
// `legal-navigation.php`, qui cite les anciennes adresses pour dire qu'elles
// sont mortes.
$sans_blocs = static fn( $src ) => (string) preg_replace( '#/\*.*?\*/#s', '', $src );

$sources_publiees = $tpl + array(
	'navigation'    => $nav,
	'pied-accueil'  => $pied_accueil,
	'pied-fse'      => $pied_fse,
	'pied-landing'  => $pied_landing,
);
$fuites = array();

foreach ( $sources_publiees as $nom => $source ) {
	if ( preg_match( '#(refund_returns|privacy-policy)#', $sans_blocs( $source ) ) ) {
		$fuites[] = $nom;
	}
}

check( '8 · aucune ancienne adresse légale ne subsiste dans ce que le thème publie',
	array() === $fuites, 'trouvée dans : ' . implode( ', ', $fuites ) );

echo "\n";

if ( $echecs ) {
	echo "$echecs CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}

echo "TOUS LES CONTROLES PASSENT\n";
exit( 0 );
