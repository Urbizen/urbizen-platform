<?php
/**
 * Banc de mutation.
 *
 * Un contrôle vert ne prouve rien tant qu'on n'a pas vu ce qui le fait rougir.
 * Ce banc casse volontairement une règle à la fois — dans une copie, jamais
 * dans le dépôt — et vérifie que le contrôle correspondant tombe bien.
 *
 * Deux techniques :
 *
 * - mutation de **données** : la définition est un tableau, on l'altère en
 *   mémoire avant de la confier à FormDefinition ;
 * - mutation de **code** : le fichier source est copié, une règle y est
 *   neutralisée, la classe est renommée puis chargée. Le dépôt n'est jamais
 *   modifié et le fichier temporaire est détruit immédiatement.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\Pricing;
use Urbizen\Platform\Forms\Validator;

$compteur = 0;

/**
 * Charge une copie mutée d'une classe du plugin.
 *
 * @param string                $relatif       Chemin sous le plugin.
 * @param string                $classe        Nom de la classe d'origine.
 * @param array<string, string> $remplacements Motif exact => remplacement.
 * @return string Nom pleinement qualifié de la classe mutée.
 */
function mutant( string $relatif, string $classe, array $remplacements ): string {
	global $compteur;

	$source  = (string) file_get_contents( URBIZEN_PLATFORM_DIR . $relatif );
	$nouveau = $classe . 'Mutant' . ( ++$compteur );
	$source  = str_replace( "final class $classe", "final class $nouveau", $source );

	foreach ( $remplacements as $de => $vers ) {
		if ( ! str_contains( $source, $de ) ) {
			// Le motif a disparu du code : la mutation ne prouverait rien.
			throw new RuntimeException( "motif introuvable dans $relatif : $de" );
		}

		$source = str_replace( $de, $vers, $source );
	}

	$fichier = sys_get_temp_dir() . '/urbizen-' . $nouveau . '.php';
	file_put_contents( $fichier, $source );
	require $fichier;
	unlink( $fichier );

	return '\\Urbizen\\Platform\\Forms\\' . $nouveau;
}

/**
 * Soumission de référence, valide.
 *
 * @param array<string, mixed> $extra Champs supplémentaires.
 * @return array<string, mixed>
 */
function soumission( array $extra = array() ): array {
	return array_merge(
		array(
			'nature'    => 'maison',
			'situation' => 'terrain_nu',
			'a_terrain' => 'non',
			'nom'       => 'Camille Fictif',
			'email'     => 'camille@exemple.test',
			'rgpd'      => '1',
		),
		$extra
	);
}

$ref = definition( brut( 'conception' ) );

echo "Chaque ligne vérifie qu'une règle cassée fait bien tomber son contrôle.\n\n";

// ============================================ 1 · le prix de base change ===
$p = mutant( 'src/Forms/Pricing.php', 'Pricing', array( 'public const BASE = 449;' => 'public const BASE = 399;' ) );

check( '1 · prix de base modifié → le montant de référence tombe', 449 !== $p::BASE );
check( '1 · prix de base modifié → le total minimal tombe', 449 !== $p::compute( array() )['total'] );
check( '1 · le catalogue du dépôt reste intact', 449 === Pricing::BASE );

// ================================ 2 · pack_ftc se cumule avec les trois ====
$p = mutant(
	'src/Forms/Pricing.php',
	'Pricing',
	array( 'unset( $ids[ $remplacee ] );' => '// éviction neutralisée.' )
);

$cumule = $p::compute( array( 'pack_ftc', 'facades', 'toiture', 'coupe' ) );

check( '2 · éviction neutralisée → le pack cumule et le total tombe', 748 !== $cumule['total'] );
check( '2 · éviction neutralisée → quatre options au lieu d’une', array( 'pack_ftc' ) !== array_column( $cumule['options'], 'id' ) );
check( '2 · le dépôt évince toujours correctement', 748 === Pricing::compute( array( 'pack_ftc', 'facades', 'toiture', 'coupe' ) )['total'] );

// ====================================== 3 · un type de champ inconnu passe =
$raw                     = brut( 'conception' );
$raw['fields'][]         = array(
	'name'  => 'mot_de_passe',
	'type'  => 'password',
	'step'  => 'contact',
	'label' => 'Mot de passe',
);
$avec_type_inconnu       = definition( $raw );

check( '3 · type inconnu → la définition signale l’anomalie', ! $avec_type_inconnu->is_valid() );
check( '3 · type inconnu → le champ est absent', null === $avec_type_inconnu->field( 'mot_de_passe' ) );
check(
	'3 · type inconnu → le refus est nommé',
	array() !== array_filter( $avec_type_inconnu->errors(), static fn( $e ) => str_contains( $e, 'type « password » refusé' ) )
);

$d = mutant(
	'src/Forms/FormDefinition.php',
	'FormDefinition',
	array( "'consent',\n\t);" => "'consent',\n\t\t'password',\n\t);" )
);

$permissive = new $d( 'conception', '', '', $raw['fields'], $raw['steps'] );

check( '3 · garde retirée → le champ interdit entre', null !== $permissive->field( 'mot_de_passe' ) );
check( '3 · le dépôt refuse toujours le type inconnu', ! in_array( 'password', FormDefinition::TYPES, true ) );

// =================================== 4 · une clé de surface arbitraire passe
// Les surfaces sont gardées par deux barrières successives : la liste blanche
// à la lecture, puis le réordonnancement sur les clés déclarées. On mesure
// chacune séparément — c'est la seule façon de savoir laquelle protège.
$attaque = soumission(
	array(
		'chambres' => '1',
		'surfaces' => array( 'chambre_1' => '14', 'Chambre 1' => '99', '<script>' => '99' ),
	)
);

$sain = Validator::validate( $ref, $attaque );

$filtre_lecture = 'if ( ! in_array( $cle, $declarees, true ) || ! in_array( $cle, $attendues, true ) ) {';
$filtre_ordre   = "foreach ( \$declarees as \$cle ) {\n\t\t\tif ( isset( \$values[ \$cle ] ) ) {\n\t\t\t\t\$ordonnees[ \$cle ] = \$values[ \$cle ];\n\t\t\t}\n\t\t}";

$v1 = mutant( 'src/Forms/Validator.php', 'Validator', array( $filtre_ordre => '$ordonnees = $values;' ) );
$v2 = mutant( 'src/Forms/Validator.php', 'Validator', array( $filtre_lecture => 'if ( false ) {' ) );
$v3 = mutant(
	'src/Forms/Validator.php',
	'Validator',
	array(
		$filtre_lecture => 'if ( false ) {',
		$filtre_ordre   => '$ordonnees = $values;',
	)
);

check( '4 · seconde barrière retirée → la liste blanche protège encore', array( 'chambre_1' ) === array_keys( $v1::validate( $ref, $attaque )['clean']['surfaces'] ) );
check( '4 · première barrière retirée → le réordonnancement protège encore', array( 'chambre_1' ) === array_keys( $v2::validate( $ref, $attaque )['clean']['surfaces'] ) );
check( '4 · les deux barrières retirées → les clés arbitraires entrent', count( $v3::validate( $ref, $attaque )['clean']['surfaces'] ) > 1 );
check( '4 · première barrière retirée → les clés ne sont plus nommées', array() === array_filter( $v2::validate( $ref, $attaque )['ignored'], static fn( $c ) => str_starts_with( $c, 'surfaces[' ) ) );
check( '4 · le dépôt n’accepte que la clé attendue', array( 'chambre_1' ) === array_keys( $sain['clean']['surfaces'] ) );
check( '4 · le dépôt nomme les clés écartées', 2 === count( array_filter( $sain['ignored'], static fn( $c ) => str_starts_with( $c, 'surfaces[' ) ) ) );

// ============================================== 5 · une valeur hors liste ==
$v = mutant(
	'src/Forms/Validator.php',
	'Validator',
	array( 'if ( ! in_array( $valeur, $permises, true ) ) {' => 'if ( false ) {' )
);

$hors_liste = soumission( array( 'nature' => 'chateau' ) );

check( '5 · contrôle de liste retiré → la valeur inventée passe', $v::validate( $ref, $hors_liste )['valid'] );
check( '5 · le dépôt refuse la valeur inventée', ! Validator::validate( $ref, $hors_liste )['valid'] );

// ================================================= 6 · une borne supprimée =
$raw = brut( 'conception' );

foreach ( $raw['fields'] as &$champ ) {
	if ( 'chambres' === $champ['name'] ) {
		unset( $champ['max'] );
	}
}

unset( $champ );

$sans_borne = definition( $raw );
$trop       = soumission( array( 'chambres' => '99' ) );

check( '6 · borne haute supprimée → 99 chambres passent', Validator::validate( $sans_borne, $trop )['valid'] );
check( '6 · le dépôt refuse 99 chambres', ! Validator::validate( $ref, $trop )['valid'] );

// ================================== 7 · rgpd cesse d’être obligatoire ======
$raw = brut( 'conception' );

foreach ( $raw['fields'] as &$champ ) {
	if ( 'rgpd' === $champ['name'] ) {
		$champ['required'] = false;
	}
}

unset( $champ );

$sans_consentement = definition( $raw );
$sans_rgpd         = soumission();
unset( $sans_rgpd['rgpd'] );

check( '7 · consentement facultatif → la soumission sans accord passe', Validator::validate( $sans_consentement, $sans_rgpd )['valid'] );
check( '7 · le dépôt exige toujours le consentement', ! Validator::validate( $ref, $sans_rgpd )['valid'] );
check(
	'7 · le dépôt marque rgpd comme obligatoire',
	! empty( $ref->field( 'rgpd' )['required'] )
);

// ===================================== 8 · modifs_sup devient exposée ======
$raw = brut( 'conception' );

foreach ( $raw['fields'] as &$champ ) {
	if ( 'options_tarifees' === $champ['name'] ) {
		$champ['options'][] = array(
			'value'    => 'modifs_sup',
			'label'    => 'Série supplémentaire de trois modifications',
			'price_id' => 'modifs_sup',
		);
	}
}

unset( $champ );

$expose  = definition( $raw );
$exposes = array_column( $expose->field( 'options_tarifees' )['options'], 'price_id' );

check( '8 · option ajoutée → modifs_sup devient exposée', in_array( 'modifs_sup', $exposes, true ) );
check( '8 · option ajoutée → elle devient cochable et facturée', Validator::validate( $expose, soumission( array( 'options_tarifees' => array( 'modifs_sup' ) ) ) )['pricing']['total'] === 548 );
check(
	'8 · le dépôt ne l’expose pas',
	! in_array( 'modifs_sup', array_column( $ref->field( 'options_tarifees' )['options'], 'price_id' ), true )
);
check( '8 · le dépôt refuse de la cocher', ! Validator::validate( $ref, soumission( array( 'options_tarifees' => array( 'modifs_sup' ) ) ) )['valid'] );

// ========================================= 9 · la remise de 200 € déduite ==
$p = mutant(
	'src/Forms/Pricing.php',
	'Pricing',
	array( '$total   = self::BASE;' => '$total   = self::BASE - self::REMISE_PERMIS_FUTUR;' )
);

check( '9 · remise soustraite → le prix de base tombe à 249 €', 249 === $p::compute( array() )['total'] );
check( '9 · remise soustraite → le pack tombe à 548 €', 548 === $p::compute( array( 'pack_ftc' ) )['total'] );
check( '9 · le dépôt ne soustrait jamais la remise', 449 === Pricing::compute( array() )['total'] );
check( '9 · aucun panier du dépôt ne vaut 649 €', array() === array_filter(
	array( array(), array( 'facades' ), array( 'toiture' ), array( 'coupe' ), array( 'pack_ftc' ), array( 'masse' ), array( 'vue3d' ) ),
	static fn( $panier ) => 649 === Pricing::compute( $panier )['total']
) );

// ================================================ 10 · localisation altérée
$raw = brut( 'localisation' );

foreach ( $raw['fields'] as &$champ ) {
	if ( 'terrain_adresse' === $champ['name'] ) {
		$champ['type'] = 'textarea';
	}
}

unset( $champ );

$loc_mutee = definition( $raw );
$loc_saine = definition( brut( 'localisation' ) );

check( '10 · type altéré → localisation n’emploie plus les trois types historiques',
	array() !== array_diff( array_unique( array_column( $loc_mutee->fields(), 'type' ) ), array( 'text', 'number', 'hidden' ) ) );
check( '10 · le dépôt conserve les trois types historiques',
	array() === array_diff( array_unique( array_column( $loc_saine->fields(), 'type' ) ), array( 'text', 'number', 'hidden' ) ) );

$raw = brut( 'localisation' );
array_pop( $raw['fields'] );
$loc_tronquee = definition( $raw );

check( '10 · champ retiré → le compte de localisation tombe', 14 !== count( $loc_tronquee->fields() ) );
check( '10 · le dépôt conserve ses 14 champs', 14 === count( $loc_saine->fields() ) );

$raw = brut( 'localisation' );
$raw['fields'][] = array( 'name' => 'terrain_adresse', 'type' => 'text', 'label' => 'Doublon' );
$loc_doublon     = definition( $raw );

check( '10 · doublon introduit → l’anomalie est signalée', ! $loc_doublon->is_valid() );
check( '10 · doublon introduit → le champ n’est pas dupliqué', 14 === count( $loc_doublon->fields() ) );
check( '10 · le dépôt se charge sans aucune anomalie', $loc_saine->is_valid() );

verdict();
