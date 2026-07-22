<?php
/**
 * Banc : l'acteur courant.
 *
 * Un seul enjeu, mais il porte tout le reste : **l'anonyme doit exister**.
 * Tant qu'il est un objet comme les autres, une politique ne peut pas le
 * confondre avec une donnée manquante et en conclure à un accès.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Domain\Identity\ActeurCourant;

// ======================================================================
// 1 · ANONYME
// ======================================================================
$anonyme = ActeurCourant::anonyme();

check( '1 · l’anonyme est un objet, jamais null', $anonyme instanceof ActeurCourant );
check( '1 · son identifiant vaut zéro', 0 === $anonyme->id() );
check( '1 · il se déclare anonyme', $anonyme->est_anonyme() );
check( '1 · il n’a aucun rôle', array() === $anonyme->roles() );
check( '1 · son adresse n’est pas vérifiée', false === $anonyme->courriel_verifie() );

// Un identifiant négatif n'existe pas : il retombe sur l'anonyme.
check( '1 · un identifiant négatif retombe sur l’anonyme', ( new ActeurCourant( -5 ) )->est_anonyme() );
check( '1 · un identifiant nul aussi', ( new ActeurCourant( 0 ) )->est_anonyme() );

// ======================================================================
// 2 · ACTEUR IDENTIFIÉ
// ======================================================================
$acteur = new ActeurCourant( 42, array( 'Subscriber', 'urbizen_client', 'subscriber' ), true );

check( '2 · l’identifiant est conservé', 42 === $acteur->id() );
check( '2 · il n’est pas anonyme', false === $acteur->est_anonyme() );
check( '2 · l’adresse vérifiée est conservée', true === $acteur->courriel_verifie() );

// ======================================================================
// 3 · NORMALISATION DES RÔLES
// ======================================================================
check( '3 · les rôles sont ramenés en minuscules', $acteur->a_role( 'subscriber' ) );
check( '3 · le doublon de casse est fondu', 2 === count( $acteur->roles() ) );
check( '3 · les rôles sont triés', array( 'subscriber', 'urbizen_client' ) === $acteur->roles() );
check( '3 · a_role est insensible à la casse', $acteur->a_role( 'URBIZEN_CLIENT' ) );
check( '3 · a_role tolère les espaces', $acteur->a_role( '  subscriber ' ) );
check( '3 · un rôle absent est absent', false === $acteur->a_role( 'administrator' ) );

$sale = new ActeurCourant( 7, array( '', '   ', 'editor', 123, null, 'editor' ) );

check( '3 · les entrées non textuelles sont écartées', array( 'editor' ) === $sale->roles() );

// ======================================================================
// 4 · UN ANONYME N’A JAMAIS D’ADRESSE VÉRIFIÉE
// ======================================================================
$menteur = new ActeurCourant( 0, array( 'administrator' ), true );

check( '4 · ANONYME AVEC ADRESSE VÉRIFIÉE → REFUSÉ', false === $menteur->courriel_verifie() );

// ======================================================================
// 5 · IDENTITÉ
// ======================================================================
$a = new ActeurCourant( 42 );
$b = new ActeurCourant( 42 );
$c = new ActeurCourant( 43 );

check( '5 · deux acteurs de même identifiant sont la même personne', $a->est_le_meme_que( $b ) );
check( '5 · deux identifiants différents, deux personnes', false === $a->est_le_meme_que( $c ) );
check( '5 · DEUX ANONYMES NE SONT PAS LA MÊME PERSONNE',
	false === ActeurCourant::anonyme()->est_le_meme_que( ActeurCourant::anonyme() ) );
check( '5 · un identifié n’est pas un anonyme',
	false === $a->est_le_meme_que( ActeurCourant::anonyme() ) );

// ======================================================================
// 6 · IMMUABILITÉ
// ======================================================================
$roles = $acteur->roles();
$roles[] = 'administrator';

check( '6 · modifier le tableau rendu ne modifie pas l’acteur',
	false === $acteur->a_role( 'administrator' ) );

verdict();
