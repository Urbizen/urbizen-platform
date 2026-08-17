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

// Largeur de fenêtre, DPR, variante attendue pour les CARTES, variante attendue
// pour l'ILLUSTRATION DE L'ÉTAPE 1, plafond de poids en Ko.
//
// L'attendu vient de la largeur RENDUE mesurée, multipliée par le DPR — pas
// d'une intuition sur la taille de l'écran. À 768 px en DPR 1, une carte occupe
// 337 px CSS, donc 337 pixels réels : la variante 352 est la bonne, et attendre
// 704 était une erreur du banc, corrigée en son temps. Le même 768 px en DPR 2
// demande 674 pixels réels, et bascule bien sur 704.
//
// DEUX FAMILLES DEPUIS LE 16 AOÛT 2026
//
// L'illustration de l'étape 1 n'a pas la largeur d'une carte. Mesurée sur la
// production : 267 px à 1400, 271 à 1200, 278 à 1024, 316 à 390, 286 à 360 —
// et 653 px à 768, parce que la grille du parcours se replie sous 1000 px et
// que le visuel y passe pleine largeur. Un attendu unique pour les sept images
// était donc faux par construction : il exigeait d'un visuel de 653 px la même
// variante que d'une carte de 337.
//
// LES DEUX PLAFONDS DE 768 px
//
// Ils sont plus hauts que leurs voisins, et c'est ce même repli qui l'explique :
// à cette largeur seule, l'étape 1 demande une variante PLUS GRANDE que les
// cartes, donc un fichier de plus. Ailleurs elle partage le leur, et ne coûte
// rien. Valeurs mesurées après optimisation des visuels :
//
//   768 / DPR 1 — cartes en 352 (68,9 Ko) + étape en 704 (52,0 Ko) = 120,9 Ko
//   768 / DPR 2 — cartes en 704 (211,3 Ko) + étape en 960 (101,7 Ko) = 313,0 Ko
//
// Les plafonds sont posés juste au-dessus, à 135 et 330 Ko : assez pour ne pas
// casser sur une variation de compression, trop peu pour laisser passer une
// image non optimisée. Pour mémoire, ces deux cas pesaient 421,9 Ko avant ce
// lot, l'étape 1 n'ayant alors aucun `srcset` et téléchargeant toujours le
// fichier de 960 px.
const CAS = [
  [1400, 1, '352', '352', 110],
  [1200, 1, '352', '352', 110],
  [1024, 1, '352', '352', 110],
  [768, 1, '352', '704', 135],
  [768, 2, '704', '960', 330],
  [390, 2, '704', '704', 290],
  [360, 2, '704', '704', 290],
  [360, 1, '352', '352', 110],
];

let echecs = 0;

function check(nom, ok, detail = '') {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
}

const nav = await chromium.launch({ headless: true });

console.log(`\n════ LOT F — IMAGES DE L'ACCUEIL — ${BASE} ════`);

for (const [largeur, dpr, attendue, etapeAttendue, plafond] of CAS) {
  const ctx = await nav.newContext({ viewport: { width: largeur, height: 900 }, deviceScaleFactor: dpr });
  const page = await ctx.newPage();

  /*
   * Les deux répertoires que la section Guides et l'étape 1 peuvent servir.
   * `blog/` était le seul jusqu'au kit visuel v2, qui a déplacé les six cartes
   * vers `seo-guides-v2/` sans toucher à l'illustration de l'étape 1. Filtrer
   * sur le seul `blog/` ne trouvait plus aucune carte — et le banc l'aurait
   * annoncé comme « 0 trouvée » au lieu de mesurer les nouvelles.
   */
  const REPERTOIRES = ['/images/blog/', '/images/seo-guides-v2/'];

  const telecharges = new Map();
  page.on('response', (r) => {
    if (REPERTOIRES.some((d) => r.url().includes(d))) telecharges.set(r.url(), r.status());
  });

  await page.goto(`${BASE}/?nc=${Date.now()}`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(2500);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(1000);

  const mesure = await page.evaluate((repertoires) => {
    const im = [...document.images].filter((i) => repertoires.some((d) => i.src.includes(d)));
    const poids = Object.fromEntries(
      performance.getEntriesByType('resource')
        .filter((r) => repertoires.some((d) => r.name.includes(d)))
        .map((r) => [r.name, r.transferSize])
    );
    /*
     * DEUX FAMILLES, DEUX ATTENDUS.
     *
     * Deux usages cohabitent : les six cartes de la section Guides, toutes de
     * la même largeur, et l'illustration de l'étape 1 du parcours, qui est plus
     * étroite en bureau et pleine largeur dès que la grille se replie. Leur
     * imposer la même variante était une erreur de conception du banc : elles
     * n'occupent pas la même place.
     *
     * On les sépare par leur CONTENEUR et non par leur répertoire. C'est ce qui
     * a permis au kit v2 de déplacer les cartes sans rien casser ici : le
     * conteneur, lui, ne bouge pas.
     */
    const dans = (sel) => im.filter((i) => i.closest(sel));
    const nom = (i) => i.currentSrc.split('/').pop();
    const largeur = (i) => Math.round(i.getBoundingClientRect().width);
    return {
      n: im.length,
      cartes: dans('.blog-preview-media').map(nom),
      cartesLargeur: dans('.blog-preview-media').map(largeur)[0] ?? null,
      etape: dans('.etape-visuel').map(nom),
      etapeLargeur: dans('.etape-visuel').map(largeur)[0] ?? null,
      horsFamille: im.filter((i) => !i.closest('.blog-preview-media') && !i.closest('.etape-visuel')).map(nom),
      choisies: im.map(nom),
      sansDim: im.filter((i) => !i.getAttribute('width') || !i.getAttribute('height')).length,
      sansSrcset: im.filter((i) => !i.getAttribute('srcset')).length,
      cassees: im.filter((i) => i.naturalWidth === 0).length,
      octets: Object.values(poids).reduce((s, v) => s + v, 0),
      logoLoading: (() => {
        const l = [...document.images].find((x) => x.src.includes('logo-urbizen'));
        return l ? l.getAttribute('loading') : null;
      })(),
    };
  }, REPERTOIRES);

  /*
   * LE CLS SE MESURE TROIS FOIS, ET ON RETIENT LA MÉDIANE.
   *
   * Une mesure unique rendait ce contrôle instable. Constaté le 16 août 2026 :
   * pendant un tour complet, deux cas sur huit ont relevé un CLS de **2,003**
   * là où toutes les autres passes donnaient 0,003 — soit exactement 2,000 de
   * plus, la signature d'un décalage unique et massif.
   *
   * Non reproduit en 35 tentatives : 15 passes à froid sur les trois largeurs
   * concernées, 12 passes en relevant les éléments responsables, 8 passes sous
   * charge artificielle. La suite relancée seule repasse au vert. Le site ne
   * décale donc pas ; c'est la mesure qui a hoqueté.
   *
   * La cause exacte n'est pas établie, et je préfère l'écrire que la deviner.
   * Ce qui est établi, c'est qu'un événement isolé suffisait à faire échouer un
   * tour complet — un banc qui échoue au hasard finit par être ignoré, et c'est
   * pire qu'un banc absent.
   *
   * LE SEUIL NE BOUGE PAS. Il reste à 0,05, la borne « bon » de Google. Seule
   * la façon de l'atteindre change : trois relevés, on garde celui du milieu.
   * Un vrai décalage se produit à chaque chargement et survit donc à la
   * médiane ; un hoquet isolé, non.
   */
  const mesurerCls = () => page.evaluate(() => new Promise((r) => {
    let v = 0;
    try {
      new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) v += e.value; })
        .observe({ type: 'layout-shift', buffered: true });
    } catch { /* non supporté */ }
    setTimeout(() => r(Math.round(v * 1000) / 1000), 700);
  }));

  const relevesCls = [];
  for (let i = 0; i < 3; i++) {
    relevesCls.push(await mesurerCls());
  }
  relevesCls.sort((a, b) => a - b);
  const cls = relevesCls[1];

  const ko = (mesure.octets / 1024).toFixed(1);
  console.log(`\n── ${largeur} px · DPR ${dpr}`);
  console.log(`           variantes choisies : ${[...new Set(mesure.choisies.map((c) => (c.match(/-(\d+)\.webp$/) || [null, '960'])[1]))].join(', ')} px`);
  // Les trois relevés sont affichés : un écart entre eux se voit alors dans
  // la trace, au lieu d'être masqué par la médiane.
  console.log(`           ${mesure.n} image(s) · ${ko} Ko · CLS ${cls} (relevés ${relevesCls.join(' / ')})`);

  check(`les six cartes de guides sont présentes`, mesure.cartes.length === 6, `${mesure.cartes.length} trouvée(s)`);
  check(`l'illustration de l'étape 1 est présente`, mesure.etape.length === 1, `${mesure.etape.length} trouvée(s)`);
  // Toute image de `/images/blog/` doit appartenir à l'une des deux familles :
  // une troisième apparaîtrait sans attendu, et passerait sans être mesurée.
  check(`aucune image hors des deux familles`, mesure.horsFamille.length === 0, mesure.horsFamille.join(', '));
  check(`aucune image cassée`, mesure.cassees === 0, `${mesure.cassees} sans pixels`);
  check(`toutes portent width et height`, mesure.sansDim === 0, `${mesure.sansDim} sans dimensions`);
  check(`toutes portent un srcset`, mesure.sansSrcset === 0, `${mesure.sansSrcset} sans srcset`);
  check(`cartes — variante ${attendue} px`,
    mesure.cartes.length > 0 && mesure.cartes.every((c) => c.includes(`-${attendue}.webp`)),
    `rendues à ${mesure.cartesLargeur} px : ${mesure.cartes.join(', ')}`);
  // `960` est le fichier de base, sans suffixe : c'est la plus grande variante.
  check(`étape 1 — variante ${etapeAttendue} px`,
    mesure.etape.length > 0 && mesure.etape.every((c) =>
      '960' === etapeAttendue ? /^extension-maison\.webp$/.test(c) : c.includes(`-${etapeAttendue}.webp`)),
    `rendue à ${mesure.etapeLargeur} px : ${mesure.etape.join(', ')}`);
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
