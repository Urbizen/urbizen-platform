<?php
/**
 * Les précisions du projet, telles qu'un humain les lit.
 *
 * Un dossier porte des mesures : longueur d'un bassin, surface créée, nombre de
 * logements. Trois endroits doivent les montrer — l'écran d'administration, la
 * notification interne, l'accusé client — et chacun aurait pu composer sa
 * propre mise en forme. Trois mises en forme, c'est trois occasions d'écrire
 * `8.5` là où il fallait `8,5 m`, ou de laisser passer un identifiant technique.
 *
 * Cette classe est donc la **seule** à savoir comment une précision se dit :
 * son libellé client, son unité, et l'écriture française de sa valeur.
 *
 * Trois règles gouvernent ce qu'elle rend :
 *
 * 1. **Rien de vide.** Un champ non renseigné ne produit aucune ligne. Une
 *    rubrique constellée de tirets se lit moins bien qu'une rubrique courte.
 * 2. **Aucun zéro d'absence.** Une valeur absente ne devient jamais `0` — c'est
 *    la règle que le normaliseur applique en amont, et celle-ci en dépend.
 * 3. **Aucun identifiant technique.** `longueur_bassin_m` ne sort jamais ;
 *    « Longueur du bassin » sort.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Mise en forme lisible des précisions d'un projet.
 */
final class PrecisionsProjet {

	/**
	 * Libellé client et unité de chaque champ de précision.
	 *
	 * L'ordre est celui de la lecture : les dimensions d'abord, puis ce qui les
	 * qualifie. Un récapitulatif se parcourt, il ne se déchiffre pas.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private const LIBELLES = array(
		'piscine_prevue'        => array( 'Piscine prévue', '' ),
		'longueur_bassin_m'     => array( 'Longueur du bassin', 'm' ),
		'largeur_bassin_m'      => array( 'Largeur du bassin', 'm' ),
		'surface_bassin_m2'     => array( 'Surface du bassin', 'm²' ),
		'profondeur_bassin_m'   => array( 'Profondeur approximative', 'm' ),
		'presence_abri_piscine' => array( 'Abri de piscine', '' ),
		'hauteur_abri_m'        => array( 'Hauteur de l’abri', 'm' ),
		'sp_existante'          => array( 'Surface de plancher existante', 'm²' ),
		'sp_creee'              => array( 'Surface de plancher créée', 'm²' ),
		'sp_totale'             => array( 'Surface de plancher totale', 'm²' ),
		'emprise_avant'         => array( 'Emprise au sol avant travaux', 'm²' ),
		'emprise_creee'         => array( 'Emprise au sol créée', 'm²' ),
		'surface_taxable'       => array( 'Surface taxable créée', 'm²' ),
		'nb_logements'          => array( 'Logements créés', '' ),
		'nb_stationnement'      => array( 'Places de stationnement', '' ),
	);

	/**
	 * Écriture client des valeurs à liste fermée.
	 *
	 * « inconnu » se dit « Je ne sais pas » : c'est une réponse, pas une absence,
	 * et la rendre telle quelle en ferait un mot de machine.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const CHOIX = array(
		'piscine_prevue'        => array(
			'oui'     => 'Oui',
			'non'     => 'Non',
			'inconnu' => 'Je ne sais pas',
		),
		'presence_abri_piscine' => array(
			'oui'     => 'Oui',
			'non'     => 'Non',
			'inconnu' => 'Je ne sais pas',
		),
	);

	/**
	 * Intitulé de la rubrique, partout le même.
	 */
	public const RUBRIQUE = 'Précisions sur le projet';

	/**
	 * Champs dont cette classe assume l'affichage.
	 *
	 * Les rendus génériques — le tableau exhaustif d'une notification, un écran
	 * d'administration — s'en servent pour **ne pas** les rendre une seconde
	 * fois. Sans cela, un dossier annonçait deux fois les mêmes mesures : une
	 * fois en forme canonique (`8.5`) sous un libellé de formulaire, une fois en
	 * français (`8,5 m`) sous son libellé client. Deux écritures d'un même
	 * nombre dans un même message, c'est une occasion de douter des deux.
	 *
	 * La liste se déduit du catalogue plutôt que d'être recopiée : ajouter une
	 * précision ici suffit à la retirer de partout ailleurs.
	 *
	 * @return array<int, string>
	 */
	public static function champs(): array {
		return array_keys( self::LIBELLES );
	}

	/**
	 * Ce champ dispose-t-il déjà d'un rendu métier dédié ?
	 *
	 * @param string $champ Nom canonique.
	 * @return bool
	 */
	public static function porte( string $champ ): bool {
		return isset( self::LIBELLES[ $champ ] );
	}

	/**
	 * Lignes à afficher : libellé → valeur écrite en français.
	 *
	 * Ne rend que ce qui est renseigné. Un tableau vide signifie qu'il n'y a
	 * rien à montrer — et l'appelant doit alors ne rien montrer du tout, pas une
	 * rubrique vide.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return array<string, string>
	 */
	public static function lignes( array $charge ): array {
		$lignes = array();

		foreach ( self::LIBELLES as $champ => $meta ) {
			if ( ! array_key_exists( $champ, $charge ) ) {
				continue;
			}

			$valeur = self::valeur( $champ, $charge[ $champ ] );

			if ( '' === $valeur ) {
				continue;
			}

			$lignes[ $meta[0] ] = '' === $meta[1] ? $valeur : $valeur . ' ' . $meta[1];
		}

		return $lignes;
	}

	/**
	 * Y a-t-il quelque chose à montrer ?
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return bool
	 */
	public static function existe( array $charge ): bool {
		return array() !== self::lignes( $charge );
	}

	/**
	 * Résumé d'une phrase, pour l'accusé client.
	 *
	 * Un accusé n'est pas un dossier technique : il rappelle ce qui a été
	 * communiqué, en une ligne qui se lit. Les dimensions du bassin s'y disent
	 * « 8,5 m × 4 m, soit 34 m² » — comme on le dirait à voix haute.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string Chaîne vide s'il n'y a rien de notable.
	 */
	public static function resume( array $charge ): string {
		$morceaux = array();

		$longueur = self::valeur( 'longueur_bassin_m', $charge['longueur_bassin_m'] ?? null );
		$largeur  = self::valeur( 'largeur_bassin_m', $charge['largeur_bassin_m'] ?? null );
		$surface  = self::valeur( 'surface_bassin_m2', $charge['surface_bassin_m2'] ?? null );

		if ( '' !== $longueur && '' !== $largeur ) {
			$bassin = sprintf( 'bassin d’environ %s m × %s m', $longueur, $largeur );

			if ( '' !== $surface ) {
				$bassin .= sprintf( ', soit %s m²', $surface );
			}

			$morceaux[] = $bassin;
		} elseif ( '' !== $surface ) {
			$morceaux[] = sprintf( 'bassin d’environ %s m²', $surface );
		}

		$profondeur = self::valeur( 'profondeur_bassin_m', $charge['profondeur_bassin_m'] ?? null );

		if ( '' !== $profondeur ) {
			$morceaux[] = sprintf( 'profondeur approximative %s m', $profondeur );
		}

		$abri = isset( $charge['presence_abri_piscine'] ) ? (string) $charge['presence_abri_piscine'] : '';

		if ( 'oui' === $abri ) {
			$hauteur    = self::valeur( 'hauteur_abri_m', $charge['hauteur_abri_m'] ?? null );
			$morceaux[] = '' === $hauteur ? 'avec abri' : sprintf( 'avec abri d’environ %s m', $hauteur );
		} elseif ( 'non' === $abri ) {
			$morceaux[] = 'sans abri';
		}

		if ( array() === $morceaux ) {
			// Rien à décrire, mais la question a pu être posée. Le dire en une
			// clause vaut mieux que de laisser croire qu'on n'a pas écouté.
			$prevue = isset( $charge['piscine_prevue'] ) ? (string) $charge['piscine_prevue'] : '';

			if ( 'non' === $prevue ) {
				return 'Aucune piscine prévue.';
			}

			if ( 'inconnu' === $prevue ) {
				return 'Piscine encore à confirmer.';
			}

			return '';
		}

		return ucfirst( implode( ', ', $morceaux ) ) . '.';
	}

	/**
	 * Valeur d'un champ, écrite pour être lue.
	 *
	 * @param string $champ  Nom canonique.
	 * @param mixed  $brut   Valeur persistée.
	 * @return string Chaîne vide si rien n'est à montrer.
	 */
	private static function valeur( string $champ, $brut ): string {
		if ( null === $brut || '' === $brut || is_array( $brut ) ) {
			return '';
		}

		if ( isset( self::CHOIX[ $champ ] ) ) {
			return self::CHOIX[ $champ ][ (string) $brut ] ?? '';
		}

		// Les mesures sont persistées sous forme canonique — point décimal, sans
		// zéro inutile. On revient ici à l'écriture française.
		$issue = NombreLocalise::decimal( $brut );

		if ( NombreLocalise::VALIDE !== $issue['etat'] ) {
			return '';
		}

		// Un zéro venant d'une absence mal normalisée n'a rien à faire dans un
		// récapitulatif : il se lirait comme une mesure prise.
		if ( 0.0 === (float) $issue['valeur'] ) {
			return '';
		}

		return NombreLocalise::afficher( (float) $issue['valeur'] );
	}
}
