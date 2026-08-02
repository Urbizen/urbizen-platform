/* Tests du module partagé « pièces du projet » des formulaires DP et PCMI.
 *
 * Ce que ces bancs protègent : la promesse faite au client qu'il peut envoyer
 * sa demande sans disposer de toutes les photos. Une régression ici ne casse
 * pas un calcul — elle transforme une demande envoyable en demande bloquée,
 * silencieusement. D'où les contrôles sur l'absence de blocage et sur l'absence
 * de vocabulaire d'erreur.
 *
 * Exécutés sur le HTML réel des quatre documents, sans réseau.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");

const FORMULAIRES = {
  dp: resolve(ROOT, "wordpress/urbizen-child/assets/forms/dp-formulaire.html"),
  pc: resolve(ROOT, "wordpress/urbizen-child/assets/forms/pc-formulaire.html"),
};

const MAQUETTES = {
  dp: resolve(ROOT, "frontend/formulaires/dp-formulaire.html"),
  pc: resolve(ROOT, "frontend/formulaires/pc-formulaire.html"),
};

const MODULE = resolve(ROOT, "wordpress/urbizen-child/assets/js/urbizen-form-pieces.js");
const MOTEUR = resolve(ROOT, "wordpress/urbizen-child/assets/js/urbizen-form-tarifs.js");

const MESSAGE_ATTENDU =
  "Vous ne disposez pas encore de toutes les photos ou informations " +
  "demandées ? Vous pouvez tout de même poursuivre et nous les transmettre " +
  "ultérieurement. Urbizen vous indiquera, après vérification de votre " +
  "demande, les éléments complémentaires nécessaires à la réalisation du " +
  "dossier.";

// Retirée volontairement : répétée sous les sept pièces, elle concurrençait
// l'encadré d'ouverture. Le banc vérifie désormais son absence.
const MENTION_RETIREE = "Vous pourrez nous transmettre cet élément plus tard.";
const LIBELLE_REPORT = "Je transmettrai ce document ultérieurement.";
const TITRE_REPORT = "À transmettre ultérieurement";

// Texte imposé, vérifié au caractère près : sur le DOM rendu pour les deux
// formulaires du thème, et dans la source des quatre documents.
const LIBELLE_CADASTRE_INCONNU = "Je ne connais pas ces informations cadastrales.";

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(72), cond ? "OK" : "ECHEC");
};
const titre = (t) => console.log(`\n── ${t}`);

/* ------------------------------------------------------------------ */

const PIECES = [
  ["PHOTO", "Photos du terrain et de la maison existante"],
  ["RUE", "Photos prises depuis la rue et de l’accès"],
  ["FACADES", "Photos des façades concernées"],
  ["CROQUIS", "Croquis du projet, même à main levée"],
  ["PLANS", "Plans existants en votre possession"],
  ["MESURES", "Relevés de dimensions ou mesures utiles"],
  ["AUTRES", "Autres documents utiles : devis, matériaux, étude de sol…"],
];

async function monter(type) {
  const html = readFileSync(FORMULAIRES[type], "utf8").replace(
    /<script src="[^"]*urbizen-form-(tarifs|pieces)\.js"><\/script>/g,
    ""
  );

  const virtualConsole = new VirtualConsole();
  virtualConsole.on("jsdomError", () => {});

  const dom = new JSDOM(html, {
    url: "https://exemple.test/",
    runScripts: "dangerously",
    pretendToBeVisual: true,
    virtualConsole,
  });

  const { window } = dom;
  await new Promise((r) => {
    if ("complete" === window.document.readyState) r();
    else window.addEventListener("load", r);
  });

  window.eval(readFileSync(MOTEUR, "utf8"));
  window.eval(readFileSync(MODULE, "utf8"));

  const form = window.document.getElementById("dp-form");
  const pieces = window.UrbizenPieces.init({
    form,
    conteneur: window.document.getElementById("pieces"),
    pieces: PIECES,
  });

  return { dom, window, document: window.document, form, pieces };
}

function reporter(ctx, code, actif) {
  const rangee = ctx.form.querySelector(`.dp-piece[data-piece="${code}"]`);
  const caseReport = rangee.querySelector(".dp-piece-report input");
  caseReport.checked = actif;
  caseReport.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
  return rangee;
}

/* ================================================================== *
 *  1. Message rassurant
 * ================================================================== */

titre("1 — Le message rassurant ouvre l'étape");

for (const type of ["dp", "pc"]) {
  const ctx = await monter(type);
  const conteneur = ctx.document.getElementById("pieces");
  const intro = conteneur.querySelector(".dp-pieces-intro");

  check(`${type.toUpperCase()} · le message est présent en tête de l'étape`, null !== intro);
  check(`${type.toUpperCase()} · le message est conforme au caractère près`,
    MESSAGE_ATTENDU === intro.querySelector(".dp-pieces-intro-texte").textContent);
  check(`${type.toUpperCase()} · il précède la première pièce`,
    intro === conteneur.firstElementChild);
  check(`${type.toUpperCase()} · la distinction obligatoire / à compléter est dite`,
    intro.textContent.includes("n’est obligatoire pour envoyer votre demande"));

  // Aucune promesse abusive : le texte ne laisse pas croire qu'un dossier peut
  // être déposé en mairie sans ses pièces réglementaires.
  const t = intro.textContent.toLowerCase();
  check(`${type.toUpperCase()} · aucune promesse de dispense`,
    !t.includes("sans pièce") && !t.includes("pas besoin") && !t.includes("facultatif en mairie"));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  2. Mention courte et option de report
 * ================================================================== */

titre("2 — Chaque rangée se limite au document, au bouton et au report");

for (const type of ["dp", "pc"]) {
  const ctx = await monter(type);
  const rangees = ctx.form.querySelectorAll(".dp-piece");

  check(`${type.toUpperCase()} · une rangée par pièce`, PIECES.length === rangees.length);

  // Le contenu d'une rangée est délibérément minimal : la pédagogie vit dans
  // l'encadré d'ouverture, pas répétée sept fois.
  check(`${type.toUpperCase()} · aucune mention répétée sous les documents`,
    !ctx.document.getElementById("pieces").textContent.includes(MENTION_RETIREE));
  check(`${type.toUpperCase()} · plus aucun élément d'indice dans le DOM`,
    0 === ctx.form.querySelectorAll(".dp-piece-indice").length);

  check(`${type.toUpperCase()} · chaque rangée nomme le document`,
    Array.from(rangees).every((r, i) => r.querySelector(".lab").textContent.includes(PIECES[i][1])));
  check(`${type.toUpperCase()} · chaque rangée porte le bouton de dépôt`,
    Array.from(rangees).every((r) => "Choisir un fichier" === r.querySelector(".dp-file-btn").textContent));
  check(`${type.toUpperCase()} · chaque rangée porte l'option de report`,
    Array.from(rangees).every((r) => r.querySelector(".dp-piece-report").textContent.includes(LIBELLE_REPORT)));
  check(`${type.toUpperCase()} · aucune option de report cochée par défaut`,
    Array.from(rangees).every((r) => !r.querySelector(".dp-piece-report input").checked));

  // L'encadré général, lui, conserve bien l'information.
  check(`${type.toUpperCase()} · l'encadré d'ouverture porte toujours l'explication`,
    ctx.document.querySelector(".dp-pieces-intro").textContent.includes("transmettre ultérieurement"));

  ctx.dom.window.close();
}

{
  const ctx = await monter("dp");
  const rangee = reporter(ctx, "PHOTO", true);

  check("pièce reportée → la rangée est marquée", rangee.classList.contains("is-reportee"));
  check("pièce reportée → l'état affiché est « À transmettre ultérieurement »",
    TITRE_REPORT === rangee.querySelector(".picked").textContent);

  reporter(ctx, "PHOTO", false);
  check("report annulé → l'état affiché redevient vide",
    "" === rangee.querySelector(".picked").textContent);
  check("report annulé → la rangée n'est plus marquée",
    !rangee.classList.contains("is-reportee"));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  3. Récapitulatif des pièces différées
 * ================================================================== */

titre("3 — « À transmettre ultérieurement » dans le récapitulatif");

{
  const ctx = await monter("dp");
  const recap = () => ctx.form.querySelector("[data-pieces-recap]");

  check("aucun bloc tant qu'aucune pièce n'est reportée", 0 === recap().children.length);

  reporter(ctx, "FACADES", true);
  reporter(ctx, "MESURES", true);

  const bloc = recap().querySelector(".dp-pieces-differees");
  check("le bloc apparaît dès la première pièce reportée", null !== bloc);
  check("il porte le titre « À transmettre ultérieurement »",
    TITRE_REPORT === bloc.querySelector(".dp-pieces-differees-titre").textContent);

  const items = Array.from(bloc.querySelectorAll("li")).map((l) => l.textContent);
  check("les deux pièces reportées y figurent, sous leur libellé client",
    2 === items.length &&
      items[0].includes("Photos des façades") &&
      items[1].includes("Relevés de dimensions"));
  check("l'ordre du formulaire est respecté",
    items[0].includes("façades") && items[1].includes("dimensions"));
  check("le bloc rappelle que l'envoi n'est pas bloqué",
    bloc.textContent.includes("ne bloquent pas l’envoi"));

  // Aucun vocabulaire d'erreur : un report est une intention, pas un défaut.
  const t = bloc.textContent.toLowerCase();
  check("aucun vocabulaire alarmant dans le bloc",
    !t.includes("erreur") && !t.includes("manquant") && !t.includes("obligatoire") && !t.includes("incomplet"));

  reporter(ctx, "FACADES", false);
  check("décocher retire la pièce du récapitulatif",
    1 === recap().querySelectorAll("li").length);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  4. Une pièce reportée ne bloque pas l'envoi
 * ================================================================== */

titre("4 — Une pièce reportée n'empêche pas d'atteindre l'écran final");

for (const type of ["dp", "pc"]) {
  const ctx = await monter(type);

  // Aucun fichier déposé, deux pièces déclarées « à transmettre ».
  reporter(ctx, "PHOTO", true);
  reporter(ctx, "PLANS", true);

  // Les documents ne sont jamais requis : aucune entrée de l'étape ne porte
  // le marqueur d'obligation que la validation d'étape inspecte.
  const etape = ctx.document.getElementById("pieces").closest(".dp-step");
  const requisDansEtape = Array.from(etape.querySelectorAll("input, select, textarea")).filter(
    (e) => e.closest(".dp-field") && e.closest(".dp-field").querySelector(".req")
  );
  check(`${type.toUpperCase()} · aucun champ obligatoire dans l'étape Documents`,
    0 === requisDansEtape.length);

  check(`${type.toUpperCase()} · aucun fichier déposé`,
    Array.from(ctx.form.querySelectorAll('input[type="file"]')).every(
      (i) => !i.files || 0 === i.files.length
    ));

  // L'écran final est atteignable et reprend les pièces reportées.
  ctx.form.querySelector("#a1").checked = true;
  ctx.form.querySelector("#a2").checked = true;
  ctx.form.dispatchEvent(new ctx.window.Event("submit", { bubbles: true, cancelable: true }));
  ctx.pieces.rafraichirRecap();

  const final = ctx.document.querySelector("[data-pieces-recap-final]");
  check(`${type.toUpperCase()} · l'écran final liste les pièces à transmettre`,
    2 === final.querySelectorAll("li").length);
  check(`${type.toUpperCase()} · sous le titre « À transmettre ultérieurement »`,
    final.textContent.includes(TITRE_REPORT));

  // Sérialisation : Urbizen reçoit la liste, pas seulement des cases cochées.
  const fd = new ctx.window.FormData(ctx.form);
  ctx.pieces.serialiser(fd);
  check(`${type.toUpperCase()} · les pièces reportées sont sérialisées`,
    JSON.stringify(["PHOTO", "PLANS"]) === fd.get("pieces_differees"));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  5. Un fichier déposé prime sur le report
 * ================================================================== */

titre("5 — Fournir un document annule le report");

{
  const ctx = await monter("dp");
  const rangee = reporter(ctx, "CROQUIS", true);
  check("la pièce est d'abord reportée", 1 === ctx.pieces.differees().length);

  // On simule un dépôt de fichier : jsdom ne permet pas d'alimenter `files`
  // directement, on redéfinit la propriété sur l'entrée réelle.
  const input = rangee.querySelector('input[type="file"]');
  Object.defineProperty(input, "files", { value: [{ name: "croquis.pdf" }], configurable: true });
  input.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));

  check("le report est levé automatiquement", 0 === ctx.pieces.differees().length);
  check("la case de report est décochée",
    !rangee.querySelector(".dp-piece-report input").checked);
  check("la rangée passe en « fournie »", rangee.classList.contains("is-fournie"));
  check("le récapitulatif ne la mentionne plus",
    0 === ctx.form.querySelector("[data-pieces-recap]").children.length);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  5 bis. Informations cadastrales facultatives
 * ================================================================== */

titre("5 bis — Les références cadastrales ne sont plus obligatoires");

const CADASTRE = ["t_sec", "t_num", "t_sup"];

for (const type of ["dp", "pc"]) {
  const ctx = await monter(type);
  const champs = CADASTRE.map((id) => ctx.document.getElementById(id));

  // L'obligation, dans ces formulaires, tient au marqueur `.req` du label :
  // c'est lui que `validateStep()` inspecte. Son absence vaut « facultatif ».
  check(`${type.toUpperCase()} · aucun des trois champs n'est marqué obligatoire`,
    champs.every((c) => null === c.closest(".dp-field").querySelector(".req")));
  check(`${type.toUpperCase()} · aucun attribut required`,
    champs.every((c) => !c.hasAttribute("required")));
  check(`${type.toUpperCase()} · les trois champs sont vides et actifs au départ`,
    champs.every((c) => "" === c.value && !c.disabled));

  const caseInconnu = ctx.form.querySelector('input[name="cad_inconnu"]');
  check(`${type.toUpperCase()} · la case de déclaration d'ignorance existe`, null !== caseInconnu);
  check(`${type.toUpperCase()} · son libellé est conforme au caractère près`,
    LIBELLE_CADASTRE_INCONNU === caseInconnu.closest("label").textContent.trim());
  check(`${type.toUpperCase()} · elle est décochée par défaut`, !caseInconnu.checked);

  // L'étape Terrain doit rester franchissable avec les trois champs vides.
  const etape = champs[0].closest(".dp-step");
  const bloquants = Array.from(etape.querySelectorAll("input, select, textarea")).filter((e) => {
    const conteneur = e.closest(".dp-field");
    return conteneur && conteneur.querySelector(".req") && "" === String(e.value).trim();
  });
  const bloquantsCadastre = bloquants.filter((e) => CADASTRE.includes(e.id));
  check(`${type.toUpperCase()} · les trois champs vides ne bloquent pas l'étape`,
    0 === bloquantsCadastre.length);

  ctx.dom.window.close();
}

titre("5 ter — La case vide, désactive, puis rend la main");

{
  const ctx = await monter("dp");
  const champs = CADASTRE.map((id) => ctx.document.getElementById(id));
  const caseInconnu = ctx.form.querySelector('input[name="cad_inconnu"]');

  // Valeurs déjà détectées par la localisation cadastrale.
  champs[0].value = "AB";
  champs[1].value = "0142";
  champs[2].value = "620";

  check("les valeurs détectées sont conservées tant que rien n'est déclaré",
    "AB" === champs[0].value && "0142" === champs[1].value && "620" === champs[2].value);
  check("la case n'est jamais cochée automatiquement", !caseInconnu.checked);

  caseInconnu.checked = true;
  caseInconnu.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));

  check("cocher vide les trois champs", champs.every((c) => "" === c.value));
  check("cocher désactive les trois champs", champs.every((c) => c.disabled));
  check("le conteneur est marqué comme neutralisé",
    champs.every((c) => c.closest(".dp-field").classList.contains("is-desactive")));

  const recap = ctx.form.querySelector("[data-pieces-recap]");
  check("le récapitulatif annonce « Informations cadastrales : à compléter ultérieurement »",
    Array.from(recap.querySelectorAll(".dp-pieces-differees-info")).some(
      (p) => "Informations cadastrales : à compléter ultérieurement" === p.textContent
    ));
  check("le bloc apparaît même sans aucune pièce reportée",
    0 === recap.querySelectorAll("li").length && null !== recap.querySelector(".dp-pieces-differees"));
  check("aucun vocabulaire d'erreur",
    !/erreur|obligatoire|manquant|invalide/i.test(recap.textContent));

  caseInconnu.checked = false;
  caseInconnu.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));

  check("décocher réactive les trois champs", champs.every((c) => !c.disabled));
  check("décocher lève le marquage visuel",
    champs.every((c) => !c.closest(".dp-field").classList.contains("is-desactive")));
  check("décocher retire la ligne du récapitulatif",
    0 === ctx.form.querySelectorAll("[data-pieces-recap] .dp-pieces-differees-info").length);

  // Renseigner un seul des trois champs est parfaitement valide.
  champs[0].value = "AB";
  champs[0].dispatchEvent(new ctx.window.Event("input", { bubbles: true }));
  check("un seul champ renseigné suffit, les deux autres restent vides",
    "AB" === champs[0].value && "" === champs[1].value && "" === champs[2].value);

  // Une valeur saisie alors que la case est cochée lève la déclaration.
  caseInconnu.checked = true;
  caseInconnu.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
  champs[1].disabled = false;
  champs[1].value = "0142";
  champs[1].dispatchEvent(new ctx.window.Event("input", { bubbles: true }));
  check("saisir une valeur décoche la déclaration d'ignorance", !caseInconnu.checked);
  check("et la valeur saisie est conservée", "0142" === champs[1].value);

  // Sérialisation.
  caseInconnu.checked = true;
  caseInconnu.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
  const fd = new ctx.window.FormData(ctx.form);
  ctx.pieces.serialiser(fd);
  check("la déclaration est sérialisée",
    JSON.stringify(["cad_inconnu"]) === fd.get("informations_differees"));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  6. Parité des sources
 * ================================================================== */

titre("6 — Parité maquette / thème");

for (const type of ["dp", "pc"]) {
  const theme = readFileSync(FORMULAIRES[type], "utf8");
  const front = readFileSync(MAQUETTES[type], "utf8");

  check(`${type.toUpperCase()} · les deux copies chargent le module partagé`,
    theme.includes("urbizen-form-pieces.js") && front.includes("urbizen-form-pieces.js"));
  check(`${type.toUpperCase()} · aucun rendu de pièces inline résiduel`,
    !theme.includes("PIECES.forEach") && !front.includes("PIECES.forEach"));
  check(`${type.toUpperCase()} · la liste PIECES est identique`,
    JSON.stringify(theme.match(/var PIECES = \[[\s\S]*?\];/)[0]) ===
      JSON.stringify(front.match(/var PIECES = \[[\s\S]*?\];/)[0]));
  check(`${type.toUpperCase()} · les conteneurs de récapitulatif sont présents`,
    theme.includes("data-pieces-recap") && theme.includes("data-pieces-recap-final") &&
      front.includes("data-pieces-recap") && front.includes("data-pieces-recap-final"));

  // Le libellé est écrit dans le balisage, donc en quatre exemplaires : le
  // vérifier sur le DOM des seules versions du thème laisserait les maquettes
  // dériver sans que rien ne le signale.
  const attendu = `> ${LIBELLE_CADASTRE_INCONNU}</label>`;
  for (const [origine, source] of [["thème", theme], ["maquette", front]]) {
    check(`${type.toUpperCase()} · ${origine} — libellé exact de la case cadastrale`,
      1 === source.split(attendu).length - 1);
  }
  check(`${type.toUpperCase()} · aucun libellé abrégé résiduel`,
    !theme.includes("> Je ne connais pas ces informations.</label>") &&
      !front.includes("> Je ne connais pas ces informations.</label>"));
}

// Les textes n'existent qu'à un seul endroit.
const module = readFileSync(MODULE, "utf8");
check("le message rassurant n'est déclaré que dans le module",
  module.includes("Vous ne disposez pas encore") &&
    !readFileSync(FORMULAIRES.dp, "utf8").includes("Vous ne disposez pas encore") &&
    !readFileSync(FORMULAIRES.pc, "utf8").includes("Vous ne disposez pas encore"));

/* ------------------------------------------------------------------ */

console.log("");
if (fail) {
  console.log(`[31m${fail} CONTROLE(S) EN ECHEC[0m`);
  process.exit(1);
}
console.log("[32mTOUS LES CONTROLES PASSENT[0m");
