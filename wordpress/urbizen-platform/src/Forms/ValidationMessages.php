<?php
/**
 * Présentateur des codes d'erreur de validation en messages publics (Lot 2, C2B).
 *
 * Le {@see Validator} produit des **codes** stables (`requis`, `trop_long`…) ;
 * la reprise ({@see \Urbizen\Platform\Http\SubmissionRecovery}) les conserve tels
 * quels, sans jamais construire de message avec la valeur fautive. Ce
 * présentateur les traduit, **côté serveur**, en phrases publiques maîtrisées.
 *
 * Il ne **duplique pas** les règles de validation : il ne fait qu'associer un
 * code connu à un message. Un code inconnu reçoit un message générique sûr —
 * jamais le code brut, jamais une valeur, jamais un détail technique.
 *
 * @package Urbizen\Platform\Forms
 */

namespace Urbizen\Platform\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Traduction des codes d'erreur en messages publics.
 */
final class ValidationMessages {

	/**
	 * Message public d'un code d'erreur de champ.
	 *
	 * @param string $code Code produit par le validateur.
	 * @return string
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'requis':
				return __( 'Ce champ est obligatoire.', 'urbizen-platform' );

			case 'trop_long':
				return __( 'Ce texte est trop long.', 'urbizen-platform' );

			case 'email_invalide':
				return __( 'Cette adresse électronique n’est pas valide.', 'urbizen-platform' );

			case 'nombre_invalide':
				// « entier » n'était vrai que tant que tous les nombres l'étaient.
				// Les mesures acceptent désormais la virgule, et le message doit
				// le dire — sinon la personne corrige ce qui n'est pas en cause.
				return __( 'Indiquez un nombre valide. La virgule et le point sont acceptés.', 'urbizen-platform' );

			case 'mesure_nulle':
				return __( 'Indiquez une mesure supérieure à zéro, ou laissez le champ vide.', 'urbizen-platform' );

			// L'adresse arrive de deux façons ; sans savoir laquelle, on ne peut
			// ni choisir ni deviner. Le message dit quoi faire, pas ce qui
			// manque techniquement.
			case 'adresse_mode_absent':
				return __( 'Indiquez l’adresse du terrain, en la recherchant ou en la saisissant manuellement.', 'urbizen-platform' );

			case 'adresse_mode_inconnu':
				return __( 'Le mode de saisie de l’adresse n’est pas reconnu. Reprenez la saisie de l’adresse.', 'urbizen-platform' );

			case 'coordonnee_orpheline':
				return __( 'La position du terrain est incomplète. Sélectionnez à nouveau l’adresse.', 'urbizen-platform' );

			case 'hors_bornes':
				return __( 'Cette valeur est en dehors des limites autorisées.', 'urbizen-platform' );

			case 'sous_le_minimum':
				return __( 'La valeur est inférieure au minimum autorisé.', 'urbizen-platform' );

			case 'au_dela_du_maximum':
				return __( 'La valeur dépasse le maximum autorisé.', 'urbizen-platform' );

			case 'hors_liste':
				return __( 'Cette valeur ne fait pas partie des choix proposés.', 'urbizen-platform' );

			case 'projet_inconnu':
				return __( 'Ce projet ne fait pas partie de ceux que nous pouvons traiter.', 'urbizen-platform' );

			case 'projet_identique_au_principal':
				return __( 'Ce projet est déjà votre projet principal. Choisissez-en un autre.', 'urbizen-platform' );

			case 'projet_en_double':
				return __( 'Ce projet figure deux fois. Chaque projet ne peut être ajouté qu’une seule fois.', 'urbizen-platform' );

			case 'projets_trop_nombreux':
				return __( 'Vous avez ajouté trop de projets pour un même dossier. Contactez-nous pour les répartir.', 'urbizen-platform' );

			case 'projet_malforme':
				return __( 'La liste des projets n’a pas pu être lue. Actualisez la page et réessayez.', 'urbizen-platform' );

			case 'hors_bornes':
				return __( 'La valeur est en dehors des limites autorisées.', 'urbizen-platform' );

			default:
				// Message générique sûr : jamais le code brut ni une valeur.
				return __( 'Cette information n’a pas pu être validée.', 'urbizen-platform' );
		}
	}

	/**
	 * Message public global, lorsqu'une erreur globale générique est présente.
	 *
	 * @param string $code Code global (chaîne vide s'il n'y en a pas).
	 * @return string
	 */
	public static function globale( string $code ): string {
		if ( '' === $code ) {
			return '';
		}

		return __( 'Certaines informations n’ont pas pu être validées. Vérifiez les champs signalés ci-dessous.', 'urbizen-platform' );
	}
}
