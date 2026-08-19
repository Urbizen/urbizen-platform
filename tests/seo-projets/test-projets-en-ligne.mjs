/**
 * Volet en ligne du cocon SEO « projets » — 21 URLs.
 *
 * Mesure ce qu'aucune lecture de source ne donne : le code HTTP réellement
 * servi, le canonical, l'indexabilité, les données structurées émises, les
 * images effectivement chargées et les liens qui répondent.
 *
 *     node tests/seo-projets/test-projets-en-ligne.mjs [base]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const BASE = (process.argv[2] || 'https://urbizen.fr').replace(/\/$/, '');

const PAGES = [
  'declaration-prealable-extension-maison',
  'declaration-prealable-piscine',
  'declaration-prealable-abri-de-jardin',
  'declaration-prealable-pergola-carport',
  'declaration-prealable-transformation-garage',
  'declaration-prealable-panneaux-solaires',
  'declaration-prealable-fenetre-de-toit',
  'declaration-prealable-modification-facade',
  'declaration-prealable-cloture-portail',
].map((s) => `/${s}/`);

const GUIDES = [
  'pieces-declaration-prealable',
  'plan-masse-dp2',
  'insertion-graphique-dp6',
  'plan-facades-toitures-dp4',
  'plan-coupe-dp3',
  'secteur-protege-abf-declaration-travaux',
  'emprise-au-sol-surface-de-plancher',
  'distance-limite-separative-construction',
  'recours-architecte-150-m2',
  'demande-pieces-complementaires-urbanisme',
  'refus-declaration-prealable',
  'cerfa-declaration-travaux',
].map((s) => `/guides/${s}/`);

// Ce qui ne doit pas avoir bougé : le hub, la page Tarifs, et les six guides
// publiés au lot précédent.
const NON_REGRESSION = [
  '/', '/declarations-prealables/', '/permis-de-construire/', '/conception/', '/tarifs/', '/guides/',
  '/guides/piscine-garage-carport-autorisation/',
  '/guides/dp-ou-permis-de-construire/',
  '/guides/extension-maison-verifications-avant-plans/',
  '/guides/lire-le-plu-de-son-terrain/',
  '/guides/erreurs-dossier-urbanisme/',
  '/guides/delais-urbanisme-debut-des-travaux/',
];

const TOUTES = [...PAGES, ...GUIDES];

/*
 * TOUS LES GUIDES ONT UNE IMAGE — CE QUI N'A PAS TOUJOURS ÉTÉ VRAI
 *
 * Ce banc portait jusqu'ici une liste de quatre guides devant rester SANS image
 * mise en avant : secteur protégé, architecte 150 m², refus, CERFA. Ce n'était
 * pas un oubli mais une position de `docs/SEO_VISUALS_HANDOFF.md` (kit v1) :
 * faute de visuel honnête disponible, mieux valait pas d'image qu'un visuel
 * décoratif — et surtout pas de faux périmètre ABF, de faux arrêté municipal ni
 * de fausse reproduction de formulaire.
 *
 * Le kit v2 lève la cause, pas la règle. `docs/SEO_VISUALS_V2_HANDOFF.md`
 * fournit un visuel propre à chacun de ces quatre guides, et pose en principe
 * que « les visuels éditoriaux n'imitent pas des documents administratifs
 * officiels ». Le visuel CERFA est une composition de bureau sans texte
 * lisible ni logo ; celui du secteur protégé, une rue de centre ancien
 * explicitement fictive. L'exigence de fond est donc tenue par le kit lui-même.
 *
 * La liste disparaît, mais le contrôle reste bilatéral dans son esprit : les
 * dix-huit guides doivent avoir une image, ET cette image doit être celle que
 * le manifeste leur attribue — vérifié ci-dessous sur le nom de fichier. Un
 * visuel décoratif quelconque échouerait encore, ce qui était tout l'objet de
 * la règle d'origine.
 */
const VISUEL_ATTENDU = Object.fromEntries(
  JSON.parse(readFileSync(new URL('../../docs/SEO_VISUALS_V2_MANIFEST.json', import.meta.url), 'utf8'))
    .assets.map((a) => [a.order, a.file]),
);

// Ordre du manifeste → chemin du guide. La correspondance est explicite : le
// manifeste identifie ses entrées par un titre rédactionnel, pas par un slug.
const ORDRE_GUIDE = {
  '/guides/cerfa-declaration-travaux/': 1,
  '/guides/refus-declaration-prealable/': 2,
  '/guides/demande-pieces-complementaires-urbanisme/': 3,
  '/guides/recours-architecte-150-m2/': 4,
  '/guides/distance-limite-separative-construction/': 5,
  '/guides/emprise-au-sol-surface-de-plancher/': 6,
  '/guides/secteur-protege-abf-declaration-travaux/': 7,
  '/guides/plan-coupe-dp3/': 8,
  '/guides/plan-facades-toitures-dp4/': 9,
  '/guides/insertion-graphique-dp6/': 10,
  '/guides/plan-masse-dp2/': 11,
  '/guides/pieces-declaration-prealable/': 12,
  '/guides/delais-urbanisme-debut-des-travaux/': 13,
  '/guides/erreurs-dossier-urbanisme/': 14,
  '/guides/lire-le-plu-de-son-terrain/': 15,
  '/guides/extension-maison-verifications-avant-plans/': 16,
  '/guides/dp-ou-permis-de-construire/': 17,
  '/guides/piscine-garage-carport-autorisation/': 18,
};

let echecs = 0;
const check = (nom, ok, detail = '') => {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
};

const nc = () => `?nc=${Date.now()}`;
const decoder = (s) => s
  .replace(/&#0?39;/g, "'").replace(/&rsquo;/g, '’').replace(/&amp;/g, '&')
  .replace(/&nbsp;/g, ' ').replace(/&quot;/g, '"').replace(/&laquo;|&raquo;/g, '"');

console.log(`\n════ COCON PROJETS — ${BASE} ════`);

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1440, height: 900 } });

const releves = {};

// ---- 1 · Chaque URL répond, est indexable et se décrit ---------------------
console.log('\n── 1 · Les 21 URLs');
for (const chemin of TOUTES) {
  const page = await ctx.newPage();
  const erreurs = [];
  page.on('pageerror', (e) => erreurs.push(String(e).slice(0, 120)));
  const rep = await page.goto(BASE + chemin + nc(), { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(900);

  const m = await page.evaluate(() => {
    const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content ?? null;
    const og = (p) => document.querySelector(`meta[property="og:${p}"]`)?.content ?? null;
    const types = [...document.querySelectorAll('script[type="application/ld+json"]')]
      .flatMap((s) => { try { return JSON.stringify(JSON.parse(s.textContent)).match(/"@type":"[A-Za-z]+"/g) || []; } catch { return []; } });
    return {
      titre: document.title,
      description: meta('description'),
      robots: meta('robots'),
      canonical: document.querySelector('link[rel="canonical"]')?.href ?? null,
      h1n: document.querySelectorAll('h1').length,
      h1: document.querySelector('h1')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
      ogTitle: og('title'),
      ogImage: og('image'),
      ogDescription: og('description'),
      types: [...new Set(types)].map((t) => t.split('"')[3]),
      fil: !!document.querySelector('.fil-ariane'),
      liensInternes: [...document.querySelectorAll('main a[href^="/"]')].map((a) => a.getAttribute('href')),
      imgsCassees: [...document.images].filter((i) => i.naturalWidth === 0 && !i.loading?.includes('lazy')).length,
      faq: document.querySelectorAll('.projet-faq details').length,
      erreursJs: 0,
    };
  });
  m.erreursJs = erreurs.length;
  releves[chemin] = m;

  const nom = chemin.replace(/^\/|\/$/g, '');
  check(`${nom} · 200`, rep.status() === 200, `code ${rep.status()}`);
  check(`${nom} · indexable`, !/noindex/i.test(m.robots ?? ''), `robots ${m.robots}`);
  check(`${nom} · canonical autonome`, m.canonical === `${BASE}${chemin}`, `${m.canonical}`);
  check(`${nom} · un seul H1`, m.h1n === 1, `${m.h1n}`);
  check(`${nom} · title et description présents`, !!m.titre && !!m.description);
  check(`${nom} · Open Graph title et description`, !!m.ogTitle && !!m.ogDescription);
  check(`${nom} · image mise en avant et og:image`, !!m.ogImage);

  // Pour un guide, l'image doit être celle que le manifeste lui attribue. Le
  // nom de fichier suffit à le dire : WordPress le conserve dans l'URL de la
  // médiathèque, à un suffixe `-1`, `-2`… près en cas de collision.
  const ordre = ORDRE_GUIDE[chemin];
  if (ordre) {
    const radical = VISUEL_ATTENDU[ordre].replace(/\.webp$/, '');
    const attendu = new RegExp(`/${radical}(-\\d+)?(-\\d+x\\d+)?\\.webp$`);
    check(`${nom} · le visuel est celui du manifeste (${radical})`,
      attendu.test(m.ogImage ?? ''), `og:image = ${m.ogImage}`);
  }
  check(`${nom} · fil d'ariane`, m.fil);
  check(`${nom} · BreadcrumbList`, m.types.includes('BreadcrumbList'), m.types.join(', '));
  check(`${nom} · aucune erreur JavaScript`, m.erreursJs === 0);
  await page.close();
}

// ---- 2 · Unicité des métadonnées ------------------------------------------
console.log('\n── 2 · Unicité');
for (const champ of ['titre', 'description', 'canonical']) {
  const vus = new Map();
  for (const [u, r] of Object.entries(releves)) {
    const v = decoder(String(r[champ] ?? ''));
    if (!vus.has(v)) vus.set(v, []);
    vus.get(v).push(u);
  }
  const doublons = [...vus.entries()].filter(([, us]) => us.length > 1);
  check(`${champ} unique sur les 21 URLs`, doublons.length === 0,
    doublons.map(([v, us]) => `« ${v.slice(0, 40)} » : ${us.join(', ')}`).join(' | '));
}

// Le H1 doit différer du title : deux signaux, deux formulations.
const h1EgalTitle = Object.entries(releves)
  .filter(([, r]) => decoder(r.h1) === decoder(r.titre ?? '')).map(([u]) => u);
check('aucun H1 strictement identique à son title', h1EgalTitle.length === 0, h1EgalTitle.join(', '));

// ---- 3 · Balisage propre à chaque type ------------------------------------
console.log('\n── 3 · Données structurées');
for (const chemin of PAGES) {
  const r = releves[chemin];
  const nom = chemin.replace(/\//g, '');
  check(`${nom} · FAQ visible dans le DOM`, r.faq >= 4, `${r.faq} question(s)`);
}
for (const chemin of GUIDES) {
  const r = releves[chemin];
  const nom = chemin.replace(/^\/guides\/|\/$/g, '');
  check(`guide ${nom} · balisage d'article`,
    r.types.some((t) => ['Article', 'BlogPosting', 'NewsArticle'].includes(t)), r.types.join(', '));
}

// ---- 4 · Aucun lien interne mort ------------------------------------------
console.log('\n── 4 · Liens internes');
const aTester = new Set();
for (const r of Object.values(releves)) for (const l of r.liensInternes) if (l.startsWith('/')) aTester.add(l);
let morts = [];
for (const l of aTester) {
  const rep = await ctx.request.head(BASE + l).catch(() => null);
  const code = rep ? rep.status() : 0;
  if (code !== 200) morts.push(`${l} (${code})`);
}
check(`aucun lien interne mort (${aTester.size} destinations)`, morts.length === 0, morts.slice(0, 6).join(', '));

// ---- 5 · Images responsives -----------------------------------------------
console.log('\n── 5 · Images');
for (const chemin of [...PAGES.slice(0, 3), GUIDES[0]]) {
  const page = await ctx.newPage();
  await page.goto(BASE + chemin + nc(), { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(800);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(1500);
  const m = await page.evaluate(() => {
    const im = [...document.images].filter((i) => /\/(seo-projects|dossier|uploads)\//.test(i.src));
    return {
      n: im.length,
      cassees: im.filter((i) => i.naturalWidth === 0).map((i) => i.src.split('/').pop()),
      sansDim: im.filter((i) => !i.getAttribute('width') || !i.getAttribute('height')).length,
      photosSansSrcset: im.filter((i) => i.src.includes('/seo-projects/') && !i.getAttribute('srcset')).length,
    };
  });
  const nom = chemin.replace(/\//g, '') || 'accueil';
  check(`${nom} · aucune image cassée`, m.cassees.length === 0, m.cassees.join(', '));
  check(`${nom} · toutes portent width et height`, m.sansDim === 0, `${m.sansDim}`);
  check(`${nom} · les photographies portent un srcset`, m.photosSansSrcset === 0, `${m.photosSansSrcset}`);
  await page.close();
}

// ---- 6 · Plan de site ------------------------------------------------------
console.log('\n── 6 · Plan de site');
{
  const index = await ctx.request.get(`${BASE}/sitemap.xml${nc()}`).then((r) => r.text());
  const plans = [...index.matchAll(/<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/loc>/gs)].map((m) => m[1].trim());
  const urls = [];
  for (const p of plans) {
    const t = await ctx.request.get(p + nc()).then((r) => r.text());
    urls.push(...[...t.matchAll(/<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/loc>/gs)].map((m) => m[1].trim()));
  }
  const absentes = TOUTES.filter((c) => !urls.some((u) => u === `${BASE}${c}`));
  check(`les 21 URLs figurent au plan de site`, absentes.length === 0, absentes.join(', '));
  check('le plan de site ne liste aucune archive de catégorie', !urls.some((u) => u.includes('/category/')));
}

// ---- 7 · Aucune régression sur l'existant ---------------------------------
console.log('\n── 7 · Non-régression');
for (const chemin of NON_REGRESSION) {
  const rep = await ctx.request.get(BASE + chemin + nc());
  check(`${chemin} · toujours 200`, rep.status() === 200, `code ${rep.status()}`);
}
{
  // Le HERO validé de l'accueil ne doit pas avoir bougé.
  const page = await ctx.newPage();
  await page.goto(`${BASE}/${nc()}`, { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(900);
  const h1 = await page.evaluate(() => document.querySelector('#hero-title')?.textContent.replace(/\s+/g, ' ').trim() ?? '');
  check('accueil · le HERO validé est intact',
    /Vos travaux commencent par les bonnes démarches\./u.test(decoder(h1)), h1);
  const tarifs = await page.evaluate(() => document.querySelectorAll('main a[href*="/tarifs/"]').length);
  check('accueil · le lien vers /tarifs/ est conservé', tarifs >= 1, `${tarifs}`);
  await page.close();
}

await ctx.close();
await nav.close();

console.log(`\n${echecs === 0 ? 'TOUS LES CONTROLES PASSENT' : echecs + ' CONTROLE(S) EN ECHEC'}`);
process.exit(echecs === 0 ? 0 : 1);
