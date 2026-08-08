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

  /* Une seule lecture de la préférence de mouvement, partagée par tous les
     défilements de cette feuille. Déclarée ici parce que le chemin « Écrire à
     Urbizen » s'en sert bien avant l'animation du hero. */
  var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var glissement = function () { return reduceMotion ? "auto" : "smooth"; };
  /* Donne le focus sans faire défiler la page : le défilement est piloté
     séparément, et un focus qui saute annulerait le mouvement en cours. */
  var focusSafe = function (el) { if (!el) return; try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); } };

  /* ----- Menu mobile -----
     `closeMobileMenu` est extraite parce que deux chemins la demandent : un lien
     ordinaire du menu, et « Écrire à Urbizen » qui doit refermer le menu avant
     de déplier le formulaire. Une seule fonction touche `hidden` et
     `aria-expanded` — les laisser diverger était le risque. */
  var burger = document.querySelector(".burger");
  var mmenu = document.getElementById("mmenu");
  var closeMobileMenu = function () {};
  if (burger && mmenu) {
    closeMobileMenu = function () {
      if (mmenu.hidden) { return; }
      mmenu.hidden = true;
      burger.setAttribute("aria-expanded", "false");
    };
    burger.addEventListener("click", function () {
      var open = mmenu.hidden;
      mmenu.hidden = !open;
      burger.setAttribute("aria-expanded", open ? "true" : "false");
    });
    // `closest` plutôt que `tagName` : un lien du menu peut contenir du balisage,
    // et le clic atterrir sur un enfant. Le lien restait alors ouvert derrière.
    mmenu.addEventListener("click", function (e) {
      if (e.target.closest && e.target.closest("a")) { closeMobileMenu(); }
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
  var contactTriggers = document.querySelectorAll(".link-tel, .js-open-contact");
  var telBtn = document.querySelector(".link-tel");
  var panel = document.getElementById("contact-panel");
  if (contactTriggers.length && panel) {
    var closeBtn = panel.querySelector(".contact-close");
    // Hôte de reparentage : le wrapper `.urbizen-accueil` (et NON <body>), pour
    // sortir le panneau du header — dont le `backdrop-filter` créait un bloc
    // englobant qui tronquait le `position: fixed` — tout en CONSERVANT le style
    // scopé `.urbizen-accueil .contact-panel`. Repli <body> pour la maquette
    // autonome (CSS non scopé).
    var activeTrigger = telBtn || contactTriggers[0];
    var host = activeTrigger.closest(".urbizen-accueil") || document.body;
    var docEl = document.documentElement;
    var prevOverflow = "";
    var backdrop = null;
    var moved = false;
    var onKeydown = function (e) {
      if (e.key === "Escape" || e.key === "Esc") { closeContact(true); return; }
      if (e.key !== "Tab") return;
      var f = panel.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    };
    var openContact = function (trigger) {
      activeTrigger = trigger || activeTrigger;
      if (!moved) {
        host.appendChild(panel);
        backdrop = document.createElement("div");
        backdrop.className = "contact-backdrop";
        backdrop.hidden = true;
        backdrop.addEventListener("click", function () { closeContact(true); });
        host.appendChild(backdrop);
        moved = true;
      }
      contactTriggers.forEach(function (item) { item.setAttribute("aria-expanded", "false"); });
      activeTrigger.setAttribute("aria-expanded", "true");
      backdrop.hidden = false;
      panel.hidden = false;
      prevOverflow = docEl.style.overflow;
      docEl.style.overflow = "hidden";
      focusSafe(closeBtn || panel);
      document.addEventListener("keydown", onKeydown);
    };
    var closeContact = function (restore) {
      if (panel.hidden) return;
      panel.hidden = true;
      if (backdrop) backdrop.hidden = true;
      contactTriggers.forEach(function (item) { item.setAttribute("aria-expanded", "false"); });
      docEl.style.overflow = prevOverflow;
      document.removeEventListener("keydown", onKeydown);
      if (restore !== false) focusSafe(activeTrigger);
    };
    contactTriggers.forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        if (panel.hidden) openContact(trigger);
        else closeContact(true);
      });
    });
    if (closeBtn) closeBtn.addEventListener("click", function () { closeContact(true); });
  }

  /* ----- Formulaire de renseignements dépliant -----
     `setInquiry` est le seul endroit qui touche à l'état du bloc : sa visibilité,
     l'`aria-expanded` du bouton et son libellé. `openInquiry` en dérive et
     garantit l'ouverture sans jamais refermer — un second « Écrire à Urbizen »
     sur un bloc déjà ouvert le laisserait ouvert, là où un basculement l'aurait
     refermé sous le doigt de quelqu'un qui venait justement l'atteindre. */
  var inquiryToggle = document.querySelector(".js-toggle-inquiry");
  var inquiryPanel = document.getElementById("cta-inquiry-form");
  var inquirySection = document.getElementById("demander-des-renseignements");
  var inquiryTitle = document.getElementById("titre-renseignements");
  var setInquiry = function () {};
  var openInquiry = function () {};
  if (inquiryToggle && inquiryPanel) {
    setInquiry = function (open) {
      inquiryPanel.hidden = !open;
      inquiryToggle.setAttribute("aria-expanded", open ? "true" : "false");
      var label = inquiryToggle.querySelector("span");
      if (label) { label.textContent = open ? "Fermer le formulaire" : "Demander des renseignements"; }
    };
    openInquiry = function () {
      if (inquiryPanel.hidden) { setInquiry(true); }
    };
    inquiryToggle.addEventListener("click", function () {
      var ouvre = inquiryPanel.hidden;
      setInquiry(ouvre);
      if (ouvre) { inquiryPanel.scrollIntoView({ behavior: glissement(), block: "nearest" }); }
    });
  }

  /* ----- « Écrire à Urbizen » → formulaire de renseignements -----
     Deux accès, un seul chemin : le canal du panneau « Parlons de votre projet »
     et l'entrée du menu mobile portent la même classe. Les deux sont de vrais
     liens vers l'ancre : sans JavaScript, la page descend quand même au bon
     endroit ; le script n'ajoute que le dépliage, la fermeture des surfaces
     ouvertes et le placement du focus.

     L'ordre compte. `closeContact(false)` d'abord : il rend le défilement du
     document, que le dialogue verrouille — sans cela, aucun défilement ne serait
     possible. `false` évite de renvoyer le focus sur l'icône qu'on quitte.
     Le focus va sur le titre du bloc, jamais dans un champ : viser le premier
     champ du formulaire lèverait le clavier virtuel sur téléphone, en masquant
     la moitié de ce qu'on vient d'ouvrir. */
  var inquiryLinks = document.querySelectorAll(".js-open-inquiry");
  if (inquiryLinks.length && inquiryPanel && inquirySection) {
    inquiryLinks.forEach(function (link) {
      link.addEventListener("click", function (ev) {
        ev.preventDefault();
        if (typeof closeContact === "function") { closeContact(false); }
        closeMobileMenu();
        openInquiry();
        inquirySection.scrollIntoView({ behavior: glissement(), block: "start" });
        // `preventScroll` : le focus ne doit pas court-circuiter le défilement.
        focusSafe(inquiryTitle || inquiryPanel);
      });
    });
  }

  /* ----- Arrivée par l'ancre -----
     Depuis une page interne, le lien est une navigation véritable : le clic ne
     peut pas être intercepté, la page change. Le navigateur saute bien à la
     section — mais le formulaire, lui, reste fermé, et l'on arrive devant un
     bouton à cliquer là où on venait de demander le formulaire. Manque trouvé en
     recette de production sur e114d69.

     La règle est volontairement asymétrique : le bon hash **garantit** l'état
     ouvert, un autre hash ne referme rien. Refermer sur changement d'ancre
     ferait disparaître un formulaire à moitié rempli au premier clic vers une
     autre section.

     Aucun état n'est touché ici : tout passe par `openInquiry()`. */
  var ANCRE_RENSEIGNEMENTS = "#demander-des-renseignements";

  /* `anime` distingue les deux entrées. À l'arrivée sur la page, non : le
     navigateur a déjà sauté quelque part, et une glissade de douze mille pixels
     depuis une position fausse n'est pas une transition, c'est du bruit — mesuré
     à deux secondes et demie d'animation avant de se poser. Sur `hashchange`, la
     page ne bouge pas sous les pieds et le mouvement fait sens ; il passe alors
     par `glissement()`, donc par la préférence de l'utilisateur.

     `"instant"` et non `"auto"` : `auto` hérite du `scroll-behavior: smooth` de
     la feuille, et animait donc malgré l'intention. `instant` n'anime jamais —
     il ne peut pas contredire une préférence de mouvement réduit. */
  var openInquiryFromHash = function (anime) {
    if (window.location.hash !== ANCRE_RENSEIGNEMENTS) { return; }
    if (!inquiryPanel || !inquirySection) { return; }
    openInquiry();

    /* Le saut d'ancre du navigateur a lieu avant que la page ait fini de se
       poser : images et polices arrivent après et repoussent le contenu. Mesuré
       sur la maquette, la section finissait à trois mille pixels de sa cible.
       Deux trames suffisent à laisser la mise en page se stabiliser — la
       première applique l'ouverture, la seconde la mesure. */
    window.requestAnimationFrame(function () {
      window.requestAnimationFrame(function () {
        inquirySection.scrollIntoView({ behavior: anime ? glissement() : "instant", block: "start" });
      });
    });
  };

  if (inquiryPanel && inquirySection) {
    openInquiryFromHash(false);
    // Retour arrière, lien vers l'ancre depuis la page elle-même, ancre saisie
    // à la main : le navigateur ne recharge pas, seul `hashchange` le signale.
    window.addEventListener("hashchange", function () { openInquiryFromHash(true); });
  }

  /* ----- Planche du HERO : animation au moment où elle devient visible ----- */
  var heroBoard = document.querySelector(".hero-v7 .hero-board");
  if (heroBoard && !reduceMotion) {
    var startHeroBoardAnimation = function () {
      if (!heroBoard.classList.contains("is-animated")) {
        heroBoard.classList.add("is-animated");
      }
    };
    if ("IntersectionObserver" in window) {
      var heroBoardObserver = new IntersectionObserver(function (entries) {
        if (entries[0] && entries[0].isIntersecting) {
          startHeroBoardAnimation();
          heroBoardObserver.disconnect();
        }
      }, { threshold: 0.25 });
      heroBoardObserver.observe(heroBoard);
    } else {
      startHeroBoardAnimation();
    }
  }

  /* ----- Sélection du type de projet + routage vers le formulaire ----- */
  // Destinations finales du tunnel. Les pages WordPress dédiées DP et PC
  // réutilisent les formulaires de référence ; Conception possède déjà sa
  // propre section de formulaire.
  var FORM_URLS = {
    dp:          "/formulaire-declaration-prealable/",
    pcmi:        "/formulaire-permis-de-construire/",
    conception:  "/conception/#formulaire-conception"
  };
  var FORM_BY_PROJECT = {
    maison:      "pcmi",
    conception:  "conception"
  };
  var FORM_COPY = {
    dp: {
      button: "Continuer vers ma déclaration préalable",
      hint: "Orientation proposée : déclaration préalable. Urbizen confirme la démarche après étude."
    },
    pcmi: {
      button: "Continuer vers mon permis de construire",
      hint: "Orientation proposée : permis de construire. Vos informations seront reprises dans le formulaire."
    },
    conception: {
      button: "Continuer vers mes plans sur mesure",
      hint: "Vous allez ouvrir le formulaire de conception de plans sur mesure."
    }
  };

  function formForProject(project) {
    return FORM_BY_PROJECT[project] || "dp";
  }

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
        var form = formForProject(selectedProjet);
        continueBtn.disabled = false;
        continueBtn.textContent = FORM_COPY[form].button;
        if (continueHint) continueHint.textContent = FORM_COPY[form].hint;
      });
    });
  }

  if (continueBtn) {
    continueBtn.addEventListener("click", function () {
      if (!selectedProjet) return;
      // adresse, parcelle et projet sont déjà conservés en sessionStorage :
      // le formulaire (branche dédiée) les relira pour se pré-remplir.
      var form = formForProject(selectedProjet);
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
