<?php
/**
 * Négociation de contenu sur l'en-tête `Accept`.
 *
 * Le même point d'entrée sert deux publics : un formulaire rendu par le socle,
 * qui poste la page et attend une redirection, et un document autonome qui
 * poste en `fetch` et attend une structure. Plutôt que de dédoubler la route ou
 * d'ajouter un champ métier au formulaire, on lit ce que le client déclare
 * accepter — c'est précisément à cela que sert cet en-tête.
 *
 * Ce que cette classe n'est pas : une preuve d'identité ni un contrôle de
 * sécurité. Un en-tête se forge aussi facilement qu'un champ. Il ne décide donc
 * que de la **forme de la réponse** ; la route, le nonce et l'intégralité des
 * contrôles restent identiques dans les deux modes.
 *
 * @package Urbizen\Platform\Http
 */

namespace Urbizen\Platform\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Lecture de l'en-tête `Accept`.
 */
final class AcceptNegotiation {

	/**
	 * Le client attend-il du JSON ?
	 *
	 * Un `Accept` réel est une liste pondérée — « application/json, text/plain »
	 * suivie d'un joker et d'un facteur de qualité — et non une chaîne unique. On
	 * cherche donc le type parmi les propositions, sans exiger d'égalité
	 * littérale. Le joker seul ne suffit pas : c'est ce qu'envoie un navigateur
	 * qui suit un formulaire classique, et il attend une page, pas une structure.
	 *
	 * @param array<string, mixed> $server Superglobale serveur.
	 * @return bool
	 */
	public static function veut_json( array $server ): bool {
		$brut = isset( $server['HTTP_ACCEPT'] ) ? (string) $server['HTTP_ACCEPT'] : '';

		if ( '' === $brut ) {
			return false;
		}

		foreach ( explode( ',', $brut ) as $proposition ) {
			// Chaque proposition peut porter des paramètres (`;q=0.9`) : seul le
			// type importe ici.
			$type = strtolower( trim( explode( ';', $proposition, 2 )[0] ) );

			if ( 'application/json' === $type ) {
				return true;
			}
		}

		return false;
	}
}
