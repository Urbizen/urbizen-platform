<?php
/**
 * Exclusion mutuelle liée à la **vie du processus**.
 *
 * Le verrou d'option est un bail : il dit qu'un propriétaire a pris la main
 * jusqu'à telle heure. Il ne dit rien de sa vie. Or `max_execution_time` ne
 * comptabilise pas, sur un système non Windows, le temps passé dans certaines
 * opérations système — flux, réseau, appels externes. Un envoi bloqué dans un
 * transport peut donc survivre à son propre bail, et deux processus se croire
 * simultanément légitimes.
 *
 * `flock()` répond exactement à la question que le bail ne sait pas poser :
 * **ce processus est-il encore là ?** La détention est attachée au descripteur
 * ouvert ; le noyau la libère à la disparition du processus, y compris sur un
 * `kill -9`, et la refuse tant qu'il vit.
 *
 * Vérifié sur l'environnement cible : ext4 local, refus inter-processus pendant
 * la vie du propriétaire, libération automatique après terminaison forcée.
 *
 * Les fichiers techniques vivent sous la racine privée B2, dont la résolution
 * n'est **pas** dupliquée ici. Leur nom est dérivé par HMAC : il ne révèle ni
 * identifiant de notification, ni référence, ni quoi que ce soit de personnel.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Mutex de notification, adossé au système de fichiers.
 */
final class MailProcessLock {

	/**
	 * Sous-répertoire technique, sous la racine privée.
	 */
	public const SOUS_DOSSIER = 'locks/mail';

	/**
	 * Prend le mutex d'une demande.
	 *
	 * Fermé par défaut : toute impossibilité technique — racine indisponible,
	 * répertoire non créable, chemin non confiné, lien symbolique, ouverture
	 * refusée — rend `null`, et l'appelant doit renoncer plutôt que se rabattre
	 * sur le bail d'option.
	 *
	 * @param int $submission Demande.
	 * @return MailLockHandle|null Poignée détenue, ou `null`.
	 */
	public static function acquire( int $submission ): ?MailLockHandle {
		if ( $submission <= 0 ) {
			return null;
		}

		$chemin = self::chemin( $submission );

		if ( null === $chemin ) {
			return null;
		}

		// Un lien symbolique à la place du fichier technique ferait verrouiller
		// autre chose que ce qu'on croit. On refuse, sans le suivre.
		if ( is_link( $chemin ) ) {
			Logger::error( sprintf( 'mutex #%d refusé : le chemin technique est un lien symbolique', $submission ) );

			return null;
		}

		$ressource = @fopen( $chemin, 'c' );

		if ( ! is_resource( $ressource ) ) {
			Logger::error( sprintf( 'mutex #%d refusé : ouverture impossible', $submission ) );

			return null;
		}

		// Contrôle **après** ouverture : le fichier existe désormais, et son
		// chemin réel doit toujours se situer dans le répertoire technique.
		$reel = realpath( $chemin );

		if ( false === $reel || ! self::est_confine( $reel ) ) {
			@fclose( $ressource );
			Logger::error( sprintf( 'mutex #%d refusé : chemin non confiné', $submission ) );

			return null;
		}

		@chmod( $chemin, Storage::FILE_MODE );

		if ( ! @flock( $ressource, LOCK_EX | LOCK_NB ) ) {
			// Contention normale : un autre processus travaille. Ce n'est pas
			// une anomalie, et cela ne se journalise pas comme telle.
			@fclose( $ressource );

			return null;
		}

		return new MailLockHandle( $ressource, $submission, $reel, '' );
	}

	/**
	 * Le mutex est-il détenu par un autre processus ?
	 *
	 * Répond en tentant de le prendre puis en le rendant aussitôt : c'est la
	 * seule manière de savoir, et elle ne ment jamais sur un propriétaire mort.
	 *
	 * Rend `true` également lorsque le mutex ne peut pas être évalué : dans le
	 * doute, on considère qu'une opération est en cours et on ne touche à rien.
	 *
	 * @param int $submission Demande.
	 * @return bool
	 */
	public static function is_held( int $submission ): bool {
		$chemin = self::chemin( $submission );

		if ( null === $chemin ) {
			// Impossible de se prononcer : fermé par défaut.
			return true;
		}

		if ( is_link( $chemin ) ) {
			return true;
		}

		$ressource = @fopen( $chemin, 'c' );

		if ( ! is_resource( $ressource ) ) {
			return true;
		}

		$libre = (bool) @flock( $ressource, LOCK_EX | LOCK_NB );

		if ( $libre ) {
			@flock( $ressource, LOCK_UN );
		}

		@fclose( $ressource );

		return ! $libre;
	}

	/**
	 * Rend le mutex.
	 *
	 * @param MailLockHandle|null $poignee Poignée.
	 * @return void
	 */
	public static function release( ?MailLockHandle $poignee ): void {
		if ( null !== $poignee ) {
			$poignee->liberer();
		}
	}

	/**
	 * Supprime le fichier technique d'une demande.
	 *
	 * **Uniquement** lorsque le mutex vient d'être obtenu, donc qu'aucun autre
	 * processus ne le détient ni ne l'attend. Réservé à la suppression
	 * définitive d'une demande.
	 *
	 * @param MailLockHandle $poignee Poignée détenue.
	 * @return bool
	 */
	public static function discard( MailLockHandle $poignee ): bool {
		if ( ! $poignee->est_detenu() ) {
			return false;
		}

		$chemin = $poignee->chemin();

		if ( '' === $chemin || ! self::est_confine( $chemin ) ) {
			return false;
		}

		// Le descripteur est rendu **avant** l'unlink : le fichier disparaît,
		// mais plus personne ne détient d'inode dessus.
		$poignee->liberer();

		return (bool) @unlink( $chemin );
	}

	/**
	 * Chemin technique d'une demande.
	 *
	 * @param int $submission Demande.
	 * @return string|null
	 */
	public static function chemin( int $submission ): ?string {
		$dossier = self::dossier();

		if ( null === $dossier ) {
			return null;
		}

		// Nom dérivé : ni identifiant de notification en clair, ni référence,
		// ni rien qui puisse être rapproché d'une personne.
		$nom = hash_hmac( 'sha256', 'mail-lock|' . $submission, wp_salt( 'auth' ) );

		return $dossier . '/' . $nom . '.lock';
	}

	/**
	 * Répertoire technique, créé si nécessaire.
	 *
	 * @return string|null
	 */
	public static function dossier(): ?string {
		$racine = Storage::root();

		if ( null === $racine ) {
			return null;
		}

		$chemin = $racine . '/' . self::SOUS_DOSSIER;

		if ( ! is_dir( $chemin ) ) {
			if ( ! @mkdir( $chemin, Storage::DIR_MODE, true ) && ! is_dir( $chemin ) ) {
				Logger::error( 'répertoire technique des verrous non créable' );

				return null;
			}

			@chmod( $chemin, Storage::DIR_MODE );
		}

		if ( is_link( $chemin ) ) {
			Logger::error( 'répertoire technique des verrous : lien symbolique refusé' );

			return null;
		}

		$reel = realpath( $chemin );

		if ( false === $reel ) {
			return null;
		}

		// Confinement : le répertoire doit rester sous la racine privée.
		$racine_reelle = realpath( $racine );

		if ( false === $racine_reelle || 0 !== strpos( $reel . '/', rtrim( $racine_reelle, '/' ) . '/' ) ) {
			Logger::error( 'répertoire technique des verrous hors de la racine privée' );

			return null;
		}

		return $reel;
	}

	/**
	 * Le chemin est-il bien dans le répertoire technique ?
	 *
	 * @param string $chemin Chemin réel.
	 * @return bool
	 */
	private static function est_confine( string $chemin ): bool {
		$dossier = self::dossier();

		if ( null === $dossier ) {
			return false;
		}

		return 0 === strpos( $chemin, rtrim( $dossier, '/' ) . '/' );
	}
}
