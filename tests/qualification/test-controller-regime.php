<?php
/**
 * Vérification du régime à travers le vrai contrôleur de soumission.
 *
 * Les bancs unitaires prouvent le calcul. Celui-ci prouve que la route DP
 * charge réellement ce calcul après le nettoyage de la définition, avant le
 * tarif et avant toute écriture. Toutes les données sont fictives.
 */

require dirname( __DIR__ ) . '/submissions/bootstrap.php';

use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Security\AntiSpam;

/**
 * Charge DP complète, hormis les caractéristiques propres au scénario.
 *
 * @param array<string, mixed> $extra Valeurs remplacées ou ajoutées.
 * @return array<string, mixed>
 */
function soumission_dp_qualification( array $extra = array() ): array {
	return array_merge(
		array(
			SubmissionController::NONCE_FIELD    => wp_create_nonce( SubmissionController::NONCE_ACTION_DP ),
			SubmissionController::TOKEN_FIELD    => AntiSpam::issue_token( wpd_now() - 60 ),
			SubmissionController::HONEYPOT_FIELD => '',
			'action'                             => SubmissionController::ACTION_DP,
			'form_type'                          => SubmissionController::FORM_TYPE_DP,
			'declarant_type'                     => 'particulier',
			'nom'                                => 'Martin',
			'prenom'                             => 'Claire',
			'qualite'                            => 'proprietaire',
			'email'                              => 'claire.martin@exemple.test',
			'telephone'                          => '0600000000',
			'mode_adresse_declarant'             => 'automatique',
			'adresse_declarant'                  => '12 rue des Lilas, 33000 Bordeaux',
			'insee_declarant'                    => '33063',
			'cp_declarant'                       => '33000',
			'ville_declarant'                    => 'Bordeaux',
			'mode_adresse'                       => 'automatique',
			'terrain_adresse'                    => '12 rue des Lilas, 33000 Bordeaux',
			'terrain_insee'                      => '33063',
			'terrain_cp'                         => '33000',
			'terrain_ville'                      => 'Bordeaux',
			'nature'                             => 'extension',
			'intervention'                       => 'existant',
			'description'                        => 'Extension d’une maison individuelle.',
			'abf'                                => 'non',
			'demolition'                         => 'non',
			'attest_exact'                       => '1',
			'attest_rgpd'                        => '1',
		),
		$extra
	);
}

/**
 * Exécute la charge sur la route DP réelle.
 *
 * @param array<string, mixed> $extra Valeurs propres au scénario.
 * @return SubmissionResult
 */
function traiter_dp_qualification( array $extra ): SubmissionResult {
	wpd_reset();

	$route = array(
		'action'       => SubmissionController::ACTION_DP,
		'form_type'    => SubmissionController::FORM_TYPE_DP,
		'nonce_action' => SubmissionController::NONCE_ACTION_DP,
	);

	return SubmissionController::process(
		soumission_dp_qualification( $extra ),
		array(),
		serveur(),
		wpd_now(),
		$route
	);
}

echo "\n── Contrôleur DP : le régime est une barrière serveur réelle\n";

$extension_pc = traiter_dp_qualification(
	array(
		'sp_creee'               => 60,
		'emprise_creee'          => 60,
		'qualification_contexte' => '{"verdict":{"status":"dp"}}',
	)
);

check(
	'extension 60 m² : la route DP renvoie validation_failed',
	SubmissionResult::VALIDATION_FAILED === $extension_pc->code()
);
check( 'extension 60 m² : l’erreur porte sur le régime', isset( $extension_pc->errors()['regime'] ) );
check( 'extension 60 m² : aucune demande n’est enregistrée', array() === $GLOBALS['wpd_posts'] );

$extension_incomplete = traiter_dp_qualification( array( 'sp_creee' => 15 ) );

check(
	'extension sans emprise : le retrait d’un déterminant est refusé',
	SubmissionResult::VALIDATION_FAILED === $extension_incomplete->code()
		&& isset( $extension_incomplete->errors()['regime'] )
);

$piscine_pc = traiter_dp_qualification(
	array(
		'nature'                 => 'piscine',
		'description'            => 'Piscine extérieure sans couverture.',
		'surface_bassin_m2'      => 120,
		'presence_abri_piscine' => 'non',
	)
);

check(
	'piscine 120 m² : les vrais champs de la route DP conduisent au refus',
	SubmissionResult::VALIDATION_FAILED === $piscine_pc->code()
		&& isset( $piscine_pc->errors()['regime'] )
);
check( 'piscine 120 m² : aucune demande n’est enregistrée', array() === $GLOBALS['wpd_posts'] );

$contexte_indicatif = '{"version":2,"parcours_id":"test-parcours","verdict":{"status":"dp"}}';
$extension_dp       = traiter_dp_qualification(
	array(
		'sp_creee'               => 15,
		'emprise_creee'          => 15,
		'qualification_contexte' => $contexte_indicatif,
	)
);

check(
	'extension 15 m² : la route DP reste acceptée',
	$extension_dp->is_success()
		&& SubmissionResult::SUCCESS === $extension_dp->code()
);

$payload_dp = json_decode( (string) get_post_meta( $extension_dp->id(), '_urbizen_payload', true ), true );

check(
	'extension 15 m² : le contexte indicatif parvient au dossier',
	is_array( $payload_dp )
		&& $contexte_indicatif === ( $payload_dp['qualification_contexte'] ?? '' )
);

echo "\n";

if ( $GLOBALS['fail'] > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $GLOBALS['fail'] );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
