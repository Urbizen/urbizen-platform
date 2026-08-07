/**
 * Une adresse, cherchée pendant la frappe.
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
 * **Plusieurs blocs par page.** Le module ne connaît pas les noms des champs
 * qu'il remplit : il connaît des *rôles*, et c'est le document qui dit quel nom
 * canonique porte chaque rôle. Un formulaire peut donc poser autant de blocs
 * d'adresse qu'il en a besoin — le terrain, le déclarant — sans que deux
 * instances se marchent dessus. Chacune tient son propre état, sa propre
 * requête et ses propres identifiants.
 *
 * Rien n'est deviné : une valeur que le service ne fournit pas reste vide.
 * Compléter une adresse incomplète par une autre adresse serait la pire des
 * obligeances — le dossier partirait sur une adresse qui n'est pas la bonne.
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
    aInvalider: "Sélectionnez une adresse dans la liste pour la retenir.",
    enCours: "Vérification de l’adresse…",
    incomplete: "Cette adresse n’a pas pu être vérifiée complètement.",
    retenue: function (a) { return "Adresse retenue : " + a; },
    // Le report annonce ce qui partira, pas ce qui est affiché : c'est le
    // serveur qui reconstruira le terrain, et il le fera depuis ces valeurs-là.
    reportOk: "Cette adresse sera enregistrée comme adresse du terrain.",
    reportIncomplet: "Complétez l’adresse du déclarant : elle n’est pas encore utilisable pour le terrain.",
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
    // Déclaré avant tout : le montage écrit déjà le mode, et écrire notifie.
    this._auditeurs = [];
    this.propositions = [];
    this.index = -1;
    this.requete = null;
    // Une réponse lente arrivant après une réponse récente réafficherait des
    // propositions périmées. Chaque recherche porte un numéro ; seule la
    // dernière a le droit d'écrire à l'écran.
    this.jeton = 0;
    // Faux tant qu'aucune proposition n'a été retenue. Une chaîne tapée n'est
    // pas une adresse : c'est une intention de recherche.
    this.selectionnee = false;

    this.$recherche = racine.querySelector("[data-adresse-recherche]");
    this.$liste = racine.querySelector("[data-adresse-propositions]");
    this.$etat = racine.querySelector("[data-adresse-etat]");
    this.$manuel = racine.querySelector("[data-adresse-manuel]");
    this.$groupeAuto = racine.querySelector("[data-adresse-groupe-auto]");
    this.$groupeManuel = racine.querySelector("[data-adresse-groupe-manuel]");
    // Code postal et commune sont un SEUL jeu de contrôles, partagé. Deux jeux
    // portant les mêmes noms enverraient deux valeurs, et le serveur en
    // retiendrait une au hasard.
    this.$groupeCommun = racine.querySelector("[data-adresse-groupe-commun]");

    if (!this.$recherche || !this.$liste) return;

    this.$liste.id = this.uid + "-liste";
    this.$recherche.setAttribute("role", "combobox");
    this.$recherche.setAttribute("aria-controls", this.$liste.id);
    this.$recherche.setAttribute("aria-expanded", "false");
    this.$recherche.setAttribute("aria-autocomplete", "list");

    this._ecouter();
    this._appliquerMode();
  }

  /**
   * Le contrôle qui tient un rôle, dans ce composant seulement.
   *
   * Le module ne connaît que des **rôles** — `adresse`, `cp`, `ville`, `insee`,
   * `lat`, `lon`, `voie`, `complement`, `mode`. C'est le document qui dit quel
   * nom canonique porte chaque rôle : `terrain_cp` ici, `cp_declarant` là. Sans
   * cette indirection, le module ne saurait servir qu'un seul bloc d'adresse
   * par page — ce qu'il faisait, et ce qui l'empêchait de servir le déclarant.
   */
  Adresse.prototype._champ = function (role) {
    return this.racine.querySelector('[data-adresse-champ="' + role + '"]');
  };

  Adresse.prototype._ecrire = function (nom, valeur) {
    var e = this._champ(nom);
    if (e) e.value = null === valeur || undefined === valeur ? "" : String(valeur);
    this._notifier();
  };

  /** La valeur d'un rôle, réduite à une chaîne. */
  Adresse.prototype._lire = function (role) {
    var e = this._champ(role);
    return e ? String(e.value || "").trim() : "";
  };

  /** Le bloc servi : `terrain`, `declarant`… tel que le document l'annonce. */
  Adresse.prototype.role = function () {
    return this.racine.getAttribute("data-adresse") || "";
  };

  /** Sommes-nous en saisie manuelle ? */
  Adresse.prototype.manuel = function () {
    return !!(this.$manuel && this.$manuel.checked);
  };

  /**
   * L'adresse est-elle complète, au sens où le serveur l'exigera ?
   *
   * Les mêmes minimums que `AdresseTerrain::EXIGES`, appliqués ici pour dire
   * *avant* l'envoi ce que le serveur dirait après. En automatique, une chaîne
   * tapée ne suffit pas : il faut une sélection aboutie, donc un code commune —
   * c'est lui que `/search` apporte et que le serveur réclame.
   */
  Adresse.prototype.valide = function () {
    if ("" === this._lire("cp") || "" === this._lire("ville")) return false;

    if (this.manuel()) return "" !== this._lire("voie");

    return this.selectionnee && "" !== this._lire("adresse") && "" !== this._lire("insee");
  };

  /**
   * L'adresse telle qu'elle se lit, en lignes.
   *
   * Même découpage que `AdresseTerrain::lignes_adresse()`. Le rendu final reste
   * celui du serveur ; ceci n'existe que pour montrer à l'écran ce qui va
   * partir, et une confirmation qui montrerait autre chose ne confirmerait rien.
   */
  Adresse.prototype.lisible = function () {
    var lignes = [];
    var bas = (this._lire("cp") + " " + this._lire("ville")).trim();

    if (this.manuel()) {
      lignes.push(this._lire("voie"));
      lignes.push(this._lire("complement"));
    } else {
      var libelle = this._lire("adresse");
      lignes.push(libelle);

      // Le libellé du service porte déjà commune et code postal : les répéter
      // ferait douter de l'adresse qu'on lit.
      if ("" !== bas && "" !== libelle && -1 !== libelle.indexOf(bas)) bas = "";
    }

    if ("" !== bas) lignes.push(bas);

    return lignes.filter(function (l) { return "" !== l; });
  };

  /**
   * Montre ou retire complètement ce composant.
   *
   * Retiré, il est masqué **et** tous ses contrôles sont désactivés : ils
   * quittent alors le `FormData`, l'ordre de tabulation et toute obligation.
   * Masquer sans désactiver laisserait partir une adresse que la personne a
   * remplacée — exactement ce que la bascule de mode évite déjà.
   *
   * @param {boolean} actif Vrai pour rendre le composant au formulaire.
   */
  Adresse.prototype.activer = function (actif) {
    this.racine.hidden = !actif;

    if (!actif) this._fermer();

    Array.prototype.forEach.call(
      this.racine.querySelectorAll("input, select, textarea, button"),
      function (c) {
        c.disabled = !actif;

        if (!actif) {
          c.removeAttribute("aria-invalid");
          c.classList.remove("is-error");
        }
      }
    );

    // Rendre le composant, c'est le rendre dans son mode : le groupe inactif
    // doit rester désactivé, sans quoi les deux modes partiraient ensemble.
    if (actif) this._appliquerMode();
  };

  /**
   * S'abonne aux changements de cette adresse.
   *
   * @param {Function} rappel Appelé après toute modification.
   */
  Adresse.prototype.surChangement = function (rappel) {
    this._auditeurs.push(rappel);
  };

  Adresse.prototype._notifier = function () {
    if (!this._auditeurs) return;

    var self = this;
    this._auditeurs.forEach(function (r) { r(self); });
  };

  Adresse.prototype._ecouter = function () {
    var self = this;

    // L'invalidation est immédiate, sans attendre l'anti-rebond : entre la
    // frappe et la requête, la charge ne doit jamais porter une adresse que le
    // champ ne montre plus.
    this.$recherche.addEventListener("input", function () { self._invalider(); });

    this.$recherche.addEventListener("input", antiRebond(function () {
      self._chercher(self.$recherche.value.trim());
    }, ANTI_REBOND_MS));

    this.$recherche.addEventListener("keydown", function (e) { self._clavier(e); });

    // La saisie manuelle n'emprunte pas `_ecrire` : elle est tapée dans les
    // contrôles. Sans cette écoute, une confirmation affichée ailleurs
    // montrerait une adresse déjà périmée.
    this.racine.addEventListener("input", function () { self._notifier(); });
    this.racine.addEventListener("change", function () { self._notifier(); });

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
    this._ecrire("mode", manuel ? "manuel" : "automatique");

    // Les champs partagés restent envoyés dans les deux modes ; seul leur
    // caractère modifiable change. En automatique ils viennent de la sélection
    // et se lisent sans s'éditer — les corriger à la main produirait une
    // adresse que le service n'a jamais rendue.
    this._verrouillerCommuns(!manuel);

    if (manuel) {
      // Ce que le service avait rempli n'a plus cours. Le code postal et la
      // commune, eux, restent : ils sont vrais, ils sont partagés, et les
      // effacer obligerait à retaper ce qui est déjà juste.
      this._oublierSelection(false);
      this._message("");
      this.$recherche.value = "";
    }
  };

  /**
   * Rend les champs partagés lisibles seulement, ou modifiables.
   *
   * `readonly` et non `disabled` : un contrôle désactivé disparaît du
   * `FormData`, et le mode automatique a besoin de ces deux valeurs.
   *
   * @param {boolean} verrouiller Vrai en mode automatique.
   */
  Adresse.prototype._verrouillerCommuns = function (verrouiller) {
    if (!this.$groupeCommun) return;

    Array.prototype.forEach.call(this.$groupeCommun.querySelectorAll("input"), function (c) {
      if (verrouiller) { c.setAttribute("readonly", "readonly"); }
      else { c.removeAttribute("readonly"); }
    });
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

  /**
   * Efface la sélection : la personne a changé d'avis ou de texte.
   *
   * Le code postal et la commune partent avec le reste. Les garder ferait
   * survivre la moitié d'une adresse que le champ ne montre plus, et le serveur
   * les prendrait pour la sélection courante.
   */
  Adresse.prototype._oublierSelection = function (complet) {
    var self = this;
    var roles = ["adresse", "insee", "lat", "lon"];

    // Modification du texte : la totalité part, code postal et commune compris.
    // Ils décrivaient l'adresse précédente, que le champ ne montre plus.
    if (false !== complet) { roles = roles.concat(["cp", "ville"]); }

    roles.forEach(function (r) { self._ecrire(r, ""); });

    this.selectionnee = false;
    // Après le drapeau, jamais avant : `valide()` le consulte, et notifier trop
    // tôt ferait lire un état que la ligne suivante dément.
    this._notifier();
  };

  /**
   * Une frappe après une sélection annule cette sélection.
   *
   * Sans cela, corriger une lettre laisserait partir l'adresse précédente avec
   * ses coordonnées, sous un libellé que la personne a modifié. C'est la façon
   * la plus discrète d'envoyer un dossier au mauvais endroit.
   */
  Adresse.prototype._invalider = function () {
    if (!this.selectionnee) return;

    this._oublierSelection();
    this._message(MESSAGES.aInvalider);
  };

  /* ----- Recherche ----- */

  Adresse.prototype._chercher = function (texteSaisi) {
    if (texteSaisi.length < MINIMUM_CARACTERES) { this._fermer(); this._message(""); return; }

    if (this.requete) this.requete.abort();
    this.requete = new AbortController();

    var self = this;
    var jeton = ++this.jeton;
    var url = SERVICE.completion +
      "?text=" + encodeURIComponent(texteSaisi) +
      "&type=StreetAddress,PositionOfInterest" +
      "&maximumResponses=" + MAXIMUM_PROPOSITIONS;

    this.racine.classList.add("is-loading");

    lireJson(url, this.requete.signal)
      .then(function (d) {
        // Réponse d'une recherche dépassée : elle n'a plus rien à dire.
        if (jeton !== self.jeton) return;

        self.racine.classList.remove("is-loading");

        // Une réponse qui n'a pas la forme attendue vaut une panne : mieux vaut
        // proposer la saisie manuelle que d'afficher une liste vide sans raison.
        if (!d || !Array.isArray(d.results)) { self.propositions = []; self._rendre(true); return; }

        self.propositions = d.results;
        self._rendre();
      })
      .catch(function (err) {
        if (jeton !== self.jeton) return;

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
      this._message(panne ? MESSAGES.panne : MESSAGES.aucune, true);
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

  /**
   * Écrit dans la zone d'état, et propose la sortie quand il y en a une.
   *
   * La zone est en `aria-live` : ce qui s'y écrit est annoncé sans déplacer le
   * focus. Déplacer le focus à l'arrivée des résultats couperait la frappe.
   *
   * @param {string} t      Message.
   * @param {boolean} sortie Proposer la bascule manuelle.
   */
  Adresse.prototype._message = function (t, sortie) {
    if (!this.$etat) return;

    while (this.$etat.firstChild) this.$etat.removeChild(this.$etat.firstChild);

    if ("" === t) { this.$etat.hidden = true; return; }

    this.$etat.appendChild(document.createTextNode(t));

    if (sortie && this.$manuel) {
      var self = this;
      var b = document.createElement("button");
      b.type = "button";
      b.className = "dp-btn dp-btn-lien";
      b.setAttribute("data-adresse-sortie", "");
      b.textContent = MESSAGES.invite;
      b.addEventListener("click", function () {
        // La même case, le même mode : une seule façon d'être en manuel.
        self.$manuel.checked = true;
        self.$manuel.dispatchEvent(new Event("change", { bubbles: true }));
        // Le premier contrôle actif du groupe manuel, quel que soit son nom :
        // nommer « terrain_voie » ici condamnerait le module au seul terrain.
        var v = self.$groupeManuel && self.$groupeManuel.querySelector("input:not([disabled])");
        if (v) v.focus();
      });
      this.$etat.appendChild(b);
    }

    this.$etat.hidden = false;
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

    var self = this;
    var jeton = ++this.jeton;

    // L'autocomplétion ne rend pas le code INSEE ; seul /search le donne, et le
    // serveur l'exige en mode automatique. La sélection n'est donc acquise
    // qu'au retour de /search : l'annoncer plus tôt promettrait une adresse que
    // l'envoi refuserait ensuite.
    this.racine.classList.add("is-loading");
    this._message(MESSAGES.enCours);

    lireJson(SERVICE.recherche + "?q=" + encodeURIComponent(p.fulltext || "") + "&limit=1&index=address")
      .then(function (fc) {
        if (jeton !== self.jeton) return;

        self.racine.classList.remove("is-loading");

        if (!fc || !Array.isArray(fc.features) || !fc.features.length) {
          self._selectionImpossible();
          return;
        }

        var trait = fc.features[0];
        var pr = trait.properties || {};
        var co = trait.geometry && trait.geometry.coordinates ? trait.geometry.coordinates : [];

        if (!pr.citycode) { self._selectionImpossible(); return; }

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

        self.selectionnee = true;
        // Idem : la sélection n'est acquise qu'ici, et c'est seulement
        // maintenant qu'un abonné peut la lire pour ce qu'elle est.
        self._notifier();
        self._message(MESSAGES.retenue(pr.label || p.fulltext || ""));
      })
      .catch(function (err) {
        if (jeton !== self.jeton) return;

        self.racine.classList.remove("is-loading");
        if ("AbortError" === err.name) return;
        self._selectionImpossible();
      });
  };

  /**
   * La proposition retenue n'a pas pu être complétée.
   *
   * On ne garde rien de partiel : une adresse sans code commune serait refusée
   * à l'envoi, et laisser croire qu'elle est retenue ferait buter la personne
   * sur une erreur qu'elle ne comprendrait pas. On propose la saisie manuelle,
   * qui, elle, aboutit toujours.
   */
  Adresse.prototype._selectionImpossible = function () {
    this._oublierSelection();
    this._message(MESSAGES.incomplete, true);
  };

  /** Écrit une sélection dans les champs, sans jamais combler un vide. */
  Adresse.prototype._appliquer = function (a) {
    var libelle = texte(a.label, 200);
    var voie = texte(a.street, 160);
    var numero = texte(a.houseNumber, 20);

    this._ecrire("adresse", libelle);
    this._ecrire("cp", texte(a.postcode, 10));
    this._ecrire("ville", texte(a.city, 120));
    this._ecrire("insee", texte(a.cityCode, 10));

    var lat = nombre(a.latitude), lon = nombre(a.longitude);

    // Les coordonnées ne partent que si le service les a réellement données.
    this._ecrire("lat", null === lat ? "" : lat);
    this._ecrire("lon", null === lon ? "" : lon);

    // La voie du service n'alimente PAS le champ manuel : il porterait alors une
    // adresse concurrente, prête à partir si la personne bascule. Le mode manuel
    // se remplit à la main, c'est ce qui le rend sans ambiguïté.
    void voie; void numero;
  };

  /* ----- « L'adresse du terrain est la même que celle du déclarant » ----- */

  /**
   * La case qui évite la double saisie.
   *
   * **Elle ne recopie rien.** Recopier dans le navigateur produirait une
   * seconde adresse, vraie au moment du clic et fausse dès la correction
   * suivante — et le serveur aurait alors à départager deux versions de la même
   * chose. Le terrain est donc simplement retiré du formulaire, et le serveur
   * le reconstruit depuis le déclarant qu'il vient lui-même de valider.
   *
   * Ce que la case fait ici, elle le fait pour les yeux et pour le clavier :
   * montrer l'adresse qui sera retenue, la tenir à jour, et refuser d'avancer
   * quand elle n'est pas utilisable.
   *
   * @param {HTMLElement} racine   Conteneur portant `data-adresse-report`.
   * @param {Array}       adresses Composants déjà montés.
   */
  function Report(racine, adresses) {
    this.racine = racine;
    this.$case = racine.querySelector("[data-adresse-report-case]");
    this.$encadre = racine.querySelector("[data-adresse-report-encadre]");
    this.$adresse = racine.querySelector("[data-adresse-report-adresse]");
    this.$note = racine.querySelector("[data-adresse-report-note]");

    this.declarant = null;
    this.terrain = null;

    adresses.forEach(function (a) {
      if ("declarant" === a.role()) this.declarant = a;
      if ("terrain" === a.role()) this.terrain = a;
    }, this);

    // Sans les deux blocs, la case n'a rien à reporter : mieux vaut ne rien
    // faire que masquer un terrain qu'aucun déclarant ne remplacerait.
    if (!this.$case || !this.declarant || !this.terrain) return;

    var self = this;

    this.$case.addEventListener("change", function () { self.appliquer(); });

    // L'encadré suit le déclarant : une adresse corrigée après le cochage ne
    // doit jamais rester affichée sous sa forme précédente.
    this.declarant.surChangement(function () { self._rafraichir(); });

    this.appliquer();
  }

  Report.prototype.coche = function () {
    return !!(this.$case && this.$case.checked);
  };

  /**
   * La progression est-elle permise ?
   *
   * Faux dans un seul cas : la case est cochée et le déclarant est incomplet.
   * Le terrain étant alors masqué, la validation d'étape du document ne le voit
   * pas — laisser passer enverrait une demande sans adresse de terrain.
   */
  Report.prototype.pret = function () {
    return !this.coche() || !this.declarant || this.declarant.valide();
  };

  Report.prototype.appliquer = function () {
    if (!this.terrain) return;

    this.terrain.activer(!this.coche());
    this._rafraichir();
  };

  Report.prototype._rafraichir = function () {
    if (!this.$encadre) return;

    if (!this.coche()) {
      this.$encadre.hidden = true;
      this.racine.classList.remove("is-error");
      return;
    }

    var valide = this.declarant.valide();

    this.$encadre.hidden = false;
    this.racine.classList.toggle("is-error", !valide);
    this.$encadre.setAttribute("aria-invalid", valide ? "false" : "true");

    if (this.$adresse) {
      while (this.$adresse.firstChild) this.$adresse.removeChild(this.$adresse.firstChild);

      // `textContent` et jamais `innerHTML` : ces lignes viennent d'un service
      // externe ou de la frappe, et le séparateur est le seul balisage voulu.
      this.declarant.lisible().forEach(function (l, i) {
        if (i) this.$adresse.appendChild(document.createElement("br"));
        this.$adresse.appendChild(document.createTextNode(l));
      }, this);
    }

    if (this.$note) {
      this.$note.textContent = valide ? MESSAGES.reportOk : MESSAGES.reportIncomplet;
    }
  };

  /** Monte tous les composants d'adresse d'un formulaire, et leurs reports. */
  function surveiller(racine) {
    var portee = racine || document;
    var trouves = [];

    Array.prototype.forEach.call(portee.querySelectorAll("[data-adresse]"), function (n) {
      trouves.push(new Adresse(n));
    });

    // Les reports après les adresses : ils s'appuient dessus.
    Array.prototype.forEach.call(portee.querySelectorAll("[data-adresse-report]"), function (n) {
      n.__report = new Report(n, trouves);
    });

    return trouves;
  }

  /**
   * Tous les reports d'un formulaire permettent-ils d'avancer ?
   *
   * Appelé par la validation d'étape du document, qui ne peut pas juger d'un
   * bloc qu'elle ne voit plus.
   *
   * @param {HTMLElement} racine Formulaire, ou document entier.
   * @return {boolean}
   */
  function reportPret(racine) {
    var ok = true;

    Array.prototype.forEach.call((racine || document).querySelectorAll("[data-adresse-report]"), function (n) {
      if (n.__report && !n.__report.pret()) ok = false;
    });

    return ok;
  }

  global.UrbizenAdresse = {
    surveiller: surveiller,
    reportPret: reportPret,
    Adresse: Adresse,
    Report: Report,
    SERVICE: SERVICE,
    MESSAGES: MESSAGES
  };
})(window);
