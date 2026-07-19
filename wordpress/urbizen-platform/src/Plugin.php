<?php
/**
 * Point d'entrée de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

use Urbizen\Platform\Blocks\CadastreBlock;
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
	 *   1. Blocks\CadastreBlock  — composant cadastre          ← étape 2, actif
	 *   2. Forms\FormRegistry    — moteur de formulaires
	 *   3. Http\RestController   — réception des soumissions
	 *   4. Files\UploadHandler   — pièces jointes
	 *   5. Backend\PythonClient  — transmission au service de génération
	 *   6. Privacy\*             — rétention et droits RGPD
	 *   7. Admin\*               — consultation des demandes
	 *
	 * @return void
	 */
	private function register_modules(): void {
		CadastreBlock::register();

		Logger::debug( 'Amorçage Urbizen Platform ' . URBIZEN_PLATFORM_VERSION . ' : module cadastre actif (étape 2).' );
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
