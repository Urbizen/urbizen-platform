<?php
/**
 * Title: Navigation entre documents légaux
 * Slug: urbizen-child/legal-navigation
 * Categories: text
 * Inserter: no
 *
 * Navigation discrète entre les trois documents légaux, placée en bas de
 * chacun d'eux.
 *
 * Le document courant est marqué `aria-current="page"` et n'est pas cliquable :
 * un lien qui ramène à la page qu'on lit est un lien mort déguisé.
 *
 * Les URL sont celles réellement en service. Les deux adresses héritées d'un
 * thème WooCommerce — `/refund_returns/` et `/privacy-policy/` — ont été
 * migrées le 13 août 2026 vers des adresses lisibles, directement dans
 * WordPress. Les identifiants de page n'ont pas changé (26 et 3), les gabarits
 * non plus.
 *
 * Aucune redirection 301 n'a été mise en place : décision de la propriétaire,
 * le site ayant encore très peu de trafic. Les anciennes adresses répondent
 * donc 404. C'est ce qui rend la mise à jour de ce tableau nécessaire et non
 * cosmétique — le pied de page a pointé vers deux liens morts entre la
 * migration et cette correction.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$urbizen_docs = array(
	'mentions'         => array( '/mentions-legales/', 'Mentions légales' ),
	'cgv'              => array( '/conditions-generales-de-vente/', 'Conditions générales de vente' ),
	'confidentialite'  => array( '/politique-de-confidentialite/', 'Politique de confidentialité' ),
);

// Le gabarit courant sert à repérer le document affiché, sans dépendre de
// l'URL : c'est le gabarit qui décide de ce qui est rendu.
$urbizen_gabarit = is_singular() ? (string) get_page_template_slug( get_queried_object_id() ) : '';
$urbizen_courant = array(
	'page-mentions-legales' => 'mentions',
	'page-cgv'              => 'cgv',
	'page-confidentialite'  => 'confidentialite',
)[ $urbizen_gabarit ] ?? '';
?>
<!-- wp:html -->
<nav class="legal-nav" aria-label="Documents légaux">
  <span class="legal-nav-titre">Documents légaux</span>
  <ul>
<?php foreach ( $urbizen_docs as $cle => $doc ) : ?>
<?php if ( $cle === $urbizen_courant ) : ?>
    <li><span class="legal-nav-courant" aria-current="page"><?php echo esc_html( $doc[1] ); ?></span></li>
<?php else : ?>
    <li><a href="<?php echo esc_url( $doc[0] ); ?>"><?php echo esc_html( $doc[1] ); ?></a></li>
<?php endif; ?>
<?php endforeach; ?>
  </ul>
</nav>
<!-- /wp:html -->
