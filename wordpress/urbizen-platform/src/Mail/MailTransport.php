<?php
/**
 * Contrat d'un transport de messages.
 *
 * L'existence de cette interface a une raison précise : elle permet aux bancs
 * d'éprouver toute la mécanique de file, de verrou et de reprise **sans jamais
 * envoyer de courriel**, et sans que le code de production ait à connaître
 * l'existence des tests.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Envoi d'un message déjà rendu et validé.
 */
interface MailTransport {

	/**
	 * Envoie un message.
	 *
	 * Reçoit un message **déjà rendu et validé**. Une implémentation ne doit
	 * jamais lire `$_POST`, `$_FILES`, ni aucune autre entrée de requête.
	 *
	 * @param string             $destinataire Adresse validée.
	 * @param string             $sujet        Sujet sur une seule ligne.
	 * @param string             $corps        Corps HTML.
	 * @param array<int, string> $entetes      En-têtes, une ligne chacun.
	 * @return array{ok:bool,code:string}
	 */
	public function send( string $destinataire, string $sujet, string $corps, array $entetes ): array;
}
