/* ============================================================================
   urbizen-form.js
   Pont entre le composant cadastre et le formulaire Urbizen.

   Ce fichier ne dépend PAS de urbizen-cadastre.js : les deux communiquent par
   l'événement `urbizen:parcel-confirmed` et par sessionStorage. Un formulaire
   fonctionne donc sur une page sans carte, et l'ordre de montage est
   indifférent — l'écoute est posée au montage, et le stockage est relu
   immédiatement.

   ── Version 0.4.0 : aucune transmission ──
   La validation est entièrement locale. Ce fichier ne contient ni fetch, ni
   XMLHttpRequest, ni sendBeacon, ni soumission HTML. Le résultat validé est
   publié par `urbizen:location-form-validated`, à charge de l'hôte d'en faire
   quelque chose. Le futur point de soumission serveur devra tout revalider :
   les champs masqués ne sont pas dignes de confiance.

   ── Vie privée ──
   Aucune donnée n'est écrite dans la console, y compris en cas d'erreur : les
   messages de diagnostic ne contiennent ni adresse, ni coordonnées, ni
   référence cadastrale. sessionStorage n'est jamais effacé automatiquement
   par la reprise ni par la validation — seulement par une action explicite.
   ========================================================================== */
(function (global) {
  "use strict";

  var SCHEMA_VERSION = "1.0";
  var STORAGE_PREFIX = "urbizen:";

  /* Chemins autorisés dans le contrat. Un `data-from` absent de cette liste
     est ignoré : le payload ne choisit jamais ce qui est lu. */
  var CHEMINS = {
    "schemaVersion": 1, "source": 1, "confirmedAt": 1,
    "address.label": 1, "address.houseNumber": 1, "address.street": 1,
    "address.postcode": 1, "address.city": 1, "address.cityCode": 1,
    "location.latitude": 1, "location.longitude": 1,
    "parcel.communeCode": 1, "parcel.prefix": 1, "parcel.section": 1,
    "parcel.number": 1, "parcel.id": 1, "parcel.surfaceM2": 1
  };

  var MAX_CHAINE = 300;
  var SURFACE_MAX = 10000000;   // 10 km², au-delà c'est une aberration de saisie

  var instanceSeq = 0;

  /* --- Utilitaires DOM : jamais d'innerHTML sur une donnée --- */
  function texte(node, valeur) {
    while (node.firstChild) { node.removeChild(node.firstChild); }
    if (valeur != null && valeur !== "") { node.appendChild(document.createTextNode(String(valeur))); }
  }

  function cleStockage(cle) {
    if (!cle) { return STORAGE_PREFIX + "parcel"; }
    return cle.indexOf(STORAGE_PREFIX) === 0 ? cle : STORAGE_PREFIX + cle;
  }

  /* --- Normalisation --- */
  function chaine(v, max) {
    if (v == null) { return ""; }
    if (typeof v === "object") { return ""; }   // refuse tableaux et objets
    var s = String(v).trim();
    var limite = max || MAX_CHAINE;
    return s.length > limite ? s.slice(0, limite) : s;
  }

  function nombre(v) {
    if (v === null || v === undefined || v === "" || typeof v === "object") { return null; }
    var n = Number(v);
    return Number.isFinite(n) ? n : null;
  }

  /* Clés interdites : un payload ne doit jamais pouvoir toucher au prototype. */
  function estCleDangereuse(k) {
    return k === "__proto__" || k === "constructor" || k === "prototype";
  }

  /* Lecture d'un chemin, sans jamais traverser une clé dangereuse. */
  function lire(objet, chemin) {
    if (!objet || !Object.prototype.hasOwnProperty.call(CHEMINS, chemin)) { return undefined; }
    var parts = chemin.split(".");
    var courant = objet;
    for (var i = 0; i < parts.length; i++) {
      if (estCleDangereuse(parts[i])) { return undefined; }
      if (courant === null || typeof courant !== "object") { return undefined; }
      if (!Object.prototype.hasOwnProperty.call(courant, parts[i])) { return undefined; }
      courant = courant[parts[i]];
    }
    return courant;
  }

  /* Reconstruit un contrat propre à partir d'un objet reçu. Ne conserve que
     les chemins connus ; ne fabrique aucune valeur. */
  function normaliserContrat(brut) {
    if (!brut || typeof brut !== "object" || Array.isArray(brut)) { return null; }

    var c = {
      schemaVersion: chaine(lire(brut, "schemaVersion"), 10) || SCHEMA_VERSION,
      source: chaine(lire(brut, "source"), 40),
      confirmedAt: chaine(lire(brut, "confirmedAt"), 40),
      address: {
        label: chaine(lire(brut, "address.label"), 300),
        houseNumber: chaine(lire(brut, "address.houseNumber"), 20),
        street: chaine(lire(brut, "address.street"), 200),
        postcode: chaine(lire(brut, "address.postcode"), 10),
        city: chaine(lire(brut, "address.city"), 120),
        cityCode: chaine(lire(brut, "address.cityCode"), 10)
      },
      location: {
        latitude: nombre(lire(brut, "location.latitude")),
        longitude: nombre(lire(brut, "location.longitude"))
      },
      parcel: {
        communeCode: chaine(lire(brut, "parcel.communeCode"), 10),
        prefix: chaine(lire(brut, "parcel.prefix"), 10),
        section: chaine(lire(brut, "parcel.section"), 10),
        number: chaine(lire(brut, "parcel.number"), 10),
        id: chaine(lire(brut, "parcel.id"), 20),
        surfaceM2: nombre(lire(brut, "parcel.surfaceM2"))
      }
    };

    /* Coordonnées hors des bornes terrestres : rejetées, pas corrigées. */
    if (c.location.latitude !== null && (c.location.latitude < -90 || c.location.latitude > 90)) {
      c.location.latitude = null;
    }
    if (c.location.longitude !== null && (c.location.longitude < -180 || c.location.longitude > 180)) {
      c.location.longitude = null;
    }
    if (c.parcel.surfaceM2 !== null && c.parcel.surfaceM2 < 0) {
      c.parcel.surfaceM2 = null;
    }

    return c;
  }

  /* Un contrat est exploitable s'il porte au moins une localisation lisible. */
  function contratUtilisable(c) {
    if (!c) { return false; }
    return c.address.label !== "" || (c.parcel.section !== "" && c.parcel.number !== "");
  }

  /* ==========================================================================
     Instance de formulaire
     ========================================================================== */
  function Formulaire(racine) {
    this.root = racine;
    this.uid = "uf-" + (++instanceSeq);
    this.storageKey = cleStockage(racine.getAttribute("data-storage-key"));
    this.formType = racine.getAttribute("data-form-type") || "localisation";
    this.formId = racine.getAttribute("data-form-id") || "";
    this.contrat = null;

    this.$form = racine.querySelector(".uf-form");
    this.$summary = racine.querySelector(".uf-summary");
    this.$summaryAddress = racine.querySelector(".uf-summary-address");
    this.$summaryParcel = racine.querySelector(".uf-summary-parcel");
    this.$notice = racine.querySelector(".uf-notice");
    this.$result = racine.querySelector(".uf-result");
    this.$edit = racine.querySelector(".uf-edit");
    this.$clear = racine.querySelector(".uf-clear");

    this._identifiantsUniques();
    this._bind();
    this._reprendreDepuisStockage();
  }

  /* Les identifiants rendus par PHP sont communs à toutes les instances :
     on les rend uniques ici pour que plusieurs formulaires cohabitent. */
  Formulaire.prototype._identifiantsUniques = function () {
    var self = this;
    var champs = this.root.querySelectorAll(".uf-input");
    for (var i = 0; i < champs.length; i++) {
      var ancien = champs[i].id;
      if (!ancien) { continue; }
      var nouveau = self.uid + "-" + ancien;
      var label = self.root.querySelector('label[for="' + ancien + '"]');
      var note = self.root.querySelector('#' + ancien + '-note');
      champs[i].id = nouveau;
      if (label) { label.setAttribute("for", nouveau); }
      if (note) {
        note.id = nouveau + "-note";
        champs[i].setAttribute("aria-describedby", nouveau + "-note");
      }
    }
  };

  Formulaire.prototype._bind = function () {
    var self = this;

    /* Écoute posée AU MONTAGE : un cadastre monté plus tard sera entendu. */
    this._onConfirm = function (e) {
      var c = e && e.detail;
      if (!c) { return; }
      /* Ciblage déterministe : on n'accepte que la clé de cette instance.
         Une confirmation sans clé (hôte tiers) est acceptée par défaut. */
      if (c.storageKey && cleStockage(c.storageKey) !== self.storageKey) { return; }
      self._appliquer(normaliserContrat(c), "evenement");
    };
    document.addEventListener("urbizen:parcel-confirmed", this._onConfirm);

    if (this.$form) {
      this.$form.addEventListener("submit", function (ev) {
        ev.preventDefault();     // aucune soumission HTML en 0.4.0
        self._valider();
      });
    }

    if (this.$edit) {
      this.$edit.addEventListener("click", function () { self._demanderCorrection(); });
    }

    if (this.$clear) {
      this.$clear.addEventListener("click", function () { self._effacer(); });
    }
  };

  /* --- Reprise depuis sessionStorage, ciblée sur LA clé de cette instance ---
     Aucun parcours de l'ensemble des clés `urbizen:*` : le choix d'une
     parcelle ne doit jamais être arbitraire. */
  Formulaire.prototype._reprendreDepuisStockage = function () {
    var brut = null;
    try { brut = sessionStorage.getItem(this.storageKey); }
    catch (e) { return; }                 // stockage indisponible : non bloquant
    if (!brut) { return; }
    var objet = null;
    try { objet = JSON.parse(brut); } catch (e) { return; }
    this._appliquer(normaliserContrat(objet), "stockage");
  };

  /* --- Application d'un contrat aux champs --- */
  Formulaire.prototype._appliquer = function (contrat, origine) {
    if (!contratUtilisable(contrat)) { return false; }

    this.contrat = contrat;

    var champs = this.root.querySelectorAll("[data-from]");
    for (var i = 0; i < champs.length; i++) {
      var chemin = champs[i].getAttribute("data-from");
      if (!Object.prototype.hasOwnProperty.call(CHEMINS, chemin)) { continue; }
      var valeur = lire(contrat, chemin);
      /* La surface n'est proposée que si elle existe : on ne préremplit
         jamais un champ avec une valeur fabriquée. */
      champs[i].value = (valeur === null || valeur === undefined) ? "" : String(valeur);
    }

    this._afficherResume();
    this._verifierCodesCommune();
    this.root.setAttribute("data-uf-origine", origine);
    return true;
  };

  Formulaire.prototype._afficherResume = function () {
    if (!this.$summary || !this.contrat) { return; }
    var a = this.contrat.address;
    var p = this.contrat.parcel;

    texte(this.$summaryAddress, a.label || [a.postcode, a.city].filter(Boolean).join(" "));

    var ref = [];
    if (p.section) { ref.push("section " + p.section); }
    if (p.number) { ref.push("parcelle n° " + p.number); }
    if (p.surfaceM2 !== null) { ref.push(p.surfaceM2 + " m² (surface cadastrale indicative)"); }
    texte(this.$summaryParcel, ref.join(" · "));

    this.$summary.hidden = false;
  };

  /* Divergence des deux codes commune : signalée, jamais corrigée. */
  Formulaire.prototype._verifierCodesCommune = function () {
    if (!this.$notice || !this.contrat) { return; }
    var a = this.contrat.address.cityCode;
    var p = this.contrat.parcel.communeCode;
    if (a && p && a !== p) {
      texte(this.$notice, "Le code commune de l’adresse et celui de la parcelle diffèrent. Vérifiez la commune indiquée avant de valider.");
      this.$notice.hidden = false;
      this.root.setAttribute("data-uf-commune-divergente", "1");
    } else {
      this.$notice.hidden = true;
      this.root.removeAttribute("data-uf-commune-divergente");
    }
  };

  /* --- Correction d'adresse : découplée, par événement --- */
  Formulaire.prototype._demanderCorrection = function () {
    /* Les données restent en place tant qu'une nouvelle parcelle n'est pas
       confirmée : on ne vide ni les champs, ni le stockage. */
    document.dispatchEvent(new CustomEvent("urbizen:cadastre-edit-requested", {
      bubbles: true,
      detail: { storageKey: this.storageKey, formId: this.formId }
    }));
  };

  /* --- Effacement explicite, à la demande de la personne --- */
  Formulaire.prototype._effacer = function () {
    try { sessionStorage.removeItem(this.storageKey); } catch (e) {}
    var champs = this.root.querySelectorAll("[data-from]");
    for (var i = 0; i < champs.length; i++) { champs[i].value = ""; }
    this.contrat = null;
    if (this.$summary) { this.$summary.hidden = true; }
    if (this.$notice) { this.$notice.hidden = true; }
    this.root.removeAttribute("data-uf-origine");
    this.root.removeAttribute("data-uf-commune-divergente");
    this._resultat("Vos données de localisation ont été effacées de cet onglet.", "info");
  };

  /* --- Validation locale --- */
  Formulaire.prototype._valider = function () {
    var erreurs = [];
    var valeurs = {};

    var champs = this.root.querySelectorAll("[data-from]");
    for (var i = 0; i < champs.length; i++) {
      var champ = champs[i];
      var chemin = champ.getAttribute("data-from");
      if (!Object.prototype.hasOwnProperty.call(CHEMINS, chemin)) { continue; }
      valeurs[chemin] = champ.value;
      this._effacerErreurChamp(champ);
    }

    /* Champs obligatoires : présence seulement, aucun contenu recopié. */
    var obligatoires = this.root.querySelectorAll(".uf-input[required]");
    for (var j = 0; j < obligatoires.length; j++) {
      if (chaine(obligatoires[j].value) === "") {
        erreurs.push("champ-obligatoire");
        this._erreurChamp(obligatoires[j], "Ce champ est nécessaire.");
      }
    }

    /* Surface : nombre fini, positif, plafonné. Décimales acceptées. */
    var champSurface = this.root.querySelector('[data-from="parcel.surfaceM2"]');
    var surface = null;
    if (champSurface && chaine(champSurface.value) !== "") {
      surface = nombre(champSurface.value);
      if (surface === null) {
        erreurs.push("surface-non-numerique");
        this._erreurChamp(champSurface, "Indiquez un nombre.");
      } else if (surface < 0) {
        erreurs.push("surface-negative");
        this._erreurChamp(champSurface, "La surface ne peut pas être négative.");
      } else if (surface > SURFACE_MAX) {
        erreurs.push("surface-hors-bornes");
        this._erreurChamp(champSurface, "Cette surface paraît anormalement élevée. Vérifiez la valeur.");
      }
    }

    if (erreurs.length) {
      this._resultat("Vérifiez les champs signalés avant de valider.", "erreur");
      /* Le journal ne reçoit que des codes, jamais une valeur saisie. */
      this.root.setAttribute("data-uf-erreurs", erreurs.join(","));
      return null;
    }

    this.root.removeAttribute("data-uf-erreurs");

    /* Contrat validé, reconstruit à partir des champs — la personne a pu les
       corriger, ce sont eux qui font foi, pas le contrat d'origine. */
    var valide = normaliserContrat({
      schemaVersion: SCHEMA_VERSION,
      source: this.contrat ? this.contrat.source : "urbizen-cadastre",
      confirmedAt: valeurs["confirmedAt"] || (this.contrat ? this.contrat.confirmedAt : ""),
      address: {
        label: valeurs["address.label"],
        houseNumber: this.contrat ? this.contrat.address.houseNumber : "",
        street: this.contrat ? this.contrat.address.street : "",
        postcode: valeurs["address.postcode"],
        city: valeurs["address.city"],
        cityCode: valeurs["address.cityCode"]
      },
      location: {
        latitude: valeurs["location.latitude"],
        longitude: valeurs["location.longitude"]
      },
      parcel: {
        communeCode: valeurs["parcel.communeCode"],
        prefix: valeurs["parcel.prefix"],
        section: valeurs["parcel.section"],
        number: valeurs["parcel.number"],
        id: valeurs["parcel.id"],
        surfaceM2: surface
      }
    });

    this._resultat("Localisation validée. Aucune donnée n’a été transmise : cette étape reste dans votre navigateur.", "succes");

    /* Publication du résultat. Aucun envoi réseau, aucune trace en console.
       L'effacement du stockage n'est PAS déclenché ici : il attendra une
       soumission serveur réellement confirmée. */
    this.root.dispatchEvent(new CustomEvent("urbizen:location-form-validated", {
      bubbles: true,
      detail: { formType: this.formType, formId: this.formId, storageKey: this.storageKey, contract: valide }
    }));

    return valide;
  };

  Formulaire.prototype._erreurChamp = function (champ, message) {
    var bloc = champ.closest ? champ.closest(".uf-field") : null;
    var cible = bloc ? bloc.querySelector(".uf-error") : null;
    champ.setAttribute("aria-invalid", "true");
    if (cible) { texte(cible, message); cible.hidden = false; }
  };

  Formulaire.prototype._effacerErreurChamp = function (champ) {
    var bloc = champ.closest ? champ.closest(".uf-field") : null;
    var cible = bloc ? bloc.querySelector(".uf-error") : null;
    champ.removeAttribute("aria-invalid");
    if (cible) { cible.hidden = true; }
  };

  Formulaire.prototype._resultat = function (message, etat) {
    if (!this.$result) { return; }
    texte(this.$result, message);
    this.$result.className = "uf-result uf-result-" + etat;
    this.$result.hidden = false;
  };

  Formulaire.prototype.destroy = function () {
    if (this._onConfirm) {
      document.removeEventListener("urbizen:parcel-confirmed", this._onConfirm);
      this._onConfirm = null;
    }
    this.root.removeAttribute("data-uf-mounted");
    delete this.root.urbizenForm;
    return true;
  };

  /* ==========================================================================
     API publique
     ========================================================================== */
  var UrbizenForm = {
    schemaVersion: SCHEMA_VERSION,

    /** Monte les conteneurs `[data-urbizen-form]`. Idempotent. */
    autoMount: function (scope) {
      var noeuds = (scope || document).querySelectorAll("[data-urbizen-form]");
      var montes = [];
      for (var i = 0; i < noeuds.length; i++) {
        var n = noeuds[i];
        if (n.getAttribute("data-uf-mounted") === "1") { continue; }
        n.setAttribute("data-uf-mounted", "1");
        var inst = new Formulaire(n);
        n.urbizenForm = inst;
        montes.push(inst);
      }
      return montes;
    },

    /** Normalisation exposée pour les tests et les intégrations. */
    normalize: function (brut) { return normaliserContrat(brut); }
  };

  global.UrbizenForm = UrbizenForm;
  if (typeof module !== "undefined" && module.exports) { module.exports = UrbizenForm; }

  if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", function () { UrbizenForm.autoMount(); });
    } else {
      UrbizenForm.autoMount();
    }
  }

})(typeof window !== "undefined" ? window : this);
