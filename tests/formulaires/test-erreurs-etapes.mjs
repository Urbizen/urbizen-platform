/**
 * Le parcours d'erreur DP/PC, rejoué dans un vrai document.
 *
 * CE QUE CE BANC EXISTE POUR EMPÊCHER
 *
 * Une soumission refusée par le serveur pour un champ de la rubrique 2, alors
 * que la personne est sur la dernière, laissait le formulaire exactement en
 * l'état : message générique en bas, rubrique inchangée, et un `focus()` sur un
 * champ en `display:none` — c'est-à-dire un appel sans effet et sans erreur. Le
 * repli qui aurait mis le focus sur le message ne s'exécutait jamais, puisqu'il
 * était conditionné à l'ABSENCE du champ, alors que celui-ci existait bel et
 * bien dans le document. Personne ne pouvait savoir quoi corriger.
 *
 * POURQUOI DANS UN DOCUMENT RÉEL
 *
 * `display:none` ne se simule pas. C'est la propriété même qui rend `focus()`
 * inopérant, et un test qui se contenterait de vérifier l'appel passerait au
 * vert sur le bug. On monte donc les fichiers de formulaire tels qu'ils sont
 * servis, et on regarde ce que le document fait réellement.
 *
 * L'ORDRE DES SCRIPTS EST REPRODUIT
 *
 * En production, le pont est chargé AVANT le script inline du document : c'est
 * ce qui permet à ce dernier de trouver `UrbizenErreurs`. Le banc remplace donc
 * la balise `src` par son contenu, au lieu de l'évaluer après coup — sans quoi
 * le moteur serait absent au moment où le formulaire s'initialise, et le banc
 * mesurerait un repli au lieu du comportement réel.
 */

import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { createRequire } from "node:module";

/* JSDOM n'est installé que dans les suites qui en avaient besoin jusqu'ici
   (`cadastre`, `privacy`). Cette suite-ci n'a pas son propre `node_modules`, et
   un `import "jsdom"` nu échouerait sur une résolution, pas sur un diagnostic.
   On le cherche donc là où il se trouve, et on signale un PRÉREQUIS ABSENT
   plutôt que de rendre un vert qui ne prouverait rien. */
const ICI = dirname(fileURLToPath(import.meta.url));
const require_ = createRequire(import.meta.url);

function chargerJsdom() {
  const pistes = [
    resolve(ICI, "../cadastre/node_modules/jsdom"),
    resolve(ICI, "../privacy/node_modules/jsdom"),
    resolve(ICI, "node_modules/jsdom"),
  ];

  for (const piste of pistes) {
    if (existsSync(piste)) return require_(piste);
  }

  try {
    return require_("jsdom");
  } catch (e) {
    console.log("\n⚠ PRÉREQUIS ABSENT : jsdom est introuvable.");
    console.log("  Installez-le dans tests/cadastre/ ou tests/formulaires/.");
    console.log("  Ce banc n'a PAS été exécuté — ce n'est pas un succès.");
    process.exit(2);
  }
}

const { JSDOM, VirtualConsole } = chargerJsdom();

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const THEME = resolve(ROOT, "wordpress/urbizen-child");
const ORIGINE = "https://urbizen.test";

const PARCOURS = [
  { nom: "DP", fichier: "dp-formulaire.html" },
  { nom: "PC", fichier: "pc-formulaire.html" },
];

let fail = 0;
const check = (label, cond, detail = "") => {
  if (!cond) fail++;
  console.log(`   ${cond ? "OK   " : "ECHEC"}  ${label}`);
  if (!cond && detail) console.log(`           ${detail}`);
};
const titre = (t) => console.log(`\n── ${t}`);

/**
 * Un champ d'une rubrique donnée qui possède un réceptacle d'erreur.
 *
 * Tous les champs n'en ont pas : le moteur le tolère, mais un banc qui en
 * choisirait un au hasard mesurerait le repli au lieu du cas courant.
 */
function champAvecReceptacle(form, etape) {
  // Le moteur fabrique le réceptacle quand il manque : tout champ nommé fait
  // donc l'affaire, pourvu qu'il soit actif, non masqué, et que son nom
  // n'apparaisse qu'une fois — sinon le focus irait sur un homonyme.
  const ctrls = [...etape.querySelectorAll("input[name], select[name], textarea[name]")];

  for (const ctrl of ctrls) {
    const nom = ctrl.getAttribute("name");
    if (ctrl.disabled || "hidden" === ctrl.type) continue;
    if (form.querySelectorAll(`[name="${nom}"]`).length !== 1) continue;
    if (ctrl.closest("[hidden]")) continue;
    if (!ctrl.closest(".dp-field")) continue;
    return { ctrl, nom };
  }
  return null;
}

/** La première rubrique, à partir de `depuis`, qui porte un champ utilisable. */
function rubriqueUtilisable(form, etapes, depuis = 0) {
  for (let i = depuis; i < etapes.length; i++) {
    const t = champAvecReceptacle(form, etapes[i]);
    if (t) return { index: i, ...t };
  }
  return null;
}

/** Monte un formulaire avec ses scripts en ligne, dans l'ordre réel. */
function monter(P) {
  let html = readFileSync(resolve(THEME, "assets/forms/" + P.fichier), "utf8");

  // Les dépendances deviennent des scripts en ligne, à leur place exacte.
  html = html.replace(/<script src="\.\.\/js\/([a-z-]+)\.js(\?[^"]*)?"><\/script>/g, (_m, nom) => {
    try {
      return "<script>" + readFileSync(resolve(THEME, "assets/js/" + nom + ".js"), "utf8") + "</script>";
    } catch (e) {
      return "";
    }
  });
  // Leaflet n'est pas nécessaire ici et n'est pas joignable hors ligne.
  html = html.replace(/<script src="https:\/\/unpkg\.com[^"]*"[^>]*><\/script>/g, "");

  const virtualConsole = new VirtualConsole();
  virtualConsole.on("jsdomError", () => {});

  const dom = new JSDOM(html, {
    url: ORIGINE + "/wp-content/themes/urbizen-child/assets/forms/" + P.fichier,
    runScripts: "dangerously",
    pretendToBeVisual: true,
    virtualConsole,
  });

  const { window } = dom;
  const form = window.document.getElementById("dp-form");

  return { window, form, doc: window.document };
}

/* JSDOM ne calcule pas de mise en page : `offsetParent` y vaut toujours `null`,
 * ce qui ferait passer tout champ pour masqué. On le redéfinit sur la règle qui
 * nous intéresse réellement — un élément est rendu si aucun de ses ancêtres
 * n'est masqué — afin que le banc mesure la même chose que le navigateur. */
function installerVisibilite(window) {
  Object.defineProperty(window.HTMLElement.prototype, "offsetParent", {
    configurable: true,
    get() {
      let n = this;
      while (n && n !== window.document.body) {
        if (n.hidden) return null;
        if (n.classList && n.classList.contains("dp-step") && !n.classList.contains("is-active")) return null;
        n = n.parentElement;
      }
      return window.document.body;
    },
  });
}

for (const P of PARCOURS) {
  console.log(`\n════ ${P.nom} ════`);

  /* ================================================================ *
   *  1. Le moteur est bien celui du pont, partagé
   * ================================================================ */
  titre(`${P.nom} · 1 — Un seul moteur d'erreurs`);
  {
    const { window, form } = monter(P);
    installerVisibilite(window);

    check(`${P.nom} · le moteur commun est exposé`, "object" === typeof window.UrbizenErreurs);
    check(`${P.nom} · le document a une zone de résumé`, !!window.document.getElementById("dp-resume-err"));
    check(`${P.nom} · le réceptacle d'erreur d'adresse existe`, !!form.querySelector('[data-erreur-pour="__adresse"]'));
  }

  /* ================================================================ *
   *  2. Erreur serveur sur une rubrique antérieure
   * ================================================================ */
  titre(`${P.nom} · 2 — Retour à la rubrique fautive, puis focus`);
  {
    const { window, form, doc } = monter(P);
    installerVisibilite(window);

    const etapes = [...form.querySelectorAll(".dp-step")];
    // On se place sur la DERNIÈRE rubrique, comme au moment de l'envoi.
    etapes.forEach((s) => s.classList.remove("is-active"));
    etapes[etapes.length - 1].classList.add("is-active");

    // Une rubrique ANTÉRIEURE portant un champ à réceptacle, choisie dans le
    // document lui-même plutôt que codée en dur : DP et PC n'ont ni le même
    // nombre de rubriques ni la même répartition des champs.
    const t = rubriqueUtilisable(form, etapes, 1);
    if (!t) { check(`${P.nom} · une rubrique antérieure exploitable existe`, false); continue; }
    const cible = t.ctrl;
    const nom = t.nom;
    const iCible = t.index;

    let activee = null;
    const moteur = window.UrbizenErreurs.creer(form, {
      activerEtape: (el) => {
        activee = el;
        etapes.forEach((s) => s.classList.remove("is-active"));
        el.classList.add("is-active");
      },
      resume: doc.getElementById("dp-resume-err"),
    });

    const AVANT = doc.activeElement;
    const total = moteur.appliquer([{ field: nom, message: "Indiquez un nombre valide." }]);

    check(`${P.nom} · l'erreur est retenue`, 1 === total);
    check(`${P.nom} · la rubrique fautive a été demandée`, activee === etapes[iCible]);
    check(`${P.nom} · elle est devenue visible AVANT le focus`, etapes[iCible].classList.contains("is-active"));
    check(`${P.nom} · le message exact est dans data-erreur-pour`,
      "Indiquez un nombre valide." === form.querySelector(`[data-erreur-pour="${nom}"]`)?.textContent,
      `lu : ${form.querySelector(`[data-erreur-pour="${nom}"]`)?.textContent}`);
    check(`${P.nom} · aria-invalid est posé`, "true" === cible.getAttribute("aria-invalid"));
    check(`${P.nom} · aria-describedby désigne le message`,
      (cible.getAttribute("aria-describedby") || "").includes(form.querySelector(`[data-erreur-pour="${nom}"]`).id));
    check(`${P.nom} · le focus a bougé`, doc.activeElement !== AVANT);
    check(`${P.nom} · le focus est sur le champ fautif`, doc.activeElement === cible,
      `activeElement = ${doc.activeElement?.getAttribute?.("name") || doc.activeElement?.tagName}`);
  }

  /* ================================================================ *
   *  3. Plusieurs erreurs, plusieurs rubriques
   * ================================================================ */
  titre(`${P.nom} · 3 — Plusieurs rubriques fautives`);
  {
    const { window, form, doc } = monter(P);
    installerVisibilite(window);

    const etapes = [...form.querySelectorAll(".dp-step")];
    etapes.forEach((s) => s.classList.remove("is-active"));
    etapes[etapes.length - 1].classList.add("is-active");

    const tb = rubriqueUtilisable(form, etapes, 0);
    const ta = tb ? rubriqueUtilisable(form, etapes, tb.index + 1) : null;
    if (!ta || !tb) {
      check(`${P.nom} · deux rubriques exploitables existent`, false,
        `première : ${tb ? tb.index : "aucune"}`);
      continue;
    }
    const a = ta.ctrl;
    const b = tb.ctrl;

    let activee = null;
    const moteur = window.UrbizenErreurs.creer(form, {
      activerEtape: (el) => {
        activee = el;
        etapes.forEach((s) => s.classList.remove("is-active"));
        el.classList.add("is-active");
      },
      resume: doc.getElementById("dp-resume-err"),
    });

    const total = moteur.appliquer([
      { field: a.getAttribute("name"), message: "Message A." },
      { field: b.getAttribute("name"), message: "Message B." },
    ]);

    check(`${P.nom} · les deux erreurs sont retenues`, 2 === total);
    check(`${P.nom} · on revient à la PREMIÈRE rubrique fautive`, activee === etapes[tb.index],
      `ouverte : ${etapes.indexOf(activee)}, attendue : ${tb.index}`);
    check(`${P.nom} · l'autre erreur est conservée`, 2 === moteur.nombre());
    check(`${P.nom} · elle reste marquée sur son champ`, "true" === a.getAttribute("aria-invalid"));
    check(`${P.nom} · le résumé annonce le total`,
      /2 informations/.test(doc.getElementById("dp-resume-err").textContent),
      doc.getElementById("dp-resume-err").textContent);
    check(`${P.nom} · le résumé ne montre aucun nom technique`,
      !doc.getElementById("dp-resume-err").textContent.includes(a.getAttribute("name")));
  }

  /* ================================================================ *
   *  4. La correction efface l'erreur
   * ================================================================ */
  titre(`${P.nom} · 4 — Corriger fait disparaître l'erreur`);
  {
    const { window, form, doc } = monter(P);
    installerVisibilite(window);

    const etapes4 = [...form.querySelectorAll(".dp-step")];
    const t4 = rubriqueUtilisable(form, etapes4, 0);
    const champ = t4.ctrl;
    const nom = t4.nom;
    const moteur = window.UrbizenErreurs.creer(form, { resume: doc.getElementById("dp-resume-err") });

    moteur.poser(nom, "Ce champ est obligatoire.");
    check(`${P.nom} · l'erreur est posée`, 1 === moteur.nombre());

    champ.value = "quelque chose";
    champ.dispatchEvent(new window.Event("input", { bubbles: true }));

    check(`${P.nom} · l'erreur disparaît`, 0 === moteur.nombre());
    check(`${P.nom} · aria-invalid est retiré`, null === champ.getAttribute("aria-invalid"));
    check(`${P.nom} · le message est vidé et masqué`,
      "" === form.querySelector(`[data-erreur-pour="${nom}"]`).textContent);
    check(`${P.nom} · le résumé est vidé`, "" === doc.getElementById("dp-resume-err").textContent);
  }

  /* ================================================================ *
   *  5. Un champ masqué ne bloque pas et ne prend pas le focus
   * ================================================================ */
  titre(`${P.nom} · 5 — Champ masqué : ni blocage, ni focus perdu`);
  {
    const { window, form, doc } = monter(P);
    installerVisibilite(window);

    const etapes = [...form.querySelectorAll(".dp-step")];
    etapes.forEach((s) => s.classList.remove("is-active"));
    etapes[0].classList.add("is-active");

    const t5 = rubriqueUtilisable(form, etapes, 1);
    const cache = t5.ctrl;
    const nom = t5.nom;

    // Aucun `activerEtape` : le moteur ne peut pas ouvrir la rubrique, ce qui
    // reproduit le cas d'un document qui ne lui en fournit pas.
    const moteur = window.UrbizenErreurs.creer(form, { resume: doc.getElementById("dp-resume-err") });
    moteur.appliquer([{ field: nom, message: "Erreur sur un champ masqué." }]);

    check(`${P.nom} · le focus n'est PAS allé sur le champ masqué`, doc.activeElement !== cache);
    check(`${P.nom} · le repli a porté le focus sur un élément joignable`,
      doc.activeElement === form.querySelector(`[data-erreur-pour="${nom}"]`) ||
        doc.activeElement === doc.getElementById("dp-resume-err") ||
        doc.activeElement === doc.body,
      `activeElement = ${doc.activeElement?.id || doc.activeElement?.tagName}`);
  }

  /* ================================================================ *
   *  6. Le libellé public vient du document, jamais du serveur
   * ================================================================ */
  titre(`${P.nom} · 6 — Le libellé est lu dans le document`);
  {
    const { window, form } = monter(P);
    installerVisibilite(window);

    const champ = form.querySelector(".dp-field label")?.closest(".dp-field")?.querySelector("[name]");
    if (champ) {
      const moteur = window.UrbizenErreurs.creer(form, {});
      const libelle = moteur.libelle(champ.getAttribute("name"));
      check(`${P.nom} · un libellé lisible est trouvé`, libelle.length > 2 && libelle !== champ.getAttribute("name"),
        `libellé : ${libelle}`);
    } else {
      check(`${P.nom} · un champ étiqueté existe`, false);
    }
  }

  /* ================================================================ *
   *  7. Le contrat serveur, de bout en bout par le pont
   * ================================================================ */
  titre(`${P.nom} · 7 — La réponse 422 du serveur, telle qu'elle arrive`);
  {
    const { window, form, doc } = monter(P);
    installerVisibilite(window);

    const etapes = [...form.querySelectorAll(".dp-step")];
    etapes.forEach((s) => s.classList.remove("is-active"));
    etapes[etapes.length - 1].classList.add("is-active");

    const t = rubriqueUtilisable(form, etapes, 1);
    if (!t) { check(`${P.nom} · une rubrique exploitable existe`, false); continue; }

    /* La forme EXACTE que compose `SubmissionJsonResponse::echec()`. Si le
       contrat serveur change, ce banc doit le voir ici. */
    const REPONSE = {
      success: false,
      code: "validation",
      message: "Certaines informations n’ont pas pu être validées. Vérifiez les champs signalés, puis renvoyez votre demande.",
      fields: [t.nom],
      errors: [{ field: t.nom, message: "Indiquez un nombre valide. La virgule et le point sont acceptés." }]
    };

    let activee = null;
    const moteur = window.UrbizenErreurs.creer(form, {
      activerEtape: (el) => { activee = el; etapes.forEach((s) => s.classList.remove("is-active")); el.classList.add("is-active"); },
      resume: doc.getElementById("dp-resume-err")
    });

    const pont = window.UrbizenPont.init({
      form,
      bouton: doc.getElementById("dp-send"),
      erreur: doc.getElementById("dp-final-err"),
      moteur,
      serialiser: () => {},
      afficherSucces: () => {}
    });

    pont._echec(REPONSE.message, REPONSE.fields, REPONSE.errors);

    check(`${P.nom} · le message global du serveur est affiché`,
      REPONSE.message === doc.getElementById("dp-final-err").textContent);
    check(`${P.nom} · la rubrique fautive a été ouverte`, activee === etapes[t.index],
      `ouverte : ${etapes.indexOf(activee)}, attendue : ${t.index}`);
    check(`${P.nom} · le message PAR CHAMP est affiché`,
      REPONSE.errors[0].message === form.querySelector(`[data-erreur-pour="${t.nom}"]`).textContent);
    check(`${P.nom} · le focus est sur le champ fautif`, doc.activeElement === t.ctrl);
    check(`${P.nom} · aucun code interne n'apparaît`, !doc.body.innerHTML.includes("nombre_invalide"));

    /* Repli : un serveur non encore à jour n'envoie que `fields`. Le pont doit
       encore conduire au bon champ, à partir du libellé lu dans le document. */
    const { window: w2, form: f2, doc: d2 } = monter(P);
    installerVisibilite(w2);
    const e2 = [...f2.querySelectorAll(".dp-step")];
    e2.forEach((s) => s.classList.remove("is-active"));
    e2[e2.length - 1].classList.add("is-active");
    const t2 = rubriqueUtilisable(f2, e2, 1);
    let a2 = null;
    const m2 = w2.UrbizenErreurs.creer(f2, {
      activerEtape: (el) => { a2 = el; e2.forEach((s) => s.classList.remove("is-active")); el.classList.add("is-active"); },
      resume: d2.getElementById("dp-resume-err")
    });
    const p2 = w2.UrbizenPont.init({ form: f2, bouton: d2.getElementById("dp-send"), erreur: d2.getElementById("dp-final-err"), moteur: m2, serialiser: () => {}, afficherSucces: () => {} });
    p2._echec("Message global.", [t2.nom], undefined);

    check(`${P.nom} · compatibilité : « fields » seul conduit encore au champ`, a2 === e2[t2.index]);
    check(`${P.nom} · le repli nomme le champ par son libellé, pas par sa clé`,
      !f2.querySelector(`[data-erreur-pour="${t2.nom}"]`).textContent.includes(t2.nom),
      f2.querySelector(`[data-erreur-pour="${t2.nom}"]`).textContent);
  }
}

/* ================================================================== *
 *  8. Parité DP / PC — les deux documents partagent le même moteur
 * ================================================================== */
titre("Parité DP / PC");
{
  const dp = readFileSync(resolve(THEME, "assets/forms/dp-formulaire.html"), "utf8");
  const pc = readFileSync(resolve(THEME, "assets/forms/pc-formulaire.html"), "utf8");

  for (const [nom, motif] of [
    ["le moteur commun est branché", /UrbizenErreurs\.creer/],
    ["la validation multi-étapes existe", /validerToutesLesEtapes/],
    ["le réceptacle d'adresse existe", /data-erreur-pour="__adresse"/],
    ["la zone de résumé existe", /id="dp-resume-err"/],
    ["l'activation d'étape est fournie au pont", /activerEtape: activerEtape/],
  ]) {
    check(`DP · ${nom}`, motif.test(dp));
    check(`PC · ${nom}`, motif.test(pc));
  }

  // Les règles métier ne doivent plus dépendre d'un NUMÉRO de rubrique : une
  // rubrique insérée déplacerait sinon la règle en silence.
  for (const [nom, src] of [["DP", dp], ["PC", pc]]) {
    // `current === 0` pilote le bouton « Précédent » : c'est de la navigation,
    // pas une règle métier, et elle a toute sa place. Ce sont les trois index
    // qui portaient une VALIDATION qui devaient disparaître.
    const lignes = src
      .split("\n")
      .filter((l) => /current === (2|6|7)\b/.test(l) && !/^\s*\*/.test(l));
    check(`${nom} · aucune validation attachée à un numéro de rubrique`, 0 === lignes.length, lignes.join(" | "));
  }

  // Les blocages muets d'origine ne doivent pas revenir.
  for (const [nom, src] of [["DP", dp], ["PC", pc]]) {
    const code = src.split("\n").filter((l) => /\bok = false\b/.test(l) && !/^\s*\*/.test(l));
    check(`${nom} · plus aucun « ok = false » muet dans le code`, 0 === code.length, code.join(" | "));
  }
}

console.log(fail === 0 ? "\nTOUS LES CONTROLES PASSENT" : `\n${fail} CONTROLE(S) EN ECHEC`);
process.exit(fail === 0 ? 0 : 1);
