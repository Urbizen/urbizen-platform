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
AntiSpam::reserve_token( $rejoue, $maintenant );
AntiSpam::consume_token( $rejoue, $maintenant );

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
	$r::reserve( 'conception', $s, wpd_now() );
}

check( '6 · limite relevée → la sixième passe', null !== $r::reserve( 'conception', $s, wpd_now() ) );
check( '6 · le dépôt bloque toujours à cinq', 5 === RateLimiter::DEFAULT_MAX );

wpd_reset();
for ( $i = 1; $i <= 5; $i++ ) {
	RateLimiter::reserve( 'conception', $s, wpd_now() );
}
check( '6 · le dépôt refuse la sixième', null === RateLimiter::reserve( 'conception', $s, wpd_now() ) );

// ================================= 7 · l'adresse IP brute est enregistrée ===
$r = mutant(
	'src/Security/RateLimiter.php',
	'RateLimiter',
	array(
		'return self::OPTION_PREFIX . substr(
			hash_hmac( \'sha256\', $bucket . \'|\' . $origine, self::secret() ),
			0,
			32
		);' => "return self::OPTION_PREFIX . \$bucket . '_' . \$origine;",
	)
);

wpd_reset();
$r::reserve( 'conception', $s, wpd_now() );
$fuite = wp_json_encode( array_keys( $GLOBALS['wpd_options'] ) );

check( '7 · condensat retiré → l’adresse apparaît dans les options', str_contains( (string) $fuite, '203.0.113.10' ) );

wpd_reset();
RateLimiter::reserve( 'conception', $s, wpd_now() );
$propre = wp_json_encode( array_keys( $GLOBALS['wpd_options'] ) );

check( '7 · le dépôt ne laisse aucune adresse dans les options', ! str_contains( (string) $propre, '203.0.113.10' ) );

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
check( '8 · muté, le compartiment change avec l’en-tête', $r::key( 'conception', $menteur ) !== $r::key( 'conception', $s ) );

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
				self::release_reference( $reference );
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
		'		// --- 13 · la demande existe : le jeton et le créneau sont acquis ---' =>
		'		wp_mail( \'contact@exemple.test\', \'Nouvelle demande\', $creation[\'reference\'] );

		// --- 13 · la demande existe : le jeton et le créneau sont acquis ---',
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

// ======================================================================
// MUTATIONS DE LA REVUE : ATOMICITÉ ET PLANIFICATION
// ======================================================================

// ============= 18 · la réservation redevient une séquence lire-puis-écrire ==
// C'est le mécanisme d'origine : vérifier, puis marquer. Entre les deux
// s'ouvre une fenêtre par laquelle deux requêtes passent toutes les deux.
$a = mutant(
	'src/Security/AntiSpam.php',
	'AntiSpam',
	array(
		'		$existante = get_option( $cle, null );

		if ( is_array( $existante ) ) {
			// Une réservation périmée se recycle. Ce chemin ne concerne en
			// pratique que des jetons déjà refusés par le contrôle de date : il
			// existe pour que le nettoyage ne soit jamais indispensable.
			if ( isset( $existante[\'expires\'] ) && $now >= (int) $existante[\'expires\'] ) {
				delete_option( $cle );
			} else {
				return false;
			}
		}
' => '',
		'		return (bool) add_option(
			$cle,
			array(
				\'state\'   => \'reserved\',
				\'expires\' => $now + self::MAX_AGE,
			),
			\'\',
			false
		);' =>
		'		// Le mécanisme d\'origine : marquer, sans arbitrer. Les deux requêtes
		// ont déjà passé le contrôle is_used() de verify_token(), et rien ici
		// ne les départage.
		update_option( $cle, array( \'state\' => \'reserved\', \'expires\' => $now + self::MAX_AGE ), false );

		return true;',
	)
);

$jeton_a = AntiSpam::issue_token( wpd_now() - 60 );
wpd_reset();

// Deux requêtes concurrentes : sans add_option, les deux réussissent.
check( '18 · séquence lire-puis-écrire → les deux requêtes réservent',
	$a::reserve_token( $jeton_a, wpd_now() ) && $a::reserve_token( $jeton_a, wpd_now() ) );

wpd_reset();
check( '18 · le dépôt n’en laisse passer qu’une',
	AntiSpam::reserve_token( $jeton_a, wpd_now() ) && ! AntiSpam::reserve_token( $jeton_a, wpd_now() ) );

// ================= 19 · le jeton consommé repose de nouveau sur un transient =
$a = mutant(
	'src/Security/AntiSpam.php',
	'AntiSpam',
	array(
		'	public static function is_used( string $token, ?int $now = null ): bool {
		$now       = null === $now ? time() : $now;
		$existante = get_option( self::option_key( $token ), null );' =>
		'	public static function is_used( string $token, ?int $now = null ): bool {
		$now       = null === $now ? time() : $now;
		$existante = get_transient( self::option_key( $token ) );
		$existante = is_array( $existante ) ? $existante : null;',
	)
);

wpd_reset();
$jeton_t = AntiSpam::issue_token( wpd_now() - 60 );
set_transient( AntiSpam::option_key( $jeton_t ), array( 'state' => 'consumed', 'expires' => wpd_now() + 86400 ), 86400 );

check( '19 · sur transient → le jeton est bien vu comme consommé', $a::is_used( $jeton_t, wpd_now() ) );

wpd_purger_caches();

check( '19 · UNE PURGE DE CACHE LE REND REJOUABLE', ! $a::is_used( $jeton_t, wpd_now() ) );

wpd_reset();
AntiSpam::reserve_token( $jeton_t, wpd_now() );
AntiSpam::consume_token( $jeton_t, wpd_now() );
wpd_purger_caches();

check( '19 · le dépôt résiste à la purge : c’est une option', AntiSpam::is_used( $jeton_t, wpd_now() ) );

// ============ 20 · une réservation est conservée après validation échouée ===
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'			RateLimiter::release( $creneau );
			AntiSpam::release_token( $jeton );' => '			// libération neutralisée.',
	)
);

neuf();
$mauvais = soumission( array( 'nature' => 'chateau_fort' ) );
$c::process( $mauvais, array(), serveur(), wpd_now() );
$mauvais['nature'] = 'maison';

check( '20 · libération neutralisée → le jeton reste brûlé après une faute de saisie',
	SubmissionResult::DUPLICATE_SUBMISSION === $c::process( $mauvais, array(), serveur(), wpd_now() )->code() );

neuf();
$corrigeable = soumission( array( 'nature' => 'chateau_fort' ) );
traiter( $corrigeable );
$corrigeable['nature'] = 'maison';

check( '20 · le dépôt rend le jeton : la correction aboutit', traiter( $corrigeable )->is_success() );

// ============ 21 · le créneau n'est pas libéré après échec =================
neuf();

for ( $i = 1; $i <= 5; $i++ ) {
	$c::process( soumission( array( 'nature' => 'chateau_fort' ) ), array(), serveur(), wpd_now() );
}

check( '21 · libération neutralisée → cinq erreurs épuisent le quota',
	SubmissionResult::RATE_LIMITED === $c::process( soumission(), array(), serveur(), wpd_now() )->code() );

neuf();

for ( $i = 1; $i <= 5; $i++ ) {
	traiter( soumission( array( 'nature' => 'chateau_fort' ) ) );
}

check( '21 · le dépôt rend les créneaux : la demande valide passe', traiter( soumission() )->is_success() );
check( '21 · aucun créneau n’a été consommé', 0 === RateLimiter::used( 'conception', serveur(), wpd_now() ) - 1 );

// ============ 22 · deux requêtes peuvent recevoir la même référence ========
$d = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array(
		'			if ( ! self::reserve_reference( $reference, $now ) ) {
				continue;
			}' => '			// réservation neutralisée : le compteur seul décide.',
	)
);

neuf();
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$ref_a = $d::next_reference( wpd_now() );
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$ref_b = $d::next_reference( wpd_now() );

check( '22 · réservation retirée → deux requêtes obtiennent la même référence', $ref_a === $ref_b );

neuf();
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$sain_a = SubmissionRepository::next_reference( wpd_now() );
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$sain_b = SubmissionRepository::next_reference( wpd_now() );

check( '22 · le dépôt en donne deux distinctes', $sain_a !== $sain_b );
check( '22 · et dans l’ordre attendu', str_ends_with( $sain_a, '-0001' ) && str_ends_with( $sain_b, '-0002' ) );

// ============ 23 · le compteur seul redevient la garantie d'unicité ========
// En retirant AUSSI la vérification en base, plus rien ne protège.
$d = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array(
		'			if ( self::reference_exists( $reference ) ) {
				continue;
			}

			if ( ! self::reserve_reference( $reference, $now ) ) {
				continue;
			}' => '			// les deux barrières sont neutralisées.',
	)
);

neuf();
$ancienne = wp_insert_post( array( 'post_type' => SubmissionPostType::POST_TYPE, 'post_status' => 'private' ) );
update_post_meta( $ancienne, '_urbizen_reference', 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001' );

check( '23 · barrières retirées → une référence historique est réattribuée',
	str_ends_with( $d::next_reference( wpd_now() ), '-0001' ) );

neuf();
$ancienne = wp_insert_post( array( 'post_type' => SubmissionPostType::POST_TYPE, 'post_status' => 'private' ) );
update_post_meta( $ancienne, '_urbizen_reference', 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001' );

check( '23 · le dépôt évite la référence historique',
	! str_ends_with( SubmissionRepository::next_reference( wpd_now() ), '-0001' ) );

// ============ 24 · l'échec de persistance laisse une référence bloquée =====
$d = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array(
		'				self::release_reference( $reference );
				Logger::error( sprintf( \'demande %s : métadonnée' => '				Logger::error( sprintf( \'demande %s : métadonnée',
	)
);

neuf();
$def_m = \Urbizen\Platform\Forms\FormRegistry::get( 'conception' );
$val_m = \Urbizen\Platform\Forms\Validator::validate(
	$def_m,
	array( 'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non', 'nom' => 'Camille Fictif', 'email' => 'camille@exemple.test', 'rgpd' => '1' )
);

$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$d::create( $val_m['clean'], $val_m['pricing'], array( 'now' => wpd_now() ) );
$GLOBALS['wpd_meta_fail'] = '';

check( '24 · libération retirée → la référence reste réservée après échec',
	null !== get_option( SubmissionRepository::RESERVATION_PREFIX . 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001', null ) );

neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
SubmissionRepository::create( $val_m['clean'], $val_m['pricing'], array( 'now' => wpd_now() ) );
$GLOBALS['wpd_meta_fail'] = '';

check( '24 · le dépôt libère la référence',
	null === get_option( SubmissionRepository::RESERVATION_PREFIX . 'URB-' . gmdate( 'Y', wpd_now() ) . '-0001', null ) );

// ============ 25 · la rétention dépend uniquement de l'activation ==========
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array( "		add_action( 'init', array( self::class, 'ensure_scheduled' ) );" => '		// programmation retirée du chargement.' )
);

wpd_reset();
$p::register();
do_action( 'init' );

check( '25 · sans appel au chargement → une mise à jour ne programme rien',
	false === wp_next_scheduled( Retention::HOOK ) );

wpd_reset();
Retention::register();
do_action( 'init' );

check( '25 · le dépôt programme la tâche sans réactivation', false !== wp_next_scheduled( Retention::HOOK ) );

// ============ 26 · deux tâches de rétention sont programmées ===============
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}' => '		// contrôle d\'existence retiré.',
	)
);

// On compte les appels réels à wp_schedule_event, et non les horodatages :
// deux programmations successives dans la même seconde donneraient la même
// date, et le contrôle passerait à tort.
wpd_reset();
$p::ensure_scheduled();
$p::ensure_scheduled();
$p::ensure_scheduled();

check( '26 · contrôle retiré → la tâche est reprogrammée à chaque appel',
	3 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

wpd_reset();
Retention::ensure_scheduled();
Retention::ensure_scheduled();
Retention::ensure_scheduled();

check( '26 · le dépôt ne programme qu’une seule fois',
	1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );
check( '26 · une seule tâche existe', 1 === count( $GLOBALS['wpd_cron'] ) );

// ============ 27 · une option technique passe en autoload=true =============
$a = mutant(
	'src/Security/AntiSpam.php',
	'AntiSpam',
	array(
		'			array(
				\'state\'   => \'reserved\',
				\'expires\' => $now + self::MAX_AGE,
			),
			\'\',
			false
		);' =>
		'			array(
				\'state\'   => \'reserved\',
				\'expires\' => $now + self::MAX_AGE,
			),
			\'\',
			true
		);',
	)
);

wpd_reset();
$jeton_al = AntiSpam::issue_token( wpd_now() - 60 );
$a::reserve_token( $jeton_al, wpd_now() );

check( '27 · muté → la réservation devient autoloadée', 'yes' === wpd_autoload( AntiSpam::option_key( $jeton_al ) ) );

wpd_reset();
AntiSpam::reserve_token( $jeton_al, wpd_now() );

check( '27 · le dépôt n’autoloade jamais une réservation', 'no' === wpd_autoload( AntiSpam::option_key( $jeton_al ) ) );

// ============ 28 · les réservations expirées ne sont jamais nettoyées ======
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		"			'jetons'     => AntiSpam::cleanup_expired_tokens( \$now )," => "			'jetons'     => 0,",
		"			'creneaux'   => RateLimiter::cleanup_expired_slots( \$now )," => "			'creneaux'   => 0,",
		"			'references' => SubmissionRepository::cleanup_abandoned_references( \$now )," => "			'references' => 0,",
	)
);

neuf();
AntiSpam::reserve_token( AntiSpam::issue_token( wpd_now() ), wpd_now() );
RateLimiter::reserve( 'conception', serveur(), wpd_now() );
SubmissionRepository::reserve_reference( 'URB-2026-0800', wpd_now() );

$plus_tard = wpd_now() + AntiSpam::MAX_AGE + 1;
$p::run_daily( $plus_tard );

$restantes_mutees = count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => preg_match( '/^urbizen_(tok|rl|ref)_/', $c ) ) );

check( '28 · nettoyage retiré → les réservations s’accumulent', 3 === $restantes_mutees );

neuf();
AntiSpam::reserve_token( AntiSpam::issue_token( wpd_now() ), wpd_now() );
RateLimiter::reserve( 'conception', serveur(), wpd_now() );
SubmissionRepository::reserve_reference( 'URB-2026-0800', wpd_now() );

Retention::run_daily( $plus_tard );

check( '28 · le dépôt les nettoie toutes',
	0 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $c ) => preg_match( '/^urbizen_(tok|rl|ref)_/', $c ) ) ) );

// ============ 29 · six requêtes concurrentes créent six demandes ===========
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'		$creneau = RateLimiter::reserve( self::FORM_TYPE, $server, $now );

		if ( null === $creneau ) {' => '		$creneau = RateLimiter::reserve( self::FORM_TYPE, $server, $now );

		if ( false ) {',
	)
);

neuf();
$creees = 0;

for ( $i = 1; $i <= 6; $i++ ) {
	if ( $c::process( soumission(), array(), serveur(), wpd_now() )->is_success() ) {
		++$creees;
	}
}

check( '29 · limite neutralisée → six demandes sont créées', 6 === $creees );

neuf();
$creees = 0;

for ( $i = 1; $i <= 6; $i++ ) {
	if ( traiter( soumission() )->is_success() ) {
		++$creees;
	}
}

check( '29 · le dépôt en crée exactement cinq', 5 === $creees );
check( '29 · cinq demandes en base', 5 === count( $GLOBALS['wpd_posts'] ) );

verdict();
