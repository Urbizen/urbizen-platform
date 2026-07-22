<?php
/**
 * Adaptateur : WordPress ↔ port des comptes.
 *
 * Seul endroit du socle qui connaisse `wp_insert_user`, `get_user_by` et les
 * métadonnées utilisateur. Il traduit, il ne décide pas.
 *
 * Il porte aussi la **garde de promotion**. Le problème qu'elle résout est
 * précis : `wp_update_user()` déclenche `profile_update`, et ce crochet doit
 * invalider une vérification lorsqu'une adresse change **hors** de notre flux.
 * Sans garde, notre propre promotion déclencherait cette invalidation et
 * effacerait la vérification qu'on est en train d'établir.
 *
 * Comparer la nouvelle adresse à `_urbizen_courriel_en_attente` ne suffirait
 * pas : un changement fait ailleurs pourrait, par coïncidence, viser la même
 * adresse. La garde est donc explicite, limitée à la requête courante, et
 * retirée dans un `finally` — y compris si `wp_update_user` lève.
 *
 * @package Urbizen\Platform\Adapter
 */

namespace Urbizen\Platform\Adapter;

use Throwable;
use Urbizen\Platform\Account\ComptesGateway;
use Urbizen\Platform\Account\JetonVerification;
use Urbizen\Platform\Account\RoleClient;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Domain\Account\AdresseCourriel;
use Urbizen\Platform\Domain\Account\Compte;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Accès WordPress aux comptes.
 */
final class WpComptes implements ComptesGateway {

	/**
	 * Comptes dont une promotion Urbizen est en cours, dans cette requête.
	 *
	 * @var array<int, bool>
	 */
	private static array $promotions = array();

	/**
	 * Accroche la surveillance des profils.
	 *
	 * Un seul crochet en E2.1, et il ne fait qu'invalider — jamais valider.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'profile_update', array( self::class, 'surveiller_profil' ), 10, 2 );
	}

	/**
	 * Invalide la vérification lors d'un changement d'adresse hors flux.
	 *
	 * @param int    $id      Identifiant.
	 * @param object $ancien  Utilisateur avant mise à jour.
	 * @return void
	 */
	public static function surveiller_profil( $id, $ancien = null ): void {
		$id = (int) $id;

		if ( $id <= 0 || ! is_object( $ancien ) ) {
			return;
		}

		// Notre propre promotion : ne rien faire.
		if ( isset( self::$promotions[ $id ] ) ) {
			return;
		}

		$avant = strtolower( trim( (string) ( $ancien->user_email ?? '' ) ) );
		$utilisateur = get_userdata( $id );

		if ( ! $utilisateur ) {
			return;
		}

		$apres = strtolower( trim( (string) $utilisateur->user_email ) );

		// Adresse inchangée : ne rien faire. C'est le cas de loin le plus
		// fréquent — toute mise à jour de profil passe ici.
		if ( '' === $apres || $avant === $apres ) {
			return;
		}

		// Changement réel, hors de notre flux : la vérification ne vaut plus,
		// et tout jeton en cours vise une cible devenue caduque.
		delete_user_meta( $id, VerificationService::META_VERIFIE );
		delete_user_meta( $id, VerificationService::META_VERIFIE_LE );
		delete_user_meta( $id, JetonVerification::META_CONDENSAT );
		delete_user_meta( $id, JetonVerification::META_EXPIRE );
		delete_user_meta( $id, JetonVerification::META_CIBLE );
		delete_user_meta( $id, VerificationService::META_EN_ATTENTE );

		Logger::info( sprintf( 'verification invalidee : adresse modifiee hors flux (compte %d)', $id ) );
	}

	/**
	 * @param string $brute Adresse saisie.
	 * @return string
	 */
	public function canoniser( string $brute ): string {
		// Les caractères de contrôle d'abord : ils feraient une injection
		// d'en-tête dès que l'adresse sert de destinataire.
		$valeur = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $brute );
		$valeur = strtolower( trim( $valeur ) );

		return (string) sanitize_email( $valeur );
	}

	/**
	 * @param int $id Identifiant.
	 * @return Compte|null
	 */
	public function trouver_par_id( int $id ): ?Compte {
		if ( $id <= 0 ) {
			return null;
		}

		$utilisateur = get_userdata( $id );

		return $utilisateur ? $this->composer( $utilisateur ) : null;
	}

	/**
	 * @param string $canonique Adresse canonique.
	 * @return Compte|null
	 */
	public function trouver_par_adresse( string $canonique ): ?Compte {
		if ( '' === $canonique ) {
			return null;
		}

		$utilisateur = get_user_by( 'email', $canonique );

		return $utilisateur ? $this->composer( $utilisateur ) : null;
	}

	/**
	 * @param string $canonique Adresse canonique.
	 * @param int    $sauf_id   Compte ignoré.
	 * @return bool
	 */
	public function adresse_disponible( string $canonique, int $sauf_id = 0 ): bool {
		if ( '' === $canonique ) {
			return false;
		}

		$utilisateur = get_user_by( 'email', $canonique );

		if ( ! $utilisateur ) {
			return true;
		}

		return $sauf_id > 0 && (int) $utilisateur->ID === $sauf_id;
	}

	/**
	 * @param string $identifiant  Identifiant technique.
	 * @param string $canonique    Adresse canonique.
	 * @param string $mot_de_passe Mot de passe en clair.
	 * @return int
	 */
	public function creer( string $identifiant, string $canonique, string $mot_de_passe ): int {
		// Seconde barrière : le service a déjà contrôlé le rôle, on refuse
		// tout de même de créer sans lui.
		if ( ! $this->role_conforme() ) {
			return 0;
		}

		$id = wp_insert_user(
			array(
				'user_login' => $identifiant,
				'user_email' => $canonique,
				'user_pass'  => $mot_de_passe,
				'role'       => RoleClient::ROLE,
			)
		);

		if ( is_wp_error( $id ) ) {
			// Le message d'erreur peut contenir l'adresse : on ne journalise
			// que le code.
			Logger::info( sprintf( 'creation refusee par WordPress : %s', $id->get_error_code() ) );

			return 0;
		}

		return (int) $id;
	}

	/**
	 * @param int    $id  Identifiant.
	 * @param string $cle Clé.
	 * @return string|null
	 */
	public function lire_meta( int $id, string $cle ): ?string {
		if ( ! metadata_exists( 'user', $id, $cle ) ) {
			return null;
		}

		return (string) get_user_meta( $id, $cle, true );
	}

	/**
	 * @param int    $id     Identifiant.
	 * @param string $cle    Clé.
	 * @param string $valeur Valeur.
	 * @return bool
	 */
	public function ecrire_meta( int $id, string $cle, string $valeur ): bool {
		update_user_meta( $id, $cle, $valeur );

		// `update_user_meta` rend `false` aussi bien sur échec que sur valeur
		// inchangée : seule la relecture prouve l'écriture. Leçon déjà tirée
		// sur les métadonnées de demande, en PR C.
		return $valeur === (string) get_user_meta( $id, $cle, true );
	}

	/**
	 * @param int    $id  Identifiant.
	 * @param string $cle Clé.
	 * @return bool
	 */
	public function supprimer_meta( int $id, string $cle ): bool {
		delete_user_meta( $id, $cle );

		return ! metadata_exists( 'user', $id, $cle );
	}

	/**
	 * @param int    $id        Identifiant.
	 * @param string $canonique Nouvelle adresse.
	 * @return bool
	 */
	public function promouvoir_adresse( int $id, string $canonique ): bool {
		if ( $id <= 0 || '' === $canonique ) {
			return false;
		}

		self::$promotions[ $id ] = true;

		try {
			$retour = wp_update_user( array( 'ID' => $id, 'user_email' => $canonique ) );

			if ( is_wp_error( $retour ) ) {
				Logger::info( sprintf( 'promotion refusee : %s (compte %d)', $retour->get_error_code(), $id ) );

				return false;
			}

			$utilisateur = get_userdata( $id );

			// Relecture : la promotion n'est acquise que si la base la porte.
			return $utilisateur && strtolower( (string) $utilisateur->user_email ) === $canonique;
		} catch ( Throwable $e ) {
			return false;
		} finally {
			// La garde est retirée dans tous les cas, exception comprise :
			// la laisser posée désarmerait la surveillance pour le reste de
			// la requête.
			unset( self::$promotions[ $id ] );
		}
	}

	/**
	 * @return bool
	 */
	public function role_conforme(): bool {
		return RoleClient::est_conforme();
	}

	/**
	 * Une promotion est-elle en cours pour ce compte ?
	 *
	 * Réservé aux bancs d'essai, qui doivent pouvoir prouver que la garde est
	 * retirée après une exception.
	 *
	 * @param int $id Identifiant.
	 * @return bool
	 */
	public static function promotion_en_cours( int $id ): bool {
		return isset( self::$promotions[ $id ] );
	}

	/**
	 * Compose un compte de domaine depuis un utilisateur WordPress.
	 *
	 * @param object $utilisateur Utilisateur.
	 * @return Compte|null
	 */
	private function composer( $utilisateur ): ?Compte {
		$id      = (int) $utilisateur->ID;
		$adresse = AdresseCourriel::ou_null( $this->canoniser( (string) $utilisateur->user_email ) );

		if ( null === $adresse ) {
			return null;
		}

		$verifie = VerificationService::VALEUR_VERIFIE
			=== (string) get_user_meta( $id, VerificationService::META_VERIFIE, true );

		$brute_en_attente = (string) get_user_meta( $id, VerificationService::META_EN_ATTENTE, true );
		$en_attente       = '' === $brute_en_attente
			? null
			: AdresseCourriel::ou_null( $this->canoniser( $brute_en_attente ) );

		return new Compte( $id, $adresse, $verifie, $en_attente );
	}
}
