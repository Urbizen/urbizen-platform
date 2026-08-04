<?php
/**
 * Banc d'essai du registre des formulaires — liste blanche sécurisée.
 *
 * Fige le comportement de FormRegistry avant la généralisation du routage
 * (Lot 1, incrément suivant) : seuls des types explicitement enregistrés sont
 * résolus, et AUCUNE valeur ressemblant à un chemin, une classe, un callable
 * ou une URL ne peut provoquer un chargement arbitraire. Décision : D-050.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\FormRegistry;

FormRegistry::reset_for_tests();

// ======================================================================
// A · TYPES LIVRÉS AVEC LE SOCLE
// ======================================================================
check( 'A · localisation est dans la liste blanche', FormRegistry::has( 'localisation' ) );
check( 'A · conception est dans la liste blanche', FormRegistry::has( 'conception' ) );

$loc  = FormRegistry::get( 'localisation' );
$conc = FormRegistry::get( 'conception' );
check( 'A · la définition localisation se charge', $loc instanceof FormDefinition && array() === $loc->errors() );
check( 'A · la définition conception se charge', $conc instanceof FormDefinition && array() === $conc->errors() );
check( 'A · default_type() reste « localisation »', 'localisation' === FormRegistry::default_type() );
// La liste blanche s'étend avec la déclaration préalable. L'assertion reste
// exacte — et non « contient » — pour qu'un type ajouté par mégarde se voie.
check( 'A · all() = exactement les trois formulaires livrés', array( 'localisation', 'conception', 'declaration_prealable' ) === FormRegistry::all() );
check( 'A · KNOWN = inventaire déclaré', array( 'localisation', 'conception', 'declaration_prealable' ) === FormRegistry::KNOWN );
check( 'A · la définition declaration_prealable se charge', null !== FormRegistry::get( 'declaration_prealable' ) );
check( 'A · et elle est valide', FormRegistry::get( 'declaration_prealable' )->is_valid() );

// ======================================================================
// B · TYPE INCONNU
// ======================================================================
check( 'B · un type inconnu n’est pas dans la liste blanche', ! FormRegistry::has( 'inconnu' ) );
check( 'B · get() d’un type inconnu renvoie null', null === FormRegistry::get( 'inconnu' ) );
check( 'B · get() d’un type inconnu ne charge aucune définition', null === FormRegistry::get( 'dp' ) );

// ======================================================================
// C · IDENTIFIANTS INVALIDES (rejetés, jamais réécrits)
// ======================================================================
$invalides = array(
	'vide'                 => '',
	'espace seul'          => ' ',
	'espaces internes'     => 'form type',
	'majuscule'            => 'Conception',
	'unicode'              => 'concéption',
	'slash'                => 'a/b',
	'antislash'            => 'a\\b',
	'traversee'            => '../conception',
	'nom de fichier php'   => 'conception.php',
	'nom de classe'        => 'FormRegistry',
	'espace de noms'       => 'Urbizen\\Platform\\Forms\\FormRegistry',
	'url'                  => 'http://x/y',
	'chemin absolu'        => '/etc/passwd',
	'commence par chiffre' => '0abc',
	'point'                => '.',
	'trop long'            => str_repeat( 'a', 65 ),
	'octet nul'            => "a\0b",
);

$echec_has      = array();
$echec_get      = array();
$echec_register = array();
foreach ( $invalides as $nom => $id ) {
	if ( FormRegistry::has( $id ) ) {
		$echec_has[] = $nom;
	}
	if ( null !== FormRegistry::get( $id ) ) {
		$echec_get[] = $nom;
	}
	if ( false !== FormRegistry::register( $id ) ) {
		$echec_register[] = $nom;
	}
}
check( 'C · tout identifiant invalide est absent de la liste blanche', array() === $echec_has );
check( 'C · get() sur identifiant invalide renvoie null (aucune inclusion)', array() === $echec_get );
check( 'C · register() refuse tout identifiant invalide', array() === $echec_register );
check( 'C · la liste blanche est intacte après les tentatives', array( 'localisation', 'conception', 'declaration_prealable' ) === FormRegistry::all() );

// ======================================================================
// D · DOUBLONS (aucun écrasement silencieux)
// ======================================================================
check( 'D · réenregistrer « conception » est refusé', false === FormRegistry::register( 'conception' ) );
check( 'D · la liste reste inchangée après le doublon', array( 'localisation', 'conception', 'declaration_prealable' ) === FormRegistry::all() );

check( 'D · enregistrer un nouveau type valide réussit', true === FormRegistry::register( 'devis_test' ) );
check( 'D · le nouveau type est désormais connu', FormRegistry::has( 'devis_test' ) );
check( 'D · réenregistrer le même type est refusé', false === FormRegistry::register( 'devis_test' ) );
check( 'D · le nouveau type n’apparaît qu’une seule fois', 1 === count( array_keys( FormRegistry::all(), 'devis_test', true ) ) );

// ======================================================================
// E · SÉCURITÉ : enregistrement ≠ chargement/exécution arbitraire
// ======================================================================
// Un type enregistré ne charge une définition que si le FICHIER existe dans
// definitions/ (contrôlé par le dépôt) : « devis_test » est enregistré mais
// sans fichier → get() reste null, aucune inclusion arbitraire.
check( 'E · un type enregistré sans fichier de définition ne charge rien', null === FormRegistry::get( 'devis_test' ) );

// Toute valeur ressemblant à un chemin, une classe ou une URL est de SYNTAXE
// invalide → refusée d'emblée (jamais résolue en fichier).
$attaques = array( '/etc/passwd', 'FormRegistry', 'http://x', '../Renderer', 'localisation/../localisation', 'localisation.php', 'Urbizen\\Platform\\Forms\\FormRegistry' );
$fuite    = array();
foreach ( $attaques as $id ) {
	if ( FormRegistry::has( $id ) || null !== FormRegistry::get( $id ) || false !== FormRegistry::register( $id ) ) {
		$fuite[] = $id;
	}
}
check( 'E · aucune valeur type-chemin/classe/URL n’est acceptée ni résolue', array() === $fuite );

// Un identifiant syntaxiquement valide qui RESSEMBLE à un callable natif n'est
// jamais exécuté : il ne pointe que vers definitions/<id>.php (absent ici).
check(
	'E · un id « callable-like » (strtolower) est un simple identifiant, jamais invoqué',
	true === FormRegistry::register( 'strtolower' ) && null === FormRegistry::get( 'strtolower' )
);

// ======================================================================
// F · COMPATIBILITÉ APRÈS TOUTES LES TENTATIVES
// ======================================================================
FormRegistry::reset_for_tests();
check( 'F · localisation reste résolue comme avant', FormRegistry::get( 'localisation' ) instanceof FormDefinition );
check( 'F · conception reste résolue comme avant', FormRegistry::get( 'conception' ) instanceof FormDefinition );
check( 'F · all() de nouveau = les trois formulaires livrés', array( 'localisation', 'conception', 'declaration_prealable' ) === FormRegistry::all() );
check( 'F · default_type() = localisation', 'localisation' === FormRegistry::default_type() );

verdict();
