<?php
/**
 * Stratégie de notification interne du parcours de conception.
 *
 * Adaptateur mince : elle **ne recopie aucun sujet, corps ni destinataire** et
 * ne change aucune règle. Le rendu historique reste dans {@see MailRenderer}
 * (destinataire serveur via `MailPolicy`, sujet à référence seule, corps
 * échappé, en-têtes sûrs) ; cette classe ne fait que l'exposer derrière le
 * contrat générique {@see NotificationStrategy}, afin que le type serveur
 * « conception » y soit associé sans qu'un second rendu Conception n'existe.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Notification interne de Conception, exposée comme stratégie.
 */
final class ConceptionNotificationStrategy implements NotificationStrategy {

	/**
	 * Construit le message Conception, à l'identique de l'existant.
	 *
	 * @param int $id  Identifiant de la demande.
	 * @param int $now Horodatage courant.
	 * @return array{to:string,subject:string,body:string,headers:array<int,string>}|null
	 */
	public function build( int $id, int $now ): ?array {
		return MailRenderer::render( $id, $now );
	}
}
