<?php
/**
 * Port SQL.
 *
 * Le schéma ne connaît pas `$wpdb`. Il pose des requêtes à travers ce port, ce
 * qui rend possible la seule preuve qui compte pour E1 : brancher une doublure
 * qui **lève à tout appel**, et montrer qu'avec un catalogue vide elle n'est
 * jamais sollicitée.
 *
 * **Aucune implémentation ne doit exécuter la moindre requête dans son
 * constructeur.** Construire n'est pas interroger.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

/**
 * Accès à la base.
 */
interface DatabaseGateway {

	/**
	 * Préfixe des tables, calculé, jamais écrit en dur.
	 *
	 * @return string
	 */
	public function prefixe(): string;

	/**
	 * Exécute une instruction sans résultat attendu.
	 *
	 * @param string             $sql        Instruction, avec substituants `%s`, `%d`.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return bool
	 */
	public function executer( string $sql, array $parametres = array() ): bool;

	/**
	 * Première valeur de la première ligne.
	 *
	 * @param string             $sql        Requête.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return string|null
	 */
	public function valeur( string $sql, array $parametres = array() ): ?string;

	/**
	 * Toutes les lignes, en tableaux associatifs.
	 *
	 * @param string             $sql        Requête.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return array<int, array<string, mixed>>
	 */
	public function lignes( string $sql, array $parametres = array() ): array;

	/**
	 * Exécute une instruction et rend le **nombre exact de lignes touchées**.
	 *
	 * C'est la primitive du compare-et-échange : un `UPDATE ... WHERE
	 * ancienne_valeur = %s` qui rend `1` prouve que ce processus — et lui seul —
	 * a remplacé la valeur qu'il avait lue. Un booléen ne le dirait pas : une
	 * mise à jour qui ne touche aucune ligne « réussit » elle aussi.
	 *
	 * @param string             $sql        Instruction.
	 * @param array<int, scalar> $parametres Paramètres.
	 * @return int Lignes touchées, ou `-1` en cas d'erreur.
	 */
	public function lignes_affectees( string $sql, array $parametres = array() ): int;

	/**
	 * Cette table existe-t-elle ?
	 *
	 * @param string $nom Nom complet, préfixe compris.
	 * @return bool
	 */
	public function table_existe( string $nom ): bool;

	/**
	 * Dernière erreur, chaîne vide s'il n'y en a pas.
	 *
	 * @return string
	 */
	public function derniere_erreur(): string;
}
