<?php
/**
 * Banc d'intégration : le parcours public d'inscription, en HTTP réel.
 *
 * Ferme la réserve laissée par la campagne de mutations : l'anti-énumération
 * (mutation 16) n'était éprouvée que par la structure, faute de pouvoir piloter
 * `handle_inscription()` sans WordPress. Ici, on émet de VRAIES requêtes vers
 * `admin-post.php`, avec méthode, nonce, jeton anti-robot et limiteurs valides,
 * pour quatre états de compte, et l'on prouve que la réponse est identique.
 *
 * Aucun courriel ne quitte la machine : le mu-plugin de banc intercepte
 * `wp_mail` et écrit chaque message dans `mailbox.jsonl`.
 *
 * Exige : URBIZEN_WP_ROOT, un serveur HTTP servant ce WordPress, et
 * URBIZEN_HTTP_BASE pointant sur son `admin-post.php`.
 */

declare( strict_types = 1 );

require __DIR__ . '/amorce-reelle.php';

use Urbizen\Platform\Account\JetonVerification;
use Urbizen\Platform\Account\LimiteEnvois;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Security\AntiSpam;

$base = (string) getenv( 'URBIZEN_HTTP_BASE' );

if ( '' === $base ) {
	fwrite( STDERR, "URBIZEN_HTTP_BASE non défini : banc HTTP ignoré.\n" );
	exit( 0 );
}

$mailbox = (string) getenv( 'URBIZEN_MAILBOX' );

$reussis = 0;
$echecs  = 0;

/**
 * @param string $libelle Intitulé.
 * @param bool   $vrai    Résultat.
 * @return void
 */
function check( string $libelle, bool $vrai ): void {
	global $reussis, $echecs;

	if ( $vrai ) {
		$reussis++;
		printf( "%-72s OK\n", $libelle );

		return;
	}

	$echecs++;
	printf( "%-72s ECHEC\n", $libelle );
}

/**
 * Requête HTTP, en capturant statut, en-têtes et corps séparément.
 *
 * @param string               $methode POST ou GET.
 * @param string               $url     URL complète.
 * @param array<string, string> $champs  Champs POST.
 * @return array{status:int, headers:array<string,string>, body:string, raw_headers:string}
 */
function requete( string $methode, string $url, array $champs = array() ): array {
	$ch = curl_init();

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => false, // on veut VOIR le 303.
			CURLOPT_TIMEOUT        => 20,
		)
	);

	if ( 'POST' === $methode ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $champs ) );
	}

	$reponse = (string) curl_exec( $ch );
	$status  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$taille  = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
	curl_close( $ch );

	$brut_entetes = substr( $reponse, 0, $taille );
	$corps        = substr( $reponse, $taille );

	$entetes = array();

	foreach ( explode( "\r\n", $brut_entetes ) as $ligne ) {
		if ( false !== strpos( $ligne, ':' ) ) {
			list( $cle, $val ) = explode( ':', $ligne, 2 );
			$entetes[ strtolower( trim( $cle ) ) ] = trim( $val );
		}
	}

	return array(
		'status'      => $status,
		'headers'     => $entetes,
		'body'        => $corps,
		'raw_headers' => $brut_entetes,
	);
}

/**
 * Purge les créneaux du limiteur et les jetons anti-robot : chaque cas part
 * d'une origine vierge, sinon quatre requêtes de la même IP se gêneraient.
 *
 * @return void
 */
function origine_vierge(): void {
	global $wpdb;

	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_rl\\_%' OR option_name LIKE 'urbizen_tok\\_%'"
	);
	wp_cache_flush();
}

/**
 * Supprime un compte de banc s'il existe.
 *
 * @param string $adresse Adresse.
 * @return void
 */
function purger_compte( string $adresse ): void {
	$u = get_user_by( 'email', $adresse );

	if ( $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u->ID );
	}
}

// ----------------------------------------------------------------------
// Préparation des quatre états
// ----------------------------------------------------------------------
$comptes = new WpComptes();

$adr_libre    = 'libre-' . uniqid() . '@example.test';
$adr_non_ver  = 'nonver-' . uniqid() . '@example.test';
$adr_verifiee = 'verif-' . uniqid() . '@example.test';
$adr_quota    = 'quota-' . uniqid() . '@example.test';

foreach ( array( $adr_libre, $adr_non_ver, $adr_verifiee, $adr_quota ) as $a ) {
	purger_compte( $a );
}

// B : existante non vérifiée.
$id_non_ver = $comptes->creer( 'urb_' . uniqid(), $adr_non_ver, 'motdepasse-de-banc-long' );
// C : existante vérifiée.
$id_verifiee = $comptes->creer( 'urb_' . uniqid(), $adr_verifiee, 'motdepasse-de-banc-long' );
$comptes->ecrire_meta( $id_verifiee, VerificationService::META_VERIFIE, VerificationService::VALEUR_VERIFIE );
// D : existante non vérifiée, quota plein.
$id_quota = $comptes->creer( 'urb_' . uniqid(), $adr_quota, 'motdepasse-de-banc-long' );
$maintenant = time();
$comptes->ecrire_meta(
	$id_quota,
	LimiteEnvois::META_SOURCE,
	LimiteEnvois::encoder_source(
		array(
			array( 'a' => $maintenant, 'e' => 'x1' ),
			array( 'a' => $maintenant, 'e' => 'x2' ),
			array( 'a' => $maintenant, 'e' => 'x3' ),
		)
	)
);

check( '0 · préparation : trois comptes créés', $id_non_ver > 0 && $id_verifiee > 0 && $id_quota > 0 );

// URBIZEN_HTTP_BASE étant défini, l'interception du courriel n'est PLUS
// optionnelle : sans boîte réellement inscriptible, la preuve « aucun envoi
// réel » et le décompte exact des messages sont invérifiables. On EXIGE donc
// une URBIZEN_MAILBOX valide — chemin renseigné, répertoire parent existant et
// accessible en écriture — et son absence FAIT ÉCHOUER le banc plutôt que de
// produire une preuve ambiguë.
$mailbox_valide = ( '' !== $mailbox && is_dir( dirname( $mailbox ) ) && is_writable( dirname( $mailbox ) ) );
check( '0 · URBIZEN_MAILBOX valide et inscriptible (interception exigée)', $mailbox_valide );

// ----------------------------------------------------------------------
// Les quatre requêtes d'inscription
// ----------------------------------------------------------------------
$mailbox_avant = ( '' !== $mailbox && is_file( $mailbox ) ) ? count( file( $mailbox ) ) : 0;

/**
 * Émet une inscription pour une adresse, origine vierge, et rend la réponse.
 *
 * @param string $base    URL admin-post.
 * @param string $adresse Adresse.
 * @return array{status:int, headers:array<string,string>, body:string, raw_headers:string, location:string}
 */
function inscrire_http( string $base, string $adresse ): array {
	origine_vierge();

	// Nonce anonyme (uid 0 en CLI = uid 0 côté requête anonyme) et jeton
	// anti-robot d'âge suffisant.
	$nonce = wp_create_nonce( 'urbizen_inscription' );
	$jeton = AntiSpam::issue_token( time() - AntiSpam::MIN_SECONDS - 5 );

	$rep = requete(
		'POST',
		$base,
		array(
			'action'         => 'urbizen_inscription',
			'_urbizen_nonce' => $nonce,
			'urbizen_token'  => $jeton,
			'adresse'        => $adresse,
			'motdepasse'     => 'motdepasse-de-banc-long',
		)
	);

	$rep['location'] = $rep['headers']['location'] ?? '';

	return $rep;
}

$cas = array(
	'A_libre'    => inscrire_http( $base, $adr_libre ),
	'B_non_ver'  => inscrire_http( $base, $adr_non_ver ),
	'C_verifiee' => inscrire_http( $base, $adr_verifiee ),
	'D_quota'    => inscrire_http( $base, $adr_quota ),
);

// ----------------------------------------------------------------------
// 1 · Chaque cas répond par une redirection 303
// ----------------------------------------------------------------------
foreach ( $cas as $nom => $rep ) {
	check( sprintf( '1 · %s : redirection 303', $nom ), 303 === $rep['status'] );
}

// ----------------------------------------------------------------------
// 2 · L'en-tête Location est IDENTIQUE — aucun élément variable à neutraliser
//     (l'URL ne porte que action + code, ni adresse ni jeton)
// ----------------------------------------------------------------------
$locations = array_map( static fn( $r ) => $r['location'], $cas );
$loc_ref   = $locations['A_libre'];

foreach ( $locations as $nom => $loc ) {
	check( sprintf( '2 · %s : Location identique à A', $nom ), $loc === $loc_ref );
}

check( '2 · le code public est « verifiez » pour tous',
	false !== strpos( $loc_ref, 'code=verifiez' ) );
check( '2 · la Location ne porte NI adresse NI jeton NI motif technique',
	false === strpos( $loc_ref, '@' )
	&& 1 !== preg_match( '/[0-9a-f]{64}/', $loc_ref )
	&& false === strpos( $loc_ref, 'prise' )
	&& false === strpos( $loc_ref, 'quota' ) );

// Analyse fine de la requête : elle doit contenir EXACTEMENT deux paramètres,
// action=urbizen_resultat et code=verifiez, sans aucun autre paramètre, sans
// fragment, sans information utilisateur (auth, user, host portant une @).
$parts = parse_url( $loc_ref );
$query = array();
parse_str( $parts['query'] ?? '', $query );

check( '2 · la Location ne porte AUCUN fragment', ! isset( $parts['fragment'] ) || '' === $parts['fragment'] );
check( '2 · la Location ne porte AUCUNE information d’authentification', ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) );
ksort( $query );
$query_attendue = array( 'action' => 'urbizen_resultat', 'code' => 'verifiez' );
ksort( $query_attendue );
check( '2 · la requête contient EXACTEMENT action=urbizen_resultat et code=verifiez, rien d’autre',
	$query === $query_attendue );

// ----------------------------------------------------------------------
// 3 · La page de résultat, chargée en GET, est identique octet par octet
// ----------------------------------------------------------------------
$resultats = array();

foreach ( $cas as $nom => $rep ) {
	$resultats[ $nom ] = requete( 'GET', $rep['location'] );
}

$ref = $resultats['A_libre'];

foreach ( $resultats as $nom => $res ) {
	check( sprintf( '3 · %s : page de résultat servie en HTTP 200', $nom ), 200 === $res['status'] );
	check( sprintf( '3 · %s : statut de la page identique', $nom ), $res['status'] === $ref['status'] );
	check( sprintf( '3 · %s : CORPS IDENTIQUE À L\'OCTET PRÈS', $nom ), $res['body'] === $ref['body'] );
}

// En-têtes protecteurs de la page de résultat : PRÉSENCE ET VALEUR, sur CHACUNE
// des quatre pages. L'égalité d'une page à l'autre ne suffit pas : quatre
// en-têtes absents seraient tous « identiques » et passeraient. On vérifie donc
// la valeur protectrice attendue avant de vérifier qu'elle ne varie pas.
foreach ( $resultats as $nom => $res ) {
	$cc = strtolower( $res['headers']['cache-control'] ?? '' );
	check( sprintf( '3 · %s : Cache-Control contient private, no-store ET max-age=0', $nom ),
		false !== strpos( $cc, 'private' )
		&& false !== strpos( $cc, 'no-store' )
		&& false !== strpos( $cc, 'max-age=0' ) );
	check( sprintf( '3 · %s : Referrer-Policy = no-referrer', $nom ),
		'no-referrer' === strtolower( trim( $res['headers']['referrer-policy'] ?? '' ) ) );
	check( sprintf( '3 · %s : X-Robots-Tag = noindex, nofollow', $nom ),
		'noindex, nofollow' === strtolower( trim( $res['headers']['x-robots-tag'] ?? '' ) ) );
}

// Et ces en-têtes ne varient pas d'un état de compte à l'autre.
foreach ( array( 'cache-control', 'referrer-policy', 'x-robots-tag' ) as $h ) {
	$ref_h = $ref['headers'][ $h ] ?? '__absent__';

	foreach ( $resultats as $nom => $res ) {
		check( sprintf( '3 · %s : en-tête %s identique', $nom, $h ),
			( $res['headers'][ $h ] ?? '__absent__' ) === $ref_h );
	}
}

// ----------------------------------------------------------------------
// 4 · Aucune fuite dans les réponses (Location + corps de résultat)
// ----------------------------------------------------------------------
$adresses = array( $adr_libre, $adr_non_ver, $adr_verifiee, $adr_quota );

foreach ( $cas as $nom => $rep ) {
	$tout = $rep['raw_headers'] . $rep['body'] . $resultats[ $nom ]['raw_headers'] . $resultats[ $nom ]['body'];

	$fuite_adresse = false;

	foreach ( $adresses as $a ) {
		if ( false !== strpos( $tout, $a ) ) {
			$fuite_adresse = true;
		}
	}

	check( sprintf( '4 · %s : AUCUNE ADRESSE ne fuit', $nom ), ! $fuite_adresse );
	check( sprintf( '4 · %s : aucun jeton de 64 hex ne fuit', $nom ),
		1 !== preg_match( '/[0-9a-f]{64}/', $tout ) );
	check( sprintf( '4 · %s : aucun motif technique ne fuit', $nom ),
		false === strpos( $tout, 'adresse_prise' )
		&& false === strpos( $tout, 'quota_epuise' )
		&& false === strpos( $tout, 'non_verifiee' ) );
}

// ----------------------------------------------------------------------
// 5 · Effets métier en base — présents, mais JAMAIS exposés
// ----------------------------------------------------------------------
// A : compte créé, non vérifié, émission en vol.
$u_libre = get_user_by( 'email', $adr_libre );
check( '5 · A : le compte a été CRÉÉ', false !== $u_libre );
check( '5 · A : il est non vérifié',
	'1' !== (string) get_user_meta( $u_libre->ID, VerificationService::META_VERIFIE, true ) );
// Le courriel étant accepté par le transport intercepté, l'émission est
// CONFIRMÉE puis close : la méta d'émission en attente disparaît, le jeton
// (condensat) subsiste, et le quota porte un créneau. C'est cet état-là qui
// prouve qu'une émission a bien eu lieu et abouti.
check( '5 · A : un jeton a été émis (condensat présent)',
	'' !== (string) get_user_meta( $u_libre->ID, JetonVerification::META_CONDENSAT, true ) );
check( '5 · A : le quota porte un créneau',
	1 === count( LimiteEnvois::decoder_source(
		(string) get_user_meta( $u_libre->ID, LimiteEnvois::META_SOURCE, true ) ?: null )['entrees'] ) );

// B : non vérifiée → une émission a été relancée et confirmée.
check( '5 · B : un jeton a été (re)émis pour le compte non vérifié',
	'' !== (string) get_user_meta( $id_non_ver, JetonVerification::META_CONDENSAT, true ) );
check( '5 · B : le quota du compte non vérifié porte un créneau',
	1 === count( LimiteEnvois::decoder_source(
		(string) get_user_meta( $id_non_ver, LimiteEnvois::META_SOURCE, true ) ?: null )['entrees'] ) );

// C : vérifiée → AUCUNE émission, AUCUN jeton.
check( '5 · C : AUCUNE émission pour un compte déjà vérifié',
	'' === (string) get_user_meta( $id_verifiee, \Urbizen\Platform\Account\EmissionEnAttente::META, true ) );
check( '5 · C : aucun condensat de jeton',
	'' === (string) get_user_meta( $id_verifiee, JetonVerification::META_CONDENSAT, true ) );

// D : quota plein → aucune nouvelle émission.
check( '5 · D : AUCUNE émission quand le quota est épuisé',
	'' === (string) get_user_meta( $id_quota, \Urbizen\Platform\Account\EmissionEnAttente::META, true ) );

// ----------------------------------------------------------------------
// 6 · Interception du courriel — rien n'est sorti, et vers les bonnes adresses
// ----------------------------------------------------------------------
$mails = array();

if ( '' !== $mailbox && is_file( $mailbox ) ) {
	foreach ( file( $mailbox, FILE_IGNORE_NEW_LINES ) as $ligne ) {
		$m = json_decode( $ligne, true );

		if ( is_array( $m ) ) {
			$mails[] = $m;
		}
	}
}

$mails_apres = array_slice( $mails, $mailbox_avant );
$destinataires = array_map( static fn( $m ) => is_array( $m['to'] ) ? implode( ',', $m['to'] ) : (string) $m['to'], $mails_apres );
$dest_concat   = implode( ' | ', $destinataires );

check( '6 · EXACTEMENT deux courriels interceptés (aucun envoi réel possible)',
	2 === count( $mails_apres ) );

// Ensemble EXACT des destinataires : A et B, une fois chacun, sans troisième
// message ni destinataire supplémentaire. On décompose chaque champ « to »
// (qui peut être une liste) et l'on compare l'inventaire complet.
$compte_dest = array();

foreach ( $destinataires as $d ) {
	foreach ( explode( ',', $d ) as $un ) {
		$un = trim( $un );

		if ( '' !== $un ) {
			$compte_dest[ $un ] = ( $compte_dest[ $un ] ?? 0 ) + 1;
		}
	}
}

$attendu = array( $adr_libre => 1, $adr_non_ver => 1 );
ksort( $compte_dest );
ksort( $attendu );

check( '6 · destinataires EXACTEMENT { A, B }, une fois chacun, rien d’autre',
	$compte_dest === $attendu );

// Contrôles nominatifs, redondants mais lisibles, sur le même inventaire.
check( '6 · A (adresse libre) a bien reçu un message', 1 === ( $compte_dest[ $adr_libre ] ?? 0 ) );
check( '6 · B (non vérifiée) a bien reçu un message', 1 === ( $compte_dest[ $adr_non_ver ] ?? 0 ) );
check( '6 · C (déjà vérifiée) NE reçoit AUCUN message', ! isset( $compte_dest[ $adr_verifiee ] ) );
check( '6 · D (quota épuisé) NE reçoit AUCUN message', ! isset( $compte_dest[ $adr_quota ] ) );

// ----------------------------------------------------------------------
// Nettoyage des comptes de banc
// ----------------------------------------------------------------------
foreach ( $adresses as $a ) {
	purger_compte( $a );
}

printf( "\n%d contrôle(s) réussi(s), %d en échec\n", $reussis, $echecs );

exit( $echecs > 0 ? 1 : 0 );
