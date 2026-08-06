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

  for (const f of ["urbizen-form-tarifs.js", "urbizen-form-pieces.js", "urbizen-form-champs.js", "urbizen-form-bridge.js"]) {
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
  const visibles = () => [...d.querySelectorAll("[data-champ]")].filter((g) => !g.hidden).map((g) => g.getAttribute("data-champ")).sort();
  const envoyes = () => {
    const fd = new w.FormData(form);
    return MATRICE.conditionnels.filter((c) => fd.has(c)).sort();
  };

  return { dom, w, d, form, champs, choisir, visibles, envoyes };
}

/* ================================================================== *
 *  1. Ce que le navigateur montre est ce que le serveur admet
 * ================================================================== */

for (const P of PARCOURS) {
  titre(`${P.nom} — la matrice du serveur, appliquée telle quelle`);

  const ctx = await monter(P);

  for (const nature of Object.keys(MATRICE[P.type])) {
    if (!ctx.choisir(nature)) continue;

    const attendus = MATRICE[P.type][nature]
      .filter((c) => ctx.d.querySelector(`[data-champ="${c}"]`))
      .sort();

    check(`${P.nom} · « ${nature} » n'affiche que ce que le serveur admet`,
      JSON.stringify(attendus) === JSON.stringify(ctx.visibles()));

    // Le contrôle décisif : ce qui part, et non ce qui se voit.
    check(`${P.nom} · « ${nature} » n'envoie que cela`,
      JSON.stringify(ctx.visibles()) === JSON.stringify(ctx.envoyes()));
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

console.log("");
if (fail) {
  console.log(`\x1b[31m${fail} CONTROLE(S) EN ECHEC\x1b[0m`);
  process.exit(1);
}
console.log("\x1b[32mTOUS LES CONTROLES PASSENT\x1b[0m");
