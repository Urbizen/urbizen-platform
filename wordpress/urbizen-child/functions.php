<?php
/**
 * Thème enfant Urbizen — amorçage.
 *
 * Périmètre volontairement restreint au rendu :
 *   - compatibilité avec le thème parent Hostinger AI ;
 *   - chargement de la feuille de style enfant.
 *
 * Interdits dans ce fichier : traitement de formulaire, requête SQL, appel
 * réseau, manipulation de données personnelles. Ces responsabilités relèvent
 * exclusivement de l'extension urbizen-platform.
 *
 * @package Urbizen\Child
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compatibilité 1/2 — chemins PHP du thème parent.
 *
 * Le thème parent définit ses constantes avec get_stylesheet_directory(), qui
 * pointe vers le thème ENFANT dès que celui-ci est actif. Ses fichiers internes
 * (Builder, Admin, i18n WooCommerce) deviendraient alors introuvables.
 *
 * WordPress charge le functions.php de l'enfant AVANT celui du parent, et le
 * parent protège ses définitions par « if ( ! defined() ) ». On fixe donc ici
 * les valeurs correctes, pointant vers le répertoire du thème parent.
 */
if ( ! defined( 'HOSTINGER_AI_WEBSITES_THEME_PATH' ) ) {
	define( 'HOSTINGER_AI_WEBSITES_THEME_PATH', get_template_directory() );
}

if ( ! defined( 'HOSTINGER_AI_WEBSITES_ASSETS_URL' ) ) {
	define( 'HOSTINGER_AI_WEBSITES_ASSETS_URL', get_template_directory_uri() . '/assets' );
}

/**
 * Compatibilité 2/2 — URL des assets du thème parent.
 *
 * Le parent enregistre style.min.css et front-scripts.min.js avec
 * get_stylesheet_directory_uri(), ce qui produirait des 404 sous thème enfant.
 * On réécrit l'URL uniquement lorsque le fichier est absent du thème enfant et
 * présent dans le thème parent : les surcharges futures d'Urbizen restent donc
 * prioritaires, et les styles ajoutés en inline sur ces handles sont préservés.
 *
 * @param string $src URL de la ressource.
 * @return string URL corrigée si nécessaire.
 */
function urbizen_child_resolve_parent_asset( $src ) {
	if ( ! is_string( $src ) || '' === $src ) {
		return $src;
	}

	$child_uri = get_stylesheet_directory_uri();

	if ( 0 !== strpos( $src, $child_uri ) ) {
		return $src;
	}

	$relative = substr( $src, strlen( $child_uri ) );
	$path     = strtok( $relative, '?' );

	if ( '' === $path || false === $path ) {
		return $src;
	}

	// Le thème enfant fournit sa propre version : on ne touche à rien.
	if ( file_exists( get_stylesheet_directory() . $path ) ) {
		return $src;
	}

	if ( file_exists( get_template_directory() . $path ) ) {
		return get_template_directory_uri() . $relative;
	}

	return $src;
}
add_filter( 'style_loader_src', 'urbizen_child_resolve_parent_asset', 10, 1 );
add_filter( 'script_loader_src', 'urbizen_child_resolve_parent_asset', 10, 1 );

/**
 * Compatibilité 3/3 — palette de couleurs et police des titres.
 *
 * Le thème parent accroche `WebsiteBuilder::update_theme_json` au filtre
 * `wp_theme_json_data_theme` en priorité 999. Il y écrase deux choses :
 *
 * 1. `settings.color`, remplacé par une palette lue dans l'option Hostinger
 *    `hostinger_ai_colors` ;
 * 2. `styles.elements.heading.typography.fontFamily`, recalculé par
 *    `Fonts::get_main_font()`. Sous thème enfant, cette méthode ne retrouve pas
 *    les familles de polices et retombe sur `system-ui` : les titres perdent
 *    Poppins.
 *
 * Sous le thème parent, les styles globaux « utilisateur » enregistrés en base
 * reprenaient la main sur la couleur. Ces styles étant rattachés au thème
 * parent, ils ne suivent pas le thème enfant : sans ce filtre, le site repasse
 * sur la palette sombre de Hostinger, fonds noirs et textes illisibles.
 *
 * On réapplique donc les deux réglages après le parent, en priorité 1000. La
 * source de vérité reste le theme.json de l'enfant : aucune valeur n'est
 * dupliquée ici, tout est lu depuis le fichier versionné.
 *
 * @param \WP_Theme_JSON_Data $theme_json Données theme.json du thème.
 * @return \WP_Theme_JSON_Data
 */
function urbizen_child_restore_theme_json( $theme_json ) {
	static $overrides = null;

	if ( null === $overrides ) {
		$data = wp_json_file_decode(
			get_stylesheet_directory() . '/theme.json',
			array( 'associative' => true )
		);

		$palette      = $data['settings']['color']['palette'] ?? array();
		$heading_font = $data['styles']['elements']['heading']['typography']['fontFamily'] ?? '';

		$overrides = array( 'version' => 3 );

		if ( ! empty( $palette ) ) {
			$overrides['settings'] = array( 'color' => array( 'palette' => $palette ) );
		}

		if ( '' !== $heading_font ) {
			$overrides['styles'] = array(
				'elements' => array(
					'heading' => array(
						'typography' => array( 'fontFamily' => $heading_font ),
					),
				),
			);
		}
	}

	if ( count( $overrides ) < 2 || ! is_object( $theme_json ) || ! method_exists( $theme_json, 'update_with' ) ) {
		return $theme_json;
	}

	return $theme_json->update_with( $overrides );
}
add_filter( 'wp_theme_json_data_theme', 'urbizen_child_restore_theme_json', 1000 );

/**
 * Charge la feuille de style du thème enfant.
 *
 * Elle dépend du handle du parent afin d'être toujours chargée après lui.
 *
 * @return void
 */
function urbizen_child_enqueue_styles() {
	$style_path = get_stylesheet_directory() . '/style.css';

	if ( ! file_exists( $style_path ) ) {
		return;
	}

	wp_enqueue_style(
		'urbizen-child-style',
		get_stylesheet_uri(),
		array( 'hostinger-ai-style' ),
		(string) filemtime( $style_path )
	);
}
add_action( 'wp_enqueue_scripts', 'urbizen_child_enqueue_styles', 20 );

/**
 * Réglages du thème enfant.
 *
 * Les patterns du répertoire /patterns sont enregistrés automatiquement par
 * WordPress à partir de leurs en-têtes de commentaire : rien à déclarer ici.
 *
 * @return void
 */
function urbizen_child_setup() {
	load_child_theme_textdomain( 'urbizen-child', get_stylesheet_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'urbizen_child_setup' );

/**
 * Identifiant du gabarit de la page d'accueil Urbizen.
 */
const URBIZEN_CHILD_TEMPLATE_ACCUEIL = 'page-accueil-urbizen';

/**
 * Gabarits Urbizen des pages internes (hors accueil).
 *
 * Ces pages réutilisent la charte, les polices et la feuille générée de
 * l'accueil, sans écrire de CSS propre. On n'y inscrit un slug que lorsque son
 * gabarit existe réellement dans le thème.
 */
/**
 * Version du lot de ressources des formulaires.
 *
 * Une seule valeur pour trois endroits : l'URL du cadre produite par cette page,
 * les feuilles et scripts que les documents DP et PC chargent, et les bancs de
 * contrat. Les faire diverger reviendrait à servir un document neuf qui appelle
 * d'anciens scripts, ou l'inverse — précisément la panne que le versionnement
 * doit fermer.
 *
 * À incrémenter à chaque changement d'un de ces fichiers. Un paramètre tiré au
 * hasard à chaque affichage invaliderait le cache en permanence : ce n'est pas
 * une invalidation, c'est une suppression.
 */
const URBIZEN_CHILD_FORMS_VERSION = '0.2.8';

const URBIZEN_CHILD_TEMPLATES_PAGES = array(
	'page-declaration-prealable',
	'page-permis-de-construire',
	'page-conception',
	'page-tarifs',
	'page-formulaire-declaration-prealable',
	'page-formulaire-permis-de-construire',
	'page-formulaire-conception',
	'page-mentions-legales',
	'page-cgv',
	'page-confidentialite',
);

/**
 * Identifiant du gabarit de la page Tarifs.
 */
const URBIZEN_CHILD_TEMPLATE_TARIFS = 'page-tarifs';

/**
 * Catalogue tarifaire affiché par la page Tarifs.
 *
 * SOURCE UNIQUE DE LA PAGE. Avant ce catalogue, les montants étaient recopiés
 * à la main dans cinq fichiers HTML, sans rien pour signaler qu'ils avaient
 * divergé. Ici, un seul endroit les porte, et `tests/tarifs/test-tarifs-source.php`
 * les compare aux constantes du greffon — celles qui chiffrent réellement les
 * formulaires :
 *
 *   `Urbizen\Platform\Forms\PricingDeclarationPrealable::NATURES`
 *   `Urbizen\Platform\Forms\PricingPermisConstruire::NATURES`
 *   `Urbizen\Platform\Forms\PricingProjets::SUPPLEMENT_ABF`
 *
 * Le thème ne LIT pas ces constantes au moment du rendu, et c'est délibéré :
 * le greffon désactivé, la page continuerait de s'afficher. C'est le banc, et
 * non le rendu, qui interdit la divergence — un test rouge plutôt qu'une page
 * vide.
 *
 * La Conception est incluse dans cette comparaison au même titre que les
 * autres : son socle, 449 €, est celui de `Pricing::BASE`, dont part
 * réellement le formulaire. Aucun montant n'échappe au contrôle.
 *
 * @return array<string, mixed>
 */
function urbizen_child_tarifs() {
	return array(
		'groupes'    => array(
			array(
				'ref'         => 'DP',
				'id'          => 'dp',
				// Surtitre de carte. Volontairement DIFFÉRENT du titre du groupe :
				// répéter « Déclaration préalable » au-dessus de chacune des trois
				// cartes, juste sous l'en-tête qui le dit déjà, n'apprend rien et
				// alourdit la lecture.
				'kicker'      => 'Forfait DP',
				'titre'       => 'Déclaration préalable',
				'accroche'    => 'Pour les travaux et aménagements soumis à déclaration préalable.',
				'cta'         => '/formulaire-declaration-prealable/',
				'cta_libelle' => 'Démarrer ma déclaration préalable',
				'offres'      => array(
					array(
						'nom'      => 'Projet simple',
						'exemples' => 'Clôtures &amp; panneaux solaires',
						'texte'    => 'Pour les projets simples tels que la pose de panneaux solaires ou la création et la modification d’une clôture.',
						'prix'     => 189,
					),
					array(
						'nom'      => 'Projet standard',
						'exemples' => 'Piscine, abri de jardin, carport, pergola, modification de façade, toiture, fenêtres de toit',
						'texte'    => 'Pour les autres projets courants relevant d’une déclaration préalable.',
						'prix'     => 249,
						'populaire' => true,
					),
					array(
						'nom'      => 'Projet important',
						'exemples' => 'Extension, agrandissement, surélévation',
						'texte'    => 'Lorsque le projet relève effectivement d’une déclaration préalable au regard de ses caractéristiques.',
						'prix'     => 549,
					),
				),
			),
			array(
				'ref'         => 'PC',
				'id'          => 'pc',
				'kicker'      => 'Forfait PC',
				'titre'       => 'Permis de construire',
				'accroche'    => 'Pour les constructions et agrandissements nécessitant un permis.',
				'cta'         => '/formulaire-permis-de-construire/',
				'cta_libelle' => 'Démarrer mon permis de construire',
				'offres'      => array(
					array(
						'nom'      => 'Projet simple',
						'exemples' => 'Garage, carport, annexe',
						'texte'    => 'Pour une construction simple nécessitant un permis de construire.',
						'prix'     => 449,
					),
					array(
						'nom'      => 'Extension / agrandissement',
						'exemples' => 'Extension, agrandissement, surélévation',
						'texte'    => 'Dossier de permis de construire pour une extension, un agrandissement ou une surélévation selon les caractéristiques du projet.',
						'prix'     => 649,
					),
					array(
						'nom'      => 'Maison individuelle',
						'exemples' => 'Construction neuve',
						'texte'    => 'Préparation du dossier de permis de construire d’une maison individuelle, selon les éléments fournis et les pièces nécessaires au projet.',
						'prix'     => 849,
						'premium'  => true,
					),
				),
			),
		),
		// Bloc distinct des autorisations d'urbanisme : la conception n'est pas
		// une démarche administrative, elle la précède.
		'conception' => array(
			'ref'         => 'PS',
			'id'          => 'conception',
			'kicker'      => 'Forfait PS',
			'titre'       => 'Conception de plans sur mesure',
			'accroche'    => 'Pour concevoir votre projet avant de préparer votre autorisation d’urbanisme.',
			'texte'       => 'Besoin de concevoir votre projet avant de préparer votre autorisation d’urbanisme&nbsp;? Urbizen réalise des plans adaptés à votre terrain, à vos besoins et aux informations transmises.',
			'prix'        => 449,
			'cta'         => '/formulaire-conception/',
			'cta_libelle' => 'Estimer mon projet',
		),
		'abf'        => array(
			'montant' => 80,
			'titre'   => 'Secteur Bâtiments de France',
			'texte'   => 'Supplément applicable aux déclarations préalables et permis de construire nécessitant un traitement spécifique lié au secteur protégé.',
		),
		// Formulations volontairement prudentes : toutes les pièces d'un dossier
		// DP ou PC ne sont pas nécessaires à tous les projets.
		'inclusions' => array(
			'CERFA complété',
			'Plans nécessaires au dossier',
			'Pièces graphiques nécessaires',
			'Mise en forme du dossier',
			'Accompagnement sur les pièces complémentaires liées à la prestation',
		),
	);
}

/**
 * Identifiants des gabarits des trois documents légaux.
 */
const URBIZEN_CHILD_TEMPLATES_LEGAL = array(
	'page-mentions-legales',
	'page-cgv',
	'page-confidentialite',
);

/**
 * Données légales d'Urbizen — source unique des trois documents.
 *
 * POURQUOI UNE SOURCE UNIQUE
 *
 * L'identité, l'adresse, les immatriculations et l'hébergeur se répètent entre
 * les mentions légales, les CGV et la politique de confidentialité. Recopiés
 * dans trois fichiers, ils divergent : c'est exactement ce qui est arrivé aux
 * tarifs, restés à 149 € dans les CGV longtemps après leur passage à 189 €.
 *
 * RÈGLE ABSOLUE : `null` SIGNIFIE « INCONNU »
 *
 * Une donnée non vérifiée vaut `null`, jamais une chaîne vide, jamais un
 * « à compléter », jamais une valeur plausible. Les gabarits **omettent** la
 * ligne correspondante plutôt que d'afficher un trou, et
 * `urbizen_child_donnees_legales_manquantes()` la remonte au contrôle de
 * préparation. Une page légale qui affiche « [À COMPLÉTER] » est pire qu'une
 * page absente : elle donne l'apparence de la conformité.
 *
 * Les valeurs présentes ci-dessous ont été confirmées par la propriétaire les
 * 10 et 11 août 2026. Celles qui manquent sont documentées dans
 * `docs/AUDIT_PAGES_LEGALES.md`.
 *
 * @return array<string, mixed>
 */
function urbizen_child_donnees_legales() {
	return array(
		// --- Éditeur, confirmé ---
		'entrepreneur'           => 'Anaïs Bacarisse',
		'forme'                  => 'Entrepreneur individuel (EI)',
		'nom_commercial'         => 'Urbizen',
		'siren'                  => '105 253 132',
		'siret'                  => '105 253 132 00010',
		'rcs'                    => '105 253 132 R.C.S. Paris',
		'adresse'                => array( '59 rue de Ponthieu', '75008 Paris', 'France' ),
		'email'                  => 'contact@urbizen.fr',
		'telephone'              => '06 64 89 58 15',
		'telephone_lien'         => '+33664895815',
		'site'                   => 'https://urbizen.fr',
		'directeur_publication'  => 'Anaïs Bacarisse',

		// --- Hébergeur ---
		// Entité contractante publiée par Hostinger pour l'Union européenne.
		// Le téléphone reste `null` : Hostinger ne publie pas de numéro
		// contractuel rattaché à cette entité, et en inventer un serait pire
		// que de n'en afficher aucun.
		'hebergeur'              => array(
			'raison_sociale' => 'Hostinger International Ltd',
			'adresse'        => array( '61 Lordou Vironos str.', '6023 Larnaca', 'Chypre' ),
			'site'           => 'https://www.hostinger.fr',
			'telephone'      => null,
		),

		// --- Régime fiscal, confirmé le 11 août 2026 ---
		// La micro-entreprise est un RÉGIME FISCAL, pas une forme juridique :
		// elle se range ici, et non dans `forme`, qui reste « Entrepreneur
		// individuel (EI) ».
		//
		// AUCUNE RÉFÉRENCE RÉGLEMENTAIRE ICI, ET C'EST VOLONTAIRE
		//
		// La référence à citer sur les factures en franchise en base change au
		// 1er septembre 2026. Une page légale est un document permanent : y
		// figer un article de loi, c'est programmer une inexactitude datée que
		// personne ne pensera à corriger. Ces pages énoncent donc le RÉGIME,
		// qui ne change pas, et la conséquence pour le client, qui ne change
		// pas non plus — les prix ne supportent pas de TVA.
		//
		// La mention réglementaire précise relève du devis et de la facture,
		// où elle s'apprécie à la date d'émission du document. Elle n'a donc
		// pas sa place dans cette source, qui n'alimente que des pages
		// publiques.
		//
		// `numero` reste `null` : la franchise en base n'impose pas d'afficher
		// un numéro de TVA intracommunautaire, et aucun n'a été communiqué.
		// Absent veut dire absent, pas « à retrouver ».
		'tva'                    => array(
			'statut' => 'Micro-entreprise',
			'regime' => 'Franchise en base de TVA',
			'effet'  => 'Les prix sont indiqués nets de TVA, celle-ci n\'étant pas facturée dans le cadre de ce régime.',
			'numero' => null,
		),

		// --- Assurance, confirmée le 11 août 2026 sur attestation ---
		// UN SEUL CONTRAT COUVRE LES DEUX GARANTIES. D'où une seule entrée :
		// deux clés séparées recopieraient le même numéro à deux endroits,
		// c'est-à-dire exactement la divergence que cette source existe pour
		// empêcher.
		//
		// Les dates ne sont PAS destinées au public : les gabarits ne les
		// affichent pas. Elles servent uniquement d'alerte de fraîcheur à
		// `urbizen_child_donnees_legales_manquantes()`, pour qu'une attestation
		// échue soit signalée avant un déploiement plutôt que découverte par un
		// client.
		'assurance'              => array(
			'assureur'          => 'Zurich Insurance Europe AG, succursale française',
			'contrat'           => '7400042329-199800202',
			'garanties'         => array(
				'Responsabilité Civile Professionnelle',
				'Responsabilité Civile Décennale',
			),
			'activites'         => array(
				'Assistant à la maîtrise d\'ouvrage technique',
				'Dessinateur projeteur',
			),
			'territoire'        => 'France métropolitaine et Corse',
			'attestation_debut' => '2026-07-01',
			'attestation_fin'   => '2026-12-31',
		),

		// --- Médiateur de la consommation, adhésion finalisée le 11 août 2026 ---
		// Le professionnel qui vend à des consommateurs doit garantir un
		// recours à un médiateur ET en communiquer les coordonnées
		// (code de la consommation, art. L.616-1). Les deux conditions sont
		// désormais remplies : la CGV nomme le médiateur au lieu d'annoncer
		// qu'il sera désigné.
		'mediateur'              => array(
			'nom'     => 'Centre de la Médiation de la Consommation de Conciliateurs de Justice',
			'sigle'   => 'CM2C',
			'adresse' => array( '49 rue de Ponthieu', '75008 Paris', 'France' ),
			'site'    => 'https://www.cm2c.net',
		),
	);
}

/**
 * Données légales manquantes, classées par gravité.
 *
 * DEUX NIVEAUX, ET LA DIFFÉRENCE COMPTE
 *
 * `bloquant`   — sans cette donnée, le document est en défaut vis-à-vis d'une
 *                obligation qui lui est propre. Il ne doit pas être publié.
 * `a_verifier` — l'absence appauvrit le document sans le rendre irrégulier.
 *                La publication reste possible.
 *
 * Confondre les deux conduit soit à publier un document non conforme, soit à
 * bloquer indéfiniment une page pour une information facultative.
 *
 * @return array<int, array<string, string>>
 */
function urbizen_child_donnees_legales_manquantes() {
	$d       = urbizen_child_donnees_legales();
	$absents = array();

	if ( null === $d['mediateur'] ) {
		$absents[] = array(
			'cle'       => 'mediateur',
			'document'  => 'CGV',
			'niveau'    => 'bloquant',
			'pourquoi'  => 'Le professionnel qui vend à des consommateurs doit garantir un recours à un médiateur de la consommation et en communiquer les coordonnées (code de la consommation, art. L.616-1). Une CGV qui annonce un médiateur sans le désigner ne satisfait pas cette obligation.',
			'ou'        => 'CGV, section « Médiation de la consommation », et rappel dans les mentions légales.',
		);
	}

	// La branche subsiste alors que la donnée est renseignée : elle décrit
	// l'obligation, pas l'état du jour. La retirer ferait disparaître le
	// contrôle en même temps que le problème qu'il surveille.
	if ( null === $d['tva'] ) {
		$absents[] = array(
			'cle'       => 'tva',
			'document'  => 'CGV',
			'niveau'    => 'bloquant',
			'pourquoi'  => 'Le prix doit être annoncé de façon non équivoque : soit TTC, soit en indiquant que le vendeur relève de la franchise en base et que les prix ne supportent pas de TVA. Publier des prix sans indiquer lequel des deux régimes s\'applique laisse le consommateur dans l\'incertitude. La référence réglementaire à porter sur les factures s\'apprécie à leur date d\'émission et ne se fige pas dans une page permanente.',
			'ou'        => 'CGV, section « Prix », et cohérence avec la page /tarifs/.',
		);
	}

	if ( null === $d['assurance'] ) {
		$absents[] = array(
			'cle'       => 'assurance',
			'document'  => 'Mentions légales',
			'niveau'    => 'a_verifier',
			'pourquoi'  => 'L\'activité déclarée d\'Urbizen est une assistance administrative et graphique, sans maîtrise d\'œuvre ni travaux : l\'affichage des assurances n\'est pas, à ce titre, une mention obligatoire des mentions légales. Son absence ne rend donc pas la page irrégulière. En revanche, la page /tarifs/ met en avant « RCP et assurance décennale » : cette affirmation commerciale doit pouvoir être justifiée.',
			'ou'        => 'Mentions légales, section « Assurances » — omise tant que l\'attestation n\'est pas fournie.',
		);
	}

	// FRAÎCHEUR DE L'ATTESTATION
	//
	// Une attestation d'assurance est datée, et les pages affirment une
	// couverture au présent. Passé le terme, l'affirmation n'est plus
	// justifiée : ce n'est pas un défaut de rédaction, c'est une pièce à
	// renouveler. Signalé sans bloquer — le contrat peut être en cours de
	// reconduction alors que la nouvelle attestation n'est pas encore émise.
	//
	// Ce contrôle dépend volontairement de la date du jour : c'est ce qui
	// empêche le site d'entrer dans l'année suivante en affichant une
	// couverture dont la pièce justificative a expiré.
	if ( is_array( $d['assurance'] ) && null !== $d['assurance']['attestation_fin'] ) {
		$fin = $d['assurance']['attestation_fin'];

		if ( gmdate( 'Y-m-d' ) > $fin ) {
			$absents[] = array(
				'cle'       => 'assurance_attestation',
				'document'  => 'Mentions légales',
				'niveau'    => 'a_verifier',
				'pourquoi'  => sprintf(
					'L\'attestation d\'assurance couvre la période s\'achevant le %s, désormais passée. Les mentions légales, les CGV et la page /tarifs/ affirment une couverture au présent : il faut obtenir l\'attestation de la période en cours, ou retirer ces affirmations.',
					$fin
				),
				'ou'        => 'Source commune `urbizen_child_donnees_legales()`, clé `assurance` — mettre à jour `attestation_debut` et `attestation_fin`.',
			);
		}
	}

	if ( null === $d['hebergeur']['telephone'] ) {
		$absents[] = array(
			'cle'       => 'hebergeur_telephone',
			'document'  => 'Mentions légales',
			'niveau'    => 'a_verifier',
			'pourquoi'  => 'La LCEN impose de mentionner le nom et l\'adresse de l\'hébergeur, ainsi que ses coordonnées. Le nom, l\'adresse et le site d\'Hostinger sont publiés ; Hostinger ne publie pas de numéro contractuel rattaché à l\'entité européenne. L\'absence de numéro n\'invalide pas la mention dès lors qu\'un moyen de contact reste indiqué.',
			'ou'        => 'Mentions légales, section « Hébergement ».',
		);
	}

	return $absents;
}

/**
 * La page affichée est-elle l'un des trois documents légaux ?
 *
 * @return bool
 */
function urbizen_child_est_page_legale() {
	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return in_array( get_page_template_slug( $id ), URBIZEN_CHILD_TEMPLATES_LEGAL, true );
}

/**
 * La page affichée est-elle l'un des deux formulaires d'autorisation ?
 *
 * @return bool
 */
function urbizen_child_est_page_formulaire_autorisation() {
	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return in_array(
		get_page_template_slug( $id ),
		array( 'page-formulaire-declaration-prealable', 'page-formulaire-permis-de-construire' ),
		true
	);
}

/**
 * Origine du site, au sens exact que le navigateur donne à ce mot.
 *
 * Une origine est un triplet — schéma, hôte, **port**. Composer seulement le
 * schéma et l'hôte donne la bonne valeur tant que le port est celui par défaut,
 * et une valeur fausse dès qu'il ne l'est pas. Le pont compare cette chaîne à
 * `window.location.origin` au caractère près : un port omis fait rejeter la
 * configuration, et le bouton d'envoi ne se déverrouille jamais.
 *
 * Le défaut ne se voyait pas en production — `urbizen.fr` répond en HTTPS sur
 * 443, que le navigateur n'écrit pas. Il s'est révélé au premier essai intégré
 * local, sur un serveur écoutant sur un autre port.
 *
 * @return string Origine complète, sans barre oblique finale.
 */
function urbizen_child_origine_site() {
	$parties = wp_parse_url( home_url() );

	if ( ! is_array( $parties ) || empty( $parties['scheme'] ) || empty( $parties['host'] ) ) {
		return '';
	}

	$origine = $parties['scheme'] . '://' . $parties['host'];

	// Les ports par défaut ne figurent pas dans `location.origin` : les ajouter
	// produirait la même divergence, en sens inverse.
	$defaut = array( 'http' => 80, 'https' => 443 );
	$port   = isset( $parties['port'] ) ? (int) $parties['port'] : 0;

	if ( $port > 0 && ( $defaut[ $parties['scheme'] ] ?? 0 ) !== $port ) {
		$origine .= ':' . $port;
	}

	return $origine;
}

/**
 * Interdit la mise en cache d'une page qui porte un secret à usage unique.
 *
 * Le défaut que cette fonction ferme s'est produit en production, et il était
 * silencieux : LiteSpeed servait la page de formulaire depuis son cache, donc
 * **le même jeton anti-robot à tous les visiteurs**. Ce jeton est à usage
 * unique — `AntiSpam::reserve_token()` refuse un jeton déjà réservé. Le premier
 * visiteur qui envoyait sa demande le consommait ; tous les suivants étaient
 * refusés, avec un message d'erreur générique, jusqu'à expiration du cache.
 *
 * Une exclusion existe dans la configuration de LiteSpeed. Elle est nécessaire
 * mais fragile : elle vit dans un réglage, qu'une réinstallation, une
 * restauration ou une migration efface sans bruit. La règle doit voyager avec
 * le code qui crée le problème.
 *
 * Deux mécanismes, parce qu'ils ne couvrent pas la même chose : `nocache_headers()`
 * s'adresse aux caches HTTP en aval, `litespeed_control_set_nocache` au cache
 * de pages de LiteSpeed, qui décide avant d'émettre le moindre en-tête.
 *
 * **Seule la page est visée.** Les feuilles, scripts et images gardent leur
 * cache : ils portent une version dans leur URL, ce qui est précisément la
 * bonne façon de les invalider. Les rendre non cacheables ferait payer à chaque
 * visiteur un défaut qui ne les concerne pas.
 *
 * @return void
 */
function urbizen_child_interdire_cache_formulaire() {
	if ( ! urbizen_child_est_page_formulaire_autorisation() ) {
		return;
	}

	// Le cache de pages de LiteSpeed, avec un motif lisible dans ses journaux.
	do_action( 'litespeed_control_set_nocache', 'page portant un jeton de formulaire à usage unique' );

	// Les caches HTTP en aval — proxy, navigateur — pour la page seule.
	if ( ! headers_sent() ) {
		nocache_headers();
	}
}
add_action( 'template_redirect', 'urbizen_child_interdire_cache_formulaire' );

/**
 * Configuration de soumission du formulaire affiché.
 *
 * Le nonce est émis ici, dans la page parente, et non dans le document servi en
 * iframe : ce dernier est un fichier statique du thème, qu'aucun PHP ne rend.
 * C'est précisément pour cela que le pont `postMessage` existe.
 *
 * Rien de ce tableau n'est décidé par le navigateur. L'action et le type sont
 * dérivés du **gabarit de la page**, donc d'une valeur serveur ; l'origine
 * autorisée vient de `home_url()`, jamais d'un en-tête de requête.
 *
 * @return array<string, string>
 */
function urbizen_child_configuration_formulaire() {
	// Une entrée par parcours raccordé. Le gabarit de la page détermine la
	// route : c'est le serveur qui décide, jamais un attribut du document servi
	// en iframe. Un nonce est lié à son action, et chaque parcours a la sienne —
	// partager une action laisserait un nonce émis pour une DP autoriser l'envoi
	// d'un permis de construire.
	$gabarits = array(
		'page-formulaire-declaration-prealable' => array(
			'action' => 'urbizen_declaration_prealable',
			'nonce'  => 'urbizen_declaration_prealable_submit',
			'type'   => 'declaration_prealable',
			'frame'  => 'dp-formulaire.html',
		),
		'page-formulaire-permis-de-construire'  => array(
			'action' => 'urbizen_permis_construire',
			'nonce'  => 'urbizen_permis_construire_submit',
			'type'   => 'permis_construire',
			'frame'  => 'pc-formulaire.html',
		),
	);

	if ( ! is_singular() ) {
		return array();
	}

	$slug = get_page_template_slug( get_queried_object_id() );

	if ( ! isset( $gabarits[ $slug ] ) ) {
		// Un gabarit absent de la table ne reçoit aucune configuration : son
		// formulaire reste inerte, plutôt que d'hériter d'une route qui n'est
		// pas la sienne.
		return array();
	}

	$route = $gabarits[ $slug ];

	// Le jeton anti-robot est émis ici pour la même raison que le nonce : il est
	// signé et horodaté côté serveur, et le document servi en iframe est un
	// fichier statique qu'aucun PHP ne rend. Sans lui, la route refuse toute
	// soumission — `invalid_antispam_token` — et aucun envoi depuis un
	// navigateur ne peut aboutir. Les bancs ne le voyaient pas : ils
	// fabriquaient le jeton eux-mêmes.
	$jeton = class_exists( '\\Urbizen\\Platform\\Security\\AntiSpam' )
		? \Urbizen\Platform\Security\AntiSpam::issue_token()
		: '';

	// Le greffon est la source de vérité métier. Absent — désactivé, en cours de
	// mise à jour — le formulaire n'affiche aucun champ conditionnel plutôt que
	// de tous les afficher : mieux vaut demander trop peu que demander une
	// surface de plancher pour une piscine.
	$matrice       = array();
	$conditionnels = array();

	if ( class_exists( '\\Urbizen\\Platform\\Forms\\MatriceChamps' ) ) {
		$matrice       = (array) ( \Urbizen\Platform\Forms\MatriceChamps::pour_type( $route['type'] ) ?? array() );
		$conditionnels = \Urbizen\Platform\Forms\MatriceChamps::CONDITIONNELS;
	}

	return array(
		'action'         => $route['action'],
		'formType'       => $route['type'],
		'nonceField'     => 'urbizen_conception_nonce',
		'nonce'          => wp_create_nonce( $route['nonce'] ),
		'tokenField'     => 'urbizen_token',
		'token'          => $jeton,
		'honeypotField'  => 'company_website',
		// La matrice métier voyage telle quelle depuis le greffon : le
		// navigateur n'en tient pas une seconde copie. Une liste recopiée à la
		// main dériverait, et l'interface finirait par proposer un champ que le
		// serveur écarte — ou l'inverse, plus grave : masquer un champ que le
		// serveur attend.
		'matrice'        => $matrice,
		'champsConditionnels' => $conditionnels,
		'submitUrl'      => admin_url( 'admin-post.php' ),
		'origin'         => urbizen_child_origine_site(),
		// **Sans version.** Le gabarit porte `?v=…` sur le cadre ; la comparaison
		// côté parent est un `indexOf`, donc un préfixe suffit. Y mettre la
		// version obligerait les deux à rester synchrones au caractère près, et
		// une divergence ferait échouer la vérification de source — le
		// formulaire deviendrait inerte pour une raison invisible.
		'frameSource'    => '/wp-content/themes/urbizen-child/assets/forms/' . $route['frame'],
		'assetsVersion'  => URBIZEN_CHILD_FORMS_VERSION,
	);
}

/**
 * La page affichée utilise-t-elle un gabarit Urbizen — accueil ou page interne ?
 *
 * Étend `urbizen_child_est_accueil_urbizen()` aux pages internes qui empruntent
 * la même charte : elles doivent recevoir les mêmes polices, tokens et feuille.
 *
 * @return bool
 */
function urbizen_child_est_page_urbizen() {
	if ( urbizen_child_est_accueil_urbizen() ) {
		return true;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return in_array( get_page_template_slug( $id ), URBIZEN_CHILD_TEMPLATES_PAGES, true );
}

/**
 * La page affichée utilise-t-elle le gabarit commercial « Conception » ?
 *
 * Sert à ne charger la feuille de galerie et le script de protection des
 * visuels que sur cette page, et nulle part ailleurs.
 *
 * @return bool
 */
function urbizen_child_est_page_conception() {
	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return 'page-conception' === get_page_template_slug( $id );
}

/**
 * La page affichée utilise-t-elle le gabarit « Tarifs » ?
 *
 * @return bool
 */
function urbizen_child_est_page_tarifs() {
	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return URBIZEN_CHILD_TEMPLATE_TARIFS === get_page_template_slug( $id );
}

/**
 * Titre de document de la page Tarifs.
 *
 * Le thème ne portait jusqu'ici aucun réglage de référencement : le titre
 * venait du nom de la page, « Tarifs », qui ne dit ni de quoi ni pour qui.
 * On le pose donc ici, et UNIQUEMENT sur ce gabarit — aucune autre page n'est
 * touchée.
 *
 * Comme la description, ce titre est un REPLI : dès qu'un greffon de
 * référencement est actif, le thème se retire. En pratique un tel greffon
 * court-circuite déjà `document_title_parts` par `pre_get_document_title`,
 * et notre filtre resterait sans effet — mais mieux vaut un retrait explicite
 * qu'un filtre qu'on croit actif et qui ne l'est pas.
 *
 * @param array<string, string> $parties Fragments du titre.
 * @return array<string, string>
 */
function urbizen_child_titre_tarifs( $parties ) {
	if ( ! urbizen_child_est_page_tarifs() ) {
		return $parties;
	}

	if ( urbizen_child_seo_gere_ailleurs() ) {
		return $parties;
	}

	$parties['title'] = __( 'Tarifs déclaration préalable et permis de construire', 'urbizen-child' );

	return $parties;
}
add_filter( 'document_title_parts', 'urbizen_child_titre_tarifs' );

/**
 * Un greffon de référencement gère-t-il déjà les métadonnées de la page ?
 *
 * POURQUOI CETTE FONCTION EXISTE
 *
 * La première version de ce garde-fou énumérait trois constantes — Yoast,
 * Rank Math, SEOPress — et se croyait complète. Le site utilise **All in One
 * SEO Pack**, qui n'y figurait pas : la page Tarifs a servi DEUX balises
 * `<meta name="description">` en production, celle d'AIOSEO et la nôtre.
 *
 * La leçon n'est pas « il manquait une constante », c'est qu'une énumération
 * est fausse par construction : elle ne connaît que le passé. Trois choses la
 * rendent tenable ici :
 *
 *   1. la détection est **nommée**, donc testable et vérifiable d'un coup
 *      d'œil, au lieu d'être noyée dans une condition ;
 *   2. chaque greffon est cherché par **plusieurs marqueurs** (constante,
 *      fonction, classe) : un greffon qui renomme sa constante reste vu ;
 *   3. le filtre `urbizen_child_seo_gere_ailleurs` permet de trancher sans
 *      toucher au code, le jour où un greffon inconnu apparaît.
 *
 * Le thème reste volontairement en retrait : il ne cherche pas à concurrencer
 * un greffon de référencement, il comble seulement un vide s'il n'y en a pas.
 *
 * @return bool Vrai si un greffon SEO est actif.
 */
function urbizen_child_seo_gere_ailleurs() {
	$marqueurs = array(
		// All in One SEO Pack — celui qu'emploie le site, et que la première
		// version de ce contrôle ignorait.
		'AIOSEO_VERSION',
		'AIOSEO_PLUGIN_NAME',
		// Yoast SEO.
		'WPSEO_VERSION',
		// Rank Math.
		'RANK_MATH_VERSION',
		// SEOPress.
		'SEOPRESS_VERSION',
		// The SEO Framework.
		'THE_SEO_FRAMEWORK_VERSION',
		// Slim SEO.
		'SLIM_SEO_VER',
		// Squirrly SEO.
		'SQUIRRLY_PLUGIN_VERSION',
	);

	foreach ( $marqueurs as $constante ) {
		if ( defined( $constante ) ) {
			return (bool) apply_filters( 'urbizen_child_seo_gere_ailleurs', true, $constante );
		}
	}

	foreach ( array( 'aioseo', 'rank_math', 'wpseo_init' ) as $fonction ) {
		if ( function_exists( $fonction ) ) {
			return (bool) apply_filters( 'urbizen_child_seo_gere_ailleurs', true, $fonction );
		}
	}

	foreach ( array( 'WPSEO_Frontend', 'RankMath', 'SEOPress' ) as $classe ) {
		if ( class_exists( $classe ) ) {
			return (bool) apply_filters( 'urbizen_child_seo_gere_ailleurs', true, $classe );
		}
	}

	return (bool) apply_filters( 'urbizen_child_seo_gere_ailleurs', false, '' );
}

/**
 * Description de la page Tarifs — repli, jamais doublon.
 *
 * Émise seulement si aucun greffon de référencement ne gère déjà la page :
 * deux balises `description` concurrentes valent moins qu'une seule, et c'est
 * le greffon qui doit rester la source du référencement.
 *
 * @return void
 */
function urbizen_child_description_tarifs() {
	if ( ! urbizen_child_est_page_tarifs() ) {
		return;
	}

	if ( urbizen_child_seo_gere_ailleurs() ) {
		return;
	}

	printf(
		'<meta name="description" content="%s" />' . "\n",
		esc_attr__(
			'Découvrez les tarifs Urbizen pour votre déclaration préalable, permis de construire et conception de plans. Dossiers préparés à distance partout en France.',
			'urbizen-child'
		)
	);
}
add_action( 'wp_head', 'urbizen_child_description_tarifs', 1 );

/**
 * La page affichée utilise-t-elle le gabarit de l'accueil Urbizen ?
 *
 * Deux gabarits rendent cette page, pour une raison tenant à la hiérarchie de
 * WordPress : pour la page d'accueil du site, `front-page` est consulté AVANT
 * le gabarit personnalisé de la page, qui n'est donc jamais atteint. Le thème
 * enfant fournit les deux fichiers, copies strictes l'une de l'autre :
 *
 *   - `templates/front-page.html`          → l'accueil du site ;
 *   - `templates/page-accueil-urbizen.html` → toute autre page qui l'assigne,
 *     dont la page brouillon de recette et les prévisualisations.
 *
 * La détection couvre les deux cas. `is_front_page()` est exactement la
 * condition d'emploi de `front-page.html` : dès lors que le fichier existe
 * dans le thème enfant, il est en tête de la hiérarchie de l'accueil.
 *
 * @return bool
 */
function urbizen_child_est_accueil_urbizen() {
	// Accueil du site : rendu par templates/front-page.html.
	if ( is_front_page() ) {
		return true;
	}

	if ( ! is_singular() ) {
		return false;
	}

	$id = get_queried_object_id();

	if ( ! $id ) {
		return false;
	}

	return URBIZEN_CHILD_TEMPLATE_ACCUEIL === get_page_template_slug( $id );
}

/**
 * Charge la charte, les polices, les styles et le script de l'accueil.
 *
 * Chargement strictement conditionnel : une page qui n'utilise pas le gabarit
 * ne reçoit aucune de ces ressources. Les polices sont auto-hébergées — aucun
 * appel à fonts.googleapis.com ni à fonts.gstatic.com.
 *
 * @return void
 */
function urbizen_child_enqueue_accueil() {
	if ( ! urbizen_child_est_page_urbizen() ) {
		return;
	}

	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();

	$ressources = array(
		'urbizen-fonts'    => array( '/assets/css/urbizen-fonts.css', array() ),
		'urbizen-tokens'   => array( '/assets/css/urbizen-tokens.css', array( 'urbizen-fonts' ) ),
		'urbizen-homepage' => array( '/assets/css/urbizen-homepage.css', array( 'urbizen-tokens', 'urbizen-child-style' ) ),
		// Corrige les interférences WordPress sur l'en-tête. Chargée en dernier,
		// après la feuille générée depuis la maquette, qu'elle ne modifie pas.
		'urbizen-entete'   => array( '/assets/css/urbizen-accueil-entete.css', array( 'urbizen-homepage' ) ),
	);

	foreach ( $ressources as $handle => $definition ) {
		list( $chemin, $deps ) = $definition;

		if ( ! file_exists( $dir . $chemin ) ) {
			continue;
		}

		wp_enqueue_style( $handle, $uri . $chemin, $deps, (string) filemtime( $dir . $chemin ) );
	}

	// Feuille des pages internes (hero de page, tableaux, frise) — scopée
	// `.urbizen-page`, classe absente de l'accueil : aucune incidence dessus.
	if ( ! urbizen_child_est_accueil_urbizen() ) {
		$pages_css = '/assets/css/urbizen-pages.css';

		if ( file_exists( $dir . $pages_css ) ) {
			wp_enqueue_style( 'urbizen-pages', $uri . $pages_css, array( 'urbizen-homepage' ), (string) filemtime( $dir . $pages_css ) );
		}
	}

	// Page commerciale « Conception » : feuille dédiée (galerie de rendus,
	// hero illustré) et script de protection des visuels. Chargés uniquement
	// sur cette page ; scopés `.urbizen-page-conception`.
	if ( urbizen_child_est_page_conception() ) {
		$conception_css = '/assets/css/urbizen-conception.css';

		if ( file_exists( $dir . $conception_css ) ) {
			// Handle distinct du plugin : ConceptionAssets enregistre déjà
			// « urbizen-conception » (CSS du formulaire). Réutiliser ce handle
			// ferait écraser silencieusement notre feuille de page.
			wp_enqueue_style( 'urbizen-conception-page', $uri . $conception_css, array( 'urbizen-pages' ), (string) filemtime( $dir . $conception_css ) );
		}

		$conception_js = '/assets/js/urbizen-conception-gallery.js';

		if ( file_exists( $dir . $conception_js ) ) {
			wp_enqueue_script( 'urbizen-conception-gallery', $uri . $conception_js, array(), (string) filemtime( $dir . $conception_js ), true );
		}
	}

	// Formulaires DP et PC : la coque WordPress garde l'en-tête et le pied de
	// page du site ; l'iframe, de même origine, est redimensionnée à son contenu.
	if ( urbizen_child_est_page_formulaire_autorisation() ) {
		$form_page_css = '/assets/css/urbizen-form-page.css';

		if ( file_exists( $dir . $form_page_css ) ) {
			wp_enqueue_style( 'urbizen-form-page', $uri . $form_page_css, array( 'urbizen-pages' ), (string) filemtime( $dir . $form_page_css ) );
		}

		$form_page_js = '/assets/js/urbizen-form-page.js';

		if ( file_exists( $dir . $form_page_js ) ) {
			wp_enqueue_script( 'urbizen-form-page', $uri . $form_page_js, array(), (string) filemtime( $dir . $form_page_js ), true );

			// La configuration de soumission est émise **côté serveur**, sur la
			// page parente, et transmise à l'iframe par `postMessage`. Elle ne
			// passe jamais par l'URL du cadre : un nonce dans une query string
			// se retrouverait dans l'historique, les journaux d'accès et tout
			// en-tête `Referer` sortant.
			$config = urbizen_child_configuration_formulaire();

			if ( array() !== $config ) {
				wp_add_inline_script(
					'urbizen-form-page',
					'window.urbizenFormConfig = ' . wp_json_encode( $config ) . ';',
					'before'
				);
			}
		}
	}

	// Le moteur de qualification d'urbanisme précède le script d'accueil, qui le
	// consomme. Il est autonome et testable hors navigateur : les seuils
	// réglementaires n'existent qu'à cet endroit, jamais dans l'orchestration.
	$moteur = '/assets/js/urbizen-qualification.js';

	if ( file_exists( $dir . $moteur ) ) {
		wp_enqueue_script( 'urbizen-qualification', $uri . $moteur, array(), (string) filemtime( $dir . $moteur ), true );
	}

	$script = '/assets/js/urbizen-homepage.js';

	if ( file_exists( $dir . $script ) ) {
		wp_enqueue_script( 'urbizen-homepage', $uri . $script, array( 'urbizen-qualification' ), (string) filemtime( $dir . $script ), true );
	}
}
add_action( 'wp_enqueue_scripts', 'urbizen_child_enqueue_accueil', 30 );

/**
 * Ajoute une classe au corps de page sur le gabarit de l'accueil.
 *
 * La maquette porte son quadrillage sur `<body class="u-grid-bg">`. Un gabarit
 * de bloc ne peut pas écrire cet attribut : on l'ajoute ici.
 *
 * @param array<int, string> $classes Classes existantes.
 * @return array<int, string>
 */
function urbizen_child_body_class( $classes ) {
	if ( urbizen_child_est_page_urbizen() ) {
		$classes[] = 'u-grid-bg';
	}

	return $classes;
}
add_filter( 'body_class', 'urbizen_child_body_class' );

/* -------------------------------------------------------------------------
 * CHATWAY — CHARGEMENT CONDITIONNÉ AU CONSENTEMENT
 *
 * POURQUOI UN CONDITIONNEMENT MAISON
 *
 * Complianz ne connaît pas Chatway : ni intégration, ni entrée dans sa base de
 * services. Son bloqueur ne peut donc pas l'arrêter. On le retient ici, sans
 * modifier le greffon Chatway lui-même — qui serait écrasé à sa prochaine mise
 * à jour.
 *
 * POURQUOI LA CATÉGORIE « MARKETING »
 *
 * Décidée par le comportement observé, non par la nature du service. Un chat en
 * direct pourrait passer pour fonctionnel ; l'analyse de `widget.js` montre
 * autre chose : un identifiant de visiteur persistant (`ch_visitor_details_*`,
 * `ch_session_info_*`), des points de terminaison nommés `/pixel/`, un appel à
 * `cdn-cgi/trace` qui récupère l'adresse IP et le pays, et un stockage sur
 * `s3.us-west-2` — hors EEE. Détail dans `docs/AUDIT_CONSENTEMENT.md`.
 *
 * CE QUE CELA NE COUVRE PAS
 *
 * Le retrait du consentement en cours de page ne décharge pas un script déjà
 * exécuté : Complianz recharge la page, et c'est ce rechargement qui rétablit
 * l'état. Le contrôle porte donc sur la page suivante, pas sur l'instant du
 * clic.
 * ------------------------------------------------------------------------- */

/**
 * Marque les scripts Chatway comme retenus, selon le balisage de Complianz.
 *
 * POURQUOI PAS DE CONDITION CÔTÉ SERVEUR
 *
 * Une décision prise en PHP est figée dans le cache de page : LiteSpeed sert la
 * même réponse à un visiteur consentant et à un visiteur qui a refusé. Mesuré —
 * `x-litespeed-cache: hit` sur les deux. Un verrou PHP bloquerait donc Chatway
 * pour tout le monde, définitivement. La balise est neutralisée en toutes
 * circonstances, ce qui se cache sans risque, et c'est le navigateur qui décide.
 *
 * LE BALISAGE EXACT COMPTE
 *
 * Complianz ne lit PAS `src` : il cherche `data-cmplz-src`, et à défaut le
 * contenu en ligne.
 *
 *     const src = obj.getAttribute( 'data-cmplz-src' );
 *
 * Une balise laissée en `type="text/plain"` avec son `src` d'origine n'est donc
 * jamais réexécutée — pire, un bloc de nettoyage la retire du document. C'est
 * ce qui a fait échouer deux tentatives : le chat restait bloqué même après
 * acceptation. La source doit migrer vers `data-cmplz-src`.
 *
 * LE GREFFON CHATWAY EST DÉSACTIVÉ DEPUIS LE 12 AOÛT 2026
 *
 * Ce filtre ne s'applique donc plus à rien. Il est conservé comme filet : si
 * le greffon était réactivé, ses scripts seraient retenus d'emblée plutôt que
 * chargés sans consentement le temps qu'on s'en aperçoive.
 *
 * CATÉGORIE « MARKETING »
 *
 * Décidée par le comportement observé, non par la nature du service. Un chat en
 * direct pourrait passer pour fonctionnel ; `widget.js` porte un identifiant de
 * visiteur persistant (`ch_visitor_details_*`), appelle des points de
 * terminaison `/pixel/`, récupère l'IP via `cdn-cgi/trace` et stocke hors EEE —
 * tout cela avant la moindre interaction. Détail dans
 * `docs/AUDIT_CONSENTEMENT.md`. À revoir si ce suivi passif devient
 * désactivable.
 *
 * @param string $balise Balise complète.
 * @param string $handle Identifiant du script.
 * @return string
 */
function urbizen_child_neutralise_chatway( $balise, $handle ) {
	if ( false === stripos( $balise, 'chatway' ) ) {
		return $balise;
	}

	if ( false !== stripos( $balise, 'data-cmplz-src' ) ) {
		return $balise;
	}

	$balise = preg_replace( '/\ssrc=/i', ' data-cmplz-src=', $balise, 1 );

	return str_replace(
		'<script ',
		'<script type="text/plain" data-category="marketing" ',
		$balise
	);
}
add_filter( 'script_loader_tag', 'urbizen_child_neutralise_chatway', 20, 2 );

/**
 * Charge l'habillage Urbizen du bandeau de consentement.
 *
 * CHARGEMENT GLOBAL, ET C'EST NÉCESSAIRE
 *
 * Contrairement aux feuilles de la maquette, celle-ci n'est pas conditionnée au
 * gabarit : le bandeau s'affiche sur toutes les pages, y compris celles qui
 * n'utilisent pas les gabarits Urbizen. Elle est en revanche conditionnée à la
 * présence de Complianz — sans lui, elle n'aurait rien à peindre.
 *
 * `cmplz-general` est le seul style que Complianz met en file côté serveur ; la
 * feuille du bandeau, elle, est injectée par JavaScript et arrive donc toujours
 * après. La déclaration de dépendance ne sert pas à gagner la cascade — cela,
 * c'est la feuille elle-même qui s'en charge par sa spécificité — mais à ne
 * charger l'habillage que là où le gestionnaire est réellement présent.
 *
 * @return void
 */
function urbizen_child_enqueue_consentement() {
	if ( ! wp_style_is( 'cmplz-general', 'registered' ) && ! wp_style_is( 'cmplz-general', 'enqueued' ) ) {
		return;
	}

	$dir     = get_stylesheet_directory();
	$chemin  = '/assets/css/urbizen-consentement.css';

	if ( ! file_exists( $dir . $chemin ) ) {
		return;
	}

	wp_enqueue_style(
		'urbizen-consentement',
		get_stylesheet_directory_uri() . $chemin,
		array( 'cmplz-general' ),
		(string) filemtime( $dir . $chemin )
	);
}
add_action( 'wp_enqueue_scripts', 'urbizen_child_enqueue_consentement', 40 );

/* -------------------------------------------------------------------------
 * ARCHIVES D'AUTEUR — DÉSACTIVÉES
 *
 * CE QUE L'AUDIT DU 13 AOÛT 2026 A TROUVÉ
 *
 * `/author/contact-urbizengmail-com/` répondait 200, était indexable, et
 * portait l'adresse de courriel de la propriétaire dans son `<title>` :
 * WordPress construit le slug d'auteur à partir de `user_nicename`, lui-même
 * dérivé de l'identifiant de connexion, qui est l'adresse. La page était donc
 * éligible à l'affichage dans les résultats de recherche, adresse comprise, et
 * offerte aux moissonneurs.
 *
 * POURQUOI PAS SEULEMENT AIOSEO
 *
 * AIOSEO 5.0.0.1 n'offre aucune option de désactivation. Son réglage
 * `searchAppearance.archives.author.show` fait deux choses, vérifiées dans son
 * code : il exclut l'archive du plan de site (`Sitemap/Content.php`,
 * `Sitemap/Root.php`) et lui applique `noindex` (`Meta/Robots.php`, branche
 * `is_author()`). L'URL, elle, continue de répondre 200 et d'afficher la page.
 *
 * `noindex` est une demande faite aux moteurs. Elle ne retire pas la page du
 * web : un moissonneur d'adresses ne lit pas `robots`. Pour une donnée
 * personnelle, la seule mesure qui vaut est que la page n'existe plus.
 *
 * Les deux couches sont donc posées : ce filtre supprime l'URL, le réglage
 * AIOSEO reste en second rideau si ce filtre disparaissait un jour.
 *
 * CE QUE CELA N'EMPÊCHE PAS
 *
 * Rien du côté rédactionnel. `the_author()`, `get_the_author_meta()` et le
 * `Person` des données structurées d'un article continuent de fonctionner : un
 * futur blog affichera son autrice normalement. Seule disparaît la page
 * d'archive listant ses publications — sans valeur pour un site mono-auteur,
 * puisqu'elle duplique la liste des articles.
 *
 * PAS DE REDIRECTION
 *
 * Décision de la propriétaire, cohérente avec celle prise pour les anciens
 * slugs légaux : le trafic est trop faible pour qu'une redirection se
 * justifie, et rediriger une page qui n'aurait jamais dû exister reviendrait à
 * lui reconnaître une légitimité. Elle répond 404.
 * ------------------------------------------------------------------------- */

/**
 * Retire les archives d'auteur de la hiérarchie de requêtes publiques.
 *
 * Intervient sur `parse_query` : la requête est corrigée avant d'être exécutée,
 * plus tôt que `template_redirect`, qui ne pourrait plus qu'habiller après coup
 * une page déjà résolue.
 *
 * POURQUOI `set_404()` ET PAS `is_404 = true`
 *
 * Première version mesurée en production le 13 août 2026 : le gabarit 404
 * s'affichait — `body.error404`, « Page non trouvée », `robots: noindex` — mais
 * la réponse partait en **200**. Un *soft 404*, c'est-à-dire le seul cas que
 * Google traite plus mal qu'un 404 franc.
 *
 * La cause tient à l'ordre d'exécution. Poser `is_404` à la main ne change que
 * le drapeau ; c'est `WP::handle_404()` qui envoie l'en-tête, et il ne le fait
 * que si la requête ne rapporte aucun article. Or vider les variables d'auteur
 * transforme la requête en requête générique, qui rapporte les articles
 * récents : `handle_404()` conclut donc qu'il n'y a pas lieu de renvoyer 404.
 *
 * `set_404()` pose les drapeaux dans les règles, et `status_header( 404 )`
 * envoie l'en-tête sans dépendre de ce que la requête a ramené.
 * `nocache_headers()` évite qu'un 404 soit mis en cache comme une page valide.
 *
 * Le flux d'administration et les requêtes internes ne sont pas touchés : la
 * garde `is_admin()` et le test de requête principale y veillent.
 *
 * @param WP_Query $requete Requête en cours de résolution.
 * @return void
 */
function urbizen_child_desactive_archives_auteur( $requete ) {
	if ( is_admin() || ! $requete->is_main_query() ) {
		return;
	}

	if ( ! $requete->is_author() ) {
		return;
	}

	$requete->set( 'author', '' );
	$requete->set( 'author_name', '' );
	$requete->set_404();

	status_header( 404 );
	nocache_headers();
}
add_action( 'parse_query', 'urbizen_child_desactive_archives_auteur' );

/* -------------------------------------------------------------------------
 * ARCHIVES DE TAXONOMIE VIDES — HORS INDEX
 *
 * AIOSEO expose un réglage nommé `noIndexEmptyCat`, actif par défaut, qui
 * promet exactement cela. Il ne fait rien : dans la version 5.0.0.1, l'option
 * n'existe que comme définition dans `Common/Options/Options.php` et n'est lue
 * nulle part ailleurs — un reste des versions 3. Mesuré en production le
 * 13 août 2026 : catégorie à zéro article, `robots: max-image-preview:large`,
 * donc parfaitement indexable.
 *
 * Poser le `noindex` sur le terme lui-même n'est pas possible non plus :
 * AIOSEO Free n'a ni modèle ni table pour les termes, c'est une fonction
 * Premium.
 *
 * D'où cette règle, qui vise l'état plutôt que le terme : **une archive sans
 * article n'a rien à montrer**. Elle vaut pour la catégorie par défaut vide
 * d'aujourd'hui comme pour n'importe quelle rubrique créée demain et pas
 * encore alimentée — et elle s'efface d'elle-même dès le premier article
 * publié. Une règle qui viserait « la catégorie non-classe » deviendrait
 * fausse au premier renommage ; une règle qui viserait « toutes les
 * catégories » casserait le blog du lot G.
 * ------------------------------------------------------------------------- */

/**
 * Ajoute `noindex` aux archives de taxonomie ne contenant aucun contenu.
 *
 * S'accroche à `aioseo_robots_meta` et non à `wp_robots` : AIOSEO retire la
 * balise du cœur et émet la sienne, un filtre sur `wp_robots` n'aurait donc
 * aucun effet visible.
 *
 * `follow` est conservé : la page ne doit pas être indexée, mais rien ne
 * justifie d'empêcher le suivi des liens qu'elle porte.
 *
 * @param array<string, string> $attributs Directives calculées par AIOSEO.
 * @return array<string, string>
 */
function urbizen_child_noindex_archives_vides( $attributs ) {
	if ( is_admin() || ! ( is_category() || is_tag() || is_tax() ) ) {
		return $attributs;
	}

	$terme = get_queried_object();

	if ( ! ( $terme instanceof WP_Term ) || (int) $terme->count > 0 ) {
		return $attributs;
	}

	$attributs['noindex'] = 'noindex';

	return $attributs;
}
add_filter( 'aioseo_robots_meta', 'urbizen_child_noindex_archives_vides', 20 );

/* -------------------------------------------------------------------------
 * DONNÉES STRUCTURÉES — CORRECTIONS DU GRAPHE AIOSEO
 *
 * AIOSEO produit un graphe correct dans ses grandes lignes : un seul émetteur,
 * aucun doublon, des `@id` qui se répondent. Trois choses lui manquent ou lui
 * échappent, et se corrigent ici plutôt que par un réglage — parce qu'aucun
 * réglage ne les expose.
 *
 * 1 · LE NŒUD `Person` DES ARTICLES POINTE VERS UNE PAGE 404
 *
 * `app/Common/Schema/Graphs/WebPage/PersonAuthor.php` construit l'auteur avec
 * `get_author_posts_url()`, soit `/author/anais-bacarisse/`. Or le thème sert
 * cette adresse en 404 depuis le lot A, délibérément : elle exposait l'adresse
 * de courriel de la propriétaire dans son `<title>`.
 *
 * Aucun article n'étant publié, le nœud n'apparaît nulle part aujourd'hui. Il
 * reviendra au premier article du blog, et personne ne s'en apercevrait. La
 * correction est donc posée maintenant, pour être en place le jour venu.
 *
 * Le nom est conservé, l'`url` retirée, et l'`@id` remplacé par un identifiant
 * stable qui n'est pas une adresse de page. Un `Person` sans `url` reste
 * valide : schema.org ne l'exige pas, et Google non plus.
 *
 * 2 · L'ORGANISATION N'A NI COURRIEL NI ADRESSE
 *
 * AIOSEO Free n'expose aucun champ d'adresse — les clés disponibles sont
 * `websiteName`, `siteRepresents`, `organizationName`, `organizationDescription`,
 * `organizationLogo`, `personName`, `personLogo`, `phone`, `email`,
 * `foundingDate` et `numberOfEmployees`. C'est tout. L'adresse passe donc par
 * ce filtre.
 *
 * Elle est lue dans `urbizen_child_donnees_legales()`, la source unique déjà
 * employée par les pages légales. La recopier ici la ferait diverger de ce que
 * les mentions légales publient — exactement ce que cette source existe pour
 * empêcher.
 *
 * `PostalAddress` sur une `Organization`, et non `LocalBusiness` : une
 * organisation peut avoir un siège sans recevoir de clientèle. Urbizen prépare
 * des dossiers à distance, partout en France ; déclarer un établissement
 * recevant du public serait une affirmation fausse. Aucune coordonnée
 * géographique, aucun horaire d'ouverture, pour la même raison.
 *
 * LE FIL D'ARIANE N'EST PAS TRAITÉ ICI
 *
 * Il annonçait « Home » sur un site francophone. La correction n'est pas un
 * filtre mais un réglage : `breadcrumbs.homepageLabel`, posé par
 * `scripts/seo-lot-e.php`.
 *
 * Le filtre `aioseo_schema_breadcrumbs_home`, qu'AIOSEO expose pourtant à
 * l'endroit exact où la chaîne est écrite, **ne produit aucun effet** : le
 * graphe ne passe pas par là. `Schema/Graphs/BreadcrumbList.php` ligne 23 lit
 * `aioseo()->breadcrumbs->frontend->getBreadcrumbs()`, c'est-à-dire le même
 * fil d'Ariane que celui affiché, dont le libellé d'accueil vient de l'option.
 * Essayé et mesuré : le filtre laissait « Home » en place.
 *
 * CE QUI N'EST DÉLIBÉRÉMENT PAS FAIT
 *
 * Aucun `Service`, aucune `Offer`, aucun `FAQPage`. Baliser un prix
 * réintroduirait le mécanisme du P0 du lot A — un montant périmé resté des mois
 * dans les résultats. Le `FAQPage` n'apporterait rien : depuis août 2023 Google
 * réserve ces enrichissements aux sites d'autorité reconnue.
 *
 * L'`@id` du premier maillon du fil d'Ariane, `https://urbizen.fr#listItem`
 * sans barre finale, n'est pas retouché : il est valide, cohérent avec les
 * autres références, et un filtre pour une barre oblique serait du bruit.
 *
 * `foundingDate` reste vide : aucune date de fondation n'est confirmée, et en
 * déduire une serait inventer une donnée publiée.
 * ------------------------------------------------------------------------- */

/**
 * Identifiant stable de l'autrice dans le graphe.
 *
 * Ce n'est pas une URL de page : c'est un identifiant de nœud. Il ne doit
 * jamais pointer vers `/author/…`, qui répond 404 par décision.
 */
const URBIZEN_CHILD_ID_AUTRICE = 'https://urbizen.fr/#anais-bacarisse';

/**
 * Remplace toute référence à l'archive d'auteur par l'identifiant stable.
 *
 * Traite le nœud en profondeur plutôt que d'énumérer les propriétés connues.
 * La première version ne corrigeait que `author` : le banc du futur article a
 * relevé que `WebPage` porte aussi un `creator`, construit de la même façon.
 * Énumérer les noms de propriétés, c'est parier sur ceux qu'AIOSEO emploie
 * aujourd'hui ; viser la valeur — une URL d'archive d'auteur — vaut pour ceux
 * qu'il emploiera demain.
 *
 * @param mixed $valeur Fragment de graphe.
 * @return mixed
 */
function urbizen_child_remplace_archive_autrice( $valeur ) {
	if ( is_string( $valeur ) ) {
		return false !== strpos( $valeur, '/author/' ) ? URBIZEN_CHILD_ID_AUTRICE : $valeur;
	}

	if ( ! is_array( $valeur ) ) {
		return $valeur;
	}

	foreach ( $valeur as $cle => $sous ) {
		$valeur[ $cle ] = urbizen_child_remplace_archive_autrice( $sous );
	}

	return $valeur;
}

/**
 * Corrige le graphe de données structurées émis par AIOSEO.
 *
 * @param array<int, array<string, mixed>> $graphe Nœuds du graphe.
 * @return array<int, array<string, mixed>>
 */
function urbizen_child_corrige_schema( $graphe ) {
	if ( ! is_array( $graphe ) ) {
		return $graphe;
	}

	$legales = function_exists( 'urbizen_child_donnees_legales' ) ? urbizen_child_donnees_legales() : array();

	foreach ( $graphe as &$noeud ) {
		if ( ! is_array( $noeud ) || empty( $noeud['@type'] ) ) {
			continue;
		}

		$types = (array) $noeud['@type'];

		// --- Person : ni url, ni @id vers l'archive d'auteur ---------------
		if ( in_array( 'Person', $types, true ) ) {
			// L'`@id` est déjà ramené sur l'identifiant stable par le
			// remplacement en profondeur. Reste `url`, qui doit disparaître
			// plutôt que d'être réécrite : un `Person` sans `url` est valide,
			// et il n'existe aucune page d'autrice à désigner.
			unset( $noeud['url'] );
		}

		// --- Toute référence à l'archive d'auteur, où qu'elle soit ---------
		//
		// `Person` n'est pas le seul nœud concerné : `WebPage` porte un `author`
		// ET un `creator`, tous deux construits par `get_author_posts_url()`.
		// Le premier correctif n'avait traité que `author`, et le banc du futur
		// article a relevé le `creator` resté en 404.
		$noeud = urbizen_child_remplace_archive_autrice( $noeud );

		// --- Organization : courriel et adresse postale --------------------
		if ( in_array( 'Organization', $types, true ) ) {
			if ( ! empty( $legales['email'] ) && empty( $noeud['email'] ) ) {
				$noeud['email'] = $legales['email'];
			}

			if ( ! empty( $legales['adresse'] ) && is_array( $legales['adresse'] ) && empty( $noeud['address'] ) ) {
				// La source donne l'adresse en lignes : rue, code postal et
				// ville, pays. On la découpe selon cette convention plutôt que
				// de dupliquer les valeurs.
				$lignes = array_values( $legales['adresse'] );
				$rue    = $lignes[0] ?? '';
				$ville  = $lignes[1] ?? '';
				$pays   = $lignes[2] ?? '';

				$code    = '';
				$localite = $ville;

				if ( preg_match( '/^\s*(\d{5})\s+(.+)$/u', $ville, $m ) ) {
					$code     = $m[1];
					$localite = $m[2];
				}

				$adresse = array( '@type' => 'PostalAddress' );

				if ( '' !== $rue ) {
					$adresse['streetAddress'] = $rue;
				}
				if ( '' !== $code ) {
					$adresse['postalCode'] = $code;
				}
				if ( '' !== $localite ) {
					$adresse['addressLocality'] = $localite;
				}
				if ( '' !== $pays ) {
					$adresse['addressCountry'] = $pays;
				}

				if ( count( $adresse ) > 1 ) {
					$noeud['address'] = $adresse;
				}
			}
		}
	}

	unset( $noeud );

	return $graphe;
}
add_filter( 'aioseo_schema_output', 'urbizen_child_corrige_schema' );
