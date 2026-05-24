# IA Webmaster Bridge — Contexte projet

> Fichier de contexte chargé automatiquement par Claude Code à chaque session
> ouverte dans ce dépôt. Indique le but du projet, sa structure et les règles
> à suivre lors de toute modification.

## En une phrase

Système permettant à un agent Claude (Claude Code ou Claude Desktop) d'agir
comme **webmaster WordPress full-compétence** sur des sites **WordPress 7.0 +
Divi 5** : contenu, configuration, génération de pages et de templates de
thème, SEO, opérations d'infrastructure — chaque action authentifiée,
journalisée et encadrée par des garde-fous.

## Décisions structurantes

Voir [`docs/decisions.md`](docs/decisions.md). En résumé :

- **Adaptateur 100 % maison.** Plugin WordPress + pont MCP propres. Pas de
  dépendance à un adaptateur externe pré-1.0.
- **Builder prioritaire : Divi 5.** Elementor reporté.
- **Développement local d'abord** (LocalWP recommandé). Jamais d'itération
  directe sur une production.
- **Sécurité de premier ordre** (voir [`specs/02-securite.md`](specs/02-securite.md)) :
  HMAC, audit, garde-fous, kill switch.
- **Opérations d'infra via le plugin** plutôt que par SSH brut quand c'est
  possible.

## Structure du dépôt

| Chemin | Rôle |
|--------|------|
| `CLAUDE.md` | Ce fichier (contexte projet partagé, chargé à chaque session) |
| `README.md` | Présentation publique du projet et installation |
| `LICENSE` | GPL-3.0-or-later |
| `docs/` | `architecture.md`, `roadmap.md`, `decisions.md`, `glossaire.md`, `divi5-format.md`, `divi5-modules-catalog.md`, `divi5-compose-dsl.md` |
| `specs/` | Une spec par fonctionnalité (`01` à `07`) |
| `plugin/ia-webmaster-bridge/` | Plugin WordPress (API REST `ia-webmaster/v1`) |
| `claude-plugin/` | Plugin Claude Code (outils MCP + skills + pont MCP) |
| `claude-plugin/mcp-gateway/` | Pont MCP (TypeScript bundled) |
| `.claude-plugin/marketplace.json` | Catalogue marketplace Claude Code |
| `tools/` | Outils de développement (voir `tools/README.md`) |

## État du projet

Phases 0 à 3 complètes — voir [`docs/roadmap.md`](docs/roadmap.md) pour le
détail des sous-jalons atteints et restants.

Capacités actuelles (haut niveau) :

- Gestion WordPress complète (contenu, médias, taxonomies, menus,
  configuration, diagnostic, plugins).
- Divi 5 : 41 modules natifs, 13 patterns paramétrables, Theme Builder
  complet, round-trip fidèle au bit, composeur unifié déclaratif
  (`iawm_divi_page_compose`).
- SEO Rank Math (Yoast prévu).
- 7 skills Claude Code de méthode et de workflow.

## Règles de collaboration (à suivre par l'agent)

- **Co-conception étape par étape.** Avancer par incréments testables ;
  valider les engagements importants avec l'utilisateur avant de coder.
- **Jamais d'opération destructrice sur une production sans confirmation
  explicite ET sauvegarde préalable.**
- **Brouillon par défaut.** Toute création de contenu commence en `draft` ;
  la publication doit être explicite.
- **Pas de scripts intermédiaires pour générer du contenu Divi.** Utiliser
  directement les outils MCP `iawm_divi_page_compose` et
  `iawm_divi_theme_builder_compose` (voir `docs/divi5-compose-dsl.md`).
  Les scripts par projet (clients) vont dans un dossier **hors du dépôt**.
- **Tenir `docs/decisions.md` et `docs/roadmap.md` à jour** à chaque décision
  ou jalon franchi.
- **Toute spec modifiée** : actualiser son champ « Statut » et sa date.
- **Aucune fuite de données privées** dans le dépôt : pas de secrets, pas de
  paths personnels, pas de contenu spécifique à un site client. Toute
  configuration sensible reste dans `~/.iawm/config.json` (gitignoré).

## Préférences personnelles

Les préférences propres au mainteneur courant (langue, style de
communication, environnement de dev) sont dans `CLAUDE.local.md` —
gitignoré — quand ce fichier existe. Si tu débutes sur ce dépôt, tu peux
en créer un pour y consigner tes propres préférences sans polluer le
dépôt public.
