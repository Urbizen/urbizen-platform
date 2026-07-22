<?php
/**
 * Commande WP-CLI : `wp urbizen accounts <status|install|verify>`.
 *
 * **Seul point d'entrée qui installe le rôle.** Aucune visite, publique ou
 * d'administration, ne doit provoquer une écriture d'installation : l'état
 * d'une installation ne dépend pas du trafic. Le crochet d'activation reste
 * offert pour une installation neuve, mais un `rsync` ne le déclenche pas —
 * d'où cette commande, qui est le chemin réel d'un déploiement.
 *
 * Elle ne rend **jamais** de jeton brut, ni d'adresse.
 *
 * @package Urbizen\Platform\Adapter
 */

namespace Urbizen\Platform\Adapter;

use Urbizen\Platform\Account\RoleClient;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptation WP-CLI de l'installation des comptes.
 */
final class WpCliAccountsCommand {

	/**
	 * Enregistre la commande, uniquement sous WP-CLI.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! constant( 'WP_CLI' ) ) {
			return;
		}

		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'urbizen accounts', self::class );
	}

	/**
	 * État du rôle client. Lecture seule.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen accounts status
	 *
	 * @return void
	 */
	public function status(): void {
		$etat = RoleClient::etat();

		WP_CLI::log( sprintf( 'rôle       : %s', RoleClient::ROLE ) );
		WP_CLI::log( sprintf( 'présent    : %s', $etat['present'] ? 'oui' : 'non' ) );
		WP_CLI::log( sprintf( 'capacités  : %s', $etat['capacites'] ? implode( ', ', $etat['capacites'] ) : '—' ) );
		WP_CLI::log( sprintf( 'conforme   : %s', $etat['conforme'] ? 'oui' : 'non' ) );

		if ( '' !== $etat['motif'] ) {
			WP_CLI::log( sprintf( 'motif      : %s', $etat['motif'] ) );
		}

		WP_CLI::success( 'lecture seule : aucune écriture' );
	}

	/**
	 * Installe ou corrige le rôle client. Idempotente.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen accounts install
	 *
	 * @return void
	 */
	public function install(): void {
		if ( RoleClient::est_conforme() ) {
			WP_CLI::success( 'rôle déjà conforme : aucune écriture' );

			return;
		}

		$motif = RoleClient::installer();

		if ( '' !== $motif ) {
			WP_CLI::error( sprintf( 'installation échouée : %s', $motif ) );

			return;
		}

		WP_CLI::success( sprintf( 'rôle %s installé avec la seule capacité read', RoleClient::ROLE ) );
	}

	/**
	 * Vérifie la conformité du rôle. Lecture seule, code de sortie non nul si
	 * la configuration diverge — de quoi arrêter un déploiement.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen accounts verify
	 *
	 * @return void
	 */
	public function verify(): void {
		$motif = RoleClient::motif_de_non_conformite();

		if ( '' !== $motif ) {
			WP_CLI::error( sprintf( 'rôle non conforme : %s', $motif ) );

			return;
		}

		WP_CLI::success( 'rôle conforme' );
	}
}
