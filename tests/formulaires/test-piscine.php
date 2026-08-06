<?php
/**
 * Tranche verticale « piscine » : de la saisie française à l'accusé client.
 *
 * Ce banc suit une mesure sur tout son chemin. Le point qu'il défend est
 * simple : **ce que la personne a écrit doit être ce qui arrive dans le
 * dossier**. Deux façons de le trahir ont déjà existé ici — un champ qui refuse
 * la virgule, et un transtypage PHP qui transforme « 8,5 » en 8 sans rien dire.
 *
 * Usage : php tests/formulaires/test-piscine.php
 */

define( 'ABSPATH', true );

if ( ! function_exists( '__' ) ) {
	/**
	 * Doublure de traduction.
	 *
	 * @param string $texte   Texte.
	 * @param string $domaine Domaine.
	 * @return string
	 */
	function __( $texte, $domaine = null ) { // phpcs:ignore
		return $texte;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Doublure de filtre.
	 *
	 * @param string $crochet Nom.
	 * @param mixed  $valeur  Valeur.
	 * @return mixed
	 */
	function apply_filters( $crochet, $valeur ) { // phpcs:ignore
		return $valeur;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Doublure d'échappement.
	 *
	 * @param string $texte Texte.
	 * @return string
	 */
	function esc_html( $texte ) { // phpcs:ignore
		return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * Doublure de validation d'adresse.
	 *
	 * @param string $adresse Adresse.
	 * @return bool
	 */
	function is_email( $adresse ) { // phpcs:ignore
		return (bool) filter_var( $adresse, FILTER_VALIDATE_EMAIL );
	}
}

$racine = dirname( __DIR__, 2 );
$src    = $racine . '/wordpress/urbizen-platform/src';

// Le registre charge les définitions depuis le greffon. Le banc n'en démarre
// pas, mais il rend le chemin disponible : sans lui, le rendu du courriel
// n'aurait pas de libellés et le contrôle du doublon serait sans objet.
if ( ! defined( 'URBIZEN_PLATFORM_DIR' ) ) {
	define( 'URBIZEN_PLATFORM_DIR', $racine . '/wordpress/urbizen-platform/' );
}

spl_autoload_register(
	static function ( $classe ) use ( $src ) {
		$fichier = $src . '/' . str_replace( '\\', '/', str_replace( 'Urbizen\\Platform\\', '', $classe ) ) . '.php';

		if ( file_exists( $fichier ) ) {
			require $fichier;
		}
	}
);

use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\MatriceChamps;
use Urbizen\Platform\Forms\PrecisionsProjet;
use Urbizen\Platform\Forms\Validator;

$echecs = 0;

/**
 * Consigne un contrôle.
 *
 * @param string $label     Intitulé.
 * @param bool   $condition Verdict.
 * @return void
 */
function verifier( $label, $condition ) {
	global $echecs;

	if ( ! $condition ) {
		$echecs++;
	}

	printf( "%-76s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

$brut = require $src . '/Forms/definitions/declaration_prealable.php';
$def  = new FormDefinition( $brut['type'], $brut['title'], $brut['submit_label'], $brut['fields'], $brut['steps'] );

/**
 * Soumission de piscine, valide par défaut.
 *
 * @param array<string, mixed> $extra Champs remplacés ou ajoutés.
 * @return array<string, mixed>
 */
function piscine( array $extra = array() ): array {
	return array_merge(
		array(
			'declarant_type' => 'particulier',
			'nom'            => 'Fictif',
			'prenom'         => 'Camille',
			'qualite'        => 'proprietaire',
			'email'          => 'camille@exemple.test',
			'telephone'      => '0600000000',
			'adresse_declarant' => '1 rue Imaginaire',
			'cp_declarant'   => '33000',
			'ville_declarant' => 'Bordeaux',
			'terrain_adresse' => '1 rue Imaginaire',
			'terrain_cp'     => '33000',
			'terrain_ville'  => 'Bordeaux',
			'nature'         => 'piscine',
			'intervention'   => 'existant',
			'description'    => 'Bassin enterré.',
			'abf'            => 'non',
			'demolition'     => 'non',
			'attest_exact'   => '1',
			'attest_rgpd'    => '1',
		),
		$extra
	);
}

/**
 * Traite une soumission comme le contrôleur : validation puis matrice.
 *
 * @param FormDefinition       $def  Définition.
 * @param array<string, mixed> $post Soumission.
 * @return array{clean:array<string,mixed>,errors:array<string,string>,ecarts:array<int,string>}
 */
function traiter( FormDefinition $def, array $post ): array {
	$v      = Validator::validate( $def, $post );
	$ecarts = array();
	$clean  = MatriceChamps::filtrer( 'declaration_prealable', $v['clean'], $ecarts );

	return array(
		'clean'  => $clean,
		'errors' => $v['errors'],
		'ecarts' => $ecarts,
	);
}

/* ================================================================== *
 *  1. La définition connaît les six champs
 * ================================================================== */

echo "\n── 1. Définition\n";

$attendus = array(
	'longueur_bassin_m',
	'largeur_bassin_m',
	'surface_bassin_m2',
	'profondeur_bassin_m',
	'presence_abri_piscine',
	'hauteur_abri_m',
);

foreach ( $attendus as $champ ) {
	verifier( sprintf( '« %s » est déclaré', $champ ), null !== $def->field( $champ ) );
}

verifier( 'la définition est sans anomalie', array() === $def->errors() );

$requis = array_column( array_filter( $def->fields(), static fn( $f ) => ! empty( $f['required'] ) ), 'name' );

foreach ( $attendus as $champ ) {
	verifier( sprintf( '« %s » reste facultatif', $champ ), ! in_array( $champ, $requis, true ) );
}

// L'ancien identifiant ne doit plus exister nulle part.
verifier( 'l’ancien « piscine_m2 » a disparu de la définition', null === $def->field( 'piscine_m2' ) );
verifier( 'et de la matrice', ! in_array( 'piscine_m2', MatriceChamps::CONDITIONNELS, true ) );

$abri = $def->field( 'presence_abri_piscine' );

verifier( 'l’abri est une liste fermée de trois valeurs',
	array( 'oui', 'non', 'inconnu' ) === array_column( $abri['options'] ?? array(), 'value' ) );

/* ================================================================== *
 *  2. Les écritures françaises traversent
 * ================================================================== */

echo "\n── 2. Saisie française\n";

$r = traiter(
	$def,
	piscine(
		array(
			'longueur_bassin_m'     => '8,5',
			'largeur_bassin_m'      => '4',
			'surface_bassin_m2'     => '34,00',
			'profondeur_bassin_m'   => ' 1,5 ',
			'presence_abri_piscine' => 'oui',
			'hauteur_abri_m'        => '1,8',
		)
	)
);

verifier( 'aucune erreur de validation', array() === $r['errors'] );
verifier( '« 8,5 » est persisté « 8.5 »', '8.5' === $r['clean']['longueur_bassin_m'] );
verifier( '« 34,00 » perd ses zéros inutiles', '34' === $r['clean']['surface_bassin_m2'] );
verifier( 'les espaces sont retirés', '1.5' === $r['clean']['profondeur_bassin_m'] );
verifier( 'l’entier reste entier', '4' === $r['clean']['largeur_bassin_m'] );
verifier( 'l’abri est conservé', 'oui' === $r['clean']['presence_abri_piscine'] );
verifier( 'la hauteur aussi', '1.8' === $r['clean']['hauteur_abri_m'] );

// Le piège que tout ceci ferme.
verifier( 'un transtypage PHP aurait donné 8', 8 === (int) '8,5' );
verifier( 'le chemin réel donne 8.5', 8.5 === (float) $r['clean']['longueur_bassin_m'] );

/* ================================================================== *
 *  3. Vide, zéro, et refus
 * ================================================================== */

echo "\n── 3. Vide, zéro, refus\n";

$vide = traiter( $def, piscine( array( 'longueur_bassin_m' => '', 'profondeur_bassin_m' => '' ) ) );

verifier( 'un champ vide ne produit aucune erreur', array() === $vide['errors'] );
verifier( 'un champ vide n’est pas persisté', ! array_key_exists( 'longueur_bassin_m', $vide['clean'] ) );
verifier( 'et ne devient jamais zéro', ! isset( $vide['clean']['profondeur_bassin_m'] ) );

$zero = traiter( $def, piscine( array( 'longueur_bassin_m' => '0' ) ) );

verifier( 'une mesure renseignée à zéro est refusée', isset( $zero['errors']['longueur_bassin_m'] ) );
verifier( 'avec un message qui dit quoi faire', 'mesure_nulle' === $zero['errors']['longueur_bassin_m'] );

foreach ( array( '-3' => 'négatif', '8,5,2' => 'deux virgules', '8,5.2' => 'virgule et point', '1e3' => 'notation scientifique', 'abc' => 'texte', '8.' => 'point final', '200' => 'au-delà de la borne' ) as $valeur => $quoi ) {
	$k = traiter( $def, piscine( array( 'longueur_bassin_m' => $valeur ) ) );

	verifier( sprintf( 'refusé : %s (« %s »)', $quoi, $valeur ), isset( $k['errors']['longueur_bassin_m'] ) );
}

verifier( 'la borne haute est atteignable', array() === traiter( $def, piscine( array( 'longueur_bassin_m' => '100' ) ) )['errors'] );

/* ================================================================== *
 *  4. La hauteur d'abri suit son pilote
 * ================================================================== */

echo "\n── 4. Abri\n";

foreach ( array( 'non', 'inconnu' ) as $sans ) {
	$k = traiter( $def, piscine( array( 'presence_abri_piscine' => $sans, 'hauteur_abri_m' => '1,8' ) ) );

	verifier( sprintf( 'abri « %s » : la hauteur est écartée', $sans ), ! array_key_exists( 'hauteur_abri_m', $k['clean'] ) );
}

// Deux gardes se superposent, et c'est voulu : `visible_if` neutralise le champ
// dès la validation, la matrice l'écarte ensuite. La première suffit au parcours
// normal ; la seconde protège d'une charge qui ne viendrait pas du formulaire.
$e_direct = array();
MatriceChamps::filtrer( 'declaration_prealable', array( 'nature' => 'piscine', 'presence_abri_piscine' => 'non', 'hauteur_abri_m' => '1.8' ), $e_direct );

verifier( 'la matrice écarte aussi une hauteur forgée hors validation', in_array( 'hauteur_abri_m', $e_direct, true ) );

$forge = traiter( $def, piscine( array( 'hauteur_abri_m' => '1,8' ) ) );

verifier( 'une hauteur forgée sans abri est écartée', ! array_key_exists( 'hauteur_abri_m', $forge['clean'] ) );

$avec = traiter( $def, piscine( array( 'presence_abri_piscine' => 'oui', 'hauteur_abri_m' => '1,8' ) ) );

verifier( 'avec abri, la hauteur survit', '1.8' === $avec['clean']['hauteur_abri_m'] );

/* ================================================================== *
 *  5. Aucune surface de plancher ne revient
 * ================================================================== */

echo "\n── 5. Rien qui ne concerne le bassin\n";

$forgees = traiter(
	$def,
	piscine( array( 'sp_existante' => '120', 'sp_creee' => '18', 'sp_totale' => '138', 'emprise_creee' => '20', 'surface_taxable' => '90' ) )
);

foreach ( array( 'sp_existante', 'sp_creee', 'sp_totale', 'emprise_creee', 'surface_taxable' ) as $champ ) {
	verifier( sprintf( '« %s » forgé pour une piscine est écarté', $champ ), ! array_key_exists( $champ, $forgees['clean'] ) );
}

verifier( 'les cinq écarts sont consignés', 5 === count( array_intersect( $forgees['ecarts'], array( 'sp_existante', 'sp_creee', 'sp_totale', 'emprise_creee', 'surface_taxable' ) ) ) );

// Et un champ de bassin forgé sur une autre nature ne passe pas davantage.
$ailleurs = array();
MatriceChamps::filtrer( 'declaration_prealable', array( 'nature' => 'cloture_mur', 'longueur_bassin_m' => '8.5' ), $ailleurs );

verifier( 'un champ de bassin forgé sur une clôture est écarté', in_array( 'longueur_bassin_m', $ailleurs, true ) );

/* ================================================================== *
 *  6. Ce que les humains liront
 * ================================================================== */

echo "\n── 6. Rendu\n";

$lignes = PrecisionsProjet::lignes( $r['clean'] );

verifier( 'la rubrique porte le bon intitulé', 'Précisions sur le projet' === PrecisionsProjet::RUBRIQUE );
verifier( 'la longueur s’écrit à la française', '8,5 m' === ( $lignes['Longueur du bassin'] ?? '' ) );
verifier( 'la surface porte son unité', '34 m²' === ( $lignes['Surface du bassin'] ?? '' ) );
verifier( 'l’abri se dit en clair', 'Oui' === ( $lignes['Abri de piscine'] ?? '' ) );
verifier( 'aucun identifiant technique ne sort',
	array() === array_filter( array_keys( $lignes ), static fn( $k ) => str_contains( $k, '_' ) ) );

$partiel = PrecisionsProjet::lignes( traiter( $def, piscine( array( 'longueur_bassin_m' => '8,5' ) ) )['clean'] );

verifier( 'seules les valeurs renseignées produisent une ligne', 1 === count( $partiel ) );
verifier( 'rien à montrer quand rien n’est renseigné', ! PrecisionsProjet::existe( traiter( $def, piscine() )['clean'] ) );

// « inconnu » est une réponse, pas une absence.
$inconnu = PrecisionsProjet::lignes( traiter( $def, piscine( array( 'presence_abri_piscine' => 'inconnu' ) ) )['clean'] );

verifier( '« inconnu » se dit « Je ne sais pas »', 'Je ne sais pas' === ( $inconnu['Abri de piscine'] ?? '' ) );

echo "\n── 7. Résumé de l’accusé client\n";

verifier( 'le résumé se lit comme une phrase',
	'Bassin d’environ 8,5 m × 4 m, soit 34 m², profondeur approximative 1,5 m, avec abri d’environ 1,8 m.'
	=== PrecisionsProjet::resume( $r['clean'] ) );

$sans_abri = traiter( $def, piscine( array( 'longueur_bassin_m' => '8,5', 'largeur_bassin_m' => '4', 'presence_abri_piscine' => 'non' ) ) );

verifier( 'sans abri, il le dit', str_contains( PrecisionsProjet::resume( $sans_abri['clean'] ), 'sans abri' ) );
verifier( 'et ne mentionne aucune hauteur', ! str_contains( PrecisionsProjet::resume( $sans_abri['clean'] ), 'abri d’environ' ) );
verifier( 'rien de précisé, rien de résumé', '' === PrecisionsProjet::resume( traiter( $def, piscine() )['clean'] ) );

// Échappement : une valeur hostile ne peut pas venir d'un champ numérique, mais
// la liste fermée et le rendu doivent rester sûrs quoi qu'il arrive.
$hostile = PrecisionsProjet::lignes( array( 'presence_abri_piscine' => '<script>alert(1)</script>' ) );

verifier( 'une valeur hors liste ne produit aucune ligne', array() === $hostile );

echo "\n── 8. Permis de construire : la piscine passe par une question\n";

/* Une maison individuelle peut comporter un bassin — mais on le demande avant
 * de le mesurer. Ce qui compte ici n'est pas l'affichage : c'est qu'une charge
 * forgée, postée sans navigateur, ne puisse pas faire entrer des dimensions
 * que personne n'a annoncées. */
$brut_pc = require $src . '/Forms/definitions/permis_construire.php';
$def_pc  = new FormDefinition( $brut_pc['type'], $brut_pc['title'], $brut_pc['submit_label'], $brut_pc['fields'], $brut_pc['steps'] );

verifier( '« piscine_prevue » est déclaré côté PC', null !== $def_pc->field( 'piscine_prevue' ) );
verifier( 'et pas côté DP', null === $def->field( 'piscine_prevue' ) );

/**
 * Soumission de maison individuelle, traitée comme le contrôleur.
 *
 * @param FormDefinition       $def_pc Définition PC.
 * @param array<string, mixed> $extra  Champs ajoutés.
 * @return array{clean:array<string,mixed>,ecarts:array<int,string>}
 */
function maison( FormDefinition $def_pc, array $extra = array() ): array {
	$post = array_merge(
		array(
			'declarant_type'    => 'particulier',
			'nom'               => 'Fictif',
			'prenom'            => 'Camille',
			'qualite'           => 'proprietaire',
			'email'             => 'camille@exemple.test',
			'telephone'         => '0600000000',
			'adresse_declarant' => '1 rue Imaginaire',
			'cp_declarant'      => '33000',
			'ville_declarant'   => 'Bordeaux',
			'terrain_adresse'   => '1 rue Imaginaire',
			'terrain_cp'        => '33000',
			'terrain_ville'     => 'Bordeaux',
			'dossier_type'      => 'pcmi',
			'nature'            => 'maison_individuelle',
			'description'       => 'Maison neuve.',
			'abf'               => 'non',
			'demolition'        => 'non',
			'attest_exact'      => '1',
			'attest_rgpd'       => '1',
		),
		$extra
	);

	$v      = Validator::validate( $def_pc, $post );
	$ecarts = array();
	$clean  = MatriceChamps::filtrer( 'permis_construire', $v['clean'], $ecarts );

	return array( 'clean' => $clean, 'ecarts' => $ecarts );
}

$mesures = array(
	'longueur_bassin_m'     => '8,5',
	'largeur_bassin_m'      => '4',
	'surface_bassin_m2'     => '34',
	'profondeur_bassin_m'   => '1,5',
	'presence_abri_piscine' => 'oui',
	'hauteur_abri_m'        => '1,8',
);

$avec = maison( $def_pc, array_merge( array( 'piscine_prevue' => 'oui' ), $mesures ) );

verifier( 'PC · annoncée, la longueur est conservée', '8.5' === $avec['clean']['longueur_bassin_m'] );
verifier( 'PC · annoncée, la hauteur d’abri aussi', '1.8' === $avec['clean']['hauteur_abri_m'] );

// Le cas qui compte : la personne a mesuré, puis a répondu « non ». Le
// navigateur n'enverrait rien, mais le serveur ne se repose pas là-dessus.
foreach ( array( 'non', 'inconnu' ) as $reponse ) {
	$sans = maison( $def_pc, array_merge( array( 'piscine_prevue' => $reponse ), $mesures ) );

	foreach ( array_keys( $mesures ) as $champ ) {
		verifier( sprintf( 'PC · « %s » écarte %s', $reponse, $champ ), ! array_key_exists( $champ, $sans['clean'] ) );
	}

	verifier( sprintf( 'PC · « %s » conserve la réponse', $reponse ), $reponse === $sans['clean']['piscine_prevue'] );
}

// Sans aucune réponse, les mesures n'entrent pas davantage : une absence n'est
// pas un « oui » qu'on aurait oublié de dire.
$muet = maison( $def_pc, $mesures );

verifier( 'PC · sans réponse, aucune mesure n’entre',
	array() === array_intersect( array_keys( $mesures ), array_keys( $muet['clean'] ) ) );

$lignes_pc = PrecisionsProjet::lignes( maison( $def_pc, array( 'piscine_prevue' => 'non' ) )['clean'] );

verifier( 'PC · « non » se dit sobrement', 'Non' === ( $lignes_pc['Piscine prévue'] ?? '' ) );
verifier( 'PC · et le résumé client le dit en une phrase',
	'Aucune piscine prévue.' === PrecisionsProjet::resume( maison( $def_pc, array( 'piscine_prevue' => 'non' ) )['clean'] ) );

echo "\n── 9. Aucun doublon dans les rendus\n";

/* Les mesures apparaissaient deux fois dans la notification interne : une fois
 * en forme canonique sous le libellé du formulaire, une fois en français sous
 * son libellé client. Deux écritures d'un même nombre dans un même message,
 * c'est une occasion de douter des deux.
 *
 * L'exclusion est déclarative : le catalogue de présentation dit ce qu'il
 * porte, et le rendu générique s'abstient. Aucune liste de piscine n'est
 * recopiée dans le renderer. */
foreach ( array_keys( $mesures ) as $champ ) {
	verifier( sprintf( 'le catalogue assume « %s »', $champ ), PrecisionsProjet::porte( $champ ) );
}

verifier( 'le catalogue assume « piscine_prevue »', PrecisionsProjet::porte( 'piscine_prevue' ) );
verifier( 'et n’assume pas « nature »', ! PrecisionsProjet::porte( 'nature' ) );
verifier( 'ni « description »', ! PrecisionsProjet::porte( 'description' ) );

$corps = \Urbizen\Platform\Mail\MailRenderer::body(
	array(
		'id'             => 1,
		'reference'      => 'URB-2026-0000',
		'created_at_gmt' => '2026-08-06 00:00:00',
		'form_type'      => 'declaration_prealable',
		'consent_at_gmt' => '2026-08-06 00:00:00',
		'payload'        => $r['clean'],
		'pricing'        => array( 'base' => 249, 'total' => 249 ),
		'files'          => array(),
	),
	0
);

verifier( 'la rubrique dédiée est présente', str_contains( $corps, PrecisionsProjet::RUBRIQUE ) );
verifier( 'la longueur s’y lit une seule fois, en français', 1 === substr_count( $corps, '8,5 m' ) );
verifier( 'et jamais en forme canonique', ! str_contains( $corps, '>8.5<' ) );
verifier( 'la surface ne paraît qu’une fois', 1 === substr_count( $corps, '34 m²' ) );
verifier( 'la profondeur ne paraît qu’une fois', 1 === substr_count( $corps, '1,5 m' ) );
verifier( 'aucun libellé de formulaire ne double la rubrique',
	! str_contains( $corps, 'Longueur approximative du bassin' ) );
verifier( 'aucun identifiant technique ne sort',
	! str_contains( $corps, 'longueur_bassin_m' ) && ! str_contains( $corps, 'presence_abri_piscine' ) );

// Les autres réponses, elles, restent : l'exclusion vise le doublon, pas le
// tableau. Un champ qui disparaîtrait des deux endroits serait une perte.
verifier( 'le tableau générique conserve le reste', str_contains( $corps, 'Bassin enterré.' ) );
verifier( 'et son libellé', str_contains( $corps, 'Description du projet' ) );

printf( "\n%s\n", 0 === $echecs ? 'TOUS LES CONTROLES PASSENT' : sprintf( '%d CONTROLE(S) EN ECHEC', $echecs ) );

exit( 0 === $echecs ? 0 : 1 );
