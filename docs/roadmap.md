# Roadmap

> Statut : Vivant · Dernière mise à jour : 2026-05-21

Plan d'action par phases. Les cases reflètent l'avancement réel. La sécurité
(`specs/02-securite.md`) est transversale : construite dès la Phase 1 et durcie
en continu, la Phase 5 étant la passe de durcissement formelle.

## Phase 0 — Cadrage & environnement

- [x] Recherches (WordPress 7.0, écosystème IA, Divi 5)
- [x] Architecture validée (3 composants, adaptateur maison)
- [x] Contexte projet, mémoire et specs rédigés
- [x] Site WordPress 7.0 local installé sous LocalWP
- [x] Accès au site local fourni à Claude
- [x] Divi 5 installé et activé sur le site local (Divi 5.5.2)
- [x] Structure du dépôt initialisée (`plugin/`, `mcp-gateway/`)

## Phase 1 — Connexion de base

- [x] Plugin minimal : namespace REST, capacité de test (`ping`, lecture seule)
- [x] Authentification : clé d'API + signature HMAC
- [x] Pont MCP local minimal connecté à Claude Code
- [x] Validation bout en bout : 3 outils MCP testés (lecture de contenu réelle → Phase 2)
- [x] Journal d'audit en place dès cette phase

## Phase 2 — Plan contenu & configuration

- [x] Capacités contenu : CRUD pages/articles, médias, menus, taxonomies
- [x] Génération de blocs Gutenberg valides (normalisation dans content/create)
- [x] Capacités configuration : réglages du site, utilisateurs (options de thème → Phase 3)
- [x] Capacités de diagnostic & accès aux logs (debug.log, Site Health, extensions)
- [x] Garde-fous : mode dry-run, brouillon avant publication, kill switch

## Phase 3 — Plan Divi 5 *(prioritaire)*

- [ ] Rétro-ingénierie du format de layout Divi 5 sur le site local
- [ ] Lecture d'un layout Divi 5 existant
- [ ] Bibliothèque de génération de layouts (`lib/divi/`)
- [ ] Création d'une page Divi 5 simple de bout en bout
- [ ] Aller-retour fiable (générer → éditer dans le builder → relire)

## Phase 4 — Plan infrastructure

- [ ] Capacités extensions/thèmes (install, activation, mise à jour)
- [ ] Capacités base de données (export, requête, search-replace) contrôlées
- [ ] Sauvegardes et restauration
- [ ] Canal SSH/WP-CLI de secours documenté

## Phase 5 — Sécurité & garde-fous (durcissement)

- [ ] Revue du modèle d'autorisation (scopes / capacités)
- [ ] Sauvegarde automatique avant opération destructrice
- [ ] Confirmations explicites pour les actions à risque
- [ ] Kill switch et rotation des clés
- [ ] Test d'intrusion léger du plugin

## Phase 6 — Couche webmaster

- [ ] Skills Claude Code (créer une landing page, audit SEO, debug, etc.)
- [ ] Empaquetage en plugin Claude Code (pont MCP + skills)
- [ ] Fichier de contexte par site
- [ ] Workflows webmaster documentés
- [ ] Éventuelles routines planifiées

## Jalons de déploiement

- [ ] Stable sur le site local
- [ ] Validé sur une petite prod
- [ ] Validé sur une grosse prod
