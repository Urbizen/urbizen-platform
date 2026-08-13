/**
 * Banc de non-régression des deux P0 de l'audit SEO du 13 août 2026.
 *
 * POURQUOI UN BANC EN LIGNE ET NON UN BANC STATIQUE
 *
 * Les deux défauts vivent dans la base, pas dans le dépôt : un prix écrit dans
 * une ligne d'AIOSEO, une identité écrite dans `wp_users`. Aucun contrôle sur
 * les fichiers ne pourrait les voir. Ce banc interroge donc le site tel qu'il
 * est servi — c'est la seule surface où le défaut est observable, et c'est
 * aussi celle qui compte.
 *
 * Le contrôle statique complémentaire — la présence du filtre qui supprime les
 * archives d'auteur dans le thème — est dans `test-seo-p0.php`.
 *
 * USAGE
 *
 *     node tests/seo/test-seo-p0.mjs [base]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 */
const BASE = (process.argv[2] || 'https://urbizen.fr').replace(/\/$/, '');
const COURRIEL = 'contact.urbizen@gmail.com';
const ANCIEN_SLUG = 'contact-urbizengmail-com';

let echecs = 0;

function check(nom, ok, detail = '') {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
}

// Le séparateur dépend de la présence d'une chaîne de requête : concaténer un
// « ? » à un chemin qui en a déjà un produit `/?author=1?nc=…`, que le serveur
// interprète autrement — le contrôle passerait alors pour une mauvaise raison.
const anticache = (chemin = '') => `${chemin.includes('?') ? '&' : '?'}nc=${Math.floor(Date.now() / 1000)}`;

async function lire(chemin, { suivre = true } = {}) {
  const r = await fetch(BASE + chemin + anticache(chemin), { redirect: suivre ? 'follow' : 'manual' });
  return { code: r.status, vers: r.headers.get('location'), html: await r.text(), url: r.url };
}

function balise(html, motif) {
  const m = html.match(motif);
  return m ? m[1].trim() : null;
}

console.log(`\n════ P0 SEO — ${BASE} ════`);

// ---- P0.1 : aucun prix dans les métadonnées de la déclaration préalable ----
{
  console.log('\n── P0.1 — page Déclaration préalable');
  const { code, html } = await lire('/declarations-prealables/');
  check('la page répond 200', code === 200, `code ${code}`);

  const meta = {
    title: balise(html, /<title>(.*?)<\/title>/is),
    description: balise(html, /<meta name="description" content="(.*?)"/is),
    'og:title': balise(html, /<meta property="og:title" content="(.*?)"/is),
    'og:description': balise(html, /<meta property="og:description" content="(.*?)"/is),
    'twitter:title': balise(html, /<meta name="twitter:title" content="(.*?)"/is),
    'twitter:description': balise(html, /<meta name="twitter:description" content="(.*?)"/is),
  };

  for (const [nom, valeur] of Object.entries(meta)) {
    check(`${nom} est renseignée`, !!valeur, 'balise absente');
    if (valeur) {
      // 149, 149€, 149 € — et toute autre écriture d'un montant dans une
      // métadonnée. Un prix dans un title est une promesse qui vieillit ; la
      // règle retenue est qu'il n'y en ait aucun, pas qu'il soit à jour.
      check(`${nom} ne contient aucun prix`, !/\d+\s*(€|&euro;|euros)/i.test(valeur), `lu : ${valeur}`);
    }
  }

  check('title sans séparateur orphelin en fin', !/[-–—|]\s*$/.test(meta.title || ''), `lu : ${meta.title}`);
  check('aucune balise dynamique AIOSEO non résolue', !/#(post_title|site_title|separator_sa|tagline)/.test(html),
    'une métadonnée contient encore une balise #…');

  // Le JSON-LD reprend le titre et la description : il doit être propre aussi.
  const ld = [...html.matchAll(/<script[^>]*application\/ld\+json[^>]*>(.*?)<\/script>/gis)].map((m) => m[1]);
  check('JSON-LD présent', ld.length > 0);
  const ldTexte = ld.join(' ');
  check('JSON-LD sans prix hérité', !/\d+\s*(€|\\u20ac|euros)/i.test(ldTexte),
    (ldTexte.match(/.{40}\d+\s*(€|\\u20ac).{20}/i) || [''])[0]);

  // Filet large : plus aucune occurrence de « 149 » suivie d'un signe monétaire
  // nulle part dans le HTML servi.
  const partout = [...html.matchAll(/149\s*(€|&euro;|\\u20ac|euros)/gi)];
  check('aucun « 149 € » dans tout le HTML servi', partout.length === 0, `${partout.length} occurrence(s)`);
}

// ---- P0.2 : plus d'adresse de courriel exposée -----------------------------
{
  console.log('\n── P0.2 — identité publique de l\'autrice');

  const archive = await lire(`/author/${ANCIEN_SLUG}/`, { suivre: false });
  check('l\'ancienne archive d\'auteur ne répond plus 200', archive.code !== 200, `code ${archive.code}`);
  check('et ne redirige pas', !(archive.code >= 300 && archive.code < 400),
    `code ${archive.code} → ${archive.vers}`);

  const nouvelle = await lire('/author/anais-bacarisse/', { suivre: false });
  check('la nouvelle archive d\'auteur n\'est pas non plus servie', nouvelle.code !== 200, `code ${nouvelle.code}`);

  // Énumération d'auteur par identifiant : ne doit plus mener nulle part.
  const parId = await lire('/?author=1', { suivre: false });
  check('?author=1 ne redirige pas vers une archive', !(parId.code >= 300 && parId.code < 400 && /\/author\//.test(parId.vers || '')),
    `code ${parId.code} → ${parId.vers}`);

  // L'API REST publie le nom affiché et le slug : c'est une sortie publique.
  const rest = await fetch(`${BASE}/wp-json/wp/v2/users${anticache()}`);
  if (rest.status === 200) {
    const brut = await rest.text();
    check('API REST sans adresse de courriel', !brut.includes(COURRIEL),
      'l\'adresse figure dans /wp-json/wp/v2/users');
    check('API REST sans ancien slug', !brut.includes(ANCIEN_SLUG), 'l\'ancien slug figure dans l\'API REST');
  } else {
    console.log(`   NOTE   API REST des utilisateurs : code ${rest.status}, contrôle sans objet`);
  }

  // Sortie publique des pages qui pourraient porter un nom d'auteur.
  for (const chemin of ['/', '/hello-world/', '/category/uncategorized/', '/declarations-prealables/']) {
    const { code, html } = await lire(chemin);
    if (code !== 200) {
      console.log(`   NOTE   ${chemin} : code ${code}, contrôle sans objet`);
      continue;
    }
    check(`${chemin} sans adresse de courriel`, !html.includes(COURRIEL));
    check(`${chemin} sans lien vers une archive d'auteur`, !/href="[^"]*\/author\//.test(html));
  }

  // Plan de site : aucune archive d'auteur annoncée.
  const plan = await fetch(`${BASE}/sitemap.xml${anticache()}`).then((r) => r.text());
  check('plan de site sans plan d\'auteur', !/author-sitemap/.test(plan));
  const pages = await fetch(`${BASE}/page-sitemap.xml${anticache()}`).then((r) => r.text());
  check('plan de site des pages sans URL d\'auteur', !/\/author\//.test(pages));
  check('plan de site sans adresse de courriel', !plan.includes(COURRIEL) && !pages.includes(COURRIEL));
}

console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
