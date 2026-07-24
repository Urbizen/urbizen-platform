<?php
/**
 * Doublure minimale de WordPress, pour les bancs de contrôleurs.
 *
 * Elle ne simule que ce que le parcours des comptes appelle réellement :
 * crochets, shortcodes, nonces, options, redirections et en-têtes. Chaque geste
 * est **enregistré** plutôt qu'exécuté, de sorte qu'un banc puisse affirmer non
 * seulement ce qui a été rendu, mais ce qui a été appelé et dans quel ordre.
 *
 * `wp_safe_redirect()` et `wp_die()` lancent une exception : sans cela, le code
 * poursuivrait après une redirection, ce qu'il ne fait jamais en production.
 *
 * @package Urbizen\Tests
 */

declare( strict_types = 1 );

/**
 * Interruption : redirection ou arrêt.
 */
final class SortieHttp extends RuntimeException {

	public string $genre;

	public string $url = '';

	public int $statut = 0;

	public function __construct( string $genre, string $url = '', int $statut = 0 ) {
		parent::__construct( $genre );

		$this->genre  = $genre;
		$this->url    = $url;
		$this->statut = $statut;
	}
}

/**
 * État observable de la doublure.
 */
final class WpDouble {

	/**
	 * @var array<string, array<int, callable>>
	 */
	public static array $actions = array();

	/**
	 * @var array<string, callable>
	 */
	public static array $shortcodes = array();

	/**
	 * @var array<string, mixed>
	 */
	public static array $options = array();

	/**
	 * @var array<int, string>
	 */
	public static array $entetes = array();

	/**
	 * @var array<int, int>
	 */
	public static array $statuts = array();

	/**
	 * Sortie rendue par le dernier gabarit inclus.
	 */
	public static string $rendu = '';

	public static bool $connecte = false;

	public static int $utilisateur = 0;

	/**
	 * Nonces réputés valides.
	 *
	 * @var array<int, string>
	 */
	public static array $nonces_valides = array();

	/**
	 * Préfixe d'option pour lequel `add_option()` doit LEVER.
	 *
	 * Une panne de stockage ne se simule pas par une valeur de retour : elle
	 * lève. Ciblée par préfixe, elle permet d'éprouver l'échec d'un seul
	 * mécanisme sans casser les autres.
	 */
	public static ?string $lever_add_option = null;

	/**
	 * Préfixe d'option pour lequel `update_option()` doit LEVER.
	 */
	public static ?string $lever_update_option = null;

	public static function reset(): void {
		self::$lever_add_option    = null;
		self::$lever_update_option = null;
		self::$actions        = array();
		self::$shortcodes     = array();
		self::$options        = array();
		self::$entetes        = array();
		self::$statuts        = array();
		self::$rendu          = '';
		self::$connecte       = false;
		self::$utilisateur    = 0;
		self::$nonces_valides = array( 'nonce-valide' );
	}
}

WpDouble::reset();

defined( 'URBIZEN_PLATFORM_DIR' ) || define( 'URBIZEN_PLATFORM_DIR', dirname( __DIR__, 2 ) . '/wordpress/urbizen-platform/' );
defined( 'URBIZEN_PLATFORM_URL' ) || define( 'URBIZEN_PLATFORM_URL', 'https://exemple.fr/wp-content/plugins/urbizen-platform/' );
defined( 'URBIZEN_PLATFORM_VERSION' ) || define( 'URBIZEN_PLATFORM_VERSION', '0.11.0' );

// ----------------------------------------------------------------------
// Crochets et shortcodes
// ----------------------------------------------------------------------

function add_action( string $crochet, $rappel, int $priorite = 10, int $args = 1 ): bool {
	WpDouble::$actions[ $crochet ][] = $rappel;

	return true;
}

function add_shortcode( string $nom, $rappel ): void {
	WpDouble::$shortcodes[ $nom ] = $rappel;
}

function add_filter( string $crochet, $rappel, int $priorite = 10, int $args = 1 ): bool {
	return true;
}

// ----------------------------------------------------------------------
// Options — support de RateLimiter et AntiSpam
// ----------------------------------------------------------------------

function get_option( string $cle, $defaut = false ) {
	return array_key_exists( $cle, WpDouble::$options ) ? WpDouble::$options[ $cle ] : $defaut;
}

function add_option( string $cle, $valeur, string $deprecie = '', $autoload = 'yes' ): bool {
	if ( null !== WpDouble::$lever_add_option && 0 === strpos( $cle, WpDouble::$lever_add_option ) ) {
		throw new RuntimeException( 'stockage indisponible : ' . $cle );
	}

	if ( array_key_exists( $cle, WpDouble::$options ) ) {
		return false;
	}

	WpDouble::$options[ $cle ] = $valeur;

	return true;
}

function update_option( string $cle, $valeur, $autoload = null ): bool {
	if ( null !== WpDouble::$lever_update_option && 0 === strpos( $cle, WpDouble::$lever_update_option ) ) {
		throw new RuntimeException( 'stockage indisponible : ' . $cle );
	}

	WpDouble::$options[ $cle ] = $valeur;

	return true;
}

function delete_option( string $cle ): bool {
	unset( WpDouble::$options[ $cle ] );

	return true;
}

// ----------------------------------------------------------------------
// Session, nonces, échappement
// ----------------------------------------------------------------------

function is_user_logged_in(): bool {
	return WpDouble::$connecte;
}

function get_current_user_id(): int {
	return WpDouble::$utilisateur;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return in_array( (string) $nonce, WpDouble::$nonces_valides, true ) ? 1 : false;
}

function wp_create_nonce( $action = -1 ): string {
	return 'nonce-valide';
}

function wp_nonce_field( $action = -1, string $nom = '_wpnonce', bool $referer = true, bool $afficher = true ) {
	$champ = '<input type="hidden" name="' . $nom . '" value="nonce-valide">';

	if ( $afficher ) {
		echo $champ; // phpcs:ignore

		return '';
	}

	return $champ;
}

function wp_unslash( $valeur ) {
	return $valeur;
}

function esc_attr( string $v ): string {
	return htmlspecialchars( $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_html( string $v ): string {
	return htmlspecialchars( $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_url( string $v ): string {
	return htmlspecialchars( $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_html__( string $v, string $domaine = '' ): string {
	return esc_html( $v );
}

function __( string $v, string $domaine = '' ): string {
	return $v;
}

function esc_html_e( string $v, string $domaine = '' ): void {
	echo esc_html( $v ); // phpcs:ignore
}

function sanitize_text_field( string $v ): string {
	return trim( (string) preg_replace( '/[\r\n\t\x00]/', '', $v ) );
}

function is_email( string $v ) {
	return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) ? $v : false;
}

// ----------------------------------------------------------------------
// URL et sortie HTTP
// ----------------------------------------------------------------------

function admin_url( string $chemin = '' ): string {
	return 'https://exemple.fr/wp-admin/' . ltrim( $chemin, '/' );
}

function home_url( string $chemin = '/' ): string {
	return 'https://exemple.fr' . $chemin;
}

function get_bloginfo( string $quoi = 'name' ): string {
	return 'charset' === $quoi ? 'UTF-8' : 'Urbizen';
}

function bloginfo( string $quoi = 'name' ): void {
	echo get_bloginfo( $quoi ); // phpcs:ignore
}

function language_attributes(): void {
	echo 'lang="fr"'; // phpcs:ignore
}

function add_query_arg( array $args, string $url ): string {
	$separateur = ( false === strpos( $url, '?' ) ) ? '?' : '&';

	return $url . $separateur . http_build_query( $args );
}

function nocache_headers(): void {
	WpDouble::$entetes[] = 'Cache-Control: no-cache, must-revalidate, max-age=0';
}

function status_header( int $code ): void {
	WpDouble::$statuts[] = $code;
}

function wp_safe_redirect( string $url, int $statut = 302 ): void {
	throw new SortieHttp( 'redirect', $url, $statut );
}

function wp_die( $message = '', $titre = '', $args = array() ): void {
	throw new SortieHttp( 'die', '', (int) ( is_array( $args ) ? ( $args['response'] ?? 0 ) : 0 ) );
}

/**
 * Sel de test.
 *
 * En production, `wp_salt()` lit `wp-config.php`, hors dépôt : aucun secret
 * n'est versionné. Ici, une valeur fixe et non secrète suffit — les bancs
 * n'éprouvent pas la qualité du sel, mais le comportement qu'il permet.
 */
function wp_salt( string $portee = 'auth' ): string {
	return 'sel-de-banc-' . $portee;
}

function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min, 0 === $max ? PHP_INT_MAX : $max );
}

function apply_filters( string $crochet, $valeur ) {
	return $valeur;
}

function do_action( string $crochet, ...$args ): void {
}
