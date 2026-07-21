<?php
/**
 * Requête multipart **réellement** tronquée, contre un vrai WordPress.
 *
 * Appeler `process()` avec un tableau `$_FILES` fabriqué ne prouve rien sur la
 * troncature : c'est le transport qui perd les fichiers, et c'est donc lui
 * qu'il faut éprouver. Ce banc écrit le corps multipart octet par octet et
 * l'envoie sur une socket, à un serveur PHP servant l'installation jetable.
 *
 * Deux variantes de troncature sont produites :
 *
 * 1. **partie entièrement absente** — le manifeste annonce vingt documents, le
 *    corps n'en contient que dix-neuf. C'est ce que produit `max_file_uploads`
 *    lorsqu'il plafonne : PHP livre une partie des fichiers sans le signaler.
 * 2. **dernière partie incomplète** — le corps est coupé au milieu du
 *    vingtième document, sans frontière de fin.
 *
 * Aucun courriel n'est émis : `pre_wp_mail` est court-circuité par une
 * extension déposée dans l'installation jetable.
 *
 * Toutes les données sont fictives.
 */

$racine = (string) getenv( 'URBIZEN_WP_ROOT' );
$hote   = (string) ( getenv( 'URBIZEN_WP_HOST' ) ?: '127.0.0.1:8799' );

if ( '' === $racine || ! is_readable( $racine . '/wp-load.php' ) ) {
	fwrite( STDERR, "URBIZEN_WP_ROOT non défini ou illisible\n" );
	exit( 0 );
}

$reussis = 0;
$echecs  = 0;

function verifier( string $libelle, bool $ok ): void {
	global $reussis, $echecs;

	if ( $ok ) {
		++$reussis;
		printf( "%-72s OK\n", $libelle );
	} else {
		++$echecs;
		printf( "%-72s ECHEC\n", $libelle );
	}
}

/**
 * Construit un corps multipart.
 *
 * @param string                          $frontiere Frontière.
 * @param array<string, string>           $champs    Champs simples.
 * @param array<int, array{0:string,1:string,2:string}> $fichiers Triplets bloc / nom / contenu.
 * @param bool                            $tronquer  Couper la dernière partie.
 * @return string
 */
function corps_multipart( string $frontiere, array $champs, array $fichiers, bool $tronquer = false ): string {
	$corps = '';

	foreach ( $champs as $nom => $valeur ) {
		$corps .= "--$frontiere\r\n";
		$corps .= "Content-Disposition: form-data; name=\"$nom\"\r\n\r\n";
		$corps .= $valeur . "\r\n";
	}

	$dernier = count( $fichiers ) - 1;

	foreach ( $fichiers as $rang => $f ) {
		list( $bloc, $nom, $contenu ) = $f;

		$partie  = "--$frontiere\r\n";
		$partie .= "Content-Disposition: form-data; name=\"{$bloc}[]\"; filename=\"$nom\"\r\n";
		$partie .= "Content-Type: image/jpeg\r\n\r\n";
		$partie .= $contenu . "\r\n";

		if ( $tronquer && $rang === $dernier ) {
			// La dernière partie est coupée en plein contenu : ni fin de
			// partie, ni frontière de clôture. C'est exactement ce qu'un
			// transport interrompu produit.
			$corps .= substr( $partie, 0, (int) ( strlen( $partie ) * 0.6 ) );

			return $corps;
		}

		$corps .= $partie;
	}

	return $corps . "--$frontiere--\r\n";
}

/**
 * Envoie une requête HTTP brute et rend l'en-tête `Location`.
 *
 * @param string $hote  Hôte et port.
 * @param string $corps Corps multipart.
 * @param string $frontiere Frontière.
 * @return array{status:int,location:string}
 */
function envoyer( string $hote, string $corps, string $frontiere ): array {
	list( $ip, $port ) = explode( ':', $hote );
	$socket            = @fsockopen( $ip, (int) $port, $errno, $errstr, 10 );

	if ( ! $socket ) {
		return array( 'status' => 0, 'location' => '' );
	}

	$requete  = "POST /wp-admin/admin-post.php HTTP/1.1\r\n";
	$requete .= "Host: $hote\r\n";
	$requete .= "Content-Type: multipart/form-data; boundary=$frontiere\r\n";
	$requete .= 'Content-Length: ' . strlen( $corps ) . "\r\n";
	$requete .= "Connection: close\r\n\r\n";

	fwrite( $socket, $requete . $corps );

	$reponse = '';

	while ( ! feof( $socket ) ) {
		$reponse .= fgets( $socket, 4096 );
	}

	fclose( $socket );

	preg_match( '#^HTTP/1\.\d (\d{3})#', $reponse, $s );
	preg_match( '#^Location:\s*(.+)$#mi', $reponse, $l );

	return array(
		'status'   => (int) ( $s[1] ?? 0 ),
		'location' => trim( (string) ( $l[1] ?? '' ) ),
	);
}

require dirname( __FILE__ ) . '/amorce-reelle.php';

// Ce banc crée son état et le rend, quoi qu'il advienne : aucun test ne doit
// dépendre de l'ordre d'exécution.
urbizen_banc_exiger_cron_desactive();
urbizen_banc_reset();
urbizen_banc_menage_a_la_sortie();

/**
 * État de la base, pour prouver qu'un refus ne laisse rien.
 *
 * @return array<string, int>
 */
function etat(): array {
	global $wpdb;

	wp_cache_flush();

	$prive = dirname( ABSPATH ) . '/private/urbizen-conception';
	$cron  = 0;

	foreach ( (array) _get_cron_array() as $ts => $c ) {
		$cron += count( (array) ( $c['urbizen_send_submission_mail'] ?? array() ) );
	}

	$fichiers = 0;

	if ( is_dir( $prive ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $prive, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $f->isFile() && ! in_array( $f->getFilename(), array( 'index.php', '.htaccess' ), true ) && ! str_contains( $f->getPathname(), '/locks/' ) ) {
				++$fichiers;
			}
		}
	}

	return array(
		'demandes'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='urbizen_demande'" ),
		'attributed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'urbizen_ref_%' AND option_value LIKE '%attributed%'" ),
		'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_urbizen_mail_status' AND meta_value='pending'" ),
		'evenements' => $cron,
		'fichiers'   => $fichiers,
		'courriels'  => (int) get_option( 'urbizen_banc_mails', 0 ),
	);
}

$jpeg = base64_decode( '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==' );

$avant = etat();

verifier( 'départ · aucune demande', 0 === $avant['demandes'] );
verifier( 'départ · aucune référence', 0 === $avant['attributed'] );
verifier( 'départ · aucun événement mail', 0 === $avant['evenements'] );
verifier( 'départ · aucun document', 0 === $avant['fichiers'] );

// --- champs communs : le formulaire réel fournit nonce et jeton ---
$nonce  = wp_create_nonce( 'urbizen_conception_submit' );
$jeton  = \Urbizen\Platform\Security\AntiSpam::issue_token( time() - 60 );
$champs = array(
	'action'                   => 'urbizen_conception',
	'urbizen_conception_nonce' => $nonce,
	'urbizen_token'            => $jeton,
	'nature'                   => 'maison',
	'situation'                => 'terrain_nu',
	'a_terrain'                => 'non',
	'nom'                      => 'Camille Fictif',
	'email'                    => 'camille@exemple.test',
	'tel'                      => '0100000000',
	'rgpd'                     => '1',
);

// ======================================================================
// A · PARTIE ENTIÈREMENT ABSENTE : 20 ANNONCÉS, 19 ENVOYÉS
// ======================================================================
$vingt = array();

for ( $i = 0; $i < 10; $i++ ) {
	$vingt[] = array( 'photos', "photo-$i.jpg", $jpeg );
}

for ( $i = 0; $i < 10; $i++ ) {
	$vingt[] = array( 'plan_terrain', "plan-$i.jpg", $jpeg );
}

$taille = strlen( $jpeg );

$manifeste_vingt = (string) wp_json_encode(
	array(
		'version'     => 1,
		'total_count' => 20,
		'total_size'  => 20 * $taille,
		'blocks'      => array(
			'photos'       => array( 'count' => 10, 'size' => 10 * $taille ),
			'plan_terrain' => array( 'count' => 10, 'size' => 10 * $taille ),
		),
	)
);

// Le corps ne contient que dix-neuf documents : le vingtième n'est jamais écrit.
$dix_neuf  = array_slice( $vingt, 0, 19 );
$frontiere = '----urbizen' . bin2hex( random_bytes( 8 ) );
$corps     = corps_multipart(
	$frontiere,
	$champs + array( 'urbizen_manifest' => $manifeste_vingt ),
	$dix_neuf
);

$r = envoyer( $hote, $corps, $frontiere );

verifier( 'A · la requête a été servie', 302 === $r['status'] );
verifier( 'A · LE SERVEUR REFUSE', str_contains( $r['location'], 'urbizen_submission=error' ) );
verifier( 'A · aucune réponse de succès', ! str_contains( $r['location'], 'success' ) );

$apres = etat();

verifier( 'A · aucune demande finalisée', 0 === $apres['demandes'] );
verifier( 'A · aucune référence attributed', 0 === $apres['attributed'] );
verifier( 'A · aucune notification pending', 0 === $apres['pending'] );
verifier( 'A · aucun événement mail', 0 === $apres['evenements'] );
verifier( 'A · aucun document final', 0 === $apres['fichiers'] );
verifier( 'A · aucun courriel', 0 === $apres['courriels'] );

// ======================================================================
// B · DERNIÈRE PARTIE MULTIPART INCOMPLÈTE
// ======================================================================
$nonce = wp_create_nonce( 'urbizen_conception_submit' );
$jeton = \Urbizen\Platform\Security\AntiSpam::issue_token( time() - 60 );

$frontiere = '----urbizen' . bin2hex( random_bytes( 8 ) );
$corps     = corps_multipart(
	$frontiere,
	array_merge( $champs, array( 'urbizen_conception_nonce' => $nonce, 'urbizen_token' => $jeton, 'urbizen_manifest' => $manifeste_vingt ) ),
	$vingt,
	true
);

$r = envoyer( $hote, $corps, $frontiere );

verifier( 'B · la requête a été servie', in_array( $r['status'], array( 200, 302, 400 ), true ) );
verifier( 'B · aucune réponse de succès', ! str_contains( $r['location'], 'success' ) );

$apres = etat();

verifier( 'B · aucune demande finalisée', 0 === $apres['demandes'] );
verifier( 'B · aucune référence attributed', 0 === $apres['attributed'] );
verifier( 'B · aucune notification pending', 0 === $apres['pending'] );
verifier( 'B · aucun événement mail', 0 === $apres['evenements'] );
verifier( 'B · aucun document final', 0 === $apres['fichiers'] );

// ======================================================================
// C · TÉMOIN : VINGT ANNONCÉS, VINGT ENVOYÉS
// ======================================================================
// Sans ce témoin, on ne saurait pas si les refus précédents viennent de la
// troncature ou d'un défaut du banc lui-même.
$nonce = wp_create_nonce( 'urbizen_conception_submit' );
$jeton = \Urbizen\Platform\Security\AntiSpam::issue_token( time() - 60 );

$frontiere = '----urbizen' . bin2hex( random_bytes( 8 ) );
$corps     = corps_multipart(
	$frontiere,
	array_merge( $champs, array( 'urbizen_conception_nonce' => $nonce, 'urbizen_token' => $jeton, 'urbizen_manifest' => $manifeste_vingt ) ),
	$vingt
);

$r = envoyer( $hote, $corps, $frontiere );

if ( ! str_contains( $r['location'], 'success' ) ) {
	printf( "  [diagnostic] location = %s\n", $r['location'] );
	printf( "  [diagnostic] max_file_uploads = %s\n", ini_get( 'max_file_uploads' ) );
}

verifier( 'C · TÉMOIN : vingt envoyés, vingt annoncés → SUCCÈS', str_contains( $r['location'], 'urbizen_submission=success' ) );

$apres = etat();

verifier( 'C · une demande finalisée', 1 === $apres['demandes'] );
verifier( 'C · une référence attributed', 1 === $apres['attributed'] );
verifier( 'C · une notification pending', 1 === $apres['pending'] );
verifier( 'C · un événement mail', 1 === $apres['evenements'] );
verifier( 'C · vingt documents stockés', 20 === $apres['fichiers'] );
verifier( 'C · aucun courriel externe', 0 === $apres['courriels'] );

// Ménage explicite, puis constat : le banc ne laisse rien derrière lui.
urbizen_banc_reset();
$reste = urbizen_banc_etat();

verifier( 'sortie · zéro demande', 0 === $reste['demandes'] );
verifier( 'sortie · zéro référence', 0 === $reste['references'] );
verifier( 'sortie · zéro notification', 0 === $reste['notifs'] );
verifier( 'sortie · zéro événement mail', 0 === $reste['evenements'] );
verifier( 'sortie · zéro document', 0 === $reste['documents'] );
verifier( 'sortie · zéro staging', 0 === $reste['staging'] );
verifier( 'sortie · zéro verrou', 0 === $reste['verrous_opt'] );

printf( "\n%d contrôle(s) réussi(s), %d en échec\n", $reussis, $echecs );

exit( $echecs > 0 ? 1 : 0 );
