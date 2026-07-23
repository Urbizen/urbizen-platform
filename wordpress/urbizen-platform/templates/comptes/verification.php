<?php
/**
 * Page de confirmation, servie en GET.
 *
 * Elle **n'agit pas** : le jeton n'est ni comparé, ni consommé. Il est reposé
 * dans un champ caché, et c'est le POST qui tranche.
 *
 * **Aucune ressource externe** : ni police distante, ni image, ni script de
 * mesure. Le jeton figure dans l'URL de cette page ; toute requête sortante
 * l'emporterait dans son en-tête `Referer`.
 *
 * @package Urbizen\Platform
 *
 * @var int    $urbizen_compte Identifiant de compte.
 * @var string $urbizen_jeton  Jeton, reposé en champ caché.
 * @var string $urbizen_cible  Adresse que ce lien confirme.
 */

defined( 'ABSPATH' ) || exit;

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="referrer" content="no-referrer">
	<title><?php echo esc_html__( 'Confirmer votre adresse', 'urbizen-platform' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( URBIZEN_PLATFORM_URL . 'assets/css/urbizen-comptes.css' ); ?>">
</head>
<body class="urbizen-comptes-page">
	<main class="urbizen-comptes-carte">
		<h1><?php echo esc_html__( 'Confirmer votre adresse', 'urbizen-platform' ); ?></h1>

		<?php if ( '' !== $urbizen_cible ) : ?>
			<p><?php echo esc_html__( 'Vous êtes sur le point de confirmer l’adresse suivante :', 'urbizen-platform' ); ?><br>
			<strong><?php echo esc_html( $urbizen_cible ); ?></strong></p>
		<?php else : ?>
			<p><?php echo esc_html__( 'Vous êtes sur le point de confirmer votre adresse.', 'urbizen-platform' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( \Urbizen\Platform\Account\LienVerification::ACTION ); ?>">
			<input type="hidden" name="c" value="<?php echo esc_attr( (string) $urbizen_compte ); ?>">
			<input type="hidden" name="t" value="<?php echo esc_attr( $urbizen_jeton ); ?>">
			<?php wp_nonce_field( \Urbizen\Platform\Account\LienVerification::ACTION, '_urbizen_nonce' ); ?>
			<p><button type="submit"><?php echo esc_html__( 'Confirmer mon adresse', 'urbizen-platform' ); ?></button></p>
		</form>

		<p><?php echo esc_html__( 'Cette confirmation ne vous connecte pas.', 'urbizen-platform' ); ?></p>
	</main>
</body>
</html>
