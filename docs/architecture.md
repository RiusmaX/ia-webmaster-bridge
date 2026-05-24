# Architecture technique

> Statut : En conception · Dernière mise à jour : 2026-05-21

## Vue d'ensemble

Le système relie **Claude Code** (le cerveau et le runtime de l'agent, sur la
machine de l'utilisateur) à un **site WordPress 7.0** (la cible). Claude Code
n'a pas besoin d'un orchestrateur séparé : il EST l'agent.

```
   Claude Code
        |
        |  protocole MCP (stdio)
        v
   Pont MCP local            <-- notre code (Node.js / TypeScript)
        |
        |  HTTPS — API REST custom + requêtes signées (HMAC)
        v
   Plugin "IA Webmaster Bridge"   <-- notre code (PHP, installé sur le site)
        |
        +-- Plan contenu        (pages, articles, medias, menus, blocs)
        +-- Plan Divi 5         (generation de layouts)
        +-- Plan configuration  (reglages, theme, utilisateurs)
        +-- Plan infrastructure (extensions, base de donnees, sauvegardes)
        |
        v
   WordPress 7.0 + Divi 5
```

## Composants à construire

### 1. Plugin « IA Webmaster Bridge » (PHP)

Installé sur le site cible. Expose une **API REST custom** sous le namespace
`ia-webmaster/v1`. Rôles :

- Exposer des **capacités** (capabilities) regroupées par plan fonctionnel.
- Appliquer l'**authentification** et la **journalisation d'audit**.
- Implémenter les **garde-fous** (dry-run, brouillon, sauvegarde préalable).
- Encapsuler les opérations d'infrastructure pour éviter d'ouvrir un shell.

C'est du WordPress REST classique : simple à sécuriser, tester et versionner.

### 2. Pont MCP local (Node.js / TypeScript)

Tourne sur la machine de l'utilisateur (dossier `mcp-gateway/`). Rôles :

- Présenter à Claude Code un **serveur MCP** (transport stdio) avec des outils
  propres et bien typés.
- Traduire chaque appel d'outil MCP en requête HTTPS signée vers le plugin.
- Détenir les **secrets** (clé d'API, URL des sites) — jamais stockés dans le
  dépôt, jamais exposés au site.
- Gérer plusieurs cibles (local, petites prod, grosses prod) via des profils.

### 3. Couche webmaster (skills + contexte)

Skills Claude Code et fichiers de contexte par site, qui donnent à Claude ses
méthodes de travail (créer une landing page, auditer le SEO, mettre à jour les
extensions en sécurité, etc.). Voir `specs/07-couche-webmaster.md`.

## Pourquoi un adaptateur maison

Le MCP Adapter officiel (`WordPress/mcp-adapter`) est en pré-1.0 (v0.5.0) :
changements cassants attendus, et il ne comprend pas nativement Divi. Construire
notre propre adaptateur garantit la maîtrise totale, l'absence de rupture due à
un composant tiers, et un périmètre taillé pour nos besoins (Divi 5 en premier).
On peut s'inspirer des concepts officiels (Abilities API du cœur WordPress) sans
en dépendre. Voir `docs/decisions.md` (D-001, D-002).

## Les trois plans fonctionnels

| Plan | Périmètre | Spec |
|------|-----------|------|
| Contenu | Pages, articles, médias, menus, taxonomies, blocs Gutenberg | `03-contenu.md` |
| Divi 5 | Génération et édition de layouts Divi 5 | `04-divi5.md` |
| Configuration | Réglages du site, thème, utilisateurs, rôles | `05-configuration.md` |
| Infrastructure | Extensions, thèmes, base de données, sauvegardes, cron | `06-infrastructure.md` |

La **sécurité** (`02-securite.md`) est transversale à tous les plans.

## Pourquoi MCP plutôt qu'un appel REST direct

Claude Code consomme nativement des **outils MCP** : c'est l'ergonomie agent la
plus fiable (outils typés, découvrables, schémas d'entrée validés). Le pont MCP
local nous laisse maîtriser les deux bouts tout en gardant le plugin comme une
simple API REST WordPress. Alternative écartée pour l'instant : implémenter le
protocole MCP directement en PHP dans le plugin (plus de travail, couplé à
l'évolution du protocole).

## Cycle de déploiement

`Local (LocalWP)` → `petites prod` → `grosses prod`. Chaque cible est un profil
du pont MCP. On ne valide une cible supérieure qu'après stabilité sur la
précédente. Voir `docs/roadmap.md`.
