<?php
/**
 * Normalisation de `$_FILES`.
 *
 * `$_FILES` est l'une des structures les plus mal formées de PHP. Un champ
 * simple donne `array( 'name' => 'x.pdf', … )` ; un champ multiple donne
 * `array( 'name' => array( 'x.pdf', 'y.pdf' ), … )`, c'est-à-dire un tableau
 * **par clé** et non un tableau de fichiers. Et rien ne garantit que les
 * sous-tableaux aient la même longueur : un client peut les fabriquer à sa
 * guise.
 *
 * Cette classe convertit tout cela en une liste plate et prévisible, ou
 * refuse. Elle ne **répare** jamais une structure ambiguë : une incohérence
 * qu'on corrige en silence est une décision qu'on prend à la place de
 * quelqu'un qui ne l'a pas demandée.
 *
 * Aucun chemin transmis par le navigateur n'est conservé : le nom est réduit à
 * son `basename`, côtés Unix et Windows.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Aplatissement contrôlé de `$_FILES`.
 */
final class UploadNormalizer {

	/**
	 * Clés attendues dans une entrée de `$_FILES`.
	 *
	 * @var array<int, string>
	 */
	private const CLES = array( 'name', 'type', 'tmp_name', 'error', 'size' );

	/**
	 * Normalise les fichiers reçus pour les blocs **du profil serveur**.
	 *
	 * Le profil est **obligatoire** : les blocs acceptés viennent de lui, jamais
	 * d'une liste Conception globale ni du nom d'un champ. Un profil fictif dont
	 * les blocs diffèrent de Conception est traité comme n'importe quel autre.
	 *
	 * **Contrat du retour.** `ignored` ne contient QUE des emplacements hors
	 * profil **réellement vides** (`UPLOAD_ERR_NO_FILE`) — ceux qu'un navigateur
	 * génère normalement pour un champ non rempli. Un bloc hors profil qui porte
	 * un **vrai fichier**, ou une entrée mal formée, n'est **jamais** rangé là en
	 * silence : la normalisation échoue (`ok=false`, `upload_invalid_structure`)
	 * pour tout le lot, avant tout effet de bord. Le contrôleur décide donc sans
	 * ambiguïté : `ok=false` ⇒ rejet ; `ignored` ⇒ champs vides inoffensifs.
	 *
	 * @param array<string, mixed> $files   Superglobale des fichiers.
	 * @param UploadProfile        $profile Profil serveur explicite.
	 * @return array{ok:bool,code:string,files:array<int,array<string,mixed>>,ignored:array<int,string>}
	 */
	public static function normalize( array $files, UploadProfile $profile ): array {
		$documents = array();
		$ignores   = array();

		foreach ( $files as $bloc => $entree ) {
			$bloc = (string) $bloc;

			// Un bloc hors du profil ne crée jamais de structure. Mais on ne l'écarte
			// en silence QUE s'il est réellement vide (champ non rempli du navigateur).
			// Une tentative réelle de dépôt hors profil est un refus explicite : elle
			// ne doit pas disparaître discrètement.
			if ( ! $profile->has_block( $bloc ) ) {
				if ( self::entree_vide( $entree ) ) {
					$ignores[] = $bloc;
					continue;
				}

				return array(
					'ok'      => false,
					'code'    => 'upload_invalid_structure',
					'files'   => array(),
					'ignored' => $ignores,
				);
			}

			$verdict = self::normalize_block( $bloc, $entree );

			if ( ! $verdict['ok'] ) {
				return array(
					'ok'      => false,
					'code'    => $verdict['code'],
					'files'   => array(),
					'ignored' => $ignores,
				);
			}

			$documents = array_merge( $documents, $verdict['files'] );
		}

		return array(
			'ok'      => true,
			'code'    => 'success',
			'files'   => $documents,
			'ignored' => $ignores,
		);
	}

	/**
	 * Une entrée `$_FILES` est-elle **réellement vide** ?
	 *
	 * Vraie seulement si l'entrée est bien formée et que **tous** ses codes
	 * d'erreur valent `UPLOAD_ERR_NO_FILE` — l'emplacement qu'un navigateur
	 * produit pour un champ non rempli. Une structure douteuse, ou le moindre
	 * fichier réellement présent, renvoie faux : hors profil, c'est un refus,
	 * jamais un écartement silencieux.
	 *
	 * @param mixed $entree Entrée de `$_FILES`.
	 * @return bool
	 */
	private static function entree_vide( $entree ): bool {
		if ( ! is_array( $entree ) || ! isset( $entree['error'] ) ) {
			return false;
		}

		$erreurs = is_array( $entree['error'] ) ? $entree['error'] : array( $entree['error'] );

		if ( array() === $erreurs ) {
			return false;
		}

		foreach ( $erreurs as $erreur ) {
			if ( ! is_numeric( $erreur ) || UPLOAD_ERR_NO_FILE !== (int) $erreur ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalise un bloc.
	 *
	 * @param string $bloc   Identifiant de bloc.
	 * @param mixed  $entree Entrée de `$_FILES`.
	 * @return array{ok:bool,code:string,files:array<int,array<string,mixed>>}
	 */
	private static function normalize_block( string $bloc, $entree ): array {
		if ( ! is_array( $entree ) ) {
			return self::refus( 'upload_invalid_structure' );
		}

		// Les cinq clés doivent être présentes. Une entrée amputée est
		// fabriquée à la main, pas produite par un navigateur.
		foreach ( self::CLES as $cle ) {
			if ( ! array_key_exists( $cle, $entree ) ) {
				return self::refus( 'upload_invalid_structure' );
			}
		}

		$multiple = is_array( $entree['name'] );

		// Toutes les clés doivent être du même genre : soit toutes scalaires,
		// soit toutes des tableaux. Un mélange est une structure forgée.
		foreach ( self::CLES as $cle ) {
			if ( is_array( $entree[ $cle ] ) !== $multiple ) {
				return self::refus( 'upload_invalid_structure' );
			}
		}

		if ( ! $multiple ) {
			return self::un_document( $bloc, $entree );
		}

		$taille = count( $entree['name'] );

		// Des sous-tableaux de longueurs différentes : impossible de savoir
		// quelle erreur correspond à quel fichier. On refuse plutôt que de
		// deviner.
		foreach ( self::CLES as $cle ) {
			if ( count( $entree[ $cle ] ) !== $taille ) {
				return self::refus( 'upload_invalid_structure' );
			}
		}

		// Des clés non séquentielles trahissent également une fabrication.
		if ( array_keys( $entree['name'] ) !== range( 0, $taille - 1 ) ) {
			return self::refus( 'upload_invalid_structure' );
		}

		$documents = array();

		for ( $i = 0; $i < $taille; $i++ ) {
			$un = self::un_document(
				$bloc,
				array(
					'name'     => $entree['name'][ $i ],
					'type'     => $entree['type'][ $i ],
					'tmp_name' => $entree['tmp_name'][ $i ],
					'error'    => $entree['error'][ $i ],
					'size'     => $entree['size'][ $i ],
				)
			);

			if ( ! $un['ok'] ) {
				return $un;
			}

			$documents = array_merge( $documents, $un['files'] );
		}

		return array(
			'ok'    => true,
			'code'  => 'success',
			'files' => $documents,
		);
	}

	/**
	 * Normalise un document unique.
	 *
	 * @param string               $bloc   Bloc.
	 * @param array<string, mixed> $entree Entrée.
	 * @return array{ok:bool,code:string,files:array<int,array<string,mixed>>}
	 */
	private static function un_document( string $bloc, array $entree ): array {
		foreach ( array( 'name', 'type', 'tmp_name' ) as $cle ) {
			if ( ! is_scalar( $entree[ $cle ] ) ) {
				return self::refus( 'upload_invalid_structure' );
			}
		}

		if ( ! is_scalar( $entree['error'] ) || ! is_numeric( $entree['error'] ) ) {
			return self::refus( 'upload_invalid_structure' );
		}

		$erreur = (int) $entree['error'];

		// Aucun document choisi dans ce bloc : ce n'est pas une erreur, c'est
		// un champ laissé vide. Il disparaît simplement de la liste.
		if ( UPLOAD_ERR_NO_FILE === $erreur ) {
			return array(
				'ok'    => true,
				'code'  => 'success',
				'files' => array(),
			);
		}

		return array(
			'ok'    => true,
			'code'  => 'success',
			'files' => array(
				array(
					'block'    => $bloc,
					// Le nom est réduit à son basename : aucun chemin fourni
					// par le navigateur ne subsiste, quelle que soit sa forme.
					'name'     => self::basename( (string) $entree['name'] ),
					'tmp_name' => (string) $entree['tmp_name'],
					'error'    => $erreur,
					// La taille annoncée est conservée pour information ; la
					// taille réelle est relue par UploadPolicy.
					'declared_size' => is_numeric( $entree['size'] ?? null ) ? (int) $entree['size'] : 0,
				),
			),
		);
	}

	/**
	 * Nom de base, quel que soit le séparateur employé.
	 *
	 * @param string $name Nom reçu.
	 * @return string
	 */
	public static function basename( string $name ): string {
		$name = str_replace( '\\', '/', $name );
		$name = (string) preg_replace( '/[\x00-\x1f\x7f]/u', '', $name );
		$parts = explode( '/', $name );

		return (string) end( $parts );
	}

	/**
	 * Refus.
	 *
	 * @param string $code Code interne.
	 * @return array{ok:bool,code:string,files:array<int,mixed>}
	 */
	private static function refus( string $code ): array {
		return array(
			'ok'    => false,
			'code'  => $code,
			'files' => array(),
		);
	}
}
