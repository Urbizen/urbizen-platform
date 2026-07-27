<?php
/**
 * Stratégie de notification interne d'un formulaire.
 *
 * Chaque type de formulaire commercial fournit sa propre stratégie — sujet,
 * corps, en-têtes — derrière ce contrat générique. La stratégie est résolue
 * depuis le **type serveur** de la demande persistée (jamais depuis une valeur
 * cliente), et **ne touche ni la queue, ni le transport, ni les retries** :
 * elle ne fait que **construire le message**. L'orchestration (verrou,
 * idempotence, backoff, envoi) reste au planificateur.
 *
 * L'implémentation ne lit aucune superglobale, ne choisit aucun destinataire ni
 * sujet depuis une donnée client, et n'appelle jamais `wp_mail` directement.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Contrat de construction d'un message de notification serveur.
 */
interface NotificationStrategy {

	/**
	 * Construit le message d'une demande, ou null s'il n'est pas rendable.
	 *
	 * @param int $id  Identifiant de la demande.
	 * @param int $now Horodatage courant.
	 * @return array{to:string,subject:string,body:string,headers:array<int,string>}|null
	 */
	public function build( int $id, int $now ): ?array;
}
