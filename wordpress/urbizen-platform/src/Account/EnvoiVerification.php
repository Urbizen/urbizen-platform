<?php
/**
 * Unique orchestrateur d'émission du parcours de comptes.
 *
 * **Un seul chemin de code émet.** Cette classe reçoit un `MailTransport` par
 * construction et appelle le contrat existant sans le modifier. Elle ne connaît
 * pas la fonction d'envoi de WordPress : seul `WordPressMailTransport` a le
 * droit de l'appeler.
 *
 * La séquence, verrou NON tenu pendant l'envoi :
 *
 * ```
 * emettre( int $compte )
 *  ├─ 1. $r = $verification->preparer( $compte )
 *  │       └─ refusé → rendre le motif, NE RIEN envoyer, NE RIEN clore
 *  ├─ 2. sujet, corps, entetes = CourrielVerification::rendre( cible, lien )
 *  ├─ 3. $envoye = false;
 *  │     try   { $envoye = ! empty( $transport->send( … )['ok'] ); }
 *  │     catch { $envoye = false; }            // une exception EST un échec
 *  └─ 4. IMMÉDIATEMENT, sans rien intercaler :
 *         false → annuler_emission()
 *         true  → confirmer_emission()
 *                 └─ si elle rend faux : JOURNALISER ET S'ARRÊTER.
 *                    JAMAIS annuler_emission() ici.
 * ```
 *
 * **Une exception du transport est un échec, sans distinction.** La laisser
 * remonter laisserait l'émission en vol jusqu'à son expiration : le compte
 * resterait fermé cinq minutes après un envoi qui n'a jamais eu lieu, et le
 * quota serait juste mais le client bloqué sans raison.
 *
 * **Après `ok = true`, l'annulation est interdite, sans exception.** Le message
 * a été accepté par le transport et le lien est peut-être déjà dans une boîte ;
 * annuler détruirait le jeton d'un lien vivant, et le destinataire cliquerait
 * sur un lien mort. Un échec de clôture est journalisé, l'émission en attente
 * reste posée, et elle sera rejouée ou expirera. Le seul risque est un créneau
 * non décompté ; le risque inverse serait un client bloqué.
 *
 * Les étapes 2 et 3 sont hors verrou, à dessein : tenir un verrou de 60
 * secondes pendant un envoi ferait expirer ce verrou en cours de route. C'est
 * précisément la raison d'être de l'émission en attente.
 *
 * @package Urbizen\Platform\Account
 */

namespace Urbizen\Platform\Account;

use Throwable;
use Urbizen\Platform\Mail\MailTransport;
use Urbizen\Platform\Support\Logger;

/**
 * Préparer, rendre, envoyer, clore.
 */
final class EnvoiVerification {

	/**
	 * Service de vérification.
	 *
	 * @var VerificationService
	 */
	private VerificationService $verification;

	/**
	 * Transport, reçu par construction.
	 *
	 * @var MailTransport
	 */
	private MailTransport $transport;

	/**
	 * URL de `admin-post.php`, sans chaîne de requête.
	 *
	 * @var string
	 */
	private string $base;

	/**
	 * Nom du site, tel qu'il apparaît dans les courriels.
	 *
	 * @var string
	 */
	private string $site;

	/**
	 * @param VerificationService $verification Service de vérification.
	 * @param MailTransport       $transport    Transport.
	 * @param string              $base         URL de `admin-post.php`.
	 * @param string              $site         Nom du site.
	 */
	public function __construct(
		VerificationService $verification,
		MailTransport $transport,
		string $base,
		string $site = 'Urbizen'
	) {
		$this->verification = $verification;
		$this->transport    = $transport;
		$this->base         = $base;
		$this->site         = $site;
	}

	/**
	 * Prépare, envoie et clôt une émission de vérification.
	 *
	 * @param int      $compte     Identifiant.
	 * @param int|null $maintenant Horloge injectable.
	 * @return array{ok: bool, motif: string, code: string}
	 */
	public function emettre( int $compte, ?int $maintenant = null ): array {
		// (1) Préparation. Un refus ne fait RIEN envoyer et RIEN clore.
		$resultat = $this->verification->preparer( $compte, $maintenant );

		if ( ! $resultat->est_prepare() ) {
			return array( 'ok' => false, 'motif' => $resultat->motif(), 'code' => '' );
		}

		return $this->rendre_envoyer_clore( $compte, $resultat, $maintenant );
	}

	/**
	 * Enregistre un changement d'adresse, avertit l'ancienne, puis vérifie.
	 *
	 * **L'avertissement part AVANT la vérification.** L'ordre inverse laisserait
	 * un lien vivant vers la nouvelle adresse sans que l'ancienne ait été
	 * prévenue, ce qui viderait l'avertissement de son sens : il est le seul
	 * signal dont dispose quelqu'un dont la boîte a été compromise.
	 *
	 * **Son échec ne bloque rien.** Il est journalisé par code technique, sans
	 * donnée personnelle, et la vérification suit son cours. Il ne déclenche
	 * jamais `annuler_emission()`.
	 *
	 * @param int      $compte         Identifiant.
	 * @param string   $nouvelle_brute Adresse demandée.
	 * @param int|null $maintenant     Horloge injectable.
	 * @return array{ok: bool, motif: string, code: string}
	 */
	public function emettre_changement_adresse( int $compte, string $nouvelle_brute, ?int $maintenant = null ): array {
		$demande = $this->verification->demander_changement_adresse( $compte, $nouvelle_brute, $maintenant );

		if ( '' !== $demande['motif'] || null === $demande['emission'] ) {
			return array( 'ok' => false, 'motif' => $demande['motif'], 'code' => '' );
		}

		// (a) L'ancienne adresse est prévenue, sans lien ni jeton.
		$this->avertir_ancienne_adresse( $demande['ancienne'], $compte );

		// (b) Puis la vérification part vers la NOUVELLE adresse.
		return $this->rendre_envoyer_clore( $compte, $demande['emission'], $maintenant );
	}

	/**
	 * Avertit une adresse qu'un changement a été demandé.
	 *
	 * **N'appelle pas `preparer()`**, ne crée aucune émission et **ne consomme
	 * aucun créneau de `LimiteEnvois`** : c'est une notification de sécurité,
	 * pas une émission. La décompter reviendrait à faire payer à la personne le
	 * fait d'avoir été prévenue.
	 *
	 * @param string   $ancienne Adresse à prévenir.
	 * @param int      $compte   Identifiant, pour le journal.
	 * @return array{ok: bool, code: string}
	 */
	public function avertir_ancienne_adresse( string $ancienne, int $compte = 0 ): array {
		if ( '' === $ancienne ) {
			return array( 'ok' => false, 'code' => 'destinataire_absent' );
		}

		$message = CourrielVerification::rendre_avertissement( $this->site );

		try {
			$reponse = $this->transport->send(
				$ancienne,
				$message['sujet'],
				$message['corps'],
				$message['entetes']
			);

			$ok   = ! empty( $reponse['ok'] );
			$code = isset( $reponse['code'] ) ? (string) $reponse['code'] : '';
		} catch ( Throwable $e ) {
			$ok   = false;
			$code = 'transport_exception';
		}

		if ( ! $ok ) {
			// Code technique et identifiant de compte. JAMAIS l'adresse.
			Logger::error(
				sprintf( 'avertissement non remis : %s (compte %d)', $code, $compte )
			);
		}

		return array( 'ok' => $ok, 'code' => $code );
	}

	/**
	 * Rend le message, l'envoie, puis clôt l'émission — sans rien intercaler.
	 *
	 * @param int              $compte     Identifiant.
	 * @param ResultatEmission $resultat   Émission préparée.
	 * @param int|null         $maintenant Horloge injectable.
	 * @return array{ok: bool, motif: string, code: string}
	 */
	private function rendre_envoyer_clore( int $compte, ResultatEmission $resultat, ?int $maintenant ): array {
		$emission_id = $resultat->emission_id();

		// (2) Rendu. Hors verrou, à dessein.
		$lien = LienVerification::pour( $this->base, $compte, $resultat->jeton() );

		if ( '' === $lien ) {
			// Aucun message ne peut être fabriqué : l'émission est annulée
			// tout de suite plutôt que laissée en vol jusqu'à son expiration.
			$this->verification->annuler_emission( $compte, $emission_id, $maintenant );

			return array( 'ok' => false, 'motif' => 'lien_impossible', 'code' => '' );
		}

		$message = CourrielVerification::rendre( $resultat->cible(), $lien, $this->site );

		// (3) Envoi. Une exception EST un échec, au même titre que ok = false.
		$envoye = false;
		$code   = '';

		try {
			$reponse = $this->transport->send(
				$resultat->cible(),
				$message['sujet'],
				$message['corps'],
				$message['entetes']
			);

			$envoye = ! empty( $reponse['ok'] );
			$code   = isset( $reponse['code'] ) ? (string) $reponse['code'] : '';
		} catch ( Throwable $e ) {
			$envoye = false;
			$code   = 'transport_exception';
		}

		// (4) IMMÉDIATEMENT. Rien ne s'intercale ici : ni journal, ni lecture,
		// ni calcul. Chaque instruction ajoutée entre le retour de l'envoi et
		// la clôture est une fenêtre pendant laquelle le processus peut mourir
		// en laissant l'émission en vol.
		if ( false === $envoye ) {
			$this->verification->annuler_emission( $compte, $emission_id, $maintenant );

			Logger::error( sprintf( 'emission non remise : %s (compte %d)', $code, $compte ) );

			return array( 'ok' => false, 'motif' => 'envoi_echoue', 'code' => $code );
		}

		if ( ! $this->verification->confirmer_emission( $compte, $emission_id, $maintenant ) ) {
			/*
			 * Le message est parti. Le lien est peut-être déjà dans une boîte.
			 * On JOURNALISE ET ON S'ARRÊTE : appeler `annuler_emission()` ici
			 * détruirait le jeton d'un lien vivant, et le destinataire
			 * cliquerait sur un lien mort. L'émission en attente reste posée ;
			 * elle sera rejouée, ou elle expirera.
			 */
			Logger::error(
				sprintf( 'cloture manquee apres envoi accepte (compte %d)', $compte )
			);

			return array( 'ok' => true, 'motif' => 'cloture_manquee', 'code' => $code );
		}

		return array( 'ok' => true, 'motif' => '', 'code' => $code );
	}
}
