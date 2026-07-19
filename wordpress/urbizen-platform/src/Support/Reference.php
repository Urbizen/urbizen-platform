<?php
/**
 * Génération des références de dossier.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Référence normalisée URB-AAAA-NNNN (cf. docs/CONVENTIONS.md).
 *
 * Étape 1 : uniquement le formatage et la validation. L'attribution du numéro
 * séquentiel, qui nécessite une écriture en base, arrivera avec le moteur de
 * formulaires.
 */
final class Reference {

	private const PATTERN = '/^URB-(\d{4})-(\d{4})$/';

	/**
	 * Formate une référence à partir d'une année et d'un rang.
	 *
	 * @param int $year     Année sur 4 chiffres.
	 * @param int $sequence Rang dans l'année.
	 */
	public static function format( int $year, int $sequence ): string {
		return sprintf( 'URB-%04d-%04d', $year, $sequence );
	}

	/**
	 * Valide le format d'une référence.
	 *
	 * @param string $reference Référence à contrôler.
	 */
	public static function is_valid( string $reference ): bool {
		return 1 === preg_match( self::PATTERN, $reference );
	}

	/**
	 * Nom de fichier normalisé d'une pièce.
	 *
	 * Exemple : URB-2026-0001_dp1-plan-de-situation_v1.pdf
	 *
	 * @param string $reference Référence du dossier.
	 * @param string $document  Identifiant de la pièce.
	 * @param int    $version   Numéro de version.
	 * @param string $extension Extension sans point.
	 */
	public static function filename( string $reference, string $document, int $version, string $extension ): string {
		return sprintf(
			'%s_%s_v%d.%s',
			$reference,
			sanitize_title( $document ),
			max( 1, $version ),
			strtolower( preg_replace( '/[^a-z0-9]/i', '', $extension ) )
		);
	}
}
