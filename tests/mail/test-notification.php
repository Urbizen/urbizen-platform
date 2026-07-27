<?php
/**
 * Banc d'essai des STRATÉGIES DE NOTIFICATION par type serveur (Lot 1, incr. 7).
 *
 * La notification interne est résolue depuis le TYPE serveur de la demande
 * persistée ; le client ne choisit ni la stratégie, ni le destinataire, ni le
 * sujet. Conception garde son courriel exact ; Localisation n'a aucune
 * notification ; une définition au type incohérent est rejetée (invariant).
 *
 * Réutilise le harnais tests/submissions. Aucun courriel réel. Données fictives.
 * Décision : D-050.
 */

require __DIR__ . '/../submissions/bootstrap.php';

use Urbizen\Platform\Files\UploadProfileRegistry;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\PricingStrategyRegistry;
use Urbizen\Platform\Mail\ConceptionNotificationStrategy;
use Urbizen\Platform\Mail\MailPolicy;
use Urbizen\Platform\Mail\MailRenderer;
use Urbizen\Platform\Mail\NotificationStrategy;
use Urbizen\Platform\Mail\NotificationStrategyRegistry as R;
use Urbizen\Platform\Submissions\SubmissionRepository;

// Destinataire interne serveur, pour le harnais (jamais un client).
add_filter( 'urbizen_submission_recipient', static fn() => 'interne@example.test' );

/** Stratégie de notification fictive, réservée aux tests. */
final class StrategieNotifFictive implements NotificationStrategy {
	public function build( int $id, int $now ): ?array {
		return array(
			'to'      => 'boite-fictive@example.test',
			'subject' => '[Fictif] Nouveau devis',
			'body'    => '<p>Corps fictif.</p>',
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);
	}
}

/** Code d'un fichier, commentaires retirés. */
function code_seul_notif( string $chemin ): string {
	$out = '';
	foreach ( token_get_all( (string) file_get_contents( $chemin ) ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= $t[1];
		} else {
			$out .= $t;
		}
	}
	return $out;
}

// ======================================================================
// A · RÉSOLUTION DE LA STRATÉGIE DEPUIS LE TYPE SERVEUR
// ======================================================================
check( 'A · conception → ConceptionNotificationStrategy', R::for_type( 'conception' ) instanceof ConceptionNotificationStrategy );
$sans = array();
foreach ( array( 'localisation', 'dp', 'pc', 'pcmi', 'cerfa', 'contact', 'inconnu' ) as $t ) {
	if ( null !== R::for_type( $t ) || R::has( $t ) ) {
		$sans[] = $t;
	}
}
check( 'A · aucune stratégie pour localisation/dp/pc/cerfa/… (null)', array() === $sans );
$leve = false;
try {
	R::require_for_type( 'dp' );
} catch ( \RuntimeException $e ) {
	$leve = true;
}
check( 'A · require_for_type(dp) → exception contrôlée', $leve );

$reg = code_seul_notif( URBIZEN_PLATFORM_DIR . 'src/Mail/NotificationStrategyRegistry.php' );
$sch = code_seul_notif( URBIZEN_PLATFORM_DIR . 'src/Mail/MailScheduler.php' );
check( 'A · le registre ne lit aucune superglobale', ! str_contains( $reg, '$_POST' ) && ! str_contains( $reg, '$_GET' ) && ! str_contains( $reg, '$_REQUEST' ) );
check( 'A · le planificateur résout le type depuis _urbizen_form_type (pas $_POST)', str_contains( $sch, '_urbizen_form_type' ) && ! str_contains( $sch, '$_POST' ) );

// ======================================================================
// Demande Conception persistée, avec une valeur hostile dans le payload.
// ======================================================================
$creation = SubmissionRepository::create(
	array( 'note_libre' => '<script>alert(1)</script>' ),
	array( 'base' => 449, 'options' => array(), 'sur_devis' => array(), 'total' => 449, 'devis_requis' => false ),
	array( 'now' => 1700000000 )
);
$id = (int) $creation['id'];
check( 'la demande Conception de test est créée', 0 < $id && '' !== $creation['reference'] );

$m = MailRenderer::render( $id, 1700000000 );
check( 'le message se rend', is_array( $m ) && isset( $m['to'], $m['subject'], $m['body'], $m['headers'] ) );

// ======================================================================
// B · DESTINATAIRE — configuration serveur, jamais $_POST
// ======================================================================
check( 'B · destinataire = MailPolicy::recipient() (serveur)', $m['to'] === MailPolicy::recipient() && 'interne@example.test' === $m['to'] );
$_POST = array( 'recipient' => 'pirate@evil.test', 'to' => 'pirate@evil.test', 'cc' => 'x@evil.test', 'bcc' => 'y@evil.test', 'from' => 'z@evil.test', 'reply_to' => 'w@evil.test' );
$m2    = MailRenderer::render( $id, 1700000000 );
$_POST = array();
check( 'B · $_POST (recipient/to/cc/bcc/from/reply_to) ne change pas la destination', $m['to'] === $m2['to'] && ! str_contains( (string) wp_json_encode( $m2 ), 'evil.test' ) );

// ======================================================================
// C · SUJET — serveur, référence seule, neutralisation CRLF
// ======================================================================
check( 'C · sujet serveur = référence seule', $m['subject'] === '[Urbizen] Nouvelle demande ' . $creation['reference'] );
check( 'C · un $_POST subject ne change pas le sujet', $m['subject'] === $m2['subject'] );
$sujet_crlf = MailRenderer::subject( "URB-2026-0001\r\nBcc: pirate@evil.test" );
check( 'C · le sujet neutralise CR/LF (pas d’injection d’en-tête)', ! str_contains( $sujet_crlf, "\n" ) && ! str_contains( $sujet_crlf, "\r" ) );

// ======================================================================
// D · CORPS — les données utilisateur restent échappées
// ======================================================================
check( 'D · une valeur hostile du payload est échappée dans le corps', ! str_contains( $m['body'], '<script>alert(1)</script>' ) && str_contains( $m['body'], '&lt;script&gt;' ) );

// ======================================================================
// E · EN-TÊTES — pas de Reply-To avec l'adresse du demandeur
// ======================================================================
$reply = array_filter( $m['headers'], static fn( $h ) => 0 === stripos( (string) $h, 'reply-to:' ) );
check( 'E · aucun Reply-To avec l’adresse du demandeur (notification interne)', array() === $reply );
$hostiles_entete = array_filter( $m['headers'], static fn( $h ) => 0 === stripos( (string) $h, 'bcc:' ) || 0 === stripos( (string) $h, 'cc:' ) || 0 === stripos( (string) $h, 'from:' ) );
check( 'E · aucun en-tête Bcc/Cc/From ajouté', array() === $hostiles_entete );

// ======================================================================
// §18 · NON-RÉGRESSION — la stratégie Conception délègue à MailRenderer
// ======================================================================
$strat = R::for_type( 'conception' );
check( '18 · la stratégie Conception produit exactement le message MailRenderer', $strat->build( $id, 1700000000 ) === MailRenderer::render( $id, 1700000000 ) );

// ======================================================================
// §16 · STRATÉGIE FICTIVE — le contrat générique est réutilisable
// ======================================================================
$f  = new StrategieNotifFictive();
$mf = $f->build( 1, 1700000000 );
check( '16 · la stratégie fictive respecte le contrat + destinataire factice', $f instanceof NotificationStrategy && str_contains( $mf['to'], 'example.test' ) );
check( '16 · Conception ne bascule pas vers elle (registre = conception seul)', R::for_type( 'conception' ) instanceof ConceptionNotificationStrategy && null === R::for_type( 'devis_fictif' ) );
$src_if = code_seul_notif( URBIZEN_PLATFORM_DIR . 'src/Mail/NotificationStrategy.php' );
$fuites = array_filter( array( 'conception', 'MailRenderer', 'MailPolicy' ), static fn( $s ) => str_contains( $src_if, $s ) );
check( '16 · l’interface NotificationStrategy ne contient aucune chaîne Conception', array() === $fuites );

// ======================================================================
// §2 · INVARIANT DES TYPES + FIXTURE INCOHÉRENTE
// ======================================================================
check( '2 · invariant : « conception » partout (definition + 3 registres)',
	'conception' === FormRegistry::get( 'conception' )->type()
		&& PricingStrategyRegistry::has( 'conception' )
		&& UploadProfileRegistry::has( 'conception' )
		&& R::has( 'conception' ) );

// Une définition déclarant un type différent de sa clé est rejetée par le
// registre (fichier temporaire, supprimé aussitôt).
$fic = URBIZEN_PLATFORM_DIR . 'src/Forms/definitions/incoherent_test.php';
file_put_contents( $fic, "<?php return array( 'type' => 'autre_type', 'title' => 'X', 'submit_label' => 'X', 'fields' => array( array( 'name' => 'a', 'type' => 'text', 'step' => 's' ) ), 'steps' => array( array( 'id' => 's', 'label' => 'S' ) ) );" );
FormRegistry::register( 'incoherent_test' );
$def_incoherente = FormRegistry::get( 'incoherent_test' );
@unlink( $fic );
check( '2 · une définition au type incohérent (autre_type sous clé incoherent_test) est rejetée', null === $def_incoherente );

verdict();
