import { chromium } from 'playwright';
import { readFileSync } from 'fs';
const J = readFileSync('../jeton-apercu.txt','utf8').trim();
const nav = await chromium.launch();
const ctx = await nav.newContext({locale:'fr-FR', timezoneId:'Europe/Paris'});
const page = await ctx.newPage();
const req = [];
page.on('request', r => req.push(r.url()));
await page.goto(`https://urbizen.fr/?cmp-apercu=${J}`, {waitUntil:'domcontentloaded', timeout:60000});
await page.waitForTimeout(9000);
const d = await page.evaluate(() => {
  const b = document.querySelector('.cmplz-cookiebanner');
  const ics = window.google_tag_data?.ics;
  const sig = {}; if (ics?.entries) for (const [k,v] of Object.entries(ics.entries)) sig[k] = v.update ?? v.default ?? null;
  return {
    banniere: !!b, visible: b ? getComputedStyle(b).display !== 'none' : false,
    boutons: [...document.querySelectorAll('.cmplz-btn')].map(e => (e.textContent||'').trim().slice(0,28)).filter(Boolean),
    bloques: document.querySelectorAll('script[type="text/plain"]').length,
    services: [...document.querySelectorAll('script[type="text/plain"]')].map(e => e.getAttribute('data-service')||e.getAttribute('data-category')||'?'),
    signaux: sig, icsActive: ics?.active ?? null,
    consentApi: typeof window.wp_consent_type,
  };
});
const ck = await ctx.cookies();
console.log('\n════ SESSION D\'APERÇU — avant tout choix ════\n');
console.log(`  bandeau présent : ${d.banniere}  visible : ${d.visible}`);
console.log(`  boutons : ${d.boutons.join(' | ') || 'aucun'}`);
console.log(`  scripts bloqués : ${d.bloques}  → services : ${[...new Set(d.services)].join(', ') || '-'}`);
console.log('  signaux Consent Mode :');
for (const [k,v] of Object.entries(d.signaux)) console.log(`     ${k.padEnd(20)} ${JSON.stringify(v)}`);
console.log(`  ics.active : ${d.icsActive}`);
console.log(`\n  GA4 gtag : ${req.filter(u=>u.includes('googletagmanager')).length}  collecte : ${req.filter(u=>u.includes('/g/collect')).length}  Chatway : ${req.filter(u=>u.includes('chatway.app')).length}`);
console.log(`  cookies : ${ck.map(c=>c.name).join(', ')}`);
await nav.close();
