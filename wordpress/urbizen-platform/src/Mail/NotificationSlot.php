<?php
/**
 * Créneau de notification : une demande, un type de message, un jeu d'états.
 *
 * La file ne connaissait qu'un message par demande — la notification interne à
 * Urbizen — et ses états vivaient dans des clés de méta fixes. Un accusé de
 * réception client est un **second** message, avec son propre destinataire, son
 * propre contenu et son propre sort : il peut échouer quand l'autre réussit, et
 * doit pouvoir être retenté seul.
 *
 * Plutôt que de dupliquer la file, on lui donne la notion de créneau. Un
 * créneau porte les clés de méta d'un couple (demande, type) ; toute la
 * mécanique d'états, de verrous et de tentatives reste unique.
 *
 * **Le type administratif conserve les clés historiques**, sans suffixe. Ce
 * n'est pas une coquetterie : les demandes déjà enregistrées, l'écran
 * d'administration, la garde de corbeille et le rendu du courriel les lisent
 * telles quelles. Les migrer n'apporterait qu'une symétrie de façade, au prix
 * d'une reprise de données et d'un risque de régression sur du code qui marche.
 * Les types suivants prennent un suffixe explicite.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Adressage des états d'un message dans la file.
 */
final class NotificationSlot {

	/**
	 * Notification interne à Urbizen. Type historique.
	 */
	public const ADMIN = 'admin_notification';

	/**
	 * Accusé de réception adressé au demandeur.
	 */
	public const CLIENT = 'customer_acknowledgement';

	/**
	 * Types reconnus. Une valeur hors liste n'est jamais adressable.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = array( self::ADMIN, self::CLIENT );

	/**
	 * @param int    $demande Identifiant de la demande.
	 * @param string $type    Type de notification, en liste blanche.
	 */
	private function __construct(
		public readonly int $demande,
		public readonly string $type
	) {
	}

	/**
	 * Crée un créneau, ou null si le type n'est pas reconnu.
	 *
	 * Rend `null` plutôt que de retomber sur le type administratif : un type
	 * inconnu doit se voir, pas écrire dans le créneau d'un autre.
	 *
	 * @param int    $demande Identifiant de la demande.
	 * @param string $type    Type candidat.
	 * @return self|null
	 */
	public static function pour( int $demande, string $type ): ?self {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return null;
		}

		return new self( $demande, $type );
	}

	/**
	 * Créneau de la notification interne.
	 *
	 * @param int $demande Identifiant de la demande.
	 * @return self
	 */
	public static function admin( int $demande ): self {
		return new self( $demande, self::ADMIN );
	}

	/**
	 * Créneau de l'accusé de réception client.
	 *
	 * @param int $demande Identifiant de la demande.
	 * @return self
	 */
	public static function client( int $demande ): self {
		return new self( $demande, self::CLIENT );
	}

	/**
	 * Clé de méta d'un état, pour ce créneau.
	 *
	 * @param string $base Clé historique, ex. `_urbizen_mail_status`.
	 * @return string
	 */
	public function cle( string $base ): string {
		return self::ADMIN === $this->type ? $base : $base . '__' . $this->type;
	}

	/**
	 * Clé d'idempotence, déterministe et lisible.
	 *
	 * Elle repose sur la **référence** de la demande, pas sur son identifiant
	 * de post : la référence est ce qui identifie la demande pour un humain, et
	 * elle ne change jamais. Deux exécutions du même travail produisent donc la
	 * même clé, et un second accusé ne peut pas naître d'une reprise.
	 *
	 * @param string $reference Référence de la demande.
	 * @return string
	 */
	public function idempotence( string $reference ): string {
		return $reference . ':' . $this->type;
	}

	/**
	 * Le créneau est-il celui de la notification interne ?
	 *
	 * @return bool
	 */
	public function est_admin(): bool {
		return self::ADMIN === $this->type;
	}
}
