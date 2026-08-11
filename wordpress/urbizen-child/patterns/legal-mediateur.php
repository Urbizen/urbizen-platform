<?php
/**
 * Title: Médiateur de la consommation
 * Slug: urbizen-child/legal-mediateur
 * Categories: text
 * Inserter: no
 *
 * Coordonnées du médiateur de la consommation, lues depuis la source commune.
 *
 * POURQUOI UN PATTERN
 *
 * Même discipline que `legal-identite` et `legal-assurance` : une adresse
 * recopiée dans un gabarit `.html` survit au changement de médiateur. Elle
 * n'existe donc qu'à un seul endroit, et un banc vérifie qu'aucune de ces
 * valeurs n'atteint un gabarit.
 *
 * CE QU'IL FAIT QUAND LE MÉDIATEUR EST INCONNU
 *
 * Rien. Le bloc entier disparaît. C'est ce qui a permis de publier la section
 * « Médiation » des CGV — qui énonce le droit du client, obligatoire — sans
 * inventer de médiateur ni écrire « sera désigné », formule qui ne satisfait
 * pas l'article L.616-1 du code de la consommation.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$u = urbizen_child_donnees_legales();

if ( null === $u['mediateur'] ) {
	return;
}

$m = $u['mediateur'];
?>
<!-- wp:html -->
<div class="legal-mediateur">
  <dl class="legal-fiche">
    <div class="legal-fiche-ligne">
      <dt>Médiateur</dt>
      <dd><strong><?php echo esc_html( $m['sigle'] ); ?></strong> — <?php echo esc_html( $m['nom'] ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Adresse</dt>
      <dd><?php echo wp_kses_post( implode( '<br />', array_map( 'esc_html', $m['adresse'] ) ) ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Site</dt>
      <dd><a href="<?php echo esc_url( $m['site'] ); ?>" rel="noopener"><?php echo esc_html( preg_replace( '#^https?://#', '', $m['site'] ) ); ?></a></dd>
    </div>
  </dl>
</div>
<!-- /wp:html -->
