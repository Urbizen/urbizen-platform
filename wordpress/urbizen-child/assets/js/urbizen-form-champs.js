/**
 * Champs conditionnés par la nature du projet.
 *
 * Le formulaire posait les mêmes questions de surface à toutes les natures : on
 * demandait une surface de plancher pour une piscine, une clôture, un
 * ravalement. Pire, la surface créée portait un astérisque — une piscine
 * *exigeait* une surface de plancher pour être envoyée.
 *
 * **Ce module ne décide de rien.** La matrice métier est déclarée côté serveur
 * et lui parvient par le pont, dans la même charge que le nonce. Il n'en tient
 * aucune copie : une liste recopiée à la main dériverait, et l'interface
 * finirait par masquer un champ que le serveur attend — ou par en proposer un
 * qu'il écarte.
 *
 * Quatre choses sont faites à chaque changement de nature :
 *
 * 1. le groupe est **masqué** ;
 * 2. son contrôle est **désactivé** — ce qui l'exclut du `FormData`, puisque le
 *    navigateur n'envoie pas un champ désactivé. C'est la garantie qui compte :
 *    masquer sans désactiver laisserait partir la valeur ;
 * 3. son **obligation est retirée**, marqueur visuel compris ;
 * 4. son **erreur affichée est effacée**, faute de quoi une étape resterait
 *    bloquée sur un champ devenu invisible.
 *
 * La valeur, elle, est **conservée dans le contrôle**. Revenir d'une piscine à
 * une extension rend les surfaces déjà saisies plutôt que d'obliger à tout
 * refaire. Elle ne part pas tant que la nature ne l'autorise pas : le contrôle
 * reste désactivé.
 *
 * Le serveur refiltre de son côté. Ce module est la politesse ; le filtrage
 * serveur est la règle.
 */
(function (global) {
  "use strict";

  /**
   * @param {Object} config
   * @param {HTMLFormElement} config.form
   * @param {Function} config.surNature  Rend la nature actuellement choisie.
   */
  function Champs(config) {
    this.form = config.form;
    this.surNature = config.surNature;

    // Tant que la matrice n'est pas arrivée, rien n'est masqué : un formulaire
    // amputé serait pire qu'un formulaire trop large.
    this.matrice = null;
    this.conditionnels = [];

    this._ecouter();
  }

  /**
   * Reçoit la matrice du serveur et l'applique aussitôt.
   *
   * @param {Object} matrice        nature → liste de champs applicables.
   * @param {Array}  conditionnels  Champs soumis à la matrice.
   */
  Champs.prototype.appliquerMatrice = function (matrice, conditionnels) {
    if (!matrice || "object" !== typeof matrice) return;

    this.matrice = matrice;
    this.conditionnels = Array.isArray(conditionnels) ? conditionnels : [];
    this.rafraichir();
  };

  /** Un champ est-il applicable à la nature courante ? */
  Champs.prototype.applicable = function (champ) {
    // Sans matrice, tout reste affiché : on ne masque pas sur une supposition.
    if (!this.matrice) return true;

    var nature = this.surNature();
    var admis = this.matrice[nature];

    // Nature non choisie, ou inconnue de la matrice : aucun champ conditionnel.
    // C'est le bon défaut — le client choisit son projet avant qu'on l'interroge
    // sur ses dimensions.
    if (!Array.isArray(admis)) return false;

    return -1 !== admis.indexOf(champ);
  };

  /** Applique la matrice à tous les groupes marqués. */
  Champs.prototype.rafraichir = function () {
    var module = this;
    var groupes = this.form.querySelectorAll("[data-champ]");
    var visibles = 0;

    Array.prototype.forEach.call(groupes, function (groupe) {
      var champ = groupe.getAttribute("data-champ");
      var actif = module.applicable(champ);

      groupe.hidden = !actif;
      groupe.setAttribute("aria-hidden", actif ? "false" : "true");

      if (actif) visibles++;

      Array.prototype.forEach.call(groupe.querySelectorAll("input, select, textarea"), function (controle) {
        // Désactivé = absent du FormData. C'est ce qui empêche une surface
        // saisie pour une extension de partir avec une piscine.
        controle.disabled = !actif;

        if (actif) return;

        controle.removeAttribute("required");
        controle.removeAttribute("aria-invalid");
        controle.classList.remove("is-error");
      });

      // Le marqueur d'obligation ne doit pas subsister sur un champ masqué :
      // la validation d'étape le lit, et bloquerait sur un champ invisible.
      Array.prototype.forEach.call(groupe.querySelectorAll(".req"), function (marqueur) {
        marqueur.hidden = !actif;
      });
    });

    this._annoncer(visibles);
  };

  /**
   * Le bloc entier disparaît quand il ne reste rien à demander.
   *
   * Un titre « Précisions sur votre projet » suivi du vide serait plus
   * déroutant qu'utile.
   */
  Champs.prototype._annoncer = function (visibles) {
    var bloc = this.form.querySelector("[data-champs-conditionnels]");

    if (bloc) bloc.hidden = 0 === visibles;
  };

  /** Toute sélection de nature déclenche une réévaluation. */
  Champs.prototype._ecouter = function () {
    var module = this;

    Array.prototype.forEach.call(this.form.querySelectorAll('[name="nature"]'), function (entree) {
      entree.addEventListener("change", function () {
        module.rafraichir();
      });
    });
  };

  global.UrbizenChamps = {
    init: function (config) {
      return new Champs(config);
    }
  };
})(window);
