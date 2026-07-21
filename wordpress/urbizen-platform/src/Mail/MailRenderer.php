<?php
/**
 * Rendu du message administratif.
 *
 * Déterministe et testable : aucune requête HTTP, aucun accès à `$_POST` ni
 * `$_FILES`, aucune écriture. On lui donne une demande, il rend un message.
 *
 * Toute donnée venant du client traverse un échappement **choisi selon sa
 * destination** : texte, attribut, URL, sujet, en-tête. Un nom de fichier
 * hostile, une adresse contenant du HTML, une note contenant du JavaScript ou
 * un retour chariot glissé dans un champ ne doivent pouvoir ni casser le HTML,
 * ni injecter un lien, ni ajouter un en-tête, ni changer le destinataire.
 *
 * Aucune pièce jointe : les documents ne sont accessibles que par les liens
 * signés de B2, temporaires et vérifiés.
 *
 * @package Urbizen\Platform\Mail
 */

namespace Urbizen\Platform\Mail;

use Urbizen\Platform\Files\SignedLink;
use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Submissions\SubmissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Fabrique le sujet, le corps et les en-têtes.
 */
final class MailRenderer {

	/**
	 * Libellés lisibles des blocs de dépôt.
	 *
	 * @var array<string, string>
	 */
	private const LIBELLES_BLOCS = array(
		'croquis_plans'     => 'Croquis et plans',
		'plan_terrain'      => 'Plan du terrain',
		'photos'            => 'Photographies',
		'inspirations_docs' => 'Inspirations et documents',
		'urbanisme'         => 'Pièces d\'urbanisme',
	);

	/**
	 * Champs du formulaire à ne jamais reproduire dans le message.
	 *
	 * Ni technique, ni utile à l'instruction du dossier.
	 *
	 * @var array<int, string>
	 */
	private const CHAMPS_EXCLUS = array(
		'urbizen_token',
		'urbizen_return',
		'urbizen_website',
		'rgpd',
	);

	/**
	 * Rend le message complet d'une demande.
	 *
	 * @param int      $id  Demande.
	 * @param int|null $now Horodatage courant.
	 * @return array{to:string,subject:string,body:string,headers:array<int,string>}|null
	 */
	public static function render( int $id, ?int $now = null ): ?array {
		$now     = null === $now ? time() : $now;
		$demande = SubmissionRepository::get( $id );

		if ( null === $demande ) {
			return null;
		}

		$destinataire = MailPolicy::recipient();

		if ( '' === $destinataire ) {
			return null;
		}

		$reference    = (string) $demande['reference'];
		$notification = (string) get_post_meta( $id, MailPolicy::META_ID, true );

		return array(
			'to'      => $destinataire,
			'subject' => self::subject( $reference ),
			'body'    => self::body( $demande, $now ),
			'headers' => self::headers( $notification ),
		);
	}

	/**
	 * Sujet du message.
	 *
	 * Ne contient que la référence — jamais un nom, un courriel, un téléphone,
	 * une adresse ou un nom de fichier. Les retours chariot sont retirés :
	 * un sujet multiligne permettrait d'injecter un en-tête.
	 *
	 * @param string $reference Référence de la demande.
	 * @return string
	 */
	public static function subject( string $reference ): string {
		return self::une_ligne( sprintf( '[Urbizen] Nouvelle demande %s', $reference ) );
	}

	/**
	 * En-têtes du message.
	 *
	 * @param string $notification Identifiant de notification.
	 * @return array<int, string>
	 */
	public static function headers( string $notification ): array {
		$entetes = array( 'Content-Type: text/html; charset=UTF-8' );

		// Identifiant technique, sans donnée personnelle : il permet de
		// reconnaître un doublon dans la boîte de réception.
		$propre = preg_replace( '/[^A-Za-z0-9]/', '', $notification );

		if ( is_string( $propre ) && '' !== $propre ) {
			$entetes[] = 'X-Urbizen-Notification-ID: ' . $propre;
		}

		return $entetes;
	}

	/**
	 * Corps HTML du message.
	 *
	 * HTML simple, styles en ligne réduits : le message doit rester lisible
	 * lorsque les styles sont retirés.
	 *
	 * @param array<string, mixed> $demande Demande complète.
	 * @param int                  $now     Horodatage courant.
	 * @return string
	 */
	public static function body( array $demande, int $now ): string {
		$id        = (int) $demande['id'];
		$reference = (string) $demande['reference'];
		$html      = array();

		$html[] = '<div style="font-family:sans-serif;font-size:14px;line-height:1.5;color:#12233b">';
		$html[] = '<h1 style="font-size:18px;margin:0 0 12px">Nouvelle demande ' . esc_html( $reference ) . '</h1>';

		// --- Dossier ---
		$html[] = self::titre( 'Dossier' );
		$html[] = self::table(
			array(
				'Référence'        => $reference,
				'Reçue le (UTC)'   => (string) $demande['created_at_gmt'],
				'Type de dossier'  => (string) $demande['form_type'],
				'Consentement'     => (string) $demande['consent_at_gmt'],
			)
		);

		// --- Réponses du demandeur ---
		$html[] = self::titre( 'Réponses' );
		$html[] = self::table( self::reponses( $demande ) );

		// --- Tarification ---
		$html[] = self::titre( 'Tarification' );
		$html[] = self::tarification( $demande['pricing'] );

		// --- Documents ---
		$html[] = self::titre( 'Documents' );
		$html[] = self::documents( $id, (array) $demande['files'], $now );

		$html[] = '<p style="margin:20px 0 0;font-size:12px;color:#5b6b80">';
		$html[] = 'Les liens ci-dessus sont temporaires et personnels au dossier. ';
		$html[] = 'Aucun document n\'est joint à ce message.';
		$html[] = '</p>';
		$html[] = '</div>';

		return implode( "\n", $html );
	}

	/**
	 * Réponses du formulaire, libellées lorsque la définition les connaît.
	 *
	 * @param array<string, mixed> $demande Demande.
	 * @return array<string, string>
	 */
	private static function reponses( array $demande ): array {
		$payload = is_array( $demande['payload'] ) ? $demande['payload'] : array();
		$def     = FormRegistry::get( (string) $demande['form_type'] );
		$lignes  = array();

		foreach ( $payload as $nom => $valeur ) {
			$nom = (string) $nom;

			if ( in_array( $nom, self::CHAMPS_EXCLUS, true ) ) {
				continue;
			}

			$champ    = null !== $def ? $def->field( $nom ) : null;
			$libelle  = is_array( $champ ) && isset( $champ['label'] ) ? (string) $champ['label'] : $nom;
			$lignes[ $libelle ] = self::aplatir( $valeur );
		}

		return array() === $lignes ? array( 'Aucune réponse enregistrée' => '—' ) : $lignes;
	}

	/**
	 * Bloc de tarification.
	 *
	 * @param array<string, mixed> $pricing Tarification calculée.
	 * @return string
	 */
	private static function tarification( array $pricing ): string {
		if ( array() === $pricing ) {
			return '<p style="margin:0 0 16px">Aucune tarification enregistrée.</p>';
		}

		$lignes = array(
			'Base'  => self::euros( (int) ( $pricing['base'] ?? 0 ) ),
			'Total' => self::euros( (int) ( $pricing['total'] ?? 0 ) ),
		);

		$options = isset( $pricing['options'] ) && is_array( $pricing['options'] ) ? $pricing['options'] : array();

		foreach ( $options as $rang => $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$lignes[ 'Option — ' . (string) ( $option['id'] ?? (string) $rang ) ] = self::euros( (int) ( $option['price'] ?? 0 ) );
		}

		$devis = isset( $pricing['sur_devis'] ) && is_array( $pricing['sur_devis'] ) ? $pricing['sur_devis'] : array();

		if ( array() !== $devis ) {
			$lignes['Sur devis'] = implode( ', ', array_map( 'strval', $devis ) );
		}

		if ( ! empty( $pricing['devis_requis'] ) ) {
			$lignes['Devis requis'] = 'oui';
		}

		return self::table( $lignes );
	}

	/**
	 * Bloc des documents, regroupés par bloc du formulaire.
	 *
	 * @param int                            $id    Demande.
	 * @param array<int, array<string,mixed>> $files Documents.
	 * @param int                            $now   Horodatage courant.
	 * @return string
	 */
	private static function documents( int $id, array $files, int $now ): string {
		if ( array() === $files ) {
			return '<p style="margin:0 0 16px">Aucun document joint à cette demande.</p>';
		}

		$total   = 0;
		$groupes = array();

		foreach ( $files as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$bloc               = (string) ( $file['block'] ?? 'autres' );
			$groupes[ $bloc ][] = $file;
			$total             += (int) ( $file['size'] ?? 0 );
		}

		$html   = array();
		$html[] = sprintf(
			'<p style="margin:0 0 12px">%d document(s), %s au total.</p>',
			count( $files ),
			esc_html( size_format( $total ) )
		);

		foreach ( $groupes as $bloc => $documents ) {
			$libelle = self::LIBELLES_BLOCS[ $bloc ] ?? $bloc;

			$html[] = '<h3 style="font-size:14px;margin:16px 0 6px">' . esc_html( $libelle ) . '</h3>';
			$html[] = '<ul style="margin:0 0 8px;padding-left:18px">';

			foreach ( $documents as $document ) {
				$nom  = self::nom_lisible( (string) ( $document['original_name'] ?? '' ) );
				$lien = SignedLink::url( $id, (string) ( $document['id'] ?? '' ), $now );

				$html[] = sprintf(
					'<li style="margin:0 0 4px">%s — %s — <a href="%s" style="color:#1f5c3d">Télécharger</a></li>',
					esc_html( $nom ),
					esc_html( size_format( (int) ( $document['size'] ?? 0 ) ) ),
					esc_url( $lien )
				);
			}

			$html[] = '</ul>';
		}

		return implode( "\n", $html );
	}

	/**
	 * Rend un tableau de couples libellé / valeur.
	 *
	 * @param array<string, string> $lignes Couples.
	 * @return string
	 */
	private static function table( array $lignes ): string {
		$html = array( '<table style="border-collapse:collapse;margin:0 0 16px;width:100%">' );

		foreach ( $lignes as $libelle => $valeur ) {
			$html[] = sprintf(
				'<tr><th style="text-align:left;padding:4px 12px 4px 0;vertical-align:top;font-weight:600;width:34%%">%s</th><td style="padding:4px 0;vertical-align:top">%s</td></tr>',
				esc_html( (string) $libelle ),
				esc_html( '' === (string) $valeur ? '—' : (string) $valeur )
			);
		}

		$html[] = '</table>';

		return implode( "\n", $html );
	}

	/**
	 * Titre de section.
	 *
	 * @param string $texte Titre.
	 * @return string
	 */
	private static function titre( string $texte ): string {
		return '<h2 style="font-size:15px;margin:20px 0 8px;border-bottom:1px solid #d8dfe8;padding-bottom:4px">'
			. esc_html( $texte ) . '</h2>';
	}

	/**
	 * Réduit une valeur, éventuellement imbriquée, à une chaîne.
	 *
	 * @param mixed $valeur Valeur.
	 * @return string
	 */
	private static function aplatir( $valeur ): string {
		if ( is_array( $valeur ) ) {
			return implode( ', ', array_map( array( self::class, 'aplatir' ), $valeur ) );
		}

		if ( is_bool( $valeur ) ) {
			return $valeur ? 'oui' : 'non';
		}

		if ( ! is_scalar( $valeur ) ) {
			return '';
		}

		// Les retours chariot sont neutralisés dans le corps aussi : ils ne
		// permettraient rien ici, mais un rendu déterministe vaut mieux qu'un
		// rendu qui dépend de ce qu'un client a tapé.
		return self::une_ligne( (string) $valeur );
	}

	/**
	 * Nom de document lisible.
	 *
	 * @param string $nom Nom d'origine, déjà nettoyé au dépôt.
	 * @return string
	 */
	private static function nom_lisible( string $nom ): string {
		$nom = self::une_ligne( $nom );

		return '' === $nom ? 'document' : $nom;
	}

	/**
	 * Retire tout ce qui pourrait constituer une nouvelle ligne.
	 *
	 * CR, LF, ainsi que les séparateurs de ligne et de paragraphe Unicode :
	 * c'est ce qui sépare un sujet d'un en-tête supplémentaire.
	 *
	 * @param string $valeur Valeur.
	 * @return string
	 */
	private static function une_ligne( string $valeur ): string {
		$valeur = str_replace( array( "\r", "\n", "\t", "\u{2028}", "\u{2029}", "\0" ), ' ', $valeur );
		$valeur = preg_replace( '/\s+/u', ' ', $valeur );

		return trim( (string) $valeur );
	}

	/**
	 * Formate un montant en euros.
	 *
	 * @param int $centimes Montant.
	 * @return string
	 */
	private static function euros( int $centimes ): string {
		return number_format( $centimes, 0, ',', ' ' ) . ' €';
	}
}
