<?php
/**
 * Banc : rendu des courriels et fabrication du lien.
 *
 * Deux points portent le reste :
 *
 * - **un seul corps, en HTML**, avec l'URL écrite en clair. Le contrat de
 *   `MailTransport::send()` n'accepte qu'un `$corps` ; on ne le contourne ni
 *   par un multipart fabriqué à la main, ni par `phpmailer_init`. La
 *   lisibilité vient de l'URL copiable, pas d'une seconde partie ;
 * - **l'avertissement ne porte ni jeton, ni lien, ni la nouvelle adresse**. Il
 *   ne les reçoit même pas : ce qu'une méthode ne connaît pas, elle ne peut
 *   pas le divulguer.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Account\CourrielVerification;
use Urbizen\Platform\Account\JetonVerification;
use Urbizen\Platform\Account\LienVerification;

$base  = 'https://exemple.fr/wp-admin/admin-post.php';
$jeton = str_repeat( 'ab', 32 );

// ======================================================================
// 1 · LE LIEN
// ======================================================================
$lien = LienVerification::pour( $base, 42, $jeton );

check( '1 · le lien porte l\'action', false !== strpos( $lien, 'action=urbizen_verification' ) );
check( '1 · il porte le compte', false !== strpos( $lien, 'c=42' ) );
check( '1 · il porte le jeton', false !== strpos( $lien, 't=' . $jeton ) );
check( '1 · il part de la base fournie', 0 === strpos( $lien, $base . '?' ) );

check( '1 · une base déjà pourvue d\'une requête est respectée',
	false !== strpos( LienVerification::pour( $base . '?x=1', 42, $jeton ), '?x=1&' ) );

check( '1 · base vide : aucun lien', '' === LienVerification::pour( '', 42, $jeton ) );
check( '1 · compte nul : aucun lien', '' === LienVerification::pour( $base, 0, $jeton ) );
check( '1 · jeton vide : aucun lien', '' === LienVerification::pour( $base, 42, '' ) );
check( '1 · JETON DE FORME INATTENDUE : AUCUN LIEN',
	'' === LienVerification::pour( $base, 42, 'pas-un-jeton' ) );

// ======================================================================
// 2 · RELECTURE — forme seulement, aucune décision
// ======================================================================
$lu = LienVerification::lire( array( 'c' => '42', 't' => $jeton ) );
check( '2 · relecture d\'une requête bien formée',
	null !== $lu && 42 === $lu['compte'] && $jeton === $lu['jeton'] );

check( '2 · compte non numérique refusé',
	null === LienVerification::lire( array( 'c' => '4x', 't' => $jeton ) ) );
check( '2 · compte nul refusé',
	null === LienVerification::lire( array( 'c' => '0', 't' => $jeton ) ) );
check( '2 · jeton de forme invalide refusé',
	null === LienVerification::lire( array( 'c' => '42', 't' => 'court' ) ) );
check( '2 · paramètres absents refusés', null === LienVerification::lire( array() ) );
check( '2 · jeton en tableau refusé',
	null === LienVerification::lire( array( 'c' => '42', 't' => array( 'x' ) ) ) );

check( '2 · aller-retour : ce qui est fabriqué est relisible',
	( static function () use ( $base, $jeton ): bool {
		$url = LienVerification::pour( $base, 7, $jeton );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
		$relu = LienVerification::lire( $q );

		return null !== $relu && 7 === $relu['compte'] && $jeton === $relu['jeton'];
	} )() );

// ======================================================================
// 3 · LE COURRIEL DE VÉRIFICATION — un seul corps, HTML
// ======================================================================
$m = CourrielVerification::rendre( 'claire@exemple.fr', $lien, 'Urbizen' );

check( '3 · trois clés rendues',
	array( 'sujet', 'corps', 'entetes' ) === array_keys( $m ) );
check( '3 · UN SEUL corps, et c\'est une chaîne', is_string( $m['corps'] ) );
check( '3 · l\'en-tête déclare text/html, comme MailRenderer',
	array( 'Content-Type: text/html; charset=UTF-8' ) === $m['entetes'] );
check( '3 · AUCUN multipart n\'est fabriqué',
	false === stripos( implode( ' ', $m['entetes'] ) . $m['corps'], 'multipart' ) );
check( '3 · aucune frontière MIME dans le corps',
	false === stripos( $m['corps'], 'boundary' ) );

check( '3 · le corps porte le lien cliquable',
	false !== strpos( $m['corps'], 'href="' . htmlspecialchars( $lien, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"' ) );
check( '3 · ET L\'URL EST ÉCRITE EN CLAIR, hors attribut href',
	1 < substr_count( $m['corps'], htmlspecialchars( $lien, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ) );
check( '3 · le corps nomme l\'adresse confirmée',
	false !== strpos( $m['corps'], 'claire@exemple.fr' ) );
check( '3 · le sujet ne porte ni jeton ni adresse',
	false === strpos( $m['sujet'], $jeton ) && false === strpos( $m['sujet'], 'claire@exemple.fr' ) );
check( '3 · le corps dit que le lien ne connecte pas',
	false !== strpos( $m['corps'], 'ne vous connecte pas' ) );

// Échappement : une adresse hostile ne doit pas ouvrir de balise.
$hostile = CourrielVerification::rendre( 'a"><script>x</script>@exemple.fr', $lien );
check( '3 · l\'adresse est échappée',
	false === strpos( $hostile['corps'], '<script>' ) );

// ======================================================================
// 4 · L'AVERTISSEMENT — sans jeton, sans lien, sans nouvelle adresse
// ======================================================================
$a = CourrielVerification::rendre_avertissement( 'Urbizen' );

check( '4 · trois clés rendues', array( 'sujet', 'corps', 'entetes' ) === array_keys( $a ) );
check( '4 · AUCUN JETON dans le corps', false === strpos( $a['corps'], $jeton ) );
check( '4 · AUCUN LIEN cliquable', false === stripos( $a['corps'], '<a href' ) );
check( '4 · aucune URL de vérification', false === strpos( $a['corps'], LienVerification::ACTION ) );
check( '4 · aucun http:// ni https://', false === stripos( $a['corps'], 'http' ) );

/*
 * La signature elle-même interdit la fuite : `rendre_avertissement()` ne
 * reçoit pas la nouvelle adresse. Le contrôle ci-dessous ne vaut donc pas
 * comme filtre, mais comme constat de cette conception.
 */
$reflexion = new ReflectionMethod( CourrielVerification::class, 'rendre_avertissement' );
$noms      = array();

foreach ( $reflexion->getParameters() as $parametre ) {
	$noms[] = $parametre->getName();
}

check( '4 · LA MÉTHODE NE REÇOIT AUCUNE ADRESSE — la fuite est structurellement impossible',
	array( 'site' ) === $noms );

check( '4 · l\'avertissement dit qu\'il ne permet rien',
	false !== strpos( $a['corps'], 'il ne permet rien' ) );
check( '4 · même en-tête que le reste',
	array( 'Content-Type: text/html; charset=UTF-8' ) === $a['entetes'] );
check( '4 · le sujet ne nomme aucune adresse',
	false === strpos( $a['sujet'], '@' ) );

// ======================================================================
// 5 · AUCUN APPEL À wp_mail() — contrôle lexical
// ======================================================================
$racine  = dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Account/';
$sources = array( 'CourrielVerification.php', 'LienVerification.php' );

foreach ( $sources as $fichier ) {
	$code = (string) file_get_contents( $racine . $fichier );

	check( '5 · ' . $fichier . ' ne nomme jamais la fonction d\'envoi de WordPress',
		1 !== preg_match( '/\bwp_mail\s*\(/', $code ) );
	check( '5 · ' . $fichier . ' n\'appelle jamais MailTransport::send',
		false === strpos( $code, '->send(' ) );
}

verdict();
