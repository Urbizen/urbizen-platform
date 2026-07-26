<?php
/**
 * La connexion à la base a été perdue pendant une section critique.
 *
 * Levée lorsque la passerelle est en mode « sans reconnexion » et qu'une
 * requête tombe parce que le serveur MySQL est parti. La requête n'a pas pu être
 * rejouée sur une nouvelle connexion. Le résultat de la tentative initiale peut
 * être inconnu ; l'appelant doit donc échouer de façon restrictive et permettre
 * une récupération ultérieure.
 *
 * @package Urbizen\Platform\Schema
 */

namespace Urbizen\Platform\Schema;

use RuntimeException;

/**
 * Perte de connexion en section non reconnectable.
 */
final class ConnexionPerdue extends RuntimeException {
}
