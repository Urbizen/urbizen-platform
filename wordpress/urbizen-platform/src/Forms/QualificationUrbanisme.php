<?php
/**
 * Qualification d'urbanisme — quelle formalité pour ce projet ?
 *
 * Jumeau serveur de `frontend/homepage/qualification.js`. Les deux appliquent
 * les mêmes règles ; ils ne partagent aucun code — l'un tourne dans un
 * navigateur, l'autre dans WordPress — mais ils partagent un corpus de cas,
 * `tests/qualification/cas.json`, que les deux bancs rejouent en exigeant des
 * verdicts identiques. Une divergence est un échec de test, jamais une
 * découverte de production.
 *
 * Pourquoi une barrière serveur
 * -----------------------------
 *
 * Parce que le navigateur n'en est pas une. Avant cette classe, une extension
 * de soixante mètres carrés traversait tout le formulaire de déclaration
 * préalable sans qu'une seule vérification ne s'y oppose : `sp_creee` n'avait
 * qu'un `min: 0`, et la validation métier ne regardait aucune surface.
 *
 * Sources
 * -------
 *
 * Code de l'urbanisme, vérifié sur Légifrance :
 *   R.421-2   constructions nouvelles dispensées de toute formalité
 *   R.421-9   constructions nouvelles soumises à déclaration préalable
 *   R.421-14  travaux sur constructions existantes soumis à permis
 *   R.421-17  travaux sur constructions existantes soumis à déclaration
 *   R.431-2   dispense d'architecte, et son plafond de 150 m²
 *
 * R.431-2 sert ici uniquement à l'exception de R.421-14 b) — la bascule en
 * permis d'une création de 20 à 40 m² en zone urbaine. L'obligation de recourir
 * à un architecte est une autre question, traitée ailleurs.
 *
 * @package Urbizen\Platform\Forms
 */

declare( strict_types=1 );

namespace Urbizen\Platform\Forms;

final class QualificationUrbanisme {

	/** Les cinq états, et rien d'autre. Aucun défaut implicite. */
	public const DP         = 'dp';
	public const PCMI       = 'pcmi';
	public const AUCUNE     = 'none';
	public const A_CONFIRMER = 'confirm';
	public const CONCEPTION = 'conception';

	public const ETATS = array( self::DP, self::PCMI, self::AUCUNE, self::A_CONFIRMER, self::CONCEPTION );

	/* Seuils, en mètres carrés et en mètres. Un seul endroit. */
	private const DISPENSE           = 5.0;   // R.421-2
	private const NOUVELLE_DP        = 20.0;  // R.421-9
	private const HAUTEUR_NOUVELLE   = 12.0;  // R.421-9 et R.421-2
	private const EXISTANT_PC        = 20.0;  // R.421-14 a)
	private const EXISTANT_PC_ZONE_U = 40.0;  // R.421-14 b)
	private const TOTAL_R431_2       = 150.0; // R.431-2 a)
	private const EXISTANT_DP        = 5.0;   // R.421-17 f) et g)
	private const BASSIN_DISPENSE    = 10.0;  // R.421-2
	private const BASSIN_DP          = 100.0; // R.421-9
	private const COUVERTURE_PISCINE = 1.8;   // R.421-9

	/**
	 * @param array<string, mixed> $donnees
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	public static function qualifier( array $donnees ): array {
		$projet = isset( $donnees['projet'] ) && is_string( $donnees['projet'] ) ? $donnees['projet'] : '';

		switch ( $projet ) {
			case 'extension':
				return self::sur_existant( $donnees, 'Extension' );

			case 'garage':
			case 'abri':
			case 'pergola':
				return self::selon_implantation( $donnees, $projet );

			case 'piscine':
				return self::piscine( $donnees );

			case 'transformation':
				return self::transformation( $donnees );

			case 'facade':
				if ( true === ( $donnees['modifie_structure_ou_facade'] ?? null )
					&& true === ( $donnees['changement_destination'] ?? null ) ) {
					return self::r( self::PCMI, 'R.421-14 c)', 'Modification de la façade accompagnant un changement de destination : permis de construire.' );
				}
				return self::r( self::DP, 'R.421-17 a)', 'Modification de l’aspect extérieur d’une construction existante : déclaration préalable.' );

			case 'toiture':
				return self::r( self::DP, 'R.421-17 a)', 'Réfection de toiture ou création d’ouvertures modifiant l’aspect extérieur : déclaration préalable.' );

			case 'solaire':
				if ( self::secteur_bloquant( $donnees ) ) {
					return self::r( self::A_CONFIRMER, 'R.421-17 a)', 'En secteur protégé, l’installation peut relever d’une autorisation particulière.', array( 'secteur_protege' ) );
				}
				return self::r( self::DP, 'R.421-17 a)', 'Panneaux modifiant l’aspect extérieur d’une construction existante : déclaration préalable.' );

			case 'maison':
				return self::r( self::PCMI, 'R.421-1', 'Construction d’une maison individuelle : permis de construire.' );

			case 'conception':
				return self::r( self::CONCEPTION, null, 'Prestation de plans sur mesure : hors qualification d’urbanisme.' );

			case 'autre':
				return self::r( self::A_CONFIRMER, null, 'Le projet doit être décrit avant de déterminer la formalité applicable.', array( 'description' ) );
		}

		return self::r( self::A_CONFIRMER, null, 'Type de projet inconnu du moteur de qualification.', array( 'projet' ) );
	}

	/**
	 * @param array<string, mixed> $donnees
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	private static function selon_implantation( array $donnees, string $projet ): array {
		$etiquettes = array(
			'garage'  => array( 'Garage accolé', 'Garage indépendant' ),
			'abri'    => array( 'Abri accolé', 'Abri indépendant' ),
			'pergola' => array( 'Pergola adossée', 'Pergola autonome' ),
		);

		$implantation = $donnees['implantation'] ?? null;

		if ( 'accole' === $implantation ) {
			return self::sur_existant( $donnees, $etiquettes[ $projet ][0] );
		}
		if ( 'independant' === $implantation ) {
			return self::construction_nouvelle( $donnees, $etiquettes[ $projet ][1] );
		}

		return self::r(
			self::A_CONFIRMER,
			'R.421-9 / R.421-14',
			'pergola' === $projet
				? 'Adossée à la construction ou autonome : les règles applicables ne sont pas les mêmes.'
				: 'Accolé à une construction existante ou indépendant : les règles applicables ne sont pas les mêmes.',
			array( 'implantation' )
		);
	}

	/**
	 * Travaux sur construction existante : extension, garage ou abri accolé.
	 *
	 * @param array<string, mixed> $donnees
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	private static function sur_existant( array $donnees, string $etiquette ): array {
		$cree = self::creation( $donnees );

		if ( null === $cree ) {
			return self::r( self::A_CONFIRMER, 'R.421-14', $etiquette . ' : la surface créée n’est pas connue.', array( 'sp_creee', 'emprise_creee' ) );
		}

		if ( $cree > self::EXISTANT_PC_ZONE_U ) {
			return self::r( self::PCMI, 'R.421-14 a)', 'Création de ' . self::m( $cree ) . ' m² : au-delà de 40 m², le permis est exigé quelle que soit la zone.' );
		}

		if ( $cree <= self::EXISTANT_DP ) {
			return self::r( self::A_CONFIRMER, 'R.421-17', 'Création de ' . self::m( $cree ) . ' m² : sous le seuil de 5 m², la formalité dépend de l’aspect extérieur des travaux.', array( 'aspect_exterieur' ) );
		}

		$zone       = $donnees['zone_u'] ?? null;
		$en_zone_u  = true === $zone;
		$hors_zone  = false === $zone;

		if ( $cree > self::EXISTANT_PC ) {
			if ( $hors_zone ) {
				return self::r( self::PCMI, 'R.421-14 a)', 'Création de ' . self::m( $cree ) . ' m² hors zone urbaine : au-delà de 20 m², le permis est exigé.' );
			}
			if ( ! $en_zone_u ) {
				return self::r( self::A_CONFIRMER, 'R.421-14 b)', 'Création de ' . self::m( $cree ) . ' m² : entre 20 et 40 m², la formalité dépend de la zone du document d’urbanisme.', array( 'zone_u' ) );
			}

			$total = self::nombre( $donnees['sp_totale'] ?? null );
			if ( null === $total ) {
				return self::r( self::A_CONFIRMER, 'R.421-14 b)', 'Création de ' . self::m( $cree ) . ' m² en zone urbaine : la surface totale après travaux décide entre déclaration et permis.', array( 'sp_totale' ) );
			}
			if ( false === ( $donnees['personne_physique'] ?? null ) || true === ( $donnees['usage_agricole'] ?? null ) ) {
				return self::r( self::A_CONFIRMER, 'R.431-2', 'Le plafond de 150 m² ne vaut que pour une personne physique construisant pour elle-même une construction non agricole.', array( 'personne_physique', 'usage_agricole' ) );
			}
			if ( $total > self::TOTAL_R431_2 ) {
				return self::r( self::PCMI, 'R.421-14 b) + R.431-2', 'Création de ' . self::m( $cree ) . ' m² en zone urbaine portant la surface totale à ' . self::m( $total ) . ' m², au-delà de 150 m² : le permis est exigé.' );
			}
			return self::r( self::DP, 'R.421-17 f)', 'Création de ' . self::m( $cree ) . ' m² en zone urbaine, surface totale de ' . self::m( $total ) . ' m² : la déclaration préalable suffit.' );
		}

		return self::r( self::DP, 'R.421-17 f)', 'Création de ' . self::m( $cree ) . ' m² : sous le seuil du permis, la déclaration préalable s’applique.' );
	}

	/**
	 * Construction indépendante : ce ne sont plus des travaux sur l'existant.
	 *
	 * @param array<string, mixed> $donnees
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	private static function construction_nouvelle( array $donnees, string $etiquette ): array {
		$cree = self::creation( $donnees );

		if ( null === $cree ) {
			return self::r( self::A_CONFIRMER, 'R.421-9', $etiquette . ' : la surface créée n’est pas connue.', array( 'sp_creee', 'emprise_creee' ) );
		}

		$hauteur = self::nombre( $donnees['hauteur_m'] ?? null );

		if ( $cree > self::NOUVELLE_DP ) {
			return self::r( self::PCMI, 'R.421-1', 'Construction indépendante de ' . self::m( $cree ) . ' m² : au-delà de 20 m², le permis est exigé.' );
		}

		if ( null !== $hauteur && $hauteur > self::HAUTEUR_NOUVELLE ) {
			return self::r( self::PCMI, 'R.421-9', 'Hauteur de ' . self::m( $hauteur ) . ' m : au-delà de 12 m, la construction sort du champ de la déclaration.' );
		}

		if ( self::secteur_bloquant( $donnees ) ) {
			return self::r( self::A_CONFIRMER, 'R.421-2 / R.421-9', 'En secteur patrimonial remarquable, aux abords d’un monument historique ou en site classé, les dispenses ne s’appliquent pas.', array( 'secteur_protege' ) );
		}

		if ( null === $hauteur ) {
			return self::r( self::A_CONFIRMER, 'R.421-9', 'La hauteur de la construction conditionne le régime.', array( 'hauteur_m' ) );
		}

		if ( $cree <= self::DISPENSE ) {
			return self::r( self::AUCUNE, 'R.421-2', 'Construction indépendante de ' . self::m( $cree ) . ' m² et de ' . self::m( $hauteur ) . ' m de haut : aucune formalité au titre du code de l’urbanisme.' );
		}

		return self::r( self::DP, 'R.421-9', 'Construction indépendante de ' . self::m( $cree ) . ' m² et de ' . self::m( $hauteur ) . ' m de haut : déclaration préalable.' );
	}

	/**
	 * @param array<string, mixed> $donnees
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	private static function piscine( array $donnees ): array {
		$bassin = self::nombre( $donnees['bassin_m2'] ?? null );

		if ( null === $bassin ) {
			return self::r( self::A_CONFIRMER, 'R.421-9', 'La superficie du bassin décide de la formalité.', array( 'bassin_m2' ) );
		}

		$couverture = self::nombre( $donnees['hauteur_couverture_m'] ?? null );
		$couverte   = true === ( $donnees['couverte'] ?? null );

		if ( $bassin > self::BASSIN_DP ) {
			return self::r( self::PCMI, 'R.421-9', 'Bassin de ' . self::m( $bassin ) . ' m² : au-delà de 100 m², le permis est exigé.' );
		}

		if ( $couverte && null === $couverture ) {
			return self::r( self::A_CONFIRMER, 'R.421-9', 'La hauteur de la couverture décide entre déclaration et permis.', array( 'hauteur_couverture_m' ) );
		}

		if ( $couverte && $couverture >= self::COUVERTURE_PISCINE ) {
			return self::r( self::PCMI, 'R.421-9', 'Couverture de ' . self::m( $couverture ) . ' m : à partir de 1,80 m, la piscine relève du permis.' );
		}

		if ( $bassin <= self::BASSIN_DISPENSE ) {
			if ( self::secteur_bloquant( $donnees ) ) {
				return self::r( self::A_CONFIRMER, 'R.421-2', 'Bassin de ' . self::m( $bassin ) . ' m² : la dispense tombe en secteur protégé.', array( 'secteur_protege' ) );
			}
			return self::r( self::AUCUNE, 'R.421-2', 'Bassin de ' . self::m( $bassin ) . ' m² : aucune formalité au titre du code de l’urbanisme.' );
		}

		return self::r( self::DP, 'R.421-9', 'Bassin de ' . self::m( $bassin ) . ' m² : déclaration préalable.' );
	}

	/**
	 * Cet espace compte-t-il déjà dans la surface de plancher ?
	 *
	 * Un garage n'y compte pas : c'est une aire de stationnement. Des combles ou
	 * un sous-sol n'y comptent pas sous 1,80 m de hauteur. Rend `null` quand la
	 * réponse dépend d'une donnée absente.
	 *
	 * @param array<string, mixed> $donnees
	 */
	private static function hors_surface_plancher( array $donnees ): ?bool {
		$local = $donnees['local_actuel'] ?? null;

		if ( 'garage' === $local ) {
			return true;
		}

		if ( in_array( $local, array( 'combles', 'sous_sol', 'dependance' ), true ) ) {
			$h = $donnees['hauteur_sup_180'] ?? null;
			if ( false === $h ) { return true; }
			if ( true === $h ) { return false; }
			return null;
		}

		return null;
	}

	/**
	 * Transformer un espace existant.
	 *
	 * Quatre situations que le mot « transformation » confond :
	 *
	 *   A. une surface close et couverte aujourd'hui hors surface de plancher
	 *      devient un local qui en constitue — R.421-17 g) ;
	 *   B. un vrai changement de destination au sens de R.151-27 ;
	 *   C. l'un ou l'autre avec travaux sur la structure ou la façade — R.421-14 c) ;
	 *   D. un simple réaménagement intérieur.
	 *
	 * Le piège est B. Un garage accessoire d'une maison a la destination du local
	 * principal — l'habitation : R.421-14 le dit en toutes lettres. Le transformer
	 * en chambre ne change AUCUNE destination. C'est A qui s'applique.
	 *
	 * La destination n'est donc jamais demandée : elle se déduit du rattachement.
	 *
	 * @param array<string, mixed> $donnees
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	private static function transformation( array $donnees ): array {
		$cree = self::creation( $donnees );

		if ( null === $cree ) {
			return self::r( self::A_CONFIRMER, 'R.421-17 g)', 'La surface transformée n’est pas connue.', array( 'sp_creee' ) );
		}

		$ferme = $donnees['ferme_couvert'] ?? null;

		if ( false === $ferme ) {
			return self::r( self::A_CONFIRMER, 'R.421-17', 'Un espace qui n’est pas clos et couvert ne relève pas de la transformation prévue par le code : le projet doit être décrit.', array( 'description' ) );
		}
		if ( true !== $ferme ) {
			return self::r( self::A_CONFIRMER, 'R.421-17 g)', 'L’espace transformé doit être clos et couvert pour relever de cette règle.', array( 'ferme_couvert' ) );
		}

		if ( empty( $donnees['local_actuel'] ) ) {
			return self::r( self::A_CONFIRMER, 'R.421-17 g)', 'La nature du local transformé décide du régime applicable.', array( 'local_actuel' ) );
		}

		$rattache   = $donnees['local_rattache'] ?? null;
		$changement = false;

		if ( 'batiment_separe' === $rattache ) {
			$destination = $donnees['destination_actuelle'] ?? null;
			if ( null === $destination ) {
				return self::r( self::A_CONFIRMER, 'R.151-27', 'Un bâtiment séparé a sa propre destination : la connaître décide s’il y a changement de destination.', array( 'destination_actuelle' ) );
			}
			$changement = 'habitation' !== $destination;
		} elseif ( 'maison' !== $rattache ) {
			return self::r( self::A_CONFIRMER, 'R.421-14 c)', 'Un local rattaché au logement en suit la destination ; un bâtiment séparé a la sienne.', array( 'local_rattache' ) );
		}

		if ( $changement ) {
			$travaux = $donnees['modifie_structure_ou_facade'] ?? null;
			if ( true === $travaux ) {
				return self::r( self::PCMI, 'R.421-14 c)', 'Changement de destination accompagné de travaux sur les structures porteuses ou la façade : permis de construire.' );
			}
			if ( false !== $travaux ) {
				return self::r( self::A_CONFIRMER, 'R.421-14 c)', 'Un changement de destination bascule en permis s’il s’accompagne de travaux sur la structure porteuse ou la façade.', array( 'modifie_structure_ou_facade' ) );
			}
			return self::r( self::DP, 'R.421-17 b)', 'Changement de destination sans travaux sur la structure ni la façade : déclaration préalable.' );
		}

		$hors = self::hors_surface_plancher( $donnees );

		if ( null === $hors ) {
			return self::r( self::A_CONFIRMER, 'R.421-17 g)', 'Sous 1,80 m de hauteur, l’espace ne compte pas dans la surface de plancher : la réponse change le régime.', array( 'hauteur_sup_180' ) );
		}

		if ( ! $hors ) {
			$aspect = $donnees['modifie_aspect_exterieur'] ?? null;
			if ( true === $aspect ) {
				return self::r( self::DP, 'R.421-17 a)', 'Réaménagement intérieur avec modification de l’aspect extérieur : déclaration préalable.' );
			}
			if ( false === $aspect ) {
				return self::r( self::AUCUNE, 'R.421-17', 'Réaménagement intérieur sans création de surface de plancher ni modification extérieure : aucune formalité au titre du code de l’urbanisme.' );
			}
			return self::r( self::A_CONFIRMER, 'R.421-17 a)', 'Cet espace compte déjà dans la surface de plancher : seule une modification extérieure déclencherait une formalité.', array( 'modifie_aspect_exterieur' ) );
		}

		if ( $cree <= self::EXISTANT_DP ) {
			return self::r( self::A_CONFIRMER, 'R.421-17 g)', 'Transformation de ' . self::m( $cree ) . ' m² : sous le seuil de 5 m², la formalité dépend des travaux extérieurs.', array( 'modifie_aspect_exterieur' ) );
		}

		$sur_seuils = self::sur_existant( $donnees, 'Transformation' );

		if ( self::DP === $sur_seuils['status'] ) {
			return self::r( self::DP, 'R.421-17 g)', 'Transformation de ' . self::m( $cree ) . ' m² de surface close et couverte en surface de plancher : déclaration préalable.' );
		}

		return $sur_seuils;
	}

	/* ------------------------------------------------------------ outils -- */

	/**
	 * @param array<string, mixed> $donnees
	 */
	private static function secteur_bloquant( array $donnees ): bool {
		$v = $donnees['secteur_protege'] ?? null;
		return true === $v || null === $v || 'unknown' === $v;
	}

	/**
	 * La plus grande des deux mesures : c'est elle que les seuils regardent.
	 *
	 * @param array<string, mixed> $donnees
	 */
	private static function creation( array $donnees ): ?float {
		$sp = self::nombre( $donnees['sp_creee'] ?? null );
		$em = self::nombre( $donnees['emprise_creee'] ?? null );

		if ( null === $sp && null === $em ) {
			return null;
		}

		return max( $sp ?? 0.0, $em ?? 0.0 );
	}

	/** Une valeur numérique utilisable, ou `null` si elle n'a pas été fournie. */
	private static function nombre( $v ): ?float {
		if ( null === $v || '' === $v || is_bool( $v ) || is_array( $v ) ) {
			return null;
		}
		if ( is_string( $v ) ) {
			$v = str_replace( ',', '.', trim( $v ) );
		}
		if ( ! is_numeric( $v ) ) {
			return null;
		}
		$n = (float) $v;
		return $n >= 0 ? $n : null;
	}

	/** Rend un nombre sans zéro décimal inutile, pour les messages. */
	private static function m( float $n ): string {
		$s = rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
		return '' === $s ? '0' : $s;
	}

	/**
	 * @param string[] $missing
	 * @return array{status: string, rule: ?string, reason: string, missing: string[]}
	 */
	private static function r( string $status, ?string $rule, string $reason, array $missing = array() ): array {
		return array(
			'status'  => $status,
			'rule'    => $rule,
			'reason'  => $reason,
			'missing' => $missing,
		);
	}
}
