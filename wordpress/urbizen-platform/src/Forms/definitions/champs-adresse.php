<?php
/**
 * Adresse du terrain — les mêmes champs pour les trois parcours.
 *
 * Déclaration préalable, permis de construire et conception posent la même
 * question : où est le terrain ? Les recopier trois fois aurait suffi à les
 * faire diverger — un `maxlength` ici, une obligation là — et l'administration
 * aurait fini par lire trois adresses de formes différentes.
 *
 * **Deux modes, et le mode est une donnée.** `mode_adresse` vaut `automatique`
 * quand la personne a retenu une proposition du service, `manuel` quand elle a
 * écrit son adresse elle-même. Le déduire de ce qui est rempli reviendrait à
 * deviner, et à se tromper sur une adresse d'un seul champ. Il est donc
 * persisté, et c'est lui qui décide de ce que le serveur exige.
 *
 * **Une seule représentation de l'adresse.** `terrain_adresse` porte la ligne
 * lisible en mode automatique — celle que le service a rendue, telle quelle.
 * `terrain_voie` porte « numéro et voie » : composé depuis les deux champs du
 * service en automatique, saisi tel quel en manuel. Rien n'est stocké deux
 * fois, et la réponse brute du service n'est jamais persistée.
 *
 * Les champs propres à un mode portent un `visible_if` : le validateur écarte
 * une branche inactive plutôt que de la refuser, et une valeur restée dans le
 * document ne peut donc pas entrer par une porte que la personne a fermée.
 *
 * @param string $etape       Identifiant de l'étape qui les accueille.
 * @param bool   $obligatoire L'adresse est-elle exigée dans ce parcours ?
 * @param array  $sous        Condition supplémentaire, ou tableau vide. La
 *                            conception n'affiche l'adresse que si la personne
 *                            déclare avoir un terrain.
 * @return array<int, array<string, mixed>>
 *
 * @package Urbizen\Platform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Champs d'adresse du terrain.
 *
 * @param string                    $etape       Étape d'accueil.
 * @param bool                      $obligatoire Adresse exigée.
 * @param array<string, mixed>|null $sous        Condition parcours, ou null.
 * @return array<int, array<string, mixed>>
 */
function urbizen_champs_adresse_terrain( string $etape, bool $obligatoire, ?array $sous = null ): array {
	/**
	 * Compose la condition d'un champ : son mode, et la condition du parcours
	 * quand il y en a une.
	 *
	 * Le validateur n'accepte qu'une condition par champ. Quand un parcours en
	 * ajoute une — la conception et son `a_terrain` — c'est elle qui prime :
	 * sans terrain, aucune adresse n'a de sens, quel que soit le mode. Le mode
	 * reste appliqué par le filtrage serveur, qui n'a pas cette limite.
	 *
	 * @param array<string, mixed> $mode Condition de mode.
	 * @return array<string, mixed>
	 */
	$condition = static function ( array $mode ) use ( $sous ): array {
		return null === $sous ? $mode : $sous;
	};

	$champs = array(
		// Le mode est demandé avant tout le reste : il gouverne les autres. Il
		// suit la condition du parcours — sans terrain, il n'y a pas de mode de
		// saisie à retenir.
		array(
			'name'       => 'mode_adresse',
			'type'       => 'radio',
			'step'       => $etape,
			'label'      => __( 'Mode de saisie de l’adresse', 'urbizen-platform' ),
			'visible_if' => $sous,
			'options'    => array(
				array(
					'value' => 'automatique',
					'label' => __( 'Adresse sélectionnée automatiquement', 'urbizen-platform' ),
				),
				array(
					'value' => 'manuel',
					'label' => __( 'Adresse renseignée manuellement', 'urbizen-platform' ),
				),
			),
		),

		/* ----- Mode automatique ----- */

		array(
			'name'       => 'terrain_adresse',
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Adresse du terrain', 'urbizen-platform' ),
			'maxlength'  => 200,
			'required'   => $obligatoire,
			'visible_if' => $condition( array( 'field' => 'mode_adresse', 'in' => array( 'automatique' ) ) ),
		),
		array(
			'name'       => 'terrain_insee',
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Code commune', 'urbizen-platform' ),
			'maxlength'  => 10,
			'visible_if' => $condition( array( 'field' => 'mode_adresse', 'in' => array( 'automatique' ) ) ),
		),
		// Les coordonnées ne sont conservées que si le service les a fournies.
		// Aucune absence ne devient zéro : le point (0, 0) est au large du
		// golfe de Guinée, et une demande n'y a jamais lieu.
		array(
			'name'       => 'terrain_lat',
			'type'       => 'number',
			'step'       => $etape,
			'label'      => __( 'Latitude', 'urbizen-platform' ),
			'min'        => -90,
			'max'        => 90,
			'increment'  => 0.000001,
			'visible_if' => $condition( array( 'field' => 'mode_adresse', 'in' => array( 'automatique' ) ) ),
		),
		array(
			'name'       => 'terrain_lon',
			'type'       => 'number',
			'step'       => $etape,
			'label'      => __( 'Longitude', 'urbizen-platform' ),
			'min'        => -180,
			'max'        => 180,
			'increment'  => 0.000001,
			'visible_if' => $condition( array( 'field' => 'mode_adresse', 'in' => array( 'automatique' ) ) ),
		),

		/* ----- Mode manuel ----- */

		array(
			'name'       => 'terrain_voie',
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Numéro et voie', 'urbizen-platform' ),
			'maxlength'  => 180,
			'required'   => $obligatoire,
			'visible_if' => $condition( array( 'field' => 'mode_adresse', 'in' => array( 'manuel' ) ) ),
		),
		array(
			'name'       => 'terrain_complement',
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Complément d’adresse', 'urbizen-platform' ),
			'maxlength'  => 180,
			'visible_if' => $condition( array( 'field' => 'mode_adresse', 'in' => array( 'manuel' ) ) ),
		),

		/* ----- Communs aux deux modes ----- */

		array(
			'name'       => 'terrain_cp',
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Code postal', 'urbizen-platform' ),
			'maxlength'  => 10,
			'required'   => $obligatoire,
			'visible_if' => $sous,
		),
		array(
			'name'       => 'terrain_ville',
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Commune', 'urbizen-platform' ),
			'maxlength'  => 120,
			'required'   => $obligatoire,
			'visible_if' => $sous,
		),
	);

	// Une condition nulle n'est pas une condition : la laisser ferait échouer
	// la normalisation, qui exige un champ de référence.
	return array_map(
		static function ( array $c ): array {
			if ( array_key_exists( 'visible_if', $c ) && null === $c['visible_if'] ) {
				unset( $c['visible_if'] );
			}

			return $c;
		},
		$champs
	);
}
