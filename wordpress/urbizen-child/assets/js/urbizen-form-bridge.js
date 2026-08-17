/**
 * Pont sécurisé et soumission réelle des formulaires d'autorisation.
 *
 * Le document est servi en iframe depuis un fichier statique du thème :
 * aucun PHP ne le rend, il ne peut donc pas porter un nonce. Il le demande à sa
 * page parente, qui l'a reçu du serveur.
 *
 * Le protocole tient en deux messages, et il est verrouillé aux deux bouts :
 *
 *   iframe  ──  urbizen_form_ready   ──▶  parent
 *   iframe  ◀──  urbizen_form_config  ──   parent
 *
 * Ce que cette extrémité vérifie avant d'accepter quoi que ce soit : l'origine
 * exacte, que l'émetteur est bien `window.parent`, le type du message, et la
 * présence de chaque propriété attendue sous la forme attendue. Une seule
 * configuration est retenue : une fois verrouillé, tout message ultérieur est
 * ignoré, y compris valide — sans quoi une fenêtre tierce pourrait remplacer
 * l'URL de soumission après coup.
 *
 * Tant que l'initialisation n'a pas abouti, **le bouton d'envoi reste
 * désactivé**. Il n'existe aucun mode de repli : un formulaire qui ne peut pas
 * poster ne doit pas laisser croire qu'il a envoyé quelque chose.
 *
 * Ce que le navigateur n'envoie jamais : aucun total, aucun prix unitaire,
 * aucun détail tarifaire, aucune référence. Le serveur recalcule et nomme.
 */
(function (global) {
  "use strict";

  var DELAI_INITIALISATION = 10000;

  /* ======================================================================
     MOTEUR D'ERREURS PAR CHAMP — une seule implémentation pour DP et PC
     ======================================================================

     POURQUOI ICI

     DP et PC sont deux documents statiques distincts, mais ils chargent le
     même pont. Y placer le moteur donne une implémentation unique sans
     nouveau fichier à enfiler : deux copies auraient divergé à la première
     retouche, et c'est exactement ce qui distingue déjà leurs `validateStep()`.

     CE QU'IL RÉPARE

     Le pont se contentait de `form.querySelector('[name=…]').focus()`. Le champ
     fautif d'une étape antérieure EXISTE dans le document — la sélection
     réussissait donc — mais son étape est en `display:none`, où `focus()` est
     sans effet et sans erreur. Le repli qui aurait mis le focus sur le message
     ne s'exécutait jamais, puisqu'il était conditionné à l'absence du champ.
     Résultat : message générique, focus nulle part, étape inchangée.

     L'ORDRE COMPTE

     Activer l'étape AVANT de donner le focus. Un `focus()` sur un élément non
     rendu est ignoré silencieusement par le navigateur ; l'inverser ne produit
     aucune erreur, seulement un formulaire qui semble ne rien faire.

     CE QU'IL NE DÉCIDE PAS

     Il ne sait pas ce qu'est une étape DP ou PC. Le document lui fournit
     `activerEtape` ; à défaut, il se contente de marquer les champs. C'est ce
     qui lui permet de servir aussi la validation locale, qui n'a pas d'étape à
     changer puisqu'elle valide celle qui est déjà ouverte.
     ====================================================================== */

  /* `.dp-step` et NON `[data-step]` : l'attribut est surchargé. Les sections
     l'emploient comme numéro de rubrique, mais les champs de mesure l'emploient
     comme pas numérique (`data-step="0.01"`). Un `closest("[data-step]")` depuis
     un champ de bassin renvoyait donc le champ lui-même, jamais sa rubrique —
     et l'étape n'était jamais ouverte. La classe, elle, ne désigne qu'une
     rubrique. */
  var SELECTEUR_ETAPE = ".dp-step";
  var SELECTEUR_CONTROLE = "input, select, textarea";

  /**
   * @param {HTMLFormElement} form
   * @param {Object}          [options]
   * @param {Function}        [options.activerEtape] Reçoit l'élément d'étape à ouvrir.
   * @param {HTMLElement}     [options.resume]       Zone de résumé, si le document en a une.
   */
  function Moteur(form, options) {
    this.form = form;
    this.options = options || {};
    // Toutes les erreurs connues, y compris celles des étapes non ouvertes :
    // elles doivent survivre au changement d'étape pour apparaître quand la
    // personne y arrive.
    this.messages = {};
    this._brancherCorrection();
  }

  /** Les contrôles portant ce nom, groupes radio compris. */
  Moteur.prototype._controles = function (nom) {
    try {
      return this.form.querySelectorAll('[name="' + nom + '"]');
    } catch (e) {
      return [];
    }
  };

  /**
   * Le réceptacle de message d'un champ — créé s'il manque.
   *
   * Les documents n'en portent que sur une poignée de champs : la rubrique des
   * mesures de bassin, et rien d'autre. Se contenter de ceux qui existent
   * reviendrait à poser `aria-invalid` sans avoir où écrire la phrase — un
   * champ signalé comme invalide, sans dire pourquoi, pour la grande majorité
   * des cas. On le fabrique donc à la demande, dans le groupe du champ, avec
   * les mêmes attributs que ceux du document.
   */
  Moteur.prototype._zone = function (nom) {
    var existante;

    try {
      existante = this.form.querySelector('[data-erreur-pour="' + nom + '"]');
    } catch (e) {
      return null;
    }

    if (existante) return existante;

    var controles = this._controles(nom);
    if (!controles.length) return null;

    // Le groupe du champ, ou à défaut son parent direct : le message doit
    // rester à côté de ce qu'il commente, jamais en fin de formulaire.
    var hote =
      (controles[0].closest && controles[0].closest(".dp-field")) ||
      controles[0].parentNode;

    if (!hote || !hote.appendChild) return null;

    var zone = this.form.ownerDocument.createElement("p");
    zone.className = "dp-err";
    zone.setAttribute("data-erreur-pour", nom);
    zone.setAttribute("role", "alert");
    zone.hidden = true;
    hote.appendChild(zone);

    return zone;
  };

  /** Un contrôle est-il réellement rendu ? `offsetParent` suffit ici : aucun
      champ de ces formulaires n'est en `position: fixed`. */
  function visible(el) {
    return !!(el && el.offsetParent !== null && !el.disabled);
  }

  Moteur.prototype.etapeDe = function (el) {
    return el && el.closest ? el.closest(SELECTEUR_ETAPE) : null;
  };

  /**
   * Enregistre une erreur et la rend visible si son étape est ouverte.
   */
  Moteur.prototype.poser = function (nom, message) {
    if (!nom || !message) return;

    this.messages[nom] = message;

    var zone = this._zone(nom);
    var controles = this._controles(nom);
    var i;

    if (zone) {
      if (!zone.id) zone.id = "err-" + String(nom).replace(/[^a-zA-Z0-9_-]/g, "-");
      zone.textContent = message;
      zone.hidden = false;
      zone.setAttribute("role", "alert");
    }

    for (i = 0; i < controles.length; i++) {
      controles[i].setAttribute("aria-invalid", "true");

      if (zone && zone.id) {
        // `aria-describedby` peut déjà désigner une aide : on complète, on ne
        // remplace pas — sinon l'aide disparaîtrait au premier échec.
        var decrit = (controles[i].getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
        if (decrit.indexOf(zone.id) === -1) decrit.push(zone.id);
        controles[i].setAttribute("aria-describedby", decrit.join(" "));
      }
    }
  };

  /** Retire l'erreur d'un champ — appelé dès que la personne le corrige. */
  Moteur.prototype.effacer = function (nom) {
    if (!Object.prototype.hasOwnProperty.call(this.messages, nom)) return;

    delete this.messages[nom];

    var zone = this._zone(nom);
    var controles = this._controles(nom);
    var i;

    if (zone) {
      zone.textContent = "";
      zone.hidden = true;
    }

    for (i = 0; i < controles.length; i++) {
      controles[i].removeAttribute("aria-invalid");
      controles[i].style.borderColor = "";

      if (zone && zone.id) {
        var reste = (controles[i].getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(function (id) { return id && id !== zone.id; });
        if (reste.length) controles[i].setAttribute("aria-describedby", reste.join(" "));
        else controles[i].removeAttribute("aria-describedby");
      }
    }
  };

  Moteur.prototype.effacerTout = function () {
    var noms = Object.keys(this.messages);
    for (var i = 0; i < noms.length; i++) this.effacer(noms[i]);
    this.resumer("");
  };

  Moteur.prototype.nombre = function () {
    return Object.keys(this.messages).length;
  };

  /** Le premier contrôle fautif du document, dans l'ordre du document. */
  Moteur.prototype.premierFautif = function () {
    var champs = this.form.querySelectorAll(SELECTEUR_CONTROLE);

    for (var i = 0; i < champs.length; i++) {
      var nom = champs[i].getAttribute("name");
      if (nom && Object.prototype.hasOwnProperty.call(this.messages, nom)) return champs[i];
    }

    return null;
  };

  /** Écrit le résumé, s'il y a une zone pour l'accueillir. */
  Moteur.prototype.resumer = function (texte) {
    var zone = this.options.resume;
    if (!zone) return;

    zone.textContent = texte;
    zone.hidden = "" === texte;
  };

  /** Le libellé public d'un champ : il vit dans le document, pas en PHP. */
  Moteur.prototype.libelle = function (nom) {
    var controles = this._controles(nom);
    if (!controles.length) return nom;

    var groupe = controles[0].closest ? controles[0].closest(".dp-field") : null;
    var etiquette = groupe ? groupe.querySelector("label, legend") : null;

    return etiquette ? etiquette.textContent.replace(/\s+/g, " ").trim() : nom;
  };

  /**
   * Applique une liste d'erreurs venue du serveur, puis conduit la personne
   * jusqu'à la première.
   *
   * @param {Array} liste  Entrées `{ field, message }`.
   * @returns {number} Nombre d'erreurs retenues.
   */
  Moteur.prototype.appliquer = function (liste) {
    var moteur = this;

    this.effacerTout();

    if (!Array.isArray(liste)) return 0;

    liste.forEach(function (entree) {
      if (!entree || "string" !== typeof entree.field) return;
      var message = "string" === typeof entree.message && "" !== entree.message ? entree.message : null;
      if (message) moteur.poser(entree.field, message);
    });

    var total = this.nombre();

    if (0 === total) return 0;

    this.resumer(
      1 === total
        ? "1 information doit être corrigée avant l’envoi."
        : total + " informations doivent être corrigées avant l’envoi."
    );

    this.conduireAuPremier();

    return total;
  };

  /**
   * Ouvre l'étape de la première erreur, puis y met le focus.
   *
   * L'ordre des cinq gestes n'est pas indifférent : activer, laisser le
   * document se rendre, vérifier que le contrôle est bien visible, faire
   * défiler, puis seulement donner le focus.
   */
  Moteur.prototype.conduireAuPremier = function () {
    var cible = this.premierFautif();
    if (!cible) return;

    var etape = this.etapeDe(cible);

    if (etape && "function" === typeof this.options.activerEtape) {
      this.options.activerEtape(etape);
    }

    // Après activation seulement : un contrôle encore masqué ne prend pas le
    // focus, et l'appel échouerait sans rien dire.
    if (!visible(cible)) {
      var zone = this._zone(cible.getAttribute("name")) || this.options.resume;
      if (zone && zone.focus) {
        zone.setAttribute("tabindex", "-1");
        zone.focus();
      }
      return;
    }

    if (cible.scrollIntoView) {
      cible.scrollIntoView({ block: "center", behavior: "smooth" });
    }

    cible.focus();
  };

  /**
   * Une erreur disparaît dès que la personne touche au champ. Sans cela, le
   * message resterait après correction et invaliderait le résumé.
   */
  Moteur.prototype._brancherCorrection = function () {
    var moteur = this;

    ["input", "change"].forEach(function (type) {
      moteur.form.addEventListener(
        type,
        function (evenement) {
          var nom = evenement.target && evenement.target.getAttribute
            ? evenement.target.getAttribute("name")
            : null;

          if (!nom || !Object.prototype.hasOwnProperty.call(moteur.messages, nom)) return;

          moteur.effacer(nom);

          var reste = moteur.nombre();
          moteur.resumer(
            0 === reste
              ? ""
              : 1 === reste
                ? "1 information doit être corrigée avant l’envoi."
                : reste + " informations doivent être corrigées avant l’envoi."
          );
        },
        true
      );
    });
  };

  global.UrbizenErreurs = {
    creer: function (form, options) {
      return new Moteur(form, options);
    }
  };

  /**
   * Temporisation des demandes de configuration, en millisecondes.
   *
   * Rien ne garantit que la page parente ait installé son écouteur au moment où
   * ce document est prêt : son script est chargé en pied de page, et un cadre
   * servi depuis le cache peut être prêt bien avant. Une demande unique perdue
   * laissait le formulaire définitivement inerte.
   *
   * Les premières répétitions sont serrées — le cas courant se règle en
   * quelques centaines de millisecondes — puis l'intervalle se stabilise à la
   * seconde jusqu'au délai maximal. Le but n'est pas d'insister longtemps, mais
   * de couvrir une fenêtre de course qui se compte en dizaines de
   * millisecondes.
   */
  var RELANCES = [0, 100, 250, 500, 1000];

  /** Intervalle des relances au-delà de la temporisation initiale. */
  var RELANCE_REGULIERE = 1000;

  var MESSAGE_INIT =
    "Le formulaire n’a pas pu être initialisé. Veuillez actualiser la page ou nous contacter.";

  var MESSAGE_RESEAU =
    "Votre demande n’a pas pu être envoyée. Vérifiez votre connexion puis réessayez.";

  var MENTION =
    "Estimation indicative. Le tarif définitif sera confirmé par Urbizen " +
    "après vérification de votre projet, avant toute commande.";

  function el(tag, classe, texte) {
    var noeud = document.createElement(tag);
    if (classe) noeud.className = classe;
    if (texte !== undefined && texte !== null) noeud.textContent = texte;
    return noeud;
  }

  function euros(montant) {
    return montant + " €";
  }

  /**
   * Une configuration reçue a-t-elle exactement la forme attendue ?
   *
   * Chaque propriété est contrôlée : une configuration partielle laisserait
   * poster une requête incomplète, refusée par le serveur, et le client n'y
   * comprendrait rien.
   */
  function configurationValide(c) {
    if (!c || "object" !== typeof c) return false;

    var requis = ["action", "formType", "nonceField", "nonce", "submitUrl"];

    for (var i = 0; i < requis.length; i++) {
      if ("string" !== typeof c[requis[i]] || "" === c[requis[i]]) return false;
    }

    return true;
  }

  /**
   * @param {Object} config
   * @param {HTMLFormElement} config.form
   * @param {HTMLButtonElement} config.bouton   Bouton d'envoi.
   * @param {HTMLElement} config.erreur         Zone d'erreur de l'étape finale.
   * @param {Function} config.serialiser        Ajoute les champs dérivés au FormData.
   * @param {Function} config.afficherSucces    Reçoit la réponse serveur validée.
   */
  function Pont(config) {
    this.form = config.form;
    this.bouton = config.bouton;
    this.erreur = config.erreur;
    this.serialiser = config.serialiser || function () {};
    this.surMatrice = config.surMatrice || null;
    this.afficherSucces = config.afficherSucces;
    // Injectable pour que les bancs puissent réellement laisser le délai
    // expirer, plutôt que de simuler son issue.
    this.delai = config.delai || DELAI_INITIALISATION;

    this.configuration = null;
    this.verrouille = false;
    this.envoiEnCours = false;
    this.minuteurs = [];

    // Le moteur d'erreurs est partagé avec la validation locale du document :
    // celui-ci le fournit s'il en tient déjà un, sinon le pont en crée un. Deux
    // instances marqueraient les mêmes champs sans se voir, et l'une effacerait
    // les marques de l'autre.
    this.moteur =
      config.moteur ||
      (global.UrbizenErreurs
        ? global.UrbizenErreurs.creer(config.form, {
            activerEtape: config.activerEtape || null,
            resume: config.resume || null
          })
        : null);

    this._preparerBouton();
    this._ecouter();
    this._demander();
  }

  /** Le bouton part désactivé : rien ne peut être envoyé avant le nonce. */
  Pont.prototype._preparerBouton = function () {
    this.libelleInitial = this.bouton.textContent;
    this.bouton.disabled = true;
    this.bouton.setAttribute("aria-disabled", "true");

    if (this.erreur) {
      this.erreur.setAttribute("role", "alert");
      this.erreur.setAttribute("aria-live", "assertive");
    }
  };

  Pont.prototype._ecouter = function () {
    var pont = this;

    global.addEventListener("message", function (event) {
      // Une configuration déjà acceptée ne se remplace pas : sinon une fenêtre
      // tierce pourrait détourner l'URL de soumission après l'initialisation.
      if (pont.verrouille) return;

      // Même origine, et strictement la fenêtre parente.
      if (event.origin !== global.location.origin) return;
      if (event.source !== global.parent) return;

      var message = event.data;

      if (!message || "object" !== typeof message) return;
      if ("urbizen_form_config" !== message.type) return;
      if (!configurationValide(message)) return;

      pont.configuration = message;
      pont.verrouille = true;

      pont._arreterRelances();

      // Accusé de réception : le parent cesse alors de renvoyer. Sans lui, il
      // continuerait d'émettre à chaque `load` et à chaque demande, sans savoir
      // que le document est servi.
      if (global.parent !== global) {
        global.parent.postMessage({ type: "urbizen_form_configured" }, global.location.origin);
      }

      pont.bouton.disabled = false;
      pont.bouton.removeAttribute("aria-disabled");

      // La matrice métier arrive avec la configuration : elle vient du serveur,
      // et c'est ce qui garantit que l'interface et lui s'accordent.
      if (pont.surMatrice) pont.surMatrice(message.matrice, message.champsConditionnels);
    });
  };

  /** Éteint toutes les répétitions en attente. */
  Pont.prototype._arreterRelances = function () {
    for (var i = 0; i < this.minuteurs.length; i++) {
      global.clearTimeout(this.minuteurs[i]);
      global.clearInterval(this.minuteurs[i]);
    }

    this.minuteurs = [];
  };

  Pont.prototype._demander = function () {
    var pont = this;

    // Sans parent, le document est ouvert directement : il n'y a personne à qui
    // demander un nonce, et le bouton doit le dire.
    if (global.parent === global) {
      this._echecInitialisation();

      return;
    }

    var demander = function () {
      // Une configuration déjà acceptée n'a plus rien à demander. Le contrôle
      // est ici et non seulement à la pose des minuteurs : une relance peut
      // avoir été programmée juste avant l'acceptation.
      if (pont.verrouille) return;

      global.parent.postMessage({ type: "urbizen_form_ready" }, global.location.origin);
    };

    demander();

    for (var i = 1; i < RELANCES.length; i++) {
      this.minuteurs.push(global.setTimeout(demander, RELANCES[i]));
    }

    // Au-delà de la temporisation initiale, une relance par seconde jusqu'au
    // délai maximal. L'intervalle est éteint en même temps que le reste.
    this.minuteurs.push(global.setInterval(demander, RELANCE_REGULIERE));

    this.minuteurs.push(
      global.setTimeout(function () {
        pont._arreterRelances();

        if (!pont.verrouille) pont._echecInitialisation();
      }, this.delai)
    );
  };

  Pont.prototype._echecInitialisation = function () {
    this.bouton.disabled = true;
    this.bouton.setAttribute("aria-disabled", "true");

    if (!this.erreur) return;

    this.erreur.textContent = MESSAGE_INIT;
    this.erreur.setAttribute("tabindex", "-1");
    this.erreur.focus();
  };

  Pont.prototype.pret = function () {
    return this.verrouille && null !== this.configuration;
  };

  /**
   * Envoie la demande, et n'affiche l'écran final que sur un succès réel.
   */
  Pont.prototype.envoyer = function () {
    var pont = this;

    if (!this.pret() || this.envoiEnCours) return;

    this.envoiEnCours = true;
    this.erreur.textContent = "";
    this.bouton.disabled = true;
    this.bouton.setAttribute("aria-busy", "true");
    this.bouton.textContent = "Envoi en cours…";

    var fd = new FormData(this.form);

    // Les champs que la route exige, jamais devinés par le formulaire.
    fd.set("action", this.configuration.action);
    fd.set("form_type", this.configuration.formType);
    fd.set(this.configuration.nonceField, this.configuration.nonce);

    // Le jeton anti-robot est signé et horodaté par le serveur, comme le nonce.
    // Le document ne peut pas le produire : c'est un fichier statique. Sans
    // lui, la route refuse — et aucun envoi depuis un navigateur n'aboutit.
    if (this.configuration.tokenField && this.configuration.token) {
      fd.set(this.configuration.tokenField, this.configuration.token);
    }

    // Le pot de miel part vide. Il n'est renseigné que par un automate qui
    // remplit tout ce qu'il trouve — c'est précisément ce qu'il détecte.
    if (this.configuration.honeypotField && !fd.has(this.configuration.honeypotField)) {
      fd.set(this.configuration.honeypotField, "");
    }

    // Les champs dérivés — projets répétés, descriptions, report cadastral.
    this.serialiser(fd);

    // Pas de `Content-Type` : le navigateur compose la frontière multipart.
    // Les fichiers voyagent tels quels, jamais encodés en JSON ni en base64.
    fetch(this.configuration.submitUrl, {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { Accept: "application/json" }
    })
      .then(function (reponse) {
        return reponse
          .json()
          .catch(function () {
            // Réponse non JSON — page d'erreur, redirection HTML inattendue.
            return null;
          })
          .then(function (donnees) {
            return { ok: reponse.ok, donnees: donnees };
          });
      })
      .then(function (issue) {
        if (!issue.donnees || "object" !== typeof issue.donnees) {
          pont._echec(MESSAGE_RESEAU);

          return;
        }

        if (issue.ok && true === issue.donnees.success && "string" === typeof issue.donnees.reference && "" !== issue.donnees.reference) {
          pont.envoiEnCours = false;
          pont.afficherSucces(issue.donnees);

          return;
        }

        // Message public du serveur, ou repli neutre. Jamais un code, jamais
        // une trace.
        pont._echec(
          "string" === typeof issue.donnees.message && "" !== issue.donnees.message
            ? issue.donnees.message
            : MESSAGE_RESEAU,
          issue.donnees.fields,
          issue.donnees.errors
        );
      })
      .catch(function () {
        pont._echec(MESSAGE_RESEAU);
      });
  };

  /**
   * Refus corrigeable : le formulaire reste tel quel, le bouton revient.
   *
   * Trois sources d'information, par ordre de précision décroissante :
   *
   *   1. `errors` — un message public par champ. C'est le seul cas où l'on peut
   *      dire ce qui ne va pas, et où la personne peut corriger sans deviner.
   *   2. `fields` — les noms seuls, conservés pour un serveur non encore à jour.
   *      On nomme alors les champs par leur libellé lu dans le document, faute
   *      de mieux : c'est moins bon qu'un message, c'est mieux que rien.
   *   3. Ni l'un ni l'autre — message global seul, focus sur ce message.
   *
   * @param {string} message Message global public.
   * @param {Array}  champs  Noms des champs en erreur (compatibilité).
   * @param {Array}  erreurs Entrées `{ field, message }`, quand le serveur les donne.
   */
  Pont.prototype._echec = function (message, champs, erreurs) {
    this.envoiEnCours = false;
    this.bouton.disabled = false;
    this.bouton.removeAttribute("aria-busy");
    this.bouton.textContent = this.libelleInitial;

    this.erreur.textContent = message;
    this.erreur.setAttribute("tabindex", "-1");

    var moteur = this.moteur;

    if (moteur && Array.isArray(erreurs) && erreurs.length) {
      if (moteur.appliquer(erreurs) > 0) return;
    }

    // Repli : le serveur n'a nommé que les champs. On fabrique un message par
    // champ à partir de son libellé, pour au moins conduire au bon endroit.
    if (moteur && Array.isArray(champs) && champs.length) {
      var liste = champs.map(function (nom) {
        return { field: nom, message: "« " + moteur.libelle(nom) + " » doit être vérifié." };
      });

      if (moteur.appliquer(liste) > 0) return;
    }

    this.erreur.focus();
  };

  /**
   * Rend le récapitulatif de l'écran final à partir de la réponse serveur.
   *
   * Aucune valeur n'est recalculée ici : tout vient de ce que le serveur a
   * effectivement enregistré.
   */
  function rendreConfirmation(cible, donnees) {
    cible.textContent = "";

    var bloc = el("div", "dp-recap");
    var lignes = el("dl", "dp-recap-lignes");

    function ligne(libelle, valeur, classe) {
      var l = el("div", "dp-recap-ligne" + (classe ? " " + classe : ""));
      l.appendChild(el("dt", null, libelle));
      l.appendChild(el("dd", null, valeur));
      lignes.appendChild(l);
    }

    var tarif = donnees.pricing || {};
    var projet = donnees.project || {};

    if (projet.label) {
      ligne("Projet principal · " + projet.label, null === tarif.total && !tarif.base ? "Tarif sur étude" : euros(tarif.base || 0));
    }

    // Les projets supplémentaires sont rendus depuis `additional_projects`,
    // qui porte leur description. Le tarif les porte AUSSI, sous leur seul
    // libellé de nature — et les rendre deux fois affichait « Piscine » sur
    // deux lignes, dont une sans sa description. On retient donc les libellés
    // déjà rendus pour les écarter du détail tarifaire.
    var dejaRendus = {};

    (donnees.additional_projects || []).forEach(function (p) {
      var libelle = "Projet supplémentaire — " + p.label;
      if (p.description) libelle += " (" + p.description + ")";
      ligne(libelle, "+100 €", "is-detail");
      dejaRendus[p.label] = true;
    });

    (tarif.options || []).forEach(function (option) {
      if (!option.label || dejaRendus[option.label]) return;
      ligne(option.label, euros(option.amount));
    });

    bloc.appendChild(lignes);

    var total = el("p", "dp-recap-total");
    total.appendChild(el("span", null, "Total estimé"));
    total.appendChild(
      el("strong", null, null === tarif.total || undefined === tarif.total ? "Tarif sur étude" : euros(tarif.total))
    );
    bloc.appendChild(total);
    bloc.appendChild(el("p", "dp-recap-mention", MENTION));

    cible.appendChild(bloc);
  }

  /**
   * Rend la liste des éléments restant à transmettre.
   */
  function rendreDifferes(cible, donnees) {
    cible.textContent = "";

    var pieces = donnees.deferred_documents || [];
    var cadastre = true === donnees.deferred_cadastral_information;

    if (!pieces.length && !cadastre) return;

    var bloc = el("div", "dp-pieces-differees");
    bloc.appendChild(el("p", "dp-pieces-differees-titre", "À transmettre ultérieurement"));

    if (cadastre) {
      bloc.appendChild(el("p", "dp-pieces-differees-info", "Informations cadastrales : à compléter ultérieurement"));
    }

    if (pieces.length) {
      var liste = el("ul", "dp-pieces-differees-liste");
      pieces.forEach(function (piece) {
        liste.appendChild(el("li", null, piece.label));
      });
      bloc.appendChild(liste);
    }

    bloc.appendChild(
      el(
        "p",
        "dp-pieces-differees-note",
        "Ces éléments ne bloquent pas l’instruction de votre demande. Urbizen les vérifiera ou vous les demandera après réception."
      )
    );

    cible.appendChild(bloc);
  }

  global.UrbizenPont = {
    init: function (config) {
      return new Pont(config);
    },
    rendreConfirmation: rendreConfirmation,
    rendreDifferes: rendreDifferes,
    MESSAGE_INIT: MESSAGE_INIT,
    MESSAGE_RESEAU: MESSAGE_RESEAU,
    DELAI_INITIALISATION: DELAI_INITIALISATION
  };
})(window);
