/* Tests du pont cadastre → formulaire (jsdom).
   Couvre les 12 exigences de la version 0.4.0 et les 14 contrôles
   supplémentaires demandés à la revue. Aucun appel réseau réel. */
import { JSDOM } from "jsdom";
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const SRC_CADASTRE = resolve(ROOT, "wordpress/urbizen-platform/assets/js/urbizen-cadastre.js");
const SRC_FORM = resolve(ROOT, "wordpress/urbizen-platform/assets/js/urbizen-form.js");

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(66), cond ? "OK" : "ECHEC");
};

/* ── HTML réel, produit par Renderer.php ──
   La fixture est régénérée par run-all.sh avant chaque exécution
   (`php make-fixture.php > fixture.html`). Aucune structure HTML n'est
   reproduite à la main ici : si le rendu serveur change de façon
   incompatible, ces tests échouent au lieu de rester verts sur une copie
   périmée. */
const FIXTURE = resolve(dirname(fileURLToPath(import.meta.url)), "fixture.html");

if (!existsSync(FIXTURE)) {
  console.error("fixture.html absente. Lancez ./run-all.sh, qui la génère depuis Renderer.php.");
  process.exit(2);
}

const FIXTURE_HTML = readFileSync(FIXTURE, "utf8");

/* Découpe la fixture en instances, indexées par leur clé de stockage. */
const INSTANCES = new Map();
for (const bloc of FIXTURE_HTML.split(/\n(?=<div class="urbizen-form")/)) {
  const m = bloc.match(/data-storage-key="([^"]+)"/);
  if (m) INSTANCES.set(m[1], bloc.trim());
}

function gabaritFormulaire(storageKey = "parcel") {
  const html = INSTANCES.get(storageKey);
  if (!html) {
    throw new Error(`fixture.html ne contient pas d'instance « ${storageKey} » — regénérez-la via run-all.sh`);
  }
  return html;
}

const CONTRAT = {
  schemaVersion: "1.0",
  source: "urbizen-cadastre",
  confirmedAt: "2026-07-19T20:00:00.000Z",
  address: { label: "Place Pey Berland 33000 Bordeaux", houseNumber: "", street: "Place Pey Berland", postcode: "33000", city: "Bordeaux", cityCode: "33063" },
  location: { latitude: 44.837654, longitude: -0.577233 },
  parcel: { communeCode: "33063", prefix: "000", section: "KE", number: "0112", id: "33063000KE0112", surfaceM2: 7117 },
};

async function nouvelleFenetre(html, { avecStockage = true } = {}) {
  const dom = new JSDOM(`<!doctype html><body>${html}</body>`, {
    url: "https://exemple.test/",
    runScripts: "outside-only",
  });
  const { window } = dom;
  await new Promise((r) => window.addEventListener("load", r));
  if (!avecStockage) {
    Object.defineProperty(window, "sessionStorage", {
      configurable: true,
      get() { throw new Error("stockage refusé"); },
    });
  }
  return window;
}

const chargerForm = (w) => w.eval(readFileSync(SRC_FORM, "utf8"));
const chargerCadastre = (w) => w.eval(readFileSync(SRC_CADASTRE, "utf8"));
const val = (w, from, i = 0) => w.document.querySelectorAll(`[data-from="${from}"]`)[i].value;

/* ===== 1. Événement reçu APRÈS montage du formulaire ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  check("1. Formulaire monté avant le cadastre : champs vides au départ", val(w, "address.label") === "");
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { bubbles: true, detail: { ...CONTRAT, storageKey: "urbizen:parcel" } }));
  check("1. Événement reçu après montage : adresse reprise", val(w, "address.label") === CONTRAT.address.label);
  check("1. Section et numéro repris", val(w, "parcel.section") === "KE" && val(w, "parcel.number") === "0112");
  check("1. Champs techniques renseignés", val(w, "location.latitude") === "44.837654" && val(w, "parcel.id") === "33063000KE0112");
  check("1. Résumé lisible affiché", !w.document.querySelector(".uf-summary").hidden
    && /KE/.test(w.document.querySelector(".uf-summary-parcel").textContent)
    && /indicative/.test(w.document.querySelector(".uf-summary-parcel").textContent));
}

/* ===== 2. Données déjà stockées AVANT montage ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  w.sessionStorage.setItem("urbizen:parcel", JSON.stringify(CONTRAT));
  chargerForm(w);
  check("2. Reprise depuis sessionStorage au montage", val(w, "address.label") === CONTRAT.address.label);
  check("2. Origine de la reprise exposée", w.document.querySelector(".urbizen-form").getAttribute("data-uf-origine") === "stockage");
}

/* ===== 3. Payload incomplet ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const partiel = { schemaVersion: "1.0", address: { label: "12 rue des Lilas 33000 Bordeaux" }, parcel: {}, location: {} };
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: partiel }));
  check("3. Payload incomplet : adresse reprise", val(w, "address.label") === "12 rue des Lilas 33000 Bordeaux");
  check("3. Payload incomplet : champs absents laissés vides", val(w, "parcel.section") === "" && val(w, "parcel.surfaceM2") === "");
  check("3. Payload incomplet : aucune valeur inventée", val(w, "location.latitude") === "");
}

/* ===== 4. Payload invalide ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  for (const mauvais of [null, undefined, "chaine", 42, [], { address: "pas un objet" }, {}]) {
    w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: mauvais }));
  }
  check("4. Payloads invalides ignorés sans exception", val(w, "address.label") === "");
  check("4. Résumé non affiché", w.document.querySelector(".uf-summary").hidden);
}

/* ===== 5. sessionStorage indisponible ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire(), { avecStockage: false });
  chargerForm(w);
  check("5. Stockage refusé : montage sans exception", typeof w.UrbizenForm === "object");
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));
  check("5. Stockage refusé : l'événement remplit quand même", val(w, "address.label") === CONTRAT.address.label);
}

/* ===== 6 et 9. Plusieurs formulaires, ciblage déterministe ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire("parcel-a") + gabaritFormulaire("parcel-b"));
  w.sessionStorage.setItem("urbizen:parcel-a", JSON.stringify(CONTRAT));
  w.sessionStorage.setItem("urbizen:parcel-b", JSON.stringify({ ...CONTRAT, address: { ...CONTRAT.address, label: "Autre adresse 33000 Bordeaux" } }));
  chargerForm(w);
  check("6. Deux formulaires montés", w.document.querySelectorAll('[data-uf-mounted="1"]').length === 2);
  check("6. Chaque formulaire lit SA clé", val(w, "address.label", 0) === CONTRAT.address.label && val(w, "address.label", 1) === "Autre adresse 33000 Bordeaux");
  const ids = [...w.document.querySelectorAll(".uf-input")].map((i) => i.id);
  check("6. Identifiants uniques entre instances", new Set(ids).size === ids.length);
  const labels = [...w.document.querySelectorAll(".uf-label")];
  check("6. Chaque label pointe un champ existant", labels.every((l) => w.document.getElementById(l.getAttribute("for"))));
  // Un événement ciblé ne doit toucher qu'une instance
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: { ...CONTRAT, storageKey: "urbizen:parcel-a", address: { ...CONTRAT.address, label: "Ciblé A" } } }));
  check("9. Événement ciblé : seule l'instance A change", val(w, "address.label", 0) === "Ciblé A" && val(w, "address.label", 1) === "Autre adresse 33000 Bordeaux");
}

/* ===== 7. Changement de parcelle après un premier choix ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));
  const second = { ...CONTRAT, confirmedAt: "2026-07-19T21:00:00.000Z", parcel: { ...CONTRAT.parcel, section: "KI", number: "0020", id: "33063000KI0020", surfaceM2: 248 } };
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: second }));
  check("7. Changement de parcelle : section mise à jour", val(w, "parcel.section") === "KI" && val(w, "parcel.number") === "0020");
  check("7. Changement de parcelle : surface mise à jour", val(w, "parcel.surfaceM2") === "248");
  check("7. Changement de parcelle : confirmedAt mis à jour", val(w, "confirmedAt") === second.confirmedAt);
  // revalidation après changement
  let recu = null;
  w.document.addEventListener("urbizen:location-form-validated", (e) => { recu = e.detail; });
  w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  check("7. Revalidation après changement : contrat à jour", recu && recu.contract.parcel.section === "KI" && recu.contract.parcel.surfaceM2 === 248);
}

/* ===== 8. Effacement explicite ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  w.sessionStorage.setItem("urbizen:parcel", JSON.stringify(CONTRAT));
  chargerForm(w);
  check("8. Reprise ne vide PAS le stockage", w.sessionStorage.getItem("urbizen:parcel") !== null);
  w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  check("8. Validation ne vide PAS le stockage (0.4.0)", w.sessionStorage.getItem("urbizen:parcel") !== null);
  w.document.querySelector(".uf-clear").click();
  check("8. Effacement explicite : stockage vidé", w.sessionStorage.getItem("urbizen:parcel") === null);
  check("8. Effacement explicite : champs vidés", val(w, "address.label") === "" && val(w, "parcel.section") === "");
  check("8. Effacement explicite : résumé masqué", w.document.querySelector(".uf-summary").hidden);
}

/* ===== 10. Absence de XSS ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const hostile = {
    ...CONTRAT,
    address: { ...CONTRAT.address, label: '<img src=x onerror="window.__xss=1">', city: "<script>window.__xss2=1</script>" },
    parcel: { ...CONTRAT.parcel, section: "<b>KE</b>" },
  };
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: hostile }));
  check("10. Aucune balise injectée dans le résumé", w.document.querySelector(".uf-summary").querySelector("img, script, b") === null);
  check("10. Aucun script exécuté", w.__xss === undefined && w.__xss2 === undefined);
  check("10. Payload hostile conservé en texte", val(w, "address.label").includes("<img src=x"));
}

/* ===== 11. Aucun envoi réseau avant validation ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  let appels = 0;
  w.fetch = () => { appels++; return Promise.resolve({ ok: true, json: async () => ({}) }); };
  w.XMLHttpRequest = function () { appels++; this.open = this.send = this.setRequestHeader = () => {}; };
  w.navigator.sendBeacon = () => { appels++; return true; };
  chargerForm(w);
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));
  let soumissionHTML = false;
  w.document.querySelector(".uf-form").addEventListener("submit", (e) => { if (!e.defaultPrevented) soumissionHTML = true; });
  w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  check("11. Aucun appel réseau après validation", appels === 0);
  check("11. Soumission HTML empêchée", soumissionHTML === false);
  const src = readFileSync(SRC_FORM, "utf8").replace(/\/\*[\s\S]*?\*\/|\/\/[^\n]*/g, "");
  check("11. Code source : ni fetch, ni XHR, ni sendBeacon", !/fetch\s*\(|XMLHttpRequest|sendBeacon/.test(src));
  check("11. Code source : aucun formulaire avec action", !/action\s*=/.test(src));
}

/* ===== 12. Sans JavaScript : message de repli ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  check("12. Repli noscript présent", /nécessite JavaScript/.test(w.document.querySelector("noscript").textContent));
}

/* ===== Contrôles supplémentaires demandés à la revue ===== */

/* confirmedAt posé à la confirmation, pas à la réponse API */
{
  const w = await nouvelleFenetre('<div id="c"></div>');
  w.fetch = async (url) => {
    if (url.includes("/completion")) return { ok: true, json: async () => ({ results: [{ fulltext: "Place Pey Berland, 33000 Bordeaux", x: -0.577, y: 44.837, city: "Bordeaux", zipcode: "33000", street: "Place Pey Berland" }] }) };
    if (url.includes("/search")) return { ok: true, json: async () => ({ features: [{ properties: { label: "Place Pey Berland 33000 Bordeaux", postcode: "33000", city: "Bordeaux", citycode: "33063", street: "Place Pey Berland" }, geometry: { type: "Point", coordinates: [-0.577, 44.837] } }] }) };
    return { ok: true, json: async () => ({ features: [{ properties: { section: "KE", numero: "0112", idu: "33063000KE0112", contenance: 7117, code_insee: "33063", com_abs: "000", nom_com: "Bordeaux" }, geometry: { type: "MultiPolygon", coordinates: [[[[0, 0], [1, 1], [1, 0], [0, 0]]]] } }] }) };
  };
  chargerCadastre(w);
  const inst = w.UrbizenCadastre.mount("#c", {});
  const champ = w.document.querySelector(".uc-input");
  champ.value = "place pey berland"; champ.dispatchEvent(new w.Event("input", { bubbles: true }));
  await new Promise((r) => setTimeout(r, 500));
  w.document.querySelector('.uc-suggest li[role="option"]').click();
  await new Promise((r) => setTimeout(r, 800));
  const avant = new Date().toISOString();
  await new Promise((r) => setTimeout(r, 20));
  w.document.querySelector(".uc-btn-confirm").click();
  const contrat = inst.getContract();
  check("+ confirmedAt posé au moment de la confirmation", contrat.confirmedAt > avant);
  check("+ Contrat canonique : structure imbriquée et versionnée", contrat.schemaVersion === "1.0" && contrat.source === "urbizen-cadastre" && !!contrat.address && !!contrat.parcel);
  check("+ street et houseNumber captés depuis le géocodeur", contrat.address.street === "Place Pey Berland");
  check("+ prefix cadastral capté (com_abs)", contrat.parcel.prefix === "000");
  check("+ Codes commune conservés séparément", contrat.address.cityCode === "33063" && contrat.parcel.communeCode === "33063");
  check("+ AUCUNE géométrie dans le contrat", !("geometry" in contrat) && !("geometry" in contrat.parcel) && !JSON.stringify(contrat).includes("MultiPolygon"));
  // publication
  let publie = null;
  w.document.addEventListener("urbizen:parcel-confirmed", (e) => { publie = e.detail; });
  w.document.querySelector(".uc-continue").click();
  check("+ Événement publié : contrat canonique sans géométrie", publie && publie.schemaVersion === "1.0" && !JSON.stringify(publie).includes("MultiPolygon"));
  const stocke = JSON.parse(w.sessionStorage.getItem("urbizen:parcel"));
  check("+ sessionStorage : contrat canonique sans géométrie", stocke.schemaVersion === "1.0" && !JSON.stringify(stocke).includes("MultiPolygon"));
  check("+ Aucune structure plate parallèle publiée", publie.cadastralSection === undefined && publie.address !== undefined);
}

/* Divergence des codes commune */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const divergent = { ...CONTRAT, parcel: { ...CONTRAT.parcel, communeCode: "33999" } };
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: divergent }));
  check("+ Divergence codes commune : signalée, non bloquante", !w.document.querySelector(".uf-notice").hidden
    && w.document.querySelector(".urbizen-form").getAttribute("data-uf-commune-divergente") === "1");
  check("+ Divergence : les deux valeurs conservées telles quelles", val(w, "address.cityCode") === "33063" && val(w, "parcel.communeCode") === "33999");
  let recu = null;
  w.document.addEventListener("urbizen:location-form-validated", (e) => { recu = e.detail; });
  w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  check("+ Divergence : validation possible, aucune valeur inventée", recu && recu.contract.address.cityCode === "33063" && recu.contract.parcel.communeCode === "33999");
}

/* Aucune trace du payload en console */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  const traces = [];
  for (const m of ["log", "info", "warn", "error", "debug"]) w.console[m] = (...a) => traces.push(a.join(" "));
  chargerForm(w);
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));
  w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  const fuite = traces.join(" ");
  check("+ Console : aucune donnée personnelle journalisée",
    !/Pey Berland|33000|Bordeaux|33063|KE|0112|44\.83|-0\.57/.test(fuite));
  const src = readFileSync(SRC_FORM, "utf8").replace(/\/\*[\s\S]*?\*\/|\/\/[^\n]*/g, "");
  check("+ Code source : aucun console.* résiduel", !/console\s*\./.test(src));
}

/* Ciblage : aucune lecture arbitraire de toutes les clés */
{
  const w = await nouvelleFenetre(gabaritFormulaire("parcel"));
  w.sessionStorage.setItem("urbizen:autre-chose", JSON.stringify(CONTRAT));
  w.sessionStorage.setItem("urbizen:projet", "dp");
  chargerForm(w);
  check("+ Aucune reprise depuis une clé non ciblée", val(w, "address.label") === "");
  const src = readFileSync(SRC_FORM, "utf8");
  check("+ Code source : pas de balayage des clés urbizen:*",
    !/Object\.keys\s*\(\s*sessionStorage|sessionStorage\.key\s*\(/.test(src));
}

/* Surface : cas limites */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const champ = () => w.document.querySelector('[data-from="parcel.surfaceM2"]');
  const soumettre = () => {
    let d = null;
    const h = (e) => { d = e.detail; };
    w.document.addEventListener("urbizen:location-form-validated", h);
    w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
    w.document.removeEventListener("urbizen:location-form-validated", h);
    return d;
  };
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));

  champ().value = ""; let r = soumettre();
  check("+ Surface absente : validation acceptée, surfaceM2 null", r && r.contract.parcel.surfaceM2 === null);
  champ().value = "0"; r = soumettre();
  check("+ Surface nulle : refusée (strictement positive attendue)", r === null);
  champ().value = "-5"; r = soumettre();
  check("+ Surface négative : refusée", r === null && !w.document.querySelector(".uf-field-terrain_superficie .uf-error").hidden);
  champ().value = "123.45"; r = soumettre();
  check("+ Surface décimale : acceptée", r && r.contract.parcel.surfaceM2 === 123.45);
  champ().value = "99999999"; r = soumettre();
  check("+ Surface anormalement élevée : refusée", r === null);
  /* Un input[type=number] ne peut pas porter une valeur non numérique : le
     navigateur la rejette avant même la validation. On vérifie donc les deux
     barrières séparément — celle du navigateur, puis celle de la
     normalisation, qui protège les payloads arrivant par événement. */
  champ().value = "abc";
  check("+ Surface non numérique : rejetée par le champ nombre", champ().value === "");
  check("+ Surface non numérique : normalisation renvoie null",
    w.UrbizenForm.normalize({ ...CONTRAT, parcel: { ...CONTRAT.parcel, surfaceM2: "abc" } }).parcel.surfaceM2 === null);
  check("+ Surface objet ou tableau : normalisation renvoie null",
    w.UrbizenForm.normalize({ ...CONTRAT, parcel: { ...CONTRAT.parcel, surfaceM2: { a: 1 } } }).parcel.surfaceM2 === null
    && w.UrbizenForm.normalize({ ...CONTRAT, parcel: { ...CONTRAT.parcel, surfaceM2: [1] } }).parcel.surfaceM2 === null);
  champ().value = "7117"; r = soumettre();
  check("+ Surface valide : acceptée à nouveau", r && r.contract.parcel.surfaceM2 === 7117);
}

/* Longueurs maximales */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const long = "X".repeat(5000);
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: { ...CONTRAT, address: { ...CONTRAT.address, label: long, city: long }, parcel: { ...CONTRAT.parcel, section: long } } }));
  /* Plus aucune troncature silencieuse : une valeur démesurée est refusée en
     entier, une valeur simplement non conforme est conservée et signalée. */
  /* Tout ce qui identifiait la localisation ayant été refusé, le contrat
     devient inexploitable : rien n'est repris, et la personne en est avertie
     plutôt que de voir un formulaire muet. */
  check("+ Chaîne démesurée refusée, pas tronquée", val(w, "address.label") === "" && val(w, "parcel.section") === "");
  check("+ Payload inexploitable : signalé, pas ignoré en silence",
    !w.document.querySelector(".uf-notice").hidden
    && /n’a pas pu être reprise/.test(w.document.querySelector(".uf-notice").textContent));
  /* Une valeur simplement non conforme, elle, est conservée et signalée sur
     son champ — la personne peut la corriger. */
  const w2 = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w2);
  w2.document.dispatchEvent(new w2.CustomEvent("urbizen:parcel-confirmed", { detail: { ...CONTRAT, parcel: { ...CONTRAT.parcel, section: "TROPLONG" } } }));
  check("+ Section non conforme conservée telle quelle", val(w2, "parcel.section") === "TROPLONG");
  check("+ Section non conforme signalée sur le champ",
    w2.document.querySelector('[data-from="parcel.section"]').getAttribute("aria-invalid") === "true");
}

/* Pollution de prototype */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const pollue = JSON.parse('{"schemaVersion":"1.0","__proto__":{"pollue":"oui"},"constructor":{"x":1},"address":{"label":"Adresse test","__proto__":{"pollue2":"oui"}},"location":{},"parcel":{"section":"KE","number":"0112"}}');
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: pollue }));
  check("+ Prototype non pollué (Object)", ({}).pollue === undefined && ({}).pollue2 === undefined);
  check("+ Prototype non pollué (fenêtre)", w.eval("({}).pollue") === undefined);
  check("+ Payload pollué : données légitimes reprises", val(w, "address.label") === "Adresse test");
  const r = w.UrbizenForm.normalize(pollue);
  check("+ Normalisation : aucune clé dangereuse conservée",
    !Object.prototype.hasOwnProperty.call(r, "__proto__") && !Object.prototype.hasOwnProperty.call(r, "constructor"));
}

/* Bouton « Modifier l'adresse » avec plusieurs instances */
{
  const w = await nouvelleFenetre(gabaritFormulaire("parcel-a") + gabaritFormulaire("parcel-b"));
  chargerForm(w);
  const demandes = [];
  w.document.addEventListener("urbizen:cadastre-edit-requested", (e) => demandes.push(e.detail));
  w.document.querySelectorAll(".uf-edit")[1].click();
  check("+ Modifier l'adresse : événement découplé émis", demandes.length === 1);
  check("+ Modifier l'adresse : cible la bonne clé et le bon formulaire",
    demandes[0].storageKey === "urbizen:parcel-b" && demandes[0].formId === "B");
  w.sessionStorage.setItem("urbizen:parcel-b", JSON.stringify(CONTRAT));
  check("+ Modifier l'adresse : ne vide ni les champs ni le stockage", w.sessionStorage.getItem("urbizen:parcel-b") !== null);
}

/* Le cadastre répond à la demande de correction */
{
  const w = await nouvelleFenetre('<div id="c1"></div><div id="c2"></div>');
  w.fetch = async () => ({ ok: true, json: async () => ({ results: [] }) });
  chargerCadastre(w);
  const i1 = w.UrbizenCadastre.mount("#c1", { storageKey: "parcel-a" });
  const i2 = w.UrbizenCadastre.mount("#c2", { storageKey: "parcel-b" });
  let focusA = false, focusB = false;
  i1.$input.addEventListener("focus", () => { focusA = true; });
  i2.$input.addEventListener("focus", () => { focusB = true; });
  w.document.dispatchEvent(new w.CustomEvent("urbizen:cadastre-edit-requested", { detail: { storageKey: "urbizen:parcel-b" } }));
  check("+ Cadastre : seule l'instance ciblée reprend le focus", focusB === true && focusA === false);
}

/* Formulaire inséré APRÈS le cadastre (montage tardif) */
{
  const w = await nouvelleFenetre('<div id="c"></div>');
  w.fetch = async () => ({ ok: true, json: async () => ({ results: [] }) });
  chargerCadastre(w);
  chargerForm(w);
  w.sessionStorage.setItem("urbizen:parcel", JSON.stringify(CONTRAT));
  const hote = w.document.createElement("div");
  hote.innerHTML = gabaritFormulaire();
  w.document.body.appendChild(hote);
  const montes = w.UrbizenForm.autoMount();
  check("+ Formulaire monté après coup : reprise immédiate", montes.length === 1 && val(w, "address.label") === CONTRAT.address.label);
  check("+ autoMount idempotent", w.UrbizenForm.autoMount().length === 0);
}

/* ===== I-5. Le HTML testé vient bien du rendu PHP ===== */
{
  check("I-5. Fixture issue de Renderer.php (3 instances)", INSTANCES.size === 3);
  check("I-5. Fixture sans aucune valeur préremplie", !/value="[^"]+"/.test(FIXTURE_HTML));
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  check("I-5. Le JS monte sur le HTML réel de Renderer.php",
    w.document.querySelectorAll('[data-uf-mounted="1"]').length === 1
    && w.document.querySelectorAll(".uf-input").length === 6
    && w.document.querySelectorAll(".uf-technical [data-from]").length === 8);
  w.document.dispatchEvent(new w.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));
  check("I-5. Reprise fonctionnelle sur le HTML réel", val(w, "address.label") === CONTRAT.address.label);
}

/* ===== I-1. Identifiants uniques dès le rendu serveur ===== */
{
  const ids = [...FIXTURE_HTML.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]);
  check("I-1. Aucun ID dupliqué dans le HTML serveur (3 formulaires)",
    ids.length > 0 && new Set(ids).size === ids.length);
  const w = await nouvelleFenetre(gabaritFormulaire("parcel-a") + gabaritFormulaire("parcel-b"));
  const avant = [...w.document.querySelectorAll(".uf-input")].map((i) => i.id);
  chargerForm(w);
  const apres = [...w.document.querySelectorAll(".uf-input")].map((i) => i.id);
  check("I-1. Le JS n'ajoute aucun second préfixe", JSON.stringify(avant) === JSON.stringify(apres));
  const forms = [...w.document.querySelectorAll(".urbizen-form")];
  check("I-1. Chaque label vise son propre champ", forms.every((f) => {
    const l = f.querySelector("label[for]");
    return f.contains(w.document.getElementById(l.getAttribute("for")));
  }));
  const errIds = [...w.document.querySelectorAll(".uf-error")].map((e) => e.id);
  check("I-1. Identifiants des messages d'erreur uniques",
    errIds.every(Boolean) && new Set(errIds).size === errIds.length);
}

/* ===== I-2. Accessibilité des erreurs ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire("parcel-a") + gabaritFormulaire("parcel-b"));
  chargerForm(w);
  const f2 = w.document.querySelectorAll(".urbizen-form")[1];
  f2.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  const champ = f2.querySelector(".uf-input[required]");
  const err = w.document.getElementById(champ.getAttribute("aria-describedby").split(/\s+/).find((id) => id.endsWith("-error")));
  check("I-2. Erreur affichée", !err.hidden && err.textContent.length > 0);
  check("I-2. Message relié au bon champ", f2.contains(err));
  check("I-2. aria-invalid posé", champ.getAttribute("aria-invalid") === "true");
  check("I-2. Annonce non intrusive (aria-live)", err.getAttribute("aria-live") === "polite");
  // description existante conservée : la note de surface reste référencée
  const surf = f2.querySelector('[data-from="parcel.surfaceM2"]');
  const desc = (surf.getAttribute("aria-describedby") || "").split(/\s+/);
  check("I-2. Description d'aide conservée", desc.some((id) => id.endsWith("-note")) && desc.some((id) => id.endsWith("-error")));
  // correction : l'état invalide disparaît
  champ.value = "12 rue des Lilas";
  champ.dispatchEvent(new w.Event("input", { bubbles: true }));
  check("I-2. aria-invalid retiré après correction", champ.getAttribute("aria-invalid") === null);
  check("I-2. Message vidé après correction", err.hidden && err.textContent === "");
  // aucune collision entre les deux formulaires
  const f1 = w.document.querySelectorAll(".urbizen-form")[0];
  check("I-2. Premier formulaire non affecté",
    f1.querySelector(".uf-input[required]").getAttribute("aria-invalid") === null);
}

/* ===== I-3. Validation réaliste, sans troncature silencieuse ===== */
{
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  const n = (o) => w.UrbizenForm.normalizeWithIssues(o);
  const base = (p) => ({ schemaVersion: "1.0", address: { label: "X", ...(p.address || {}) }, location: p.location || {}, parcel: { section: "KE", number: "0112", ...(p.parcel || {}) } });

  // valides aux limites
  check("I-3. cityCode 5 chiffres accepté", n(base({ address: { cityCode: "33063" } })).anomalies.length === 0);
  check("I-3. cityCode corse 2B033 accepté (cas réel Bastia)", n(base({ address: { cityCode: "2B033" } })).anomalies.length === 0);
  check("I-3. cityCode 2A004 accepté", n(base({ address: { cityCode: "2A004" } })).anomalies.length === 0);
  check("I-3. prefix 000 accepté", n(base({ parcel: { prefix: "000" } })).anomalies.length === 0);
  check("I-3. section 1 à 3 caractères acceptée",
    n(base({ parcel: { section: "A" } })).anomalies.length === 0
    && n(base({ parcel: { section: "KE" } })).anomalies.length === 0
    && n(base({ parcel: { section: "0AB" } })).anomalies.length === 0);
  check("I-3. numéro 1 à 4 chiffres accepté",
    n(base({ parcel: { number: "1" } })).anomalies.length === 0
    && n(base({ parcel: { number: "0112" } })).anomalies.length === 0);
  check("I-3. idu 14 caractères accepté", n(base({ parcel: { id: "33063000KE0112" } })).anomalies.length === 0);
  check("I-3. postcode 5 chiffres accepté", n(base({ address: { postcode: "33000" } })).anomalies.length === 0);
  check("I-3. champs vides sans anomalie", n(base({})).anomalies.length === 0);

  // invalides : signalés, jamais tronqués
  const mauvaisCode = n(base({ address: { cityCode: "pas-un-code" } }));
  check("I-3. cityCode invalide signalé", mauvaisCode.anomalies.some((a) => a.chemin === "address.cityCode"));
  check("I-3. cityCode invalide NON tronqué", mauvaisCode.contrat.address.cityCode === "PAS-UN-CODE");
  check("I-3. postcode invalide signalé", n(base({ address: { postcode: "AZERTY" } })).anomalies.length === 1);
  check("I-3. section trop longue signalée", n(base({ parcel: { section: "1234567890" } })).anomalies.length === 1);
  check("I-3. numéro non numérique signalé", n(base({ parcel: { number: "@@@@" } })).anomalies.length === 1);
  check("I-3. idu de mauvaise longueur signalé", n(base({ parcel: { id: "trop-court" } })).anomalies.length === 1);
  check("I-3. prefix à 2 chiffres signalé", n(base({ parcel: { prefix: "00" } })).anomalies.length === 1);

  // normalisation explicite : casse
  check("I-3. section normalisée en majuscules", n(base({ parcel: { section: "ke" } })).contrat.parcel.section === "KE");
  check("I-3. idu normalisé en majuscules", n(base({ parcel: { id: "33063000ke0112" } })).contrat.parcel.id === "33063000KE0112");

  // coordonnées et surface
  check("I-3. latitude hors bornes refusée et signalée", (() => { const r = n(base({ location: { latitude: 91, longitude: 0 } })); return r.contrat.location.latitude === null && r.anomalies.some((a) => a.chemin === "location.latitude"); })());
  check("I-3. longitude hors bornes refusée", n(base({ location: { latitude: 0, longitude: 181 } })).contrat.location.longitude === null);
  check("I-3. latitude aux bornes acceptée", n(base({ location: { latitude: -90, longitude: 180 } })).anomalies.length === 0);
  check("I-3. surface nulle refusée (strictement positive)", n(base({ parcel: { surfaceM2: 0 } })).contrat.parcel.surfaceM2 === null);
  check("I-3. surface 1 m² acceptée", n(base({ parcel: { surfaceM2: 1 } })).contrat.parcel.surfaceM2 === 1);
  check("I-3. surface au plafond acceptée", n(base({ parcel: { surfaceM2: 10000000 } })).contrat.parcel.surfaceM2 === 10000000);
  check("I-3. surface au-dessus du plafond refusée", n(base({ parcel: { surfaceM2: 10000001 } })).contrat.parcel.surfaceM2 === null);
  check("I-3. chaîne démesurée refusée", n(base({ address: { label: "X".repeat(1001) } })).contrat.address.label === "");

  // revalidation des données entrantes : message visible sur un champ visible
  const w2 = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w2);
  w2.document.dispatchEvent(new w2.CustomEvent("urbizen:parcel-confirmed", { detail: { ...CONTRAT, address: { ...CONTRAT.address, postcode: "AZERTY" } } }));
  const champCp = w2.document.querySelector('[data-from="address.postcode"]');
  check("I-3. Donnée cadastre invalide : champ marqué à la reprise", champCp.getAttribute("aria-invalid") === "true");
  check("I-3. Donnée cadastre invalide : valeur affichée telle quelle", champCp.value === "AZERTY");
  // technique invalide : signalé dans la zone d'état
  const w3 = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w3);
  w3.document.dispatchEvent(new w3.CustomEvent("urbizen:parcel-confirmed", { detail: { ...CONTRAT, parcel: { ...CONTRAT.parcel, id: "court" } } }));
  check("I-3. Donnée technique invalide : signalée dans la zone d'état",
    !w3.document.querySelector(".uf-notice").hidden && /Identifiant cadastral/.test(w3.document.querySelector(".uf-notice").textContent));
}

/* ===== A-1. Provenance honnête ===== */
{
  // saisie entièrement manuelle
  const w = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w);
  for (const [from, v] of [["address.label", "12 rue de la Paix"], ["address.postcode", "75002"], ["address.city", "Paris"], ["parcel.section", "AB"], ["parcel.number", "0142"]]) {
    w.document.querySelector(`[data-from="${from}"]`).value = v;
  }
  let d = null;
  w.document.addEventListener("urbizen:location-form-validated", (e) => { d = e.detail; });
  w.document.querySelector(".uf-form").dispatchEvent(new w.Event("submit", { bubbles: true, cancelable: true }));
  check("A-1. Saisie manuelle : source = urbizen-form", d && d.contract.source === "urbizen-form");
  check("A-1. Saisie manuelle : confirmedAt reste vide", d && d.contract.confirmedAt === "");

  // reprise cadastre puis correction manuelle
  const w2 = await nouvelleFenetre(gabaritFormulaire());
  chargerForm(w2);
  w2.document.dispatchEvent(new w2.CustomEvent("urbizen:parcel-confirmed", { detail: CONTRAT }));
  w2.document.querySelector('[data-from="address.city"]').value = "Bordeaux Métropole";
  let d2 = null;
  w2.document.addEventListener("urbizen:location-form-validated", (e) => { d2 = e.detail; });
  w2.document.querySelector(".uf-form").dispatchEvent(new w2.Event("submit", { bubbles: true, cancelable: true }));
  check("A-1. Cadastre puis correction : source reste urbizen-cadastre", d2 && d2.contract.source === "urbizen-cadastre");
  check("A-1. Cadastre : confirmedAt conservé", d2 && d2.contract.confirmedAt === CONTRAT.confirmedAt);
  check("A-1. Validation locale ne crée aucun nouvel horodatage", d2 && d2.contract.confirmedAt === CONTRAT.confirmedAt);

  // effacement : la provenance repart de zéro
  w2.document.querySelector(".uf-clear").click();
  w2.document.querySelector('[data-from="address.label"]').value = "1 rue de Rivoli";
  w2.document.querySelector('[data-from="address.postcode"]').value = "75001";
  w2.document.querySelector('[data-from="address.city"]').value = "Paris";
  w2.document.querySelector('[data-from="parcel.section"]').value = "AB";
  w2.document.querySelector('[data-from="parcel.number"]').value = "0001";
  let d3 = null;
  w2.document.addEventListener("urbizen:location-form-validated", (e) => { d3 = e.detail; });
  w2.document.querySelector(".uf-form").dispatchEvent(new w2.Event("submit", { bubbles: true, cancelable: true }));
  check("A-1. Après effacement : source repasse à urbizen-form", d3 && d3.contract.source === "urbizen-form");
}

console.log("\n" + (fail === 0 ? "TOUS LES CONTROLES PASSENT" : fail + " CONTROLE(S) EN ECHEC"));
process.exit(fail === 0 ? 0 : 1);
