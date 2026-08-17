<?php
/**
 * Title: Pied d'une page projet Urbizen
 * Slug: urbizen-child/projet-pied
 * Categories: cta
 * Inserter: no
 *
 * Appel à l'action, réassurance, et retour au hub.
 *
 * CE QUE CE BLOC NE DIT JAMAIS
 *
 * Qu'un dossier sera accepté. Urbizen prépare et remet les pièces ; la décision
 * appartient à l'autorité qui instruit. La règle vient du lot C, elle vaut ici
 * plus qu'ailleurs : c'est le bloc que le lecteur voit juste avant de cliquer,
 * et c'est donc là qu'une promesse de résultat ferait le plus de dégâts.
 *
 * Aucune urgence fabriquée non plus : ni compte à rebours, ni offre limitée, ni
 * nombre de places restantes. Un dossier d'urbanisme n'est pas un billet
 * d'avion.
 *
 * DEUX ACTIONS, PAS TROIS
 *
 * Le formulaire, et les tarifs. Le troisième lien — le retour au hub — est un
 * lien simple : trois boutons de même poids ne hiérarchisent plus rien.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$page_hub = get_page_by_path( 'declarations-prealables' );
$url_hub  = $page_hub ? get_permalink( $page_hub ) : '';
?>
<!-- wp:html -->
<aside class="projet-cta" aria-labelledby="projet-cta-titre">
  <h2 id="projet-cta-titre">Faites préparer votre dossier</h2>
  <p>Décrivez votre projet en quelques minutes. Urbizen vérifie les règles applicables à votre terrain, réalise les plans et les pièces graphiques, et vous remet un dossier complet, prêt à déposer en mairie.</p>
  <div class="projet-cta-actions">
    <a class="btn btn-primary" href="/formulaire-declaration-prealable/">Décrire mon projet</a>
    <a class="btn btn-ghost" href="/tarifs/">Voir tous les tarifs</a>
  </div>
  <p class="projet-cta-note">Devis estimatif avant toute commande. Le dépôt et l'instruction restent de la compétence de votre mairie&nbsp;: Urbizen prépare le dossier, il ne délivre pas l'autorisation.</p>
</aside>

<section class="projet-reassurance" aria-label="Ce sur quoi Urbizen s'engage">
  <div class="projet-reassurance-grille">
    <div>
      <h3>Un interlocuteur, du début à la fin</h3>
      <p>Votre dossier est suivi par la même personne, de la première question jusqu'à la remise des pièces.</p>
    </div>
    <div>
      <h3>Des pièces conformes à ce que le code exige</h3>
      <p>Les plans et documents graphiques sont établis d'après les articles applicables à votre projet, et cotés pour être lisibles à l'instruction.</p>
    </div>
    <div>
      <h3>Les compléments traités avec vous</h3>
      <p>Si la mairie demande une pièce supplémentaire, nous la préparons et vous accompagnons jusqu'à la réponse.</p>
    </div>
    <div>
      <h3>RCP et assurance décennale</h3>
      <p>Urbizen exerce dans un cadre professionnel assuré, partout en France métropolitaine.</p>
    </div>
  </div>
</section>
<?php if ( '' !== $url_hub ) : ?>
<p><a class="projet-retour" href="<?php echo esc_url( $url_hub ); ?>">‹ Tous les projets soumis à déclaration</a></p>
<?php endif; ?>
<!-- /wp:html -->
