<?php
/**
 * Banc : les actions `admin-post` du parcours des comptes.
 *
 * Ce que ce banc prouve, et ce qu'il ne prouve pas.
 *
 * Il éprouve les crochets déclarés, les shortcodes, le cycle complet du
 * limiteur et de l'anti-robot, l'uniformité des réponses et la liste fermée de
 * codes. Il **ne prouve pas** les en-têtes HTTP : `header()` est native, ne peut
 * pas être remplacée par une doublure, et reste sans effet en ligne de
 * commande. Leur vérification appartient au banc d'intégration.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp-double.php';
require __DIR__ . '/doublures.php';

require URBIZEN_SRC . 'Security/RateLimiter.php';
require URBIZEN_SRC . 'Security/AntiSpam.php';
require URBIZEN_SRC . 'Http/ComptesController.php';

use Urbizen\Platform\Http\ComptesController as CC;
use Urbizen\Platform\Security\AntiSpam;
use Urbizen\Platform\Security\RateLimiter;

/**
 * Exécute un gestionnaire et rend la sortie HTTP observée.
 *
 * @param callable $rappel Gestionnaire.
 * @return SortieHttp|null
 */
function sortie( callable $rappel ): ?SortieHttp {
	try {
		$rappel();
	} catch ( SortieHttp $sortie ) {
		return $sortie;
	}

	return null;
}

/**
 * Créneaux du limiteur actuellement réservés.
 *
 * @return int
 */
function creneaux_reserves(): int {
	$n = 0;

	foreach ( WpDouble::$options as $cle => $valeur ) {
		if ( 0 === strpos( (string) $cle, RateLimiter::OPTION_PREFIX ) && is_array( $valeur ) ) {
			$n++;
		}
	}

	return $n;
}

/**
 * Remet la doublure à zéro et rend un jeton anti-robot utilisable.
 *
 * @return string
 */
function preparer_requete(): string {
	WpDouble::reset();

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SERVER['REMOTE_ADDR']    = '203.0.113.7';

	$jeton = AntiSpam::issue_token( time() - AntiSpam::MIN_SECONDS - 1 );

	$_POST = array(
		'_urbizen_nonce' => 'nonce-valide',
		'urbizen_token'  => $jeton,
		'adresse'        => 'claire@exemple.fr',
		'motdepasse'     => 'motdepasse-long',
	);

	return $jeton;
}

$source = (string) file_get_contents(
	dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Http/ComptesController.php'
);

// ======================================================================
// 1 · LES CROCHETS — trois actions anonymes, deux de session
// ======================================================================
WpDouble::reset();
CC::register();

check( '1 · inscription : nopriv déclaré',
	isset( WpDouble::$actions['admin_post_nopriv_urbizen_inscription'] ) );
check( '1 · résultat : nopriv déclaré',
	isset( WpDouble::$actions['admin_post_nopriv_urbizen_resultat'] ) );
check( '1 · vérification : servie par son propre contrôleur, pas ici',
	! isset( WpDouble::$actions['admin_post_nopriv_urbizen_verification'] ) );

check( '1 · CHANGEMENT D\'ADRESSE : AUCUN nopriv',
	! isset( WpDouble::$actions['admin_post_nopriv_urbizen_changer_adresse'] )
	&& isset( WpDouble::$actions['admin_post_urbizen_changer_adresse'] ) );
check( '1 · RENVOI CONNECTÉ : AUCUN nopriv',
	! isset( WpDouble::$actions['admin_post_nopriv_urbizen_renvoi_connecte'] )
	&& isset( WpDouble::$actions['admin_post_urbizen_renvoi_connecte'] ) );

check( '1 · IL N\'EXISTE AUCUNE ACTION renvoi_public',
	! isset( WpDouble::$actions['admin_post_nopriv_urbizen_renvoi_public'] )
	&& ! isset( WpDouble::$actions['admin_post_urbizen_renvoi_public'] ) );
check( '1 · et le nom n\'apparaît nulle part dans la source',
	false === strpos( $source, 'renvoi_public' ) );

$anonymes = 0;

foreach ( array_keys( WpDouble::$actions ) as $crochet ) {
	if ( 0 === strpos( $crochet, 'admin_post_nopriv_' ) ) {
		$anonymes++;
	}
}

check( '1 · EXACTEMENT DEUX crochets anonymes ici (le troisième est la vérification)',
	2 === $anonymes );

// ======================================================================
// 2 · LES SHORTCODES — les deux publics visent LE MÊME endpoint
// ======================================================================
check( '2 · trois shortcodes enregistrés', 3 === count( WpDouble::$shortcodes ) );
check( '2 · aucun fichier de shortcodes séparé n\'existe',
	! file_exists( dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Shortcodes/ComptesShortcodes.php' ) );

$form_inscription = CC::rendre_formulaire_inscription();
$form_renvoi      = CC::rendre_formulaire_renvoi();

check( '2 · le formulaire d\'inscription poste vers urbizen_inscription',
	false !== strpos( $form_inscription, 'value="urbizen_inscription"' ) );
check( '2 · LE FORMULAIRE DE RENVOI POSTE VERS LA MÊME ACTION',
	false !== strpos( $form_renvoi, 'value="urbizen_inscription"' ) );
check( '2 · l\'inscription demande un mot de passe',
	false !== strpos( $form_inscription, 'name="motdepasse"' ) );
check( '2 · LE RENVOI N\'EN DEMANDE PAS',
	false === strpos( $form_renvoi, 'name="motdepasse"' ) );
check( '2 · les deux portent un jeton anti-robot',
	false !== strpos( $form_inscription, 'name="urbizen_token"' )
	&& false !== strpos( $form_renvoi, 'name="urbizen_token"' ) );

WpDouble::$connecte = false;
check( '2 · le changement d\'adresse NE REND RIEN hors session',
	'' === CC::rendre_formulaire_changement() );

WpDouble::$connecte = true;
check( '2 · et rend un formulaire en session',
	false !== strpos( CC::rendre_formulaire_changement(), 'urbizen_changer_adresse' ) );

// ======================================================================
// 3 · MÉTHODE — refus 405 avant tout effet
// ======================================================================
preparer_requete();
$_SERVER['REQUEST_METHOD'] = 'GET';

$s = sortie( array( CC::class, 'handle_inscription' ) );
check( '3 · GET sur l\'inscription : refusé', null !== $s && 'die' === $s->genre );
check( '3 · avec un 405', null !== $s && 405 === $s->statut );
check( '3 · AUCUN créneau consommé par une méthode refusée', 0 === creneaux_reserves() );

preparer_requete();
$_SERVER['REQUEST_METHOD'] = 'POST';
$s = sortie( array( CC::class, 'handle_resultat' ) );
check( '3 · POST SUR LA PAGE DE RÉSULTAT : REFUSÉ', null !== $s && 'die' === $s->genre );
check( '3 · avec un 405', null !== $s && 405 === $s->statut );

$_SERVER['REQUEST_METHOD'] = 'DELETE';
$s = sortie( array( CC::class, 'handle_resultat' ) );
check( '3 · toute autre méthode aussi', null !== $s && 'die' === $s->genre );

// ======================================================================
// 4 · ANTI-ROBOT — vérifier AVANT de réserver
// ======================================================================
check( '4 · verify_token EST APPELÉE, et avant reserve_token',
	false !== strpos( $source, 'AntiSpam::verify_token' )
	&& strpos( $source, 'AntiSpam::verify_token' ) < strpos( $source, 'AntiSpam::reserve_token' ) );

// Jeton falsifié.
preparer_requete();
$_POST['urbizen_token'] = 'jeton-fabrique-de-toutes-pieces';
$s = sortie( array( CC::class, 'handle_inscription' ) );

check( '4 · JETON FALSIFIÉ : réponse uniforme', null !== $s && 'redirect' === $s->genre );
check( '4 · et code uniforme', null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '4 · AUCUN créneau consommé', 0 === creneaux_reserves() );

// Jeton trop récent.
preparer_requete();
$_POST['urbizen_token'] = AntiSpam::issue_token( time() );
$s = sortie( array( CC::class, 'handle_inscription' ) );

check( '4 · JETON TROP RÉCENT : refusé', null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '4 · aucun créneau consommé', 0 === creneaux_reserves() );

// Jeton expiré.
preparer_requete();
$_POST['urbizen_token'] = AntiSpam::issue_token( time() - AntiSpam::MAX_AGE - 10 );
$s = sortie( array( CC::class, 'handle_inscription' ) );

check( '4 · JETON EXPIRÉ : refusé', null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '4 · aucun créneau consommé', 0 === creneaux_reserves() );

// Jeton déjà utilisé.
$jeton_double = preparer_requete();
AntiSpam::reserve_token( $jeton_double );
AntiSpam::consume_token( $jeton_double );
$s = sortie( array( CC::class, 'handle_inscription' ) );

check( '4 · JETON DÉJÀ UTILISÉ : refusé', null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '4 · aucun créneau consommé', 0 === creneaux_reserves() );

// ======================================================================
// 5 · NONCE
// ======================================================================
preparer_requete();
$_POST['_urbizen_nonce'] = 'nonce-faux';
$s = sortie( array( CC::class, 'handle_inscription' ) );

check( '5 · nonce invalide : réponse uniforme',
	null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '5 · aucun créneau consommé', 0 === creneaux_reserves() );

// ======================================================================
// 6 · CYCLE DU LIMITEUR — la frontière de coût
// ======================================================================
check( '6 · release_token est appelé AVANT la frontière',
	strpos( $source, 'AntiSpam::release_token' ) < strpos( $source, 'FRONTIÈRE DE COÛT' ) );
// On repart après le bloc de commentaire de la frontière : celui-ci NOMME
// `release_token()` pour l'interdire, et une recherche naïve s'y prendrait.
$apres_frontiere = substr( $source, (int) strpos( $source, '══ FRONTIÈRE DE COÛT ══' ) );
$apres_frontiere = substr( $apres_frontiere, (int) strpos( $apres_frontiere, '*/' ) );

check( '6 · et JAMAIS APPELÉ après la frontière',
	false === strpos( $apres_frontiere, 'AntiSpam::release_token(' ) );
check( '6 · RateLimiter::release n\'est jamais appelé',
	false === strpos( $source, 'RateLimiter::release' ) );

// L'imbrication du `finally` de finalisation n'est PAS éprouvée par une
// expression régulière : le cas 12 la fait échouer pour de vrai et observe
// l'état du créneau.

// Le limiteur refuse : le jeton est libéré, rien n'est consommé.
preparer_requete();
$reserves = array();

for ( $n = 0; $n < RateLimiter::max(); $n++ ) {
	$reserves[] = RateLimiter::reserve( CC::BUCKET, $_SERVER );
}

$avant = creneaux_reserves();
$s     = sortie( array( CC::class, 'handle_inscription' ) );

check( '6 · LIMITEUR SATURÉ : réponse uniforme',
	null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '6 · aucun créneau supplémentaire', $avant === creneaux_reserves() );
check( '6 · LE JETON A ÉTÉ LIBÉRÉ, il reste utilisable',
	! AntiSpam::is_used( $_POST['urbizen_token'] ) );

// ======================================================================
// 7 · AUCUNE REDIRECTION DANS LA ZONE PROTÉGÉE
// ======================================================================
foreach ( array( 'handle_inscription', 'handle_changement', 'handle_renvoi' ) as $methode ) {
	$debut = strpos( $source, 'function ' . $methode . '(' );
	$corps = substr( $source, (int) $debut, 4000 );
	$fin   = strpos( $corps, "\n	}\n" );
	$corps = false === $fin ? $corps : substr( $corps, 0, $fin );

	$pos_confirm  = strpos( $corps, 'RateLimiter::confirm' );
	$pos_redirect = strrpos( $corps, 'self::rediriger' );

	check( sprintf( '7 · %s : confirme le créneau AVANT de rediriger', $methode ),
		false !== $pos_confirm && false !== $pos_redirect && $pos_confirm < $pos_redirect );

	$zone = substr( $corps, (int) $pos_confirm );

	check( sprintf( '7 · %s : une seule redirection après le finally', $methode ),
		1 === substr_count( $zone, 'self::rediriger' ) );
}

// ======================================================================
// 8 · PAGE DE RÉSULTAT — liste fermée, aucun effet
// ======================================================================
check( '8 · sept codes, pas un de plus', 7 === count( CC::CODES ) );
check( '8 · le code uniforme en fait partie', in_array( CC::CODE_UNIFORME, CC::CODES, true ) );

check( '8 · la page de résultat ne consomme aucun jeton et n\'émet rien',
	false === strpos( $source, 'servir_resultat' )
	|| ( false === strpos( substr( $source, (int) strpos( $source, 'private static function servir_resultat' ) ), 'emettre' ) ) );

// ======================================================================
// 9 · L'ÉMISSION DÉJÀ PRÉPARÉE N'EST PAS REPRÉPARÉE
// ======================================================================
check( '9 · le contrôleur appelle emettre_prepare, pas emettre, à l\'inscription',
	false !== strpos( $source, 'emettre_prepare(' ) );

$bloc_inscription = substr(
	$source,
	(int) strpos( $source, 'function handle_inscription(' ),
	(int) strpos( $source, 'function handle_resultat(' ) - (int) strpos( $source, 'function handle_inscription(' )
);

check( '9 · et n\'appelle jamais emettre() nu dans ce bloc',
	1 !== preg_match( '/->emettre\s*\(\s*\$/', $bloc_inscription ) );

// ======================================================================
// 10 · AUCUNE POLITIQUE SUR LES ACTES ANONYMES
// ======================================================================
check( '10 · l\'inscription n\'appelle aucune politique',
	false === strpos( $bloc_inscription, 'autorisation()' ) );

$debut_session = (int) strpos( $source, 'function handle_changement(' );
$fin_session   = (int) strpos( $source, '// SHORTCODES' );
$bloc_session  = substr( $source, $debut_session, $fin_session - $debut_session );

check( '10 · le changement d\'adresse exige compte.modifier',
	false !== strpos( $bloc_session, "'compte.modifier'" ) );
check( '10 · le renvoi connecté exige verification.demander',
	false !== strpos( $bloc_session, "'verification.demander'" ) );
check( '10 · les deux exigent une session',
	2 === substr_count( $bloc_session, 'is_user_logged_in()' ) );

// ======================================================================
// 11 · PANNE DU LIMITEUR AVANT LA FRONTIÈRE — exécutée, pas déduite
// ======================================================================
$jeton_11 = preparer_requete();

// `add_option()` lève, mais UNIQUEMENT pour les créneaux du limiteur : la
// réservation du jeton anti-robot, elle, a bien eu lieu.
WpDouble::$lever_add_option = RateLimiter::OPTION_PREFIX;

$s = sortie( array( CC::class, 'handle_inscription' ) );

WpDouble::$lever_add_option = null;

check( '11 · EXCEPTION DU LIMITEUR : la réponse reste une redirection',
	null !== $s && 'redirect' === $s->genre );
check( '11 · en 303', null !== $s && 303 === $s->statut );
check( '11 · avec le code uniforme',
	null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '11 · AUCUN CRÉNEAU ne subsiste', 0 === creneaux_reserves() );
check( '11 · LE JETON A ÉTÉ RENDU UTILISABLE — release_token a bien agi',
	! AntiSpam::is_used( $jeton_11 ) );
check( '11 · et il est effectivement réservable à nouveau',
	true === AntiSpam::reserve_token( $jeton_11 ) );
check( '11 · aucun détail de l\'exception ne franchit la frontière publique',
	null !== $s && false === stripos( $s->url, 'stockage' )
	&& false === stripos( $s->url, 'exception' ) );

// ======================================================================
// 12 · PANNE DE consume_token() APRÈS LA FRONTIÈRE
// ======================================================================
$jeton_12 = preparer_requete();

/*
 * On laisse la requête franchir la frontière, puis `update_option()` lève —
 * uniquement pour les jetons anti-robot. `RateLimiter::confirm()` passe par
 * `update_option()` sur SON préfixe, qui reste intact : c'est ce qui permet
 * d'observer que la confirmation a bien eu lieu malgré l'échec.
 */
WpDouble::$lever_update_option = AntiSpam::OPTION_PREFIX;

$s = sortie( array( CC::class, 'handle_inscription' ) );

WpDouble::$lever_update_option = null;

check( '12 · L\'EXCEPTION EST ABSORBÉE : la redirection a bien lieu',
	null !== $s && 'redirect' === $s->genre );
check( '12 · en 303', null !== $s && 303 === $s->statut );
check( '12 · avec le code uniforme, comme toutes les autres issues',
	null !== $s && false !== strpos( $s->url, 'code=verifiez' ) );
check( '12 · aucun détail de l\'exception dans la réponse',
	null !== $s && false === stripos( $s->url, 'stockage' ) );

$creneaux_12 = array();

foreach ( WpDouble::$options as $cle => $valeur ) {
	if ( 0 === strpos( (string) $cle, RateLimiter::OPTION_PREFIX ) && is_array( $valeur ) ) {
		$creneaux_12[] = $valeur;
	}
}

check( '12 · un créneau existe bel et bien', 1 === count( $creneaux_12 ) );
check( '12 · RateLimiter::confirm S\'EST EXÉCUTÉ malgré l\'échec de consume_token',
	isset( $creneaux_12[0]['state'] ) && 'confirmed' === $creneaux_12[0]['state'] );
check( '12 · LE CRÉNEAU N\'EST PAS RESTÉ « reserved »',
	isset( $creneaux_12[0]['state'] ) && 'reserved' !== $creneaux_12[0]['state'] );
check( '12 · AUCUNE LIBÉRATION APRÈS LA FRONTIÈRE — le créneau n\'a pas disparu',
	1 === count( $creneaux_12 ) );

// La panne ne remonte pas non plus quand le métier a réussi avant elle.
check( '12 · la finalisation incomplète est journalisée sans détail',
	false !== strpos( \Urbizen\Platform\Support\Logger::tout(), 'finalisation incomplete' )
	&& false === strpos( \Urbizen\Platform\Support\Logger::tout(), 'stockage indisponible' ) );

// ======================================================================
// 13 · ANTI-ÉNUMÉRATION — la réponse ne dépend jamais de l'état du compte
// ======================================================================
// La comparaison OCTET des réponses réelles (adresse libre / prise non
// vérifiée / prise vérifiée / quota épuisé) appartient au banc d'intégration :
// `handle_inscription()` instancie `new WpComptes()` et `new WpdbGateway()` en
// dur, non substituables sans un WordPress réel. On éprouve ici, par la
// structure, l'invariant qui rend cette comparaison vraie : APRÈS la frontière
// de coût, il n'existe qu'UNE redirection, vers `CODE_UNIFORME`, et le résultat
// de l'inscription ne l'atteint jamais. Un mutant qui différencie une issue
// métier ajoute une seconde redirection dans cette zone.
$bloc_insc = substr(
	$source,
	(int) strpos( $source, 'function handle_inscription(' ),
	(int) strpos( $source, 'function handle_resultat(' ) - (int) strpos( $source, 'function handle_inscription(' )
);

$apres_frontiere_insc = substr( $bloc_insc, (int) strpos( $bloc_insc, '══ FRONTIÈRE DE COÛT ══' ) );

check( '13 · APRÈS la frontière, une SEULE redirection',
	1 === substr_count( $apres_frontiere_insc, 'self::rediriger(' ) );
check( '13 · et elle vise CODE_UNIFORME, littéralement',
	false !== strpos( $apres_frontiere_insc, 'self::rediriger( self::CODE_UNIFORME );' ) );
check( '13 · le résultat d\'inscription ne touche jamais la redirection',
	false === strpos( $apres_frontiere_insc, 'rediriger( $inscription' )
	&& 1 !== preg_match( '/rediriger\\([^)]*\\$inscription/', $apres_frontiere_insc ) );

verdict();
