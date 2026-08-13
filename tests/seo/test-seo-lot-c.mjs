/**
 * Banc du lot C — métadonnées et ciblage des pages commerciales.
 *
 * Il contrôle trois choses que le lot devait produire, et une quatrième qu'il
 * ne devait pas casser.
 *
 * 1. Les métadonnées valent exactement ce qui a été arbitré — pas « quelque
 *    chose de plausible », les chaînes elles-mêmes.
 * 2. Elles sont **uniques** entre les cinq pages : deux pages qui annoncent la
 *    même promesse se cannibalisent, et c'était le défaut d'origine.
 * 3. Elles sont **durables** : aucun montant, aucune balise dynamique, aucune
 *    casse de marque erronée.
 * 4. Les cinq pages restent en 200 et indexables, et les deux liens ajoutés
 *    sont bien là.
 *
 *     node tests/seo/test-seo-lot-c.mjs [base]
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

// Les entités HTML des métadonnées doivent être ramenées au texte pour
// comparer à la chaîne arbitrée : AIOSEO échappe l'apostrophe typographique.
const decoder = (s) => (s || '')
  .replace(/&#0?39;|&apos;/g, "'")
  .replace(/&#8217;|&rsquo;/g, '’')
  .replace(/&amp;/g, '&')
  .replace(/&quot;/g, '"')
  .replace(/&nbsp;/g, ' ');

async function lire(chemin) {
  const r = await fetch(BASE + chemin + anticache(chemin), { redirect: 'manual' });
  const html = await r.text();
  const g = (p) => { const m = html.match(p); return m ? decoder(m[1].trim()) : null; };
  const robots = html.match(/<meta name="robots" content="(.*?)"/i);
  return {
    code: r.status,
    html,
    robots: robots ? robots[1] : null,
    indexable: r.status === 200 && !(robots && /noindex/i.test(robots[1])),
    title: g(/<title>(.*?)<\/title>/is),
    description: g(/<meta name="description" content="(.*?)"/is),
    ogTitle: g(/<meta property="og:title" content="(.*?)"/is),
    ogDescription: g(/<meta property="og:description" content="(.*?)"/is),
    twTitle: g(/<meta name="twitter:title" content="(.*?)"/is),
    twDescription: g(/<meta name="twitter:description" content="(.*?)"/is),
  };
}

// L'apostrophe des métadonnées est la DROITE (U+0027), servie encodée en
// `&#039;`. Vérifié sur les sept descriptions du site, pages légales comprises :
// elles l'emploient toutes. Le contenu visible, lui, utilise la typographique —
// c'est une incohérence de forme préexistante, sans effet sur le référencement,
// qui n'a pas justifié de réécrire des métadonnées déjà en place.
const A = "'";

const ATTENDU = {
  '/': {
    nom: 'Accueil',
    title: `Dossiers d${A}urbanisme à distance | Urbizen`,
    description: `Urbizen prépare vos dossiers d${A}urbanisme à distance, partout en France : déclaration préalable, permis de construire, plans et pièces prêts à déposer.`,
    h1Attendu: /dossiers d.urbanisme/i,
  },
  '/declarations-prealables/': {
    nom: 'Déclaration préalable',
    title: 'Déclaration préalable de travaux à distance | Urbizen',
    description: 'Urbizen prépare votre déclaration préalable de travaux à distance partout en France : plans, CERFA et pièces du dossier selon votre projet.',
    h1Attendu: /déclaration préalable/i,
  },
  '/permis-de-construire/': {
    nom: 'Permis de construire',
    title: 'Dossier de permis de construire à distance | Urbizen',
    description: 'Urbizen prépare votre dossier de permis de construire à distance : CERFA, plans PCMI, notice descriptive et insertion paysagère, prêts à déposer en mairie.',
    h1Attendu: /dossier de permis de construire/i,
  },
  '/conception/': {
    nom: 'Conception',
    title: `Plans sur mesure pour dossier d${A}urbanisme | Urbizen`,
    description: `Urbizen dessine les plans de votre projet : plan de masse, plan de coupe, façades et insertion paysagère, réalisés sur mesure pour votre dossier d${A}urbanisme.`,
    h1Attendu: /plans/i,
  },
  '/tarifs/': {
    nom: 'Tarifs',
    title: 'Tarifs déclaration préalable et permis | Urbizen',
    description: `Le prix de votre dossier d${A}urbanisme selon la nature du projet : déclaration préalable, permis de construire ou plans. Ce qui est inclus, devis avant commande.`,
    h1Attendu: /tarifs/i,
  },
};

console.log(`\n════ LOT C — ${BASE} ════`);

const releves = {};

for (const [chemin, att] of Object.entries(ATTENDU)) {
  console.log(`\n── ${att.nom} — ${chemin}`);
  const r = await lire(chemin);
  releves[chemin] = r;

  check('répond 200 et reste indexable', r.indexable, `code ${r.code}, robots ${r.robots ?? '(absent)'}`);
  check('title conforme', r.title === att.title, `lu : ${r.title}`);
  check('description conforme', r.description === att.description, `lu : ${r.description}`);
  check('og:title suit le title', r.ogTitle === att.title, `lu : ${r.ogTitle}`);
  check('og:description suit la description', r.ogDescription === att.description, `lu : ${r.ogDescription}`);
  check('twitter:title suit le title', r.twTitle === att.title, `lu : ${r.twTitle}`);
  check('twitter:description suit la description', r.twDescription === att.description, `lu : ${r.twDescription}`);

  const meta = `${r.title} ${r.description}`;
  check('aucun montant dans les métadonnées', !/\d+\s*(€|&euro;|euros)/i.test(meta), meta);
  check('aucune balise dynamique AIOSEO', !/#(post_title|site_title|separator_sa|tagline)/.test(meta));
  check('casse de marque normalisée', !/UrbiZen|URBIZEN/.test(meta), meta);
  check('longueur de title tenable', (r.title || '').length <= 60, `${(r.title || '').length} c.`);
  check('longueur de description tenable',
    (r.description || '').length >= 120 && (r.description || '').length <= 160,
    `${(r.description || '').length} c.`);

  // Cohérence title ↔ H1 : le H1 doit reprendre la promesse du title. C'est ce
  // qui évite d'envoyer deux signaux différents sur une même page.
  const corps = r.html.replace(/<div id="cmplz-cookiebanner-container"[\s\S]*$/i, '');
  const h1 = [...corps.matchAll(/<h1[^>]*>([\s\S]*?)<\/h1>/gi)]
    .map((m) => decoder(m[1].replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim());
  check('un seul H1', h1.length === 1, `${h1.length} H1`);
  check('le H1 reprend la promesse du title', h1.length > 0 && att.h1Attendu.test(h1[0]), `H1 : ${h1[0]}`);
}

// ---- Unicité : c'est le cœur du lot ---------------------------------------
console.log('\n── Unicité des promesses');
for (const champ of ['title', 'description']) {
  const vus = new Map();
  for (const [chemin, r] of Object.entries(releves)) {
    const v = r[champ];
    if (vus.has(v)) {
      check(`${champ} unique`, false, `${chemin} et ${vus.get(v)} partagent : ${v}`);
    } else {
      vus.set(v, chemin);
    }
  }
  check(`aucun ${champ} en double sur les cinq pages`, vus.size === Object.keys(releves).length);
}

// La cannibalisation d'origine : l'accueil et Tarifs annonçaient les deux
// démarches. L'accueil ne doit plus les nommer dans son title.
check('le title de l\'accueil ne nomme plus les deux démarches',
  !/déclaration préalable/i.test(releves['/'].title) && !/permis de construire/i.test(releves['/'].title),
  releves['/'].title);

// ---- Les deux liens ajoutés ----------------------------------------------
//
// Comptés dans le CONTENU seul. L'en-tête et le pied de page lient déjà Tarifs
// et Conception depuis toutes les pages : mesurer sur le document entier ferait
// passer ces deux contrôles au vert sans qu'aucun lien de contenu ait été
// ajouté — vérifié avant application, les deux passaient effectivement à tort.
console.log('\n── Maillage ajouté');

/** Contenu principal, en-tête et pied de page retirés. */
const contenu = (html) => {
  const i = html.indexOf('<main');
  const j = html.lastIndexOf('</main>');
  return i >= 0 && j > i ? html.slice(i, j) : html;
};

// Mesuré avant application : l'accueil n'avait **aucun** lien de contenu vers
// Tarifs. Les trois liens relevés à l'audit étaient tous hors `<main>` —
// navigation et pied de page. L'audit les avait attribués à tort à la section
// d'appel à l'action, faute d'avoir borné la lecture à la fin de `<main>`.
const liensTarifs = (contenu(releves['/'].html).match(/href="[^"]*\/tarifs\/"/g) || []).length;
check('l\'accueil lie /tarifs/ depuis son contenu, et non seulement depuis sa navigation',
  liensTarifs >= 1, `${liensTarifs} lien(s) dans <main>`);

const liensConception = (contenu(releves['/tarifs/'].html).match(/href="[^"]*\/conception\/"/g) || []).length;
check('/tarifs/ lie /conception/ depuis son contenu',
  liensConception >= 1, `${liensConception} lien(s) dans <main>`);

console.log(`\n${echecs ? `${echecs} ECART(S)` : 'TOUS LES CONTROLES PASSENT'}\n`);
process.exit(echecs ? 1 : 0);
