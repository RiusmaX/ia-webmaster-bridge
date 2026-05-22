# Spec 01 — Adaptateur (plugin + pont MCP)

- **Statut** : Ébauche
- **Phase** : 1–2
- **Priorité** : Haute
- **Dernière mise à jour** : 2026-05-21

## Objectif

Fournir le canal de communication entre Claude Code et le site WordPress :
notre propre adaptateur, sans dépendance externe (décisions D-001, D-002). Deux
composants : le **plugin WordPress** et le **pont MCP local**.

## Périmètre

### Inclus
- Plugin WordPress exposant une API REST custom `ia-webmaster/v1`.
- Pont MCP local (Node.js) présentant un serveur MCP à Claude Code.
- Registre de **capacités** (capabilities) découvrables et typées.
- Versionnage de l'API et gestion de plusieurs cibles (profils).

### Exclu (pour l'instant)
- L'implémentation de chaque plan fonctionnel (specs 03 à 06).
- Le détail du modèle de sécurité (spec 02) — référencé ici, spécifié là-bas.

## Approche technique

### Plugin « IA Webmaster Bridge » (PHP)
- Plugin WordPress autonome, dossier `plugin/`.
- Enregistre des routes REST via `register_rest_route()` sous `ia-webmaster/v1`.
- Chaque **capacité** = un endpoint avec : schéma d'entrée validé, callback de
  permission, exécution, journalisation. Les capacités sont regroupées par plan
  (contenu, Divi, configuration, infrastructure).
- Un endpoint de découverte (`/capabilities`) liste les capacités disponibles et
  leurs schémas — le pont MCP s'en sert pour générer ses outils.
- Compatibilité ciblée : WordPress 7.0, PHP moderne. Divi 5 requis pour le
  plan Divi.

### Pont MCP local (Node.js / TypeScript)
- Dossier `mcp-gateway/`. Serveur MCP, transport stdio, pour Claude Code.
- Au démarrage : interroge `/capabilities` du plugin et expose chaque capacité
  comme un **outil MCP** typé.
- Traduit chaque appel d'outil en requête HTTPS signée (voir spec 02).
- **Profils** : un fichier de configuration (hors dépôt) décrit chaque cible
  (local, prod A, prod B) avec son URL et son secret.
- Implémentation pressentie avec le SDK MCP officiel (`@modelcontextprotocol/sdk`).

### Découverte dynamique vs statique
Le pont génère ses outils à partir du `/capabilities` du plugin : ajouter une
capacité côté plugin la rend disponible sans modifier le pont.

## Points ouverts

- Utiliser l'Abilities API du cœur WordPress comme registre interne, ou un
  registre maison ? (à trancher en Phase 1, lié à D-001).
- Pont MCP : un seul serveur multi-profils, ou un serveur par cible ?
- Granularité des capacités : beaucoup de petites capacités, ou des capacités
  plus larges et paramétrées ?
- Gestion des versions d'API (`v1`, `v2`) et compatibilité ascendante.

## Dépendances & risques

- Dépend de la spec 02 (sécurité) pour le transport.
- Le SDK MCP officiel est une dépendance du pont — acceptable (standard, notre
  choix), mais à épingler en version.
- Risque : évolution du protocole MCP — isolée dans le pont, sans impact plugin.
