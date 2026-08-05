<?php
/**
 * Banc d'essai de l'accusé de réception client.
 *
 * C'est le premier message qu'Urbizen adresse à une personne extérieure. Tout
 * ce qui, jusqu'ici, ne circulait qu'entre le serveur et la boîte d'Urbizen
 * peut désormais sortir — et ce banc existe pour que rien n'en sorte.
 *
 * Trois familles de contrôles, dans cet ordre d'importance :
 *
 * 1. **Le destinataire.** Il ne vient que de l'adresse validée puis persistée.
 *    Un `recipient`, un `notification_email`, un `to`, un `cc` glissés dans la
 *    requête n'ont aucun chemin jusqu'ici. Sans cette règle, un tiers ferait
 *    envoyer par Urbizen, depuis le domaine d'Urbizen, une confirmation à
 *    l'adresse de son choix.
 * 2. **Le contenu.** Aucun lien signé, aucune réponse recopiée, aucun état
 *    technique. Un accusé traverse des boîtes tierces, est archivé, transféré
 *    et indexé : ce qu'il contient est publié.
 * 3. **La mention tarifaire**, au caractère près. Elle seule empêche une
 *    estimation d'être lue comme un devis.
 *
 * Toutes les données sont fictives. Aucun courriel n'est émis.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Mail\CustomerAcknowledgementRenderer;
use Urbizen\Platform\Mail\CustomerAcknowledgementStrategy;
use Urbizen\Platform\Mail\ConceptionNotificationStrategy;
use Urbizen\Platform\Mail\DeclarationPrealableNotificationStrategy;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailQueue;
use Urbizen\Platform\Mail\NotificationSlot;
use Urbizen\Platform\Mail\NotificationStrategyRegistry;
use Urbizen\Platform\Mail\PermisConstruireNotificationStrategy;

/**
 * Installe une demande DP persistée, et rend son identifiant.
 *
 * @param array<string, mixed> $charge Charge à persister, fusionnée au défaut.
 * @param array<string, mixed> $tarif  Tarif persisté ; `null` pour aucun.
 * @return int
 */
function dp_persistee( array $charge = array(), ?array $tarif = null ): int {
	return demande_persistee( 'declaration_prealable', $charge, $tarif );
}

/**
 * Installe une demande persistée d'un type quelconque, et rend son identifiant.
 *
 * @param string                    $type   Type de formulaire.
 * @param array<string, mixed>      $charge Charge à persister, fusionnée au défaut.
 * @param array<string, mixed>|null $tarif  Tarif persisté ; `null` pour le défaut.
 * @return int
 */
function demande_persistee( string $type, array $charge = array(), ?array $tarif = null ): int {
	$id  = 7001;
	$ref = 'URB-2026-0077';

	$GLOBALS['wpd_posts'][ $id ] = (object) array(
		'ID'          => $id,
		'post_type'   => 'urbizen_demande',
		'post_title'  => $ref,
		'post_status' => 'private',
	);

	$defaut = array(
		'nature'                  => 'extension',
		'projets_supplementaires' => array( 'piscine' ),
		'pieces_differees'        => array( 'facades' ),
		'nom'                     => 'Camille Fictif',
		'email'                   => 'camille@exemple.test',
		'telephone'               => '0600000000',
		'adresse'                 => '12 rue Imaginaire',
	);

	$GLOBALS['wpd_meta'][ $id ] = array(
		'_urbizen_reference'   => $ref,
		'_urbizen_form_type'   => $type,
		'_urbizen_status'      => 'received',
		'_urbizen_payload'     => wp_json_encode( array_merge( $defaut, $charge ) ),
		'_urbizen_pricing'     => wp_json_encode(
			null === $tarif
				? array(
					'base'    => 549,
					'options' => array(
						array( 'id' => 'projet_supplementaire:piscine', 'price' => 100 ),
						array( 'id' => 'secteur_abf', 'price' => 80 ),
					),
					'total'   => 729,
				)
				: $tarif
		),
		'_urbizen_files'       => wp_json_encode( array() ),
		'_urbizen_transaction' => wp_json_encode( array( 'id' => 'secret-interne', 'staging' => '/var/private/x' ) ),
	);

	return $id;
}

// ======================================================================
// 1 · LE DESTINATAIRE NE VIENT QUE DE LA CHARGE VALIDÉE
// ======================================================================

$GLOBALS['wpd_meta'] = array();
$id = dp_persistee();

check( '1 · le destinataire est l’adresse validée du demandeur',
	'camille@exemple.test' === MailPolicy::customer_recipient( $id ) );

// Ce qu'un tiers pourrait tenter de glisser. Aucun de ces noms n'est lu.
$id = dp_persistee(
	array(
		'recipient'          => 'pirate@ailleurs.test',
		'notification_email' => 'pirate@ailleurs.test',
		'to'                 => 'pirate@ailleurs.test',
		'cc'                 => 'pirate@ailleurs.test',
		'bcc'                => 'pirate@ailleurs.test',
		'from'               => 'pirate@ailleurs.test',
		'reply_to'           => 'pirate@ailleurs.test',
	)
);

check( '2 · aucun champ contrefait ne détourne le destinataire',
	'camille@exemple.test' === MailPolicy::customer_recipient( $id ) );

$_POST['recipient'] = 'pirate@ailleurs.test';
$_POST['email']     = 'pirate@ailleurs.test';

check( '3 · $_POST n’a aucun chemin jusqu’au destinataire',
	'camille@exemple.test' === MailPolicy::customer_recipient( $id ) );

$_POST = array();

$id = dp_persistee( array( 'email' => 'pas-une-adresse' ) );
check( '4 · une adresse invalide ne produit aucun destinataire',
	'' === MailPolicy::customer_recipient( $id ) );

$id = dp_persistee( array( 'email' => "camille@exemple.test\r\nBcc: pirate@ailleurs.test" ) );
check( '5 · une adresse multiligne ne devient pas un en-tête',
	! str_contains( MailPolicy::customer_recipient( $id ), 'pirate' ) );

$id = dp_persistee( array( 'email' => '' ) );
check( '6 · sans adresse, il n’y a pas d’accusé — et pas de repli sur l’admin',
	'' === MailPolicy::customer_recipient( $id )
	&& null === CustomerAcknowledgementRenderer::render( $id, 1000 ) );

check( '7 · une demande inexistante ne produit rien',
	'' === MailPolicy::customer_recipient( 999999 )
	&& null === CustomerAcknowledgementRenderer::render( 999999, 1000 ) );

// ======================================================================
// 2 · LE SUJET
// ======================================================================

check( '8 · le sujet est celui qui a été arrêté, au caractère près',
	'Votre demande Urbizen a bien été reçue — URB-2026-0077'
	=== CustomerAcknowledgementRenderer::subject( 'URB-2026-0077' ) );

check( '9 · une référence multiligne ne casse pas le sujet',
	! str_contains( CustomerAcknowledgementRenderer::subject( "URB\r\nBcc: pirate@x.test" ), "\n" ) );

// ======================================================================
// 3 · LE CONTENU — ce qui doit y être
// ======================================================================

$GLOBALS['wpd_meta'] = array();
$id      = dp_persistee();
$message = CustomerAcknowledgementRenderer::render( $id, 1000 );

check( '10 · le message se rend', is_array( $message ) );
check( '10 · adressé au demandeur', 'camille@exemple.test' === $message['to'] );
check( '10 · avec le sujet attendu',
	'Votre demande Urbizen a bien été reçue — URB-2026-0077' === $message['subject'] );

$corps = (string) $message['body'];

check( '11 · la référence y figure', str_contains( $corps, 'URB-2026-0077' ) );
check( '11 · le projet est nommé sous son libellé client', str_contains( $corps, 'Extension' ) );
check( '11 · les projets supplémentaires aussi', str_contains( $corps, 'Piscine' ) );
check( '11 · le montant estimé y figure', str_contains( $corps, '729 €' ) );
check( '11 · les pièces différées sont rappelées',
	str_contains( $corps, 'À transmettre ultérieurement' )
	&& str_contains( $corps, 'façades' ) );

check( '12 · la mention tarifaire est présente au caractère près',
	str_contains( $corps, CustomerAcknowledgementRenderer::MENTION ) );
check( '12 · et elle est bien celle qui a été arrêtée',
	'Estimation indicative. Le tarif définitif sera confirmé par Urbizen après vérification de votre projet, avant toute commande.'
	=== CustomerAcknowledgementRenderer::MENTION );

// ======================================================================
// 4 · LE CONTENU — ce qui ne doit PAS y être
// ======================================================================

$interdits = array(
	'secret-interne'        => 'l’identifiant de transaction',
	'/var/private'          => 'un chemin de stockage',
	'_urbizen_'             => 'une clé de métadonnée',
	'urbizen_dl'            => 'un lien signé',
	'received'              => 'le statut interne',
	'declaration_prealable' => 'le type technique du formulaire',
	'0600000000'            => 'le téléphone recopié',
	'12 rue Imaginaire'     => 'l’adresse recopiée',
	'#7001'                 => 'l’identifiant de post',
);

foreach ( $interdits as $motif => $quoi ) {
	check( sprintf( '13 · %s n’apparaît pas dans l’accusé', $quoi ), ! str_contains( $corps, $motif ) );
}

check( '14 · aucun lien de téléchargement, d’aucune sorte',
	! str_contains( $corps, 'http://' ) && ! str_contains( $corps, 'https://' ) );

// ======================================================================
// 5 · ÉCHAPPEMENT
// ======================================================================

$GLOBALS['wpd_meta'] = array();
$id      = dp_persistee( array( 'nom' => '<script>alert(1)</script>' ) );
$hostile = (string) CustomerAcknowledgementRenderer::render( $id, 1000 )['body'];

check( '15 · un nom hostile est échappé, pas exécuté',
	! str_contains( $hostile, '<script>' ) && str_contains( $hostile, '&lt;script&gt;' ) );

// ======================================================================
// 5 bis · LA SALUTATION
// ======================================================================

$GLOBALS['wpd_meta'] = array();

// La DP sépare prénom et nom : saluer par le seul nom de famille donnerait
// « Bonjour Fictif », ce que personne n'écrit.
$id = dp_persistee( array( 'prenom' => 'Camille', 'nom' => 'Fictif' ) );
check( '15 bis · la salutation compose prénom puis nom',
	str_contains( (string) CustomerAcknowledgementRenderer::render( $id, 1000 )['body'], 'Bonjour Camille Fictif,' ) );

$GLOBALS['wpd_meta'] = array();
$id = dp_persistee( array( 'prenom' => '', 'nom' => 'Fictif' ) );
check( '15 bis · un seul des deux suffit',
	str_contains( (string) CustomerAcknowledgementRenderer::render( $id, 1000 )['body'], 'Bonjour Fictif,' ) );

$GLOBALS['wpd_meta'] = array();
$id = dp_persistee( array( 'prenom' => '', 'nom' => '' ) );
check( '15 bis · sans nom, la salutation reste correcte',
	str_contains( (string) CustomerAcknowledgementRenderer::render( $id, 1000 )['body'], 'Bonjour,' ) );

// ======================================================================
// 6 · LE TARIF SUR ÉTUDE N'EST PAS UN ZÉRO
// ======================================================================

$GLOBALS['wpd_meta'] = array();
$id = dp_persistee(
	array(),
	array(
		'base'    => 0,
		'options' => array( array( 'id' => 'secteur_abf', 'price' => 80 ) ),
		'total'   => null,
	)
);

$sur_etude = (string) CustomerAcknowledgementRenderer::render( $id, 1000 )['body'];

check( '16 · un total non chiffré se dit « Tarif sur étude »',
	str_contains( $sur_etude, 'Tarif sur étude' ) );
check( '16 · et surtout pas « 0 € », qui serait un engagement faux',
	! str_contains( $sur_etude, '0 €' ) );
check( '16 · la mention reste présente',
	str_contains( $sur_etude, CustomerAcknowledgementRenderer::MENTION ) );

// ======================================================================
// 7 · LES EN-TÊTES
// ======================================================================

$GLOBALS['wpd_meta'] = array();
$id   = dp_persistee();
$slot = NotificationSlot::client( $id );

update_post_meta( $id, $slot->cle( MailPolicy::META_ID ), 'aaaabbbbccccdddd1111222233334444' );
update_post_meta( $id, MailPolicy::META_ID, 'ffffeeeeddddcccc9999888877776666' );

$entetes = (array) CustomerAcknowledgementRenderer::render( $id, 1000 )['headers'];
$plat    = implode( "\n", $entetes );

check( '17 · aucun Reply-To fabriqué', ! str_contains( $plat, 'Reply-To' ) );
check( '17 · aucun Cc ni Bcc', ! str_contains( $plat, 'Cc:' ) && ! str_contains( $plat, 'Bcc:' ) );
check( '18 · l’identifiant technique est celui du créneau client',
	str_contains( $plat, 'aaaabbbbccccdddd1111222233334444' ) );
check( '18 · pas celui de la notification interne',
	! str_contains( $plat, 'ffffeeeeddddcccc9999888877776666' ) );

// ======================================================================
// 8 · RÉSOLUTION DE LA STRATÉGIE PAR CRÉNEAU
// ======================================================================

$admin  = NotificationSlot::admin( $id );
$client = NotificationSlot::client( $id );

check( '19 · le créneau administratif d’une DP garde la stratégie interne',
	NotificationStrategyRegistry::for_slot( 'declaration_prealable', $admin )
		instanceof DeclarationPrealableNotificationStrategy );
check( '19 · le créneau client reçoit l’accusé',
	NotificationStrategyRegistry::for_slot( 'declaration_prealable', $client )
		instanceof CustomerAcknowledgementStrategy );

check( '20 · Conception n’envoie aucun accusé client',
	null === NotificationStrategyRegistry::for_slot( 'conception', $client )
	&& NotificationStrategyRegistry::for_slot( 'conception', $admin )
		instanceof ConceptionNotificationStrategy );
check( '20 · un type sans stratégie n’en gagne pas une par le créneau client',
	null === NotificationStrategyRegistry::for_slot( 'localisation', $client )
	&& null === NotificationStrategyRegistry::for_slot( 'localisation', $admin ) );
check( '20 · la liste blanche des accusés est explicite',
	NotificationStrategyRegistry::has_customer_acknowledgement( 'declaration_prealable' )
	&& ! NotificationStrategyRegistry::has_customer_acknowledgement( 'conception' ) );

check( '21 · la stratégie rend exactement ce que rend le renderer',
	( new CustomerAcknowledgementStrategy() )->build( $id, 1000 )
	=== CustomerAcknowledgementRenderer::render( $id, 1000 ) );

// ======================================================================
// 9 · IDEMPOTENCE ET INDÉPENDANCE DES DEUX MESSAGES
// ======================================================================

check( '22 · les deux messages d’une même référence ont des clés distinctes',
	$admin->idempotence( 'URB-2026-0077' ) !== $client->idempotence( 'URB-2026-0077' ) );
check( '22 · et la clé du client est stable d’un appel à l’autre',
	$client->idempotence( 'URB-2026-0077' ) === NotificationSlot::client( $id )->idempotence( 'URB-2026-0077' ) );

$GLOBALS['wpd_meta'] = array();
$id     = dp_persistee();
$client = NotificationSlot::client( $id );

MailQueue::create_pending( $id, 1000 );
MailQueue::create_pending( $id, 1000, $client );
MailQueue::mark_failure( $id, 1, 'transport_refused', 1000, $client );

check( '23 · l’échec de l’accusé ne remet pas en cause la notification interne',
	MailPolicy::PENDING === MailQueue::state( $id )['status']
	&& MailPolicy::RETRY === MailQueue::state( $id, $client )['status'] );
check( '23 · ni le dossier lui-même',
	'received' === (string) get_post_meta( $id, '_urbizen_status', true ) );

// ======================================================================
// 10 · SOURCE UNIQUE
// ======================================================================

// ======================================================================
// 11 · LE PERMIS DE CONSTRUIRE — mêmes garanties, catalogue propre
// ======================================================================

$GLOBALS['wpd_meta'] = array();
$id = demande_persistee(
	'permis_construire',
	array(
		'nature'                  => 'maison_individuelle',
		'projets_supplementaires' => array( 'annexe_garage' ),
		'pieces_differees'        => array( 'plans' ),
		'prenom'                  => 'Camille',
		'nom'                     => 'Fictif',
	),
	array(
		'base'           => 849,
		'options'        => array(
			array( 'id' => 'projet_supplementaire:annexe_garage', 'price' => 100 ),
			array( 'id' => 'secteur_abf', 'price' => 80 ),
			array( 'id' => 'depot_guichet', 'price' => 30 ),
		),
		'total'          => 1059,
		'pricing_status' => 'estime',
	)
);

$pc = CustomerAcknowledgementRenderer::render( $id, 1000 );

check( '25 · l’accusé d’un permis de construire se rend', is_array( $pc ) );
check( '25 · adressé au demandeur validé', 'camille@exemple.test' === $pc['to'] );
check( '25 · avec le sujet arrêté', 'Votre demande Urbizen a bien été reçue — URB-2026-0077' === $pc['subject'] );

$corps_pc = (string) $pc['body'];

check( '26 · la démarche est nommée telle qu’elle se dit',
	str_contains( $corps_pc, 'Type de démarche : Permis de construire' ) );
check( '26 · jamais sous son identifiant technique',
	! str_contains( $corps_pc, 'permis_construire' ) );

// Les libellés viennent du catalogue PC. Sans résolution par type, ils
// seraient vides : « maison_individuelle » n'existe pas au catalogue DP.
check( '27 · le projet principal porte son libellé PC', str_contains( $corps_pc, 'Maison neuve' ) );
check( '27 · le projet supplémentaire aussi', str_contains( $corps_pc, 'Annexe / garage' ) );
check( '27 · la pièce différée est nommée', str_contains( $corps_pc, 'Plans existants en votre possession' ) );
check( '27 · le montant serveur y figure', str_contains( $corps_pc, '1059 €' ) );
check( '27 · la mention imposée est présente', str_contains( $corps_pc, CustomerAcknowledgementRenderer::MENTION ) );
check( '27 · la salutation compose prénom puis nom', str_contains( $corps_pc, 'Bonjour Camille Fictif,' ) );

foreach ( array( 'secret-interne', '/var/private', '_urbizen_', 'received', 'http://', 'https://' ) as $interdit ) {
	check( sprintf( '28 · « %s » n’apparaît pas dans l’accusé PC', $interdit ), ! str_contains( $corps_pc, $interdit ) );
}

// --- le cas sur étude ---

$GLOBALS['wpd_meta'] = array();
$id = demande_persistee(
	'permis_construire',
	array(
		'nature'                  => 'autre',
		'projets_supplementaires' => array( 'extension' ),
		'pieces_differees'        => array(),
	),
	array(
		'base'           => null,
		'options'        => array(
			array( 'id' => 'projet_supplementaire:extension', 'price' => 100 ),
			array( 'id' => 'secteur_abf', 'price' => 80 ),
		),
		'total'          => null,
		'pricing_status' => 'sur_etude',
	)
);

$etude = (string) CustomerAcknowledgementRenderer::render( $id, 1000 )['body'];

check( '29 · un dossier sur étude le dit', str_contains( $etude, 'Tarif sur étude' ) );
check( '29 · et surtout pas « 0 € »', ! str_contains( $etude, '0 €' ) );
check( '29 · aucun total fabriqué depuis les suppléments', ! str_contains( $etude, '180 €' ) );
check( '29 · la mention reste présente', str_contains( $etude, CustomerAcknowledgementRenderer::MENTION ) );
check( '29 · rien ne présente le message comme une commande',
	! str_contains( $etude, 'facture' ) && ! str_contains( $etude, 'devis accepté' )
	&& ! str_contains( $etude, 'commande confirmée' ) );

// --- résolution par créneau, et indépendance des deux messages ---

$admin_pc  = NotificationSlot::admin( $id );
$client_pc = NotificationSlot::client( $id );

check( '30 · le créneau administratif PC a sa stratégie interne',
	NotificationStrategyRegistry::for_slot( 'permis_construire', $admin_pc )
		instanceof PermisConstruireNotificationStrategy );
check( '30 · le créneau client PC reçoit l’accusé',
	NotificationStrategyRegistry::for_slot( 'permis_construire', $client_pc )
		instanceof CustomerAcknowledgementStrategy );
check( '30 · le PC figure à la liste blanche des accusés',
	NotificationStrategyRegistry::has_customer_acknowledgement( 'permis_construire' ) );

MailQueue::create_pending( $id, 1000 );
MailQueue::create_pending( $id, 1000, $client_pc );
MailQueue::mark_sending( $id, 1, 1000, $client_pc );
MailQueue::mark_failure( $id, 1, 'transport_refused', 1000, $client_pc );

check( '31 · les deux créneaux PC ont des identifiants distincts',
	MailQueue::state( $id )['notification_id'] !== MailQueue::state( $id, $client_pc )['notification_id'] );
check( '31 · l’échec de l’accusé PC ne touche pas la notification interne',
	MailPolicy::PENDING === MailQueue::state( $id )['status']
	&& MailPolicy::RETRY === MailQueue::state( $id, $client_pc )['status'] );
check( '31 · chaque créneau compte ses propres tentatives',
	0 === MailQueue::state( $id )['attempts'] && 1 === MailQueue::state( $id, $client_pc )['attempts'] );
check( '31 · les clés d’idempotence diffèrent',
	$admin_pc->idempotence( 'URB-2026-0077' ) !== $client_pc->idempotence( 'URB-2026-0077' ) );

// ======================================================================
// 12 · SOURCE UNIQUE
// ======================================================================

$source = (string) file_get_contents( URBIZEN_PLATFORM_DIR . 'src/Mail/CustomerAcknowledgementRenderer.php' );

check( '24 · l’accusé ne fabrique aucun lien signé',
	! str_contains( $source, 'SignedLink' ) );
check( '32 · l’accusé n’est écrit qu’une fois, pour tous les parcours',
	! str_contains( $source, 'CatalogueDeclarationPrealable' )
	&& ! str_contains( $source, 'CataloguePermisConstruire' )
	&& str_contains( $source, 'CatalogueRegistry' ) );
check( '24 · et ne choisit son destinataire que par la charge persistée',
	str_contains( $source, 'MailPolicy::customer_recipient' )
	&& ! str_contains( $source, 'MailPolicy::recipient()' )
	&& ! str_contains( $source, '$_POST' ) );

exit( $GLOBALS['fail'] > 0 ? 1 : 0 );
