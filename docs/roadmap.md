# Roadmap

> Statut : Phases 0-3 complètes · Dernière mise à jour : 2026-05-23

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

## Phase 3 — Plan Divi 5 *(complète)*

- [x] **3.1** — Cartographie complète de l'API `divi/v1` (102 routes documentées dans `docs/divi5-api-index.md`)
- [x] **3.1** — Rétro-ingénierie du format Divi 5 (3 pages de référence peuplées : id 19, 29, 53)
- [x] **3.1** — Catalogue exhaustif des ~99 modules natifs (`docs/divi5-modules-catalog.md`)
- [x] **3.2** — Endpoint `/divi/page/read` (lecture en arbre : tree / flat / raw)
- [x] **3.2** — Endpoint `/divi/page/write` (écriture content + blocks)
- [x] **3.2** — Round-trip fidèle au bit (bug `wp_slash` corrigé)
- [x] **3.3** — Endpoint `/divi/library/list` + `/library/item` + `/library/local`
- [x] **3.3** — Endpoint `/divi/cloud/status` + `/divi/global-data` (design system)
- [x] **3.3** — Workflow hybride Divi Cloud documenté (Save to Library côté builder)
- [x] **3.4** — Bibliothèque `lib/divi/` (builders + 11 patterns paramétrables)
- [x] **3.4** — Skill `creer-page-divi-wordpress` (workflow prompt → page)
- [x] **3.5** — 41 modules implémentés (modules avancés, structurels, dynamiques)
- [x] **3.5** — 13 patterns : hero, features3col, ctaBanner, imageTextSplit, testimonials, faqAccordion, numbersBar, videoSection, contactSection, pricing3col, teamGrid, headerSimple, footerStandard
- [x] **3.6** — Theme Builder : endpoints `/divi/theme-builder/*` (8 routes)
- [x] **3.6** — Wrapper `setup-site-defaults` (header + footer + template default en 1 appel)
- [x] **3.6** — Logique override Divi décodée (`enabled=true + id=0` pour rendu natif)
- [x] **Test E2E réel** : page d'accueil complète (header + footer + 10 sections + SEO Rank Math) générée en draft sur le site de test à partir d'un brief — validation du workflow prompt → site

## Phase 4 — Plan infrastructure

- [x] Capacités extensions (install, activation, désactivation) — avancée Phase 3 pour intégrer Rank Math
- [ ] Capacités thèmes (install, activation, mise à jour)
- [ ] Mise à jour des extensions
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

- [x] Skills Claude Code de base (webmaster, créer page, audit)
- [x] Empaquetage en plugin Claude Code + diffusion open source (GitHub, GPL-3.0)
- [x] Skill `design-frontend-wordpress` (hiérarchie, typo, couleurs, espacement, mobile-first)
- [x] Skill `marketing-conversion-wordpress` (AIDA/PAS/FAB, preuve sociale, hiérarchie des CTA)
- [x] Skill `seo-wordpress` (audit SEO, Rank Math, structure sémantique)
- [x] Skill `creer-page-divi-wordpress` (workflow prompt → page Divi complète)
- [x] API SEO normalisée avec backend Rank Math
- [ ] Backend Yoast SEO en alternative (#33)
- [ ] Fichier de contexte par site
- [ ] Workflows webmaster documentés
- [ ] Éventuelles routines planifiées

## Jalons de déploiement

- [ ] Stable sur le site local
- [ ] Validé sur une petite prod
- [ ] Validé sur une grosse prod
