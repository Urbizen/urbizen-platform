<?php
/**
 * Point d'entrée de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

use Urbizen\Platform\Admin\SubmissionsAdmin;
use Urbizen\Platform\Blocks\CadastreBlock;
use Urbizen\Platform\Blocks\FormBlock;
use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Http\FileDownloadController;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Conception\ConceptionAssets;
use Urbizen\Platform\Mail\MailScheduler;
use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\TrashGuard;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrateur : enregistre les modules au fur et à mesure de leur arrivée.
 *
 * Étape 1 (actuelle) : amorçage nu. Aucun formulaire, aucune route REST,
 * aucune table, aucune écriture. Les modules sont ajoutés un par un dans
 * register_modules(), avec vérification à chaque étape.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	/**
	 * Instance unique.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructeur privé (singleton).
	 */
	private function __construct() {}

	/**
	 * Démarre l'extension si l'environnement est compatible.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		if ( ! Requirements::are_met() ) {
			add_action( 'admin_notices', array( Requirements::class, 'render_notice' ) );
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_modules();
	}

	/**
	 * Charge les traductions.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			URBIZEN_PLATFORM_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( URBIZEN_PLATFORM_FILE ) ) . '/languages'
		);
	}

	/**
	 * Enregistre les modules métier.
	 *
	 * Ordre d'arrivée prévu :
	 *   1. Blocks\CadastreBlock         — composant cadastre        ← actif
	 *   2. Blocks\FormBlock             — formulaires déclaratifs   ← actif
	 *   3. Submissions\SubmissionPostType — conservation des demandes ← actif
	 *   4. Http\SubmissionController    — réception des soumissions ← actif
	 *   5. Privacy\Retention            — purge à 365 jours         ← actif
	 *   6. Admin\SubmissionsAdmin       — liste des demandes        ← actif
	 *   7. Files\*                      — documents privés          ← actif
	 *   8. Mail\Mailer                  — notifications             ← PR B3
	 *   9. Backend\PythonClient         — génération documentaire
	 *
	 * @return void
	 */
	private function register_modules(): void {
		CadastreBlock::register();
		FormBlock::register();

		// La conservation des demandes précède leur réception : le type de
		// contenu doit exister avant qu'une soumission cherche à l'écrire.
		SubmissionPostType::register();
		SubmissionController::register();
		FileDownloadController::register();
		FileCleaner::register();
		TrashGuard::register();
		Retention::register();
		MailScheduler::register();
		ConceptionAssets::register();

		if ( is_admin() ) {
			SubmissionsAdmin::register();
		}

		Logger::debug( 'Amorçage Urbizen Platform ' . URBIZEN_PLATFORM_VERSION . ' : cadastre, formulaires, réception et documents privés actifs.' );
	}

	/**
	 * Empêche le clonage.
	 */
	private function __clone() {}

	/**
	 * Empêche la désérialisation.
	 *
	 * @throws \RuntimeException Toujours.
	 */
	public function __wakeup(): void {
		throw new \RuntimeException( 'Désérialisation interdite.' );
	}
}
