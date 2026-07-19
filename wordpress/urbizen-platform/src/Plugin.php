<?php
/**
 * Point d'entrée de l'extension.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform;

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
	 * Volontairement vide à l'étape 1. Ordre d'arrivée prévu :
	 *   1. Support\Options       — réglages
	 *   2. Shortcodes\Cadastre   — composant cadastre
	 *   3. Forms\FormRegistry    — moteur de formulaires
	 *   4. Http\RestController   — réception des soumissions
	 *   5. Files\UploadHandler   — pièces jointes
	 *   6. Backend\PythonClient  — transmission au service de génération
	 *   7. Privacy\*             — rétention et droits RGPD
	 *   8. Admin\*               — consultation des demandes
	 *
	 * @return void
	 */
	private function register_modules(): void {
		Logger::debug( 'Amorçage Urbizen Platform ' . URBIZEN_PLATFORM_VERSION . ' : aucun module actif (étape 1).' );
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
