<?php
/**
 * Résolution serveur du renderer autorisé pour un type de formulaire.
 *
 * Le bloc/shortcode peut **demander** un type, il ne le **choisit** jamais : le
 * type est d'abord validé par {@see FormRegistry} (liste blanche), puis ce
 * résolveur associe le type à son renderer autorisé, via une **table privée,
 * immuable, écrite en dur dans le code serveur**.
 *
 * **Aucun chargement dynamique client.** La table ne contient jamais un nom de
 * classe, un chemin, un callable ou un namespace venant d'une donnée client :
 * chaque fabrique est une fermeture définie ici, référençant une classe connue
 * du dépôt. Il n'y a ni `new $variable`, ni `class_exists($valeur_client)`, ni
 * `call_user_func($valeur_client)`, ni `require` calculé. Un type sans renderer
 * autorisé échoue **proprement** (journalisé, aucun rendu), jamais par déduction
 * d'une classe à partir de l'identifiant.
 *
 * Ce résolveur vit dans `Blocks` — la couche de composition — et non dans
 * `Forms` : il connaît à la fois le renderer plat (`Forms\Renderer`) et la
 * façade métier (`Conception\ConceptionRenderer`), sans qu'aucun de ces paquets
 * ne dépende en retour de `Blocks`. Aucune dépendance circulaire.
 *
 * @package Urbizen\Platform\Blocks
 */

namespace Urbizen\Platform\Blocks;

use Urbizen\Platform\Conception\ConceptionRenderer;
use Urbizen\Platform\Forms\FormDefinition;
use Urbizen\Platform\Forms\Renderer;
use Urbizen\Platform\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Table serveur de confiance : type de formulaire → renderer autorisé.
 */
final class FormRendererResolver {

	/**
	 * Table immuable des renderers de confiance.
	 *
	 * Chaque entrée déclare :
	 *  - `render` : une fabrique `(FormDefinition, array): string`, fermeture
	 *    définie dans ce fichier, référençant une classe connue ;
	 *  - `block_assets` : les ressources **de bloc** à enfiler pour ce parcours.
	 *    Le formulaire plat porte les ressources `urbizen-form` ; Conception
	 *    n'en a aucune ici — sa façade enfile elle-même `ConceptionAssets`.
	 *
	 * @return array<string, array{render: callable, block_assets: bool}>
	 */
	private static function table(): array {
		return array(
			'localisation' => array(
				'render'       => static fn( FormDefinition $def, array $options ): string => Renderer::render( $def, $options ),
				'block_assets' => true,
			),
			'conception'   => array(
				'render'       => static fn( FormDefinition $def, array $options ): string => ConceptionRenderer::render( $def ),
				'block_assets' => false,
			),
		);
	}

	/**
	 * Un renderer autorisé existe-t-il pour ce type ?
	 *
	 * @param string $type Type déjà validé par la liste blanche.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return isset( self::table()[ $type ] );
	}

	/**
	 * Ce type nécessite-t-il les ressources de bloc `urbizen-form` ?
	 *
	 * Faux pour un type inconnu : aucune ressource n'est enfilée à tort.
	 *
	 * @param string $type Type de formulaire.
	 * @return bool
	 */
	public static function needs_block_assets( string $type ): bool {
		$table = self::table();

		return isset( $table[ $type ] ) ? $table[ $type ]['block_assets'] : false;
	}

	/**
	 * Rend la définition avec le renderer autorisé du type, ou `null`.
	 *
	 * `null` signale un échec sûr — type sans renderer autorisé — que l'appelant
	 * traduit en absence de rendu public. Jamais de repli silencieux vers un
	 * autre renderer, jamais de déduction de classe.
	 *
	 * @param string             $type    Type déjà validé par la liste blanche.
	 * @param FormDefinition     $def     Définition serveur résolue.
	 * @param array<string, string> $options Options de rendu du renderer plat (clé de stockage, id).
	 * @return string|null
	 */
	public static function render( string $type, FormDefinition $def, array $options = array() ): ?string {
		$table = self::table();

		if ( ! isset( $table[ $type ] ) ) {
			Logger::error( sprintf( 'aucun renderer autorisé pour le type de formulaire « %s »', $type ) );

			return null;
		}

		return ( $table[ $type ]['render'] )( $def, $options );
	}
}
