<?php
/**
 * Stratégie de l'accusé de réception client.
 *
 * Même contrat que les stratégies internes — on lui donne une demande, elle
 * rend un message — et les mêmes interdits : elle ne touche ni la file, ni le
 * transport, ni les reprises, ne lit aucune superglobale, et ne choisit son
 * destinataire qu'à partir de ce qui a été validé puis persisté.
 *
 * Elle est mince par construction : {@see CustomerAcknowledgementRenderer}
 * porte toute la mise en forme, de sorte qu'il n'existe qu'un seul endroit où
 * le contenu adressé au client est décidé.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Accusé de réception adressé au demandeur, exposé comme stratégie.
 */
final class CustomerAcknowledgementStrategy implements NotificationStrategy {

	/**
	 * Construit l'accusé, ou null s'il n'est pas rendable.
	 *
	 * @param int $id  Identifiant de la demande.
	 * @param int $now Horodatage courant.
	 * @return array{to:string,subject:string,body:string,headers:array<int,string>}|null
	 */
	public function build( int $id, int $now ): ?array {
		return CustomerAcknowledgementRenderer::render( $id, $now );
	}
}
