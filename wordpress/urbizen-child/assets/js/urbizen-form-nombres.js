/**
 * Nombres écrits par une personne, côté navigateur.
 *
 * En France, « huit mètres et demi » s'écrit `8,5`. Un `input type="number"`
 * refuse cette écriture — il rend une valeur vide, sans rien dire. Les mesures
 * sont donc des champs texte à clavier décimal, et la forme est contrôlée ici.
 *
 * **Ce module n'est pas l'autorité.** Il rend la saisie possible et signale une
 * erreur avant l'envoi ; le serveur revalide tout, et c'est lui qui tranche.
 * Les deux appliquent délibérément les mêmes règles — même liste d'acceptations,
 * mêmes refus — de sorte qu'une saisie acceptée ici ne soit jamais refusée
 * là-bas, ce qui serait la pire des expériences.
 *
 * Ce qui est refusé plutôt que deviné : deux séparateurs, une virgule mêlée à
 * un point, la notation scientifique, un point final sans décimale, du texte.
 * `parseFloat("8,5")` rend **8** en silence : il n'est jamais employé sur une
 * chaîne non normalisée, et c'est tout l'objet de ce fichier.
 */
(function (global) {
  "use strict";

  /** États rendus par l'analyse, alignés sur ceux du serveur. */
  var ABSENT = "absent";
  var VALIDE = "valide";
  var FORMAT = "format";
  var BORNE = "borne";

  /** Décimales conservées, comme côté serveur. */
  var DECIMALES = 2;

  var MESSAGES = {
    format: "Indiquez un nombre valide. La virgule et le point sont acceptés.",
    borne: "La valeur est en dehors des limites autorisées.",
    mesure_nulle: "Indiquez une mesure supérieure à zéro, ou laissez le champ vide."
  };

  /** Retire les espaces, insécables compris — ceux des copier-coller. */
  function nettoyer(brut) {
    if ("string" !== typeof brut) return "";

    return brut.replace(/[\s  ]/g, "");
  }

  /**
   * Analyse une saisie et rend un verdict explicite.
   *
   * @param {string} brut
   * @param {Object} bornes {min, max, strict}
   * @returns {{etat: string, valeur: (number|null), raison: string}}
   */
  function analyser(brut, bornes) {
    bornes = bornes || {};

    var chaine = nettoyer(brut);

    if ("" === chaine) return { etat: ABSENT, valeur: null, raison: "" };

    var virgules = (chaine.match(/,/g) || []).length;
    var points = (chaine.match(/\./g) || []).length;

    // Deux séparateurs, ou les deux conventions mêlées : aucune lecture n'est
    // évidente, et choisir à la place de la personne serait présomptueux.
    if (virgules > 1 || points > 1 || (virgules > 0 && points > 0)) {
      return { etat: FORMAT, valeur: null, raison: "nombre_illisible" };
    }

    var normalise = chaine.replace(",", ".");

    // Motif ancré et complet : ni notation scientifique, ni point orphelin, ni
    // caractère résiduel.
    if (!/^[+-]?(\d+(\.\d+)?|\.\d+)$/.test(normalise)) {
      return { etat: FORMAT, valeur: null, raison: "nombre_illisible" };
    }

    var valeur = Number(normalise);

    if (!isFinite(valeur)) return { etat: FORMAT, valeur: null, raison: "nombre_non_fini" };

    valeur = Math.round(valeur * 100) / 100;

    if (bornes.strict && valeur <= 0) {
      return { etat: BORNE, valeur: null, raison: "mesure_nulle" };
    }

    if (null != bornes.min && valeur < bornes.min) {
      return { etat: BORNE, valeur: null, raison: "sous_borne" };
    }

    if (null != bornes.max && valeur > bornes.max) {
      return { etat: BORNE, valeur: null, raison: "au_dessus_borne" };
    }

    return { etat: VALIDE, valeur: valeur, raison: "" };
  }

  /**
   * Forme canonique : point décimal, sans zéro inutile.
   *
   * `34.00` s'écrit `34`. Une précision affichée qui n'existe pas est un
   * mensonge, fût-il typographique.
   */
  function canonique(valeur) {
    return String(Math.round(valeur * 100) / 100);
  }

  /** Écriture française, pour ce que la personne relit. */
  function afficher(valeur) {
    return canonique(valeur).replace(".", ",");
  }

  /** Bornes déclarées sur le contrôle, telles que le serveur les connaît. */
  function bornesDe(controle) {
    var lire = function (attr) {
      var v = controle.getAttribute(attr);
      return null === v || "" === v ? null : Number(v);
    };

    return {
      min: lire("data-min"),
      max: lire("data-max"),
      // Une mesure renseignée vaut plus que zéro. Le serveur porte la même
      // règle sous le nom `strict_positif`.
      strict: controle.hasAttribute("data-mesure")
    };
  }

  /**
   * Attache le contrôle de forme à toutes les mesures d'un formulaire.
   *
   * L'erreur est signalée **à la sortie du champ**, pas à chaque frappe : une
   * personne qui tape « 8, » n'a pas encore fait d'erreur, et le lui dire
   * pendant qu'elle écrit est une façon sûre de la braquer.
   */
  function surveiller(form) {
    Array.prototype.forEach.call(form.querySelectorAll("[data-mesure]"), function (controle) {
      var messageId = (controle.id || controle.name) + "-erreur";
      var zone = form.querySelector('[data-erreur-pour="' + controle.name + '"]');

      if (zone && !zone.id) zone.id = messageId;

      var effacer = function () {
        controle.removeAttribute("aria-invalid");
        controle.classList.remove("is-error");
        if (zone) {
          zone.textContent = "";
          zone.hidden = true;
        }
      };

      controle.addEventListener("input", effacer);

      controle.addEventListener("blur", function () {
        var issue = analyser(controle.value, bornesDe(controle));

        if (ABSENT === issue.etat || VALIDE === issue.etat) {
          effacer();
          return;
        }

        controle.setAttribute("aria-invalid", "true");
        controle.classList.add("is-error");

        if (!zone) return;

        // Le message est relié au champ : un lecteur d'écran l'annonce en
        // arrivant dessus, et pas seulement au moment où il apparaît.
        controle.setAttribute("aria-describedby", zone.id);
        zone.textContent = MESSAGES[issue.raison] || MESSAGES[issue.etat] || MESSAGES.format;
        zone.hidden = false;
      });
    });
  }

  global.UrbizenNombres = {
    analyser: analyser,
    canonique: canonique,
    afficher: afficher,
    bornesDe: bornesDe,
    surveiller: surveiller,
    ABSENT: ABSENT,
    VALIDE: VALIDE,
    FORMAT: FORMAT,
    BORNE: BORNE,
    DECIMALES: DECIMALES
  };
})(window);
