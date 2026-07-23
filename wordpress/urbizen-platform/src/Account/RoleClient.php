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
 * **Le rôle n'est jamais retiré pour être corrigé.** Deux raisons, toutes deux
 * concrètes. Une panne entre la suppression et la recréation laisserait
 * l'installation sans rôle, donc toute inscription refusée. Et `remove_role()`
 * dépossède au passage **tous les utilisateurs qui le portent** : corriger une
 * capacité en trop déconnecterait les clients existants de leur rôle. La
 * correction se fait donc en place, capacité par capacité.
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

		return self::motif_pour_capacites( (array) $role->capabilities );
	}

	/**
	 * Ce jeu de capacités est-il exactement celui attendu ?
	 *
	 * On compare **toutes les clés**, pas seulement les capacités actives. Une
	 * entrée `edit_posts => false` n'accorde rien, mais elle traîne dans
	 * l'option, et la laisser passer rendrait la correction non idempotente :
	 * `installer()` la retirerait à chaque exécution sans que la conformité
	 * l'exige jamais.
	 *
	 * @param array<string, mixed> $capacites Capacités observées.
	 * @return string
	 */
	private static function motif_pour_capacites( array $capacites ): string {
		$presentes = array_keys( $capacites );
		sort( $presentes );

		$attendues = array_keys( self::CAPACITES );
		sort( $attendues );

		if ( $presentes !== $attendues ) {
			// On ne détaille pas les capacités en trop : le motif part au
			// journal, et énumérer des capacités n'y apporte rien.
			return 'capacites_inattendues';
		}

		foreach ( self::CAPACITES as $capacite => $attendu ) {
			if ( (bool) $attendu !== (bool) ( $capacites[ $capacite ] ?? false ) ) {
				return 'capacites_inattendues';
			}
		}

		return '';
	}

	/**
	 * Crée le rôle s'il manque, le réconcilie en place s'il diverge.
	 *
	 * **Idempotente** : rejouée sur un rôle conforme, elle n'écrit rien. Rejouée
	 * sur un rôle divergent, elle ne touche que ce qui diverge. Elle n'appelle
	 * jamais `remove_role()` — voir l'en-tête de la classe.
	 *
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

		$role = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, self::LIBELLE, self::CAPACITES );

			$role = get_role( self::ROLE );

			if ( null === $role ) {
				return 'creation_impossible';
			}
		} else {
			// (a) Ce qui manque est ajouté.
			foreach ( self::CAPACITES as $capacite => $attendu ) {
				if ( $attendu && true !== ( $role->capabilities[ $capacite ] ?? null ) ) {
					$role->add_cap( $capacite );
				}
			}

			// (b) Chaque capacité surnuméraire est retirée EXPLICITEMENT, une à
			// une. Le tableau est figé avant la boucle : `remove_cap()` modifie
			// `$role->capabilities` en cours de route.
			foreach ( array_keys( (array) $role->capabilities ) as $capacite ) {
				if ( ! array_key_exists( $capacite, self::CAPACITES ) ) {
					$role->remove_cap( (string) $capacite );
				}
			}
		}

		// (c) Relecture de l'état final. L'objet en mémoire ne suffit pas : on
		// interroge l'option réellement persistée, faute de quoi on affirmerait
		// une correction que la base n'a peut-être pas retenue.
		$motif = self::motif_de_non_conformite();

		if ( '' !== $motif ) {
			return $motif;
		}

		$persistees = self::capacites_persistees();

		if ( null === $persistees ) {
			return 'etat_non_relu';
		}

		$motif = self::motif_pour_capacites( $persistees );

		return '' === $motif ? '' : 'etat_non_persiste';
	}

	/**
	 * Capacités telles que l'option les porte réellement.
	 *
	 * @return array<string, mixed>|null Null si la lecture est impossible.
	 */
	private static function capacites_persistees(): ?array {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'wp_roles' ) ) {
			return null;
		}

		$roles = get_option( wp_roles()->role_key );

		if ( ! is_array( $roles ) || ! isset( $roles[ self::ROLE ]['capabilities'] ) ) {
			return null;
		}

		return (array) $roles[ self::ROLE ]['capabilities'];
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
			$role = get_role( self::ROLE );

			// Toutes les clés, pas seulement les actives : une capacité
			// surnuméraire mise à `false` doit rester visible à l'exploitant,
			// puisqu'elle suffit à rendre le rôle non conforme.
			foreach ( (array) $role->capabilities as $capacite => $actif ) {
				$capacites[] = $actif ? (string) $capacite : (string) $capacite . ' (inactive)';
			}

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
