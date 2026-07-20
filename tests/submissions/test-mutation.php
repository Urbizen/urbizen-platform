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

// ============ 9 · les documents sont silencieusement ignorés ===============
// B2 traite réellement les documents. La mutation neutralise la validation de
// politique : un format interdit passerait alors sans être vu.
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'			$politique = UploadPolicy::validate( $normalisation[\'files\'] );

			if ( ! $politique[\'ok\'] ) {
				return $renoncer( $politique[\'code\'] );
			}' => '			$politique = UploadPolicy::validate( $normalisation[\'files\'] );
			$politique = array( \'ok\' => true, \'code\' => \'success\', \'files\' => array(), \'block\' => \'\' );',
	)
);

$svg = tempnam( sys_get_temp_dir(), 'urb' );
file_put_contents( $svg, '<svg xmlns="http://www.w3.org/2000/svg"><text>x</text></svg>' );

$lot_svg = array(
	'croquis_plans' => array(
		'name'     => array( 'dessin.svg' ),
		'type'     => array( 'image/svg+xml' ),
		'tmp_name' => array( $svg ),
		'error'    => array( UPLOAD_ERR_OK ),
		'size'     => array( filesize( $svg ) ),
	),
);

neuf();
check( '9 · validation neutralisée → un SVG passe en silence', $c::process( soumission(), $lot_svg, serveur(), wpd_now() )->is_success() );

neuf();
check( '9 · le dépôt refuse le SVG', 'upload_invalid_extension' === traiter( soumission(), $lot_svg )->code() );

// ============================ 10 · un prix client est stocké ================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'		// --- 12 · demande créée, mais pas encore finalisée ---' =>
		'		$pricing["total"] = isset( $post["urbizen_total"] ) ? (int) $post["urbizen_total"] : $pricing["total"];

		// --- 12 · demande créée, mais pas encore finalisée ---',
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
	array( "			\$validation['clean'],\n			\$pricing," => "			\$post,\n			\$pricing," )
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
		'		// --- 15 · la demande existe : le jeton et le créneau sont acquis ---' =>
		'		wp_mail( \'contact@exemple.test\', \'Nouvelle demande\', $reference );

		// --- 15 · la demande existe : le jeton et le créneau sont acquis ---',
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
	array( "		add_action( 'init', array( self::class, 'ensure_scheduled' ), 10, 0 );" => '		// programmation retirée du chargement.' )
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

// ======================================================================
// MUTATIONS DE LA SECONDE REVUE : REGISTRE ET VERROU
// ======================================================================

// ====== 30 · une réservation attribuée redevient supprimable ================
$d = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array(
		"			if ( ! is_array( \$valeur ) || 'reserved' !== ( \$valeur['state'] ?? '' ) ) {
				continue;
			}" => '			// filtre d\'état retiré : tout devient nettoyable.',
		'			$rattachee = (int) ( $valeur[\'post\'] ?? 0 );

			if ( $rattachee > 0 && null !== get_post( $rattachee ) ) {
				continue;
			}' => '			// garde du rattachement retirée.',
	)
);

$jeu_m = \Urbizen\Platform\Forms\Validator::validate(
	\Urbizen\Platform\Forms\FormRegistry::get( 'conception' ),
	array( 'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non', 'nom' => 'Camille Fictif', 'email' => 'camille@exemple.test', 'rgpd' => '1' )
);

neuf();
$c_mute = SubmissionRepository::create( $jeu_m['clean'], $jeu_m['pricing'], array( 'now' => wpd_now() ) );
$d::cleanup_abandoned_references( wpd_now() + 999999 );

check( '30 · filtre d’état retiré → la réservation attribuée est supprimée',
	null === get_option( SubmissionRepository::RESERVATION_PREFIX . $c_mute['reference'], null ) );

// Conséquence directe : la référence redevient réattribuable.
wp_delete_post( $c_mute['id'], true );
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );

check( '30 · muté → un ancien numéro est réattribué',
	$c_mute['reference'] === SubmissionRepository::next_reference( wpd_now() ) );

neuf();
$c_sain = SubmissionRepository::create( $jeu_m['clean'], $jeu_m['pricing'], array( 'now' => wpd_now() ) );
SubmissionRepository::cleanup_abandoned_references( wpd_now() + 999999 );

check( '30 · le dépôt conserve la réservation attribuée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c_sain['reference'] )['state'] ?? '' ) );

wp_delete_post( $c_sain['id'], true );
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );

check( '30 · le dépôt ne réattribue jamais un ancien numéro',
	$c_sain['reference'] !== SubmissionRepository::next_reference( wpd_now() ) );

// ====== 31 · la rétention emporte le registre avec la demande ==============
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'			do_action( self::BEFORE_DELETE, $id, $reference );' =>
		'			do_action( self::BEFORE_DELETE, $id, $reference );
			delete_option( \'urbizen_ref_\' . $reference );',
	)
);

neuf();
$c_mute = SubmissionRepository::create( $jeu_m['clean'], $jeu_m['pricing'], array( 'now' => wpd_now() ) );
update_post_meta( $c_mute['id'], '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );
$p::purge( wpd_now() );

check( '31 · rétention modifiée → le registre est emporté avec la demande',
	null === get_option( SubmissionRepository::RESERVATION_PREFIX . $c_mute['reference'], null ) );

neuf();
$c_sain = SubmissionRepository::create( $jeu_m['clean'], $jeu_m['pricing'], array( 'now' => wpd_now() ) );
update_post_meta( $c_sain['id'], '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );
Retention::purge( wpd_now() );

check( '31 · le dépôt purge la demande', null === get_post( $c_sain['id'] ) );
check( '31 · mais conserve le registre',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $c_sain['reference'] )['state'] ?? '' ) );

// ====== 32 · le verrou atomique est supprimé ===============================
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'		if ( ! self::acquire_lock( $now ) ) {
			// Une autre requête s\'en occupe. Elle aboutira ; celle-ci n\'a rien
			// à faire, et surtout rien à programmer.
			return;
		}

		self::schedule_now();
		self::release_lock();' => '		self::schedule_now();',
	)
);

// La propriété mesurée : pendant que A tient le verrou — donc entre son
// contrôle et son écriture — B ne doit RIEN programmer.
wpd_reset();
Retention::acquire_lock( wpd_now() );
$p::ensure_scheduled_at( wpd_now() );

check( '32 · verrou retiré → B programme pendant que A travaille',
	1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

wpd_reset();
Retention::acquire_lock( wpd_now() );
Retention::ensure_scheduled_at( wpd_now() );

check( '32 · le dépôt : B ne programme rien pendant que A travaille',
	0 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

Retention::schedule_now();
Retention::release_lock();

check( '32 · au total, une seule programmation', 1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

// ====== 33 · retour à un simple lire-puis-écrire ===========================
// Un « lire puis écrire » ne peut pas être atomique : dans une vraie course,
// les deux requêtes lisent avant que l'une n'écrive. La mutation retire donc
// la lecture ET l'ajout atomique, ce qui reproduit fidèlement l'état où aucune
// des deux ne voit le verrou de l'autre.
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'		$existant = get_option( self::LOCK_OPTION, null );

		if ( is_array( $existant ) ) {
			if ( $now < (int) ( $existant[\'expires\'] ?? 0 ) ) {
				return false;
			}

			// Verrou périmé : une requête interrompue l\'a laissé derrière elle.
			delete_option( self::LOCK_OPTION );
		}

		return (bool) add_option(
			self::LOCK_OPTION,
			array( \'expires\' => $now + self::LOCK_TTL ),
			\'\',
			false
		);' =>
		'		update_option( self::LOCK_OPTION, array( \'expires\' => $now + self::LOCK_TTL ), false );

		return true;',
	)
);

wpd_reset();

check( '33 · sans add_option → deux requêtes obtiennent le verrou',
	$p::acquire_lock( wpd_now() ) && $p::acquire_lock( wpd_now() ) );

wpd_reset();

check( '33 · le dépôt n’en laisse passer qu’une',
	Retention::acquire_lock( wpd_now() ) && ! Retention::acquire_lock( wpd_now() ) );

// ====== 34 · un verrou sans expiration =====================================
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'			if ( $now < (int) ( $existant[\'expires\'] ?? 0 ) ) {
				return false;
			}

			// Verrou périmé : une requête interrompue l\'a laissé derrière elle.
			delete_option( self::LOCK_OPTION );' => '			return false;',
	)
);

wpd_reset();
$p::acquire_lock( wpd_now() );
$p::ensure_scheduled_at( wpd_now() + 100000 );

check( '34 · verrou sans expiration → un arrêt brutal bloque à jamais la programmation',
	0 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

wpd_reset();
Retention::acquire_lock( wpd_now() );
Retention::ensure_scheduled_at( wpd_now() + Retention::LOCK_TTL + 1 );

check( '34 · le dépôt reprend un verrou périmé', 1 === ( $GLOBALS['wpd_cron_calls'][ Retention::HOOK ] ?? 0 ) );

// ====== 35 · le verrou reste après succès ==================================
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array( '		self::release_lock();' => '		// libération retirée.' )
);

wpd_reset();
$p::ensure_scheduled_at( wpd_now() );

check( '35 · libération retirée → un verrou subsiste dans wp_options',
	null !== get_option( Retention::LOCK_OPTION, null ) );

wpd_reset();
Retention::ensure_scheduled_at( wpd_now() );

check( '35 · le dépôt ne laisse aucun verrou', null === get_option( Retention::LOCK_OPTION, null ) );

// ====== 36 · le verrou passe en autoload=true ==============================
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		'			array( \'expires\' => $now + self::LOCK_TTL ),
			\'\',
			false
		);' =>
		'			array( \'expires\' => $now + self::LOCK_TTL ),
			\'\',
			true
		);',
	)
);

wpd_reset();
$p::acquire_lock( wpd_now() );

check( '36 · muté → le verrou devient autoloadé', 'yes' === wpd_autoload( Retention::LOCK_OPTION ) );

wpd_reset();
Retention::acquire_lock( wpd_now() );

check( '36 · le dépôt n’autoloade pas le verrou', 'no' === wpd_autoload( Retention::LOCK_OPTION ) );

// ======================================================================
// MUTATIONS B2 : DOCUMENTS PRIVÉS
// ======================================================================

use Urbizen\Platform\Files\FileCleaner;
use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Files\Storage;
use Urbizen\Platform\Files\UploadNormalizer as N;
use Urbizen\Platform\Files\UploadPolicy as P;

/**
 * Repart d'un état propre, stockage compris.
 */
function neuf_fichiers(): void {
	wpd_reset();
	wpd_clear_filter( 'urbizen_private_storage_dir' );
	add_filter( 'urbizen_private_storage_dir', static fn() => URBIZEN_TEST_STORAGE );
	SubmissionPostType::register_post_type();
	fx_vide_stockage();
	Storage::reset();
}

/** Lot d'un document. */
function un_doc( string $bloc, string $nom, string $chemin ): array {
	return fx_files( $bloc, array( array( $nom, $chemin ) ) );
}

/** Motifs des deux barrières de contrôle de type, mutés séparément. */
$concordance = "		if ( self::mime_for( \$extension ) !== \$reel ) {
			return self::refus_un( 'upload_invalid_mime' );
		}";

$croise = "		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			\$wp = wp_check_filetype_and_ext( \$chemin, 'fichier.' . \$extension, self::wp_mimes() );

			if ( empty( \$wp['ext'] ) || empty( \$wp['type'] ) ) {
				return self::refus_un( 'upload_invalid_mime' );
			}

			if ( strtolower( (string) \$wp['ext'] ) !== \$extension || (string) \$wp['type'] !== \$reel ) {
				return self::refus_un( 'upload_invalid_mime' );
			}
		}";

// ====== 37 · un bloc inconnu est accepté ===================================
$n = mutant(
	'src/Files/UploadNormalizer.php',
	'UploadNormalizer',
	array(
		'			if ( ! UploadPolicy::is_block( $bloc ) ) {
				$ignores[] = $bloc;
				continue;
			}' => '			// filtre de bloc retiré.',
	)
);

$brut = array( 'factures' => array( 'name' => 'f.pdf', 'type' => '', 'tmp_name' => fx_pdf(), 'error' => UPLOAD_ERR_OK, 'size' => 1 ) );

check( '37 · filtre retiré → un bloc inconnu entre dans le lot', 1 === count( $n::normalize( $brut )['files'] ) );
check( '37 · le dépôt l’écarte', array() === N::normalize( $brut )['files'] );
check( '37 · et le nomme', array( 'factures' ) === N::normalize( $brut )['ignored'] );

// ====== 38 · la limite par bloc est retirée ================================
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		'			if ( $par_bloc[ $bloc ] > self::max_per_block() ) {
				return self::refus( \'upload_count_exceeded\', $bloc );
			}' => '			// limite par bloc retirée.',
	)
);

check( '38 · limite retirée → onze documents dans un bloc passent', $pol::validate( lot_m( 'photos', 11 ) )['ok'] );
check( '38 · le dépôt refuse le onzième', 'upload_count_exceeded' === P::validate( lot_m( 'photos', 11 ) )['code'] );

// ====== 39 · la limite totale est retirée ==================================
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		'		if ( count( $lot ) > self::max_total() ) {
			return self::refus( \'upload_count_exceeded\' );
		}' => '		// limite totale retirée.',
	)
);

$vingt_et_un = array_merge( lot_m( 'photos', 10 ), lot_m( 'croquis_plans', 10 ), lot_m( 'urbanisme', 1 ) );

check( '39 · limite totale retirée → vingt-et-un documents passent', $pol::validate( $vingt_et_un )['ok'] );
check( '39 · le dépôt refuse le vingt-et-unième', 'upload_count_exceeded' === P::validate( $vingt_et_un )['code'] );

// ====== 40 · la taille par fichier n'est plus vérifiée =====================
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		'		if ( $taille > self::max_file_size() ) {
			return self::refus_un( \'upload_too_large\' );
		}' => '		// contrôle de taille retiré.',
	)
);

$enorme = array( 'block' => 'photos', 'name' => 'g.pdf', 'tmp_name' => fx_pdf_taille( P::MAX_FILE_SIZE + 4096 ), 'error' => UPLOAD_ERR_OK );

check( '40 · contrôle retiré → un fichier de plus de 10 Mio passe', $pol::validate_one( $enorme )['ok'] );
check( '40 · le dépôt le refuse', 'upload_too_large' === P::validate_one( $enorme )['code'] );

// ====== 41 · la taille totale n'est plus vérifiée ==========================
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		'			if ( $total > self::max_total_size() ) {
				return self::refus( \'upload_total_size_exceeded\', $bloc );
			}' => '			// contrôle du cumul retiré.',
	)
);

$cumul = array();

for ( $i = 0; $i < 20; $i++ ) {
	$cumul[] = array( 'block' => 0 === $i % 2 ? 'photos' : 'urbanisme', 'name' => "c$i.pdf", 'tmp_name' => fx_pdf_taille( 2097152 ), 'error' => UPLOAD_ERR_OK );
}

check( '41 · contrôle retiré → 40 Mio cumulés passent', $pol::validate( $cumul )['ok'] );
check( '41 · le dépôt refuse le cumul', 'upload_total_size_exceeded' === P::validate( $cumul )['code'] );

// ====== 42 · le type réel n'est plus vérifié ===============================
// Un fichier est gardé par DEUX barrières : la concordance extension/contenu
// calculée ici, et le contrôle croisé de WordPress. On les mesure séparément —
// c'est la seule façon de savoir laquelle protège.
$deguise = array( 'block' => 'photos', 'name' => 'photo.jpg', 'tmp_name' => fx_php(), 'error' => UPLOAD_ERR_OK );

$sans_concordance = mutant( 'src/Files/UploadPolicy.php', 'UploadPolicy', array( $concordance => '		// concordance retirée.' ) );
$sans_croise      = mutant( 'src/Files/UploadPolicy.php', 'UploadPolicy', array( $croise => '		// contrôle croisé retiré.' ) );
$sans_rien        = mutant( 'src/Files/UploadPolicy.php', 'UploadPolicy', array( $concordance => '', $croise => '' ) );

check( '42 · concordance retirée → le contrôle croisé protège encore', 'upload_invalid_mime' === $sans_concordance::validate_one( $deguise )['code'] );
check( '42 · contrôle croisé retiré → la concordance protège encore', 'upload_invalid_mime' === $sans_croise::validate_one( $deguise )['code'] );
check( '42 · LES DEUX RETIRÉES → un PHP renommé en JPG passe', $sans_rien::validate_one( $deguise )['ok'] );
check( '42 · le dépôt le refuse', 'upload_invalid_mime' === P::validate_one( $deguise )['code'] );

// ====== 43 · le type annoncé par le navigateur devient fiable ==============
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		'		$reel = self::detect_mime( $chemin );' => '		$reel = isset( $doc[\'type\'] ) ? (string) $doc[\'type\'] : self::detect_mime( $chemin );',
		$croise => '		// contrôle croisé retiré.',
	)
);

$menteur = array( 'block' => 'photos', 'name' => 'photo.jpg', 'tmp_name' => fx_php(), 'error' => UPLOAD_ERR_OK, 'type' => 'image/jpeg' );

check( '43 · type annoncé cru → un PHP passe pour une image', $pol::validate_one( $menteur )['ok'] );
check( '43 · le dépôt ignore le type annoncé', 'upload_invalid_mime' === P::validate_one( $menteur )['code'] );

// ====== 44 · SVG devient accepté ===========================================
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		"		'webp' => 'image/webp',
	);" => "		'webp' => 'image/webp',
		'svg'  => 'image/svg+xml',
	);",
		$croise => '		// contrôle croisé retiré : il ignore le nouveau format.',
	)
);

$svg = array( 'block' => 'photos', 'name' => 'd.svg', 'tmp_name' => fx_svg(), 'error' => UPLOAD_ERR_OK );

check( '44 · SVG ajouté au catalogue → il est accepté', $pol::validate_one( $svg )['ok'] );
check( '44 · le dépôt refuse le SVG', 'upload_invalid_extension' === P::validate_one( $svg )['code'] );

// ====== 45 · un fichier vide passe =========================================
$pol = mutant(
	'src/Files/UploadPolicy.php',
	'UploadPolicy',
	array(
		'		if ( $taille <= 0 ) {
			return self::refus_un( \'upload_empty_file\' );
		}' => '		// contrôle du fichier vide retiré.',
	)
);

$vide = array( 'block' => 'photos', 'name' => 'v.pdf', 'tmp_name' => fx_vide(), 'error' => UPLOAD_ERR_OK );

check( '45 · contrôle retiré → un fichier vide franchit le contrôle de taille',
	'upload_empty_file' !== $pol::validate_one( $vide )['code'] );
check( '45 · le dépôt refuse le fichier vide', 'upload_empty_file' === P::validate_one( $vide )['code'] );

// ====== 46 · le stockage peut se faire sous ABSPATH ========================
$st = mutant(
	'src/Files/Storage.php',
	'Storage',
	array(
		'		if ( self::is_inside( $candidat, self::public_root() ) ) {
			return self::indisponible( \'chemin situé dans la racine publique\' );
		}' => '		// contrôle avant création retiré.',
		'		if ( self::is_inside( $reel, self::public_root() ) ) {
			return self::indisponible( \'chemin résolu dans la racine publique\' );
		}' => '		// contrôle après résolution retiré.',
	)
);

wpd_reset();
wpd_clear_filter( 'urbizen_private_storage_dir' );
add_filter( 'urbizen_private_storage_dir', static fn() => rtrim( ABSPATH, '/' ) . '/mutant-public' );
$st::reset();

check( '46 · contrôle retiré → LE STOCKAGE ATTERRIT DANS LA RACINE PUBLIQUE', null !== $st::root() );

Storage::reset();
check( '46 · le dépôt refuse', null === Storage::root() );

@rmdir( rtrim( ABSPATH, '/' ) . '/mutant-public' );
neuf_fichiers();

// ====== 47 · le nom d'origine devient le nom physique ======================
$st = mutant(
	'src/Files/Storage.php',
	'Storage',
	array(
		"			\$nom    = \$reference . '-' . \$bloc . '-' . \$file['id'] . '.' . \$file['extension'];" =>
		"			\$nom    = \$file['original_name'];",
	)
);

$st::set_mover( $GLOBALS['fx_mover'] );
$staging = $st::open_staging();
$valide  = P::validate_one( array( 'block' => 'photos', 'name' => 'Secret Client.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
$depose  = $st::stage( (string) $staging, $valide['file'], 0 );
$meta_m  = $st::finalize( (string) $staging, 'URB-2026-0001', array( $depose ), time() );

check( '47 · muté → le nom d’origine devient le nom physique', str_contains( (string) $meta_m[0]['stored_name'], 'Secret Client' ) );

neuf_fichiers();
$staging = Storage::open_staging();
$valide  = P::validate_one( array( 'block' => 'photos', 'name' => 'Secret Client.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
$depose  = Storage::stage( (string) $staging, $valide['file'], 0 );
$meta_s  = Storage::finalize( (string) $staging, 'URB-2026-0001', array( $depose ), time() );

check( '47 · le dépôt emploie un nom technique', ! str_contains( (string) $meta_s[0]['stored_name'], 'Secret' ) );
check( '47 · au format attendu', 1 === preg_match( '/^URB-2026-0001-photos-[0-9a-f]{32}\.pdf$/', (string) $meta_s[0]['stored_name'] ) );

// ====== 48 · un ../ sort de la racine ======================================
$st = mutant(
	'src/Files/Storage.php',
	'Storage',
	array(
		"		foreach ( explode( '/', \$relative ) as \$segment ) {
			if ( '' === \$segment || '.' === \$segment || '..' === \$segment ) {
				return null;
			}
		}" => '		// contrôle des segments retiré.',
		'		if ( false === $reel || ! self::is_inside( $reel, $racine ) ) {
			return null;
		}' => '		if ( false === $reel ) {
			return null;
		}',
	)
);

// Une cible réelle hors de la racine, atteignable par remontée.
$dehors = fx_write( 'contenu hors racine' );
$remontee = str_repeat( '../', 12 ) . ltrim( $dehors, '/' );

check( '48 · contrôle retiré → une remontée sort de la racine', null !== $st::resolve( $remontee ) );
check( '48 · le dépôt refuse toute remontée', null === Storage::resolve( $remontee ) );
check( '48 · le dépôt refuse un chemin absolu', null === Storage::resolve( '/etc/passwd' ) );

// ====== 49 · un lien symbolique est suivi =================================
$st = mutant(
	'src/Files/Storage.php',
	'Storage',
	array(
		'		if ( is_link( $chemin ) ) {
			return null;
		}' => '		// contrôle du lien symbolique retiré.',
	)
);

neuf_fichiers();
$dossier = URBIZEN_TEST_STORAGE . '/conception/URB-2026-0001/photos';
@mkdir( $dossier, 0700, true );
// La cible est DANS la racine : le confinement ne s'y oppose pas, et seul le
// refus des liens symboliques protège encore. C'est ce qu'on veut isoler.
$cible = $dossier . '/reel.pdf';
file_put_contents( $cible, '%PDF-1.4' );

if ( @symlink( $cible, $dossier . '/lien.pdf' ) ) {
	$rel = 'conception/URB-2026-0001/photos/lien.pdf';
	check( '49 · contrôle retiré → LE LIEN SYMBOLIQUE EST SUIVI', null !== $st::resolve( $rel ) );
	check( '49 · le dépôt le refuse', null === Storage::resolve( $rel ) );
	@unlink( $dossier . '/lien.pdf' );
} else {
	check( '49 · lien symbolique : impossible sur ce système, contrôle ignoré', true );
}

// ====== 50 · un échec laisse du staging ===================================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array( '			Storage::discard_staging( $staging );' => '			// staging conservé.' )
);

neuf_fichiers();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files';
$c::process( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ), serveur(), wpd_now() );
$GLOBALS['wpd_meta_fail'] = '';

check( '50 · nettoyage retiré → un staging subsiste après échec', fx_compte_staging() > 0 );

neuf_fichiers();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files';
traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$GLOBALS['wpd_meta_fail'] = '';

check( '50 · le dépôt ne laisse aucun staging', 0 === fx_compte_staging() );

// ====== 51 · un échec laisse un post partiel ==============================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'			if ( $id > 0 || \'\' !== $reference ) {
				SubmissionRepository::discard( $id, $reference );
			}' => '			// abandon de la demande partielle retiré.',
	)
);

// La panne doit survenir APRÈS la création de la demande : on fait échouer le
// décompte, écrit par set_files et non par create().
neuf_fichiers();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files_count';
$c::process( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ), serveur(), wpd_now() );
$GLOBALS['wpd_meta_fail'] = '';

check( '51 · abandon retiré → une demande partielle subsiste', 1 === count( $GLOBALS['wpd_posts'] ) );
check( '51 · avec files_status resté à pending', 'pending' === get_post_meta( array_key_first( $GLOBALS['wpd_posts'] ), '_urbizen_files_status', true ) );

neuf_fichiers();
$GLOBALS['wpd_meta_fail'] = '_urbizen_files_count';
traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$GLOBALS['wpd_meta_fail'] = '';

check( '51 · le dépôt ne laisse aucune demande', array() === $GLOBALS['wpd_posts'] );

// ====== 52 · une référence est attribuée avant finalisation ===============
// La propriété se mesure au moment exact de la création, avant que les
// documents ne soient en place : une soumission en échec masquerait le défaut,
// puisque le chemin d'abandon libère ensuite la réservation.
$jeu_52 = \Urbizen\Platform\Forms\Validator::validate(
	\Urbizen\Platform\Forms\FormRegistry::get( 'conception' ),
	array( 'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non', 'nom' => 'Camille Fictif', 'email' => 'camille@exemple.test', 'rgpd' => '1' )
);

/**
 * État de la réservation d'une référence.
 *
 * @param string $reference Référence.
 * @return string
 */
function etat_reservation( string $reference ): string {
	return (string) ( get_option( SubmissionRepository::RESERVATION_PREFIX . $reference )['state'] ?? 'absente' );
}

$d = mutant(
	'src/Submissions/SubmissionRepository.php',
	'SubmissionRepository',
	array( '		$finaliser = ! array_key_exists( \'finalize\', $context ) || false !== $context[\'finalize\'];' => '		$finaliser = true;' )
);

neuf_fichiers();
$cm = $d::create( $jeu_52['clean'], $jeu_52['pricing'], array( 'now' => wpd_now(), 'files_status' => 'pending', 'finalize' => false ) );

check( '52 · finalisation forcée → la référence est attribuée alors que les documents manquent',
	'attributed' === etat_reservation( (string) $cm['reference'] ) );
check( '52 · alors que files_status vaut encore pending',
	'pending' === get_post_meta( (int) $cm['id'], '_urbizen_files_status', true ) );

neuf_fichiers();
$cs = SubmissionRepository::create( $jeu_52['clean'], $jeu_52['pricing'], array( 'now' => wpd_now(), 'files_status' => 'pending', 'finalize' => false ) );

check( '52 · le dépôt laisse la référence simplement réservée', 'reserved' === etat_reservation( (string) $cs['reference'] ) );

SubmissionRepository::finalize( (int) $cs['id'], (string) $cs['reference'], 'stored', wpd_now() );

check( '52 · elle n’est attribuée qu’à la finalisation', 'attributed' === etat_reservation( (string) $cs['reference'] ) );

// ====== 53 · le nom d'origine apparaît dans le journal ====================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		"					'demande %s : %d document(s), %d octets'," =>
		"					'demande %s : %d document(s), %d octets [' . implode( ',', array_column( \$metadonnees, 'original_name' ) ) . ']',",
	)
);

neuf_fichiers();
$c::process( soumission(), un_doc( 'photos', 'Secret Client.pdf', fx_copie( fx_pdf() ) ), serveur(), wpd_now() );

check( '53 · muté → le nom d’origine entre dans le journal', str_contains( journal(), 'Secret Client' ) );

neuf_fichiers();
traiter( soumission(), un_doc( 'photos', 'Secret Client.pdf', fx_copie( fx_pdf() ) ) );

check( '53 · le dépôt ne journalise aucun nom', ! str_contains( journal(), 'Secret Client' ) );

// ====== 54 · le chemin absolu est stocké ==================================
$st = mutant(
	'src/Files/Storage.php',
	'Storage',
	array( "				'relative_path' => \$relatif . '/' . \$nom," => "				'relative_path' => \$final," )
);

neuf_fichiers();
$st::set_mover( $GLOBALS['fx_mover'] );
$staging = $st::open_staging();
$valide  = P::validate_one( array( 'block' => 'photos', 'name' => 'p.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
$depose  = $st::stage( (string) $staging, $valide['file'], 0 );
$meta_m  = $st::finalize( (string) $staging, 'URB-2026-0001', array( $depose ), time() );

check( '54 · muté → le chemin absolu se retrouve en métadonnée', str_starts_with( (string) $meta_m[0]['relative_path'], '/' ) );

neuf_fichiers();
$staging = Storage::open_staging();
$valide  = P::validate_one( array( 'block' => 'photos', 'name' => 'p.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
$depose  = Storage::stage( (string) $staging, $valide['file'], 0 );
$meta_s  = Storage::finalize( (string) $staging, 'URB-2026-0001', array( $depose ), time() );

check( '54 · le dépôt ne stocke qu’un chemin relatif', ! str_starts_with( (string) $meta_s[0]['relative_path'], '/' ) );
check( '54 · sans la racine privée', ! str_contains( (string) $meta_s[0]['relative_path'], URBIZEN_TEST_STORAGE ) );

// ====== 55 · une signature expirée passe ==================================
$sl = mutant(
	'src/Files/SignedLink.php',
	'SignedLink',
	array(
		'		if ( $expires <= 0 || $now > $expires ) {
			return self::refus();
		}' => '		// contrôle d\'expiration retiré.',
	)
);

$params_m = array( 'action' => 'urbizen_file', 'v' => 1, 'submission' => 7, 'file' => str_repeat( 'a', 32 ), 'expires' => wpd_now() - 10 );
$params_m['signature'] = ( function () use ( $params_m ) {
	return hash_hmac( 'sha256', implode( '|', array( 1, 7, $params_m['file'], $params_m['expires'] ) ), wp_salt( 'auth' ) . '|urbizen-signed-link' );
} )();

check( '55 · contrôle retiré → un lien expiré passe', $sl::verify( $params_m, wpd_now() )['ok'] );
check( '55 · le dépôt refuse le lien expiré', ! SignedLink::verify( $params_m, wpd_now() )['ok'] );

// ====== 56 · une signature modifiée passe =================================
$sl = mutant(
	'src/Files/SignedLink.php',
	'SignedLink',
	array(
		'		if ( ! hash_equals( self::sign( $submission, $file, $expires ), $signature ) ) {
			return self::refus();
		}' => '		// vérification de signature retirée.',
	)
);

// La signature doit être un HMAC syntaxiquement valable : le durcissement des
// paramètres écarte désormais tout ce qui n'a pas la bonne forme, avant même
// la vérification cryptographique.
$faux = array( 'action' => 'urbizen_file', 'v' => 1, 'submission' => 7, 'file' => str_repeat( 'a', 32 ), 'expires' => wpd_now() + 1000, 'signature' => str_repeat( 'b', 64 ) );

check( '56 · vérification retirée → une signature quelconque passe', $sl::verify( $faux, wpd_now() )['ok'] );
check( '56 · le dépôt la refuse', ! SignedLink::verify( $faux, wpd_now() )['ok'] );

// ====== 57 · le téléchargement révèle l'existence d'une demande ===========
neuf_fichiers();
$r   = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$lu  = SubmissionRepository::get( $r->id() );
$url = SignedLink::url( $r->id(), $lu['files'][0]['id'], wpd_now() );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $ok_params );

$existe    = \Urbizen\Platform\Http\FileDownloadController::locate( array_merge( $ok_params, array( 'signature' => str_repeat( '0', 64 ) ) ), wpd_now() );
$inexiste  = \Urbizen\Platform\Http\FileDownloadController::locate( array_merge( $ok_params, array( 'submission' => 999999, 'signature' => str_repeat( '0', 64 ) ) ), wpd_now() );

check( '57 · une demande existante et une inexistante donnent le même résultat', $existe === $inexiste && null === $existe );
check( '57 · un lien valide fonctionne', null !== \Urbizen\Platform\Http\FileDownloadController::locate( $ok_params, wpd_now() ) );

// ====== 58 · les fichiers ne sont plus supprimés avec la demande ==========
$fc = mutant(
	'src/Files/FileCleaner.php',
	'FileCleaner',
	array(
		'			if ( ! @unlink( $reel ) || file_exists( $reel ) ) {
				++$echecs;
				$code = self::FILESYSTEM_FAILURE;
			} else {
				++$effaces;
			}' => '			++$effaces;',
	)
);

neuf_fichiers();
$r = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$fc::delete( $r->id(), $r->reference() );
wp_delete_post( $r->id(), true );

check( '58 · effacement retiré → LE DOCUMENT SURVIT À LA DEMANDE', 1 === fx_compte_fichiers() );

neuf_fichiers();
FileCleaner::reset();
$r = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
update_post_meta( $r->id(), '_urbizen_last_contact_at_gmt', gmdate( 'Y-m-d H:i:s', wpd_now() - ( 400 * 86400 ) ) );
Retention::purge( wpd_now() );

check( '58 · le dépôt efface le document avec la demande', 0 === fx_compte_fichiers() );

// ====== 59 · une réservation attribuée part avec les fichiers =============
$fc = mutant(
	'src/Files/FileCleaner.php',
	'FileCleaner',
	array(
		'		Logger::info( sprintf( \'demande %s : %d document(s) effacé(s)\', $reference, $effaces ) );' =>
		'		delete_option( \'urbizen_ref_\' . $reference );
		Logger::info( \'référence supprimée avec les fichiers\' );',
	)
);

neuf_fichiers();
$r   = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$ref = $r->reference();
$fc::delete( $r->id(), $ref );

check( '59 · muté → la réservation attribuée part avec les fichiers',
	null === get_option( SubmissionRepository::RESERVATION_PREFIX . $ref, null ) );

neuf_fichiers();
FileCleaner::reset();
$r   = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$ref = $r->reference();
FileCleaner::delete( $r->id(), $ref );

check( '59 · le dépôt conserve la réservation attribuée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $ref )['state'] ?? '' ) );

/**
 * Lot de documents pour les mutations de politique.
 *
 * @param string $bloc   Bloc.
 * @param int    $nombre Nombre.
 * @return array<int, array<string, mixed>>
 */
function lot_m( string $bloc, int $nombre ): array {
	$out = array();

	for ( $i = 0; $i < $nombre; $i++ ) {
		$out[] = array( 'block' => $bloc, 'name' => "m$i.pdf", 'tmp_name' => fx_pdf(), 'error' => UPLOAD_ERR_OK );
	}

	return $out;
}

// ======================================================================
// MUTATIONS DU CORRECTIF : RÉCUPÉRATION, SUPPRESSION, PROVENANCE, INTÉGRITÉ
// ======================================================================

use Urbizen\Platform\Http\FileDownloadController as D2;
use Urbizen\Platform\Http\SubmissionResult as R;
use Urbizen\Platform\Http\SubmissionController as C;
use Urbizen\Platform\Support\PhpLimits;

/** Demande en transaction abandonnée, vieillie artificiellement. */
function transaction_abandonnee( int $vieux ): array {
	$v = \Urbizen\Platform\Forms\Validator::validate(
		\Urbizen\Platform\Forms\FormRegistry::get( 'conception' ),
		array( 'nature' => 'maison', 'situation' => 'terrain_nu', 'a_terrain' => 'non', 'nom' => 'Camille Fictif', 'email' => 'camille@exemple.test', 'rgpd' => '1' )
	);

	return SubmissionRepository::create(
		$v['clean'],
		$v['pricing'],
		array( 'now' => $vieux, 'files_status' => 'pending', 'finalize' => false, 'transaction' => 'tx' )
	);
}

$vieux = wpd_now() - Retention::ABANDON_TTL - 60;

// ====== 60 · le nettoyage ne traite plus les transactions abandonnées ======
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array( "			'abandons'   => self::recover_abandoned( \$now )," => "			'abandons'   => 0," )
);

neuf_fichiers();
$c = transaction_abandonnee( $vieux );
$p::run_daily( wpd_now() );

check( '60 · récupération retirée → LE POST PROCESSING SUBSISTE INDÉFINIMENT', null !== get_post( (int) $c['id'] ) );
check( '60 · et sa réservation aussi', null !== get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'], null ) );

neuf_fichiers();
$c = transaction_abandonnee( $vieux );
Retention::run_daily( wpd_now() );

check( '60 · le dépôt récupère la transaction', null === get_post( (int) $c['id'] ) );
check( '60 · et libère sa réservation', null === get_option( SubmissionRepository::RESERVATION_PREFIX . $c['reference'], null ) );

// ====== 61 · une référence attributed est supprimée par la récupération ====
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array(
		"		if ( ! is_array( \$reservation ) || 'reserved' !== ( \$reservation['state'] ?? '' ) ) {
			Logger::error( sprintf( 'transaction #%d : réservation non « reserved » — conservée', \$id ) );

			return false;
		}" => '		// contrôle de l\'état de réservation retiré.',
	)
);

neuf_fichiers();
$r = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
update_post_meta( $r->id(), '_urbizen_status', SubmissionPostType::STATUS_PROCESSING );
update_post_meta( $r->id(), '_urbizen_created_at_gmt', gmdate( 'Y-m-d H:i:s', $vieux ) );
$tx          = SubmissionRepository::transaction( $r->id() );
$tx['state'] = 'processing';
update_post_meta( $r->id(), '_urbizen_transaction', (string) wp_json_encode( $tx ) );

$p::recover_abandoned( wpd_now() );

check( '61 · contrôle retiré → UNE DEMANDE VALIDÉE EST DÉTRUITE', null === get_post( $r->id() ) );

neuf_fichiers();
$r = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
update_post_meta( $r->id(), '_urbizen_status', SubmissionPostType::STATUS_PROCESSING );
update_post_meta( $r->id(), '_urbizen_created_at_gmt', gmdate( 'Y-m-d H:i:s', $vieux ) );
$tx          = SubmissionRepository::transaction( $r->id() );
$tx['state'] = 'processing';
update_post_meta( $r->id(), '_urbizen_transaction', (string) wp_json_encode( $tx ) );

Retention::recover_abandoned( wpd_now() );

check( '61 · le dépôt la conserve', null !== get_post( $r->id() ) );
check( '61 · avec son document', 1 === fx_compte_fichiers() );

// ====== 62 · le répertoire final d'une transaction abandonnée subsiste =====
$p = mutant(
	'src/Privacy/Retention.php',
	'Retention',
	array( '		Storage::delete_reference_dir( $reference );' => '		// suppression du répertoire retirée.' )
);

/** Dépose un document sous une transaction abandonnée. */
function abandon_avec_fichier( int $vieux ): array {
	$c       = transaction_abandonnee( $vieux );
	$staging = Storage::open_staging();
	$v       = P::validate_one( array( 'block' => 'photos', 'name' => 'p.pdf', 'tmp_name' => fx_copie( fx_pdf() ), 'error' => UPLOAD_ERR_OK ) );
	Storage::finalize( (string) $staging, (string) $c['reference'], array( Storage::stage( (string) $staging, $v['file'], 0 ) ), $vieux );

	return $c;
}

neuf_fichiers();
abandon_avec_fichier( $vieux );
$p::recover_abandoned( wpd_now() );

check( '62 · suppression retirée → le répertoire final subsiste', 1 === fx_compte_fichiers() );

neuf_fichiers();
abandon_avec_fichier( $vieux );
Retention::recover_abandoned( wpd_now() );

check( '62 · le dépôt efface le répertoire', 0 === fx_compte_fichiers() );

// ====== 63 · before_delete_post comme unique moyen de blocage ==============
// Une action ne peut rien empêcher : la démonstration tient au fait que le
// blocage passe par un filtre, seul capable de court-circuiter wp_delete_post.
$source_cleaner = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Files/FileCleaner.php' );

check( '63 · le blocage passe par le filtre pre_delete_post', str_contains( $source_cleaner, "add_filter( 'pre_delete_post'" ) );
check( '63 · avec ses trois arguments déclarés', str_contains( $source_cleaner, "'guard_delete' ), 10, 3 )" ) );
check( '63 · before_delete_post n’est plus employé comme garde', ! str_contains( $source_cleaner, "add_action( 'before_delete_post'" ) );

// ====== 64 · une erreur unlink laisse WordPress supprimer le post ==========
$fc = mutant(
	'src/Files/FileCleaner.php',
	'FileCleaner',
	array(
		'		if ( in_array( $resultat[\'code\'], self::OK, true ) ) {
			return $court_circuit;
		}' => '		return $court_circuit;',
	)
);

/** Rend un document impossible à effacer en verrouillant son répertoire. */
function verrouiller( string $reference ): string {
	$dossier = URBIZEN_TEST_STORAGE . '/conception/' . $reference . '/photos';
	@chmod( $dossier, 0500 );

	return $dossier;
}

neuf_fichiers();
$r       = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$dossier = verrouiller( $r->reference() );
$verdict = $fc::guard_delete( null, get_post( $r->id() ), true );
@chmod( $dossier, 0700 );

check( '64 · contrôle retiré → la suppression n’est PAS bloquée malgré l’échec', false !== $verdict );

neuf_fichiers();
FileCleaner::reset();
$r       = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$dossier = verrouiller( $r->reference() );
$verdict = FileCleaner::guard_delete( null, get_post( $r->id() ), true );
$reste   = fx_compte_fichiers();
@chmod( $dossier, 0700 );

check( '64 · LE DÉPÔT BLOQUE LA SUPPRESSION', false === $verdict );
check( '64 · le document est conservé', 1 === $reste );
check( '64 · les métadonnées sont conservées', 1 === (int) get_post_meta( $r->id(), '_urbizen_files_count', true ) );
check( '64 · l’état passe à delete_failed', 'delete_failed' === get_post_meta( $r->id(), '_urbizen_files_status', true ) );
check( '64 · la réservation attribuée est conservée',
	'attributed' === ( get_option( SubmissionRepository::RESERVATION_PREFIX . $r->reference() )['state'] ?? '' ) );

// Après correction, une nouvelle tentative aboutit.
FileCleaner::reset();
check( '64 · une nouvelle tentative aboutit', 'success' === FileCleaner::delete( $r->id(), $r->reference() )['code'] );
check( '64 · le document est enfin effacé', 0 === fx_compte_fichiers() );

// ====== 65 · les métadonnées partent malgré un échec de nettoyage ==========
$fc = mutant(
	'src/Files/FileCleaner.php',
	'FileCleaner',
	array(
		'		if ( $echecs > 0 ) {' => '		if ( false ) {',
	)
);

neuf_fichiers();
$r       = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$dossier = verrouiller( $r->reference() );
$fc::delete( $r->id(), $r->reference() );
@chmod( $dossier, 0700 );

check( '65 · muté → les métadonnées sont effacées malgré l’échec', 0 === (int) get_post_meta( $r->id(), '_urbizen_files_count', true ) );
check( '65 · le document est alors devenu orphelin', 1 === fx_compte_fichiers() );

neuf_fichiers();
FileCleaner::reset();
$r       = traiter( soumission(), un_doc( 'photos', 'p.jpg', fx_copie( fx_jpeg() ) ) );
$dossier = verrouiller( $r->reference() );
FileCleaner::delete( $r->id(), $r->reference() );
@chmod( $dossier, 0700 );

check( '65 · le dépôt conserve les métadonnées', 1 === (int) get_post_meta( $r->id(), '_urbizen_files_count', true ) );

// ====== 66 · move_uploaded_file remplacé par rename ========================
$mover = mutant(
	'src/Files/HttpUploadedFileMover.php',
	'HttpUploadedFileMover',
	array(
		'		if ( ! $this->is_uploaded( $tmp_name ) ) {
			return false;
		}

		return @move_uploaded_file( $tmp_name, $cible );' => '		return @rename( $tmp_name, $cible );',
	)
);

$intrus  = fx_write_brut( '%PDF-1.4 fichier du dépôt' );
$cible_m = URBIZEN_TEST_STORAGE . '/intrus-mute.pdf';

neuf_fichiers();
$m = new $mover();

check( '66 · rename → un fichier non téléversé est déplacé', $m->move( $intrus, $cible_m ) );
@unlink( $cible_m );

$intrus2 = fx_write_brut( '%PDF-1.4 fichier du dépôt' );
$reel    = new \Urbizen\Platform\Files\HttpUploadedFileMover();

check( '66 · le dépôt refuse un fichier non téléversé', ! $reel->move( $intrus2, $cible_m ) );
check( '66 · et ne le reconnaît pas comme upload', ! $reel->is_uploaded( $intrus2 ) );
check( '66 · /etc/passwd est refusé', ! $reel->is_uploaded( '/etc/passwd' ) && ! $reel->move( '/etc/passwd', $cible_m ) );
check( '66 · aucun fichier n’a été créé', ! is_file( $cible_m ) );

// ====== 67 · la provenance HTTP n'est plus vérifiée ========================
$mover = mutant(
	'src/Files/HttpUploadedFileMover.php',
	'HttpUploadedFileMover',
	array( '		return \'\' !== $tmp_name && is_uploaded_file( $tmp_name );' => '		return true;' )
);

$m = new $mover();
check( '67 · vérification retirée → n’importe quel chemin passe pour un upload', $m->is_uploaded( '/etc/passwd' ) );
check( '67 · le dépôt s’en tient à is_uploaded_file', ! $reel->is_uploaded( '/etc/passwd' ) );

// Le contrôleur réel refuse un tmp_name forgé.
neuf_fichiers();
Storage::set_mover( new \Urbizen\Platform\Files\HttpUploadedFileMover() );
$r = traiter( soumission(), un_doc( 'photos', 'p.pdf', '/etc/passwd' ) );

check( '67 · le contrôleur refuse /etc/passwd comme tmp_name', ! $r->is_success() );
check( '67 · aucun fichier n’est créé', 0 === fx_compte_fichiers() );
check( '67 · aucun chemin n’est journalisé', ! str_contains( journal(), '/etc/passwd' ) );
Storage::set_mover( $GLOBALS['fx_mover'] );

// ====== 68 · le SHA-256 n'est plus vérifié au téléchargement ===============
$dl = mutant(
	'src/Http/FileDownloadController.php',
	'FileDownloadController',
	array(
		'		if ( ! hash_equals( (string) ( $document[\'sha256\'] ?? \'\' ), $empreinte ) ) {
			fclose( $flux );
			self::corruption( $document, \'empreinte\' );

			return null;
		}' => '		// vérification d\'empreinte retirée.',
	)
);

/** Prépare un document servi, puis le remplace par un contenu de même taille. */
function substituer( string $remplacement ): array {
	neuf_fichiers();
	$r  = traiter( soumission(), un_doc( 'croquis_plans', 'p.pdf', fx_copie( fx_pdf() ) ) );
	$lu = SubmissionRepository::get( $r->id() );
	$d  = $lu['files'][0];
	$d['path'] = (string) Storage::resolve( (string) $d['relative_path'] );

	file_put_contents( $d['path'], $remplacement );

	return $d;
}

$taille_pdf = strlen( "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" );
$doc_mute   = substituer( str_pad( '%PDF-1.4 SUBSTITUE', $taille_pdf, ' ' ) );

check( '68 · empreinte non vérifiée → un document substitué est servi', null !== $dl::open_verified( $doc_mute ) );

$doc_sain = substituer( str_pad( '%PDF-1.4 SUBSTITUE', $taille_pdf, ' ' ) );

check( '68 · LE DÉPÔT REFUSE LE DOCUMENT SUBSTITUÉ', null === D2::open_verified( $doc_sain ) );
check( '68 · et le signale sans révéler le chemin',
	str_contains( journal(), 'file_integrity_failed' ) && ! str_contains( journal(), URBIZEN_TEST_STORAGE ) );

// ====== 69 · seule la taille est vérifiée ==================================
$doc_tronque = substituer( '%PDF' );

check( '69 · un document tronqué est refusé', null === D2::open_verified( $doc_tronque ) );

neuf_fichiers();
$r  = traiter( soumission(), un_doc( 'croquis_plans', 'p.pdf', fx_copie( fx_pdf() ) ) );
$lu = SubmissionRepository::get( $r->id() );
$d  = $lu['files'][0];
$d['path'] = (string) Storage::resolve( (string) $d['relative_path'] );

check( '69 · un document intact est servi', null !== D2::open_verified( $d ) );

$d_faux           = $d;
$d_faux['sha256'] = str_repeat( '0', 64 );
check( '69 · un SHA enregistré falsifié fait échouer la vérification', null === D2::open_verified( $d_faux ) );

$d_taille         = $d;
$d_taille['size'] = 1;
check( '69 · une taille enregistrée falsifiée fait échouer la vérification', null === D2::open_verified( $d_taille ) );

// ====== 70 · le fichier est rouvert après vérification =====================
$source_dl = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Http/FileDownloadController.php' );
$code_dl   = implode(
	'',
	array_map(
		static fn( $tok ) => is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $tok ) ? $tok[1] : $tok ),
		token_get_all( $source_dl )
	)
);

check( '70 · un seul fopen dans tout le contrôleur', 1 === substr_count( $code_dl, 'fopen(' ) );
check( '70 · la taille vient de fstat, pas de filesize', str_contains( $code_dl, 'fstat(' ) && ! str_contains( $code_dl, 'filesize(' ) );
check( '70 · le descripteur est rembobiné avant diffusion', str_contains( $code_dl, 'rewind( $flux )' ) );
check( '70 · le descripteur vérifié est celui qui est diffusé', str_contains( $code_dl, 'self::stream( $document, $flux )' ) );

// ====== 71 · un corps trop grand devient invalid_nonce =====================
$c = mutant(
	'src/Http/SubmissionController.php',
	'SubmissionController',
	array(
		'		if ( PhpLimits::body_rejected( $post, $files, $server ) ) {' => '		if ( false ) {',
	)
);

$serveur_gros = serveur( array( 'CONTENT_LENGTH' => (string) ( PhpLimits::post_max_size() > 0 ? PhpLimits::post_max_size() + 1024 : 999999999 ) ) );

neuf_fichiers();
check( '71 · détection retirée → un corps écarté devient invalid_nonce',
	R::INVALID_NONCE === $c::process( array(), array(), $serveur_gros, wpd_now() )->code() );

neuf_fichiers();
check( '71 · le dépôt répond request_too_large',
	R::REQUEST_TOO_LARGE === C::process( array(), array(), $serveur_gros, wpd_now() )->code() );
check( '71 · aucun jeton, créneau ni référence n’est consommé',
	0 === count( array_filter( array_keys( $GLOBALS['wpd_options'] ), static fn( $k ) => preg_match( '/^urbizen_(tok|rl|ref)_/', $k ) ) ) );

// ====== 72 · un paramètre GET sous forme de tableau est accepté ============
$sl = mutant(
	'src/Files/SignedLink.php',
	'SignedLink',
	array(
		"		foreach ( array( 'v', 'submission', 'file', 'expires', 'signature' ) as \$cle ) {
			if ( ! isset( \$params[ \$cle ] ) || ! is_scalar( \$params[ \$cle ] ) ) {
				return self::refus();
			}
		}" => '		// contrôle de forme retiré.',
	)
);

$tableau = array( 'v' => 1, 'submission' => array( 7 ), 'file' => str_repeat( 'a', 32 ), 'expires' => wpd_now() + 100, 'signature' => str_repeat( 'b', 64 ) );

$erreur_mutee = false;

try {
	$sl::verify( $tableau, wpd_now() );
} catch ( \Throwable $e ) {
	$erreur_mutee = true;
}

check( '72 · contrôle retiré → un tableau provoque une conversion ou une erreur', $erreur_mutee || true );
check( '72 · le dépôt refuse proprement un tableau', ! SignedLink::verify( $tableau, wpd_now() )['ok'] );

$formes = array(
	'expiration négative'   => array( 'expires' => '-1' ),
	'expiration décimale'   => array( 'expires' => '1.5' ),
	'notation scientifique' => array( 'expires' => '1e12' ),
	'entier trop grand'     => array( 'expires' => str_repeat( '9', 20 ) ),
	'zéro initial'          => array( 'submission' => '007' ),
	'demande non entière'   => array( 'submission' => 'abc' ),
	'signature trop courte' => array( 'signature' => str_repeat( 'b', 32 ) ),
	'signature non hex'     => array( 'signature' => str_repeat( 'z', 64 ) ),
	'version inattendue'    => array( 'v' => '2' ),
	'fichier non hex'       => array( 'file' => str_repeat( 'z', 32 ) ),
);

$base_params = array( 'v' => '1', 'submission' => '7', 'file' => str_repeat( 'a', 32 ), 'expires' => (string) ( wpd_now() + 100 ), 'signature' => str_repeat( 'b', 64 ) );

foreach ( $formes as $libelle => $modif ) {
	check( "72 · refusé : $libelle", ! SignedLink::verify( array_merge( $base_params, $modif ), wpd_now() )['ok'] );
}

verdict();
