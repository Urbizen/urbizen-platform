<?php
/**
 * La connexion à la base a été perdue pendant une section critique.
 *
 * Levée lorsque la passerelle est en mode « sans reconnexion » et qu'une
 * requête tombe parce que le serveur MySQL est parti. C'est le signal qu'une
 * écriture **n'a pas** pu être rejouée sur une nouvelle connexion — donc qu'elle
 * n'a pas eu lieu — et que l'appelant doit échouer de façon restrictive plutôt
 * que de poursuivre sans le verrou qu'il croyait tenir.
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
