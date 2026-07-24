<?php
/**
 * Page de destination UNIQUE de toutes les redirections 303.
 *
 * Elle n'affiche qu'un message, choisi dans une liste fermée de codes courts.
 * Elle ne porte **aucune adresse, aucun jeton, aucun motif technique** : ce qui
 * n'est pas affiché ne peut pas être lu par-dessus une épaule, ni rester dans
 * un historique de navigation, ni partir dans un en-tête `Referer`.
 *
 * **Aucune ressource externe** : ni police distante, ni image, ni script de
 * mesure. La feuille de style est servie localement.
 *
 * @package Urbizen\Platform
 *
 * @var string $urbizen_code Code public, déjà filtré par le contrôleur.
 */

defined( 'ABSPATH' ) || exit;

$urbizen_messages = array(
	'verifiez'     => __( 'Si un compte correspond à cette adresse, un message vient de lui être envoyé. Ouvrez-le pour confirmer votre adresse.', 'urbizen-platform' ),
	'confirme'     => __( 'Votre adresse est confirmée. Vous pouvez maintenant vous connecter.', 'urbizen-platform' ),
	'expire'       => __( 'Ce lien a expiré. Demandez-en un nouveau.', 'urbizen-platform' ),
	'invalide'     => __( 'Ce lien n’est plus valable. Demandez-en un nouveau.', 'urbizen-platform' ),
	'indisponible' => __( 'Le service est momentanément indisponible. Réessayez dans quelques instants.', 'urbizen-platform' ),
	'change'       => __( 'Si la demande a pu être enregistrée, un message vient d’être envoyé à la nouvelle adresse. L’adresse actuelle reste active jusqu’à confirmation.', 'urbizen-platform' ),
	'refus'        => __( 'La demande n’a pas pu être traitée.', 'urbizen-platform' ),
);

$urbizen_message = $urbizen_messages[ $urbizen_code ] ?? $urbizen_messages['refus'];

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html__( 'Votre compte', 'urbizen-platform' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( URBIZEN_PLATFORM_URL . 'assets/css/urbizen-comptes.css' ); ?>">
</head>
<body class="urbizen-comptes-page">
	<main class="urbizen-comptes-carte">
		<h1><?php echo esc_html__( 'Votre compte', 'urbizen-platform' ); ?></h1>
		<p><?php echo esc_html( $urbizen_message ); ?></p>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html__( 'Retour à l’accueil', 'urbizen-platform' ); ?></a></p>
	</main>
</body>
</html>
