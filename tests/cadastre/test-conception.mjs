/* Tests du parcours de conception en DOM simulé (jsdom).

   Le HTML vient du rendu **réel** de ConceptionRenderer, régénéré avant
   chaque exécution : un gabarit écrit à la main ferait passer une divergence
   pour une preuve.

   Couvre : navigation, conditions, validation, brouillon de session,
   consentement au brouillon persistant, expiration, schéma incompatible,
   collection de fichiers, manifeste, soumission et verdict. */
import { JSDOM } from "jsdom";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ICI = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(ICI, "../..");
const SRC = resolve(ROOT, "wordpress/urbizen-platform/assets/js/urbizen-conception.js");
const FIXTURE = resolve(ICI, "fixture-conception.html");

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(70), cond ? "OK" : "ECHEC");
};

const source = readFileSync(SRC, "utf8");
const html = readFileSync(FIXTURE, "utf8");

/** Monte une page neuve, avec un schéma éventuellement modifié. */
async function monter({ schemaVersion = null, session = null, local = null, fetchImpl = null } = {}) {
  const dom = new JSDOM(html, { url: "https://exemple.test/apercu/", runScripts: "outside-only" });
  const { window } = dom;

  const schema = JSON.parse(window.document.getElementById("schema").textContent);
  if (schemaVersion !== null) schema.version = schemaVersion;

  if (session) window.sessionStorage.setItem(session.cle, JSON.stringify(session.valeur));
  if (local) window.localStorage.setItem(local.cle, JSON.stringify(local.valeur));

  window.urbizenConception = { "urbizen-conception-1": schema };
  window.fetch = fetchImpl || (() => Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success&reference=URB-2026-0001" }));
  window.FormData = class {
    constructor() { this.entrees = []; }
    append(k, v, n) { this.entrees.push([k, v, n]); }
    get(k) { const e = this.entrees.find((x) => x[0] === k); return e ? e[1] : null; }
    getAll(k) { return this.entrees.filter((x) => x[0] === k).map((x) => x[1]); }
  };

  window.eval(source);

  // Le script attend DOMContentLoaded : sans cette attente, on observerait un
  // formulaire non initialisé et l'on croirait à un défaut.
  if (window.document.readyState === "loading") {
    await new Promise((r) => window.addEventListener("load", r));
  }

  return { dom, window, doc: window.document, schema };
}

const CLE = (v) => "urbizen:conception:draft:v" + v;

/* ================================================================== *
 * 1 · NAVIGATION
 * ================================================================== */
{
  const { doc } = await monter();
  const etapes = [...doc.querySelectorAll(".urbizen-conception__etape")];

  check("1 · six étapes montées", etapes.length === 6);
  check("1 · seule la première est visible", !etapes[0].hidden && etapes.slice(1).every((e) => e.hidden));
  check("1 · Précédent est masqué au départ", doc.querySelector('[data-action="precedent"]').hidden);
  check("1 · Envoyer est masqué au départ", doc.querySelector('[data-action="envoyer"]').hidden);
  check("1 · Suivant est visible", !doc.querySelector('[data-action="suivant"]').hidden);

  // L'étape 1 a deux champs obligatoires : sans réponse, on n'avance pas.
  doc.querySelector('[data-action="suivant"]').click();

  check("1 · sans réponse obligatoire, on n’avance pas", !etapes[0].hidden);
  check("1 · le résumé d’erreurs apparaît", !doc.querySelector(".urbizen-conception__erreurs").hidden);
  check("1 · les champs manquants sont marqués", doc.querySelectorAll('[aria-invalid="true"]').length > 0);
  check("1 · l’erreur est écrite, pas seulement colorée",
    [...doc.querySelectorAll(".urbizen-conception__erreur")].some((e) => !e.hidden && e.textContent.length > 0));

  // On répond, puis on avance.
  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  doc.querySelector('[data-action="suivant"]').click();

  check("1 · avec les réponses, on avance", etapes[0].hidden && !etapes[1].hidden);
  check("1 · aria-current suit l’étape",
    doc.querySelectorAll('[aria-current="step"]').length === 1 &&
      doc.querySelectorAll(".urbizen-conception__progression-item")[1].hasAttribute("aria-current"));
  check("1 · Précédent devient visible", !doc.querySelector('[data-action="precedent"]').hidden);

  // Précédent ne valide rien et n'efface rien.
  const avant = doc.querySelector('[data-field="nature"] input[type="radio"]').checked;
  doc.querySelector('[data-action="precedent"]').click();

  check("1 · Précédent revient sans valider", !etapes[0].hidden);
  check("1 · et n’efface aucune valeur", doc.querySelector('[data-field="nature"] input[type="radio"]').checked === avant);
}

/* ================================================================== *
 * 2 · CONDITIONS
 * ================================================================== */
{
  const { doc } = await monter();
  const adresse = doc.querySelector('[data-field="terrain_adresse"]');

  check("2 · un champ conditionnel est masqué au départ", adresse.hidden);
  check("2 · et exclu de la soumission", adresse.querySelector("input").disabled);

  const oui = [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "oui");
  oui.checked = true;
  oui.dispatchEvent(new doc.defaultView.Event("change", { bubbles: true }));

  check("2 · la condition remplie l’affiche", !adresse.hidden);
  check("2 · et le réintègre", !adresse.querySelector("input").disabled);

  // On saisit puis on change d'avis : le champ redevient sans objet.
  adresse.querySelector("input").value = "1 place de la Mairie";
  const non = [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non");
  non.checked = true;
  oui.checked = false;
  non.dispatchEvent(new doc.defaultView.Event("change", { bubbles: true }));

  check("2 · la condition devenue fausse le masque", adresse.hidden);
  check("2 · et l’exclut de nouveau", adresse.querySelector("input").disabled);
  check("2 · sans effacer la saisie", adresse.querySelector("input").value === "1 place de la Mairie");
}

/* ================================================================== *
 * 3 · BROUILLON DE SESSION
 * ================================================================== */
{
  const { doc, window, schema } = await monter();

  check("3 · aucune entrée localStorage à l’affichage", window.localStorage.length === 0);

  const champ = doc.querySelector('[data-field="nature"] input[type="radio"]');
  champ.checked = true;
  champ.dispatchEvent(new window.Event("change", { bubbles: true }));

  const brut = window.sessionStorage.getItem(CLE(schema.version));

  check("3 · le brouillon de session est écrit", typeof brut === "string" && brut.length > 0);
  check("3 · toujours rien dans localStorage", window.localStorage.length === 0);

  const charge = JSON.parse(brut);

  check("3 · il porte la version du schéma", String(charge.schemaVersion) === String(schema.version));
  check("3 · un horodatage", typeof charge.savedAt === "number");
  check("3 · l’étape courante", typeof charge.step === "number");
  check("3 · les valeurs", typeof charge.values === "object");
  check("3 · l’état du consentement", charge.persist === false);
  check("3 · exactement cinq clés", Object.keys(charge).sort().join(",") === "persist,savedAt,schemaVersion,step,values");

  // Rien d'interdit n'y figure.
  const json = brut.toLowerCase();
  for (const interdit of ["nonce", "token", "company_website", "manifest", "urbizen_return", "reference", "signature", "tmp", "/"]) {
    check(`3 · le brouillon ne contient pas « ${interdit} »`, !json.includes(interdit));
  }
}

/* ================================================================== *
 * 4 · CONSENTEMENT AU BROUILLON PERSISTANT
 * ================================================================== */
{
  const { doc, window, schema } = await monter();
  const consentement = doc.querySelector('[data-role="consentement-brouillon"]');

  check("4 · la case existe", !!consentement);
  check("4 · elle est décochée par défaut", consentement.checked === false);
  check("4 · elle est distincte du consentement contractuel",
    consentement.id !== (doc.querySelector('[data-field="rgpd"] input') || {}).id);
  check("4 · un texte prévient pour un appareil partagé",
    doc.querySelector(".urbizen-conception__brouillon-note").textContent.includes("partagé"));

  consentement.checked = true;
  consentement.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("4 · le consentement déclenche l’écriture persistante", window.localStorage.getItem(CLE(schema.version)) !== null);

  consentement.checked = false;
  consentement.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("4 · son retrait efface immédiatement", window.localStorage.getItem(CLE(schema.version)) === null);
  check("4 · le brouillon de session subsiste", window.sessionStorage.getItem(CLE(schema.version)) !== null);
  check("4 · et l’interface le dit", doc.querySelector('[data-role="info-brouillon"]').textContent.includes("désactivée"));
}

/* ================================================================== *
 * 5 · RESTAURATION, EXPIRATION, SCHÉMA INCOMPATIBLE
 * ================================================================== */
{
  const { doc, window, schema } = await monter({
    session: { cle: CLE("1"), valeur: { schemaVersion: "1", savedAt: Date.now(), step: 2, values: { nature: ["maison"] }, persist: false } }
  });

  const radio = [...doc.querySelectorAll('[data-field="nature"] input')].find((i) => i.value === "maison");

  check("5 · les valeurs sont restaurées", radio && radio.checked);
  check("5 · l’étape est restaurée", !doc.querySelectorAll(".urbizen-conception__etape")[2].hidden);
  check("5 · l’utilisateur est prévenu pour les documents",
    doc.querySelector('[data-role="info-brouillon"]').textContent.includes("documents"));
}

{
  const huitJours = Date.now() - 8 * 24 * 60 * 60 * 1000;
  const { doc, window } = await monter({
    local: { cle: CLE("1"), valeur: { schemaVersion: "1", savedAt: huitJours, step: 3, values: { nature: ["maison"] }, persist: true } }
  });

  check("5 · un brouillon de plus de sept jours n’est pas restauré",
    ![...doc.querySelectorAll('[data-field="nature"] input')].some((i) => i.checked));
  check("5 · il est supprimé", window.localStorage.getItem(CLE("1")) === null);
  check("5 · et l’utilisateur est informé", doc.querySelector('[data-role="info-brouillon"]').textContent.includes("expiré"));
}

{
  const { doc, window } = await monter({
    session: { cle: CLE("1"), valeur: { schemaVersion: "0", savedAt: Date.now(), step: 4, values: { nature: ["maison"] }, persist: false } }
  });

  check("5 · un schéma incompatible n’est pas restauré",
    ![...doc.querySelectorAll('[data-field="nature"] input')].some((i) => i.checked));
  check("5 · aucune valeur n’est injectée ailleurs",
    [...doc.querySelectorAll('input[type="text"]')].every((i) => i.value === ""));
  check("5 · le formulaire reste à la première étape", !doc.querySelectorAll(".urbizen-conception__etape")[0].hidden);
  check("5 · l’ancien brouillon est supprimé", window.sessionStorage.getItem(CLE("1")) === null);
  check("5 · et l’utilisateur en est averti",
    doc.querySelector('[data-role="info-brouillon"]').textContent.includes("antérieure"));
}

{
  const { doc, window, schema } = await monter();
  const champ = doc.querySelector('[data-field="nature"] input[type="radio"]');
  champ.checked = true;
  champ.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("5 · un brouillon existe", window.sessionStorage.getItem(CLE(schema.version)) !== null);

  doc.querySelector('[data-action="effacer-brouillon"]').click();

  check("5 · la suppression manuelle efface la session", window.sessionStorage.getItem(CLE(schema.version)) === null);
  check("5 · et le persistant", window.localStorage.getItem(CLE(schema.version)) === null);
}

/* ================================================================== *
 * 6 · FICHIERS ET MANIFESTE
 * ================================================================== */
function fichier(window, nom, taille, type = "image/jpeg") {
  const f = new window.File([new Uint8Array(taille)], nom, { type });
  return f;
}

{
  const { doc, window } = await monter();
  const input = doc.querySelector('input[name="photos[]"]');
  const liste = doc.querySelector('[data-bloc="photos"]');

  Object.defineProperty(input, "files", { value: [fichier(window, "plan.jpg", 1000)], configurable: true });
  input.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("6 · un document accepté est listé", liste.children.length === 1);
  check("6 · l’input est vidé au profit de la collection interne", input.value === "");
  check("6 · le nom est affiché comme texte", liste.textContent.includes("plan.jpg"));

  // Une seconde sélection s'ajoute, elle ne remplace pas.
  Object.defineProperty(input, "files", { value: [fichier(window, "coupe.jpg", 2000)], configurable: true });
  input.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("6 · une seconde sélection s’ajoute", liste.children.length === 2);

  // Un format refusé n'entre pas.
  Object.defineProperty(input, "files", { value: [fichier(window, "dessin.svg", 500, "image/svg+xml")], configurable: true });
  input.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("6 · un format non accepté est refusé", liste.children.length === 2);

  // Un document trop lourd non plus.
  Object.defineProperty(input, "files", { value: [fichier(window, "enorme.jpg", 11 * 1024 * 1024)], configurable: true });
  input.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("6 · un document trop volumineux est refusé", liste.children.length === 2);

  // Retrait individuel.
  liste.querySelector("button").click();

  check("6 · le retrait individuel fonctionne", liste.children.length === 1);

  // La navigation ne perd pas les fichiers.
  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  doc.querySelector('[data-action="suivant"]').click();
  doc.querySelector('[data-action="precedent"]').click();

  check("6 · les fichiers survivent à la navigation", liste.children.length === 1);
}

{
  // Limite par bloc : onze documents, dix retenus.
  const { doc, window } = await monter();
  const input = doc.querySelector('input[name="photos[]"]');
  const liste = doc.querySelector('[data-bloc="photos"]');
  const onze = Array.from({ length: 11 }, (_, i) => fichier(window, `p${i}.jpg`, 100));

  Object.defineProperty(input, "files", { value: onze, configurable: true });
  input.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("6 · la limite de dix par bloc est appliquée", liste.children.length === 10);
}

/* ================================================================== *
 * 7 · SOUMISSION ET VERDICT
 * ================================================================== */
{
  let capture = null;
  const { doc, window } = await monter({
    fetchImpl: (url, opts) => { capture = opts; return Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success&reference=URB-2026-0001" }); }
  });

  // On remplit les six champs obligatoires.
  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non").click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;

  const input = doc.querySelector('input[name="photos[]"]');
  Object.defineProperty(input, "files", { value: [fichier(window, "plan.jpg", 1234)], configurable: true });
  input.dispatchEvent(new window.Event("change", { bubbles: true }));

  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("7 · fetch a été appelé", capture !== null);
  check("7 · en POST", capture && capture.method === "POST");

  const donnees = capture.body;
  const manifeste = JSON.parse(donnees.get("urbizen_manifest"));

  check("7 · un manifeste est transmis", !!manifeste);
  check("7 · version 1", manifeste.version === 1);
  check("7 · un document déclaré", manifeste.total_count === 1);
  check("7 · la taille déclarée", manifeste.total_size === 1234);
  check("7 · le bloc exact", manifeste.blocks.photos.count === 1 && manifeste.blocks.photos.size === 1234);
  check("7 · quatre clés à la racine", Object.keys(manifeste).sort().join(",") === "blocks,total_count,total_size,version");
  check("7 · deux clés par bloc", Object.keys(manifeste.blocks.photos).sort().join(",") === "count,size");
  check("7 · aucun nom de fichier dans le manifeste", !JSON.stringify(manifeste).includes("plan.jpg"));

  check("7 · le document est joint sous le nom attendu", donnees.getAll("photos[]").length === 1);
  check("7 · le nonce est transmis", donnees.get("urbizen_conception_nonce") !== null);
  check("7 · le jeton est transmis", donnees.get("urbizen_token") !== null);
  check("7 · les champs inapplicables sont absents", donnees.get("terrain_adresse") === null);

  check("7 · le bouton est désactivé", doc.querySelector('[data-action="envoyer"]').disabled);
  check("7 · le brouillon de session est effacé après succès", window.sessionStorage.length === 0);
  check("7 · et le persistant", window.localStorage.length === 0);
}

{
  // Une redirection d'erreur mène à une page en 200 : ce n'est pas un succès.
  const { doc, window } = await monter({
    fetchImpl: () => Promise.resolve({ url: "https://exemple.test/?urbizen_submission=error", status: 200, ok: true })
  });

  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non").click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;

  const champ = doc.querySelector('[data-field="nature"] input[type="radio"]');
  champ.dispatchEvent(new window.Event("change", { bubbles: true }));

  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("7 · UN 200 APRÈS REDIRECTION D’ERREUR N’EST PAS UN SUCCÈS",
    !doc.querySelector('[data-action="envoyer"]').disabled);
  check("7 · le brouillon est conservé", window.sessionStorage.length === 1);
  check("7 · un message générique est affiché",
    doc.querySelector('[data-role="info-brouillon"]').textContent.includes("pas pu"));
}

{
  // Erreur réseau : tout est conservé.
  const { doc, window } = await monter({ fetchImpl: () => Promise.reject(new Error("réseau")) });

  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non").click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;
  doc.querySelector('[data-field="nature"] input[type="radio"]').dispatchEvent(new window.Event("change", { bubbles: true }));

  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("7 · une erreur réseau réactive le bouton", !doc.querySelector('[data-action="envoyer"]').disabled);
  check("7 · et conserve le brouillon", window.sessionStorage.length === 1);
}

{
  // Double clic : un seul envoi.
  let appels = 0;
  const { doc, window } = await monter({
    fetchImpl: () => { appels++; return new Promise((r) => setTimeout(() => r({ url: "https://exemple.test/?urbizen_submission=success" }), 30)); }
  });

  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non").click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;

  const form = doc.querySelector(".urbizen-conception__form");
  form.dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  form.dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 60));

  check("7 · un double envoi n’appelle le serveur qu’une fois", appels === 1);
}

/* ================================================================== *
 * 8 · AUCUNE FUITE
 * ================================================================== */
{
  check("8 · aucun console.log dans le script", !/console\.(log|debug|info)\s*\(/.test(source));
  check("8 · aucun innerHTML", !/\.innerHTML\s*=/.test(source));
  check("8 · aucun eval", !/\beval\s*\(/.test(source));
  check("8 · aucune table tarifaire en dur",
    !/449|299|149\b/.test(source.replace(/\/\*[\s\S]*?\*\//g, "")));
  check("8 · aucune limite de dépôt en dur",
    !/10485760|26214400/.test(source));
  check("8 · aucune dépendance à jQuery", !/\$\(|jQuery/.test(source));
}

/* ================================================================== *
 * 9 · RESTAURATION TOUT OU RIEN
 * ================================================================== */
{
  // Un brouillon riche, produit sous la version N.
  const ancien = {
    schemaVersion: "0",
    savedAt: Date.now(),
    step: 3,
    values: {
      nature: ["maison"],
      situation: ["terrain_nu"],
      a_terrain: ["oui"],
      terrain_adresse: ["1 place de la Mairie"],
      terrain_cp: ["75004"],
      options: ["facades", "pack_ftc"],
      nom: ["Camille Fictif"],
      email: ["camille@exemple.test"]
    },
    persist: true
  };

  const { doc, window } = await monter({
    session: { cle: CLE("1"), valeur: ancien },
    local: { cle: CLE("1"), valeur: ancien }
  });

  const coches = [...doc.querySelectorAll("input[type=radio], input[type=checkbox]")].filter((i) => i.checked);
  const remplis = [...doc.querySelectorAll("input[type=text], input[type=number], textarea, select")].filter((i) => i.value !== "");

  check("9 · aucune valeur cochée n’est restaurée", coches.length === 0);
  check("9 · aucun champ texte n’est rempli", remplis.length === 0);
  check("9 · aucune option ancienne n’est active",
    ![...doc.querySelectorAll('[data-field="options"] input')].some((i) => i.checked));
  check("9 · l’étape ancienne n’est pas restaurée", !doc.querySelectorAll(".urbizen-conception__etape")[0].hidden);
  check("9 · aucun champ conditionnel n’est ouvert à tort",
    doc.querySelector('[data-field="terrain_adresse"]').hidden);
  check("9 · et il reste exclu de la soumission",
    doc.querySelector('[data-field="terrain_adresse"] input').disabled);
  check("9 · aucun aria-invalid résiduel", doc.querySelectorAll('[aria-invalid="true"]').length === 0);
  check("9 · aucune erreur affichée",
    [...doc.querySelectorAll(".urbizen-conception__erreur")].every((e) => e.hidden));
  check("9 · le résumé d’erreurs reste masqué", doc.querySelector(".urbizen-conception__erreurs").hidden);
  check("9 · le brouillon de session est supprimé", window.sessionStorage.getItem(CLE("1")) === null);
  check("9 · le brouillon persistant est supprimé", window.localStorage.getItem(CLE("1")) === null);
  check("9 · l’incompatibilité est annoncée",
    doc.querySelector('[data-role="info-brouillon"]').textContent.includes("antérieure"));
  check("9 · le consentement n’est pas réactivé",
    doc.querySelector('[data-role="consentement-brouillon"]').checked === false);

  // Le formulaire reste utilisable : rien n'est cassé.
  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  doc.querySelector('[data-action="suivant"]').click();

  check("9 · le formulaire reste pleinement fonctionnel",
    doc.querySelectorAll(".urbizen-conception__etape")[0].hidden &&
      !doc.querySelectorAll(".urbizen-conception__etape")[1].hidden);
}

{
  // L'inverse : session incompatible, persistant compatible.
  const compatible = {
    schemaVersion: "1",
    savedAt: Date.now(),
    step: 2,
    values: { nature: ["maison"] },
    persist: true
  };

  const { doc, window } = await monter({
    session: { cle: CLE("1"), valeur: { ...compatible, schemaVersion: "0" } },
    local: { cle: CLE("1"), valeur: compatible }
  });

  const radio = [...doc.querySelectorAll('[data-field="nature"] input')].find((i) => i.value === "maison");

  check("9 · session incompatible, persistant compatible → restauration du persistant", radio && radio.checked);
  check("9 · à la bonne étape", !doc.querySelectorAll(".urbizen-conception__etape")[2].hidden);
  const restant = window.sessionStorage.getItem(CLE("1"));

  check("9 · la session incompatible ne subsiste plus telle quelle",
    restant === null || JSON.parse(restant).schemaVersion === "1");
  check("9 · aucune fusion des deux brouillons",
    [...doc.querySelectorAll("input[type=text]")].every((i) => i.value === ""));
}

{
  // Les deux incompatibles : rien, et un message.
  const ancien = { schemaVersion: "0", savedAt: Date.now(), step: 1, values: { nature: ["maison"] }, persist: true };
  const { doc, window } = await monter({
    session: { cle: CLE("1"), valeur: ancien },
    local: { cle: CLE("1"), valeur: ancien }
  });

  check("9 · deux brouillons incompatibles → rien n’est restauré",
    ![...doc.querySelectorAll("input")].some((i) => i.checked && i.type !== "hidden"));
  check("9 · les deux sont supprimés",
    window.sessionStorage.getItem(CLE("1")) === null && window.localStorage.getItem(CLE("1")) === null);
  check("9 · le message est visible",
    doc.querySelector('[data-role="info-brouillon"]').textContent.length > 0);
}

/* ================================================================== *
 * 10 · CLAVIER, FOCUS, ENTRÉE
 * ================================================================== */
{
  const { doc, window } = await monter();

  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  doc.querySelector('[data-action="suivant"]').click();

  const titre = doc.querySelectorAll(".urbizen-conception__etape")[1].querySelector(".urbizen-conception__etape-titre");

  check("10 · le focus va au titre de l’étape après Suivant", doc.activeElement === titre);
  check("10 · le titre est focalisable", titre.getAttribute("tabindex") === "-1");
  check("10 · le changement d’étape est annoncé",
    doc.querySelector(".urbizen-conception__annonce").textContent.includes("Étape 2"));

  doc.querySelector('[data-action="precedent"]').click();

  const titre0 = doc.querySelectorAll(".urbizen-conception__etape")[0].querySelector(".urbizen-conception__etape-titre");

  check("10 · le focus revient au titre après Précédent", doc.activeElement === titre0);

  // Entrée dans un champ avance, sans soumettre.
  let envois = 0;
  window.fetch = () => { envois++; return Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success" }); };

  const champ = doc.querySelector('[data-field="surface_hab"] input') || doc.querySelector('input[type="number"]');
  const evt = new window.KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true });
  champ.dispatchEvent(evt);
  await new Promise((r) => setTimeout(r, 10));

  check("10 · Entrée n’envoie pas depuis une étape intermédiaire", envois === 0);
  check("10 · et l’événement est bien annulé", evt.defaultPrevented);
}

{
  // Le résumé d'erreurs pointe vers les champs concernés.
  const { doc } = await monter();
  doc.querySelector('[data-action="suivant"]').click();

  const liens = [...doc.querySelectorAll(".urbizen-conception__erreurs-liste a")];

  check("10 · le résumé liste les champs manquants", liens.length === 2);
  check("10 · chaque entrée pointe vers un champ", liens.every((a) => a.getAttribute("href").startsWith("#")));
  check("10 · le résumé prend le focus", doc.activeElement === doc.querySelector(".urbizen-conception__erreurs"));

  liens[0].click();

  check("10 · un clic sur le résumé donne le focus au champ",
    doc.activeElement && doc.activeElement.closest("[data-field]") !== null);
}

/* ================================================================== *
 * 11 · VINGT FICHIERS ET LIMITE TOTALE
 * ================================================================== */
{
  const { doc, window } = await monter();
  const photos = doc.querySelector('input[name="photos[]"]');
  const plans = doc.querySelector('input[name="plan_terrain[]"]');
  const urba = doc.querySelector('input[name="urbanisme[]"]');

  Object.defineProperty(photos, "files", { value: Array.from({ length: 10 }, (_, i) => fichier(window, `p${i}.jpg`, 100)), configurable: true });
  photos.dispatchEvent(new window.Event("change", { bubbles: true }));
  Object.defineProperty(plans, "files", { value: Array.from({ length: 10 }, (_, i) => fichier(window, `t${i}.jpg`, 100)), configurable: true });
  plans.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("11 · vingt documents acceptés",
    doc.querySelector('[data-bloc="photos"]').children.length === 10 &&
      doc.querySelector('[data-bloc="plan_terrain"]').children.length === 10);

  Object.defineProperty(urba, "files", { value: [fichier(window, "plu.pdf", 100, "application/pdf")], configurable: true });
  urba.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("11 · le vingt-et-unième est refusé", doc.querySelector('[data-bloc="urbanisme"]').children.length === 0);

  // Poids total.
  const { doc: d2, window: w2 } = await monter();
  const gros = d2.querySelector('input[name="photos[]"]');
  Object.defineProperty(gros, "files", { value: Array.from({ length: 3 }, (_, i) => fichier(w2, `g${i}.jpg`, 9 * 1024 * 1024)), configurable: true });
  gros.dispatchEvent(new w2.Event("change", { bubbles: true }));

  check("11 · la limite de poids total est appliquée",
    d2.querySelector('[data-bloc="photos"]').children.length === 2);
}

/* ================================================================== *
 * 12 · AUCUNE DONNÉE PERSONNELLE DANS LES MESSAGES
 * ================================================================== */
{
  const { doc, window } = await monter({
    fetchImpl: () => Promise.resolve({ url: "https://exemple.test/?urbizen_submission=error" })
  });

  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non").click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;
  doc.querySelector('[data-field="nature"] input[type="radio"]').dispatchEvent(new window.Event("change", { bubbles: true }));

  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  const messages = doc.querySelector('[data-role="info-brouillon"]').textContent +
    doc.querySelector(".urbizen-conception__annonce").textContent;

  check("12 · aucun nom dans les messages", !messages.includes("Camille"));
  check("12 · aucun courriel", !messages.includes("@exemple"));
  check("12 · aucun code technique", !/upload_|urbizen_submission|nonce/.test(messages));
  check("12 · un message générique est bien affiché", messages.length > 0);
}

/* ================================================================== *
 * 13 · MUTATIONS DU PARCOURS
 *
 * Le script est chargé dans une page neuve après substitution textuelle : on
 * observe le comportement réel du mutant, pas la présence d'un motif.
 * ================================================================== */
async function monterMute(remplacements, options = {}) {
  let mute = source;

  for (const [de, vers] of remplacements) {
    if (!mute.includes(de)) throw new Error("motif introuvable : " + de.slice(0, 60));
    mute = mute.replace(de, vers);
  }

  const dom = new JSDOM(html, { url: "https://exemple.test/apercu/", runScripts: "outside-only" });
  const { window } = dom;
  const schema = JSON.parse(window.document.getElementById("schema").textContent);

  if (options.session) window.sessionStorage.setItem(options.session.cle, JSON.stringify(options.session.valeur));

  window.urbizenConception = { "urbizen-conception-1": schema };
  window.fetch = options.fetchImpl || (() => Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success" }));
  window.FormData = class {
    constructor() { this.entrees = []; }
    append(k, v, n) { this.entrees.push([k, v, n]); }
    get(k) { const e = this.entrees.find((x) => x[0] === k); return e ? e[1] : null; }
    getAll(k) { return this.entrees.filter((x) => x[0] === k).map((x) => x[1]); }
  };

  window.eval(mute);
  if (window.document.readyState === "loading") await new Promise((r) => window.addEventListener("load", r));

  return { doc: window.document, window };
}

function remplirObligatoires(doc) {
  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non").click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;
}

{
  // M1 · localStorage écrit sans consentement.
  const { doc, window } = await monterMute([[
    "\t\tif ( charge.persist ) {\n\t\t\tthis.brouillon.ecrire( 'localStorage', charge );\n\t\t}",
    "\t\tthis.brouillon.ecrire( 'localStorage', charge );"
  ]]);

  const champ = doc.querySelector('[data-field="nature"] input[type="radio"]');
  champ.checked = true;
  champ.dispatchEvent(new window.Event("change", { bubbles: true }));

  check("M1 · garde retirée → LOCALSTORAGE EST ÉCRIT SANS CONSENTEMENT", window.localStorage.length > 0);

  const sain = await monter();
  const c2 = sain.doc.querySelector('[data-field="nature"] input[type="radio"]');
  c2.checked = true;
  c2.dispatchEvent(new sain.window.Event("change", { bubbles: true }));

  check("M1 · le dépôt n’écrit rien sans consentement", sain.window.localStorage.length === 0);
}

{
  // M2 · restauration partielle d'un schéma incompatible.
  const ancien = { schemaVersion: "0", savedAt: Date.now(), step: 2, values: { nature: ["maison"], nom: ["Camille Fictif"] }, persist: false };

  // La garde de version rend `{incompatible:true}` ; on la fait rendre la
  // charge telle quelle, ce qui provoquerait exactement la restauration
  // partielle que l'on veut interdire.
  const { doc } = await monterMute(
    [["\t\t\tthis.effacer( magasin );\n\n\t\t\treturn { incompatible: true };", "\t\t\treturn charge;"]],
    { session: { cle: CLE("1"), valeur: ancien } }
  );

  const restaure = [...doc.querySelectorAll("input")].some((i) => i.checked && i.type === "radio") ||
    doc.querySelector('[data-field="nom"] input').value !== "";

  check("M2 · gardes retirées → UNE VALEUR ANCIENNE EST RESTAURÉE", restaure);

  const sain = await monter({ session: { cle: CLE("1"), valeur: ancien } });

  check("M2 · le dépôt ne restaure rien",
    ![...sain.doc.querySelectorAll("input")].some((i) => i.checked && i.type === "radio") &&
      sain.doc.querySelector('[data-field="nom"] input').value === "");
}

{
  // M3 · un HTTP 200 après redirection d'erreur pris pour un succès.
  const erreur = () => Promise.resolve({ url: "https://exemple.test/?urbizen_submission=error", status: 200, ok: true });

  const { doc, window } = await monterMute(
    [["\t\tif ( url.indexOf( 'urbizen_submission=error' ) !== -1 ) {\n\t\t\treturn false;\n\t\t}", "\t\treturn true;"]],
    { fetchImpl: erreur }
  );

  remplirObligatoires(doc);
  doc.querySelector('[data-field="nature"] input[type="radio"]').dispatchEvent(new window.Event("change", { bubbles: true }));
  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("M3 · verdict muté → UNE ERREUR PASSE POUR UN SUCCÈS", window.sessionStorage.length === 0);

  const sain = await monter({ fetchImpl: erreur });
  remplirObligatoires(sain.doc);
  sain.doc.querySelector('[data-field="nature"] input[type="radio"]').dispatchEvent(new sain.window.Event("change", { bubbles: true }));
  sain.doc.querySelector(".urbizen-conception__form").dispatchEvent(new sain.window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("M3 · le dépôt conserve le brouillon", sain.window.sessionStorage.length === 1);
  check("M3 · et réactive le bouton", !sain.doc.querySelector('[data-action="envoyer"]').disabled);
}

{
  // M4 · double soumission autorisée.
  let appels = 0;
  const lent = () => { appels++; return new Promise((r) => setTimeout(() => r({ url: "https://exemple.test/?urbizen_submission=success" }), 30)); };

  const { doc, window } = await monterMute(
    [["\t\tif ( this.envoiEnCours ) {\n\t\t\treturn;\n\t\t}", "\t\tif ( false ) {\n\t\t\treturn;\n\t\t}"]],
    { fetchImpl: lent }
  );

  remplirObligatoires(doc);
  const form = doc.querySelector(".urbizen-conception__form");
  form.dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  form.dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 60));

  check("M4 · garde retirée → DEUX ENVOIS PARTENT", appels === 2);
}

{
  // M5 · le brouillon est supprimé après une erreur.
  const erreur = () => Promise.reject(new Error("réseau"));

  const { doc, window } = await monterMute(
    [["\t\t// Les brouillons et les fichiers restent : c'est tout l'intérêt.\n\t\tthis.informer( message );",
      "\t\tthis.brouillon.effacerTout();\n\t\tthis.informer( message );"]],
    { fetchImpl: erreur }
  );

  remplirObligatoires(doc);
  doc.querySelector('[data-field="nature"] input[type="radio"]').dispatchEvent(new window.Event("change", { bubbles: true }));
  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("M5 · muté → LE BROUILLON EST PERDU APRÈS UNE ERREUR", window.sessionStorage.length === 0);

  const sain = await monter({ fetchImpl: erreur });
  remplirObligatoires(sain.doc);
  sain.doc.querySelector('[data-field="nature"] input[type="radio"]').dispatchEvent(new sain.window.Event("change", { bubbles: true }));
  sain.doc.querySelector(".urbizen-conception__form").dispatchEvent(new sain.window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("M5 · le dépôt le conserve", sain.window.sessionStorage.length === 1);
}

{
  // M6 · un champ conditionnel masqué reste soumis.
  let capture = null;
  const capter = (url, opts) => { capture = opts; return Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success" }); };

  const { doc, window } = await monterMute(
    [["\t\t\t\t\t\tc.disabled = ! pertinent;", "\t\t\t\t\t\tc.disabled = false;"]],
    { fetchImpl: capter }
  );

  // On répond « oui », on saisit, puis on revient sur « non » : le champ
  // devient sans objet.
  const oui = [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "oui");
  oui.checked = true;
  oui.dispatchEvent(new window.Event("change", { bubbles: true }));
  doc.querySelector('[data-field="terrain_adresse"] input').value = "1 place de la Mairie";
  const non = [...doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non");
  oui.checked = false;
  non.checked = true;
  non.dispatchEvent(new window.Event("change", { bubbles: true }));

  doc.querySelector('[data-field="nature"] input[type="radio"]').click();
  doc.querySelector('[data-field="situation"] input[type="radio"]').click();
  doc.querySelector('[data-field="nom"] input').value = "Camille Fictif";
  doc.querySelector('[data-field="email"] input').value = "camille@exemple.test";
  doc.querySelector('[data-field="rgpd"] input').checked = true;

  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("M6 · désactivation retirée → UN CHAMP SANS OBJET EST SOUMIS",
    capture && capture.body.get("terrain_adresse") === "1 place de la Mairie");

  // Dépôt sain : même parcours, le champ n'est pas transmis.
  let capture2 = null;
  const sain = await monter({ fetchImpl: (u, o) => { capture2 = o; return Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success" }); } });
  const oui2 = [...sain.doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "oui");
  oui2.checked = true;
  oui2.dispatchEvent(new sain.window.Event("change", { bubbles: true }));
  sain.doc.querySelector('[data-field="terrain_adresse"] input').value = "1 place de la Mairie";
  const non2 = [...sain.doc.querySelectorAll('[data-field="a_terrain"] input')].find((i) => i.value === "non");
  oui2.checked = false;
  non2.checked = true;
  non2.dispatchEvent(new sain.window.Event("change", { bubbles: true }));
  remplirObligatoires(sain.doc);
  sain.doc.querySelector(".urbizen-conception__form").dispatchEvent(new sain.window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("M6 · le dépôt ne transmet pas le champ sans objet",
    capture2 && capture2.body.get("terrain_adresse") === null);
}

{
  // M7 · le prix affiché ne part jamais au serveur.
  let capture = null;
  const { doc, window } = await monter({ fetchImpl: (u, o) => { capture = o; return Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success" }); } });

  remplirObligatoires(doc);
  doc.querySelector(".urbizen-conception__form").dispatchEvent(new window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  const envoye = capture.body.entrees.map((e) => e[0]).join(",");

  check("M7 · aucun champ de prix n’est transmis", !/prix|total|estimation|montant/i.test(envoye));
  check("M7 · l’estimation reste affichée", doc.querySelector(".urbizen-conception__estimation").textContent.includes("€"));
}

/* ================================================================== *
 * 14 · ESTIMATION TARIFAIRE
 *
 * Défaut trouvé par la revue visuelle réelle : le script lisait un champ
 * « options » inexistant, et l'estimation restait figée sur le prix de base.
 * ================================================================== */
{
  const { doc, window } = await monter();
  const est = () => doc.querySelector(".urbizen-conception__estimation").textContent;

  check("14 · l’estimation part du prix de base", est().includes("449"));

  const cocher = (champ, valeur) => {
    const i = [...doc.querySelectorAll('[data-field="' + champ + '"] input')].find((x) => x.value === valeur);
    i.checked = true;
    i.dispatchEvent(new window.Event("change", { bubbles: true }));
    return i;
  };

  cocher("options_tarifees", "facades");

  check("14 · une option tarifée s’ajoute", est().includes("598"));

  cocher("options_tarifees", "toiture");

  check("14 · deux options s’additionnent", est().includes("697"));

  // Le pack remplace façades, toiture et coupe : il ne double pas.
  const p = cocher("options_tarifees", "pack_ftc");

  check("14 · le pack remplace les prestations qu’il contient", est().includes("748"));

  p.checked = false;
  p.dispatchEvent(new window.Event("change", { bubbles: true }));

  cocher("options_sur_devis", "insertion3d");

  check("14 · une prestation sur devis est signalée", est().includes("sur devis"));
  check("14 · sans fausser le montant chiffré", est().includes("697"));

  // Aucune valeur tarifaire n’est transmise au serveur.
  let capture = null;
  const sain = await monter({ fetchImpl: (u, o) => { capture = o; return Promise.resolve({ url: "https://exemple.test/?urbizen_submission=success" }); } });
  remplirObligatoires(sain.doc);
  sain.doc.querySelector(".urbizen-conception__form").dispatchEvent(new sain.window.Event("submit", { bubbles: true, cancelable: true }));
  await new Promise((r) => setTimeout(r, 20));

  check("14 · l’estimation n’est jamais transmise",
    !capture.body.entrees.some((e) => /estimation|prix|montant/i.test(String(e[0]))));
}

/* ================================================================== *
 * 15 · SANS JAVASCRIPT
 * ================================================================== */
{
  // Page brute, script jamais exécuté.
  const dom = new JSDOM(html, { url: "https://exemple.test/", runScripts: "outside-only" });
  const d = dom.window.document;

  check("15 · le message noscript est présent", !!d.querySelector("noscript"));
  check("15 · il dit que l’envoi ne fonctionnera pas",
    d.querySelector("noscript").textContent.includes("nécessite JavaScript"));
  check("15 · les six étapes restent consultables",
    [...d.querySelectorAll(".urbizen-conception__etape")].filter((e) => !e.hidden).length === 6);
  check("15 · AUCUN BOUTON NE LAISSE CROIRE QUE L’ENVOI MARCHERA",
    d.querySelector(".urbizen-conception__navigation").hidden === true);
  check("15 · le script révèle la navigation", source.includes("nav.hidden = false"));
}

console.log("");
if (fail > 0) { console.log(`${fail} CONTROLE(S) EN ECHEC`); process.exit(1); }
console.log("TOUS LES CONTROLES PASSENT");
