<?php
/**
 * Stratégie de notification interne de la déclaration préalable.
 *
 * Adaptateur mince, sur le modèle de {@see ConceptionNotificationStrategy} :
 * elle **ne recopie aucun sujet, corps ni destinataire**. {@see MailRenderer}
 * est déjà générique — il résout la définition depuis le type de la demande et
 * rend les réponses à partir d'elle — de sorte qu'une déclaration préalable s'y
 * rend sans qu'une seconde mise en forme existe.
 *
 * Ce qui vaut pour Conception vaut donc ici : destinataire résolu côté serveur
 * par `MailPolicy`, sujet réduit à la référence, corps échappé, en-têtes sûrs.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Notification interne d'une déclaration préalable, exposée comme stratégie.
 */
final class DeclarationPrealableNotificationStrategy implements NotificationStrategy {

	/**
	 * Construit le message, à l'identique de l'existant.
	 *
	 * @param int $id  Identifiant de la demande.
	 * @param int $now Horodatage courant.
	 * @return array{to:string,subject:string,body:string,headers:array<int,string>}|null
	 */
	public function build( int $id, int $now ): ?array {
		return MailRenderer::render( $id, $now );
	}
}
