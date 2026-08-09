<?php
/**
 * Falsification — le serveur croit-il ce que le navigateur lui dit ?
 *
 * Le tunnel de l'accueil transmet ses réponses et son verdict au formulaire.
 * Tout cela passe par `sessionStorage` puis par un champ caché : c'est-à-dire
 * par des données que n'importe qui peut réécrire. Un audit statique disait que
 * le serveur recalcule ; ce banc l'exécute.
 *
 * Cinq scénarios, tous joués contre la vraie validation métier :
 *
 *   A. un verdict `dp` transmis avec une extension de 60 m² ;
 *   B. la nature du projet réécrite après coup ;
 *   C. la surface réécrite après qualification ;
 *   D. un verdict `pcmi` injecté là où les données donnent `dp` ;
 *   E. des données retirées jusqu'à rendre la qualification impossible.
 *
 * Deux attendus opposés, et c'est le cœur du sujet. Une contradiction certaine
 * doit être refusée. Un déterminant que le formulaire sait collecter — surfaces
 * ou couverture — doit aussi être exigé ; une donnée externe comme le zonage
 * peut en revanche rester à confirmer sans bloquer une demande recevable.
 *
 * Usage : php tests/qualification/test-falsification.php
 */

define( 'ABSPATH', true );

if ( ! function_exists( '__' ) ) {
	function __( $texte, $domaine = null ) { // phpcs:ignore
		return $texte;
	}
}

$racine = dirname( __DIR__, 2 );
$src    = $racine . '/wordpress/urbizen-platform/src';

spl_autoload_register(
	static function ( $classe ) use ( $src ) {
		$relatif = str_replace( 'Urbizen\\Platform\\', '', $classe );
		$fichier = $src . '/' . str_replace( '\\', '/', $relatif ) . '.php';
		if ( file_exists( $fichier ) ) {
			require $fichier;
		}
	}
);

use Urbizen\Platform\Forms\QualificationUrbanisme;
use Urbizen\Platform\Forms\ValidationMetierDeclarationPrealable;
use Urbizen\Platform\Forms\ValidationMetierPermisConstruire;

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-74s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

$dp = new ValidationMetierDeclarationPrealable();
$pc = new ValidationMetierPermisConstruire();

/** Joue une charge falsifiée et rend l'erreur de régime, s'il y en a une. */
function regime( $validateur, array $charge ): ?string {
	$erreurs = $validateur->valider( $charge );
	return $erreurs['regime'] ?? null;
}

/** Rend toutes les erreurs pour vérifier aussi le contrat des noms de champs. */
function erreurs( $validateur, array $charge ): array {
	return $validateur->valider( $charge );
}

/* ================================================== A · verdict falsifié == */

// Le navigateur affirme « déclaration préalable » pour une extension de 60 m².
// Le code exige un permis au-delà de 40 m², quelle que soit la zone.
$a = array(
	'nature'                 => 'extension',
	'sp_creee'               => 60,
	'qualification_verdict'  => 'dp',      // ce que le client prétend
	'qualification_contexte' => '{"verdict":{"status":"dp"}}',
);
$erreurs_a = erreurs( $dp, $a );
$erreur_a  = $erreurs_a['regime'] ?? null;

check(
	'A · un verdict « dp » transmis avec 60 m² créés est refusé',
	null !== $erreur_a,
	'la barrière a laissé passer'
);
check(
	'A · le refus nomme le régime réellement applicable',
	null !== $erreur_a && str_contains( (string) $erreur_a, 'permis de construire' ),
	(string) $erreur_a
);
check(
	'A · le refus cite l’article qui le fonde',
	null !== $erreur_a && str_contains( (string) $erreur_a, 'R.421-14' ),
	(string) $erreur_a
);
check( 'A · le vrai champ « nature » est reconnu', ! isset( $erreurs_a['nature'] ) );

/* =============================================== B · nature réécrite ====== */

// La qualification portait sur une extension ; la charge annonce une piscine
// pour se glisser dans le tarif d'une déclaration. Le serveur ne juge que ce
// qu'il reçoit — et une piscine sans bassin ne conclut rien.
$b = regime( $dp, array( 'nature' => 'piscine', 'sp_creee' => 60 ) );
check(
	'B · une piscine sans bassin est refusée comme incomplète',
	null !== $b,
	'la barrière a accepté une nature sans son déterminant principal'
);
check(
	'B · le refus indique la donnée de bassin à compléter',
	null !== $b && str_contains( $b, 'surface du bassin' ),
	(string) $b
);

// Mais réécrire la nature ne met pas non plus à l'abri : une piscine dont le
// bassin dépasse 100 m² relève du permis, déclarée en déclaration préalable.
$b2 = regime( $dp, array( 'nature' => 'piscine', 'surface_bassin_m2' => 120 ) );
check(
	'B · une piscine de 120 m² déclarée en déclaration préalable est refusée',
	null !== $b2,
	'la barrière a laissé passer'
);

/* ============================================== C · surface réécrite ====== */

// Qualifié à 15 m² — donc déclaration préalable — puis 60 m² envoyés au
// serveur. C'est la charge qui décide, pas la qualification passée.
$c = regime( $dp, array( 'nature' => 'extension', 'sp_creee' => 15, 'emprise_creee' => 15 ) );
check( 'C · 15 m² en déclaration préalable : rien à redire', null === $c, (string) $c );

$c2 = regime( $dp, array( 'nature' => 'extension', 'sp_creee' => 60 ) );
check( 'C · la même charge à 60 m² est refusée', null !== $c2 );

/* ============================================ D · verdict pcmi injecté ==== */

// Le navigateur affirme « permis » sur une extension de 15 m². Le serveur
// conclut « déclaration préalable » — et le formulaire de permis, lui, est bien
// contredit.
$d = array(
	'nature'                => 'extension',
	'sp_creee'              => 15,
	'emprise_creee'         => 15,
	'qualification_verdict' => 'pcmi',
);
$erreur_d = regime( $pc, $d );
check(
	'D · un verdict « pcmi » injecté sur 15 m² est contredit par le serveur',
	null !== $erreur_d,
	'la barrière a suivi le verdict du navigateur'
);
check(
	'D · le refus renvoie vers la déclaration préalable',
	null !== $erreur_d && str_contains( (string) $erreur_d, 'déclaration préalable' ),
	(string) $erreur_d
);

/* ========================================= E · données retirées =========== */

// Une surface ou une couverture retirée doit être refusée. Le zonage, qui ne
// figure pas dans le formulaire, peut rester à confirmer sans refus automatique.
$sans = array(
	array( 'nature' => 'extension' ),                                           // aucune surface : refus
	array( 'nature' => 'extension', 'sp_creee' => 30 ),                         // emprise retirée : refus
	array( 'nature' => 'extension', 'sp_creee' => 30, 'emprise_creee' => 30 ), // zone inconnue : confirmation
	array( 'nature' => 'piscine', 'surface_bassin_m2' => 40 ),                  // couverture retirée : refus
	array( 'nature' => 'garage' ),                                              // implantation non collectée ici
	array( 'nature' => '' ),                                                    // erreur de nature, distincte du régime
	array(),                                                                   // charge vide
);
$bloques = array();
foreach ( $sans as $i => $charge ) {
	if ( null !== regime( $dp, $charge ) ) { $bloques[] = '#' . $i; }
}
check(
	'E · surfaces et couverture manquantes sont refusées, pas le zonage inconnu',
	array( '#0', '#1', '#3' ) === $bloques,
	'refus observés : ' . implode( ', ', $bloques )
);

/* ================================= le verdict client n’est jamais consulté = */

// La preuve directe : la même charge, avec tous les verdicts possibles greffés
// dessus, doit produire exactement le même résultat.
$base      = array( 'nature' => 'extension', 'sp_creee' => 60 );
$reference = regime( $dp, $base );
$divergents = array();

foreach ( QualificationUrbanisme::ETATS as $etat ) {
	foreach ( array( 'qualification_verdict', 'verdict', 'statut', 'qualification_contexte' ) as $cle ) {
		$charge         = $base;
		$charge[ $cle ] = 'qualification_contexte' === $cle
			? wp_json_placeholder( $etat )
			: $etat;
		if ( regime( $dp, $charge ) !== $reference ) {
			$divergents[] = $cle . '=' . $etat;
		}
	}
}

check(
	'Aucun verdict transmis par le navigateur ne change la décision du serveur',
	array() === $divergents,
	implode( ' | ', $divergents )
);

/**
 * Fabrique un contexte de qualification tel que le navigateur l'enverrait.
 */
function wp_json_placeholder( string $statut ): string {
	return (string) wp_json_encode_local(
		array(
			'projet'  => 'extension',
			'resume'  => 'contexte falsifié',
			'verdict' => array( 'status' => $statut, 'rule' => 'R.421-17 f)' ),
		)
	);
}

function wp_json_encode_local( array $donnees ): string {
	return json_encode( $donnees, JSON_UNESCAPED_UNICODE );
}

echo "\n";
echo 0 === $fail ? "TOUS LES CONTROLES PASSENT\n" : "$fail CONTROLE(S) EN ECHEC\n";
exit( 0 === $fail ? 0 : 1 );
