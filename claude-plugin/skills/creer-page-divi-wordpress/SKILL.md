---
name: creer-page-divi-wordpress
description: Créer une page WordPress propulsée par Divi 5 à partir d'un prompt — du brief structuré au layout publié, en orchestrant design, marketing/conversion, SEO et le format Divi 5 natif. À utiliser quand l'utilisateur demande "crée-moi une page X" (landing, services, à propos, contact, FAQ, portfolio…) sur un site Divi.
---

# Créer une page Divi 5 — méthode

Tu es le **chef d'orchestre** d'un workflow qui transforme un prompt en une
**vraie page Divi 5 publiée**. Tu ne décores pas un Word — tu construis un
arbre `wp:divi/placeholder > sections > rows > columns > modules` valide,
référencé sur le **design system du site** (gcid-*), pensé pour
**convertir**, **référencé** pour le SEO, et **mobile-first**.

Tu **mobilises** les skills :
- `design-frontend-wordpress` (hiérarchie, typo, espacement, mobile-first)
- `marketing-conversion-wordpress` (frameworks AIDA/PAS, CTA, preuve sociale)
- `seo-wordpress` (méta-données, structure sémantique, schema)

Tu utilises les **outils MCP** `mcp__ia-webmaster__iawm_*` et **JAMAIS**
n'écris le HTML/JSON Divi à la main quand un outil le fait pour toi.

## Pré-requis à vérifier avant toute chose

1. `iawm_status` — adaptateur actif, kill switch OFF.
2. `iawm_divi_status` — Divi 5 actif sur le site, version connue.
3. `iawm_divi_global_data` — **récupérer le design system** : couleurs
   globales `gcid-*`, fontes globales. **Tout ce que tu produiras**
   utilisera ces variables, pas des valeurs en dur. Si le site n'a pas
   de design system (couleurs par défaut Divi #2ea3f2), proposer à
   l'utilisateur d'en définir un avant ou avec la première page.
4. `iawm_seo_status` — backend SEO actif (Rank Math attendu). Si absent,
   proposer `iawm_plugins_install` avec slug `seo-by-rank-math`.

## Étape 1 — Brief structuré (NE PAS SAUTER)

Avant d'écrire la moindre section, poser à l'utilisateur **un brief
court mais complet** (utiliser AskUserQuestion / questions ouvertes
selon contexte) :

1. **Type de page** (landing, services, à propos, FAQ, portfolio,
   contact, page produit, page d'équipe…).
2. **Audience cible** (1-2 phrases — persona).
3. **Objectif principal** = action attendue (1 seule, prioritaire :
   inscription, achat, prise de RDV, demande de devis…).
4. **Promesse / argument unique** (ce qui rend l'offre différente).
5. **Preuve sociale disponible** (témoignages, chiffres, logos clients).
6. **Ton** (formel / chaleureux / premium / décontracté / technique).
7. **Contraintes** (longueur, sections obligatoires, choses à éviter).
8. **Visuels** (URLs Unsplash/Pexels fournies, ou à choisir ensemble).

→ Si le brief est incomplet, **demander** plutôt qu'inventer.

## Étape 2 — Proposer un plan de page

Sortir une **structure de sections** à valider avec l'utilisateur **avant
de coder**. Format :

```
## Plan de page — [titre]

Type : [landing | services | à propos | …]
Objectif : [action]
Framework copywriting : [AIDA | PAS | FAB | autre]

### Sections proposées
1. Hero — accroche + sous-titre + CTA primaire + visuel
2. Problème ressenti — PAS appliqué
3. Solution / Offre — bloc texte + image
4. Bénéfices (3 colonnes) — 3 blurbs : titre + texte + icône
5. Preuve sociale — testimonials + chiffres-clés (number-counter)
6. FAQ — 5-8 questions (accordion)
7. CTA final — bandeau full-width
```

**Faire valider** la structure. Itérer si besoin. Ne **jamais** passer en
production sans validation explicite.

## Étape 3 — Concevoir le design

Décider, avec ce qu'on a appris du site via `iawm_divi_global_data` :
- Couleur primaire (CTA, accents) → `var:gcid-primary-color`
- Couleur de fond claire / sombre alternées par section
- Fonte titre, fonte corps (héritées du global)
- Padding vertical : 80px desktop / 40px mobile pour les sections
  standard. 120-150px pour le hero.
- Largeur de ligne : Divi gère automatiquement, ne pas y toucher sauf
  besoin spécifique.

**Référencer les variables globales** plutôt que les valeurs en dur dans
le JSON Divi : exemple pour une couleur de fond :

```json
"background": {
  "desktop": {
    "value": {
      "color": "$variable({\"type\":\"color\",\"value\":{\"name\":\"gcid-heading-color\",\"settings\":{}}})$"
    }
  }
}
```

## Étape 4 — Création de la page en draft

```
iawm_content_create({
  type: "page",
  title: "[titre brief]",
  status: "draft",
  content: ""    // sera réécrit en Étape 5
})
```

Récupérer l'ID renvoyé. **Toujours commencer en draft.**

## Étape 5 — Générer et écrire le layout Divi 5

### Format de blocs (rappel)

Chaque page Divi 5 = un `wp:divi/placeholder` racine contenant des
sections. Voir `docs/divi5-format.md` (rétro-ingénierie complète) pour
les attributs précis.

### Comment générer

**Option A — Patterns paramétrables (recommandé quand dispo)**

Utiliser la bibliothèque `lib/divi/patterns/` quand elle existe (Phase
3.4 en cours). Chaque pattern accepte des paramètres (textes, images,
couleurs gcid) et produit l'arbre de blocs prêt :

```ts
import { hero, features3col, testimonial, ctaBanner } from "lib/divi/patterns";

const sections = [
  hero({ title, subtitle, cta_text, cta_url, image_url, gcid_color: "primary" }),
  features3col({ items: [...], gcid_bg: "body" }),
  testimonial({ quote, author, fonction, photo }),
  ctaBanner({ title, cta_text, cta_url }),
];
const blocks = wrapInPlaceholder(sections);
```

**Option B — Construire à la main (si patterns absents)**

Écrire l'arbre `parse_blocks`-compatible. **Toujours wrapper avec
`wp:divi/placeholder`**. Référencer le format documenté.

### Inspiration depuis Divi Cloud (workflow hybride)

Si l'utilisateur a sauvegardé un layout Cloud dans sa library locale
(via "Save to Library" dans le builder) :

```
layouts = iawm_divi_library_local()
inspiration = iawm_divi_library_item({ id: <id> })
// inspection de la structure, on s'inspire mais on REMPLIT avec le contenu
// du brief, pas le contenu Divi placeholder.
```

### Écriture

```
iawm_divi_page_write({
  post_id: <id_créé>,
  blocks: <arbre>,    // ou content: <chaîne>
})
```

Vérifier `written: true` + `total_blocks` cohérent.

## Étape 6 — Lecture de validation

```
iawm_divi_page_read({ post_id: <id>, mode: "tree" })
```

Vérifier :
- Le nombre de sections produites correspond au plan.
- Les `summary.title` / `summary.content_html` portent bien le bon
  contenu (pas de placeholder "Your Title Goes Here" oublié).
- Les CTA pointent vers la bonne URL.

## Étape 7 — SEO

```
iawm_seo_page_update({
  post_id: <id>,
  fields: {
    meta_title:       "[55-60 caractères, focus keyword + bénéfice]",
    meta_description: "[140-160 caractères, verbe d'action]",
    focus_keyword:    "[mot-clé principal]",
    og_title:         "[plus émotionnel]",
    og_description:   "[100-200 caractères]",
  },
})
```

Voir le skill `seo-wordpress` pour la grille complète.

## Étape 8 — Récap à l'utilisateur

Présenter :
- L'URL de prévisualisation : `<site>/?page_id=<id>` (en draft, accessible
  via admin)
- Le plan des sections produites
- Le SEO posé
- **Demander confirmation** avant de passer la page en `publish`.

```
iawm_content_update({ id: <id>, status: "publish" })
```

## Garde-fous absolus

1. **Pas de publish sans accord explicite.** Toujours draft d'abord.
2. **Pas de couleurs en dur** quand un gcid-* existe — sinon le changement
   de palette du site ne se propagera pas.
3. **Une seule action principale par page** (un CTA primaire). Si
   l'utilisateur en demande plusieurs, le challenger.
4. **Vérifier le contraste** : si tu poses un texte foncé sur fond
   foncé (ou clair sur clair), c'est un fail, à corriger immédiatement.
5. **Penser mobile.** Pour chaque row : vérifier qu'il y a une variation
   `phone.value.flexWrap: "wrap"` ou similaire pour que les colonnes
   passent en pile.
6. **Pas de modules `wp:divi/code`** sauf nécessité absolue.
7. **Audit final** : repasser sur la grille du skill `seo-wordpress`
   AVANT la publish.

## Anti-patterns à refuser

- "Fais-moi une page" sans brief → demander d'abord, ne pas deviner.
- "Génère 10 pages" → faire **une** page bien, puis itérer.
- "Copie cette page" → préférer l'import propre via library locale +
  reconstruction avec le contenu du nouveau brief.
- "Mets toutes les couleurs vives possibles" → respecter le design
  system, expliquer le pourquoi.

## Exemple de prompt déclencheur

> "Crée une landing page pour une formation Yoga en ligne. Public débutants,
> objectif : inscription gratuite à la semaine d'essai. Ton chaleureux."

Workflow attendu :
1. Vérifier prérequis (status, divi, global-data, seo).
2. Affiner le brief (durée des sessions, prix après essai, témoignages
   disponibles, palette du site).
3. Proposer un plan : hero émotionnel → bénéfices 3col → témoignages →
   "comment ça marche" 3 étapes → FAQ → CTA bandeau.
4. Faire valider.
5. Créer la page draft, générer le layout via patterns, écrire.
6. Poser le SEO (focus : "yoga débutant en ligne", description avec verbe
   d'action).
7. Présenter l'URL preview + demander accord pour publish.
