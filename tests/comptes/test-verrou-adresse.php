<?php
/**
 * Banc : le verrou d'exclusion d'inscription, dérivé de l'adresse.
 *
 * Le verrou repose sur `GET_LOCK()` : il tient tant que la **connexion** vit,
 * sans échéance. Deux passerelles d'identités différentes modélisent deux
 * connexions concurrentes, et l'on éprouve l'exclusivité, la libération par le
 * seul détenteur, et — propriété de sûreté centrale — que le nom du verrou **ne
 * révèle jamais l'adresse** : il en est un HMAC avec le secret du site, tronqué
 * sous la limite de 64 caractères du moteur, non un condensat recalculable.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\VerrouAdresse;

PasserelleOptions::reinitialiser_verrous();

$adresse = 'claire@exemple.fr';
$conn_a  = new PasserelleOptions( 'A' );
$conn_b  = new PasserelleOptions( 'B' );

// ======================================================================
// 1 · ACQUISITION ET EXCLUSIVITÉ ENTRE CONNEXIONS
// ======================================================================
$v1 = VerrouAdresse::acquerir( $conn_a, $adresse, 0 );

check( '1 · le verrou s’acquiert', $v1 instanceof VerrouAdresse );
check( '1 · il est tenu', $v1->est_tenu() );
check( '1 · UNE AUTRE CONNEXION EST REFUSÉE PENDANT QU’ON TIENT',
	null === VerrouAdresse::acquerir( $conn_b, $adresse, 0 ) );
check( '1 · une autre adresse n’est pas bloquée',
	VerrouAdresse::acquerir( $conn_b, 'bob@exemple.fr', 0 ) instanceof VerrouAdresse );
check( '1 · une adresse vide est refusée', null === VerrouAdresse::acquerir( $conn_a, '', 0 ) );

// ======================================================================
// 2 · LE NOM DE VERROU NE PORTE PAS L'ADRESSE, ET C'EST UN HMAC
// ======================================================================
$nom = VerrouAdresse::nom_pour( $adresse );

check( '2 · le nom de verrou ne contient pas l’adresse',
	false === strpos( $nom, $adresse )
	&& false === strpos( $nom, 'claire' )
	&& false === strpos( $nom, 'exemple' ) );
check( '2 · le nom du verrou tenu est bien celui-là', $v1->nom() === $nom );
check( '2 · il n’est PAS un simple sha256 de l’adresse (donc dérivé par secret)',
	$nom !== VerrouAdresse::PREFIXE . substr( hash( 'sha256', $adresse ), 0, 48 ) );
check( '2 · deux adresses différentes donnent deux noms différents',
	VerrouAdresse::nom_pour( 'a@exemple.fr' ) !== VerrouAdresse::nom_pour( 'b@exemple.fr' ) );
check( '2 · la même adresse donne toujours le même nom (déterministe)',
	VerrouAdresse::nom_pour( $adresse ) === VerrouAdresse::nom_pour( $adresse ) );
check( '2 · le nom tient sous la limite de 64 caractères du moteur',
	strlen( $nom ) <= 64 );

// ======================================================================
// 3 · LIBÉRATION PAR LE SEUL DÉTENTEUR
// ======================================================================
check( '3 · une autre connexion ne libère PAS notre verrou',
	false === VerrouAdresse::acquerir( $conn_b, $adresse, 0 ) instanceof VerrouAdresse );
check( '3 · le détenteur, lui, libère', true === $v1->liberer() );
check( '3 · une seconde libération ne porte pas', false === $v1->liberer() );
check( '3 · le verrou n’est plus tenu après libération', false === $v1->est_tenu() );
check( '3 · après libération, une autre connexion peut acquérir',
	VerrouAdresse::acquerir( $conn_b, $adresse, 0 ) instanceof VerrouAdresse );

// ======================================================================
// 4 · MORT DE LA CONNEXION — le verrou n'a pas d'échéance à attendre
// ======================================================================
// Ici, « la connexion B meurt » se modélise en oubliant ses verrous : c'est ce
// que fait le moteur quand la connexion se ferme. Aucune fenêtre à deux
// détenteurs : tant que B tient, A échoue ; dès que B disparaît, A prend.
PasserelleOptions::reinitialiser_verrous();
$conn_c = new PasserelleOptions( 'C' );
$conn_d = new PasserelleOptions( 'D' );

$vc = VerrouAdresse::acquerir( $conn_c, $adresse, 0 );
check( '4 · C tient le verrou', $vc instanceof VerrouAdresse );
check( '4 · D est refusé tant que C tient', null === VerrouAdresse::acquerir( $conn_d, $adresse, 0 ) );

PasserelleOptions::reinitialiser_verrous(); // C « meurt » : le moteur libère.

check( '4 · C mort, D acquiert immédiatement — pas d’échéance à attendre',
	VerrouAdresse::acquerir( $conn_d, $adresse, 0 ) instanceof VerrouAdresse );

verdict();
