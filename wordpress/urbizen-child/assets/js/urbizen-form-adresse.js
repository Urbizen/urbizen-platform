/**
 * Adresse du terrain, cherchée pendant la frappe.
 *
 * Une adresse tapée à la main arrive fautée : voie abrégée, commune mal
 * orthographiée, code postal d'à côté. Le dossier part alors sur une adresse
 * qui n'existe pas, et cela se découvre en mairie. La chercher dans la base
 * officielle règle le problème à la source.
 *
 * **Le service n'est pas une condition.** Il tombe, il est lent, il ne connaît
 * pas un lieu-dit : dans tous ces cas la personne doit pouvoir saisir son
 * adresse et envoyer sa demande. Une aide qui empêche est pire que pas d'aide.
 * D'où la case « Je renseigne l'adresse manuellement », offerte d'emblée et pas
 * seulement après une panne.
 *
 * **Un seul service, celui déjà en place.** La Géoplateforme IGN, base BAN,
 * avec les conventions du composant cadastre : anti-rebond, annulation de la
 * requête précédente, délai maximal. `api-adresse.data.gouv.fr` n'est pas
 * employé — consigne de projet.
 *
 * **Un seul vocabulaire.** Les champs écrits reprennent la forme canonique que
 * `urbizen-cadastre.js` publie déjà (`label`, `houseNumber`, `street`,
 * `postcode`, `city`, `cityCode`, `latitude`, `longitude`). En inventer un
 * second condamnerait l'administration à lire deux formes d'une même adresse.
 *
 * Rien n'est deviné : une valeur que le service ne fournit pas reste vide.
 * Compléter une adresse incomplète par une autre adresse serait la pire des
 * obligeances — le dossier partirait sur un terrain qui n'est pas le bon.
 */
(function (global) {
  "use strict";

  var SERVICE = {
    completion: "https://data.geopf.fr/geocodage/completion",
    recherche: "https://data.geopf.fr/geocodage/search"
  };

  /** Mêmes réglages que le composant cadastre : une seule expérience. */
  var ANTI_REBOND_MS = 260;
  var DELAI_MAX_MS = 8000;
  var MINIMUM_CARACTERES = 3;
  var MAXIMUM_PROPOSITIONS = 7;

  var MESSAGES = {
    invite: "Adresse introuvable ? Renseignez-la manuellement.",
    aucune: "Aucune adresse trouvée.",
    panne: "La recherche d’adresse est momentanément indisponible.",
    // Le décompte est annoncé, pas seulement affiché : sans lecteur d'écran on
    // voit la liste s'ouvrir, avec lui on n'a que ce que l'on annonce.
    resultats: function (n) {
      return 1 === n ? "1 adresse proposée." : n + " adresses proposées.";
    }
  };

  var compteur = 0;

  /** Chaîne bornée, jamais nulle — même normalisation que le contrat cadastre. */
  function texte(v, max) {
    if (null === v || undefined === v) return "";
    var s = String(v).trim();
    return max && s.length > max ? s.slice(0, max) : s;
  }

  /** Nombre fini, sinon rien. Aucune coordonnée n'est inventée. */
  function nombre(v) {
    if (null === v || undefined === v || "" === v) return null;
    var n = Number(v);
    return isFinite(n) ? n : null;
  }

  function antiRebond(fn, ms) {
    var t = null;
    return function () {
      var args = arguments, self = this;
      if (t) clearTimeout(t);
      t = setTimeout(function () { t = null; fn.apply(self, args); }, ms);
    };
  }

  /** `fetch` avec délai maximal. Un service lent ne doit pas retenir la saisie. */
  function lireJson(url, signal) {
    var ctrl = new AbortController();
    var minuteur = setTimeout(function () { ctrl.abort(); }, DELAI_MAX_MS);

    if (signal) {
      signal.addEventListener("abort", function () { ctrl.abort(); });
    }

    return fetch(url, { signal: ctrl.signal })
      .then(function (r) {
        clearTimeout(minuteur);
        if (!r.ok) throw new Error("service");
        return r.json();
      })
      .catch(function (e) { clearTimeout(minuteur); throw e; });
  }

  /**
   * @param {HTMLElement} racine Conteneur portant `data-adresse`.
   */
  function Adresse(racine) {
    this.racine = racine;
    this.uid = "adr" + ++compteur;
    this.propositions = [];
    this.index = -1;
    this.requete = null;

    this.$recherche = racine.querySelector("[data-adresse-recherche]");
    this.$liste = racine.querySelector("[data-adresse-propositions]");
    this.$etat = racine.querySelector("[data-adresse-etat]");
    this.$manuel = racine.querySelector("[data-adresse-manuel]");
    this.$groupeAuto = racine.querySelector("[data-adresse-groupe-auto]");
    this.$groupeManuel = racine.querySelector("[data-adresse-groupe-manuel]");

    if (!this.$recherche || !this.$liste) return;

    this.$liste.id = this.uid + "-liste";
    this.$recherche.setAttribute("aria-controls", this.$liste.id);
    this.$recherche.setAttribute("aria-expanded", "false");

    this._ecouter();
    this._appliquerMode();
  }

  /** Le champ nommé, dans ce composant seulement. */
  Adresse.prototype._champ = function (nom) {
    return this.racine.querySelector('[data-adresse-champ="' + nom + '"]');
  };

  Adresse.prototype._ecrire = function (nom, valeur) {
    var e = this._champ(nom);
    if (e) e.value = null === valeur || undefined === valeur ? "" : String(valeur);
  };

  Adresse.prototype._ecouter = function () {
    var self = this;

    this.$recherche.addEventListener("input", antiRebond(function () {
      self._chercher(self.$recherche.value.trim());
    }, ANTI_REBOND_MS));

    this.$recherche.addEventListener("keydown", function (e) { self._clavier(e); });

    this._surClicDocument = function (e) {
      if (!self.racine.contains(e.target)) self._fermer();
    };
    document.addEventListener("click", this._surClicDocument);

    if (this.$manuel) {
      this.$manuel.addEventListener("change", function () {
        self._fermer();
        self._appliquerMode();
      });
    }
  };

  /**
   * Bascule automatique ↔ manuel.
   *
   * Le groupe écarté est masqué **et désactivé** : désactivé, il est absent du
   * `FormData`, hors de l'ordre de tabulation et sans obligation. Masquer sans
   * désactiver laisserait partir une adresse que la personne a abandonnée — et
   * le serveur recevrait deux adresses sans savoir laquelle fait foi.
   */
  Adresse.prototype._appliquerMode = function () {
    var manuel = !!(this.$manuel && this.$manuel.checked);

    this._basculer(this.$groupeAuto, !manuel);
    this._basculer(this.$groupeManuel, manuel);

    // Le mode est persisté explicitement. Le déduire de ce qui est rempli
    // reviendrait à deviner, et à se tromper sur une adresse à un seul champ.
    this._ecrire("mode_adresse", manuel ? "manuel" : "automatique");

    if (manuel) {
      // Ce que le service avait rempli n'a plus cours : le garder ferait
      // coexister deux adresses dans la même demande.
      this._oublierSelection();
      this._message("");
    }
  };

  Adresse.prototype._basculer = function (groupe, actif) {
    if (!groupe) return;

    groupe.hidden = !actif;

    Array.prototype.forEach.call(groupe.querySelectorAll("input, select, textarea, button"), function (c) {
      c.disabled = !actif;

      if (!actif) {
        c.removeAttribute("aria-invalid");
        c.classList.remove("is-error");
      }
    });

    Array.prototype.forEach.call(groupe.querySelectorAll(".req"), function (m) {
      m.hidden = !actif;
    });
  };

  Adresse.prototype._oublierSelection = function () {
    var self = this;

    ["terrain_libelle_service", "terrain_insee", "terrain_lat", "terrain_lon"].forEach(function (n) {
      self._ecrire(n, "");
    });
  };

  /* ----- Recherche ----- */

  Adresse.prototype._chercher = function (texteSaisi) {
    if (texteSaisi.length < MINIMUM_CARACTERES) { this._fermer(); this._message(""); return; }

    if (this.requete) this.requete.abort();
    this.requete = new AbortController();

    var self = this;
    var url = SERVICE.completion +
      "?text=" + encodeURIComponent(texteSaisi) +
      "&type=StreetAddress,PositionOfInterest" +
      "&maximumResponses=" + MAXIMUM_PROPOSITIONS;

    this.racine.classList.add("is-loading");

    lireJson(url, this.requete.signal)
      .then(function (d) {
        self.racine.classList.remove("is-loading");
        self.propositions = d && d.results ? d.results : [];
        self._rendre();
      })
      .catch(function (err) {
        self.racine.classList.remove("is-loading");
        if ("AbortError" === err.name) return;
        // Panne : on le dit en clair, sans code ni URL, et on montre la sortie.
        self.propositions = [];
        self._rendre(true);
      });
  };

  Adresse.prototype._rendre = function (panne) {
    while (this.$liste.firstChild) this.$liste.removeChild(this.$liste.firstChild);

    this.index = -1;
    this.$recherche.removeAttribute("aria-activedescendant");

    if (panne || !this.propositions.length) {
      this._message((panne ? MESSAGES.panne : MESSAGES.aucune) + " " + MESSAGES.invite);
      this._fermer();
      return;
    }

    var self = this;

    this.propositions.forEach(function (p, i) {
      var li = document.createElement("li");
      li.setAttribute("role", "option");
      li.setAttribute("aria-selected", "false");
      li.id = self.uid + "-opt-" + i;
      // `fulltext` vient d'un service externe : jamais d'`innerHTML` dessus.
      li.textContent = p.fulltext || "";
      li.addEventListener("click", function () { self._choisir(i); });
      self.$liste.appendChild(li);
    });

    this._message(MESSAGES.resultats(this.propositions.length));
    this._ouvrir();
  };

  Adresse.prototype._ouvrir = function () {
    this.$liste.hidden = false;
    this.$recherche.setAttribute("aria-expanded", "true");
  };

  Adresse.prototype._fermer = function () {
    this.$liste.hidden = true;
    this.$recherche.setAttribute("aria-expanded", "false");
    this.index = -1;
    // Aucune option active : l'attribut doit disparaître, sans quoi un lecteur
    // d'écran annonce une option qui n'existe plus.
    this.$recherche.removeAttribute("aria-activedescendant");
  };

  Adresse.prototype._message = function (t) {
    if (!this.$etat) return;
    this.$etat.textContent = t;
    this.$etat.hidden = "" === t;
  };

  Adresse.prototype._clavier = function (e) {
    if (this.$liste.hidden) {
      if ("ArrowDown" === e.key && this.propositions.length) { e.preventDefault(); this._ouvrir(); }
      return;
    }

    var options = this.$liste.querySelectorAll('li[role="option"]');
    if (!options.length) return;

    if ("ArrowDown" === e.key) {
      e.preventDefault();
      this.index = Math.min(this.index + 1, options.length - 1);
      this._surligner(options);
    } else if ("ArrowUp" === e.key) {
      e.preventDefault();
      this.index = Math.max(this.index - 1, 0);
      this._surligner(options);
    } else if ("Enter" === e.key) {
      // Sans option retenue, `Entrée` ne doit pas valider l'étape à l'aveugle.
      if (this.index >= 0) { e.preventDefault(); this._choisir(this.index); }
    } else if ("Escape" === e.key) {
      e.preventDefault();
      this._fermer();
    }
  };

  Adresse.prototype._surligner = function (options) {
    for (var i = 0; i < options.length; i++) {
      options[i].setAttribute("aria-selected", i === this.index ? "true" : "false");
    }

    if (this.index < 0) { this.$recherche.removeAttribute("aria-activedescendant"); return; }

    this.$recherche.setAttribute("aria-activedescendant", this.uid + "-opt-" + this.index);

    if ("function" === typeof options[this.index].scrollIntoView) {
      options[this.index].scrollIntoView({ block: "nearest" });
    }
  };

  /* ----- Sélection ----- */

  Adresse.prototype._choisir = function (i) {
    var p = this.propositions[i];
    if (!p) return;

    this.$recherche.value = p.fulltext || "";
    this._fermer();

    // Ce que l'autocomplétion sait déjà est écrit tout de suite : si /search
    // échoue, la demande reste exploitable.
    this._appliquer({
      label: p.fulltext,
      street: p.street,
      postcode: p.zipcode,
      city: p.city,
      cityCode: p.inseeCode || (p.inseeCodes && p.inseeCodes[0]),
      longitude: p.x,
      latitude: p.y
    });

    var self = this;

    // /search rend le code INSEE et les coordonnées canoniques. Son échec
    // n'annule rien : on garde ce que l'autocomplétion a fourni.
    lireJson(SERVICE.recherche + "?q=" + encodeURIComponent(p.fulltext || "") + "&limit=1&index=address")
      .then(function (fc) {
        if (!fc || !fc.features || !fc.features.length) return;

        var f = fc.features[0];
        var pr = f.properties || {};
        var co = f.geometry && f.geometry.coordinates ? f.geometry.coordinates : [];

        self._appliquer({
          label: pr.label || p.fulltext,
          houseNumber: pr.housenumber,
          street: pr.street || p.street,
          postcode: pr.postcode || p.zipcode,
          city: pr.city || p.city,
          cityCode: pr.citycode,
          longitude: co[0],
          latitude: co[1]
        });
      })
      .catch(function () { /* silence : rien à corriger, rien à alarmer. */ });

    this._message("");
  };

  /** Écrit une sélection dans les champs, sans jamais combler un vide. */
  Adresse.prototype._appliquer = function (a) {
    var libelle = texte(a.label, 200);
    var voie = texte(a.street, 160);
    var numero = texte(a.houseNumber, 20);

    this._ecrire("terrain_adresse", libelle);
    this._ecrire("terrain_libelle_service", libelle);
    this._ecrire("terrain_cp", texte(a.postcode, 10));
    this._ecrire("terrain_ville", texte(a.city, 120));
    this._ecrire("terrain_insee", texte(a.cityCode, 10));

    var lat = nombre(a.latitude), lon = nombre(a.longitude);

    // Les coordonnées ne partent que si le service les a réellement données.
    this._ecrire("terrain_lat", null === lat ? "" : lat);
    this._ecrire("terrain_lon", null === lon ? "" : lon);

    // La voie et le numéro alimentent aussi les champs manuels : si la personne
    // bascule ensuite pour corriger un détail, elle repart de ce qui a été
    // trouvé plutôt que d'une page blanche.
    if (voie) this._ecrire("terrain_voie", numero ? numero + " " + voie : voie);
  };

  /** Monte tous les composants d'adresse d'un formulaire. */
  function surveiller(racine) {
    var trouves = [];

    Array.prototype.forEach.call((racine || document).querySelectorAll("[data-adresse]"), function (n) {
      trouves.push(new Adresse(n));
    });

    return trouves;
  }

  global.UrbizenAdresse = {
    surveiller: surveiller,
    Adresse: Adresse,
    SERVICE: SERVICE,
    MESSAGES: MESSAGES
  };
})(window);
