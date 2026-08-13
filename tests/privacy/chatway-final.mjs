import { chromium } from 'playwright';
import { readFileSync } from 'fs';
const J = readFileSync('../jeton-apercu.txt','utf8').trim();
const C = `https://urbizen.fr/?cmp-apercu=${J}`, A = `https://urbizen.fr/tarifs/?cmp-apercu=${J}`;
const SEL = {acc:'.cmplz-accept', ref:'.cmplz-deny', ger:'.cmplz-manage-consent'};
const nav = await chromium.launch();
const ctx = await nav.newContext({locale:'fr-FR', timezoneId:'Europe/Paris'});
const page = await ctx.newPage();
let cw = [];
page.on('request', r => { if (r.url().includes('chatway')) cw.push(r.url().slice(0,95)); });
const etat = () => page.evaluate(() => ({
  retenus: document.querySelectorAll('script[type="text/plain"][data-cmplz-src*="chatway"]').length,
  actifs: [...document.querySelectorAll('script[src*="chatway"]')].length,
  conteneur: !!document.querySelector('[id*="chatway--"]'),
  elements: document.querySelectorAll('[id*="chatway" i],[class*="chatway" i]').length,
  iframes: document.querySelectorAll('iframe[src*="chatway"]').length,
}));
const stock = () => page.evaluate(() => {
  const f=(s)=>{const o=[];for(let i=0;i<s.length;i++){const k=s.key(i); if(/^ch_|chatway/i.test(k)) o.push(k);} return o;};
  return {local:f(localStorage), session:f(sessionStorage)};
});
const ligne = async (t) => { const e=await etat(), s=await stock();
  console.log(`  ${t}\n     requêtes=${cw.length} retenus=${e.retenus} actifs=${e.actifs} conteneur=${e.conteneur} éléments=${e.elements} iframes=${e.iframes}`);
  console.log(`     stockage Chatway : local=[${s.local}] session=[${s.session}]`); };

console.log('\n════ VIERGE ════'); await page.goto(C,{waitUntil:'domcontentloaded',timeout:60000}); await page.waitForTimeout(10000); await ligne('vierge');
console.log('\n════ REFUS ════'); cw=[]; await page.locator(SEL.ref).first().click(); await page.waitForTimeout(6000);
cw=[]; await page.goto(C,{waitUntil:'domcontentloaded',timeout:60000}); await page.waitForTimeout(12000); await ligne('après refus + reload');
console.log('\n════ ACCEPTATION ════');
const ctx2 = await nav.newContext({locale:'fr-FR'}); const p2 = await ctx2.newPage();
let cw2=[]; p2.on('request', r=>{ if(r.url().includes('chatway')) cw2.push(r.url().slice(0,95)); });
await p2.goto(C,{waitUntil:'domcontentloaded',timeout:60000}); await p2.waitForTimeout(4000);
await p2.locator(SEL.acc).first().click(); await p2.waitForTimeout(30000);
const e2 = await p2.evaluate(() => ({retenus:document.querySelectorAll('script[type="text/plain"][data-cmplz-src*="chatway"]').length, actifs:document.querySelectorAll('script[src*="chatway"]').length, conteneur:!!document.querySelector('[id*="chatway--"]'), elements:document.querySelectorAll('[id*="chatway" i],[class*="chatway" i]').length, iframes:document.querySelectorAll('iframe[src*="chatway"]').length}));
console.log(`  après clic + 30 s\n     requêtes=${cw2.length} retenus=${e2.retenus} actifs=${e2.actifs} conteneur=${e2.conteneur} éléments=${e2.elements} iframes=${e2.iframes}`);
for (const u of cw2.slice(0,4)) console.log(`       ${u}`);
const bulle = await p2.evaluate(() => { const e=document.querySelector('#chatway--bubble, [id*="chatway--"]'); if(!e) return null; const r=e.getBoundingClientRect(); return {taille:`${Math.round(r.width)}x${Math.round(r.height)}`, visible:r.width>0}; });
console.log(`     widget : ${JSON.stringify(bulle)}`);
const s2 = await p2.evaluate(() => { const f=(s)=>{const o=[];for(let i=0;i<s.length;i++){const k=s.key(i); if(/^ch_|chatway/i.test(k)) o.push(k);} return o;}; return {local:f(localStorage), session:f(sessionStorage)}; });
console.log(`     stockage Chatway : local=[${s2.local}] session=[${s2.session}]`);
if (bulle && bulle.visible) { try { await p2.locator('#chatway--bubble, [id*="chatway--bubble"]').first().click({timeout:8000}); await p2.waitForTimeout(6000);
  const ouv = await p2.evaluate(()=>({iframes:document.querySelectorAll('iframe[src*="chatway"]').length, ouvert:!!document.querySelector('[class*="chatway--expanded"], [id*="chatway--frame"]')}));
  console.log(`     après clic sur la bulle : ${JSON.stringify(ouv)}`); } catch(e){ console.log(`     clic impossible : ${String(e).slice(0,70)}`); } }
console.log('\n════ RETRAIT ════');
await p2.locator(SEL.ger).first().click({force:true}); await p2.waitForTimeout(2500);
await p2.locator(SEL.ref).first().click(); await p2.waitForTimeout(6000);
cw2=[]; await p2.goto(A,{waitUntil:'domcontentloaded',timeout:60000}); await p2.waitForTimeout(14000);
const e3 = await p2.evaluate(() => ({retenus:document.querySelectorAll('script[type="text/plain"][data-cmplz-src*="chatway"]').length, actifs:document.querySelectorAll('script[src*="chatway"]').length, conteneur:!!document.querySelector('[id*="chatway--"]'), iframes:document.querySelectorAll('iframe[src*="chatway"]').length}));
const s3 = await p2.evaluate(() => { const f=(s)=>{const o=[];for(let i=0;i<s.length;i++){const k=s.key(i); if(/^ch_|chatway/i.test(k)) o.push(k);} return o;}; return {local:f(localStorage), session:f(sessionStorage)}; });
const wc = (await ctx2.cookies()).filter(c=>c.name.startsWith('wp_consent_')).map(c=>`${c.name.replace('wp_consent_','')}=${c.value}`).sort();
console.log(`  après retrait + navigation\n     requêtes=${cw2.length} retenus=${e3.retenus} actifs=${e3.actifs} conteneur=${e3.conteneur} iframes=${e3.iframes}`);
console.log(`     stockage Chatway : local=[${s3.local}] session=[${s3.session}]`);
console.log(`     WP Consent API : ${wc.join(', ')}`);
await nav.close();
