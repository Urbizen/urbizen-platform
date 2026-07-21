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
	$GLOBALS['wpd_meta_lie']   = '';
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
	$GLOBALS['wpd_trash_fail']   = false;
	$GLOBALS['wpd_untrash_fail'] = false;
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
	$GLOBALS['wpd_filters'][ $hook ][] = array(
		'cb'   => $callback,
		'n'    => (int) $args,
		'p'    => (int) $priorite,
		// L'ordre d'inscription départage deux priorités égales, comme dans
		// WordPress.
		'rang' => count( $GLOBALS['wpd_filters'][ $hook ] ?? array() ),
	);

	return true;
}

/**
 * Trie des rappels par priorité, puis par ordre d'inscription.
 *
 * WordPress exécute les filtres dans l'ordre des priorités, pas dans celui des
 * appels à `add_filter()`. Ignorer ce tri masquerait tout conflit d'ordre entre
 * greffons — précisément ce qu'on cherche à éprouver.
 */
function wpd_trier( array $entrees ): array {
	usort(
		$entrees,
		static function ( $a, $b ) {
			return ( $a['p'] ?? 10 ) <=> ( $b['p'] ?? 10 ) ?: ( $a['rang'] ?? 0 ) <=> ( $b['rang'] ?? 0 );
		}
	);

	return $entrees;
}

/**
 * Applique un filtre, **fidèlement** à WordPress.
 *
 * Le nombre d'arguments transmis est plafonné par `accepted_args`. Déclarer
 * trop peu d'arguments est une erreur classique — le rappel reçoit alors moins
 * que prévu, et échoue ou travaille sur des valeurs manquantes.
 */
function apply_filters( $hook, $valeur, ...$extra ) {
	$tous = array_merge( array( $valeur ), $extra );

	foreach ( wpd_trier( $GLOBALS['wpd_filters'][ $hook ] ?? array() ) as $entree ) {
		$args    = array_slice( $tous, 0, max( 1, $entree['n'] ) );
		$valeur  = $entree['cb']( ...$args );
		$tous[0] = $valeur;
	}

	return $valeur;
}

function add_action( $hook, $callback, $priorite = 10, $args = 1 ) {
	$GLOBALS['wpd_actions'][ $hook ][] = array(
		'cb'   => $callback,
		'n'    => (int) $args,
		'p'    => (int) $priorite,
		'rang' => count( $GLOBALS['wpd_actions'][ $hook ] ?? array() ),
	);

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

	foreach ( wpd_trier( $GLOBALS['wpd_actions'][ $hook ] ?? array() ) as $entree ) {
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

	foreach ( wpd_trier( $GLOBALS['wpd_actions'][ $hook ] ?? array() ) as $entree ) {
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
	// Même ambiguïté que `update_post_meta()` : le cœur rend `false` lorsque la
	// valeur enregistrée est déjà la bonne. Aucun appel de production ne lit ce
	// retour aujourd'hui ; la doublure reste néanmoins fidèle, pour qu'un futur
	// usage fautif tombe ici plutôt qu'en production.
	$inchangee = array_key_exists( $cle, $GLOBALS['wpd_options'] )
		&& $GLOBALS['wpd_options'][ $cle ] === $valeur;

	$GLOBALS['wpd_options'][ $cle ] = $valeur;

	if ( null !== $autoload ) {
		$GLOBALS['wpd_autoload'][ $cle ] = $autoload ? 'yes' : 'no';
	}

	return ! $inchangee;
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

/**
 * Mise à jour d'un contenu.
 *
 * Rend l'identifiant en cas de succès, `0` sinon — comme le cœur.
 */
function wp_update_post( $data, $wp_error = false ) {
	$id   = (int) ( $data['ID'] ?? 0 );
	$post = get_post( $id );

	if ( ! $post ) {
		return $wp_error ? new WP_Error( 'invalid_post', 'contenu introuvable' ) : 0;
	}

	if ( ! empty( $GLOBALS['wpd_update_fail'] ) ) {
		return $wp_error ? new WP_Error( 'update_failed', 'échec simulé' ) : 0;
	}

	foreach ( $data as $cle => $valeur ) {
		if ( 'ID' !== $cle ) {
			$post->$cle = $valeur;
		}
	}

	return $id;
}

/**
 * Appelle `wp_update_post()` en forçant l'échec si le drapeau nommé est levé.
 *
 * L'échec se produit **au niveau de `wp_update_post()`**, donc après tout ce
 * que le cœur a déjà écrit ou effacé.
 */
function wpd_update_post_ou_echec( array $data, string $drapeau ) {
	$anterieur = $GLOBALS['wpd_update_fail'] ?? false;

	if ( ! empty( $GLOBALS[ $drapeau ] ) ) {
		$GLOBALS['wpd_update_fail'] = true;
	}

	$resultat = wp_update_post( $data );

	$GLOBALS['wpd_update_fail'] = $anterieur;

	return $resultat;
}

/**
 * Ajout d'une métadonnée. Sans unicité demandée, le cœur **ajoute** une valeur
 * supplémentaire ; `get_post_meta( …, true )` rend alors la première.
 */
function add_post_meta( $id, $cle, $valeur, $unique = false ) {
	$id = (int) $id;

	if ( ! isset( $GLOBALS['wpd_meta'][ $id ] ) ) {
		$GLOBALS['wpd_meta'][ $id ] = array();
	}

	if ( $unique && array_key_exists( $cle, $GLOBALS['wpd_meta'][ $id ] ) ) {
		return false;
	}

	if ( ! array_key_exists( $cle, $GLOBALS['wpd_meta'][ $id ] ) ) {
		$GLOBALS['wpd_meta'][ $id ][ $cle ] = $valeur;
	}

	return true;
}

function get_post_status( $id ) {
	$post = get_post( $id );

	return $post ? (string) $post->post_status : false;
}

function get_post( $id ) {
	return $GLOBALS['wpd_posts'][ (int) $id ] ?? null;
}

/**
 * Suppression définitive, **fidèle** au cœur de WordPress.
 *
 * `pre_delete_post` est consulté d'abord : un `false` empêche la suppression.
 * Sans cette fidélité, un banc croirait qu'un contenu a été supprimé alors
 * qu'un filtre l'a bloqué.
 */
function wp_delete_post( $id, $force = false ) {
	$id   = (int) $id;
	$post = get_post( $id );

	if ( ! $post ) {
		return false;
	}

	// Le cœur court-circuite dès que la valeur n'est plus `null` — et il rend
	// cette valeur telle quelle. Ne réagir qu'à `false` laisserait passer un
	// greffon qui bloque avec n'importe quelle autre valeur.
	$court = apply_filters( 'pre_delete_post', null, $post, (bool) $force );

	if ( null !== $court ) {
		return $court;
	}

	unset( $GLOBALS['wpd_posts'][ $id ], $GLOBALS['wpd_meta'][ $id ] );

	return $post;
}

/**
 * Mise à la Corbeille, fidèle au cœur de WordPress.
 *
 * `pre_trash_post` reçoit trois arguments et peut empêcher l'opération. Le
 * statut précédent est mémorisé dans `_wp_trash_meta_status`, comme le fait
 * WordPress, afin que la restauration le rétablisse.
 */
function wp_trash_post( $id ) {
	$id   = (int) $id;
	$post = get_post( $id );

	if ( ! $post ) {
		return $post;
	}

	if ( 'trash' === $post->post_status ) {
		return false;
	}

	$precedent = (string) $post->post_status;
	$court     = apply_filters( 'pre_trash_post', null, $post, $precedent );

	if ( null !== $court ) {
		return $court;
	}

	do_action( 'wp_trash_post', $id, $precedent );

	// `add_post_meta`, et non `update_post_meta` : le cœur ajoute. Une seconde
	// tentative après un échec crée donc une seconde valeur, et c'est la
	// **première** que `get_post_meta( …, true )` rendra.
	add_post_meta( $id, '_wp_trash_meta_status', $precedent );
	add_post_meta( $id, '_wp_trash_meta_time', wpd_now() );

	// L'écriture native vient **après** les métadonnées : si elle échoue,
	// celles-ci subsistent, et le post garde son statut d'origine.
	$post_updated = wpd_update_post_ou_echec(
		array(
			'ID'          => $id,
			'post_status' => 'trash',
		),
		'wpd_trash_fail'
	);

	if ( ! $post_updated ) {
		return false;
	}

	do_action( 'trashed_post', $id, $precedent );

	return $post;
}

/**
 * Restauration depuis la Corbeille, fidèle à WordPress **moderne**.
 *
 * Depuis WordPress 5.6, un contenu non joint restauré ne retrouve **pas** son
 * ancien statut : il repart en `draft`, sauf si le filtre
 * `wp_untrash_post_status` en décide autrement. Reproduire l'ancien
 * comportement — restaurer implicitement le statut d'avant — masquerait
 * précisément le défaut que ce filtre existe pour corriger.
 */
function wp_untrash_post( $id ) {
	$id   = (int) $id;
	$post = get_post( $id );

	if ( ! $post ) {
		return $post;
	}

	if ( 'trash' !== $post->post_status ) {
		return false;
	}

	$precedent = (string) get_post_meta( $id, '_wp_trash_meta_status', true );
	$court     = apply_filters( 'pre_untrash_post', null, $post, $precedent );

	if ( null !== $court ) {
		return $court;
	}

	do_action( 'untrash_post', $id, $precedent );

	// Le défaut du cœur : `draft` pour tout contenu non joint, quel que soit
	// le statut d'origine.
	$propose = 'attachment' === (string) $post->post_type ? 'inherit' : 'draft';
	$nouveau = apply_filters( 'wp_untrash_post_status', $propose, $id, $precedent );

	// **Point décisif.** Le cœur efface les métadonnées natives de Corbeille
	// AVANT l'écriture. Si celle-ci échoue, elles sont perdues : le post reste
	// à la Corbeille, mais plus rien n'indique d'où il venait. Les conserver
	// ici rendrait possible une reprise que le vrai WordPress ne permet pas.
	delete_post_meta( $id, '_wp_trash_meta_status' );
	delete_post_meta( $id, '_wp_trash_meta_time' );

	$post_updated = wpd_update_post_ou_echec(
		array(
			'ID'          => $id,
			'post_status' => (string) $nouveau,
		),
		'wpd_untrash_fail'
	);

	if ( ! $post_updated ) {
		// `untrashed_post` n'est pas exécuté : le cœur sort ici.
		return false;
	}

	do_action( 'untrashed_post', $id, $precedent );

	return $post;
}

/**
 * Vidage automatique de la Corbeille.
 *
 * Emprunte `wp_delete_post()`, donc le même chemin protégé — comme le fait
 * `wp_scheduled_delete()` dans WordPress.
 */
function wp_scheduled_delete( $jours = 30 ) {
	$limite    = wpd_now() - ( $jours * DAY_IN_SECONDS );
	$supprimes = 0;

	foreach ( array_keys( $GLOBALS['wpd_posts'] ) as $id ) {
		// Le cœur interroge `_wp_trash_meta_time` : **une métadonnée absente
		// ne remonte pas**. La traiter comme un zéro purgerait des contenus que
		// le vrai WordPress laisse tranquilles — dont, précisément, ceux dont
		// une restauration interrompue a effacé l'état natif.
		if ( ! array_key_exists( '_wp_trash_meta_time', $GLOBALS['wpd_meta'][ $id ] ?? array() ) ) {
			continue;
		}

		if ( (int) $GLOBALS['wpd_meta'][ $id ]['_wp_trash_meta_time'] > $limite ) {
			continue;
		}

		$post = get_post( $id );

		// Plus à la Corbeille : le cœur ne supprime pas, il fait le ménage de
		// ses propres métadonnées.
		if ( ! $post || 'trash' !== $post->post_status ) {
			delete_post_meta( $id, '_wp_trash_meta_status' );
			delete_post_meta( $id, '_wp_trash_meta_time' );

			continue;
		}

		// `wp_delete_post( $id )` — **sans** forçage, comme le cœur. Le
		// troisième argument de `pre_delete_post` vaut donc `false`.
		if ( false !== wp_delete_post( $id ) ) {
			++$supprimes;
		}
	}

	return $supprimes;
}

// -------------------------------------------------------------- métadonnées -
function update_post_meta( $id, $cle, $valeur, $prev = '' ) {
	// Permet d'éprouver le retour arrière : une métadonnée désignée échoue.
	if ( '' !== $GLOBALS['wpd_meta_fail'] && $cle === $GLOBALS['wpd_meta_fail'] ) {
		return false;
	}

	$id      = (int) $id;
	$present = array_key_exists( $cle, $GLOBALS['wpd_meta'][ $id ] ?? array() );

	// Couche de stockage qui **annonce** un succès sans rien écrire. Un code
	// qui se fie au retour ne le verra jamais ; une relecture, si.
	if ( '' !== ( $GLOBALS['wpd_meta_lie'] ?? '' ) && $cle === $GLOBALS['wpd_meta_lie'] ) {
		return $present ? true : 123;
	}

	// Fidélité à `update_metadata()` : une valeur **identique** rend `false`.
	// Ce n'est pas un échec, c'est une absence de changement — et tout code qui
	// lit ce retour comme un échec se trompe.
	if ( $present && $GLOBALS['wpd_meta'][ $id ][ $cle ] === $valeur ) {
		return false;
	}

	$GLOBALS['wpd_meta'][ $id ][ $cle ] = $valeur;

	// Création : le cœur rend l'identifiant de la métadonnée. Mise à jour :
	// `true`.
	return $present ? true : 123;
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
