/* Pont sécurisé entre la page WordPress et les iframes d'autorisation.
 *
 * Ce que ces bancs protègent : le nonce. Il est produit par la page parente et
 * remis au document par `postMessage`. Si cette extrémité acceptait un message
 * d'une autre origine, d'une autre fenêtre, ou un second message après coup, une
 * page tierce pourrait détourner l'URL de soumission — le formulaire posterait
 * ailleurs, avec un nonce valide.
 *
 * D'où l'insistance des contrôles sur les refus, plus que sur le chemin nominal.
 *
 * **Les deux parcours sont éprouvés par les mêmes contrôles**, dans une seule
 * boucle. Ce n'est pas une économie de lignes : deux bancs jumeaux auraient fini
 * par diverger, et le parcours le moins souvent relu aurait perdu ses garanties
 * sans que personne ne s'en aperçoive. Ici, un refus qui cesserait d'être opposé
 * au permis de construire tombe au même titre que pour la déclaration préalable.
 *
 * Exécuté sur le HTML réel des formulaires, sans réseau : `fetch` est doublé.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const THEME = resolve(ROOT, "wordpress/urbizen-child");

const MOTEUR = resolve(THEME, "assets/js/urbizen-form-tarifs.js");
const PIECES = resolve(THEME, "assets/js/urbizen-form-pieces.js");
const PONT = resolve(THEME, "assets/js/urbizen-form-bridge.js");
const PARENT = resolve(THEME, "assets/js/urbizen-form-page.js");

const ORIGINE = "https://urbizen.test";

/* Un parcours = un document, une route, une maquette. Tout le reste est commun,
 * et doit le rester : le pont est un module unique, paramétré par la
 * configuration que la page parente lui remet. */
const PARCOURS = [
  {
    nom: "DP",
    fichier: "dp-formulaire.html",
    config: {
      type: "urbizen_form_config",
      action: "urbizen_declaration_prealable",
      formType: "declaration_prealable",
      nonceField: "urbizen_conception_nonce",
      nonce: "abc123",
      submitUrl: "https://urbizen.test/wp-admin/admin-post.php",
    },
  },
  {
    nom: "PC",
    fichier: "pc-formulaire.html",
    config: {
      type: "urbizen_form_config",
      action: "urbizen_permis_construire",
      formType: "permis_construire",
      // Le champ de nonce est commun aux routes ; l'action du nonce, elle, ne
      // l'est pas — c'est elle qui empêche un nonce de DP d'autoriser un PC.
      nonceField: "urbizen_conception_nonce",
      nonce: "def456",
      submitUrl: "https://urbizen.test/wp-admin/admin-post.php",
    },
  },
];

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(72), cond ? "OK" : "ECHEC");
};
const titre = (t) => console.log(`\n── ${t}`);

/* ------------------------------------------------------------------ *
 *  Montage : le document, sa fenêtre parente doublée, et fetch doublé
 * ------------------------------------------------------------------ */

async function monter(P, { reponse = null, statut = 200, jsonInvalide = false, reseauKo = false, delai = 0 } = {}) {
  const html = readFileSync(resolve(THEME, "assets/forms/" + P.fichier), "utf8").replace(
    /<script src="[^"]*urbizen-form-(tarifs|pieces|bridge)\.js"><\/script>/g,
    ""
  );

  const virtualConsole = new VirtualConsole();
  virtualConsole.on("jsdomError", () => {});

  const dom = new JSDOM(html, {
    url: ORIGINE + "/wp-content/themes/urbizen-child/assets/forms/" + P.fichier,
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

for (const P of PARCOURS) {

  /* ================================================================== *
   *  1. Le document demande, le bouton attend
   * ================================================================== */

  titre(P.nom + " · 1 — Initialisation demandée, envoi verrouillé");

  {
    const ctx = await monter(P);

    check(P.nom + " · le document envoie « urbizen_form_ready »",
      1 === ctx.versParent.length && "urbizen_form_ready" === ctx.versParent[0].donnees.type);
    check(P.nom + " · il ne parle qu'à son origine, jamais à « * »",
      ORIGINE === ctx.versParent[0].cible && "*" !== ctx.versParent[0].cible);
    check(P.nom + " · le bouton d'envoi part désactivé", ctx.bouton.disabled);
    check(P.nom + " · l'état est annoncé aux technologies d'assistance", "true" === ctx.bouton.getAttribute("aria-disabled"));
    check(P.nom + " · la zone d'erreur est une région vivante", "alert" === ctx.erreur.getAttribute("role"));
    check(P.nom + " · le pont n'est pas prêt", !ctx.pont.pret());

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  2. Une configuration valide déverrouille
   * ================================================================== */

  titre(P.nom + " · 2 — Une configuration valide, et une seule");

  {
    const ctx = await monter(P);
    recevoir(ctx, { data: P.config });

    check(P.nom + " · le pont est prêt", ctx.pont.pret());
    check(P.nom + " · le bouton devient utilisable", !ctx.bouton.disabled);
    check(P.nom + " · l'attribut d'état est retiré", null === ctx.bouton.getAttribute("aria-disabled"));

    // Verrouillage : une seconde configuration, même valide, est ignorée.
    recevoir(ctx, { data: { ...P.config, submitUrl: "https://pirate.test/collecte" } });

    check(P.nom + " · une seconde configuration est ignorée", "https://urbizen.test/wp-admin/admin-post.php" === ctx.pont.configuration.submitUrl);

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  3. Ce que le document refuse
   * ================================================================== */

  titre(P.nom + " · 3 — Refus");

  const refus = [
    ["une autre origine", { data: P.config, origin: "https://pirate.test" }],
    ["une autre fenêtre", { data: P.config, source: { postMessage() {} } }],
    ["un type inconnu", { data: { ...P.config, type: "autre_chose" } }],
    ["un message sans type", { data: { action: "x" } }],
    ["une configuration sans nonce", { data: { ...P.config, nonce: "" } }],
    ["une configuration sans URL", { data: { ...P.config, submitUrl: undefined } }],
    ["une configuration sans action", { data: { ...P.config, action: "" } }],
    ["un message qui n'est pas un objet", { data: "urbizen_form_config" }],
    ["un message nul", { data: null }],
  ];

  for (const [label, message] of refus) {
    const ctx = await monter(P);
    recevoir(ctx, message);

    check(`${P.nom} · ${label} → refusée`, !ctx.pont.pret() && ctx.bouton.disabled);

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  4. Délai dépassé
   * ================================================================== */

  titre(P.nom + " · 4 — Aucune configuration reçue");

  {
    // Délai volontairement court : on laisse réellement le minuteur expirer,
    // sans jamais répondre au message « ready ».
    const ctx = await monter(P, { delai: 30 });

    await new Promise((r) => setTimeout(r, 80));

    check(P.nom + " · le bouton reste désactivé", ctx.bouton.disabled);
    check(P.nom + " · le message d'échec est celui attendu",
      "Le formulaire n’a pas pu être initialisé. Veuillez actualiser la page ou nous contacter." === ctx.erreur.textContent);
    check(P.nom + " · le message ne contient rien de technique",
      !/fetch|nonce|origin|postMessage|undefined/i.test(ctx.erreur.textContent));

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  5. La requête composée
   * ================================================================== */

  titre(P.nom + " · 5 — Ce que la requête transporte");

  {
    const ctx = await monter(P, { reponse: { success: true, reference: "URB-2026-0001", pricing: { base: 549, total: 549, options: [] }, project: { id: "extension", label: "Extension" } }, statut: 201 });
    recevoir(ctx, { data: P.config });

    // Une demande minimale.
    const nature = [...ctx.form.querySelectorAll('input[name="nature"]')].find((i) => "extension" === i.value);
    nature.checked = true;
    ctx.form.querySelector("#a1").checked = true;
    ctx.form.querySelector("#a2").checked = true;

    ctx.pont.envoyer();
    await new Promise((r) => setTimeout(r, 10));

    check(P.nom + " · une requête est partie", 1 === ctx.requetes.length);

    const { url, options } = ctx.requetes[0];

    check(P.nom + " · vers l'URL reçue du parent", P.config.submitUrl === url);
    check(P.nom + " · en POST", "POST" === options.method);
    check(P.nom + " · le corps est un FormData", options.body instanceof ctx.window.FormData);
    check(P.nom + " · aucun Content-Type imposé", undefined === options.headers["Content-Type"]);
    check(P.nom + " · l'en-tête Accept demande du JSON", "application/json" === options.headers.Accept);
    check(P.nom + " · les témoins de session accompagnent la requête", "same-origin" === options.credentials);

    const fd = options.body;

    check(P.nom + " · l'action de la route est transmise", P.config.action === fd.get("action"));
    check(P.nom + " · le type de formulaire aussi", P.config.formType === fd.get("form_type"));
    check(P.nom + " · le nonce est dans le champ attendu", P.config.nonce === fd.get(P.config.nonceField));
    check(P.nom + " · la nature choisie est transmise", "extension" === fd.get("nature"));

    // Aucun montant, sous aucune forme.
    for (const interdit of ["total", "prix", "montant", "pricing", "estimation", "reference"]) {
      check(`${P.nom} · aucun « ${interdit} » n'est envoyé`, null === fd.get(interdit));
    }

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  6. Succès réel
   * ================================================================== */

  titre(P.nom + " · 6 — L'écran final n'apparaît que sur un succès réel");

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

    const ctx = await monter(P, { reponse, statut: 201 });
    recevoir(ctx, { data: P.config });

    ctx.form.querySelector("#a1").checked = true;
    ctx.form.querySelector("#a2").checked = true;
    ctx.pont.envoyer();
    await new Promise((r) => setTimeout(r, 10));

    check(P.nom + " · l'écran final a reçu la réponse serveur", undefined !== ctx.window.__succes);
    check(P.nom + " · la référence rendue est celle du serveur", "URB-2026-0042" === ctx.window.__succes.reference);

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  7. Aucun faux succès
   * ================================================================== */

  titre(P.nom + " · 7 — Aucune erreur ne devient un succès");

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
    const ctx = await monter(P, options);
    recevoir(ctx, { data: P.config });

    ctx.form.querySelector("#a1").checked = true;
    ctx.form.querySelector("#a2").checked = true;
    ctx.pont.envoyer();
    await new Promise((r) => setTimeout(r, 10));

    check(`${P.nom} · ${label} → aucun écran final`, undefined === ctx.window.__succes);
    check(`${P.nom} · ${label} → le bouton revient`, !ctx.bouton.disabled);
    check(`${P.nom} · ${label} → un message est affiché`, "" !== ctx.erreur.textContent);
    check(`${P.nom} · ${label} → rien de technique à l'écran`,
      !/HTTP \d|fetch|json|undefined|Error|\.php|Urbizen\\/i.test(ctx.erreur.textContent));

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  8. Double soumission
   * ================================================================== */

  titre(P.nom + " · 8 — Une seule requête par envoi");

  {
    const ctx = await monter(P, { reponse: { success: true, reference: "URB-1" }, statut: 201 });
    recevoir(ctx, { data: P.config });

    ctx.form.querySelector("#a1").checked = true;
    ctx.form.querySelector("#a2").checked = true;

    // Deux soumissions coup sur coup, avant toute réponse.
    // Deux appels coup sur coup, avant toute réponse.
    ctx.pont.envoyer();
    ctx.pont.envoyer();

    check(P.nom + " · le second envoi est ignoré", 1 === ctx.requetes.length);

    await new Promise((r) => setTimeout(r, 10));
    ctx.dom.window.close();
  }

  {
    const ctx = await monter(P, { reponse: { success: true, reference: "URB-2" }, statut: 201 });

    // Sans configuration, aucune requête ne part, même en soumettant.
    ctx.form.querySelector("#a1").checked = true;
    ctx.form.querySelector("#a2").checked = true;
    ctx.pont.envoyer();
    await new Promise((r) => setTimeout(r, 10));

    check(P.nom + " · aucun envoi tant que le pont n'est pas initialisé", 0 === ctx.requetes.length);
    check(P.nom + " · et aucun écran final", undefined === ctx.window.__succes);

    ctx.dom.window.close();
  }

  /* ================================================================== *
   *  9. Le mode aperçu a disparu
   * ================================================================== */

  titre(P.nom + " · 9 — Plus aucun repli d'aperçu");

  for (const chemin of [resolve(THEME, "assets/forms/" + P.fichier), resolve(ROOT, "frontend/formulaires/" + P.fichier)]) {
    const source = readFileSync(chemin, "utf8");
    const nom = chemin.includes("urbizen-child") ? "thème" : "maquette";

    check(`${P.nom} · ${nom} · ENDPOINT vide a disparu`, !source.includes('var ENDPOINT = ""'));
    check(`${P.nom} · ${nom} · la mention d'aperçu a disparu`, !source.includes("aucune donnée n’a été transmise"));
    check(`${P.nom} · ${nom} · le pont est chargé`, source.includes("urbizen-form-bridge.js"));
    check(`${P.nom} · ${nom} · la référence réelle a sa place`, source.includes('id="dp-reference"'));
  }

  /* ================================================================== *
   *  10. Le parent ne répond qu'au bon cadre
   * ================================================================== */

  titre(P.nom + " · 10 — Côté parent : vérification de la source");

  {
    const dom = new JSDOM(
      `<!doctype html><body><iframe data-urbizen-form-frame src="/wp-content/themes/urbizen-child/assets/forms/${P.fichier}"></iframe></body>`,
      { url: ORIGINE + "/formulaire/", runScripts: "dangerously", pretendToBeVisual: true }
    );

    const { window } = dom;
    await new Promise((r) => ("complete" === window.document.readyState ? r() : window.addEventListener("load", r)));

    window.urbizenFormConfig = {
      ...P.config,
      origin: ORIGINE,
      frameSource: "/wp-content/themes/urbizen-child/assets/forms/" + P.fichier,
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
    check(P.nom + " · une demande d'une autre origine reste sans réponse", 0 === recu.length);

    envoyer({ source: { postMessage() {} } });
    check(P.nom + " · une demande d'une fenêtre inconnue reste sans réponse", 0 === recu.length);

    envoyer({ data: { type: "autre" } });
    check(P.nom + " · un type inattendu reste sans réponse", 0 === recu.length);

    envoyer({});
    check(P.nom + " · le bon cadre reçoit la configuration", 1 === recu.length && "urbizen_form_config" === recu[0].d.type);
    check(P.nom + " · elle porte le nonce", P.config.nonce === recu[0].d.nonce);
    check(P.nom + " · elle est adressée à l'origine exacte, jamais à « * »", ORIGINE === recu[0].c);

    dom.window.close();
  }


}

/* ================================================================== *
 *  11. Le tarif sur étude, propre au permis de construire
 * ================================================================== */

titre("PC · 11 — Un dossier sur étude n'affiche aucun total chiffré");

{
  const PC = PARCOURS[1];

  // Ce que le serveur rend pour un « Autre » : socle et total nuls, statut
  // explicite, suppléments connus et chiffrés.
  const reponse = {
    success: true,
    reference: "URB-2026-0099",
    status: "received",
    pricing: {
      base: null,
      total: null,
      status: "sur_etude",
      options: [
        { label: "Extension", amount: 100 },
        { label: "Secteur Bâtiments de France", amount: 80 },
      ],
    },
    project: { id: "autre", label: "Autre" },
    additional_projects: [{ id: "extension", label: "Extension" }],
    deferred_documents: [],
    deferred_cadastral_information: false,
  };

  const ctx = await monter(PC, { reponse, statut: 201 });
  recevoir(ctx, { data: PC.config });

  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;
  ctx.pont.envoyer();
  await new Promise((r) => setTimeout(r, 10));

  check("l'écran final s'affiche", undefined !== ctx.window.__succes);

  const cible = ctx.window.document.querySelector("[data-tarifs-recap-final]");
  ctx.window.UrbizenPont.rendreConfirmation(cible, reponse);

  const texte = cible.textContent;

  check("le total est annoncé « Tarif sur étude »", texte.includes("Tarif sur étude"));
  check("les suppléments restent détaillés",
    texte.includes("Secteur Bâtiments de France") && texte.includes("80 €"));
  check("le projet supplémentaire est chiffré", texte.includes("100 €"));
  // Un socle nul ne doit pas se rendre « 0 € » : ce serait annoncer la gratuité.
  check("aucun montant nul n'est affiché", !/(^|[^\d])0 €/.test(texte));
  check("aucun total général n'est fabriqué depuis les suppléments", !texte.includes("180 €"));
  check("la mention imposée est présente",
    texte.includes("Estimation indicative. Le tarif définitif sera confirmé par Urbizen après vérification de votre projet, avant toute commande."));

  ctx.dom.window.close();
}

console.log("");
if (fail) {
  console.log(`[31m${fail} CONTROLE(S) EN ECHEC[0m`);
  process.exit(1);
}
console.log("[32mTOUS LES CONTROLES PASSENT[0m");
