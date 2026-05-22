# IA Webmaster Bridge

Pilotez un site WordPress directement depuis Claude — un webmaster assisté par
IA, sécurisé et auditable.

IA Webmaster Bridge connecte **Claude Code** à un site **WordPress 7.0** pour en
gérer le contenu, les médias, les menus, les taxonomies, la configuration et le
diagnostic. Chaque action est **authentifiée** (signature HMAC), **journalisée**
et encadrée par des **garde-fous**.

> **Statut :** projet en développement actif. Les phases 0 à 2 sont opérationnelles
> (connexion sécurisée et gestion de contenu). À tester en environnement local
> avant toute utilisation en production.

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

L'adaptateur expose 29 outils répartis en familles :

- **Contenu** — lister, lire, créer, modifier pages et articles (blocs Gutenberg
  normalisés).
- **Médias** — médiathèque, import depuis URL, métadonnées.
- **Taxonomies** — catégories, étiquettes, assignation.
- **Menus** — menus de navigation et emplacements de thème.
- **Configuration** — réglages du site, utilisateurs.
- **Diagnostic** — système, extensions, thèmes, logs (lecture seule).

Et trois *skills* : `webmaster-wordpress` (méthode et garde-fous),
`creer-page-wordpress`, `auditer-site-wordpress`.

## Installation

**Prérequis :** WordPress 7.0+, Node.js 18+, Claude Code.

### 1. Côté WordPress

1. Copier le dossier `plugin/ia-webmaster-bridge/` dans le `wp-content/plugins/`
   du site.
2. Activer l'extension « IA Webmaster Bridge ».
3. Dans **Réglages → IA Webmaster Bridge**, générer le secret. Noter l'URL du
   site, l'identifiant de clé et le secret.

### 2. Côté Claude Code

```
/plugin marketplace add RiusmaX/ia-webmaster-bridge
/plugin install ia-webmaster@ia-webmaster-bridge
```

Puis créer le fichier de configuration **`~/.iawm/config.json`** (modèle :
[`config.example.json`](claude-plugin/mcp-gateway/config.example.json)) :

```json
{
  "baseUrl": "https://exemple.com",
  "keyId": "iawm_xxxxxxxxxxxx",
  "secret": "le-secret-genere-dans-l-admin"
}
```

Redémarrer Claude Code : les outils `iawm_*` sont disponibles.

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

- **Phases 0–2 (faites)** — connexion sécurisée, plan contenu complet.
- **Phase 3** — prise en charge fine du constructeur **Divi 5**.
- **Phases 4–6** — infrastructure (extensions, base de données, sauvegardes),
  durcissement de la sécurité, workflows webmaster avancés.

## Licence

[GPL-3.0-or-later](LICENSE). Le plugin WordPress étant un dérivé de WordPress,
l'ensemble du projet est publié sous licence GPL.
