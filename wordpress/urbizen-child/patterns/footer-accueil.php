<?php
/**
 * Title: Pied de page Urbizen (accueil)
 * Slug: urbizen-child/footer-accueil
 * Categories: footer
 * Inserter: no
 *
 * Markup repris à l'identique de frontend/homepage/index.html, lignes 412 à 447.
 * Seule différence : l'URL du logo, résolue par get_theme_file_uri().
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:html -->
<footer class="site-footer">
  <div class="wrap foot">
    <div class="foot-brand">
      <img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo-urbizen.png' ) ); ?>"
           alt="Urbizen" class="foot-logo" />
      <p>Dossiers d'urbanisme à distance : déclaration préalable, permis de construire, CERFA, plans, insertions paysagères et pièces graphiques prêts à déposer. Partout en France métropolitaine.</p>
    </div>
    <div>
      <h4>Prestations</h4>
      <ul>
        <li><a href="https://urbizen.fr/declarations-prealables/">Déclaration préalable</a></li>
        <li><a href="https://urbizen.fr/permis-de-construire/">Permis de construire</a></li>
        <li><a href="#tarifs">Tarifs</a></li>
      </ul>
    </div>
    <div>
      <h4>Contact</h4>
      <ul>
        <li><a href="mailto:contact@urbizen.fr">contact@urbizen.fr</a></li>
        <li><a href="tel:+33664895815">+33 6 64 89 58 15</a></li>
        <li><a href="#" title="Espace client — bientôt disponible">Espace client (bientôt)</a></li>
      </ul>
    </div>
    <div>
      <h4>Informations</h4>
      <ul>
        <li><a href="https://urbizen.fr/mentions-legales/">Mentions légales</a></li>
        <li><a href="https://urbizen.fr/privacy-policy/">Politique de confidentialité</a></li>
        <li><a href="https://urbizen.fr/refund_returns/">Conditions générales de service</a></li>
      </ul>
    </div>
  </div>
  <div class="wrap foot-bottom">
    <span>© 2026 Urbizen — Urbanisme &amp; projets</span>
    <span class="mono">urbizen.fr</span>
  </div>
</footer>
<!-- /wp:html -->
