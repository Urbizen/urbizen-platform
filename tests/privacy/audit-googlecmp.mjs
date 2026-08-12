/**
 * Faisabilité du CMP Google natif — LECTURE SEULE.
 *
 * Cherche les traces de l'infrastructure « Confidentialité et messages »
 * (Funding Choices) : scripts chargés, objets exposés, et origine réelle de
 * la clé `google_auto_fc_cmp_setting`.
 *
 * Aucun clic, aucune modification.
 */
import { chromium } from 'playwright';

const CIBLE = process.argv[2] || 'https://urbizen.fr/';

const nav = await chromium.launch();
const ctx = await nav.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
const page = await ctx.newPage();

const req = [];
page.on('request', (r) => {
  const u = r.url();
  if (/fundingchoices|googlefc|consent|cmp|tcf/i.test(u)) req.push(`${r.method()} ${u.slice(0, 170)}`);
});

// Piéger l'écriture de la clé pour savoir QUI la pose.
await page.addInitScript(() => {
  window.__tracesFC = [];
  const brut = Storage.prototype.setItem;
  Storage.prototype.setItem = function (k, v) {
    if (/google_auto_fc|fc_cmp|consent|tcf/i.test(k)) {
      window.__tracesFC.push({ cle: k, valeur: String(v).slice(0, 80), pile: (new Error().stack || '').split('\n').slice(1, 5).join(' | ').slice(0, 400) });
    }
    return brut.apply(this, arguments);
  };
});

await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(11000);

const etat = await page.evaluate(() => ({
  googlefc: typeof window.googlefc,
  googlefcCles: window.googlefc ? Object.keys(window.googlefc).slice(0, 20) : [],
  ccpa: window.googlefc?.ccpa ? Object.keys(window.googlefc.ccpa) : [],
  callbackQueue: !!window.googlefc?.callbackQueue,
  showRevocation: typeof window.googlefc?.showRevocationMessage,
  tcfapi: typeof window.__tcfapi,
  gpp: typeof window.__gpp,
  adsbygoogle: typeof window.adsbygoogle,
  gtag: typeof window.gtag,
  ics: window.google_tag_data?.ics ? Object.keys(window.google_tag_data.ics.entries || {}) : null,
  traces: window.__tracesFC || [],
  fcScripts: [...document.querySelectorAll('script[src]')].map((s) => s.src).filter((s) => /fundingchoices|googlefc|adsbygoogle/i.test(s)),
}));

console.log(`\n════ CMP GOOGLE — faisabilité — ${CIBLE} ════\n`);

console.log('── Requêtes liées au consentement');
console.log(req.length ? req.map((r) => `   ${r}`).join('\n') : '   aucune');

console.log('\n── Scripts Google chargés');
console.log(etat.fcScripts.length ? etat.fcScripts.map((s) => `   ${s.slice(0, 150)}`).join('\n') : '   aucun');

console.log('\n── Objets exposés');
console.log(`   window.googlefc            : ${etat.googlefc}`);
if (etat.googlefcCles.length) console.log(`      clés : ${etat.googlefcCles.join(', ')}`);
console.log(`   googlefc.callbackQueue     : ${etat.callbackQueue}`);
console.log(`   googlefc.showRevocationMessage : ${etat.showRevocation}`);
console.log(`   window.__tcfapi            : ${etat.tcfapi}`);
console.log(`   window.__gpp               : ${etat.gpp}`);
console.log(`   window.adsbygoogle         : ${etat.adsbygoogle}`);
console.log(`   window.gtag                : ${etat.gtag}`);
console.log(`   Consent Mode (ics.entries) : ${etat.ics ? etat.ics.join(', ') || '(vide)' : 'absent'}`);

console.log('\n── Origine de la clé google_auto_fc_cmp_setting');
if (!etat.traces.length) console.log('   aucune écriture interceptée pendant l\'observation');
for (const t of etat.traces) {
  console.log(`   ${t.cle} = ${t.valeur}`);
  console.log(`      posée par : ${t.pile}`);
}

const c = await ctx.cookies();
console.log('\n── Cookies liés au consentement');
const pertinents = c.filter((x) => /consent|euconsent|fc|gdpr|tcf/i.test(x.name));
console.log(pertinents.length ? pertinents.map((x) => `   ${x.name} @ ${x.domain}`).join('\n') : '   aucun');

await nav.close();
