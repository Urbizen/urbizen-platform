/**
 * Audit comportemental des traceurs sur urbizen.fr — LECTURE SEULE.
 *
 * Observe ce qu'une première visite déclenche réellement : requêtes sortantes,
 * cookies déposés, stockage local et de session, et données envoyées aux tiers.
 *
 * Aucun clic, aucune soumission, aucune écriture côté site. On regarde.
 */
import { chromium } from 'playwright';

const CIBLE = process.argv[2] || 'https://urbizen.fr/';
const ATTENTE = 9000;

const TIERS = [
  ['googletagmanager.com', 'Google Tag / GA4'],
  ['google-analytics.com', 'Google Analytics (collecte)'],
  ['analytics.google.com', 'Google Analytics'],
  ['googlesyndication.com', 'Google AdSense'],
  ['doubleclick.net', 'DoubleClick'],
  ['googleadservices.com', 'Google Ads'],
  ['chatway.app', 'Chatway'],
  ['optinmonster', 'OptinMonster'],
  ['google.com/ads', 'Google Ads'],
];

const classe = (url) => {
  for (const [motif, nom] of TIERS) if (url.includes(motif)) return nom;
  try { const h = new URL(url).hostname; return h.endsWith('urbizen.fr') ? null : `AUTRE — ${h}`; }
  catch { return null; }
};

const nav = await chromium.launch();
const ctx = await nav.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
const page = await ctx.newPage();

const requetes = [];
page.on('request', (r) => {
  const nom = classe(r.url());
  if (nom) requetes.push({ nom, url: r.url(), methode: r.method(), postData: r.postData()?.slice(0, 300) || null });
});

await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(ATTENTE);

const cookies = await ctx.cookies();
const stockage = await page.evaluate(() => {
  const lire = (s) => { const o = {}; for (let i = 0; i < s.length; i++) { const k = s.key(i); o[k] = (s.getItem(k) || '').slice(0, 120); } return o; };
  return {
    local: lire(localStorage),
    session: lire(sessionStorage),
    dataLayer: (window.dataLayer || []).slice(0, 25).map((e) => { try { return JSON.stringify(e).slice(0, 220); } catch { return String(e); } }),
    aGtag: typeof window.gtag === 'function',
    tcfApi: typeof window.__tcfapi === 'function',
    gppApi: typeof window.__gpp === 'function',
  };
});

const parNom = {};
for (const r of requetes) (parNom[r.nom] ??= []).push(r);

console.log(`\n════ AUDIT — ${CIBLE} — première visite, aucun choix ════\n`);

console.log('── Requêtes tierces');
if (!Object.keys(parNom).length) console.log('   aucune');
for (const [nom, liste] of Object.entries(parNom)) {
  console.log(`   ${nom} : ${liste.length} requête(s)`);
  for (const r of liste.slice(0, 4)) console.log(`      ${r.methode} ${r.url.slice(0, 155)}`);
  const avecDonnees = liste.filter((r) => r.postData);
  for (const r of avecDonnees.slice(0, 2)) console.log(`      ↑ données envoyées : ${r.postData}`);
}

console.log('\n── Cookies déposés');
if (!cookies.length) console.log('   aucun');
for (const c of cookies) {
  const duree = c.expires && c.expires > 0 ? `${Math.round((c.expires * 1000 - Date.now()) / 86400000)} j` : 'session';
  console.log(`   ${c.name.padEnd(28)} domaine=${c.domain.padEnd(22)} durée=${duree}`);
}

console.log('\n── localStorage');
const cles = Object.keys(stockage.local);
console.log(cles.length ? cles.map((k) => `   ${k} = ${stockage.local[k]}`).join('\n') : '   vide');

console.log('\n── sessionStorage');
const clesS = Object.keys(stockage.session);
console.log(clesS.length ? clesS.map((k) => `   ${k} = ${stockage.session[k]}`).join('\n') : '   vide');

console.log('\n── Consent Mode / CMP');
console.log(`   window.gtag      : ${stockage.aGtag ? 'présent' : 'absent'}`);
console.log(`   __tcfapi (TCF)   : ${stockage.tcfApi ? 'présent' : 'absent'}`);
console.log(`   __gpp            : ${stockage.gppApi ? 'présent' : 'absent'}`);
console.log('   dataLayer :');
console.log(stockage.dataLayer.length ? stockage.dataLayer.map((e) => `      ${e}`).join('\n') : '      vide');

await nav.close();
