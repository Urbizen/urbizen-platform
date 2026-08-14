<?php
/**
 * Title: En-tête Urbizen (accueil)
 * Slug: urbizen-child/header-accueil
 * Categories: header
 * Inserter: no
 *
 * Markup repris à l'identique de frontend/homepage/index.html, lignes 77 à 110.
 * Deux différences, résolues en PHP (un .html de gabarit n'exécute pas PHP,
 * d'où ce pattern) :
 *   1. l'URL du logo (`get_theme_file_uri()`) ;
 *   2. le lien du logo : `#top` (défilement) sur l'accueil, mais l'URL de
 *      l'accueil (`home_url('/')`) sur les pages internes, qui partagent cet
 *      en-tête — sans quoi le logo n'y renvoie nulle part.
 *
 * Aucun attribut width/height sur le logo : mesuré en conditions réelles, les
 * ajouter donne à l'image un rapport d'aspect définitif qui change le calcul
 * flex de l'en-tête — le logo passait de 109 à 290 px et le menu perdait
 * 135 px, ses libellés basculant sur deux lignes. La maquette s'appuie sur la
 * compression du logo par flex-shrink : on ne la contrarie pas.
 *
 * LE LOGO : `loading="eager"`, ET AUCUNE DIMENSION
 *
 * WordPress ajoute `loading="lazy"` selon un comptage interne
 * (`wp_omit_loading_attr_threshold`) : mesuré, le logo le recevait sur
 * /tarifs/ et pas ailleurs, alors qu'il est au-dessus de la ligne de
 * flottaison partout. Le déclarer ici rend le comportement identique sur
 * toutes les pages.
 *
 * Pas de `fetchpriority` : le logo n'est le plus grand élément peint sur aucune
 * page, et le prioriser prendrait la bande passante de ce qui l'est.
 *
 * Pas de `width` ni de `height` non plus — posés puis retirés le 14 août 2026.
 * La feuille ne fixe que la hauteur et laisse la largeur libre ; sans règle CSS
 * sur `width`, l'attribut sert d'indication de présentation et l'emporte. Le
 * logo est passé de 129 × 36 à 430 × 36 en desktop, et celui du pied de 122 à
 * 328 : étirés, déformés, en production. Le décalage que ces attributs
 * préviennent n'existe pas ici, la hauteur étant déjà fixée en CSS.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

/*
 * Préfixe des liens d'ancre : vide sur l'accueil (défilement en page), URL de
 * l'accueil ailleurs. Les pages internes partagent cet en-tête ; sans ce
 * préfixe, « Nos prestations », « Tarifs », etc. ne mèneraient nulle part.
 */
$pfx = is_front_page() ? '' : esc_url( home_url( '/' ) );

/*
 * Destination d'« Accueil » — et du logo, qui joue le même rôle : le sommet de
 * la page sur l'accueil, l'accueil ailleurs. La règle est écrite une fois ; la
 * dupliquer sur trois liens était le moyen sûr de les laisser diverger.
 */
$accueil = is_front_page() ? '#top' : esc_url( home_url( '/' ) );

/*
 * Page courante — `aria-current="page"`, que le CSS se contente de rendre
 * visible. L'état est ainsi dans le balisage, donc annoncé aux lecteurs
 * d'écran, et non pas seulement peint.
 *
 * L'ordre du ternaire n'est pas indifférent : sur l'accueil, la branche courte
 * est prise et NI `is_singular()` NI `get_permalink()` ne sont appelées. Le
 * banc de fidélité rend ce pattern hors de WordPress, avec pour seul doublon
 * `is_front_page()` ; l'y faire appeler une fonction de plus le casserait sans
 * rien apporter, l'accueil n'ayant de toute façon aucune URL à comparer.
 */
$courant = is_front_page() ? '' : ( is_singular() ? get_permalink() : '' );

/** Marque l'entrée dont l'URL est celle de la page ouverte. */
$actif = static function ( $url ) use ( $courant ) {
	if ( ! $courant || untrailingslashit( $url ) !== untrailingslashit( $courant ) ) {
		return '';
	}
	return ' aria-current="page"';
};

$url_dp         = 'https://urbizen.fr/declarations-prealables/';
$url_pc         = 'https://urbizen.fr/permis-de-construire/';
$url_conception = 'https://urbizen.fr/conception/';

// Le groupe s'allume dès que l'une de ses trois pages est ouverte : sinon, sur
// /conception/, le menu resterait muet sur l'endroit où l'on se trouve.
$classe_parent = ( $actif( $url_dp ) || $actif( $url_pc ) || $actif( $url_conception ) )
	? 'nav-parent is-actif' : 'nav-parent';
?>
<!-- wp:html -->
<header class="site" id="top">
  <div class="wrap nav">
    <a class="logo" href="<?php echo $accueil; ?>" aria-label="Urbizen — accueil">
      <img src="<?php echo esc_url( get_theme_file_uri( 'assets/img/logo-urbizen.png' ) ); ?>" loading="eager" decoding="async" alt="Urbizen · urbanisme & projets" />
    </a>
    <nav class="nav-links" aria-label="Navigation principale">
      <a href="<?php echo $accueil; ?>"<?php echo is_front_page() ? ' aria-current="page"' : ''; ?>>Accueil</a>
      <div class="nav-groupe">
        <button type="button" class="<?php echo $classe_parent; ?>" aria-expanded="false" aria-controls="sous-menu-prestations">Nos prestations<svg class="nav-parent-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 9.5l6 6 6-6"/></svg></button>
        <div class="nav-sous-menu" id="sous-menu-prestations" hidden>
          <a href="<?php echo $url_dp; ?>"<?php echo $actif( $url_dp ); ?>>Déclaration préalable</a>
          <a href="<?php echo $url_pc; ?>"<?php echo $actif( $url_pc ); ?>>Permis de construire</a>
          <a href="<?php echo $url_conception; ?>"<?php echo $actif( $url_conception ); ?>>Conception de plans</a>
        </div>
      </div>
      <a href="https://urbizen.fr/tarifs/"<?php echo $actif( 'https://urbizen.fr/tarifs/' ); ?>>Tarifs</a>
      <span class="nav-bientot" aria-disabled="true" title="Espace client — bientôt disponible">Espace client<span class="nav-tag">bientôt</span></span>
      <a href="https://urbizen.fr/contact/"<?php echo $actif( 'https://urbizen.fr/contact/' ); ?>>Contact</a>
    </nav>
    <div class="nav-right">
      <button type="button" class="icon-btn link-tel" aria-label="Nous contacter" title="Nous contacter" aria-haspopup="dialog" aria-expanded="false" aria-controls="contact-panel">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M15.5 21a13.5 13.5 0 0 1-12.5-12.5A2 2 0 0 1 5 6.4h2.2a1.4 1.4 0 0 1 1.4 1.2c.1.9.3 1.8.6 2.6a1.4 1.4 0 0 1-.32 1.5l-1 1a11 11 0 0 0 4.9 4.9l1-1a1.4 1.4 0 0 1 1.5-.32c.8.3 1.7.5 2.6.6a1.4 1.4 0 0 1 1.2 1.42V19a2 2 0 0 1-2.1 2z"/></svg>
      </button>
      <button type="button" class="icon-btn link-login" aria-label="Espace client (bientôt disponible)" title="Espace client — bientôt disponible" aria-disabled="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.6"/><path d="M5.5 19.5a6.5 6.5 0 0 1 13 0"/></svg>
      </button>
      <a class="btn btn-primary btn-sm js-start" href="<?php echo $pfx; ?>#localisation" aria-label="Démarrer mon projet"><span class="nav-cta-long">Démarrer mon projet</span><span class="nav-cta-short" aria-hidden="true">Démarrer</span></a>
      <button type="button" class="burger" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="mmenu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </div>
  <div id="contact-panel" class="contact-panel" role="dialog" aria-modal="true" aria-labelledby="contact-panel-title" hidden>
    <div class="contact-panel-inner">
      <div class="contact-head">
        <h2 id="contact-panel-title">Parlons de votre projet</h2>
        <button type="button" class="contact-close" aria-label="Fermer">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
      </div>
      <ul class="contact-channels">
        <li class="contact-ch">
          <a class="contact-ch-link" href="tel:+33664895815">
            <span class="contact-ch-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15.5 21a13.5 13.5 0 0 1-12.5-12.5A2 2 0 0 1 5 6.4h2.2a1.4 1.4 0 0 1 1.4 1.2c.1.9.3 1.8.6 2.6a1.4 1.4 0 0 1-.32 1.5l-1 1a11 11 0 0 0 4.9 4.9l1-1a1.4 1.4 0 0 1 1.5-.32c.8.3 1.7.5 2.6.6a1.4 1.4 0 0 1 1.2 1.42V19a2 2 0 0 1-2.1 2z"/></svg></span>
          <span class="contact-ch-txt"><span class="contact-ch-title">Appeler maintenant</span><span class="contact-ch-sub">+33 6 64 89 58 15</span></span>
          </a>
        </li>
        <li class="contact-ch is-soon">
          <span class="contact-ch-link" role="button" aria-disabled="true">
            <span class="contact-ch-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 9.5h16M8 3v4M16 3v4"/></svg></span>
          <span class="contact-ch-txt"><span class="contact-ch-title">Réserver un appel</span><span class="contact-ch-sub">Bientôt disponible</span></span>
          </span>
        </li>
        <li class="contact-ch">
          <a class="contact-ch-link js-open-inquiry" href="<?php echo $pfx; ?>#demander-des-renseignements">
            <span class="contact-ch-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 6.5l8 6 8-6"/></svg></span>
          <span class="contact-ch-txt"><span class="contact-ch-title">Écrire à Urbizen</span><span class="contact-ch-sub">Réponse sous 24 h ouvrées</span></span>
          </a>
        </li>
      </ul>
    </div>
  </div>
  <div id="mmenu" class="mmenu" hidden>
    <div class="wrap">
      <a href="<?php echo $accueil; ?>"<?php echo is_front_page() ? ' aria-current="page"' : ''; ?>>Accueil</a>
      <p class="mmenu-groupe">Nos prestations</p>
      <a class="mmenu-enfant" href="<?php echo $url_dp; ?>"<?php echo $actif( $url_dp ); ?>>Déclaration préalable</a>
      <a class="mmenu-enfant" href="<?php echo $url_pc; ?>"<?php echo $actif( $url_pc ); ?>>Permis de construire</a>
      <a class="mmenu-enfant" href="<?php echo $url_conception; ?>"<?php echo $actif( $url_conception ); ?>>Conception de plans</a>
      <a href="https://urbizen.fr/tarifs/"<?php echo $actif( 'https://urbizen.fr/tarifs/' ); ?>>Tarifs</a>
      <span class="mmenu-bientot" aria-disabled="true">Espace client<span class="nav-tag">bientôt</span></span>
      <a href="https://urbizen.fr/contact/"<?php echo $actif( 'https://urbizen.fr/contact/' ); ?>>Contact</a>
      <a class="js-open-inquiry" href="<?php echo $pfx; ?>#demander-des-renseignements">Écrire à Urbizen</a>
      <a class="btn btn-primary js-start" href="<?php echo $pfx; ?>#localisation">Démarrer mon projet</a>
    </div>
  </div>
</header>
<!-- /wp:html -->
