/**
 * Audit ciblé de Chatway — LECTURE SEULE.
 *
 * Objectif : déterminer sa catégorie de consentement à partir de son
 * comportement réel, et non de ce qu'un chat est censé faire.
 *
 * On isole Chatway en coupant les autres tiers, puis on observe longuement :
 * requêtes, données transmises, cookies, localStorage et sessionStorage,
 * y compris ceux posés dans ses propres iframes.
 */
import { chromium } from 'playwright';

const ATTENTE = 20000;
const AUTRES_TIERS = ['googletagmanager', 'google-analytics', 'googlesyndication', 'adtrafficquality', 'doubleclick', 'googleadservices'];

const nav = await chromium.launch();
const ctx = await nav.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
const page = await ctx.newPage();

// Couper les autres tiers pour n'observer que Chatway.
await page.route('**/*', (route) => {
  const u = route.request().url();
  if (AUTRES_TIERS.some((t) => u.includes(t))) return route.abort();
  return route.continue();
});

const req = [];
page.on('request', (r) => { if (r.url().includes('chatway')) req.push({ m: r.method(), u: r.url(), d: r.postData()?.slice(0, 400) || null }); });
const rep = [];
page.on('response', (r) => { if (r.url().includes('chatway')) rep.push({ s: r.status(), u: r.url(), ct: r.headers()['content-type'] || '', sc: r.headers()['set-cookie'] || null }); });

await page.goto('https://urbizen.fr/', { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(ATTENTE);

console.log('\n════ CHATWAY — comportement réel, sans interaction ════\n');

console.log(`── Requêtes vers chatway (${req.length})`);
for (const r of req) {
  console.log(`   ${r.m} ${r.u.slice(0, 150)}`);
  if (r.d) console.log(`      ↑ données : ${r.d}`);
}

console.log(`\n── Réponses chatway (${rep.length})`);
for (const r of rep) {
  console.log(`   ${r.s} ${r.ct.split(';')[0].padEnd(26)} ${r.u.slice(0, 110)}`);
  if (r.sc) console.log(`      Set-Cookie : ${r.sc.slice(0, 200)}`);
}

const cookies = await ctx.cookies();
console.log('\n── Cookies (tous domaines, tiers coupés)');
console.log(cookies.length ? cookies.map((c) => `   ${c.name} @ ${c.domain} (${c.expires > 0 ? Math.round((c.expires * 1000 - Date.now()) / 86400000) + ' j' : 'session'})`).join('\n') : '   aucun');

// Stockage dans la page principale ET dans chaque iframe Chatway.
const contextes = [{ nom: 'page principale', f: page.mainFrame() }];
for (const f of page.frames()) if (f !== page.mainFrame()) contextes.push({ nom: `iframe ${f.url().slice(0, 70)}`, f });

for (const { nom, f } of contextes) {
  try {
    const st = await f.evaluate(() => {
      const lire = (s) => { const o = {}; for (let i = 0; i < s.length; i++) { const k = s.key(i); o[k] = (s.getItem(k) || '').slice(0, 140); } return o; };
      return { l: lire(localStorage), s: lire(sessionStorage) };
    });
    const kl = Object.keys(st.l), ks = Object.keys(st.s);
    if (kl.length || ks.length) {
      console.log(`\n── Stockage — ${nom}`);
      for (const k of kl) console.log(`   local   ${k} = ${st.l[k]}`);
      for (const k of ks) console.log(`   session ${k} = ${st.s[k]}`);
    }
  } catch { /* iframe cross-origin : inaccessible, c'est attendu */ }
}

const iframes = page.frames().filter((f) => f.url().includes('chatway'));
console.log(`\n── Iframes Chatway : ${iframes.length}`);
for (const f of iframes) console.log(`   ${f.url().slice(0, 130)}`);

console.log(`\n── Widget visible dans la page : ${await page.locator('[id*="chatway" i], [class*="chatway" i]').count()} élément(s)`);

await nav.close();
