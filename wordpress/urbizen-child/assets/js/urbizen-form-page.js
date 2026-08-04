/**
 * Coque des pages de formulaire d'autorisation.
 *
 * Deux rôles, tous deux côté parent :
 *
 * 1. **Redimensionner le cadre** à la hauteur de son contenu, pour que la page
 *    n'ait qu'une seule barre de défilement.
 * 2. **Remettre au document sa configuration de soumission** — URL, action,
 *    type, nonce. Ces valeurs sont produites par PHP sur cette page ; le
 *    document servi dans le cadre est un fichier statique du thème, qu'aucun
 *    PHP ne rend, et qui ne peut donc pas porter un nonce.
 *
 * Pourquoi `postMessage` plutôt qu'une lecture directe : le cadre est de même
 * origine, un accès direct fonctionnerait. Mais un échange explicite permet de
 * vérifier les deux extrémités, refuse une fenêtre qui ne serait pas le cadre
 * attendu, et resterait valable si le document venait à être servi ailleurs.
 * Le nonce ne passe jamais par l'URL du cadre : il s'y retrouverait dans
 * l'historique, dans les journaux d'accès et dans tout `Referer` sortant.
 */
(function () {
  "use strict";

  var config = window.urbizenFormConfig || null;

  /**
   * Origine attendue des messages.
   *
   * Faute de configuration serveur, on retombe sur l'origine de la page
   * courante — jamais sur « * », qui reviendrait à parler à n'importe qui.
   */
  var ORIGINE = (config && config.origin) || window.location.origin;

  /* ------------------------------------------------------------------ *
   *  1 · hauteur du cadre
   * ------------------------------------------------------------------ */

  document.querySelectorAll("[data-urbizen-form-frame]").forEach(function (frame) {
    frame.addEventListener("load", function () {
      var doc;

      try {
        doc = frame.contentDocument;
      } catch (e) {
        return;
      }

      if (!doc || !doc.documentElement) return;

      var resize = function () {
        var bodyHeight = doc.body ? doc.body.scrollHeight : 0;
        var rootHeight = doc.documentElement.scrollHeight || 0;
        frame.style.height = Math.max(bodyHeight, rootHeight, 900) + "px";
      };

      resize();

      if ("ResizeObserver" in window && doc.body) {
        var observer = new ResizeObserver(resize);
        observer.observe(doc.body);
      }

      window.addEventListener("resize", resize);
    });
  });

  /* ------------------------------------------------------------------ *
   *  2 · pont de configuration
   * ------------------------------------------------------------------ */

  if (!config) {
    // Page non raccordée : on ne répond à rien. Le document restera non
    // soumettable, ce qui vaut mieux qu'un formulaire postant dans le vide.
    return;
  }

  window.addEventListener("message", function (event) {
    // --- origine ---
    if (event.origin !== ORIGINE) return;

    // --- type et forme du message ---
    var message = event.data;

    if (!message || "object" !== typeof message || "urbizen_form_ready" !== message.type) {
      return;
    }

    // --- source : exactement le cadre attendu ---
    // `event.source` doit être la fenêtre d'un de nos cadres, et ce cadre doit
    // servir le document que la configuration désigne. Une fenêtre tierce, ou
    // un cadre pointant ailleurs, n'obtient rien.
    var cadre = null;

    document.querySelectorAll("[data-urbizen-form-frame]").forEach(function (candidat) {
      if (candidat.contentWindow === event.source) cadre = candidat;
    });

    if (!cadre) return;

    var source = cadre.getAttribute("src") || "";

    if (config.frameSource && -1 === source.indexOf(config.frameSource)) return;

    // --- réponse, à l'origine exacte ---
    event.source.postMessage(
      {
        type: "urbizen_form_config",
        action: config.action,
        formType: config.formType,
        nonceField: config.nonceField,
        nonce: config.nonce,
        submitUrl: config.submitUrl
      },
      ORIGINE
    );
  });
})();
