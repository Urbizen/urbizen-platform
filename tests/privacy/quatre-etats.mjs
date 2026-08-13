/**
 * Les quatre états du consentement, relevés dans la session d'aperçu.
 *
 * Pour chacun : WP Consent API, Consent Mode, cookies, stockage, requêtes GA4
 * et Chatway, puis persistance du choix après rechargement.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'fs';

const J = readFileSync('../jeton-apercu.txt', 'utf8').trim();
const CIBLE = `https://urbizen.fr/?cmp-apercu=${J}`;
const SEL = { banniere: '.cmplz-cookiebanner', accepter: '.cmplz-accept', refuser: '.cmplz-deny', gerer: '.cmplz-manage-consent' };

const nav = await chromium.launch();

async function releve(page, ctx, req) {
  const d = await page.evaluate(() => {
    const ics = window.google_tag_data?.ics;
    const sig = {};
    if (ics?.entries) for (const [k, v] of Object.entries(ics.entries)) sig[k] = v.update ?? v.default ?? null;
    const lire = (s) => { const o = {}; for (let i = 0; i < s.length; i++) o[s.key(i)] = 1; return Object.keys(o); };
    return {
      signaux: sig,
      wpConsent: window.wp_consent_type ?? null,
      consentApi: (() => { try { return document.cookie.match(/wp_consent_[a-z]+=[a-z]+/g) || []; } catch { return []; } })(),
      local: lire(localStorage), session: lire(sessionStorage),
      banniereVisible: (() => { const e = document.querySelector('.cmplz-cookiebanner'); return e ? getComputedStyle(e).display !== 'none' : false; })(),
      chatwayDom: document.querySelectorAll('[id*="chatway" i], [class*="chatway" i]').length,
      bloques: document.querySelectorAll('script[type="text/plain"]').length,
    };
  });
  const ck = (await ctx.cookies()).map((c) => c.name);
  const ga4 = req.filter((u) => u.includes('/g/collect'));
  const gcs = ga4.map((u) => new URL(u).searchParams.get('gcs') || 'absent');
  return {
    ...d, cookies: ck,
    ga4gtag: req.filter((u) => u.includes('googletagmanager')).length,
    ga4collect: ga4.length, gcs,
    chatway: req.filter((u) => u.includes('chatway.app')).length,
    adsense: req.filter((u) => u.includes('googlesyndication')).length,
  };
}

function afficher(titre, r) {
  console.log(`\n══════ ${titre} ══════`);
  console.log('  Consent Mode :');
  for (const k of ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization']) {
    const v = r.signaux[k];
    console.log(`     ${k.padEnd(20)} ${v === true ? 'granted' : v === false ? 'denied' : JSON.stringify(v)}`);
  }
  console.log(`  WP Consent API   : ${r.consentApi.length ? r.consentApi.join(', ') : 'aucun cookie wp_consent'}`);
  console.log(`  cookies          : ${r.cookies.filter((c) => c !== 'urbizen_cmp_apercu').join(', ') || 'aucun (hors jeton d\'aperçu)'}`);
  console.log(`  localStorage     : ${r.local.join(', ') || 'vide'}`);
  console.log(`  sessionStorage   : ${r.session.join(', ') || 'vide'}`);
  console.log(`  GA4              : ${r.ga4gtag} gtag · ${r.ga4collect} collecte(s) · gcs=${r.gcs.join(',') || '-'}`);
  console.log(`  Chatway          : ${r.chatway} requête(s) · ${r.chatwayDom} élément(s) DOM · ${r.bloques} script(s) retenu(s)`);
  console.log(`  AdSense          : ${r.adsense} requête(s)`);
  console.log(`  bandeau visible  : ${r.banniereVisible}`);
}

async function ouvrir() {
  const ctx = await nav.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  const page = await ctx.newPage();
  const req = [];
  page.on('request', (r) => req.push(r.url()));
  return { ctx, page, req };
}

// ---- 1 · première visite vierge -----------------------------------------
{
  const { ctx, page, req } = await ouvrir();
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(8000);
  afficher('1 · PREMIÈRE VISITE — aucun choix', await releve(page, ctx, req));
  await ctx.close();
}

// ---- 2 · refus total -----------------------------------------------------
{
  const { ctx, page, req } = await ouvrir();
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3500);
  await page.locator(SEL.refuser).first().click();
  await page.waitForTimeout(6000);
  afficher('2 · REFUS TOTAL', await releve(page, ctx, req));
  req.length = 0;
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(7000);
  afficher('2 bis · REFUS — après rechargement', await releve(page, ctx, req));
  await ctx.close();
}

// ---- 3 · acceptation -----------------------------------------------------
{
  const { ctx, page, req } = await ouvrir();
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3500);
  await page.locator(SEL.accepter).first().click();
  await page.waitForTimeout(7000);
  afficher('3 · ACCEPTATION', await releve(page, ctx, req));
  req.length = 0;
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(8000);
  afficher('3 bis · ACCEPTATION — après rechargement', await releve(page, ctx, req));
  await ctx.close();
}

// ---- 4 · retrait après acceptation ---------------------------------------
{
  const { ctx, page, req } = await ouvrir();
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3500);
  await page.locator(SEL.accepter).first().click();
  await page.waitForTimeout(5000);
  const gerer = page.locator(SEL.gerer).first();
  const n = await gerer.count();
  console.log(`\n  lien permanent « Gérer le consentement » : ${n ? 'présent' : 'ABSENT'}`);
  if (n) {
    await gerer.click({ force: true });
    await page.waitForTimeout(2500);
    await page.locator(SEL.refuser).first().click();
    await page.waitForTimeout(6000);
    afficher('4 · RETRAIT APRÈS ACCEPTATION', await releve(page, ctx, req));
    req.length = 0;
    await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(7000);
    afficher('4 bis · RETRAIT — après rechargement', await releve(page, ctx, req));
  }
  await ctx.close();
}

await nav.close();
