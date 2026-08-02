/**
 * Moteur tarifaire partagé des formulaires d'autorisation (DP et PCMI).
 *
 * Source unique du calcul affiché au client. Les formulaires ne déclarent que
 * leur barème et leurs suppléments : aucun montant, aucune règle de cumul et
 * aucune construction de récapitulatif ne vit ailleurs que dans ce fichier.
 * C'est ce qui évite qu'un même barème dérive entre la maquette `frontend/` et
 * la copie servie par le thème.
 *
 * Ce que ce moteur calcule est une **estimation affichée**. Ce n'est pas une
 * source de vérité tarifaire : rien ici n'engage Urbizen, et le jour où ces
 * formulaires posteront réellement, le montant reçu du navigateur ne devra pas
 * être cru — le barème devra exister côté serveur, comme `Forms\Pricing` le
 * fait déjà pour la conception.
 *
 * La liste des natures de travaux n'est **pas** configurée : elle est lue dans
 * le formulaire lui-même. Une nature ajoutée au balisage est donc disponible
 * immédiatement, sans seconde déclaration à tenir à jour.
 */
(function (global) {
  "use strict";

  /**
   * Mention légale imposée sous le total. Texte contractuel : il est reproduit
   * au caractère près et un banc en vérifie la conformité.
   */
  var MENTION =
    "Estimation indicative. Le tarif définitif sera confirmé par Urbizen " +
    "après vérification de votre projet, avant toute commande.";

  var RAPPEL_REGROUPEMENT =
    "Les projets supplémentaires doivent concerner le même demandeur, la même " +
    "adresse et la même parcelle, et pouvoir être regroupés dans un même " +
    "dossier administratif. Urbizen vérifiera leur compatibilité après " +
    "réception de la demande.";

  var PRECISIONS_MAX = 200;

  function euros(montant) {
    return montant + " €";
  }

  function el(tag, classe, texte) {
    var noeud = document.createElement(tag);
    if (classe) noeud.className = classe;
    if (texte !== undefined && texte !== null) noeud.textContent = texte;
    return noeud;
  }

  /**
   * Moteur attaché à un formulaire.
   *
   * @param {Object} config
   * @param {HTMLFormElement} config.form
   * @param {string} config.libelleType   Ex. « Déclaration préalable ».
   * @param {Object} config.bareme        nature → montant ; `__defaut` obligatoire.
   * @param {Array}  config.surEtude      Natures sans prix chiffrable.
   * @param {Object} config.categories    nature → libellé de catégorie ; `__defaut`.
   * @param {Object} config.supplements   { abf, depot, travail }
   */
  function Tarifs(config) {
    this.form = config.form;
    this.libelleType = config.libelleType;
    this.bareme = config.bareme;
    this.surEtude = config.surEtude || [];
    this.categories = config.categories || {};
    this.supplements = config.supplements;

    /** @type {Array<{nature: string, precisions: string}>} */
    this.travaux = [];

    this.conteneurTravaux = this.form.querySelector("[data-tarifs-travaux]");
    this.boutonAjouter = this.form.querySelector("[data-tarifs-ajouter]");
    this.conteneurRecap = this.form.querySelector("[data-tarifs-recap]");
    this.rappelPrincipal = this.form.querySelector("[data-tarifs-principal]");

    // Le récapitulatif final vit hors du <form> (écran de confirmation).
    var racine = this.form.closest("#dp-app") || document;
    this.conteneurRecapFinal = racine.querySelector("[data-tarifs-recap-final]");

    this._brancher();
    this.rafraichir();
  }

  /**
   * Natures proposables, lues dans le formulaire (pas dans la configuration).
   *
   * @return {Array<{value: string, label: string}>}
   */
  Tarifs.prototype.natures = function () {
    var liste = [];

    Array.prototype.forEach.call(
      this.form.querySelectorAll('input[name="nature"]'),
      function (input) {
        var carte = input.closest(".dp-check");
        liste.push({
          value: input.value,
          label: carte ? carte.textContent.trim() : input.value
        });
      }
    );

    return liste;
  };

  Tarifs.prototype.naturePrincipale = function () {
    var choisi = this.form.querySelector('input[name="nature"]:checked');
    return choisi ? choisi.value : "";
  };

  /**
   * Barème du projet principal.
   *
   * @return {{nature: string, categorie: string, montant: (number|null), surEtude: boolean}|null}
   */
  Tarifs.prototype.principal = function () {
    var nature = this.naturePrincipale();
    if ("" === nature) return null;

    var estSurEtude = this.surEtude.indexOf(nature) !== -1;
    var montant = Object.prototype.hasOwnProperty.call(this.bareme, nature)
      ? this.bareme[nature]
      : this.bareme.__defaut;

    return {
      nature: nature,
      categorie: this.categories[nature] || this.categories.__defaut || "",
      montant: estSurEtude ? null : montant,
      surEtude: estSurEtude
    };
  };

  /** Natures déjà prises : le projet principal et les autres lignes. */
  Tarifs.prototype.naturesPrises = function (saufIndex) {
    var prises = [];
    var principale = this.naturePrincipale();

    if ("" !== principale) prises.push(principale);

    this.travaux.forEach(function (travail, i) {
      if (i !== saufIndex && "" !== travail.nature) prises.push(travail.nature);
    });

    return prises;
  };

  /**
   * Détail tarifaire complet.
   *
   * Le total vaut `null` dès que le projet principal est « sur étude » : on
   * n'additionne pas des suppléments à un socle inconnu pour en tirer un
   * chiffre qui aurait l'air d'un prix. Les suppléments restent détaillés.
   */
  Tarifs.prototype.detail = function () {
    var principal = this.principal();
    var supp = this.supplements;

    var abf = this.form.querySelector('input[name="abf"]:checked');
    var montantAbf = abf && "oui" === abf.value ? supp.abf : 0;

    var depot = this.form.querySelector('input[name="depot_guichet"]');
    var montantDepot = depot && depot.checked ? supp.depot : 0;

    var travaux = this.travaux.map(function (travail) {
      return {
        nature: travail.nature,
        precisions: travail.precisions,
        montant: supp.travail
      };
    });

    var surEtude = !principal || principal.surEtude;
    var total = null;

    if (!surEtude) {
      total =
        principal.montant +
        travaux.length * supp.travail +
        montantAbf +
        montantDepot;
    }

    return {
      principal: principal,
      travaux: travaux,
      abf: montantAbf,
      depot: montantDepot,
      surEtude: surEtude,
      total: total
    };
  };

  /* ------------------------------------------------------------------ *
   *  Projets supplémentaires
   * ------------------------------------------------------------------ */

  Tarifs.prototype.ajouter = function () {
    this.travaux.push({ nature: "", precisions: "" });
    this.rafraichir();

    var selects = this.conteneurTravaux.querySelectorAll(".dp-travail-nature");
    if (selects.length) selects[selects.length - 1].focus();
  };

  Tarifs.prototype.supprimer = function (index) {
    this.travaux.splice(index, 1);
    this.rafraichir();
  };

  /**
   * Une ligne est-elle complète ? Un `select` vide bloque l'étape ; aucune
   * ligne du tout est un cas parfaitement valide.
   */
  Tarifs.prototype.estValide = function () {
    return this.travaux.every(function (travail) {
      return "" !== travail.nature;
    });
  };

  Tarifs.prototype.rendreTravaux = function () {
    if (!this.conteneurTravaux) return;

    var moteur = this;
    this.conteneurTravaux.textContent = "";

    this.travaux.forEach(function (travail, index) {
      moteur.conteneurTravaux.appendChild(moteur._ligne(travail, index));
    });
  };

  Tarifs.prototype._ligne = function (travail, index) {
    var moteur = this;
    var ligne = el("div", "dp-travail");
    ligne.dataset.index = String(index);

    var tete = el("div", "dp-travail-tete");
    tete.appendChild(el("span", "dp-travail-num", "Projet supplémentaire " + (index + 1)));
    tete.appendChild(el("span", "dp-travail-prix", "+" + euros(this.supplements.travail)));

    var suppr = el("button", "dp-travail-suppr");
    suppr.type = "button";
    suppr.appendChild(el("span", "dp-travail-suppr-txt", "Supprimer"));
    suppr.setAttribute("aria-label", "Supprimer le projet supplémentaire " + (index + 1));
    suppr.addEventListener("click", function () {
      moteur.supprimer(index);
    });
    tete.appendChild(suppr);
    ligne.appendChild(tete);

    /* --- nature --- */
    var champNature = el("div", "dp-field");
    var idNature = "travail-nature-" + index;
    var labelNature = el("label", null, "Nature ");
    labelNature.htmlFor = idNature;
    labelNature.appendChild(el("span", "req", "*"));
    champNature.appendChild(labelNature);

    var select = el("select", "dp-travail-nature");
    select.id = idNature;

    var vide = el("option", null, "— Choisissez un travail —");
    vide.value = "";
    select.appendChild(vide);

    var prises = this.naturesPrises(index);

    this.natures().forEach(function (nature) {
      // Une nature déjà retenue ailleurs n'est tout simplement pas proposée :
      // le doublon est rendu impossible, plutôt que signalé après coup.
      if (prises.indexOf(nature.value) !== -1) return;

      var option = el("option", null, nature.label);
      option.value = nature.value;
      select.appendChild(option);
    });

    // La valeur courante reste sélectionnable même si elle est « prise » —
    // par elle-même. Sans cela, la ligne perdrait son choix à chaque rendu.
    // On balaie les options plutôt que d'interroger le sélecteur par valeur :
    // les natures contiennent des apostrophes et des parenthèses, et tous les
    // moteurs ne fournissent pas `CSS.escape`.
    var dejaProposee = Array.prototype.some.call(select.options, function (option) {
      return option.value === travail.nature;
    });

    if ("" !== travail.nature && !dejaProposee) {
      var courante = el("option", null, this._libelleDe(travail.nature));
      courante.value = travail.nature;
      select.appendChild(courante);
    }

    select.value = travail.nature;
    select.addEventListener("change", function () {
      moteur.travaux[index].nature = select.value;
      moteur.rafraichir();
    });
    champNature.appendChild(select);
    ligne.appendChild(champNature);

    /* --- précisions --- */
    var champDesc = el("div", "dp-field");
    var idDesc = "travail-desc-" + index;
    var labelDesc = el("label", null, "Précisions (facultatif)");
    labelDesc.htmlFor = idDesc;
    champDesc.appendChild(labelDesc);

    var desc = el("input", "dp-travail-desc");
    desc.type = "text";
    desc.id = idDesc;
    desc.maxLength = PRECISIONS_MAX;
    desc.placeholder = "Ex. bassin 4 × 8 m à l'arrière de la maison";
    desc.value = travail.precisions;
    desc.addEventListener("input", function () {
      // Pas de re-rendu : réécrire le DOM à chaque frappe ferait perdre le
      // curseur. Seul le récapitulatif est rafraîchi.
      moteur.travaux[index].precisions = desc.value;
      moteur.rendreRecap();
    });
    champDesc.appendChild(desc);
    ligne.appendChild(champDesc);

    return ligne;
  };

  Tarifs.prototype._libelleDe = function (valeur) {
    var trouve = "";

    this.natures().forEach(function (nature) {
      if (nature.value === valeur) trouve = nature.label;
    });

    return trouve || valeur;
  };

  /* ------------------------------------------------------------------ *
   *  Récapitulatif
   * ------------------------------------------------------------------ */

  /**
   * Construit le récapitulatif. Une ligne à zéro est **absente**, jamais
   * affichée à « 0 € » : le client ne lit que ce qu'il a effectivement choisi.
   *
   * @param {boolean} compact Sans le détail ligne à ligne des travaux.
   */
  Tarifs.prototype._recap = function (compact) {
    var detail = this.detail();
    var bloc = el("div", "dp-recap");
    var lignes = el("dl", "dp-recap-lignes");
    var moteur = this;

    function ajouterLigne(libelle, valeur, classe) {
      var ligne = el("div", "dp-recap-ligne" + (classe ? " " + classe : ""));
      ligne.appendChild(el("dt", null, libelle));
      ligne.appendChild(el("dd", null, valeur));
      lignes.appendChild(ligne);
    }

    if (!detail.principal) {
      bloc.appendChild(
        el("p", "dp-recap-vide", "Sélectionnez la nature de votre projet pour voir l'estimation.")
      );
      return bloc;
    }

    ajouterLigne(
      "Projet principal · " + this._libelleDe(detail.principal.nature),
      detail.principal.surEtude ? "Tarif sur étude" : euros(detail.principal.montant)
    );

    if (detail.travaux.length) {
      ajouterLigne(
        "Projets supplémentaires (" + detail.travaux.length + ")",
        euros(detail.travaux.length * this.supplements.travail)
      );

      if (!compact) {
        detail.travaux.forEach(function (travail) {
          var libelle = "Projet supplémentaire — " + moteur._libelleDe(travail.nature);
          if (travail.precisions) libelle += " (" + travail.precisions + ")";
          ajouterLigne(libelle, "+" + euros(travail.montant), "is-detail");
        });
      }
    }

    if (detail.abf) {
      ajouterLigne("Secteur Bâtiments de France", euros(detail.abf));
    }

    if (detail.depot) {
      ajouterLigne("Dépôt sur le guichet numérique", euros(detail.depot));
    }

    bloc.appendChild(lignes);

    var total = el("p", "dp-recap-total");
    total.appendChild(el("span", null, "Total estimé"));
    total.appendChild(
      el("strong", null, detail.surEtude ? "Tarif sur étude" : euros(detail.total))
    );
    bloc.appendChild(total);

    bloc.appendChild(el("p", "dp-recap-mention", MENTION));

    return bloc;
  };

  Tarifs.prototype.rendreRecap = function () {
    if (this.conteneurRecap) {
      this.conteneurRecap.textContent = "";
      this.conteneurRecap.appendChild(this._recap(false));
    }

    if (this.rappelPrincipal) {
      var principal = this.principal();
      this.rappelPrincipal.textContent = principal
        ? "Votre projet principal — " +
          this._libelleDe(principal.nature) +
          " — est déjà pris en compte. Vous pouvez regrouper d'autres projets " +
          "dans le même dossier : chaque projet supplémentaire est facturé " +
          euros(this.supplements.travail) + "."
        : "Sélectionnez d'abord la nature de votre projet à l'étape « Projet ».";
    }
  };

  /** Récapitulatif synthétique de l'écran de confirmation. */
  Tarifs.prototype.rendreRecapFinal = function () {
    if (!this.conteneurRecapFinal) return;

    this.conteneurRecapFinal.textContent = "";
    this.conteneurRecapFinal.appendChild(this._recap(true));
  };

  Tarifs.prototype.rafraichir = function () {
    this._purgerDoublonPrincipal();
    this.rendreTravaux();
    this.rendreRecap();
  };

  /**
   * Si le projet principal devient une nature déjà présente en ligne, la ligne
   * concernée est vidée — jamais supprimée en silence : le client doit voir
   * qu'il a un choix à refaire.
   */
  Tarifs.prototype._purgerDoublonPrincipal = function () {
    var principale = this.naturePrincipale();
    if ("" === principale) return;

    this.travaux.forEach(function (travail) {
      if (travail.nature === principale) travail.nature = "";
    });
  };

  /* ------------------------------------------------------------------ *
   *  Liaison au formulaire
   * ------------------------------------------------------------------ */

  Tarifs.prototype._brancher = function () {
    var moteur = this;

    if (this.boutonAjouter) {
      this.boutonAjouter.addEventListener("click", function () {
        moteur.ajouter();
      });
    }

    this.form.addEventListener("change", function (e) {
      var nom = e.target && e.target.name;

      if ("nature" === nom) {
        // En choix unique, la carte précédemment retenue doit perdre son état
        // visuel : sans cette purge, deux cartes resteraient allumées.
        Array.prototype.forEach.call(
          moteur.form.querySelectorAll('input[name="nature"]'),
          function (input) {
            var carte = input.closest(".dp-check");
            if (carte) carte.classList.toggle("on", input.checked);
          }
        );
      }

      if ("nature" === nom || "abf" === nom || "depot_guichet" === nom) {
        moteur.rafraichir();
      }
    });
  };

  /**
   * Ajoute les travaux et l'option de dépôt à la charge envoyée.
   *
   * Le total **n'est pas** sérialisé : il est recalculé par Urbizen à la
   * lecture. Un montant venu du navigateur n'a aucune valeur probante.
   */
  Tarifs.prototype.serialiser = function (fd) {
    var travaux = this.travaux
      .filter(function (travail) {
        return "" !== travail.nature;
      })
      .map(function (travail) {
        return { nature: travail.nature, precisions: travail.precisions };
      });

    fd.set("travaux_supplementaires", JSON.stringify(travaux));

    var depot = this.form.querySelector('input[name="depot_guichet"]');
    fd.set("depot_guichet", depot && depot.checked ? "oui" : "non");
  };

  global.UrbizenTarifs = {
    init: function (config) {
      return new Tarifs(config);
    },
    MENTION: MENTION,
    RAPPEL_REGROUPEMENT: RAPPEL_REGROUPEMENT
  };
})(window);
