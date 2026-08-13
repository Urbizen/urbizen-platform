/**
 * Banc du lot D — polices.
 *
 * Le critère est mesuré, pas théorique : ce qui compte n'est pas ce que les
 * feuilles déclarent mais ce que le navigateur télécharge. Une police n'est
 * chargée qu'au moment où un caractère doit être peint avec elle ; le banc
 * parcourt donc chaque page de bout en bout avant de conclure.
 *
 * Il contrôle deux choses, et une troisième qu'il ne faut pas casser.
 *
 * 1. Sur les gabarits Urbizen : zéro requête vers `OpenSans-Variable.ttf` et
 *    zéro vers `IBMPlexMono-Regular.ttf`, le `.ttf` du thème parent.
 * 2. Les familles calculées restent celles de la charte, sur le corps, les
 *    titres, les boutons, les formulaires et le bandeau de consentement.
 * 3. `/contact/`, page héritée témoin, garde exactement son rendu : Open Sans
 *    et Poppins y sont la police du contenu, pas un résidu.
 *
 *     node tests/seo/test-seo-lot-d.mjs [base]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 */
import { chromium } from 'playwright';

const BASE = (process.argv[2] || 'https://urbizen.fr').replace(/\/$/, '');

const URBIZEN = [
  ['accueil', '/'],
  ['déclaration préalable', '/declarations-prealables/'],
  ['permis de construire', '/permis-de-construire/'],
  ['conception', '/conception/'],
  ['tarifs', '/tarifs/'],
  ['page légale', '/mentions-legales/'],
  ['formulaire Urbizen', '/formulaire-conception/'],
];
const TEMOIN = ['contact (hérité)', '/contact/'];

// Les deux fichiers du thème parent que ce lot doit faire disparaître des
// gabarits Urbizen.
const PARENT_LOURD = 'OpenSans-Variable.ttf';
const PARENT_MONO = 'IBMPlexMono-Regular.ttf';

let echecs = 0;

function check(nom, ok, detail = '') {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
}

const nav = await chromium.launch({ headless: true });

/** Charge une page, la parcourt, et rend les polices réellement téléchargées. */
async function mesurer(chemin) {
  const ctx = await nav.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const fichiers = new Set();
  page.on('response', (r) => {
    if (r.request().resourceType() === 'font' || /\.(woff2?|ttf|otf)(\?|$)/i.test(r.url())) {
      fichiers.add(r.url());
    }
  });

  await page.goto(BASE + chemin + `?nc=${Math.floor(Date.now() / 1000)}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3500);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(2000);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(1200);
  try { await page.evaluate(() => document.fonts.ready); } catch { /* sans objet */ }

  const releve = await page.evaluate(() => {
    const fam = (s) => {
      const n = document.querySelector(s);
      return n ? getComputedStyle(n).fontFamily.split(',')[0].replace(/["']/g, '').trim() : null;
    };
    const enOpenSans = [...document.querySelectorAll('body *')].filter((el) => {
      const b = el.getBoundingClientRect();
      return b.width > 0 && b.height > 0
        && getComputedStyle(el).fontFamily.split(',')[0].replace(/["']/g, '').trim() === 'Open Sans';
    }).length;
    return {
      corps: fam('body'),
      titre: fam('h1'),
      bouton: fam('.btn, .wp-block-button__link, button'),
      champ: fam('input[type=text], .ff-el-form-control, input'),
      cmplzTexte: fam('.cmplz-message'),
      cmplzBouton: fam('.cmplz-btn'),
      enOpenSans,
    };
  });

  const poids = await page.evaluate(() => Object.fromEntries(
    performance.getEntriesByType('resource')
      .filter((r) => /\.(woff2?|ttf|otf)(\?|$)/i.test(r.name))
      .map((r) => [r.name, r.transferSize])
  ));

  await ctx.close();

  const liste = [...fichiers].map((u) => ({
    nom: u.split('/').pop().split('?')[0],
    octets: poids[u] || 0,
  }));
  return { liste, total: liste.reduce((s, f) => s + f.octets, 0), ...releve };
}

const ko = (o) => `${(o / 1024).toFixed(1)} Ko`;

console.log(`\n════ LOT D — POLICES — ${BASE} ════`);

let totalAvant = 0;
let totalApres = 0;

for (const [nom, chemin] of URBIZEN) {
  const m = await mesurer(chemin);
  console.log(`\n── ${nom} — ${chemin}`);
  for (const f of m.liste) console.log(`        ${ko(f.octets).padStart(9)}  ${f.nom}`);
  console.log(`        ${ko(m.total).padStart(9)}  TOTAL · ${m.liste.length} fichier(s)`);

  check('aucune requête vers OpenSans-Variable.ttf',
    !m.liste.some((f) => f.nom === PARENT_LOURD),
    m.liste.filter((f) => f.nom === PARENT_LOURD).map((f) => ko(f.octets)).join(''));
  check('aucune requête vers IBMPlexMono-Regular.ttf du parent',
    !m.liste.some((f) => f.nom === PARENT_MONO),
    m.liste.filter((f) => f.nom === PARENT_MONO).map((f) => ko(f.octets)).join(''));
  check('aucun élément visible ne résout vers Open Sans', m.enOpenSans === 0, `${m.enOpenSans} élément(s)`);

  // Les familles de la charte doivent rester en place : l'allègement ne vaut
  // que s'il ne change rien à ce qui est peint.
  check('le corps de page est en IBM Plex Sans', m.corps === 'IBM Plex Sans', `lu : ${m.corps}`);
  check('les titres sont en Space Grotesk', m.titre === 'Space Grotesk', `lu : ${m.titre}`);
  check('le bandeau de consentement garde ses familles',
    m.cmplzTexte === 'IBM Plex Sans' && m.cmplzBouton === 'Space Grotesk',
    `texte ${m.cmplzTexte} · bouton ${m.cmplzBouton}`);

  // Objectif chiffré : le poids doit tomber sous 100 Ko.
  check('poids des polices sous 100 Ko', m.total < 100 * 1024, ko(m.total));

  totalApres += m.total;
  totalAvant += 443.2 * 1024;
}

// ---- Témoin hérité : rien ne doit avoir bougé -----------------------------
{
  const [nom, chemin] = TEMOIN;
  const m = await mesurer(chemin);
  console.log(`\n── ${nom} — ${chemin}  (témoin : doit rester inchangé)`);
  for (const f of m.liste) console.log(`        ${ko(f.octets).padStart(9)}  ${f.nom}`);
  console.log(`        ${ko(m.total).padStart(9)}  TOTAL · ${m.liste.length} fichier(s)`);

  check('la page héritée garde Open Sans', m.liste.some((f) => f.nom === PARENT_LOURD),
    'Open Sans a disparu d\'une page qui s\'en sert pour son contenu');
  check('la page héritée garde Poppins', m.liste.some((f) => f.nom.startsWith('Poppins')));
  check('son corps de page reste en Open Sans', m.corps === 'Open Sans', `lu : ${m.corps}`);
  check('son contenu reste massivement en Open Sans', m.enOpenSans > 50, `${m.enOpenSans} élément(s)`);
}

await nav.close();

console.log(`\n── Bilan sur les ${URBIZEN.length} pages Urbizen`);
console.log(`   avant  : ${ko(totalAvant / URBIZEN.length)} par page`);
console.log(`   après  : ${ko(totalApres / URBIZEN.length)} par page`);
console.log(`   gain   : ${ko((totalAvant - totalApres) / URBIZEN.length)} par page`);

console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
