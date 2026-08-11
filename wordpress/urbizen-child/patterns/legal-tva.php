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
 * réserve ni une incertitude : c'est un régime établi. Le texte l'énonce donc
 * au présent et sans précaution, là où la rédaction précédente renvoyait au
 * devis faute de régime confirmé.
 *
 * AUCUNE RÉFÉRENCE RÉGLEMENTAIRE, ET C'EST VOLONTAIRE
 *
 * La référence à citer sur les factures en franchise en base change au
 * 1er septembre 2026. Une page légale est permanente : y inscrire un article de
 * loi, c'est programmer une inexactitude datée. Le paragraphe énonce donc le
 * régime et sa conséquence — deux choses qui ne changent pas — et renvoie la
 * mention réglementaire au devis et à la facture, où elle s'apprécie à la date
 * d'émission du document.
 *
 * Le texte vient de `urbizen_child_donnees_legales()` : le recopier ici le
 * ferait diverger de la fiche d'identité, qui l'affiche déjà.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$u = urbizen_child_donnees_legales();
?>
<!-- wp:html -->
<p>Les prix en vigueur sont consultables sur la page <a href="/tarifs/">Tarifs</a>. Ils sont exprimés en euros.</p>
<?php if ( null !== $u['tva'] ) : ?>
<p>Urbizen relève du régime de la <strong><?php echo esc_html( strtolower( $u['tva']['regime'] ) ); ?></strong>. <?php echo esc_html( $u['tva']['effet'] ); ?></p>
<p>Les devis et factures portent la mention réglementaire applicable à leur date d'émission.</p>
<?php endif; ?>
<!-- /wp:html -->
