<?php
/**
 * Banc de compatibilité et d'absence d'effet public.
 *
 * La PR B1 ajoute un backend entier. Elle ne doit rien changer de ce qui
 * existe, et surtout ne rien faire apparaître sur le site.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Pricing;
use Urbizen\Platform\Http\SubmissionController;
use Urbizen\Platform\Submissions\SubmissionPostType;

$racine = dirname( __DIR__, 2 );

// ------------------------------------------------ définition inchangée ------
$conception = FormRegistry::get( 'conception' );

check( 'la définition conception se charge', null !== $conception );
check( 'elle est valide, sans anomalie', $conception->is_valid() && array() === $conception->errors() );
check( 'six étapes', 6 === count( $conception->steps() ) );
check( 'quarante-cinq champs', 45 === count( $conception->fields() ) );
check( 'les étapes sont dans l’ordre',
	array( 'programme', 'pieces', 'terrain', 'style_options', 'documents', 'contact' ) === $conception->step_ids() );

$localisation = FormRegistry::get( 'localisation' );

check( 'localisation se charge toujours', null !== $localisation );
check( 'localisation conserve ses 14 champs', 14 === count( $localisation->fields() ) );
check( 'localisation ne déclare aucune étape', array() === $localisation->steps() );
check( 'localisation reste sans anomalie', $localisation->is_valid() );
check( 'le registre ne connaît que deux formulaires', array( 'localisation', 'conception' ) === FormRegistry::KNOWN );

// ------------------------------------------------ tarification inchangée ----
check( 'la base vaut toujours 449 €', 449 === Pricing::BASE );
check( 'le pack reste exclusif', 748 === Pricing::compute( array( 'pack_ftc', 'facades', 'toiture', 'coupe' ) )['total'] );
check( 'la remise de 200 € n’est toujours pas déduite', 449 === Pricing::compute( array() )['total'] );
check( 'modifs_sup reste au catalogue', in_array( 'modifs_sup', Pricing::known_ids(), true ) );
check( 'les neuf types de champs sont toujours reconnus', 9 === count( FormDefinition::TYPES ) );

// ------------------------------------------ le garde-fou du Renderer --------
$renderer = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Forms/Renderer.php' );

check( 'le garde-fou du rendu en étapes est toujours présent',
	str_contains( $renderer, 'array() !== $def->steps()' ) && str_contains( $renderer, 'rendu refusé' ) );

// ------------------------------------------------ aucun effet public --------
$refs = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $racine . '/wordpress' ) ) as $f ) {
	if ( ! $f->isFile() || str_contains( $f->getPathname(), '/definitions/' ) ) {
		continue;
	}

	$contenu  = (string) file_get_contents( $f->getPathname() );
	$relatif  = str_replace( $racine . '/', '', $f->getPathname() );

	if ( preg_match( '/(formType|form_type|form-type)["\'\s=:]+conception/', $contenu )
		&& ! str_contains( $relatif, 'SubmissionController' ) ) {
		$refs[] = $relatif;
	}
}

check( 'aucun gabarit ni bloc ne demande le rendu du formulaire conception', array() === $refs );

if ( array() !== $refs ) {
	echo '    référence : ' . implode( ' | ', $refs ) . "\n";
}

// PR C introduit les ressources du formulaire de conception. Ce qui doit être
// garanti n'est plus leur absence, mais qu'elles ne soient **jamais chargées**
// pour qui n'a pas le droit de voir le formulaire.
$assets = glob( URBIZEN_PLATFORM_DIR . 'assets/{css,js}/*conception*', GLOB_BRACE );

check( 'les ressources du formulaire existent', 2 === count( (array) $assets ) );

$GLOBALS['wpd_logged_in'] = false;
$GLOBALS['wpd_can']       = false;
$GLOBALS['wpd_styles']    = array();
$GLOBALS['wpd_scripts']   = array();

$rendu_anonyme = \Urbizen\Platform\Conception\ConceptionRenderer::render(
	\Urbizen\Platform\Forms\FormRegistry::get( 'conception' )
);

check( 'un visiteur anonyme n’obtient aucun rendu', '' === $rendu_anonyme );
check( 'ni feuille de style', array() === $GLOBALS['wpd_styles'] );
check( 'ni script', array() === $GLOBALS['wpd_scripts'] );
check( 'ni schéma exposé', array() === ( $GLOBALS['wpd_inline'] ?? array() ) );

// Le prototype de la page d'accueil, lui, ne doit toujours rien apporter.
$proto = glob( URBIZEN_PLATFORM_DIR . 'assets/{css,js}/*prototype*', GLOB_BRACE );

check( 'aucune ressource de prototype', array() === (array) $proto );

$theme = $racine . '/wordpress/urbizen-child';

check( 'aucun gabarit de page conception dans le thème', array() === glob( $theme . '/templates/*conception*' ) );
check( 'aucun pattern conception dans le thème', array() === glob( $theme . '/patterns/*conception*' ) );

// Le thème enfant est-il resté à l'écart de cette PR ?
$empreintes = array();

foreach ( array( 'templates/front-page.html', 'templates/page-accueil-urbizen.html', 'functions.php', 'theme.json' ) as $f ) {
	$empreintes[ $f ] = is_readable( $theme . '/' . $f );
}

check( 'les fichiers clés du thème existent toujours', ! in_array( false, $empreintes, true ) );

// ------------------------------------------------ aucun courriel ------------
$sources = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( URBIZEN_PLATFORM_DIR . 'src' ) ) as $f ) {
	if ( $f->isFile() && 'php' === $f->getExtension() ) {
		$sources[ str_replace( URBIZEN_PLATFORM_DIR, '', $f->getPathname() ) ] = (string) file_get_contents( $f->getPathname() );
	}
}

$fautifs = array();
$directs = array();

foreach ( $sources as $chemin => $contenu ) {
	// On retire les commentaires : parler de « courriel » est permis, en
	// envoyer un ne l'est pas.
	$code = implode(
		'',
		array_map(
			static fn( $t ) => is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $t ) ? $t[1] : $t ),
			token_get_all( $contenu )
		)
	);

	if ( preg_match( '/\bwp_mail\s*\(/', $code ) ) {
		$fautifs[] = $chemin;
	}

	// `mail()` contournerait les filtres de WordPress, la configuration SMTP du
	// site et toute extension de messagerie. Elle n'a sa place nulle part.
	if ( preg_match( '/(?<![\w>$:])mail\s*\(/', $code ) ) {
		$directs[] = $chemin;
	}
}

// B3 introduit un envoi — mais **un seul point d'envoi**. Concentrer l'appel
// permet de prouver par simple lecture qu'aucun autre chemin du greffon
// n'émet de courriel.
check( 'wp_mail n’est appelée que dans un seul fichier', array( 'src/Mail/WordPressMailTransport.php' ) === $fautifs );
check( 'aucun appel direct à mail()', array() === $directs );

// Le contrôleur de soumission et le dépôt ne construisent ni n'envoient rien.
foreach ( array( 'src/Http/SubmissionController.php', 'src/Submissions/SubmissionRepository.php' ) as $interdit ) {
	check(
		sprintf( '%s n’appelle aucun transport', $interdit ),
		! str_contains( $sources[ $interdit ] ?? '', 'wp_mail' )
		&& ! str_contains( $sources[ $interdit ] ?? '', 'MailRenderer' )
	);
}

if ( array() !== $fautifs ) {
	echo '    fichier : ' . implode( ' | ', $fautifs ) . "\n";
}

// -------------------------------------- les documents ne passent pas par WP -
// B2 traite les documents, mais **jamais** par la médiathèque : un fichier
// confié à `wp_handle_upload()` atterrirait dans `wp-content/uploads`, donc
// derrière une URL publique — exactement ce qu'on refuse.
$mediatheque = array();

foreach ( $sources as $chemin => $contenu ) {
	if ( preg_match( '/wp_handle_upload|wp_upload_dir|media_handle_upload|wp_insert_attachment/', $contenu ) ) {
		$mediatheque[] = $chemin;
	}
}

check( 'aucun document ne passe par la médiathèque WordPress', array() === $mediatheque );
// `move_uploaded_file()` n'est appelé qu'à un seul endroit : l'adaptateur de
// production. Storage délègue, et ne connaît plus aucun repli.
// `move_uploaded_file()` n'est **appelé** qu'à un seul endroit. Le contrat
// l'évoque en commentaire, ce qui est légitime : on compare donc le code, pas
// la documentation.
$appelants = array();

foreach ( $sources as $chemin => $contenu ) {
	$code = implode(
		'',
		array_map(
			static fn( $tok ) => is_array( $tok ) && in_array( $tok[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $tok ) ? $tok[1] : $tok ),
			token_get_all( $contenu )
		)
	);

	if ( str_contains( $code, 'move_uploaded_file(' ) ) {
		$appelants[] = $chemin;
	}
}

check( 'move_uploaded_file n’est appelé que dans l’adaptateur de production',
	array( 'src/Files/HttpUploadedFileMover.php' ) === $appelants );
check( 'Storage ne déplace plus rien lui-même',
	! preg_match( '/@rename\( \$source/', $sources['src/Files/Storage.php'] ) );
check( 'la provenance HTTP est exigée dans l’adaptateur',
	str_contains( $sources['src/Files/HttpUploadedFileMover.php'], 'is_uploaded_file(' ) );

// ------------------------------------------------ aucune table SQL ----------
/*
 * ASSERTION HISTORIQUE MODIFIÉE — inventoriée.
 *
 * Elle interdisait `CREATE TABLE` et `dbDelta` dans TOUT `src/`. E1 introduit
 * une couche de schéma dont c'est précisément le métier : la formulation
 * lexicale d'origine est devenue inapplicable.
 *
 * Elle n'est pas affaiblie, elle est **scindée et durcie** :
 *
 *   1. l'interdiction demeure, inchangée, partout hors `src/Schema/` — donc
 *      sur les cinquante classes qu'elle protégeait déjà ;
 *   2. `dbDelta` reste interdit PARTOUT, y compris dans `src/Schema/` : la
 *      couche de schéma écrit son SQL, elle ne s'en remet pas à un
 *      comparateur approximatif ;
 *   3. s'ajoute ce que l'ancienne formulation ne disait pas — le catalogue
 *      est vide, donc aucune table n'est réellement créée à l'exécution.
 *
 * Le troisième point est plus fort que les deux précédents : il porte sur le
 * comportement, non sur le texte du code.
 */
$sql     = array();
$deltas  = array();

foreach ( $sources as $chemin => $contenu ) {
	if ( preg_match( '/dbDelta/i', $contenu ) ) {
		$deltas[] = $chemin;
	}

	if ( 0 === strpos( $chemin, 'src/Schema/' ) ) {
		continue;
	}

	if ( preg_match( '/dbDelta|CREATE TABLE/i', $contenu ) ) {
		$sql[] = $chemin;
	}
}

check( 'aucune table SQL créée hors de la couche de schéma', array() === $sql );
check( 'dbDelta reste interdit partout', array() === $deltas );
check( 'LE CATALOGUE DE MIGRATIONS EST VIDE : AUCUNE TABLE N’EST CRÉÉE',
	\Urbizen\Platform\Schema\MigrationCatalogue::plateforme()->est_vide() );

// ------------------------------------------------ constantes de contrat -----
check( 'l’action admin-post est urbizen_conception', 'urbizen_conception' === SubmissionController::ACTION );
check( 'l’action du nonce est urbizen_conception_submit', 'urbizen_conception_submit' === SubmissionController::NONCE_ACTION );
check( 'le champ de nonce est urbizen_conception_nonce', 'urbizen_conception_nonce' === SubmissionController::NONCE_FIELD );
check( 'le champ piégé est company_website', 'company_website' === SubmissionController::HONEYPOT_FIELD );

$controleur = $sources['src/Http/SubmissionController.php'];

check( 'les deux hooks admin-post sont enregistrés',
	str_contains( $controleur, "admin_post_nopriv_' . self::ACTION" )
	&& str_contains( $controleur, "admin_post_' . self::ACTION" ) );
check( 'la redirection passe par wp_safe_redirect', str_contains( $controleur, 'wp_safe_redirect(' ) );
check( 'aucune redirection non sûre', ! preg_match( '/\bwp_redirect\s*\(/', $controleur ) );

// ------------------------------------------------ versions alignées ---------
$principal = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'urbizen-platform.php' );

preg_match( "/URBIZEN_PLATFORM_VERSION\s*=\s*'([^']+)'/", $principal, $m );
preg_match( '/^ \* Version:\s*(.+)$/m', $principal, $mh );

$version = $m[1] ?? '';

/*
 * ASSERTION HISTORIQUE MODIFIÉE — inventoriée.
 *
 * Elle comparait la version à un littéral, `0.9.0`, qu'il fallait rééditer à
 * chaque incrément — un contrôle qu'on corrige mécaniquement finit par n'être
 * plus lu. Elle est remplacée par une vérification de **concordance** : la
 * version est celle qu'on veut si les quatre emplacements disent la même
 * chose, et si sa forme est valide.
 *
 * C'est plus exigeant : l'ancienne formulation laissait passer un `block.json`
 * resté en arrière, celle-ci le refuse.
 */
check( 'la version a une forme valide', 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ) );
check( 'l’en-tête du greffon annonce la même version', trim( $mh[1] ?? '' ) === $version );

$blocs_versions = array();

foreach ( array( 'cadastre', 'formulaire' ) as $bloc ) {
	$json = json_decode(
		(string) file_get_contents( URBIZEN_PLATFORM_DIR . 'blocks/' . $bloc . '/block.json' ),
		true
	);

	$blocs_versions[ $bloc ] = (string) ( $json['version'] ?? '' );
}

check( 'LES QUATRE EMPLACEMENTS DE VERSION CONCORDENT',
	array( $version, $version ) === array_values( $blocs_versions ) );
check( 'l’en-tête concorde avec la constante', trim( $mh[1] ?? '' ) === $version );

foreach ( array( 'cadastre', 'formulaire' ) as $bloc ) {
	$json = json_decode( (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'blocks/' . $bloc . '/block.json' ), true );
	check( "[$bloc] block.json aligné sur $version", $version === ( $json['version'] ?? '' ) );
	check( "[$bloc] le nom du bloc est inchangé", 'urbizen/' . $bloc === ( $json['name'] ?? '' ) );
	check( "[$bloc] la catégorie est inchangée", 'widgets' === ( $json['category'] ?? '' ) );
}

// ------------------------------------------------ modules enregistrés -------
$plugin = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Plugin.php' );

foreach ( array( 'CadastreBlock::register()', 'FormBlock::register()', 'SubmissionPostType::register()', 'SubmissionController::register()', 'Retention::register()' ) as $module ) {
	check( 'module enregistré : ' . $module, str_contains( $plugin, $module ) );
}

check( 'la liste d’administration n’est chargée qu’en administration',
	str_contains( $plugin, 'if ( is_admin() ) {' ) && str_contains( $plugin, 'SubmissionsAdmin::register()' ) );

$activator = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Activator.php' );
$deact     = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Deactivator.php' );

check( 'la purge est programmée à l’activation', str_contains( $activator, 'Retention::schedule()' ) );
check( 'la purge est déprogrammée à la désactivation', str_contains( $deact, 'Retention::HOOK' ) );

// ------------------------------------------------ la liste d’admin ----------
// Le commentaire a le droit de nommer ce qui est interdit ; le code, non.
$admin_code = implode(
	'',
	array_map(
		static fn( $t ) => is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? ' ' : ( is_array( $t ) ? $t[1] : $t ),
		token_get_all( $sources['src/Admin/SubmissionsAdmin.php'] )
	)
);

preg_match_all( "/'(_urbizen_[a-z_]+)'/", $admin_code, $lues );

check( 'la liste ne lit que des métadonnées non personnelles',
	array( '_urbizen_form_type', '_urbizen_status', '_urbizen_files_count', '_urbizen_files_total_size', '_urbizen_files_status', '_urbizen_created_at_gmt' )
		=== array_values( array_unique( $lues[1] ) ) );
check( 'la liste ne lit jamais les documents eux-mêmes', ! preg_match( "/'_urbizen_files'/", $admin_code ) );
check( 'la liste ne lit jamais le payload', ! str_contains( $admin_code, '_urbizen_payload' ) );
check( 'la liste vérifie la capacité avant d’afficher', str_contains( $admin_code, 'current_user_can(' ) );
check( 'la liste échappe tout ce qu’elle affiche',
	substr_count( $admin_code, 'echo ' ) === substr_count( $admin_code, 'esc_html(' ) );

verdict();
