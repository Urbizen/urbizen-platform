<?php
/**
 * Création et lecture des demandes.
 *
 * Seule couche autorisée à écrire une demande. Le contrôleur ne touche jamais
 * à `wp_insert_post()` directement : une règle de conservation qui n'existe
 * qu'à un seul endroit est une règle qu'on peut vérifier.
 *
 * **La demande est écrite avant toute action externe.** Un courriel qui part
 * mais dont la demande n'a pas été enregistrée est un prospect perdu ; une
 * demande enregistrée dont le courriel échoue est un incident réparable. La
 * base est le support, le courriel n'est qu'une notification.
 *
 * Ce qui est écrit : **exclusivement** ce que `Validator` a nettoyé et ce que
 * `Pricing` a calculé. Jamais le POST brut, jamais un champ inconnu, jamais un
 * prix venu du navigateur, jamais une adresse IP, un agent utilisateur, un
 * nonce, un pot de miel ou un jeton.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Submissions;

use Urbizen\Platform\Support\Logger;
use Urbizen\Platform\Support\OptionsScan;
use Urbizen\Platform\Support\Reference;

defined( 'ABSPATH' ) || exit;

/**
 * Persistance des demandes de conception.
 */
final class SubmissionRepository {

	/**
	 * Option portant les compteurs de référence, par année.
	 */
	public const SEQUENCE_OPTION = 'urbizen_reference_sequence';

	/**
	 * Préfixe des options de réservation de référence.
	 *
	 * Le compteur seul ne garantit rien : deux requêtes peuvent le lire avant
	 * que l'une n'écrive, et repartir avec le même rang. Il reste un
	 * **accélérateur** — il évite de repartir de 1 à chaque fois — mais
	 * l'unicité vient de l'unicité de `option_name`, que `add_option()` rend
	 * atomique.
	 */
	public const RESERVATION_PREFIX = 'urbizen_ref_';

	/**
	 * Délai au-delà duquel une réservation jamais attribuée est abandonnée.
	 */
	public const RESERVATION_TTL = 3600;

	/**
	 * Version du schéma de stockage.
	 */
	public const SCHEMA_VERSION = '1.0';

	/**
	 * Métadonnées obligatoires. Si l'une manque, la demande n'existe pas.
	 *
	 * @var array<int, string>
	 */
	public const REQUIRED_META = array(
		'_urbizen_reference',
		'_urbizen_form_type',
		'_urbizen_schema_version',
		'_urbizen_status',
		'_urbizen_created_at_gmt',
		'_urbizen_last_contact_at_gmt',
		'_urbizen_payload',
		'_urbizen_pricing',
		'_urbizen_consent_at_gmt',
		'_urbizen_source_path',
		'_urbizen_mail_status',
		'_urbizen_files_status',
	);

	/**
	 * Nombre maximal de tentatives d'attribution d'une référence libre.
	 */
	private const MAX_TENTATIVES = 50;

	/**
	 * Crée une demande.
	 *
	 * @param array<string, mixed> $clean   Données nettoyées par Validator.
	 * @param array<string, mixed> $pricing Résultat de Pricing.
	 * @param array<string, mixed> $context Contexte : form_type, source_path, now.
	 * @return array{ok:bool,code:string,id:int,reference:string}
	 */
	public static function create( array $clean, array $pricing, array $context = array() ): array {
		$form_type   = isset( $context['form_type'] ) ? (string) $context['form_type'] : 'conception';
		$source_path = isset( $context['source_path'] ) ? (string) $context['source_path'] : '';
		$now         = isset( $context['now'] ) ? (int) $context['now'] : time();
		$horodatage  = gmdate( 'Y-m-d H:i:s', $now );

		$reference = self::next_reference( $now );

		if ( '' === $reference ) {
			Logger::error( 'demande : aucune référence disponible' );
			return self::echec( 'persistence_failed' );
		}

		// Le titre ne porte que la référence. Aucune donnée personnelle n'entre
		// dans un champ que WordPress affiche, exporte ou met dans une URL.
		$id = wp_insert_post(
			array(
				'post_type'    => SubmissionPostType::POST_TYPE,
				'post_title'   => $reference,
				'post_name'    => strtolower( $reference ),
				'post_status'  => 'private',
				'post_content' => '',
				'post_excerpt' => '',
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			self::release_reference( $reference );
			Logger::error( 'demande : création impossible' );

			return self::echec( 'persistence_failed' );
		}

		$id = (int) $id;

		$meta = array(
			'_urbizen_reference'           => $reference,
			'_urbizen_form_type'           => $form_type,
			'_urbizen_schema_version'      => self::SCHEMA_VERSION,
			'_urbizen_status'              => SubmissionPostType::STATUS_RECEIVED,
			'_urbizen_created_at_gmt'      => $horodatage,
			'_urbizen_last_contact_at_gmt' => $horodatage,
			'_urbizen_payload'             => (string) wp_json_encode( $clean ),
			'_urbizen_pricing'             => (string) wp_json_encode( self::normalize_pricing( $pricing ) ),
			'_urbizen_consent_at_gmt'      => ! empty( $clean['rgpd'] ) ? $horodatage : '',
			'_urbizen_source_path'         => $source_path,
			'_urbizen_mail_status'         => 'not_started',
			'_urbizen_files_status'        => 'not_started',
		);

		foreach ( $meta as $cle => $valeur ) {
			if ( ! update_post_meta( $id, $cle, $valeur ) ) {
				// Une demande amputée d'une métadonnée obligatoire est pire
				// qu'une absence de demande : elle laisse croire que le dossier
				// est en main. On efface et on annonce l'échec.
				wp_delete_post( $id, true );
				self::release_reference( $reference );
				Logger::error( sprintf( 'demande %s : métadonnée « %s » non écrite, demande supprimée', $reference, $cle ) );

				return self::echec( 'persistence_failed' );
			}
		}

		$manquantes = array_diff( self::REQUIRED_META, array_keys( get_post_meta( $id ) ) );

		if ( array() !== $manquantes ) {
			wp_delete_post( $id, true );
			self::release_reference( $reference );
			Logger::error( sprintf( 'demande %s : métadonnées manquantes après écriture, demande supprimée', $reference ) );

			return self::echec( 'persistence_failed' );
		}

		// La référence est désormais attribuée pour de bon.
		self::confirm_reference( $reference, $id, $now );

		Logger::info( sprintf( 'demande %s enregistrée (#%d, %s)', $reference, $id, $form_type ) );

		return array(
			'ok'        => true,
			'code'      => 'success',
			'id'        => $id,
			'reference' => $reference,
		);
	}

	/**
	 * Attribue la prochaine référence libre.
	 *
	 * Le compteur est une option ; l'unicité réelle est vérifiée en base. Deux
	 * soumissions simultanées peuvent viser le même rang : la vérification
	 * décale la seconde plutôt que d'écraser la première.
	 *
	 * @param int $now Horodatage courant.
	 * @return string Référence, ou chaîne vide si aucune n'est libre.
	 */
	public static function next_reference( int $now ): string {
		$annee     = (int) gmdate( 'Y', $now );
		$compteurs = get_option( self::SEQUENCE_OPTION, array() );

		if ( ! is_array( $compteurs ) ) {
			$compteurs = array();
		}

		$rang = isset( $compteurs[ $annee ] ) ? (int) $compteurs[ $annee ] : 0;

		for ( $tentative = 0; $tentative < self::MAX_TENTATIVES; $tentative++ ) {
			++$rang;
			$reference = Reference::format( $annee, $rang );

			// Deux barrières, dans cet ordre. La première écarte les références
			// historiques, créées avant l'existence des réservations. La
			// seconde, atomique, tranche entre deux requêtes concurrentes : une
			// seule peut réussir add_option() sur un nom donné.
			if ( self::reference_exists( $reference ) ) {
				continue;
			}

			if ( ! self::reserve_reference( $reference, $now ) ) {
				continue;
			}

			$compteurs[ $annee ] = $rang;
			update_option( self::SEQUENCE_OPTION, $compteurs, false );

			return $reference;
		}

		return '';
	}

	/**
	 * Réserve une référence, de façon atomique.
	 *
	 * @param string $reference Référence.
	 * @param int    $now       Horodatage courant.
	 * @return bool Vrai si la réservation est acquise.
	 */
	public static function reserve_reference( string $reference, int $now ): bool {
		$cle       = self::RESERVATION_PREFIX . $reference;
		$existante = get_option( $cle, null );

		if ( is_array( $existante ) ) {
			// Une réservation attribuée n'est jamais recyclée : la référence
			// appartient définitivement à une demande. Seule une réservation
			// abandonnée — jamais attribuée, et ancienne — se libère.
			if ( 'reserved' !== ( $existante['state'] ?? '' ) ) {
				return false;
			}

			if ( $now - (int) ( $existante['at'] ?? 0 ) < self::RESERVATION_TTL ) {
				return false;
			}

			delete_option( $cle );
		}

		return (bool) add_option(
			$cle,
			array(
				'state' => 'reserved',
				'at'    => $now,
				'post'  => 0,
			),
			'',
			false
		);
	}

	/**
	 * Libère une réservation de référence.
	 *
	 * Appelée quand la demande n'a finalement pas pu être écrite : la référence
	 * redevient disponible plutôt que de laisser un trou dans la série.
	 *
	 * @param string $reference Référence.
	 * @return void
	 */
	public static function release_reference( string $reference ): void {
		delete_option( self::RESERVATION_PREFIX . $reference );
	}

	/**
	 * Marque une réservation comme définitivement attribuée.
	 *
	 * Elle devient un **registre technique permanent**. Une référence attribuée
	 * ne doit jamais resservir, y compris longtemps après que la demande a
	 * disparu : c'est la seule chose qui empêche qu'un vieux numéro soit
	 * réattribué à un autre dossier.
	 *
	 * Cette distinction est le cœur de la conservation Urbizen :
	 *
	 * - les **données personnelles** de la demande sont effacées après 365
	 *   jours, comme le veut la limitation de conservation ;
	 * - le **registre des références déjà attribuées** survit, parce qu'il ne
	 *   contient aucune donnée personnelle et sert uniquement d'unicité.
	 *
	 * Contenu : un état, une date technique, et l'identifiant du contenu
	 * WordPress. Ni nom, ni adresse, ni téléphone, ni charge utile, ni adresse
	 * IP, ni fichier.
	 *
	 * @param string $reference Référence.
	 * @param int    $post_id   Demande associée.
	 * @param int    $now       Horodatage d'attribution.
	 * @return void
	 */
	public static function confirm_reference( string $reference, int $post_id, int $now ): void {
		update_option(
			self::RESERVATION_PREFIX . $reference,
			array(
				'state' => 'attributed',
				'at'    => gmdate( 'Y-m-d H:i:s', $now ),
				'post'  => $post_id,
			),
			false
		);
	}

	/**
	 * Supprime les réservations abandonnées.
	 *
	 * Une réservation **attribuée** n'est jamais supprimée : elle est associée à
	 * une demande, et la référence ne doit pas pouvoir resservir. Seules partent
	 * celles restées à l'état `reserved` au-delà du délai — trace d'un
	 * traitement interrompu.
	 *
	 * @param int|null $now Horodatage courant (tests).
	 * @return int Nombre de réservations libérées.
	 */
	public static function cleanup_abandoned_references( ?int $now = null ): int {
		$now       = null === $now ? time() : $now;
		$liberees  = 0;

		foreach ( OptionsScan::names( self::RESERVATION_PREFIX ) as $cle ) {
			$valeur = get_option( $cle, null );

			// Seul l'état `reserved` est nettoyable. Tout le reste — `attributed`
			// comme une valeur devenue illisible — est conservé : en cas de
			// doute, garder une entrée inutile coûte une ligne ; en supprimer
			// une à tort rouvre une référence déjà donnée à un client.
			if ( ! is_array( $valeur ) || 'reserved' !== ( $valeur['state'] ?? '' ) ) {
				continue;
			}

			if ( $now - (int) ( $valeur['at'] ?? 0 ) >= self::RESERVATION_TTL ) {
				delete_option( $cle );
				++$liberees;
			}
		}

		return $liberees;
	}

	/**
	 * Une référence est-elle déjà attribuée ?
	 *
	 * @param string $reference Référence.
	 * @return bool
	 */
	public static function reference_exists( string $reference ): bool {
		$trouves = get_posts(
			array(
				'post_type'        => SubmissionPostType::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_urbizen_reference', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $reference, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return ! empty( $trouves );
	}

	/**
	 * Lit une demande.
	 *
	 * @param int $id Identifiant.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post || SubmissionPostType::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$payload = json_decode( (string) get_post_meta( $id, '_urbizen_payload', true ), true );
		$pricing = json_decode( (string) get_post_meta( $id, '_urbizen_pricing', true ), true );

		return array(
			'id'              => $id,
			'reference'       => (string) get_post_meta( $id, '_urbizen_reference', true ),
			'form_type'       => (string) get_post_meta( $id, '_urbizen_form_type', true ),
			'schema_version'  => (string) get_post_meta( $id, '_urbizen_schema_version', true ),
			'status'          => (string) get_post_meta( $id, '_urbizen_status', true ),
			'created_at_gmt'  => (string) get_post_meta( $id, '_urbizen_created_at_gmt', true ),
			'last_contact_at' => (string) get_post_meta( $id, '_urbizen_last_contact_at_gmt', true ),
			'consent_at_gmt'  => (string) get_post_meta( $id, '_urbizen_consent_at_gmt', true ),
			'source_path'     => (string) get_post_meta( $id, '_urbizen_source_path', true ),
			'mail_status'     => (string) get_post_meta( $id, '_urbizen_mail_status', true ),
			'files_status'    => (string) get_post_meta( $id, '_urbizen_files_status', true ),
			'payload'         => is_array( $payload ) ? $payload : array(),
			'pricing'         => is_array( $pricing ) ? $pricing : array(),
		);
	}

	/**
	 * Ne retient du calcul tarifaire que les clés attendues.
	 *
	 * Une clé inattendue dans le résultat de Pricing ne doit pas se retrouver
	 * stockée par inadvertance.
	 *
	 * @param array<string, mixed> $pricing Résultat de Pricing.
	 * @return array<string, mixed>
	 */
	private static function normalize_pricing( array $pricing ): array {
		return array(
			'base'         => isset( $pricing['base'] ) ? (int) $pricing['base'] : 0,
			'options'      => isset( $pricing['options'] ) && is_array( $pricing['options'] ) ? $pricing['options'] : array(),
			'sur_devis'    => isset( $pricing['sur_devis'] ) && is_array( $pricing['sur_devis'] ) ? $pricing['sur_devis'] : array(),
			'total'        => isset( $pricing['total'] ) ? (int) $pricing['total'] : 0,
			'devis_requis' => ! empty( $pricing['devis_requis'] ),
		);
	}

	/**
	 * Fabrique un échec.
	 *
	 * @param string $code Code interne.
	 * @return array{ok:bool,code:string,id:int,reference:string}
	 */
	private static function echec( string $code ): array {
		return array(
			'ok'        => false,
			'code'      => $code,
			'id'        => 0,
			'reference' => '',
		);
	}
}
