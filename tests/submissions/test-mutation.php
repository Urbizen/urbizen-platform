<?php
/**
 * Banc de mutation de la réception des demandes.
 *
 * Un contrôle vert ne prouve rien tant qu'on n'a pas vu ce qui le fait rougir.
 * Chaque scénario casse **une** règle — dans une copie, jamais dans le dépôt —
 * et vérifie que le contrôle correspondant tombe.
 *
 * Les fichiers mutés sont écrits dans le répertoire temporaire du système et
 * détruits immédiatement après chargement.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Privacy\Retention;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

$compteur = 0;

/**
 * Charge une copie mutée d'une classe du plugin.
 *
 * @param string                $relatif       Chemin sous le plugin.
 * @param string                $classe        Classe d'origine.
 * @param array<string, string> $remplacements Motif exact => remplacement.
 * @return string Classe mutée, pleinement qualifiée.
 */
function mutant( string $relatif, string $classe, array $remplacements ): string {
	global $compteur;

	$source  = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $relatif );
	$nouveau = $classe . 'Mutant' . ( ++$compteur );

	// La classe est renommée, et toutes ses auto-références avec elle.
	$source = str_replace( "final class $classe", "final class $nouveau", $source );

	foreach ( $remplacements as $de => $vers ) {
		if ( ! str_contains( $source, $de ) ) {
			throw new RuntimeException( "motif introuvable dans $relatif : $de" );
		}

		$source = str_replace( $de, $vers, $source );
	}

	preg_match( '/^namespace\s+([^;]+);/m', $source, $ns );

	$fichier = sys_get_temp_dir() . '/urbizen-' . $nouveau . '.php';
	file_put_contents( $fichier, $source );
	require $fichier;
	unlink( $fichier );

	return '\\' . trim( $ns[1] ) . '\\' . $nouveau;
}

/**
 * Repart d'un état propre.
 */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

echo "Chaque ligne vérifie qu'une règle cassée fait bien tomber son contrôle.\n\n";

// =========================================== 1 · le nonce n'est plus vérifié
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array( "if ( '' === \$nonce || ! wp_verify_nonce( \$nonce, self::NONCE_ACTION ) ) {" => 'if ( false ) {' )
);

neuf();
$sans_nonce = soumission();
unset( $sans_nonce[ SubmissionController::NONCE_FIELD ] );

check( '1 · nonce non vérifié → une requête sans nonce passe',
	$c::process( $sans_nonce, array(), serveur(), wpd_now() )->is_success() );

neuf();
check( '1 · le dépôt refuse une requête sans nonce',
	SubmissionResult::INVALID_NONCE === traiter( $sans_nonce )->code() );

// ======================================= 2 · le pot de miel n'est plus vérifié
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array( "if ( '' !== \$miel ) {" => 'if ( false ) {' )
);

neuf();
$piege = soumission( array( SubmissionController::HONEYPOT_FIELD => 'robot' ) );

check( '2 · pot de miel ignoré → un robot passe', $c::process( $piege, array(), serveur(), wpd_now() )->is_success() );

neuf();
check( '2 · le dépôt refuse le robot', SubmissionResult::SPAM_HONEYPOT === traiter( $piege )->code() );

// ================================= 3 · la signature du jeton est ignorée ====
$a = mutant(
	'src/Security/AntiSpam.php',
	'AntiSpam',
	array( 'if ( ! hash_equals( $attendue, $signature ) ) {' => 'if ( false ) {' )
);

$maintenant = wpd_now();
$jeton      = AntiSpam::issue_token( $maintenant - 60 );
list( $id, $emis, $sig ) = explode( '.', $jeton );
$contrefait = $id . '.' . $emis . '.' . str_repeat( 'f', 64 );

check( '3 · signature ignorée → un jeton contrefait passe', $a::verify_token( $contrefait, $maintenant )['ok'] );
check( '3 · le dépôt refuse le jeton contrefait', ! AntiSpam::verify_token( $contrefait, $maintenant )['ok'] );

// Reculer l'heure d'émission redevient possible sans signature vérifiée.
$antidate = $id . '.' . ( (int) $emis - 100000 ) . '.' . $sig;
check( '3 · signature ignorée → une antidatation devient exploitable',
	'invalid_antispam_token' !== $a::verify_token( $antidate, $maintenant )['code'] );
check( '3 · le dépôt refuse l’antidatation', 'invalid_antispam_token' === AntiSpam::verify_token( $antidate, $maintenant )['code'] );

// ==================================== 4 · le délai minimal est retiré =======
$a = mutant(
	'src/Security/AntiSpam.php',
	'AntiSpam',
	array( 'if ( $age < self::min_seconds() ) {' => 'if ( false ) {' )
);

$instantane = AntiSpam::issue_token( $maintenant );

check( '4 · délai retiré → une soumission instantanée passe', $a::verify_token( $instantane, $maintenant )['ok'] );
check( '4 · le dépôt refuse la soumission instantanée', 'token_too_fast' === AntiSpam::verify_token( $instantane, $maintenant )['code'] );

// ====================================== 5 · un jeton peut être réutilisé ====
$a = mutant(
	'src/Security/AntiSpam.php',
	'AntiSpam',
	array( 'if ( self::is_used( $token ) ) {' => 'if ( false ) {' )
);

$rejoue = AntiSpam::issue_token( $maintenant - 60 );
AntiSpam::mark_used( $rejoue );

check( '5 · contrôle de réemploi retiré → le jeton se rejoue', $a::verify_token( $rejoue, $maintenant )['ok'] );
check( '5 · le dépôt refuse le rejeu', 'duplicate_submission' === AntiSpam::verify_token( $rejoue, $maintenant )['code'] );

neuf();
$post = soumission();
traiter( $post );
check( '5 · le dépôt refuse la double soumission complète',
	SubmissionResult::DUPLICATE_SUBMISSION === traiter( $post )->code() );

// ============================== 6 · la limite passe à une valeur non prévue =
$r = mutant(
	'src/Security/RateLimiter.php',
	'RateLimiter',
	array( 'public const DEFAULT_MAX = 5;' => 'public const DEFAULT_MAX = 500;' )
);

wpd_reset();
$s = serveur();

for ( $i = 1; $i <= 5; $i++ ) {
	$r::allow( 'conception', $s, wpd_now() );
}

check( '6 · limite relevée → la sixième passe', $r::allow( 'conception', $s, wpd_now() ) );
check( '6 · le dépôt bloque toujours à cinq', 5 === RateLimiter::DEFAULT_MAX );

wpd_reset();
for ( $i = 1; $i <= 5; $i++ ) {
	RateLimiter::allow( 'conception', $s, wpd_now() );
}
check( '6 · le dépôt refuse la sixième', ! RateLimiter::allow( 'conception', $s, wpd_now() ) );

// ================================= 7 · l'adresse IP brute est enregistrée ===
$r = mutant(
	'src/Security/RateLimiter.php',
	'RateLimiter',
	array(
		'return self::PREFIX . substr(
			hash_hmac( \'sha256\', $bucket . \'|\' . $origine, self::secret() ),
			0,
			40
		);' => "return self::PREFIX . \$bucket . '_' . \$origine;",
	)
);

wpd_reset();
$r::allow( 'conception', $s, wpd_now() );
$fuite = wp_json_encode( $GLOBALS['wpd_transients'] );

check( '7 · condensat retiré → l’adresse apparaît dans les transients', str_contains( (string) $fuite, '203.0.113.10' ) );

wpd_reset();
RateLimiter::allow( 'conception', $s, wpd_now() );
$propre = wp_json_encode( $GLOBALS['wpd_transients'] );

check( '7 · le dépôt ne laisse aucune adresse dans les transients', ! str_contains( (string) $propre, '203.0.113.10' ) );

// ================== 8 · X-Forwarded-For devient automatiquement fiable ======
$r = mutant(
	'src/Security/RateLimiter.php',
	'RateLimiter',
	array( "\$entete = (string) apply_filters( 'urbizen_trusted_proxy_header', '' );" => "\$entete = 'X-Forwarded-For';" )
);

$menteur = serveur( array( 'HTTP_X_FORWARDED_FOR' => '198.51.100.99' ) );

check( '8 · en-tête cru sur parole → l’origine devient celle annoncée', '198.51.100.99' === $r::origin( $menteur ) );
check( '8 · le dépôt s’en tient à REMOTE_ADDR', '203.0.113.10' === RateLimiter::origin( $menteur ) );
check( '8 · le dépôt donne la même clé quel que soit l’en-tête',
	RateLimiter::key( 'conception', $menteur ) === RateLimiter::key( 'conception', $s ) );
check( '8 · muté, la clé change avec l’en-tête', $r::key( 'conception', $menteur ) !== $r::key( 'conception', $s ) );

// ========================= 9 · les fichiers sont silencieusement ignorés ====
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array( 'if ( self::has_files( $files ) ) {' => 'if ( false ) {' )
);

$fichier = array(
	'croquis_plans' => array(
		'name'     => array( 'plan.pdf' ),
		'tmp_name' => array( '/tmp/phpfictif' ),
		'error'    => array( UPLOAD_ERR_OK ),
		'size'     => array( 1024 ),
	),
);

neuf();
check( '9 · refus retiré → un fichier passe en silence', $c::process( soumission(), $fichier, serveur(), wpd_now() )->is_success() );

neuf();
check( '9 · le dépôt refuse explicitement',
	SubmissionResult::FILES_NOT_SUPPORTED_YET === traiter( soumission(), $fichier )->code() );

// ============================ 10 · un prix client est stocké ================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'$creation = SubmissionRepository::create(
			$validation[\'clean\'],
			$pricing,' => '$pricing["total"] = isset( $post["urbizen_total"] ) ? (int) $post["urbizen_total"] : $pricing["total"];
		$creation = SubmissionRepository::create(
			$validation[\'clean\'],
			$pricing,',
	)
);

neuf();
$falsifie = soumission( array( 'options_tarifees' => array( 'facades' ), 'urbizen_total' => '1' ) );
$rm       = $c::process( $falsifie, array(), serveur(), wpd_now() );
$pm       = json_decode( (string) get_post_meta( $rm->id(), '_urbizen_pricing', true ), true );

check( '10 · prix client accepté → le total tombe à 1 €', 1 === $pm['total'] );

neuf();
$rs = traiter( $falsifie );
$ps = json_decode( (string) get_post_meta( $rs->id(), '_urbizen_pricing', true ), true );

check( '10 · le dépôt recalcule et stocke 598 €', 598 === $ps['total'] );
check( '10 · le total soumis n’apparaît nulle part',
	! str_contains( (string) wp_json_encode( $GLOBALS['wpd_meta'][ $rs->id() ] ), 'urbizen_total' ) );

// ============================ 11 · le payload brut est conservé =============
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array( "\$validation['clean'],\n\t\t\t\$pricing," => "\$post,\n\t\t\t\$pricing," )
);

neuf();
$avec_inconnus = soumission( array( 'prix_total' => '0', 'pente' => 'plat' ) );
$rm            = $c::process( $avec_inconnus, array(), serveur(), wpd_now() );
$payload_mute  = (string) get_post_meta( $rm->id(), '_urbizen_payload', true );

check( '11 · payload brut → le nonce se retrouve stocké', str_contains( $payload_mute, 'urbizen_conception_nonce' ) );
check( '11 · payload brut → le jeton se retrouve stocké', str_contains( $payload_mute, 'urbizen_token' ) );
check( '11 · payload brut → un champ inconnu se retrouve stocké', str_contains( $payload_mute, 'prix_total' ) );

neuf();
$rs           = traiter( $avec_inconnus );
$payload_sain = (string) get_post_meta( $rs->id(), '_urbizen_payload', true );

check( '11 · le dépôt ne stocke ni nonce ni jeton',
	! str_contains( $payload_sain, 'urbizen_conception_nonce' ) && ! str_contains( $payload_sain, 'urbizen_token' ) );
check( '11 · le dépôt ne stocke aucun champ inconnu', ! str_contains( $payload_sain, 'prix_total' ) );
check( '11 · le dépôt ne stocke pas la branche inactive', ! str_contains( $payload_sain, 'pente' ) );

// ================== 12 · une demande partielle subsiste après échec =========
$d = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array(
		'				wp_delete_post( $id, true );
				Logger::error( sprintf( \'demande %s : métadonnée « %s » non écrite, demande supprimée\', $reference, $cle ) );' =>
		'				Logger::error( \'retour arrière neutralisé\' );',
	)
);

neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$d::create( array( 'rgpd' => true ), array( 'base' => 449, 'total' => 449 ), array( 'now' => wpd_now() ) );

check( '12 · retour arrière neutralisé → une demande amputée subsiste', 1 === count( $GLOBALS['wpd_posts'] ) );

neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
SubmissionRepository::create( array( 'rgpd' => true ), array( 'base' => 449, 'total' => 449 ), array( 'now' => wpd_now() ) );

check( '12 · le dépôt supprime la demande amputée', array() === $GLOBALS['wpd_posts'] );
$GLOBALS['wpd_meta_fail'] = '';

// ============================== 13 · le CPT devient public ==================
$t = mutant(
	'src/Submissions/SubmissionPostType.php',
	'SubmissionPostType',
	array(
		"'public'              => false," => "'public'              => true,",
		"'show_in_rest'        => false," => "'show_in_rest'        => true,",
	)
);

wpd_reset();
$t::register_post_type();
$args_mutes = wpd_post_type_args( 'urbizen_demande' );

check( '13 · muté → le type devient public', true === $args_mutes['public'] );
check( '13 · muté → le type entre dans l’API REST', true === $args_mutes['show_in_rest'] );

wpd_reset();
SubmissionPostType::register_post_type();
$args_sains = wpd_post_type_args( 'urbizen_demande' );

check( '13 · le dépôt garde le type privé', false === $args_sains['public'] );
check( '13 · le dépôt le tient hors de l’API REST', false === $args_sains['show_in_rest'] );
check( '13 · le dépôt le tient hors des recherches', true === $args_sains['exclude_from_search'] );

// ============ 14 · une demande convertie est supprimée automatiquement ======
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'return array( SubmissionPostType::STATUS_RECEIVED, SubmissionPostType::STATUS_CLOSED );' =>
		'return array( SubmissionPostType::STATUS_RECEIVED, SubmissionPostType::STATUS_CLOSED, SubmissionPostType::STATUS_CONVERTED );',
	)
);

/**
 * Crée une demande d'essai.
 *
 * @param string $statut État.
 * @param int    $jours  Ancienneté.
 * @return int
 */
function poser( string $statut, int $jours ): int {
	$id = wp_insert_post( array( 'post_type' => SubmissionPostType::POST_TYPE, 'post_status' => 'private' ) );
	update_post_meta( $id, '_urbizen_status', $statut );
	update_post_meta( $id, '_urbizen_reference', 'URB-2026-0001' );
	update_post_meta( $id, '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( $jours * 86400 ) ) );
	return (int) $id;
}

neuf();
$client_mute = poser( 'converted', 400 );
$p::purge( wpd_now() );

check( '14 · converted rendu purgeable → le dossier client disparaît', null === get_post( $client_mute ) );

neuf();
$client_sain = poser( 'converted', 400 );
Retention::purge( wpd_now() );

check( '14 · le dépôt conserve le dossier client', null !== get_post( $client_sain ) );

// ================= 15 · un journal contient une donnée personnelle ==========
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		"'soumission %s refusée : %s (%d champ(s) en erreur)'," =>
		"'soumission %s refusée : %s (%d champ(s) en erreur) [' . implode( ',', array_keys( \$result->errors() ) ) . ']',",
	)
);

neuf();
$c::process( soumission( array( 'nature' => 'chateau_fort' ) ), array(), serveur(), wpd_now() );

check( '15 · muté → le journal nomme les champs fautifs', str_contains( journal(), 'nature' ) );

neuf();
traiter( soumission( array( 'nature' => 'chateau_fort', 'email' => 'camille@exemple.test' ) ) );

check( '15 · le dépôt ne nomme aucun champ dans le journal', ! str_contains( journal(), '[nature]' ) );
check( '15 · le dépôt ne journalise aucune adresse', ! str_contains( journal(), 'camille@exemple.test' ) );

// ============================== 16 · wp_mail est appelé =====================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'		// --- 13 · le jeton est consommé, et une seule fois ---' =>
		'		wp_mail( \'contact@exemple.test\', \'Nouvelle demande\', $creation[\'reference\'] );

		// --- 13 · le jeton est consommé, et une seule fois ---',
	)
);

neuf();
$c::process( soumission(), array(), serveur(), wpd_now() );

check( '16 · muté → un courriel part', 1 === count( $GLOBALS['wpd_mails'] ) );

neuf();
traiter( soumission() );

check( '16 · le dépôt n’envoie aucun courriel', array() === $GLOBALS['wpd_mails'] );

// =============== 17 · le formulaire conception devient visible ==============
$renderer = mutant(
	'src/Forms/Renderer.php',
	'Renderer',
	array( 'if ( array() !== $def->steps() ) {' => 'if ( false ) {' )
);

$conception = \Urbizen\Platform\Forms\FormRegistry::get( 'conception' );

$renderer::reset_instances();
$rendu_mute = $renderer::render( $conception );

\Urbizen\Platform\Forms\Renderer::reset_instances();
$rendu_sain = \Urbizen\Platform\Forms\Renderer::render( $conception );

check( '17 · garde-fou retiré → le formulaire se rend publiquement', '' !== $rendu_mute );
check( '17 · garde-fou retiré → les champs sortent à plat', substr_count( $rendu_mute, '<input' ) > 20 );
check( '17 · le dépôt ne rend rien', '' === $rendu_sain );

verdict();
