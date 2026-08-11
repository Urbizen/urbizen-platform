<?php
/**
 * Contrôle de préparation au déploiement des documents légaux.
 *
 * CE N'EST PAS UN TEST TECHNIQUE
 *
 * Il ne vérifie ni le code, ni la mise en page : `test-pages-legales.php` s'en
 * charge. Il répond à une seule question, métier et juridique :
 *
 *     ces documents peuvent-ils être publiés en l'état ?
 *
 * DEUX NIVEAUX, ET LA DIFFÉRENCE COMPTE
 *
 *   BLOQUANT    la donnée est indispensable à une obligation propre au
 *               document. Sans elle, publier serait publier un document en
 *               défaut. Code de sortie 1.
 *   À VÉRIFIER  la donnée enrichit le document sans conditionner sa
 *               régularité. Signalée, jamais bloquante.
 *
 * POURQUOI CE BANC N'EST PAS DANS `run-all.sh`
 *
 * Il est fait pour être rouge tant qu'une donnée manque. L'inclure dans la
 * suite technique rendrait celle-ci rouge en permanence et découragerait de la
 * lancer — le contraire du but recherché. On le lance explicitement, avant un
 * déploiement, et son verdict figure dans le compte rendu.
 *
 * IL DÉPEND DE LA DATE DU JOUR, ET C'EST VOULU
 *
 * L'attestation d'assurance porte un terme. Passé celui-ci, les pages
 * continueraient d'affirmer une couverture au présent sans pièce à l'appui.
 * Le banc signale donc l'attestation échue — en « à vérifier », le contrat
 * pouvant se reconduire avant que la nouvelle attestation ne soit émise. Un
 * banc qui ne regarderait pas le calendrier laisserait passer cette péremption
 * en silence.
 *
 *     php tests/legal/test-legal-readiness.php
 *
 * Codes de sortie : 0 publiable · 1 au moins un blocage.
 */

$racine = dirname( __DIR__, 2 );
$theme  = $racine . '/wordpress/urbizen-child';

define( 'ABSPATH', $racine );

$src = (string) file_get_contents( $theme . '/functions.php' );

foreach ( array( 'urbizen_child_donnees_legales', 'urbizen_child_donnees_legales_manquantes' ) as $fn ) {
	if ( ! preg_match( '/^function ' . $fn . '\(\).*?^}$/ms', $src, $m ) ) {
		echo "Fonction introuvable : $fn\n";
		exit( 2 );
	}

	eval( $m[0] ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- source du dépôt.
}

$manquantes = urbizen_child_donnees_legales_manquantes();
$bloquants  = array_values( array_filter( $manquantes, fn( $x ) => 'bloquant' === $x['niveau'] ) );
$a_verifier = array_values( array_filter( $manquantes, fn( $x ) => 'a_verifier' === $x['niveau'] ) );

echo "════════════════════════════════════════════════════════════════════\n";
echo " PRÉPARATION AU DÉPLOIEMENT DES DOCUMENTS LÉGAUX\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

if ( $bloquants ) {
	echo "BLOQUANTS — publication interdite en l'état\n";
	echo "────────────────────────────────────────────────────────────────────\n";

	foreach ( $bloquants as $i => $b ) {
		printf( "\n  %d. %s  [%s]\n", $i + 1, strtoupper( $b['cle'] ), $b['document'] );
		echo '     Pourquoi : ' . wordwrap( $b['pourquoi'], 66, "\n                ", false ) . "\n";
		echo '     Où       : ' . wordwrap( $b['ou'], 66, "\n                ", false ) . "\n";
	}

	echo "\n";
} else {
	echo "BLOQUANTS : aucun.\n\n";
}

if ( $a_verifier ) {
	echo "À VÉRIFIER — n'empêche pas la publication\n";
	echo "────────────────────────────────────────────────────────────────────\n";

	foreach ( $a_verifier as $i => $a ) {
		printf( "\n  %d. %s  [%s]\n", $i + 1, strtoupper( $a['cle'] ), $a['document'] );
		echo '     Pourquoi : ' . wordwrap( $a['pourquoi'], 66, "\n                ", false ) . "\n";
		echo '     Où       : ' . wordwrap( $a['ou'], 66, "\n                ", false ) . "\n";
	}

	echo "\n";
}

// Un document qui affiche une donnée pourtant déclarée absente serait le pire
// des cas : une page publique qui invente. On le vérifie plutôt que d'y croire.
$donnees = urbizen_child_donnees_legales();
$fuites  = array();

foreach ( array( 'page-mentions-legales', 'page-cgv', 'page-confidentialite' ) as $slug ) {
	$t = (string) file_get_contents( $theme . '/templates/' . $slug . '.html' );

	if ( null === $donnees['mediateur'] && preg_match( '/médiateur\s+(agréé|référencé)\s*:|coordonnées du médiateur\s*:/i', $t ) ) {
		$fuites[] = "$slug affiche des coordonnées de médiateur alors qu'aucune n'est connue";
	}

	if ( null === $donnees['assurance'] && preg_match( '/assur\w+ (en )?décennale|police n°/i', $t ) ) {
		$fuites[] = "$slug affirme une couverture d'assurance non attestée";
	}

	// Le numéro de contrat et le nom de l'assureur ne doivent exister qu'à un
	// seul endroit : la source commune, lue par le pattern. Recopiés dans un
	// gabarit, ils survivraient à un changement d'assureur.
	if ( preg_match( '/Zurich|7400042329/i', $t ) ) {
		$fuites[] = "$slug recopie les coordonnées d'assurance au lieu de lire la source commune";
	}

	// Même raison pour le médiateur : une adhésion se résilie et se remplace.
	if ( preg_match( '/CM2C|cm2c\.net/i', $t ) ) {
		$fuites[] = "$slug recopie les coordonnées du médiateur au lieu de lire la source commune";
	}

	if ( preg_match( '/à compléter|à venir|sera communiqué/i', $t ) ) {
		$fuites[] = "$slug contient une mention d'attente destinée au public";
	}
}

echo "CONTRÔLE DES FUITES — une page ne doit jamais afficher l'inconnu\n";
echo "────────────────────────────────────────────────────────────────────\n";

if ( $fuites ) {
	foreach ( $fuites as $f ) {
		echo "  ✗ $f\n";
	}
	echo "\n";
} else {
	echo "  Aucune. Les données absentes sont omises, jamais suggérées.\n\n";
}

echo "════════════════════════════════════════════════════════════════════\n";

if ( $bloquants || $fuites ) {
	printf( " READY FOR PRODUCTION : NON  (%d bloquant(s), %d fuite(s))\n", count( $bloquants ), count( $fuites ) );
	echo "════════════════════════════════════════════════════════════════════\n";
	exit( 1 );
}

echo " READY FOR PRODUCTION : OUI\n";
echo "════════════════════════════════════════════════════════════════════\n";
exit( 0 );
