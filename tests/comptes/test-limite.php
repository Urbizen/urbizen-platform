<?php
/**
 * Banc : le quota d'émissions.
 *
 * La classe est pure : elle transforme des tableaux. Sa sûreté sous concurrence
 * vient de son appelant, qui la manipule sous verrou — c'est le banc des
 * services qui l'éprouve.
 *
 * Ici, un point tient tout le reste : **une valeur corrompue est traitée comme
 * pleine, jamais comme vide**. Considérer l'illisible comme « aucun envoi »
 * élargirait les droits exactement là où l'on ne comprend plus l'état.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Account\LimiteEnvois;

$t = 1785000000;

/**
 * Raccourci : état décodé depuis des horodatages.
 *
 * @param array<int, int> $h Horodatages.
 * @return array{horodatages: array<int, int>, corrompue: bool}
 */
function etat( array $h ): array {
	return array( 'horodatages' => $h, 'corrompue' => false );
}

// ======================================================================
// 1 · QUOTA
// ======================================================================
check( '1 · aucun envoi : permis', '' === LimiteEnvois::motif_de_refus( etat( array() ), $t ) );
check( '1 · un envoi ancien : permis',
	'' === LimiteEnvois::motif_de_refus( etat( array( $t - 3600 ) ), $t ) );
check( '1 · deux envois : permis',
	'' === LimiteEnvois::motif_de_refus( etat( array( $t - 7200, $t - 3600 ) ), $t ) );
check( '1 · TROIS ENVOIS : REFUSÉ',
	'quota_epuise' === LimiteEnvois::motif_de_refus( etat( array( $t - 10800, $t - 7200, $t - 3600 ) ), $t ) );

// ======================================================================
// 2 · FENÊTRE GLISSANTE
// ======================================================================
$vieux = array( $t - 90000, $t - 7200, $t - 3600 );

check( '2 · un envoi de plus de 24 h sort de la fenêtre',
	array( $t - 7200, $t - 3600 ) === LimiteEnvois::purger( $vieux, $t ) );
check( '2 · LA PREMIÈRE ÉMISSION REDEVIENT DISPONIBLE APRÈS 24 H',
	'' === LimiteEnvois::motif_de_refus( etat( $vieux ), $t ) );
check( '2 · exactement 24 h : encore dans la fenêtre',
	array( $t - 86400 ) !== LimiteEnvois::purger( array( $t - 86400 ), $t ) );

// ======================================================================
// 3 · DÉLAI MINIMAL DE 60 SECONDES
// ======================================================================
check( '3 · un envoi il y a 10 s : REFUSÉ',
	'delai_minimal' === LimiteEnvois::motif_de_refus( etat( array( $t - 10 ) ), $t ) );
check( '3 · un envoi il y a 59 s : refusé',
	'delai_minimal' === LimiteEnvois::motif_de_refus( etat( array( $t - 59 ) ), $t ) );
check( '3 · un envoi il y a 60 s : permis',
	'' === LimiteEnvois::motif_de_refus( etat( array( $t - 60 ) ), $t ) );
check( '3 · le délai porte sur le PLUS RÉCENT',
	'delai_minimal' === LimiteEnvois::motif_de_refus( etat( array( $t - 5000, $t - 5 ) ), $t ) );

// ======================================================================
// 4 · VALEUR CORROMPUE — RESTRICTIVE
// ======================================================================
foreach ( array( 'pas du json', '{"a":1}', '[1,2,3,4]', '["texte"]', '[null]' ) as $brut ) {
	$decode = LimiteEnvois::decoder( $brut );

	check(
		sprintf( '4 · « %s » est jugée corrompue', substr( $brut, 0, 14 ) ),
		true === $decode['corrompue']
	);
}

check( '4 · UNE VALEUR CORROMPUE REFUSE L’ÉMISSION',
	'quota_illisible' === LimiteEnvois::motif_de_refus( LimiteEnvois::decoder( 'pas du json' ), $t ) );
check( '4 · elle n’élargit jamais les droits',
	'' !== LimiteEnvois::motif_de_refus( LimiteEnvois::decoder( '[9,9,9,9,9]' ), $t ) );

// ======================================================================
// 5 · DÉCODAGE ET ENCODAGE
// ======================================================================
check( '5 · absente : aucun envoi, non corrompue',
	array() === LimiteEnvois::decoder( null )['horodatages'] && false === LimiteEnvois::decoder( null )['corrompue'] );
check( '5 · chaîne vide : idem', false === LimiteEnvois::decoder( '' )['corrompue'] );
check( '5 · un aller-retour conserve les valeurs',
	array( 10, 20 ) === LimiteEnvois::decoder( LimiteEnvois::encoder( array( 20, 10 ) ) )['horodatages'] );
check( '5 · l’encodage trie', '[10,20]' === LimiteEnvois::encoder( array( 20, 10 ) ) );
check( '5 · des entiers en chaîne sont acceptés',
	array( 10, 20 ) === LimiteEnvois::decoder( '["10","20"]' )['horodatages'] );

// ======================================================================
// 6 · CONFIRMATION
// ======================================================================
$apres = LimiteEnvois::confirmer( array( $t - 3600 ), $t );

check( '6 · la confirmation ajoute un horodatage', 2 === count( $apres ) );
check( '6 · elle purge au passage', 2 === count( LimiteEnvois::confirmer( array( $t - 90000, $t - 3600 ), $t ) ) );
check( '6 · elle ne dépasse jamais le maximum',
	LimiteEnvois::MAX >= count( LimiteEnvois::confirmer( array( $t - 3, $t - 2, $t - 1 ), $t ) ) );

// ======================================================================
// 7 · TROIS ÉMISSIONS SUR 24 H, LA QUATRIÈME REFUSÉE
// ======================================================================
$h = array();

for ( $i = 0; $i < 3; $i++ ) {
	$quand = $t + ( $i * 120 );

	check(
		sprintf( '7 · émission %d permise', $i + 1 ),
		'' === LimiteEnvois::motif_de_refus( etat( $h ), $quand )
	);

	$h = LimiteEnvois::confirmer( $h, $quand );
}

check( '7 · LA QUATRIÈME EST REFUSÉE',
	'quota_epuise' === LimiteEnvois::motif_de_refus( etat( $h ), $t + 400 ) );
check( '7 · et le reste 23 heures plus tard',
	'quota_epuise' === LimiteEnvois::motif_de_refus( etat( $h ), $t + 82800 ) );
check( '7 · MAIS PLUS APRÈS 24 H',
	'' === LimiteEnvois::motif_de_refus( etat( $h ), $t + 86500 ) );

// ======================================================================
// 8 · SOURCE DE VÉRITÉ — `{a, e}`
// ======================================================================
$src = LimiteEnvois::decoder_source( null );
check( '8 · source absente : absente, NON corrompue',
	true === $src['absente'] && false === $src['corrompue'] );

$src = LimiteEnvois::decoder_source( '[]' );
check( '8 · source vide : ni absente ni corrompue',
	false === $src['absente'] && false === $src['corrompue'] && array() === $src['entrees'] );

$src = LimiteEnvois::decoder_source( '[{"a":' . $t . ',"e":"01J8Z"}]' );
check( '8 · une entrée bien formée', 1 === count( $src['entrees'] ) && '01J8Z' === $src['entrees'][0]['e'] );

check( '8 · objet JSON refusé', true === LimiteEnvois::decoder_source( '{"a":1}' )['corrompue'] );
check( '8 · entrée sans « e » refusée', true === LimiteEnvois::decoder_source( '[{"a":1}]' )['corrompue'] );
check( '8 · entrée sans « a » refusée', true === LimiteEnvois::decoder_source( '[{"e":"x"}]' )['corrompue'] );
check( '8 · « e » non chaîne refusé', true === LimiteEnvois::decoder_source( '[{"a":1,"e":5}]' )['corrompue'] );
check( '8 · « a » non entier refusé', true === LimiteEnvois::decoder_source( '[{"a":"x","e":"y"}]' )['corrompue'] );
check( '8 · charabia refusé', true === LimiteEnvois::decoder_source( 'nawak' )['corrompue'] );

$quatre = '[{"a":1,"e":"a"},{"a":2,"e":"b"},{"a":3,"e":"c"},{"a":4,"e":"d"}]';
check( '8 · PLUS DE MAX ENTRÉES : CORROMPU, jamais tronqué',
	true === LimiteEnvois::decoder_source( $quatre )['corrompue'] );
check( '8 · et un quota corrompu est traité comme PLEIN',
	'quota_illisible' === LimiteEnvois::motif_de_refus(
		LimiteEnvois::etat_depuis_source( LimiteEnvois::decoder_source( $quatre ) ), $t ) );

// ======================================================================
// 9 · AMORÇAGE DEPUIS LE MIROIR — borne sans autoriser
// ======================================================================
$amorce = LimiteEnvois::amorcer_depuis_miroir( array( $t - 100, $t - 200 ) );
check( '9 · chaque horodatage hérité devient {a, e:""}',
	2 === count( $amorce ) && '' === $amorce[0]['e'] && '' === $amorce[1]['e'] );
check( '9 · un identifiant vide NE CORRESPOND JAMAIS',
	false === LimiteEnvois::contient_emission( $amorce, '' ) );
check( '9 · ni à un identifiant réel',
	false === LimiteEnvois::contient_emission( $amorce, '01J8Z' ) );
check( '9 · mais les créneaux hérités BORNENT bien le quota',
	2 === count( LimiteEnvois::horodatages_de( $amorce ) ) );

// ======================================================================
// 10 · RECONNAISSANCE ET AJOUT
// ======================================================================
$e = LimiteEnvois::ajouter_emission( array(), $t, '01J8Z' );
check( '10 · ajout : un créneau nommé', 1 === count( $e ) && '01J8Z' === $e[0]['e'] );
check( '10 · reconnu au rejeu', true === LimiteEnvois::contient_emission( $e, '01J8Z' ) );
check( '10 · un autre identifiant ne l\'est pas',
	false === LimiteEnvois::contient_emission( $e, '01J9A' ) );

$e = LimiteEnvois::ajouter_emission( $e, $t + 100, '01J9A' );
$e = LimiteEnvois::ajouter_emission( $e, $t + 200, '01J9B' );
check( '10 · trois créneaux : quota épuisé',
	'quota_epuise' === LimiteEnvois::motif_de_refus(
		LimiteEnvois::etat_depuis_source(
			array( 'entrees' => $e, 'corrompue' => false, 'absente' => false ) ), $t + 300 ) );
check( '10 · le miroir dérive exactement de la source',
	array( $t, $t + 100, $t + 200 ) === LimiteEnvois::horodatages_de( $e ) );

$vieux = LimiteEnvois::ajouter_emission(
	array( array( 'a' => $t - 90000, 'e' => 'vieux' ) ), $t, '01J8Z' );
check( '10 · l\'ajout purge ce qui est sorti de la fenêtre',
	1 === count( $vieux ) && '01J8Z' === $vieux[0]['e'] );

// ======================================================================
// 11 · ALLER-RETOUR
// ======================================================================
$aller  = LimiteEnvois::ajouter_emission( array(), $t, '01J8Z' );
$retour = LimiteEnvois::decoder_source( LimiteEnvois::encoder_source( $aller ) );
check( '11 · encoder puis décoder rend la même source',
	false === $retour['corrompue'] && $aller === $retour['entrees'] );

// ======================================================================
// 12 · quota-verify — CONSTATE, ne purge jamais
// ======================================================================
$cli = (string) file_get_contents(
	dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Adapter/WpCliAccountsCommand.php'
);

$cli_sans_commentaires = implode(
	'',
	array_map(
		static fn( $t ) => is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true )
			? ' '
			: ( is_array( $t ) ? $t[1] : $t ),
		token_get_all( $cli )
	)
);

check( '12 · la sous-commande existe',
	false !== strpos( $cli_sans_commentaires, 'function quota_verify' ) );
check( '12 · AUCUNE PURGE : le mot n\'apparaît dans aucun nom de méthode',
	1 !== preg_match( '/function\s+\w*purge/i', $cli_sans_commentaires ) );
check( '12 · elle N\'ÉCRIT JAMAIS LA SOURCE',
	false === strpos( $cli_sans_commentaires, 'META_SOURCE, LimiteEnvois::encoder_source' )
	&& 1 !== preg_match( '/update_user_meta\s*\([^)]*META_SOURCE/', $cli_sans_commentaires ) );
check( '12 · elle ne supprime aucune métadonnée',
	false === strpos( $cli_sans_commentaires, 'delete_user_meta' ) );
check( '12 · le miroir seul est réécrit',
	1 === preg_match( '/update_user_meta\s*\([^)]*LimiteEnvois::META\s*,/', $cli_sans_commentaires ) );
check( '12 · une seule écriture dans toute la commande',
	1 === substr_count( $cli_sans_commentaires, 'update_user_meta' ) );
check( '12 · lecture seule par défaut : l\'écriture est sous condition',
	false !== strpos( $cli_sans_commentaires, 'repair-mirror' ) );
check( '12 · une source illisible n\'est JAMAIS réparée',
	false !== strpos( $cli, 'aucune réparation possible' ) );
check( '12 · code de sortie non nul en cas de divergence',
	false !== strpos( $cli_sans_commentaires, 'divergence constatée' ) );

/*
 * La réparation ne peut pas élargir un droit : le miroir écrit dérive de la
 * source, donc il porte exactement les mêmes créneaux. On le vérifie sur la
 * transformation elle-même, pour toutes les tailles de fenêtre.
 */
$elargit = false;

for ( $n = 0; $n <= LimiteEnvois::MAX; $n++ ) {
	$entrees = array();

	for ( $k = 0; $k < $n; $k++ ) {
		$entrees[] = array( 'a' => $t - ( $k * 10 ), 'e' => 'e' . $k );
	}

	if ( count( LimiteEnvois::horodatages_de( $entrees ) ) !== $n ) {
		$elargit = true;
	}
}

check( '12 · LE MIROIR DÉRIVÉ PORTE EXACTEMENT LES CRÉNEAUX DE SA SOURCE', ! $elargit );

verdict();
