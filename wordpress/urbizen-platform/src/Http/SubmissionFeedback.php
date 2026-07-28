<?php
/**
 * Retour public d'une soumission, prêt à être affiché après redirection.
 *
 * Objet-valeur **immuable** portant le strict nécessaire à l'affichage du
 * résultat d'une soumission — rien de plus. Il ne contient **aucune donnée
 * personnelle** : ni nom, ni adresse électronique, ni téléphone, ni réponse du
 * formulaire, ni erreur par champ, ni prix détaillé. La référence Urbizen y est
 * une **information technique** (elle identifie une demande), jamais une preuve
 * d'authentification : la porter ici n'ouvre aucun accès à la demande persistée.
 *
 * Il est produit par le serveur à partir du résultat réel du pipeline
 * ({@see SubmissionResult}), transporté sous forme d'un jeton signé
 * ({@see SubmissionFeedbackToken}), puis traduit en message par
 * {@see SubmissionResultNotice}. Aucune de ses valeurs ne provient d'un
 * paramètre libre de l'URL.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Charge utile minimale d'une confirmation post-soumission.
 */
final class SubmissionFeedback {

	/**
	 * Version du format. Un jeton d'une version inconnue est rejeté.
	 */
	public const VERSION = 1;

	/**
	 * États publics possibles.
	 */
	public const STATUT_SUCCES = 'success';
	public const STATUT_ERREUR = 'error';

	/**
	 * Catégories d'erreur **publiques**. Liste fermée : elles ne révèlent jamais
	 * un code interne ni le fonctionnement des défenses anti-robot.
	 *
	 * @var array<int, string>
	 */
	public const CATEGORIES = array( 'validation', 'rate_limited', 'unavailable', 'technical' );

	/**
	 * @param string      $form_type        Type serveur de la demande (« conception »).
	 * @param string      $statut           STATUT_SUCCES ou STATUT_ERREUR.
	 * @param string|null $categorie_erreur Catégorie publique en cas d'échec, sinon null.
	 * @param string|null $reference        Référence technique en cas de succès, sinon null.
	 * @param string|null $recovery_id      Identifiant OPAQUE d'une reprise serveur (C2A), présent
	 *                                      **uniquement** pour une erreur corrigeable (`validation`) ;
	 *                                      jamais pour un succès ni une erreur de sécurité.
	 */
	private function __construct(
		public readonly string $form_type,
		public readonly string $statut,
		public readonly ?string $categorie_erreur,
		public readonly ?string $reference,
		public readonly ?string $recovery_id = null,
	) {
	}

	/**
	 * Retour de succès portant la référence serveur.
	 *
	 * @param string $form_type Type serveur.
	 * @param string $reference Référence générée par le serveur.
	 * @return self
	 */
	public static function succes( string $form_type, string $reference ): self {
		return new self( $form_type, self::STATUT_SUCCES, null, $reference );
	}

	/**
	 * Retour d'erreur portant une catégorie publique (jamais un code interne).
	 *
	 * L'identifiant de reprise n'est retenu que pour une erreur **corrigeable**
	 * (`validation`) : pour toute autre catégorie il est ignoré, une erreur de
	 * sécurité n'ouvrant jamais de reprise.
	 *
	 * @param string      $form_type   Type serveur.
	 * @param string      $categorie   Catégorie publique en liste blanche.
	 * @param string|null $recovery_id Identifiant opaque de reprise, ou null.
	 * @return self
	 */
	public static function erreur( string $form_type, string $categorie, ?string $recovery_id = null ): self {
		$reprise = ( 'validation' === $categorie && is_string( $recovery_id ) && '' !== $recovery_id ) ? $recovery_id : null;

		return new self( $form_type, self::STATUT_ERREUR, $categorie, null, $reprise );
	}

	/**
	 * S'agit-il d'un succès ?
	 *
	 * @return bool
	 */
	public function est_succes(): bool {
		return self::STATUT_SUCCES === $this->statut;
	}

	/**
	 * La catégorie est-elle une catégorie publique connue ?
	 *
	 * @param string $categorie Catégorie candidate.
	 * @return bool
	 */
	public static function categorie_valide( string $categorie ): bool {
		return in_array( $categorie, self::CATEGORIES, true );
	}
}
