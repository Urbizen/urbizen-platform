/* Pont sécurisé entre la page WordPress et l'iframe de déclaration préalable.
 *
 * Ce que ces bancs protègent : le nonce. Il est produit par la page parente et
 * remis au document par `postMessage`. Si cette extrémité acceptait un message
 * d'une autre origine, d'une autre fenêtre, ou un second message après coup, une
 * page tierce pourrait détourner l'URL de soumission — le formulaire posterait
 * ailleurs, avec un nonce valide.
 *
 * D'où l'insistance des contrôles sur les refus, plus que sur le chemin nominal.
 *
 * Exécuté sur le HTML réel du formulaire, sans réseau : `fetch` est doublé.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const THEME = resolve(ROOT, "wordpress/urbizen-child");

const FORMULAIRE = resolve(THEME, "assets/forms/dp-formulaire.html");
const MOTEUR = resolve(THEME, "assets/js/urbizen-form-tarifs.js");
const PIECES = resolve(THEME, "assets/js/urbizen-form-pieces.js");
const PONT = resolve(THEME, "assets/js/urbizen-form-bridge.js");
const PARENT = resolve(THEME, "assets/js/urbizen-form-page.js");

const ORIGINE = "https://urbizen.test";

const CONFIG = {
  type: "urbizen_form_config",
  action: "urbizen_declaration_prealable",
  formType: "declaration_prealable",
  nonceField: "urbizen_conception_nonce",
  nonce: "abc123",
  submitUrl: "https://urbizen.test/wp-admin/admin-post.php",
};

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(72), cond ? "OK" : "ECHEC");
};
const titre = (t) => console.log(`\n── ${t}`);

/* ------------------------------------------------------------------ *
 *  Montage : le document, sa fenêtre parente doublée, et fetch doublé
 * ------------------------------------------------------------------ */

async function monter({ reponse = null, statut = 200, jsonInvalide = false, reseauKo = false, delai = 0 } = {}) {
  const html = readFileSync(FORMULAIRE, "utf8").replace(
    /<script src="[^"]*urbizen-form-(tarifs|pieces|bridge)\.js"><\/script>/g,
    ""
  );

  const virtualConsole = new VirtualConsole();
  virtualConsole.on("jsdomError", () => {});

  const dom = new JSDOM(html, {
    url: ORIGINE + "/wp-content/themes/urbizen-child/assets/forms/dp-formulaire.html",
    runScripts: "dangerously",
    pretendToBeVisual: true,
    virtualConsole,
  });

  const { window } = dom;
  await new Promise((r) =>
    "complete" === window.document.readyState ? r() : window.addEventListener("load", r)
  );

  // Fenêtre parente doublée : on capte ce que le document lui envoie.
  const versParent = [];
  const faux = {
    postMessage(donnees, cible) {
      versParent.push({ donnees, cible });
    },
  };
  Object.defineProperty(window, "parent", { value: faux, configurable: true });

  // `fetch` doublé : aucun réseau, et on observe la requête composée.
  const requetes = [];
  window.fetch = (url, options) => {
    requetes.push({ url, options });

    if (reseauKo) return Promise.reject(new Error("réseau"));

    return Promise.resolve({
      ok: statut >= 200 && statut < 300,
      status: statut,
      json: () => (jsonInvalide ? Promise.reject(new Error("json")) : Promise.resolve(reponse)),
    });
  };

  window.eval(readFileSync(MOTEUR, "utf8"));
  window.eval(readFileSync(PIECES, "utf8"));
  window.eval(readFileSync(PONT, "utf8"));

  const form = window.document.getElementById("dp-form");
  const bouton = window.document.getElementById("dp-send");
  const erreur = window.document.getElementById("dp-final-err");

  const pont = window.UrbizenPont.init({
    form,
    bouton,
    erreur,
    delai: delai || undefined,
    serialiser: () => {},
    afficherSucces: (donnees) => {
      window.__succes = donnees;
    },
  });

  return { dom, window, form, bouton, erreur, pont, versParent, requetes };
}

/** Simule un message reçu par le document. */
function recevoir(ctx, { data, origin = ORIGINE, source = null }) {
  const evenement = new ctx.window.MessageEvent("message", { data, origin });

  Object.defineProperty(evenement, "source", {
    value: undefined === source ? null : source || ctx.window.parent,
    configurable: true,
  });

  ctx.window.dispatchEvent(evenement);
}

/* ================================================================== *
 *  1. Le document demande, le bouton attend
 * ================================================================== */

titre("1 — Initialisation demandée, envoi verrouillé");

{
  const ctx = await monter();

  check("le document envoie « urbizen_form_ready »",
    1 === ctx.versParent.length && "urbizen_form_ready" === ctx.versParent[0].donnees.type);
  check("il ne parle qu'à son origine, jamais à « * »",
    ORIGINE === ctx.versParent[0].cible && "*" !== ctx.versParent[0].cible);
  check("le bouton d'envoi part désactivé", ctx.bouton.disabled);
  check("l'état est annoncé aux technologies d'assistance", "true" === ctx.bouton.getAttribute("aria-disabled"));
  check("la zone d'erreur est une région vivante", "alert" === ctx.erreur.getAttribute("role"));
  check("le pont n'est pas prêt", !ctx.pont.pret());

  ctx.dom.window.close();
}

/* ================================================================== *
 *  2. Une configuration valide déverrouille
 * ================================================================== */

titre("2 — Une configuration valide, et une seule");

{
  const ctx = await monter();
  recevoir(ctx, { data: CONFIG });

  check("le pont est prêt", ctx.pont.pret());
  check("le bouton devient utilisable", !ctx.bouton.disabled);
  check("l'attribut d'état est retiré", null === ctx.bouton.getAttribute("aria-disabled"));

  // Verrouillage : une seconde configuration, même valide, est ignorée.
  recevoir(ctx, { data: { ...CONFIG, submitUrl: "https://pirate.test/collecte" } });

  check("une seconde configuration est ignorée", "https://urbizen.test/wp-admin/admin-post.php" === ctx.pont.configuration.submitUrl);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  3. Ce que le document refuse
 * ================================================================== */

titre("3 — Refus");

const refus = [
  ["une autre origine", { data: CONFIG, origin: "https://pirate.test" }],
  ["une autre fenêtre", { data: CONFIG, source: { postMessage() {} } }],
  ["un type inconnu", { data: { ...CONFIG, type: "autre_chose" } }],
  ["un message sans type", { data: { action: "x" } }],
  ["une configuration sans nonce", { data: { ...CONFIG, nonce: "" } }],
  ["une configuration sans URL", { data: { ...CONFIG, submitUrl: undefined } }],
  ["une configuration sans action", { data: { ...CONFIG, action: "" } }],
  ["un message qui n'est pas un objet", { data: "urbizen_form_config" }],
  ["un message nul", { data: null }],
];

for (const [label, message] of refus) {
  const ctx = await monter();
  recevoir(ctx, message);

  check(`${label} → refusée`, !ctx.pont.pret() && ctx.bouton.disabled);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  4. Délai dépassé
 * ================================================================== */

titre("4 — Aucune configuration reçue");

{
  // Délai volontairement court : on laisse réellement le minuteur expirer,
  // sans jamais répondre au message « ready ».
  const ctx = await monter({ delai: 30 });

  await new Promise((r) => setTimeout(r, 80));

  check("le bouton reste désactivé", ctx.bouton.disabled);
  check("le message d'échec est celui attendu",
    "Le formulaire n’a pas pu être initialisé. Veuillez actualiser la page ou nous contacter." === ctx.erreur.textContent);
  check("le message ne contient rien de technique",
    !/fetch|nonce|origin|postMessage|undefined/i.test(ctx.erreur.textContent));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  5. La requête composée
 * ================================================================== */

titre("5 — Ce que la requête transporte");

{
  const ctx = await monter({ reponse: { success: true, reference: "URB-2026-0001", pricing: { base: 549, total: 549, options: [] }, project: { id: "extension", label: "Extension" } }, statut: 201 });
  recevoir(ctx, { data: CONFIG });

  // Une demande minimale.
  const nature = [...ctx.form.querySelectorAll('input[name="nature"]')].find((i) => "extension" === i.value);
  nature.checked = true;
  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;

  ctx.pont.envoyer();
  await new Promise((r) => setTimeout(r, 10));

  check("une requête est partie", 1 === ctx.requetes.length);

  const { url, options } = ctx.requetes[0];

  check("vers l'URL reçue du parent", CONFIG.submitUrl === url);
  check("en POST", "POST" === options.method);
  check("le corps est un FormData", options.body instanceof ctx.window.FormData);
  check("aucun Content-Type imposé", undefined === options.headers["Content-Type"]);
  check("l'en-tête Accept demande du JSON", "application/json" === options.headers.Accept);
  check("les témoins de session accompagnent la requête", "same-origin" === options.credentials);

  const fd = options.body;

  check("l'action de la route est transmise", CONFIG.action === fd.get("action"));
  check("le type de formulaire aussi", CONFIG.formType === fd.get("form_type"));
  check("le nonce est dans le champ attendu", CONFIG.nonce === fd.get(CONFIG.nonceField));
  check("la nature choisie est transmise", "extension" === fd.get("nature"));

  // Aucun montant, sous aucune forme.
  for (const interdit of ["total", "prix", "montant", "pricing", "estimation", "reference"]) {
    check(`aucun « ${interdit} » n'est envoyé`, null === fd.get(interdit));
  }

  ctx.dom.window.close();
}

/* ================================================================== *
 *  6. Succès réel
 * ================================================================== */

titre("6 — L'écran final n'apparaît que sur un succès réel");

{
  const reponse = {
    success: true,
    reference: "URB-2026-0042",
    status: "received",
    pricing: { base: 549, total: 729, options: [{ label: "Secteur Bâtiments de France", amount: 80 }], status: "estime" },
    project: { id: "extension", label: "Extension" },
    additional_projects: [{ id: "piscine", label: "Piscine" }],
    deferred_documents: [{ id: "facades", label: "Photos des façades concernées" }],
    deferred_cadastral_information: true,
  };

  const ctx = await monter({ reponse, statut: 201 });
  recevoir(ctx, { data: CONFIG });

  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;
  ctx.pont.envoyer();
  await new Promise((r) => setTimeout(r, 10));

  check("l'écran final a reçu la réponse serveur", undefined !== ctx.window.__succes);
  check("la référence rendue est celle du serveur", "URB-2026-0042" === ctx.window.__succes.reference);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  7. Aucun faux succès
 * ================================================================== */

titre("7 — Aucune erreur ne devient un succès");

const echecs = [
  ["une 422 avec message public", { reponse: { success: false, code: "validation", message: "Certaines informations n’ont pas pu être validées.", fields: ["email"] }, statut: 422 }],
  ["une 500 générique", { reponse: { success: false, code: "technical", message: "Votre demande n’a pas pu être envoyée." }, statut: 500 }],
  ["une 429", { reponse: { success: false, code: "rate_limited", message: "Vous avez envoyé plusieurs demandes coup sur coup." }, statut: 429 }],
  ["un succès sans référence", { reponse: { success: true, reference: "" }, statut: 201 }],
  ["une réponse non JSON", { jsonInvalide: true, statut: 200 }],
  ["une redirection HTML imprévue", { jsonInvalide: true, statut: 302 }],
  ["une erreur réseau", { reseauKo: true }],
];

for (const [label, options] of echecs) {
  const ctx = await monter(options);
  recevoir(ctx, { data: CONFIG });

  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;
  ctx.pont.envoyer();
  await new Promise((r) => setTimeout(r, 10));

  check(`${label} → aucun écran final`, undefined === ctx.window.__succes);
  check(`${label} → le bouton revient`, !ctx.bouton.disabled);
  check(`${label} → un message est affiché`, "" !== ctx.erreur.textContent);
  check(`${label} → rien de technique à l'écran`,
    !/HTTP \d|fetch|json|undefined|Error|\.php|Urbizen\\/i.test(ctx.erreur.textContent));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  8. Double soumission
 * ================================================================== */

titre("8 — Une seule requête par envoi");

{
  const ctx = await monter({ reponse: { success: true, reference: "URB-1" }, statut: 201 });
  recevoir(ctx, { data: CONFIG });

  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;

  // Deux soumissions coup sur coup, avant toute réponse.
  // Deux appels coup sur coup, avant toute réponse.
  ctx.pont.envoyer();
  ctx.pont.envoyer();

  check("le second envoi est ignoré", 1 === ctx.requetes.length);

  await new Promise((r) => setTimeout(r, 10));
  ctx.dom.window.close();
}

{
  const ctx = await monter({ reponse: { success: true, reference: "URB-2" }, statut: 201 });

  // Sans configuration, aucune requête ne part, même en soumettant.
  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;
  ctx.pont.envoyer();
  await new Promise((r) => setTimeout(r, 10));

  check("aucun envoi tant que le pont n'est pas initialisé", 0 === ctx.requetes.length);
  check("et aucun écran final", undefined === ctx.window.__succes);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  9. Le mode aperçu a disparu
 * ================================================================== */

titre("9 — Plus aucun repli d'aperçu");

for (const chemin of [FORMULAIRE, resolve(ROOT, "frontend/formulaires/dp-formulaire.html")]) {
  const source = readFileSync(chemin, "utf8");
  const nom = chemin.includes("urbizen-child") ? "thème" : "maquette";

  check(`${nom} · ENDPOINT vide a disparu`, !source.includes('var ENDPOINT = ""'));
  check(`${nom} · la mention d'aperçu a disparu`, !source.includes("aucune donnée n’a été transmise"));
  check(`${nom} · le pont est chargé`, source.includes("urbizen-form-bridge.js"));
  check(`${nom} · la référence réelle a sa place`, source.includes('id="dp-reference"'));
}

/* ================================================================== *
 *  10. Le parent ne répond qu'au bon cadre
 * ================================================================== */

titre("10 — Côté parent : vérification de la source");

{
  const dom = new JSDOM(
    `<!doctype html><body><iframe data-urbizen-form-frame src="/wp-content/themes/urbizen-child/assets/forms/dp-formulaire.html"></iframe></body>`,
    { url: ORIGINE + "/declaration-prealable/", runScripts: "dangerously", pretendToBeVisual: true }
  );

  const { window } = dom;
  await new Promise((r) => ("complete" === window.document.readyState ? r() : window.addEventListener("load", r)));

  window.urbizenFormConfig = {
    ...CONFIG,
    origin: ORIGINE,
    frameSource: "/wp-content/themes/urbizen-child/assets/forms/dp-formulaire.html",
  };

  window.eval(readFileSync(PARENT, "utf8"));

  const cadre = window.document.querySelector("iframe");
  const recu = [];

  const fausseFenetre = { postMessage: (d, c) => recu.push({ d, c }) };
  Object.defineProperty(cadre, "contentWindow", { value: fausseFenetre, configurable: true });

  function envoyer({ origin = ORIGINE, source = fausseFenetre, data = { type: "urbizen_form_ready" } }) {
    const e = new window.MessageEvent("message", { data, origin });
    Object.defineProperty(e, "source", { value: source, configurable: true });
    window.dispatchEvent(e);
  }

  envoyer({ origin: "https://pirate.test" });
  check("une demande d'une autre origine reste sans réponse", 0 === recu.length);

  envoyer({ source: { postMessage() {} } });
  check("une demande d'une fenêtre inconnue reste sans réponse", 0 === recu.length);

  envoyer({ data: { type: "autre" } });
  check("un type inattendu reste sans réponse", 0 === recu.length);

  envoyer({});
  check("le bon cadre reçoit la configuration", 1 === recu.length && "urbizen_form_config" === recu[0].d.type);
  check("elle porte le nonce", CONFIG.nonce === recu[0].d.nonce);
  check("elle est adressée à l'origine exacte, jamais à « * »", ORIGINE === recu[0].c);

  dom.window.close();
}

console.log("");
if (fail) {
  console.log(`[31m${fail} CONTROLE(S) EN ECHEC[0m`);
  process.exit(1);
}
console.log("[32mTOUS LES CONTROLES PASSENT[0m");
