/**
 * Banc du lot E — données structurées servies.
 *
 * Le volet « futur article » est ailleurs : `test-seo-lot-e-article.php`, qui
 * s'exécute sur le serveur parce que le nœud `Person` n'existe que sur un
 * article, et qu'aucun n'est publié.
 *
 *     node tests/seo/test-seo-lot-e.mjs [base]
 *
 * Codes de sortie : 0 conforme · 1 au moins un écart.
 */
const BASE = (process.argv[2] || 'https://urbizen.fr').replace(/\/$/, '');

const PAGES = [
  ['accueil', '/'],
  ['déclaration préalable', '/declarations-prealables/'],
  ['permis de construire', '/permis-de-construire/'],
  ['conception', '/conception/'],
  ['tarifs', '/tarifs/'],
  ['contact', '/contact/'],
  ['page légale', '/mentions-legales/'],
];

const SUIVI = ['mibextid', 'rdid', 'share_url', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign'];

let echecs = 0;

function check(nom, ok, detail = '') {
  console.log(`   ${ok ? 'OK   ' : 'ECHEC'}  ${nom}`);
  if (!ok && detail) console.log(`           ${detail}`);
  if (!ok) echecs++;
}

const anticache = (c = '') => `${c.includes('?') ? '&' : '?'}nc=${Math.floor(Date.now() / 1000)}`;

async function graphe(chemin) {
  const r = await fetch(BASE + chemin + anticache(chemin));
  const html = await r.text();
  const blocs = [...html.matchAll(/<script[^>]*application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)].map((m) => m[1]);
  const noeuds = [];
  for (const b of blocs) {
    const d = JSON.parse(b);
    noeuds.push(...(d['@graph'] || [d]));
  }
  return { blocs, noeuds, html };
}

console.log(`\n════ LOT E — DONNÉES STRUCTURÉES — ${BASE} ════`);

const toutesUrls = new Map();

for (const [nom, chemin] of PAGES) {
  console.log(`\n── ${nom} — ${chemin}`);
  const { blocs, noeuds } = await graphe(chemin);

  // Un seul émetteur : c'était vrai avant le lot, cela doit le rester.
  check('un seul bloc JSON-LD', blocs.length === 1, `${blocs.length} bloc(s)`);

  const types = noeuds.map((n) => (Array.isArray(n['@type']) ? n['@type'].join(',') : n['@type']));
  const doublons = types.filter((t, i) => types.indexOf(t) !== i);
  check('aucun type dupliqué dans le graphe', doublons.length === 0, doublons.join(', '));

  const orga = noeuds.find((n) => [].concat(n['@type'] || []).includes('Organization'));
  check('Organization présente', !!orga);

  if (orga) {
    check('Organization.name vaut « Urbizen »', orga.name === 'Urbizen', `lu : ${orga.name}`);
    check('Organization n\'est pas un LocalBusiness',
      ![].concat(orga['@type']).some((t) => /LocalBusiness|ProfessionalService/.test(t)),
      JSON.stringify(orga['@type']));
    check('description : l\'activité, pas le slogan',
      /prépare à distance des dossiers/i.test(orga.description || ''), `lu : ${orga.description}`);
    check('courriel de contact présent', !!orga.email, `lu : ${orga.email ?? '(absent)'}`);
    check('téléphone présent', !!orga.telephone);
    check('adresse postale présente', orga.address?.['@type'] === 'PostalAddress', JSON.stringify(orga.address));

    if (orga.address) {
      check('adresse : rue, code postal, ville, pays',
        !!orga.address.streetAddress && !!orga.address.postalCode
        && !!orga.address.addressLocality && !!orga.address.addressCountry,
        JSON.stringify(orga.address));
    }

    // Aucune coordonnée géographique ni horaire : ce serait revendiquer un
    // établissement recevant du public, ce que le lot refuse explicitement.
    check('ni coordonnées géographiques ni horaires',
      !orga.geo && !orga.openingHours && !orga.openingHoursSpecification);

    const sameAs = [].concat(orga.sameAs || []);
    check('sameAs renseigné', sameAs.length > 0);
    const sales = sameAs.filter((u) => SUIVI.some((p) => u.includes(p)));
    check('sameAs sans paramètre de suivi ni de partage', sales.length === 0, sales.join(' · '));
  }

  // Fil d'Ariane : plus de « Home ».
  const fil = noeuds.find((n) => [].concat(n['@type'] || []).includes('BreadcrumbList'));
  check('BreadcrumbList présente', !!fil);
  if (fil) {
    const noms = JSON.stringify(fil).match(/"name":"([^"]*)"/g) || [];
    check('le premier maillon n\'est plus « Home »', !JSON.stringify(fil).includes('"name":"Home"'), noms.join(' '));
    check('le premier maillon dit « Accueil »', JSON.stringify(fil).includes('"name":"Accueil"'), noms.join(' '));
  }

  // Aucun Person sur les pages : il n'apparaît que sur les articles.
  const person = noeuds.find((n) => [].concat(n['@type'] || []).includes('Person'));
  check('aucun nœud Person sur une page', !person, JSON.stringify(person));

  // Récolte des URL pour le contrôle global.
  const marcher = (o) => {
    if (o && typeof o === 'object') {
      for (const [k, v] of Object.entries(o)) {
        if (typeof v === 'string' && v.startsWith('https://urbizen.fr')) {
          toutesUrls.set(v.split('#')[0], `${chemin} · ${k}`);
        } else marcher(v);
      }
    } else if (Array.isArray(o)) o.forEach(marcher);
  };
  marcher(noeuds);
}

// ---- Aucune URL morte dans le graphe, toutes pages confondues -------------
console.log('\n── URL citées dans le graphe');
const mortes = [];
for (const [u, src] of toutesUrls) {
  if (!u) continue;
  const r = await fetch(u + anticache(u), { method: 'HEAD', redirect: 'manual' });
  if (r.status >= 400) mortes.push(`${u} → ${r.status} (${src})`);
}
console.log(`           ${toutesUrls.size} URL contrôlées`);
check('aucune URL morte dans le graphe', mortes.length === 0, mortes.join(' · '));
check('aucune URL /author/ dans le graphe',
  ![...toutesUrls.keys()].some((u) => u.includes('/author/')),
  [...toutesUrls.keys()].filter((u) => u.includes('/author/')).join(' · '));

// ---- Ce que le lot a délibérément écarté ---------------------------------
console.log('\n── Ce qui ne doit PAS avoir été ajouté');
{
  const { noeuds } = await graphe('/tarifs/');
  const types = noeuds.flatMap((n) => [].concat(n['@type'] || []));
  check('aucune Offer — pas de prix dupliqué dans le schéma', !types.includes('Offer'));
  check('aucun FAQPage — sans bénéfice attendu aujourd\'hui', !types.includes('FAQPage'));
  check('aucun Service — réévalué au lot G', !types.includes('Service'));
}

console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
