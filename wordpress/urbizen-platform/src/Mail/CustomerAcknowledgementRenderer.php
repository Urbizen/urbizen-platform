<?php
/**
 * Rendu de l'accusé de réception adressé au demandeur.
 *
 * Ce message n'est pas une variante du message administratif : il en est
 * l'exact contraire. La notification interne existe pour qu'Urbizen puisse
 * instruire un dossier — elle porte donc toutes les réponses, le détail
 * tarifaire et les liens vers les documents. L'accusé, lui, existe pour qu'une
 * personne sache que sa demande est arrivée. Il ne doit rien contenir de plus.
 *
 * D'où trois règles, qui sont des règles de sûreté autant que de style :
 *
 * 1. **Aucun lien signé, jamais.** Les liens de téléchargement sont temporaires
 *    mais réels ; les envoyer dans un message qui traverse la boîte d'un tiers,
 *    est archivé, transféré et indexé, reviendrait à publier les pièces du
 *    dossier. Un accusé ne contient donc aucune URL de document.
 * 2. **Aucune réponse recopiée.** Le demandeur sait ce qu'il a écrit. Le lui
 *    renvoyer n'ajoute rien et multiplie les endroits où ses données existent.
 * 3. **Aucun état technique.** Ni identifiant de post, ni statut interne, ni
 *    code d'erreur, ni nom de fichier. La référence suffit à retrouver le
 *    dossier, et elle est faite pour être communiquée.
 *
 * Le tarif est repris **tel qu'il a été calculé et persisté par le serveur** —
 * jamais recalculé ici, jamais repris du navigateur — et il est présenté pour
 * ce qu'il est : une estimation, que rien n'engage encore.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

use Urbizen\Platform\Forms\CatalogueRegistry;
use Urbizen\Platform\Forms\AdresseTerrain;
use Urbizen\Platform\Forms\PrecisionsProjet;
use Urbizen\Platform\Submissions\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique le sujet, le corps et les en-têtes de l'accusé client.
 */
final class CustomerAcknowledgementRenderer {

	/**
	 * Mention tarifaire, imposée au caractère près.
	 *
	 * Elle est la seule chose qui empêche une estimation d'être lue comme un
	 * devis. Sa formulation est un arbitrage produit, pas une tournure : elle ne
	 * se reformule pas, et un banc en vérifie l'exactitude littérale.
	 */
	public const MENTION = 'Estimation indicative. Le tarif définitif sera confirmé par Urbizen après vérification de votre projet, avant toute commande.';

	/**
	 * Ce qui va se passer, et sous quel délai.
	 *
	 * La formulation dit une vérification et une prise de contact — jamais un
	 * devis accepté, une commande confirmée, un dossier validé ni un dépôt
	 * effectué. C'est la même information que l'écran final, adaptée au fait
	 * qu'on écrit après coup et non au moment du clic. Un banc en vérifie la
	 * présence exacte, ici et sur les deux écrans.
	 */
	public const SUITE = 'Un interlocuteur Urbizen vérifiera les informations transmises et prendra contact avec vous sous 24 heures ouvrées afin de confirmer votre besoin, les éventuelles pièces complémentaires et le tarif définitif avant toute commande.';

	/**
	 * Rend l'accusé complet d'une demande.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @return array{to:string,subject:string,body:string,headers:array<int,string>}|null
	 */
	public static function render( int $id, ?int $now = null ): ?array {
		$demande = SubmissionRepository::get( $id );

		if ( null === $demande ) {
			return null;
		}

		// Le destinataire vient de la charge **persistée et validée**, jamais de
		// la requête. Sans adresse exploitable, il n'y a pas d'accusé — et pas
		// de repli sur l'adresse administrative, qui recevrait un message écrit
		// pour quelqu'un d'autre.
		$destinataire = MailPolicy::customer_recipient( $id );

		if ( '' === $destinataire ) {
			return null;
		}

		$reference    = (string) $demande['reference'];
		$slot         = NotificationSlot::client( $id );
		$notification = (string) get_post_meta( $id, $slot->cle( MailPolicy::META_ID ), true );

		return array(
			'to'      => $destinataire,
			'subject' => self::subject( $reference ),
			'body'    => self::body( $demande ),
			'headers' => self::headers( $notification ),
		);
	}

	/**
	 * Sujet du message.
	 *
	 * Il porte la référence, et rien d'autre : un sujet est visible dans une
	 * liste de messages, parfois sur un écran verrouillé. Les retours chariot
	 * sont retirés — un sujet multiligne permettrait d'injecter un en-tête.
	 *
	 * @param string $reference Référence de la demande.
	 * @return string
	 */
	public static function subject( string $reference ): string {
		return self::une_ligne( sprintf( 'Votre demande Urbizen a bien été reçue — %s', $reference ) );
	}

	/**
	 * En-têtes du message.
	 *
	 * Pas de `Reply-To` fabriqué, pas de `Cc`, pas de `Bcc`. L'identifiant
	 * technique est celui du créneau client, distinct de celui de la
	 * notification interne : un même dossier ne doit pas produire deux messages
	 * porteurs du même identifiant.
	 *
	 * @param string $notification Identifiant de notification du créneau client.
	 * @return array<int, string>
	 */
	public static function headers( string $notification ): array {
		$entetes = array( 'Content-Type: text/html; charset=UTF-8' );
		$propre  = preg_replace( '/[^A-Za-z0-9]/', '', $notification );

		if ( is_string( $propre ) && '' !== $propre ) {
			$entetes[] = 'X-Urbizen-Notification-ID: ' . $propre;
		}

		return $entetes;
	}

	/**
	 * Corps HTML de l'accusé.
	 *
	 * HTML volontairement pauvre : le message doit rester lisible une fois les
	 * styles retirés, ce que font la plupart des clients de messagerie.
	 *
	 * @param array<string, mixed> $demande Demande complète.
	 * @return string
	 */
	public static function body( array $demande ): string {
		$reference = (string) $demande['reference'];
		$type      = (string) ( $demande['form_type'] ?? '' );
		$charge    = is_array( $demande['payload'] ?? null ) ? $demande['payload'] : array();
		$html      = array();

		$html[] = '<div style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#12233b">';
		$html[] = '<p style="margin:0 0 16px">Bonjour' . self::salutation( $charge ) . ',</p>';
		$html[] = '<p style="margin:0 0 16px">Nous avons bien reçu votre demande. Elle est enregistrée sous la référence suivante :</p>';
		$html[] = '<p style="margin:0 0 20px;font-size:18px"><strong>' . esc_html( $reference ) . '</strong></p>';
		$html[] = self::demarche( $type );

		$html[] = self::adresse( $charge );
		$html[] = self::projet( $type, $charge );
		$html[] = self::precisions( $charge );
		$html[] = self::estimation( is_array( $demande['pricing'] ?? null ) ? $demande['pricing'] : array() );
		$html[] = self::a_transmettre( $type, $charge );

		$html[] = '<p style="margin:20px 0 0">' . esc_html( self::SUITE ) . '</p>';
		$html[] = '<p style="margin:12px 0 0">Vous pouvez répondre à ce message en indiquant la référence ci-dessus.</p>';
		$html[] = '<p style="margin:16px 0 0;font-size:12px;color:#5b6b80">Ce message est un accusé de réception automatique. ';
		$html[] = 'Aucun document n\'y est joint.</p>';
		$html[] = '</div>';

		return implode( "\n", array_filter( $html, static fn( $ligne ) => '' !== $ligne ) );
	}

	/**
	 * Fin de la salutation, si un nom exploitable a été validé.
	 *
	 * Les formulaires ne nomment pas les personnes de la même façon : certains
	 * ont un seul champ `nom` portant le nom complet, la déclaration préalable
	 * sépare `prenom` et `nom`. Saluer d'après le seul champ `nom` donnerait
	 * « Bonjour Fictif » là où le client a écrit « Camille Fictif » — correct
	 * techniquement, désagréable à lire.
	 *
	 * Aucun nom exploitable rend une chaîne vide : « Bonjour, » est une
	 * salutation acceptable, « Bonjour , » ne l'est pas.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string Chaîne vide si aucun nom n'est utilisable.
	 */
	private static function salutation( array $charge ): string {
		$morceaux = array();

		foreach ( array( 'prenom', 'nom' ) as $champ ) {
			$valeur = isset( $charge[ $champ ] ) && is_string( $charge[ $champ ] ) ? trim( $charge[ $champ ] ) : '';

			if ( '' !== $valeur ) {
				$morceaux[] = $valeur;
			}
		}

		return array() === $morceaux ? '' : ' ' . esc_html( implode( ' ', $morceaux ) );
	}

	/**
	 * Nature administrative de la démarche, nommée telle qu'elle se dit.
	 *
	 * Le client a rempli un formulaire, pas choisi un type serveur : il doit
	 * lire « Permis de construire », jamais `permis_construire`. Un type sans
	 * intitulé public n'en invente pas un — la ligne disparaît.
	 *
	 * @param string $type Type de formulaire.
	 * @return string
	 */
	private static function demarche( string $type ): string {
		$intitules = array(
			'declaration_prealable' => 'Déclaration préalable de travaux',
			'permis_construire'     => 'Permis de construire',
		);

		if ( ! isset( $intitules[ $type ] ) ) {
			return '';
		}

		return '<p style="margin:0 0 20px;color:#5b6b80">Type de démarche : '
			. esc_html( $intitules[ $type ] ) . '</p>';
	}

	/**
	 * Rappel du projet, sous les libellés que le client a vus.
	 *
	 * @param string               $type   Type de formulaire.
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string
	 */
	private static function projet( string $type, array $charge ): string {
		$nature  = isset( $charge['nature'] ) ? (string) $charge['nature'] : '';
		$libelle = CatalogueRegistry::libelle_nature( $type, $nature );

		if ( null === $libelle ) {
			return '';
		}

		$html   = array();
		$html[] = '<p style="margin:0 0 8px"><strong>Votre projet</strong></p>';
		$html[] = '<p style="margin:0 0 16px">' . esc_html( $libelle );

		$supplementaires = array();

		foreach ( (array) ( $charge['projets_supplementaires'] ?? array() ) as $projet ) {
			$autre = CatalogueRegistry::libelle_nature( $type, (string) $projet );

			if ( null !== $autre ) {
				$supplementaires[] = $autre;
			}
		}

		if ( array() !== $supplementaires ) {
			$html[] = '<br><span style="color:#5b6b80">Projets supplémentaires : '
				. esc_html( implode( ', ', $supplementaires ) ) . '</span>';
		}

		$html[] = '</p>';

		return implode( '', $html );
	}

	/**
	 * Rappel d'une phrase de ce que le client a communiqué.
	 *
	 * Un accusé n'est pas un dossier technique. On ne recopie donc pas la
	 * rubrique complète de la notification interne : une ligne suffit à montrer
	 * que l'information est bien arrivée, ce qui est tout ce que le client
	 * cherche à vérifier. Rien n'est affiché s'il n'a rien précisé.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string
	 */
	/**
	 * L'adresse du terrain, telle qu'elle se lit.
	 *
	 * Rien d'autre : ni le mode de saisie, ni le code commune, ni les
	 * coordonnées. Le demandeur connaît son terrain — ce qu'il vérifie ici,
	 * c'est qu'Urbizen l'a bien noté, pas ce qu'Urbizen en a déduit.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string Chaîne vide s'il n'y a pas d'adresse.
	 */
	private static function adresse( array $charge ): string {
		$lignes = AdresseTerrain::lignes_adresse( $charge );

		if ( array() === $lignes ) {
			return '';
		}

		$html   = array();
		$html[] = '<p style="margin:0 0 8px"><strong>Adresse du terrain</strong></p>';
		$html[] = '<p style="margin:0 0 16px">' . implode( '<br>', array_map( 'esc_html', $lignes ) ) . '</p>';

		return implode( "\n", $html );
	}

	private static function precisions( array $charge ): string {
		$resume = PrecisionsProjet::resume( $charge );

		if ( '' === $resume ) {
			return '';
		}

		return '<p style="margin:0 0 20px"><strong>Informations communiquées :</strong><br>'
			. esc_html( $resume ) . '</p>';
	}

	/**
	 * Estimation tarifaire, telle que le serveur l'a calculée et enregistrée.
	 *
	 * Un total absent n'est pas un zéro : c'est un tarif sur étude, et il se dit
	 * comme tel. Afficher « 0 € » serait un engagement, et un engagement faux.
	 *
	 * @param array<string, mixed> $tarif Tarif persisté.
	 * @return string
	 */
	private static function estimation( array $tarif ): string {
		if ( array() === $tarif ) {
			return '';
		}

		$total   = array_key_exists( 'total', $tarif ) ? $tarif['total'] : null;
		$montant = null === $total ? 'Tarif sur étude' : (int) $total . ' €';

		$html   = array();
		$html[] = '<p style="margin:0 0 8px"><strong>Estimation</strong></p>';
		$html[] = '<p style="margin:0 0 6px;font-size:17px">' . esc_html( $montant ) . '</p>';
		$html[] = '<p style="margin:0 0 20px;font-size:12px;color:#5b6b80">' . esc_html( self::MENTION ) . '</p>';

		return implode( '', $html );
	}

	/**
	 * Ce que le client a annoncé transmettre plus tard.
	 *
	 * Rappelé sans reproche : ces éléments ne bloquent pas l'instruction, et le
	 * formulaire l'a déjà dit. Le taire ici laisserait croire que le dossier est
	 * complet.
	 *
	 * @param string               $type   Type de formulaire.
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string
	 */
	private static function a_transmettre( string $type, array $charge ): string {
		$elements = array();

		foreach ( (array) ( $charge['pieces_differees'] ?? array() ) as $piece ) {
			$libelle = CatalogueRegistry::libelle_piece( $type, (string) $piece );

			if ( null !== $libelle ) {
				$elements[] = $libelle;
			}
		}

		if ( self::option_active( $charge, 'informations_cadastrales_differees' ) ) {
			$elements[] = 'Informations cadastrales';
		}

		if ( array() === $elements ) {
			return '';
		}

		$html   = array();
		$html[] = '<p style="margin:0 0 8px"><strong>À transmettre ultérieurement</strong></p>';
		$html[] = '<ul style="margin:0 0 8px;padding-left:20px">';

		foreach ( $elements as $element ) {
			$html[] = '<li>' . esc_html( $element ) . '</li>';
		}

		$html[] = '</ul>';
		$html[] = '<p style="margin:0 0 20px;font-size:12px;color:#5b6b80">';
		$html[] = 'Ces éléments ne bloquent pas l’instruction de votre demande.</p>';

		return implode( '', $html );
	}

	/**
	 * Une option à liste fermée est-elle active ?
	 *
	 * Le validateur normalise une case à options en tableau de valeurs retenues.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @param string               $champ  Nom du champ.
	 * @return bool
	 */
	private static function option_active( array $charge, string $champ ): bool {
		$valeur = $charge[ $champ ] ?? null;

		if ( is_array( $valeur ) ) {
			return in_array( 'oui', array_map( 'strval', $valeur ), true );
		}

		return is_scalar( $valeur ) && 'oui' === (string) $valeur;
	}

	/**
	 * Réduit une chaîne à une seule ligne.
	 *
	 * @param string $valeur Chaîne.
	 * @return string
	 */
	private static function une_ligne( string $valeur ): string {
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $valeur ) );
	}
}
