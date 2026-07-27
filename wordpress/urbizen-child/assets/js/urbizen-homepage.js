/* ============================================================================
   homepage.js — logique de la page d'accueil Urbizen.
   Copie de frontend/homepage/homepage.js, à deux différences près :
     1. le montage manuel du cadastre est retiré, le bloc WordPress s'en charge ;
     2. la sélection des cartes projet est conditionnée à la présence du bouton
        de continuation (le tunnel de l'accueil). La même feuille étant partagée
        par les pages internes, leurs vignettes .pcard y restent informatives.
   Chargé en `defer` : le DOM est prêt à l'exécution.
   ========================================================================== */
(function () {
  "use strict";

  /* ----- Menu mobile ----- */
  var burger = document.querySelector(".burger");
  var mmenu = document.getElementById("mmenu");
  if (burger && mmenu) {
    burger.addEventListener("click", function () {
      var open = mmenu.hidden;
      mmenu.hidden = !open;
      burger.setAttribute("aria-expanded", open ? "true" : "false");
    });
    mmenu.addEventListener("click", function (e) {
      if (e.target.tagName === "A") { mmenu.hidden = true; burger.setAttribute("aria-expanded", "false"); }
    });
  }

  /* ----- Centre de contact « Parlons de votre projet » -----
     Dialogue accessible ouvert par l'icône téléphone. À la 1re ouverture, le
     panneau ET son fond d'écran sont déplacés sous <body> : cela échappe au bloc
     englobant créé par le `backdrop-filter` du header (sinon `position: fixed`
     était relatif au header et le panneau apparaissait tronqué — seule la
     dernière action visible). L'ouverture ne déclenche AUCUN appel ; seul le lien
     tel: appelle, sur clic. Fermeture : croix, Échap, clic sur le fond. Focus
     déplacé dans le panneau sans faire défiler la page (preventScroll), restitué
     à l'icône à la fermeture ; Tab piégé ; défilement de fond verrouillé. */
  var telBtn = document.querySelector(".link-tel");
  var panel = document.getElementById("contact-panel");
  if (telBtn && panel) {
    var closeBtn = panel.querySelector(".contact-close");
    // Hôte de reparentage : le wrapper `.urbizen-accueil` (et NON <body>), pour
    // sortir le panneau du header — dont le `backdrop-filter` créait un bloc
    // englobant qui tronquait le `position: fixed` — tout en CONSERVANT le style
    // scopé `.urbizen-accueil .contact-panel`. Repli <body> pour la maquette
    // autonome (CSS non scopé).
    var host = telBtn.closest(".urbizen-accueil") || document.body;
    var docEl = document.documentElement;
    var prevOverflow = "";
    var backdrop = null;
    var moved = false;
    var focusSafe = function (el) { if (!el) return; try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } };
    var onKeydown = function (e) {
      if (e.key === "Escape" || e.key === "Esc") { closeContact(true); return; }
      if (e.key !== "Tab") return;
      var f = panel.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    var openContact = function () {
      if (!moved) {
        host.appendChild(panel);
        backdrop = document.createElement("div");
        backdrop.className = "contact-backdrop";
        backdrop.hidden = true;
        backdrop.addEventListener("click", function () { closeContact(true); });
        host.appendChild(backdrop);
        moved = true;
      }
      backdrop.hidden = false;
      panel.hidden = false;
      telBtn.setAttribute("aria-expanded", "true");
      prevOverflow = docEl.style.overflow;   // verrou de défilement (style en ligne, insensible au scoping)
      docEl.style.overflow = "hidden";
      focusSafe(closeBtn || panel);
      document.addEventListener("keydown", onKeydown);
    };
    var closeContact = function (restore) {
      if (panel.hidden) return;
      panel.hidden = true;
      if (backdrop) backdrop.hidden = true;
      telBtn.setAttribute("aria-expanded", "false");
      docEl.style.overflow = prevOverflow;
      document.removeEventListener("keydown", onKeydown);
      if (restore !== false) focusSafe(telBtn);
    };
    telBtn.addEventListener("click", function () { if (panel.hidden) openContact(); else closeContact(true); });
    if (closeBtn) closeBtn.addEventListener("click", function () { closeContact(true); });
  }

  /* ----- Sélection du type de projet + routage vers le formulaire ----- */
  // URLs internes réelles (pages WordPress publiées). Aucun chemin relatif de
  // maquette : selon la nature du projet, on oriente vers la page du service
  // correspondant, où la démarche est confirmée par Urbizen.
  var FORM_URLS = {
    dp:   "/declarations-prealables/",
    pcmi: "/permis-de-construire/"
  };
  // Projets orientés permis de construire ; les autres démarrent en déclaration
  // préalable (Urbizen confirme la démarche après étude — pas de détermination définitive ici).
  var PC_PROJETS = ["maison"];

  var selectedProjet = null;
  var continueBtn = document.getElementById("js-continue");
  var continueHint = document.getElementById("js-continue-hint");
  var cards = document.querySelectorAll(".pcard");

  // La sélection des cartes n'a de sens que dans le tunnel de l'accueil, dont le
  // bouton de continuation est le marqueur naturel. Ailleurs (pages internes),
  // les mêmes vignettes .pcard restent purement informatives : aucun écouteur,
  // aucun aria-pressed, aucun état sélectionné qui promettrait une suite.
  if (continueBtn) {
    cards.forEach(function (card) {
      card.setAttribute("aria-pressed", "false");
      card.addEventListener("click", function () {
        cards.forEach(function (c) { c.classList.remove("is-selected"); c.setAttribute("aria-pressed", "false"); });
        card.classList.add("is-selected");
        card.setAttribute("aria-pressed", "true");
        selectedProjet = card.getAttribute("data-projet");
        try { sessionStorage.setItem("urbizen:projet", selectedProjet); } catch (e) {}
        continueBtn.disabled = false;
        if (continueHint) continueHint.textContent = "Vos informations de localisation seront reprises dans le formulaire.";
      });
    });
  }

  if (continueBtn) {
    continueBtn.addEventListener("click", function () {
      if (!selectedProjet) return;
      // adresse, parcelle et projet sont déjà conservés en sessionStorage :
      // le formulaire (branche dédiée) les relira pour se pré-remplir.
      var form = PC_PROJETS.indexOf(selectedProjet) !== -1 ? "pcmi" : "dp";
      window.location.href = FORM_URLS[form];
    });
  }

  /* ----- Composant cadastre -----
     Aucun montage manuel ici : sous WordPress, le bloc `urbizen/cadastre`
     rend son propre conteneur et urbizen-cadastre.js le monte via
     autoMount(). Un mount() supplémentaire provoquerait un double montage.
     Les libellés et la clé de stockage « accueil » sont portés par les
     attributs du bloc, dans le gabarit. */

  /* ----- Réaction à la confirmation de parcelle -----
     Pour cette première version : on conserve les données (déjà persistées en
     sessionStorage par le composant) et on fait défiler vers l'étape suivante.
     Pas de redirection forcée vers les formulaires. */
  document.addEventListener("urbizen:parcel-confirmed", function (e) {
    var next = document.getElementById("projet");
    if (next) next.scrollIntoView({ behavior: "smooth", block: "start" });
    // e.detail contient l'objet de localisation, réutilisable ultérieurement.
  });

  /* ----- Défilement doux vers la localisation pour les CTA "Démarrer" ----- */
  document.querySelectorAll(".js-start").forEach(function (a) {
    a.addEventListener("click", function (ev) {
      var target = document.getElementById("localisation");
      if (target) {
        ev.preventDefault();
        target.scrollIntoView({ behavior: "smooth", block: "start" });
        var input = target.querySelector(".uc-input");
        if (input) setTimeout(function () { input.focus(); }, 500);
      }
    });
  });

})();
