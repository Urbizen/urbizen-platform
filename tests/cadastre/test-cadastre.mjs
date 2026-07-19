/* Tests du composant cadastre en DOM simulé (jsdom).
   Couvre : montage automatique, identifiants uniques, non-injection HTML,
   adresse valide, adresse introuvable, erreur réseau, clearStored(). */
import { JSDOM } from "jsdom";
import { readFileSync } from "node:fs";

import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const SRC = resolve(ROOT, "wordpress/urbizen-platform/assets/js/urbizen-cadastre.js");

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(62), cond ? "OK" : "ECHEC");
};

/* --- Environnement --- */
const dom = new JSDOM(
  `<!doctype html><body>
     <div data-urbizen-cadastre="1" data-label="Adresse du projet" data-storage-key="parcel"></div>
     <div data-urbizen-cadastre="1" data-label="Second bloc" data-storage-key="autre"></div>
   </body>`,
  { url: "https://exemple.test/", runScripts: "outside-only" }
);
const { window } = dom;
// On attend la fin du chargement : readyState passe a "complete", ce qui
// permet de verifier le montage automatique immediat du composant.
await new Promise((r) => window.addEventListener("load", r));
global.window = window;
global.document = window.document;
global.CustomEvent = window.CustomEvent;
global.AbortController = window.AbortController;

// Leaflet absent volontairement : on vérifie le message d'erreur visible.
let fetchMode = "ok";
window.fetch = async (url) => {
  if (fetchMode === "network") throw new Error("network down");
  if (url.includes("/completion")) {
    const results = fetchMode === "empty"
      ? []
      : [{ fulltext: '12 rue des Lilas 33000 Bordeaux <img src=x onerror="alert(1)">', x: -0.57, y: 44.83, city: "Bordeaux", zipcode: "33000" }];
    return { ok: true, json: async () => ({ results }) };
  }
  return { ok: true, json: async () => ({ features: [] }) };
};

/* --- Chargement du composant --- */
window.eval(readFileSync(SRC, "utf8"));
check("readyState complete au chargement", window.document.readyState === "complete");
const UC = window.UrbizenCadastre;
const [c1, c2] = [...window.document.querySelectorAll("[data-urbizen-cadastre]")];

check("Montage automatique des deux conteneurs",
  c1.querySelector(".uc-input") !== null && c2.querySelector(".uc-input") !== null);

/* --- Identifiants uniques --- */
const id1 = c1.querySelector(".uc-input").id;
const id2 = c2.querySelector(".uc-input").id;
check("Identifiants d'input distincts", id1 && id2 && id1 !== id2);
check("Label lie au bon champ",
  c1.querySelector(".uc-label").getAttribute("for") === id1 &&
  c2.querySelector(".uc-label").getAttribute("for") === id2);
check("aria-controls pointe la bonne liste",
  c1.querySelector(".uc-input").getAttribute("aria-controls") === c1.querySelector(".uc-suggest").id);
const allIds = [...window.document.querySelectorAll("[id]")].map((n) => n.id);
check("Aucun identifiant duplique dans la page", new Set(allIds).size === allIds.length);

/* --- Libelles issus des attributs --- */
check("Libelle lu depuis data-label",
  c1.querySelector(".uc-label").textContent === "Adresse du projet" &&
  c2.querySelector(".uc-label").textContent === "Second bloc");

/* --- Adresse valide : la suggestion hostile ne doit pas creer d'element --- */
const input = c1.querySelector(".uc-input");
const type = async (v) => {
  input.value = v;
  input.dispatchEvent(new window.Event("input"));
  await new Promise((r) => setTimeout(r, 400));
};

await type("12 rue des lilas");
const opts = c1.querySelectorAll('.uc-suggest li[role="option"]');
check("Adresse valide : une suggestion affichee", opts.length === 1);
check("Suggestion hostile non interpretee",
  c1.querySelector(".uc-suggest img") === null &&
  opts[0].textContent.includes("<img src=x"));
check("Option porte un identifiant prefixe par l'instance",
  opts[0].id.startsWith(id1.replace("-input", "")));

/* --- Adresse introuvable --- */
fetchMode = "empty";
await type("adresse qui n existe pas");
check("Adresse introuvable : message explicite",
  /Aucune adresse trouvée/.test(c1.querySelector(".uc-suggest").textContent));

/* --- Erreur reseau --- */
fetchMode = "network";
await type("12 rue des lilas");
check("Erreur reseau : message explicite",
  /Recherche indisponible/.test(c1.querySelector(".uc-suggest").textContent));

/* --- sessionStorage : cle prefixee + clearStored --- */
window.sessionStorage.setItem("urbizen:parcel", JSON.stringify({ address: "test" }));
check("getStored prefixe la cle", UC.getStored("parcel")?.address === "test");
check("clearStored efface", UC.clearStored("parcel") && UC.getStored("parcel") === null);
check("clearStored expose dans l'API", typeof UC.clearStored === "function");

/* --- Absence de Leaflet : message visible, pas de plantage --- */
check("Aucun innerHTML sur donnees d'API",
  !readFileSync(SRC, "utf8").match(/\.innerHTML\s*=(?!\s*"")/));

console.log("\n" + (fail === 0 ? "TOUS LES CONTROLES PASSENT" : fail + " CONTROLE(S) EN ECHEC"));
process.exit(fail === 0 ? 0 : 1);
