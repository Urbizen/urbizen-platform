/**
 * Banc du lot F — images de l'accueil.
 *
 * Contrôle ce que le navigateur télécharge vraiment, et quelle variante il
 * choisit à chaque largeur. Un `srcset` mal calibré passe inaperçu à la lecture
 * du balisage : seule la sélection réelle le révèle.
 *
 *     node tests/seo/test-seo-lot-f.mjs [base]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 */
import { chromium } from 'playwright';

const BASE = (process.argv[2] || 'https://urbizen.fr').replace(/\/$/, '');

// Largeur de fenêtre, DPR, variante attendue, plafond de poids en Ko.
//
// L'attendu vient de la largeur RENDUE mesurée, multipliée par le DPR — pas
// d'une intuition sur la taille de l'écran. À 768 px en DPR 1, l'image occupe
// 337 px CSS, donc 337 pixels réels : la variante 352 est la bonne, et attendre
// 704 était une erreur du banc, corrigée ici. Le même 768 px en DPR 2 demande
// 674 pixels réels, et bascule bien sur 704.
const CAS = [
  [1400, 1, '352', 110],
  [1200, 1, '352', 110],
  [1024, 1, '352', 110],
  [768, 1, '352', 110],
  [768, 2, '704', 290],
  [390, 2, '704', 290],
  [360, 2, '704', 290],
  [360, 1, '352', 110],
];

let echecs = 0;

function check(nom, ok, detail = '') {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
}

const nav = await chromium.launch({ headless: true });

console.log(`\n════ LOT F — IMAGES DE L'ACCUEIL — ${BASE} ════`);

for (const [largeur, dpr, attendue, plafond] of CAS) {
  const ctx = await nav.newContext({ viewport: { width: largeur, height: 900 }, deviceScaleFactor: dpr });
  const page = await ctx.newPage();

  const telecharges = new Map();
  page.on('response', (r) => {
    if (r.url().includes('/images/blog/')) telecharges.set(r.url(), r.status());
  });

  await page.goto(`${BASE}/?nc=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(2500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(1000);

  const mesure = await page.evaluate(() => {
    const im = [...document.images].filter((i) => i.src.includes('/images/blog/'));
    const poids = Object.fromEntries(
      performance.getEntriesByType('resource')
        .filter((r) => r.name.includes('/images/blog/'))
        .map((r) => [r.name, r.transferSize])
    );
    return {
      n: im.length,
      choisies: im.map((i) => i.currentSrc.split('/').pop()),
      sansDim: im.filter((i) => !i.getAttribute('width') || !i.getAttribute('height')).length,
      sansSrcset: im.filter((i) => !i.getAttribute('srcset')).length,
      cassees: im.filter((i) => i.naturalWidth === 0).length,
      octets: Object.values(poids).reduce((s, v) => s + v, 0),
      logoLoading: (() => {
        const l = [...document.images].find((x) => x.src.includes('logo-urbizen'));
        return l ? l.getAttribute('loading') : null;
      })(),
    };
  });

  const cls = await page.evaluate(() => new Promise((r) => {
    let v = 0;
    try {
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) v += e.value; })
        .observe({ type: 'layout-shift', buffered: true });
    } catch { /* non supporté */ }
    setTimeout(() => r(Math.round(v * 1000) / 1000), 700);
  }));

  const ko = (mesure.octets / 1024).toFixed(1);
  console.log(`\n── ${largeur} px · DPR ${dpr}`);
  console.log(`           variantes choisies : ${[...new Set(mesure.choisies.map((c) => (c.match(/-(\d+)\.webp$/) || [null, '960'])[1]))].join(', ')} px`);
  console.log(`           ${mesure.n} image(s) · ${ko} Ko · CLS ${cls}`);

  check(`les six illustrations sont présentes`, mesure.n === 6, `${mesure.n} trouvée(s)`);
  check(`aucune image cassée`, mesure.cassees === 0, `${mesure.cassees} sans pixels`);
  check(`toutes portent width et height`, mesure.sansDim === 0, `${mesure.sansDim} sans dimensions`);
  check(`toutes portent un srcset`, mesure.sansSrcset === 0, `${mesure.sansSrcset} sans srcset`);
  check(`variante ${attendue} px sélectionnée`,
    mesure.choisies.every((c) => c.includes(`-${attendue}.webp`)),
    mesure.choisies.join(', '));
  check(`poids sous ${plafond} Ko`, mesure.octets / 1024 < plafond, `${ko} Ko`);
  check('CLS négligeable', cls < 0.05, `${cls}`);
  check('le logo d\'en-tête n\'est pas différé', mesure.logoLoading !== 'lazy', `loading=${mesure.logoLoading}`);

  await ctx.close();
}

// Le logo : deux règles nées d'une mesure, pas d'une intuition.
//
// Il reste en PNG parce que les conversions l'alourdissaient — 5 145 octets
// contre 16 718 en WebP.
//
// Et il ne porte NI `width` NI `height`. Ils ont été posés le 14 août 2026 puis
// retirés le jour même : la feuille ne fixe que la hauteur, si bien que
// l'attribut `width` servait d'indication de présentation et l'emportait. Le
// logo est passé de 129 × 36 à 430 × 36 en desktop, étiré, en production. Ce
// contrôle interdit le retour de ces attributs, et vérifie surtout ce qui
// compte : la taille rendue.
{
  const TAILLES = [[1400, 1, 129, 36], [390, 2, 115, 32]];
  console.log('\n── Logo');

  for (const [largeur, dpr, l, h] of TAILLES) {
    const ctx = await nav.newContext({ viewport: { width: largeur, height: 900 }, deviceScaleFactor: dpr });
    const page = await ctx.newPage();
    await page.goto(`${BASE}/?nc=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    const logo = await page.evaluate(() => {
      const x = [...document.images].find((i) => i.src.includes('logo-urbizen'));
      if (!x) return null;
      const r = x.getBoundingClientRect();
      return {
        src: x.src,
        w: x.getAttribute('width'),
        h: x.getAttribute('height'),
        rendu: [Math.round(r.width), Math.round(r.height)],
        loading: x.getAttribute('loading'),
      };
    });

    check(`${largeur} px — le logo reste en PNG`, /\.png$/.test(logo?.src || ''), logo?.src);
    check(`${largeur} px — aucun attribut de dimension sur le logo`,
      !logo?.w && !logo?.h, `width=${logo?.w} height=${logo?.h}`);
    check(`${largeur} px — le logo est rendu ${l} × ${h}`,
      logo?.rendu[0] === l && logo?.rendu[1] === h, `rendu ${logo?.rendu?.join(' × ')}`);
    check(`${largeur} px — le logo d'en-tête est chargé sans délai`,
      logo?.loading === 'eager', `loading=${logo?.loading}`);

    await ctx.close();
  }
}

await nav.close();

console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
