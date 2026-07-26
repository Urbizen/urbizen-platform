<?php
/**
 * Banc : la mesure du texte en points de code, sans `mbstring`.
 *
 * Une seule règle de mesure dans tout le dépôt — {@see Texte} — pour que le
 * domaine (l'adresse) et les services (le mot de passe) ne divergent jamais.
 * On éprouve ici qu'elle compte des CARACTÈRES et non des octets, qu'elle
 * refuse l'UTF-8 mal formé, et qu'elle **n'appelle aucune fonction `mb_*`** :
 * l'extension n'est pas garantie partout, et un appel direct serait fatal.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Domain\Support\Texte;

// ======================================================================
// 1 · VALIDITÉ UTF-8
// ======================================================================
check( '1 · l’ASCII est de l’UTF-8 valide', Texte::est_utf8( 'abc' ) );
check( '1 · les accents aussi', Texte::est_utf8( 'garçon' ) );
check( '1 · les emojis aussi', Texte::est_utf8( '🎈' ) );
check( '1 · la chaîne vide est valide', Texte::est_utf8( '' ) );
check( '1 · une séquence d’octets mal formée est refusée', false === Texte::est_utf8( "clef\xC3\x28" ) );
check( '1 · un octet de continuation isolé est refusé', false === Texte::est_utf8( "\x80" ) );

// ======================================================================
// 2 · LONGUEUR EN POINTS DE CODE, PAS EN OCTETS
// ======================================================================
check( '2 · « abc » vaut 3', 3 === Texte::longueur( 'abc' ) );
check( '2 · la chaîne vide vaut 0', 0 === Texte::longueur( '' ) );
// « garçon » : 6 caractères, 7 octets (le ç en occupe 2).
check( '2 · « garçon » vaut 6 caractères (et non 7 octets)', 6 === Texte::longueur( 'garçon' ) );
check( '2 · un emoji vaut 1 caractère (et non 4 octets)', 1 === Texte::longueur( '🎈' ) );
check( '2 · douze emojis valent 12', 12 === Texte::longueur( str_repeat( '🎈', 12 ) ) );
check( '2 · un UTF-8 invalide rend le sentinel -1', -1 === Texte::longueur( "clef\xC3\x28" ) );

// ======================================================================
// 3 · SEUILS — au_moins / au_plus
// ======================================================================
check( '3 · 12 caractères ASCII atteignent le minimum de 12', Texte::au_moins( 'MotDePasse12', 12 ) );
check( '3 · 11 caractères ne l’atteignent pas', false === Texte::au_moins( 'abcdefghijk', 12 ) );
// « garçon12345 » : 11 caractères, 12 octets. En octets, `strlen` dirait 12 —
// à tort. La mesure en caractères le refuse.
check( '3 · « garçon12345 » (11 car., 12 octets) N’ATTEINT PAS 12', false === Texte::au_moins( 'garçon12345', 12 ) );
check( '3 · « garçon123456 » (12 car.) atteint 12', Texte::au_moins( 'garçon123456', 12 ) );
check( '3 · douze emojis atteignent 12', Texte::au_moins( str_repeat( '🎈', 12 ), 12 ) );
check( '3 · un UTF-8 invalide n’atteint jamais le minimum', false === Texte::au_moins( "abc\xC3\x28defghij", 12 ) );
check( '3 · la chaîne vide n’atteint pas 12', false === Texte::au_moins( '', 12 ) );

check( '3 · 100 caractères tiennent sous la borne de 100', Texte::au_plus( str_repeat( 'a', 100 ), 100 ) );
check( '3 · 101 caractères la dépassent', false === Texte::au_plus( str_repeat( 'a', 101 ), 100 ) );
check( '3 · un UTF-8 invalide ne tient sous aucune borne', false === Texte::au_plus( "clef\xC3\x28", 100 ) );

// ======================================================================
// 4 · AUCUNE DÉPENDANCE À MBSTRING
// ======================================================================
// La garantie centrale : la source ne fait AUCUN appel `mb_*`. On lit le code
// débarrassé de ses commentaires (le préambule cite les fonctions remplacées).
$source = (string) file_get_contents( URBIZEN_SRC . 'Domain/Support/Texte.php' );
$code   = (string) preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#' ), '', $source );

check( '4 · Texte n’appelle jamais mb_strlen', false === strpos( $code, 'mb_strlen' ) );
check( '4 · Texte n’appelle jamais mb_check_encoding', false === strpos( $code, 'mb_check_encoding' ) );
check( '4 · Texte n’emploie aucune fonction mb_', false === strpos( $code, 'mb_' ) );

verdict();
