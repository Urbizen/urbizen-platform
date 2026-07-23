<?php
/**
 * Adresse de courriel, sous forme canonique.
 *
 * **Cet objet ne normalise pas, il valide.** La distinction est délibérée : la
 * normalisation propre à WordPress — `sanitize_email()` et ses cas
 * particuliers — appartient à l'adaptateur, qui l'applique **avant** de
 * construire cet objet. Prétendre la reproduire ici obligerait le domaine à
 * suivre les évolutions de WordPress sans jamais pouvoir le vérifier, et deux
 * normalisations divergentes valent moins qu'une seule.
 *
 * Le domaine se contente donc de refuser ce qui, en PHP pur, n'est pas une
 * adresse : vide, mal formée, ou porteuse d'un caractère de contrôle.
 *
 * Aucune dépendance à WordPress, à `Validator` ni à `Forms`.
 *
 * @package Urbizen\Platform\Domain\Account
 */

namespace Urbizen\Platform\Domain\Account;

use InvalidArgumentException;

/**
 * Adresse valide et immuable.
 */
final class AdresseCourriel {

	/**
	 * Longueur maximale admise.
	 *
	 * La RFC 5321 borne la partie locale à 64 octets et le domaine à 255 ;
	 * 254 est la limite pratique d'une adresse complète.
	 */
	public const LONGUEUR_MAX = 254;

	/**
	 * @var string
	 */
	private string $valeur;

	/**
	 * @param string $canonique Valeur déjà normalisée par l'adaptateur.
	 *
	 * @throws InvalidArgumentException Si la valeur n'est pas une adresse.
	 */
	public function __construct( string $canonique ) {
		$motif = self::motif_de_refus( $canonique );

		if ( '' !== $motif ) {
			throw new InvalidArgumentException( $motif );
		}

		$this->valeur = $canonique;
	}

	/**
	 * Construit, ou rend `null` sans lever.
	 *
	 * Utile là où une adresse invalide est un cas ordinaire — une saisie —
	 * plutôt qu'une erreur de programmation.
	 *
	 * @param string $canonique Valeur normalisée.
	 * @return self|null
	 */
	public static function ou_null( string $canonique ): ?self {
		return '' === self::motif_de_refus( $canonique ) ? new self( $canonique ) : null;
	}

	/**
	 * Motif de refus, ou chaîne vide si la valeur convient.
	 *
	 * @param string $valeur Valeur éprouvée.
	 * @return string
	 */
	public static function motif_de_refus( string $valeur ): string {
		if ( '' === $valeur ) {
			return 'adresse_vide';
		}

		if ( strlen( $valeur ) > self::LONGUEUR_MAX ) {
			return 'adresse_trop_longue';
		}

		// Un retour chariot dans une adresse devient une injection d'en-tête
		// dès qu'elle sert de destinataire. Contrôle volontairement redondant
		// avec la normalisation de l'adaptateur.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $valeur ) ) {
			return 'adresse_caractere_de_controle';
		}

		if ( false === filter_var( $valeur, FILTER_VALIDATE_EMAIL ) ) {
			return 'adresse_invalide';
		}

		return '';
	}

	/**
	 * @return string
	 */
	public function valeur(): string {
		return $this->valeur;
	}

	/**
	 * Deux adresses désignent-elles la même boîte ?
	 *
	 * La comparaison est faite sur la forme canonique, donc sensible à la
	 * casse : c'est à l'adaptateur d'avoir abaissé la casse en amont. Comparer
	 * ici sans distinction de casse masquerait une normalisation manquante.
	 *
	 * @param self $autre Adresse comparée.
	 * @return bool
	 */
	public function est_la_meme_que( self $autre ): bool {
		return hash_equals( $this->valeur, $autre->valeur );
	}

	/**
	 * @return string
	 */
	public function __toString(): string {
		return $this->valeur;
	}
}
