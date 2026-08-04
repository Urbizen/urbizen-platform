<?php
/**
 * Réponse JSON des soumissions, et négociation de contenu.
 *
 * Le risque que ces bancs couvrent n'est pas le format : c'est qu'un en-tête
 * finisse par décider autre chose que la forme de la réponse. Un `Accept` se
 * forge ; s'il ouvrait un chemin plus court dans le traitement, il deviendrait
 * une porte. Les contrôles vérifient donc que le pipeline est le même dans les
 * deux modes, que la négociation intervient **après** lui, et qu'aucune donnée
 * technique ne sort dans la structure rendue.
 *
 * Usage : php tests/submissions/test-reponse-json.php
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Http\AcceptNegotiation;
use Urbizen\Platform\Http\SubmissionFeedback;
use Urbizen\Platform\Http\SubmissionJsonResponse;

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $label     Intitulé.
 * @param bool   $condition Verdict.
 * @return void
 */
function check_json( $label, $condition ) {
	global $echecs;

	if ( ! $condition ) {
		$echecs++;
	}

	printf( "%-76s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

/* ================================================================== *
 *  1. Négociation
 * ================================================================== */

echo "\n── 1. Lecture de l'en-tête Accept\n";

$cas = array(
	'application/json'                                                => true,
	'application/json, text/plain, */*'                               => true,
	'text/plain, application/json;q=0.9'                              => true,
	'APPLICATION/JSON'                                                => true,
	'  application/json  '                                            => true,
	'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' => false,
	'*/*'                                                             => false,
	'text/html'                                                       => false,
	''                                                                => false,
	'application/jsonp'                                               => false,
);

foreach ( $cas as $entete => $attendu ) {
	check_json(
		sprintf( '« %s » → %s', '' === $entete ? '(vide)' : $entete, $attendu ? 'JSON' : 'redirection' ),
		$attendu === AcceptNegotiation::veut_json( array( 'HTTP_ACCEPT' => $entete ) )
	);
}

check_json( 'un Accept absent vaut redirection', ! AcceptNegotiation::veut_json( array() ) );

/* ================================================================== *
 *  2. La négociation ne décide que de la forme
 * ================================================================== */

echo "\n── 2. La négociation n'ouvre aucun raccourci\n";

$source = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Http/SubmissionController.php' );

$pos_process   = strpos( $source, 'self::process(' );
$pos_negociation = strpos( $source, 'AcceptNegotiation::veut_json(' );

check_json( 'le pipeline est appelé dans handle()', false !== $pos_process );
check_json( 'la négociation intervient APRÈS le traitement', false !== $pos_negociation && $pos_negociation > $pos_process );
check_json(
	'aucun contrôle n’est conditionné à l’en-tête',
	1 === substr_count( $source, 'AcceptNegotiation::veut_json(' )
);
check_json(
	'process() ne connaît pas l’en-tête Accept',
	! str_contains( substr( $source, (int) strpos( $source, 'private static function evaluate' ) ), 'HTTP_ACCEPT' )
);
check_json( 'le chemin historique de redirection subsiste', str_contains( $source, 'wp_safe_redirect(' ) );

/* ================================================================== *
 *  3. Codes HTTP
 * ================================================================== */

echo "\n── 3. Codes HTTP cohérents\n";

$codes = array(
	'validation'   => 422,
	'rate_limited' => 429,
	'unavailable'  => 503,
	'technical'    => 500,
);

foreach ( $codes as $categorie => $attendu ) {
	check_json( sprintf( '« %s » → %d', $categorie, $attendu ), $attendu === SubmissionJsonResponse::statut_http( $categorie ) );
}

check_json( 'une catégorie inconnue retombe sur 500', 500 === SubmissionJsonResponse::statut_http( 'inventee' ) );

/* ================================================================== *
 *  4. Réponse d'échec
 * ================================================================== */

echo "\n── 4. Un échec reste un échec\n";

$echec = SubmissionJsonResponse::echec( 'validation', array( 'email' => 'email_invalide', 'nature' => 'projet_inconnu' ) );

check_json( 'succès explicitement faux', false === $echec['success'] );
check_json( 'le code est une catégorie publique', in_array( $echec['code'], SubmissionFeedback::CATEGORIES, true ) );
check_json( 'le message est destiné à une personne', str_contains( $echec['message'], 'Vérifiez les champs signalés' ) );
check_json( 'les champs concernés sont nommés', array( 'email', 'nature' ) === $echec['fields'] );
check_json(
	'aucun code interne de contrôle ne ressort',
	! str_contains( wp_json_encode( $echec ), 'email_invalide' ) && ! str_contains( wp_json_encode( $echec ), 'projet_inconnu' )
);

$technique = SubmissionJsonResponse::echec( 'technical' );

check_json( 'un incident ne nomme aucun champ', ! isset( $technique['fields'] ) );
check_json( 'une catégorie hors liste blanche devient « technical »', 'technical' === SubmissionJsonResponse::echec( 'inventee' )['code'] );

$serialise = (string) wp_json_encode( $technique );

foreach ( array( 'Urbizen\\', '.php', '/src/', 'wp_', 'Fatal', 'SELECT', '_urbizen_' ) as $interdit ) {
	check_json( sprintf( 'aucune trace de « %s » dans la réponse', $interdit ), ! str_contains( $serialise, $interdit ) );
}

/* ================================================================== *
 *  5. Réponse de succès
 * ================================================================== */

echo "\n── 5. Le succès ne porte que l'utile\n";

// La réponse est composée à partir de la demande PERSISTÉE : c'est ce qui
// garantit qu'un total injecté dans la requête n'a aucun chemin jusqu'au client.
$GLOBALS['wpd_meta'] = array();

$id  = 4242;
$ref = 'URB-2026-0042';

$GLOBALS['wpd_posts'][ $id ] = (object) array(
	'ID'          => $id,
	'post_type'   => 'urbizen_demande',
	'post_title'  => $ref,
	'post_status' => 'private',
);

$GLOBALS['wpd_meta'][ $id ] = array(
	'_urbizen_reference'    => $ref,
	'_urbizen_form_type'    => 'declaration_prealable',
	'_urbizen_status'       => 'received',
	'_urbizen_payload'      => wp_json_encode(
		array(
			'nature'                             => 'extension',
			'projets_supplementaires'            => array( 'piscine', 'toiture' ),
			'description_projet_piscine'         => 'Bassin 4 × 8 m',
			'pieces_differees'                   => array( 'facades' ),
			'informations_cadastrales_differees' => 'oui',
			// Ce qu'un client aurait pu injecter : sans effet, la réponse ne lit
			// que le tarif persisté.
			'total'                              => 1,
		)
	),
	'_urbizen_pricing'      => wp_json_encode(
		array(
			'base'    => 549,
			'options' => array(
				array( 'id' => 'projet_supplementaire:piscine', 'price' => 100 ),
				array( 'id' => 'projet_supplementaire:toiture', 'price' => 100 ),
				array( 'id' => 'secteur_abf', 'price' => 80 ),
			),
			'total'   => 829,
		)
	),
	'_urbizen_files'        => wp_json_encode( array() ),
	'_urbizen_transaction'  => wp_json_encode( array( 'id' => 'secret-interne', 'staging' => '/var/private/x' ) ),
);

$resultat = Urbizen\Platform\Http\SubmissionResult::success( $ref, $id );
$reponse  = SubmissionJsonResponse::succes( $resultat );

check_json( 'succès explicitement vrai', true === $reponse['success'] );
check_json( 'la référence réelle est rendue', $ref === $reponse['reference'] );
check_json( 'le statut de la demande est rendu', 'received' === $reponse['status'] );
check_json( 'le total vient du tarif persisté', 829 === $reponse['pricing']['total'] );
check_json( 'le socle aussi', 549 === $reponse['pricing']['base'] );
check_json( 'le tarif est marqué comme estimé', 'estime' === $reponse['pricing']['status'] );
check_json( 'le total injecté dans la charge est ignoré', 1 !== $reponse['pricing']['total'] );

check_json( 'le projet principal porte son libellé client', 'Extension' === $reponse['project']['label'] );
check_json( 'les projets supplémentaires sont nommés',
	'Piscine' === $reponse['additional_projects'][0]['label'] && 'Toiture' === $reponse['additional_projects'][1]['label'] );
check_json( 'la description accompagne son projet', 'Bassin 4 × 8 m' === $reponse['additional_projects'][0]['description'] );
check_json( 'un projet sans description n’en invente pas', ! isset( $reponse['additional_projects'][1]['description'] ) );
check_json( 'les pièces différées sont nommées', 'Photos des façades concernées' === $reponse['deferred_documents'][0]['label'] );
check_json( 'le report cadastral est rendu', true === $reponse['deferred_cadastral_information'] );

$lignes = array_column( $reponse['pricing']['options'], 'label' );

check_json( 'les lignes de tarif portent des libellés lisibles',
	in_array( 'Secteur Bâtiments de France', $lignes, true ) && in_array( 'Piscine', $lignes, true ) );
check_json( 'aucun identifiant technique dans les libellés',
	! in_array( 'projet_supplementaire:piscine', $lignes, true ) );

$json = (string) wp_json_encode( $reponse );

foreach ( array( 'secret-interne', '/var/private', '_urbizen_', '"id":4242', 'transaction', 'payload' ) as $interdit ) {
	check_json( sprintf( 'la réponse ne contient pas « %s »', $interdit ), ! str_contains( $json, $interdit ) );
}

check_json( 'aucun identifiant WordPress interne n’est exposé', ! str_contains( $json, (string) $id ) );

/* ================================================================== *
 *  6. Le cas « sur étude » se distingue d'un total absent
 * ================================================================== */

echo "\n── 6. Un total non chiffré n'est pas un total manquant\n";

$GLOBALS['wpd_meta'][ $id ]['_urbizen_pricing'] = wp_json_encode(
	array(
		'base'    => 0,
		'options' => array( array( 'id' => 'secteur_abf', 'price' => 80 ) ),
		'total'   => null,
	)
);

$sur_etude = SubmissionJsonResponse::succes( $resultat );

check_json( 'la clé « total » reste présente', array_key_exists( 'total', $sur_etude['pricing'] ) );
check_json( 'sa valeur est nulle', null === $sur_etude['pricing']['total'] );
check_json( 'un indicateur distinct le signale', 'sur_etude' === $sur_etude['pricing']['status'] );
check_json( 'aucun faux montant ne comble le vide', 0 !== $sur_etude['pricing']['total'] );
check_json( 'les suppléments restent détaillés', 1 === count( $sur_etude['pricing']['options'] ) );

echo "\n";

if ( $echecs > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $echecs );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
