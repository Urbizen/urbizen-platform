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

console.log("");
if (fail > 0) { console.log(`${fail} CONTROLE(S) EN ECHEC`); process.exit(1); }
console.log("TOUS LES CONTROLES PASSENT");
