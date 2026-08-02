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
	str_contains( $dp, '"Clôture / mur": 189' )
		&& str_contains( $dp, '"__defaut": 249' )
		&& str_contains( $dp, '"Extension": 549' )
);
verifier(
	'DP · Garage et Carport sont des natures propres à 249 €',
	str_contains( $dp, 'value="Garage"' )
		&& str_contains( $dp, 'value="Carport / abri de voiture"' )
		&& str_contains( $dp, 'value="Abri / annexe"' )
		&& str_contains( $dp, '"Garage": 249' )
		&& str_contains( $dp, '"Carport / abri de voiture": 249' )
);
verifier(
	'PC · barème déclaré dans le formulaire',
	str_contains( $pc, '"Annexe (garage, dépendance)": 449' )
		&& str_contains( $pc, '"Extension": 649' )
		&& str_contains( $pc, '"Construction d’une maison individuelle": 849' )
		&& str_contains( $pc, 'surEtude: ["Autre"]' )
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

echo "\n── 7. Aucune soumission réelle à ce stade\n";

verifier(
	'ENDPOINT reste vide dans les quatre documents',
	4 === substr_count( $dp . $pc . $dp_maq . $pc_maq, 'var ENDPOINT = ""' )
);

echo "\n";

if ( $echecs > 0 ) {
	printf( "\033[31m%d CONTROLE(S) EN ECHEC\033[0m\n", $echecs );
	exit( 1 );
}

echo "\033[32mTOUS LES CONTROLES PASSENT\033[0m\n";
