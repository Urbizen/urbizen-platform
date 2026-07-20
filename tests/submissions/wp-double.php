<?php
/**
 * Doublure de WordPress pour les bancs d'essai « soumissions ».
 *
 * Reproduit en mémoire ce dont le code a besoin : contenus, métadonnées,
 * options, transients, filtres, actions et tâches planifiées. Aucune base de
 * données, aucun réseau, aucun fichier écrit.
 *
 * L'horloge est **pilotable** : `wpd_avancer()` fait passer le temps sans
 * attendre. C'est indispensable pour éprouver l'expiration d'un jeton, la
 * fenêtre du limiteur et la rétention à 365 jours.
 *
 * La doublure est volontairement stricte là où le vrai WordPress l'est : un
 * transient expiré n'est pas renvoyé, `update_post_meta` renvoie faux si
 * l'écriture échoue, et `get_posts` honore réellement `meta_query`.
 */

// ---------------------------------------------------------------- horloge --
$GLOBALS['wpd_now'] = 1800000000; // Instant de référence fixe : aucun test ne dépend de la date réelle.

/**
 * Instant courant de la doublure.
 */
function wpd_now(): int {
	return $GLOBALS['wpd_now'];
}

/**
 * Fait avancer l'horloge.
 *
 * @param int $secondes Secondes à ajouter.
 */
function wpd_avancer( int $secondes ): void {
	$GLOBALS['wpd_now'] += $secondes;
}

/**
 * Remet la doublure à zéro entre deux scénarios.
 */
function wpd_reset(): void {
	$GLOBALS['wpd_posts']      = array();
	$GLOBALS['wpd_meta']       = array();
	$GLOBALS['wpd_options']    = array();
	$GLOBALS['wpd_autoload']   = array();
	$GLOBALS['wpd_transients'] = array();
	$GLOBALS['wpd_filters']    = array();
	$GLOBALS['wpd_actions']    = array();
	$GLOBALS['wpd_done']       = array();
	$GLOBALS['wpd_cron']       = array();
	$GLOBALS['wpd_cron_calls'] = array();
	$GLOBALS['wpd_redirects']  = array();
	$GLOBALS['wpd_logs']       = array();
	$GLOBALS['wpd_next_id']    = 1;
	$GLOBALS['wpd_insert_fail'] = false;
	$GLOBALS['wpd_meta_fail']  = '';
	$GLOBALS['wpd_post_types'] = array();
	$GLOBALS['wpd_can']        = true;
	$GLOBALS['wpd_referer']    = '';
	$GLOBALS['wpd_mails']      = array();
}

wpd_reset();

// -------------------------------------------------------------- constantes -
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

// ------------------------------------------------------- internationalisation
function __( $texte, $domaine = '' ) { return $texte; }
function esc_html__( $texte, $domaine = '' ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $texte ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $texte ) { return htmlspecialchars( (string) $texte, ENT_QUOTES, 'UTF-8' ); }

// ------------------------------------------------------------ filtres/actions
function add_filter( $hook, $callback, $priorite = 10, $args = 1 ) {
	$GLOBALS['wpd_filters'][ $hook ][] = $callback;
	return true;
}

function apply_filters( $hook, $valeur, ...$extra ) {
	foreach ( $GLOBALS['wpd_filters'][ $hook ] ?? array() as $callback ) {
		$valeur = $callback( $valeur, ...$extra );
	}
	return $valeur;
}

function add_action( $hook, $callback, $priorite = 10, $args = 1 ) {
	$GLOBALS['wpd_actions'][ $hook ][] = array( 'cb' => $callback, 'n' => (int) $args );
	return true;
}

/**
 * Déclenche une action, **fidèlement** à WordPress.
 *
 * Deux subtilités reproduites, parce qu'elles cassent du code réel :
 *
 * 1. `do_action()` sans argument transmet une chaîne vide — un rappel déclaré
 *    avec `?int $now = null` reçoit alors `''` et lève une TypeError ;
 * 2. le nombre d'arguments transmis est plafonné par `accepted_args`.
 */
function do_action( $hook, ...$args ) {
	$GLOBALS['wpd_done'][] = array( 'hook' => $hook, 'args' => $args );

	if ( array() === $args ) {
		$args = array( '' );
	}

	foreach ( $GLOBALS['wpd_actions'][ $hook ] ?? array() as $entree ) {
		$entree['cb']( ...array_slice( $args, 0, $entree['n'] ) );
	}
}

/**
 * Déclenche une action avec un tableau d'arguments, comme le fait wp-cron.
 *
 * Contrairement à `do_action()`, aucune chaîne vide n'est ajoutée.
 */
function do_action_ref_array( $hook, $args ) {
	$GLOBALS['wpd_done'][] = array( 'hook' => $hook, 'args' => $args );

	foreach ( $GLOBALS['wpd_actions'][ $hook ] ?? array() as $entree ) {
		$entree['cb']( ...array_slice( (array) $args, 0, $entree['n'] ) );
	}
}

function has_action( $hook ) {
	return ! empty( $GLOBALS['wpd_actions'][ $hook ] );
}

/**
 * Retire tous les filtres d'un hook (isolation entre scénarios).
 */
function wpd_clear_filter( string $hook ): void {
	unset( $GLOBALS['wpd_filters'][ $hook ] );
}

// ------------------------------------------------------------------ secrets -
function wp_salt( $scheme = 'auth' ) {
	// Sel fixe et manifestement factice : aucun secret réel n'entre ici.
	return 'sel-de-test-' . $scheme . '-0123456789abcdef';
}

// ------------------------------------------------------------------- nonces -
function wp_create_nonce( $action = -1 ) {
	return substr( hash_hmac( 'sha256', (string) $action, wp_salt( 'nonce' ) ), 0, 10 );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
}

// --------------------------------------------------------------- transients -
function set_transient( $cle, $valeur, $duree = 0 ) {
	$GLOBALS['wpd_transients'][ $cle ] = array(
		'valeur'  => $valeur,
		'expire'  => $duree > 0 ? wpd_now() + $duree : 0,
	);
	return true;
}

function get_transient( $cle ) {
	if ( ! isset( $GLOBALS['wpd_transients'][ $cle ] ) ) {
		return false;
	}

	$entree = $GLOBALS['wpd_transients'][ $cle ];

	if ( 0 !== $entree['expire'] && wpd_now() >= $entree['expire'] ) {
		unset( $GLOBALS['wpd_transients'][ $cle ] );
		return false;
	}

	return $entree['valeur'];
}

function delete_transient( $cle ) {
	unset( $GLOBALS['wpd_transients'][ $cle ] );
	return true;
}

// ----------------------------------------------------------------- options --
function get_option( $cle, $defaut = false ) {
	return array_key_exists( $cle, $GLOBALS['wpd_options'] ) ? $GLOBALS['wpd_options'][ $cle ] : $defaut;
}

function update_option( $cle, $valeur, $autoload = null ) {
	$GLOBALS['wpd_options'][ $cle ] = $valeur;

	if ( null !== $autoload ) {
		$GLOBALS['wpd_autoload'][ $cle ] = $autoload ? 'yes' : 'no';
	}

	return true;
}

/**
 * Ajout d'option, primitive **atomique**.
 *
 * Reproduit fidèlement le comportement de WordPress, qui s'appuie sur l'index
 * unique de `option_name` : si le nom existe déjà, l'ajout échoue et renvoie
 * faux. C'est cette propriété, et elle seule, qui départage deux requêtes
 * concurrentes.
 */
function add_option( $cle, $valeur = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $cle, $GLOBALS['wpd_options'] ) ) {
		return false;
	}

	$GLOBALS['wpd_options'][ $cle ]  = $valeur;
	$GLOBALS['wpd_autoload'][ $cle ] = ( false === $autoload || 'no' === $autoload ) ? 'no' : 'yes';

	return true;
}

function delete_option( $cle ) {
	unset( $GLOBALS['wpd_options'][ $cle ], $GLOBALS['wpd_autoload'][ $cle ] );
	return true;
}

/**
 * Valeur d'autoload d'une option, pour les bancs.
 */
function wpd_autoload( string $cle ): string {
	return $GLOBALS['wpd_autoload'][ $cle ] ?? 'absent';
}

/**
 * Vide le cache objet et les transients, sans toucher aux options.
 *
 * Reproduit ce qu'une purge LiteSpeed ou un `wp cache flush` fait réellement :
 * les transients peuvent disparaître, les options persistent en base.
 */
function wpd_purger_caches(): void {
	$GLOBALS['wpd_transients'] = array();
}

// ------------------------------------------------------------------ erreurs -
class WP_Error {
	public string $code;
	public string $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
	}
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $chose ) { return $chose instanceof WP_Error; }

// ----------------------------------------------------------------- contenus -
function register_post_type( $type, $args = array() ) {
	$GLOBALS['wpd_post_types'][ $type ] = $args;
	return $args;
}

function wpd_post_type_args( string $type ): array {
	return $GLOBALS['wpd_post_types'][ $type ] ?? array();
}

function wp_insert_post( $data, $wp_error = false ) {
	if ( ! empty( $GLOBALS['wpd_insert_fail'] ) ) {
		return $wp_error ? new WP_Error( 'insert_failed', 'échec simulé' ) : 0;
	}

	$id = $GLOBALS['wpd_next_id']++;

	$GLOBALS['wpd_posts'][ $id ] = (object) array(
		'ID'           => $id,
		'post_type'    => $data['post_type'] ?? 'post',
		'post_title'   => $data['post_title'] ?? '',
		'post_name'    => $data['post_name'] ?? '',
		'post_status'  => $data['post_status'] ?? 'draft',
		'post_content' => $data['post_content'] ?? '',
		'post_excerpt' => $data['post_excerpt'] ?? '',
	);

	$GLOBALS['wpd_meta'][ $id ] = array();

	return $id;
}

function get_post( $id ) {
	return $GLOBALS['wpd_posts'][ (int) $id ] ?? null;
}

function wp_delete_post( $id, $force = false ) {
	$id = (int) $id;
	unset( $GLOBALS['wpd_posts'][ $id ], $GLOBALS['wpd_meta'][ $id ] );
	return true;
}

// -------------------------------------------------------------- métadonnées -
function update_post_meta( $id, $cle, $valeur, $prev = '' ) {
	// Permet d'éprouver le retour arrière : une métadonnée désignée échoue.
	if ( '' !== $GLOBALS['wpd_meta_fail'] && $cle === $GLOBALS['wpd_meta_fail'] ) {
		return false;
	}

	$GLOBALS['wpd_meta'][ (int) $id ][ $cle ] = $valeur;
	return 123;
}

function get_post_meta( $id, $cle = '', $single = false ) {
	$metas = $GLOBALS['wpd_meta'][ (int) $id ] ?? array();

	if ( '' === $cle ) {
		$out = array();
		foreach ( $metas as $k => $v ) { $out[ $k ] = array( $v ); }
		return $out;
	}

	if ( ! array_key_exists( $cle, $metas ) ) {
		return $single ? '' : array();
	}

	return $single ? $metas[ $cle ] : array( $metas[ $cle ] );
}

function delete_post_meta( $id, $cle ) {
	unset( $GLOBALS['wpd_meta'][ (int) $id ][ $cle ] );
	return true;
}

// ------------------------------------------------------------- requête posts -
function get_posts( $args = array() ) {
	$type     = $args['post_type'] ?? 'post';
	$limite   = (int) ( $args['posts_per_page'] ?? 5 );
	$resultat = array();

	foreach ( $GLOBALS['wpd_posts'] as $id => $post ) {
		if ( $post->post_type !== $type ) {
			continue;
		}

		if ( isset( $args['meta_key'] ) ) {
			if ( ( $GLOBALS['wpd_meta'][ $id ][ $args['meta_key'] ] ?? null ) !== ( $args['meta_value'] ?? null ) ) {
				continue;
			}
		}

		if ( isset( $args['meta_query'] ) && ! wpd_meta_query_match( $id, $args['meta_query'] ) ) {
			continue;
		}

		$resultat[] = $id;

		if ( $limite > 0 && count( $resultat ) >= $limite ) {
			break;
		}
	}

	return $resultat;
}

/**
 * Évalue une meta_query simplifiée : relation AND, comparaisons IN et <.
 */
function wpd_meta_query_match( int $id, array $query ): bool {
	foreach ( $query as $cle => $clause ) {
		if ( 'relation' === $cle || ! is_array( $clause ) ) {
			continue;
		}

		$valeur  = $GLOBALS['wpd_meta'][ $id ][ $clause['key'] ] ?? null;
		$compare = strtoupper( $clause['compare'] ?? '=' );

		switch ( $compare ) {
			case 'IN':
				if ( ! in_array( $valeur, (array) $clause['value'], true ) ) { return false; }
				break;
			case '<':
				if ( null === $valeur || ! ( (string) $valeur < (string) $clause['value'] ) ) { return false; }
				break;
			default:
				if ( $valeur !== $clause['value'] ) { return false; }
		}
	}

	return true;
}

// -------------------------------------------------------------------- JSON --
function wp_json_encode( $donnees, $options = 0, $depth = 512 ) {
	return json_encode( $donnees, $options | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, $depth );
}

// ---------------------------------------------------------------- adresses --
function home_url( $chemin = '' ) { return 'https://exemple.test' . $chemin; }
function wp_parse_url( $url, $composant = -1 ) { return parse_url( $url, $composant ); }
function wp_unslash( $valeur ) { return $valeur; }
function wp_get_referer() { return '' !== $GLOBALS['wpd_referer'] ? $GLOBALS['wpd_referer'] : false; }

function add_query_arg( $args, $url = '' ) {
	$parts = parse_url( $url );
	parse_str( $parts['query'] ?? '', $existants );
	$final = array_merge( $existants, $args );
	$base  = ( $parts['scheme'] ?? '' ) ? $parts['scheme'] . '://' . $parts['host'] : '';
	$base .= $parts['path'] ?? '';
	return $base . ( $final ? '?' . http_build_query( $final ) : '' );
}

function remove_query_arg( $cles, $url = '' ) {
	$parts = parse_url( $url );
	parse_str( $parts['query'] ?? '', $existants );
	foreach ( (array) $cles as $c ) { unset( $existants[ $c ] ); }
	$base  = ( $parts['scheme'] ?? '' ) ? $parts['scheme'] . '://' . $parts['host'] : '';
	$base .= $parts['path'] ?? '';
	return $base . ( $existants ? '?' . http_build_query( $existants ) : '' );
}

function wp_safe_redirect( $url, $statut = 302 ) {
	$GLOBALS['wpd_redirects'][] = $url;
	return true;
}

function sanitize_title( $titre ) {
	return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $titre ) );
}

// ------------------------------------------------------------- capacités ----
function current_user_can( $capacite ) { return (bool) $GLOBALS['wpd_can']; }
function is_admin() { return false; }

// -------------------------------------------------------------------- cron --
function wp_next_scheduled( $hook, $args = array() ) {
	return $GLOBALS['wpd_cron'][ $hook ] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['wpd_cron'][ $hook ] = $timestamp;

	// Compte les appels réels : c'est ce qui distingue une programmation
	// idempotente d'une reprogrammation à chaque chargement.
	$GLOBALS['wpd_cron_calls'][ $hook ] = ( $GLOBALS['wpd_cron_calls'][ $hook ] ?? 0 ) + 1;

	return true;
}

function wp_unschedule_event( $timestamp, $hook, $args = array() ) {
	unset( $GLOBALS['wpd_cron'][ $hook ] );
	return true;
}

// ------------------------------------------------------------- courriels ----
/**
 * Doublure d'envoi de courriel.
 *
 * Elle n'envoie rien : elle compte. La PR B1 ne doit produire **aucun** appel,
 * et les bancs le vérifient plutôt que de le supposer.
 */
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	$GLOBALS['wpd_mails'][] = array( 'to' => $to, 'subject' => $subject );
	return true;
}

// ---------------------------------------------------------------- $wpdb -----
/**
 * Doublure minimale de $wpdb.
 *
 * Ne sert qu'à `OptionsScan` : retrouver des noms d'options par préfixe. Elle
 * n'implémente que ce que cette requête emploie.
 */
class WPDB_Double {
	public string $options = 'wp_options';

	public function esc_like( $texte ) {
		return addcslashes( (string) $texte, '_%\\' );
	}

	public function prepare( $requete, ...$args ) {
		foreach ( $args as $arg ) {
			$requete = preg_replace(
				'/%[sd]/',
				is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'",
				$requete,
				1
			);
		}
		return $requete;
	}

	public function get_col( $requete ) {
		if ( ! preg_match( "/option_name LIKE '([^']*)'/", $requete, $m ) ) {
			return array();
		}

		$prefixe = stripslashes( rtrim( $m[1], '%' ) );
		$limite  = preg_match( '/LIMIT (\d+)/', $requete, $l ) ? (int) $l[1] : 500;

		$noms = array_values(
			array_filter(
				array_keys( $GLOBALS['wpd_options'] ),
				static fn( $c ) => str_starts_with( (string) $c, $prefixe )
			)
		);

		return array_slice( $noms, 0, $limite );
	}
}

$GLOBALS['wpdb'] = new WPDB_Double();

// ------------------------------------------------------------- divers ------
function nocache_headers() {}
function status_header( $code ) { $GLOBALS['wpd_status'] = $code; }
function admin_url( $chemin = '' ) { return 'https://exemple.test/wp-admin/' . ltrim( $chemin, '/' ); }
function size_format( $octets, $decimales = 0 ) { return round( $octets / 1024 ) . ' KB'; }
function _n( $singulier, $pluriel, $nombre, $domaine = '' ) { return $nombre > 1 ? $pluriel : $singulier; }

/**
 * Contrôle croisé du type de fichier, doublure de WordPress.
 *
 * Reproduit le comportement réel : le type est déduit du **contenu**, jamais
 * de ce que déclare l'appelant, et l'extension proposée doit y correspondre.
 */
function wp_check_filetype_and_ext( $fichier, $nom, $mimes = null ) {
	$reel = \Urbizen\Platform\Files\UploadPolicy::detect_mime( $fichier );
	$ext  = strtolower( (string) pathinfo( $nom, PATHINFO_EXTENSION ) );

	$attendus = \Urbizen\Platform\Files\UploadPolicy::TYPES;

	if ( ! isset( $attendus[ $ext ] ) || $attendus[ $ext ] !== $reel ) {
		return array( 'ext' => false, 'type' => false, 'proper_filename' => false );
	}

	return array( 'ext' => $ext, 'type' => $reel, 'proper_filename' => false );
}
