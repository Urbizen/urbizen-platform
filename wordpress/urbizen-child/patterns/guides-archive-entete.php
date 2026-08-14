<?php
/**
 * Title: En-tête d'archive des guides Urbizen
 * Slug: urbizen-child/guides-archive-entete
 * Categories: header
 * Inserter: no
 *
 * Fil d'ariane et titre d'une archive de catégorie.
 *
 * POURQUOI PAS `core/query-title`
 *
 * Ce bloc préfixe le nom du terme — « Catégorie : Règles d'urbanisme » — et le
 * préfixe se retire par un attribut que l'on oublie. Le nom du terme suffit, et
 * la nature de la page se lit dans le fil d'ariane juste au-dessus.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$terme = get_queried_object();
$titre = ( $terme instanceof WP_Term ) ? $terme->name : 'Guides';

/*
 * Description de catégorie : affichée si elle existe, sinon rien. Elle n'est
 * pas inventée ici — une phrase générique du genre « Retrouvez tous nos
 * articles » n'apprendrait rien et occuperait la place d'un texte utile le
 * jour où il sera écrit.
 */
$description = ( $terme instanceof WP_Term ) ? trim( wp_strip_all_tags( term_description( $terme ) ) ) : '';

$id_index  = (int) get_option( 'page_for_posts' );
$url_index = $id_index ? get_permalink( $id_index ) : '';
$nom_index = $id_index ? get_the_title( $id_index ) : 'Guides';
?>
<!-- wp:html -->
<nav class="fil-ariane" aria-label="Fil d'ariane">
  <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Accueil</a>
  <span class="sep">›</span>
  <?php if ( '' !== $url_index ) : ?>
  <a href="<?php echo esc_url( $url_index ); ?>"><?php echo esc_html( $nom_index ); ?></a>
  <span class="sep">›</span>
  <?php endif; ?>
  <?php echo esc_html( $titre ); ?>
</nav>
<span class="eyebrow eyebrow-highlight"><span class="eyebrow-highlight-text">Catégorie</span></span>
<h1><?php echo esc_html( $titre ); ?></h1>
<?php if ( '' !== $description ) : ?>
<p class="lead"><?php echo esc_html( $description ); ?></p>
<?php endif; ?>
<!-- /wp:html -->
