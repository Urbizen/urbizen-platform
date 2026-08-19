<?php
/**
 * Title: En-tête d'un guide Urbizen
 * Slug: urbizen-child/guide-entete
 * Categories: header
 * Inserter: no
 *
 * Fil d'ariane, catégorie, date, titre et visuel d'un article.
 *
 * AUCUN LIEN VERS UNE ARCHIVE D'AUTEUR
 *
 * L'archive d'auteur est volontairement en 404 depuis le lot A, et le lot E a
 * nettoyé le graphe JSON-LD de toute valeur qui la désignait. Ce gabarit
 * n'affiche donc pas d'auteur du tout : afficher un nom sans lien inviterait
 * quelqu'un à le rendre cliquable un jour, et l'archive reviendrait par la
 * bande.
 *
 * LE FIL D'ARIANE
 *
 * Écrit ici, en `.fil-ariane` — la même classe et la même forme que sur les
 * pages commerciales. Il double le `BreadcrumbList` qu'AIOSEO émet en JSON-LD
 * (dont le premier élément s'appelle « Accueil » depuis le lot E) : l'un est
 * lu par les moteurs, l'autre par les visiteurs, et ils disent la même chose.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$id_guide = get_the_ID();

/*
 * Le maillon « Guides » ne pointe vers l'index que si celui-ci existe. Tant que
 * `page_for_posts` vaut 0, le fil s'arrête à l'accueil plutôt que de proposer
 * un lien vers une page absente — un fil d'ariane cassé est pire qu'un fil
 * court.
 */
$id_index  = (int) get_option( 'page_for_posts' );
$url_index = $id_index ? get_permalink( $id_index ) : '';
$nom_index = $id_index ? get_the_title( $id_index ) : 'Guides d’urbanisme';

$categorie = '';
$url_cat   = '';

foreach ( (array) get_the_category( $id_guide ) as $terme ) {
	if ( 'non-classe' !== $terme->slug ) {
		$categorie = $terme->name;
		$url_cat   = get_category_link( $terme->term_id );
		break;
	}
}
?>
<!-- wp:html -->
<section class="page-hero guide-hero">
  <div class="wrap">
    <nav class="fil-ariane" aria-label="Fil d'ariane">
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Accueil</a>
      <span class="sep">›</span>
	  <?php if ( '' !== $url_index ) : ?>
      <a href="<?php echo esc_url( $url_index ); ?>"><?php echo esc_html( $nom_index ); ?></a>
      <span class="sep">›</span>
	  <?php endif; ?>
      <?php echo esc_html( wp_trim_words( get_the_title( $id_guide ), 8, '…' ) ); ?>
    </nav>
    <div class="guide-hero-meta">
	  <?php if ( '' !== $categorie ) : ?>
      <a class="guide-hero-cat" href="<?php echo esc_url( $url_cat ); ?>"><?php echo esc_html( $categorie ); ?></a>
	  <?php endif; ?>
      <span class="guide-hero-date">Publié le <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'j F Y' ) ); ?></time></span>
    </div>
    <h1><?php echo esc_html( get_the_title( $id_guide ) ); ?></h1>
	<?php if ( '' !== get_the_excerpt() ) : ?>
    <p class="lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
	<?php endif; ?>
  </div>
</section>
<?php if ( has_post_thumbnail( $id_guide ) ) : ?>
<div class="wrap">
  <figure class="guide-visuel<?php echo urbizen_child_visuel_entier( $id_guide ) ? ' guide-visuel--planche' : ''; ?>">
	<?php
	/*
	 * Le visuel d'article relève de la largeur ÉDITORIALE — le jeton
	 * `--u-guide-large`, soit 65rem = 1040 px — et non de la colonne de lecture :
	 * c'est un objet qu'on regarde, pas qu'on lit. Le `sizes` suit : 1120 px est
	 * le point où le `.wrap` cesse de contraindre la figure (1040 + 2 × 40 px de
	 * gouttière), au-delà duquel elle est fixe. Un `sizes` faux sur ce visuel se
	 * paie cher : c'est le plus grand élément peint de la page.
	 *
	 * `eager` et `fetchpriority="high"` restent : ils évitent que le navigateur
	 * le découvre tard.
	 */
	echo get_the_post_thumbnail(
		$id_guide,
		'large',
		array(
			'loading'       => 'eager',
			'decoding'      => 'async',
			'fetchpriority' => 'high',
			'sizes'         => '(max-width: 1120px) 92vw, 1040px',
		)
	);
	?>
  </figure>
</div>
<?php endif; ?>
<!-- /wp:html -->
