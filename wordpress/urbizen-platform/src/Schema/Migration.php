<?php
/**
 * Contrat d'une migration — **en avant seulement**.
 *
 * Il n'y a pas de méthode `annuler()`, et c'est délibéré. Une migration qui
 * supprime une colonne ne peut pas la restaurer : lui donner une méthode
 * d'annulation aurait promis une réversibilité qui n'existe pas, et cette
 * promesse aurait fini par être crue un jour de panne.
 *
 * Le retour arrière repose sur quatre niveaux explicites, dans cet ordre :
 * rollback du code tant que le schéma reste compatible ; migration
 * compensatrice, elle-même en avant, lorsque l'opération inverse est sûre ;
 * procédure manuelle documentée dans la PR ; restauration de la sauvegarde en
 * dernier recours.
 *
 * D'où une règle qui ne souffre pas d'exception : **aucune suppression de
 * table ou de colonne dans la même PR que l'arrêt de son utilisation.**
 * Toujours deux PR — « le code n'écrit plus », puis « la colonne disparaît ».
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

/**
 * Une transformation de schéma.
 */
interface Migration {

	/**
	 * Identifiant immuable et ordonnant, par exemple « 0001_organisations ».
	 *
	 * Il fixe l'ordre d'application et sert de clé dans le registre. Le
	 * modifier après livraison ferait rejouer une migration déjà appliquée.
	 *
	 * @return string
	 */
	public function identifiant(): string;

	/**
	 * Capacités exigées du moteur : « innodb », « check », « utf8mb4 ».
	 *
	 * Tableau vide si la migration n'exige rien de particulier. Ces capacités
	 * sont éprouvées **au moment d'appliquer cette migration-là**, jamais au
	 * chargement du greffon.
	 *
	 * @return array<int, string>
	 */
	public function prerequis(): array;

	/**
	 * Applique.
	 *
	 * **Doit être idempotente** : une seconde exécution ne doit ni échouer ni
	 * modifier davantage l'état. Le DDL de MariaDB n'étant pas transactionnel,
	 * une reprise après incident rejouera cette méthode sur un état partiel.
	 *
	 * @param DatabaseGateway $db Passerelle.
	 * @return void
	 */
	public function appliquer( DatabaseGateway $db ): void;

	/**
	 * L'état attendu est-il atteint ?
	 *
	 * Interrogée **après** application, et fondée sur le schéma réel — jamais
	 * sur le registre. Une ligne de registre affirme qu'on a cru réussir ;
	 * seule la base dit ce qui existe.
	 *
	 * @param DatabaseGateway $db Passerelle.
	 * @return bool
	 */
	public function verifier( DatabaseGateway $db ): bool;
}
