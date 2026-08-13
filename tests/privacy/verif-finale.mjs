/**
 * Vérifications finales : dormance de `_ga` après retrait, et preuve
 * fonctionnelle de Chatway.
 *
 * DORMANCE PLUTÔT QUE SUPPRESSION
 *
 * La présence physique d'un cookie ne dit rien. Ce qui compte : plus d'écriture,
 * pas de renouvellement d'expiration, et surtout aucune exploitation par une
 * collecte consentie. On mesure donc l'horodatage d'expiration avant et après
 * navigation, et l'identifiant client réellement transmis à Google.
 */
import { chromium } from 'playwright';
import { readFileSync } from 'fs';

const J = readFileSync('../jeton-apercu.txt', 'utf8').trim();
const CIBLE = `https://urbizen.fr/?cmp-apercu=${J}`;
const AUTRE = `https://urbizen.fr/tarifs/?cmp-apercu=${J}`;
const SEL = { accepter: '.cmplz-accept', refuser: '.cmplz-deny', gerer: '.cmplz-manage-consent' };

const nav = await chromium.launch();
const ctx = await nav.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
const page = await ctx.newPage();

const collectes = [];
const chatway = [];
page.on('request', (r) => {
  const u = r.url();
  if (u.includes('/g/collect')) {
    const p = new globalThis.URL(u).searchParams;
    collectes.push({ gcs: p.get('gcs'), cid: p.get('cid'), tid: p.get('tid'), t: Date.now() });
  }
  if (u.includes('chatway')) chatway.push({ u: u.slice(0, 110), t: Date.now() });
});

const ga = async () => (await ctx.cookies()).filter((c) => c.name.startsWith('_ga'))
  .map((c) => ({ nom: c.name, valeur: c.value.slice(0, 24), expire: Math.round(c.expires) }));

const signaux = () => page.evaluate(() => {
  const ics = window.google_tag_data?.ics; const o = {};
  if (ics?.entries) for (const [k, v] of Object.entries(ics.entries)) o[k] = v.update ?? v.default ?? null;
  return o;
});

const wpConsent = async () => (await ctx.cookies()).filter((c) => c.name.startsWith('wp_consent_'))
  .map((c) => `${c.name.replace('wp_consent_', '')}=${c.value}`).sort();

const etatChatway = () => page.evaluate(() => ({
  retenus: document.querySelectorAll('script[type="text/plain"]').length,
  actifs: [...document.querySelectorAll('script[src*="chatway"]')].filter((s) => s.type !== 'text/plain').length,
  conteneur: !!document.querySelector('#chatway--container, [id*="chatway--"]'),
  bulle: !!document.querySelector('#chatway--bubble, [class*="chatway--bubble"], [id*="chatway"][class*="bubble"]'),
  elements: document.querySelectorAll('[id*="chatway" i], [class*="chatway" i]').length,
  iframes: document.querySelectorAll('iframe[src*="chatway"]').length,
}));

console.log('\n════════ 1 · AVANT CONSENTEMENT ════════');
await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(9000);
let c = await etatChatway();
console.log(`  Chatway : ${chatway.length} requête(s) · ${c.retenus} script(s) retenu(s) · ${c.actifs} actif(s) · conteneur=${c.conteneur} · iframes=${c.iframes}`);
console.log(`  _ga     : ${(await ga()).length === 0 ? 'aucun' : JSON.stringify(await ga())}`);

console.log('\n════════ 2 · ACCEPTATION — fenêtre longue ════════');
chatway.length = 0;
await page.locator(SEL.accepter).first().click();
await page.waitForTimeout(20000);
c = await etatChatway();
console.log(`  après clic (20 s) : ${chatway.length} requête(s) · retenus=${c.retenus} · actifs=${c.actifs} · conteneur=${c.conteneur} · éléments=${c.elements} · iframes=${c.iframes}`);
if (chatway.length) for (const r of chatway.slice(0, 4)) console.log(`     ${r.u}`);

console.log('\n  — après rechargement —');
chatway.length = 0;
await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(18000);
c = await etatChatway();
console.log(`  ${chatway.length} requête(s) · retenus=${c.retenus} · actifs=${c.actifs} · conteneur=${c.conteneur} · éléments=${c.elements} · iframes=${c.iframes}`);
for (const r of chatway.slice(0, 4)) console.log(`     ${r.u}`);
const bulleVisible = await page.evaluate(() => {
  const e = document.querySelector('#chatway--bubble, [id*="chatway"]');
  if (!e) return null;
  const r = e.getBoundingClientRect();
  return { visible: r.width > 0 && r.height > 0, taille: `${Math.round(r.width)}x${Math.round(r.height)}` };
});
console.log(`  widget mesuré : ${JSON.stringify(bulleVisible)}`);
console.log(`  _ga après acceptation : ${JSON.stringify(await ga())}`);

console.log('\n════════ 3 · RETRAIT ════════');
const avantRetrait = await ga();
await page.locator(SEL.gerer).first().click({ force: true });
await page.waitForTimeout(2500);
await page.locator(SEL.refuser).first().click();
await page.waitForTimeout(8000);
const apresRetrait = await ga();
console.log(`  signaux : ${JSON.stringify(await signaux())}`);
console.log(`  WP Consent API : ${(await wpConsent()).join(', ')}`);
console.log(`  _ga : ${apresRetrait.length ? JSON.stringify(apresRetrait) : 'supprimés'}`);

console.log('\n════════ 4 · NAVIGATION APRÈS RETRAIT — dormance ════════');
collectes.length = 0; chatway.length = 0;
await page.goto(AUTRE, { waitUntil: 'domcontentloaded', timeout: 60000 });
await page.waitForTimeout(12000);
const apresNav = await ga();
c = await etatChatway();
console.log(`  signaux : ${JSON.stringify(await signaux())}`);
console.log(`  WP Consent API : ${(await wpConsent()).join(', ')}`);
console.log(`  Chatway : ${chatway.length} requête(s) · retenus=${c.retenus} · actifs=${c.actifs} · conteneur=${c.conteneur}`);
console.log(`  collectes GA4 : ${collectes.length} · gcs=${collectes.map((x) => x.gcs).join(',') || '-'}`);
console.log(`  cid transmis : ${[...new Set(collectes.map((x) => x.cid ?? 'aucun'))].join(', ')}`);
console.log('\n  — expiration renouvelée ? —');
for (const c1 of apresRetrait) {
  const c2 = apresNav.find((x) => x.nom === c1.nom);
  if (!c2) { console.log(`     ${c1.nom} : supprimé entre-temps`); continue; }
  const delta = c2.expire - c1.expire;
  console.log(`     ${c1.nom} : expire ${delta === 0 ? 'INCHANGÉE — dormant' : `PROLONGÉE de ${delta} s — renouvelé`} · valeur ${c1.valeur === c2.valeur ? 'inchangée' : 'RÉÉCRITE'}`);
}

await nav.close();
