<?php
/**
 * Liste d'administration des demandes.
 *
 * Volontairement minimale en PR B1 : quatre colonnes, aucune modification,
 * aucun téléchargement, aucun renvoi de courriel.
 *
 * **Aucune donnée personnelle n'apparaît dans la liste.** Ni nom, ni adresse
 * électronique, ni téléphone, ni commune. Une liste est ce qui s'affiche le
 * plus souvent, s'imprime, se capture et se partage par erreur : elle ne doit
 * porter qu'une référence. La consultation détaillée en lecture seule viendra
 * dans une PR d'administration dédiée.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Admin;

use Urbizen\Platform\Mail\MailLockHandle;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Colonnes de la liste des demandes.
 */
final class SubmissionsAdmin {

	/**
	 * Accroche les filtres d'affichage.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter(
			'manage_' . SubmissionPostType::POST_TYPE . '_posts_columns',
			array( self::class, 'columns' )
		);
		add_action(
			'manage_' . SubmissionPostType::POST_TYPE . '_posts_custom_column',
			array( self::class, 'render_column' ),
			10,
			2
		);

		// L'action de reprise est une écriture : elle passe par POST, un nonce
		// et un contrôle de capacité. Elle ne fait que replacer la notification
		// en attente — l'envoi reste le travail du planificateur.
		add_action( 'admin_post_' . self::ACTION_RETRY, array( self::class, 'handle_retry' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * Action administrative de reprise d'une notification.
	 */
	public const ACTION_RETRY = 'urbizen_retry_notification';

	/**
	 * Action du nonce de reprise.
	 */
	public const NONCE_RETRY = 'urbizen_retry_notification_';

	/**
	 * Ajoute le lien de reprise sur les lignes éligibles.
	 *
	 * @param array<string, string> $actions Actions existantes.
	 * @param \WP_Post              $post    Contenu.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, $post ): array {
		if ( ! is_object( $post ) || SubmissionPostType::POST_TYPE !== ( $post->post_type ?? '' ) ) {
			return $actions;
		}

		$id = (int) $post->ID;

		if ( ! self::retry_allowed( $id ) ) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => self::ACTION_RETRY,
					'submission' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_RETRY . $id
		);

		$actions['urbizen_retry'] = sprintf(
			'<a href="%s" data-method="post">%s</a>',
			esc_url( $url ),
			esc_html__( 'Réessayer la notification', 'urbizen-platform' )
		);

		return $actions;
	}

	/**
	 * La reprise est-elle permise pour cette demande ?
	 *
	 * @param int $id Demande.
	 * @return bool
	 */
	public static function retry_allowed( int $id ): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$statut = (string) get_post_meta( $id, MailPolicy::META_STATUS, true );

		if ( ! in_array( $statut, array( MailPolicy::FAILED, MailPolicy::CANCELLED ), true ) ) {
			return false;
		}

		// La demande doit être éligible **aujourd'hui**, indépendamment de son
		// état de notification : on écarte le seul motif qui porte sur celui-ci.
		$motif = MailPolicy::blocker( $id );

		return null === $motif || 'mail_status_non_envoyable' === $motif;
	}

	/**
	 * Traite la demande de reprise.
	 *
	 * @return void
	 */
	public static function handle_retry(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			self::refus( 'method' );
		}

		$brut = isset( $_POST['submission'] ) ? $_POST['submission'] : ( $_GET['submission'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! is_scalar( $brut ) || 1 !== preg_match( '/^[1-9][0-9]{0,17}$/', (string) $brut ) ) {
			self::refus( 'identifiant' );
		}

		$id = (int) $brut;

		if ( ! current_user_can( 'manage_options' ) ) {
			self::refus( 'capacite' );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) && is_scalar( $_REQUEST['_wpnonce'] ) ? (string) $_REQUEST['_wpnonce'] : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_RETRY . $id ) ) {
			self::refus( 'nonce' );
		}

		if ( ! self::retry_allowed( $id ) ) {
			self::refus( 'ineligible' );
		}

		// Sous le verrou commun : deux clics simultanés ne doivent produire
		// qu'un seul état `pending` et qu'un seul événement. L'envoi n'a
		// **pas** lieu dans cette requête — une action d'administration ne doit
		// pas dépendre d'un serveur de messagerie.
		$resultat = MailQueue::with_lock(
			$id,
			static function ( MailLockHandle $poignee ) use ( $id ) {
				// Relecture sous verrou : l'état a pu changer depuis le
				// contrôle d'éligibilité, et `sent` ne se reprend jamais.
				$statut = (string) get_post_meta( $id, MailPolicy::META_STATUS, true );

				if ( ! in_array( $statut, array( MailPolicy::FAILED, MailPolicy::CANCELLED ), true ) ) {
					return false;
				}

				if ( ! MailQueue::requeue( $id ) ) {
					return false;
				}

				return MailScheduler::schedule_unique( $id, null, $poignee );
			}
		);

		if ( empty( $resultat['ok'] ) || true !== $resultat['valeur'] ) {
			self::refus( 'requeue' );
		}

		wp_safe_redirect(
			add_query_arg(
				array( 'urbizen_notification' => 'requeued' ),
				admin_url( 'edit.php?post_type=' . SubmissionPostType::POST_TYPE )
			)
		);

		exit;
	}

	/**
	 * Refuse une reprise, sans révéler la cause précise.
	 *
	 * @param string $code Code technique, journalisé seulement.
	 * @return void
	 */
	private static function refus( string $code ): void {
		Logger::error( sprintf( 'reprise de notification refusée : %s', $code ) );

		wp_safe_redirect(
			add_query_arg(
				array( 'urbizen_notification' => 'refused' ),
				admin_url( 'edit.php?post_type=' . SubmissionPostType::POST_TYPE )
			)
		);

		exit;
	}

	/**
	 * Colonnes affichées.
	 *
	 * @param array<string, string> $columns Colonnes par défaut.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		return array(
			'cb'                => isset( $columns['cb'] ) ? $columns['cb'] : '',
			'title'             => __( 'Référence', 'urbizen-platform' ),
			'urbizen_form_type' => __( 'Formulaire', 'urbizen-platform' ),
			'urbizen_status'    => __( 'Statut', 'urbizen-platform' ),
			'urbizen_files'     => __( 'Documents', 'urbizen-platform' ),
			'urbizen_created'   => __( 'Reçue le', 'urbizen-platform' ),
			'urbizen_mail'      => __( 'Notification', 'urbizen-platform' ),
		);
	}

	/**
	 * Contenu d'une colonne.
	 *
	 * @param string $column  Colonne.
	 * @param int    $post_id Demande.
	 * @return void
	 */
	public static function render_column( string $column, int $post_id ): void {
		// Double barrière : même si un filtre tiers appelait cette méthode
		// ailleurs, rien ne s'affiche sans la capacité requise.
		if ( ! current_user_can( SubmissionPostType::CAPABILITY ) ) {
			return;
		}

		switch ( $column ) {
			case 'urbizen_form_type':
				echo esc_html( (string) get_post_meta( $post_id, '_urbizen_form_type', true ) );
				break;

			case 'urbizen_status':
				echo esc_html( self::status_label( (string) get_post_meta( $post_id, '_urbizen_status', true ) ) );
				break;

			case 'urbizen_files':
				// Un décompte et une taille : ni nom de document, ni lien, ni
				// chemin. Une liste s'imprime, se capture et se partage.
				$nombre = (int) get_post_meta( $post_id, '_urbizen_files_count', true );
				$taille = (int) get_post_meta( $post_id, '_urbizen_files_total_size', true );
				$etat   = (string) get_post_meta( $post_id, '_urbizen_files_status', true );

				echo esc_html(
					0 === $nombre
						? self::files_label( $etat )
						: sprintf(
							/* translators: 1: nombre de documents, 2: taille lisible. */
							_n( '%1$d document (%2$s)', '%1$d documents (%2$s)', $nombre, 'urbizen-platform' ),
							$nombre,
							size_format( $taille )
						)
				);
				break;

			case 'urbizen_created':
				echo esc_html( (string) get_post_meta( $post_id, '_urbizen_created_at_gmt', true ) );
				break;

			case 'urbizen_mail':
				// Un état, un décompte, une date d'envoi. Jamais le
				// destinataire, jamais le corps, jamais un lien signé, jamais
				// le détail d'une erreur technique.
				$etat        = MailQueue::state( $post_id );
				$affichage   = self::mail_label( $etat['status'] );
				$tentatives  = (int) $etat['attempts'];

				if ( $tentatives > 0 ) {
					$affichage .= sprintf(
						/* translators: %d: nombre de tentatives. */
						' — ' . _n( '%d tentative', '%d tentatives', $tentatives, 'urbizen-platform' ),
						$tentatives
					);
				}

				if ( MailPolicy::SENT === $etat['status'] && '' !== $etat['sent_at'] ) {
					$affichage .= ' — ' . $etat['sent_at'];
				}

				echo esc_html( $affichage );
				break;
		}
	}

	/**
	 * Libellé lisible d'un état de notification.
	 *
	 * @param string $status État interne.
	 * @return string
	 */
	public static function mail_label( string $status ): string {
		$libelles = array(
			MailPolicy::NOT_STARTED => __( 'Non commencée', 'urbizen-platform' ),
			MailPolicy::PENDING     => __( 'En attente', 'urbizen-platform' ),
			MailPolicy::SENDING     => __( 'Envoi en cours', 'urbizen-platform' ),
			MailPolicy::RETRY       => __( 'Reprise programmée', 'urbizen-platform' ),
			MailPolicy::SENT        => __( 'Envoyée', 'urbizen-platform' ),
			MailPolicy::FAILED      => __( 'Échec', 'urbizen-platform' ),
			MailPolicy::CANCELLED   => __( 'Annulée', 'urbizen-platform' ),
		);

		return $libelles[ $status ] ?? __( 'Inconnue', 'urbizen-platform' );
	}

	/**
	 * Libellé lisible d'un état de documents.
	 *
	 * @param string $status État interne.
	 * @return string
	 */
	public static function files_label( string $status ): string {
		$libelles = array(
			'none'    => __( 'Aucun', 'urbizen-platform' ),
			'pending' => __( 'En cours', 'urbizen-platform' ),
			'stored'  => __( 'Aucun', 'urbizen-platform' ),
			'failed'  => __( 'Échec', 'urbizen-platform' ),
			'deleted' => __( 'Effacés', 'urbizen-platform' ),
		);

		return $libelles[ $status ] ?? '—';
	}

	/**
	 * Libellé lisible d'un état.
	 *
	 * @param string $status État interne.
	 * @return string
	 */
	public static function status_label( string $status ): string {
		$libelles = array(
			SubmissionPostType::STATUS_RECEIVED  => __( 'Demande reçue', 'urbizen-platform' ),
			SubmissionPostType::STATUS_CONVERTED => __( 'Devenue dossier client', 'urbizen-platform' ),
			SubmissionPostType::STATUS_CLOSED    => __( 'Close', 'urbizen-platform' ),
		);

		return $libelles[ $status ] ?? $status;
	}
}
