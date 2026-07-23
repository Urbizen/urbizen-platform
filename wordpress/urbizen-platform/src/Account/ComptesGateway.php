<?php
/**
 * Port de persistance des comptes.
 *
 * Il existe pour une raison concrète et immédiate : **les services doivent
 * être éprouvables sans WordPress**. Sans ce port, `InscriptionService` et
 * `VerificationService` appelleraient `wp_insert_user()` et `update_user_meta()`
 * en direct, et leurs bancs exigeraient une installation complète — donc ne
 * pourraient pas éprouver les cas de course, les échecs partiels ni les états
 * corrompus, qui sont précisément ce qu'il faut éprouver.
 *
 * Il n'expose **que** les opérations employées par E2.1. Aucune méthode
 * générique de dépôt, aucune méthode sans appelant.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Urbizen\Platform\Domain\Account\Compte;

/**
 * Accès aux comptes.
 */
interface ComptesGateway {

	/**
	 * Ramène une adresse brute à sa forme canonique.
	 *
	 * C'est ici, et non dans le domaine, que s'appliquent les règles propres à
	 * WordPress. Le domaine reçoit le résultat et se contente de le valider.
	 *
	 * @param string $brute Adresse telle que saisie.
	 * @return string Forme canonique, éventuellement vide.
	 */
	public function canoniser( string $brute ): string;

	/**
	 * @param int $id Identifiant.
	 * @return Compte|null
	 */
	public function trouver_par_id( int $id ): ?Compte;

	/**
	 * @param string $canonique Adresse canonique.
	 * @return Compte|null
	 */
	public function trouver_par_adresse( string $canonique ): ?Compte;

	/**
	 * Cette adresse est-elle libre ?
	 *
	 * @param string $canonique Adresse canonique.
	 * @param int    $sauf_id   Compte à ignorer, pour un changement d'adresse.
	 * @return bool
	 */
	public function adresse_disponible( string $canonique, int $sauf_id = 0 ): bool;

	/**
	 * Crée un utilisateur portant le rôle client.
	 *
	 * @param string $identifiant   Identifiant technique opaque.
	 * @param string $canonique     Adresse canonique.
	 * @param string $mot_de_passe  Mot de passe en clair, haché par l'implémentation.
	 * @return int Identifiant créé, ou `0` en cas d'échec.
	 */
	public function creer( string $identifiant, string $canonique, string $mot_de_passe ): int;

	/**
	 * @param int    $id  Compte.
	 * @param string $cle Clé de métadonnée.
	 * @return string|null `null` si absente.
	 */
	public function lire_meta( int $id, string $cle ): ?string;

	/**
	 * @param int    $id     Compte.
	 * @param string $cle    Clé.
	 * @param string $valeur Valeur.
	 * @return bool
	 */
	public function ecrire_meta( int $id, string $cle, string $valeur ): bool;

	/**
	 * @param int    $id  Compte.
	 * @param string $cle Clé.
	 * @return bool
	 */
	public function supprimer_meta( int $id, string $cle ): bool;

	/**
	 * Promeut une adresse en attente en adresse du compte.
	 *
	 * L'implémentation doit poser sa garde interne pendant l'opération, afin
	 * que le crochet `profile_update` reconnaisse un changement Urbizen et
	 * n'invalide pas la vérification qu'on est en train d'établir.
	 *
	 * @param int    $id        Compte.
	 * @param string $canonique Nouvelle adresse.
	 * @return bool
	 */
	public function promouvoir_adresse( int $id, string $canonique ): bool;

	/**
	 * Le rôle client existe-t-il avec exactement la configuration attendue ?
	 *
	 * Interrogé avant toute création de compte : sans lui, WordPress
	 * attribuerait silencieusement `default_role`.
	 *
	 * @return bool
	 */
	public function role_conforme(): bool;
}
