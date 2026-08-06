<?php
/**
 * L'adresse du terrain : ce qu'on en garde, et comment elle se lit.
 *
 * Une adresse peut arriver de deux façons — retenue dans la base officielle, ou
 * écrite à la main. Les deux sont légitimes : le service ne connaît pas tous
 * les lieux-dits, et il tombe parfois. Mais une demande ne doit porter **qu'une
 * seule adresse**. Recevoir les deux jeux de champs et les persister tous les
 * deux, c'est livrer à l'instruction un dossier qui se contredit.
 *
 * Cette classe fait donc deux choses, et une seule fois :
 *
 * 1. **Elle tranche.** `filtrer()` ne garde que les champs du mode retenu et
 *    écarte les autres, quoi qu'ait envoyé le navigateur. Le mode déclaré n'est
 *    pas cru sur parole : il est vérifié contre une liste fermée, et ce qui
 *    n'appartient pas au mode retenu disparaît.
 * 2. **Elle écrit.** `lignes()` et `resume()` rendent une adresse lisible, une
 *    seule, sans identifiant technique ni réponse de service. Comme
 *    {@see PrecisionsProjet}, elle déclare les champs qu'elle assume pour que le
 *    tableau générique ne les répète pas.
 *
 * Ce qu'elle ne fait pas, délibérément : appeler le service. Une soumission ne
 * doit pas attendre un service public pour aboutir — s'il est lent ou en panne,
 * la demande partirait quand même, ou pire, échouerait. La vérification est
 * structurelle, pas oraculaire.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Filtrage et rendu de l'adresse du terrain.
 */
final class AdresseTerrain {

	/** Le mode est une liste fermée. Toute autre valeur est une erreur. */
	public const AUTOMATIQUE = 'automatique';
	public const MANUEL      = 'manuel';

	/** Intitulé de la rubrique, partout le même. */
	public const RUBRIQUE = 'Adresse du terrain';

	/**
	 * Champs propres à chaque mode.
	 *
	 * Ce tableau est la seule description de ce qui appartient à quoi. Le
	 * filtrage, le rendu et les bancs le lisent : une liste recopiée ailleurs
	 * finirait par autoriser un champ que le mode ne justifie plus.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const PAR_MODE = array(
		self::AUTOMATIQUE => array( 'terrain_adresse', 'terrain_insee', 'terrain_lat', 'terrain_lon' ),
		self::MANUEL      => array( 'terrain_voie', 'terrain_complement' ),
	);

	/** Communs aux deux modes : ils ne sont jamais écartés. */
	private const COMMUNS = array( 'terrain_cp', 'terrain_ville' );

	/**
	 * Tous les champs que cette classe assume, mode compris.
	 *
	 * @return array<int, string>
	 */
	public static function champs(): array {
		return array_merge(
			array( 'mode_adresse' ),
			self::PAR_MODE[ self::AUTOMATIQUE ],
			self::PAR_MODE[ self::MANUEL ],
			self::COMMUNS
		);
	}

	/**
	 * Ce champ dispose-t-il déjà d'un rendu métier dédié ?
	 *
	 * @param string $champ Nom canonique.
	 * @return bool
	 */
	public static function porte( string $champ ): bool {
		return in_array( $champ, self::champs(), true );
	}

	/**
	 * Le mode retenu, ou null si la charge n'en déclare pas un valide.
	 *
	 * @param array<string, mixed> $charge Réponses nettoyées.
	 * @return string|null
	 */
	public static function mode( array $charge ): ?string {
		$brut = isset( $charge['mode_adresse'] ) ? $charge['mode_adresse'] : null;

		if ( ! is_string( $brut ) ) {
			return null;
		}

		return in_array( $brut, array( self::AUTOMATIQUE, self::MANUEL ), true ) ? $brut : null;
	}

	/**
	 * Champs exigés par chaque mode.
	 *
	 * Le code commune fait partie du minimum en automatique : il vient de la
	 * sélection, et son absence signale une adresse composée à la main dans un
	 * mode qui prétend le contraire. En manuel, on n'exige que ce qu'une
	 * personne peut honnêtement écrire.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EXIGES = array(
		self::AUTOMATIQUE => array( 'terrain_adresse', 'terrain_cp', 'terrain_ville', 'terrain_insee' ),
		self::MANUEL      => array( 'terrain_voie', 'terrain_cp', 'terrain_ville' ),
	);

	/**
	 * L'adresse est-elle cohérente avec le mode déclaré ?
	 *
	 * **Un mode absent ou inventé refuse la demande.** C'est le seul traitement
	 * honnête : sans mode, deux jeux de champs concurrents arrivent sans que
	 * rien ne dise lequel fait foi, et en choisir un reviendrait à décider de
	 * l'adresse du dossier à la place du demandeur. Écarter les deux
	 * silencieusement serait pire encore — la demande partirait sans adresse.
	 *
	 * Le parcours qui ne pose pas d'adresse n'est pas concerné : la clé est
	 * absente de la charge nettoyée, et la règle ne s'applique pas.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string> Erreurs par champ, vide si tout va bien.
	 */
	public static function verifier( array $clean ): array {
		// Le parcours ne déclare pas d'adresse : rien à exiger.
		if ( ! array_key_exists( 'mode_adresse', $clean ) ) {
			return array();
		}

		$brut = $clean['mode_adresse'];

		if ( null === $brut || '' === $brut ) {
			return array( 'mode_adresse' => 'adresse_mode_absent' );
		}

		$mode = self::mode( $clean );

		if ( null === $mode ) {
			return array( 'mode_adresse' => 'adresse_mode_inconnu' );
		}

		$erreurs = array();

		foreach ( self::EXIGES[ $mode ] as $champ ) {
			$valeur = $clean[ $champ ] ?? null;

			if ( null === $valeur || '' === $valeur ) {
				$erreurs[ $champ ] = 'champ_requis';
			}
		}

		// Les coordonnées vont par deux, et restent dans les bornes du globe.
		// Une valeur hors bornes n'est pas une imprécision : c'est une donnée
		// qui ne vient pas du service.
		$erreurs += self::verifier_coordonnees( $clean );

		return $erreurs;
	}

	/**
	 * Contrôle de la paire de coordonnées.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string>
	 */
	private static function verifier_coordonnees( array $clean ): array {
		$lat = self::valeur( $clean, 'terrain_lat' );
		$lon = self::valeur( $clean, 'terrain_lon' );

		if ( '' === $lat && '' === $lon ) {
			return array();
		}

		if ( '' === $lat || '' === $lon ) {
			// Une coordonnée seule ne localise rien. Le filtrage l'écartera ;
			// la signaler ici évite qu'une charge forgée passe pour complète.
			return array( '' === $lat ? 'terrain_lat' : 'terrain_lon' => 'coordonnee_orpheline' );
		}

		if ( ! is_numeric( $lat ) || abs( (float) $lat ) > 90.0 ) {
			return array( 'terrain_lat' => 'hors_bornes' );
		}

		if ( ! is_numeric( $lon ) || abs( (float) $lon ) > 180.0 ) {
			return array( 'terrain_lon' => 'hors_bornes' );
		}

		return array();
	}

	/**
	 * Ne garde que l'adresse du mode retenu.
	 *
	 * Le retrait est silencieux, comme celui de {@see MatriceChamps} : une
	 * valeur restée dans le document après un changement d'avis n'est pas une
	 * attaque, et refuser la demande pour cela punirait une hésitation. Les
	 * écarts sont consignés, pour qu'un masquage défaillant se voie dans les
	 * journaux plutôt que dans les dossiers.
	 *
	 * Un mode absent ou hors liste ne laisse passer **aucun** champ propre à un
	 * mode : deviner entre deux charges concurrentes reviendrait à choisir
	 * l'adresse du dossier à la place du demandeur. Le validateur signale par
	 * ailleurs l'erreur ; ici, on se contente de ne rien inventer.
	 *
	 * @param array<string, mixed> $clean  Réponses nettoyées.
	 * @param array<int, string>   $ecarts Noms écartés, modifiés sur place.
	 * @return array<string, mixed>
	 */
	public static function filtrer( array $clean, array &$ecarts = array() ): array {
		// Le parcours ne pose pas d'adresse du tout : rien à filtrer. C'est le
		// cas d'une conception sans terrain.
		if ( ! array_key_exists( 'mode_adresse', $clean ) ) {
			return $clean;
		}

		$mode = self::mode( $clean );

		// Mode absent ou inventé : la demande est refusée par `verifier()`, et
		// rien ne doit subsister. Laisser passer le code postal et la commune
		// enregistrerait un fragment d'adresse sans savoir de quelle adresse
		// c'est le fragment.
		if ( null === $mode ) {
			foreach ( array_merge( self::COMMUNS, self::PAR_MODE[ self::AUTOMATIQUE ], self::PAR_MODE[ self::MANUEL ] ) as $champ ) {
				if ( ! array_key_exists( $champ, $clean ) ) {
					continue;
				}

				if ( null !== $clean[ $champ ] && '' !== $clean[ $champ ] ) {
					$ecarts[] = $champ;
				}

				unset( $clean[ $champ ] );
			}

			unset( $clean['mode_adresse'] );

			return $clean;
		}

		$gardes = self::PAR_MODE[ $mode ];

		foreach ( self::PAR_MODE as $champs ) {
			foreach ( $champs as $champ ) {
				if ( ! array_key_exists( $champ, $clean ) ) {
					continue;
				}

				if ( in_array( $champ, $gardes, true ) ) {
					// Retenu mais vide : l'absence de clé se lit « non
					// renseigné », un null se lirait « renseigné à rien ».
					if ( null === $clean[ $champ ] || '' === $clean[ $champ ] ) {
						unset( $clean[ $champ ] );
					}

					continue;
				}

				if ( null !== $clean[ $champ ] && '' !== $clean[ $champ ] ) {
					$ecarts[] = $champ;
				}

				unset( $clean[ $champ ] );
			}
		}

		// Les coordonnées vont par deux. Une seule ne localise rien, et la
		// conserver ferait croire à une position que personne n'a.
		$a_lat = array_key_exists( 'terrain_lat', $clean );
		$a_lon = array_key_exists( 'terrain_lon', $clean );

		if ( $a_lat !== $a_lon ) {
			$orpheline = $a_lat ? 'terrain_lat' : 'terrain_lon';
			$ecarts[]  = $orpheline;
			unset( $clean[ $orpheline ] );
		}

		return $clean;
	}

	/**
	 * L'adresse, écrite pour être lue.
	 *
	 * Une seule adresse, sur deux ou trois lignes, comme on l'écrirait sur une
	 * enveloppe. Aucune ligne vide, aucun identifiant technique.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return array<int, string> Lignes, dans l'ordre de lecture.
	 */
	public static function lignes_adresse( array $charge ): array {
		$mode   = self::mode( $charge );
		$lignes = array();

		$cp    = self::valeur( $charge, 'terrain_cp' );
		$ville = self::valeur( $charge, 'terrain_ville' );
		// Code postal et commune sur une même ligne : c'est ainsi qu'une adresse
		// française se lit, et le rendu doit se lire.
		$bas   = trim( $cp . ' ' . $ville );

		if ( self::MANUEL === $mode ) {
			$lignes[] = self::valeur( $charge, 'terrain_voie' );
			$lignes[] = self::valeur( $charge, 'terrain_complement' );
		} else {
			$libelle  = self::valeur( $charge, 'terrain_adresse' );
			$lignes[] = $libelle;

			// Le libellé du service porte déjà la commune et le code postal.
			// Ajouter la ligne basse écrirait deux fois la même chose, et une
			// adresse qui se répète donne à douter de celle qu'on lit.
			if ( '' !== $bas && '' !== $libelle && false !== mb_strpos( $libelle, $bas ) ) {
				$bas = '';
			}
		}

		if ( '' !== $bas ) {
			$lignes[] = $bas;
		}

		return array_values( array_filter( $lignes, static fn( $l ) => '' !== $l ) );
	}

	/**
	 * Comment cette adresse est arrivée, dit sobrement.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string Chaîne vide si le mode n'est pas exploitable.
	 */
	public static function provenance( array $charge ): string {
		$mode = self::mode( $charge );

		if ( self::AUTOMATIQUE === $mode ) {
			return 'Adresse sélectionnée automatiquement';
		}

		if ( self::MANUEL === $mode ) {
			return 'Adresse renseignée manuellement';
		}

		return '';
	}

	/**
	 * Y a-t-il une adresse à montrer ?
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return bool
	 */
	public static function existe( array $charge ): bool {
		return array() !== self::lignes_adresse( $charge );
	}

	/**
	 * L'adresse en une ligne, pour l'accusé client.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string
	 */
	public static function resume( array $charge ): string {
		return implode( ', ', self::lignes_adresse( $charge ) );
	}

	/**
	 * Précisions techniques, réservées à l'administration.
	 *
	 * Le code commune sert à instruire ; les coordonnées servent à retrouver le
	 * terrain sur une carte. Ni l'un ni l'autre n'a sa place dans un message au
	 * client, qui n'en ferait rien.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return array<string, string> Vide s'il n'y a rien d'utile.
	 */
	public static function reperes( array $charge ): array {
		$out = array();

		$insee = self::valeur( $charge, 'terrain_insee' );

		if ( '' !== $insee ) {
			$out['Code commune'] = $insee;
		}

		$lat = self::valeur( $charge, 'terrain_lat' );
		$lon = self::valeur( $charge, 'terrain_lon' );

		if ( '' !== $lat && '' !== $lon ) {
			$out['Coordonnées'] = str_replace( '.', ',', $lat ) . ' · ' . str_replace( '.', ',', $lon );
		}

		return $out;
	}

	/**
	 * Valeur d'un champ, réduite à une chaîne sûre.
	 *
	 * @param array<string, mixed> $charge Charge.
	 * @param string               $champ  Nom canonique.
	 * @return string
	 */
	private static function valeur( array $charge, string $champ ): string {
		if ( ! isset( $charge[ $champ ] ) || is_array( $charge[ $champ ] ) ) {
			return '';
		}

		return trim( (string) $charge[ $champ ] );
	}
}
