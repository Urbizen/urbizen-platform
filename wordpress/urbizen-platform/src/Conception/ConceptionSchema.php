<?php
/**
 * Exposition contrôlée de la définition serveur au navigateur.
 *
 * Le navigateur a besoin de savoir quels champs dépendent de quels autres, et
 * quelles options existent, pour masquer ce qui n'a pas lieu d'être et
 * afficher une estimation. Il n'a besoin de rien d'autre.
 *
 * Ce composant est donc une **réduction**, jamais une seconde définition :
 * `FormDefinition` reste la source de vérité, et le serveur revalide tout.
 * Aucune règle métier n'est réécrite ici ; elles sont lues.
 *
 * @package Urbizen\Platform\Conception
 */

namespace Urbizen\Platform\Conception;

use Urbizen\Platform\Files\UploadPolicy;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Schéma réduit, destiné au navigateur.
 */
final class ConceptionSchema {

	/**
	 * Version du schéma exposé.
	 *
	 * Elle change dès qu'un brouillon enregistré ne peut plus être restauré
	 * sans risque : c'est elle qui empêche d'injecter d'anciennes valeurs dans
	 * de mauvais champs.
	 */
	public const VERSION = '1';

	/**
	 * Options tarifaires **internes**, jamais présentées au client.
	 *
	 * @var array<int, string>
	 */
	private const OPTIONS_INTERNES = array( 'modifs_sup' );

	/**
	 * Construit le schéma exposé.
	 *
	 * @param FormDefinition $def Définition serveur.
	 * @return array<string, mixed>
	 */
	public static function build( FormDefinition $def ): array {
		return array(
			'version'   => self::VERSION,
			'formType'  => $def->type(),
			'steps'     => self::steps( $def ),
			'pricing'   => self::pricing(),
			'uploads'   => self::uploads(),
		);
	}

	/**
	 * Étapes et champs, réduits à ce que le navigateur doit connaître.
	 *
	 * @param FormDefinition $def Définition.
	 * @return array<int, array<string, mixed>>
	 */
	private static function steps( FormDefinition $def ): array {
		$sortie = array();

		foreach ( $def->steps() as $etape ) {
			$id      = is_array( $etape ) ? (string) ( $etape['id'] ?? '' ) : (string) $etape;
			$libelle = is_array( $etape ) ? (string) ( $etape['label'] ?? $etape['title'] ?? $id ) : $id;

			if ( '' === $id ) {
				continue;
			}

			$champs = array();

			foreach ( $def->fields_for_step( $id ) as $champ ) {
				$champs[] = self::champ( (array) $champ );
			}

			$sortie[] = array(
				'id'     => $id,
				'label'  => $libelle,
				'fields' => $champs,
			);
		}

		return $sortie;
	}

	/**
	 * Réduction d'un champ.
	 *
	 * On expose le nom, le type, l'obligation et la condition d'affichage —
	 * de quoi naviguer et valider *au premier degré*. Les contraintes fines
	 * restent au serveur.
	 *
	 * @param array<string, mixed> $champ Champ.
	 * @return array<string, mixed>
	 */
	private static function champ( array $champ ): array {
		$reduit = array(
			'name'     => (string) ( $champ['name'] ?? '' ),
			'type'     => (string) ( $champ['type'] ?? 'text' ),
			'required' => ! empty( $champ['required'] ),
		);

		if ( isset( $champ['visible_if'] ) && is_array( $champ['visible_if'] ) ) {
			$reduit['visibleIf'] = array(
				'field' => (string) ( $champ['visible_if']['field'] ?? '' ),
				'in'    => array_values( array_map( 'strval', (array) ( $champ['visible_if']['in'] ?? array() ) ) ),
			);
		}

		foreach ( array( 'maxlength', 'min', 'max', 'step' ) as $cle ) {
			if ( isset( $champ[ $cle ] ) && is_scalar( $champ[ $cle ] ) ) {
				$reduit[ $cle ] = $champ[ $cle ];
			}
		}

		if ( isset( $champ['options'] ) && is_array( $champ['options'] ) ) {
			$reduit['options'] = array_values(
				array_map(
					static fn( $o ) => array(
						'value' => (string) ( is_array( $o ) ? ( $o['value'] ?? '' ) : $o ),
						'label' => (string) ( is_array( $o ) ? ( $o['label'] ?? $o['value'] ?? '' ) : $o ),
					),
					$champ['options']
				)
			);
		}

		return $reduit;
	}

	/**
	 * Tarifs exposés.
	 *
	 * Lus depuis `Pricing`, jamais recopiés. Les options internes en sont
	 * retirées : le client n'a pas à voir une prestation qui ne lui est pas
	 * proposée. Le montant définitif reste calculé par le serveur.
	 *
	 * @return array<string, mixed>
	 */
	private static function pricing(): array {
		$options = array();

		foreach ( Pricing::OPTIONS as $id => $prix ) {
			if ( in_array( $id, self::OPTIONS_INTERNES, true ) ) {
				continue;
			}

			$options[ $id ] = (int) $prix;
		}

		return array(
			'base'          => (int) Pricing::BASE,
			'options'       => $options,
			'pack'          => (string) Pricing::PACK,
			'packReplaces'  => array_values( Pricing::PACK_REMPLACE ),
			'surDevis'      => array_values( Pricing::SUR_DEVIS ),
			// Information **commerciale** : jamais déduite d'une commande de
			// conception. Le montant affiché reste celui de la conception.
			'remisePermis'  => (int) Pricing::REMISE_PERMIS_FUTUR,
		);
	}

	/**
	 * Contraintes de dépôt exposées.
	 *
	 * @return array<string, mixed>
	 */
	private static function uploads(): array {
		return array(
			'blocks'       => array_values( UploadPolicy::BLOCKS ),
			'extensions'   => array_values( array_keys( UploadPolicy::TYPES ) ),
			'maxPerBlock'  => (int) UploadPolicy::MAX_PER_BLOCK,
			'maxTotal'     => (int) UploadPolicy::MAX_TOTAL,
			'maxFileSize'  => (int) UploadPolicy::MAX_FILE_SIZE,
			'maxTotalSize' => (int) UploadPolicy::MAX_TOTAL_SIZE,
		);
	}
}
