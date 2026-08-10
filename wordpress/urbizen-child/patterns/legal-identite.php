<?php
/**
 * Title: Identité légale Urbizen
 * Slug: urbizen-child/legal-identite
 * Categories: text
 * Inserter: no
 *
 * Bloc d'identité de l'éditeur, partagé par les trois documents légaux.
 *
 * POURQUOI UN PATTERN
 *
 * Ces informations figurent dans les mentions légales, dans les CGV et dans la
 * politique de confidentialité. Recopiées dans trois gabarits, elles finissent
 * par diverger — c'est précisément ce qui est arrivé aux tarifs, restés à
 * 149 € dans les CGV longtemps après leur passage à 189 €. Un gabarit `.html`
 * n'exécutant pas de PHP, le pattern est le mécanisme déjà employé par le
 * thème pour ce besoin.
 *
 * CE QU'IL NE FAIT JAMAIS
 *
 * Afficher une donnée absente. `urbizen_child_donnees_legales()` renvoie
 * `null` pour tout ce qui n'est pas vérifié, et chaque ligne ci-dessous est
 * conditionnée. Aucune mention « à compléter » ne peut donc atteindre une page
 * publique : la ligne disparaît, et le contrôle de préparation la signale.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$u = urbizen_child_donnees_legales();
?>
<!-- wp:html -->
<div class="legal-identite">
  <dl class="legal-fiche">
    <div class="legal-fiche-ligne">
      <dt>Éditeur</dt>
      <dd><strong><?php echo esc_html( $u['entrepreneur'] ); ?></strong>, <?php echo esc_html( $u['forme'] ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Nom commercial</dt>
      <dd><?php echo esc_html( $u['nom_commercial'] ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Adresse</dt>
      <dd><?php echo wp_kses_post( implode( '<br />', array_map( 'esc_html', $u['adresse'] ) ) ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>SIREN</dt>
      <dd class="legal-mono"><?php echo esc_html( $u['siren'] ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>SIRET</dt>
      <dd class="legal-mono"><?php echo esc_html( $u['siret'] ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Immatriculation</dt>
      <dd class="legal-mono"><?php echo esc_html( $u['rcs'] ); ?></dd>
    </div>
<?php if ( null !== $u['tva_numero'] ) : ?>
    <div class="legal-fiche-ligne">
      <dt>TVA intracommunautaire</dt>
      <dd class="legal-mono"><?php echo esc_html( $u['tva_numero'] ); ?></dd>
    </div>
<?php endif; ?>
    <div class="legal-fiche-ligne">
      <dt>Courriel</dt>
      <dd><a href="mailto:<?php echo esc_attr( $u['email'] ); ?>"><?php echo esc_html( $u['email'] ); ?></a></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Téléphone</dt>
      <dd><a href="tel:<?php echo esc_attr( $u['telephone_lien'] ); ?>"><?php echo esc_html( $u['telephone'] ); ?></a></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Site</dt>
      <dd><a href="<?php echo esc_url( $u['site'] ); ?>"><?php echo esc_html( $u['site'] ); ?></a></dd>
    </div>
  </dl>
</div>
<!-- /wp:html -->
