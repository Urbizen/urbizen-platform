<?php
/**
 * Rôle WordPress des particuliers.
 *
 * **Il n'est jamais créé au chargement.** Une visite publique ne doit provoquer
 * aucune écriture d'installation — même principe que l'exécuteur de migrations
 * d'E1, et pour la même raison : l'état d'une installation ne doit pas
 * dépendre du trafic. La création passe par `wp urbizen accounts install` ou
 * par le crochet d'activation.
 *
 * Une seule capacité : `read`. Elle ne sert que la navigation WordPress et
 * **n'accorde aucun droit métier** — toute décision passe par `Authorization`.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

/**
 * Définition et contrôle du rôle client.
 */
final class RoleClient {

	/**
	 * Identifiant du rôle.
	 */
	public const ROLE = 'urbizen_client';

	/**
	 * Libellé affiché.
	 */
	public const LIBELLE = 'Client Urbizen';

	/**
	 * Capacités attendues, exhaustives.
	 *
	 * @var array<string, bool>
	 */
	public const CAPACITES = array( 'read' => true );

	/**
	 * Le rôle existe-t-il exactement tel qu'attendu ?
	 *
	 * **Lecture seule.** Ni création, ni correction : c'est la question que
	 * pose `InscriptionService` avant de créer un compte, et elle ne doit rien
	 * écrire.
	 *
	 * @return bool
	 */
	public static function est_conforme(): bool {
		return '' === self::motif_de_non_conformite();
	}

	/**
	 * Motif de non-conformité, ou chaîne vide.
	 *
	 * @return string
	 */
	public static function motif_de_non_conformite(): string {
		if ( ! function_exists( 'get_role' ) ) {
			return 'wordpress_absent';
		}

		$role = get_role( self::ROLE );

		if ( null === $role ) {
			return 'role_absent';
		}

		$actives = array_keys( array_filter( (array) $role->capabilities ) );
		sort( $actives );

		$attendues = array_keys( array_filter( self::CAPACITES ) );
		sort( $attendues );

		if ( $actives !== $attendues ) {
			// On ne détaille pas les capacités en trop : le motif part au
			// journal, et énumérer des capacités n'y apporte rien.
			return 'capacites_inattendues';
		}

		return '';
	}

	/**
	 * Crée ou corrige le rôle.
	 *
	 * **Idempotente** : rejouée sur un rôle conforme, elle n'écrit rien.
	 * Appelée uniquement par la commande WP-CLI ou le crochet d'activation.
	 *
	 * @return string Chaîne vide si tout va bien, motif d'échec sinon.
	 */
	public static function installer(): string {
		if ( ! function_exists( 'add_role' ) || ! function_exists( 'get_role' ) ) {
			return 'wordpress_absent';
		}

		if ( self::est_conforme() ) {
			return '';
		}

		if ( null !== get_role( self::ROLE ) ) {
			// Le rôle existe mais diverge : on le retire pour le reposer, seule
			// façon de garantir qu'aucune capacité surnuméraire ne subsiste.
			remove_role( self::ROLE );
		}

		add_role( self::ROLE, self::LIBELLE, self::CAPACITES );

		return self::motif_de_non_conformite();
	}

	/**
	 * État lisible, sans écriture.
	 *
	 * @return array{present: bool, conforme: bool, motif: string, capacites: array<int, string>}
	 */
	public static function etat(): array {
		$present   = function_exists( 'get_role' ) && null !== get_role( self::ROLE );
		$capacites = array();

		if ( $present ) {
			$role      = get_role( self::ROLE );
			$capacites = array_keys( array_filter( (array) $role->capabilities ) );
			sort( $capacites );
		}

		return array(
			'present'   => $present,
			'conforme'  => self::est_conforme(),
			'motif'     => self::motif_de_non_conformite(),
			'capacites' => $capacites,
		);
	}
}
