/* ============================================================================
   urbizen-cadastre.js
   Composant cadastre réutilisable Urbizen.
   Se monte dans un conteneur, gère : saisie d'adresse avec autocomplétion,
   carte (photo aérienne + parcellaire), sélection et confirmation de parcelle,
   persistance sessionStorage, événement `urbizen:parcel-confirmed`.

   Réutilisable : page d'accueil, formulaire DP, formulaire PCMI, espace client.
   Dépendances : Leaflet (window.L), urbizen-cadastre.css, urbizen-tokens.css.

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
    storageKey: "urbizen:parcel"
  };

  function tileUrl(layer, fmt) { return CONFIG.wmts + "&LAYER=" + layer + "&FORMAT=" + fmt; }

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

  function el(tag, cls, html) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html != null) e.innerHTML = html;
    return e;
  }

  /* ==========================================================================
     Instance du composant
     ========================================================================== */
  function Cadastre(container, options) {
    this.opts = options || {};
    this.root = typeof container === "string" ? document.querySelector(container) : container;
    if (!this.root) { console.warn("[urbizen-cadastre] conteneur introuvable"); return; }
    this.storageKey = this.opts.storageKey || CONFIG.storageKey;
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

  Cadastre.prototype._build = function () {
    this.root.classList.add("uc-root");
    var labelText = this.opts.label || "Adresse du projet";
    var placeholder = this.opts.placeholder || "Commencez à saisir une adresse…";

    this.root.innerHTML =
      '<div class="uc-field">' +
        '<label class="uc-label" for="uc-input">' + labelText + '</label>' +
        '<input class="uc-input" id="uc-input" type="text" autocomplete="off" ' +
          'role="combobox" aria-expanded="false" aria-autocomplete="list" ' +
          'aria-controls="uc-suggest" placeholder="' + placeholder + '">' +
        '<span class="uc-spinner" aria-hidden="true"></span>' +
        '<ul class="uc-suggest" id="uc-suggest" role="listbox" hidden></ul>' +
      '</div>' +
      '<div class="uc-error" role="alert" hidden></div>' +
      '<div class="uc-map-wrap" hidden>' +
        '<div class="uc-map-bar">' +
          '<span class="uc-status" role="status" aria-live="polite"><span class="uc-dot"></span><span class="uc-status-txt">Cliquez sur votre parcelle pour la confirmer.</span></span>' +
          '<button type="button" class="uc-toggle">Vue plan</button>' +
        '</div>' +
        '<div class="uc-map" id="uc-map"></div>' +
        '<div class="uc-parcel" hidden>' +
          '<div>' +
            '<div class="uc-parcel-head">Parcelle sélectionnée</div>' +
            '<div class="uc-parcel-body"><span class="uc-parcel-ref">—</span></div>' +
          '</div>' +
          '<div class="uc-parcel-actions">' +
            '<button type="button" class="uc-btn uc-btn-confirm">Confirmer cette parcelle</button>' +
            '<button type="button" class="uc-link">Ce n\u2019est pas la bonne parcelle</button>' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div class="uc-continue-row">' +
        '<button type="button" class="uc-continue" disabled>' + (this.opts.continueLabel || "Continuer") + '</button>' +
        '<span class="uc-continue-hint">Confirmez votre parcelle pour continuer.</span>' +
      '</div>';

    this.$input   = this.root.querySelector(".uc-input");
    this.$field   = this.root.querySelector(".uc-field");
    this.$suggest = this.root.querySelector(".uc-suggest");
    this.$error   = this.root.querySelector(".uc-error");
    this.$mapWrap = this.root.querySelector(".uc-map-wrap");
    this.$mapEl   = this.root.querySelector(".uc-map");
    this.$status  = this.root.querySelector(".uc-status-txt");
    this.$statusWrap = this.root.querySelector(".uc-status");
    this.$toggle  = this.root.querySelector(".uc-toggle");
    this.$parcel  = this.root.querySelector(".uc-parcel");
    this.$parcelRef = this.root.querySelector(".uc-parcel-ref");
    this.$confirm = this.root.querySelector(".uc-btn-confirm");
    this.$reject  = this.root.querySelector(".uc-link");
    this.$continue = this.root.querySelector(".uc-continue");
    this.$continueHint = this.root.querySelector(".uc-continue-hint");
  };

  Cadastre.prototype._bind = function () {
    var self = this;
    var onType = debounce(function () { self._complete(self.$input.value.trim()); }, CONFIG.debounceMs);
    this.$input.addEventListener("input", onType);
    this.$input.addEventListener("keydown", function (e) { self._onKey(e); });
    this.$input.addEventListener("focus", function () { if (self.suggestions.length) self._openSuggest(); });
    document.addEventListener("click", function (e) { if (!self.root.contains(e.target)) self._closeSuggest(); });

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
    this.$suggest.innerHTML = "";
    this.suggestIndex = -1;
    if (networkError) {
      this.$suggest.appendChild(el("li", "uc-empty", "Recherche indisponible. Réessayez."));
      this._openSuggest(); return;
    }
    if (!this.suggestions.length) {
      this.$suggest.appendChild(el("li", "uc-empty", "Aucune adresse trouvée."));
      this._openSuggest(); return;
    }
    var self = this;
    this.suggestions.forEach(function (s, i) {
      var li = el("li", null, '<span class="uc-pin">\u25C9</span><span>' + (s.fulltext || "") + "</span>");
      li.setAttribute("role", "option");
      li.setAttribute("id", "uc-opt-" + i);
      li.addEventListener("click", function () { self._pick(i); });
      self.$suggest.appendChild(li);
    });
    this._openSuggest();
  };

  Cadastre.prototype._openSuggest = function () { this.$suggest.hidden = false; this.$input.setAttribute("aria-expanded", "true"); };
  Cadastre.prototype._closeSuggest = function () { this.$suggest.hidden = true; this.$input.setAttribute("aria-expanded", "false"); this.suggestIndex = -1; };

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
    if (this.suggestIndex >= 0) {
      this.$input.setAttribute("aria-activedescendant", "uc-opt-" + this.suggestIndex);
      items[this.suggestIndex].scrollIntoView({ block: "nearest" });
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
    var sec = p.section || "", num = p.numero || "", area = (p.contenance != null ? p.contenance : "");
    this.$parcelRef.innerHTML =
      "Section <b>" + sec + "</b> &middot; parcelle <b>n\u00B0" + num + "</b>" +
      (area !== "" ? '<br><span class="uc-area">Surface indicative : <b>' + area + " m\u00B2</b></span>" : "");
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
      try { return JSON.parse(sessionStorage.getItem(key || CONFIG.storageKey)); }
      catch (e) { return null; }
    }
  };

  global.UrbizenCadastre = UrbizenCadastre;
  if (typeof module !== "undefined" && module.exports) module.exports = UrbizenCadastre;

})(typeof window !== "undefined" ? window : this);
