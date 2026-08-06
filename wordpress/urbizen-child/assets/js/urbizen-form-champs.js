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
    this._bassin();
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

      Array.prototype.forEach.call(groupe.querySelectorAll("input, select, textarea, button"), function (controle) {
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

    this._sousChamps();
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

  /**
   * Sous-champs qui ne dépendent pas de la nature mais d'une autre réponse.
   *
   * La hauteur d'un abri n'a de sens que si un abri est annoncé, et l'abri
   * lui-même n'a de sens que si une piscine est prévue. Le marqueur
   * `data-visible-si="champ=valeur"` porte la condition dans le document, là où
   * elle se lit en même temps que le champ qu'elle gouverne.
   *
   * Deux règles font tenir l'enchaînement :
   *
   * 1. **La matrice commande en premier.** Un champ que la nature n'admet pas
   *    ne peut être ramené par aucune réponse.
   * 2. **Un pilote désactivé ne gouverne rien.** Un contrôle désactivé est un
   *    contrôle qu'on ne pose pas ; sa valeur résiduelle ne doit pas ouvrir le
   *    champ qu'il gouverne. C'est ce qui propage la fermeture le long de la
   *    chaîne, sans que le document ait à déclarer des conditions composées.
   *
   * La boucle se répète jusqu'à stabilité pour ne pas dépendre de l'ordre du
   * document : une chaîne écrite à l'envers doit converger, pas se tromper.
   */
  Champs.prototype._sousChamps = function () {
    var module = this;
    var form = this.form;
    var groupes = form.querySelectorAll("[data-visible-si]");

    for (var passe = 0; passe < groupes.length + 1; passe++) {
      var change = false;

      Array.prototype.forEach.call(groupes, function (groupe) {
        var regle = (groupe.getAttribute("data-visible-si") || "").split("=");
        var champ = groupe.getAttribute("data-champ");
        var pilote = form.querySelector('[name="' + regle[0] + '"]:checked');

        var actif =
          (!champ || module.applicable(champ)) && !!pilote && !pilote.disabled && pilote.value === regle[1];

        if (groupe.hidden === !actif) return;

        change = true;
        groupe.hidden = !actif;

        Array.prototype.forEach.call(groupe.querySelectorAll("input, select, textarea, button"), function (c) {
          c.disabled = !actif;
        });
      });

      if (!change) break;
    }
  };

  /**
   * Surface du bassin, proposée depuis la longueur et la largeur.
   *
   * **Proposée, pas imposée.** Un bassin n'est pas toujours rectangulaire, et
   * une personne qui corrige la surface a une raison de le faire. Dès qu'elle y
   * touche, le calcul cesse de la remplacer : écraser une correction à chaque
   * frappe serait la façon la plus sûre de faire perdre confiance au formulaire.
   */
  Champs.prototype._bassin = function () {
    var module = this;
    var form = this.form;
    var surface = form.querySelector("[data-bassin-surface]");

    if (!surface) return;

    var note = form.querySelector("[data-bassin-note]");
    var corrigee = false;

    // Une valeur déjà saisie à l'arrivée est une valeur de l'utilisateur.
    if ("" !== surface.value) corrigee = true;

    surface.addEventListener("input", function () {
      corrigee = true;
      module._etatSurface(true);
    });

    // Retour explicite au calcul : un état implicite qu'on ne sait pas défaire
    // est pire que pas d'automatisme du tout.
    var bouton = form.querySelector("[data-bassin-recalculer]");

    if (bouton) {
      bouton.addEventListener("click", function (e) {
        e.preventDefault();
        corrigee = false;
        recalculer();
      });
    }

    var recalculer = function () {
      if (corrigee) return;

      var nombre = function (champ) {
        var e = form.querySelector('[name="' + champ + '"]');
        if (!e) return null;

        // L'analyse partagée, jamais `parseFloat` sur une chaîne française :
        // `parseFloat("8,5")` rend 8, et le bassin perdrait un demi-mètre.
        var issue = global.UrbizenNombres.analyser(e.value, global.UrbizenNombres.bornesDe(e));

        return global.UrbizenNombres.VALIDE === issue.etat && issue.valeur > 0 ? issue.valeur : null;
      };

      var l = nombre(surface.getAttribute("data-bassin-longueur") || "");
      var g = nombre(surface.getAttribute("data-bassin-largeur") || "");

      if (null === l || null === g) return;

      // Écrite à la française : la personne relit ce qu'elle aurait écrit.
      surface.value = global.UrbizenNombres.afficher(l * g);
      module._etatSurface(false);
    };

    this._recalculer = recalculer;

    [surface.getAttribute("data-bassin-longueur"), surface.getAttribute("data-bassin-largeur")].forEach(function (champ) {
      var e = form.querySelector('[name="' + champ + '"]');
      if (e) e.addEventListener("input", recalculer);
    });
  };

  /**
   * Dit à l'écran d'où vient la surface affichée.
   *
   * Deux états, nommés : « calculée » tant que personne n'y a touché,
   * « personnalisée » ensuite — avec le moyen de revenir en arrière. Un
   * automatisme qui cesse sans le dire laisse croire à une panne.
   */
  Champs.prototype._etatSurface = function (personnalisee) {
    var note = this.form.querySelector("[data-bassin-note]");
    var bouton = this.form.querySelector("[data-bassin-recalculer]");

    if (note) {
      note.textContent = personnalisee
        ? "Surface personnalisée."
        : "Calculée depuis la longueur et la largeur. Modifiez-la si le bassin n’est pas rectangulaire.";
      note.hidden = false;
    }

    if (bouton) bouton.hidden = !personnalisee;
  };

  /** Toute sélection de nature déclenche une réévaluation. */
  Champs.prototype._ecouter = function () {
    var module = this;

    Array.prototype.forEach.call(this.form.querySelectorAll('[name="nature"]'), function (entree) {
      entree.addEventListener("change", function () {
        module.rafraichir();
      });
    });

    // Les pilotes de sous-champs sont déduits des règles portées par le
    // document : le module n'a pas à connaître leurs noms. Nommer ici un champ
    // précis reviendrait à recopier une donnée métier dans du code générique.
    // Un même pilote gouvernant plusieurs champs, on ne l'écoute qu'une fois.
    var pilotes = {};

    Array.prototype.forEach.call(this.form.querySelectorAll("[data-visible-si]"), function (groupe) {
      pilotes[(groupe.getAttribute("data-visible-si") || "").split("=")[0]] = true;
    });

    Object.keys(pilotes).forEach(function (pilote) {
      Array.prototype.forEach.call(module.form.querySelectorAll('[name="' + pilote + '"]'), function (entree) {
        entree.addEventListener("change", function () {
          module._sousChamps();
        });
      });
    });
  };

  global.UrbizenChamps = {
    init: function (config) {
      return new Champs(config);
    }
  };
})(window);
