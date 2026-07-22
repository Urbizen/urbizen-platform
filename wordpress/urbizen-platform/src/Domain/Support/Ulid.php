<?php
/**
 * Identifiants exposés : ULID.
 *
 * Vingt-six caractères, alphabet Crockford, horodatage en tête. Trois
 * propriétés en découlent, et ce sont les trois qu'on voulait :
 *
 * - **non énumérable** — les quatre-vingts bits de queue sont aléatoires, on
 *   ne devine pas le voisin d'un identifiant connu ;
 * - **triable** — l'ordre lexicographique est l'ordre de création, ce qui
 *   donne un index primaire dense sans colonne supplémentaire ;
 * - **lisible** — l'alphabet exclut `I`, `L`, `O` et `U`, donc rien à
 *   confondre au téléphone avec un client.
 *
 * La monotonie est garantie **dans un même processus** : deux appels dans la
 * même milliseconde rendent des valeurs strictement croissantes. Entre
 * processus, seule l'entropie sépare — quatre-vingts bits, la collision n'est
 * pas un risque qu'on gère, c'en est un qu'on ignore.
 *
 * @package Urbizen\Platform\Domain\Support
 */

namespace Urbizen\Platform\Domain\Support;

use RuntimeException;

/**
 * Génération et validation d'ULID.
 */
final class Ulid {

	/**
	 * Alphabet Crockford, sans I, L, O ni U.
	 */
	public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * Longueur d'un ULID.
	 */
	public const LONGUEUR = 26;

	/**
	 * Nombre d'octets aléatoires : quatre-vingts bits.
	 */
	private const OCTETS_ALEA = 10;

	/**
	 * Dernière milliseconde servie.
	 *
	 * @var int
	 */
	private static int $dernier_temps = -1;

	/**
	 * Dernière queue aléatoire servie, dix octets bruts.
	 *
	 * @var string
	 */
	private static string $derniere_queue = '';

	/**
	 * Engendre un ULID.
	 *
	 * @param int|null $millisecondes Horodatage imposé, pour les bancs.
	 * @return string
	 *
	 * @throws RuntimeException Si aucune source d'aléa sûre n'est disponible.
	 */
	public static function generer( ?int $millisecondes = null ): string {
		$temps = null === $millisecondes ? (int) floor( microtime( true ) * 1000 ) : $millisecondes;

		if ( $temps < 0 ) {
			$temps = 0;
		}

		if ( $temps === self::$dernier_temps && '' !== self::$derniere_queue ) {
			// Même milliseconde : on incrémente la queue plutôt que de tirer
			// à nouveau, sans quoi deux identifiants du même instant
			// pourraient se présenter dans le désordre.
			$queue = self::incrementer( self::$derniere_queue );
		} else {
			$queue = self::alea();
		}

		self::$dernier_temps  = $temps;
		self::$derniere_queue = $queue;

		return self::encoder( $temps, $queue );
	}

	/**
	 * La chaîne est-elle un ULID valide ?
	 *
	 * La validation est **stricte** : longueur exacte, capitales seulement,
	 * alphabet Crockford. Accepter les minuscules ou les lettres ambiguës
	 * ferait exister deux écritures d'un même identifiant, donc deux lignes
	 * possibles là où on en veut une.
	 *
	 * @param string $valeur Chaîne éprouvée.
	 * @return bool
	 */
	public static function est_valide( string $valeur ): bool {
		if ( self::LONGUEUR !== strlen( $valeur ) ) {
			return false;
		}

		return 1 === preg_match( '/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $valeur );
	}

	/**
	 * Horodatage porté par un ULID, en millisecondes.
	 *
	 * @param string $ulid ULID.
	 * @return int|null `null` si la chaîne n'est pas un ULID.
	 */
	public static function horodatage( string $ulid ): ?int {
		if ( ! self::est_valide( $ulid ) ) {
			return null;
		}

		$temps = 0;

		// Les dix premiers caractères portent les quarante-huit bits de temps.
		for ( $i = 0; $i < 10; $i++ ) {
			$temps = ( $temps << 5 ) | (int) strpos( self::ALPHABET, $ulid[ $i ] );
		}

		return $temps;
	}

	/**
	 * Réinitialise l'état de monotonie.
	 *
	 * Réservé aux bancs d'essai : sans cela, un banc qui impose un horodatage
	 * hériterait de la queue laissée par le précédent.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$dernier_temps  = -1;
		self::$derniere_queue = '';
	}

	/**
	 * Dix octets aléatoires.
	 *
	 * @return string
	 *
	 * @throws RuntimeException Si l'aléa cryptographique est indisponible.
	 */
	private static function alea(): string {
		try {
			return random_bytes( self::OCTETS_ALEA );
		} catch ( \Throwable $e ) {
			// Pas de repli sur `mt_rand()` : un identifiant prévisible vaut
			// moins que pas d'identifiant du tout.
			throw new RuntimeException( 'aléa cryptographique indisponible', 0, $e );
		}
	}

	/**
	 * Incrémente une queue de dix octets, vue comme un grand entier.
	 *
	 * Au débordement — quatre-vingts bits à un, autant dire jamais — on
	 * retire une queue neuve plutôt que de repartir à zéro, ce qui casserait
	 * la monotonie.
	 *
	 * @param string $queue Queue courante.
	 * @return string
	 */
	private static function incrementer( string $queue ): string {
		for ( $i = self::OCTETS_ALEA - 1; $i >= 0; $i-- ) {
			$octet = ord( $queue[ $i ] );

			if ( $octet < 255 ) {
				$queue[ $i ] = chr( $octet + 1 );

				return $queue;
			}

			$queue[ $i ] = chr( 0 );
		}

		return self::alea();
	}

	/**
	 * Encode quarante-huit bits de temps et quatre-vingts bits d'aléa.
	 *
	 * @param int    $temps Millisecondes.
	 * @param string $queue Dix octets.
	 * @return string
	 */
	private static function encoder( int $temps, string $queue ): string {
		$bits = '';

		// Quarante-huit bits de temps, poids fort en tête.
		for ( $i = 47; $i >= 0; $i-- ) {
			$bits .= ( ( $temps >> $i ) & 1 ) ? '1' : '0';
		}

		// Quatre-vingts bits d'aléa.
		for ( $i = 0; $i < self::OCTETS_ALEA; $i++ ) {
			$bits .= str_pad( decbin( ord( $queue[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		// Cent vingt-huit bits ne se découpent pas en tranches de cinq :
		// deux bits de bourrage en tête portent le total à cent trente.
		$bits = '00' . $bits;

		$sortie = '';

		for ( $i = 0; $i < self::LONGUEUR; $i++ ) {
			$sortie .= self::ALPHABET[ bindec( substr( $bits, $i * 5, 5 ) ) ];
		}

		return $sortie;
	}
}
