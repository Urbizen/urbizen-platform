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

  var SURFACE_MAX = 10000000;   // 10 km², au-delà c'est une aberration de saisie
  var LONGUEUR_ABSURDE = 1000;  // garde-fou contre un payload démesuré

  /* Règles de format, appliquées à l'entrée comme à la validation.
     AUCUNE valeur n'est tronquée : une valeur non conforme est refusée et
     signalée, jamais silencieusement raccourcie.

     `cityCode` et `communeCode` acceptent la forme corse : vérifié sur les API
     réelles, le code INSEE de Bastia est « 2B033 » et non cinq chiffres. La
     règle « exactement 5 chiffres » aurait rejeté toute la Corse. */
  var REGLES = {
    "address.postcode":   { re: /^[0-9]{5}$/,                   libelle: "Code postal", attendu: "5 chiffres" },
    "address.cityCode":   { re: /^(?:[0-9]{5}|2[AB][0-9]{3})$/, libelle: "Code commune de l’adresse", attendu: "5 caractères, par exemple 33063 ou 2B033" },
    "parcel.communeCode": { re: /^(?:[0-9]{5}|2[AB][0-9]{3})$/, libelle: "Code commune de la parcelle", attendu: "5 caractères, par exemple 33063 ou 2B033" },
    "parcel.prefix":      { re: /^[0-9]{3}$/,                   libelle: "Préfixe cadastral", attendu: "3 chiffres, par exemple 000" },
    "parcel.section":     { re: /^[0-9A-Z]{1,3}$/,              libelle: "Section cadastrale", attendu: "1 à 3 caractères, par exemple KE" },
    "parcel.number":      { re: /^[0-9]{1,4}$/,                 libelle: "Numéro de parcelle", attendu: "1 à 4 chiffres, par exemple 0112" },
    "parcel.id":          { re: /^[0-9A-Z]{14}$/,               libelle: "Identifiant cadastral", attendu: "14 caractères" }
  };

  /* Champs dont la casse est normalisée : transformation explicite et
     prévisible, annoncée dans la documentation (D-009). */
  var EN_MAJUSCULES = { "parcel.section": 1, "parcel.id": 1, "address.cityCode": 1, "parcel.communeCode": 1 };

  /* Vérifie une valeur selon son chemin. Renvoie { valeur, erreur }. */
  function verifier(chemin, brut) {
    var v = chaine(brut);
    if (v === "") { return { valeur: "", erreur: null }; }
    if (v.length > LONGUEUR_ABSURDE) {
      return { valeur: "", erreur: { chemin: chemin, libelle: (REGLES[chemin] && REGLES[chemin].libelle) || chemin, message: "Valeur démesurée, ignorée." } };
    }
    if (Object.prototype.hasOwnProperty.call(EN_MAJUSCULES, chemin)) { v = v.toUpperCase(); }
    var regle = REGLES[chemin];
    if (regle && !regle.re.test(v)) {
      return { valeur: v, erreur: { chemin: chemin, libelle: regle.libelle, message: regle.libelle + " incorrect : " + regle.attendu + " attendu." } };
    }
    return { valeur: v, erreur: null };
  }

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
  /* Trim seulement. Aucune troncature : la longueur est une règle de
     validation, pas une occasion de mutiler la donnée en silence. */
  function chaine(v) {
    if (v == null) { return ""; }
    if (typeof v === "object") { return ""; }   // refuse tableaux et objets
    return String(v).trim();
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
    var r = normaliserAvecAnomalies(brut);
    return r ? r.contrat : null;
  }

  /* Renvoie { contrat, anomalies[] }. Les valeurs non conformes sont
     conservées telles quelles dans le contrat — pour que la personne les voie
     et puisse les corriger — mais signalées. Rien n'est tronqué. */
  function normaliserAvecAnomalies(brut) {
    if (!brut || typeof brut !== "object" || Array.isArray(brut)) { return null; }

    var anomalies = [];
    function champ(chemin) {
      var v = verifier(chemin, lire(brut, chemin));
      if (v.erreur) { anomalies.push(v.erreur); }
      return v.valeur;
    }

    var c = {
      schemaVersion: chaine(lire(brut, "schemaVersion")) || SCHEMA_VERSION,
      source: chaine(lire(brut, "source")),
      confirmedAt: chaine(lire(brut, "confirmedAt")),
      address: {
        label: chaine(lire(brut, "address.label")),
        houseNumber: chaine(lire(brut, "address.houseNumber")),
        street: chaine(lire(brut, "address.street")),
        postcode: champ("address.postcode"),
        city: chaine(lire(brut, "address.city")),
        cityCode: champ("address.cityCode")
      },
      location: {
        latitude: nombre(lire(brut, "location.latitude")),
        longitude: nombre(lire(brut, "location.longitude"))
      },
      parcel: {
        communeCode: champ("parcel.communeCode"),
        prefix: champ("parcel.prefix"),
        section: champ("parcel.section"),
        number: champ("parcel.number"),
        id: champ("parcel.id"),
        surfaceM2: nombre(lire(brut, "parcel.surfaceM2"))
      }
    };

    /* Coordonnées : nombre fini dans les bornes terrestres, sinon refusées et
       signalées — jamais « corrigées ». */
    if (c.location.latitude !== null && (c.location.latitude < -90 || c.location.latitude > 90)) {
      c.location.latitude = null;
      anomalies.push({ chemin: "location.latitude", libelle: "Latitude", message: "Latitude hors bornes, ignorée." });
    }
    if (c.location.longitude !== null && (c.location.longitude < -180 || c.location.longitude > 180)) {
      c.location.longitude = null;
      anomalies.push({ chemin: "location.longitude", libelle: "Longitude", message: "Longitude hors bornes, ignorée." });
    }

    /* Surface : strictement positive lorsqu'elle est renseignée, et plafonnée. */
    if (c.parcel.surfaceM2 !== null && (c.parcel.surfaceM2 <= 0 || c.parcel.surfaceM2 > SURFACE_MAX)) {
      c.parcel.surfaceM2 = null;
      anomalies.push({ chemin: "parcel.surfaceM2", libelle: "Surface cadastrale", message: "Surface hors bornes, ignorée." });
    }

    /* Longueurs libres : seules les valeurs démesurées sont refusées. */
    ["address.label", "address.street", "address.city", "address.houseNumber"].forEach(function (ch) {
      var parts = ch.split(".");
      if (c[parts[0]][parts[1]].length > LONGUEUR_ABSURDE) {
        c[parts[0]][parts[1]] = "";
        anomalies.push({ chemin: ch, libelle: ch, message: "Valeur démesurée, ignorée." });
      }
    });

    return { contrat: c, anomalies: anomalies };
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
    this.origineCadastre = false;

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

  /* Depuis la 0.4.0, Renderer.php pose déjà un préfixe d'instance : le HTML
     est valide sans JavaScript. On ne renomme donc que si ce marqueur est
     absent — cas d'un gabarit rendu par un hôte tiers. Aucun double préfixe. */
  Formulaire.prototype._identifiantsUniques = function () {
    if (this.root.getAttribute("data-uf-instance")) { return; }
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
      self._appliquer(c, "evenement");   // brut : _appliquer normalise et signale
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

    /* L'état invalide disparaît dès que la personne corrige : pas d'annonce
       répétitive, et aria-invalid ne survit pas à la correction. */
    var saisies = this.root.querySelectorAll(".uf-input");
    for (var i = 0; i < saisies.length; i++) {
      saisies[i].addEventListener("input", function () {
        if (this.getAttribute("aria-invalid") === "true") { self._effacerErreurChamp(this); }
      });
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
    this._appliquer(objet, "stockage");   // brut : _appliquer normalise et signale
  };

  /* --- Application d'un contrat aux champs --- */
  Formulaire.prototype._appliquer = function (brut, origine) {
    var r = normaliserAvecAnomalies(brut);
    if (!r) { return false; }

    if (!contratUtilisable(r.contrat)) {
      /* Rien d'exploitable. Si c'est parce que des valeurs ont été refusées,
         on le dit : un payload écarté en silence est indétectable pour la
         personne comme pour la personne qui débogue. */
      if (r.anomalies.length) {
        this._notice(["La localisation reçue n’a pas pu être reprise : "
          + r.anomalies.map(function (a) { return a.libelle; }).join(", ")
          + ". Reprenez votre adresse sur la carte."]);
      }
      return false;
    }

    this.contrat = r.contrat;
    /* Provenance : une confirmation cadastre fait foi et se conserve, même si
       la personne corrige ensuite les champs (A-1). */
    this.origineCadastre = (r.contrat.source === "urbizen-cadastre") || this.origineCadastre;

    var champs = this.root.querySelectorAll("[data-from]");
    for (var i = 0; i < champs.length; i++) {
      var chemin = champs[i].getAttribute("data-from");
      if (!Object.prototype.hasOwnProperty.call(CHEMINS, chemin)) { continue; }
      var valeur = lire(r.contrat, chemin);
      champs[i].value = (valeur === null || valeur === undefined) ? "" : String(valeur);
      this._effacerErreurChamp(champs[i]);
    }

    /* Les données venant du cadastre sont revalidées avant d'être reprises :
       ce qui ne respecte pas les règles est signalé, sur le champ quand il est
       visible, dans la zone d'état quand il est technique. Les messages de la
       zone d'état sont rassemblés puis rendus UNE fois : sans cela, le
       contrôle des codes commune effacerait les messages précédents. */
    var messages = this._signalerAnomalies(r.anomalies);
    var divergence = this._verifierCodesCommune();
    if (divergence) { messages.push(divergence); }
    this._notice(messages);

    this._afficherResume();
    this.root.setAttribute("data-uf-origine", origine);
    return true;
  };

  /* Signale les anomalies d'entrée sans jamais recopier une valeur.
     Renvoie les messages destinés à la zone d'état. */
  Formulaire.prototype._signalerAnomalies = function (anomalies) {
    if (!anomalies || !anomalies.length) { return []; }
    var techniques = [];
    for (var i = 0; i < anomalies.length; i++) {
      var a = anomalies[i];
      var champ = this.root.querySelector('[data-from="' + a.chemin + '"]');
      if (champ && champ.type !== "hidden") {
        this._erreurChamp(champ, a.message);
      } else {
        techniques.push(a.libelle);
      }
    }
    if (!techniques.length) { return []; }
    return ["Certaines données techniques n’ont pas pu être reprises : "
      + techniques.join(", ") + ". Vous pouvez continuer, ou reprendre votre adresse sur la carte."];
  };

  /* Rend la zone d'état. Accepte une chaîne ou une liste de messages. */
  Formulaire.prototype._notice = function (messages) {
    if (!this.$notice) { return; }
    var liste = Array.isArray(messages) ? messages.filter(Boolean) : (messages ? [messages] : []);
    if (!liste.length) { texte(this.$notice, ""); this.$notice.hidden = true; return; }
    texte(this.$notice, liste.join(" "));
    this.$notice.hidden = false;
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
    if (!this.contrat) { return null; }
    var a = this.contrat.address.cityCode;
    var p = this.contrat.parcel.communeCode;
    /* Divergence non bloquante — à condition que chacun soit valide par
       ailleurs, ce que la normalisation a déjà vérifié. */
    if (a && p && a !== p) {
      this.root.setAttribute("data-uf-commune-divergente", "1");
      return "Le code commune de l’adresse et celui de la parcelle diffèrent. Vérifiez la commune indiquée avant de valider.";
    }
    this.root.removeAttribute("data-uf-commune-divergente");
    return null;
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
    this.origineCadastre = false;
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
    var i;

    var champs = this.root.querySelectorAll("[data-from]");
    for (i = 0; i < champs.length; i++) {
      var chemin = champs[i].getAttribute("data-from");
      if (!Object.prototype.hasOwnProperty.call(CHEMINS, chemin)) { continue; }
      valeurs[chemin] = champs[i].value;
      this._effacerErreurChamp(champs[i]);
    }

    /* Champs obligatoires : présence seulement, aucun contenu recopié. */
    var obligatoires = this.root.querySelectorAll(".uf-input[required]");
    for (i = 0; i < obligatoires.length; i++) {
      if (chaine(obligatoires[i].value) === "") {
        erreurs.push("champ-obligatoire");
        this._erreurChamp(obligatoires[i], "Ce champ est nécessaire.");
      }
    }

    /* Règles de format, appliquées à tous les champs qui en ont une. */
    for (i = 0; i < champs.length; i++) {
      var ch = champs[i].getAttribute("data-from");
      if (!REGLES[ch]) { continue; }
      var v = verifier(ch, champs[i].value);
      if (v.erreur) {
        erreurs.push(ch);
        if (champs[i].type === "hidden") {
          this._notice([v.erreur.message]);
        } else {
          this._erreurChamp(champs[i], v.erreur.message);
        }
      } else if (v.valeur !== champs[i].value) {
        /* Normalisation explicite et visible : la casse est remontée dans le
           champ, la personne voit ce qui sera retenu. */
        champs[i].value = v.valeur;
        valeurs[ch] = v.valeur;
      }
    }

    /* Surface : nombre fini, strictement positif, plafonné. */
    var champSurface = this.root.querySelector('[data-from="parcel.surfaceM2"]');
    var surface = null;
    if (champSurface && chaine(champSurface.value) !== "") {
      surface = nombre(champSurface.value);
      if (surface === null) {
        erreurs.push("surface-non-numerique");
        this._erreurChamp(champSurface, "Indiquez un nombre.");
      } else if (surface <= 0) {
        erreurs.push("surface-non-positive");
        this._erreurChamp(champSurface, "La surface doit être strictement positive.");
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

    /* Provenance honnête (A-1) : « urbizen-cadastre » si la localisation vient
       d'une confirmation sur la carte, même corrigée ensuite ; « urbizen-form »
       si tout a été saisi à la main. Aucun horodatage n'est créé ici : la
       validation locale n'est pas une confirmation cadastrale. */
    var source = this.origineCadastre ? "urbizen-cadastre" : "urbizen-form";

    var r = normaliserAvecAnomalies({
      schemaVersion: SCHEMA_VERSION,
      source: source,
      confirmedAt: this.origineCadastre ? (valeurs["confirmedAt"] || (this.contrat ? this.contrat.confirmedAt : "")) : "",
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

    this.root.dispatchEvent(new CustomEvent("urbizen:location-form-validated", {
      bubbles: true,
      detail: { formType: this.formType, formId: this.formId, storageKey: this.storageKey, contract: r.contrat }
    }));

    return r.contrat;
  };

  Formulaire.prototype._erreurChamp = function (champ, message) {
    var bloc = champ.closest ? champ.closest(".uf-field") : null;
    var cible = bloc ? bloc.querySelector(".uf-error") : null;
    champ.setAttribute("aria-invalid", "true");
    if (!cible) { return; }
    texte(cible, message);
    cible.hidden = false;
    /* Le lien champ → message existe déjà dans le HTML serveur ; on le pose
       ici aussi pour les gabarits tiers, sans écraser les descriptions déjà
       présentes (note d'aide notamment). */
    if (cible.id) {
      var decrit = (champ.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
      if (decrit.indexOf(cible.id) === -1) {
        decrit.push(cible.id);
        champ.setAttribute("aria-describedby", decrit.join(" "));
      }
    }
  };

  Formulaire.prototype._effacerErreurChamp = function (champ) {
    var bloc = champ.closest ? champ.closest(".uf-field") : null;
    var cible = bloc ? bloc.querySelector(".uf-error") : null;
    champ.removeAttribute("aria-invalid");
    if (cible) { texte(cible, ""); cible.hidden = true; }
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
    normalize: function (brut) { return normaliserContrat(brut); },

    /** Normalisation détaillée : { contrat, anomalies[] }. */
    normalizeWithIssues: function (brut) { return normaliserAvecAnomalies(brut); }
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
