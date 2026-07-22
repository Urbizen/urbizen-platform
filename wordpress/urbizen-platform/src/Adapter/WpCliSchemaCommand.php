<?php
/**
 * Commande WP-CLI : `wp urbizen schema <status|migrate|verify>`.
 *
 * **Seul point d'entrée prévu pour appliquer une migration.** Elle ne fait
 * qu'une chose : traduire WP-CLI vers `MigrationRunner`, et rendre un code de
 * sortie exploitable par un script de déploiement. Aucune règle, aucun SQL,
 * aucune décision — sans quoi elle deviendrait une seconde implémentation de
 * l'exécuteur.
 *
 * Elle n'est enregistrée que sous WP-CLI. Dans une requête web, ni la classe
 * ni la commande n'existent, donc aucun chemin HTTP ne peut lancer une
 * migration.
 *
 * @package Urbizen\Platform\Adapter
 */

namespace Urbizen\Platform\Adapter;

use Urbizen\Platform\Schema\MigrationCatalogue;
use Urbizen\Platform\Schema\MigrationRunner;
use Urbizen\Platform\Schema\ResultatMigration;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Adaptation WP-CLI de l'exécuteur de migrations.
 */
final class WpCliSchemaCommand {

	/**
	 * Enregistre la commande, **uniquement sous WP-CLI**.
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

		WP_CLI::add_command( 'urbizen schema', self::class );
	}

	/**
	 * État du schéma. Lecture seule.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen schema status
	 *
	 * @return void
	 */
	public function status(): void {
		$this->rendre( $this->runner()->etat(), false );
	}

	/**
	 * Applique les migrations manquantes.
	 *
	 * Rend un code de sortie non nul en cas d'échec, afin qu'un déploiement
	 * puisse s'arrêter dessus.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen schema migrate
	 *
	 * @return void
	 */
	public function migrate(): void {
		$this->rendre( $this->runner()->executer(), true );
	}

	/**
	 * Rejoue la vérification sur toutes les migrations déclarées.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen schema verify
	 *
	 * @return void
	 */
	public function verify(): void {
		$this->rendre( $this->runner()->verifier(), true );
	}

	/**
	 * Construit l'exécuteur.
	 *
	 * @return MigrationRunner
	 */
	private function runner(): MigrationRunner {
		return new MigrationRunner( new WpdbGateway(), MigrationCatalogue::plateforme() );
	}

	/**
	 * Écrit le résultat et fixe le code de sortie.
	 *
	 * @param ResultatMigration $resultat  Résultat.
	 * @param bool              $bloquante La commande doit-elle échouer sur un échec ?
	 * @return void
	 */
	private function rendre( ResultatMigration $resultat, bool $bloquante ): void {
		$resume = $resultat->resume();

		if ( $resultat->rien_a_faire() ) {
			WP_CLI::log( $resume );
			WP_CLI::success( 'aucune requête émise' );

			return;
		}

		if ( $resultat->reussi() ) {
			WP_CLI::success( $resume );

			return;
		}

		if ( $bloquante ) {
			WP_CLI::error( $resume );

			return;
		}

		WP_CLI::warning( $resume );
	}
}
