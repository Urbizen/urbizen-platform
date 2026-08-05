<?php
/**
 * Bancs des deux pages de formulaire d'autorisation (DP et PCMI).
 *
 * Ces contrôles vivaient dans `tests/homepage/test-orientation-tunnel.php`, qui
 * mêlait le tunnel d'accueil et les formulaires. Le banc y lisait `front-page.html`
 * et `urbizen-homepage.js` : impossible de l'emporter avec le seul périmètre
 * DP/PC sans traîner la refonte de l'accueil. Les assertions proprement
 * « formulaires » sont donc reprises ici, à leur place.
 *
 * Aucun WordPress n'est requis : on lit les sources du thème telles qu'elles
 * seront déployées.
 *
 * Usage : php tests/formulaires/test-pages-formulaires.php
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

$dp        = (string) file_get_contents( $theme . '/assets/forms/dp-formulaire.html' );
$pc        = (string) file_get_contents( $theme . '/assets/forms/pc-formulaire.html' );
$dp_maq    = (string) file_get_contents( $racine . '/frontend/formulaires/dp-formulaire.html' );
$pc_maq    = (string) file_get_contents( $racine . '/frontend/formulaires/pc-formulaire.html' );
$functions = (string) file_get_contents( $theme . '/functions.php' );
$theme_json = (string) file_get_contents( $theme . '/theme.json' );
$gabarit_dp = (string) file_get_contents( $theme . '/templates/page-formulaire-declaration-prealable.html' );
$gabarit_pc = (string) file_get_contents( $theme . '/templates/page-formulaire-permis-de-construire.html' );

$echecs = 0;

function verifier( $label, $condition ) {
	global $echecs;

	if ( ! $condition ) {
		$echecs++;
	}

	printf( "%-74s %s\n", $label, $condition ? 'OK' : 'ECHEC' );
}

/* ---------------------------------------------------------------- *
 *  1. Les deux gabarits sont déclarés au thème
 * ---------------------------------------------------------------- */

echo "\n── 1. Gabarits déclarés\n";

foreach ( array( 'declaration-prealable' => 'déclaration préalable', 'permis-de-construire' => 'permis de construire' ) as $slug => $nom ) {
	$gabarit = 'page-formulaire-' . $slug;

	verifier(
		sprintf( 'le gabarit « %s » figure dans la liste du thème', $nom ),
		str_contains( $functions, "'" . $gabarit . "'" )
	);
	verifier(
		sprintf( 'le gabarit « %s » est déclaré dans theme.json', $nom ),
		str_contains( $theme_json, '"name": "' . $gabarit . '"' )
	);
}

/* ---------------------------------------------------------------- *
 *  2. Les ressources ne se chargent que sur ces pages
 * ---------------------------------------------------------------- */

echo "\n── 2. Chargement conditionnel des ressources\n";

verifier(
	'une garde reconnaît les pages de formulaire',
	str_contains( $functions, 'function urbizen_child_est_page_formulaire_autorisation' )
);
verifier(
	'la feuille et le script de coque sont mis en file sous cette garde',
	str_contains( $functions, "urbizen_child_est_page_formulaire_autorisation()" )
		&& str_contains( $functions, '/assets/css/urbizen-form-page.css' )
		&& str_contains( $functions, '/assets/js/urbizen-form-page.js' )
);

/* ---------------------------------------------------------------- *
 *  3. Chaque gabarit sert son propre document
 * ---------------------------------------------------------------- */

echo "\n── 3. Gabarits et documents servis\n";

verifier(
	'le gabarit DP pointe vers dp-formulaire.html',
	str_contains( $gabarit_dp, '/assets/forms/dp-formulaire.html' )
);
verifier(
	'le gabarit PC pointe vers pc-formulaire.html',
	str_contains( $gabarit_pc, '/assets/forms/pc-formulaire.html' )
);
verifier(
	'les deux gabarits conservent en-tête et pied de page du site',
	str_contains( $gabarit_dp, 'header-urbizen' ) && str_contains( $gabarit_dp, 'footer-urbizen' )
		&& str_contains( $gabarit_pc, 'header-urbizen' ) && str_contains( $gabarit_pc, 'footer-urbizen' )
);

/* ---------------------------------------------------------------- *
 *  4. Ce que les formulaires demandent — et ne demandent pas
 * ---------------------------------------------------------------- */

echo "\n── 4. Pièces demandées au client\n";

verifier(
	'le client transmet des sources, pas les pièces réglementaires',
	str_contains( $dp, 'Photos du terrain et de la maison existante' )
		&& str_contains( $dp, 'Croquis du projet, même à main levée' )
		&& str_contains( $pc, 'Photos du terrain et de la maison existante' )
		&& ! str_contains( $dp, '["DP1", "Plan de situation du terrain"]' )
		&& ! str_contains( $pc, '["PCMI1","Plan de situation du terrain"]' )
);
verifier(
	'aucune donnée saisie ni JSON de démonstration n’est affiché',
	! str_contains( $dp, 'dp-debug' ) && ! str_contains( $pc, 'dp-debug' )
		&& ! str_contains( $dp, 'Données collectées' ) && ! str_contains( $pc, 'Données collectées' )
);

/* ---------------------------------------------------------------- *
 *  5. Barème et modules partagés
 * ---------------------------------------------------------------- */

echo "\n── 5. Barème déclaré et modules partagés\n";

verifier(
	'DP · barème déclaré dans le formulaire',
	str_contains( $dp, '"cloture_mur": 189' )
		&& str_contains( $dp, '"__defaut": 249' )
		&& str_contains( $dp, '"extension": 549' )
);
verifier(
	'DP · Garage et Carport sont des natures propres à 249 €',
	str_contains( $dp, 'value="garage"' )
		&& str_contains( $dp, 'value="carport"' )
		&& str_contains( $dp, 'value="abri_annexe"' )
		&& str_contains( $dp, '"garage": 249' )
		&& str_contains( $dp, '"carport": 249' )
);
verifier(
	'PC · barème déclaré dans le formulaire',
	str_contains( $pc, '"annexe_garage": 449' )
		&& str_contains( $pc, '"extension": 649' )
		&& str_contains( $pc, '"maison_individuelle": 849' )
		&& str_contains( $pc, 'surEtude: ["autre"]' )
);
verifier(
	'les suppléments sont identiques des deux côtés',
	str_contains( $dp, 'supplements: { abf: 80, depot: 30, travail: 100 }' )
		&& str_contains( $pc, 'supplements: { abf: 80, depot: 30, travail: 100 }' )
);

foreach ( array( 'DP' => $dp, 'PC' => $pc ) as $nom => $source ) {
	verifier(
		sprintf( '%s · charge les deux modules partagés', $nom ),
		str_contains( $source, 'urbizen-form-tarifs.js' ) && str_contains( $source, 'urbizen-form-pieces.js' )
	);
	verifier(
		sprintf( '%s · aucun calcul ni rendu de pièces inline', $nom ),
		! str_contains( $source, 'function estimatePrice' ) && ! str_contains( $source, 'PIECES.forEach' )
	);
}

/* ---------------------------------------------------------------- *
 *  6. Parité maquette / thème
 * ---------------------------------------------------------------- */

echo "\n── 6. Parité des sources\n";

foreach ( array( 'DP' => array( $dp, $dp_maq ), 'PC' => array( $pc, $pc_maq ) ) as $nom => $paire ) {
	list( $du_theme, $de_la_maquette ) = $paire;

	verifier(
		sprintf( '%s · le vocabulaire client est harmonisé des deux côtés', $nom ),
		str_contains( $du_theme, 'Cadre 8 · Projets supplémentaires' )
			&& str_contains( $de_la_maquette, 'Cadre 8 · Projets supplémentaires' )
			&& ! str_contains( $du_theme, 'Ajouter un travail' )
			&& ! str_contains( $de_la_maquette, 'Ajouter un travail' )
	);
	verifier(
		sprintf( '%s · la case cadastrale porte le libellé exact', $nom ),
		str_contains( $du_theme, '> Je ne connais pas ces informations cadastrales.</label>' )
			&& str_contains( $de_la_maquette, '> Je ne connais pas ces informations cadastrales.</label>' )
	);
	verifier(
		sprintf( '%s · la case de dépôt est imbriquée dans son libellé', $nom ),
		str_contains( $du_theme, '<label class="dp-option" for="depot_guichet">' )
			&& str_contains( $de_la_maquette, '<label class="dp-option" for="depot_guichet">' )
	);
}

/* ---------------------------------------------------------------- *
 *  7. Rien n'est encore envoyé
 * ---------------------------------------------------------------- */

echo "\n── 7. État du raccordement\n";

// La DP poste réellement, via le pont sécurisé ; le PC ne le fait pas encore.
// L'asymétrie est voulue et datée : ce contrôle la rend visible plutôt que de
// laisser croire que les deux parcours sont au même stade.
verifier(
	'DP · le mode aperçu a disparu des deux copies',
	! str_contains( $dp, 'var ENDPOINT = ""' ) && ! str_contains( $dp_maq, 'var ENDPOINT = ""' )
);
verifier(
	'DP · le pont sécurisé est chargé',
	str_contains( $dp, 'urbizen-form-bridge.js' ) && str_contains( $dp_maq, 'urbizen-form-bridge.js' )
);
verifier(
	'DP · la référence réelle a sa place à l’écran final',
	str_contains( $dp, 'id="dp-reference"' ) && str_contains( $dp_maq, 'id="dp-reference"' )
);
verifier(
	'DP · aucune mention d’aperçu ne subsiste',
	! str_contains( $dp, 'aucune donnée n’a été transmise' )
		&& ! str_contains( $dp_maq, 'aucune donnée n’a été transmise' )
);
// Le permis de construire est raccordé à son tour : les mêmes garanties lui
// sont opposées qu'à la déclaration préalable, sur les deux documents.
verifier(
	'PC · le pont est chargé dans les deux documents',
	str_contains( $pc, 'urbizen-form-bridge.js' ) && str_contains( $pc_maq, 'urbizen-form-bridge.js' )
);
verifier(
	'PC · la référence réelle a sa place à l’écran final',
	str_contains( $pc, 'id="dp-reference"' ) && str_contains( $pc_maq, 'id="dp-reference"' )
);
verifier(
	'PC · aucune mention d’aperçu ne subsiste',
	! str_contains( $pc, 'aucune donnée n’a été transmise' )
		&& ! str_contains( $pc_maq, 'aucune donnée n’a été transmise' )
);
// L'endpoint vide était le repli qui affichait un écran de réussite sans le
// moindre envoi. Sa disparition est ce qui rend le faux succès impossible.
verifier(
	'PC · le repli d’aperçu a disparu',
	! str_contains( $pc, 'var ENDPOINT' ) && ! str_contains( $pc_maq, 'var ENDPOINT' )
);
verifier(
	'PC · l’envoi passe par le pont',
	str_contains( $pc, 'UrbizenPont.init' ) && str_contains( $pc_maq, 'UrbizenPont.init' )
);

/* ================================================================== *
 *  Version du lot de ressources : une seule, partout
 * ================================================================== */

// Un document neuf qui appellerait d'anciens scripts — ou l'inverse — est
// exactement la panne que le versionnement doit fermer. Les trois endroits
// doivent donc porter la même valeur, et le banc échoue à la moindre dérive.
preg_match( "/const URBIZEN_CHILD_FORMS_VERSION = '([^']+)'/", $functions, $m );
$version = $m[1] ?? '';

verifier( 'une version de lot est déclarée', '' !== $version && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version ) );

foreach ( array( 'declaration-prealable' => $dp, 'permis-de-construire' => $pc ) as $slug => $_ ) {
	$gabarit = (string) file_get_contents( $theme . '/templates/page-formulaire-' . $slug . '.html' );

	verifier(
		sprintf( 'le cadre de « %s » porte la version du lot', $slug ),
		str_contains( $gabarit, '.html?v=' . $version . '"' )
	);
	verifier(
		sprintf( 'et aucun secret dans l’URL de « %s »', $slug ),
		! preg_match( '/(nonce|token|_wpnonce|urbizen_token)=/i', $gabarit )
	);
}

foreach ( array( 'DP thème' => $dp, 'DP maquette' => $dp_maq, 'PC thème' => $pc, 'PC maquette' => $pc_maq ) as $nom => $doc ) {
	preg_match_all( '/urbizen-form-[a-z]+\.(?:js|css)\?v=([0-9.]+)/', $doc, $versions );

	verifier(
		sprintf( '%s : les cinq ressources sont versionnées', $nom ),
		5 === count( $versions[1] ?? array() )
	);
	verifier(
		sprintf( '%s : toutes à la version du lot', $nom ),
		array() !== ( $versions[1] ?? array() ) && array( $version ) === array_values( array_unique( $versions[1] ) )
	);
	verifier(
		sprintf( '%s : aucune ressource interne sans version', $nom ),
		! preg_match( '/urbizen-form-[a-z]+\.(?:js|css)"/', $doc )
	);
}

echo "\n";

if ( $echecs > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $echecs );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
