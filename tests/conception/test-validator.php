<?php
/**
 * Banc d'essai de la validation serveur.
 *
 * Toutes les données de ce banc sont fictives. Aucune adresse, aucun nom et
 * aucune adresse électronique réelle n'y figure.
 *
 * Le principe vérifié ici tient en une phrase : rien de ce qui arrive du
 * navigateur ne peut créer un champ, élargir une liste, repousser une borne ou
 * peser sur un prix.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\Validator;

$def = definition( brut( 'conception' ) );

/**
 * Soumission minimale valide, servant de base aux variantes.
 *
 * @param array<string, mixed> $extra Champs supplémentaires ou remplacés.
 * @return array<string, mixed>
 */
function base( array $extra = array() ): array {
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

// -------------------------------------------------- soumission minimale ----
$r = Validator::validate( $def, base() );

check( 'une soumission minimale est valide', $r['valid'] );
check( 'aucune erreur relevée', array() === $r['errors'] );
check( 'le consentement est interprété comme un booléen vrai', true === $r['clean']['rgpd'] );
check( 'le prix minimal est le prix de base', 449 === $r['pricing']['total'] );
check( 'aucun devis n’est requis par défaut', false === $r['pricing']['devis_requis'] );

// ------------------------------------------------------ champs requis ------
foreach ( array( 'nature', 'situation', 'a_terrain', 'nom', 'email', 'rgpd' ) as $champ ) {
	$sans = base();
	unset( $sans[ $champ ] );
	$r = Validator::validate( $def, $sans );

	check( "[$champ] absent → soumission refusée", ! $r['valid'] && isset( $r['errors'][ $champ ] ) );
}

$r = Validator::validate( $def, base( array( 'rgpd' => '0' ) ) );
check( 'un consentement refusé bloque la soumission', ! $r['valid'] && 'requis' === $r['errors']['rgpd'] );

$r = Validator::validate( $def, base( array( 'rgpd' => 'peut_etre' ) ) );
check( 'une valeur fantaisiste de consentement vaut refus', ! $r['valid'] && false === $r['clean']['rgpd'] );

// -------------------------------------------------------- listes fermées ---
$r = Validator::validate( $def, base( array( 'nature' => 'chateau' ) ) );
check( 'une valeur hors liste est refusée', ! $r['valid'] && 'hors_liste' === $r['errors']['nature'] );

$r = Validator::validate( $def, base( array( 'niveaux' => 'plain_pied' ) ) );
check( 'une valeur de la liste est acceptée', $r['valid'] && 'plain_pied' === $r['clean']['niveaux'] );

$r = Validator::validate( $def, base( array( 'pieces' => array( 'bureau', 'garage' ) ) ) );
check( 'une liste à choix multiples est acceptée', $r['valid'] && array( 'bureau', 'garage' ) === $r['clean']['pieces'] );

$r = Validator::validate( $def, base( array( 'pieces' => array( 'bureau', 'piscine_olympique' ) ) ) );
check( 'une seule valeur hors liste invalide la liste entière', ! $r['valid'] && 'hors_liste' === $r['errors']['pieces'] );

$r = Validator::validate( $def, base( array( 'pieces' => array( 'garage', 'bureau' ) ) ) );
check( 'les choix multiples sont réordonnés selon la définition', array( 'bureau', 'garage' ) === $r['clean']['pieces'] );

// -------------------------------------------------------------- bornes -----
$bornes = array(
	array( 'surface', 10, true ),
	array( 'surface', 9, false ),
	array( 'surface', 1000, true ),
	array( 'surface', 1001, false ),
	array( 'chambres', 0, true ),
	array( 'chambres', 20, true ),
	array( 'chambres', 21, false ),
	array( 'chambres', -1, false ),
	array( 'sdb', 10, true ),
	array( 'sdb', 11, false ),
	array( 'wc', 10, true ),
	array( 'wc', 11, false ),
);

foreach ( $bornes as list( $champ, $valeur, $accepte ) ) {
	$r = Validator::validate( $def, base( array( $champ => (string) $valeur ) ) );
	check(
		sprintf( '[%s] %s %s', $champ, $valeur, $accepte ? 'accepté' : 'refusé' ),
		$accepte === $r['valid']
	);
}

$r = Validator::validate( $def, base( array( 'surface' => '120,5' ) ) );
check( 'un nombre décimal est refusé', ! $r['valid'] && 'nombre_invalide' === $r['errors']['surface'] );

$r = Validator::validate( $def, base( array( 'surface' => 'beaucoup' ) ) );
check( 'un nombre non numérique est refusé', ! $r['valid'] && 'nombre_invalide' === $r['errors']['surface'] );

$r = Validator::validate( $def, base( array( 'surface' => ' 120 ' ) ) );
check( 'un nombre entouré d’espaces est accepté et normalisé', $r['valid'] && 120 === $r['clean']['surface'] );

// ----------------------------------------------------------- longueurs -----
$r = Validator::validate( $def, base( array( 'message' => str_repeat( 'a', 3000 ) ) ) );
check( 'un message de 3 000 caractères est accepté', $r['valid'] );

$r = Validator::validate( $def, base( array( 'message' => str_repeat( 'a', 3001 ) ) ) );
check( 'un message de 3 001 caractères est refusé', ! $r['valid'] && 'trop_long' === $r['errors']['message'] );

$r = Validator::validate( $def, base( array( 'nom' => str_repeat( 'é', 120 ) ) ) );
check( 'la longueur se compte en caractères, non en octets', $r['valid'] );

// ----------------------------------------------- normalisation des textes --
$r = Validator::validate( $def, base( array( 'nom' => "  Camille   Fictif  \t " ) ) );
check( 'les espaces superflus sont réduits', 'Camille Fictif' === $r['clean']['nom'] );

$r = Validator::validate( $def, base( array( 'nom' => "Camille\r\nBcc: intrus@exemple.test" ) ) );
check( 'un retour chariot dans le nom est neutralisé', ! preg_match( "/[\r\n]/", $r['clean']['nom'] ) );
check( 'le nom reste sur une seule ligne', 'Camille Bcc: intrus@exemple.test' === $r['clean']['nom'] );

$r = Validator::validate( $def, base( array( 'email' => "camille@exemple.test\r\nBcc: intrus@exemple.test" ) ) );
check( 'une injection d’en-tête par l’adresse est refusée', ! $r['valid'] && 'email_invalide' === $r['errors']['email'] );

$r = Validator::validate( $def, base( array( 'email' => 'Camille@Exemple.TEST' ) ) );
check( 'l’adresse électronique est normalisée en minuscules', 'camille@exemple.test' === $r['clean']['email'] );

$r = Validator::validate( $def, base( array( 'email' => 'pas-une-adresse' ) ) );
check( 'une adresse mal formée est refusée', ! $r['valid'] && 'email_invalide' === $r['errors']['email'] );

$r = Validator::validate( $def, base( array( 'message' => "Ligne 1\r\nLigne 2\n\n\n\nLigne 3" ) ) );
check( 'un champ multiligne conserve ses sauts de ligne', str_contains( $r['clean']['message'], "Ligne 1\nLigne 2" ) );
check( 'les lignes vides excédentaires sont réduites', ! str_contains( $r['clean']['message'], "\n\n\n" ) );

$r = Validator::validate( $def, base( array( 'message' => "Bonjour\x00\x07 !" ) ) );
check( 'les caractères de contrôle sont retirés', 'Bonjour !' === $r['clean']['message'] );

// ------------------------------------------------- branches conditionnelles
$avec_terrain = base(
	array(
		'a_terrain'       => 'oui',
		'terrain_adresse' => '1 rue de la Mairie',
		'terrain_ville'   => 'Villefictive',
		'terrain_cp'      => '00000',
		'pente'           => 'plat',
	)
);

$r = Validator::validate( $def, $avec_terrain );

check( 'avec terrain, les champs de terrain sont conservés', $r['valid'] && '1 rue de la Mairie' === $r['clean']['terrain_adresse'] );
check( 'avec terrain, le relief est conservé', 'plat' === $r['clean']['pente'] );

$sans_terrain = array_merge( $avec_terrain, array( 'a_terrain' => 'non' ) );
$r            = Validator::validate( $def, $sans_terrain );

check( 'sans terrain, la soumission reste valide', $r['valid'] );
check( 'sans terrain, l’adresse d’une branche inactive est écartée', ! isset( $r['clean']['terrain_adresse'] ) );
check( 'sans terrain, le relief est écarté', ! isset( $r['clean']['pente'] ) );
check( 'l’écart d’une branche inactive est consigné', array() !== array_filter( $r['notes'], static fn( $n ) => str_contains( $n, 'branche inactive' ) ) );

// Une valeur invalide dans une branche inactive ne doit pas bloquer.
$r = Validator::validate( $def, array_merge( $sans_terrain, array( 'pente' => 'valeur_inventee' ) ) );
check( 'une valeur fautive d’une branche inactive ne bloque pas', $r['valid'] );

// La même valeur fautive dans une branche active bloque bien.
$r = Validator::validate( $def, array_merge( $avec_terrain, array( 'pente' => 'valeur_inventee' ) ) );
check( 'la même valeur fautive bloque en branche active', ! $r['valid'] && isset( $r['errors']['pente'] ) );

$existant = base(
	array(
		'situation'    => 'batiment_existant',
		'bati_type'    => 'maison',
		'bati_surface' => '90',
	)
);

$r = Validator::validate( $def, $existant );
check( 'sur bâtiment existant, les champs d’existant sont conservés', $r['valid'] && 90 === $r['clean']['bati_surface'] );

$r = Validator::validate( $def, array_merge( $existant, array( 'situation' => 'terrain_nu' ) ) );
check( 'sur terrain nu, les champs d’existant sont écartés', ! isset( $r['clean']['bati_surface'] ) );

// -------------------------------------------------- surfaces dynamiques ----
$avec_pieces = base(
	array(
		'chambres' => '3',
		'sdb'      => '1',
		'pieces'   => array( 'bureau' ),
		'surfaces' => array(
			'sejour'    => '35',
			'chambre_1' => '14',
			'chambre_2' => '12',
			'chambre_3' => '11',
			'sdb_1'     => '6',
			'bureau'    => '9',
		),
	)
);

$r = Validator::validate( $def, $avec_pieces );

check( 'les surfaces attendues sont retenues', $r['valid'] && 6 === count( $r['clean']['surfaces'] ) );
check( 'les surfaces sont converties en entiers', 35 === $r['clean']['surfaces']['sejour'] );
check( 'les surfaces sont ordonnées selon la définition', array( 'sejour', 'chambre_1', 'chambre_2', 'chambre_3', 'sdb_1', 'bureau' ) === array_keys( $r['clean']['surfaces'] ) );

// Clé au-delà du nombre de chambres déclaré.
$r = Validator::validate( $def, base( array( 'chambres' => '2', 'surfaces' => array( 'chambre_1' => '14', 'chambre_5' => '14' ) ) ) );
check( 'une chambre au-delà du compteur est écartée', array( 'chambre_1' ) === array_keys( $r['clean']['surfaces'] ) );
check( 'la clé écartée est nommée', in_array( 'surfaces[chambre_5]', $r['ignored'], true ) );

// Clé purement inventée, dans l'esprit du prototype (`surf[Chambre 1]`).
$r = Validator::validate(
	$def,
	base(
		array(
			'chambres' => '1',
			'surfaces' => array(
				'chambre_1'    => '14',
				'Chambre 1'    => '99',
				'salle_du_tr' => '99',
				'../../etc'    => '99',
				'<script>'     => '99',
			),
		)
	)
);

check( 'une clé arbitraire est écartée', array( 'chambre_1' ) === array_keys( $r['clean']['surfaces'] ) );
check( 'les quatre clés arbitraires sont nommées', 4 === count( array_filter( $r['ignored'], static fn( $c ) => str_starts_with( $c, 'surfaces[' ) ) ) );
check( 'une clé arbitraire ne provoque pas d’erreur bloquante', $r['valid'] );

// Une pièce non cochée n'a pas de surface attendue.
$r = Validator::validate( $def, base( array( 'surfaces' => array( 'garage' => '20' ) ) ) );
check( 'la surface d’une pièce non cochée est écartée', array() === $r['clean']['surfaces'] );

$r = Validator::validate( $def, base( array( 'pieces' => array( 'garage' ), 'surfaces' => array( 'garage' => '20' ) ) ) );
check( 'la surface d’une pièce cochée est retenue', array( 'garage' => 20 ) === $r['clean']['surfaces'] );

// Bornes par pièce.
$r = Validator::validate( $def, base( array( 'surfaces' => array( 'sejour' => '0' ) ) ) );
check( 'une surface de 0 m² est refusée', ! $r['valid'] && isset( $r['errors']['surfaces[sejour]'] ) );

$r = Validator::validate( $def, base( array( 'surfaces' => array( 'sejour' => '201' ) ) ) );
check( 'une surface de 201 m² est refusée', ! $r['valid'] && isset( $r['errors']['surfaces[sejour]'] ) );

$r = Validator::validate( $def, base( array( 'surfaces' => array( 'sejour' => '200' ) ) ) );
check( 'une surface de 200 m² est acceptée', $r['valid'] );

$r = Validator::validate( $def, base( array( 'surfaces' => array( 'sejour' => '-40' ) ) ) );
check( 'une surface négative est refusée', ! $r['valid'] );

// Seuil de devis sur le total cumulé.
$grand = array( 'sejour' => '200', 'cuisine' => '200' );

for ( $i = 1; $i <= 4; $i++ ) {
	$grand[ 'chambre_' . $i ] = '200';
}

$r = Validator::validate( $def, base( array( 'chambres' => '4', 'surfaces' => $grand ) ) );

check( 'un cumul de 1 200 m² ne bloque pas la soumission', $r['valid'] );
check( 'un cumul supérieur à 1 000 m² lève la note de devis', in_array( 'devis_requis:surface_totale', $r['notes'], true ) );

$r = Validator::validate( $def, base( array( 'surfaces' => array( 'sejour' => '200', 'cuisine' => '200' ) ) ) );
check( 'un cumul de 400 m² ne lève aucune note de devis', ! in_array( 'devis_requis:surface_totale', $r['notes'], true ) );

// ------------------------------------------------------- champs inconnus ---
$r = Validator::validate( $def, base( array( 'admin' => '1', 'prix_total' => '0', 'surf' => array( 'Chambre 1' => '14' ) ) ) );

check( 'les champs non déclarés sont écartés', $r['valid'] );
check( 'les champs non déclarés sont nommés', array( 'admin', 'prix_total', 'surf' ) === array_values( array_intersect( $r['ignored'], array( 'admin', 'prix_total', 'surf' ) ) ) );
check( 'aucun champ non déclaré ne survit au nettoyage', array() === array_intersect( array_keys( $r['clean'] ), array( 'admin', 'prix_total', 'surf' ) ) );

// ------------------------------------------------------------- tarifs ------
$r = Validator::validate( $def, base( array( 'options_tarifees' => array( 'facades' ) ) ) );
check( 'une option cochée est facturée', 598 === $r['pricing']['total'] );

$r = Validator::validate( $def, base( array( 'options_tarifees' => array( 'pack_ftc', 'facades', 'toiture', 'coupe' ) ) ) );
check( 'le pack évince les options individuelles jusque dans la validation', 748 === $r['pricing']['total'] );

$r = Validator::validate( $def, base( array( 'options_sur_devis' => array( 'insertion3d' ) ) ) );
check( 'une prestation sur devis ne change pas le total', 449 === $r['pricing']['total'] );
check( 'une prestation sur devis lève l’indicateur', true === $r['pricing']['devis_requis'] );

$r = Validator::validate( $def, base( array( 'options_tarifees' => array( 'modifs_sup' ) ) ) );
check( 'modifs_sup n’est pas cochable : la valeur est refusée', ! $r['valid'] && 'hors_liste' === $r['errors']['options_tarifees'] );

$r = Validator::validate( $def, base( array( 'options_tarifees' => array( 'facades' ), 'total' => '0', 'prix' => '1' ) ) );
check( 'un total transmis par le navigateur est sans effet', 598 === $r['pricing']['total'] );

$r = Validator::validate( $def, base( array( 'options_tarifees' => array( 'facades_offertes' ) ) ) );
check( 'un identifiant d’option inventé est refusé', ! $r['valid'] && isset( $r['errors']['options_tarifees'] ) );

// --------------------------------------------- séparation brut / nettoyé ---
$entree = base( array( 'nom' => '  Camille  ', 'surface' => ' 120 ' ) );
$r      = Validator::validate( $def, $entree );

check( 'les données d’entrée ne sont pas modifiées', '  Camille  ' === $entree['nom'] );
check( 'les données nettoyées sont distinctes des données brutes', 'Camille' === $r['clean']['nom'] && 120 === $r['clean']['surface'] );
check( 'aucun champ de type fichier ne figure dans les données nettoyées', array() === array_intersect( array_keys( $r['clean'] ), array( 'croquis_plans', 'photos', 'urbanisme' ) ) );

verdict();
