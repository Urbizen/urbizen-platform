<?php
/**
 * Une adresse : ce qu'on en garde, et comment elle se lit.
 *
 * Une adresse peut arriver de deux façons — retenue dans la base officielle, ou
 * écrite à la main. Les deux sont légitimes : le service ne connaît pas tous
 * les lieux-dits, et il tombe parfois. Mais un dossier ne doit porter **qu'une
 * seule adresse par rôle**. Recevoir les deux jeux de champs et les persister
 * tous les deux, c'est livrer à l'instruction un dossier qui se contredit.
 *
 * Cette classe fait donc deux choses, et une seule fois :
 *
 * 1. **Elle tranche.** `filtrer()` ne garde que les champs du mode retenu et
 *    écarte les autres, quoi qu'ait envoyé le navigateur. Le mode déclaré n'est
 *    pas cru sur parole : il est vérifié contre une liste fermée, et ce qui
 *    n'appartient pas au mode retenu disparaît.
 * 2. **Elle écrit.** `lignes_adresse()` et `resume()` rendent une adresse
 *    lisible, une seule, sans identifiant technique ni réponse de service.
 *    Comme {@see PrecisionsProjet}, elle déclare les champs qu'elle assume pour
 *    que le tableau générique ne les répète pas.
 *
 * **Deux rôles, une seule logique.** Le terrain et le déclarant posent la même
 * question et méritent le même traitement. La classe ne connaît donc que des
 * *rôles de champ* — `adresse`, `cp`, `ville`, `insee`, `lat`, `lon`, `voie`,
 * `complement`, `mode` — et une table dit quel nom canonique porte chaque rôle
 * dans chaque bloc. C'est le miroir serveur exact de l'indirection que
 * `urbizen-form-adresse.js` applique dans le document. Écrire deux classes
 * jumelles les aurait laissées diverger, et l'administration aurait fini par
 * lire deux formes d'une même adresse.
 *
 * Les noms du déclarant sont **historiques** et le restent : `adresse_declarant`
 * et non `declarant_adresse`. Les renommer aurait cassé un contrat déjà servi
 * pour la seule satisfaction d'une symétrie.
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
 * Filtrage et rendu d'une adresse, pour un rôle donné.
 */
final class AdresseTerrain {

	/** Le mode est une liste fermée. Toute autre valeur est une erreur. */
	public const AUTOMATIQUE = 'automatique';
	public const MANUEL      = 'manuel';

	/** Les deux blocs d'adresse que la plateforme connaît. */
	public const TERRAIN   = 'terrain';
	public const DECLARANT = 'declarant';

	/**
	 * La case qui reporte l'adresse du déclarant sur le terrain.
	 *
	 * Elle vit ici parce que c'est ici qu'on sait ce qu'elle recouvre : sans
	 * elle, le tableau générique l'afficherait brute, et l'accusé client
	 * montrerait « 1 » à la place d'une adresse.
	 */
	public const REPORT = 'terrain_meme_adresse_declarant';

	/**
	 * Seule valeur qui coche la case.
	 *
	 * Une liste fermée, comme le mode : accepter « on », « true » ou « 1 »
	 * reviendrait à deviner l'intention derrière une charge forgée. Une seule
	 * valeur coche, toutes les autres laissent la case décochée.
	 *
	 * `oui` et non `1` : {@see FormDefinition::ID_PATTERN} exige qu'une valeur
	 * d'option commence par une lettre, et c'est déjà la forme que porte la
	 * case voisine `informations_cadastrales_differees`. Assouplir la règle
	 * d'identifiants de tous les formulaires pour un chiffre aurait coûté plus
	 * cher que ce qu'il rapportait.
	 */
	public const REPORT_VRAI = 'oui';

	/**
	 * Le nom canonique de chaque rôle de champ, bloc par bloc.
	 *
	 * Cette table est la **seule** description du vocabulaire. Le filtrage, la
	 * vérification, le rendu et les bancs la lisent : une liste recopiée
	 * ailleurs finirait par autoriser un champ que le mode ne justifie plus.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const ROLES = array(
		self::TERRAIN   => array(
			'rubrique' => 'Adresse du terrain',
			'noms'     => array(
				'mode'       => 'mode_adresse',
				'adresse'    => 'terrain_adresse',
				'insee'      => 'terrain_insee',
				'lat'        => 'terrain_lat',
				'lon'        => 'terrain_lon',
				'voie'       => 'terrain_voie',
				'complement' => 'terrain_complement',
				'cp'         => 'terrain_cp',
				'ville'      => 'terrain_ville',
			),
		),
		self::DECLARANT => array(
			'rubrique' => 'Adresse du déclarant',
			'noms'     => array(
				'mode'       => 'mode_adresse_declarant',
				'adresse'    => 'adresse_declarant',
				'insee'      => 'insee_declarant',
				'lat'        => 'lat_declarant',
				'lon'        => 'lon_declarant',
				'voie'       => 'voie_declarant',
				'complement' => 'complement_declarant',
				'cp'         => 'cp_declarant',
				'ville'      => 'ville_declarant',
			),
		),
	);

	/**
	 * Rôles de champ propres à chaque mode.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const PAR_MODE = array(
		self::AUTOMATIQUE => array( 'adresse', 'insee', 'lat', 'lon' ),
		self::MANUEL      => array( 'voie', 'complement' ),
	);

	/** Communs aux deux modes : ils ne sont jamais écartés. */
	private const COMMUNS = array( 'cp', 'ville' );

	/**
	 * Rôles de champ exigés par chaque mode.
	 *
	 * Le code commune fait partie du minimum en automatique : il vient de la
	 * sélection, et son absence signale une adresse composée à la main dans un
	 * mode qui prétend le contraire. En manuel, on n'exige que ce qu'une
	 * personne peut honnêtement écrire.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const EXIGES = array(
		self::AUTOMATIQUE => array( 'adresse', 'cp', 'ville', 'insee' ),
		self::MANUEL      => array( 'voie', 'cp', 'ville' ),
	);

	/** Rôle servi par cette instance. */
	private string $role;

	/**
	 * Noms canoniques du rôle, indexés par rôle de champ.
	 *
	 * @var array<string, string>
	 */
	private array $noms;

	/** Intitulé de la rubrique pour ce rôle. */
	private string $rubrique;

	/**
	 * @param string $role Rôle servi.
	 */
	private function __construct( string $role ) {
		$this->role     = $role;
		$this->noms     = self::ROLES[ $role ]['noms'];
		$this->rubrique = self::ROLES[ $role ]['rubrique'];
	}

	/**
	 * L'adresse d'un rôle.
	 *
	 * @param string $role `terrain` ou `declarant`.
	 * @return self
	 * @throws \InvalidArgumentException Si le rôle est inconnu — c'est une
	 *                                   erreur de programmation, pas une donnée.
	 */
	public static function pour( string $role ): self {
		if ( ! array_key_exists( $role, self::ROLES ) ) {
			throw new \InvalidArgumentException( 'Rôle d’adresse inconnu : ' . $role );
		}

		return new self( $role );
	}

	/**
	 * Les deux adresses, dans l'ordre de lecture d'un dossier.
	 *
	 * Le déclarant d'abord : c'est la personne, et le terrain vient après elle.
	 *
	 * @return array<int, self>
	 */
	public static function toutes(): array {
		return array( self::pour( self::DECLARANT ), self::pour( self::TERRAIN ) );
	}

	/** Rôle servi. */
	public function role(): string {
		return $this->role;
	}

	/** Intitulé de la rubrique. */
	public function rubrique(): string {
		return $this->rubrique;
	}

	/**
	 * Le nom canonique d'un rôle de champ.
	 *
	 * @param string $champ Rôle de champ (`cp`, `ville`, `mode`…).
	 * @return string
	 */
	public function nom( string $champ ): string {
		return $this->noms[ $champ ];
	}

	/**
	 * Tous les noms canoniques que ce rôle assume, mode compris.
	 *
	 * @return array<int, string>
	 */
	public function champs(): array {
		return array_values( $this->noms );
	}

	/**
	 * Ce champ dispose-t-il d'un rendu métier dédié, quel que soit le rôle ?
	 *
	 * La question est posée sans rôle parce que le tableau générique la pose
	 * sans rôle : il veut savoir s'il doit se taire, pas qui parle à sa place.
	 *
	 * @param string $champ Nom canonique.
	 * @return bool
	 */
	public static function porte( string $champ ): bool {
		if ( self::REPORT === $champ ) {
			return true;
		}

		foreach ( self::ROLES as $description ) {
			if ( in_array( $champ, array_values( $description['noms'] ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * L'adresse du terrain est-elle reportée depuis celle du déclarant ?
	 *
	 * @param array<string, mixed> $charge Réponses nettoyées ou persistées.
	 * @return bool
	 */
	public static function reportee( array $charge ): bool {
		$brut = $charge[ self::REPORT ] ?? null;

		if ( is_array( $brut ) || null === $brut ) {
			return false;
		}

		return self::REPORT_VRAI === (string) $brut;
	}

	/**
	 * Le mode retenu, ou null si la charge n'en déclare pas un valide.
	 *
	 * @param array<string, mixed> $charge Réponses nettoyées.
	 * @return string|null
	 */
	public function mode( array $charge ): ?string {
		$brut = $charge[ $this->nom( 'mode' ) ] ?? null;

		if ( ! is_string( $brut ) ) {
			return null;
		}

		return in_array( $brut, array( self::AUTOMATIQUE, self::MANUEL ), true ) ? $brut : null;
	}

	/**
	 * L'adresse est-elle cohérente avec le mode déclaré ?
	 *
	 * **Un mode absent ou inventé refuse la demande.** C'est le seul traitement
	 * honnête : sans mode, deux jeux de champs concurrents arrivent sans que
	 * rien ne dise lequel fait foi, et en choisir un reviendrait à décider de
	 * l'adresse du dossier à la place du demandeur. Écarter les deux
	 * silencieusement serait pire encore — la demande partirait sans adresse.
	 *
	 * `$exige_present` distingue deux absences que rien d'autre ne sépare :
	 *
	 * - **le parcours ne pose pas cette adresse** — une conception sans terrain.
	 *   La clé est absente, la règle ne s'applique pas, et c'est très bien.
	 * - **le parcours l'exige et elle manque** — une charge forgée qui a retiré
	 *   le mode pour échapper au contrôle. Depuis que l'obligation ne repose
	 *   plus sur `required`, c'est ici, et ici seulement, qu'elle est rattrapée.
	 *
	 * @param array<string, mixed> $clean          Réponses nettoyées.
	 * @param bool                 $exige_present  L'adresse est-elle exigée ?
	 * @return array<string, string> Erreurs par champ, vide si tout va bien.
	 */
	public function verifier( array $clean, bool $exige_present = false ): array {
		$mode_nom = $this->nom( 'mode' );

		if ( ! array_key_exists( $mode_nom, $clean ) ) {
			return $exige_present ? array( $mode_nom => 'adresse_mode_absent' ) : array();
		}

		$brut = $clean[ $mode_nom ];

		if ( null === $brut || '' === $brut ) {
			return array( $mode_nom => 'adresse_mode_absent' );
		}

		$mode = $this->mode( $clean );

		if ( null === $mode ) {
			return array( $mode_nom => 'adresse_mode_inconnu' );
		}

		$erreurs = array();

		foreach ( self::EXIGES[ $mode ] as $champ ) {
			$nom    = $this->nom( $champ );
			$valeur = $clean[ $nom ] ?? null;

			if ( null === $valeur || '' === $valeur ) {
				$erreurs[ $nom ] = 'champ_requis';
			}
		}

		// Les coordonnées vont par deux, et restent dans les bornes du globe.
		// Une valeur hors bornes n'est pas une imprécision : c'est une donnée
		// qui ne vient pas du service.
		$erreurs += $this->verifier_coordonnees( $clean );

		return $erreurs;
	}

	/**
	 * Contrôle de la paire de coordonnées.
	 *
	 * @param array<string, mixed> $clean Réponses nettoyées.
	 * @return array<string, string>
	 */
	private function verifier_coordonnees( array $clean ): array {
		$lat = $this->valeur( $clean, 'lat' );
		$lon = $this->valeur( $clean, 'lon' );

		if ( '' === $lat && '' === $lon ) {
			return array();
		}

		if ( '' === $lat || '' === $lon ) {
			// Une coordonnée seule ne localise rien. Le filtrage l'écartera ;
			// la signaler ici évite qu'une charge forgée passe pour complète.
			return array( $this->nom( '' === $lat ? 'lat' : 'lon' ) => 'coordonnee_orpheline' );
		}

		if ( ! is_numeric( $lat ) || abs( (float) $lat ) > 90.0 ) {
			return array( $this->nom( 'lat' ) => 'hors_bornes' );
		}

		if ( ! is_numeric( $lon ) || abs( (float) $lon ) > 180.0 ) {
			return array( $this->nom( 'lon' ) => 'hors_bornes' );
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
	public function filtrer( array $clean, array &$ecarts = array() ): array {
		// Le parcours ne pose pas cette adresse du tout : rien à filtrer. C'est
		// le cas d'une conception sans terrain.
		if ( ! array_key_exists( $this->nom( 'mode' ), $clean ) ) {
			return $clean;
		}

		$mode = $this->mode( $clean );

		// Mode absent ou inventé : la demande est refusée par `verifier()`, et
		// rien ne doit subsister. Laisser passer le code postal et la commune
		// enregistrerait un fragment d'adresse sans savoir de quelle adresse
		// c'est le fragment.
		if ( null === $mode ) {
			$tous = array_merge( self::COMMUNS, self::PAR_MODE[ self::AUTOMATIQUE ], self::PAR_MODE[ self::MANUEL ] );

			foreach ( $tous as $champ ) {
				$clean = $this->retirer( $clean, $champ, $ecarts );
			}

			unset( $clean[ $this->nom( 'mode' ) ] );

			return $clean;
		}

		$gardes = self::PAR_MODE[ $mode ];

		foreach ( self::PAR_MODE as $champs ) {
			foreach ( $champs as $champ ) {
				$nom = $this->nom( $champ );

				if ( ! array_key_exists( $nom, $clean ) ) {
					continue;
				}

				if ( in_array( $champ, $gardes, true ) ) {
					// Retenu mais vide : l'absence de clé se lit « non
					// renseigné », un null se lirait « renseigné à rien ».
					if ( null === $clean[ $nom ] || '' === $clean[ $nom ] ) {
						unset( $clean[ $nom ] );
					}

					continue;
				}

				$clean = $this->retirer( $clean, $champ, $ecarts );
			}
		}

		return $this->apparier_coordonnees( $clean, $ecarts );
	}

	/**
	 * Retire tous les champs de ce rôle, quelles que soient leurs valeurs.
	 *
	 * Employé quand l'adresse va être reconstruite depuis une autre : il ne
	 * doit rien rester de ce que le navigateur a envoyé, sans quoi une valeur
	 * forgée survivrait à la recopie et se mêlerait à la copie légitime.
	 *
	 * @param array<string, mixed> $clean  Réponses nettoyées.
	 * @param array<int, string>   $ecarts Noms écartés, modifiés sur place.
	 * @return array<string, mixed>
	 */
	public function purger( array $clean, array &$ecarts = array() ): array {
		foreach ( array_keys( $this->noms ) as $champ ) {
			$clean = $this->retirer( $clean, $champ, $ecarts );
		}

		return $clean;
	}

	/**
	 * L'adresse active, prête à être portée sur un autre rôle.
	 *
	 * Seuls les champs que le mode retenu justifie sortent : exporter le mode
	 * inactif reviendrait à recopier une adresse que la personne a abandonnée.
	 * Les coordonnées ne sortent que par paire.
	 *
	 * @param array<string, mixed> $charge Réponses nettoyées.
	 * @return array<string, string> Indexé par rôle de champ, vide si le mode
	 *                               n'est pas exploitable.
	 */
	public function exporter( array $charge ): array {
		$mode = $this->mode( $charge );

		if ( null === $mode ) {
			return array();
		}

		$sortie = array( 'mode' => $mode );

		foreach ( array_merge( self::PAR_MODE[ $mode ], self::COMMUNS ) as $champ ) {
			$valeur = $this->valeur( $charge, $champ );

			if ( '' !== $valeur ) {
				$sortie[ $champ ] = $valeur;
			}
		}

		// Une coordonnée seule ne localise rien : les deux partent, ou aucune.
		if ( ! isset( $sortie['lat'], $sortie['lon'] ) ) {
			unset( $sortie['lat'], $sortie['lon'] );
		}

		return $sortie;
	}

	/**
	 * Écrit une adresse exportée dans les champs de ce rôle.
	 *
	 * @param array<string, mixed>  $clean   Réponses nettoyées.
	 * @param array<string, string> $valeurs Sortie de {@see self::exporter()}.
	 * @return array<string, mixed>
	 */
	public function importer( array $clean, array $valeurs ): array {
		foreach ( $valeurs as $champ => $valeur ) {
			$clean[ $this->nom( $champ ) ] = $valeur;
		}

		return $clean;
	}

	/**
	 * Les coordonnées vont par deux.
	 *
	 * Une seule ne localise rien, et la conserver ferait croire à une position
	 * que personne n'a.
	 *
	 * @param array<string, mixed> $clean  Réponses nettoyées.
	 * @param array<int, string>   $ecarts Noms écartés, modifiés sur place.
	 * @return array<string, mixed>
	 */
	private function apparier_coordonnees( array $clean, array &$ecarts ): array {
		$a_lat = array_key_exists( $this->nom( 'lat' ), $clean );
		$a_lon = array_key_exists( $this->nom( 'lon' ), $clean );

		if ( $a_lat === $a_lon ) {
			return $clean;
		}

		return $this->retirer( $clean, $a_lat ? 'lat' : 'lon', $ecarts );
	}

	/**
	 * Retire un champ, en consignant l'écart s'il portait quelque chose.
	 *
	 * @param array<string, mixed> $clean  Réponses nettoyées.
	 * @param string               $champ  Rôle de champ.
	 * @param array<int, string>   $ecarts Noms écartés, modifiés sur place.
	 * @return array<string, mixed>
	 */
	private function retirer( array $clean, string $champ, array &$ecarts ): array {
		$nom = $this->nom( $champ );

		if ( ! array_key_exists( $nom, $clean ) ) {
			return $clean;
		}

		if ( null !== $clean[ $nom ] && '' !== $clean[ $nom ] ) {
			$ecarts[] = $nom;
		}

		unset( $clean[ $nom ] );

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
	public function lignes_adresse( array $charge ): array {
		$mode   = $this->mode( $charge );
		$lignes = array();

		$cp    = $this->valeur( $charge, 'cp' );
		$ville = $this->valeur( $charge, 'ville' );
		// Code postal et commune sur une même ligne : c'est ainsi qu'une adresse
		// française se lit, et le rendu doit se lire.
		$bas = trim( $cp . ' ' . $ville );

		if ( self::MANUEL === $mode ) {
			$lignes[] = $this->valeur( $charge, 'voie' );
			$lignes[] = $this->valeur( $charge, 'complement' );
		} else {
			$libelle  = $this->valeur( $charge, 'adresse' );
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
	public function provenance( array $charge ): string {
		$mode = $this->mode( $charge );

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
	public function existe( array $charge ): bool {
		return array() !== $this->lignes_adresse( $charge );
	}

	/**
	 * L'adresse en une ligne, pour l'accusé client.
	 *
	 * @param array<string, mixed> $charge Charge persistée.
	 * @return string
	 */
	public function resume( array $charge ): string {
		return implode( ', ', $this->lignes_adresse( $charge ) );
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
	public function reperes( array $charge ): array {
		$out = array();

		$insee = $this->valeur( $charge, 'insee' );

		if ( '' !== $insee ) {
			$out['Code commune'] = $insee;
		}

		$lat = $this->valeur( $charge, 'lat' );
		$lon = $this->valeur( $charge, 'lon' );

		if ( '' !== $lat && '' !== $lon ) {
			$out['Coordonnées'] = str_replace( '.', ',', $lat ) . ' · ' . str_replace( '.', ',', $lon );
		}

		return $out;
	}

	/**
	 * Valeur d'un champ, réduite à une chaîne sûre.
	 *
	 * @param array<string, mixed> $charge Charge.
	 * @param string               $champ  Rôle de champ.
	 * @return string
	 */
	private function valeur( array $charge, string $champ ): string {
		$nom = $this->nom( $champ );

		if ( ! isset( $charge[ $nom ] ) || is_array( $charge[ $nom ] ) ) {
			return '';
		}

		return trim( (string) $charge[ $nom ] );
	}
}
