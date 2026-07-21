<?php
/**
 * Poignée d'un verrou de processus détenu.
 *
 * Elle porte la ressource ouverte : c'est **elle** qui matérialise la
 * détention. Tant que ce descripteur vit, le système d'exploitation garantit
 * qu'aucun autre processus n'obtiendra le verrou ; s'il disparaît — fin
 * normale, coupure, `kill -9` —, le verrou est libéré sans que personne ait à
 * s'en occuper.
 *
 * C'est toute la différence avec un bail temporel : une échéance dépassée ne
 * prouve rien sur la vie du propriétaire.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

defined( 'ABSPATH' ) || exit;

/**
 * Détention vivante d'un verrou de notification.
 */
final class MailLockHandle {

	/**
	 * Descripteur ouvert et verrouillé.
	 *
	 * @var resource|null
	 */
	private $ressource;

	/**
	 * Demande concernée.
	 */
	private int $submission;

	/**
	 * Chemin technique du fichier de verrou.
	 */
	private string $chemin;

	/**
	 * Jeton propriétaire de l'option technique complémentaire.
	 */
	private string $jeton;

	/**
	 * @param resource $ressource  Descripteur verrouillé.
	 * @param int      $submission Demande.
	 * @param string   $chemin     Chemin technique.
	 * @param string   $jeton      Jeton de l'option.
	 */
	public function __construct( $ressource, int $submission, string $chemin, string $jeton ) {
		$this->ressource  = $ressource;
		$this->submission = $submission;
		$this->chemin     = $chemin;
		$this->jeton      = $jeton;
	}

	/**
	 * Demande concernée.
	 *
	 * @return int
	 */
	public function submission(): int {
		return $this->submission;
	}

	/**
	 * Jeton propriétaire de l'option complémentaire.
	 *
	 * @return string
	 */
	public function jeton(): string {
		return $this->jeton;
	}

	/**
	 * Remplace le jeton, lorsque l'option est réconciliée sous le mutex.
	 *
	 * @param string $jeton Nouveau jeton.
	 * @return void
	 */
	public function set_jeton( string $jeton ): void {
		$this->jeton = $jeton;
	}

	/**
	 * Chemin technique, sans donnée personnelle.
	 *
	 * @return string
	 */
	public function chemin(): string {
		return $this->chemin;
	}

	/**
	 * Le verrou est-il toujours détenu par cette poignée ?
	 *
	 * @return bool
	 */
	public function est_detenu(): bool {
		return is_resource( $this->ressource );
	}

	/**
	 * Ressource sous-jacente.
	 *
	 * @return resource|null
	 */
	public function ressource() {
		return $this->ressource;
	}

	/**
	 * Libère le verrou.
	 *
	 * Le fichier n'est **pas** supprimé : sur un système POSIX, supprimer puis
	 * recréer un chemin pendant qu'un autre processus détient encore un
	 * descripteur sur l'ancien inode donnerait deux verrous indépendants
	 * portant le même nom.
	 *
	 * @return void
	 */
	public function liberer(): void {
		if ( ! is_resource( $this->ressource ) ) {
			return;
		}

		@flock( $this->ressource, LOCK_UN );
		@fclose( $this->ressource );

		$this->ressource = null;
	}
}
