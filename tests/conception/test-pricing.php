<?php
/**
 * Banc d'essai du catalogue tarifaire serveur.
 *
 * Le prix est une décision commerciale : il se vérifie au montant près, et sa
 * seule source est le serveur. Ce banc contrôle les montants, l'exclusivité du
 * pack, la mise à l'écart des prestations sur devis, et surtout que rien de ce
 * qui vient du navigateur ne peut peser sur le total.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\Pricing;

// ---------------------------------------------------------- catalogue ------
check( 'prestation de base à 449 €', 449 === Pricing::BASE );

$catalogue = array(
	'facades'    => 149,
	'toiture'    => 99,
	'coupe'      => 99,
	'pack_ftc'   => 299,
	'masse'      => 149,
	'vue3d'      => 149,
	'modifs_sup' => 99,
);

check( 'le catalogue contient exactement les sept options chiffrées', $catalogue === Pricing::OPTIONS );
check( 'les trois prestations sur devis sont déclarées', array( 'insertion3d', 'complexe', 'particulier' ) === Pricing::SUR_DEVIS );
check( 'aucune prestation sur devis ne porte de montant', array() === array_intersect( Pricing::SUR_DEVIS, array_keys( Pricing::OPTIONS ) ) );
check( 'le pack remplace bien façades, toiture et coupe', array( 'facades', 'toiture', 'coupe' ) === Pricing::PACK_REMPLACE );

// ------------------------------------------------------------ calculs ------
$cas = array(
	'base seule'                            => array( array(), 449 ),
	'façades'                               => array( array( 'facades' ), 598 ),
	'toiture'                               => array( array( 'toiture' ), 548 ),
	'coupe'                                 => array( array( 'coupe' ), 548 ),
	'pack'                                  => array( array( 'pack_ftc' ), 748 ),
	'façades + toiture + coupe, sans pack'  => array( array( 'facades', 'toiture', 'coupe' ), 796 ),
	'pack + les trois individuelles'        => array( array( 'pack_ftc', 'facades', 'toiture', 'coupe' ), 748 ),
	'masse + vue3d'                         => array( array( 'masse', 'vue3d' ), 747 ),
	'option sur devis seule'                => array( array( 'insertion3d' ), 449 ),
);

foreach ( $cas as $libelle => $attendu ) {
	$r = Pricing::compute( $attendu[0] );
	check( sprintf( '%-38s → %d €', $libelle, $attendu[1] ), $attendu[1] === $r['total'] );
}

// L'écart entre le pack et les trois options séparées doit rester une économie.
$pack   = Pricing::compute( array( 'pack_ftc' ) )['total'];
$separe = Pricing::compute( array( 'facades', 'toiture', 'coupe' ) )['total'];

check( 'le pack est strictement moins cher que les trois options séparées', $pack < $separe );
check( 'l’économie du pack est de 48 €', 48 === $separe - $pack );

// ------------------------------------------------------ pack exclusif ------
$r = Pricing::compute( array( 'pack_ftc', 'facades', 'toiture', 'coupe' ) );

check( 'le pack évince les trois options individuelles', array( 'pack_ftc' ) === array_column( $r['options'], 'id' ) );
check( 'le total ne peut jamais atteindre 1 044 €', 1044 !== $r['total'] );

$ordre_a = Pricing::compute( array( 'vue3d', 'facades', 'masse' ) );
$ordre_b = Pricing::compute( array( 'masse', 'vue3d', 'facades' ) );

check( 'l’ordre des cases cochées n’influe ni sur le total ni sur le détail', $ordre_a === $ordre_b );
check( 'les options sont restituées dans l’ordre du catalogue', array( 'facades', 'masse', 'vue3d' ) === array_column( $ordre_a['options'], 'id' ) );

$double = Pricing::compute( array( 'facades', 'facades', 'facades' ) );

check( 'une option cochée trois fois n’est comptée qu’une fois', 598 === $double['total'] );

// --------------------------------------------------------- sur devis -------
$r = Pricing::compute( array( 'facades', 'insertion3d', 'complexe' ) );

check( 'les prestations sur devis ne s’ajoutent pas au total', 598 === $r['total'] );
check( 'les prestations sur devis sont restituées à part', array( 'insertion3d', 'complexe' ) === $r['sur_devis'] );
check( 'l’indicateur de devis est levé', true === $r['devis_requis'] );
check( 'aucune prestation sur devis ne figure parmi les options chiffrées', array() === array_intersect( array_column( $r['options'], 'id' ), Pricing::SUR_DEVIS ) );
check( 'sans prestation sur devis, l’indicateur reste baissé', false === Pricing::compute( array( 'masse' ) )['devis_requis'] );

// ------------------------------------------- rien ne vient du navigateur ---
$falsifie = Pricing::compute( array( 'facades', 'total', '449', 'facades_gratuit', 'PACK_FTC' ) );

check( 'un prix transmis par le navigateur est sans effet', 598 === $falsifie['total'] );
check( 'les identifiants inconnus sont écartés et nommés', array( 'total', '449', 'facades_gratuit', 'PACK_FTC' ) === $falsifie['ignores'] );
check( 'la casse d’un identifiant n’est pas tolérée', array() === array_intersect( array_column( $falsifie['options'], 'id' ), array( 'pack_ftc' ) ) );

$types = Pricing::compute( array( 149, null, array( 'facades' ), true, 'masse' ) );

check( 'une valeur qui n’est pas une chaîne est écartée', 598 === $types['total'] );
check( 'seule l’option valide survit à un panier hétéroclite', array( 'masse' ) === array_column( $types['options'], 'id' ) );

// -------------------------------------------------- remise permis futur ----
check( 'la remise permis vaut 200 € en constante d’affichage', 200 === Pricing::REMISE_PERMIS_FUTUR );

$tous = Pricing::compute( array_keys( Pricing::OPTIONS ) );

check( 'aucun calcul ne descend jamais sous le prix de base', $tous['total'] >= Pricing::BASE );
check( 'aucun panier ne produit 249 € (449 − 200)', 249 !== Pricing::compute( array() )['total'] );
check( 'aucun panier ne produit 649 € par déduction de la remise', 649 !== Pricing::compute( array( 'masse' ) )['total'] );

$source = file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Forms/Pricing.php' );
$code   = implode(
	'',
	array_map(
		static fn( $t ) => is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $t ) ? $t[1] : $t ),
		token_get_all( $source )
	)
);

check( 'aucune soustraction dans le code de Pricing', ! preg_match( '/-=|(?<![<>=!-])\s-\s/', $code ) );
check( 'la constante de remise n’est employée nulle part dans le calcul', 1 === substr_count( $code, 'REMISE_PERMIS_FUTUR' ) );
check( 'aucun montant 649 n’est écrit dans Pricing', ! preg_match( '/\b649\b/', $code ) );

// Le total est toujours la somme exacte de la base et des options retenues.
$echantillons = array(
	array(),
	array( 'facades' ),
	array( 'pack_ftc', 'masse' ),
	array( 'facades', 'toiture', 'coupe', 'masse', 'vue3d' ),
	array( 'pack_ftc', 'facades', 'toiture', 'coupe', 'masse', 'vue3d', 'insertion3d' ),
);

$coherent = true;

foreach ( $echantillons as $panier ) {
	$r = Pricing::compute( $panier );

	if ( $r['total'] !== $r['base'] + array_sum( array_column( $r['options'], 'price' ) ) ) {
		$coherent = false;
	}
}

check( 'le total est toujours la somme exacte de la base et des options retenues', $coherent );

verdict();
