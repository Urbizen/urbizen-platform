<?php
/**
 * Title: Régime de TVA applicable aux prix
 * Slug: urbizen-child/legal-tva
 * Categories: text
 * Inserter: no
 *
 * Paragraphe de régime fiscal des CGV, rendu depuis la source commune.
 *
 * POURQUOI CE PARAGRAPHE EST ÉCRIT AINSI
 *
 * Le prix doit être annoncé sans équivoque. La franchise en base n'est pas une
 * réserve ni une incertitude : c'est un régime établi, qui impose une mention
 * précise (CGI, art. 293 B). Le texte l'énonce donc au présent et sans
 * précaution — « les prix ne sont pas soumis à la TVA » —, là où la rédaction
 * précédente renvoyait au devis faute de régime confirmé.
 *
 * La mention légale elle-même vient de `urbizen_child_donnees_legales()` : la
 * recopier ici la ferait diverger de la fiche d'identité, qui l'affiche déjà.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$u = urbizen_child_donnees_legales();
?>
<!-- wp:html -->
<p>Les prix en vigueur sont consultables sur la page <a href="/tarifs/">Tarifs</a>. Ils sont exprimés en euros.</p>
<?php if ( null !== $u['tva'] ) : ?>
<p>Urbizen relève du régime de la <strong><?php echo esc_html( strtolower( $u['tva']['regime'] ) ); ?></strong>&nbsp;: les prix annoncés ne sont pas soumis à la TVA et aucune taxe ne s'ajoute au montant indiqué. Les devis et factures portent la mention «&nbsp;<?php echo esc_html( $u['tva']['mention'] ); ?>&nbsp;».</p>
<?php endif; ?>
<!-- /wp:html -->
