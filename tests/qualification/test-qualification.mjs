/* Banc du moteur de qualification — côté navigateur.
 *
 * Rejoue `cas.json`, le corpus partagé avec le moteur serveur, puis compare
 * verdict par verdict avec ce que le PHP en a tiré. Les deux moteurs ne
 * partagent aucun code ; ce corpus est ce qui les empêche de diverger.
 *
 * L'équivalence n'est pas un confort : une règle corrigée d'un seul côté
 * produirait un client orienté vers un formulaire que le serveur refuse, ou
 * l'inverse — une soumission acceptée sous le mauvais régime.
 */

import { readFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const ici = dirname(fileURLToPath(import.meta.url));
const racine = join(ici, "..", "..");

const { qualifyProject, ETATS } = (await import(join(racine, "frontend/homepage/qualification.js"))).default;

let fail = 0;
const check = (label, cond, detail = "") => {
  if (!cond) fail++;
  console.log(label.padEnd(74) + " " + (cond ? "OK" : "ECHEC"));
  if (!cond && detail) console.log("    " + detail);
};

const corpus = JSON.parse(readFileSync(join(ici, "cas.json"), "utf8"));
check("Le corpus partagé est lisible", Array.isArray(corpus.cas) && corpus.cas.length > 0);

/* ------------------------------------------------- le fallback est mort -- */

/* Les commentaires citent l'ancien défaut pour expliquer pourquoi ce module
   existe : on ne cherche le fallback que dans le code exécutable. */
const sansCommentaires = (src) =>
  src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

const source = sansCommentaires(readFileSync(join(racine, "frontend/homepage/qualification.js"), "utf8"));
check(
  "Aucun défaut implicite vers la déclaration préalable dans le moteur",
  !/\|\|\s*["']dp["']/.test(source)
);

const accueil = sansCommentaires(readFileSync(join(racine, "frontend/homepage/homepage.js"), "utf8"));
check(
  "L'accueil ne contient plus le fallback `|| \"dp\"`",
  !/\|\|\s*["']dp["']/.test(accueil),
  "le défaut qui envoyait tout projet non mappé en déclaration préalable"
);
check(
  "L'accueil ne réimplémente aucun seuil réglementaire",
  !/\b(20|40|150)\s*\)?\s*(?:m²)?\s*(?:>|<|>=|<=)/.test(accueil) && !accueil.includes("R.421-"),
  "les seuils doivent vivre dans le moteur seul"
);

/* ------------------------------------------------------- le corpus entier */

const ecarts = [];
const regles = [];
const etats = {};
const verdictsJS = [];

for (const c of corpus.cas) {
  const o = qualifyProject(c.donnees);
  verdictsJS.push({ nom: c.nom, status: o.status, rule: o.rule });
  etats[o.status] = (etats[o.status] || 0) + 1;
  if (o.status !== c.attendu) ecarts.push(`${c.nom} → ${o.status} (attendu ${c.attendu})`);
  if (c.rule && o.rule !== c.rule) regles.push(`${c.nom} → ${o.rule} (attendu ${c.rule})`);
}

check(`Les ${corpus.cas.length} cas du corpus rendent l'état attendu`, ecarts.length === 0, ecarts.slice(0, 10).join(" | "));
check("Chaque cas cite l'article qui le fonde", regles.length === 0, regles.slice(0, 8).join(" | "));
check("Aucun état hors des cinq autorisés", Object.keys(etats).every((e) => ETATS.includes(e)));
check("Les cinq états sont atteints par le corpus", ETATS.every((e) => etats[e] > 0),
  "jamais rendus : " + ETATS.filter((e) => !etats[e]).join(", "));

/* ------------------------------------------- équivalence avec le serveur - */

const script = `
require_once "${join(racine, "wordpress/urbizen-platform/src/Forms/QualificationUrbanisme.php")}";
$corpus = json_decode(file_get_contents("${join(ici, "cas.json")}"), true);
$out = [];
foreach ($corpus["cas"] as $c) {
  $o = Urbizen\\Platform\\Forms\\QualificationUrbanisme::qualifier($c["donnees"]);
  $out[] = ["nom" => $c["nom"], "status" => $o["status"], "rule" => $o["rule"]];
}
echo json_encode($out);
`;
const verdictsPHP = JSON.parse(execFileSync("php", ["-r", script], { encoding: "utf8" }));

check("Le serveur a rendu autant de verdicts que le navigateur", verdictsPHP.length === verdictsJS.length);

const divergences = [];
for (let i = 0; i < verdictsJS.length; i++) {
  const a = verdictsJS[i], b = verdictsPHP[i];
  if (a.status !== b.status || a.rule !== b.rule) {
    divergences.push(`${a.nom} : navigateur ${a.status}/${a.rule} ≠ serveur ${b.status}/${b.rule}`);
  }
}
check("Les deux moteurs rendent des verdicts IDENTIQUES sur tout le corpus",
  divergences.length === 0, divergences.slice(0, 10).join(" | "));

console.log("");
console.log("répartition des états : " + Object.entries(etats).map(([e, n]) => `${e}=${n}`).join(" "));
console.log("");
console.log(fail === 0 ? "TOUS LES CONTROLES PASSENT" : `${fail} CONTROLE(S) EN ECHEC`);
process.exit(fail === 0 ? 0 : 1);
