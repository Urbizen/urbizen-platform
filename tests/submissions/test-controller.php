<?php
/**
 * Banc d'essai du contrôleur de soumission.
 *
 * Éprouve le chemin complet et, surtout, l'ordre des refus : un contrôle de
 * sécurité qui s'exécuterait après le travail métier serait un contrôle qui
 * laisse travailler l'attaquant.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Http\SubmissionResult;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

/**
 * Repart d'un état propre.
 */
function neuf(): void {
	wpd_reset();
	SubmissionPostType::register_post_type();
}

neuf();

// ================================================== CHEMIN NOMINAL ==========
$r = traiter( soumission() );

check( 'une soumission complète réussit', $r->is_success() && SubmissionResult::SUCCESS === $r->code() );
check( 'une référence est renvoyée', 1 === preg_match( '/^URB-\d{4}-\d{4}$/', $r->reference() ) );
check( 'un identifiant est renvoyé', $r->id() > 0 );
check( 'aucune erreur de validation', array() === $r->errors() );
check( 'la demande existe en base', 1 === count( $GLOBALS['wpd_posts'] ) );
check( 'aucun courriel n’est envoyé', array() === $GLOBALS['wpd_mails'] );
// B3 : une demande finalisée porte une notification en attente, et un
// identifiant de notification. Aucun courriel n'est parti pour autant : le
// contrôleur ne rend rien et n'appelle aucun transport.
check( 'mail_status = pending', MailPolicy::PENDING === get_post_meta( $r->id(), '_urbizen_mail_status', true ) );
check( 'un identifiant de notification est attribué',
	1 === preg_match( '/^[0-9a-f]{32}$/', (string) get_post_meta( $r->id(), MailPolicy::META_ID, true ) ) );
check( 'aucun courriel envoyé par le contrôleur', array() === $GLOBALS['wpd_mails'] );

// ================================================== ORDRE DES REFUS =========
// Chaque refus est éprouvé sur une soumission par ailleurs parfaite : ce qui
// échoue est bien le contrôle visé, et rien d'autre.

$scenarios = array(
	'GET refusé'                          => array( 'post' => null, 'serveur' => serveur( array( 'REQUEST_METHOD' => 'GET' ) ), 'code' => SubmissionResult::INVALID_METHOD ),
	'PUT refusé'                          => array( 'post' => null, 'serveur' => serveur( array( 'REQUEST_METHOD' => 'PUT' ) ), 'code' => SubmissionResult::INVALID_METHOD ),
	'méthode absente refusée'             => array( 'post' => null, 'serveur' => array( 'REMOTE_ADDR' => '203.0.113.10' ), 'code' => SubmissionResult::INVALID_METHOD ),
	'nonce absent'                        => array( 'retirer' => SubmissionController::NONCE_FIELD, 'code' => SubmissionResult::INVALID_NONCE ),
	'nonce vide'                          => array( 'post' => array( SubmissionController::NONCE_FIELD => '' ), 'code' => SubmissionResult::INVALID_NONCE ),
	'nonce invalide'                      => array( 'post' => array( SubmissionController::NONCE_FIELD => 'faux-nonce' ), 'code' => SubmissionResult::INVALID_NONCE ),
	'nonce d’une autre action'            => array( 'post' => array( SubmissionController::NONCE_FIELD => wp_create_nonce( 'autre_action' ) ), 'code' => SubmissionResult::INVALID_NONCE ),
	'pot de miel rempli'                  => array( 'post' => array( SubmissionController::HONEYPOT_FIELD => 'robot' ), 'code' => SubmissionResult::SPAM_HONEYPOT ),
	'jeton absent'                        => array( 'retirer' => SubmissionController::TOKEN_FIELD, 'code' => SubmissionResult::INVALID_ANTISPAM_TOKEN ),
	'jeton falsifié'                      => array( 'post' => array( SubmissionController::TOKEN_FIELD => 'a.b.c' ), 'code' => SubmissionResult::INVALID_ANTISPAM_TOKEN ),
);

foreach ( $scenarios as $libelle => $cas ) {
	neuf();

	$post = soumission();

	if ( isset( $cas['retirer'] ) ) {
		unset( $post[ $cas['retirer'] ] );
	}

	if ( ! empty( $cas['post'] ) ) {
		$post = array_merge( $post, $cas['post'] );
	}

	$r = traiter( $post, array(), $cas['serveur'] ?? serveur() );

	check( sprintf( '%-36s → %s', $libelle, $cas['code'] ), ! $r->is_success() && $cas['code'] === $r->code() );
	check( sprintf( '%-36s → rien enregistré', $libelle ), array() === $GLOBALS['wpd_posts'] );
}

// --- jeton trop rapide, expiré, rejoué ---
neuf();
$r = traiter( soumission( array(), wpd_now() - 1 ) );
check( 'jeton soumis en 1 seconde → token_too_fast', SubmissionResult::TOKEN_TOO_FAST === $r->code() );

neuf();
$r = traiter( soumission( array(), wpd_now() - AntiSpam::MAX_AGE - 10 ) );
check( 'jeton de plus de 24 h → token_expired', SubmissionResult::TOKEN_EXPIRED === $r->code() );

neuf();
$post = soumission();
$un   = traiter( $post );
$deux = traiter( $post );

check( 'la première soumission réussit', $un->is_success() );
check( 'la seconde, identique, est refusée', ! $deux->is_success() && SubmissionResult::DUPLICATE_SUBMISSION === $deux->code() );
check( 'une seule demande existe', 1 === count( $GLOBALS['wpd_posts'] ) );

// --- limitation de débit ---
neuf();
$reussies = 0;

for ( $i = 1; $i <= 5; $i++ ) {
	if ( traiter( soumission() )->is_success() ) {
		++$reussies;
	}
}

$sixieme = traiter( soumission() );

check( 'cinq soumissions consécutives réussissent', 5 === $reussies );
check( 'la sixième est limitée', ! $sixieme->is_success() && SubmissionResult::RATE_LIMITED === $sixieme->code() );
check( 'cinq demandes en base, pas six', 5 === count( $GLOBALS['wpd_posts'] ) );

// ============================================= DOCUMENTS ACCEPTÉS ==========
// B2 remplace le refus provisoire de B1 par le traitement réel. Les cas
// détaillés vivent dans test-documents.php ; on vérifie ici que le contrôleur
// délègue correctement et respecte les invariants de B1.
neuf();

$pdf = fx_copie( fx_pdf() );

$avec_fichier = array(
	'croquis_plans' => array(
		'name'     => array( 'plan.pdf' ),
		'type'     => array( 'application/pdf' ),
		'tmp_name' => array( $pdf ),
		'error'    => array( UPLOAD_ERR_OK ),
		'size'     => array( filesize( $pdf ) ),
	),
);

$r = traiter( soumission(), $avec_fichier );

check( 'une soumission avec un document valide réussit', $r->is_success() );
check( 'files_status vaut stored', 'stored' === get_post_meta( $r->id(), '_urbizen_files_status', true ) );
check( 'un document est compté', 1 === (int) get_post_meta( $r->id(), '_urbizen_files_count', true ) );
check( 'le nom du fichier n’apparaît pas dans le journal', ! str_contains( journal(), 'plan.pdf' ) );

$vide = array(
	'croquis_plans' => array(
		'name'     => array( '' ),
		'type'     => array( '' ),
		'tmp_name' => array( '' ),
		'error'    => array( UPLOAD_ERR_NO_FILE ),
		'size'     => array( 0 ),
	),
);

neuf();
$r = traiter( soumission(), $vide );

check( 'un champ de dépôt laissé vide ne bloque pas', $r->is_success() );
check( 'sans document, files_status vaut none', 'none' === get_post_meta( $r->id(), '_urbizen_files_status', true ) );

// Un document refusé ne doit consommer ni jeton, ni créneau, ni référence.
neuf();
$svg = fx_copie( fx_svg() );
$r   = traiter(
	soumission(),
	array(
		'croquis_plans' => array(
			'name'     => array( 'dessin.svg' ),
			'type'     => array( 'image/svg+xml' ),
			'tmp_name' => array( $svg ),
			'error'    => array( UPLOAD_ERR_OK ),
			'size'     => array( filesize( $svg ) ),
		),
	)
);

check( 'un SVG est refusé', ! $r->is_success() && 'upload_invalid_extension' === $r->code() );
check( 'aucune demande n’est enregistrée', array() === $GLOBALS['wpd_posts'] );
check( 'aucune référence n’est consommée', false === get_option( \Urbizen\Platform\Submissions\SubmissionRepository::SEQUENCE_OPTION, false ) );

// ================================================== VALIDATION ==============
neuf();
$r = traiter( soumission( array( 'nature' => 'chateau_fort' ) ) );

check( 'une valeur hors liste fait échouer la validation', SubmissionResult::VALIDATION_FAILED === $r->code() );
check( 'les erreurs sont structurées par champ', isset( $r->errors()['nature'] ) );
check( 'aucune demande n’est enregistrée', array() === $GLOBALS['wpd_posts'] );

neuf();
$sans_consentement = soumission();
unset( $sans_consentement['rgpd'] );
$r = traiter( $sans_consentement );

check( 'sans consentement, la soumission échoue', SubmissionResult::VALIDATION_FAILED === $r->code() );
check( 'le consentement est nommé parmi les erreurs', isset( $r->errors()['rgpd'] ) );

// ================================================== TARIFICATION ============
neuf();
$r = traiter(
	soumission(
		array(
			'options_tarifees' => array( 'pack_ftc', 'facades', 'toiture', 'coupe' ),
			'total'            => '1',
			'prix'             => '0',
			'urbizen_total'    => '99',
		)
	)
);

$pricing = json_decode( (string) get_post_meta( $r->id(), '_urbizen_pricing', true ), true );

check( 'la soumission réussit', $r->is_success() );
check( 'le pack reste exclusif : 748 €', 748 === $pricing['total'] );
check( 'une seule option est retenue', array( 'pack_ftc' ) === array_column( $pricing['options'], 'id' ) );
check( 'aucun prix client n’est stocké',
	! str_contains( (string) wp_json_encode( $GLOBALS['wpd_meta'][ $r->id() ] ), 'urbizen_total' ) );

neuf();
$r       = traiter( soumission( array( 'options_sur_devis' => array( 'insertion3d', 'complexe' ) ) ) );
$pricing = json_decode( (string) get_post_meta( $r->id(), '_urbizen_pricing', true ), true );

check( 'les prestations sur devis ne changent pas le total', 449 === $pricing['total'] );
check( 'elles sont stockées à part', array( 'insertion3d', 'complexe' ) === $pricing['sur_devis'] );
check( 'l’indicateur de devis est stocké', true === $pricing['devis_requis'] );
check( 'la remise de 200 € n’est jamais déduite', 249 !== $pricing['total'] );

neuf();
$r = traiter( soumission( array( 'options_tarifees' => array( 'modifs_sup' ) ) ) );
check( 'modifs_sup n’est pas cochable', SubmissionResult::VALIDATION_FAILED === $r->code() );

// ================================================== PERSISTANCE =============
neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_pricing';
$r                        = traiter( soumission() );

check( 'un échec d’écriture donne persistence_failed', SubmissionResult::PERSISTENCE_FAILED === $r->code() );
check( 'aucune demande partielle ne subsiste', array() === $GLOBALS['wpd_posts'] );
$GLOBALS['wpd_meta_fail'] = '';

// Le jeton ne doit pas être consommé si la demande n'a pas pu être écrite :
// sinon la personne ne pourrait pas réessayer.
neuf();
$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$post                     = soumission();
traiter( $post );
$GLOBALS['wpd_meta_fail'] = '';
$retente                  = traiter( $post );

check( 'après un échec de persistance, une nouvelle tentative est possible', $retente->is_success() );

// ================================================== REDIRECTION =============
neuf();

$succes = SubmissionResult::success( 'URB-2026-0007', 42 );
$echec  = SubmissionResult::failure( SubmissionResult::VALIDATION_FAILED, array( 'nom' => 'requis' ) );

$url = SubmissionController::redirect_url( $succes, array( SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/' ) );

check( 'succès : la destination est locale', str_starts_with( $url, '/conception-plans-sur-mesure/' ) );
check( 'succès : l’issue est indiquée', str_contains( $url, 'urbizen_submission=success' ) );
check( 'succès : la référence est transmise', str_contains( $url, 'reference=URB-2026-0007' ) );

$url_echec = SubmissionController::redirect_url( $echec, array( SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/' ) );

check( 'échec : l’issue est indiquée', str_contains( $url_echec, 'urbizen_submission=error' ) );
check( 'échec : aucune référence', ! str_contains( $url_echec, 'reference=' ) );
check( 'échec : aucun code technique dans l’adresse', ! str_contains( $url_echec, 'validation_failed' ) );
check( 'échec : aucun champ en erreur dans l’adresse', ! str_contains( $url_echec, 'nom' ) );

// --- une adresse étrangère n'est jamais suivie ---
foreach ( array(
	'https://exemple-attaquant.test/piege',
	'//exemple-attaquant.test/piege',
	'http://exemple-attaquant.test',
	'javascript:alert(1)',
) as $hostile ) {
	$u = SubmissionController::redirect_url( $succes, array( SubmissionController::RETURN_FIELD => $hostile ) );
	check( 'adresse étrangère refusée : ' . substr( $hostile, 0, 34 ), ! str_contains( $u, 'attaquant' ) && ! str_contains( $u, 'javascript' ) );
}

check( 'sans retour ni referer, on retombe sur l’accueil',
	str_starts_with( SubmissionController::redirect_url( $succes, array() ), 'https://exemple.test/' ) );

$GLOBALS['wpd_referer'] = 'https://exemple.test/une-page/';
check( 'un referer du même site est accepté',
	str_contains( SubmissionController::redirect_url( $succes, array() ), '/une-page/' ) );

$GLOBALS['wpd_referer'] = 'https://exemple-attaquant.test/page/';
check( 'un referer étranger est ignoré',
	! str_contains( SubmissionController::redirect_url( $succes, array() ), 'attaquant' ) );
$GLOBALS['wpd_referer'] = '';

// --- aucune donnée personnelle dans l'adresse ---
$u = SubmissionController::redirect_url(
	$succes,
	array(
		SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/',
		'email'                            => 'camille@exemple.test',
		'nom'                              => 'Camille Fictif',
		'tel'                              => '0100000000',
		'message'                          => 'Bonjour',
	)
);

foreach ( array( 'camille', 'Camille', 'exemple.test/?', '0100000000', 'Bonjour', 'message' ) as $motif ) {
	check( 'l’adresse ne contient pas : ' . $motif, ! str_contains( $u, $motif ) );
}

// --- une issue précédente ne s'accumule pas dans l'adresse ---
$u = SubmissionController::redirect_url(
	$succes,
	array( SubmissionController::RETURN_FIELD => '/page/?urbizen_submission=error&reference=URB-2020-0001' )
);

check( 'une issue précédente est remplacée, pas empilée', 1 === substr_count( $u, 'urbizen_submission=' ) );
check( 'une référence précédente est remplacée', ! str_contains( $u, 'URB-2020-0001' ) );

// ================================================== CHEMIN SOURCE ===========
check( 'le chemin local est retenu',
	'/conception-plans-sur-mesure/' === SubmissionController::source_path(
		array( SubmissionController::RETURN_FIELD => '/conception-plans-sur-mesure/?utm_source=pub#ancre' ),
		array()
	) );
check( 'le domaine, les paramètres et le fragment sont écartés',
	'/page/' === SubmissionController::source_path(
		array( SubmissionController::RETURN_FIELD => 'https://exemple.test/page/?utm_campaign=x&gclid=y#bas' ),
		array()
	) );
check( 'un referer du même site sert de repli',
	'/depuis-referer/' === SubmissionController::source_path( array(), array( 'HTTP_REFERER' => 'https://exemple.test/depuis-referer/' ) ) );
check( 'un referer étranger n’est pas conservé',
	'' === SubmissionController::source_path( array(), array( 'HTTP_REFERER' => 'https://exemple-attaquant.test/page/' ) ) );
check( 'sans rien, le chemin source est vide', '' === SubmissionController::source_path( array(), array() ) );
check( 'le chemin source est borné en longueur',
	strlen( SubmissionController::source_path( array( SubmissionController::RETURN_FIELD => '/' . str_repeat( 'a', 500 ) ), array() ) ) <= 200 );

// ================================================== JOURNALISATION ==========
neuf();
$r = traiter(
	soumission(
		array(
			'nom'     => 'Camille Fictif',
			'email'   => 'camille@exemple.test',
			'tel'     => '0100000000',
			'message' => 'Un projet de maison à Villefictive',
		)
	)
);

$log = journal();

check( 'le journal mentionne la référence', str_contains( $log, $r->reference() ) );
check( 'le journal mentionne le type de formulaire', str_contains( $log, 'conception' ) );

foreach ( array(
	'nom'               => 'Camille',
	'adresse'           => 'camille@exemple.test',
	'téléphone'         => '0100000000',
	'message'           => 'Villefictive',
	'adresse IP'        => '203.0.113',
	'jeton'             => $post[ SubmissionController::TOKEN_FIELD ] ?? 'zzz-inexistant',
) as $libelle => $motif ) {
	check( "le journal ne contient pas : $libelle", ! str_contains( $log, $motif ) );
}

// --- un refus est journalisé sans nommer les champs fautifs ---
neuf();
traiter( soumission( array( 'nature' => 'chateau_fort', 'nom' => 'Camille Fictif' ) ) );
$log = journal();

check( 'un refus est journalisé avec son code', str_contains( $log, 'validation_failed' ) );
check( 'le refus indique le nombre de champs fautifs', 1 === preg_match( '/\d+ champ\(s\) en erreur/', $log ) );
check( 'le refus ne nomme pas le champ fautif', ! str_contains( $log, 'chateau_fort' ) );
check( 'le refus ne contient aucune donnée personnelle', ! str_contains( $log, 'Camille' ) );

// ================================================== RÉSULTAT STRUCTURÉ ======
// Treize codes de B1 (files_not_supported_yet a disparu) plus quatorze codes
// de documents introduits par B2.
// Vingt-sept codes de B2, plus trois ajoutés par le correctif : intégrité au
// téléchargement et deux refus liés aux limites du serveur.
check( 'les trente codes internes sont déclarés', 30 === count( SubmissionResult::CODES ) );
check( 'le code provisoire de B1 a disparu', ! in_array( 'files_not_supported_yet', SubmissionResult::CODES, true ) );
check( 'aucun code en double', count( SubmissionResult::CODES ) === count( array_unique( SubmissionResult::CODES ) ) );
check( 'un échec ne porte ni référence ni identifiant', '' === $echec->reference() && 0 === $echec->id() );
check( 'le résultat est immuable : with_redirect renvoie une copie',
	SubmissionResult::success( 'URB-2026-0001', 1 )->with_redirect( '/x/' ) !== $succes && '' === $succes->redirect() );

verdict();
