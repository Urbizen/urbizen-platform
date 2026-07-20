<?php
/**
 * Banc d'essai du type de contenu privé et du repository.
 *
 * Deux questions : la demande est-elle réellement inaccessible, et ce qui est
 * écrit est-il exactement ce que le validateur a nettoyé — rien de plus.
 *
 * Toutes les données sont fictives.
 */

require __DIR__ . '/bootstrap.php';

use Urbizen\Platform\Forms\FormRegistry;
use Urbizen\Platform\Forms\Pricing;
use Urbizen\Platform\Forms\Validator;
use Urbizen\Platform\Submissions\SubmissionPostType;
use Urbizen\Platform\Submissions\SubmissionRepository;

SubmissionPostType::register_post_type();
$args = wpd_post_type_args( SubmissionPostType::POST_TYPE );

// ================================================= TYPE DE CONTENU ==========
check( 'le type de contenu est enregistré', array() !== $args );
check( 'identifiant urbizen_demande', 'urbizen_demande' === SubmissionPostType::POST_TYPE );

$interdits = array(
	'public'              => false,
	'publicly_queryable'  => false,
	'exclude_from_search' => true,
	'has_archive'         => false,
	'rewrite'             => false,
	'query_var'           => false,
	'show_in_rest'        => false,
	'show_in_nav_menus'   => false,
	'can_export'          => false,
);

foreach ( $interdits as $cle => $attendu ) {
	check( "[$cle] = " . var_export( $attendu, true ), $attendu === ( $args[ $cle ] ?? null ) );
}

check( 'aucun éditeur, aucun extrait : seul le titre', array( 'title' ) === ( $args['supports'] ?? null ) );
check( 'les capacités ne sont pas mappées automatiquement', false === ( $args['map_meta_cap'] ?? null ) );
check( 'le type de capacité est propre au dossier', 'urbizen_demande' === ( $args['capability_type'] ?? null ) );

$caps = $args['capabilities'] ?? array();

check( 'aucune capacité ne retombe sur celles des articles',
	array() === array_intersect( array_values( $caps ), array( 'edit_posts', 'edit_post', 'read', 'publish_posts', 'delete_posts' ) ) );
check( 'lecture et modification réservées à manage_options',
	'manage_options' === ( $caps['read_post'] ?? '' )
	&& 'manage_options' === ( $caps['edit_posts'] ?? '' )
	&& 'manage_options' === ( $caps['read_private_posts'] ?? '' )
	&& 'manage_options' === ( $caps['delete_post'] ?? '' ) );
check( 'personne ne crée une demande à la main', 'do_not_allow' === ( $caps['create_posts'] ?? '' ) );
check( 'personne ne publie une demande', 'do_not_allow' === ( $caps['publish_posts'] ?? '' ) );
check( 'les trois états métier sont déclarés',
	array( 'received', 'converted', 'closed' ) === SubmissionPostType::statuses() );

// ==================================================== REPOSITORY ============
wpd_reset();
SubmissionPostType::register_post_type();

$def = FormRegistry::get( 'conception' );

$entree = array(
	'nature'           => 'maison',
	'situation'        => 'terrain_nu',
	'a_terrain'        => 'non',
	'surface'          => '120',
	'chambres'         => '3',
	'pieces'           => array( 'bureau' ),
	'surfaces'         => array( 'sejour' => '35', 'chambre_1' => '14', 'salon_du_roi' => '99' ),
	'options_tarifees' => array( 'facades', 'masse' ),
	'options_sur_devis' => array( 'insertion3d' ),
	'pente'            => 'plat',            // branche inactive : a_terrain = non
	'nom'              => 'Camille Fictif',
	'email'            => 'camille@exemple.test',
	'tel'              => '0100000000',
	'rgpd'             => '1',
	'prix_total'       => '0',               // champ inconnu
	'total'            => '1',               // champ inconnu
);

$validation = Validator::validate( $def, $entree );

check( 'la soumission d’essai est valide', $validation['valid'] );

$creation = SubmissionRepository::create(
	$validation['clean'],
	$validation['pricing'],
	array( 'form_type' => 'conception', 'source_path' => '/conception-plans-sur-mesure/', 'now' => wpd_now() )
);

check( 'la demande est créée', ! empty( $creation['ok'] ) );
check( 'une référence est attribuée', 1 === preg_match( '/^URB-\d{4}-\d{4}$/', $creation['reference'] ) );

$id   = (int) $creation['id'];
$post = get_post( $id );

check( 'le contenu est privé', 'private' === $post->post_status );
check( 'le titre ne porte que la référence', $creation['reference'] === $post->post_title );
check( 'le contenu principal est vide', '' === $post->post_content );
check( 'l’extrait est vide', '' === $post->post_excerpt );
check( 'aucune donnée personnelle dans le titre',
	! str_contains( $post->post_title, 'Camille' ) && ! str_contains( $post->post_title, 'exemple.test' ) );
check( 'aucune donnée personnelle dans le slug',
	! str_contains( $post->post_name, 'camille' ) && ! str_contains( $post->post_name, '@' ) );

// --- métadonnées ---
$metas = array_keys( $GLOBALS['wpd_meta'][ $id ] );

check( 'les douze métadonnées obligatoires sont présentes',
	array() === array_diff( SubmissionRepository::REQUIRED_META, $metas ) );
check( 'aucune métadonnée inattendue', array() === array_diff( $metas, SubmissionRepository::REQUIRED_META ) );

$lu = SubmissionRepository::get( $id );

check( 'form_type = conception', 'conception' === $lu['form_type'] );
check( 'schema_version = 1.0', '1.0' === $lu['schema_version'] );
check( 'status initial = received', 'received' === $lu['status'] );
check( 'mail_status = not_started', 'not_started' === $lu['mail_status'] );
check( 'files_status = not_started', 'not_started' === $lu['files_status'] );
check( 'created_at et last_contact sont horodatés en UTC',
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lu['created_at_gmt'] )
	&& $lu['created_at_gmt'] === $lu['last_contact_at'] );
check( 'le consentement est horodaté', '' !== $lu['consent_at_gmt'] );
check( 'le chemin source est local', '/conception-plans-sur-mesure/' === $lu['source_path'] );

// --- payload : exactement ce que Validator a nettoyé ---
check( 'le payload est exactement les données nettoyées', $validation['clean'] == $lu['payload'] );
check( 'le champ inconnu prix_total est absent', ! array_key_exists( 'prix_total', $lu['payload'] ) );
check( 'le champ inconnu total est absent', ! array_key_exists( 'total', $lu['payload'] ) );
check( 'la branche inactive pente est absente', ! array_key_exists( 'pente', $lu['payload'] ) );
check( 'la surface arbitraire est écartée', ! array_key_exists( 'salon_du_roi', $lu['payload']['surfaces'] ) );
check( 'les surfaces attendues sont conservées', array( 'sejour' => 35, 'chambre_1' => 14 ) === $lu['payload']['surfaces'] );

$brut = wp_json_encode( $GLOBALS['wpd_meta'][ $id ] );

foreach ( array(
	'nonce'                 => 'urbizen_conception_nonce',
	'pot de miel'           => 'company_website',
	'jeton'                 => 'urbizen_token',
	'adresse IP'            => '203.0.113',
	'agent utilisateur'     => 'Mozilla',
	'champ inconnu'         => 'prix_total',
) as $libelle => $motif ) {
	check( "aucune trace de : $libelle", ! str_contains( (string) $brut, $motif ) );
}

// --- pricing : recalculé, jamais repris du navigateur ---
$p = $lu['pricing'];

check( 'la base stockée vaut 449 €', 449 === $p['base'] );
check( 'le total stocké vaut 747 €', 747 === $p['total'] );
check( 'le total est celui recalculé par Pricing', Pricing::compute( array( 'facades', 'masse' ) )['total'] === $p['total'] );
check( 'les options stockées sont façades et masse', array( 'facades', 'masse' ) === array_column( $p['options'], 'id' ) );
check( 'la prestation sur devis est stockée à part', array( 'insertion3d' ) === $p['sur_devis'] );
check( 'l’indicateur de devis est levé', true === $p['devis_requis'] );
check( 'le pricing ne porte que les cinq clés prévues',
	array( 'base', 'options', 'sur_devis', 'total', 'devis_requis' ) === array_keys( $p ) );

// --- références successives et collisions ---
$deux = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );
check( 'la deuxième demande reçoit la référence suivante',
	(int) substr( $deux['reference'], -4 ) === (int) substr( $creation['reference'], -4 ) + 1 );
check( 'les deux références diffèrent', $deux['reference'] !== $creation['reference'] );

// Une référence déjà prise ne doit jamais être réattribuée : on remet le
// compteur en arrière et on vérifie que la base a le dernier mot.
update_option( SubmissionRepository::SEQUENCE_OPTION, array( (int) gmdate( 'Y', wpd_now() ) => 0 ) );
$trois = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );

check( 'une collision de référence est contournée, pas écrasée',
	! in_array( $trois['reference'], array( $creation['reference'], $deux['reference'] ), true ) );
check( 'les trois demandes coexistent', 3 === count( $GLOBALS['wpd_posts'] ) );
check( 'la première demande est intacte', null !== get_post( $id ) );

// --- retour arrière sur échec de métadonnée ---
wpd_reset();
SubmissionPostType::register_post_type();

$GLOBALS['wpd_meta_fail'] = '_urbizen_payload';
$echec                    = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );

check( 'un échec de métadonnée fait échouer la création', empty( $echec['ok'] ) && 'persistence_failed' === $echec['code'] );
check( 'aucune demande partielle ne subsiste', array() === $GLOBALS['wpd_posts'] );
check( 'aucune métadonnée orpheline ne subsiste', array() === $GLOBALS['wpd_meta'] );
check( 'l’échec est journalisé', str_contains( journal(), 'supprimée' ) );
check( 'le journal ne contient aucune donnée personnelle',
	! str_contains( journal(), 'Camille' ) && ! str_contains( journal(), 'exemple.test' ) );

$GLOBALS['wpd_meta_fail'] = '';

// --- échec d'insertion ---
wpd_reset();
SubmissionPostType::register_post_type();
$GLOBALS['wpd_insert_fail'] = true;
$echec                      = SubmissionRepository::create( $validation['clean'], $validation['pricing'], array( 'now' => wpd_now() ) );

check( 'un échec d’insertion est signalé', empty( $echec['ok'] ) && 'persistence_failed' === $echec['code'] );
check( 'aucune référence n’est annoncée', '' === $echec['reference'] );
$GLOBALS['wpd_insert_fail'] = false;

verdict();
