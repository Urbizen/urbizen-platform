<?php
/**
 * Manifeste de dépôt : détecter ce que le serveur ne peut pas voir.
 *
 * `max_file_uploads` vaut 20 en production. Si un navigateur envoie plus de
 * fichiers que PHP n'en accepte, **PHP n'en livre qu'une partie et ne le dit
 * pas**. Le serveur ne peut pas connaître un fichier qui ne lui est jamais
 * parvenu : il n'a aucun moyen de distinguer « l'utilisateur en a joint 19 »
 * de « il en a joint 20 et l'un s'est perdu ».
 *
 * D'où ce manifeste : le navigateur **déclare** ce qu'il envoie, et le serveur
 * compare avec ce qu'il reçoit. Un écart trahit une réception partielle.
 *
 * Une précision qui commande tout le reste : **ce manifeste n'est pas une
 * preuve de confiance.** Un client malveillant peut y écrire n'importe quoi.
 * Il ne remplace donc aucun contrôle — extension réelle, type réel, provenance
 * HTTP, tailles, limites par bloc et totales continuent de s'appliquer
 * intégralement. Le manifeste ne sert qu'à *détecter une perte*, jamais à
 * *autoriser* un fichier.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Déclaration client, et sa confrontation aux fichiers réellement reçus.
 */
final class UploadManifest {

	/**
	 * Nom du champ transmis par le formulaire.
	 */
	public const FIELD = 'urbizen_manifest';

	/**
	 * Version du format.
	 */
	public const VERSION = 1;

	/**
	 * Longueur maximale de la déclaration.
	 *
	 * Cinq blocs, deux entiers chacun : quelques centaines d'octets suffisent
	 * largement. Une borne évite d'avoir à décoder un JSON démesuré.
	 */
	public const MAX_LENGTH = 2048;

	/**
	 * Codes d'issue.
	 */
	public const OK                 = 'success';
	public const INCOMPLETE         = 'upload_incomplete';
	public const MANIFEST_INVALID   = 'upload_manifest_invalid';
	public const MANIFEST_MISSING   = 'upload_manifest_missing';

	/**
	 * Clés autorisées à la racine, exactement.
	 *
	 * @var array<int, string>
	 */
	private const CLES_RACINE = array( 'version', 'total_count', 'total_size', 'blocks' );

	/**
	 * Clés autorisées dans un bloc, exactement.
	 *
	 * @var array<int, string>
	 */
	private const CLES_BLOC = array( 'count', 'size' );

	/**
	 * Confronte la déclaration du navigateur aux fichiers reçus.
	 *
	 * À n'appeler qu'**après** `UploadNormalizer::normalize()` : les tailles
	 * comparées sont celles des fichiers réellement reçus, pas celles que la
	 * requête HTTP prétend.
	 *
	 * @param mixed                                  $declaration Valeur brute reçue.
	 * @param array<int, array<string, mixed>>       $recus       Documents normalisés.
	 * @return array{ok:bool,code:string}
	 */
	public static function verify( $declaration, array $recus ): array {
		$reel = self::from_files( $recus );

		// Aucun fichier reçu et aucune déclaration : le parcours historique
		// sans document reste compatible. C'est le seul cas où l'absence de
		// manifeste est tolérée — et il ne peut rien cacher, puisqu'une
		// troncature de zéro fichier n'existe pas.
		if ( ! self::est_present( $declaration ) ) {
			return 0 === $reel['total_count']
				? array( 'ok' => true, 'code' => self::OK )
				: array( 'ok' => false, 'code' => self::MANIFEST_MISSING );
		}

		$declare = self::parse( $declaration );

		if ( null === $declare ) {
			return array( 'ok' => false, 'code' => self::MANIFEST_INVALID );
		}

		// Comparaison **stricte**, sur chaque grandeur.
		if ( $declare['total_count'] !== $reel['total_count'] ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}

		if ( $declare['total_size'] !== $reel['total_size'] ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}

		if ( array_keys( $declare['blocks'] ) !== array_keys( $reel['blocks'] ) ) {
			return array( 'ok' => false, 'code' => self::INCOMPLETE );
		}

		foreach ( $declare['blocks'] as $bloc => $chiffres ) {
			if ( $chiffres['count'] !== $reel['blocks'][ $bloc ]['count'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}

			if ( $chiffres['size'] !== $reel['blocks'][ $bloc ]['size'] ) {
				return array( 'ok' => false, 'code' => self::INCOMPLETE );
			}
		}

		return array( 'ok' => true, 'code' => self::OK );
	}

	/**
	 * Calcule le manifeste des fichiers réellement reçus.
	 *
	 * @param array<int, array<string, mixed>> $recus Documents normalisés.
	 * @return array{version:int,total_count:int,total_size:int,blocks:array<string,array{count:int,size:int}>}
	 */
	public static function from_files( array $recus ): array {
		$blocs = array();
		$total = 0;

		foreach ( $recus as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$bloc   = (string) ( $document['block'] ?? '' );
			$taille = self::taille_reelle( $document );

			if ( '' === $bloc ) {
				continue;
			}

			if ( ! isset( $blocs[ $bloc ] ) ) {
				$blocs[ $bloc ] = array( 'count' => 0, 'size' => 0 );
			}

			++$blocs[ $bloc ]['count'];
			$blocs[ $bloc ]['size'] += $taille;
			$total                  += $taille;
		}

		ksort( $blocs );

		return array(
			'version'     => self::VERSION,
			'total_count' => count( $recus ),
			'total_size'  => $total,
			'blocks'      => $blocs,
		);
	}

	/**
	 * Taille **réellement reçue** d'un document.
	 *
	 * `declared_size` est ce que la requête HTTP prétend ; ce n'est pas une
	 * mesure. La taille qui compte est celle du fichier temporaire écrit par
	 * PHP — la seule que le serveur ait constatée lui-même. On ne retombe sur
	 * la déclaration que si le fichier temporaire est illisible, auquel cas
	 * d'autres barrières auront déjà refusé le dépôt.
	 *
	 * @param array<string, mixed> $document Document normalisé.
	 * @return int
	 */
	private static function taille_reelle( array $document ): int {
		$tmp = (string) ( $document['tmp_name'] ?? '' );

		if ( '' !== $tmp && is_file( $tmp ) ) {
			$taille = @filesize( $tmp );

			if ( false !== $taille ) {
				return (int) $taille;
			}
		}

		return (int) ( $document['declared_size'] ?? 0 );
	}

	/**
	 * La déclaration est-elle présente ?
	 *
	 * @param mixed $declaration Valeur brute.
	 * @return bool
	 */
	private static function est_present( $declaration ): bool {
		return is_scalar( $declaration ) && '' !== trim( (string) $declaration );
	}

	/**
	 * Décode et valide une déclaration.
	 *
	 * Fermé par défaut : toute forme inattendue rend `null`.
	 *
	 * @param mixed $declaration Valeur brute.
	 * @return array{total_count:int,total_size:int,blocks:array<string,array{count:int,size:int}>}|null
	 */
	public static function parse( $declaration ): ?array {
		// Un tableau PHP au lieu d'une chaîne : la requête a été fabriquée.
		if ( ! is_scalar( $declaration ) ) {
			return null;
		}

		$brut = (string) $declaration;

		if ( strlen( $brut ) > self::MAX_LENGTH ) {
			return null;
		}

		$decode = json_decode( $brut, true );

		if ( ! is_array( $decode ) || array() === $decode ) {
			return null;
		}

		// Exactement les clés attendues, ni plus, ni moins.
		$cles = array_keys( $decode );
		sort( $cles );
		$attendues = self::CLES_RACINE;
		sort( $attendues );

		if ( $cles !== $attendues ) {
			return null;
		}

		if ( self::VERSION !== self::entier( $decode['version'] ) ) {
			return null;
		}

		$total_count = self::entier( $decode['total_count'] );
		$total_size  = self::entier( $decode['total_size'] );

		if ( null === $total_count || null === $total_size ) {
			return null;
		}

		if ( ! is_array( $decode['blocks'] ) ) {
			return null;
		}

		$blocs = array();
		$somme_count = 0;
		$somme_size  = 0;

		foreach ( $decode['blocks'] as $bloc => $chiffres ) {
			$bloc = (string) $bloc;

			// Un bloc inconnu n'existe pas : le navigateur ne décide pas de la
			// structure du dossier.
			if ( ! UploadPolicy::is_block( $bloc ) ) {
				return null;
			}

			if ( ! is_array( $chiffres ) ) {
				return null;
			}

			$cb = array_keys( $chiffres );
			sort( $cb );
			$ab = self::CLES_BLOC;
			sort( $ab );

			if ( $cb !== $ab ) {
				return null;
			}

			$count = self::entier( $chiffres['count'] );
			$size  = self::entier( $chiffres['size'] );

			if ( null === $count || null === $size ) {
				return null;
			}

			// Un bloc déclaré vide n'a pas lieu d'être : il ne correspondrait à
			// aucun bloc du manifeste serveur, qui n'inscrit que ce qu'il reçoit.
			if ( 0 === $count ) {
				return null;
			}

			$blocs[ $bloc ] = array( 'count' => $count, 'size' => $size );
			$somme_count   += $count;
			$somme_size    += $size;
		}

		// Le total doit être **cohérent** avec la somme des blocs : une
		// déclaration qui se contredit elle-même est fabriquée.
		if ( $somme_count !== $total_count || $somme_size !== $total_size ) {
			return null;
		}

		ksort( $blocs );

		return array(
			'total_count' => $total_count,
			'total_size'  => $total_size,
			'blocks'      => $blocs,
		);
	}

	/**
	 * Entier canonique, ou `null`.
	 *
	 * Refuse les chaînes numériques, les flottants et les négatifs : `"20"`,
	 * `20.0` et `-1` ne sont pas des entiers canoniques. Une déclaration qui
	 * n'est pas exactement typée n'a pas été produite par le formulaire.
	 *
	 * @param mixed $valeur Valeur.
	 * @return int|null
	 */
	private static function entier( $valeur ): ?int {
		if ( ! is_int( $valeur ) || $valeur < 0 ) {
			return null;
		}

		return $valeur;
	}
}
