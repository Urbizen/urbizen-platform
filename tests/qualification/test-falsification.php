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
 * Deux attendus opposés, et c'est le cœur du sujet. Une contradiction CERTAINE
 * doit être refusée. Une donnée manquante ne doit RIEN refuser : le moteur rend
 * alors « à confirmer », et une barrière qui bloquerait là renverrait des
 * dossiers parfaitement valides au motif qu'on ignore un zonage.
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

/* ================================================== A · verdict falsifié == */

// Le navigateur affirme « déclaration préalable » pour une extension de 60 m².
// Le code exige un permis au-delà de 40 m², quelle que soit la zone.
$a = array(
	'nature_projet'          => 'extension',
	'sp_creee'               => 60,
	'qualification_verdict'  => 'dp',      // ce que le client prétend
	'qualification_contexte' => '{"verdict":{"status":"dp"}}',
);
$erreur_a = regime( $dp, $a );

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

/* =============================================== B · nature réécrite ====== */

// La qualification portait sur une extension ; la charge annonce une piscine
// pour se glisser dans le tarif d'une déclaration. Le serveur ne juge que ce
// qu'il reçoit — et une piscine sans bassin ne conclut rien.
$b = regime( $dp, array( 'nature_projet' => 'piscine', 'sp_creee' => 60 ) );
check(
	'B · une nature réécrite ne fait pas juger l’ancienne',
	null === $b,
	'la barrière a jugé sur une nature qui n’est plus déclarée'
);

// Mais réécrire la nature ne met pas non plus à l'abri : une piscine dont le
// bassin dépasse 100 m² relève du permis, déclarée en déclaration préalable.
$b2 = regime( $dp, array( 'nature_projet' => 'piscine', 'bassin_surface' => 120 ) );
check(
	'B · une piscine de 120 m² déclarée en déclaration préalable est refusée',
	null !== $b2,
	'la barrière a laissé passer'
);

/* ============================================== C · surface réécrite ====== */

// Qualifié à 15 m² — donc déclaration préalable — puis 60 m² envoyés au
// serveur. C'est la charge qui décide, pas la qualification passée.
$c = regime( $dp, array( 'nature_projet' => 'extension', 'sp_creee' => 15 ) );
check( 'C · 15 m² en déclaration préalable : rien à redire', null === $c, (string) $c );

$c2 = regime( $dp, array( 'nature_projet' => 'extension', 'sp_creee' => 60 ) );
check( 'C · la même charge à 60 m² est refusée', null !== $c2 );

/* ============================================ D · verdict pcmi injecté ==== */

// Le navigateur affirme « permis » sur une extension de 15 m². Le serveur
// conclut « déclaration préalable » — et le formulaire de permis, lui, est bien
// contredit.
$d = array(
	'nature_projet'         => 'extension',
	'sp_creee'              => 15,
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

// Une charge amputée ne doit RIEN déclencher : le moteur rend « à confirmer »,
// et refuser là renverrait des dossiers valides.
$sans = array(
	array( 'nature_projet' => 'extension' ),                                  // aucune surface
	array( 'nature_projet' => 'extension', 'sp_creee' => 30 ),                 // zone décisive, inconnue
	array( 'nature_projet' => 'extension', 'sp_creee' => 30, 'sp_totale' => 200 ), // zone toujours inconnue
	array( 'nature_projet' => 'garage' ),                                      // implantation inconnue
	array( 'nature_projet' => '' ),                                            // aucune nature
	array(),                                                                   // charge vide
);
$bloques = array();
foreach ( $sans as $i => $charge ) {
	if ( null !== regime( $dp, $charge ) ) { $bloques[] = '#' . $i; }
}
check(
	'E · une charge incomplète n’est jamais refusée : douter n’est pas refuser',
	array() === $bloques,
	'refusées à tort : ' . implode( ', ', $bloques )
);

/* ================================= le verdict client n’est jamais consulté = */

// La preuve directe : la même charge, avec tous les verdicts possibles greffés
// dessus, doit produire exactement le même résultat.
$base      = array( 'nature_projet' => 'extension', 'sp_creee' => 60 );
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
