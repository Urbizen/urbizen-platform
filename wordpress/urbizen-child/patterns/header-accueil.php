<?php
/**
 * Title: En-tête Urbizen (accueil)
 * Slug: urbizen-child/header-accueil
 * Categories: header
 * Inserter: no
 *
 * Markup repris à l'identique de frontend/homepage/index.html, lignes 77 à 110.
 * Seule différence : l'URL du logo, résolue par get_theme_file_uri(). Un
 * fichier .html de gabarit n'exécute pas PHP, d'où ce pattern — les patterns
 * de thème sont des fichiers PHP par conception.
 *
 * Aucun attribut width/height sur le logo : mesuré en conditions réelles, les
 * ajouter donne à l'image un rapport d'aspect définitif qui change le calcul
 * flex de l'en-tête — le logo passait de 109 à 290 px et le menu perdait
 * 135 px, ses libellés basculant sur deux lignes. La maquette s'appuie sur la
 * compression du logo par flex-shrink : on ne la contrarie pas.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;
?>
<!-- wp:html -->
<header class="site" id="top">
  <div class="wrap nav">
    <a class="logo" href="#top" aria-label="Urbizen — accueil">
      <img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo-urbizen.png' ) ); ?>"
           alt="Urbizen · urbanisme & projets" />
    </a>
    <nav class="nav-links" aria-label="Navigation principale">
      <a href="https://urbizen.fr/declarations-prealables/">Déclaration préalable</a>
      <a href="https://urbizen.fr/permis-de-construire/">Permis de construire</a>
      <a href="#prestations">Nos prestations</a>
      <a href="#methode">Comment ça marche</a>
      <a href="#tarifs">Tarifs</a>
      <a href="#faq">Questions fréquentes</a>
    </nav>
    <div class="nav-right">
      <a class="link-login" href="#" title="Espace client — bientôt disponible">Se connecter</a>
      <a class="btn btn-primary btn-sm js-start" href="#localisation">Démarrer mon projet</a>
      <button class="burger" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="mmenu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
  <div id="mmenu" class="mmenu" hidden>
    <div class="wrap">
      <a href="https://urbizen.fr/declarations-prealables/">Déclaration préalable</a>
      <a href="https://urbizen.fr/permis-de-construire/">Permis de construire</a>
      <a href="#prestations">Nos prestations</a>
      <a href="#methode">Comment ça marche</a>
      <a href="#tarifs">Tarifs</a>
      <a href="#faq">Questions fréquentes</a>
      <a href="#" class="link-login">Se connecter</a>
      <a class="btn btn-primary js-start" href="#localisation">Démarrer mon projet</a>
    </div>
  </div>
</header>
<!-- /wp:html -->
