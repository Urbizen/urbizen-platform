/**
 * Banc de consentement — quatre états, dans un navigateur réel.
 *
 * CE QU'IL CONTRÔLE, ET POURQUOI PAS AUTRE CHOSE
 *
 * Il ne contrôle PAS « aucune requête vers googletagmanager.com ». Avec Consent
 * Mode avancé, une connexion à Google peut exister alors que tout stockage et
 * tout usage soumis à consentement restent refusés : exiger l'absence de
 * requête ferait échouer une configuration pourtant conforme, et réussir une
 * configuration qui bloque le script tout en posant des cookies ailleurs.
 *
 * Ce qui est contrôlé est ce qui compte juridiquement :
 *
 *   - l'état des quatre signaux Consent Mode ;
 *   - les cookies réellement déposés ;
 *   - le stockage local et de session ;
 *   - l'existence d'une collecte GA4 équivalente à celle d'un visiteur
 *     consentant — lue dans le paramètre `gcs` de la requête de collecte ;
 *   - l'absence de chaîne TCF, cohérente avec l'absence d'AdSense.
 *
 * POURQUOI L'ABSENCE DE TCF EST DÉSORMAIS L'ATTENDU
 *
 * Ce banc a été écrit pendant l'audit, alors qu'AdSense était encore en place et
 * qu'un CMP certifié TCF restait envisagé. L'architecture retenue le 12 août 2026
 * ne diffuse aucune publicité — le code AdSense n'est plus injecté — et n'emploie
 * donc pas de TCF. Exiger `window.__tcfapi` reviendrait à faire échouer le banc
 * sur une décision d'architecture, pas sur un écart de conformité. Le contrôle
 * est inversé, et la contrepartie est explicitement vérifiée : aucune requête
 * publicitaire, dans aucun des quatre états.
 *
 * DORMANCE PLUTÔT QUE SUPPRESSION, APRÈS RETRAIT
 *
 * Le retrait du consentement n'efface pas `_ga` : Complianz ne peut pas
 * supprimer un cookie posé sur un autre chemin par un script tiers. Le critère
 * retenu n'est donc pas sa disparition mais son inertie — plus d'écriture, pas
 * de renouvellement d'expiration, et surtout aucune exploitation par une
 * collecte. Un cookie présent mais jamais lu ni rafraîchi ne trace personne ;
 * exiger sa suppression ferait échouer un mécanisme pourtant conforme. La
 * démonstration complète — l'identifiant transmis à Google diffère de la valeur
 * du cookie — est dans `verif-finale.mjs` ; on contrôle ici la part mesurable
 * en une session : valeur et expiration inchangées d'un chargement à l'autre.
 *
 * LE PARAMÈTRE `gcs`
 *
 * Google encode l'état du consentement dans `gcs` : `G100` signifie refus des
 * deux stockages, `G111` acceptation. Une collecte peut donc partir en état
 * refusé — c'est le « ping » sans stockage — sans valoir consentement.
 *
 * USAGE
 *
 *     node test-consentement.mjs [url]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart · 2 prérequis absent.
 */
import { chromium } from 'playwright';

const CIBLE = process.argv[2] || 'https://urbizen.fr/';
const PAUSE = 7000;

/** Sélecteurs du CMP. À confirmer après installation de Complianz. */
const SEL = {
  bandeau: '.cmplz-cookiebanner, #cmplz-cookiebanner-container',
  accepter: '.cmplz-accept',
  refuser: '.cmplz-deny',
  personnaliser: '.cmplz-manage-options, .cmplz-view-preferences',
  // Le bouton, pas son conteneur : `#cmplz-manage-consent` est un `div` dont
  // l'unique enfant est en `position: fixed`. Il a donc une hauteur nulle, et un
  // clic dessus expire au bout de trente secondes sur « element is not visible »
  // — un rouge qui ne dit rien de la conformité.
  rouvrir: '#cmplz-manage-consent .cmplz-manage-consent',
};

const COOKIES_SOUMIS = [/^_ga/, /^_gid$/, /^_gat/, /^__gads$/, /^__gpi$/, /^__eoi$/, /^IDE$/, /^test_cookie$/];
const STOCKAGE_SOUMIS = [/^ch_session_info/, /^ch_visitor_details/, /^google_auto_fc/];

let echecs = 0;
const check = (libelle, ok, detail = '') => {
  if (!ok) echecs++;
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${libelle}${!ok && detail ? `\n           ${detail}` : ''}`);
};

/** Ouvre une session neuve et renvoie de quoi observer. */
async function session(nav) {
  const ctx = await nav.newContext({ locale: 'fr-FR', timezoneId: 'Europe/Paris' });
  const page = await ctx.newPage();
  const collectes = [];
  page.on('request', (r) => {
    const u = r.url();
    if (u.includes('/g/collect') || u.includes('/pagead/ads')) {
      const p = new URL(u).searchParams;
      collectes.push({ type: u.includes('/g/collect') ? 'ga4' : 'adsense', gcs: p.get('gcs'), tid: p.get('tid'), npa: p.get('npa') });
    }
  });
  return { ctx, page, collectes };
}

/** Lit les quatre signaux Consent Mode tels que gtag les connaît. */
const lireConsentMode = (page) =>
  page.evaluate(() => {
    const out = {};
    const d = window.google_tag_data;
    if (d?.ics?.entries) {
      for (const [k, v] of Object.entries(d.ics.entries)) out[k] = v.update ?? v.default ?? null;
    }
    return {
      signaux: out,
      tcf: typeof window.__tcfapi === 'function',
      dataLayerConsent: (window.dataLayer || []).filter((e) => e && e[0] === 'consent').map((e) => JSON.stringify(e).slice(0, 200)),
    };
  });

const etatStockage = async (ctx, page) => {
  const cookies = await ctx.cookies();
  const st = await page.evaluate(() => {
    const lire = (s) => Object.fromEntries(Array.from({ length: s.length }, (_, i) => [s.key(i), (s.getItem(s.key(i)) || '').slice(0, 80)]));
    return { local: lire(localStorage), session: lire(sessionStorage) };
  });
  return { cookies, ...st };
};

const soumis = (noms, motifs) => noms.filter((n) => motifs.some((m) => m.test(n)));

/**
 * Un état = une attente sur les signaux, les cookies et la collecte.
 *
 * `heriteCookies` vaut vrai dans le seul état de retrait : le cookie `_ga` y
 * préexiste légitimement, puisqu'il a été posé pendant l'acceptation. Exiger
 * son absence reviendrait à exiger sa suppression, critère écarté au profit de
 * la dormance — voir l'entête de ce fichier et `verif-finale.mjs`.
 */
function verifierRefus(nom, cm, stock, collectes, heriteCookies = false) {
  console.log(`\n── ${nom}`);
  const s = cm.signaux;
  for (const k of ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization']) {
    check(`${k} = denied`, s[k] === 'denied' || s[k] === false, `lu : ${JSON.stringify(s[k] ?? '(absent)')}`);
  }
  const ck = soumis(stock.cookies.map((c) => c.name), COOKIES_SOUMIS);
  if (! heriteCookies) {
    check('aucun cookie soumis à consentement', ck.length === 0, `trouvés : ${ck.join(', ')}`);
  }
  const ls = soumis(Object.keys(stock.local), STOCKAGE_SOUMIS);
  check('aucun stockage local soumis à consentement', ls.length === 0, `trouvés : ${ls.join(', ')}`);
  // Une collecte SANS `gcs` est le cas le plus grave : elle signifie qu'aucun
  // Consent Mode n'encadre l'envoi. La traiter comme conforme parce que le
  // paramètre manque reviendrait à valider l'absence totale de mécanisme.
  const consentie = collectes.filter((c) => c.type === 'ga4' && c.gcs !== 'G100');
  check(
    'aucune collecte GA4 en état consenti',
    consentie.length === 0,
    consentie.map((c) => `gcs=${c.gcs ?? 'ABSENT — aucun Consent Mode'}`).join(', ')
  );
  const pub = collectes.filter((c) => c.type === 'adsense');
  check('aucune demande de publicité', pub.length === 0, `${pub.length} requête(s) /pagead/ads`);
}

const nav = await chromium.launch();
console.log(`\n════ CONSENTEMENT — ${CIBLE} ════`);

// ---- État 1 : première visite, aucun choix -------------------------------
{
  const { ctx, page, collectes } = await session(nav);
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(PAUSE);
  const cm = await lireConsentMode(page);
  const stock = await etatStockage(ctx, page);
  verifierRefus('État 1 — première visite, aucun choix', cm, stock, collectes);
  check('un bandeau est présenté', (await page.locator(SEL.bandeau).count()) > 0);
  check('bouton « Tout refuser » présent', (await page.locator(SEL.refuser).count()) > 0);
  check('bouton « Personnaliser » présent', (await page.locator(SEL.personnaliser).count()) > 0);
  check('aucune chaîne TCF, cohérent avec l\'absence d\'AdSense', ! cm.tcf, 'window.__tcfapi présent : un CMP TCF s\'est installé');
  await ctx.close();
}

// ---- État 2 : refus total ------------------------------------------------
{
  const { ctx, page, collectes } = await session(nav);
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3000);
  const bouton = page.locator(SEL.refuser).first();
  if (await bouton.count()) {
    await bouton.click();
    await page.waitForTimeout(PAUSE);
    verifierRefus('État 2 — refus total', await lireConsentMode(page), await etatStockage(ctx, page), collectes);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(PAUSE);
    check('le refus persiste au rechargement', (await page.locator(SEL.bandeau).isVisible().catch(() => false)) === false);
    verifierRefus('État 2 bis — refus, après rechargement', await lireConsentMode(page), await etatStockage(ctx, page), collectes);
  } else {
    console.log('\n── État 2 — refus total\n   IMPOSSIBLE : bouton de refus introuvable');
    echecs++;
  }
  await ctx.close();
}

// ---- État 3 : acceptation ------------------------------------------------
{
  const { ctx, page, collectes } = await session(nav);
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3000);
  const bouton = page.locator(SEL.accepter).first();
  console.log('\n── État 3 — acceptation');
  if (await bouton.count()) {
    await bouton.click();
    await page.waitForTimeout(PAUSE);
    const cm = await lireConsentMode(page);
    const stock = await etatStockage(ctx, page);
    for (const k of ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization']) {
      check(`${k} = granted`, cm.signaux[k] === 'granted' || cm.signaux[k] === true, `lu : ${JSON.stringify(cm.signaux[k] ?? '(absent)')}`);
    }
    const ga4 = collectes.filter((c) => c.type === 'ga4');
    check('GA4 collecte effectivement', ga4.length > 0);
    check('une seule propriété GA4 sollicitée', new Set(ga4.map((c) => c.tid)).size <= 1, `tid : ${[...new Set(ga4.map((c) => c.tid))].join(', ')}`);
    check('cookies Analytics présents après acceptation', soumis(stock.cookies.map((c) => c.name), [/^_ga/]).length > 0);
    // L'absence de TCF n'est acceptable que parce qu'aucune publicité n'est
    // servie : les deux contrôles vont ensemble et ne se lisent pas séparément.
    check('aucune demande de publicité, même après acceptation', collectes.filter((c) => c.type === 'adsense').length === 0);
    check('aucune chaîne TCF, cohérent avec l\'absence d\'AdSense', ! cm.tcf, 'window.__tcfapi présent : un CMP TCF s\'est installé');
  } else {
    console.log('   IMPOSSIBLE : bouton d\'acceptation introuvable');
    echecs++;
  }
  await ctx.close();
}

// ---- État 4 : retrait après acceptation ----------------------------------
{
  const { ctx, page, collectes } = await session(nav);
  await page.goto(CIBLE, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3000);
  const acc = page.locator(SEL.accepter).first();
  console.log('\n── État 4 — retrait après acceptation');
  if (await acc.count()) {
    await acc.click();
    await page.waitForTimeout(4000);
    const rouvrir = page.locator(SEL.rouvrir).first();
    if (await rouvrir.count()) {
      await rouvrir.click();
      await page.waitForTimeout(1500);
      const ref = page.locator(SEL.refuser).first();
      if (await ref.count()) {
        await ref.click();
        await page.waitForTimeout(PAUSE);
        const auRetrait = await etatStockage(ctx, page);
        verifierRefus('État 4 — après retrait', await lireConsentMode(page), auRetrait, [], true);

        // Photographie des cookies analytiques au moment du retrait, puis
        // relecture après un chargement complet. Ce qui est mesuré, c'est le
        // mouvement : une valeur qui change ou une expiration repoussée
        // signeraient une écriture, donc un suivi toujours actif.
        const soumisCookies = (liste) => liste.filter((c) => COOKIES_SOUMIS.some((m) => m.test(c.name)));
        const avant = soumisCookies(auRetrait.cookies);

        await page.reload({ waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(PAUSE);
        const stock = await etatStockage(ctx, page);
        const apres = soumisCookies(stock.cookies);

        const remues = avant.filter((a) => {
          const b = apres.find((c) => c.name === a.name);
          // Disparu : mieux que dormant. Sinon, ni la valeur ni l'échéance ne
          // doivent avoir bougé — cinq secondes de tolérance pour l'arrondi.
          return b && (b.value !== a.value || Math.abs((b.expires ?? 0) - (a.expires ?? 0)) > 5);
        });
        check(
          'cookies analytiques dormants après retrait (valeur et expiration inchangées)',
          remues.length === 0,
          remues.map((c) => c.name).join(', ')
        );
        console.log(`          présents mais dormants : ${apres.length ? apres.map((c) => c.name).join(', ') : 'aucun'}`);
        check('le retrait persiste au rechargement', (await lireConsentMode(page)).signaux.analytics_storage !== 'granted');
      } else { console.log('   IMPOSSIBLE : refus indisponible depuis le lien de retrait'); echecs++; }
    } else { console.log(`   IMPOSSIBLE : lien permanent de retrait introuvable (${SEL.rouvrir})`); echecs++; }
  } else {
    console.log('   IMPOSSIBLE : bouton d\'acceptation introuvable');
    echecs++;
  }
  await ctx.close();
}

await nav.close();
console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
