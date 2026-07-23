<?php
/**
 * Banc : le verrou temporaire par compte.
 *
 * Deux propriétés sont éprouvées, et ce sont celles qu'une première version du
 * verrou de migration n'avait pas :
 *
 *   la reprise d'un verrou expiré est ATOMIQUE — un lecteur périmé ne peut pas
 *   détruire le verrou tout neuf d'un tiers ;
 *
 *   le verrou n'est PAS une marque permanente — un échec le libère, et rien de
 *   définitif ne reste derrière.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/doublures.php';

use Urbizen\Platform\Account\VerrouCompte;
use Urbizen\Platform\Domain\Support\Ulid;

$db = new PasserelleOptions();

// ======================================================================
// 1 · ACQUISITION
// ======================================================================
$v1 = VerrouCompte::acquerir( $db, 42, 1000 );

check( '1 · le verrou s’acquiert', $v1 instanceof VerrouCompte );
check( '1 · son propriétaire est un ULID', Ulid::est_valide( $v1->proprietaire() ) );
check( '1 · il est vivant', $v1->est_vivant( 1000 ) );
check( '1 · la valeur stockée est du JSON prévisible',
	is_array( json_decode( $db->options[ VerrouCompte::option_pour( 42 ) ] ?? '', true ) ) );
check( '1 · le nom d’option ne révèle pas l’identifiant',
	false === strpos( VerrouCompte::option_pour( 42 ), '42' ) );
check( '1 · UN SECOND PROCESSUS EST REFUSÉ', null === VerrouCompte::acquerir( $db, 42, 1001 ) );
check( '1 · un autre compte n’est pas bloqué', VerrouCompte::acquerir( $db, 43, 1001 ) instanceof VerrouCompte );
check( '1 · un identifiant nul est refusé', null === VerrouCompte::acquerir( $db, 0, 1000 ) );

// ======================================================================
// 2 · LIBÉRATION PAR LE SEUL PROPRIÉTAIRE
// ======================================================================
$expire = 1000 + VerrouCompte::TTL + 1;
$repreneur = VerrouCompte::acquerir( $db, 42, $expire );

check( '2 · UN VERROU EXPIRÉ EST REPRIS', $repreneur instanceof VerrouCompte );
check( '2 · par un propriétaire différent', $repreneur->proprietaire() !== $v1->proprietaire() );
check( '2 · L’ANCIEN NE PEUT PLUS LIBÉRER', false === $v1->liberer() );
check( '2 · le verrou du repreneur est intact', isset( $db->options[ VerrouCompte::option_pour( 42 ) ] ) );
check( '2 · le repreneur libère', $repreneur->liberer() );
check( '2 · l’option a disparu', ! isset( $db->options[ VerrouCompte::option_pour( 42 ) ] ) );
check( '2 · libérer deux fois ne rend pas vrai', false === $repreneur->liberer() );

// ======================================================================
// 3 · LE SCÉNARIO DU DÉFAUT — un lecteur périmé face à un verrou neuf
// ======================================================================
$db2 = new PasserelleOptions();

VerrouCompte::acquerir( $db2, 7, 1000 );                       // verrou qui va expirer
$p3 = VerrouCompte::acquerir( $db2, 7, 1000 + VerrouCompte::TTL + 1 ); // P3 reprend

check( '3 · P3 a repris le verrou expiré', $p3 instanceof VerrouCompte );

// P1 arrive avec sa lecture périmée : sa reprise doit échouer.
$p1 = VerrouCompte::acquerir( $db2, 7, 1000 + VerrouCompte::TTL + 2 );

check( '3 · P1 NE PEUT PAS REPRENDRE LE VERROU VIVANT DE P3', null === $p1 );
check( '3 · LE VERROU DE P3 EST INTACT', $p3->est_vivant( 1000 + VerrouCompte::TTL + 2 ) );
check( '3 · P3 libère normalement', $p3->liberer() );

// ======================================================================
// 4 · CE N’EST PAS UNE MARQUE PERMANENTE
// ======================================================================
$db3 = new PasserelleOptions();

$a = VerrouCompte::acquerir( $db3, 9, 2000 );
$a->liberer();

check( '4 · APRÈS LIBÉRATION, LE MÊME COMPTE EST DE NOUVEAU VERROUILLABLE',
	VerrouCompte::acquerir( $db3, 9, 2001 ) instanceof VerrouCompte );

// ======================================================================
// 5 · VALEUR CORROMPUE
// ======================================================================
$db4 = new PasserelleOptions();
$db4->options[ VerrouCompte::option_pour( 11 ) ] = 'pas du json';

check( '5 · UN VERROU ILLISIBLE FAIT REFUSER', null === VerrouCompte::acquerir( $db4, 11, 3000 ) );

$db5 = new PasserelleOptions();
$db5->options[ VerrouCompte::option_pour( 11 ) ] =
	'{"proprietaire":"pas-un-ulid","cree_le":1,"expire_le":2}';

check( '5 · un propriétaire non conforme fait refuser', null === VerrouCompte::acquerir( $db5, 11, 3000 ) );

// ======================================================================
// 6 · LE SQL PORTE BIEN LA CONDITION
// ======================================================================
$db6 = new PasserelleOptions();
$x   = VerrouCompte::acquerir( $db6, 5, 4000 );
$x->liberer();

$avec_condition = 0;

foreach ( $db6->instructions as $sql ) {
	if ( ( 0 === strpos( $sql, 'DELETE' ) || 0 === strpos( $sql, 'UPDATE' ) )
		&& 1 === preg_match( '/option_value\s*=\s*%s/', $sql ) ) {
		++$avec_condition;
	}
}

check( '6 · LA LIBÉRATION PORTE LA CONDITION SUR LA VALEUR', $avec_condition >= 1 );
check( '6 · l’insertion précise autoload = no',
	false !== strpos( implode( ' ', $db6->instructions ), 'autoload' ) );

verdict();
