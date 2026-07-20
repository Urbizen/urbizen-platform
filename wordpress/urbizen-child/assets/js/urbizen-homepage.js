/* ============================================================================
   homepage.js — logique de la page d'accueil Urbizen.
   Copie de frontend/homepage/homepage.js, à une différence près : le montage
   manuel du cadastre est retiré, le bloc WordPress s'en charge.
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

  /* ----- Sélection du type de projet + routage vers le formulaire ----- */
  // URLs des formulaires. À ajuster lors de l'intégration WordPress
  // (ex. "/commander-un-dossier/" et "/permis-de-construire/demande/").
  var FORM_URLS = {
    dp:   "../formulaires/dp-formulaire.html",
    pcmi: "../formulaires/pc-formulaire.html"
  };
  // Projets orientés permis de construire ; les autres démarrent en déclaration
  // préalable (Urbizen confirme la démarche après étude — pas de détermination définitive ici).
  var PC_PROJETS = ["maison"];

  var selectedProjet = null;
  var continueBtn = document.getElementById("js-continue");
  var continueHint = document.getElementById("js-continue-hint");
  var cards = document.querySelectorAll(".pcard");

  cards.forEach(function (card) {
    card.setAttribute("aria-pressed", "false");
    card.addEventListener("click", function () {
      cards.forEach(function (c) { c.classList.remove("is-selected"); c.setAttribute("aria-pressed", "false"); });
      card.classList.add("is-selected");
      card.setAttribute("aria-pressed", "true");
      selectedProjet = card.getAttribute("data-projet");
      try { sessionStorage.setItem("urbizen:projet", selectedProjet); } catch (e) {}
      if (continueBtn) {
        continueBtn.disabled = false;
        if (continueHint) continueHint.textContent = "Vos informations de localisation seront reprises dans le formulaire.";
      }
    });
  });

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
