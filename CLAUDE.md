# IA Webmaster — Contexte projet

## En une phrase

Système permettant à Claude d'agir comme **webmaster WordPress full-compétence**
sur des sites WordPress 7.0 : gestion de contenu, configuration, génération de
pages **Divi 5**, et opérations d'infrastructure.

## Porteur du projet

Marius Sergent — professionnel WordPress, francophone. **Répondre en français.**
Méthode : co-conception étape par étape, on teste et on itère, on construit
solide au fur et à mesure. Environnement : Windows 11, shell PowerShell.

## Décisions structurantes

Voir `docs/decisions.md` pour le détail et le contexte. En résumé :

- **Adaptateur 100 % maison.** On construit notre propre plugin WordPress + pont
  MCP. On n'utilise PAS le `WordPress/mcp-adapter` externe (pré-1.0, dépendance).
- **Builder prioritaire : Divi 5** (majorité des sites cibles). Elementor plus
  tard, éventuellement.
- **Développement local d'abord** via LocalWP. Jamais d'itération sur une prod.
- **Sécurité = exigence de premier ordre**, transversale (voir `specs/02-securite.md`).
- **Infra via le plugin** : opérations serveur exposées par endpoints contrôlés
  plutôt que par SSH brut quand c'est possible.

## Structure du dépôt

- `CLAUDE.md` — ce fichier (contexte chargé à chaque session)
- `README.md` — présentation publique du projet
- `LICENSE` — licence GPL-3.0-or-later
- `docs/` — `architecture.md`, `roadmap.md`, `decisions.md`, `glossaire.md`
- `specs/` — une spec par fonctionnalité (`01` à `07`)
- `plugin/ia-webmaster-bridge/` — le plugin WordPress (API REST `ia-webmaster/v1`)
- `claude-plugin/` — le plugin Claude Code (outils MCP + skills + pont `mcp-gateway/`)
- `.claude-plugin/marketplace.json` — catalogue marketplace

## État courant

**Phases 0 à 3 terminées** — système complet capable de **générer un site
WordPress + Divi 5 depuis un prompt** (header, footer, page d'accueil,
contenu, SEO).

- **Plugin WordPress** : v0.18.3, ~12 modules, ~56 endpoints REST.
- **Pont MCP gateway** : v0.12.0, bundle 1.1 Mo, 38+ outils MCP.
- **Plugin Claude Code** : v0.2.0, 7 skills (méthodes webmaster + design
  + marketing + SEO + créer page + créer page Divi + audit).
- **Couverture Divi 5** : 41 modules natifs implémentés (sur ~99
  inventoriés), 13 patterns paramétrables (hero, features3col, pricing,
  testimonials, team, FAQ, headerSimple, footerStandard…), Theme Builder
  complet (header / footer / templates dynamiques), round-trip fidèle au
  bit.
- **SEO Rank Math** intégré (API normalisée, prête pour Yoast).
- **Test E2E réussi** : page d'accueil complète + header + footer + SEO
  générés en draft en une commande, à partir d'un brief client.

Reste : Phase 4 (infrastructure : thèmes, BDD, sauvegardes), Phase 5
(durcissement sécurité), modules WooCommerce, backend SEO Yoast. Voir
`docs/roadmap.md`.

## Règles de collaboration

- Répondre en français.
- Avancer étape par étape ; valider les engagements importants avec l'utilisateur.
- Jamais d'opération destructrice sur une prod sans confirmation explicite ET
  sauvegarde préalable.
- Tenir `docs/decisions.md` et `docs/roadmap.md` à jour à chaque décision.
- Toute spec modifiée : mettre à jour son champ « Statut » et la date.
