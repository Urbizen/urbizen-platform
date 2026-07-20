<?php
/**
 * Suppression des documents avec leur demande.
 *
 * Les fichiers doivent disparaître **avec** la demande, jamais lui survivre :
 * un document orphelin est une donnée personnelle que plus rien ne rattache à
 * une personne, donc que plus rien ne permet d'effacer sur demande.
 *
 * Le branchement se fait sur `urbizen_before_submission_delete`, déclenché
 * pendant que la demande existe encore — c'est le seul moment où ses
 * métadonnées permettent de retrouver ses fichiers.
 *
 * **Le hook transmet deux arguments.** WordPress plafonne ce qu'il transmet au
 * nombre déclaré : l'oublier livrerait un identifiant sans référence.
 *
 * @package Urbizen\Platform\Files
 */

namespace Urbizen\Platform\Files;

use Urbizen\Platform\Submissions\SubmissionRepository;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Effacement des documents rattachés à une demande.
 */
final class FileCleaner {

	/**
	 * Accroche l'effacement.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'urbizen_before_submission_delete', array( self::class, 'purge' ), 10, 2 );

		// Une demande supprimée à la main dans l'administration doit emporter
		// ses documents au même titre qu'une purge automatique.
		add_action( 'before_delete_post', array( self::class, 'on_post_delete' ), 10, 1 );
	}

	/**
	 * Efface les documents d'une demande.
	 *
	 * Idempotente : un second appel ne produit ni erreur, ni effet.
	 *
	 * @param int    $submission Identifiant de la demande.
	 * @param string $reference  Référence.
	 * @return int Nombre de documents effacés.
	 */
	public static function purge( int $submission, string $reference ): int {
		if ( $submission <= 0 || ! Storage::is_reference( $reference ) ) {
			return 0;
		}

		$files = SubmissionRepository::decode_files( $submission );

		if ( array() === $files ) {
			return 0;
		}

		$effaces = Storage::delete_files( $reference, $files );

		// Les métadonnées partent avec les fichiers : garder la liste d'un
		// document effacé, c'est garder le nom d'origine que le client avait
		// donné à sa photographie.
		update_post_meta( $submission, '_urbizen_files', (string) wp_json_encode( array() ) );
		update_post_meta( $submission, '_urbizen_files_count', 0 );
		update_post_meta( $submission, '_urbizen_files_total_size', 0 );
		update_post_meta( $submission, '_urbizen_files_status', 'deleted' );

		Logger::info( sprintf( 'demande %s : %d document(s) effacé(s)', $reference, $effaces ) );

		return $effaces;
	}

	/**
	 * Rattrape une suppression opérée hors de la rétention.
	 *
	 * @param int $post_id Contenu supprimé.
	 * @return void
	 */
	public static function on_post_delete( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || \Urbizen\Platform\Submissions\SubmissionPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$reference = (string) get_post_meta( $post_id, '_urbizen_reference', true );

		if ( '' === $reference ) {
			return;
		}

		self::purge( $post_id, $reference );
	}
}
