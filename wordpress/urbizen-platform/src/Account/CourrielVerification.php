<?php
/**
 * Rendu des courriels du parcours de comptes.
 *
 * **Un seul corps, en HTML.** `MailTransport::send()` n'accepte qu'un `$corps`,
 * et la convention du dépôt — celle de `MailRenderer` — est de le remplir en
 * HTML avec `Content-Type: text/html; charset=UTF-8` dans les en-têtes.
 * Fabriquer un `multipart/alternative` à la main, ou passer par
 * `phpmailer_init`, reviendrait à contourner le transport par un chemin qu'il
 * ne contrôle pas. On s'en tient au contrat.
 *
 * La lisibilité est donc obtenue autrement : **l'URL est écrite en clair dans
 * le corps**, en plus d'être cliquable. Un client qui dégrade le HTML laisse
 * une adresse copiable, pas un lien mort.
 *
 * La classe est **pure** : aucune dépendance HTTP, aucun appel à la fonction
 * d'envoi de WordPress, aucune lecture de sa configuration. Ce qu'elle affiche
 * lui est remis. Seul `WordPressMailTransport` a le droit de l'appeler, et le
 * contrôle lexical de `tests/submissions/test-compat.php` l'impose sur le code,
 * commentaires neutralisés.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

/**
 * Sujets, corps et en-têtes.
 */
final class CourrielVerification {

	/**
	 * En-têtes communs — le contrat de `MailRenderer`, à la lettre.
	 */
	public const ENTETES = array( 'Content-Type: text/html; charset=UTF-8' );

	/**
	 * Rendu du courriel de vérification.
	 *
	 * @param string $cible Adresse que le lien confirme.
	 * @param string $lien  URL de vérification.
	 * @param string $site  Nom du site.
	 * @return array{sujet: string, corps: string, entetes: array<int, string>}
	 */
	public static function rendre( string $cible, string $lien, string $site = 'Urbizen' ): array {
		$e_cible = self::echapper( $cible );
		$e_lien  = self::echapper( $lien );
		$e_site  = self::echapper( $site );

		$corps = '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body>'
			. '<p>Bonjour,</p>'
			. '<p>Vous avez demandé à confirmer l’adresse <strong>' . $e_cible . '</strong> '
			. 'sur ' . $e_site . '.</p>'
			. '<p><a href="' . $e_lien . '">Confirmer mon adresse</a></p>'
			. '<p>Si le lien ne fonctionne pas, copiez cette adresse dans votre '
			. 'navigateur :<br><span>' . $e_lien . '</span></p>'
			. '<p>Ce lien est valable 24 heures et ne peut servir qu’une fois. '
			. 'Il confirme une adresse : il ne vous connecte pas.</p>'
			. '<p>Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.</p>'
			. '</body></html>';

		return array(
			'sujet'   => 'Confirmez votre adresse — ' . $site,
			'corps'   => $corps,
			'entetes' => self::ENTETES,
		);
	}

	/**
	 * Rendu de l'avertissement envoyé à l'ancienne adresse.
	 *
	 * **Ne reçoit pas la nouvelle adresse**, et c'est délibéré : ce qu'une
	 * méthode ne connaît pas, elle ne peut pas le divulguer. Une boîte
	 * compromise ne doit pas apprendre, par ce message, vers quelle adresse le
	 * compte est en train de partir.
	 *
	 * **Ne porte ni jeton, ni lien de validation.** Ce message avertit ; il
	 * n'agit pas. Le seul geste qu'il propose est de reprendre la main par les
	 * moyens habituels.
	 *
	 * @param string $site Nom du site.
	 * @return array{sujet: string, corps: string, entetes: array<int, string>}
	 */
	public static function rendre_avertissement( string $site = 'Urbizen' ): array {
		$e_site = self::echapper( $site );

		$corps = '<!doctype html><html lang="fr"><head><meta charset="utf-8"></head><body>'
			. '<p>Bonjour,</p>'
			. '<p>Un changement d’adresse de courriel vient d’être demandé pour '
			. 'votre compte sur ' . $e_site . '.</p>'
			. '<p>Tant que la nouvelle adresse n’a pas été confirmée, votre compte '
			. 'reste rattaché à celle-ci.</p>'
			. '<p><strong>Si vous n’êtes pas à l’origine de cette demande</strong>, '
			. 'changez votre mot de passe sans attendre et contactez-nous.</p>'
			. '<p>Ce message ne contient aucun lien de validation : il vous informe, '
			. 'il ne permet rien.</p>'
			. '</body></html>';

		return array(
			'sujet'   => 'Demande de changement d’adresse — ' . $site,
			'corps'   => $corps,
			'entetes' => self::ENTETES,
		);
	}

	/**
	 * Échappement HTML.
	 *
	 * `esc_html()` n'est pas employée : la classe doit rester éprouvable sans
	 * WordPress, et `htmlspecialchars()` fait ici exactement le même travail.
	 *
	 * @param string $valeur Valeur.
	 * @return string
	 */
	private static function echapper( string $valeur ): string {
		return htmlspecialchars( $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
