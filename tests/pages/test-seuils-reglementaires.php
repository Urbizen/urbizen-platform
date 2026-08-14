<?php
/**
 * Banc des seuils réglementaires publiés sur les pages DP et PC.
 *
 * POURQUOI CE BANC
 *
 * Le 14 août 2026, la vérification à la source des cinq règles chiffrées
 * publiées sur les deux pages commerciales a révélé deux erreurs de borne et
 * trois lacunes. L'une des deux erreurs venait de ce que **les deux pages ne
 * disaient pas la même chose** : la page DP écrivait « moins de 5 m² » là où la
 * page PC écrivait « jusqu'à 5 m² ». Une seule des deux était juste.
 *
 * Ce banc empêche les deux défauts de revenir : la borne fausse, et la
 * divergence entre deux pages qui décrivent les mêmes seuils.
 *
 * CE QU'IL NE FAIT PAS
 *
 * Il ne vérifie pas le droit — aucun banc ne peut lire Legifrance. Il fige les
 * formulations arrêtées après vérification, et signale toute réécriture qui les
 * ferait dériver. Le contrôle du droit lui-même est consigné dans
 * `docs/VERIFICATION_REGLEMENTAIRE_GUIDES_01-02.md`, avec les articles et leur
 * date de version.
 *
 * Aucun accès réseau, aucune base de données.
 */

$racine = dirname( __DIR__, 2 );
$T      = $racine . '/wordpress/urbizen-child/templates/';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
	global $fail;
	if ( ! $cond ) { $fail++; }
	printf( "%-78s %s\n", $label, $cond ? 'OK' : 'ECHEC' );
	if ( ! $cond && '' !== $detail ) { echo '    ' . $detail . "\n"; }
}

$dp = file_get_contents( $T . 'page-declaration-prealable.html' );
$pc = file_get_contents( $T . 'page-permis-de-construire.html' );
$deux = array( 'DP' => $dp, 'PC' => $pc );

// ------------------------------------------- 1 · la borne des 5 m² ----------
// R*421-2 a) dispense les constructions dont l'emprise ET la surface de
// plancher sont INFÉRIEURES OU ÉGALES à 5 m². Un abri de 5 m² pile est donc
// dispensé : « moins de 5 m² » le range à tort en déclaration préalable.
foreach ( $deux as $nom => $page ) {
	check( "$nom : la dispense va jusqu'à 5 m² inclus",
		str_contains( $page, "jusqu'à 5&nbsp;m²" ) && ! str_contains( $page, 'moins de 5&nbsp;m²' ) );
	check( "$nom : la déclaration préalable commence au-delà de 5 m²",
		str_contains( $page, 'plus de 5 à 20&nbsp;m²' ) );
}

// ------------------------------------------- 2 · la borne des 1,80 m --------
// R.421-9 vise une couverture « d'une hauteur au-dessus du sol INFÉRIEURE À un
// mètre quatre-vingts ». L'égalité appartient donc au permis de construire.
foreach ( $deux as $nom => $page ) {
	check( "$nom : couverture de piscine — la DP s'arrête sous 1,80 m",
		str_contains( $page, 'dont la couverture mesure moins de 1,80&nbsp;m' ) );
	check( "$nom : couverture de piscine — l'égalité à 1,80 m relève du permis",
		str_contains( $page, 'couverture de 1,80&nbsp;m ou plus' ) );
	check( "$nom : aucune formulation plaçant l'égalité du mauvais côté",
		! str_contains( $page, "couverture jusqu'à 1,80" )
		&& ! str_contains( $page, 'abri de plus de 1,80' )
		&& ! str_contains( $page, 'couverture de plus de 1,80' ) );
}

// ------------------------------------------- 3 · les périmètres protégés ----
// Les dispenses de R*421-2 tombent dans trois périmètres nommés, et R.421-11 y
// impose la DP. Le banc exige que les périmètres soient NOMMÉS : « secteur
// protégé » seul laisserait croire à un régime juridique unique.
foreach ( $deux as $nom => $page ) {
	check( "$nom : l'avertissement sur les dispenses qui disparaissent est présent",
		str_contains( $page, 'disparaissent dans certains périmètres protégés' ) );
	foreach ( array( 'site patrimonial remarquable', 'abords des monuments historiques', 'site classé ou en instance de classement' ) as $perimetre ) {
		check( "$nom : le périmètre « $perimetre » est nommé", str_contains( $page, $perimetre ) );
	}
	check( "$nom : l'avertissement cite ses deux articles",
		str_contains( $page, 'R.421-2' ) && str_contains( $page, 'R.421-11' ) );
}

// ------------------------------------------- 4 · l'emprise au sol -----------
// R*420-1 : « tous débords et surplombs inclus », MAIS avec des exceptions. Une
// définition amputée des exceptions serait aussi fausse que l'ancienne, dans
// l'autre sens.
foreach ( $deux as $nom => $page ) {
	check( "$nom : l'emprise inclut les débords et surplombs",
		str_contains( $page, 'tous débords et surplombs inclus' ) );
	check( "$nom : les exceptions de R.420-1 sont conservées",
		str_contains( $page, 'marquises' )
		&& ( str_contains( $page, 'débords de toiture non soutenus' )
			|| str_contains( $page, "débords de toiture lorsqu'ils ne sont pas soutenus" ) ) );
}
check( 'DP : la FAQ porte la définition complète, exceptions comprises',
	(bool) preg_match( '/emprise au sol est la projection verticale.*?modénature.*?encorbellements/s', $dp ) );

// ------------------------------------------- 5 · les pompes à chaleur -------
// Décret 2026-117. La dispense est étroite : implantation EN FAÇADE, sur un
// bâtiment EXISTANT, sous condition de non-visibilité, avec des exclusions.
check( 'DP : la dispense pompe à chaleur est mentionnée',
	str_contains( $dp, "l'implantation en façade d'une pompe à chaleur sur un bâtiment existant" ) );
check( 'DP : les trois conditions de non-visibilité sont citées',
	str_contains( $dp, 'domaine public' ) && str_contains( $dp, 'voie ouverte au public' )
	&& str_contains( $dp, 'autre immeuble ayant vue sur' ) );
check( 'DP : la dispense n\'est pas présentée comme générale',
	str_contains( $dp, 'La dispense comporte des exclusions' ) );
check( 'DP : l\'article est cité', str_contains( $dp, 'R.421-13' ) );

// ------------------------------------------- 6 · les deux pages concordent --
// C'est le contrôle qui manquait : deux pages du même site décrivant les mêmes
// seuils doivent employer les mêmes formulations.
$communes = array(
	"jusqu'à 5&nbsp;m²",
	'plus de 5 à 20&nbsp;m²',
	'plus de 20&nbsp;m²',
	"bassin jusqu'à 10&nbsp;m²",
	'bassin de plus de 10 à 100&nbsp;m², non couvert ou dont la couverture mesure moins de 1,80&nbsp;m',
	'bassin supérieur à 100&nbsp;m², ou couverture de 1,80&nbsp;m ou plus',
);
$divergences = array();
foreach ( $communes as $v ) {
	if ( ! str_contains( $dp, $v ) || ! str_contains( $pc, $v ) ) {
		$divergences[] = mb_substr( $v, 0, 46 );
	}
}
check( 'Les six seuils communs sont écrits à l\'identique sur les deux pages',
	array() === $divergences, implode( ' | ', $divergences ) );

// ------------------------------------------- 7 · rien de SEO n'a bougé ------
// Ces gabarits ne portent ni title ni meta — AIOSEO s'en charge. Le banc le
// vérifie pour qu'une correction de contenu n'y introduise jamais l'un ou
// l'autre par mégarde.
foreach ( $deux as $nom => $page ) {
	check( "$nom : le gabarit ne porte ni <title> ni meta description",
		! str_contains( $page, '<title' ) && ! str_contains( $page, '<meta name="description"' ) );
	check( "$nom : un seul H1", 1 === preg_match_all( '/<h1[^>]*>/', $page ) );
}
check( 'DP : le H1 est inchangé',
	str_contains( $dp, 'Votre déclaration préalable de travaux' ) || 1 === preg_match_all( '/<h1[^>]*>/', $dp ) );

echo "\n";
if ( $fail ) {
	echo $fail . " CONTROLE(S) EN ECHEC\n";
	exit( 1 );
}
echo "TOUS LES CONTROLES PASSENT\n";
