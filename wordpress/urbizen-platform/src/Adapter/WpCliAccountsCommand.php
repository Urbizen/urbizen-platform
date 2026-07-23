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

use Urbizen\Platform\Account\LimiteEnvois;
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

	/**
	 * Compare la source du quota à son miroir. LECTURE SEULE par défaut.
	 *
	 * **Aucune purge.** Purger détruirait un mécanisme de sécurité, et le
	 * besoin ne le justifie pas : la sous-commande de purge envisagée un temps
	 * a été retirée. Cette commande CONSTATE.
	 *
	 * Avec `--repair-mirror`, elle réécrit le **miroir seul**, depuis la
	 * source. Elle ne touche jamais la source, ne supprime aucun créneau, et
	 * ne peut donc JAMAIS élargir un droit : sous 0.12.0 elle n'a aucun effet
	 * sur les décisions — le miroir n'est pas lu pour décider — et sous 0.11.0
	 * elle ne peut que faire REMONTER un compte vers la vérité.
	 *
	 * ## OPTIONS
	 *
	 * [--repair-mirror]
	 * : Réécrit le miroir depuis la source. La source n'est jamais modifiée.
	 *
	 * ## EXAMPLES
	 *
	 *     wp urbizen accounts quota-verify
	 *     wp urbizen accounts quota-verify --repair-mirror
	 *
	 * @param array<int, string>    $args       Arguments positionnels.
	 * @param array<string, string> $assoc_args Options.
	 * @return void
	 */
	public function quota_verify( array $args = array(), array $assoc_args = array() ): void {
		$reparer = isset( $assoc_args['repair-mirror'] );

		$divergents = 0;
		$corrompus  = 0;
		$repares    = 0;
		$examines   = 0;

		foreach ( self::comptes_a_examiner() as $compte ) {
			$examines++;

			$brut_source = get_user_meta( $compte, LimiteEnvois::META_SOURCE, true );
			$source      = LimiteEnvois::decoder_source( '' === $brut_source ? null : (string) $brut_source );

			if ( ! empty( $source['corrompue'] ) ) {
				// On NE RÉPARE PAS une source illisible : on ne saurait pas
				// quoi écrire, et écrire quand même reviendrait à choisir
				// quels créneaux oublier.
				$corrompus++;

				WP_CLI::warning( sprintf( 'compte %d : source illisible, aucune réparation possible', $compte ) );

				continue;
			}

			if ( ! empty( $source['absente'] ) ) {
				// Absente n'est pas corrompue : le compte n'a simplement
				// jamais été migré. Ce n'est pas une divergence.
				continue;
			}

			$attendu = LimiteEnvois::horodatages_de( $source['entrees'] );
			$brut_m  = get_user_meta( $compte, LimiteEnvois::META, true );
			$miroir  = LimiteEnvois::decoder( '' === $brut_m ? null : (string) $brut_m );

			$reel = ! empty( $miroir['corrompue'] ) ? null : $miroir['horodatages'];

			if ( $attendu === $reel ) {
				continue;
			}

			$divergents++;

			WP_CLI::warning( sprintf( 'compte %d : miroir divergent', $compte ) );

			if ( ! $reparer ) {
				continue;
			}

			// Le MIROIR SEUL est réécrit, depuis la source.
			update_user_meta( $compte, LimiteEnvois::META, LimiteEnvois::encoder( $attendu ) );

			$repares++;
		}

		WP_CLI::log( sprintf( 'comptes examinés : %d', $examines ) );
		WP_CLI::log( sprintf( 'miroirs divergents : %d', $divergents ) );
		WP_CLI::log( sprintf( 'sources illisibles : %d', $corrompus ) );

		if ( $reparer ) {
			WP_CLI::log( sprintf( 'miroirs réécrits : %d', $repares ) );
		}

		// Code de sortie non nul en cas de divergence : de quoi arrêter un
		// déploiement, ou vérifier avant un retour arrière.
		if ( $divergents > $repares || $corrompus > 0 ) {
			WP_CLI::error( 'divergence constatée' );

			return;
		}

		WP_CLI::success(
			$reparer ? 'miroirs alignés sur leur source' : 'lecture seule : aucune écriture'
		);
	}

	/**
	 * Identifiants des comptes portant un quota.
	 *
	 * @return array<int, int>
	 */
	private static function comptes_a_examiner(): array {
		$ids = get_users(
			array(
				'fields'       => 'ID',
				'meta_query'   => array(
					'relation' => 'OR',
					array( 'key' => LimiteEnvois::META_SOURCE, 'compare' => 'EXISTS' ),
					array( 'key' => LimiteEnvois::META, 'compare' => 'EXISTS' ),
				),
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
