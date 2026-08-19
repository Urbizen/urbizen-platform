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

const texte = (s) => decoder((s || '').replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();

/**
 * La zone éditoriale d'une page : son H1, son chapô, ses H2.
 *
 * L'extraction est bornée à `<main>`. C'est le point qui fait tout : le menu et
 * le pied de page citent la cible sans rien promettre, et un contrôle sur le
 * corps entier passerait grâce à eux, quel que soit le contenu réel. Hors
 * `<main>`, on retombe sur le document entier plutôt que de ne rien contrôler —
 * un thème sans `<main>` doit faire échouer le banc sur le fond, pas le
 * neutraliser en silence.
 */
function zoneEditoriale(html) {
  const m = html.match(/<main[^>]*>([\s\S]*?)<\/main>/i);
  const zone = m ? m[1] : html;
  const tous = (re) => [...zone.matchAll(re)].map((x) => texte(x[1])).filter(Boolean);
  return {
    h1: tous(/<h1[^>]*>([\s\S]*?)<\/h1>/gi),
    lead: tous(/<p[^>]*class="[^"]*\blead\b[^"]*"[^>]*>([\s\S]*?)<\/p>/gi),
    h2: tous(/<h2[^>]*>([\s\S]*?)<\/h2>/gi),
  };
}

/** La cible est-elle portée par le H1, le chapô ou un H2 ? */
const portePromesse = (zone, motif) =>
  [...zone.h1, ...zone.lead, ...zone.h2].some((t) => motif.test(t));

/** Où la cible a été trouvée — pour que l'échec se lise sans rouvrir la page. */
function decrirePortee(zone, motif) {
  const ou = [];
  if (zone.h1.some((t) => motif.test(t))) ou.push('H1');
  if (zone.lead.some((t) => motif.test(t))) ou.push('chapô');
  if (zone.h2.some((t) => motif.test(t))) ou.push('H2');
  if (ou.length) return `portée par : ${ou.join(', ')}`;
  return `absente de H1 (${zone.h1.length}), chapô (${zone.lead.length}), H2 (${zone.h2.length})`;
}

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
    // L'ACCUEIL SE CONTRÔLE SUR SON SUJET, PAS SUR UN MOT DANS SON H1
    //
    // La règle était : le H1 doit contenir l'expression du title. Elle tenait
    // tant que le H1 nommait le livrable. Il s'adresse désormais à la personne
    // qui lit — « Vos travaux commencent par les bonnes démarches. » — et le
    // mot-clé a migré vers un H2, sans que la page ait changé de sujet.
    //
    // Exiger le mot dans le H1 revenait donc à interdire toute accroche qui ne
    // soit pas un résumé du title. Ce qui se contrôle ici est l'intention : la
    // cible doit rester portée par la zone ÉDITORIALE — H1, chapô, H2 — et le
    // title doit continuer de la porter, lui aussi. Si l'expression disparaît
    // de ces trois endroits, la page a réellement changé de sujet, et le banc
    // le dit.
    //
    // La zone est délibérément restreinte : un contrôle sur tout le corps
    // passerait grâce au menu ou au pied de page, qui citent la cible sans rien
    // promettre. Huit occurrences existent dans le document, une seule dans la
    // zone éditoriale — c'est celle-là qui compte.
    promesseEditoriale: /dossiers? d.urbanisme/i,
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

  // Cohérence title ↔ contenu : la page ne doit pas envoyer deux signaux
  // différents. Selon la page, cela se contrôle sur le H1 seul ou sur la zone
  // éditoriale entière — voir le commentaire porté par l'entrée « / ».
  const corps = r.html.replace(/<div id="cmplz-cookiebanner-container"[\s\S]*$/i, '');
  const zone = zoneEditoriale(corps);
  const h1 = zone.h1;

  check('un seul H1', h1.length === 1, `${h1.length} H1`);
  check('le H1 est non vide', h1.length > 0 && h1[0].length > 0, `H1 : « ${h1[0] ?? ''} »`);

  if (att.h1Attendu) {
    check('le H1 reprend la promesse du title', h1.length > 0 && att.h1Attendu.test(h1[0]), `H1 : ${h1[0]}`);
  }

  if (att.promesseEditoriale) {
    const p = att.promesseEditoriale;
    check('le title porte toujours la cible', p.test(r.title || ''), `title : ${r.title}`);
    check('la cible est portée par la zone éditoriale (H1, chapô ou H2)',
      portePromesse(zone, p), decrirePortee(zone, p));
  }
}

// ---- Contre-épreuve de la garde éditoriale --------------------------------
//
// Une garde qui ne peut pas échouer ne garde rien. Celle-ci se vérifie donc sur
// une zone amputée : on retire la cible du H1, du chapô et des H2, et le
// prédicat doit basculer au rouge. Sans cette contre-épreuve, un jour où
// `portePromesse` renverrait `true` par construction — regex vidée, zone mal
// extraite — le banc resterait vert en ne contrôlant plus rien.
{
  console.log('\n── Contre-épreuve : la garde éditoriale sait échouer');
  const p = ATTENDU['/'].promesseEditoriale;

  const pleine = {
    h1: ['Vos travaux commencent par les bonnes démarches.'],
    lead: ['Construction neuve, extension, modification de l’existant.'],
    h2: ['Découvrez les pièces préparées pour votre dossier d’urbanisme'],
  };
  const amputee = { h1: pleine.h1, lead: pleine.lead, h2: ['Découvrez les pièces préparées'] };

  check('zone portant la cible en H2 : acceptée', portePromesse(pleine, p));
  check('zone amputée de la cible : refusée', !portePromesse(amputee, p));
  check('cible en H1 seul : acceptée',
    portePromesse({ h1: ['Vos dossiers d’urbanisme'], lead: [], h2: [] }, p));
  check('cible dans le chapô seul : acceptée',
    portePromesse({ h1: ['Titre'], lead: ['Un dossier d’urbanisme complet'], h2: [] }, p));
  check('zone entièrement vide : refusée', !portePromesse({ h1: [], lead: [], h2: [] }, p));
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
