/* Tests du moteur tarifaire partagé des formulaires DP et PCMI (jsdom).
 *
 * Les bancs s'exécutent sur le **HTML réel** des quatre formulaires, pas sur
 * une structure reproduite à la main : si un formulaire cesse de déclarer son
 * barème, perd son répéteur ou change de balisage, ces contrôles échouent au
 * lieu de rester verts sur une copie périmée.
 *
 * Aucun appel réseau : les formulaires n'en font aucun tant que ENDPOINT est
 * vide, et la cartographie n'est pas sollicitée par les étapes testées ici.
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

const MOTEUR = resolve(ROOT, "wordpress/urbizen-child/assets/js/urbizen-form-tarifs.js");

const MENTION_ATTENDUE =
  "Estimation indicative. Le tarif définitif sera confirmé par Urbizen " +
  "après vérification de votre projet, avant toute commande.";

let fail = 0;
const check = (label, cond) => {
  if (!cond) fail++;
  console.log(label.padEnd(72), cond ? "OK" : "ECHEC");
};

const titre = (t) => console.log(`\n── ${t}`);

/* ------------------------------------------------------------------ *
 *  Montage d'un formulaire réel dans jsdom
 * ------------------------------------------------------------------ */

/**
 * Charge un formulaire, injecte le moteur (le <script src> n'est pas résolu
 * par jsdom sans réseau ni resources:"usable") et exécute le script inline.
 */
async function monter(type) {
  const html = readFileSync(FORMULAIRES[type], "utf8");

  // Les <script src> externes sont neutralisés : on injecte le moteur
  // nous-mêmes, depuis le fichier réel, pour rester en phase avec la source.
  const sansSrc = html.replace(/<script src="[^"]*urbizen-form-(tarifs|pieces)\.js"><\/script>/g, "");

  const virtualConsole = new VirtualConsole();
  virtualConsole.on("jsdomError", () => {});

  const dom = new JSDOM(sansSrc, {
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

  return { dom, window, document: window.document };
}

/**
 * Le script inline s'exécute au chargement et appelle `UrbizenTarifs.init`.
 * Pour piloter le moteur depuis le banc, on le ré-instancie sur le même DOM :
 * c'est la même classe, la même configuration lue dans le même document.
 */
async function monterAvecMoteur(type) {
  const ctx = await monter(type);
  const { window } = ctx;

  // Le moteur n'a pas pu être chargé par <script src> : on l'évalue ici, puis
  // on rejoue l'initialisation avec la configuration réelle du formulaire.
  window.eval(readFileSync(MOTEUR, "utf8"));

  const config = configDe(type);
  const form = ctx.document.getElementById("dp-form");
  const tarifs = window.UrbizenTarifs.init({ form, ...config });

  return { ...ctx, tarifs, form };
}

/** Configurations, recopiées volontairement depuis les formulaires. */
function configDe(type) {
  if ("dp" === type) {
    return {
      libelleType: "Déclaration préalable",
      bareme: {
        "extension": 549,
        "cloture_mur": 189,
        "panneaux_solaires": 189,
        // Déclarés explicitement côté formulaire : leur tarif est une décision
        // produit, pas un repli sur « __defaut ».
        "garage": 249,
        "carport": 249,
        "__defaut": 249,
      },
      categories: {
        "extension": "Projet important",
        "cloture_mur": "Projet simple",
        "panneaux_solaires": "Projet simple",
        "__defaut": "Projet standard",
      },
      surEtude: [],
      supplements: { abf: 80, depot: 30, travail: 100 },
    };
  }

  return {
    libelleType: "Permis de construire",
    bareme: {
      "maison_individuelle": 849,
      "extension": 649,
      "surelevation": 649,
      "changement_destination": 649,
      "annexe_garage": 449,
      "__defaut": 449,
    },
    categories: {
      "maison_individuelle": "Projet important",
      "extension": "Projet standard",
      "surelevation": "Projet standard",
      "changement_destination": "Projet standard",
      "autre": "Projet à étudier",
      "__defaut": "Projet simple",
    },
    surEtude: ["autre"],
    supplements: { abf: 80, depot: 30, travail: 100 },
  };
}

/* --- petits pilotes --- */

function choisirNature(ctx, valeur) {
  const input = Array.from(ctx.form.querySelectorAll('input[name="nature"]')).find(
    (i) => i.value === valeur
  );
  if (!input) throw new Error(`nature « ${valeur} » absente du formulaire`);
  input.checked = true;
  input.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
}

function basculerAbf(ctx, oui) {
  const input = ctx.form.querySelector(`input[name="abf"][value="${oui ? "oui" : "non"}"]`);
  input.checked = true;
  input.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
}

function basculerDepot(ctx, actif) {
  const input = ctx.form.querySelector('input[name="depot_guichet"]');
  input.checked = actif;
  input.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
}

function ajouterTravail(ctx, nature) {
  ctx.tarifs.ajouter();
  if (undefined === nature) return;

  const selects = ctx.form.querySelectorAll(".dp-travail-nature");
  const select = selects[selects.length - 1];
  select.value = nature;
  select.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));
}

const totalAffiche = (ctx) =>
  ctx.form.querySelector("[data-tarifs-recap] .dp-recap-total strong").textContent;

const lignesRecap = (ctx) =>
  Array.from(ctx.form.querySelectorAll("[data-tarifs-recap] .dp-recap-ligne")).map((l) => ({
    libelle: l.querySelector("dt").textContent,
    valeur: l.querySelector("dd").textContent,
  }));

/* ================================================================== *
 *  1. Projet principal seul — barème de chaque nature
 * ================================================================== */

titre("1 — Projet principal seul, barème par nature");

{
  const ctx = await monterAvecMoteur("dp");
  const attendus = {
    "extension": 549,
    "cloture_mur": 189,
    "panneaux_solaires": 189,
    "abri_annexe": 249,
    // Garage et Carport sont des natures à part entière : le client ne doit pas
    // avoir à les deviner sous « Abri, annexe ».
    "garage": 249,
    "carport": 249,
    "piscine": 249,
    "modification_facade": 249,
    "ravalement": 249,
    "toiture": 249,
    "changement_destination": 249,
    "autre": 249,
  };

  for (const [nature, montant] of Object.entries(attendus)) {
    choisirNature(ctx, nature);
    const d = ctx.tarifs.detail();
    check(`DP · ${nature} → ${montant} €`, d.principal.montant === montant && d.total === montant);
  }

  check("DP · aucun supplément appliqué au projet principal", ctx.tarifs.detail().abf === 0);
  ctx.dom.window.close();
}

{
  const ctx = await monterAvecMoteur("pc");
  const attendus = {
    "maison_individuelle": 849,
    "extension": 649,
    "surelevation": 649,
    "changement_destination": 649,
    "annexe_garage": 449,
  };

  for (const [nature, montant] of Object.entries(attendus)) {
    choisirNature(ctx, nature);
    const d = ctx.tarifs.detail();
    check(`PC · ${nature} → ${montant} €`, d.principal.montant === montant && d.total === montant);
  }

  ctx.dom.window.close();
}

/* ================================================================== *
 *  2. Choix unique
 * ================================================================== */

titre("2 — Le projet principal est un choix unique");

{
  const ctx = await monterAvecMoteur("dp");

  check(
    "toutes les natures sont des boutons radio",
    Array.from(ctx.form.querySelectorAll('input[name="nature"]')).every((i) => "radio" === i.type)
  );

  choisirNature(ctx, "piscine");
  choisirNature(ctx, "extension");

  const cochees = ctx.form.querySelectorAll('input[name="nature"]:checked');
  check("une seule nature reste cochée", 1 === cochees.length && "extension" === cochees[0].value);

  const allumees = ctx.form.querySelectorAll(".dp-check.on");
  check("une seule carte porte l'état visuel « on »", 1 === allumees.length);

  check("le tarif suit le dernier choix (549 €)", 549 === ctx.tarifs.detail().total);
  ctx.dom.window.close();
}

/* ================================================================== *
 *  3. Suppléments : ABF, dépôt, cumul
 * ================================================================== */

titre("3 — Suppléments ABF et dépôt numérique");

{
  const ctx = await monterAvecMoteur("dp");
  choisirNature(ctx, "extension");

  check("dépôt numérique décoché par défaut", !ctx.form.querySelector('input[name="depot_guichet"]').checked);
  check("base seule = 549 €", 549 === ctx.tarifs.detail().total);

  basculerAbf(ctx, true);
  check("ABF seul → 549 + 80 = 629 €", 629 === ctx.tarifs.detail().total);

  basculerAbf(ctx, false);
  basculerDepot(ctx, true);
  check("dépôt seul → 549 + 30 = 579 €", 579 === ctx.tarifs.detail().total);

  basculerAbf(ctx, true);
  check("ABF + dépôt → 549 + 80 + 30 = 659 €", 659 === ctx.tarifs.detail().total);

  basculerDepot(ctx, false);
  check("recalcul immédiat au décochage → 629 €", 629 === ctx.tarifs.detail().total);
  check("le total affiché suit le calcul", "629 €" === totalAffiche(ctx));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  4. Projets supplémentaires
 * ================================================================== */

titre("4 — Projets supplémentaires");

{
  const ctx = await monterAvecMoteur("dp");
  choisirNature(ctx, "extension");

  check("aucun travail au départ → 549 €", 549 === ctx.tarifs.detail().total);

  ajouterTravail(ctx, "piscine");
  check("un travail → 549 + 100 = 649 €", 649 === ctx.tarifs.detail().total);

  ajouterTravail(ctx, "toiture");
  ajouterTravail(ctx, "ravalement");
  check("trois travaux → 549 + 300 = 849 €", 849 === ctx.tarifs.detail().total);

  check("trois lignes affichées", 3 === ctx.form.querySelectorAll(".dp-travail").length);

  ctx.tarifs.supprimer(1);
  check("après suppression → 549 + 200 = 749 €", 749 === ctx.tarifs.detail().total);
  check("deux lignes restantes", 2 === ctx.form.querySelectorAll(".dp-travail").length);
  check(
    "les lignes sont renumérotées",
    "Projet supplémentaire 1" === ctx.form.querySelectorAll(".dp-travail-num")[0].textContent &&
      "Projet supplémentaire 2" === ctx.form.querySelectorAll(".dp-travail-num")[1].textContent
  );
  check(
    "la ligne supprimée a bien disparu du détail",
    !ctx.tarifs.detail().travaux.some((t) => "toiture" === t.nature)
  );

  ctx.dom.window.close();
}

titre("5 — Le doublon est impossible");

{
  const ctx = await monterAvecMoteur("dp");
  choisirNature(ctx, "extension");
  ajouterTravail(ctx, "piscine");
  ajouterTravail(ctx);

  const options = Array.from(
    ctx.form.querySelectorAll(".dp-travail")[1].querySelectorAll("option")
  ).map((o) => o.value);

  check("le projet principal n'est pas proposé comme travail", !options.includes("extension"));
  check("une nature déjà ajoutée n'est pas reproposée", !options.includes("piscine"));
  check("les autres natures restent proposées", options.includes("toiture"));

  // Le principal bascule sur une nature déjà en ligne : la ligne est vidée,
  // jamais supprimée en silence.
  choisirNature(ctx, "piscine");
  check("changer de principal vide la ligne devenue identique", "" === ctx.tarifs.travaux[0].nature);
  check("la ligne vide invalide l'étape", !ctx.tarifs.estValide());
  check("le projet principal n'est jamais compté comme projet supplémentaire",
    !ctx.tarifs.detail().travaux.some((t) => "piscine" === t.nature));

  ctx.dom.window.close();
}

titre("5 bis — Garage et Carport, natures distinctes de « Abri, annexe »");

{
  const ctx = await monterAvecMoteur("dp");

  const valeurs = Array.from(ctx.form.querySelectorAll('input[name="nature"]')).map((i) => i.value);
  check("« Garage » est proposé comme projet principal", valeurs.includes("garage"));
  check("« Carport / abri de voiture » est proposé comme projet principal",
    valeurs.includes("carport"));
  check("« Abri / annexe » subsiste pour les autres annexes", valeurs.includes("abri_annexe"));

  const libelle = (v) =>
    Array.from(ctx.form.querySelectorAll('input[name="nature"]'))
      .find((i) => i.value === v)
      .closest(".dp-check")
      .textContent.trim();

  check("le client lit « Garage »", "Garage" === libelle("garage"));
  check("le client lit « Carport, abri de voiture »",
    "Carport, abri de voiture" === libelle("carport"));

  // Chacune, seule, au barème de base.
  choisirNature(ctx, "garage");
  check("Garage en projet principal → 249 €", 249 === ctx.tarifs.detail().total);

  choisirNature(ctx, "carport");
  check("Carport en projet principal → 249 €", 249 === ctx.tarifs.detail().total);

  // Ajoutées comme projets supplémentaires : +100 €, jamais leur barème.
  choisirNature(ctx, "extension");
  ajouterTravail(ctx, "garage");
  check("Garage en projet supplémentaire → 549 + 100 = 649 €", 649 === ctx.tarifs.detail().total);

  ajouterTravail(ctx, "carport");
  check("Carport en projet supplémentaire → 549 + 200 = 749 €", 749 === ctx.tarifs.detail().total);
  check("les deux valent 100 € chacune, pas leur barème",
    ctx.tarifs.detail().travaux.every((t) => 100 === t.montant));

  // Anti-doublon sur les nouvelles natures.
  ajouterTravail(ctx);
  const libres = Array.from(
    ctx.form.querySelectorAll(".dp-travail")[2].querySelectorAll("option")
  )
    .map((o) => o.value)
    .filter(Boolean);

  check("Garage déjà ajouté n'est pas reproposé", !libres.includes("garage"));
  check("Carport déjà ajouté n'est pas reproposé", !libres.includes("carport"));

  // En projet principal, elles disparaissent de la liste des travaux.
  ctx.tarifs.supprimer(2);
  ctx.tarifs.supprimer(1);
  ctx.tarifs.supprimer(0);
  choisirNature(ctx, "garage");
  ajouterTravail(ctx);
  const apresPrincipal = Array.from(
    ctx.form.querySelector(".dp-travail").querySelectorAll("option")
  )
    .map((o) => o.value)
    .filter(Boolean);

  check("Garage projet principal → absent des projets supplémentaires",
    !apresPrincipal.includes("garage"));
  check("Carport reste proposable tant qu'il n'est pas le principal",
    apresPrincipal.includes("carport"));

  // Lisibles dans le récapitulatif, sous leur libellé client.
  const selectRestant = ctx.form.querySelector(".dp-travail-nature");
  selectRestant.value = "carport";
  selectRestant.dispatchEvent(new ctx.window.Event("change", { bubbles: true }));

  const lignes = lignesRecap(ctx);
  check("le récapitulatif nomme Garage comme projet principal",
    lignes.some((l) => "Projet principal · Garage" === l.libelle && "249 €" === l.valeur));
  check("le récapitulatif nomme Carport, abri de voiture en projet supplémentaire",
    lignes.some((l) => "Projet supplémentaire — Carport, abri de voiture" === l.libelle && "+100 €" === l.valeur));

  ctx.tarifs.rendreRecapFinal();
  const texteFinal = ctx.document.querySelector("[data-tarifs-recap-final]").textContent;
  check("l'écran final nomme Garage sous son libellé client", texteFinal.includes("Garage"));
  check("l'écran final porte le total 349 €", texteFinal.includes("349 €"));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  6. PC « Autre » — tarif sur étude
 * ================================================================== */

titre("6 — PC « Autre » : tarif sur étude");

{
  const ctx = await monterAvecMoteur("pc");
  choisirNature(ctx, "autre");
  ajouterTravail(ctx, "extension");
  basculerAbf(ctx, true);

  const d = ctx.tarifs.detail();
  check("le projet principal est sur étude", d.principal.surEtude && null === d.principal.montant);
  check("aucun total chiffré n'est inventé", null === d.total);
  check("le total affiche « Tarif sur étude »", "Tarif sur étude" === totalAffiche(ctx));

  const lignes = lignesRecap(ctx);
  check("le principal est listé « Tarif sur étude »",
    lignes.some((l) => l.libelle.startsWith("Projet principal") && "Tarif sur étude" === l.valeur));
  check("le projet supplémentaire reste chiffré à 100 €",
    lignes.some((l) => l.libelle.startsWith("Projets supplémentaires") && "100 €" === l.valeur));
  check("le supplément ABF reste chiffré à 80 €",
    lignes.some((l) => "Secteur Bâtiments de France" === l.libelle && "80 €" === l.valeur));
  check("le dépôt non coché n'apparaît pas",
    !lignes.some((l) => l.libelle.includes("guichet")));

  basculerDepot(ctx, true);
  check("le dépôt coché apparaît alors à 30 €",
    lignesRecap(ctx).some((l) => l.libelle.includes("guichet") && "30 €" === l.valeur));
  check("le total reste « Tarif sur étude »", "Tarif sur étude" === totalAffiche(ctx));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  7. Récapitulatif
 * ================================================================== */

titre("7 — Récapitulatif détaillé");

{
  const ctx = await monterAvecMoteur("dp");
  choisirNature(ctx, "extension");
  ajouterTravail(ctx, "piscine");
  ajouterTravail(ctx, "toiture");
  ajouterTravail(ctx, "ravalement");
  basculerAbf(ctx, true);
  basculerDepot(ctx, true);

  check("cumul complet 549 + 300 + 80 + 30 = 959 €", 959 === ctx.tarifs.detail().total);
  check("le total affiché vaut 959 €", "959 €" === totalAffiche(ctx));

  const lignes = lignesRecap(ctx);
  check("le projet principal est détaillé",
    lignes.some((l) => l.libelle.startsWith("Projet principal") && "549 €" === l.valeur));
  check("les projets supplémentaires sont regroupés à 300 €",
    lignes.some((l) => "Projets supplémentaires (3)" === l.libelle && "300 €" === l.valeur));
  check("chaque travail est détaillé à 100 €",
    3 === ctx.form.querySelectorAll("[data-tarifs-recap] .dp-recap-ligne.is-detail").length);
  check("le supplément ABF est détaillé séparément",
    lignes.some((l) => "Secteur Bâtiments de France" === l.libelle && "80 €" === l.valeur));
  check("le dépôt numérique est détaillé séparément",
    lignes.some((l) => "Dépôt sur le guichet numérique" === l.libelle && "30 €" === l.valeur));

  const mention = ctx.form.querySelector("[data-tarifs-recap] .dp-recap-mention").textContent;
  check("la mention d'estimation est conforme au caractère près", MENTION_ATTENDUE === mention);

  // Aucune ligne à zéro : ce qui n'est pas choisi n'est pas affiché.
  basculerAbf(ctx, false);
  basculerDepot(ctx, false);
  check("une ligne à zéro est masquée, pas affichée « 0 € »",
    !lignesRecap(ctx).some((l) => "0 €" === l.valeur));
  check("le total reste toujours présent", "849 €" === totalAffiche(ctx));

  ctx.dom.window.close();
}

titre("8 — Écran de confirmation");

{
  const ctx = await monterAvecMoteur("dp");
  choisirNature(ctx, "extension");
  ajouterTravail(ctx, "piscine");
  basculerAbf(ctx, true);

  ctx.tarifs.rendreRecapFinal();

  const final = ctx.document.querySelector("[data-tarifs-recap-final]");
  const totalFinal = final.querySelector(".dp-recap-total strong").textContent;

  check("l'écran final reprend le même total (729 €)", "729 €" === totalFinal);
  check("l'écran final porte la mention imposée",
    MENTION_ATTENDUE === final.querySelector(".dp-recap-mention").textContent);
  check("le récapitulatif final est synthétique (sans détail ligne à ligne)",
    0 === final.querySelectorAll(".dp-recap-ligne.is-detail").length);

  ctx.dom.window.close();
}

/* ================================================================== *
 *  9. Sérialisation
 * ================================================================== */

titre("9 — Sérialisation de la demande");

{
  const ctx = await monterAvecMoteur("dp");
  choisirNature(ctx, "extension");
  ajouterTravail(ctx, "piscine");
  basculerDepot(ctx, true);

  const fd = new ctx.window.FormData(ctx.form);
  ctx.tarifs.serialiser(fd);

  check("« nature » porte une valeur unique", "extension" === fd.get("nature"));
  check("les projets supplémentaires voyagent en valeurs répétées",
    JSON.stringify(["piscine"]) === JSON.stringify(fd.getAll("projets_supplementaires[]")));
  check("aucune chaîne JSON de projets n'est émise", null === fd.get("travaux_supplementaires"));
  check("l'option de dépôt est sérialisée", "oui" === fd.get("depot_guichet"));
  check("aucun montant n'est envoyé au serveur",
    null === fd.get("total") && null === fd.get("estimation"));

  ctx.dom.window.close();
}

/* ================================================================== *
 *  9 bis. Vocabulaire client et cibles tactiles
 * ================================================================== */

titre("9 bis — Vocabulaire « projet supplémentaire » et accessibilité");

// Un client lit « projet », pas « travail » : le mot « travaux » reste réservé
// à l'objet de la déclaration (« déclaration préalable de travaux »), jamais au
// répéteur. Ces motifs sont donc cherchés dans le texte réellement rendu.
const INTERDITS = ["travail supplémentaire", "Travail supplémentaire",
                   "travaux supplémentaires", "Travaux supplémentaires",
                   "Ajouter un travail", "ajouter d’autres travaux"];

for (const type of ["dp", "pc"]) {
  const ctx = await monterAvecMoteur(type);
  choisirNature(ctx, "dp" === type ? "extension" : "maison_individuelle");
  ajouterTravail(ctx, "dp" === type ? "piscine" : "surelevation");
  basculerAbf(ctx, true);
  basculerDepot(ctx, true);

  const etape = ctx.form.querySelector("[data-tarifs-travaux]").closest(".dp-step");
  const rendu = etape.textContent;

  check(`${type.toUpperCase()} · aucun vocabulaire « travail » dans l'étape`,
    !INTERDITS.some((mot) => rendu.includes(mot)));
  check(`${type.toUpperCase()} · l'étape s'intitule « Projets supplémentaires »`,
    etape.querySelector(".dp-step-kicker").textContent.includes("Projets supplémentaires"));
  check(`${type.toUpperCase()} · la question porte sur « d'autres projets »`,
    etape.querySelector(".dp-step-title").textContent.includes("d’autres projets"));
  check(`${type.toUpperCase()} · le bouton dit « + Ajouter un projet »`,
    "+ Ajouter un projet" === ctx.form.querySelector("[data-tarifs-ajouter]").textContent);
  // La légende du rail gauche est lue en permanence : elle ne doit pas rester
  // le dernier endroit où le client lit « Travaux ».
  check(`${type.toUpperCase()} · la légende nomme l'étape « Autres projets »`,
    readFileSync(FORMULAIRES[type], "utf8").includes('"Autres projets"') &&
      !/legendData[^\]]*"Travaux"/.test(readFileSync(FORMULAIRES[type], "utf8")));
  check(`${type.toUpperCase()} · l'en-tête de ligne dit « Projet supplémentaire 1 »`,
    "Projet supplémentaire 1" === ctx.form.querySelector(".dp-travail-num").textContent);
  check(`${type.toUpperCase()} · le récapitulatif groupe sous « Projets supplémentaires (1) »`,
    lignesRecap(ctx).some((l) => "Projets supplémentaires (1)" === l.libelle));
  check(`${type.toUpperCase()} · le détail nomme « Projet supplémentaire — … : +100 € »`,
    lignesRecap(ctx).some((l) => l.libelle.startsWith("Projet supplémentaire — ") && "+100 €" === l.valeur));

  /* --- accessibilité : bouton de suppression --- */
  const suppr = ctx.form.querySelector(".dp-travail-suppr");
  check(`${type.toUpperCase()} · « Supprimer » est un vrai bouton`,
    "BUTTON" === suppr.tagName && "button" === suppr.type);
  check(`${type.toUpperCase()} · son aria-label nomme le projet visé`,
    "Supprimer le projet supplémentaire 1" === suppr.getAttribute("aria-label"));

  /* --- accessibilité : option de dépôt --- */
  const depot = ctx.form.querySelector('input[name="depot_guichet"]');
  const label = depot.closest("label");
  check(`${type.toUpperCase()} · la case de dépôt est imbriquée dans son libellé`,
    null !== label && label.classList.contains("dp-option"));
  check(`${type.toUpperCase()} · le libellé reste associé par for/id`,
    "depot_guichet" === label.getAttribute("for") && "depot_guichet" === depot.id);
  check(`${type.toUpperCase()} · le texte et le montant sont dans la zone cliquable`,
    label.textContent.includes("guichet numérique") && label.textContent.includes("+30 €"));

  /* --- suppression toujours fonctionnelle après le renommage --- */
  const avant = ctx.tarifs.detail().total;
  suppr.click();
  check(`${type.toUpperCase()} · le bouton supprime et recalcule`,
    0 === ctx.form.querySelectorAll(".dp-travail").length && avant - 100 === ctx.tarifs.detail().total);

  ctx.dom.window.close();
}

// La cible de 44 px ne se mesure pas dans jsdom, qui ne fait pas de mise en
// page : on vérifie que la règle existe, la mesure réelle se fait au navigateur.
{
  const css = readFileSync(
    resolve(ROOT, "wordpress/urbizen-child/assets/css/urbizen-form-tarifs.css"), "utf8");
  check("le bouton de suppression déclare une cible d'au moins 44 px",
    /\.dp-travail-suppr\s*\{[^}]*min-height:\s*44px/s.test(css) &&
      /\.dp-travail-suppr\s*\{[^}]*min-width:\s*44px/s.test(css));
  check("l'option de dépôt déclare une hauteur minimale de 44 px",
    /\.dp-option\s*\{[^}]*min-height:\s*44px/s.test(css));
}

/* ================================================================== *
 *  10. Parité maquette / thème
 * ================================================================== */

titre("10 — Parité des sources");

{
  for (const type of ["dp", "pc"]) {
    const theme = readFileSync(FORMULAIRES[type], "utf8");
    const front = readFileSync(MAQUETTES[type], "utf8");

    // Le barème doit être écrit à l'identique de part et d'autre.
    const bareme = (s) => {
      const m = s.match(/bareme:\s*\{[\s\S]*?\},/);
      return m ? m[0].replace(/\s+/g, " ") : null;
    };

    check(`${type.toUpperCase()} · barème identique entre maquette et thème`,
      null !== bareme(theme) && bareme(theme) === bareme(front));

    check(`${type.toUpperCase()} · suppléments identiques`,
      theme.includes("supplements: { abf: 80, depot: 30, travail: 100 }") &&
        front.includes("supplements: { abf: 80, depot: 30, travail: 100 }"));

    check(`${type.toUpperCase()} · aucun calcul inline résiduel`,
      !theme.includes("function estimatePrice") && !front.includes("function estimatePrice"));

  // Le vocabulaire doit être harmonisé dans les deux copies, pas seulement dans
  // celle que jsdom monte.
  for (const [origine, source] of [["thème", theme], ["maquette", front]]) {
    check(`${type.toUpperCase()} · ${origine} — vocabulaire « projet » harmonisé`,
      source.includes("Cadre 8 · Projets supplémentaires") &&
        source.includes(">+ Ajouter un projet</button>") &&
        source.includes("d’autres projets à ce dossier") &&
        !source.includes("Ajouter un travail") &&
        !source.includes("Cadre 8 · Travaux supplémentaires"));
    check(`${type.toUpperCase()} · ${origine} — case de dépôt imbriquée dans son libellé`,
      source.includes('<label class="dp-option" for="depot_guichet">'));
  }

    check(`${type.toUpperCase()} · les deux copies chargent le moteur partagé`,
      theme.includes("urbizen-form-tarifs.js") && front.includes("urbizen-form-tarifs.js"));
  }

  // Le moteur est l'unique porteur de la mention et de la règle de cumul.
  const moteur = readFileSync(MOTEUR, "utf8");
  check("la mention imposée n'est déclarée que dans le moteur",
    moteur.includes("Estimation indicative.") &&
      !readFileSync(FORMULAIRES.dp, "utf8").includes("Estimation indicative.") &&
      !readFileSync(FORMULAIRES.pc, "utf8").includes("Estimation indicative."));
}

/* ------------------------------------------------------------------ */

console.log("");
if (fail) {
  console.log(`[31m${fail} CONTROLE(S) EN ECHEC[0m`);
  process.exit(1);
}
console.log("[32mTOUS LES CONTROLES PASSENT[0m");
