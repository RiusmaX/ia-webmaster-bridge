---
name: webmaster-wordpress
description: Méthode et garde-fous pour gérer un site WordPress via l'adaptateur IA Webmaster Bridge. À utiliser pour toute tâche de gestion de contenu, de médias, de menus, de configuration ou de diagnostic sur un site WordPress connecté par les outils mcp__ia-webmaster__iawm_*.
---

# Webmaster WordPress — méthode

Le site WordPress est piloté via l'adaptateur **IA Webmaster Bridge**. Toutes les
actions passent par les outils MCP `mcp__ia-webmaster__iawm_*` — chaque appel est
authentifié (signature HMAC) et journalisé.

## Avant toute chose

Commencer par `iawm_status` : confirmer que la connexion est valide et que le
**kill switch** n'est pas actif. S'il l'est, les écritures sont volontairement
coupées : prévenir l'utilisateur plutôt que de tenter d'écrire.

## Familles d'outils

- **Diagnostic** (`iawm_diagnostics_*`) — système, extensions, thèmes, logs.
- **Contenu** (`iawm_content_*`) — pages et articles : list, get, create, update.
- **Médias** (`iawm_media_*`) — médiathèque : list, get, sideload, update.
- **Taxonomies** (`iawm_taxonomy_*`) — catégories, étiquettes.
- **Menus** (`iawm_menu_*`) — menus de navigation.
- **Configuration** (`iawm_config_*`) — réglages du site, utilisateurs.
- **Audit** (`iawm_audit`) — journal de toutes les actions effectuées.

## Garde-fous — à respecter systématiquement

1. **Lire avant d'écrire.** Avant de modifier un contenu, le lire (`get`) pour
   connaître son état et son `builder` (gutenberg / divi / classic).
2. **Dry-run d'abord.** Pour toute écriture non triviale, appeler l'outil avec
   `dry_run: true`, montrer à l'utilisateur ce qui serait fait, et n'appliquer
   qu'après son accord.
3. **Brouillon par défaut.** Les contenus sont créés en brouillon. Ne publier
   (`status: publish`) que sur demande explicite.
4. **Vérifier après écriture.** Après une création ou modification, relire le
   résultat pour confirmer.
5. **Ne jamais contourner les garde-fous** ni proposer de les désactiver.

## En cas de problème

- Consulter `iawm_diagnostics_logs` (erreurs WordPress) et `iawm_diagnostics_system`.
- Consulter `iawm_audit` pour retracer les dernières actions.
- Un statut 403 « kill switch » sur une écriture : prévenir l'utilisateur que les
  écritures ont été coupées côté site.

## Pages construites avec Divi

Le champ `builder` de `iawm_content_get` indique `divi`, `gutenberg` ou
`classic`. **Ne pas écrire de contenu Gutenberg dans une page Divi** (ni
l'inverse) : cela corromprait la page. La prise en charge fine de Divi est en
cours (Phase 3 du projet).
