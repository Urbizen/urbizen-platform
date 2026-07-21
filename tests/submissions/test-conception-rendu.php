<?php
/**
 * Banc d'essai du rendu du formulaire de conception.
 *
 * Deux garanties, dans cet ordre.
 *
 * **Personne ne voit ce formulaire.** Un visiteur anonyme n'obtient ni balise,
 * ni schéma, ni nonce, ni jeton, ni ressource. Le garde est serveur : le
 * masquer en CSS reviendrait à le servir quand même.
 *
 * **Ce qui est rendu vient de la définition serveur.** Six étapes, quarante-cinq
 * champs, dans l'ordre exact, avec leurs libellés, leurs obligations et leurs
 * conditions — rien de recopié.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Conception\ConceptionAssets;
use Urbizen\Platform\Conception\ConceptionAvailability;
use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Conception\ConceptionSchema;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Http\SubmissionController;

/**
 * Repart d'un état propre, visiteur anonyme.
 */
function neuf(): void {
	wpd_reset();
	ConceptionRenderer::reset();
	ConceptionAssets::register();
	$GLOBALS['wpd_logged_in'] = false;
	$GLOBALS['wpd_can']       = false;
}

/**
 * Devient administrateur authentifié.
 */
function administrateur(): void {
	$GLOBALS['wpd_logged_in'] = true;
	$GLOBALS['wpd_can']       = true;
}

$def = FormRegistry::get( 'conception' );

// ======================================================================
// 1 · GARDE DE PUBLICATION
// ======================================================================
neuf();

check( '1 · le formulaire n’est pas public par défaut', false === ConceptionAvailability::is_public() );
check( '1 · un anonyme ne peut pas prévisualiser', false === ConceptionAvailability::can_preview() );
check( '1 · ni faire rendre', false === ConceptionAvailability::can_render() );
check( '1 · motif technique', 'formulaire_non_public' === ConceptionAvailability::blocker() );

$rendu = ConceptionRenderer::render( $def );

check( '1 · rendu anonyme vide', '' === $rendu );
check( '1 · aucun nonce émis', ! str_contains( $rendu, SubmissionController::NONCE_FIELD ) );
check( '1 · aucun jeton émis', ! str_contains( $rendu, SubmissionController::TOKEN_FIELD ) );
check( '1 · aucune ressource mise en file', array() === $GLOBALS['wpd_styles'] && array() === $GLOBALS['wpd_scripts'] );
check( '1 · aucun schéma exposé', array() === $GLOBALS['wpd_inline'] );

// --- un utilisateur connecté sans capacité n'obtient rien non plus ---
neuf();
$GLOBALS['wpd_logged_in'] = true;

check( '1 · connecté sans manage_options : aucun rendu', '' === ConceptionRenderer::render( $def ) );
check( '1 · aucune ressource', array() === $GLOBALS['wpd_styles'] );

// --- une capacité sans session ne suffit pas ---
neuf();
$GLOBALS['wpd_can'] = true;

check( '1 · capacité sans session : aucun rendu', '' === ConceptionRenderer::render( $def ) );

// --- aucun paramètre d'URL n'ouvre le formulaire ---
neuf();
$_GET['urbizen_conception']  = '1';
$_GET['preview']             = 'true';
$_REQUEST['conception']      = 'ouvert';

check( '1 · UN PARAMÈTRE D’URL N’OUVRE RIEN', '' === ConceptionRenderer::render( $def ) );

$_GET     = array();
$_REQUEST = array();

// --- le filtre serveur, lui, ouvre ---
neuf();
add_filter( 'urbizen_conception_public_enabled', static fn() => true );

check( '1 · le filtre serveur ouvre le formulaire', true === ConceptionAvailability::is_public() );
check( '1 · et le rendu a lieu, même anonyme', '' !== ConceptionRenderer::render( $def ) );

wpd_clear_filter( 'urbizen_conception_public_enabled' );

// --- une valeur non stricte ne suffit pas ---
neuf();
add_filter( 'urbizen_conception_public_enabled', static fn() => '1' );

check( '1 · une valeur non booléenne n’ouvre pas', false === ConceptionAvailability::is_public() );

wpd_clear_filter( 'urbizen_conception_public_enabled' );

// ======================================================================
// 2 · APERÇU ADMINISTRATEUR
// ======================================================================
neuf();
administrateur();
$rendu = ConceptionRenderer::render( $def );

check( '2 · l’administrateur obtient le formulaire', '' !== $rendu );
check( '2 · il est signalé comme aperçu', str_contains( $rendu, 'urbizen-conception__apercu' ) );
check( '2 · la feuille de style est chargée', in_array( ConceptionAssets::HANDLE_CSS, $GLOBALS['wpd_styles'], true ) );
check( '2 · le script est chargé', in_array( ConceptionAssets::HANDLE_JS, $GLOBALS['wpd_scripts'], true ) );
check( '2 · le schéma est transmis', isset( $GLOBALS['wpd_inline'][ ConceptionAssets::HANDLE_JS ] ) );

// ======================================================================
// 3 · STRUCTURE : SIX ÉTAPES, QUARANTE-CINQ CHAMPS
// ======================================================================
$etapes = $def->steps();

check( '3 · six étapes', 6 === count( $etapes ) );
check( '3 · six fieldset d’étape', 6 === substr_count( $rendu, 'class="urbizen-conception__etape"' ) );
check( '3 · six légendes', 6 === substr_count( $rendu, 'urbizen-conception__etape-titre' ) );
check( '3 · six entrées de progression', 6 === substr_count( $rendu, 'urbizen-conception__progression-item' ) );

$total = 0;
$rang  = 0;
$ordre = true;
$position = -1;

foreach ( $etapes as $etape ) {
	$eid    = is_array( $etape ) ? (string) $etape['id'] : (string) $etape;
	$champs = $def->fields_for_step( $eid );
	$total += count( $champs );

	$ici = strpos( $rendu, 'data-step="' . $eid . '" data-rang="' . $rang . '"' );

	if ( false === $ici || $ici < $position ) {
		$ordre = false;
	}

	$position = (int) $ici;
	++$rang;
}

check( '3 · quarante-cinq champs déclarés', 45 === $total );
check( '3 · les étapes sont dans l’ordre exact', $ordre );
check( '3 · chaque champ a son bloc', 45 === substr_count( $rendu, 'data-field="' ) );

// Chaque champ de la définition est présent, nommément.
$manquants = array();

foreach ( $def->fields() as $champ ) {
	if ( ! str_contains( $rendu, 'data-field="' . $champ['name'] . '"' ) ) {
		$manquants[] = $champ['name'];
	}
}

check( '3 · aucun champ manquant', array() === $manquants );

// ======================================================================
// 4 · LIBELLÉS, OBLIGATIONS, CONDITIONS
// ======================================================================
$sans_label   = array();
$sans_requis  = array();
$sans_cond    = array();

foreach ( $def->fields() as $champ ) {
	if ( isset( $champ['label'] ) && ! str_contains( $rendu, esc_html( (string) $champ['label'] ) ) ) {
		$sans_label[] = $champ['name'];
	}

	if ( ! empty( $champ['required'] ) ) {
		$bloc = substr( $rendu, (int) strpos( $rendu, 'data-field="' . $champ['name'] . '"' ), 1200 );

		if ( ! str_contains( $bloc, 'urbizen-conception__requis' ) ) {
			$sans_requis[] = $champ['name'];
		}
	}

	if ( isset( $champ['visible_if']['field'] )
		&& ! str_contains( $rendu, 'data-visible-if="' . $champ['visible_if']['field'] . '"' ) ) {
		$sans_cond[] = $champ['name'];
	}
}

check( '4 · tous les libellés sont rendus', array() === $sans_label );
check( '4 · les six champs obligatoires sont marqués', array() === $sans_requis );
check( '4 · l’obligation est écrite, pas seulement colorée', str_contains( $rendu, '(obligatoire)' ) );
check( '4 · les seize conditions sont portées par le HTML', array() === $sans_cond );
check( '4 · seize champs conditionnels', 16 === substr_count( $rendu, 'data-visible-if="' ) );

// ======================================================================
// 5 · ACCESSIBILITÉ
// ======================================================================
check( '5 · un seul formulaire', 1 === substr_count( $rendu, '<form ' ) );
check( '5 · un seul bouton submit', 1 === substr_count( $rendu, 'type="submit"' ) );
// Quatre boutons simples : Précédent, Suivant, suppression du brouillon, et
// le bouton d'envoi n'en fait pas partie — c'est le seul `submit`.
check( '5 · Précédent et Suivant sont des boutons simples',
	str_contains( $rendu, 'data-action="precedent"' ) && str_contains( $rendu, 'data-action="suivant"' ) );
check( '5 · aucun autre submit que l’envoi', 1 === substr_count( $rendu, 'type="submit"' ) );
check( '5 · les actions secondaires sont des boutons simples',
	3 === substr_count( $rendu, 'type="button"' ) );
check( '5 · zone aria-live pour les annonces', str_contains( $rendu, 'aria-live="polite"' ) );
check( '5 · résumé d’erreurs en alerte', str_contains( $rendu, 'role="alert"' ) && str_contains( $rendu, 'aria-live="assertive"' ) );
check( '5 · l’étape active porte aria-current', 1 === substr_count( $rendu, 'aria-current="step"' ) );
check( '5 · les légendes sont focalisables', str_contains( $rendu, '__etape-titre" id=' ) && str_contains( $rendu, 'tabindex="-1"' ) );
check( '5 · chaque contrôle simple a un label lié', substr_count( $rendu, '<label class="urbizen-conception__label" for="' ) > 0 );
check( '5 · les groupes de choix ont une légende', substr_count( $rendu, 'urbizen-conception__groupe' ) > 0 );
check( '5 · aucun placeholder tenant lieu de libellé', ! str_contains( $rendu, 'placeholder=' ) );
check( '5 · chaque contrôle décrit son message d’erreur', substr_count( $rendu, 'aria-describedby="' ) > 0 );
check( '5 · le pot de miel est hors du parcours clavier',
	str_contains( $rendu, 'tabindex="-1" autocomplete="off"' ) && str_contains( $rendu, 'aria-hidden="true"' ) );

// ======================================================================
// 6 · SANS JAVASCRIPT
// ======================================================================
check( '6 · un message noscript explicite', str_contains( $rendu, '<noscript>' ) && str_contains( $rendu, 'nécessite JavaScript' ) );
check( '6 · les six étapes sont dans le document', 6 === substr_count( $rendu, 'class="urbizen-conception__etape"' ) );
check( '6 · aucune étape masquée côté serveur', ! str_contains( $rendu, '__etape" id="urbizen-conception-1-etape-pieces" data-step="pieces" data-rang="1" hidden' ) );

// ======================================================================
// 7 · CHAMPS TECHNIQUES
// ======================================================================
check( '7 · l’action est celle du contrôleur existant', str_contains( $rendu, 'value="' . SubmissionController::ACTION . '"' ) );
check( '7 · un nonce est posé', str_contains( $rendu, 'name="' . SubmissionController::NONCE_FIELD . '"' ) );
check( '7 · un jeton anti-spam est posé', str_contains( $rendu, 'name="' . SubmissionController::TOKEN_FIELD . '"' ) );
check( '7 · un pot de miel est posé', str_contains( $rendu, 'name="' . SubmissionController::HONEYPOT_FIELD . '"' ) );
check( '7 · une URL de retour du même site', str_contains( $rendu, 'name="' . SubmissionController::RETURN_FIELD . '"' ) );
check( '7 · le formulaire est multipart', str_contains( $rendu, 'enctype="multipart/form-data"' ) );
check( '7 · il pointe vers admin-post.php', str_contains( $rendu, 'admin-post.php' ) );
check( '7 · aucune donnée dans l’URL d’action', ! str_contains( $rendu, 'admin-post.php?' ) );

// ======================================================================
// 8 · DOCUMENTS
// ======================================================================
foreach ( \Urbizen\Platform\Files\UploadPolicy::BLOCKS as $bloc ) {
	check( "8 · le bloc « $bloc » est rendu", str_contains( $rendu, 'data-bloc="' . $bloc . '"' ) );
}

check( '8 · cinq champs de dépôt', 5 === substr_count( $rendu, 'type="file"' ) );
check( '8 · dépôt multiple', 5 === substr_count( $rendu, 'multiple accept=' ) );
check( '8 · les extensions viennent de la politique', str_contains( $rendu, '.pdf,.jpg,.jpeg,.png,.webp' ) );
check( '8 · les limites sont affichées', str_contains( $rendu, '10 documents au maximum par rubrique, 20 au total' ) );

// ======================================================================
// 9 · SCHÉMA EXPOSÉ
// ======================================================================
$inline = implode( "\n", $GLOBALS['wpd_inline'][ ConceptionAssets::HANDLE_JS ] ?? array() );
$schema = ConceptionSchema::build( $def );

check( '9 · le schéma porte une version', '1' === $schema['version'] );
check( '9 · six étapes', 6 === count( $schema['steps'] ) );
check( '9 · quarante-cinq champs', 45 === array_sum( array_map( static fn( $e ) => count( $e['fields'] ), $schema['steps'] ) ) );
check( '9 · les tarifs viennent de Pricing', 449 === $schema['pricing']['base'] );
check( '9 · l’option interne n’est pas exposée', ! isset( $schema['pricing']['options']['modifs_sup'] ) );
check( '9 · les six options commerciales le sont', 6 === count( $schema['pricing']['options'] ) );
check( '9 · le pack et ses remplacements', 'pack_ftc' === $schema['pricing']['pack'] && 3 === count( $schema['pricing']['packReplaces'] ) );
check( '9 · les prestations sur devis', 3 === count( $schema['pricing']['surDevis'] ) );
check( '9 · la remise permis est une information', 200 === $schema['pricing']['remisePermis'] );
check( '9 · les limites de dépôt', 20 === $schema['uploads']['maxTotal'] && 10 === $schema['uploads']['maxPerBlock'] );

// Rien de technique ne fuit dans le schéma.
$json = (string) wp_json_encode( $schema );

foreach ( array( 'nonce', 'token', 'signature', 'salt', 'path', '_urbizen_', 'admin_email' ) as $interdit ) {
	check( "9 · le schéma ne contient pas « $interdit »", ! str_contains( strtolower( $json ), $interdit ) );
}

check( '9 · le schéma est bien celui transmis au navigateur', str_contains( $inline, '"version":"1"' ) );

// ======================================================================
// 10 · ISOLATION DES STYLES
// ======================================================================
$css = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'assets/css/urbizen-conception.css' );

$regles = array_filter(
	array_map( 'trim', explode( '}', $css ) ),
	static fn( $r ) => str_contains( $r, '{' ) && ! str_starts_with( ltrim( $r ), '@' ) && ! str_starts_with( ltrim( $r ), '/*' )
);

$hors_racine = array();

foreach ( $regles as $regle ) {
	$selecteur = trim( explode( '{', $regle )[0] );
	$selecteur = trim( (string) preg_replace( '#/\*.*?\*/#s', '', $selecteur ) );

	if ( '' === $selecteur ) {
		continue;
	}

	foreach ( explode( ',', $selecteur ) as $part ) {
		$part = trim( $part );

		if ( '' !== $part && ! str_contains( $part, '.urbizen-conception' ) ) {
			$hors_racine[] = $part;
		}
	}
}

check( '10 · toutes les règles sont sous la classe racine', array() === $hors_racine );
check( '10 · la palette Urbizen est employée',
	str_contains( $css, '#0b1f3a' ) && str_contains( $css, '#7bdcb5' ) && str_contains( $css, '#f6f8fb' ) );
check( '10 · une adaptation mobile est prévue', str_contains( $css, '@media' ) );
check( '10 · aucune règle sur body, header ou footer',
	! preg_match( '/(^|[\s,])(body|header|footer|html)\s*\{/m', $css ) );

// ======================================================================
// 11 · ÉCHAPPEMENT
// ======================================================================
check( '11 · aucun script dans le rendu', ! str_contains( $rendu, '<script' ) );
check( '11 · aucun gestionnaire d’événement en ligne', 1 !== preg_match( '/<[a-z]+[^>]*\son[a-z]+\s*=/i', $rendu ) );
check( '11 · le HTML est équilibré',
	substr_count( $rendu, '<fieldset' ) === substr_count( $rendu, '</fieldset>' )
	&& substr_count( $rendu, '<form ' ) === substr_count( $rendu, '</form>' ) );

// Deux instances sur la même page portent des identifiants distincts.
neuf();
administrateur();
$a = ConceptionRenderer::render( $def );
$b = ConceptionRenderer::render( $def );

check( '11 · deux instances ont des identifiants distincts',
	str_contains( $a, 'id="urbizen-conception-1"' ) && str_contains( $b, 'id="urbizen-conception-2"' ) );

verdict();
