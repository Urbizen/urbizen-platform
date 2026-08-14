/* ============================================================================
   homepage.js — logique de la page d'accueil Urbizen.
   Dépend de urbizen-cadastre.js (window.UrbizenCadastre) et de Leaflet.
   Chargé en `defer` : le DOM est prêt à l'exécution.

   Le portage WordPress (assets/js/urbizen-homepage.js) en dérive, à deux
   différences près et documentées de son côté :
     1. le montage manuel du cadastre est retiré, le bloc WordPress s'en charge ;
     2. la sélection des cartes projet y est conditionnée à la présence du
        bouton de continuation, la même feuille servant aussi les pages
        internes où les vignettes restent informatives.
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

  /* ----- Sous-menu « Nos prestations » (bureau) -----
     Le parent est un <button aria-expanded>, pas un lien : aucune page « Nos
     prestations » n'existe, et un faux lien mentirait au clavier.

     Ouverture au clic et au clavier UNIQUEMENT. Ajouter le survol rouvrirait le
     menu que le clic vient de fermer, le pointeur étant encore dessus.

     Quatre fermetures, toutes nécessaires : le clic sur le parent, Échap (qui
     rend le focus au parent — sinon il retombe sur <body> et la tabulation
     repart du début), le clic au dehors, et la sortie du focus par tabulation.
     `focusout` est différé d'un tour : au moment où il part, le focus n'est pas
     encore arrivé sur sa cible, et `document.activeElement` vaut <body>. */
  var navParent = document.querySelector(".nav-parent");
  var navSousMenu = navParent && document.getElementById(navParent.getAttribute("aria-controls"));
  if (navParent && navSousMenu) {
    var navGroupe = navParent.closest(".nav-groupe");
    var ouvrirSousMenu = function (ouvert) {
      navSousMenu.hidden = !ouvert;
      navParent.setAttribute("aria-expanded", ouvert ? "true" : "false");
    };
    navParent.addEventListener("click", function () {
      ouvrirSousMenu(navSousMenu.hidden);
    });
    /* Flèche bas : ouvre et pose le focus sur la première prestation. Geste
       attendu d'un menu, et le seul moyen d'y entrer sans quitter le clavier. */
    navParent.addEventListener("keydown", function (e) {
      if (e.key !== "ArrowDown") { return; }
      e.preventDefault();
      ouvrirSousMenu(true);
      var premier = navSousMenu.querySelector("a");
      if (premier) { premier.focus(); }
    });
    navGroupe.addEventListener("keydown", function (e) {
      if (e.key !== "Escape" || navSousMenu.hidden) { return; }
      ouvrirSousMenu(false);
      navParent.focus();
    });
    navGroupe.addEventListener("focusout", function () {
      window.setTimeout(function () {
        if (!navGroupe.contains(document.activeElement)) { ouvrirSousMenu(false); }
      }, 0);
    });
    document.addEventListener("click", function (e) {
      if (!navSousMenu.hidden && !navGroupe.contains(e.target)) { ouvrirSousMenu(false); }
    });
  }


  /* ----- Onglets ARIA, mécanique unique -----
     Trois usages sur l'accueil : les familles de pièces, les pièces elles-mêmes,
     et les quatre étapes. Tous suivent le même contrat — un `role="tablist"`,
     des `role="tab"` portant `aria-controls`, et un panneau par onglet. Une
     seule fonction les sert : trois implémentations auraient dérivé.

     Le `tabindex` roulant est ce qui rend la chose utilisable au clavier : un
     seul onglet est atteignable en tabulant, les autres se rejoignent aux
     flèches. C'est le comportement attendu d'un groupe d'onglets, et l'oublier
     obligerait à traverser dix boutons pour atteindre le contenu. */
  var brancherOnglets = function ( liste, apres ) {
    if ( ! liste ) { return; }
    var onglets = [].slice.call( liste.querySelectorAll( '[role="tab"]' ) );
    if ( ! onglets.length ) { return; }
    var vertical = liste.getAttribute( 'aria-orientation' ) === 'vertical';

    var activer = function ( onglet, donnerLeFocus ) {
      onglets.forEach( function ( o ) {
        var actif = o === onglet;
        o.setAttribute( 'aria-selected', actif ? 'true' : 'false' );
        o.setAttribute( 'tabindex', actif ? '0' : '-1' );
        var panneau = document.getElementById( o.getAttribute( 'aria-controls' ) );
        if ( panneau ) { panneau.hidden = ! actif; }
      } );
      if ( donnerLeFocus ) {
        try { onglet.focus( { preventScroll: true } ); } catch ( e ) { onglet.focus(); }
      }
      if ( apres ) { apres( onglets.indexOf( onglet ), onglet ); }
    };

    onglets.forEach( function ( onglet ) {
      onglet.addEventListener( 'click', function () { activer( onglet, false ); } );
      onglet.addEventListener( 'keydown', function ( e ) {
        var i = onglets.indexOf( onglet );
        var suivant = vertical ? 'ArrowDown' : 'ArrowRight';
        var precedent = vertical ? 'ArrowUp' : 'ArrowLeft';
        var cible = null;
        if ( e.key === suivant ) { cible = onglets[ ( i + 1 ) % onglets.length ]; }
        else if ( e.key === precedent ) { cible = onglets[ ( i - 1 + onglets.length ) % onglets.length ]; }
        else if ( e.key === 'Home' ) { cible = onglets[ 0 ]; }
        else if ( e.key === 'End' ) { cible = onglets[ onglets.length - 1 ]; }
        if ( ! cible ) { return; }
        e.preventDefault();
        activer( cible, true );
      } );
    } );
  };

  /* Les familles de pièces, puis les pièces de chaque famille. */
  brancherOnglets( document.querySelector( '.dx-tabs' ) );
  [].forEach.call( document.querySelectorAll( '.dx-nav' ), function ( nav ) {
    brancherOnglets( nav );
  } );

  /* Les quatre étapes, avec le compteur et les repères du bas. */
  var compteur = document.querySelector( '.etapes-compteur b' );
  var points = [].slice.call( document.querySelectorAll( '.etapes-point' ) );
  brancherOnglets( document.querySelector( '.etapes-nav' ), function ( rang ) {
    if ( compteur ) { compteur.textContent = String( rang + 1 ); }
    points.forEach( function ( p, i ) { p.classList.toggle( 'is-actif', i === rang ); } );
  } );

  /* ----- La loupe sur un document -----
     Un document de dossier doit pouvoir être lu, pas seulement aperçu. La
     visionneuse est construite à la première ouverture et réutilisée ensuite :
     l'ajouter au chargement coûterait un nœud à tout le monde pour un usage
     qui reste minoritaire. Échap ferme, le focus revient au bouton d'origine —
     sans quoi il retomberait en haut du document. */
  var loupe = null, loupeImg = null, loupeOrigine = null;

  var fermerLoupe = function () {
    if ( ! loupe || loupe.hidden ) { return; }
    loupe.hidden = true;
    document.documentElement.style.overflow = '';
    if ( loupeOrigine ) {
      try { loupeOrigine.focus( { preventScroll: true } ); } catch ( e ) { loupeOrigine.focus(); }
    }
  };

  var construireLoupe = function () {
    loupe = document.createElement( 'div' );
    loupe.className = 'dx-lightbox';
    loupe.hidden = true;
    loupe.setAttribute( 'role', 'dialog' );
    loupe.setAttribute( 'aria-modal', 'true' );
    loupe.setAttribute( 'aria-label', 'Document agrandi' );
    loupeImg = document.createElement( 'img' );
    loupeImg.alt = '';
    var fermer = document.createElement( 'button' );
    fermer.type = 'button';
    fermer.className = 'dx-lightbox-fermer';
    fermer.setAttribute( 'aria-label', 'Fermer' );
    fermer.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
    fermer.addEventListener( 'click', fermerLoupe );
    loupe.appendChild( loupeImg );
    loupe.appendChild( fermer );
    loupe.addEventListener( 'click', function ( e ) { if ( e.target === loupe ) { fermerLoupe(); } } );
    document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { fermerLoupe(); } } );
    /* Le CSS de l'accueil est porté sous « .urbizen-accueil » : rattachée à
       <body>, la loupe tomberait hors de portée et perdrait tout son style. */
    ( document.querySelector( '.urbizen-accueil' ) || document.body ).appendChild( loupe );
    return fermer;
  };

  [].forEach.call( document.querySelectorAll( '.dx-zoom' ), function ( bouton ) {
    bouton.addEventListener( 'click', function () {
      var img = bouton.querySelector( 'img' );
      if ( ! img ) { return; }
      var fermer = loupe ? loupe.querySelector( '.dx-lightbox-fermer' ) : construireLoupe();
      loupeOrigine = bouton;
      loupeImg.src = img.currentSrc || img.src;
      loupeImg.alt = img.alt;
      loupe.hidden = false;
      document.documentElement.style.overflow = 'hidden';
      try { fermer.focus( { preventScroll: true } ); } catch ( e ) { fermer.focus(); }
    } );
  } );

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
        contextualiserRenseignements();
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
  /* ----- contexte du projet vers le formulaire de renseignements ------------

     `none` et `confirm` mènent ici. Urbizen doit recevoir les réponses déjà
     données, sans présenter ce passage comme une prestation de qualification.

     Le formulaire est un bloc FluentForm : sa définition vit en base, et lui
     ajouter un champ depuis le dépôt serait fragile — un champ inconnu de la
     définition est écarté à la soumission. On prérempli donc le message, en
     clair. Le résumé est écrit pour être lu par un humain : aucune chaîne
     technique, aucun JSON.

     Deux garde-fous. Le message n'est prérempli que s'il est vide : ce que le
     client a écrit ne se réécrit jamais. Et le verdict est présenté comme un
     élément de contexte, jamais comme une décision — c'est Urbizen qui tranche.
  */
  var LIBELLES_PROJET = {
    extension: "Extension", garage: "Garage", abri: "Abri de jardin",
    piscine: "Piscine", pergola: "Pergola", transformation: "Transformation d'un espace existant",
    facade: "Modification de façade", toiture: "Toiture", solaire: "Panneaux solaires",
    maison: "Maison individuelle", conception: "Conception de plans sur mesure",
    autre: "Autre projet"
  };

  var LIBELLES_VERDICT = {
    none: "aucune formalité nationale identifiée",
    confirm: "échange direct avec Urbizen"
  };

  var LIBELLES_REPONSE = {
    implantation: { accole: "accolé à une construction existante", independant: "indépendant" },
    local_actuel: { garage: "un garage", combles: "des combles", sous_sol: "un sous-sol ou une cave", dependance: "une dépendance" },
    local_rattache: { maison: "rattaché à la maison", batiment_separe: "bâtiment séparé" },
    zone_u: { "true": "oui", "false": "non", unknown: "inconnue" },
    secteur_protege: { "true": "oui", "false": "non", unknown: "inconnu" },
    modifie_aspect_exterieur: { "true": "oui", "false": "non" }
  };

  function resumeQualification(donnees, verdict) {
    var lignes = [];
    lignes.push("Projet : " + (LIBELLES_PROJET[donnees.projet] || donnees.projet || "non précisé"));

    if (donnees.local_actuel && LIBELLES_REPONSE.local_actuel[donnees.local_actuel]) {
      lignes.push("Espace transformé : " + LIBELLES_REPONSE.local_actuel[donnees.local_actuel]);
    }
    if (donnees.implantation && LIBELLES_REPONSE.implantation[donnees.implantation]) {
      lignes.push("Implantation : " + LIBELLES_REPONSE.implantation[donnees.implantation]);
    }
    if (donnees.sp_creee !== undefined) { lignes.push("Surface de plancher créée : " + donnees.sp_creee + " m²"); }
    if (donnees.emprise_creee !== undefined) { lignes.push("Emprise créée : " + donnees.emprise_creee + " m²"); }
    if (donnees.sp_totale !== undefined) { lignes.push("Surface de plancher totale après travaux : " + donnees.sp_totale + " m²"); }
    if (donnees.bassin_m2 !== undefined) { lignes.push("Bassin : " + donnees.bassin_m2 + " m²"); }
    if (donnees.hauteur_m !== undefined) { lignes.push("Hauteur : " + donnees.hauteur_m + " m"); }
    if (donnees.zone_u !== undefined) { lignes.push("Zone U du PLU : " + LIBELLES_REPONSE.zone_u[String(donnees.zone_u)]); }
    if (donnees.secteur_protege !== undefined) { lignes.push("Secteur protégé : " + LIBELLES_REPONSE.secteur_protege[String(donnees.secteur_protege)]); }
    if (donnees.modifie_aspect_exterieur !== undefined) { lignes.push("Modification extérieure : " + LIBELLES_REPONSE.modifie_aspect_exterieur[String(donnees.modifie_aspect_exterieur)]); }

    lignes.push("Suite proposée : " + (LIBELLES_VERDICT[verdict.status] || verdict.status));

    return lignes.join("\n");
  }

  var QUALIFICATION_VERSION = 3;
  var QUALIFICATION_MAX_AGE = 30 * 60 * 1000;

  function qualificationValide(qualif, projetAttendu, statutAttendu) {
    var parcours = null;
    try {
      var brutParcours = sessionStorage.getItem("urbizen:parcours");
      parcours = brutParcours ? JSON.parse(brutParcours) : null;
    } catch (e) { return false; }

    if (!qualif || qualif.version !== QUALIFICATION_VERSION) { return false; }
    if (typeof qualif.parcours_id !== "string" || !qualif.parcours_id) { return false; }
    if (typeof qualif.created_at !== "number" || Date.now() - qualif.created_at > QUALIFICATION_MAX_AGE || qualif.created_at > Date.now() + 60000) { return false; }
    if (!qualif.donnees || !qualif.verdict || qualif.projet !== qualif.donnees.projet) { return false; }
    if (!parcours || parcours.version !== QUALIFICATION_VERSION || parcours.parcours_id !== qualif.parcours_id || parcours.projet !== qualif.projet) { return false; }
    if (projetAttendu && qualif.projet !== projetAttendu) { return false; }
    if (statutAttendu && qualif.verdict.status !== statutAttendu) { return false; }
    return true;
  }

  function contextualiserRenseignements() {
    if (!inquiryPanel) { return; }

    var qualif = null;
    try {
      var brut = sessionStorage.getItem("urbizen:qualification");
      qualif = brut ? JSON.parse(brut) : null;
    } catch (e) { return; }

    if (!qualificationValide(qualif)) { return; }
    if (qualif.verdict.status !== "none" && qualif.verdict.status !== "confirm") { return; }

    var message = inquiryPanel.querySelector("textarea");
    if (!message || message.value.trim() !== "") { return; }

    message.value = resumeQualification(qualif.donnees || {}, qualif.verdict) + "\n\n";
    message.dispatchEvent(new Event("input", { bubbles: true }));
  }

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
    contextualiserRenseignements();

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

  /* ----- Sélection du type de projet et qualification -----

     Auparavant, un choix non mappé tombait implicitement en déclaration
     préalable. Deux types étaient explicites ; les huit autres recevaient ce
     même régime — une extension de soixante mètres carrés comme un ravalement.
     La décision tombait au clic sur la carte, avant la moindre question de
     surface, et l'écran annonçait « orientation proposée », donnant à un défaut
     l'apparence d'une étude.

     Désormais l'accueil ne décide de rien. Il pose les questions que le moteur
     réclame, une à la fois, et n'oriente que lorsque le moteur a conclu. Aucun
     seuil réglementaire ne vit ici : ils sont tous dans `qualification.js`,
     testé hors navigateur et jumelé à son équivalent serveur. */

  var FORM_URLS = {
    dp:         "/formulaire-declaration-prealable/",
    pcmi:       "/formulaire-permis-de-construire/",
    conception: "/formulaire-conception/"
  };

  /* Les questions que le moteur peut réclamer. Chacune répond à un nom de
     donnée manquante ; l'ordre de cette table est l'ordre d'apparition. Le
     visiteur ne voit jamais les questions des autres branches. */
  var QUESTIONS = [
    { champ: "implantation", libelle: "Cette construction sera-t-elle accolée à un bâtiment existant, ou indépendante ?",
      aide: "Accolée : elle touche la maison ou un autre bâtiment. Indépendante : elle est isolée sur le terrain.",
      choix: [ { v: "accole", t: "Accolée" }, { v: "independant", t: "Indépendante" } ] },

    { champ: "sp_creee", libelle: "Quelle surface de plancher le projet va-t-il créer ?",
      aide: "Il s'agit des surfaces closes et couvertes, mesurées selon les règles de la surface de plancher. Une estimation suffit à ce stade.",
      unite: "m²" },

    { champ: "emprise_creee", libelle: "Quelle emprise au sol le projet va-t-il occuper ?",
      aide: "L'ombre portée de la construction au sol.", unite: "m²" },

    { champ: "bassin_m2", libelle: "Quelle est la superficie du bassin ?",
      aide: "La surface du bassin lui-même, hors plage et margelles.", unite: "m²" },

    { champ: "couverte", libelle: "La piscine sera-t-elle couverte ?",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "hauteur_couverture_m", libelle: "Quelle hauteur fera la couverture au-dessus du sol ?",
      unite: "m" },

    { champ: "hauteur_m", libelle: "Quelle hauteur fera la construction ?",
      aide: "Du sol au point le plus haut.", unite: "m" },

    { champ: "sp_totale", libelle: "Quelle sera la surface de plancher totale après travaux ?",
      aide: "La surface de plancher actuelle, plus celle que vous créez.", unite: "m²" },

    { champ: "personne_physique", libelle: "Le projet est-il réalisé par un particulier pour lui-même ?",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "usage_agricole", libelle: "La construction est-elle destinée à une activité agricole ?",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    /* La zone urbaine décide du seuil de 20 ou 40 m². Attention à ce que la
       question demande : R.421-14 b) vise le CLASSEMENT en zone urbaine d'un
       PLU ou d'un document en tenant lieu, pas l'impression d'être « dans un
       secteur déjà bâti ». Un terrain construit peut être classé en zone
       agricole ou naturelle ; l'apparence n'est pas le zonage. Demander l'un
       pour l'autre produirait des permis manqués.
       Personne ne connaît son zonage de tête : « je ne sais pas » est une
       réponse légitime. Le moteur retient alors le régime prudent, et Urbizen
       vérifie le classement après l'envoi du formulaire. */
    { champ: "zone_u", libelle: "Votre terrain est-il classé en zone U (zone urbaine) du PLU ?",
      aide: "Cette information figure sur le plan de zonage de votre commune. Si vous ne la connaissez pas, le parcours retiendra la démarche la plus prudente ; Urbizen vérifiera après l'envoi.",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" }, { v: "unknown", t: "Je ne sais pas" } ] },

    { champ: "secteur_protege", libelle: "Votre terrain est-il dans un secteur protégé ?",
      aide: "Abords d'un monument historique, site classé, secteur patrimonial remarquable. En cas de doute, le parcours retient la démarche la plus prudente ; Urbizen vérifiera après l'envoi.",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" }, { v: "unknown", t: "Je ne sais pas" } ] },

    /* Les questions de la transformation évitent le vocabulaire du code. On ne
       demande pas « cette surface est-elle comprise dans la surface de
       plancher ? » : on demande ce que le propriétaire sait — quel espace, est-il
       fermé, quelle hauteur — et le moteur en tire la donnée réglementaire.
       On ne demande pas non plus « l'usage va-t-il changer ? » : un garage de
       maison a déjà la destination du logement, et la question induirait un
       changement de destination qui n'existe pas. */
    { champ: "local_actuel", libelle: "Quel espace allez-vous transformer ?",
      choix: [ { v: "garage", t: "Un garage" }, { v: "combles", t: "Des combles" },
               { v: "sous_sol", t: "Un sous-sol ou une cave" }, { v: "dependance", t: "Une dépendance" } ] },

    { champ: "ferme_couvert", libelle: "Cet espace est-il aujourd'hui fermé et couvert ?",
      aide: "Des murs et un toit, même sans chauffage ni isolation.",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "local_rattache", libelle: "Cet espace fait-il partie de votre maison, ou est-ce un bâtiment séparé ?",
      aide: "Un garage attenant fait partie de la maison. Un bâtiment isolé sur le terrain est séparé.",
      choix: [ { v: "maison", t: "Il fait partie de la maison" }, { v: "batiment_separe", t: "C'est un bâtiment séparé" } ] },

    { champ: "destination_actuelle", libelle: "À quoi sert aujourd'hui ce bâtiment séparé ?",
      aide: "Sa destination actuelle décide s'il y a changement de destination au sens du code.",
      choix: [ { v: "habitation", t: "À l'habitation" }, { v: "autres_activites", t: "À autre chose (atelier, remise, activité…)" } ] },

    { champ: "hauteur_sup_180", libelle: "La hauteur sous plafond dépasse-t-elle 1,80 m ?",
      aide: "En dessous de 1,80 m, l'espace ne compte pas dans la surface habitable ; le régime n'est pas le même.",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "modifie_aspect_exterieur", libelle: "L'aspect extérieur va-t-il changer ?",
      aide: "Par exemple une porte de garage remplacée par une fenêtre ou une baie, ou une fenêtre de toit ajoutée.",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "changement_destination", libelle: "L'usage du bâtiment va-t-il changer ?",
      aide: "Par exemple un local commercial transformé en logement.",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "modifie_structure_ou_facade", libelle: "Les travaux toucheront-ils les murs porteurs ou la façade ?",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "aspect_exterieur", libelle: "Les travaux modifieront-ils l'aspect extérieur de la construction ?",
      choix: [ { v: true, t: "Oui" }, { v: false, t: "Non" } ] },

    { champ: "description", libelle: "Décrivez votre projet en quelques mots.", texte: true }
  ];

  var MESSAGES = {
    dp:         "Démarche : déclaration préalable. Après l'envoi, Urbizen vérifie vos informations et vous rappelle sous 24 h ouvrées.",
    pcmi:       "Démarche : permis de construire. Après l'envoi, Urbizen vérifie vos informations et vous rappelle sous 24 h ouvrées.",
    conception: "Vous allez ouvrir le formulaire de conception de plans sur mesure.",
    none:       "D'après ces éléments, aucune autorisation d'urbanisme nationale n'est nécessaire. Vous pouvez transmettre votre demande à Urbizen pour un contrôle des règles locales sous 24 h ouvrées.",
    confirm:    "Continuez vers le formulaire de renseignements. Urbizen vérifie les informations transmises et vous répond sous 24 h ouvrées."
  };

  var BOUTONS = {
    dp:         "Continuer vers ma déclaration préalable",
    pcmi:       "Continuer vers mon permis de construire",
    conception: "Continuer vers mes plans sur mesure",
    none:       "Continuer vers ma demande",
    confirm:    "Continuer vers ma demande"
  };

  var reponses = {};
  var selectedProjet = null;
  var parcoursId = null;
  var continueBtn = document.getElementById("js-continue");
  var continueHint = document.getElementById("js-continue-hint");
  var zoneQuestions = document.getElementById("js-qualification");
  var cards = document.querySelectorAll(".pcard");
  var moteur = window.UrbizenQualification;

  function question(champ) {
    for (var i = 0; i < QUESTIONS.length; i++) {
      if (QUESTIONS[i].champ === champ) { return QUESTIONS[i]; }
    }
    return null;
  }

  /* Une seule question à la fois : la première que le moteur réclame, pour
     laquelle nous savons formuler une phrase compréhensible, et à laquelle le
     visiteur n'a pas DÉJÀ répondu.

     Cette dernière condition n'est pas un détail. « Je ne sais pas » est une
     réponse légitime — c'est même celle qu'on attend de la plupart des gens sur
     le zonage. Mais le moteur continue alors de réclamer la donnée, puisqu'elle
     lui manque toujours. Sans cette garde, la question revenait indéfiniment et
     le tunnel ne concluait jamais : une impasse, découverte par le banc du
     tunnel. Une réponse donnée ne se redemande pas ; si tout ce qui manque a
     déjà été demandé, on conclut « à confirmer ». */
  function prochaineQuestion(manquantes) {
    for (var i = 0; i < QUESTIONS.length; i++) {
      var q = QUESTIONS[i];
      if (manquantes.indexOf(q.champ) === -1) { continue; }
      if (Object.prototype.hasOwnProperty.call(reponses, q.champ)) { continue; }
      return q;
    }
    return null;
  }

  function repondre(champ, valeur) {
    reponses[champ] = valeur;
    evaluer();
  }

  function afficherQuestion(q) {
    if (!zoneQuestions) { return; }
    zoneQuestions.innerHTML = "";
    zoneQuestions.hidden = false;

    var bloc = document.createElement("div");
    bloc.className = "qualif-question";

    var titre = document.createElement("p");
    titre.className = "qualif-libelle";
    titre.textContent = q.libelle;
    titre.tabIndex = -1;
    bloc.appendChild(titre);

    if (q.aide) {
      var aide = document.createElement("p");
      aide.className = "qualif-aide";
      aide.textContent = q.aide;
      bloc.appendChild(aide);
    }

    if (q.choix) {
      var groupe = document.createElement("div");
      groupe.className = "qualif-choix";
      q.choix.forEach(function (c) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "btn btn-ghost btn-sm";
        b.textContent = c.t;
        b.addEventListener("click", function () { repondre(q.champ, c.v); });
        groupe.appendChild(b);
      });
      bloc.appendChild(groupe);
    } else {
      var ligne = document.createElement("div");
      ligne.className = "qualif-saisie";
      var champ = document.createElement("input");
      champ.type = "text";
      champ.inputMode = q.texte ? "text" : "decimal";
      champ.maxLength = q.texte ? 500 : 32;
      champ.className = "qualif-champ";
      champ.setAttribute("aria-label", q.libelle);
      var valider = document.createElement("button");
      valider.type = "button";
      valider.className = "btn btn-primary btn-sm";
      valider.textContent = "Valider";
      var envoyer = function () {
        var v = champ.value.trim();
        if (!v) { return; }
        repondre(q.champ, q.texte ? v : v.replace(",", "."));
      };
      valider.addEventListener("click", envoyer);
      champ.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); envoyer(); } });
      ligne.appendChild(champ);
      if (q.unite) {
        var u = document.createElement("span");
        u.className = "qualif-unite";
        u.textContent = q.unite;
        ligne.appendChild(u);
      }
      ligne.appendChild(valider);
      bloc.appendChild(ligne);
    }

    zoneQuestions.appendChild(bloc);
    window.setTimeout(function () {
      var tactile = window.matchMedia && window.matchMedia("(pointer: coarse)").matches;
      if (!q.choix && !tactile) { champ.focus(); }
      else { titre.focus(); }
    }, 0);
  }

  function masquerQuestions() {
    if (zoneQuestions) { zoneQuestions.hidden = true; zoneQuestions.innerHTML = ""; }
  }

  /* Interroge le moteur, puis soit pose la question suivante, soit conclut.
     Aucune redirection n'est proposée tant que le verdict n'est pas rendu. */
  function evaluer() {
    if (!selectedProjet || !moteur) { return; }

    var donnees = { projet: selectedProjet };
    for (var k in reponses) {
      if (Object.prototype.hasOwnProperty.call(reponses, k)) { donnees[k] = reponses[k]; }
    }

    var verdict = moteur.qualifyProject(donnees);
    continueBtn.setAttribute("data-statut", verdict.status);

    if (verdict.status === "confirm" && verdict.missing.length) {
      var q = prochaineQuestion(verdict.missing);
      if (q) {
        afficherQuestion(q);
        continueBtn.disabled = true;
        continueBtn.textContent = "Répondez pour continuer";
        if (continueHint) { continueHint.textContent = ""; }
        return;
      }
    }

    masquerQuestions();
    continueBtn.disabled = false;
    continueBtn.textContent = BOUTONS[verdict.status];
    if (continueHint) { continueHint.textContent = MESSAGES[verdict.status]; }
    window.setTimeout(function () { continueBtn.focus(); }, 0);

    try {
      sessionStorage.setItem("urbizen:qualification", JSON.stringify({
        version: QUALIFICATION_VERSION,
        parcours_id: parcoursId,
        projet: selectedProjet,
        created_at: Date.now(),
        donnees: donnees,
        verdict: verdict
      }));
    } catch (e) {}
  }

  cards.forEach(function (card) {
    card.setAttribute("aria-pressed", "false");
    card.addEventListener("click", function () {
      cards.forEach(function (c) { c.classList.remove("is-selected"); c.setAttribute("aria-pressed", "false"); });
      card.classList.add("is-selected");
      card.setAttribute("aria-pressed", "true");
      selectedProjet = card.getAttribute("data-projet");
      reponses = {};
      /* La carte Conception est un raccourci vers le formulaire lui-même.
         La page commerciale reste accessible depuis le menu principal. */
      if (selectedProjet === "conception") {
        try {
          sessionStorage.removeItem("urbizen:qualification");
          sessionStorage.removeItem("urbizen:projet");
          sessionStorage.removeItem("urbizen:parcours");
        } catch (e) {}
        window.location.href = FORM_URLS.conception;
        return;
      }
      /* Un garage de stationnement et une pergola ouverte ne créent pas de
         surface de plancher. Conserver ce zéro explicite évite de redemander
         cette donnée dans le formulaire final. */
      if (selectedProjet === "garage" || selectedProjet === "pergola") {
        reponses.sp_creee = 0;
      }
      parcoursId = String(Date.now()) + "-" + Math.random().toString(36).slice(2, 10);
      try {
        sessionStorage.removeItem("urbizen:qualification");
        sessionStorage.setItem("urbizen:projet", selectedProjet);
        sessionStorage.setItem("urbizen:parcours", JSON.stringify({ version: QUALIFICATION_VERSION, parcours_id: parcoursId, projet: selectedProjet, created_at: Date.now() }));
      } catch (e) {}
      evaluer();
    });
  });

  if (continueBtn) {
    continueBtn.addEventListener("click", function () {
      if (!selectedProjet) { return; }
      var statut = continueBtn.getAttribute("data-statut");

      /* `none` et `confirm` n'ont pas de formulaire de régime : envoyer
         quelqu'un vers un dossier payant sans avoir conclu serait exactement
         le défaut que cette tranche corrige. Ils mènent au formulaire de
         renseignements, où Urbizen reprend la main. */
      if (statut === "none" || statut === "confirm") {
        window.location.href = "/#demander-des-renseignements";
        return;
      }

      if (Object.prototype.hasOwnProperty.call(FORM_URLS, statut)) {
        window.location.href = FORM_URLS[statut];
      }
    });
  }

  /* ----- Composant cadastre -----
     Aucun montage manuel ici : sous WordPress, le bloc `urbizen/cadastre`
     rend son propre conteneur et urbizen-cadastre.js le monte via
     autoMount(). Un mount() supplémentaire provoquerait un double montage.
     Les libellés et la clé de stockage « accueil » sont portés par les
     attributs du bloc, dans le gabarit. */

  /* ----- Montage du composant cadastre partagé ----- */
  if (window.UrbizenCadastre) {
    window.UrbizenCadastre.mount("#cadastre-mount", {
      label: "Adresse du projet",
      placeholder: "Commencez à saisir une adresse…",
      continueLabel: "Continuer"
    });
  }

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
