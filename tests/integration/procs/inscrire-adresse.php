<?php
/**
 * Processus fils : présente une adresse à l'inscription, sur une barrière.
 *
 * Il écrit son jalon « prêt », attend le top commun, puis appelle le VRAI
 * service d'inscription — même passerelle `$wpdb`, donc même connexion pour le
 * verrou, la recherche et la création. Le résultat (identifiant, création,
 * motif) est écrit tel quel : c'est le parent qui juge l'unicité après coup.
 *
 * C'est le seul moyen d'éprouver la course : dans un seul processus, la
 * séquence trouver-puis-créer paraît atomique sans l'être.
 */

declare( strict_types = 1 );

require dirname( __DIR__ ) . '/amorce-reelle.php';

use Urbizen\Platform\Account\InscriptionService;
use Urbizen\Platform\Account\VerificationService;
use Urbizen\Platform\Adapter\WpComptes;
use Urbizen\Platform\Adapter\WpdbGateway;

$rdv     = (string) getenv( 'URBIZEN_RDV' );
$adresse = (string) getenv( 'URBIZEN_ADRESSE' );
$mdp     = (string) getenv( 'URBIZEN_MDP' );
$rang    = (string) getenv( 'URBIZEN_RANG' );

$comptes = new WpComptes();
$service = new InscriptionService(
	$comptes,
	new VerificationService( $comptes, new WpdbGateway() ),
	new WpdbGateway()
);

// Prêt, puis attente du top commun : on maximise le recouvrement des sections
// critiques, là où la course a lieu.
urbizen_jalon( $rdv . '/pret-' . $rang );
urbizen_attendre( $rdv . '/go', 20.0 );

$r = $service->inscrire( $adresse, $mdp, time() );

file_put_contents(
	$rdv . '/res-' . $rang,
	(string) wp_json_encode(
		array(
			'compte' => (int) $r['compte'],
			'cree'   => (bool) $r['cree'],
			'motif'  => (string) $r['motif'],
		)
	)
);

exit( 0 );
