# IA Webmaster Bridge

Pilotez un site WordPress + **Divi 5** directement depuis Claude — un webmaster
assisté par IA, sécurisé et auditable, capable de **construire un site complet
depuis un prompt**.

IA Webmaster Bridge connecte **Claude Code** (et Claude Desktop) à un site
**WordPress 7.0** pour en gérer le contenu, les médias, les menus, les
taxonomies, la configuration, les extensions, le SEO et — surtout — la
**génération de pages, headers et footers Divi 5** complets. Chaque action est
**authentifiée** (signature HMAC), **journalisée** et encadrée par des
**garde-fous**.

> **Statut :** Phases 0 à 3 complètes. Système opérationnel pour générer un
> site WordPress + Divi 5 depuis un prompt (page d'accueil + header + footer +
> SEO). Validé en bout-en-bout en environnement local. À tester en
> environnement local avant toute utilisation en production.

## Architecture

Le système se compose de trois éléments :

```
   Claude Code
       │  protocole MCP (stdio)
       ▼
   Pont MCP           ── claude-plugin/mcp-gateway/  (Node.js, bundle autonome)
       │  HTTPS — requêtes signées HMAC
       ▼
   Plugin WordPress   ── plugin/ia-webmaster-bridge/  (PHP, API REST)
       ▼
   WordPress 7.0
```

1. **Plugin WordPress** — expose une API REST contrôlée sous le namespace
   `ia-webmaster/v1`.
2. **Pont MCP** — traduit les appels d'outils MCP de Claude en requêtes signées
   vers le plugin.
3. **Plugin Claude Code** — réunit les outils MCP et des *skills* (méthodes de
   travail de webmaster).

## Sécurité

La sécurité est une exigence de premier ordre du projet :

- **Authentification HMAC-SHA256** de chaque requête (signature + nonce
  anti-rejeu + fenêtre temporelle).
- **Journal d'audit** : toute action est tracée (date, route, résultat, IP).
- **Garde-fous d'écriture** : mode *dry-run*, contenus en brouillon par défaut,
  **kill switch** pour couper toutes les écritures.
- **Liste blanche** des réglages modifiables — aucune option critique exposée.
- Le secret partagé ne vit jamais dans le dépôt.

## Fonctionnalités

L'adaptateur expose **38+ outils MCP** répartis en familles :

### Gestion WordPress (Phases 0-2)
- **Contenu** — lister, lire, créer, modifier pages et articles (blocs Gutenberg
  normalisés). Bug critique `wp_slash` résolu.
- **Médias** — médiathèque, import depuis URL, métadonnées.
- **Taxonomies** — catégories, étiquettes, assignation.
- **Menus** — menus de navigation et emplacements de thème.
- **Configuration** — réglages du site, utilisateurs.
- **Diagnostic** — système, extensions, thèmes, logs (lecture seule).

### Divi 5 (Phase 3)
- **Pages Divi** — lecture en arbre structuré (3 modes : tree, flat, raw),
  écriture round-trip fidèle au bit.
- **41 modules natifs** implémentés (text, blurb, cta, image, button, heading,
  number-counter, testimonial, gallery, video, pricing-tables, icon-list,
  social-media-follow, team-member, signup, map, accordion, tabs, slider,
  contact-form, menu, search, breadcrumbs, post-title…).
- **13 patterns paramétrables** : hero, features3col, ctaBanner, imageTextSplit,
  testimonials, faqAccordion, numbersBar, videoSection, contactSection,
  pricing3col, teamGrid, headerSimple, footerStandard.
- **Theme Builder complet** — création de templates avec header / footer /
  body dynamique. Wrapper `setup-site-defaults` qui pose tout en un appel.
- **Library Divi** — locale (Save to Library) accessible côté API ;
  Divi Cloud limité (token côté navigateur).
- **Design system** — récupération des global colors et fonts du site.
- **Catalogue complet** des ~99 modules natifs documentés
  (`docs/divi5-modules-catalog.md`).

### SEO (Phase 3)
- **API SEO normalisée** avec backend **Rank Math** (meta_title,
  meta_description, focus_keyword, canonical, Open Graph, Twitter, robots).
- Backend Yoast préparé (mapping en place, à activer).
- **Installation automatique de plugins** depuis WP.org via
  `/plugins/install`.

### Skills Claude Code (7 méthodes)
- `webmaster-wordpress` — méthodes et garde-fous transversaux.
- `creer-page-wordpress` — page WordPress classique.
- `creer-page-divi-wordpress` — workflow prompt → page Divi complète (8 étapes).
- `auditer-site-wordpress` — audit de l'état du site.
- `design-frontend-wordpress` — principes design (hiérarchie, typo, mobile-first).
- `marketing-conversion-wordpress` — frameworks AIDA/PAS/FAB, CTA, preuve sociale.
- `seo-wordpress` — grille d'audit SEO et bonnes pratiques.

## Installation

**Prérequis :** WordPress 7.0+, Node.js 18+, Claude Code.

### 1. Côté WordPress

1. Copier le dossier `plugin/ia-webmaster-bridge/` dans le `wp-content/plugins/`
   du site.
2. Activer l'extension « IA Webmaster Bridge ».
3. Dans **Réglages → IA Webmaster Bridge**, générer le secret. Noter l'URL du
   site, l'identifiant de clé et le secret.

### 2. Côté Claude Code (CLI)

```
/plugin marketplace add RiusmaX/ia-webmaster-bridge
/plugin install ia-webmaster@ia-webmaster-bridge
```

Si `/plugin` n'est pas disponible dans ton environnement, installation
user-scope :

```
claude mcp add ia-webmaster --scope user -- node /chemin/vers/.iawm/gateway/index.js
```

### 2bis. Côté Claude Desktop

Settings → Connectors → **Add custom connector** :
- Command : `node`
- Args : `C:\Users\<vous>\.iawm\gateway\index.js`

OU édition manuelle de `%APPDATA%\Claude\claude_desktop_config.json` :

```json
{
  "mcpServers": {
    "ia-webmaster": {
      "command": "node",
      "args": ["C:\\Users\\<vous>\\.iawm\\gateway\\index.js"]
    }
  }
}
```

### 3. Configuration

Créer **`~/.iawm/config.json`** (modèle :
[`config.example.json`](claude-plugin/mcp-gateway/config.example.json)) :

```json
{
  "baseUrl": "https://exemple.com",
  "keyId": "iawm_xxxxxxxxxxxx",
  "secret": "le-secret-genere-dans-l-admin"
}
```

Redémarrer Claude Code / Claude Desktop : les outils `iawm_*` sont disponibles.

## Exemples d'usage

Une fois connecté, vous pouvez par exemple demander à Claude :

> *« Crée la page d'accueil de mon site. Activité : [votre métier]. Audience :
> [persona]. Objectif principal : [action attendue, ex. demande de devis,
> inscription, achat]. Avec header (logo + menu + CTA), 8 à 10 sections
> (hero, présentation, chiffres-clés, services, témoignages, tarifs, FAQ,
> contact) et footer multi-colonnes. »*

Le skill `creer-page-divi-wordpress` orchestrera :
1. Brief structuré (audience, ton, sections).
2. Plan de page validé.
3. Création en draft (jamais publish direct).
4. Génération des blocs Divi 5 avec patterns paramétrables.
5. Setup Theme Builder (header + footer).
6. SEO Rank Math.
7. Lecture de validation.
8. Demande explicite avant publish.

## Structure du dépôt

| Chemin | Rôle |
|--------|------|
| `plugin/ia-webmaster-bridge/` | Plugin WordPress (API REST) |
| `claude-plugin/` | Plugin Claude Code (outils MCP + skills) |
| `claude-plugin/mcp-gateway/` | Pont MCP (TypeScript, bundle autonome) |
| `.claude-plugin/marketplace.json` | Catalogue marketplace |
| `docs/` | Architecture, roadmap, journal des décisions, glossaire |
| `specs/` | Spécifications par fonctionnalité |

## Développement

Le pont MCP se reconstruit avec :

```
npm install --prefix claude-plugin/mcp-gateway
npm run build --prefix claude-plugin/mcp-gateway
```

Le projet se développe contre un site WordPress local (LocalWP), jamais
directement sur une production. La démarche est documentée dans `docs/`.

## Feuille de route

- **Phases 0–3 (faites)** — connexion sécurisée, plan contenu, Divi 5 complet
  (41 modules, 13 patterns, Theme Builder), SEO Rank Math, premier test E2E
  réussi sur site réel.
- **Phase 4** — infrastructure : thèmes (install/activation), base de données
  (export, requêtes contrôlées), sauvegardes et restauration.
- **Phase 5** — durcissement sécurité (modèle d'autorisation, sauvegarde
  pré-destructive, test d'intrusion).
- **Phase 6** — couche webmaster avancée (fichier de contexte par site,
  workflows documentés, routines planifiées).
- **Backlog** : modules WooCommerce (25 inventoriés), backend SEO Yoast,
  patterns Theme Builder pour single post / archive.

## Licence

[GPL-3.0-or-later](LICENSE). Le plugin WordPress étant un dérivé de WordPress,
l'ensemble du projet est publié sous licence GPL.
