/* Champs conditionnés par la nature du projet.
 *
 * Le formulaire demandait une surface de plancher pour une piscine, une
 * clôture, un ravalement. Pire : la surface créée portait un astérisque, donc
 * une piscine EXIGEAIT une surface de plancher pour être envoyée.
 *
 * Ce banc éprouve trois choses, et la troisième est la seule qui protège
 * réellement les données :
 *
 * 1. la matrice du navigateur est **celle du serveur**, pas une copie ;
 * 2. les champs non applicables sont masqués et dépouillés de leur obligation ;
 * 3. ils ne partent pas dans le `FormData` — masquer sans désactiver
 *    laisserait la valeur voyager.
 *
 * Exécuté sur le HTML réel des deux documents.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { execFileSync } from "node:child_process";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const THEME = resolve(ROOT, "wordpress/urbizen-child");

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(74), cond ? "OK" : "ECHEC");
};
const titre = (t) => console.log(`\n── ${t}`);

/* La matrice est lue DEPUIS LE PHP : le banc ne la recopie pas davantage que
 * le navigateur ne le fait. Une matrice recopiée ici passerait les contrôles
 * tout en divergeant de la source. */
const MATRICE = JSON.parse(
  execFileSync(process.env.PHP_BIN || "php", [
    "-r",
    'define("ABSPATH",true);require "' +
      resolve(ROOT, "wordpress/urbizen-platform/src/Forms/MatriceChamps.php") +
      '";use Urbizen\\Platform\\Forms\\MatriceChamps as M;' +
      'echo json_encode(["declaration_prealable"=>M::DP,"permis_construire"=>M::PC,"conditionnels"=>M::CONDITIONNELS]);',
  ]).toString()
);

const PARCOURS = [
  { nom: "DP", fichier: "dp-formulaire.html", type: "declaration_prealable" },
  { nom: "PC", fichier: "pc-formulaire.html", type: "permis_construire" },
];

/** Monte un document, charge les modules, applique la matrice. */
async function monter(P) {
  const html = readFileSync(resolve(THEME, "assets/forms/" + P.fichier), "utf8").replace(
    /<script src="[^"]*urbizen-form-[a-z]+\.js[^"]*"><\/script>/g,
    ""
  );
  const vc = new VirtualConsole();
  vc.on("jsdomError", () => {});
  const dom = new JSDOM(html, {
    url: "https://urbizen.test/f.html",
    runScripts: "dangerously",
    pretendToBeVisual: true,
    virtualConsole: vc,
  });
  const w = dom.window;
  await new Promise((r) => ("complete" === w.document.readyState ? r() : w.addEventListener("load", r)));

  for (const f of ["urbizen-form-tarifs.js", "urbizen-form-pieces.js", "urbizen-form-nombres.js", "urbizen-form-champs.js", "urbizen-form-bridge.js"]) {
    w.eval(readFileSync(resolve(THEME, "assets/js/" + f), "utf8"));
  }

  const d = w.document;
  const form = d.getElementById("dp-form");
  const champs = w.UrbizenChamps.init({
    form,
    surNature: () => (form.querySelector('input[name="nature"]:checked') || {}).value || "",
  });

  champs.appliquerMatrice(MATRICE[P.type], MATRICE.conditionnels);

  const choisir = (nature) => {
    const r = [...d.querySelectorAll('input[name="nature"]')].find((i) => i.value === nature);
    if (!r) return false;
    r.checked = true;
    r.dispatchEvent(new w.Event("change", { bubbles: true }));
    return true;
  };
  /** Répond à une question à liste fermée, comme le ferait un clic. */
  const repondre = (nom, valeur) => {
    const r = [...d.querySelectorAll(`input[name="${nom}"]`)].find((i) => i.value === valeur);
    if (!r) return false;
    r.checked = true;
    r.dispatchEvent(new w.Event("change", { bubbles: true }));
    return true;
  };
  const saisir = (nom, valeur) => {
    const e = d.querySelector(`[name="${nom}"]`);
    if (!e) return false;
    e.value = valeur;
    e.dispatchEvent(new w.Event("input", { bubbles: true }));
    return true;
  };
  const visibles = () => [...d.querySelectorAll("[data-champ]")].filter((g) => !g.hidden).map((g) => g.getAttribute("data-champ")).sort();
  const envoyes = () => {
    const fd = new w.FormData(form);
    return MATRICE.conditionnels.filter((c) => fd.has(c)).sort();
  };

  return { dom, w, d, form, champs, choisir, repondre, saisir, visibles, envoyes };
}

/* ================================================================== *
 *  1. Ce que le navigateur montre est ce que le serveur admet
 * ================================================================== */

for (const P of PARCOURS) {
  titre(`${P.nom} — la matrice du serveur, appliquée telle quelle`);

  const ctx = await monter(P);

  for (const nature of Object.keys(MATRICE[P.type])) {
    if (!ctx.choisir(nature)) continue;

    // Un champ porteur de `data-visible-si` dépend en outre d'une autre
    // réponse : la hauteur d'un abri n'apparaît que si un abri est annoncé.
    // L'attendre visible sans avoir répondu serait exiger l'inverse de ce que
    // le formulaire doit faire.
    const attendus = MATRICE[P.type][nature]
      .filter((c) => {
        const g = ctx.d.querySelector(`[data-champ="${c}"]`);
        if (!g) return false;
        const regle = g.getAttribute("data-visible-si");
        if (!regle) return true;
        const [pilote, valeur] = regle.split("=");
        const coche = ctx.d.querySelector(`[name="${pilote}"]:checked`);
        return !!coche && coche.value === valeur;
      })
      .sort();

    check(`${P.nom} · « ${nature} » n'affiche que ce que le serveur admet`,
      JSON.stringify(attendus) === JSON.stringify(ctx.visibles()));

    // Le contrôle décisif : **rien ne part qui ne soit visible**. L'égalité
    // stricte serait fausse — un groupe de boutons radio non coché est visible
    // et n'envoie rien, ce qui est le comportement normal d'un formulaire.
    const vus = ctx.visibles();
    const hors = ctx.envoyes().filter((c) => !vus.includes(c));

    check(`${P.nom} · « ${nature} » n'envoie rien qui ne soit visible`, 0 === hors.length);
  }

  ctx.dom.window.close();
}

/* ================================================================== *
 *  2. Aucune surface de plancher là où il n'y en a pas
 * ================================================================== */

titre("Aucune surface de plancher sur les natures qui n'en créent pas");

{
  const PLANCHER = ["sp_existante", "sp_creee", "sp_totale"];
  const ctx = await monter(PARCOURS[0]);

  for (const nature of ["piscine", "cloture_mur", "panneaux_solaires", "ravalement", "toiture", "modification_facade", "carport"]) {
    ctx.choisir(nature);

    const vus = ctx.visibles().filter((c) => PLANCHER.includes(c));
    const partis = ctx.envoyes().filter((c) => PLANCHER.includes(c));

    check(`« ${nature} » n'affiche aucune surface de plancher`, 0 === vus.length);
    check(`« ${nature} » n'en envoie aucune`, 0 === partis.length);
  }

  // L'astérisque d'obligation doit disparaître avec le champ : la validation
  // d'étape le lit, et bloquerait sur un champ invisible.
  ctx.choisir("piscine");

  const marqueurs = [...ctx.d.querySelectorAll('[data-champ] .req')].filter((m) => !m.hidden);
  const requis = [...ctx.d.querySelectorAll("[data-champ] [required]")];

  check("piscine · aucun marqueur d'obligation ne subsiste", 0 === marqueurs.length);
  check("piscine · aucun contrôle masqué n'est requis", 0 === requis.length);
  check("piscine · les contrôles masqués sont désactivés",
    [...ctx.d.querySelectorAll("[data-champ][hidden] input")].every((i) => i.disabled));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  3. Changer de nature n'emporte pas les anciennes valeurs
 * ================================================================== */

titre("Changement de nature");

{
  const ctx = await monter(PARCOURS[0]);

  ctx.choisir("extension");
  ctx.d.querySelector('[name="sp_creee"]').value = "18";
  ctx.d.querySelector('[name="sp_existante"]').value = "120";

  check("extension · les surfaces partent", ctx.envoyes().includes("sp_creee"));

  ctx.choisir("piscine");

  check("extension → piscine · plus aucune surface n'est envoyée",
    0 === ctx.envoyes().filter((c) => c.startsWith("sp_")).length);
  // La valeur reste dans le contrôle : revenir en arrière ne doit pas obliger à
  // tout resaisir.
  check("extension → piscine · la valeur reste saisie dans le champ",
    "18" === ctx.d.querySelector('[name="sp_creee"]').value);

  ctx.choisir("extension");

  check("piscine → extension · les surfaces redeviennent disponibles",
    ctx.visibles().includes("sp_creee") && ctx.envoyes().includes("sp_creee"));
  check("piscine → extension · avec leur valeur d'origine",
    "18" === ctx.d.querySelector('[name="sp_creee"]').value);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  4. Sans matrice, rien n'est masqué
 * ================================================================== */

titre("Défaut prudent");

{
  // Le greffon absent ou désactivé, le pont ne transmet aucune matrice. Un
  // formulaire amputé serait pire qu'un formulaire trop large : on n'affiche
  // pas moins que ce qu'on sait.
  const P = PARCOURS[0];
  const html = readFileSync(resolve(THEME, "assets/forms/" + P.fichier), "utf8").replace(
    /<script src="[^"]*urbizen-form-[a-z]+\.js[^"]*"><\/script>/g, ""
  );
  const vc = new VirtualConsole(); vc.on("jsdomError", () => {});
  const dom = new JSDOM(html, { url: "https://urbizen.test/f.html", runScripts: "dangerously", pretendToBeVisual: true, virtualConsole: vc });
  const w = dom.window;
  await new Promise((r) => ("complete" === w.document.readyState ? r() : w.addEventListener("load", r)));
  for (const f of ["urbizen-form-tarifs.js", "urbizen-form-pieces.js", "urbizen-form-champs.js"]) {
    w.eval(readFileSync(resolve(THEME, "assets/js/" + f), "utf8"));
  }
  const form = w.document.getElementById("dp-form");
  w.UrbizenChamps.init({ form, surNature: () => "" });

  check("sans matrice, aucun champ n'est masqué",
    0 === [...w.document.querySelectorAll("[data-champ]")].filter((g) => g.hidden).length);

  dom.window.close();
}

/* ================================================================== *
 *  5. Le navigateur ne tient aucune seconde copie de la matrice
 * ================================================================== */

titre("Source unique");

{
  const brut = readFileSync(resolve(THEME, "assets/js/urbizen-form-champs.js"), "utf8");

  // Les commentaires sont retirés : parler d'une piscine pour expliquer le
  // défaut qu'on corrige est permis, en tenir la liste ne l'est pas.
  const module = brut.replace(/\/\*[\s\S]*?\*\//g, " ").replace(/^\s*\/\/.*$/gm, " ");

  for (const nature of ["piscine", "cloture_mur", "maison_individuelle", "sp_creee", "emprise_creee"]) {
    check(`le code du module ne cite aucune nature ni champ : « ${nature} »`, !module.includes(nature));
  }

  check("il reçoit la matrice au lieu de la déclarer", module.includes("appliquerMatrice"));

  const functions = readFileSync(resolve(THEME, "functions.php"), "utf8");

  check("la page émet la matrice depuis le greffon", functions.includes("MatriceChamps::pour_type"));
  check("et la liste des champs conditionnels", functions.includes("MatriceChamps::CONDITIONNELS"));
}

/* ================================================================== *
 *  Piscine : saisie française, calcul, et retour au calcul
 * ================================================================== */

titre("Piscine — nombres français et surface proposée");

{
  const P = PARCOURS[0];
  const ctx = await monter(P);

  ctx.w.UrbizenNombres.surveiller(ctx.form);
  ctx.choisir("piscine");

  const d = ctx.d;
  const val = (n) => d.querySelector(`[name="${n}"]`).value;
  const set = (n, v) => {
    const e = d.querySelector(`[name="${n}"]`);
    e.value = v;
    e.dispatchEvent(new ctx.w.Event("input", { bubbles: true }));
  };
  const note = () => d.querySelector("[data-bassin-note]");
  const bouton = () => d.querySelector("[data-bassin-recalculer]");

  // Le champ doit accepter la virgule : un `type="number"` la refuserait et
  // rendrait une valeur vide, ce qui était le défaut d'origine.
  check("les mesures ne sont plus des champs « number »",
    "text" === d.querySelector('[name="longueur_bassin_m"]').type);
  check("elles annoncent un clavier décimal",
    "decimal" === d.querySelector('[name="longueur_bassin_m"]').getAttribute("inputmode"));

  set("longueur_bassin_m", "8,5");
  check("la virgule est conservée dans le champ", "8,5" === val("longueur_bassin_m"));

  set("largeur_bassin_m", "4");
  check("8,5 × 4 propose 34", "34" === val("surface_bassin_m2"));
  check("la note dit d'où vient la valeur", note().textContent.startsWith("Calculée"));
  check("aucun bouton de recalcul tant que rien n'est personnalisé", bouton().hidden);

  set("longueur_bassin_m", "8.25");
  check("8.25 × 4 propose 33", "33" === val("surface_bassin_m2"));

  // Correction manuelle : le calcul cesse, et le dit.
  set("surface_bassin_m2", "30");
  check("la surface devient personnalisée", "Surface personnalisée." === note().textContent);
  check("le retour au calcul est proposé", !bouton().hidden);

  set("longueur_bassin_m", "10");
  check("changer la longueur n'écrase plus la surface", "30" === val("surface_bassin_m2"));

  bouton().click();
  check("le recalcul rend la main au calcul", "40" === val("surface_bassin_m2"));
  check("et la note repasse à « calculée »", note().textContent.startsWith("Calculée"));

  // Une saisie illisible n'entraîne aucun calcul : mieux vaut rien qu'un chiffre
  // inventé à partir d'une valeur qu'on n'a pas su lire.
  set("longueur_bassin_m", "8,5,2");
  check("une valeur illisible n'entraîne aucun calcul", "40" === val("surface_bassin_m2"));

  ctx.dom.window.close();
}

titre("Piscine — abri et hauteur");

{
  const P = PARCOURS[0];
  const ctx = await monter(P);
  const d = ctx.d;

  ctx.choisir("piscine");

  const abri = (v) => {
    const e = d.querySelector(`[name="presence_abri_piscine"][value="${v}"]`);
    e.checked = true;
    e.dispatchEvent(new ctx.w.Event("change", { bubbles: true }));
  };
  const hauteur = () => d.querySelector('[data-champ="hauteur_abri_m"]');
  const envoyee = () => new ctx.w.FormData(ctx.form).has("hauteur_abri_m");

  check("les trois réponses existent",
    3 === d.querySelectorAll('[name="presence_abri_piscine"]').length);
  check("aucune n'est cochée d'office",
    null === d.querySelector('[name="presence_abri_piscine"]:checked'));
  check("la hauteur est masquée tant qu'aucun abri n'est annoncé", hauteur().hidden);

  abri("oui");
  d.querySelector('[name="hauteur_abri_m"]').value = "1,8";
  check("« oui » révèle la hauteur", !hauteur().hidden);
  check("et elle part avec la requête", envoyee());

  abri("non");
  check("« non » la masque", hauteur().hidden);
  check("et la retire de la requête", !envoyee());

  abri("inconnu");
  check("« inconnu » la masque aussi", hauteur().hidden);
  check("et la retire également", !envoyee());

  abri("oui");
  check("revenir à « oui » la rend, avec sa valeur", !hauteur().hidden && "1,8" === d.querySelector('[name="hauteur_abri_m"]').value);

  ctx.dom.window.close();
}

titre("PC maison — la piscine passe par une question");

{
  /* Une maison neuve peut comporter un bassin, ou non. Demander six mesures à
   * tout constructeur reviendrait à supposer un projet qu'il n'a pas décrit.
   * La question ouvre le bloc, et rien d'autre ne l'ouvre.
   *
   * Le cas qui compte vraiment est celui du **changement d'avis** : on répond
   * oui, on mesure, on repasse à non. Les valeurs restent dans les contrôles —
   * c'est une politesse, revenir en arrière ne doit pas coûter une nouvelle
   * saisie — mais elles ne doivent plus partir. */
  const BASSIN = ["longueur_bassin_m", "largeur_bassin_m", "surface_bassin_m2",
    "profondeur_bassin_m", "presence_abri_piscine", "hauteur_abri_m"];
  const ctx = await monter(PARCOURS[1]);

  ctx.choisir("maison_individuelle");

  check("sans réponse · aucun champ de bassin visible",
    0 === ctx.visibles().filter((c) => BASSIN.includes(c)).length);
  check("sans réponse · la question, elle, est posée", ctx.visibles().includes("piscine_prevue"));
  check("sans réponse · rien ne part", 0 === ctx.envoyes().filter((c) => BASSIN.includes(c)).length);

  ctx.repondre("piscine_prevue", "oui");

  // Cinq, pas six : la hauteur d'abri attend encore sa propre réponse.
  check("oui · les cinq mesures s'affichent",
    5 === ctx.visibles().filter((c) => BASSIN.includes(c)).length);
  check("oui · la hauteur d'abri attend l'abri", !ctx.visibles().includes("hauteur_abri_m"));

  ctx.saisir("longueur_bassin_m", "8,5");
  ctx.saisir("largeur_bassin_m", "4");
  ctx.repondre("presence_abri_piscine", "oui");
  ctx.saisir("hauteur_abri_m", "1,8");

  check("oui · la hauteur d'abri suit l'abri", ctx.visibles().includes("hauteur_abri_m"));
  check("oui · la surface est proposée à 34",
    "34" === ctx.d.querySelector('[name="surface_bassin_m2"]').value);
  check("oui · les six partent", 6 === ctx.envoyes().filter((c) => BASSIN.includes(c)).length);

  for (const reponse of ["non", "inconnu"]) {
    ctx.repondre("piscine_prevue", reponse);

    check(`${reponse} · plus aucun champ de bassin visible`,
      0 === ctx.visibles().filter((c) => BASSIN.includes(c)).length);
    check(`${reponse} · aucun ne part`, 0 === ctx.envoyes().filter((c) => BASSIN.includes(c)).length);
    check(`${reponse} · la réponse, elle, part`, ctx.envoyes().includes("piscine_prevue"));
    check(`${reponse} · la hauteur tombe avec l'abri`,
      ctx.d.querySelector('[name="hauteur_abri_m"]').disabled);
  }

  // Retour à « oui » : ce que la personne avait écrit est toujours là.
  ctx.repondre("piscine_prevue", "oui");

  check("retour à oui · la longueur est retrouvée",
    "8,5" === ctx.d.querySelector('[name="longueur_bassin_m"]').value);
  check("retour à oui · la hauteur d'abri aussi",
    "1,8" === ctx.d.querySelector('[name="hauteur_abri_m"]').value);
  check("retour à oui · les six repartent", 6 === ctx.envoyes().filter((c) => BASSIN.includes(c)).length);

  // Dernier mot avant l'envoi : c'est l'état final qui décide.
  ctx.repondre("piscine_prevue", "non");
  check("dernier mot à « non » · rien ne part", 0 === ctx.envoyes().filter((c) => BASSIN.includes(c)).length);

  // Et la DP « piscine » n'a pas cette porte du tout.
  const dp = await monter(PARCOURS[0]);
  dp.choisir("piscine");

  check("DP · aucune question préalable n'est posée", !dp.visibles().includes("piscine_prevue"));
  check("DP · les mesures s'affichent d'emblée",
    5 === dp.visibles().filter((c) => BASSIN.includes(c)).length);

  ctx.dom.window.close();
  dp.dom.window.close();
}

titre("Le bassin ne déborde sur aucune autre nature");

{
  /* Section 1 compare chaque nature à la matrice, ce qui couvre le bassin par
   * construction. Ce contrôle-ci le dit en clair, parce que c'est le risque
   * propre au lot : six champs neufs, tous nommés, qui ne doivent apparaître
   * que là où un bassin se décrit. Une matrice mal éditée passerait la
   * section 1 — elle compare le rendu à la matrice, pas la matrice au métier.
   *
   * Deux natures les portent, et la seconde n'est pas un oubli : le PC pour
   * maison individuelle demandait déjà « Bassin de piscine » (`piscine_m2`)
   * avant ce lot, une maison neuve se déposant souvent avec sa piscine. Ce
   * banc fige donc dix-huit natures moins ces deux-là. */
  const BASSIN = ["longueur_bassin_m", "largeur_bassin_m", "surface_bassin_m2",
    "profondeur_bassin_m", "presence_abri_piscine", "hauteur_abri_m"];
  const PORTEUSES = { declaration_prealable: "piscine", permis_construire: "maison_individuelle" };

  for (const P of PARCOURS) {
    const ctx = await monter(P);
    const porteuse = PORTEUSES[P.type];
    const autres = Object.keys(MATRICE[P.type]).filter((n) => porteuse !== n);
    let vus = 0, partis = 0, actifs = 0, balayees = 0;

    for (const nature of autres) {
      if (!ctx.choisir(nature)) continue;

      balayees++;
      vus += ctx.visibles().filter((c) => BASSIN.includes(c)).length;
      partis += ctx.envoyes().filter((c) => BASSIN.includes(c)).length;
      actifs += BASSIN.filter((c) => {
        const e = ctx.d.querySelector(`[name="${c}"]`);
        return e && !e.disabled;
      }).length;
    }

    check(`${P.nom} · ${balayees} autres natures balayées`, balayees === autres.length && balayees > 0);
    check(`${P.nom} · aucune n'affiche un champ de bassin`, 0 === vus);
    check(`${P.nom} · aucune n'en laisse un actif`, 0 === actifs);
    check(`${P.nom} · aucune n'en envoie`, 0 === partis);

    // Et la nature porteuse, elle, les rend bien : un balayage qui ne
    // prouverait que l'absence passerait tout aussi bien sur un formulaire
    // qui n'a rien. Sur le PC, il faut d'abord répondre « oui » à la question
    // d'entrée — c'est précisément ce que la porte veut dire.
    ctx.choisir(porteuse);

    if ("permis_construire" === P.type) {
      check(`${P.nom} · « ${porteuse} » n'affiche rien avant la réponse`,
        0 === ctx.visibles().filter((c) => BASSIN.includes(c)).length);
      ctx.repondre("piscine_prevue", "oui");
    }

    check(`${P.nom} · « ${porteuse} » les affiche`,
      5 === ctx.visibles().filter((c) => BASSIN.includes(c)).length);

    ctx.dom.window.close();
  }
}

titre("Le masquage est aussi visuel");

{
  /* Les contrôles ci-dessus vérifient l'attribut `hidden` et l'état `disabled`
   * — donc la garantie qui compte : rien ne part. Ils ne disaient rien de ce
   * que la personne voit, et c'est exactement là que le défaut s'était logé :
   * `#dp-app .dp-field { display: flex; }` bat la règle du navigateur pour
   * `[hidden]`, si bien qu'une piscine affichait encore six champs de surface
   * de plancher, grisés mais présents.
   *
   * On mesure donc le **style calculé**, pas l'attribut. jsdom applique la
   * cascade des feuilles d'auteur et honore `!important` : sans le correctif,
   * ce même contrôle rend `flex` — il n'est pas décoratif.
   *
   * Quatre preuves, parce qu'un champ écarté doit disparaître de quatre
   * façons : de l'écran, du clavier, de l'interaction et de l'envoi. */
  const REGLE = /#dp-app \[hidden\]\s*\{[^}]*display:\s*none\s*!important/;

  for (const f of [
    "wordpress/urbizen-child/assets/forms/dp-formulaire.html",
    "wordpress/urbizen-child/assets/forms/pc-formulaire.html",
    "frontend/formulaires/dp-formulaire.html",
    "frontend/formulaires/pc-formulaire.html",
  ]) {
    const ou = f.startsWith("frontend/") ? "maquette" : "thème";

    check(`${ou} · ${f.split("/").pop()} · la règle est présente`,
      REGLE.test(readFileSync(resolve(ROOT, f), "utf8")));
  }

  for (const P of PARCOURS) {
    const ctx = await monter(P);

    // Une nature qui n'admet ni plancher ni bassin : tout ce qui est
    // conditionnel doit alors être invisible pour de bon.
    ctx.choisir("permis_construire" === P.type ? "autre" : "cloture_mur");

    const masques = [...ctx.d.querySelectorAll("[data-champ]")].filter((g) => g.hidden);
    const rendus = masques.filter((g) => "none" !== ctx.w.getComputedStyle(g).display);
    const actifs = masques.filter((g) =>
      [...g.querySelectorAll("input, select, textarea")].some((c) => !c.disabled));
    const atteignables = masques.filter((g) =>
      [...g.querySelectorAll("input, select, textarea, button, a[href]")].some(
        (c) => !c.disabled && -1 !== (c.tabIndex || 0)));
    const envoyes = ctx.envoyes();

    check(`${P.nom} · ${masques.length} groupes écartés, et il y en a`, masques.length > 0);
    check(`${P.nom} · leur style calculé est bien « none »`, 0 === rendus.length);
    check(`${P.nom} · aucun de leurs contrôles n'est actif`, 0 === actifs.length);
    check(`${P.nom} · aucun n'est atteignable au clavier`, 0 === atteignables.length);
    check(`${P.nom} · aucun ne part dans le FormData`, 0 === envoyes.length);

    ctx.dom.window.close();
  }
}

titre("Cibles tactiles à 44 px");

{
  /* Le seuil est une exigence produit, pas une préférence : 40 px se rate au
   * doigt. Le contrôle lit la feuille de style — jsdom ne met pas en page, il
   * ne peut donc pas mesurer une hauteur rendue — et exige que chaque famille
   * de contrôle nommée porte le seuil. */
  const CIBLES = [
    ".dp-seg label",
    ".dp-check",
    ".dp-locate",
    ".dp-map-toggle",
    ".dp-parcel-ok",
    ".dp-cadastre-inconnu",
    ".dp-attest label[for]",
    ".dp-file-btn",
    ".dp-travail-ajouter",
    ".dp-travail-suppr",
    "button.dp-btn",
  ];

  for (const f of [
    "wordpress/urbizen-child/assets/forms/dp-formulaire.html",
    "wordpress/urbizen-child/assets/forms/pc-formulaire.html",
    "frontend/formulaires/dp-formulaire.html",
    "frontend/formulaires/pc-formulaire.html",
  ]) {
    const css = readFileSync(resolve(ROOT, f), "utf8");
    const ou = f.startsWith("frontend/") ? "maquette" : "thème";
    const bloc = css.match(/#dp-app \.dp-seg label,[\s\S]*?min-height:\s*44px;\s*\}/);
    const manquantes = CIBLES.filter((s) => !bloc || !bloc[0].includes(`#dp-app ${s},`) && !bloc[0].includes(`#dp-app ${s} {`) && !bloc[0].includes(`#dp-app ${s}\n`));

    check(`${ou} · ${f.split("/").pop()} · les ${CIBLES.length} familles portent 44 px`,
      !!bloc && 0 === manquantes.length);
  }
}

titre("Piscine — analyse partagée avec le serveur");

{
  const P = PARCOURS[0];
  const ctx = await monter(P);
  const N = ctx.w.UrbizenNombres;

  // Les mêmes acceptations et les mêmes refus que le normaliseur PHP : une
  // saisie acceptée ici ne doit jamais être refusée là-bas.
  for (const bon of ["8", "8,5", "8.5", " 8,5 ", ",5", "34,25"]) {
    check(`« ${bon} » est accepté`, N.VALIDE === N.analyser(bon, {}).etat);
  }

  for (const mauvais of ["8,5,2", "8,5.2", "1e3", "abc", "8.", "8 m"]) {
    check(`« ${mauvais} » est refusé`, N.FORMAT === N.analyser(mauvais, {}).etat);
  }

  check("un champ vide est absent, pas invalide", N.ABSENT === N.analyser("", {}).etat);
  check("un négatif sort des bornes", N.BORNE === N.analyser("-3", { min: 0 }).etat);
  check("zéro est refusé pour une mesure", N.BORNE === N.analyser("0", { strict: true }).etat);
  check("au-delà de la borne haute", N.BORNE === N.analyser("200", { max: 100 }).etat);
  check("l'écriture rendue est française", "8,5" === N.afficher(8.5));
  check("sans décimale inutile", "34" === N.afficher(34));

  ctx.dom.window.close();
}

console.log("");
if (fail) {
  console.log(`\x1b[31m${fail} CONTROLE(S) EN ECHEC\x1b[0m`);
  process.exit(1);
}
console.log("\x1b[32mTOUS LES CONTROLES PASSENT\x1b[0m");
