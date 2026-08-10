<?php
/**
 * Title: Grille tarifaire Urbizen
 * Slug: urbizen-child/tarifs-grille
 * Categories: text
 * Inserter: no
 *
 * POURQUOI CE PATTERN EXISTE
 *
 * Un gabarit `.html` de thème de blocs n'exécute pas de PHP. Or les montants
 * affichés ici doivent venir d'un seul endroit — `urbizen_child_tarifs()` —
 * et non d'une énième recopie à la main : c'est exactement le problème que ce
 * lot referme. Le pattern est le mécanisme que le thème emploie déjà quand un
 * gabarit a besoin de PHP (voir `header-accueil.php`, qui résout l'URL du logo
 * de la même façon). Rien de neuf n'est introduit.
 *
 * CE QU'IL NE FAIT PAS
 *
 * Aucun style propre. Le vocabulaire visuel est celui de la section Tarifs de
 * l'accueil — `.tarif-group`, `.tarif`, `.tarif-price`, `.tarif-popular`,
 * `.tarif-supplement-global` — porté par `urbizen-homepage.css`. La page
 * Tarifs se contente d'en régler l'échelle dans `urbizen-pages.css`, sous
 * `.urbizen-page-tarifs`. Les cartes sont donc, au pixel près, celles que le
 * visiteur a déjà vues sur l'accueil.
 *
 * Les chaînes du catalogue sont des fragments rédigés dans le thème lui-même,
 * jamais des saisies : `wp_kses_post()` les laisse passer entités comprises
 * (`&nbsp;`, `&amp;`) sans les ré-échapper, et referme malgré tout la porte.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$urbizen_tarifs = urbizen_child_tarifs();
$urbizen_inclus = $urbizen_tarifs['inclusions'];
?>
<!-- wp:html -->
<div class="tarif-groups">
<?php foreach ( $urbizen_tarifs['groupes'] as $groupe ) : ?>
  <section class="tarif-group tarif-group-<?php echo esc_attr( $groupe['id'] ); ?>" aria-labelledby="tarifs-<?php echo esc_attr( $groupe['id'] ); ?>-titre">
    <header class="tarif-group-head">
      <span class="tarif-group-ref" aria-hidden="true"><?php echo esc_html( $groupe['ref'] ); ?></span>
      <div>
        <h3 id="tarifs-<?php echo esc_attr( $groupe['id'] ); ?>-titre"><?php echo wp_kses_post( $groupe['titre'] ); ?></h3>
        <p><?php echo wp_kses_post( $groupe['accroche'] ); ?></p>
      </div>
    </header>
    <div class="tarif-offers tarif-offers-3">
<?php foreach ( $groupe['offres'] as $offre ) : ?>
      <article class="tarif<?php echo empty( $offre['populaire'] ) ? '' : ' featured'; ?><?php echo empty( $offre['premium'] ) ? '' : ' tarif-premium'; ?>">
<?php if ( ! empty( $offre['populaire'] ) ) : ?>
        <span class="tarif-popular">Le plus demandé</span>
<?php endif; ?>
        <span class="tarif-type"><?php echo wp_kses_post( $groupe['kicker'] ); ?></span>
        <h4><?php echo wp_kses_post( $offre['nom'] ); ?></h4>
        <?php /* Le prix vient AVANT les exemples. Placé après, il descendait
                d'une ou deux lignes selon la longueur de la liste d'exemples,
                et les trois montants d'une même ligne ne s'alignaient plus —
                le défaut le plus visible qui soit sur une page de tarifs. */ ?>
        <div class="tarif-price"><span class="tarif-from">À partir de</span><?php echo (int) $offre['prix']; ?>&nbsp;€</div>
        <span class="tarif-detail"><?php echo wp_kses_post( $offre['exemples'] ); ?></span>
        <p class="tarif-texte"><?php echo wp_kses_post( $offre['texte'] ); ?></p>
        <ul class="tarif-inclus">
<?php foreach ( $urbizen_inclus as $inclusion ) : ?>
          <li><?php echo wp_kses_post( $inclusion ); ?></li>
<?php endforeach; ?>
        </ul>
        <a class="btn <?php echo empty( $offre['populaire'] ) ? 'btn-ghost' : 'btn-primary'; ?> btn-sm tarif-cta" href="<?php echo esc_url( $groupe['cta'] ); ?>"><?php echo esc_html( $groupe['cta_libelle'] ); ?></a>
      </article>
<?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<?php $urbizen_conception = $urbizen_tarifs['conception']; ?>
<section class="tarif-group tarif-group-<?php echo esc_attr( $urbizen_conception['id'] ); ?> tarif-group-solo" aria-labelledby="tarifs-<?php echo esc_attr( $urbizen_conception['id'] ); ?>-titre">
  <header class="tarif-group-head">
    <span class="tarif-group-ref" aria-hidden="true"><?php echo esc_html( $urbizen_conception['ref'] ); ?></span>
    <div>
      <h3 id="tarifs-<?php echo esc_attr( $urbizen_conception['id'] ); ?>-titre"><?php echo wp_kses_post( $urbizen_conception['titre'] ); ?></h3>
      <p><?php echo wp_kses_post( $urbizen_conception['accroche'] ); ?></p>
    </div>
  </header>
  <div class="tarif-offers tarif-offers-1">
    <article class="tarif tarif-large">
      <div>
        <span class="tarif-type"><?php echo wp_kses_post( $urbizen_conception['kicker'] ); ?></span>
        <h4>Conception personnalisée</h4>
        <p class="tarif-texte"><?php echo wp_kses_post( $urbizen_conception['texte'] ); ?></p>
      </div>
      <div class="tarif-large-aside">
        <div class="tarif-price"><span class="tarif-from">À partir de</span><?php echo (int) $urbizen_conception['prix']; ?>&nbsp;€</div>
        <a class="btn btn-primary btn-sm tarif-cta" href="<?php echo esc_url( $urbizen_conception['cta'] ); ?>"><?php echo esc_html( $urbizen_conception['cta_libelle'] ); ?></a>
      </div>
    </article>
  </div>
</section>

<?php $urbizen_abf = $urbizen_tarifs['abf']; ?>
<aside class="tarif-supplement-global">
  <span class="tarif-supplement-mark" aria-hidden="true">ABF</span>
  <div>
    <strong><?php echo wp_kses_post( $urbizen_abf['titre'] ); ?></strong>
    <span><?php echo wp_kses_post( $urbizen_abf['texte'] ); ?></span>
  </div>
  <b>+<?php echo (int) $urbizen_abf['montant']; ?>&nbsp;€</b>
</aside>
<!-- /wp:html -->
