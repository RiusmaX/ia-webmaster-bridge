# Journal des décisions

> Statut : Vivant · Dernière mise à jour : 2026-05-21

Chaque décision a un identifiant (`D-NNN`), une date, un statut
(`Validée` / `À revoir` / `Remplacée`), son contexte, la décision et ses
conséquences. On ne supprime pas une décision : on la marque `Remplacée` et on
en ajoute une nouvelle.

## D-001 — S'appuyer sur les concepts officiels, pas sur les dépendances externes

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : WordPress 6.9+ fournit l'Abilities API (dans le cœur) et un MCP
  Adapter officiel (`WordPress/mcp-adapter`, externe, v0.5.0 — pré-1.0, sujet à
  changements cassants).
- **Décision** : on s'inspire de ces concepts mais on ne dépend pas du MCP
  Adapter externe. L'Abilities API du cœur pourra éventuellement servir de
  registre interne (elle est dans le cœur, ce n'est pas une dépendance tierce) —
  à décider en Phase 1.
- **Conséquences** : maîtrise totale, pas de rupture due à un composant pré-1.0 ;
  un peu plus de code à écrire de notre côté.

## D-002 — Adaptateur 100 % maison

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : nous voulons garantir la compatibilité et ne pas dépendre d'un
  composant externe.
- **Décision** : on construit notre propre plugin WordPress (API REST custom) et
  notre propre pont MCP local (Node.js). Le plugin reste du WordPress REST
  classique ; le pont MCP isole le protocole MCP côté machine de l'utilisateur.
- **Conséquences** : deux composants à maintenir, mais découplés et versionnables
  indépendamment ; aucune surface d'attaque héritée d'un tiers.

## D-003 — Builder prioritaire : Divi 5

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : la majorité des sites visés sont sous Divi 5, qui est moins
  documenté qu'Elementor pour la génération programmatique.
- **Décision** : Divi 5 est la cible prioritaire. Elementor est reporté.
- **Conséquences** : la Phase 3 inclut une rétro-ingénierie du format Divi 5 sur
  le site local. Approche assumée : tester et itérer.

## D-004 — Développement local d'abord (LocalWP)

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : itérer sur une prod est risqué.
- **Décision** : développement et tests sur un WordPress 7.0 local sous LocalWP.
  Déploiement progressif : local → petites prod → grosses prod.
- **Conséquences** : aucune itération directe sur une prod ; chaque cible est un
  profil distinct du pont MCP.

## D-005 — Sécurité de premier ordre

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : un agent doté des pleins pouvoirs de webmaster est une surface
  d'attaque majeure ; les échanges plugin ↔ pont ne doivent pas créer de faille.
- **Décision** : authentification par clé d'API + signature HMAC, HTTPS imposé,
  capacités scoppées, journal d'audit, garde-fous (dry-run, brouillon,
  sauvegarde préalable), kill switch. Détail dans `specs/02-securite.md`.
- **Conséquences** : la sécurité est construite dès la Phase 1, pas ajoutée après.

## D-006 — Opérations d'infrastructure via le plugin

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : nous avons un accès SSH aux serveurs, mais préférons ne pas
  exposer un shell à l'agent.
- **Décision** : les opérations d'infrastructure passent par des endpoints
  contrôlés et journalisés du plugin. Le SSH/WP-CLI reste un canal de secours
  (réservé à l'opérateur humain ou aux cas où le plugin est indisponible).
- **Conséquences** : surface d'attaque réduite ; certaines opérations système
  devront être encapsulées explicitement côté plugin.

## D-007 — Nom du plugin et du namespace REST

- Date : 2026-05-21 · Statut : Validée
- **Contexte** : un nom provisoire avait été proposé pour le plugin et son API.
- **Décision** : le plugin s'appelle « IA Webmaster Bridge » (slug
  `ia-webmaster-bridge`) ; l'API REST est exposée sous le namespace
  `ia-webmaster/v1`.
- **Conséquences** : noms figés pour le dépôt et le code à venir.

## D-008 — Intégration livrée comme plugin Claude Code

- Date : 2026-05-22 · Statut : Validée
- **Contexte** : nous voulons une intégration propre dans Claude (plugin,
  connecteur ou extension — au choix du plus adapté).
- **Décision** : livrer un **plugin Claude Code** empaquetant le pont MCP et les
  skills. C'est le format natif de Claude Code pour regrouper un serveur MCP,
  des skills et des commandes. Écartés : l'« extension Claude Desktop » (mauvais
  environnement), le simple `.mcp.json` nu (pas d'empaquetage).
- **Conséquences** : `mcp-gateway/` et `skills/` seront structurés en plugin
  avec un manifest ; installation et mise à jour propres.

## D-009 — Accès aux logs et au diagnostic

- Date : 2026-05-22 · Statut : Validée
- **Contexte** : nous voulons pouvoir diagnostiquer et déboguer le site via
  l'agent.
- **Décision** : ajouter un module de diagnostic en **lecture seule** : debug.log
  de WordPress, Site Health, état et versions des extensions/thèmes, versions de
  l'environnement. La lecture seule est le garde-fou ; chaque accès reste
  journalisé dans l'audit.
- **Conséquences** : nouveau module `IAWM_Diagnostics` côté plugin.

## D-010 — Diffusion open source publique

- Date : 2026-05-22 · Statut : Validée
- **Contexte** : nous souhaitons diffuser le projet en open source.
- **Décision** : publication publique sur GitHub (`RiusmaX/ia-webmaster-bridge`)
  sous licence **GPL-3.0-or-later**. Le dépôt sert aussi de marketplace Claude
  Code : le plugin `ia-webmaster` s'installe via `/plugin marketplace add` puis
  `/plugin install`.
- **Conséquences** : le secret HMAC et toute donnée sensible sont exclus du dépôt
  (`.gitignore`) ; une vérification de sécurité précède chaque publication. Le
  pont est distribué en bundle autonome ; sa configuration vit hors du dépôt
  (`~/.iawm/config.json`).
