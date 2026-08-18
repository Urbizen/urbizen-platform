<?php
/**
 * Title: Pied d'un guide Urbizen
 * Slug: urbizen-child/guide-pied
 * Categories: cta
 * Inserter: no
 *
 * Appel à l'action orienté par la catégorie, guides voisins, retour à l'index.
 *
 * L'APPEL À L'ACTION SUIT LE SUJET, PAS UN DÉFAUT COMMERCIAL
 *
 * Un guide sur la piscine doit mener à la déclaration préalable, pas à une page
 * générique. La table ci-dessous fait cette correspondance par slug de
 * catégorie. Elle est volontairement courte et lisible : trois catégories sont
 * prévues au lot G, et le repli est `/tarifs/`, qui présente les trois
 * prestations sans en imposer une.
 *
 * Les slugs sont ceux que produira WordPress à la création des catégories
 * validées. Si une catégorie inconnue apparaît, le repli s'applique — aucun
 * lien mort, aucune orientation fausse.
 *
 * TROIS ACTIONS, DEUX BOUTONS, UN SEUL TITRE
 *
 * Le bloc a hésité : d'abord `/contact/`, puis deux ancres d'accueil avec la
 * prestation reléguée en lien de texte, puis l'inverse. Il se cale ici sur le
 * tunnel du site, et n'en bouge plus :
 *
 *   bouton principal    « Étudier mon projet »   → /#localisation
 *   bouton secondaire   « Poser mes questions »  → /#demander-des-renseignements
 *   lien de texte       « Tarifs et délais »     → /tarifs/
 *
 * DEUX BOUTONS, PAS TROIS. Trois appels à l'action de même poids ne
 * hiérarchisent plus rien. Le troisième chemin existe, en lien.
 *
 * « Démarrer mon projet » NE DOIT PAS REVENIR. Le site a été harmonisé sur
 * « Étudier mon projet » pour cette ancre ; un banc refuse l'ancien libellé
 * dans ce pattern.
 *
 * Le titre est commun aux dix-huit guides. Ce qui varie — le texte et les trois
 * points — vient de `urbizen_child_cta_guide()`, par catégorie : c'est ce qui
 * fait qu'un guide sur la piscine parle de métré et de pièces DP, et non d'un
 * argumentaire générique.
 *
 * LA PAGE DE PRESTATION N'EST PLUS ICI
 *
 * Elle est liée dans l'introduction et dans le corps de chaque guide, en
 * contexte, là où elle veut dire quelque chose. Le bloc de fin d'article sert
 * la qualification, pas le catalogue.
 *
 * PAS DE PROMESSE SUR LE RÉSULTAT
 *
 * Les libellés parlent de préparer et de déposer un dossier. Ils ne laissent
 * pas entendre qu'Urbizen délivre ou obtient une autorisation : c'est la règle
 * posée au lot C pour les métadonnées, elle vaut autant ici.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$id_guide = get_the_ID();
$cta      = urbizen_child_cta_guide( $id_guide );

/*
 * Guides voisins : la même catégorie d'abord, l'article courant exclu. Le
 * `no_found_rows` évite un décompte total dont on ne fait rien. Si la catégorie
 * n'a pas encore de voisin, la section disparaît — mieux vaut rien qu'une
 * rangée dépareillée.
 */
$categories = wp_get_post_categories( $id_guide );

$voisins = new WP_Query(
	array(
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'posts_per_page'      => 3,
		'post__not_in'        => array( $id_guide ),
		'category__in'        => array() !== $categories ? $categories : array(),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	)
);

$id_index  = (int) get_option( 'page_for_posts' );
$url_index = $id_index ? get_permalink( $id_index ) : '';
?>
<!-- wp:html -->
<aside class="guide-cta" aria-labelledby="guide-cta-titre">
  <p class="guide-cta-marque">Le service Urbizen</p>
  <h2 id="guide-cta-titre">Besoin d’aide pour votre projet&nbsp;?</h2>
  <p><?php echo esc_html( $cta['texte'] ); ?></p>
	<?php $points = (array) ( $cta['points'] ?? array() ); ?>
	<?php if ( array() !== $points ) : ?>
  <ul class="guide-cta-points">
		<?php foreach ( $points as $point ) : ?>
    <li><?php echo esc_html( $point ); ?></li>
		<?php endforeach; ?>
  </ul>
	<?php endif; ?>
  <div class="guide-cta-actions">
    <a class="btn btn-primary" href="<?php echo esc_url( home_url( '/#localisation' ) ); ?>">Étudier mon projet</a>
    <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/#demander-des-renseignements' ) ); ?>">Poser mes questions</a>
  </div>
  <p class="guide-cta-lien"><a href="<?php echo esc_url( home_url( '/tarifs/' ) ); ?>">Tarifs et délais</a></p>
</aside>
<?php if ( $voisins->have_posts() ) : ?>
<section class="guide-voisins">
  <h2>À lire aussi</h2>
  <div class="blog-preview-grid">
	<?php
	while ( $voisins->have_posts() ) :
		$voisins->the_post();
		$id_voisin = get_the_ID();
		$id_titre  = 'voisin-titre-' . $id_voisin;
		?>
    <article class="blog-preview-card">
	  <?php echo urbizen_child_vignette_guide( $id_voisin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
      <div class="blog-preview-content">
        <div class="blog-preview-meta">
          <span><?php echo esc_html( urbizen_child_categorie_guide( $id_voisin ) ?: 'Guide' ); ?></span>
          <b><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'd.m.Y' ) ); ?></time></b>
        </div>
        <h3 id="<?php echo esc_attr( $id_titre ); ?>"><?php the_title(); ?></h3>
        <a class="guide-lien" href="<?php the_permalink(); ?>" aria-labelledby="<?php echo esc_attr( $id_titre ); ?>"></a>
      </div>
    </article>
		<?php
	endwhile;
	/*
	 * Obligatoire : `the_post()` a écrasé le post global. Sans cette remise en
	 * état, tout ce qui suit dans le gabarit parlerait du dernier voisin.
	 */
	wp_reset_postdata();
	?>
  </div>
</section>
<?php endif; ?>
<?php if ( '' !== $url_index ) : ?>
<p class="guide-retour-ligne"><a class="guide-retour" href="<?php echo esc_url( $url_index ); ?>">‹ Tous les guides</a></p>
<?php endif; ?>
<!-- /wp:html -->
