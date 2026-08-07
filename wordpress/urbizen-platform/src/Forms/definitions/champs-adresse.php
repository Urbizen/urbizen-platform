<?php
/**
 * Une adresse assistée — les mêmes champs, quel que soit le bloc.
 *
 * Le terrain et le déclarant posent la même question : où ? Les recopier aurait
 * suffi à les faire diverger — un `maxlength` ici, une obligation là — et
 * l'administration aurait fini par lire deux formes d'une même adresse.
 *
 * **Deux modes, et le mode est une donnée.** Le champ de mode vaut
 * `automatique` quand la personne a retenu une proposition du service, `manuel`
 * quand elle a écrit son adresse elle-même. Le déduire de ce qui est rempli
 * reviendrait à deviner, et à se tromper sur une adresse d'un seul champ. Il est
 * donc persisté, et c'est lui qui décide de ce que le serveur exige.
 *
 * **Une seule représentation.** Le champ « adresse » porte la ligne lisible en
 * mode automatique — celle que le service a rendue, telle quelle. Le champ
 * « voie » porte « numéro et voie », saisi tel quel en manuel. Rien n'est stocké
 * deux fois, et la réponse brute du service n'est jamais persistée.
 *
 * **L'obligation ne vit plus ici.** Elle est portée par
 * {@see AdresseTerrain::verifier()}, qui connaît le mode retenu et sait
 * n'exiger que ce que ce mode justifie. Le validateur générique, lui, n'accepte
 * qu'une condition par champ : il ne saurait pas combiner « mode » et « même
 * adresse que le déclarant ». Lui laisser l'obligation aurait donc rendu
 * obligatoire, case cochée, un bloc terrain que la personne ne remplit plus.
 *
 * Les champs propres à un mode portent un `visible_if` : le validateur écarte
 * une branche inactive plutôt que de la refuser, et une valeur restée dans le
 * document ne peut donc pas entrer par une porte que la personne a fermée.
 *
 * @package Urbizen\Platform
 */

use Urbizen\Platform\Forms\AdresseTerrain;

defined( 'ABSPATH' ) || exit;

/**
 * Champs d'une adresse assistée, pour un rôle donné.
 *
 * @param string                    $role  Rôle au sens de {@see AdresseTerrain}.
 * @param string                    $etape Étape d'accueil.
 * @param array<string, mixed>|null $sous  Condition propre au parcours, ou null.
 *                                         La conception n'affiche l'adresse que
 *                                         si la personne déclare un terrain.
 * @return array<int, array<string, mixed>>
 */
function urbizen_champs_adresse( string $role, string $etape, ?array $sous = null ): array {
	$adresse = AdresseTerrain::pour( $role );

	/**
	 * Compose la condition d'un champ : son mode, et la condition du parcours
	 * quand il y en a une.
	 *
	 * Le validateur n'accepte qu'une condition par champ. Quand un parcours en
	 * ajoute une — la conception et son `a_terrain` — c'est elle qui prime :
	 * sans terrain, aucune adresse n'a de sens, quel que soit le mode. Le mode
	 * reste appliqué par le filtrage serveur, qui n'a pas cette limite.
	 *
	 * @param string $mode Mode qui rend le champ visible.
	 * @return array<string, mixed>
	 */
	$condition = static function ( string $mode ) use ( $sous, $adresse ): array {
		return null === $sous
			? array( 'field' => $adresse->nom( 'mode' ), 'in' => array( $mode ) )
			: $sous;
	};

	$champs = array(
		// Le mode est demandé avant tout le reste : il gouverne les autres. Il
		// suit la condition du parcours — sans terrain, il n'y a pas de mode de
		// saisie à retenir.
		array(
			'name'       => $adresse->nom( 'mode' ),
			'type'       => 'radio',
			'step'       => $etape,
			'label'      => __( 'Mode de saisie de l’adresse', 'urbizen-platform' ),
			'visible_if' => $sous,
			'options'    => array(
				array(
					'value' => AdresseTerrain::AUTOMATIQUE,
					'label' => __( 'Adresse sélectionnée automatiquement', 'urbizen-platform' ),
				),
				array(
					'value' => AdresseTerrain::MANUEL,
					'label' => __( 'Adresse renseignée manuellement', 'urbizen-platform' ),
				),
			),
		),

		/* ----- Mode automatique ----- */

		// 300 caractères, comme l'adresse postale historique du déclarant. Le
		// contraire — ramener le déclarant à 200 — aurait rétréci un contrat
		// déjà servi, et `maxlength` refuse au lieu de tronquer : des adresses
		// autrefois admises seraient devenues invalides.
		array(
			'name'       => $adresse->nom( 'adresse' ),
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Adresse', 'urbizen-platform' ),
			'maxlength'  => 300,
			'visible_if' => $condition( AdresseTerrain::AUTOMATIQUE ),
		),
		array(
			'name'       => $adresse->nom( 'insee' ),
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Code commune', 'urbizen-platform' ),
			'maxlength'  => 10,
			'visible_if' => $condition( AdresseTerrain::AUTOMATIQUE ),
		),
		// Les coordonnées ne sont conservées que si le service les a fournies.
		// Aucune absence ne devient zéro : le point (0, 0) est au large du
		// golfe de Guinée, et une demande n'y a jamais lieu.
		array(
			'name'       => $adresse->nom( 'lat' ),
			'type'       => 'number',
			'step'       => $etape,
			'label'      => __( 'Latitude', 'urbizen-platform' ),
			'min'        => -90,
			'max'        => 90,
			'increment'  => 0.000001,
			'visible_if' => $condition( AdresseTerrain::AUTOMATIQUE ),
		),
		array(
			'name'       => $adresse->nom( 'lon' ),
			'type'       => 'number',
			'step'       => $etape,
			'label'      => __( 'Longitude', 'urbizen-platform' ),
			'min'        => -180,
			'max'        => 180,
			'increment'  => 0.000001,
			'visible_if' => $condition( AdresseTerrain::AUTOMATIQUE ),
		),

		/* ----- Mode manuel ----- */

		array(
			'name'       => $adresse->nom( 'voie' ),
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Numéro et voie', 'urbizen-platform' ),
			'maxlength'  => 180,
			'visible_if' => $condition( AdresseTerrain::MANUEL ),
		),
		array(
			'name'       => $adresse->nom( 'complement' ),
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Complément d’adresse', 'urbizen-platform' ),
			'maxlength'  => 180,
			'visible_if' => $condition( AdresseTerrain::MANUEL ),
		),

		/* ----- Communs aux deux modes ----- */

		array(
			'name'       => $adresse->nom( 'cp' ),
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Code postal', 'urbizen-platform' ),
			'maxlength'  => 10,
			'visible_if' => $sous,
		),
		array(
			'name'       => $adresse->nom( 'ville' ),
			'type'       => 'text',
			'step'       => $etape,
			'label'      => __( 'Commune', 'urbizen-platform' ),
			'maxlength'  => 120,
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
