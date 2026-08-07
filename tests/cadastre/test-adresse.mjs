/* Deux adresses assistées sur une même page, et la case qui reporte l'une sur
 * l'autre.
 *
 * Le module d'adresse écrivait autrefois dans des champs nommés en dur : il ne
 * pouvait servir qu'un bloc par page. Il ne connaît plus que des **rôles**, et
 * c'est le document qui dit quel nom canonique porte chaque rôle. Ce banc
 * éprouve ce que cette indirection promet, et que rien d'autre ne vérifie :
 *
 * 1. **deux instances vivent côte à côte sans se voir** — état, requête, jeton
 *    d'obsolescence et identifiants ARIA séparés ;
 * 2. **la case « même adresse » retire le terrain du formulaire** plutôt que de
 *    le recopier — recopier produirait une seconde adresse, vraie au moment du
 *    clic et fausse dès la correction suivante ;
 * 3. **rien de masqué ne reste soumettable** : un contrôle caché mais actif
 *    laisserait partir une adresse que la personne a remplacée.
 *
 * Le service n'est jamais appelé : les charges rejouées ici sont celles que la
 * Géoplateforme rend réellement, capturées telles quelles.
 *
 * Exécuté sur le HTML réel du document DP.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const THEME = resolve(ROOT, "wordpress/urbizen-child");

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(74), cond ? "OK" : "ECHEC");
};
const titre = (t) => console.log(`\n── ${t}`);

/* Charges réelles du service, capturées sur data.geopf.fr. Les recomposer à la
 * main aurait fait éprouver une forme que le service ne rend pas. */
const COMPLETION = {
  status: "OK",
  results: [
    { city: "Paris", kind: "housenumber", zipcode: "75004", street: "Rue de Rivoli", fulltext: "12 Rue de Rivoli, 75004 Paris" },
    { city: "Paris", kind: "street", zipcode: "75001", street: "Rue de Rivoli", fulltext: "Rue de Rivoli, 75001 Paris" },
  ],
};

const SEARCH = {
  type: "FeatureCollection",
  features: [
    {
      geometry: { coordinates: [2.35995, 48.855602] },
      properties: { label: "12 Rue de Rivoli 75004 Paris", housenumber: "12", postcode: "75004", citycode: "75104", city: "Paris", street: "Rue de Rivoli" },
    },
  ],
};

const ANTI_REBOND = 260;
const attendre = (ms) => new Promise((r) => setTimeout(r, ms));

/** Monte le document DP et le module d'adresse, service simulé. */
async function monter() {
  const html = readFileSync(resolve(THEME, "assets/forms/dp-formulaire.html"), "utf8").replace(
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

  // Le service est simulé au plus près de ce que `lireJson` consomme : `ok` et
  // `json()`. Rien d'autre n'est employé, et en simuler davantage ferait
  // éprouver une mécanique que le module n'a pas.
  w.__appels = [];
  w.fetch = (url) => {
    const u = String(url);
    w.__appels.push(u);

    const rendre = (d) => Promise.resolve({ ok: true, json: () => Promise.resolve(d) });

    if (u.includes("/geocodage/completion")) return rendre(COMPLETION);
    if (u.includes("/geocodage/search")) return rendre(SEARCH);

    return Promise.reject(new Error("hors service"));
  };

  w.eval(readFileSync(resolve(THEME, "assets/js/urbizen-form-adresse.js"), "utf8"));

  const d = w.document;
  const form = d.getElementById("dp-form");

  w.UrbizenAdresse.surveiller(form);

  const bloc = (role) => d.querySelector(`[data-adresse="${role}"]`);
  const champ = (role, r) => bloc(role).querySelector(`[data-adresse-champ="${r}"]`);
  const val = (role, r) => {
    const e = champ(role, r);
    return e ? e.value : null;
  };

  /** Tape dans la recherche d'un bloc et laisse passer l'anti-rebond. */
  const chercher = async (role, texte) => {
    const e = bloc(role).querySelector("[data-adresse-recherche]");
    e.value = texte;
    e.dispatchEvent(new w.Event("input", { bubbles: true }));
    await attendre(ANTI_REBOND + 120);
  };

  const propositions = (role) => [...bloc(role).querySelectorAll("[data-adresse-propositions] li")];

  /** Retient la première proposition et laisse `/search` répondre. */
  const retenir = async (role) => {
    propositions(role)[0].dispatchEvent(new w.Event("click", { bubbles: true }));
    await attendre(60);
  };

  const basculerManuel = (role, actif) => {
    const c = bloc(role).querySelector("[data-adresse-manuel]");
    c.checked = actif;
    c.dispatchEvent(new w.Event("change", { bubbles: true }));
  };

  const saisir = (role, r, valeur) => {
    const e = champ(role, r);
    e.value = valeur;
    e.dispatchEvent(new w.Event("input", { bubbles: true }));
  };

  const report = d.querySelector("[data-adresse-report]");
  const caseReport = report.querySelector("[data-adresse-report-case]");

  const cocher = (actif) => {
    caseReport.checked = actif;
    caseReport.dispatchEvent(new w.Event("change", { bubbles: true }));
  };

  const envoyes = (motif) => {
    const fd = new w.FormData(form);
    return [...fd.keys()].filter((k) => motif.test(k)).sort();
  };

  const encadre = () => report.querySelector("[data-adresse-report-encadre]");
  const texteEncadre = () => report.querySelector("[data-adresse-report-adresse]").textContent;
  const noteEncadre = () => report.querySelector("[data-adresse-report-note]").textContent;

  return { dom, w, d, form, bloc, champ, val, chercher, propositions, retenir, basculerManuel, saisir, report, caseReport, cocher, envoyes, encadre, texteEncadre, noteEncadre };
}

const C = await monter();

/* ------------------------------------------------------------------ */
titre("1. Deux composants montés en même temps");

const blocs = [...C.d.querySelectorAll("[data-adresse]")];

check("deux composants d’adresse sont montés", 2 === blocs.length);
check("chacun annonce son rôle", "declarant" === blocs[0].getAttribute("data-adresse") && "terrain" === blocs[1].getAttribute("data-adresse"));
check("un seul report est monté", 1 === C.d.querySelectorAll("[data-adresse-report]").length);

/* ------------------------------------------------------------------ */
titre("2. Identifiants et attributs ARIA uniques");

const listes = blocs.map((b) => b.querySelector("[data-adresse-propositions]").id);
const controles = blocs.map((b) => b.querySelector("[data-adresse-recherche]").getAttribute("aria-controls"));

check("chaque liste porte un identifiant", listes.every((i) => i && "" !== i));
check("les deux identifiants diffèrent", listes[0] !== listes[1]);
check("chaque recherche pointe vers SA liste", controles[0] === listes[0] && controles[1] === listes[1]);
check("chaque recherche est une combobox repliée", blocs.every((b) => "combobox" === b.querySelector("[data-adresse-recherche]").getAttribute("role") && "false" === b.querySelector("[data-adresse-recherche]").getAttribute("aria-expanded")));

/* ------------------------------------------------------------------ */
titre("3. Chercher dans le déclarant ne touche pas le terrain");

await C.chercher("declarant", "12 rue de Rivoli");

check("le déclarant propose deux adresses", 2 === C.propositions("declarant").length);
check("le terrain n’en propose aucune", 0 === C.propositions("terrain").length);
check("la liste du terrain reste fermée", C.bloc("terrain").querySelector("[data-adresse-propositions]").hidden);

await C.retenir("declarant");

check("le déclarant retient le libellé canonisé", "12 Rue de Rivoli 75004 Paris" === C.val("declarant", "adresse"));
check("son code postal vient de /search", "75004" === C.val("declarant", "cp"));
check("sa commune aussi", "Paris" === C.val("declarant", "ville"));
check("son code commune aussi", "75104" === C.val("declarant", "insee"));
check("ses coordonnées arrivent par paire", "48.855602" === C.val("declarant", "lat") && "2.35995" === C.val("declarant", "lon"));
check("le service a été appelé deux fois : complétion puis recherche", 2 === C.w.__appels.length && C.w.__appels[0].includes("/completion") && C.w.__appels[1].includes("/search"));
check("le terrain n’a rien reçu", "" === C.val("terrain", "adresse") && "" === C.val("terrain", "cp") && "" === C.val("terrain", "insee"));

/* ------------------------------------------------------------------ */
titre("4. Chercher dans le terrain ne touche pas le déclarant");

await C.chercher("terrain", "12 rue de Rivoli");

check("le terrain propose deux adresses", 2 === C.propositions("terrain").length);
check("le déclarant garde son adresse retenue", "12 Rue de Rivoli 75004 Paris" === C.val("declarant", "adresse"));

await C.retenir("terrain");

check("le terrain retient sa propre adresse", "12 Rue de Rivoli 75004 Paris" === C.val("terrain", "adresse"));
check("et le déclarant n’a pas bougé", "75104" === C.val("declarant", "insee"));

/* ------------------------------------------------------------------ */
titre("5. Le clavier reste opérant sur chaque liste");

await C.chercher("declarant", "12 rue de Rivoli");

const rechD = C.bloc("declarant").querySelector("[data-adresse-recherche]");
const touche = (cible, key) => cible.dispatchEvent(new C.w.KeyboardEvent("keydown", { key, bubbles: true, cancelable: true }));

check("la liste s’ouvre sur la recherche", "true" === rechD.getAttribute("aria-expanded"));

touche(rechD, "ArrowDown");

check("la flèche basse désigne une option", null !== rechD.getAttribute("aria-activedescendant"));
check("l’option désignée appartient à CE composant", (rechD.getAttribute("aria-activedescendant") || "").startsWith(listes[0].replace("-liste", "")));
check("et l’option est marquée sélectionnée", "true" === C.propositions("declarant")[0].getAttribute("aria-selected"));

touche(rechD, "Escape");

check("Échap referme la liste", "false" === rechD.getAttribute("aria-expanded"));
check("et ne laisse aucune option désignée", null === rechD.getAttribute("aria-activedescendant"));

/* ------------------------------------------------------------------ */
titre("6. Une frappe après sélection invalide la sélection");

await C.chercher("declarant", "12 rue de Rivoli");
await C.retenir("declarant");

check("le déclarant est de nouveau retenu", "75104" === C.val("declarant", "insee"));

const rech2 = C.bloc("declarant").querySelector("[data-adresse-recherche]");
rech2.value = rech2.value + "X";
rech2.dispatchEvent(new C.w.Event("input", { bubbles: true }));

check("l’adresse retenue est effacée", "" === C.val("declarant", "adresse"));
check("le code commune aussi", "" === C.val("declarant", "insee"));
check("les coordonnées aussi", "" === C.val("declarant", "lat") && "" === C.val("declarant", "lon"));
check("le terrain, lui, n’est pas invalidé", "12 Rue de Rivoli 75004 Paris" === C.val("terrain", "adresse"));

await attendre(ANTI_REBOND + 120);

/* ------------------------------------------------------------------ */
titre("7. Case cochée sur un déclarant incomplet");

C.cocher(true);

check("l’encadré s’affiche quand même", !C.encadre().hidden);
check("il se signale invalide", "true" === C.encadre().getAttribute("aria-invalid"));
check("le report est marqué en erreur", C.report.classList.contains("is-error"));
check("la note invite à compléter le déclarant", C.noteEncadre().includes("Complétez"));
check("et la progression est refusée", false === C.w.UrbizenAdresse.reportPret(C.form));

/* ------------------------------------------------------------------ */
titre("8. Case cochée sur un déclarant automatique valide");

await C.chercher("declarant", "12 rue de Rivoli");
await C.retenir("declarant");

check("l’encadré redevient valide sans reclic", "false" === C.encadre().getAttribute("aria-invalid"));
check("le report n’est plus en erreur", !C.report.classList.contains("is-error"));
check("l’encadré montre l’adresse du déclarant", C.texteEncadre().includes("12 Rue de Rivoli 75004 Paris"));
check("la note annonce ce qui sera enregistré", C.noteEncadre().includes("adresse du terrain"));
check("la progression est de nouveau permise", true === C.w.UrbizenAdresse.reportPret(C.form));

/* ------------------------------------------------------------------ */
titre("9. Le terrain quitte réellement le formulaire");

check("le composant terrain est masqué", C.bloc("terrain").hidden);
check("tous ses contrôles sont désactivés", [...C.bloc("terrain").querySelectorAll("input, select, textarea, button")].every((c) => c.disabled));
check("donc retirés du parcours clavier", [...C.bloc("terrain").querySelectorAll("input")].every((c) => c.disabled));
check("sa liste de propositions est refermée", C.bloc("terrain").querySelector("[data-adresse-propositions]").hidden);
check("aucun champ d’adresse du terrain n’est envoyé", 0 === C.envoyes(/^terrain_(adresse|insee|lat|lon|voie|complement|cp|ville)$/).length);
check("ni son mode", 0 === C.envoyes(/^mode_adresse$/).length);
check("la case, elle, part avec la valeur canonique", "oui" === new C.w.FormData(C.form).get("terrain_meme_adresse_declarant"));
check("le déclarant continue d’être envoyé", C.envoyes(/_declarant$/).includes("adresse_declarant") && C.envoyes(/_declarant$/).includes("insee_declarant"));

/* ------------------------------------------------------------------ */
titre("10. Le déclarant modifié après cochage rafraîchit l’encadré");

C.basculerManuel("declarant", true);

check("le mode du déclarant devient manuel", "manuel" === C.val("declarant", "mode"));
check("l’encadré redevient invalide, le manuel étant vide", "true" === C.encadre().getAttribute("aria-invalid"));
check("et la progression est refusée", false === C.w.UrbizenAdresse.reportPret(C.form));

C.saisir("declarant", "voie", "Lieu-dit Les Vignes");
C.saisir("declarant", "cp", "20000");
C.saisir("declarant", "ville", "Ajaccio");

check("l’encadré suit la saisie manuelle", C.texteEncadre().includes("Lieu-dit Les Vignes") && C.texteEncadre().includes("20000 Ajaccio"));
check("il ne montre plus l’ancienne adresse", !C.texteEncadre().includes("Rivoli"));
check("il redevient valide", "false" === C.encadre().getAttribute("aria-invalid"));
check("la progression est permise", true === C.w.UrbizenAdresse.reportPret(C.form));
check("le terrain est toujours retiré", C.bloc("terrain").hidden);

/* ------------------------------------------------------------------ */
titre("11. Case cochée sur un déclarant manuel valide");

const clesManuel = C.envoyes(/_declarant$/);

check("la voie du déclarant est envoyée", clesManuel.includes("voie_declarant"));
check("son code postal et sa commune aussi", clesManuel.includes("cp_declarant") && clesManuel.includes("ville_declarant"));
check("mais aucun libellé de service", !clesManuel.includes("adresse_declarant"));
check("aucun code commune", !clesManuel.includes("insee_declarant"));
check("aucune coordonnée", !clesManuel.includes("lat_declarant") && !clesManuel.includes("lon_declarant"));

/* ------------------------------------------------------------------ */
titre("12. Décoche : le terrain revient, dans son mode");

C.cocher(false);

check("l’encadré disparaît", C.encadre().hidden);
check("le composant terrain réapparaît", !C.bloc("terrain").hidden);
check("sa recherche redevient utilisable", !C.bloc("terrain").querySelector("[data-adresse-recherche]").disabled);
check("son mode reste automatique", "automatique" === C.val("terrain", "mode"));
check("son groupe manuel reste désactivé", [...C.bloc("terrain").querySelectorAll("[data-adresse-groupe-manuel] input")].every((c) => c.disabled));
check("ses champs repartent dans le FormData", C.envoyes(/^terrain_/).includes("terrain_adresse") && C.envoyes(/^terrain_/).includes("terrain_cp"));
check("la case ne part plus du tout", null === new C.w.FormData(C.form).get("terrain_meme_adresse_declarant"));
check("la progression n’est plus conditionnée au déclarant", true === C.w.UrbizenAdresse.reportPret(C.form));

/* ------------------------------------------------------------------ */
titre("13. Le terrain redevient une adresse indépendante");

await C.chercher("terrain", "12 rue de Rivoli");
await C.retenir("terrain");

check("le terrain retient sa propre adresse", "75104" === C.val("terrain", "insee"));
check("le déclarant reste en manuel, intact", "manuel" === C.val("declarant", "mode") && "Lieu-dit Les Vignes" === C.val("declarant", "voie"));
check("les deux adresses coexistent sans se confondre", "20000" === C.val("declarant", "cp") && "75004" === C.val("terrain", "cp"));

/* ------------------------------------------------------------------ */
titre("14. Lisibilité mobile (390 px)");

/* jsdom ne calcule pas de mise en page : ce qui se vérifie ici est la règle
 * qui la produit. Le rendu réel à 390 px est contrôlé au navigateur. */
const CSS = readFileSync(resolve(THEME, "assets/forms/dp-formulaire.html"), "utf8");

check("la cible tactile des cases fait au moins 44 px", /\.dp-adresse-bascule\s*\{[^}]*min-height:\s*44px/.test(CSS));
check("l’encadré de report a son propre style", CSS.includes(".dp-report-encadre"));
check("et un style d’erreur distinct", CSS.includes(".dp-report.is-error .dp-report-encadre"));
check("le document déclare une grille repliable en mobile", /@media[^{]*max-width[^{]*\{[\s\S]*?grid-template-columns:\s*1fr/.test(CSS));

/* ------------------------------------------------------------------ */
console.log(`\n${0 === fail ? "TOUS LES CONTROLES PASSENT" : `${fail} CONTROLE(S) EN ECHEC`}`);

C.dom.window.close();
process.exit(0 === fail ? 0 : 1);
