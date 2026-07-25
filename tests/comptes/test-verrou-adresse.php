<?php
/**
 * Banc : le verrou temporaire d'inscription, dérivé de l'adresse.
 *
 * Il reprend le compare-et-échange de VerrouCompte, mais garantit en plus que
 * le nom d'option **ne révèle jamais l'adresse** : il en est un HMAC avec le
 * secret du site, non un simple condensat que l'on pourrait recalculer.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\VerrouAdresse;
use Urbizen\Platform\Domain\Support\Ulid;

$db      = new PasserelleOptions();
$adresse = 'claire@exemple.fr';

// ======================================================================
// 1 · ACQUISITION
// ======================================================================
$v1 = VerrouAdresse::acquerir( $db, $adresse, 1000 );

check( '1 · le verrou s’acquiert', $v1 instanceof VerrouAdresse );
check( '1 · son propriétaire est un ULID', Ulid::est_valide( $v1->proprietaire() ) );
check( '1 · il est vivant', $v1->est_vivant( 1000 ) );
check( '1 · UN SECOND PROCESSUS EST REFUSÉ', null === VerrouAdresse::acquerir( $db, $adresse, 1001 ) );
check( '1 · une autre adresse n’est pas bloquée',
	VerrouAdresse::acquerir( $db, 'bob@exemple.fr', 1001 ) instanceof VerrouAdresse );
check( '1 · une adresse vide est refusée', null === VerrouAdresse::acquerir( $db, '', 1000 ) );

// ======================================================================
// 2 · LE NOM D'OPTION NE PORTE PAS L'ADRESSE, ET C'EST UN HMAC
// ======================================================================
$option = VerrouAdresse::option_pour( $adresse );

check( '2 · le nom d’option ne contient pas l’adresse',
	false === strpos( $option, $adresse )
	&& false === strpos( $option, 'claire' )
	&& false === strpos( $option, 'exemple' ) );
check( '2 · il n’est PAS un simple sha256 de l’adresse (donc dérivé par secret)',
	$option !== VerrouAdresse::PREFIXE . substr( hash( 'sha256', $adresse ), 0, 32 ) );
check( '2 · deux adresses différentes donnent deux noms différents',
	VerrouAdresse::option_pour( 'a@exemple.fr' ) !== VerrouAdresse::option_pour( 'b@exemple.fr' ) );
check( '2 · la même adresse donne toujours le même nom (déterministe)',
	VerrouAdresse::option_pour( $adresse ) === VerrouAdresse::option_pour( $adresse ) );

// ======================================================================
// 3 · LIBÉRATION PAR LE SEUL PROPRIÉTAIRE
// ======================================================================
$expire    = 1000 + VerrouAdresse::TTL + 1;
$repreneur = VerrouAdresse::acquerir( $db, $adresse, $expire ); // reprise du verrou expiré

check( '3 · un verrou expiré est repris', $repreneur instanceof VerrouAdresse );
check( '3 · l’ancien propriétaire ne libère PAS le verrou du repreneur', false === $v1->liberer() );
check( '3 · l’option existe toujours (celle du repreneur)',
	isset( $db->options[ $option ] ) );
check( '3 · le repreneur, lui, libère', true === $repreneur->liberer() );
check( '3 · l’option a disparu', ! isset( $db->options[ $option ] ) );
check( '3 · après libération, une nouvelle acquisition passe',
	VerrouAdresse::acquerir( $db, $adresse, $expire + 1 ) instanceof VerrouAdresse );

// ======================================================================
// 4 · REPRISE ATOMIQUE — un lecteur périmé ne détruit pas un verrou neuf
// ======================================================================
$db2 = new PasserelleOptions();
$a   = VerrouAdresse::acquerir( $db2, $adresse, 2000 );          // A tient
$exp = 2000 + VerrouAdresse::TTL + 1;
$b   = VerrouAdresse::acquerir( $db2, $adresse, $exp );          // B reprend (A expiré)

check( '4 · B reprend le verrou expiré de A', $b instanceof VerrouAdresse );
check( '4 · A, périmé, ne peut plus rien libérer', false === $a->liberer() );
check( '4 · le verrou de B est intact', $b->est_vivant( $exp ) );

verdict();
