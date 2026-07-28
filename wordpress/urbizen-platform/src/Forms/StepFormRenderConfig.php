<?php
/**
 * Configuration technique du rendu d'un formulaire multi-étapes.
 *
 * Objet-valeur **immuable**. Il ne porte que ce dont le renderer générique a
 * besoin pour produire le HTML : la classe racine CSS, l'identifiant d'instance,
 * la cible et les champs techniques du formulaire (action, nonce, jeton déjà
 * généré, pot de miel, retour), et l'attribut `accept` des dépôts.
 *
 * Il ne contient **aucune route de soumission**, **aucun contrôleur**, et
 * **aucune valeur issue de `$_POST`, `$_GET` ou d'une URL** : tout est fourni
 * par le serveur, au moment du rendu. Le renderer ne choisit pas de route ; il
 * reçoit des valeurs déjà décidées.
 *
 * Les quatre emplacements d'extension — `trusted_prelude_html`,
 * `trusted_header_html`, `trusted_footer_html`, `trusted_step_extras_html` —
 * sont des fragments HTML **déjà rendus par l'appelant** (une façade métier).
 * Le renderer les insère à des points fixes **tels quels, sans les échapper ni
 * les interpréter** : c'est par eux qu'un formulaire particulier (Conception,
 * demain un autre) glisse son cartouche, ses consignes ou son consentement sans
 * que le renderer générique n'en connaisse rien.
 *
 * **Contrat de confiance — impératif.** Ces quatre fragments doivent être
 * construits par du **code serveur de confiance** et n'intégrer une valeur
 * saisie qu'après l'échappement adapté (`esc_html`, `esc_attr`…), à la charge de
 * l'appelant. Ils ne doivent **jamais** provenir, même indirectement, de
 * `$_POST`, `$_GET`, d'un attribut de bloc, d'un shortcode, d'un cookie ou d'une
 * URL : le renderer les écrit sans filet, une donnée non échappée y devient une
 * injection. Le préfixe `trusted_` du nom rappelle cette obligation à chaque
 * point d'appel. Ce ne sont pas des gabarits : aucun moteur de template n'est
 * introduit ici.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs techniques nécessaires au rendu d'un formulaire multi-étapes.
 */
final class StepFormRenderConfig {

	/**
	 * @param string                $root            Classe racine CSS (isolation des styles).
	 * @param string                $instance_id     Identifiant d'instance, unique dans la page.
	 * @param string                $form_action_url Cible du `<form action>` (admin-post).
	 * @param string                $action          Valeur du champ caché `action`.
	 * @param string                $nonce_action    Action du nonce.
	 * @param string                $nonce_field     Nom du champ nonce.
	 * @param string                $token_field     Nom du champ jeton anti-spam.
	 * @param string                $token           Jeton anti-spam **déjà généré** par l'appelant.
	 * @param string                $honeypot_field  Nom du champ pot de miel.
	 * @param string                $return_field    Nom du champ d'URL de retour.
	 * @param string                $return_url      URL de retour (du même site).
	 * @param string                $file_accept     Valeur brute de l'attribut `accept` des dépôts.
	 * @param string                $action_field             Nom du champ d'action (défaut « action »).
	 * @param string                $trusted_prelude_html     Fragment de confiance inséré juste après l'ouverture du conteneur.
	 * @param string                $trusted_header_html      Fragment de confiance inséré entre le repli no-JS et la progression.
	 * @param string                $trusted_footer_html      Fragment de confiance inséré après la dernière étape, avant la navigation.
	 * @param array<string, string> $trusted_step_extras_html Fragments de confiance additionnels par identifiant d'étape.
	 * @param string                $render_mode              Mode de rendu **serveur**, en liste blanche : `operational`
	 *                                                        (défenses réelles) ou `preview` (rendu inerte, non soumissible).
	 *                                                        Choisi exclusivement par du code serveur — jamais par le
	 *                                                        navigateur.
	 */
	public function __construct(
		public readonly string $root,
		public readonly string $instance_id,
		public readonly string $form_action_url,
		public readonly string $action,
		public readonly string $nonce_action,
		public readonly string $nonce_field,
		public readonly string $token_field,
		public readonly string $token,
		public readonly string $honeypot_field,
		public readonly string $return_field,
		public readonly string $return_url,
		public readonly string $file_accept,
		public readonly string $action_field = 'action',
		public readonly string $trusted_prelude_html = '',
		public readonly string $trusted_header_html = '',
		public readonly string $trusted_footer_html = '',
		public readonly array $trusted_step_extras_html = array(),
		public readonly string $render_mode = 'operational',
	) {
	}

	/**
	 * S'agit-il d'un rendu d'aperçu inerte ?
	 *
	 * @return bool
	 */
	public function est_apercu(): bool {
		return 'preview' === $this->render_mode;
	}
}
