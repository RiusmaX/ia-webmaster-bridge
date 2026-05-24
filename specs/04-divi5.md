# Spec 04 — Plan Divi 5 (prioritaire)

- **Statut** : En cours (Phase 3.1 — rétro-ingénierie)
- **Phase** : 3
- **Priorité** : Prioritaire
- **Dernière mise à jour** : 2026-05-23

## Objectif

Permettre à Claude de **lire, créer et modifier des layouts Divi 5** : le cœur
de la valeur du projet, puisque la majorité des sites visés sont sous Divi 5
(décision D-003).

## Contexte

Divi 5 (sorti le 26 février 2026) a réécrit son architecture : builder React,
contenu stocké en **format JSON / blocs sérialisés** (proche du format
Gutenberg), attributs hiérarchiques multi-breakpoints et multi-états. Les
layouts Divi 4 sont migrés vers ce format.

**Difficulté assumée** : il n'existe **pas d'API publique documentée** pour
générer un layout Divi 5 complet par programme. Les ressources disponibles :
- le format `.json` d'import/export « portability » (officiel, mais contraint
  par le contexte d'export) ;
- la nouvelle Builder API Divi 5 (orientée modules custom — périmètre à
  explorer) ;
- une documentation communautaire non officielle (à vérifier, jamais à supposer
  exacte).

**Cartographie complète de `divi/v1` (2026-05-23)** — 102 routes uniques, 29
groupes. Carte détaillée dans `docs/divi5-api-index.md` (source brute :
`docs/divi5-api-map.json`). Routes les plus structurantes pour notre usage :

- **`page-manager/`** (9 routes) — create, update, duplicate, trash, search, show.
  Voie haut niveau pour gérer les pages Divi sans passer par le VB.
- **`sync-to-server`** — POST que le VB utilise pour persister le contenu :
  `post_id`, `content`, `pageSettingsByLayout`, `off_canvas_data`,
  `layout_post_ids`, `mainLoopType`, `mainLoopSettingsData`. **C'est le canal
  d'écriture canonique de Divi 5.**
- **`outside-vb/posts/set-layout`** — applique un layout (par contenu ou par
  post source) à un post cible, sans VB.
- **`outside-vb/export-layout`** — exporte le layout d'un post (lecture).
- **`portability/export`** et **`portability/import`** — format JSON officiel,
  utilisé pour la migration de layouts.
- **`divi-library/`** (12 routes) — bibliothèque Divi : list, item, load,
  create-item, save, split-item, convert-item, upload-image, item-location ;
  **`cloud-token`** pour Divi Cloud.
- **`global-data/`** (4 routes) — couleurs globales, fontes, variables,
  presets (système design). **Critique** pour piloter le style site-wide.
- **`module-render`** — rendu HTML d'un module à partir de son JSON
  (preview programmatique).
- **`dynamic-content/options`** — options de contenu dynamique disponibles
  pour un post (titre, extrait, custom fields…).
- **`breakpoints/update`** — paramètres responsive (mobile, tablette,
  desktop, custom).
- **`outside-vb/theme-builder/*`** (5 routes) — templates header/footer,
  templates personnalisés.
- **`menu-manager/`** (8 routes) — gestionnaire de menus Divi (distinct du
  système menu WP standard).
- **`module-data/`** (21 routes) — endpoints utilitaires par module
  (gallery, video, audio, blog/posts, breadcrumbs, sidebar, shortcode…).
- **`loop/`** (5 routes) — types de requête pour les boucles dynamiques.
- **`ai_layout_save_defaults`** — Divi a déjà ses propres "AI layout
  defaults" (fontes, couleurs primaire/secondaire, description site).

Toutes ces routes sont **protégées par le nonce du Visual Builder** : un
appel en pur server-to-server (sans cookie admin) reçoit 401/403. Trois
voies pour les exploiter :
1. **Appel interne en PHP** depuis notre plugin (`rest_do_request` après
   `act_as_agent`) : on hérite des droits admin et on évite le nonce.
2. **Appel direct aux fonctions PHP de Divi** : certaines routes ne sont
   qu'un wrapper léger autour d'une fonction réutilisable (à confirmer cas
   par cas).
3. **Travail au niveau stockage** (`post_content`, post meta) sans passer
   par les routes Divi — plus brut mais plus stable. À privilégier pour la
   lecture ; à éviter pour l'écriture (déclencheurs/cache).

Conséquence : la première brique reste une **rétro-ingénierie du format
réel** sur le site local (création d'une page de référence dans le VB,
extraction du `post_content` et des meta), pour ancrer toute la suite.

## Périmètre

### Inclus
- Lecture d'un layout Divi 5 existant (structure, modules, réglages).
- Génération de layouts Divi 5 par programme.
- Modification de layouts existants.
- Bibliothèque de génération côté Claude (`lib/divi/`).

### Exclu (pour l'instant)
- Divi 4 (sites encore en Divi 4 — à traiter plus tard si besoin).
- Elementor (reporté, décision D-003).
- La création de modules Divi custom (Builder API) — hors périmètre initial.

## Approche technique (itérative)

1. **Observer le format réel.** Sur le site local : créer dans le builder Divi 5
   plusieurs layouts de référence (section simple, colonnes, modules courants),
   puis extraire et analyser leur stockage exact (`post_content` et/ou post
   meta). C'est la source de vérité — pas la doc communautaire.
2. **Lire avant d'écrire.** Une capacité de lecture qui restitue un layout Divi 5
   dans une structure exploitable par Claude.
3. **Aller-retour (round-trip).** Vérifier qu'un layout lu puis réécrit tel quel
   reste identique et s'ouvre sans erreur dans le builder. Critère de fiabilité.
4. **Génération incrémentale.** Construire `lib/divi/` : des aides qui produisent
   un JSON Divi 5 valide à partir d'une intention de haut niveau (section →
   lignes → colonnes → modules). Commencer par un sous-ensemble réduit de
   modules, élargir au fur et à mesure.
5. **Import contrôlé.** Capacité plugin qui applique un layout généré à une page,
   en respectant les contraintes de contexte du format « portability ».

## Points ouverts

- Où Divi 5 stocke-t-il exactement le layout (post_content, post meta, table
  dédiée) ? → à confirmer par l'observation (étape 1).
- Faut-il, après écriture, déclencher une régénération de cache / d'assets Divi
  (analogue au cache CSS d'Elementor) ? → à vérifier.
- Le format « portability » est-il suffisant, ou faut-il écrire le stockage
  natif directement ?
- La Builder API Divi 5 offre-t-elle un point d'entrée pour la génération de
  layouts, ou seulement pour les modules custom ?
- Modèle des attributs multi-breakpoints / multi-états : structure exacte à
  cartographier.
- Stratégie pour les modules tiers (extensions Divi) présents sur les sites.

## Dépendances & risques

- Dépend des specs 01 (adaptateur) et 02 (sécurité).
- **Risque principal** : format non documenté et susceptible d'évoluer entre
  versions mineures de Divi 5 → d'où l'observation directe, l'épinglage de la
  version de Divi testée, et le test d'aller-retour comme garde-fou.
- Risque : un layout généré invalide peut casser l'affichage d'une page → tout
  passe par le brouillon et le dry-run (spec 02) avant publication.
