<?php
/**
 * Banc : la page de vérification.
 *
 * Le point qui porte tout : **le GET n'agit pas**. Un lien qui vérifierait en
 * GET serait consommé par le premier antivirus de messagerie ou préchargeur
 * qui le suit, et le client recevrait un lien mort sans avoir rien fait.
 *
 * Comme pour l'autre contrôleur, les en-têtes eux-mêmes ne sont pas éprouvés
 * ici : `header()` est native et sans effet en ligne de commande. `nocache_headers()`
 * l'est, et la vérification complète appartient au banc d'intégration.
 */

declare( strict_types = 1 );

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/wp-double.php';
require __DIR__ . '/doublures.php';

require URBIZEN_SRC . 'Security/RateLimiter.php';
require URBIZEN_SRC . 'Security/AntiSpam.php';
require URBIZEN_SRC . 'Http/ComptesController.php';
require URBIZEN_SRC . 'Http/VerificationController.php';

use Urbizen\Platform\Account\JetonVerification;
use Urbizen\Platform\Account\LienVerification;
use Urbizen\Platform\Http\VerificationController as VC;

/**
 * Exécute un gestionnaire et rend la sortie HTTP observée.
 *
 * @param callable $rappel Gestionnaire.
 * @return SortieHttp|null
 */
function sortie_v( callable $rappel ): ?SortieHttp {
	try {
		$rappel();
	} catch ( SortieHttp $sortie ) {
		return $sortie;
	}

	return null;
}

/*
 * `header()` est native : en ligne de commande elle est sans effet et se plaint
 * que les en-têtes sont déjà partis. L'avertissement est un artefact du banc,
 * pas un défaut du code — il est tu, les erreurs réelles ne le sont pas.
 */
error_reporting( E_ALL & ~E_WARNING );

$source = (string) file_get_contents(
	dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/src/Http/VerificationController.php'
);

$gabarit = (string) file_get_contents(
	dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/templates/comptes/verification.php'
);

$css = (string) file_get_contents(
	dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/assets/css/urbizen-comptes.css'
);

// ======================================================================
// 1 · CROCHETS ET MÉTHODES
// ======================================================================
WpDouble::reset();
VC::register();

check( '1 · la vérification est anonyme', isset( WpDouble::$actions['admin_post_nopriv_urbizen_verification'] ) );
check( '1 · et déclarée aussi pour une session ouverte',
	isset( WpDouble::$actions['admin_post_urbizen_verification'] ) );

WpDouble::reset();
$_SERVER['REQUEST_METHOD'] = 'DELETE';
$_GET                      = array();

$s = sortie_v( array( VC::class, 'handle' ) );
check( '1 · une méthode non prévue est refusée', null !== $s && 'die' === $s->genre );
check( '1 · avec un 405', null !== $s && 405 === $s->statut );

// ======================================================================
// 2 · LE GET NE CONSOMME RIEN
// ======================================================================
check( '2 · le GET n\'appelle jamais consommer()',
	( static function () use ( $source ): bool {
		$debut = (int) strpos( $source, 'private static function afficher(' );
		$fin   = (int) strpos( $source, 'private static function consommer(' );

		return false === strpos( substr( $source, $debut, $fin - $debut ), '->consommer(' );
	} )() );

check( '2 · il n\'efface aucune métadonnée',
	( static function () use ( $source ): bool {
		$debut = (int) strpos( $source, 'private static function afficher(' );
		$fin   = (int) strpos( $source, 'private static function consommer(' );
		$bloc  = substr( $source, $debut, $fin - $debut );

		return false === strpos( $bloc, 'supprimer_meta' ) && false === strpos( $bloc, 'ecrire_meta' );
	} )() );

check( '2 · il ne lit la cible que pour l\'afficher',
	false !== strpos( $source, 'META_CIBLE' ) );

// ======================================================================
// 3 · LE GABARIT — jeton en champ caché, aucune ressource externe
// ======================================================================
check( '3 · le jeton est reposé en CHAMP CACHÉ',
	false !== strpos( $gabarit, 'type="hidden" name="t"' ) );
check( '3 · le nonce est posé', false !== strpos( $gabarit, 'wp_nonce_field' ) );
check( '3 · un seul bouton, en POST',
	1 === substr_count( $gabarit, '<form method="post"' ) );
check( '3 · AUCUNE RESSOURCE EXTERNE : aucun http(s):// sortant',
	1 !== preg_match( '#(src|href)\s*=\s*"https?://#', $gabarit ) );
check( '3 · aucun script', false === stripos( $gabarit, '<script' ) );
check( '3 · noindex par balise, en plus de l\'en-tête',
	false !== strpos( $gabarit, 'name="robots"' ) );
check( '3 · referrer no-referrer par balise aussi',
	false !== strpos( $gabarit, 'content="no-referrer"' ) );
check( '3 · il dit que la confirmation ne connecte pas',
	false !== strpos( $gabarit, 'ne vous connecte pas' ) );
check( '3 · la feuille de style est locale',
	false !== strpos( $gabarit, 'URBIZEN_PLATFORM_URL' ) );

check( '3 · le CSS n\'importe rien de distant',
	false === stripos( $css, '@import' ) && 1 !== preg_match( '#url\(\s*["\']?https?://#i', $css ) );

// ======================================================================
// 4 · AUCUNE CONNEXION APRÈS VÉRIFICATION
// ======================================================================
foreach ( array( 'wp_set_auth_cookie', 'wp_set_current_user', 'wp_signon', 'wp_login' ) as $interdit ) {
	check( sprintf( '4 · le contrôleur n\'appelle jamais %s()', $interdit ),
		false === strpos( $source, $interdit ) );
	check( sprintf( '4 · le gabarit non plus (%s)', $interdit ),
		false === strpos( $gabarit, $interdit ) );
}

// ======================================================================
// 5 · AUCUNE POLITIQUE — le jeton EST l'autorisation
// ======================================================================
/*
 * Commentaires neutralisés avant la recherche, comme le fait `test-compat.php` :
 * ce contrôleur EXPLIQUE pourquoi il n'emploie pas l'infrastructure, et une
 * recherche naïve trébucherait sur sa propre justification.
 */
$source_sans_commentaires = implode(
	'',
	array_map(
		static fn( $t ) => is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true )
			? ' '
			: ( is_array( $t ) ? $t[1] : $t ),
		token_get_all( $source )
	)
);

check( '5 · aucune infrastructure d\'autorisation dans ce contrôleur',
	1 !== preg_match(
		'/\bAuthorization\b|\bPolicyRegistry\b|\bActeurCourant\b|AutorisationComptes/',
		$source_sans_commentaires
	) );

// ======================================================================
// 6 · QUATRE ISSUES, PAS CINQ
// ======================================================================
$traduire = new ReflectionMethod( VC::class, 'code_public' );

check( '6 · succès', 'confirme' === $traduire->invoke( null, '' ) );
check( '6 · expiré', 'expire' === $traduire->invoke( null, 'jeton_expire' ) );
check( '6 · verrou indisponible → indisponible',
	'indisponible' === $traduire->invoke( null, 'verrou_indisponible' ) );
check( '6 · exception → indisponible', 'indisponible' === $traduire->invoke( null, 'exception' ) );

check( '6 · JETON INVALIDE ET DÉJÀ UTILISÉ RENDENT LE MÊME CODE',
	$traduire->invoke( null, 'jeton_invalide' ) === $traduire->invoke( null, 'jeton_absent' ) );
check( '6 · et ce code est « invalide »', 'invalide' === $traduire->invoke( null, 'jeton_invalide' ) );

$issues = array();

foreach ( array( '', 'jeton_expire', 'jeton_invalide', 'jeton_absent', 'verrou_indisponible', 'exception', 'nawak' ) as $motif ) {
	$issues[ $traduire->invoke( null, $motif ) ] = true;
}

check( '6 · EXACTEMENT QUATRE ISSUES PUBLIQUES', 4 === count( $issues ) );

// ======================================================================
// 7 · LA REDIRECTION NETTOIE L'URL
// ======================================================================
WpDouble::reset();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR']    = '203.0.113.9';
$jeton                     = str_repeat( 'ab', 32 );

$_POST = array(
	'_urbizen_nonce' => 'nonce-faux',
	'c'              => '42',
	't'              => $jeton,
);

$s = sortie_v( array( VC::class, 'handle' ) );

check( '7 · nonce invalide : redirection', null !== $s && 'redirect' === $s->genre );
check( '7 · EN 303', null !== $s && 303 === $s->statut );
check( '7 · L\'URL NE PORTE PAS LE JETON', null !== $s && false === strpos( $s->url, $jeton ) );
check( '7 · ni le paramètre qui le portait',
	null !== $s && false === strpos( $s->url, LienVerification::PARAM_JETON . '=' ) );
check( '7 · ni aucune adresse', null !== $s && false === strpos( $s->url, '@' ) );
check( '7 · elle mène à la page de résultat',
	null !== $s && false !== strpos( $s->url, 'action=urbizen_resultat' ) );

// Toute redirection de ce contrôleur nettoie : on le vérifie sur la source.
check( '7 · aucune redirection ne recopie le jeton',
	( static function () use ( $source ): bool {
		$debut = (int) strpos( $source, 'private static function rediriger(' );

		return false === strpos( substr( $source, $debut ), 'jeton' );
	} )() );

// ======================================================================
// 8 · AUCUN exit SOUS UN finally
// ======================================================================
$bloc_consommer = substr(
	$source,
	(int) strpos( $source, 'private static function consommer(' ),
	(int) strpos( $source, 'private static function code_public(' ) - (int) strpos( $source, 'private static function consommer(' )
);

$pos_confirm  = strpos( $bloc_consommer, 'RateLimiter::confirm' );
$pos_redirect = strrpos( $bloc_consommer, 'self::rediriger' );

check( '8 · le créneau est confirmé AVANT toute redirection',
	false !== $pos_confirm && false !== $pos_redirect && $pos_confirm < $pos_redirect );
check( '8 · une seule redirection après le finally',
	1 === substr_count( substr( $bloc_consommer, (int) $pos_confirm ), 'self::rediriger' ) );

// ======================================================================
// 9 · LE JETON NE VA JAMAIS AU JOURNAL
// ======================================================================
check( '9 · le contrôleur ne journalise rien du tout',
	false === strpos( $source, 'Logger::' ) );
check( '9 · le gabarit non plus', false === strpos( $gabarit, 'Logger::' ) );

verdict();
