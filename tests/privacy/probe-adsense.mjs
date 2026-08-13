import { chromium } from 'playwright';
const nav = await chromium.launch();
const page = await (await nav.newContext({locale:'fr-FR', timezoneId:'Europe/Paris'})).newPage();
const ads = [];
page.on('response', async r => {
  const u = r.url();
  if (u.includes('/pagead/ads')) {
    let taille = 0; try { taille = (await r.body()).length; } catch {}
    ads.push({ statut: r.status(), taille, type: (r.headers()['content-type']||'').split(';')[0] });
  }
});
await page.goto('https://urbizen.fr/', {waitUntil:'domcontentloaded', timeout:60000});
await page.waitForTimeout(12000);
const dom = await page.evaluate(() => ({
  ins: document.querySelectorAll('ins.adsbygoogle').length,
  remplis: [...document.querySelectorAll('ins.adsbygoogle')].filter(e => e.getAttribute('data-ad-status') === 'filled').length,
  statuts: [...document.querySelectorAll('ins.adsbygoogle')].map(e => e.getAttribute('data-ad-status') || '(aucun)'),
  iframesAds: document.querySelectorAll('iframe[id^="aswift"], iframe[name^="aswift"]').length,
  autoAds: !!document.querySelector('script[src*="adsbygoogle"][data-ad-client]') || /enable_page_level_ads|auto ads/i.test(document.documentElement.innerHTML),
}));
console.log('── Réponses /pagead/ads');
console.log(ads.length ? ads.map(a=>`   ${a.statut}  ${a.taille} octets  ${a.type}`).join('\n') : '   aucune');
console.log('── Emplacements publicitaires dans la page');
console.log(`   <ins class="adsbygoogle"> : ${dom.ins}   remplis : ${dom.remplis}`);
console.log(`   statuts : ${dom.statuts.join(', ') || 'aucun'}`);
console.log(`   iframes d'annonce (aswift) : ${dom.iframesAds}`);
console.log(`   annonces automatiques déclarées : ${dom.autoAds}`);
await nav.close();
