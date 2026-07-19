/* ============================================================================
   urbizen-cadastre.js
   Composant cadastre réutilisable Urbizen.
   Se monte dans un conteneur, gère : saisie d'adresse avec autocomplétion,
   carte (photo aérienne + parcellaire), sélection et confirmation de parcelle,
   persistance sessionStorage, événement `urbizen:parcel-confirmed`.

   Réutilisable : page d'accueil, formulaire DP, formulaire PCMI, espace client.
   Source de vérité unique — ce fichier ne doit exister qu'ici (voir D-008).

   Dépendances : Leaflet (window.L), servi localement depuis assets/vendor/,
   et urbizen-cadastre.css. Les tokens de charte `--u-*` sont facultatifs :
   chaque var() porte une valeur de repli côté CSS.

   ── Sécurité ──
   Aucune donnée d'API ni option d'attribut n'est insérée via innerHTML : le DOM
   est construit par createElement / textContent / setAttribute. L'échappement
   PHP côté serveur ne dispense pas de cette règle.

   ── Vie privée ──
   L'adresse et la parcelle restent dans l'onglet du navigateur (sessionStorage,
   clé préfixée et configurable). Rien n'est envoyé à un serveur Urbizen ; seuls
   les services publics IGN ci-dessous sont appelés. `clearStored()` efface.

   ── Services cartographiques (Géoplateforme IGN — configuration centralisée) ──
   Géocodage      : https://data.geopf.fr/geocodage   (service IGN, base BAN)
                    - /completion : autocomplétion
                    - /search     : géocodage canonique (fournit le code INSEE)
                    NB : on n'utilise PAS api-adresse.data.gouv.fr (consigne projet).
   Tuiles         : https://data.geopf.fr/wmts        (WMTS, sans clé)
   Parcellaire    : https://apicarto.ign.fr/api/cadastre/parcelle  (source PCI)
   Limitations    : services publics soumis à quota ; parcellaire mis à jour ~2×/an ;
                    surface fournie = surface cadastrale INDICATIVE.
   ========================================================================== */
(function (global) {
  "use strict";

  var CONFIG = {
    geocodeCompletion: "https://data.geopf.fr/geocodage/completion",
    geocodeSearch:     "https://data.geopf.fr/geocodage/search",
    parcelle:          "https://apicarto.ign.fr/api/cadastre/parcelle",
    wmts:              "https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&STYLE=normal&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}",
    layers: {
      ortho:    "ORTHOIMAGERY.ORTHOPHOTOS",
      plan:     "GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2",
      cadastre: "CADASTRALPARCELS.PARCELLAIRE_EXPRESS"
    },
    debounceMs: 260,
    requestTimeoutMs: 8000,
    defaultView: { lat: 46.8, lon: 1.9, zoom: 6 },  // centre France
    storagePrefix: "urbizen:",
    storageKey: "urbizen:parcel"
  };

  function tileUrl(layer, fmt) { return CONFIG.wmts + "&LAYER=" + layer + "&FORMAT=" + fmt; }

  /* Compteur d'instances : garantit des identifiants HTML uniques par composant,
     pour que plusieurs cadastres cohabitent sur une même page sans casser les
     liens `for`, `aria-controls` et `aria-activedescendant`. */
  var instanceSeq = 0;

  /* Clé de stockage : toujours préfixée, quelle que soit la valeur reçue. */
  function storageKeyFor(key) {
    if (!key) { return CONFIG.storageKey; }
    return key.indexOf(CONFIG.storagePrefix) === 0 ? key : CONFIG.storagePrefix + key;
  }

  /* Modèle de données conservé (schéma fixé par la spécification) */
  function emptyData() {
    return {
      address: "", postcode: "", city: "", cityCode: "",
      longitude: null, latitude: null,
      cadastralSection: "", cadastralNumber: "", cadastralId: "",
      area: null, geometry: null, source: "", retrievedAt: ""
    };
  }

  /* fetch avec délai maximal + signal d'annulation */
  function fetchJson(url, signal) {
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, CONFIG.requestTimeoutMs);
    if (signal) signal.addEventListener("abort", function () { ctrl.abort(); });
    return fetch(url, { signal: ctrl.signal, headers: { "Accept": "application/json" } })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .finally(function () { clearTimeout(timer); });
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  /* Fabrique d'éléments : le texte passe TOUJOURS par textContent.
     Aucune variante acceptant du HTML n'est fournie, volontairement. */
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text != null) { e.textContent = text; }
    return e;
  }

  function setAttrs(node, attrs) {
    for (var k in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, k) && attrs[k] != null) {
        node.setAttribute(k, String(attrs[k]));
      }
    }
    return node;
  }

  function append(parent) {
    for (var i = 1; i < arguments.length; i++) {
      if (arguments[i]) { parent.appendChild(arguments[i]); }
    }
    return parent;
  }

  /* ==========================================================================
     Instance du composant
     ========================================================================== */
  function Cadastre(container, options) {
    this.opts = options || {};
    this.root = typeof container === "string" ? document.querySelector(container) : container;
    if (!this.root) { console.warn("[urbizen-cadastre] conteneur introuvable"); return; }
    this.uid = "uc-" + (++instanceSeq);
    this.storageKey = storageKeyFor(this.opts.storageKey);
    this.data = emptyData();
    this.map = null;
    this.layers = {};
    this.selLayer = null;
    this.pointMarker = null;
    this.baseOrtho = true;
    this.confirmed = false;
    this.activeGeocode = null;   // AbortController de la recherche en cours
    this.activeParcel = null;
    this.suggestIndex = -1;
    this.suggestions = [];
    this._build();
    this._bind();
  }

  /* Construction du DOM.
     Tout passe par createElement / textContent / setAttribute : les libellés
     viennent d'attributs de bloc éditables et les suggestions d'une API
     publique — aucun des deux n'est digne de confiance pour de l'innerHTML.
     Les identifiants sont préfixés par `this.uid`, unique à chaque instance. */
  Cadastre.prototype._build = function () {
    this.root.classList.add("uc-root");

    var idInput   = this.uid + "-input";
    var idSuggest = this.uid + "-suggest";
    var idMap     = this.uid + "-map";
    var idStatus  = this.uid + "-status";
    var idError   = this.uid + "-error";

    while (this.root.firstChild) { this.root.removeChild(this.root.firstChild); }

    /* --- Champ de saisie et suggestions --- */
    var label = el("label", "uc-label", this.opts.label || "Adresse du projet");
    setAttrs(label, { "for": idInput });

    this.$input = setAttrs(el("input", "uc-input"), {
      id: idInput,
      type: "text",
      autocomplete: "off",
      role: "combobox",
      "aria-expanded": "false",
      "aria-autocomplete": "list",
      "aria-controls": idSuggest,
      "aria-describedby": idStatus,
      placeholder: this.opts.placeholder || "Commencez à saisir une adresse…"
    });

    var spinner = setAttrs(el("span", "uc-spinner"), { "aria-hidden": "true" });
    this.$suggest = setAttrs(el("ul", "uc-suggest"), { id: idSuggest, role: "listbox" });
    this.$suggest.hidden = true;

    this.$field = append(el("div", "uc-field"), label, this.$input, spinner, this.$suggest);

    /* --- Zone d'erreur --- */
    this.$error = setAttrs(el("div", "uc-error"), { id: idError, role: "alert" });
    this.$error.hidden = true;

    /* --- Barre de statut et bascule de fond de carte --- */
    this.$status = el("span", "uc-status-txt", "Cliquez sur votre parcelle pour la confirmer.");
    this.$statusWrap = setAttrs(el("span", "uc-status"), {
      id: idStatus, role: "status", "aria-live": "polite"
    });
    append(this.$statusWrap, el("span", "uc-dot"), this.$status);

    this.$toggle = setAttrs(el("button", "uc-toggle", "Vue plan"), { type: "button" });
    var bar = append(el("div", "uc-map-bar"), this.$statusWrap, this.$toggle);

    /* --- Carte --- */
    this.$mapEl = setAttrs(el("div", "uc-map"), { id: idMap });
    if (this.opts.mapHeight) { this.$mapEl.style.height = this.opts.mapHeight; }

    /* --- Cartouche de la parcelle --- */
    this.$parcelRef = el("span", "uc-parcel-ref", "—");
    var parcelInfo = append(
      el("div", null),
      el("div", "uc-parcel-head", "Parcelle sélectionnée"),
      append(el("div", "uc-parcel-body"), this.$parcelRef)
    );

    this.$confirm = setAttrs(el("button", "uc-btn uc-btn-confirm", "Confirmer cette parcelle"), { type: "button" });
    this.$reject  = setAttrs(el("button", "uc-link", "Ce n’est pas la bonne parcelle"), { type: "button" });
    var actions = append(el("div", "uc-parcel-actions"), this.$confirm, this.$reject);

    this.$parcel = append(el("div", "uc-parcel"), parcelInfo, actions);
    this.$parcel.hidden = true;

    this.$mapWrap = append(el("div", "uc-map-wrap"), bar, this.$mapEl, this.$parcel);
    this.$mapWrap.hidden = true;

    /* --- Action finale --- */
    this.$continue = setAttrs(
      el("button", "uc-continue", this.opts.continueLabel || "Continuer"),
      { type: "button" }
    );
    this.$continue.disabled = true;
    this.$continueHint = el("span", "uc-continue-hint", "Confirmez votre parcelle pour continuer.");
    var continueRow = append(el("div", "uc-continue-row"), this.$continue, this.$continueHint);

    append(this.root, this.$field, this.$error, this.$mapWrap, continueRow);
  };


  Cadastre.prototype._bind = function () {
    var self = this;
    var onType = debounce(function () { self._complete(self.$input.value.trim()); }, CONFIG.debounceMs);
    this.$input.addEventListener("input", onType);
    this.$input.addEventListener("keydown", function (e) { self._onKey(e); });
    this.$input.addEventListener("focus", function () { if (self.suggestions.length) self._openSuggest(); });
    /* Mémorisé pour pouvoir être retiré par destroy() : sans cela, chaque
       remontage laisserait un écouteur orphelin sur le document. */
    this._onDocClick = function (e) { if (!self.root.contains(e.target)) self._closeSuggest(); };
    document.addEventListener("click", this._onDocClick);

    this.$toggle.addEventListener("click", function () { self._setBase(!self.baseOrtho); });
    this.$confirm.addEventListener("click", function () { self._confirmCurrent(); });
    this.$reject.addEventListener("click", function () { self._rejectParcel(); });
    this.$continue.addEventListener("click", function () { self._continue(); });
  };

  /* ----- Autocomplétion (Géoplateforme /completion) ----- */
  Cadastre.prototype._complete = function (text) {
    if (text.length < 3) { this._closeSuggest(); return; }
    if (this.activeGeocode) this.activeGeocode.abort();
    this.activeGeocode = new AbortController();
    this.$field.classList.add("is-loading");
    var url = CONFIG.geocodeCompletion +
      "?text=" + encodeURIComponent(text) +
      "&type=StreetAddress,PositionOfInterest&maximumResponses=7";
    var self = this;
    fetchJson(url, this.activeGeocode.signal)
      .then(function (d) {
        self.$field.classList.remove("is-loading");
        self.suggestions = (d && d.results) ? d.results : [];
        self._renderSuggest();
      })
      .catch(function (err) {
        self.$field.classList.remove("is-loading");
        if (err.name === "AbortError") return;
        self._renderSuggest(true); // affiche "adresse introuvable / réseau"
      });
  };

  Cadastre.prototype._renderSuggest = function (networkError) {
    while (this.$suggest.firstChild) { this.$suggest.removeChild(this.$suggest.firstChild); }
    this.suggestIndex = -1;
    this.$input.removeAttribute("aria-activedescendant");
    if (networkError) {
      this.$suggest.appendChild(el("li", "uc-empty",
        "Recherche indisponible : vérifiez votre connexion, puis réessayez."));
      this._openSuggest(); return;
    }
    if (!this.suggestions.length) {
      this.$suggest.appendChild(el("li", "uc-empty",
        "Aucune adresse trouvée. Vérifiez l’orthographe ou précisez la commune."));
      this._openSuggest(); return;
    }
    var self = this;
    this.suggestions.forEach(function (s, i) {
      /* `fulltext` vient d'un service externe : jamais d'innerHTML dessus. */
      var li = setAttrs(el("li"), { role: "option", id: self.uid + "-opt-" + i });
      var pin = setAttrs(el("span", "uc-pin", "◉"), { "aria-hidden": "true" });
      append(li, pin, el("span", null, s.fulltext || ""));
      li.addEventListener("click", function () { self._pick(i); });
      self.$suggest.appendChild(li);
    });
    this._openSuggest();
  };

  Cadastre.prototype._openSuggest = function () { this.$suggest.hidden = false; this.$input.setAttribute("aria-expanded", "true"); };
  Cadastre.prototype._closeSuggest = function () {
    this.$suggest.hidden = true;
    this.$input.setAttribute("aria-expanded", "false");
    this.suggestIndex = -1;
    /* Aucune option active : l'attribut doit disparaître, sans quoi les
       lecteurs d'écran annoncent une option qui n'existe plus. */
    this.$input.removeAttribute("aria-activedescendant");
  };

  Cadastre.prototype._onKey = function (e) {
    if (this.$suggest.hidden) return;
    var items = this.$suggest.querySelectorAll('li[role="option"]');
    if (!items.length) return;
    if (e.key === "ArrowDown") { e.preventDefault(); this.suggestIndex = Math.min(this.suggestIndex + 1, items.length - 1); this._highlight(items); }
    else if (e.key === "ArrowUp") { e.preventDefault(); this.suggestIndex = Math.max(this.suggestIndex - 1, 0); this._highlight(items); }
    else if (e.key === "Enter") { if (this.suggestIndex >= 0) { e.preventDefault(); this._pick(this.suggestIndex); } }
    else if (e.key === "Escape") { this._closeSuggest(); }
  };
  Cadastre.prototype._highlight = function (items) {
    for (var i = 0; i < items.length; i++) items[i].setAttribute("aria-selected", i === this.suggestIndex ? "true" : "false");
    if (this.suggestIndex < 0) {
      this.$input.removeAttribute("aria-activedescendant");
      return;
    }
    if (this.suggestIndex >= 0) {
      this.$input.setAttribute("aria-activedescendant", this.uid + "-opt-" + this.suggestIndex);
      /* scrollIntoView n'existe pas partout (environnements de test, vieux
         navigateurs) : son absence ne doit pas casser la navigation clavier. */
      if (typeof items[this.suggestIndex].scrollIntoView === "function") {
        items[this.suggestIndex].scrollIntoView({ block: "nearest" });
      }
    }
  };

  /* ----- Sélection d'une adresse -> géocodage canonique + carte ----- */
  Cadastre.prototype._pick = function (i) {
    var s = this.suggestions[i];
    if (!s) return;
    this.$input.value = s.fulltext || "";
    this._closeSuggest();
    this._resetParcel();
    this._hideError();
    // /search pour obtenir le code INSEE + coordonnées canoniques
    var url = CONFIG.geocodeSearch + "?q=" + encodeURIComponent(s.fulltext) + "&limit=1&index=address";
    var self = this;
    this.$field.classList.add("is-loading");
    fetchJson(url)
      .then(function (fc) {
        self.$field.classList.remove("is-loading");
        var lon, lat, p = {};
        if (fc && fc.features && fc.features.length) {
          var f = fc.features[0];
          p = f.properties || {};
          lon = f.geometry.coordinates[0]; lat = f.geometry.coordinates[1];
        } else { // repli sur les coordonnées de l'autocomplétion
          lon = s.x; lat = s.y;
        }
        self.data.address = p.label || s.fulltext || "";
        self.data.postcode = p.postcode || s.zipcode || "";
        self.data.city = p.city || s.city || "";
        self.data.cityCode = p.citycode || "";
        self.data.longitude = lon; self.data.latitude = lat;
        self.data.source = "geoplateforme";
        self._showMap(lon, lat);
        self._queryParcel(lon, lat);
      })
      .catch(function () {
        self.$field.classList.remove("is-loading");
        // on tente quand même la carte avec les coords de l'autocomplétion
        if (s.x != null && s.y != null) {
          self.data.longitude = s.x; self.data.latitude = s.y;
          self.data.address = s.fulltext || ""; self.data.city = s.city || ""; self.data.postcode = s.zipcode || "";
          self._showMap(s.x, s.y); self._queryParcel(s.x, s.y);
        } else {
          self._showError("Adresse introuvable. Réessayez ou saisissez une autre adresse.");
        }
      });
  };

  /* ----- Carte ----- */
  Cadastre.prototype._showMap = function (lon, lat) {
    this.$mapWrap.hidden = false;
    if (typeof global.L === "undefined") { this._showError("La carte est momentanément indisponible."); return; }
    if (!this.map) {
      try {
        this.map = global.L.map(this.$mapEl, { scrollWheelZoom: false }).setView([lat, lon], 18);
        this.layers.ortho = global.L.tileLayer(tileUrl(CONFIG.layers.ortho, "image/jpeg"), { maxZoom: 20, attribution: "\u00A9 IGN / G\u00E9oplateforme" }).addTo(this.map);
        this.layers.plan = global.L.tileLayer(tileUrl(CONFIG.layers.plan, "image/png"), { maxZoom: 20, attribution: "\u00A9 IGN / G\u00E9oplateforme" });
        this.layers.cadastre = global.L.tileLayer(tileUrl(CONFIG.layers.cadastre, "image/png"), { maxZoom: 20, opacity: 0.9 }).addTo(this.map);
        var self = this;
        this.map.on("click", function (e) { self._queryParcel(e.latlng.lng, e.latlng.lat, true); });
        /* Une couche IGN qui ne répond pas ne doit pas laisser une carte muette :
           on prévient une fois, sans masquer ce qui s'affiche déjà. */
        this.layers.ortho.on("tileerror", function () { self._tileError(); });
        this.layers.cadastre.on("tileerror", function () { self._tileError(); });
      } catch (err) { this._showError("La carte est momentanément indisponible."); return; }
    } else {
      this.map.setView([lat, lon], 18);
    }
    var m = this.map, self2 = this;
    setTimeout(function () { try { m.invalidateSize(); } catch (e) {} }, 60);
    if (this.pointMarker) this.map.removeLayer(this.pointMarker);
    this.pointMarker = global.L.circleMarker([lat, lon], { radius: 7, color: "#14233B", weight: 2, fillColor: "#54CF99", fillOpacity: 1 }).addTo(this.map);
  };

  Cadastre.prototype._setBase = function (ortho) {
    if (!this.map) return;
    this.baseOrtho = ortho;
    if (ortho) { this.map.addLayer(this.layers.ortho); this.map.removeLayer(this.layers.plan); this.$toggle.textContent = "Vue plan"; }
    else { this.map.addLayer(this.layers.plan); this.map.removeLayer(this.layers.ortho); this.$toggle.textContent = "Vue photo"; }
    if (this.layers.cadastre) this.layers.cadastre.bringToFront();
  };

  /* ----- Parcelle (API Carto cadastre, source PCI) ----- */
  Cadastre.prototype._queryParcel = function (lon, lat, fromClick) {
    if (this.activeParcel) this.activeParcel.abort();
    this.activeParcel = new AbortController();
    var geom = encodeURIComponent(JSON.stringify({ type: "Point", coordinates: [lon, lat] }));
    var url = CONFIG.parcelle + "?source_ign=PCI&geom=" + geom;
    var self = this;
    this._setStatus("Recherche de la parcelle…");
    this._searching(true);
    fetchJson(url, this.activeParcel.signal)
      .then(function (fc) {
        self._searching(false);
        if (!fc || !fc.features || !fc.features.length) {
          self._setStatus("Aucune parcelle ici — zoomez et cliquez sur votre terrain.");
          return;
        }
        self._selectParcel(fc.features[0], !!fromClick);
      })
      .catch(function (err) {
        if (err.name === "AbortError") return;
        self._searching(false);
        self._setStatus("Lecture de la parcelle impossible. Réessayez.");
      });
  };

  Cadastre.prototype._selectParcel = function (feature, deliberate) {
    var p = feature.properties || {};
    this.current = feature;
    // dessin
    if (this.selLayer) this.map.removeLayer(this.selLayer);
    this.selLayer = global.L.geoJSON(feature, { style: { color: "#128A5A", weight: 2, fillColor: "#54CF99", fillOpacity: 0.25 } }).addTo(this.map);
    try { this.map.fitBounds(this.selLayer.getBounds(), { maxZoom: 19, padding: [20, 20] }); } catch (e) {}
    // cartouche
    // cartouche — construit en DOM : `section`, `numero` et `contenance`
    // proviennent d'API Carto et ne sont pas de confiance.
    var sec = p.section || "", num = p.numero || "", area = (p.contenance != null ? p.contenance : "");
    while (this.$parcelRef.firstChild) { this.$parcelRef.removeChild(this.$parcelRef.firstChild); }
    append(this.$parcelRef,
      document.createTextNode("Section "), el("b", null, sec),
      document.createTextNode(" · parcelle "), el("b", null, "n° " + num));
    if (area !== "") {
      var areaWrap = el("span", "uc-area", "Surface indicative : ");
      append(areaWrap, el("b", null, area + " m²"));
      append(this.$parcelRef, el("br"), areaWrap);
    }
    this.$parcel.hidden = false;
    // une sélection (auto ou clic) n'est PAS une confirmation : on attend l'action du client
    this._setConfirmed(false);
    this._setStatus(deliberate ? "Parcelle sélectionnée. Confirmez si c\u2019est la bonne." : "Parcelle probable détectée. Vérifiez puis confirmez.");
  };

  Cadastre.prototype._confirmCurrent = function () {
    if (!this.current) return;
    var p = this.current.properties || {};
    this.data.cadastralSection = p.section || "";
    this.data.cadastralNumber = p.numero || "";
    this.data.cadastralId = p.idu || p.id || "";
    this.data.area = (p.contenance != null ? p.contenance : null);
    this.data.geometry = this.current.geometry || null;
    if (!this.data.city && p.nom_com) this.data.city = p.nom_com;
    if (!this.data.cityCode && p.code_insee) this.data.cityCode = p.code_insee;
    this.data.retrievedAt = new Date().toISOString();
    this._setConfirmed(true);
    this._setStatus("Parcelle confirmée.");
  };

  Cadastre.prototype._rejectParcel = function () {
    this._resetParcel();
    this._setStatus("Cliquez sur votre parcelle sur le plan pour la sélectionner.");
  };

  Cadastre.prototype._resetParcel = function () {
    this.current = null;
    if (this.selLayer && this.map) { this.map.removeLayer(this.selLayer); this.selLayer = null; }
    this.$parcel.hidden = true;
    this._setConfirmed(false);
  };

  Cadastre.prototype._setConfirmed = function (ok) {
    this.confirmed = ok;
    this.$parcel.classList.toggle("is-confirmed", ok);
    this.$confirm.textContent = ok ? "\u2713 Parcelle confirmée" : "Confirmer cette parcelle";
    this.$continue.disabled = !ok;
    this.$continueHint.textContent = ok ? "Vos informations de localisation sont prêtes." : "Confirmez votre parcelle pour continuer.";
  };

  /* ----- Continuer : persiste + émet l'événement + laisse l'hôte réagir ----- */
  Cadastre.prototype._continue = function () {
    if (!this.confirmed) return;
    try { sessionStorage.setItem(this.storageKey, JSON.stringify(this.data)); } catch (e) { /* stockage indisponible : non bloquant */ }
    var evt = new CustomEvent("urbizen:parcel-confirmed", { bubbles: true, detail: this.data });
    this.root.dispatchEvent(evt);
    if (typeof this.opts.onConfirm === "function") this.opts.onConfirm(this.data);
  };

  /* Signale une seule fois l'indisponibilité des tuiles. */
  Cadastre.prototype._tileError = function () {
    if (this._tileErrorShown) { return; }
    this._tileErrorShown = true;
    this._setStatus("Le fond de carte ne répond pas. Vous pouvez réessayer plus tard ; la sélection de parcelle reste possible.");
  };

  /* Démonte proprement l'instance : carte Leaflet, écouteurs, DOM et marqueur
     de montage. Après appel, le conteneur peut être remonté par autoMount(). */
  Cadastre.prototype.destroy = function () {
    if (this.activeGeocode) { try { this.activeGeocode.abort(); } catch (e) {} }
    if (this.activeParcel) { try { this.activeParcel.abort(); } catch (e) {} }
    if (this._onDocClick) { document.removeEventListener("click", this._onDocClick); this._onDocClick = null; }
    if (this.map) { try { this.map.remove(); } catch (e) {} this.map = null; }
    this.layers = {};
    this.selLayer = null;
    this.pointMarker = null;
    if (this.root) {
      while (this.root.firstChild) { this.root.removeChild(this.root.firstChild); }
      this.root.classList.remove("uc-root");
      this.root.removeAttribute("data-uc-mounted");
      delete this.root.urbizenCadastre;
    }
    return true;
  };

  Cadastre.prototype._setStatus = function (txt) { if (this.$status) this.$status.textContent = txt; };
  Cadastre.prototype._searching = function (on) { if (this.$statusWrap) this.$statusWrap.classList.toggle("is-searching", on); };
  Cadastre.prototype._showError = function (msg) { this.$error.textContent = msg; this.$error.hidden = false; };
  Cadastre.prototype._hideError = function () { this.$error.hidden = true; };

  /* ==========================================================================
     API publique
     ========================================================================== */
  var UrbizenCadastre = {
    config: CONFIG,
    mount: function (container, options) { return new Cadastre(container, options); },

    /** Récupère les dernières données confirmées (page d'accueil -> formulaires) */
    getStored: function (key) {
      try { return JSON.parse(sessionStorage.getItem(storageKeyFor(key))); }
      catch (e) { return null; }
    },

    /** Efface les données de localisation conservées dans l'onglet.
        À appeler après reprise dans un formulaire, ou sur demande du visiteur. */
    clearStored: function (key) {
      try { sessionStorage.removeItem(storageKeyFor(key)); return true; }
      catch (e) { return false; }
    },

    /** Monte automatiquement tout conteneur `[data-urbizen-cadastre]`.
        Les options sont lues sur des attributs `data-*` : c'est ce que rend le
        bloc côté PHP, après échappement. Idempotent : un conteneur déjà monté
        est ignoré. */
    autoMount: function (scope) {
      var nodes = (scope || document).querySelectorAll("[data-urbizen-cadastre]");
      var mounted = [];
      for (var i = 0; i < nodes.length; i++) {
        var n = nodes[i];
        if (n.getAttribute("data-uc-mounted") === "1") { continue; }
        n.setAttribute("data-uc-mounted", "1");
        var inst = new Cadastre(n, {
          label:         n.getAttribute("data-label") || undefined,
          placeholder:   n.getAttribute("data-placeholder") || undefined,
          continueLabel: n.getAttribute("data-continue-label") || undefined,
          storageKey:    n.getAttribute("data-storage-key") || undefined,
          mapHeight:     n.getAttribute("data-map-height") || undefined
        });
        /* Accessible depuis l'hôte : instance.destroy(), lecture de instance.data. */
        n.urbizenCadastre = inst;
        mounted.push(inst);
      }
      return mounted;
    }
  };

  global.UrbizenCadastre = UrbizenCadastre;
  if (typeof module !== "undefined" && module.exports) module.exports = UrbizenCadastre;

  /* Montage automatique des conteneurs rendus par le bloc ou le shortcode. */
  if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", function () { UrbizenCadastre.autoMount(); });
    } else {
      UrbizenCadastre.autoMount();
    }
  }

})(typeof window !== "undefined" ? window : this);
