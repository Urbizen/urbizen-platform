<?php
/**
 * Transport de production : le **seul** composant qui appelle `wp_mail()`.
 *
 * Concentrer cet appel en un point unique a deux effets. Il devient possible
 * de prouver, par simple lecture, qu'aucun autre chemin du greffon n'envoie de
 * courriel. Et l'ensemble de la mécanique — file, verrou, reprise, annulation
 * — s'éprouve contre un double, sans jamais rien émettre.
 *
 * `mail()` n'est jamais employée directement : elle contournerait les filtres
 * de WordPress, la configuration SMTP du site et toute extension de messagerie.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Envoi par `wp_mail()`.
 */
final class WordPressMailTransport implements MailTransport {

	/**
	 * Envoie un message.
	 *
	 * Refuse, avant tout appel, un destinataire invalide ou un contenu portant
	 * une nouvelle ligne là où elle permettrait d'ajouter un en-tête.
	 *
	 * @param string             $destinataire Adresse validée.
	 * @param string             $sujet        Sujet.
	 * @param string             $corps        Corps HTML.
	 * @param array<int, string> $entetes      En-têtes.
	 * @return array{ok:bool,code:string}
	 */
	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array {
		if ( ! is_email( $destinataire ) || self::a_une_nouvelle_ligne( $destinataire ) ) {
			return array( 'ok' => false, 'code' => 'recipient_invalid' );
		}

		if ( self::a_une_nouvelle_ligne( $sujet ) ) {
			return array( 'ok' => false, 'code' => 'subject_invalid' );
		}

		$propres = array();

		foreach ( $entetes as $entete ) {
			if ( ! is_string( $entete ) || self::a_une_nouvelle_ligne( $entete ) ) {
				return array( 'ok' => false, 'code' => 'header_invalid' );
			}

			$propres[] = $entete;
		}

		try {
			$envoye = wp_mail( $destinataire, $sujet, $corps, $propres );
		} catch ( \Throwable $e ) {
			// Une extension de messagerie mal lunée ne doit pas faire tomber la
			// requête : l'échec est technique, la reprise s'en chargera.
			return array( 'ok' => false, 'code' => 'transport_exception' );
		}

		return true === $envoye
			? array( 'ok' => true, 'code' => 'accepted' )
			: array( 'ok' => false, 'code' => 'transport_refused' );
	}

	/**
	 * La valeur contient-elle de quoi ouvrir une nouvelle ligne ?
	 *
	 * @param string $valeur Valeur.
	 * @return bool
	 */
	private static function a_une_nouvelle_ligne( string $valeur ): bool {
		return 1 === preg_match( '/[\r\n\x00\x0b\x0c\x{2028}\x{2029}]/u', $valeur );
	}
}
