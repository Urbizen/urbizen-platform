<?php
/**
 * Title: En-tête d'une page projet Urbizen
 * Slug: urbizen-child/projet-entete
 * Categories: header
 * Inserter: no
 *
 * Fil d'ariane, titre, chapô et visuel d'une page projet.
 *
 * POURQUOI UN SEUL GABARIT POUR NEUF PAGES
 *
 * Les pages commerciales historiques sont neuf fichiers de gabarit distincts,
 * chacun portant son markup en entier. C'était tenable à trois pages ; à douze
 * ce serait douze occasions de diverger, et la première retouche de fil
 * d'ariane devrait être répétée douze fois.
 *
 * Les pages projets suivent donc le modèle des guides, qui a fait ses preuves :
 * UN gabarit, UN en-tête, UN pied, et le contenu dans l'éditeur — sourcé depuis
 * `content/pages/` au dépôt. Ajouter une dixième page projet ne demandera pas
 * une ligne de PHP.
 *
 * LE FIL D'ARIANE PASSE PAR LA DÉCLARATION PRÉALABLE
 *
 * `Accueil › Déclaration préalable › <projet>`. Le maillon intermédiaire n'est
 * pas décoratif : il dit au lecteur comme au moteur que ces neuf pages sont les
 * enfants de `/declarations-prealables/`, qui reste le hub. Il double le
 * `BreadcrumbList` émis en JSON-LD.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$id_projet = get_the_ID();

/*
 * Le maillon « Déclaration préalable » n'est écrit que si la page existe. Un
 * fil d'ariane qui pointe vers une adresse absente est pire qu'un fil court —
 * c'est la règle déjà retenue pour l'index des guides.
 */
$page_hub = get_page_by_path( 'declarations-prealables' );
$url_hub  = $page_hub ? get_permalink( $page_hub ) : '';
?>
<!-- wp:html -->
<section class="page-hero projet-hero">
  <div class="wrap">
    <nav class="fil-ariane" aria-label="Fil d'ariane">
      <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Accueil</a>
      <span class="sep">›</span>
	  <?php if ( '' !== $url_hub ) : ?>
      <a href="<?php echo esc_url( $url_hub ); ?>">Déclaration préalable</a>
      <span class="sep">›</span>
	  <?php endif; ?>
      <?php echo esc_html( wp_trim_words( get_the_title( $id_projet ), 8, '…' ) ); ?>
    </nav>
    <span class="eyebrow eyebrow-highlight"><span class="eyebrow-highlight-text">Dossier préparé à distance</span></span>
    <h1><?php echo esc_html( get_the_title( $id_projet ) ); ?></h1>
	<?php if ( '' !== get_the_excerpt() ) : ?>
    <p class="lead"><?php echo esc_html( get_the_excerpt() ); ?></p>
	<?php endif; ?>
    <div class="hero-cta">
      <a class="btn btn-primary" href="/formulaire-declaration-prealable/">Décrire mon projet</a>
      <a class="btn btn-ghost" href="/tarifs/">Voir les tarifs</a>
    </div>
  </div>
</section>
<?php if ( has_post_thumbnail( $id_projet ) ) : ?>
<div class="wrap">
  <figure class="projet-visuel">
	<?php
	/*
	 * Le visuel d'en-tête est le plus grand élément peint de la page : `eager`
	 * et `fetchpriority="high"` évitent que le navigateur le découvre tard.
	 * C'est la seule image de la page qui n'est PAS différée, conformément au
	 * handoff visuel — toutes les autres sont hors de la zone initiale.
	 */
	echo get_the_post_thumbnail(
		$id_projet,
		'large',
		array(
			'loading'       => 'eager',
			'decoding'      => 'async',
			'fetchpriority' => 'high',
			'sizes'         => '(max-width: 1240px) 92vw, 1140px',
		)
	);
	?>
    <figcaption>Illustration d'un projet fictif. Urbizen ne publie pas de photographies de chantiers de clients.</figcaption>
  </figure>
</div>
<?php endif; ?>
<!-- /wp:html -->
