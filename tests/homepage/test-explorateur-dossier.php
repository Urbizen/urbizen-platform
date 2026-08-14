<?php
/**
 * Banc de l'explorateur de dossier et du parcours en quatre étapes.
 *
 * POURQUOI CE BANC
 *
 * Les deux sections refondues le 15 août 2026 portent des affirmations qui
 * engagent Urbizen, et trois d'entre elles se sont révélées fausses à la
 * relecture visuelle, alors que les treize bancs de l'accueil étaient au vert :
 *
 *   1. la loupe était rattachée à `document.body`, hors de la portée
 *      `.urbizen-accueil` sous laquelle scope-css.py écrit la feuille : elle
 *      s'ouvrait donc **sans aucun style**, et rien dans les sources ne le
 *      disait ;
 *   2. trois vues sur dix n'avaient pas d'encart, la référence en demandant un
 *      par pièce ;
 *   3. les trois « visuels » — dont un vrai rendu 3D Urbizen — étaient légendés
 *      « projet fictif », et la note de section affirmait que **tout** était
 *      fictif.
 *
 * Le point 3 n'est pas cosmétique : il fait dire au site l'inverse de la
 * vérité sur la provenance de ses propres images.
 *
 * Ce banc lit les sources. Il ne remplace pas un contrôle rendu — le
 * chevauchement du bouton « Agrandir » relève de la géométrie — mais il fige
 * la règle CSS qui l'écarte de la planche, et tout le reste est vérifiable ici.
 *
 * Aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-76s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

$gabarit = file_get_contents( $racine . '/wordpress/urbizen-child/templates/page-accueil-urbizen.html' );
$maquette = file_get_contents( $racine . '/frontend/homepage/index.html' );
$css      = file_get_contents( $racine . '/frontend/homepage/homepage.css' );
$js_wp    = file_get_contents( $racine . '/wordpress/urbizen-child/assets/js/urbizen-homepage.js' );
$js_front = file_get_contents( $racine . '/frontend/homepage/homepage.js' );

// La section de l'explorateur, isolée de tout le reste de l'accueil.
$dossier = '';
if ( preg_match( '#<section class="dossier-explorer" id="dossier">.*?</section>#s', $gabarit, $m ) ) {
	$dossier = $m[0];
}
check( 'La section « dossier-explorer » est présente', '' !== $dossier );

$methode = '';
if ( preg_match( '#<section class="methode" id="methode">.*?</section>#s', $gabarit, $m ) ) {
	$methode = $m[0];
}
check( 'La section « methode » est présente', '' !== $methode );

// ------------------------------------------ 1 · la loupe reste sous portée --
// scope-css.py préfixe toute la feuille par `.urbizen-accueil`. Un élément
// créé en JS et rattaché à <body> tombe hors de cette portée : ses règles ne
// s'appliquent jamais. Le défaut est invisible dans les sources CSS, qui sont
// parfaitement correctes — c'est le point d'attache qui est faux.
foreach ( array( 'thème' => $js_wp, 'maquette' => $js_front ) as $ou => $js ) {
	check( "Loupe ($ou) : rattachée sous « .urbizen-accueil », pas à <body>",
		str_contains( $js, "document.querySelector( '.urbizen-accueil' ) || document.body" )
		&& ! preg_match( '#document\.body\.appendChild\(\s*loupe\s*\)#', $js ) );
}
check( 'La feuille portée définit bien la loupe sous la portée',
	str_contains( file_get_contents( $racine . '/wordpress/urbizen-child/assets/css/urbizen-homepage.css' ),
		'.urbizen-accueil .dx-lightbox' ) );
check( 'La loupe est une boîte de dialogue modale',
	str_contains( $dossier . $js_wp, "'aria-modal', 'true'" )
	&& str_contains( $js_wp, "'role', 'dialog'" ) );
check( 'Échap ferme la loupe et le focus revient au déclencheur',
	str_contains( $js_wp, "e.key === 'Escape'" ) && str_contains( $js_wp, 'loupeOrigine.focus' ) );

// ------------------------------------------ 2 · une pièce = une fiche -------
// La référence demande, pour chaque pièce : un badge, un titre, une
// explication, deux à trois points, un encart. Trois vues sortaient sans
// encart, ce qu'aucun décompte global n'aurait vu.
$vues = preg_split( '#(?=<div class="dx-vue")#', $dossier );
array_shift( $vues );
check( 'Dix pièces, dix vues', 10 === count( $vues ), count( $vues ) . ' vue(s)' );

$sans = array();
foreach ( $vues as $vue ) {
	preg_match( '#id="(dx-v-[a-z0-9]+)"#', $vue, $id );
	$ref = $id[1] ?? '?';
	$manque = array();
	if ( ! str_contains( $vue, 'class="dx-badge"' ) )  { $manque[] = 'badge'; }
	if ( ! str_contains( $vue, '<h3>' ) )              { $manque[] = 'titre'; }
	if ( ! str_contains( $vue, 'class="dx-points"' ) ) { $manque[] = 'points'; }
	if ( ! str_contains( $vue, 'class="dx-encart"' ) ) { $manque[] = 'encart'; }
	if ( ! str_contains( $vue, '<figcaption>' ) )      { $manque[] = 'légende'; }
	if ( $manque ) { $sans[] = $ref . ' (' . implode( ', ', $manque ) . ')'; }
}
check( 'Chaque pièce porte badge, titre, points, encart et légende',
	array() === $sans, implode( ' | ', $sans ) );

// Deux à trois points par pièce : au-delà, la fiche cesse d'être lisible d'un
// regard, ce qui est sa seule raison d'être.
$hors = array();
foreach ( $vues as $vue ) {
	preg_match( '#id="(dx-v-[a-z0-9]+)"#', $vue, $id );
	$n = substr_count( $vue, '<li>' );
	if ( $n < 2 || $n > 3 ) { $hors[] = ( $id[1] ?? '?' ) . " : $n"; }
}
check( 'Deux à trois points par pièce', array() === $hors, implode( ' | ', $hors ) );

// ------------------------------------------ 3 · la provenance des images ----
// C'est le contrôle qui manquait. Trois provenances cohabitent :
//   · les planches dessinées pour l'occasion — projet fictif ;
//   · un rendu 3D réellement produit par Urbizen ;
//   · deux photographies d'illustration, pour des pièces que le demandeur
//     fournit lui-même.
// Les confondre fait dire au site le contraire de la vérité.
check( 'Le rendu 3D réel n\'est pas présenté comme un projet fictif',
	str_contains( $dossier, 'rendu 3D réalisé par Urbizen' )
	&& ! preg_match( '#conception/[^"]+\.webp".*?<figcaption>[^<]*projet fictif#s', $dossier ) );
check( 'Les pièces photographiques disent qu\'elles sont prises par le demandeur',
	2 === substr_count( $dossier, 'cette pièce est prise par vos soins' ) );
check( 'Les planches dessinées restent annoncées comme fictives',
	7 === substr_count( $dossier, 'exemple Urbizen sur un projet fictif' ) );
check( 'La note de section ne dit plus que tout le contenu est fictif',
	! str_contains( $dossier, 'Les documents montrés sont des exemples réalisés sur un projet fictif' )
	&& str_contains( $dossier, "aucune pièce n'est systématiquement exigée" ) );
// La consigne est explicite : ne jamais présenter une pièce comme toujours due.
// Le motif doit ignorer la NÉGATION portée par la note de section
// (« aucune pièce n'est systématiquement exigée »), qui dit exactement
// l'inverse de ce que ce contrôle cherche.
check( 'Aucune pièce n\'est annoncée comme systématiquement fournie',
	! preg_match( '#(?<!aucune pièce n.est )(toujours|systématiquement) (fourni|joint|exigé|inclus)#iu', $dossier ) );

// Le dépôt ne doit contenir aucun document client. Les planches sont dessinées,
// et leur cartouche le dit — c'est ce qui distingue une illustration d'une
// pièce réelle anonymisée.
$planches = glob( $racine . '/wordpress/urbizen-child/assets/images/dossier/*' );
check( 'Sept planches, toutes en SVG dessiné', 7 === count( $planches )
	&& 7 === count( preg_grep( '#\.svg$#', $planches ) ),
	implode( ', ', array_map( 'basename', $planches ) ) );
$sans_cartouche = array();
foreach ( $planches as $f ) {
	$svg = file_get_contents( $f );
	if ( ! str_contains( $svg, 'EXEMPLE URBIZEN' ) || ! str_contains( $svg, 'projet fictif' ) ) {
		$sans_cartouche[] = basename( $f );
	}
}
check( 'Chaque planche porte son cartouche « EXEMPLE URBIZEN / projet fictif »',
	array() === $sans_cartouche, implode( ', ', $sans_cartouche ) );

// ------------------------------------------ 4 · le bouton d'agrandissement --
// Aucun coin de la planche n'est libre : en bas à droite se trouve le cartouche
// « projet fictif », en haut à droite la flèche du nord. Le bouton a donc été
// sorti du cadre, sous la planche. La règle est figée ici parce que la
// remettre en `absolute` dans le cadre masquerait à nouveau la mention.
check( 'Le bouton « Agrandir » est posé sous la planche, pas dessus',
	(bool) preg_match( '#\.dx-zoom-ico \{\s*position: absolute; right: 0; top: 100%;#', $css ) );
check( 'Le bouton n\'est pas rogné par le cadre de la planche',
	(bool) preg_match( '#\.dx-zoom \{.*?overflow: visible;#s', $css ) );
check( 'La légende réserve sa droite au bouton',
	(bool) preg_match( '#\.dx-doc figcaption \{.*?padding-right: \d+px;#s', $css ) );
check( 'Le bouton est libellé, pas une pastille muette',
	10 === substr_count( $dossier, '</svg>Agrandir</span>' ) );
check( 'Chaque déclencheur nomme la pièce qu\'il agrandit',
	10 === preg_match_all( '#aria-label="Agrandir&nbsp;: [^"]+"|aria-label="Agrandir : [^"]+"#', $dossier ) );

// ------------------------------------------ 5 · le câblage ARIA -------------
check( 'Trois familles, trois panneaux',
	3 === substr_count( $dossier, 'class="dx-tab"' )
	&& 3 === substr_count( $dossier, 'class="dx-panel"' ) );
check( 'Une seule famille sélectionnée au chargement',
	1 === preg_match_all( '#class="dx-tab"[^>]*aria-selected="true"#', $dossier )
	&& 2 === preg_match_all( '#class="dx-tab"[^>]*aria-selected="false"#', $dossier ) );
check( 'Deux panneaux sur trois sont masqués au chargement',
	2 === preg_match_all( '#class="dx-panel"[^>]*hidden#', $dossier ) );
// Une vue reste visible par famille, pas une pour tout l'explorateur : trois
// panneaux, donc trois vues ouvertes et sept masquées.
check( 'Sept vues sur dix sont masquées — une reste ouverte par famille',
	7 === preg_match_all( '#class="dx-vue"[^>]*hidden#', $dossier ) );
check( 'Chaque vue est reliée à sa pièce par aria-labelledby',
	10 === preg_match_all( '#aria-labelledby="dx-i-[a-z0-9]+"#', $dossier ) );
check( 'Chaque pièce pointe sa vue par aria-controls',
	10 === preg_match_all( '#aria-controls="dx-v-[a-z0-9]+"#', $dossier ) );

// ------------------------------------------ 6 · les quatre étapes -----------
check( 'Quatre étapes, quatre panneaux',
	4 === substr_count( $methode, 'class="etape-lien"' )
	&& 4 === substr_count( $methode, 'class="etape-panel"' ) );
check( 'Trois panneaux sur quatre sont masqués au chargement',
	3 === preg_match_all( '#class="etape-panel"[^>]*hidden#', $methode ) );
check( 'Le compteur annonce « Étape 1 sur 4 » au chargement',
	str_contains( $methode, 'Étape 1 sur 4' ) );
// « etapes-point » sans délimiteur attrape aussi le conteneur « etapes-points ».
check( 'Quatre repères accompagnent le compteur',
	4 === preg_match_all( '#class="etapes-point( is-actif)?"#', $methode )
	&& 1 === preg_match_all( '#class="etapes-point is-actif"#', $methode ) );

// ------------------------------------------ 7 · les deux délais -------------
// La consigne est catégorique : les sept jours sont le délai de PRÉPARATION
// par Urbizen, jamais le délai d'instruction de la mairie. Le bloc doit donc
// porter les deux informations ensemble — le chiffre seul serait trompeur.
check( 'Le bloc de délai annonce 7 jours',
	str_contains( $methode, 'class="etape-delai"' )
	&& (bool) preg_match( '#class="etape-delai-chiffre">(<span>)?\s*7#', $methode ) );
check( 'Le délai est qualifié de délai de préparation',
	str_contains( $methode, 'DÉLAI DE PRÉPARATION' ) || str_contains( $methode, 'Délai de préparation' ) );
check( 'Le point de départ du délai est dit',
	str_contains( $methode, 'après réception de l' ) );
check( 'La distinction avec le délai d\'instruction est écrite, dans le même bloc',
	(bool) preg_match( '#ne se confond pas avec le délai d.{0,3}instruction de la mairie#u', $methode ) );
check( 'Les sept jours ne sont jamais présentés comme un délai de mairie',
	! preg_match( '#7\s*jours[^<]{0,80}(mairie|instruction)#iu', $methode ) );
// On ne promet pas une décision favorable : Urbizen prépare un dossier, la
// commune l'instruit.
check( 'Aucune promesse d\'acceptation par la mairie',
	! preg_match( '#(accept\w+|valid\w+|approu\w+|obtention)[^<]{0,40}(mairie|demande|autorisation)#iu', $methode )
	&& ! str_contains( $methode, 'garantit' ) );

// ------------------------------------------ 8 · la parité des deux copies ---
// La maquette et le gabarit sont tenus identiques sur ces deux sections : une
// divergence ferait diverger le rendu de recette et la production.
foreach ( array( 'dossier-explorer' => 'dossier', 'methode' => 'methode' ) as $classe => $id ) {
	$a = preg_match( '#<section class="' . $classe . '" id="' . $id . '">.*?</section>#s', $gabarit, $m1 ) ? $m1[0] : 'A';
	$b = preg_match( '#<section class="' . $classe . '" id="' . $id . '">.*?</section>#s', $maquette, $m2 ) ? $m2[0] : 'B';
	check( "Section « $id » : gabarit et maquette sont identiques", $a === $b );
}

// ------------------------------------------ 9 · le hors-périmètre ----------
// Une première tentative avait supprimé les trois parcours de prestation et la
// grille de sept tarifs de l'accueil, qui n'étaient pas dans le périmètre.
check( 'Les trois parcours de prestation sont intacts',
	3 === substr_count( $gabarit, 'class="service-route"' ) );
check( 'Les sept tarifs de l\'accueil sont intacts',
	7 === substr_count( $gabarit, 'À partir de' ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
