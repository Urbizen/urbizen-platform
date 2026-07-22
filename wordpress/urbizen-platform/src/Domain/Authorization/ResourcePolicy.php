<?php
/**
 * Contrat d'une politique d'accès, spécialisée par type de ressource.
 *
 * Une politique par ressource, jamais un service unique contenant toutes les
 * règles : c'est ce qui permet de lire les droits d'un projet sans traverser
 * ceux d'une facture, et de les tester séparément.
 *
 * @package Urbizen\Platform\Domain\Authorization
 */

namespace Urbizen\Platform\Domain\Authorization;

use Urbizen\Platform\Domain\Identity\ActeurCourant;

/**
 * Politique d'accès à un type de ressource.
 */
interface ResourcePolicy {

	/**
	 * Nom pleinement qualifié de la classe de ressource couverte.
	 *
	 * @return string
	 */
	public function gere(): string;

	/**
	 * Décide.
	 *
	 * Une politique qui ne connaît pas l'action **refuse**. Elle ne lève pas
	 * d'exception : une exception non rattrapée dans une couche appelante
	 * finit par ouvrir ce qu'elle devait fermer.
	 *
	 * @param ActeurCourant $acteur    Acteur courant.
	 * @param string        $action    Action demandée, par exemple « projet.voir ».
	 * @param object        $ressource Ressource visée.
	 * @return Decision
	 */
	public function decider( ActeurCourant $acteur, string $action, object $ressource ): Decision;
}
