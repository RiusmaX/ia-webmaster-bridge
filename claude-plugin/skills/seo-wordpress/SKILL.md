---
name: seo-wordpress
description: Optimiser le référencement naturel (SEO) d'un site WordPress géré via l'adaptateur IA Webmaster Bridge. À utiliser pour analyser, configurer et améliorer le SEO d'une page (titre, méta-description, structure sémantique, Open Graph, schema.org, intégration Rank Math / Yoast).
---

# SEO WordPress — méthode

Le SEO n'est pas un module à plaquer en fin de page : c'est une discipline
**transversale** qui structure le contenu dès sa rédaction. Sur les sites
gérés par cet adaptateur, l'objectif est d'**indexer correctement chaque
page, faciliter sa lecture par les moteurs, et la rendre attractive dans les
SERP**.

Ce skill suppose que tu disposes des outils `mcp__ia-webmaster__iawm_*` et
que l'un des plugins SEO de référence est actif sur le site
(**Rank Math** prioritaire, **Yoast** secondaire).

## Détection du backend SEO actif

Avant toute écriture SEO, déterminer quel plugin pilote les méta-données :

1. Appeler `iawm_diagnostics_plugins`.
2. Chercher dans la liste, dans l'ordre :
   - `seo-by-rank-math/rank-math.php` (Rank Math, **prioritaire**).
   - `wordpress-seo/wp-seo.php` (Yoast).
3. Si aucun n'est actif, proposer à l'utilisateur d'installer Rank Math
   via `iawm_plugins_install` (slug `seo-by-rank-math`, `activate: true`).
4. Si les deux sont actifs simultanément, alerter l'utilisateur — ces
   plugins entrent en conflit et il faut désactiver l'un des deux.

## Audit SEO d'une page

L'audit suit toujours la même grille (à restituer sous forme de tableau ou
de checklist) :

### 1. Métadonnées de base (Rank Math / Yoast)

- **Titre SEO** (`<title>` final, distinct du H1) : 50–60 caractères,
  contient le mot-clé principal, est accrocheur (pas juste descriptif).
- **Méta-description** : 140–160 caractères, contient le mot-clé,
  promet une valeur claire, **incite à cliquer** (verbe d'action).
- **Slug** : court, en kebab-case, mots-clés essentiels uniquement, pas
  de stop-words (le, la, de, du, à, des, …).
- **Focus keyword** (Rank Math) : un mot-clé principal renseigné, repris
  dans le titre SEO, l'URL, le H1, le premier paragraphe.

### 2. Structure sémantique de la page

- **Un seul H1**, qui correspond au sujet principal (peut différer du
  titre SEO).
- **Hiérarchie H2/H3** cohérente, sans saut de niveau (pas de H4 après un
  H2 sans H3 intermédiaire).
- **Mots-clés sémantiques** (cooccurrents) présents dans les H2.
- **Longueur** ≥ 300 mots pour un article (≥ 600 pour viser le top 10).
- **Premier paragraphe** : 50–100 mots, contient le focus keyword,
  répond directement à l'intention de recherche.
- **Listes** (`<ul>`, `<ol>`) pour les énumérations — Google les
  remonte volontiers en featured snippet.

### 3. Médias et accessibilité

- **Attribut `alt`** sur toutes les images (descriptif, pas keyword
  stuffing). Vérifier via `iawm_media_get`.
- **Nom de fichier image** explicite (pas `IMG_1234.jpg`).
- **Taille** : optimiser au-delà de 200 ko (proposer compression).
- **Lazy loading** activé (WP le fait par défaut depuis 5.5).

### 4. Maillage interne et externe

- Au moins **3 liens internes** vers d'autres pages du site, avec
  ancres descriptives (pas « cliquez ici »).
- **1–2 liens externes** vers des sources d'autorité (en `rel="noopener"`).
- Vérifier qu'aucun lien n'est cassé (à terme : capacité dédiée).

### 5. Partage social (Open Graph / Twitter Cards)

- Image OG dédiée (1200×630 px idéal), distincte de l'image vedette si
  possible.
- Titre OG distinct du titre SEO (plus émotionnel).
- Description OG accrocheuse, 100–200 caractères.

### 6. Données structurées (schema.org)

- Type schema cohérent avec le contenu :
  - `Article` pour un article de blog.
  - `LocalBusiness` pour une fiche entreprise locale.
  - `Product` pour un produit e-commerce.
  - `FAQPage` pour une page FAQ.
  - `BreadcrumbList` partout.
- Rank Math gère la plupart en automatique — vérifier qu'il est bien
  configuré, ne pas tout réinventer.

### 7. Performance

- Charger `iawm_diagnostics_system` pour vérifier la version PHP, les
  limites mémoire, le cache.
- À terme : intégrer un outil de mesure Core Web Vitals.

## Workflow standard d'optimisation d'une page

1. **Comprendre l'intention** : que cherche réellement le visiteur ?
   Demander à l'utilisateur le mot-clé visé et le type d'intention
   (informationnel / transactionnel / navigationnel).
2. **Audit avant écriture** : appliquer la grille ci-dessus si la page
   existe déjà.
3. **Plan d'amélioration** : présenter à l'utilisateur les problèmes
   classés par impact (titre/méta = impact maximal ; alt-tags = impact
   modéré). **Valider** avant toute modification.
4. **Écriture / réécriture** : appliquer les changements via
   `iawm_content_update` pour le contenu, et via l'API SEO (à venir :
   `iawm_seo_page_*`) pour les méta-données.
5. **Revérification** : refaire l'audit après modification.

## Garde-fous SEO

- **Ne jamais sur-optimiser** un mot-clé (« keyword stuffing »). Densité
  cible : 1 à 2 %.
- **Ne jamais générer du contenu vide** uniquement pour augmenter la
  longueur. Mieux vaut 400 mots utiles que 1200 mots dilués.
- **Toujours conserver une voix éditoriale** humaine, naturelle ; pas
  de phrases-clés robotiques.
- **Respecter la cohérence sémantique** : le titre, l'URL, le H1, la
  méta-description et le premier paragraphe doivent raconter la même
  histoire.
- **Pas de cloaking, pas de redirections trompeuses, pas de pages
  satellites.** Black-hat = perte de positionnement à terme.

## Sortie attendue

Quand on te demande un audit SEO, fournis :

```
## Audit SEO — [titre de la page]

### Score global : X/10

### Forces
- (3 à 5 points)

### Faiblesses (par ordre d'impact)
1. (impact maximal) ...
2. (impact moyen) ...

### Plan d'action proposé
- [ ] Action 1 (impact, effort, outil utilisé)
- [ ] Action 2 ...
```

Demande toujours validation avant d'appliquer les changements à la page.
