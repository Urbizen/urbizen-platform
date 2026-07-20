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

use Urbizen\Platform\Submissions\SubmissionPostType;

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
		}
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
