<?php
/**
 * Cycle de Corbeille des demandes.
 *
 * La Corbeille est un piège discret : `wp_trash_post()` change le
 * `post_status` sans toucher à l'état applicatif. Une demande mise à la
 * Corbeille — geste banal, souvent le premier réflexe pour « retirer » un
 * dossier — resterait donc téléchargeable par ses liens signés, alors que
 * l'intention était précisément de la retirer.
 *
 * D'où deux verrous complémentaires :
 *
 * 1. **applicatif** : `_urbizen_status` passe à `trashed` *avant* que la
 *    Corbeille ne soit effective, et l'ancien statut est mémorisé à part pour
 *    pouvoir être restauré **exactement** ;
 * 2. **natif** : le contrôleur de téléchargement exige en outre un
 *    `post_status` WordPress figurant dans une liste fermée. Ce second verrou
 *    tient même si un autre greffon, ou un appel direct, change le statut sans
 *    passer par les hooks applicatifs.
 *
 * La mise à la Corbeille **ne supprime aucun fichier** : elle rend seulement
 * les documents inaccessibles. L'effacement physique reste l'affaire de la
 * suppression définitive, qui passe par `FileCleaner`.
 *
 * @package Urbizen\Platform\Submissions
 */

namespace Urbizen\Platform\Submissions;

use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Verrouillage de la mise à la Corbeille et de la restauration.
 */
final class TrashGuard {

	/**
	 * État applicatif d'une demande à la Corbeille.
	 */
	public const STATUS_TRASHED = 'trashed';

	/**
	 * Métadonnée mémorisant le statut applicatif d'avant la Corbeille.
	 */
	public const PRE_TRASH = '_urbizen_pre_trash_status';

	/**
	 * État durable de la transition vers la Corbeille.
	 *
	 * L'invalidation applicative précède le changement de `post_status` : entre
	 * les deux, un autre filtre peut court-circuiter `wp_trash_post()`, ou
	 * l'écriture native échouer. Sans marqueur, rien ne distinguerait alors une
	 * demande **préparée** d'une demande réellement **mise à la Corbeille** — et
	 * la rétention comme la restauration raisonneraient sur une apparence.
	 */
	public const TRANSITION = '_urbizen_trash_transition';

	/**
	 * L'invalidation est écrite, la Corbeille native n'est pas confirmée.
	 */
	public const PREPARED = 'prepared';

	/**
	 * La Corbeille native a bien eu lieu.
	 */
	public const COMPLETED = 'completed';

	/**
	 * Statuts applicatifs qu'une restauration peut rétablir.
	 *
	 * Liste **fermée**. Un état transitoire ou fautif — `processing`,
	 * `deleting`, `delete_failed`, `recovery_failed`, `incoherent`, `trashed` —
	 * ne doit jamais devenir un statut restaurable : le restaurer rouvrirait
	 * l'accès aux documents sur la foi d'une valeur qui ne l'autorisait pas.
	 *
	 * @return array<int, string>
	 */
	public static function restorable_statuses(): array {
		return SubmissionPostType::downloadable_statuses();
	}

	/**
	 * Accroche les trois points de contrôle.
	 *
	 * @return void
	 */
	public static function register(): void {
		// Trois arguments : WordPress plafonne ce qu'il transmet au nombre
		// déclaré, et le troisième porte le statut précédent.
		add_filter( 'pre_trash_post', array( self::class, 'guard_trash' ), 10, 3 );
		add_action( 'trashed_post', array( self::class, 'after_trash' ), 10, 2 );
		add_filter( 'pre_untrash_post', array( self::class, 'guard_untrash' ), 10, 3 );
		add_action( 'untrashed_post', array( self::class, 'after_untrash' ), 10, 2 );
	}

	/**
	 * Invalide une demande avant sa mise à la Corbeille.
	 *
	 * @param mixed    $court_circuit Valeur de court-circuit de WordPress.
	 * @param mixed    $post          Contenu visé.
	 * @param string   $precedent     Statut WordPress précédent.
	 * @return mixed `false` pour empêcher, la valeur reçue sinon.
	 */
	public static function guard_trash( $court_circuit, $post, $precedent = '' ) {
		if ( ! self::is_ours( $post ) ) {
			return $court_circuit;
		}

		$id      = (int) $post->ID;
		$courant = (string) get_post_meta( $id, '_urbizen_status', true );

		// Déjà invalidée : la préparation existe. On laisse WordPress retenter
		// le passage natif — la mémoire et la transition restent intactes, et
		// une nouvelle tentative n'écrase rien.
		if ( self::STATUS_TRASHED === $courant ) {
			$transition = self::transition( $id );

			if ( array() === $transition ) {
				// Invalidée sans transition : état contradictoire, on ne
				// devine pas ce qu'il faudrait restaurer.
				Logger::error( sprintf( 'corbeille refusée pour #%d : invalidation sans transition', $id ) );

				return false;
			}

			return $court_circuit;
		}

		// Un état incohérent ou transitoire ne se met pas à la Corbeille : on
		// ne saurait pas quoi restaurer ensuite.
		if ( ! in_array( $courant, self::restorable_statuses(), true ) ) {
			Logger::error( sprintf( 'corbeille refusée pour #%d : état applicatif non restaurable', $id ) );

			return false;
		}

		// Mémorisation **une seule fois**.
		if ( '' === (string) get_post_meta( $id, self::PRE_TRASH, true ) ) {
			update_post_meta( $id, self::PRE_TRASH, $courant );

			if ( $courant !== (string) get_post_meta( $id, self::PRE_TRASH, true ) ) {
				Logger::error( sprintf( 'corbeille refusée pour #%d : mémorisation impossible', $id ) );

				return false;
			}
		}

		// Transition marquée « préparée » : elle survit à une coupure et permet
		// de reconnaître, plus tard, une mise à la Corbeille inachevée.
		$transition = array(
			'state'       => self::PREPARED,
			'previous'    => $courant,
			'prepared_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		update_post_meta( $id, self::TRANSITION, (string) wp_json_encode( $transition ) );

		if ( self::PREPARED !== ( self::transition( $id )['state'] ?? '' ) ) {
			Logger::error( sprintf( 'corbeille refusée pour #%d : transition non enregistrée', $id ) );

			return false;
		}

		update_post_meta( $id, '_urbizen_status', self::STATUS_TRASHED );

		// Vérification : sans invalidation certaine, on n'avance pas. Mieux
		// vaut une demande qui reste en place qu'un document accessible alors
		// qu'on croyait l'avoir retiré.
		if ( self::STATUS_TRASHED !== (string) get_post_meta( $id, '_urbizen_status', true ) ) {
			Logger::error( sprintf( 'corbeille refusée pour #%d : invalidation impossible', $id ) );

			return false;
		}

		return $court_circuit;
	}

	/**
	 * Confirme la transition, une fois la Corbeille réellement effective.
	 *
	 * Seul ce hook s'exécute **après** que WordPress a changé le
	 * `post_status`. C'est donc le seul endroit où l'on sait que la mise à la
	 * Corbeille a bien eu lieu.
	 *
	 * Il ne touche ni aux fichiers, ni à la référence, et ne réactive aucun
	 * téléchargement.
	 *
	 * @param int    $post_id   Demande.
	 * @param string $precedent Statut WordPress précédent.
	 * @return void
	 */
	public static function after_trash( $post_id, $precedent = '' ): void {
		$id   = (int) $post_id;
		$post = get_post( $id );

		if ( ! self::is_ours( $post ) ) {
			return;
		}

		if ( 'trash' !== (string) $post->post_status ) {
			return;
		}

		if ( self::STATUS_TRASHED !== (string) get_post_meta( $id, '_urbizen_status', true ) ) {
			return;
		}

		$transition = self::transition( $id );

		if ( self::PREPARED !== ( $transition['state'] ?? '' ) ) {
			return;
		}

		$transition['state'] = self::COMPLETED;
		update_post_meta( $id, self::TRANSITION, (string) wp_json_encode( $transition ) );

		Logger::info( sprintf( 'demande #%d : mise à la Corbeille confirmée', $id ) );
	}

	/**
	 * État durable de la transition.
	 *
	 * @param int $id Demande.
	 * @return array<string, mixed>
	 */
	public static function transition( int $id ): array {
		$brut = json_decode( (string) get_post_meta( $id, self::TRANSITION, true ), true );

		return is_array( $brut ) ? $brut : array();
	}

	/**
	 * La demande est-elle préparée mais pas réellement à la Corbeille ?
	 *
	 * État transitoire : l'invalidation est écrite, le passage natif n'a pas
	 * abouti. Rien ne doit y toucher automatiquement — ni la rétention, ni la
	 * suppression définitive.
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function is_prepared_only( int $id ): bool {
		$post = get_post( $id );

		if ( ! self::is_ours( $post ) ) {
			return false;
		}

		return self::PREPARED === ( self::transition( $id )['state'] ?? '' )
			&& 'trash' !== (string) $post->post_status;
	}

	/**
	 * Réconcilie les transitions restées en plan, sans rien détruire.
	 *
	 * Deux situations, deux traitements :
	 *
	 * - le `post_status` est passé à `trash` mais la confirmation n'a pas eu
	 *   lieu — le hook postérieur a échoué : on confirme ;
	 * - la demande est invalidée sans aucune transition — état contradictoire :
	 *   on marque `incoherent` et on attend une intervention.
	 *
	 * Une transition simplement préparée est **laissée telle quelle** : elle est
	 * rejouable, et l'intention de suppression reste fermée par défaut.
	 *
	 * @return array{confirmees:int,incoherentes:int}
	 */
	public static function reconcile(): array {
		$bilan = array( 'confirmees' => 0, 'incoherentes' => 0 );

		$candidats = get_posts(
			array(
				'post_type'        => SubmissionPostType::POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_key'         => '_urbizen_status', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => self::STATUS_TRASHED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		foreach ( $candidats as $id ) {
			$id   = (int) $id;
			$post = get_post( $id );

			if ( ! $post ) {
				continue;
			}

			$etat = (string) ( self::transition( $id )['state'] ?? '' );

			if ( '' === $etat ) {
				// Invalidée sans transition : on ne sait pas quoi restaurer.
				update_post_meta( $id, '_urbizen_status', TransactionRecovery::INCOHERENT );
				Logger::error( sprintf( 'demande #%d : invalidée sans transition, marquée incohérente', $id ) );
				++$bilan['incoherentes'];

				continue;
			}

			if ( self::PREPARED === $etat && 'trash' === (string) $post->post_status ) {
				self::after_trash( $id, '' );
				++$bilan['confirmees'];
			}
		}

		return $bilan;
	}

	/**
	 * Autorise ou refuse une restauration.
	 *
	 * @param mixed  $court_circuit Valeur de court-circuit de WordPress.
	 * @param mixed  $post          Contenu visé.
	 * @param string $precedent     Statut WordPress d'avant la Corbeille.
	 * @return mixed `false` pour empêcher, la valeur reçue sinon.
	 */
	public static function guard_untrash( $court_circuit, $post, $precedent = '' ) {
		if ( ! self::is_ours( $post ) ) {
			return $court_circuit;
		}

		$id     = (int) $post->ID;
		$motif  = self::restoration_blocker( $id, $post );

		if ( null !== $motif ) {
			Logger::error( sprintf( 'restauration refusée pour #%d : %s', $id, $motif ) );

			return false;
		}

		return $court_circuit;
	}

	/**
	 * Motif empêchant la restauration, ou null si elle est permise.
	 *
	 * @param int   $id   Demande.
	 * @param mixed $post Contenu.
	 * @return string|null
	 */
	public static function restoration_blocker( int $id, $post ): ?string {
		if ( 'trash' !== (string) $post->post_status ) {
			return 'le contenu n’est pas à la Corbeille';
		}

		if ( self::STATUS_TRASHED !== (string) get_post_meta( $id, '_urbizen_status', true ) ) {
			return 'état applicatif inattendu';
		}

		// Une transition seulement préparée ne vaut pas mise à la Corbeille :
		// restaurer reviendrait à rouvrir l'accès sur la foi d'une apparence.
		if ( self::COMPLETED !== ( self::transition( $id )['state'] ?? '' ) ) {
			return 'mise à la Corbeille non confirmée';
		}

		$memoire = (string) get_post_meta( $id, self::PRE_TRASH, true );

		if ( ! in_array( $memoire, self::restorable_statuses(), true ) ) {
			return 'statut mémorisé non restaurable';
		}

		$demande = SubmissionRepository::get( $id );

		if ( null === $demande ) {
			return 'demande illisible';
		}

		$transaction = $demande['transaction'];
		$reference   = (string) $demande['reference'];

		if ( 'committed' !== ( $transaction['state'] ?? '' ) ) {
			return 'transaction non validée';
		}

		if ( '' === $reference || (string) ( $transaction['reference'] ?? '' ) !== $reference ) {
			return 'référence divergente';
		}

		if ( ! in_array( (string) $demande['files_status'], array( 'stored', 'none' ), true ) ) {
			return 'documents dans un état non final';
		}

		$reservation = get_option( SubmissionRepository::RESERVATION_PREFIX . $reference, null );

		if ( ! is_array( $reservation ) || 'attributed' !== ( $reservation['state'] ?? '' ) ) {
			return 'référence non attribuée';
		}

		if ( (int) ( $reservation['post'] ?? 0 ) !== $id ) {
			return 'réservation rattachée à une autre demande';
		}

		$manquantes = array_diff( SubmissionRepository::REQUIRED_META, array_keys( get_post_meta( $id ) ) );

		if ( array() !== $manquantes ) {
			return 'métadonnées obligatoires incomplètes';
		}

		return null;
	}

	/**
	 * Rétablit le statut applicatif après une restauration réussie.
	 *
	 * Le statut mémorisé est rétabli **exactement** : une demande qui était
	 * `converted` ou `closed` ne doit pas revenir en `received`, ce qui
	 * effacerait une information métier.
	 *
	 * @param int    $post_id   Demande restaurée.
	 * @param string $precedent Statut WordPress rétabli.
	 * @return void
	 */
	public static function after_untrash( $post_id, $precedent = '' ): void {
		$id   = (int) $post_id;
		$post = get_post( $id );

		if ( ! self::is_ours( $post ) ) {
			return;
		}

		$memoire = (string) get_post_meta( $id, self::PRE_TRASH, true );

		if ( ! in_array( $memoire, self::restorable_statuses(), true ) ) {
			// Sans statut restaurable certain, on ne rouvre pas l'accès.
			update_post_meta( $id, '_urbizen_status', TransactionRecovery::INCOHERENT );
			Logger::error( sprintf( 'restauration #%d : aucun statut restaurable, demande marquée incohérente', $id ) );

			return;
		}

		update_post_meta( $id, '_urbizen_status', $memoire );

		if ( $memoire !== (string) get_post_meta( $id, '_urbizen_status', true ) ) {
			// L'écriture a échoué : on ne laisse surtout pas un état
			// téléchargeable par défaut.
			update_post_meta( $id, '_urbizen_status', TransactionRecovery::INCOHERENT );
			Logger::error( sprintf( 'restauration #%d : statut non rétabli, demande marquée incohérente', $id ) );

			return;
		}

		delete_post_meta( $id, self::PRE_TRASH );
		delete_post_meta( $id, self::TRANSITION );

		Logger::info( sprintf( 'demande #%d restaurée en %s', $id, $memoire ) );
	}

	/**
	 * Le contenu est-il une demande Urbizen ?
	 *
	 * @param mixed $post Contenu.
	 * @return bool
	 */
	private static function is_ours( $post ): bool {
		return is_object( $post )
			&& isset( $post->post_type, $post->ID )
			&& SubmissionPostType::POST_TYPE === $post->post_type;
	}
}
