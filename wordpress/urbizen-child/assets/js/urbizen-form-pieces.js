/**
 * Pièces du projet — dépôt de fichiers et report à plus tard (DP et PCMI).
 *
 * Source unique du rendu de l'étape « Documents ». Les quatre documents de
 * formulaire — les deux maquettes de `frontend/` et les deux copies servies par
 * le thème — chargent ce fichier ; aucun d'eux ne reconstruit la liste.
 *
 * Ce que ce module porte, et pourquoi :
 *
 * 1. **Aucune pièce ne bloque l'envoi.** Les documents demandés ici sont des
 *    aides à l'étude, pas les pièces réglementaires du dossier. Un client qui
 *    n'a pas encore photographié sa façade doit pouvoir envoyer sa demande :
 *    c'est Urbizen qui lui dira ensuite ce qui manque. Les informations
 *    réellement indispensables — identité, terrain, nature du projet — restent
 *    obligatoires, et sont marquées comme telles ailleurs dans le formulaire.
 *
 * 2. **Le report est déclaré, pas subi.** Cocher « je transmettrai ce document
 *    ultérieurement » vaut engagement explicite du client ; la pièce apparaît
 *    alors dans le récapitulatif sous « À transmettre ultérieurement ». C'est
 *    une information utile à Urbizen, pas un simple champ vide.
 *
 * 3. **Rien n'est présenté comme une dispense.** Le message rassure sur le
 *    moment de la transmission, jamais sur la nécessité : un dossier déposé en
 *    mairie exige ses pièces réglementaires, et le texte ne laisse pas entendre
 *    le contraire.
 */
(function (global) {
  "use strict";

  var MESSAGE_RASSURANT =
    "Vous ne disposez pas encore de toutes les photos ou informations " +
    "demandées ? Vous pouvez tout de même poursuivre et nous les transmettre " +
    "ultérieurement. Urbizen vous indiquera, après vérification de votre " +
    "demande, les éléments complémentaires nécessaires à la réalisation du " +
    "dossier.";

  // Il n'y a volontairement pas de mention par ligne : répétée sous chacune des
  // sept pièces, elle noyait l'encadré d'ouverture qui porte déjà l'information.
  // Chaque rangée n'affiche que le document demandé, le bouton de dépôt et la
  // case de report.
  var LIBELLE_REPORT = "Je transmettrai ce document ultérieurement.";

  var TITRE_REPORT = "À transmettre ultérieurement";

  /**
   * Groupes d'informations que le client peut déclarer ne pas connaître.
   *
   * Les références cadastrales sont typiquement introuvables pour qui n'a pas
   * son acte sous la main. Les exiger ferait abandonner une demande que la
   * localisation cartographique, ou Urbizen après réception, sait compléter.
   */
  var INFORMATIONS = [
    {
      case: "informations_cadastrales_differees",
      champs: ["t_sec", "t_num", "t_sup"],
      libelle: "Informations cadastrales : à compléter ultérieurement"
    }
  ];

  function el(tag, classe, texte) {
    var noeud = document.createElement(tag);
    if (classe) noeud.className = classe;
    if (texte !== undefined && texte !== null) noeud.textContent = texte;
    return noeud;
  }

  /**
   * @param {Object} config
   * @param {HTMLFormElement} config.form
   * @param {HTMLElement} config.conteneur  Le `#pieces` de l'étape Documents.
   * @param {Array<Array<string>>} config.pieces  [[code, libellé], …]
   */
  function Pieces(config) {
    this.form = config.form;
    this.conteneur = config.conteneur;
    this.pieces = config.pieces;

    /** Codes des pièces déclarées « à transmettre ultérieurement ». */
    this.reportees = {};
    /** Codes des pièces pour lesquelles au moins un fichier est choisi. */
    this.fournies = {};

    this.recap = this.form.querySelector("[data-pieces-recap]");

    var racine = this.form.closest("#dp-app") || document;
    this.recapFinal = racine.querySelector("[data-pieces-recap-final]");

    this._rendre();
    this._brancherInformations();
    this.rafraichirRecap();
  }

  /* ------------------------------------------------------------------ *
   *  Informations que le client peut déclarer ne pas connaître
   * ------------------------------------------------------------------ */

  Pieces.prototype._brancherInformations = function () {
    var module = this;
    this.informations = [];

    INFORMATIONS.forEach(function (groupe) {
      var caseGroupe = module.form.querySelector('input[name="' + groupe.case + '"]');
      if (!caseGroupe) return;

      var champs = groupe.champs
        .map(function (id) {
          return module.form.querySelector("#" + id);
        })
        .filter(Boolean);

      // Les valeurs déjà détectées par la localisation cadastrale sont des
      // données acquises : la case n'est jamais cochée automatiquement, et
      // rien n'est effacé tant que le client ne le demande pas lui-même.
      function appliquer() {
        var inconnu = caseGroupe.checked;

        champs.forEach(function (champ) {
          if (inconnu) champ.value = "";
          champ.disabled = inconnu;
          var conteneur = champ.closest(".dp-field");
          if (conteneur) conteneur.classList.toggle("is-desactive", inconnu);
        });

        module.rafraichirRecap();
      }

      caseGroupe.addEventListener("change", appliquer);

      // Si une valeur arrive après coup — confirmation d'une parcelle sur le
      // plan, par exemple — la déclaration d'ignorance n'a plus lieu d'être.
      champs.forEach(function (champ) {
        champ.addEventListener("input", function () {
          if (caseGroupe.checked && "" !== champ.value) {
            caseGroupe.checked = false;
            appliquer();
          }
        });
      });

      module.informations.push({ groupe: groupe, caseGroupe: caseGroupe, champs: champs });
      appliquer();
    });
  };

  /** Groupes d'informations déclarés inconnus. */
  Pieces.prototype.informationsDifferees = function () {
    return (this.informations || [])
      .filter(function (entree) {
        return entree.caseGroupe.checked;
      })
      .map(function (entree) {
        return entree.groupe.libelle;
      });
  };

  Pieces.prototype._rendre = function () {
    var module = this;
    this.conteneur.textContent = "";

    /* --- Message rassurant, en tête de l'étape --- */
    var intro = el("div", "dp-pieces-intro");
    intro.appendChild(el("p", "dp-pieces-intro-texte", MESSAGE_RASSURANT));
    intro.appendChild(
      el(
        "p",
        "dp-pieces-intro-note",
        "Aucun document de cette étape n’est obligatoire pour envoyer votre " +
          "demande. Seuls les champs marqués d’un astérisque dans les étapes " +
          "précédentes le sont."
      )
    );
    this.conteneur.appendChild(intro);

    this.pieces.forEach(function (piece) {
      module.conteneur.appendChild(module._ligne(piece[0], piece[1]));
    });
  };

  Pieces.prototype._ligne = function (code, libelle) {
    var module = this;
    var rangee = el("div", "dp-piece");
    rangee.dataset.piece = code;

    var idFichier = "file_" + code;

    /* --- libellé --- */
    var lab = el("span", "lab");
    lab.appendChild(el("b", null, code));
    lab.appendChild(document.createTextNode(libelle));
    rangee.appendChild(lab);

    /* --- action --- */
    var action = el("span", "dp-piece-action");
    var choisis = el("span", "picked");
    var bouton = el("label", "dp-file-btn", "Choisir un fichier");
    bouton.htmlFor = idFichier;
    action.appendChild(choisis);
    action.appendChild(bouton);
    rangee.appendChild(action);

    var input = el("input");
    input.type = "file";
    input.id = idFichier;
    input.name = "piece_" + code;
    input.accept = ".pdf,.jpg,.jpeg,.png";
    input.multiple = true;
    rangee.appendChild(input);

    /* --- report --- */
    var report = el("label", "dp-piece-report");
    var caseReport = el("input");
    caseReport.type = "checkbox";
    // Valeur répétée à liste fermée : le serveur juge chaque identifiant, au
    // lieu d'avoir à ouvrir une chaîne JSON.
    caseReport.name = "pieces_differees[]";
    caseReport.value = code;
    report.appendChild(caseReport);
    report.appendChild(document.createTextNode(LIBELLE_REPORT));
    rangee.appendChild(report);

    function majEtat() {
      var aDesFichiers = input.files && input.files.length > 0;
      var reportee = caseReport.checked && !aDesFichiers;

      module.fournies[code] = aDesFichiers;
      module.reportees[code] = reportee;

      choisis.textContent = aDesFichiers
        ? input.files.length + " fichier" + (input.files.length > 1 ? "s" : "") + " ✓"
        : reportee
        ? TITRE_REPORT
        : "";

      // Un fichier déposé rend le report sans objet : on décoche plutôt que de
      // laisser coexister « fourni » et « à transmettre ».
      if (aDesFichiers && caseReport.checked) caseReport.checked = false;

      rangee.classList.toggle("is-reportee", reportee);
      rangee.classList.toggle("is-fournie", aDesFichiers);

      module.rafraichirRecap();
    }

    input.addEventListener("change", majEtat);
    caseReport.addEventListener("change", majEtat);
    majEtat();

    return rangee;
  };

  /** Pièces reportées, dans l'ordre du formulaire. */
  Pieces.prototype.differees = function () {
    var module = this;

    return this.pieces
      .filter(function (piece) {
        return true === module.reportees[piece[0]];
      })
      .map(function (piece) {
        return { code: piece[0], libelle: piece[1] };
      });
  };

  Pieces.prototype._bloc = function () {
    var differees = this.differees();
    var informations = this.informationsDifferees();
    if (!differees.length && !informations.length) return null;

    var bloc = el("div", "dp-pieces-differees");
    bloc.appendChild(el("p", "dp-pieces-differees-titre", TITRE_REPORT));

    // Les informations manquantes précèdent les documents : elles conditionnent
    // l'instruction du dossier, là où une photo se rattrape à tout moment.
    informations.forEach(function (libelle) {
      bloc.appendChild(el("p", "dp-pieces-differees-info", libelle));
    });

    var liste = el("ul", "dp-pieces-differees-liste");
    differees.forEach(function (piece) {
      liste.appendChild(el("li", null, piece.libelle));
    });
    if (differees.length) bloc.appendChild(liste);

    bloc.appendChild(
      el(
        "p",
        "dp-pieces-differees-note",
        "Ces éléments ne bloquent pas l’envoi de votre demande. Urbizen les " +
          "vérifiera ou vous les demandera après réception."
      )
    );

    return bloc;
  };

  Pieces.prototype.rafraichirRecap = function () {
    [this.recap, this.recapFinal].forEach(
      function (cible) {
        if (!cible) return;
        cible.textContent = "";
        var bloc = this._bloc();
        if (bloc) cible.appendChild(bloc);
      }.bind(this)
    );
  };

  /**
   * Ajoute la liste des pièces reportées à la charge envoyée. Les cases
   * `piece_later_*` voyagent déjà dans le FormData ; cette clé donne à Urbizen
   * la liste lisible, sans avoir à recomposer les noms de champs.
   */
  /**
   * Déclare ce que le navigateur croit envoyer.
   *
   * PHP plafonne le nombre de fichiers d'une requête (`max_file_uploads`) et
   * tronque au-delà **sans rien signaler**. Le serveur ne peut pas connaître un
   * fichier qui ne lui est jamais parvenu ; seule cette déclaration préalable
   * lui permet de constater l'écart et de refuser plutôt que d'enregistrer un
   * dossier amputé. Le manifeste n'est pas une commodité : sans lui, le socle
   * rejette tout envoi comportant des fichiers.
   *
   * @param {FormData} fd Charge en construction.
   * @return {void}
   */
  Pieces.prototype._manifeste = function ( fd ) {
    var blocs = {};
    var total = 0;
    var octets = 0;

    this.pieces.forEach( function ( piece ) {
      var code = piece[ 0 ];
      var input = document.getElementById( "file_" + code );

      if ( ! input || ! input.files || ! input.files.length ) {
        return;
      }

      var taille = 0;

      for ( var i = 0; i < input.files.length; i++ ) {
        taille += input.files[ i ].size;
      }

      blocs[ "piece_" + code ] = { count: input.files.length, size: taille };
      total += input.files.length;
      octets += taille;
    } );

    // Aucun fichier : pas de manifeste. C'est le seul cas où le socle tolère
    // son absence, et il ne peut rien cacher — une troncature de zéro fichier
    // n'existe pas.
    if ( 0 === total ) {
      return;
    }

    fd.set(
      "urbizen_manifest",
      JSON.stringify( { version: 1, total_count: total, total_size: octets, blocks: blocs } )
    );
  };

  Pieces.prototype.serialiser = function ( fd ) {
    this._manifeste( fd );

    // `pieces_differees[]` voyage nativement : les cases cochées sont dans le
    // formulaire, rien à recomposer ici.
    //
    // La déclaration cadastrale, elle, est rendue **explicite**. Les champs
    // désactivés ne voyagent pas dans un FormData : sans valeur affirmée, le
    // serveur ne pourrait pas distinguer « le client déclare ne pas savoir »
    // d'« il n'a simplement rien saisi ». Une absence ne vaut jamais report.
    ( this.informations || [] ).forEach( function ( entree ) {
      fd.set( entree.groupe.case, entree.caseGroupe.checked ? "oui" : "non" );
    } );
  };

  global.UrbizenPieces = {
    init: function (config) {
      return new Pieces(config);
    },
    MESSAGE_RASSURANT: MESSAGE_RASSURANT,
    LIBELLE_REPORT: LIBELLE_REPORT,
    TITRE_REPORT: TITRE_REPORT
  };
})(window);
