/**
 * Banc du lot B — assainissement de l'index.
 *
 * Il ne vérifie pas seulement que les URL retirées ont disparu : il vérifie
 * aussi, et surtout, que **rien d'utile n'est parti avec**. Un assainissement
 * réussi se reconnaît autant à ce qu'il conserve qu'à ce qu'il retire.
 *
 *     node tests/seo/test-seo-lot-b.mjs [base]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 */
const BASE = (process.argv[2] || 'https://urbizen.fr').replace(/\/$/, '');

let echecs = 0;

function check(nom, ok, detail = '') {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
}

const anticache = (c = '') => `${c.includes('?') ? '&' : '?'}nc=${Math.floor(Date.now() / 1000)}`;

async function lire(chemin) {
  const r = await fetch(BASE + chemin + anticache(chemin), { redirect: 'manual' });
  const html = await r.text();
  const m = html.match(/<meta name="robots" content="(.*?)"/i);
  return {
    code: r.status,
    vers: r.headers.get('location'),
    html,
    robots: m ? m[1] : null,
    indexable: r.status === 200 && !(m && /noindex/i.test(m[1])),
    title: (html.match(/<title>(.*?)<\/title>/is) || [null, null])[1],
  };
}

console.log(`\n════ LOT B — ${BASE} ════`);

// ---- 1 · Ce qui doit avoir disparu ---------------------------------------
{
  console.log('\n── 1 · URL retirées');
  const parties = [
    ['/shop/', 'page WooCommerce'],
    ['/cart/', 'page WooCommerce'],
    ['/checkout/', 'page WooCommerce'],
    ['/my-account/', 'page WooCommerce'],
    ['/espace-professionnels/', 'page héritée'],
    ['/hello-world/', 'article de démonstration'],
    ['/category/uncategorized/', 'ancien slug de la catégorie par défaut'],
  ];
  for (const [chemin, quoi] of parties) {
    const r = await lire(chemin);
    check(`${chemin} ne répond plus 200 (${quoi})`, r.code !== 200, `code ${r.code}`);
    check(`${chemin} ne redirige pas`, !(r.code >= 300 && r.code < 400), `→ ${r.vers}`);
  }
}

// ---- 2 · Ce qui reste servi mais hors index ------------------------------
{
  console.log('\n── 2 · URL conservées, hors index');
  for (const chemin of [
    '/autres-projets/',
    '/formulaire-declaration-prealable/',
    '/formulaire-permis-de-construire/',
    '/formulaire-conception/',
  ]) {
    const r = await lire(chemin);
    check(`${chemin} reste accessible`, r.code === 200, `code ${r.code}`);
    check(`${chemin} est en noindex`, r.code === 200 && !r.indexable, `robots : ${r.robots ?? '(absent)'}`);
    // `noindex, follow` : AIOSEO n'écrit pas `follow`, qui est la valeur par
    // défaut des moteurs. Ce qui se contrôle est donc l'absence de `nofollow` —
    // les liens portés par ces pages doivent rester suivis.
    check(`${chemin} laisse suivre ses liens`, !/nofollow/i.test(r.robots || ''), `robots : ${r.robots}`);
  }

  // L'ANCIEN PARCOURS DE COMMANDE NE SE SERT PLUS : IL REDIRIGE
  //
  // Ce banc exigeait ici que `/commander-un-dossier/` réponde 200 en portant le
  // formulaire n° 6, contrat du lot SEO B. Le lot « parcours de formulaires » l'a
  // retiré : la page redirige désormais vers le tunnel de l'accueil, et c'est le
  // comportement voulu. Laisser l'ancienne attente en place ne protégeait plus
  // rien — elle réclamait le retour d'un parcours abandonné, et maintenait ce
  // banc au rouge en permanence, ce qui aurait masqué la prochaine vraie
  // régression SEO.
  //
  // Le formulaire n° 6 n'est pas supprimé pour autant : il reste en base, et
  // retirer le hook `urbizen_child_rediriger_ancien_parcours` remettrait la page
  // en ligne telle quelle. Ce qui se contrôle ici est donc la redirection, pas
  // la disparition. `tests/homepage/test-parcours-legacy.php` tient l'autre bout
  // de la garde, côté sources.
  {
    const r = await lire('/commander-un-dossier/');
    check('/commander-un-dossier/ redirige en 301', r.code === 301, `code ${r.code}`);
    check('/commander-un-dossier/ mène au tunnel de l’accueil',
      /\/#localisation$/.test(r.vers || ''), `→ ${r.vers ?? '(aucune destination)'}`);
  }

  // Les archives de date ne sont pas contrôlées sur un code précis, et c'est
  // délibéré. Tant qu'aucun article n'est publié, WordPress les sert en 404 :
  // elles n'ont rien à lister. Le jour où le blog existera, elles répondront
  // 200 et devront alors être en noindex — c'est le réglage AIOSEO qui s'en
  // charge. Exiger 200 aujourd'hui ferait échouer le banc sur un site
  // parfaitement propre ; exiger 404 le ferait échouer dès la première
  // publication. Ce qui compte dans les deux cas est qu'elles ne soient jamais
  // indexables.
  // La catégorie par défaut, vide. AIOSEO annonce un réglage `noIndexEmptyCat`
  // qui ne fait rien dans la version 5.0.0.1 — l'option n'est lue nulle part.
  // C'est le filtre `aioseo_robots_meta` du thème qui tient cette règle.
  //
  // CORRIGÉ LE 15 AOÛT 2026 — le contrôle contredisait son propre commentaire.
  //
  // Il exigeait `r.code === 200`, alors que les quatre lignes ci-dessus disent
  // exactement l'inverse : 200 comme 404 conviennent, seule l'indexabilité
  // compte. Il interrogeait de surcroît `/category/…`, une base que la bascule
  // des permaliens a déplacée sous `/guides/category/…`. Mesuré : l'ancienne
  // adresse répond 404 (donc non indexable, ce qui est bien), la nouvelle
  // répond 200 avec `noindex` — et le contrôle échouait sur la première sans
  // jamais regarder la seconde.
  //
  // On vérifie donc les deux adresses, sur le seul critère qui vaut.
  for (const chemin of ['/category/non-classe/', '/guides/category/non-classe/']) {
    const r = await lire(chemin);
    check(`la catégorie par défaut n'est pas indexable — ${chemin}`, !r.indexable,
      `code ${r.code}, robots ${r.robots ?? '(absent)'}`);
  }

  for (const chemin of ['/2026/', '/2026/05/']) {
    const r = await lire(chemin);
    check(`${chemin} n'est pas indexable`, !r.indexable,
      `code ${r.code}, robots ${r.robots ?? '(absent)'}`);
    console.log(`           ${chemin} : code ${r.code}${r.code === 404 ? ' — aucun article publié, rien à lister' : ''}`);
  }
}

// ---- 3 · Ce qui doit rester intact --------------------------------------
{
  console.log('\n── 3 · Pages qui doivent rester indexables');
  for (const chemin of [
    '/',
    '/tarifs/',
    '/declarations-prealables/',
    '/permis-de-construire/',
    '/conception/',
    '/contact/',
    '/mentions-legales/',
    '/conditions-generales-de-vente/',
    '/politique-de-confidentialite/',
  ]) {
    const r = await lire(chemin);
    check(`${chemin} répond 200 et reste indexable`, r.indexable, `code ${r.code}, robots ${r.robots ?? '(absent)'}`);
  }

  // Le raccrochage de /contact/ au site vivant : c'est la contrepartie de sa
  // conservation. Sans lien entrant, la garder n'aurait servi à rien.
  const accueil = await lire('/');
  check('/contact/ est lié depuis le pied de page vivant',
    /href="[^"]*\/contact\/"/.test(accueil.html), 'aucun lien vers /contact/ sur l\'accueil');

  // Le formulaire de renseignements doit survivre à l'assainissement.
  check('le formulaire de renseignements est toujours rendu sur l\'accueil',
    /data-form_id="5"/.test(accueil.html));
  const contact = await lire('/contact/');
  check('le formulaire de renseignements est toujours rendu sur /contact/',
    /data-form_id="5"/.test(contact.html));
}

// ---- 4 · Effets du titre de site ----------------------------------------
{
  console.log('\n── 4 · Titre de site');
  const pages = ['/', '/tarifs/', '/declarations-prealables/', '/permis-de-construire/', '/conception/', '/contact/'];
  for (const chemin of pages) {
    const r = await lire(chemin);
    check(`${chemin} — title sans séparateur orphelin`, !/[-–—|]\s*$/.test(r.title || ''), `lu : ${r.title}`);
    check(`${chemin} — title non vide et plausible`, (r.title || '').trim().length >= 8, `lu : ${r.title}`);
  }

  const { html } = await lire('/');
  const blocs = [...html.matchAll(/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/gis)].map((m) => m[1]);
  check('un seul bloc JSON-LD', blocs.length === 1, `${blocs.length} bloc(s)`);
  if (blocs.length) {
    const graphe = JSON.parse(blocs[0])['@graph'] || [];
    const site = graphe.find((n) => n['@type'] === 'WebSite');
    const orga = graphe.find((n) => n['@type'] === 'Organization');
    check('WebSite.name vaut « Urbizen »', site?.name === 'Urbizen', `lu : ${JSON.stringify(site?.name)}`);
    check('Organization.name vaut « Urbizen »', orga?.name === 'Urbizen', `lu : ${JSON.stringify(orga?.name)}`);
    // Le défaut d'origine : le nom reprenait le slogan faute de titre de site.
    check('Organization.name n\'est plus le slogan',
      !/tranquillit/i.test(orga?.name || ''), `lu : ${JSON.stringify(orga?.name)}`);
  }
}

// ---- 5 · Plan de site ----------------------------------------------------
{
  console.log('\n── 5 · Plan de site');
  const index = await fetch(`${BASE}/sitemap.xml${anticache()}`).then((r) => r.text());
  const plans = [...index.matchAll(/<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/loc>/gs)].map((m) => m[1].trim());
  const urls = [];
  for (const p of plans) {
    urls.push(...[...(await fetch(p + anticache(p)).then((r) => r.text()))
      .matchAll(/<loc>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*<\/loc>/gs)].map((m) => m[1].trim()));
  }

  for (const absent of ['/shop/', '/cart/', '/checkout/', '/my-account/', '/hello-world/',
    '/espace-professionnels/', '/autres-projets/', '/commander-un-dossier/', '/category/',
    '/author/', '/2026/', '/formulaire-declaration-prealable/',
    '/formulaire-permis-de-construire/', '/formulaire-conception/']) {
    check(`plan de site sans ${absent}`, !urls.some((u) => u.includes(absent)),
      urls.filter((u) => u.includes(absent)).join(', '));
  }
  for (const present of ['/tarifs/', '/declarations-prealables/', '/permis-de-construire/',
    '/conception/', '/contact/', '/mentions-legales/']) {
    check(`plan de site avec ${present}`, urls.some((u) => u.includes(present)));
  }
  console.log(`           plan de site : ${urls.length} URL`);
}

// ---- 6 · Aucun lien mort dans le périmètre indexable ---------------------
{
  console.log('\n── 6 · Liens internes');
  const aVisiter = ['/', '/tarifs/', '/declarations-prealables/', '/permis-de-construire/',
    '/conception/', '/contact/', '/mentions-legales/', '/conditions-generales-de-vente/',
    '/politique-de-confidentialite/'];
  const cibles = new Set();
  for (const chemin of aVisiter) {
    const { html } = await lire(chemin);
    // Le bandeau de consentement est écarté : ses liens sont des commandes.
    const corps = html.replace(/<div id="cmplz-cookiebanner-container"[\s\S]*?<\/div>\s*$/i, '');
    for (const m of corps.matchAll(/href="(https:\/\/urbizen\.fr[^"#?]*|\/[^"#?]*)"/g)) {
      const u = m[1].startsWith('http') ? m[1].replace('https://urbizen.fr', '') : m[1];
      if (!/\.(png|jpe?g|webp|svg|css|js|xml|ico)$/i.test(u)) cibles.add(u);
    }
  }
  const morts = [];
  for (const c of [...cibles].sort()) {
    const r = await fetch(BASE + c + anticache(c), { redirect: 'manual' });
    if (r.status >= 400) morts.push(`${c} → ${r.status}`);
  }
  check('aucun lien interne mort depuis les pages indexables', morts.length === 0, morts.join(' · '));
  console.log(`           ${cibles.size} destinations internes contrôlées`);
}

console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
