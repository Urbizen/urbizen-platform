<?php
/**
 * Title: Assurance professionnelle Urbizen
 * Slug: urbizen-child/legal-assurance
 * Categories: text
 * Inserter: no
 *
 * Bloc d'assurance professionnelle, partagé par les mentions légales et les CGV.
 *
 * POURQUOI UN PATTERN
 *
 * Même raison que `legal-identite` : ces informations figurent dans deux
 * documents, et un numéro de contrat recopié dans deux gabarits `.html` finit
 * par diverger. Un gabarit de blocs n'exécutant pas de PHP, le pattern est le
 * mécanisme déjà employé par le thème pour lire la source commune.
 *
 * CE QU'IL N'AFFICHE PAS
 *
 * Les dates de validité de l'attestation. Elles existent dans
 * `urbizen_child_donnees_legales()`, mais publier « attestation valable
 * jusqu'au 31/12/2026 » ferait paraître la page périmée dès le lendemain du
 * terme, alors que le contrat, lui, se reconduit. Ces dates servent au contrôle
 * de préparation, qui signale une attestation échue avant tout déploiement.
 *
 * Le bloc entier disparaît si l'assurance n'est pas renseignée : une couverture
 * non attestée ne s'affirme pas.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

$u = urbizen_child_donnees_legales();

if ( null === $u['assurance'] ) {
	return;
}

$a = $u['assurance'];
?>
<!-- wp:html -->
<div class="legal-assurance">
  <dl class="legal-fiche">
    <div class="legal-fiche-ligne">
      <dt>Assureur</dt>
      <dd><strong><?php echo esc_html( $a['assureur'] ); ?></strong></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Contrat</dt>
      <dd class="legal-mono"><?php echo esc_html( $a['contrat'] ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Garanties</dt>
      <dd><?php echo wp_kses_post( implode( '<br />', array_map( 'esc_html', $a['garanties'] ) ) ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Activités assurées</dt>
      <dd><?php echo wp_kses_post( implode( '<br />', array_map( 'esc_html', $a['activites'] ) ) ); ?></dd>
    </div>
    <div class="legal-fiche-ligne">
      <dt>Couverture géographique</dt>
      <dd><?php echo esc_html( $a['territoire'] ); ?></dd>
    </div>
  </dl>
</div>
<!-- /wp:html -->
