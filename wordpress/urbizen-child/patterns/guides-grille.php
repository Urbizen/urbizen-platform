<?php
/**
 * Title: Grille des guides Urbizen
 * Slug: urbizen-child/guides-grille
 * Categories: query
 * Inserter: no
 *
 * Grille de cartes + pagination, servie par `home.html` et `archive.html`.
 *
 * POURQUOI UN PATTERN ET NON LE BLOC « REQUÊTE »
 *
 * Les cartes doivent être celles de l'accueil — mêmes classes
 * `.blog-preview-*`, donc mêmes styles, donc une seule définition à maintenir.
 * Le bloc `core/query` rend sa propre structure (`<ul class="wp-block-post-…">`)
 * qu'il aurait fallu re-styler à l'identique : un second composant, qui aurait
 * dérivé du premier à la première retouche. Ici, le markup est le même à la
 * balise près, à deux ajouts près que l'accueil n'a pas — l'extrait et le lien.
 *
 * Ce pattern lit la BOUCLE PRINCIPALE. Il ne crée pas de `WP_Query` : la
 * requête est déjà faite par WordPress, avec la bonne pagination, la bonne
 * catégorie sur une archive et le bon nombre d'articles par page. En refaire
 * une aurait ignoré tout cela et cassé la pagination en silence.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

/*
 * Tout le rendu tient dans un unique bloc `wp:html`. Sans ce délimiteur, le
 * markup serait pris pour du contenu classique et passerait dans `wpautop`,
 * qui y sèmerait des <p> au milieu de la grille. Et il n'y a PAS de sortie
 * anticipée quand la liste est vide : un `return` sauterait la fermeture du
 * bloc, et le gabarit se retrouverait avec un `wp:html` jamais refermé.
 */
?>
<!-- wp:html -->
<?php if ( ! have_posts() ) : ?>
<p class="guides-vide">Les premiers guides sont en cours de rédaction. Ils paraîtront ici au fil de leur publication.</p>
<?php else : ?>
<div class="blog-preview-grid">
	<?php
	while ( have_posts() ) :
		the_post();
		$id_guide = get_the_ID();
		/*
		 * Le titre porte l'identifiant, et le lien s'y réfère par
		 * `aria-labelledby` : l'ancre couvre toute la carte (voir la feuille),
		 * elle n'a donc pas de texte propre. Sans cet attribut, elle serait
		 * annoncée « lien », sans plus.
		 */
		$id_titre = 'guide-titre-' . $id_guide;
		$categorie = urbizen_child_categorie_guide( $id_guide );
		?>
	<article class="blog-preview-card">
		<?php echo urbizen_child_vignette_guide( $id_guide ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<div class="blog-preview-content">
			<div class="blog-preview-meta">
				<span><?php echo '' !== $categorie ? esc_html( $categorie ) : 'Guide'; ?></span>
				<b><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'd.m.Y' ) ); ?></time></b>
			</div>
			<h3 id="<?php echo esc_attr( $id_titre ); ?>"><?php the_title(); ?></h3>
			<?php if ( has_excerpt() || '' !== get_the_excerpt() ) : ?>
			<p class="guide-extrait"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 26, '…' ) ); ?></p>
			<?php endif; ?>
			<a class="guide-lien" href="<?php the_permalink(); ?>" aria-labelledby="<?php echo esc_attr( $id_titre ); ?>"></a>
		</div>
	</article>
		<?php
	endwhile;
	?>
</div>
<?php
	/*
	 * `paginate_links()` et non `the_posts_pagination()` : le second enveloppe le
 * résultat dans un `<nav class="navigation pagination">` que le thème parent
 * met en forme, et qu'il aurait fallu défaire. Ici, seule la liste de liens est
 * produite, dans notre propre nav.
 */
	$liens = paginate_links(
		array(
			'mid_size'  => 1,
			'end_size'  => 1,
			'prev_text' => '‹ Précédent',
			'next_text' => 'Suivant ›',
			'type'      => 'array',
		)
	);

	if ( is_array( $liens ) && array() !== $liens ) :
		?>
<nav class="guides-pagination" aria-label="Pagination des guides">
		<?php echo implode( "\n\t\t", $liens ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</nav>
		<?php
	endif;
endif;
?>
<!-- /wp:html -->
