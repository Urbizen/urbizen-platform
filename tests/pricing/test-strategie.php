<?php
/**
 * Banc d'essai des STRATÉGIES TARIFAIRES par type serveur (Lot 1, incrément 6).
 *
 * Le prix est toujours recalculé côté serveur ; la stratégie est résolue depuis
 * le TYPE serveur, jamais depuis une valeur cliente. Conception garde son calcul
 * exact ; Localisation n'a aucune stratégie commerciale ; un profil tarifaire
 * fictif traverse le contrat générique sans une ligne de vocabulaire Conception.
 *
 * Réutilise le harnais tests/submissions. Données fictives. Décision : D-050.
 */

require __DIR__ . '/../submissions/bootstrap.php';

use Urbizen\Platform\Forms\ConceptionPricingStrategy;
use Urbizen\Platform\Forms\Pricing;
use Urbizen\Platform\Forms\PricingStrategy;
use Urbizen\Platform\Forms\PricingStrategyRegistry as R;

/** Stratégie fictive, réservée aux tests : socle et option distincts. */
final class StrategieFictive implements PricingStrategy {

	public function calculate( array $selection ): array {
		$total   = 100;
		$options = array();
		foreach ( $selection as $id ) {
			if ( 'piece_sup' === $id ) {
				$options[] = array( 'id' => 'piece_sup', 'price' => 50 );
				$total    += 50;
			}
		}
		return array( 'base' => 100, 'options' => $options, 'sur_devis' => array(), 'total' => $total, 'devis_requis' => false, 'ignores' => array() );
	}

	public function base(): int {
		return 100;
	}
}

// ======================================================================
// A · RÉSOLUTION DE LA STRATÉGIE DEPUIS LE TYPE SERVEUR
// ======================================================================
check( 'A · conception → ConceptionPricingStrategy', R::for_type( 'conception' ) instanceof ConceptionPricingStrategy );
check( 'A · le socle Conception est 449 €', 449 === R::for_type( 'conception' )->base() );
check( 'A · la stratégie Conception calcule comme Pricing', Pricing::compute( array( 'facades' ) ) === R::for_type( 'conception' )->calculate( array( 'facades' ) ) );
$sans = array();
foreach ( array( 'localisation', 'dp', 'pc', 'pcmi', 'cerfa', 'contact', 'inconnu' ) as $t ) {
	if ( null !== R::for_type( $t ) || R::has( $t ) ) {
		$sans[] = $t;
	}
}
check( 'A · aucune stratégie pour localisation/dp/pc/cerfa/… (null)', array() === $sans );

$leve = false;
try {
	R::require_for_type( 'dp' );
} catch ( \RuntimeException $e ) {
	$leve = true;
}
check( 'A · require_for_type(dp) → exception contrôlée', $leve );

// ======================================================================
// B · PRIX CLIENT — jamais une source de vérité
// ======================================================================
$conc = R::for_type( 'conception' );
// Des identifiants falsifiés ressemblant à un montant sont écartés, pas comptés.
$falsifie = $conc->calculate( array( 'facades', 'total', '449', 'price', 'amount', '-1', '999999' ) );
check( 'B · un « prix » glissé dans la sélection est ignoré', 598 === $falsifie['total'] );
check( 'B · seuls les vrais identifiants d’options comptent', array( 'facades' ) === array_column( $falsifie['options'], 'id' ) );
check( 'B · les faux identifiants sont nommés dans ignores', array( 'total', '449', 'price', 'amount', '-1', '999999' ) === $falsifie['ignores'] );

// ======================================================================
// C · OPTIONS — connues, inconnues, autre formulaire, formes hostiles
// ======================================================================
check( 'C · option connue comptée une fois', 598 === $conc->calculate( array( 'facades' ) )['total'] );
check( 'C · doublon compté une seule fois', 598 === $conc->calculate( array( 'facades', 'facades', 'facades' ) )['total'] );
check( 'C · casse différente rejetée', 449 === $conc->calculate( array( 'FACADES', 'Facades' ) )['total'] );
check( 'C · option d’un autre formulaire (piece_sup) rejetée par Conception', 449 === $conc->calculate( array( 'piece_sup' ) )['total'] );
check( 'C · unicode / chaîne longue rejetés', 449 === $conc->calculate( array( 'façades', str_repeat( 'a', 300 ) ) )['total'] );
check( 'C · une entrée non-scalaire (tableau) est ignorée sans erreur', 449 === $conc->calculate( array( array( 'facades' ) ) )['total'] );

// ======================================================================
// D · QUANTITÉS — Conception est tarifé à l'option, sans multiplicateur
// ======================================================================
// Le catalogue Conception n'a pas de quantité tarifée : chaque option est un
// forfait. On vérifie qu'aucune répétition ni valeur numérique ne fait varier
// le total au-delà du forfait unique.
check( 'D · répéter une option ne multiplie pas le prix', 598 === $conc->calculate( array_fill( 0, 10, 'facades' ) )['total'] );
check( 'D · une quantité numérique parasite est ignorée', 449 === $conc->calculate( array( '3', '-1', '0' ) )['total'] );

// ======================================================================
// E · LE CLIENT NE CHOISIT PAS LA STRATÉGIE (scan statique du code)
// ======================================================================
$code_seul = static function ( string $chemin ): string {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $chemin ) ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= $t[1];
		} else {
			$out .= $t;
		}
	}
	return $out;
};
$reg  = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Forms/PricingStrategyRegistry.php' );
$ctrl = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Http/SubmissionController.php' );
check( 'E · le registre ne lit aucune superglobale', ! str_contains( $reg, '$_POST' ) && ! str_contains( $reg, '$_GET' ) && ! str_contains( $reg, '$_REQUEST' ) );
check( 'E · le contrôleur ne lit ni pricing_strategy ni price/amount depuis $_POST',
	! str_contains( $ctrl, 'pricing_strategy' ) && ! str_contains( $ctrl, "\$post['price']" ) && ! str_contains( $ctrl, "\$post['amount']" ) );
check( 'E · for_type() est une fonction pure du type', R::for_type( 'conception' ) instanceof ConceptionPricingStrategy && null === R::for_type( 'dp' ) );

// ======================================================================
// §16 · STRATÉGIE FICTIVE — le contrat générique est réutilisable
// ======================================================================
$f = new StrategieFictive();
check( '16 · la stratégie fictive respecte le contrat', $f instanceof PricingStrategy && 100 === $f->base() );
check( '16 · elle calcule selon son propre catalogue', 150 === $f->calculate( array( 'piece_sup' ) )['total'] );
check( '16 · Conception ne bascule pas vers elle (registre = conception seul)', R::for_type( 'conception' ) instanceof ConceptionPricingStrategy && null === R::for_type( 'devis_fictif' ) );
check( '16 · l’option fictive n’est pas acceptée par Conception', 449 === $conc->calculate( array( 'piece_sup' ) )['total'] );
check( '16 · l’option Conception n’est pas comptée par la stratégie fictive', 100 === $f->calculate( array( 'facades' ) )['total'] );

$src_interface = $code_seul( URBIZEN_PLATFORM_DIR . 'src/Forms/PricingStrategy.php' );
$interdits     = array( 'conception', 'facades', 'pack_ftc', '449' );
$fuites        = array_filter( $interdits, static fn( $s ) => str_contains( strtolower( $src_interface ), $s ) );
check( '16 · l’interface générique PricingStrategy ne contient aucune chaîne Conception', array() === $fuites );

verdict();
