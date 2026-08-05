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

  var DELAI_INITIALISATION = 8000;

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
    this.afficherSucces = config.afficherSucces;
    // Injectable pour que les bancs puissent réellement laisser le délai
    // expirer, plutôt que de simuler son issue.
    this.delai = config.delai || DELAI_INITIALISATION;

    this.configuration = null;
    this.verrouille = false;
    this.envoiEnCours = false;

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

      if (pont.minuteur) global.clearTimeout(pont.minuteur);

      pont.bouton.disabled = false;
      pont.bouton.removeAttribute("aria-disabled");
    });
  };

  Pont.prototype._demander = function () {
    var pont = this;

    // Sans parent, le document est ouvert directement : il n'y a personne à qui
    // demander un nonce, et le bouton doit le dire.
    if (global.parent === global) {
      this._echecInitialisation();

      return;
    }

    global.parent.postMessage({ type: "urbizen_form_ready" }, global.location.origin);

    this.minuteur = global.setTimeout(function () {
      if (!pont.verrouille) pont._echecInitialisation();
    }, this.delai);
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
          issue.donnees.fields
        );
      })
      .catch(function () {
        pont._echec(MESSAGE_RESEAU);
      });
  };

  /**
   * Refus corrigeable : le formulaire reste tel quel, le bouton revient.
   */
  Pont.prototype._echec = function (message, champs) {
    this.envoiEnCours = false;
    this.bouton.disabled = false;
    this.bouton.removeAttribute("aria-busy");
    this.bouton.textContent = this.libelleInitial;

    this.erreur.textContent = message;
    this.erreur.setAttribute("tabindex", "-1");

    // Le focus va au premier champ nommé par le serveur quand il en nomme un,
    // au message sinon : dans les deux cas, la personne sait où regarder.
    var cible = null;

    if (Array.isArray(champs) && champs.length) {
      cible = this.form.querySelector('[name="' + champs[0] + '"]');
    }

    if (cible && cible.focus) {
      cible.focus();
    } else {
      this.erreur.focus();
    }
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

    (donnees.additional_projects || []).forEach(function (p) {
      var libelle = "Projet supplémentaire — " + p.label;
      if (p.description) libelle += " (" + p.description + ")";
      ligne(libelle, "+100 €", "is-detail");
    });

    (tarif.options || []).forEach(function (option) {
      if (!option.label || 0 === option.label.indexOf("Projet")) return;
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
