# Glossaire

> Statut : Vivant · Dernière mise à jour : 2026-05-21

**WordPress 7.0** — Version majeure de WordPress sortie le 20 mai 2026.
Nouveautés surtout côté éditeur. La cible du projet.

**WordPress 6.9** — Version de décembre 2025 qui a introduit dans le cœur
l'infrastructure « agent IA » (Abilities API).

**Abilities API** — API du cœur WordPress (depuis 6.9) : un registre d'« actions »
(capacités) avec entrées/sorties validées par JSON Schema et callbacks de
permission. On peut s'en inspirer / l'utiliser comme registre interne, sans en
dépendre comme d'un composant externe.

**MCP (Model Context Protocol)** — Protocole standard par lequel un agent IA
(ici Claude Code) découvre et appelle des outils. Notre pont local parle MCP.

**MCP Adapter** (`WordPress/mcp-adapter`) — Plugin officiel qui expose les
Abilities en serveur MCP. Externe, pré-1.0 (v0.5.0). **Non utilisé** dans ce
projet (voir décision D-002).

**Pont MCP local** (MCP Gateway) — Notre composant Node.js, sur la machine de
Marius. Présente un serveur MCP à Claude Code et le traduit en appels HTTPS vers
le plugin. Dossier `mcp-gateway/`.

**Plugin « IA Webmaster Bridge »** — Notre plugin WordPress (PHP), installé sur
le site cible. Expose l'API REST custom `ia-webmaster/v1`. Dossier `plugin/`.

**Capacité (capability)** — Une opération unitaire exposée par le plugin
(ex. « créer une page », « uploader un média »), avec son schéma d'entrée, sa
permission et son journal.

**Divi 5** — Version du page builder Divi (Elegant Themes), sortie le 26 février
2026. Architecture React, contenu stocké en format JSON / blocs sérialisés.
Builder prioritaire du projet.

**Builder API Divi** — API développeur introduite avec Divi 5 pour les modules
et fonctionnalités custom. Périmètre exact à explorer.

**Format « portability » Divi** — Format `.json` d'import/export des layouts
Divi. Contrainte : un layout doit être importé dans le même contexte que celui
d'où il a été exporté (page / bibliothèque / theme builder).

**Gutenberg / blocs** — L'éditeur de blocs de WordPress. Le contenu est stocké
dans `post_content` sous forme de balisage HTML annoté par des commentaires
(`<!-- wp:... -->`).

**FSE (Full Site Editing)** — Édition de site complète : templates et parties de
template gérés comme des blocs.

**post_content / post meta** — `post_content` : le corps d'un contenu.
`post meta` : paires clé/valeur attachées à un contenu (les builders y stockent
souvent leurs données).

**HMAC** — Signature cryptographique d'une requête avec un secret partagé, pour
garantir authenticité et intégrité. Utilisé pour sécuriser les échanges
pont ↔ plugin.

**WP-CLI** — Interface en ligne de commande de WordPress. Canal de secours pour
les opérations système (voir décision D-006).

**LocalWP** — Outil de création de sites WordPress locaux. Sert d'environnement
de développement et de test du projet.

**Claude Code** — L'environnement dans lequel tourne Claude ; ici, le cerveau et
le runtime de l'agent webmaster.

**Skill** — Module de compétence réutilisable pour Claude Code (un workflow
documenté). Voir `specs/07-couche-webmaster.md`.
