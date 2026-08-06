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
 *
 * **Le protocole ne repose pas sur un message unique.** Le document demande sa
 * configuration dès qu'il est prêt, mais rien ne garantit que cette page ait
 * déjà installé son écouteur : son script est chargé en pied de page, et un
 * cadre servi depuis le cache peut être prêt bien avant. Un `ready` perdu
 * laissait alors le formulaire définitivement non initialisé, sans recours.
 *
 * D'où trois garanties, qui se complètent :
 *
 * 1. le document **répète** sa demande, selon une temporisation bornée ;
 * 2. cette page **émet aussi** la configuration au `load` du cadre, sans
 *    attendre d'être sollicitée ;
 * 3. le document **accuse réception** — `urbizen_form_configured` — et tout
 *    renvoi cesse alors.
 *
 * Les renvois sont idempotents : le document verrouille sa configuration au
 * premier message valide et ignore les suivants, même identiques.
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

  /**
   * Le cadre a-t-il accusé réception de sa configuration ?
   *
   * Marqué sur l'élément lui-même : deux cadres sur une même page — cas qui
   * n'existe pas aujourd'hui mais que rien n'interdit — gardent chacun leur
   * état, et un rechargement du cadre remet le drapeau à zéro puisqu'un nouvel
   * élément n'en porte pas.
   */
  function estConfigure(cadre) {
    return "1" === cadre.getAttribute("data-urbizen-configured");
  }

  /** Le cadre attendu, ou null : la fenêtre émettrice doit être l'un des nôtres. */
  function cadreDe(source) {
    var trouve = null;

    document.querySelectorAll("[data-urbizen-form-frame]").forEach(function (candidat) {
      if (candidat.contentWindow === source) trouve = candidat;
    });

    if (!trouve) return null;

    // Le cadre doit servir le document que la configuration désigne. Un cadre
    // pointant ailleurs n'obtient rien, même s'il est bien le nôtre.
    var src = trouve.getAttribute("src") || "";

    if (config.frameSource && -1 === src.indexOf(config.frameSource)) return null;

    return trouve;
  }

  /**
   * Remet sa configuration à un cadre, à l'origine exacte.
   *
   * Idempotent : réémettre après l'accusé ne se produit pas, et réémettre avant
   * est sans effet — le document verrouille au premier message valide.
   */
  function emettre(cadre) {
    if (!cadre || estConfigure(cadre)) return;

    var fenetre = cadre.contentWindow;

    if (!fenetre) return;

    fenetre.postMessage(
      {
        type: "urbizen_form_config",
        action: config.action,
        formType: config.formType,
        nonceField: config.nonceField,
        nonce: config.nonce,
        // Le jeton anti-robot voyage avec le nonce, et pour la même raison : il
        // est signé côté serveur, et le document statique ne peut pas le
        // produire. Sans lui, la route refuse toute soumission.
        tokenField: config.tokenField,
        token: config.token,
        honeypotField: config.honeypotField,
        // La matrice métier, telle que le serveur la déclare. Le document ne la
        // recopie pas : il l'applique.
        matrice: config.matrice,
        champsConditionnels: config.champsConditionnels,
        submitUrl: config.submitUrl
      },
      ORIGINE
    );
  }

  window.addEventListener("message", function (event) {
    // --- origine ---
    if (event.origin !== ORIGINE) return;

    var message = event.data;

    if (!message || "object" !== typeof message) return;

    // --- source : exactement l'un de nos cadres, servant le bon document ---
    var cadre = cadreDe(event.source);

    if (!cadre) return;

    // --- accusé : on cesse de renvoyer ---
    if ("urbizen_form_configured" === message.type) {
      cadre.setAttribute("data-urbizen-configured", "1");

      return;
    }

    if ("urbizen_form_ready" !== message.type) return;

    emettre(cadre);
  });

  // La configuration part aussi au `load` du cadre, sans attendre d'être
  // sollicitée : c'est ce qui couvre le cas où le tout premier « ready » a été
  // émis avant que cet écouteur n'existe. `load` peut se produire plusieurs
  // fois — rechargement, navigation interne — et chaque émission reste sans
  // effet une fois l'accusé reçu.
  document.querySelectorAll("[data-urbizen-form-frame]").forEach(function (cadre) {
    cadre.addEventListener("load", function () {
      emettre(cadre);
    });

    // Le cadre a pu terminer son chargement avant que ce script ne s'exécute :
    // `load` ne se rejouera pas, et sans cette émission immédiate le document
    // dépendrait entièrement de ses propres répétitions.
    emettre(cadre);
  });
})();
